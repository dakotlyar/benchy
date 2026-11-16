# Signed test catalog: the server side (E4.4)

Status: design, 2026-11-14. Nothing here is built.

This is how a Benchy (Phoromatic) server imports, keeps and serves the signed test catalog, and
how schedules use it. It reads alongside the engine's `spec/catalog-index.md` (the contract,
cited here as "the catalog spec" with its section numbers), `spec/catalog-signing.md` (the key
ceremony and the release steps) and `spec/protocol-v2/README.md`. Where this document and the
catalog spec disagree, the catalog spec wins and this document is wrong.

Read against Benchy `master` 895500abd (v0.9.13, database schema 60) and benchy-engine `main`
b90eccf (untagged, production key table merged).

## 0. Summary

- **Storage.** A server-level catalog under `<storage>/catalog/` (content-addressed files) and five
  new tables with no `AccountID`, plus two nullable columns: a system's agent capabilities and a
  schedule row's pinned version. One schema step, numbered 61 if it merges before tenancy T5.
- **Verification is in PHP**, with `openssl_verify` on P-256 and DER, following the same steps, the
  same refusal reasons and the same forward-only state as the engine. It is checked against the
  engine's committed fixtures. It doesn't call the engine binary (section 3.2 says why).
- **Import** is for server administrators only. It runs from the CLI or the new `?server_catalog` page,
  and once on the first start after an upgrade for the public bundle shipped in the package. Every
  step passes before anything is written. One database transaction makes the new catalog live, and
  every outcome goes to the server audit.
- **Serving** uses the two GET routes the engine already calls. They take the agent bearer token
  like the rest of v2, require an approved system in an active tenant, and serve only tiers that
  `phoromatic_catalog::tiers_for_account()` returns. In 0.10 that is every tier the server holds.
- **hello** stores the agent's capabilities and advertises the tiers' pairs. A poll asks an agent
  to say hello again after an import, so a schedule pinned to a new version doesn't reach an agent
  that hasn't refreshed yet.
- **Schedules** pin a version as the catalog spec 8.4 says. The picker shows the tier and the
  "Installs software for all users" and "Runs as administrator" labels, and a pinned schedule is
  never issued to an agent without `catalog_v1`.
- **Tests that need administrator rights run when scheduled** (Dave, 2026-11-14: "zero
  exceptions"). They go only to a system that can run them unattended or with someone at the desk
  to approve. A system that can't yet is held, with the one-time fix on its page, and isn't
  failed. Section 6.6 has the server's part, and the engine amendment is benchy-engine
  `spec/elevated-scheduled.md` (branch `elevated-scheduled`).
- **The release gate** compares the server's key table with `benchy-tests/legal/catalog-keys.txt`,
  as the engine's gate does. It also checks that the public bundle a release ships is signed,
  public-tier, and the one in the pin.
- **Suites** (Dave, 2026-11-14): native suites on the console. A suite with an administrator test
  runs whole and elevated, after a level-1 final check that names every member, unsigned ones
  especially (6.7).
- **Size:** this release is E4.4's C0 to C6 at 11.9 points likely (8.1 to 18.9) plus the engine
  and client amendment for engine 0.7.0 at 16.2 (10.8 to 26.7): 28.1 together (18.9 to 45.6).
  Elevated suites follow one release later (C7 and ES, 7.0), for 35.1 in all (23.7 to 57.0). Every
  question for Dave is decided (section 18). C0 goes into 0.9.14 whatever happens to the rest.

## 1. Facts this design rests on

| Fact | Where |
|---|---|
| Schema is 60. Each tenancy step uses `migrate_step($to, ...)`, which takes a lock, makes a backup first and sets `user_version` inside one transaction | `phoromatic_server.php` 824, `case 58`/`case 59` |
| T3 and T4 need no schema step. T5 and T6 take the next two numbers | Vault, `Benchy - Tenancy.md` B4 |
| `is_server_admin()`, `phoromatic_server_audit` (append-only, `record()` is safe inside a transaction, log line written after commit) | 0.9.11 |
| `?server_settings` is the pattern for a server-admin page; root reaches the same sections on `?admin_config` | `phoromatic_server_settings.php` |
| The v2 router accepts only POST, authenticates with `phoromatic_agent_auth::authenticate()` (suspended is 403, deleted is 401), sets the tenant context, and refuses an unapproved system on everything except hello | `phoromatic_api_v2.php` 35-163 |
| hello stores `catalogue` rows keyed `(AccountID, SystemID, Ref)` with `Version`. It ignores `engine.capabilities` | `phoromatic_api_v2.php` 333 |
| Native schedule rows are `phoromatic_schedules_native_tests`, `UNIQUE(AccountID, ScheduleID, Ref, OptionsJSON)`, and have no version | `case 57` |
| The picker offers what the schedule's systems reported, and says which systems can run each test | `phoromatic_native_admin.php` 451, 570 |
| Result uploads are validated against `pts-core/static/protocol-v2/result-v1.schema.json`, whose `error_code` lacks the two catalog codes | `phoromatic_api_v2.php` 1147 |
| `benchy/` refs are always native by pattern, whatever any catalogue says | `phoromatic_native_result.php` 28 |
| Web limits: `post_max_size` 24M, `memory_limit` 256M, 8 PHP workers | `phoromatic_tls.php` 633 |
| The event server calls `prepare_database()` at the top of its loop, first run included | `pts_phoromatic_event_server.php` 67 |
| The engine calls `GET /api/v2/catalog/{tier}/index.signed` and `GET /api/v2/catalog/packs/{sha}` with its bearer token. A 404 means "no catalog"; any other non-2xx is an error for that refresh | engine `internal/agent/client.go` 280-325 |
| The engine reports `catalog_v1` among `engine.capabilities`, and its hello catalogue lists one row per id: the version it would select with no pin, including tests that declare `[machine_install]` or `[elevated_run]` | engine `cmd/benchy-engine/main.go` 34, `agent.go` 359 |
| `benchy-engine catalog keys` prints `generation <n> <key id>`, `denied <id>` and `floor <tier> <g> <s>` lines, sorted. Today: two generation lines, no denied, no floor | engine `internal/authoring/render.go` 112 |
| The shared fixtures (`internal/catindex/testdata/fixtures/`) were signed by a discarded test key. PHP's `openssl_verify` gives 1 on good, malleated and respaced, 0 on flipped, **-1 on bad DER**, and the key-id check refuses the unknown key (checked for this design, scratch script only) | engine fixtures |

## 2. What the server stores, and where

### 2.1 Files

```
<storage>/catalog/                     0700, owned by the server user
  signed/<sha256 of file>.signed       every accepted index.signed, content-addressed, 0600
  packs/<pack_sha256>.zip              every pack an accepted index names, 0600
  work/<random>/                       one import's staging, removed when it ends
  import.lock                          flock: one import at a time, server-wide
```

`<storage>` is `phoromatic_server::phoromatic_path()`. The catalog sits outside `accounts/`, so a
tenant purge can't reach it (tenancy B9). File names come only from hashes the server computed
itself, never from bundle member names (catalog spec 7.2). Writes go to a temporary name in the
same folder, then `fsync`, `rename` and a sync of the parent folder.

### 2.2 Tables (schema step 61, all server scope)

```sql
CREATE TABLE phoromatic_catalog_state (
  Singleton INTEGER PRIMARY KEY CHECK (Singleton = 1),
  StateVersion INTEGER NOT NULL,          -- 1, as state.json's "state"
  RetiredBelow INTEGER NOT NULL DEFAULT 0,
  ChangedOn TEXT,                          -- last commit that changed what is served (drives rehello, 5.3)
  PackagedSHA256 TEXT, PackagedOutcome TEXT, PackagedOn TEXT);   -- last packaged-bundle attempt (3.6)

CREATE TABLE phoromatic_catalog_tiers (
  Tier TEXT PRIMARY KEY CHECK (Tier IN ('public', 'private')),
  Generation INTEGER NOT NULL, Sequence INTEGER NOT NULL,
  SignedSHA256 TEXT NOT NULL, KeyID TEXT NOT NULL, Issued TEXT NOT NULL, MinGeneration INTEGER,
  TestCount INTEGER NOT NULL, SuiteCount INTEGER NOT NULL, RevokedCount INTEGER NOT NULL,
  BundleSHA256 TEXT NOT NULL,
  ImportedOn TEXT NOT NULL, ImportedBy TEXT NOT NULL,
  ImportedVia TEXT NOT NULL CHECK (ImportedVia IN ('cli', 'web', 'package')),
  ImportedAccount TEXT);                   -- the importing admin's account (catalog spec 11); NULL for cli and package

CREATE TABLE phoromatic_catalog_ledger (    -- state.json tests and suites: only grow, except by a recovery index
  Tier TEXT NOT NULL, Kind TEXT NOT NULL CHECK (Kind IN ('test', 'suite')),
  Ref TEXT NOT NULL,                       -- id@version
  Hash TEXT NOT NULL,                      -- tree_sha256 or suite_sha256
  PRIMARY KEY (Tier, Kind, Ref));

CREATE TABLE phoromatic_catalog_revoked (   -- state.json revoked, with the latest reason for display
  Tier TEXT NOT NULL, Ref TEXT NOT NULL, Reason TEXT NOT NULL,
  PRIMARY KEY (Tier, Ref));

CREATE TABLE phoromatic_catalog_entries (   -- what the served index of each tier lists, for the UI and the picker
  Tier TEXT NOT NULL, Kind TEXT NOT NULL CHECK (Kind IN ('test', 'suite')),
  ID TEXT NOT NULL, Version TEXT NOT NULL,
  PackSHA256 TEXT, PackSize INTEGER,      -- tests only
  MinEngine TEXT NOT NULL, Platforms TEXT,  -- JSON array, tests only
  LicenseClass TEXT, Title TEXT NOT NULL,
  MachineInstall TEXT,                     -- the manifest's machine_install.product, NULL when absent
  ElevatedRun INTEGER NOT NULL DEFAULT 0,
  EntryJSON TEXT NOT NULL,                 -- the entry as signed, re-encoded
  PRIMARY KEY (Tier, Kind, ID, Version));
CREATE INDEX phoromatic_catalog_entries_pack ON phoromatic_catalog_entries (PackSHA256);

ALTER TABLE phoromatic_systems ADD COLUMN AgentCapabilities TEXT;          -- JSON array; NULL = unknown = no catalog_v1
ALTER TABLE phoromatic_schedules_native_tests ADD COLUMN Version TEXT;    -- NULL = no pin
ALTER TABLE phoromatic_v2_jobs ADD COLUMN DeferredKind TEXT;               -- end.deferred_kind (6.6), NULL otherwise
```

The ledger, revoked and state tables hold exactly what the engine keeps in `state.json` (catalog
spec 5.3), split by tier. `entries` is derived: it is replaced as a whole for a tier at each
commit and could be rebuilt from the stored index, so it is a cache, not state.

All five tables go into `tests/tenancy/tables.json` as `server` scope, with the reason "the signed
catalog, server-wide and read-only to tenants (tenancy Q3)". The two new columns are on tables
that are already tenant scope, and every query that touches them is bound to `AccountID` already.

The step is additive (`IF NOT EXISTS`, columns added only when missing) and runs through
`migrate_step(phoromatic_catalog::SCHEMA_STEP, ...)`, with `const SCHEMA_STEP = 61`, so it gets
the lock, the backup and the five-minute retry like 59 and 60. Every "schema is new enough" check
(the import precondition, the packaged import, the routes) uses the same constant, so renumbering
the step if T5 merges first is a one-line change.
It writes one `migration` audit row ("catalog tables created"), and a fresh database gets the same
tables with no row.

### 2.3 The key table

`pts-core/static/catalog/keys.json`, part of the program tree (root-owned under `/usr/share/benchy`
in the packages):

```json
{ "schema": "benchy-catalog-keys/1",
  "keys": [ { "generation": 1, "spki": "MFkwEwYHKoZIzj0CAQYI..." },
            { "generation": 2, "spki": "MFkwEwYHKoZIzj0CAQYI..." } ],
  "denied_key_ids": [],
  "floor": {} }
```

- The SPKIs are the same two strings as the engine's `prodKeys` (generation 1 is the current key,
  generation 2 the standby). The key id is **computed** from the SPKI (the first 32 hex digits of
  SHA-256 over the DER) and never stored, so a table can't name a key that its SPKI isn't.
- At load, the same checks as the engine's `NewTrust` run: generation 1 or higher and used once;
  base64 that decodes to an SPKI whose `openssl_pkey_get_details()` says EC with curve
  `prime256v1`; no duplicate id; denied ids are 32 lowercase hex; floors only for `public` and
  `private`, with sequence 1 to 2^53-1. A table that fails any check **fails closed**: imports are
  refused with "The server's catalog key table is damaged", the routes serve nothing, and the
  catalog page says so. An empty floor means (1, 1), as in the engine.
- Nothing else can change it: no environment variable, setting or flag (catalog spec 5.2). The
  test image replaces this one file with `tests/catalog/keys.test.json`, which holds keys made fresh
  for each test run (section 14.1). The production file never holds a test key, and the release
  gate checks that (section 10).
- `benchy server-catalog-keys` prints the table in exactly the engine's `catalog keys` text format.
  That output is what the release gate compares, so the gate runs the same code that computes the
  ids the server uses.

## 3. Import

### 3.1 Order

One function, `phoromatic_catalog_import::run($bundle_path, $via, $actor, $actor_detail, $account)`,
serves the CLI, the web page and the packaged import. Each step must pass before the next starts.
Nothing is written to the database or to `signed/` and `packs/` before step 11. The refusal
reasons are the engine's `detail.reason` strings (`internal/catindex/envelope.go`), so an admin
sees the same word from a server and from `benchy-engine catalog import`.

0. **Preconditions.** Refuse unless all of these hold: the caller is a server admin (web) or the
   server user on its host (CLI, `server_command_preflight`); `extension_loaded('openssl')`, `OPENSSL_ALGO_SHA256` is
   defined and `prime256v1` is in `openssl_get_curve_names()`; zlib is loaded; the key table loads;
   schema is 61 or later. Then take `import.lock` (wait up to 5 s, then refuse "Another catalog
   import is running"). Raise `memory_limit` to 512M for this request.
1. **Structure (catalog spec 7.2, before the signature).** The bundle is at most 128 MiB.
   A PHP reader (`phoromatic_catalog_zip`) parses the end record, the central directory and every
   local header itself. It refuses data before the first entry or after the end record, a zip
   comment, zip64, multi-disk, and a local header that disagrees with the central directory, by
   catalog spec 7.1's rule: name and method always. CRC and sizes are compared only when the local
   header's general-purpose bit 3 is clear. When bit 3 is set, the local header's CRC and sizes are
   not compared: Go's zip writer, which builds the bundles, writes zeros there. Instead the data
   descriptor directly after the entry's data (with or without its `PK\x07\x08` signature) must agree
   with the central directory. It also refuses data ranges that overlap or leave gaps, apart from
   that descriptor, compression other than store or deflate, encryption,
   any external attributes other than a regular file (no symlinks, no setuid, setgid or sticky bits),
   two names equal under case folding, more than 1002 members, and any member name other than
   `index.signed` (exactly once) or `packs/<64 lowercase hex>.zip`. A bundle holds no directories.
   The same reader then extracts `index.signed` alone into memory, refusing it above 16 MiB plus
   4 KiB, and checks its CRC and its declared size.
2. **Already accepted (6.2 step 2).** If the file's SHA-256 equals a stored tier's
   `SignedSHA256`: refuse `denied_key` if the signature line's key id is denied, and refuse
   `retired_key` if that tier's generation is below `RetiredBelow`. Otherwise it is **unchanged**:
   skip to step 10 (the membership and hash checks) and then the repair commit (3.4).
3. **Envelope (6.2 step 3).** Split at the first `\n`. The signature line is at most 4 KiB and
   decodes (strictly, 3.3) to exactly `schema` = `benchy-catalog-sig/1`, `key_id` (32 lowercase hex)
   and `sig` (strict base64: `base64_decode($s, true)`). Refuse `bad_envelope`, `unknown_key` or
   `denied_key`. The generation comes from the key table, never from the line.
4. **Retired (6.2 step 4).** Refuse `retired_key` if that generation is below `RetiredBelow`.
5. **Signature (6.2 step 5).** `openssl_verify($index_bytes, $der, $pem, OPENSSL_ALGO_SHA256) === 1`.
   Anything else, `0`, `-1` or `false`, is `bad_signature`. **Nothing parses the index bytes before
   this.** The parser is passed in as a callable, so a test can count its calls (section 14).
6. **Parse (6.2 step 6, and catalog spec 11 step 3).** Read the index with the strict rules in 3.3.
   Then check `schema` = `benchy-catalog-index/1`, `repo` = `benchy`, `tier` is `public` or
   `private`, `sequence` an integer from 1 to 2^53-1, `issued` RFC 3339, and `min_generation` null or
   an integer from 1 to the signing key's generation. Then check every entry: the id grammar
   `^benchy/[a-z0-9]+([._-][a-z0-9]+)*$` within 71 bytes; `version` and `min_engine` as `x.y.z`
   with no leading zeros; `format` = `native`; `pack_sha256` and `tree_sha256` 64 lowercase hex;
   `pack_size` from 1 to 64 MiB; `platforms` a non-empty array of strings; `license_class` a non-empty
   string; `manifest` an object; at most 1000 tests and 1000 suites; no duplicate `id@version`; no id
   used by both a test and a suite; every suite has at least one test, `suite_sha256` is 64 hex,
   option keys match `[A-Za-z0-9_-]+`, and values have no NUL; **every test a suite names, at its
   pinned version if it has one, is in this index** (the server has no built-ins, so a suite naming
   `benchy/stream` that the index lacks is refused like any other); `revoked` entries have a good id
   and version and a string reason. Refuse `bad_index`, or `limit` for the count limits. Like the
   engine, the server does not validate manifests (catalog spec 11 step 3).
7. **Pair (6.2 step 7).** The pair is (key generation, `sequence`), compared lexicographically.
   Refuse `floor` below `floor[tier]` and `rollback` below the stored pair. At an equal pair,
   compare the index bytes (after the first `\n`) with the stored file's. If they are the same, the
   result is unchanged: the stored file stays, there is no alarm, and the import goes to step 10 for
   repair. If they differ, refuse `same_pair_differs`. If the stored file is missing, take the index
   as a fresh accept: the ledger still applies, and its hash is recorded.
8. **Ledger and revocations (6.2 step 9).** A recovery index has a generation above the stored
   one and `min_generation` equal to its own generation. Every index must have no `tests` entry
   whose `id@version` is in its own `revoked` (`revoked_listed`). For anything except a recovery
   index, every stored ledger row for this tier whose `id@version` appears in the index must have
   the same hash (`ledger_conflict`), and every stored revocation must still be in `revoked`
   (`revocation_dropped`).
9. **License (catalog spec 9.3, check 4).** A `public` index may contain only entries whose
   `license_class` is `free` and whose `manifest` has no `machine_install` and no `elevated_run`
   key, whatever their value, `null` included. The rule in 3.3 makes `Machine_Install` count as
   well. The reason is `license_tier`, a server-only reason ("the public catalog lists
   benchy/x@1.0.0, which is non-free" or "which installs software for all users").
10. **Membership and pack hashes (7.2, after the signature).** Every `packs/` member is named by an
    index test's `pack_sha256`, and every index test has a member. Each member is extracted with
    `inflate_add` in chunks into `work/<random>/`, hashed as it streams, held to its declared size,
    to 64 MiB and to its CRC, and must hash to its name **and** to `pack_sha256`, with the size equal
    to `pack_size`. The file in `work/` is named by the hash computed, not the member name. Refuse
    `bad_bundle` (a server-side name for "the bundle's members don't match its index").
11. **Commit.** See 3.4.

On a refusal: delete `work/<random>/`, release the lock, and write one `catalog_import_refused`
audit row (section 9) outside any transaction. Nothing else is written, served or advertised.

### 3.2 Verifying in PHP, not by calling the engine

| | PHP (`openssl_verify`) | Calling `benchy-engine catalog import` |
|---|---|---|
| Spec | What catalog spec 5.1 chose P-256 for, and what 5.2 and 13 assume ("both verifiers") | Would need a spec change |
| Packaging | Nothing new. The openssl extension comes with `php-cli` on Debian and Ubuntu (through `php-common`); import refuses clearly where it is missing | The server packages are architecture-independent today. They would have to ship linux-x64 and arm64 engine builds, and a source install would need one too |
| State | Kept in the server database, beside the audit row it commits with | Kept in an engine home's `state.json`. The server would have to keep a hidden engine home and read its state back, and the audit commit can't be atomic with it |
| Version coupling | None | The server's release would wait on an engine tag, and tagging waits on Dave |
| What the engine doesn't do | The license check (9.3 check 4) has to be PHP either way | Same |
| Divergence risk | Two implementations. Kept in step by the committed fixtures, the §13 list run against both, and the same reason strings | None for verification |
| Cost of divergence | Low. The engine verifies everything again and the server can't forge (catalog spec 1), so a server bug either refuses a good bundle, which an admin sees, or serves one that agents then refuse, which they report as `catalog_refused` | n/a |

**Decision: PHP.** The server's check is a gate and a convenience. It keeps a bad bundle off the
server, keeps the server's own state forward-only, and gives `hello` the right pairs. It is not the
trust anchor. The engine binary wins only on "one implementation", and the cost of that is
packaging, coupling and a second state store. That isn't worth paying for a check that isn't the
security boundary.

### 3.3 Reading JSON the way the engine does

Go's `encoding/json` matches struct keys **case-insensitively**, but PHP's `json_decode` does not.
A signed index carrying `"Min_Generation": 2` would raise `retired_below` on every engine while the
server saw `null`. A `"Machine_Install"` key would slip past check 4. The server reads the
signature line and the index (`json_decode($s, true, 64, JSON_BIGINT_AS_STRING | JSON_THROW_ON_ERROR)`)
and then refuses `bad_index` when:

- an object read as a record has a key that equals one of its known keys under ASCII case folding
  but isn't spelled exactly, or two keys equal under case folding. The records are the root, a
  test, a suite, a suite's test, a revoked entry, the signature line, and the `manifest` with its
  nested `trials`, `metrics`, `parse`, `options`, `lineage`, `machine_install` and `elevated_run`.
  A suite test's `options` is a map, not a record, so its keys can have any case.
- a key in a record contains any byte outside ASCII. Go folds case with Unicode's simple folding,
  so `"ſequence"` (U+017F) matches `sequence`, and a key containing U+212A (the Kelvin sign) matches
  one spelled with `k`. ASCII-only keys make the case-folding rule above complete. Values may hold
  any valid UTF-8;
- a number that must be an integer isn't a PHP `int` (a big integer becomes a string, and `1.0` a
  float);
- the input isn't valid UTF-8, or nests deeper than 64.

This costs about 30 lines. The engine's review deferred it to E4.4 (EC-P1, +2 for a silently wrong
result, adjusted 3, but cheap enough to do now). The engine should get the same rule and a
shared fixture later (section 19).

### 3.4 Commit, repair, collection

**Commit (a new index).** With the lock still held:

1. Move each pack from `work/` to `packs/<sha>.zip` if that file isn't already there, and write
   `signed/<sha256>.signed`. Each goes to a temporary name, gets an `fsync` and is renamed into place.
2. `BEGIN IMMEDIATE`. Upsert the tier row. Update `RetiredBelow` to the larger of the stored
   value and `min_generation`, and set `ChangedOn`. For a recovery index, delete the tier's ledger
   and revoked rows. Then `INSERT OR IGNORE` the index's tests and suites into the ledger (a recovery
   index inserts all of them). Upsert its revocations with their reasons. Replace the tier's
   `entries` rows. Write the `catalog_imported` audit row. `COMMIT`, then `phoromatic_server_audit::flush()`.
   If the audit row or anything else fails: `ROLLBACK`, and the import is refused with
   `state_write`.
3. Collect (below).

**Repair (unchanged index).** Make sure `signed/<sha>.signed` exists and is byte-identical to the
bundle's, then copy any pack that is missing or has the wrong size into `packs/`. If anything was
put back, write `catalog_imported` with `"unchanged": true` and `restored` counts. If nothing was,
write it with `restored` 0. There is no state change. This is the re-import repair path in catalog
spec 11.

**Crash safety.** A crash before `COMMIT` leaves files that nothing references and a database
that hasn't changed, so the server is consistent and the next import or collection removes the
leftovers. Because the files are content-addressed, a file another tier still uses is never
overwritten.

**Collection** runs after every commit and repair, under the lock. It deletes the files in
`signed/` and `packs/` that no tier row or `entries` row references, and every folder in `work/`
older than one hour. Linux keeps an unlinked file readable for a request that is already
streaming it. An agent that asks for a pack only the old index named gets a 404, refreshes its
index and retries once (catalog spec 6.3 step 2), so the server has nothing more to do.

### 3.5 Who imports, from where

| Path | Who | Limit | Actor in the audit |
|---|---|---|---|
| `benchy server-catalog-import <bundle.zip>` | The server user on its host (`sudo -u <server user> benchy ...`) | 128 MiB | `cli`, local actor |
| `?server_catalog` upload | `is_server_admin()`, CSRF token, re-checked in the handler | `post_max_size` (24 MiB); above it the page says to use the CLI | `user`, user name, account in `actor_detail` |
| Root login | `?admin_config` shows the same section | As the web | `root` |
| Packaged bundle | The event server, at start (3.6) | 128 MiB | `system`, `package` |

A tenant admin who isn't a server admin gets the same refusal as on `?server_settings`, and a
forged POST changes nothing.

### 3.6 The packaged public bundle

- The server packages (deb and rpm) ship exactly one public bundle at
  `/usr/share/benchy/catalog/benchy-catalog-public-<g>-<s>.zip`. More precisely, the folder is
  `PTS_PATH . 'catalog/'`, which a package puts at that path. A source-tree install has no such
  folder. That includes a server updated with `deploy.sh`, whose admin imports by CLI. Private
  bundles are never packaged.
- When it runs: in the event server's first loop iteration, after `prepare_database()`, and only when
  the schema is 61 or later. If the migration was put off (its five-minute retry), the import waits
  for a later iteration in the same process. It is never part of the migration.
- Once per bundle: if the file's SHA-256 equals `PackagedSHA256`, nothing happens. Otherwise
  the import runs (via `package`). `PackagedSHA256`, `PackagedOutcome` and `PackagedOn` are recorded
  **only for a final outcome**: `imported`, `unchanged`, `older`, or a refusal about the bundle's
  content (any reason from 3.1 steps 1 to 10). Recording the hash means a bundle that was refused
  for its content isn't audited again on every restart. A **transient** refusal is not recorded,
  and the import is tried again at the next start: another import holding the lock, openssl or zlib
  missing, a damaged key table, the schema not ready, `state_write`, or a disk error. Its audit row
  is written once per process.
- **Forward-only:** a packaged bundle below the stored pair is recorded as `older`, with no audit
  alarm and no notice. Only a real refusal writes `catalog_import_refused` and puts a notice on the
  catalog page and the server settings page.
- **It never fails the upgrade or the start.** Exceptions are caught, the outcome is recorded, and
  the event server carries on.
- Two folders: if more than one bundle file is present, the server imports none of them and the
  notice says so (EC-P2, so only the message).

## 4. Serving

### 4.1 Which tiers are served

```
phoromatic_catalog::served_tiers()            the tiers this server can serve right now
phoromatic_catalog::tiers_for_account($aid)   served_tiers(), filtered for an account; in 0.10 the filter returns every tier (tenancy Q3)
```

A tier is **served** when its row exists, its `KeyID` isn't in the key table's `denied_key_ids`, its
`Generation` isn't below `RetiredBelow`, and `signed/<SignedSHA256>.signed` exists. Load uses
only these checks and never re-applies the floor or needs the key to be in the table. A planned
rotation that drops a key from the table therefore leaves a stored tier served (catalog spec 5.3,
"Floors apply only when an index is first accepted"). A tier that isn't served is left out of
hello and gets a 404 on its routes. The catalog page says why (section 8). `hello`, both routes
and the picker all go through `tiers_for_account()`, so a later per-tenant filter changes one
function.

### 4.2 Routes

Both are added to `phoromatic_api_v2::run()` **before** the POST-only check, with method GET only
(anything else gets `400 bad_request` and `Allow: GET`). They take the same HTTPS rule (426) and the
same `authenticate()` as the other v2 routes.

| Route | Success | 404 `not_found` when |
|---|---|---|
| `GET /api/v2/catalog/{tier}/index.signed` | 200, the stored file's exact bytes, `Content-Type: application/octet-stream`, `Content-Length`, `Cache-Control: no-store`, `X-Content-Type-Options: nosniff`. The file is read into memory (16 MiB at most) and its SHA-256 checked against the row before any byte is sent. On a mismatch the server sends a 404, logs it and sets a notice | `tier` isn't `public` or `private`, or the tier isn't in `tiers_for_account()`, or the file is missing or doesn't match |
| `GET /api/v2/catalog/packs/{pack_sha256}` | 200, streamed with `readfile`, the same headers but `Cache-Control: private, max-age=86400`. The server checks the size against `PackSize` and doesn't hash: the engine hashes every byte and the server can't forge | the hash isn't `^[0-9a-f]{64}\z`, or no `entries` row of a tier in `tiers_for_account()` names it, or the file is missing or the wrong size |

Errors use the usual `error_reply` envelope. The two routes don't update `LastCommunication` or
the system's task, so fetching the catalog isn't treated as a check-in.

**Only the agent parent calls them.** On an engine 0.7.0 agent that is elevated, tests run in a
de-elevated child that has no system token. The parent fetches and verifies the index and packs and
streams them to that child (engine amendment 3.7, H2). So the routes see the same `bsa_` bearer
caller as always. No child token and no new route exist, and the auth table below is unchanged.

### 4.3 Who gets what

| Caller | `hello` reply `catalog` | index route | pack route |
|---|---|---|---|
| Approved system, active tenant, valid token | The tiers of `tiers_for_account()` | 200 | 200 if referenced |
| System not approved | Left out | 403 `system_not_approved` | 403 |
| Tenant suspended | 403 `tenant_suspended`, inherited from `authenticate()` | 403 | 403 |
| Tenant deleted, token revoked or unknown, no token | 401 | 401 | 401 |
| A browser session, a v1 client, a bench token (`bbt_`), a private-repo token (`bpr_`) | n/a | 401: only `bsa_` bearer tokens | 401 |

Because the server can't forge content, sending the public tier to an unapproved system would be
harmless. It isn't sent because an approved system is what the rest of v2 trusts, and because the
private tier names non-free products.

## 5. hello and poll

### 5.1 Capabilities

In the hello handler's existing `UPDATE phoromatic_systems`, which isn't run for a cloned machine,
write `AgentCapabilities` as `json_encode` of `engine.capabilities`. The list is kept to strings
matching `^[a-z0-9_]{1,40}\z`, de-duplicated, at most 64 of them. It is replaced on **every**
hello, so an agent downgraded from 0.6 to 0.5 loses `catalog_v1` on its next start.
`phoromatic_native_admin::has_capability($system_row, 'catalog_v1')` is the one reader. NULL, and
anything that doesn't parse, mean no. The same reader answers `elevation_unattended` and
`elevation_desktop` (6.6).

The engine version for `min_engine` comparisons is read from `ClientVersion`
(`benchy-native x.y.z`). If it doesn't parse, it counts as unknown, and unknown passes the
comparison (a warning only, see 6.4).

### 5.2 `hello_response.catalog`

When the system is approved and `tiers_for_account()` isn't empty:

```json
"catalog": { "tiers": [ { "tier": "public", "generation": 1, "sequence": 3 },
                         { "tier": "private", "generation": 1, "sequence": 2 } ] }
```

Otherwise the key is absent. Older agents ignore it ("Readers ignore fields they don't know").

### 5.3 Rehello after an import

The problem is EC-P7. An admin imports a new release and pins a schedule to its new version
straight away. The next trigger reaches an agent that last said hello hours ago. That agent's
index doesn't have the version yet, so it fails the job `not_found`.

The fix: in `poll`, before choosing a job, if the system has `catalog_v1` and
`phoromatic_catalog_state.ChangedOn` is later than the `hello_on` the system last recorded
(`SystemProperties.benchy_agent.hello_on`), reply with `rehello: true` and the hold "Refreshing
the test catalog before the next job." The agent says hello, refreshes the tier and polls again
at its next interval (60 s). This adds about 15 lines and no table, because `hello_on` is already
stored.

## 6. Schedules: version pins, the picker, labels, warnings

### 6.1 Data

`phoromatic_schedules_native_tests.Version` is NULL (no pin) or `x.y.z`. The unique key stays
`(AccountID, ScheduleID, Ref, OptionsJSON)`, so one schedule can't hold the same test with the same
options at two versions. Changing that needs a table rebuild, which an additive step can't do and a
rollback wouldn't survive. Comparing two versions is done with two schedules for now (EC-P4,
deferred).

### 6.2 What the picker offers and pins

For each test the schedule's systems reported, the picker works out:

- `targets`: the systems the schedule runs on (as today);
- `v1`: whether every target has `catalog_v1`;
- `reported`: each target's reported version for the ref (`phoromatic_system_catalogue.Version`),
  **compared across systems, not taken from the first row**;
- `catalog_versions`: the versions of that id in `entries` of the tiers in `tiers_for_account()`,
  minus every revoked `id@version` in any tier (revocation crosses tiers, catalog spec 8.5), sorted
  newest first by comparing each field as a number.

| Case | Default when adding | Version choice offered | What the picker says |
|---|---|---|---|
| `v1`, all targets report the same version V | Pin V | V, plus every entry in `catalog_versions` | "Pinned to 1.1.0, the version every system has now." |
| `v1`, the targets report different versions | No pin | Every entry in `catalog_versions`. Picking one pins it | "Each system runs its own newest version: lab-1 1.1.0, lab-2 1.0.0. Pick a version to make them match." |
| Not `v1` | No pin | None | "Not pinned: lab-2 runs engine 0.5.0, which can't run a chosen version. Each system runs its own newest version." |

A POST works the whole thing out again on the server and accepts only a version from the list it
would offer. The form is never trusted. The version must also match the job-test pattern
`^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\z`.

**Existing rows.** A row with no pin shows "Not pinned" and, when the first case holds, a "Pin to
1.1.0" button. A pinned row shows its version and, when `v1` holds, a version menu. Both
changes are audited (6.5).

### 6.3 Labels

The tier, the product name and the "Runs as administrator" flag come from the server's own
`entries` row for the test's `id@version` (the pinned version, or the reported one when there is no
pin). That row is looked up only in tiers returned by `tiers_for_account()`, so a later per-tenant
filter also hides labels from tiers the tenant can't see. The per-target status (ready, needs
acceptance, needs setup) comes from each system's hello: its capabilities and the catalogue entry's
`admin` object (6.6).

| From the entry | Label | Warning beside "Add" |
|---|---|---|
| Tier `public` | "Free test" | none |
| Tier `private` | "Non-free test" (never just "private": a tenant's `private/` repo is a different thing, tenancy B9) | none |
| `MachineInstall` set | "Installs *product* for all users" | Per target, from 6.6: "lab-1: ready", "lab-2: a person at the machine has to accept it once (the tray offers this, or `benchy-engine machine-accept`)". Until then the schedule waits on that system. It doesn't fail |
| `ElevatedRun` = 1 | "Runs as administrator" | Per target, from 6.6: "lab-1: runs without asking", "lab-2: someone at the desk approves each run", "lab-3: not set up yet, see its page". It can always be added. Only a tenant level-1 admin can add it (6.6) |
| No entry (an agent imported a bundle this server doesn't hold, or a built-in) | none | "Not in this server's catalog." |

All text from an index (titles, products, revocation reasons) goes through the one escape helper
used on tenant pages (tenancy B7), with control characters removed and a 200-character cap.

### 6.4 Issuing a job

`issue()` adds `"version"` to each `job_test` whose row has one. When the schedule is chosen,
**inside the selection and not after it** (so that a schedule this system can't take doesn't
keep others waiting), a schedule is skipped for a system when:

1. any of its native rows is pinned and the system lacks `catalog_v1` (a §13 refusal test). The
   systems page's "what won't run here" list (`wont_run()`) says: "Schedule *X* pins test versions,
   and this system's engine (0.5.0) can't run a chosen version. Update it to engine 0.6.0 or later."
2. a pinned `id@version` is revoked in any tier. A system warning and a schedule notice say:
   "benchy/x 1.0.0 was withdrawn: *reason*. Pick another version." (EC-P5, handle simply)
3. a pinned version's `entries` row has a `min_engine` above the system's engine version, when that
   version is known. `wont_run()` says what is needed. (EC-P5, handle simply)
4. it holds a test that needs administrator rights and the system can't run it yet, or is waiting
   for an approval (6.6, rules A to D).

When no schedule is left, the hold is the first reason that applied. Rules 1 to 4 skip the schedule
without consuming its trigger, so it runs as soon as the reason is gone. A system that is held
behaves like one that is switched off: the newest trigger of the schedule waits, and older ones
don't pile up.

### 6.5 Tenant audit

These are schedule edits, so they go to the account's `phoromatic_admin_audit` as other schedule
edits do. `native_test_add` gets `version` in its detail (NULL when not pinned), and `admin`
(`elevated_run`, `machine_install`) when the entry declares either. There is one new action,
`native_test_version_set` (`ref`, `options`, `from`, `to`).

### 6.6 Tests that need administrator rights

Dave's rule (2026-11-14): every test runs, administrator tests included, with zero exceptions. The
server never refuses a test for the privilege it needs. It sends the test where it can run, and
holds it, with the fix shown, where it can't run yet. Safety refusals stay: revocation, the
signature, the tier rule at import, and an acceptance nobody has given. The engine side (the two
ways an agent runs such a test, the approval wait, one-time setup and the trust chain) is in
benchy-engine `spec/elevated-scheduled.md`. This section is the server's part.

**What the server knows about a system**, all from hello (engine amendment section 2):

- `elevation_unattended` among the capabilities: the agent runs elevated, from a protected binary and
  home, and the machine's policy allows scheduled administrator runs. It runs such tests with no
  prompt.
- `elevation_desktop`: the agent was started by the desktop client in someone's session, and the
  handshake holds. Each run waits for that person to approve it, then raises UAC.
- Neither: **not set up**, with `engine.not_set_up_reason` from an engine 0.7.0 or later. An older
  engine sends neither and no reason.
- Each catalogue entry's `admin` object: `elevated_run` and `machine_install` (for the reported
  version), `accepted_versions` (every version of that id an administrator at the machine has
  accepted, B1), and `installed` (the vendor product is there).

`phoromatic_v2_jobs` gains a nullable `DeferredKind` column in step 61, filled from
`end.deferred_kind` (B2). It is a tenant table, so the column is bound to `AccountID` like the rest.

**The version a rule looks at** is the one the job would send to that system: the row's pin, or,
with no pin, that system's own reported version.

**Issue rules** (rule 4 of 6.4, inside the selection), checked in this order. Each one holds the
schedule for that system only and leaves its trigger unconsumed:

- **0. Engine too old (M3).** The schedule holds a test with `elevated_run` or `machine_install`, and
  the system's engine (from `ClientVersion`) is older than 0.7.0. Hold: "Update the engine on this
  system to 0.7.0 or later." This comes first, because an older engine reports no capabilities and no
  reason, and rules A and B would otherwise give the wrong fix.
- **A. Not set up.** The schedule holds a test that, at that version, has `elevated_run`, or has
  `machine_install` with `installed: false` (the installer needs administrator rights too), and the
  system has neither capability. Hold: "This system can't run tests that need administrator rights
  yet", plus the fix below. **`installed` describes only the reported version.** When the job's
  version differs from the reported one, `installed` counts as unknown: rule A doesn't hold for the
  install on that basis. The agent checks `detect` itself, and if it needs an installer it can't run,
  it ends the job `deferred` with `install_needs_setup`, and rule C holds it from then on.
- **B. Not accepted.** A test in the schedule has `elevated_run` or `machine_install`, and its
  version isn't in that system's `accepted_versions`. Hold: "Someone at this machine has to accept
  *title* *version* once." The poll reply's `hold_detail` (`{kind: "acceptance_needed", tests:
  [{ref, version}], fix}`) lets the tray offer **Review and accept…**.
- **C. Deferred.** The system's newest job for this schedule ended `deferred` with a
  `deferred_kind`. Hold it on that system by kind, and release it when its cause has changed, or
  after 6 hours at the latest, whichever comes first:

  | `deferred_kind` | Released by |
  |---|---|
  | `approval_declined`, `uac_declined` | A poll with `ready_for_approval.schedule_id` equal to this schedule (someone clicked **Approve scheduled run…**) |
  | `not_set_up`, `install_needs_setup` | A hello after that job that reports `elevation_unattended` or `elevation_desktop` |
  | `acceptance_needed` | A hello after that job whose `accepted_versions` holds the version |
  | `suite_changed` (6.7) | A re-confirmation of the suite on the console, or a hello whose reports match the confirmed members again |
  | absent (the old meaning: a local run won, or the agent stopped) | Nothing held: today's behavior |

  Rules A and B usually catch these cases before issue. Rule C covers what the server couldn't see
  in time: a state change between hello and poll, a tray that closed, a click nobody made.
- **D. Issue.** Otherwise the job goes out as usual. On an `elevation_desktop` system, the job's
  `log{notice}` with `detail.kind: "awaiting_approval"` sets the system's current task to "Waiting
  for administrator approval at the machine", and the job page shows the same. While it waits, that
  system takes no other job, for up to 30 minutes (engine amendment 3.4, E9). The system page shows
  how long it has been waiting.

A schedule that mixes ordinary tests with an administrator test is **held whole** on a system that
can't run the administrator test. It isn't split, because a partial result would differ silently
from what the schedule measures (+2). On a system that can run it, the administrator test runs with
administrator rights and the loose ordinary tests run without them (engine amendment 3.7). A
**suite** with an administrator test is different: it runs whole and elevated (6.7). Q12 has the
answer.

**On a machine where it runs, what runs as administrator.** On an Unattended system the agent runs
every test that isn't `[elevated_run]` with a standard-user token (Dave's E2 decision), so only
signed, accepted administrator tests get administrator rights, plus the members of a confirmed
elevated suite. The systems page says so in its "Administrator tests" row, and each result entry
shows `info.elevated`.

**The fix the systems page shows (S4)** depends on what the system reports:

| The system reports | Text |
|---|---|
| An engine older than 0.7.0 (from `ClientVersion`), checked before anything else (rule 0) | "Update the engine on this system to 0.7.0 or later. It can then run tests that need administrator rights." |
| `no_desktop_client`, Windows | "Start the server agent from the Benchy tray (Server…, Start when I log in), and each run asks you first. Or, for a machine nobody sits at, once as administrator: `benchy-engine agent setup-elevated`." |
| `elevated_without_policy` | "This system's agent already runs as administrator, which happens when User Account Control is off or the built-in Administrator account is used. Allow scheduled administrator tests once, as administrator: `benchy-engine agent setup-elevated`." |
| `binary_not_protected` | "The unattended agent isn't running from the protected copy. Start it from the task that setup made, or run `benchy-engine agent setup-elevated` again." |
| `home_not_protected` | "The unattended agent's folder isn't protected. Run `benchy-engine agent setup-elevated` again." |
| `policy_unreadable` | "The policy file on this system can't be read safely, so it refuses administrator tests. An administrator should fix or remove `Benchy Policy\policy.toml`." |
| Linux, any reason | "Once: `sudo benchy-engine agent setup-elevated --system-unit`." (Linux administrator tests don't exist yet, so this shows only when one does) |

The same text goes into the poll `hold_detail.fix`, so the tray shows it too.

**Who can add one, and who can change such a schedule (S3, H5).** A schedule is an **administrator
schedule** while any of its native rows either names a test whose `entries` row, at any version in a
visible tier, has `elevated_run` or `machine_install`, **or is a suite row (`Kind = suite`) whose
suite is elevated (6.7)**. These need **tenant level 1** (an account
admin, not a level-2 user):

- adding such a test, or changing its version;
- on an administrator schedule, changing its targets (systems and groups), its triggers and times,
  or whether it is enabled, or deleting it;
- changing a system's groups when that adds the system to the targets of an enabled administrator
  schedule. This is the indirect way to aim one at another machine.

A level-2 user sees these controls disabled, with "An account administrator has to change this,
because the schedule runs tests that need administrator rights." Every POST handler checks the level
again, so a forged form changes nothing. Each change is audited in the account's
`phoromatic_admin_audit` as `admin_schedule_changed`, with `schedule`, `field`, `from`, `to` and the
administrator tests involved. Removing an administrator test from a schedule is allowed at level 2,
because that only narrows what runs, and it is audited the same way. This is defense in depth.
For a loose `[elevated_run]` test, the machine is the boundary: it runs elevated only with an
administrator's acceptance record for that exact digest at the machine, plus either the machine's
policy consent (unattended) or a person's click at the desk. The server can't produce either.
**Inside a confirmed suite the boundary is wider (H8):** every signed member also runs as
administrator. The machine checks that each one is signed and not revoked, but not that it was
reviewed as administrator code. The review rule (engine amendment Q9) covers that.

**Trust chain, as the server sees it.** The server chooses which accepted test runs and when. That
is all a scheduled elevated run lets it do, and it is the dangerous part, so the engine checks the
rest itself before elevating. It runs only signed, private-tier, non-revoked entries from its
accepted index. It requires the record for the digest it computes from its own store. It reads
policy. It allows choice and number options only. It sends no server variables to the elevated
side. The run host then verifies the copy. The server adds nothing that can widen that: no job
field, setting or option reaches the elevation decision.

### 6.7 Native suites, and suites with an administrator test

Dave (2026-11-14): "For test suites with an admin test, run the suite elevated with a warning to
the administrator prior as a final check when saving the test suite on the admin console."

**Native suites on the server (new).** Today a schedule holds loose native tests, and the server has
no native suites. This adds them:

- `phoromatic_native_suites` (`AccountID`, `SuiteID`, `Title`, `Revision`, `CreatedOn`, `CreatedBy`,
  `ConfirmedRevision`, `ConfirmedOn`, `ConfirmedBy`, `ConfirmAuditID`, `ConfirmedJSON`) and
  `phoromatic_native_suite_members` (`AccountID`, `SuiteID`, `Position`, `Ref`, `Version`,
  `OptionsJSON`). Both are tenant scope, made in step 61, and go into `tables.json` as tenant.
  `Revision` goes up with every saved change.
- `phoromatic_schedules_native_tests` gains `Kind TEXT NOT NULL DEFAULT 'test'`. A row of kind
  `suite` has `Ref` set to `native:<SuiteID>` or to a signed index suite (`benchy/<id>`, with
  `Version`). **A signed suite that contains an administrator test is never a schedule row by itself
  (H4).** When an admin adds one, the console wraps it in a native suite of the account. Its members
  are the signed suite's tests, expanded to concrete versions and options, and its title says which
  signed suite it came from. The confirmation, the snapshot and every lapse rule below then apply to
  it unchanged. This was chosen over a confirmation record on the schedule row because it gives one
  confirmation model, one page and one set of lapse checks, and a signed suite's members can be shown
  and pinned like any other. A signed suite with no administrator test can still be a row on its own.
- A suite page (`?native_suites`) builds one from what the account's systems report and from the
  signed catalog, with the same version and option rules as the picker (6.2). The picker can then add
  a suite to a schedule as one row.
- At issue the suite is expanded into its tests in the job, with the engine amendment's `suites`
  object (4.2) for agents that report `elevated_suite_v1`.

**An elevated suite** is a native suite with a member that, at its version, has `ElevatedRun` in a
visible tier. A signed suite with such a member is always wrapped first (above). It runs **whole and elevated** on every target:
ordinary members included, `install.*`, `check.*` and trials alike. At the desk that is one approval
and one UAC prompt for the whole suite. Unattended, there is no prompt (engine amendment 3.8).

**The final check when saving.** Saving any change to an elevated suite needs tenant level 1, as do
creating one, adding an administrator test to a suite, and adding an elevated suite to a schedule or
changing that schedule's targets, triggers or enabled state (S3). Before the save, the console shows a
confirmation page, and nothing is written until it is confirmed:

```
Run this whole suite as Administrator?

"Workstation full run" contains PassMark PerformanceTEST, which runs as administrator, so every test
in this suite runs as Administrator on every machine it is scheduled on. Its install steps, checks
and runs all have full control of those machines.

  PassMark PerformanceTEST     benchy/passmark 11.0.0     signed (non-free)   runs as administrator
  Zstd Compression             benchy/compress-zstd 1.0.0 signed (free)
  Build PHP                    benchy/build-php 1.0.0     signed (free)       compiles code during install
  Office macro timing          acme/office-macro 2.1.0    NOT SIGNED (repo)   takes free text: "macro name"
  Our in-house script          local/inhouse 0.3.0        NOT SIGNED (local on lab-2)

The two unsigned tests above are not signed by the Benchy catalog. After you save, they run as
Administrator on machines whose administrator allows unsigned tests in such suites (lab-1). On the
other machines (lab-2, lab-3) they still run, without administrator rights, and the result says so.
"Office macro timing" takes free text that this server fills in, and that text reaches its script.

Tests written for PowerShell 7 may not work here: elevated tests run under Windows PowerShell 5.1.

[ ] I have checked every test above and want this suite to run as Administrator.
                                                   (Cancel)  (Save and run as Administrator)
```

- The page lists **every** member. Each unsigned member (a repo or local test, or any test with a
  `text` option) is named on its own line in the paragraph, not only in the table, because after the
  save it may run as administrator. A suite not yet in any schedule says "on every machine a schedule
  runs it on".
- The per-machine sentence comes from hello. Machines whose policy allows unsigned members report the
  capability `elevated_suite_unsigned` (engine amendment Q7).
- Confirming writes, in one transaction with the save, a `native_suite_elevation_confirmed` row to
  the account's `phoromatic_admin_audit`. It records who, the suite and its new revision, and every
  member: `ref`, `version`, signed or unsigned and its source, the `text` options, and the
  `tree_sha256` each reporting system gave for it. The same snapshot goes into `ConfirmedJSON`, and
  `ConfirmedRevision` is set to `Revision`.

**When a confirmation lapses.** At issue, a suite is held on a system (6.4 rule 4, shown as
"Waiting for an account administrator to confirm this suite again") when:

- `ConfirmedRevision` isn't `Revision`: any member, version or option changed since the last
  confirmation. The next save shows the warning again;
- a member is now different from the snapshot for that system: its version was revoked, it went from
  signed to unsigned (a new unsigned version, or a signed version no longer listed), or its reported
  `tree_sha256` on that system isn't one the snapshot holds. That includes a system that wasn't
  reporting the member when the suite was confirmed;
- the agent ended its last job of this suite `deferred` with `suite_changed` (rule C).

The schedule and suite pages say which member changed, and a level-1 admin re-confirms from there.
A held suite isn't a failure, and its trigger isn't consumed.

**A local member on an unattended system (M5)** isn't a lapse, and confirming again wouldn't help. An
unattended agent never runs local tests as members of an elevated suite (engine amendment Q8). On
such a system the suite is held with its own text: "This suite has a local test (*title*), and this
system's unattended agent doesn't run local tests inside a suite that runs as administrator. Use a
repo test instead, or run the suite on a system where someone approves it at the desk." Loose local
tests still run on that system, without administrator rights.

**What the server sends, and why the machine doesn't simply obey it.** The job's `suites` object
carries the confirmation (`by`, `at`, the audit id) and the confirmed member list with the tree hash
the server expects on that system. The agent treats it as permission to widen elevation only as far
as it can verify itself (engine amendment 3.8). Signed members are re-checked against both tiers'
signed indexes by the run host, and unsigned members are elevated only where the machine's
administrator allowed it. The server can't sign this flag in a way that helps: the key would be on
the same server a thief would hold. Scores: a compromised server marking a suite confirmed, EC-P2
(+2) → 4, deferred. That is limited to signed, non-revoked members, **but each of those members is
then trusted administrator code**, and only the review rule (engine Q9) stands behind that. A
level-1 admin putting an unsigned test in such a suite: EC-P5 (+2) → 7, which gets the warning, the
audit row and the default de-elevation.

**Hold rules still apply.** The suite's administrator test still needs a system that can elevate
(rule A), an accepted version (rule B), and an approval at the desk (rule C). An agent that can
elevate but lacks `elevated_suite_v1` (an engine that ships administrator tests before suites) is
held with "Update the engine on this system to run suites with administrator tests."

**Rollback.** A 0.9.13 server sees a suite row as a test whose ref is `native:12` and issues it, and
the agent fails that job `not_found`. That is EC-P3, +1 for a failure with an unclear message,
adjusted 4. Deferred: a line in the rollback notes.

## 7. The non-free tier, `[machine_install]` and `[elevated_run]` on the server

- **The tier is in the signed index**, so the server serves an index under its own tier's route and
  nowhere else, and the route's tier can't be chosen by the bundle's file name.
- **Public tier:** import check 4 (3.1 step 9) refuses any entry that isn't `free`, or that has
  `machine_install` or `elevated_run`. This is the fourth of the spec's four independent checks.
  The engine also skips such an entry by itself (6.2 step 12).
- **Non-free tier:** imported only by a server admin's choice, normally by CLI (its bundles are
  large and come from the private `benchy-tests` releases). It is never packaged and never attached
  to a public release (the fifth guard, section 10). In 0.10 every tenant on the server sees it
  (Q3). The importing account is recorded so a later release can filter it without a data
  migration.
- **The server doesn't open packs** and doesn't validate manifests. Everything it shows comes from the signed
  index entry. `machine_install.product` and the presence of `elevated_run` are read from
  `manifest` with the strict rules in 3.3.
- **Installer files** named by `[machine_install]` are fetched by the engine from its `files.toml`
  pins through the usual mirrors. That includes this server's own `v2_mirrors`, which doesn't change. The
  server never fetches anything for the catalog (no phone-home).
- **Agents elevate only by the machine's consent** (6.6 and the engine amendment): an acceptance
  record per version, plus the machine's policy for unattended runs or a click at the desk. The
  server adds no setting, job field or option that takes part in that decision, and doesn't send
  `BENCHY_VAR_*` to a catalog test any differently than today.
- **License keys:** none are in a pack or an index (9.2), so the server holds none. Activation is
  done by a person at the machine.

## 8. What a server admin sees

### 8.1 `?server_catalog`

A `server*` page, gated like `?server_settings`, with the same sections on `?admin_config` for the
root login. It is linked from `?server_settings` today, and from T4's console menu once T4 exists.

```
Test catalog
These signed tests are shared by every account on this server. Agents fetch them from here and
check Dave's signature themselves, so this server can't change them.

 Tier       Status    Release          Signed by            Issued        Tests  Suites  Withdrawn
 Free       Served    generation 1, 3  current key (gen 1)  2026-11-20    14     2       0
 Non-free   Served    generation 1, 2  current key (gen 1)  2026-11-20    6      0       1
 Retired keys: none (generations below 1)

 [ Import a catalog bundle: (choose file) (Import) ]   Larger than 24 MB? On the server, run:
   sudo -u phoromatic benchy server-catalog-import /path/to/benchy-catalog-private-1-2.zip

 Free tests                      Version  Platforms        Notes
 benchy/compress-zstd            1.0.0    linux
 ...
 Non-free tests
 benchy/passmark                 11.0.0   windows          Installs PassMark PerformanceTEST 11 for all users; Runs as administrator
                                                           (runs on systems set up for administrator tests; see each system's page)
 Withdrawn
 benchy/example 1.2.0 (non-free): Plain text for an admin.

 Recent catalog events (from the server audit)
 2026-11-20 15:02  dave (web) imported the free tier, generation 1 sequence 3 (was 2): 1 test added
 2026-11-20 14:10  package import at start: refused (license_tier): the free catalog lists ...
```

**Notices** (red when something isn't served):

| Condition | Text |
|---|---|
| Packaged bundle refused | "The catalog bundle that came with this version of Benchy was not imported: *message*. The catalog you had is unchanged." |
| Tier not served: key denied | "The *free* catalog was signed with a key that is no longer trusted. Import a newer release." |
| Tier not served: retired | "The *free* catalog was signed with a retired key (generation 1). Import a newer release." |
| Tier not served: file missing or wrong | "The *free* catalog's stored file is missing or damaged. Import the same bundle again to repair it." |
| A pack file missing | "*n* test packs are missing from storage. Import the same bundle again to put them back." (checked by `stat` when the page loads) |
| `issued` more than 365 days old | "The *free* catalog is more than a year old. Check for a newer release." (catalog spec 3) |
| `same_pair_differs` refused, ever | "Two different catalogs with the same release number were offered. That means the signing process or a key is broken. Tell the catalog's publisher." This stays until a later import of that tier succeeds |
| openssl or zlib missing, key table damaged | What is missing, and that imports are refused while the stored catalog is still served |

### 8.2 CLI

```
benchy server-catalog-import <bundle.zip>   import (the server user, on the server host); prints the outcome
benchy server-catalog-status                tiers, pairs, key, issued, counts, served or why not, retired_below, last packaged attempt
benchy server-catalog-keys                  the key table in the engine's `catalog keys` format (for the release gate)
benchy server-catalog-check <bundle.zip>    steps 1-10 against an empty state and the floors, writes nothing, needs no
                                            server storage; prints "tier generation sequence tests suites revoked"; exit 1 on refusal
```

On success, `server-catalog-import` prints, for example, "Imported the free catalog: generation 1,
sequence 3 (was sequence 2). 1 test added, 0 withdrawn. Agents pick it up when they next check
in." A refusal prints the reason word and the message, and exits 1.

## 9. Audit

`phoromatic_server_audit::ACTIONS` gains `catalog_imported` and `catalog_import_refused`.
`TenantID` is NULL (the catalog is a server thing). `SubjectType` is `catalog_tier` and `SubjectID`
the tier (NULL when a refusal came before the tier was known).

| Action | Detail |
|---|---|
| `catalog_imported` | `via` (cli, web, package), `bundle_sha256`, `key_id`, `generation`, `sequence`, `from` (the previous pair or null), `recovery`, `unchanged`, `tests_added`, `suites_added`, `revoked_added`, `retired_below` (from, to), `restored` (index, packs) |
| `catalog_import_refused` | `via`, `bundle_sha256`, `reason` (the stable word), `message` (300 characters at most), the claimed `key_id` and pair when known |

A refused import writes its row after the work folder is removed, outside any transaction. A
successful one writes inside the commit transaction, so there is never a row for an import that
didn't happen, or an import without a row. Tenants never see these rows. A server admin sees them
on `?server_catalog`, and from T4 on `?server_audit`.

## 10. Key table and the release gate

`deploy/release.sh check <version>` gains three checks, and a new step `catalog-bundle` is added
next to `linux-assets`:

1. **Keys.** Make a `git archive` of the tag, run `./benchy server-catalog-keys` from it, and
   `diff -u` that output against `legal/catalog-keys.txt` from `benchy-tests`' default branch,
   fetched with `gh api repos/dakotlyar/benchy-tests/contents/legal/catalog-keys.txt` (Q6). It
   fails on any difference in ids, generations, denied ids or floors, and it fails closed when `gh`
   or the file isn't there. It is the same list and the same text format as the engine's
   `scripts/check-catalog-keys.sh`.
2. **No test key.** Fail if `pts-core/static/catalog/keys.json` at the tag holds an SPKI other than
   the two the comparison accepted, or if any file outside `tests/` contains the id of the engine's
   fixture key (`d98e6e86a8584efd075cc0fc8155f980`) or of a key from `tests/catalog/`.
3. **Bundle pin** (only when `runtime-pins.json` has a `catalog.public` pin): the pin is `file`,
   `size`, `sha256`, `generation`, `sequence` and `urls`. Fetch it with `fetch_pinned` (the cache,
   or `--catalog-bundle <path>` the first time, before the file is a release asset anywhere). Run
   `./benchy server-catalog-check` on it from the archived tree, and require tier `public` and the
   pinned pair. **This is the fifth guard (catalog spec 9.3): a public Benchy release can only carry
   a bundle whose signed tier is public.**

`catalog-bundle <version>` puts the checked file in the output folder with a `.sha256`, for
attaching to the public release. `build-package-deb.php` and the rpm build copy it to
`catalog/` in the package tree. A tag whose pins have no `catalog` builds as before.

## 11. 0.9.14: the two error codes (C0)

The two codes have to go in before the engine pin moves to an engine that can send them. The
reason is concrete: the server validates every result upload against `result-v1.schema.json`. An
engine 0.6 job can run some tests and then hit `catalog_refused` on a later one, for example when
a store directory fails its tree check twice (catalog spec 6.3 step 10). The result then has an
entry with that code, the server answers `422 result_invalid`, the agent stops retrying, and **the
results of the tests that did run are lost** (EC-P3, +2 data loss, adjusted 5: handle simply).

- `pts-core/static/json-events/v1.schema.json` (Benchy owns this file): add `catalog_refused` and
  `catalog_state_corrupt` to `error_code`, and say so in `documentation/json-events.md`. The engine's
  `spec/contract-v1/PIN` then moves to that Benchy commit and drops its `local-change` note.
- `pts-core/static/protocol-v2/result-v1.schema.json` and `v2.schema.json`: the same two codes,
  recorded in `PIN` as a `local-change` until E4.4 re-pins the whole folder to the engine tag that
  carries the catalog fields (`hello_response.catalog`, `job_test.version`). This mirrors what the
  engine did, and avoids pinning to an untagged commit.
- No new test: the existing protocol-v2 conformance set and the schema checks cover the enum.

**The pin plan after C0 (S5).** Benchy's `pts-core/static/protocol-v2/` copy moves in three steps,
and `PIN` says which one it is at:

| When | What the copy is | `PIN` says |
|---|---|---|
| 0.9.14 (C0) | The v0.3.0 copy plus the two error codes | "v0.3.0, local-change: catalog_refused, catalog_state_corrupt" |
| C3 | A re-pin to engine **v0.6.0** (`hello_response.catalog`, `job_test.version`), plus the 0.7.0 fields as a local change: the `elevation_*` capabilities, `engine.not_set_up_reason`, the catalogue `admin` object, poll `ready_for_approval` and `hold_detail`. C4 adds `end.deferred_kind` the same way | "v0.6.0, local-change: <each field>, from benchy-engine `spec/elevated-scheduled.md` 4.2" |
| The engine's v0.7.0 tag | A second re-pin, with no local change left | "v0.7.0" |

A local change is only ever something the engine's main branch already has in its schema, or the
amendment's 4.2 table if it isn't built yet. The conformance set compares the local fields with
that table, so the two can't drift silently. The release gate refuses a Benchy release whose `PIN`
lists a local change the engine tag it names already carries, which means the re-pin was
forgotten.

## 12. How E4.4 fits next to tenancy T3 and T4

- **Migration numbers.** T3 and T4 have no schema step. T5 and T6 take the next two (tenancy B4).
  E4.4 takes **61** if it merges before T5, which this plan expects. "The second branch to merge
  takes the next number" still holds: the step's body doesn't depend on its number, and if T5
  merges first, E4.4 becomes 62 with no other change. E4.4 doesn't touch the T1 or T2 steps.
- **The console page.** `?server_catalog` is E4.4's own `server*` page, built the way
  `?server_settings` is, with no console framework. If E4.4 lands first, T4 adds it to its menu,
  its `?server_audit` shows the two new actions with no extra work, and its post-upgrade notice can
  name a refused packaged bundle. If T4 lands first, E4.4 adds the menu entry. The only shared code
  is the page header and the escape helper that already exist.
- **T3** (each tenant's own `private/` repo view) has nothing in common with the non-free catalog
  tier except the word "private", which the UI avoids (6.3).
- **T2's native-ref fix** needs nothing more: catalog ids are all `benchy/`, which is native by
  pattern (`is_native_ref()`), so tenancy B9's line "or the server catalog" is already true.
- **Tenancy proof tooling.** The five tables are server scope in `tables.json`, their queries are
  on the `allow_sql.json` list, and the two routes and `?server_catalog` are in `routes.json` (the
  route lint fails without them). N23 extends to the catalog routes (suspended gets 403, with no
  state change). T4's purge test (N27) checks that the catalog is byte-identical before and after.
- **The harness.** E4.4 checkpoints use the docker harness. One full `run.sh all` per checkpoint,
  scheduled one at a time with tenancy. C1 is mostly PHP unit work and can be written while T3
  holds the harness. A new suite, `run.sh catalog`, runs on its own between full passes.

## 13. Upgrade, rollback, and the first signed release

**Upgrade to the E4.4 release.** Step 61 runs on the first database open. It is additive, backed up
and locked. On a packaged server, the first event-server loop then imports the packaged public
bundle. Agents get the pairs at their next hello and refresh. Older agents ignore the field and
keep their built-ins. Nothing is pinned until someone adds or pins a test.

**For the release notes and the runbook:** from E4.4 on, a `[machine_install]` test on a system whose
engine is older than 0.7.0 is held with "Update the engine to 0.7.0", even when its product is already
installed and accepted (6.6 rule 0).

**Rollback to 0.9.13** (`run.sh rollback OLD=v0.9.13` gets a case for this):

| What | After rollback |
|---|---|
| Catalog tables and files | Ignored, unchanged. Re-upgrading finds them as they were, and the packaged import is a no-op |
| hello | No `catalog`. Agents keep what they accepted (forward-only) and run what they already installed |
| Routes | 404. An agent that needs a pack it doesn't have fails that job `download_failed` |
| Version pins | **Ignored: the old server sends no `version`, so each system runs its own newest version.** The result records the version that ran. The release notes and the runbook say so (EC-P3, +2 silently different result, adjusted 5: a note, plus one assertion in the rollback check that nothing crashes) |
| `AgentCapabilities` | Ignored |

Data rollback means restoring the pre-migration backup, as for tenancy.

**The first signed catalog release.** This depends on Dave and is outside the server code:

1. Dave runs `benchy-catalog build --first-release`, `verify` on both tiers, then `prepare`, signs
   each tier with openssl on his own machine, and runs `finish` for each into one release folder. The
   result is `benchy-catalog-public-1-1.zip`, `benchy-catalog-private-1-1.zip` and `ledger.json`,
   which goes into benchy-tests.
2. Rehearsal on a docker harness server that has the production key table: `server-catalog-check`
   and `server-catalog-import` for both bundles, then an engine 0.6.0 agent (once tagged) does hello,
   refresh, a pinned job and a pack fetch. Before the tag, the same flow runs with a catalogtest
   engine build and test keys. That proves the code, not the production keys.
3. The public bundle is pinned in `runtime-pins.json` for the next Benchy release, and the release
   gate checks it (section 10).
4. On Dave's own server, which updates from the source tree: `sudo -u <server user> benchy
   server-catalog-import benchy-catalog-public-1-1.zip`, the same for the non-free tier if he
   wants it there, then `server-catalog-status`.

## 14. Refusal tests

### 14.1 How they're built

- `tests/catalog/` holds a Python driver and fixture builders, run as `run.sh catalog` and as
  part of `all`. Each run makes two P-256 keys with `openssl genpkey` inside the container, as
  generations 1 and 2, writes their public halves to `keys.test.json`, signs fixtures with
  `openssl dgst -sha256 -sign`, and deletes the private halves when it ends. No private key is ever
  committed or kept.
- `tests/catalog/engine-fixtures/` is a copy of the engine's
  `internal/catindex/testdata/fixtures/` with a `PIN` (engine commit). It is checked against
  `expected.json` with that folder's own fixture key, which is used only for this test. This is
  the shared parity check from catalog spec 5.2.
- **"Nothing was written"** is one assertion used by every refusal: the catalog tables' row hashes,
  a manifest of `<storage>/catalog/{signed,packs}` (`work/` empty), the hello reply's `catalog`
  and both routes' bytes are all the same before and after, and there is exactly one new
  `catalog_import_refused` row with the expected `reason`.
- A counting parser is passed into the verifier, and refusals at steps 1 to 5 assert it was called
  zero times.

### 14.2 The catalog spec's §13 list, server side

Every item below is a separate test. Items in §13 that only the engine can test (pack contents
under 7.1, installs, selection, authoring, machine install on Windows, signing) are not repeated
here.

| §13 group | Server test (reason expected) |
|---|---|
| index | unknown key (`unknown_key`); denied key (`denied_key`, from a table that denies it); below `retired_below` (`retired_key`); bad signature, one flipped byte, **malformed DER where PHP returns -1** (`bad_signature`, engine fixtures and our own); correctly signed malformed JSON (`bad_index`); `sequence` 0 and 2^53 (`bad_index`); `min_generation` above the signer (`bad_index`); lower pair (`rollback`); **equal pair, different bytes** (`same_pair_differs`, and the notice); below the floor on first contact (`floor`, from a table with a floor); duplicate `id@version`; **ids with a backslash, a colon, `..`, uppercase**, one test each; an id used by a test and a suite; **a suite naming a test outside the index, including `benchy/stream`** (all `bad_index`); ledger conflict for a test and for a suite (`ledger_conflict`); **a dropped revocation** (`revocation_dropped`); a listed version that is revoked (`revoked_listed`); repo other than `benchy`, a `pts/` id (`bad_index`); over each limit: bundle size, member count, `index.signed` size, signature line size, 1001 tests, 1001 suites, a pack over 64 MiB (`limit`); **a malleated signature and a respaced signature line at an equal pair: no alarm, no state change, the stored file kept**; bytes parsed before verification (counting parser) |
| index, server-only | a key spelled in another case (`Min_Generation`, `Tier`, `Machine_Install`) (`bad_index`); a float or big integer for `sequence` (`bad_index`); a key with a non-ASCII character (`ſequence`, a Kelvin-sign key) (`bad_index`) |
| route tier | the index route for tier X never returns the other tier's file; a bundle named `...-public-...` that holds a private index is stored and served as private |
| recovery and rotation | **a generation-2 recovery index accepted after a generation-1 index at sequence 2^53-1**; **accepted after a poisoned ledger, and the poisoned row replaced**; a thief's revocation cleared; **a recovery-shaped index on a tier already at that generation continues its sequence and goes through the ledger**; **a routine rotation keeps revocations and the ledger, and a second-phase index isn't treated as a recovery**; **after the second phase, a retired key signing the other tier is refused, including a tier the server has never held** |
| forward compatibility | **an entry with a future `min_engine` and an unknown option kind is accepted and served** (the server doesn't validate manifests), and a suite naming it too |
| stored indexes | **a key table with a raised floor, or without the key, leaves a stored tier served**; denying the key stops it being served and advertised; **a planned generation bump of one tier leaves the other served**; after a signed `min_generation` the other tier is neither served nor advertised |
| crash and corruption (server's own) | a failure injected after the file writes and before `COMMIT` leaves the state unchanged and the files unreferenced, they are collected, and the next import succeeds; a tier whose stored file is missing or altered isn't served and the notice shows, and re-importing the same bundle repairs it; files present with no rows count as first contact |
| bundle records (7.1 and 7.2 before the signature), one fixture each | data before the first entry; data after the end record; a zip comment; zip64; multi-disk; a local header disagreeing on name, method, sizes or CRC; overlapping and gapped ranges; a data descriptor after an entry that declares one is **accepted**, both with and without its signature, with **zero CRC and sizes in the local header as Go's writer writes them**; a data descriptor that disagrees with the central directory is refused; method other than store or deflate; encryption; a symlink, setuid and a directory entry; names equal under case folding; a member decompressing to a size other than declared; a member name other than the two allowed forms |
| import | **a license failure writes nothing**: a `non-free`, `unstated`, `machine_install` and `elevated_run` entry in a public index, one each (`license_tier`); **a successful import advances the state, and an older bundle is then refused**; an extra bundle member; a pack not in the index; an index entry with no pack; a pack whose bytes don't hash to its name or `pack_sha256` (`bad_bundle`); a replayed lower pair; **re-importing repairs a lost pack**; openssl missing (the precondition function with a stub, since the docker PHP builds openssl in); **a refused packaged bundle leaves the upgrade, the start and the catalog intact**, and isn't audited again on the next start; **a pinned job isn't issued to an agent without `catalog_v1`**, and the systems page says why; **no pin is recorded when a target lacks `catalog_v1` or systems report different versions**; a tier below `retired_below` is neither served nor advertised |

### 14.3 Server-only tests (adjusted EC 7 or higher)

| Test | Why it must exist |
|---|---|
| A tenant level-1 admin who isn't a server admin: page refused, forged POST and forged upload change nothing | Security boundary, EC-P6 (+2) → 8 |
| CSRF token missing on the upload: refused | Same boundary → 8 |
| A suspended tenant's agent: 403 on both routes, no catalog in hello (N23) | Tenant boundary, EC-P5 (+2) → 7 |
| An unapproved system: no catalog in hello, 403 on both routes | Same, EC-P5 (+2) → 7 |
| No catalog at all: hello has no `catalog`, both routes 404, and an engine 0.6 agent keeps its built-ins | Golden path on every fresh install, EC-P9 |
| End to end in docker: enroll a catalogtest engine agent, import, hello, refresh, a pinned job, pack fetch, result | Golden path, EC-P10 |
| Import, then pin the new version straight away: the next poll says rehello before any job | EC-P7, section 5.3 |
| Re-importing the current bundle: no state change, one `unchanged` audit row | EC-P7: admins re-import when unsure |
| Mixed fleet: one target on engine 0.5 and one on 0.6, a pinned schedule issues only to the 0.6 one, and the other system's page says why | Version skew, EC-P10 |
| Administrator test, three targets (`elevation_unattended`, `elevation_desktop`, neither): issued to the first two; the third is held with the setup fix, no job row, **trigger not consumed**; it is issued once that system's hello reports a capability | Dave's rule; most fleets mix machines, EC-P8 |
| Administrator test whose job version isn't in `accepted_versions`: held with `hold_detail.acceptance_needed`, trigger not consumed, issued after a hello that lists it. **A schedule pinned to 11.0.0 on a system that reports 11.1.0 and has accepted only 11.1.0 is held** | EC-P9: every new version starts unaccepted |
| Machine-install test with `installed: false` on a not-set-up system: held (rule A) | EC-P8: a fresh machine is the normal case |
| Each `deferred_kind` holds that schedule on that system, and is released by its own cause or after 6 hours (rule C table); a `deferred` with no kind holds nothing | Old and new agents mixed, EC-P10 |
| Fix text for an engine before 0.7.0, and for each `not_set_up_reason` (UAC off gives `elevated_without_policy` and the setup command) | EC-P8 |
| An `elevation_desktop` job that ends `deferred` with "nobody approved": that schedule is held on that system and others still run; a poll with `ready_for_approval: {schedule_id}` for that schedule issues it straight away, and one for another schedule doesn't; after 6 hours it is issued again; **never `failed`, never consumed** | EC-P8: people are away from their desks |
| The `awaiting_approval` notice sets the system's task text and the job page | EC-P8, the same |
| A level-2 user can't add an administrator or machine-install test, change its version, change an administrator schedule's targets, triggers, enabled state, or delete it, nor add a system to a group an enabled administrator schedule targets. **The same holds for a schedule whose only administrator content is an elevated suite row** (delete, re-aim by targets or by group). A forged POST changes nothing. Each level-1 change is in the tenant audit as `admin_schedule_changed`. Level 2 may remove an administrator test | Authorization boundary, EC-P5 (+2) → 7 |
| A schedule mixing an ordinary test and an administrator test is held whole on a not-set-up system | +2 silently partial result, EC-P6 → 8 |
| Elevated suite: saving needs level 1; the confirmation page lists every member and names each unsigned, repo, local and `text`-option one; Cancel writes nothing; Confirm writes the suite and one `native_suite_elevation_confirmed` row with the member snapshot, in one transaction | Dave's rule; boundary, EC-P7 (+2) → 9 |
| Elevated suite lapses: a member edit, a version change, a revocation, a signed member going unsigned, and an unknown tree hash on one system each hold it on the right systems, until re-confirmed; the trigger isn't consumed | EC-P6 (+2 silently different) → 8 |
| The job carries the `suites` object only to agents with `elevated_suite_v1`; an agent that elevates but lacks it is held with the update text | Version skew, EC-P10 |
| Adding a signed suite that contains an administrator test wraps it in a native suite, shows the confirmation, and its lapse rules apply (revoke one of its members: held) | H4, EC-P6 (+2) → 8 |
| A local member on an unattended system holds the suite with the local-member text, not "confirm again"; a loose local test on that system still runs | M5, EC-P6 |
| Rule 0: an engine 0.6 system with an administrator test gets "update the engine" before any other rule; a pin different from the reported version doesn't hold on `installed` | M3, EC-P10 |
| The confirmation page's per-machine sentence follows each target's `elevated_suite_unsigned` capability | EC-P7 |

## 15. Edge cases

Scored on the Benchy scale. Bump shown where it applies. Only 5 and above go into the tasks.

| Case | Score | Band | Handling |
|---|---|---|---|
| Server with no catalog (fresh, or a source install) | EC-P9 | must | 4.1, 5.2; test |
| Agents on 0.5 and 0.6 against the new server; a pinned schedule | EC-P10 | must | 6.4 rule 1; test |
| Targets report different versions | EC-P8 | must | 6.2; test |
| Import, then pin the new version before agents say hello | EC-P7 | must | 5.3 rehello; test |
| Re-importing the same bundle | EC-P7 | must | 3.4 repair; test |
| Tenant admin (not a server admin) tries to import | EC-P6 (+2 boundary) → 8 | must | 3.5; test |
| Suspended tenant or unapproved system hits the routes | EC-P5 (+2 boundary) → 7 | must | 4.3; test |
| Packaged bundle refused at start | EC-P6 (+1 if it blocked the start) → 7 | must | 3.6; test (§13) |
| Bundle over 24 MiB through the web page | EC-P6 | simple | The page says to use the CLI, and checks the `$_FILES` error and an empty POST |
| CLI import while the packaged import runs | EC-P5 | simple | `import.lock`, "another import is running" |
| Pinned version later revoked | EC-P5 | simple | 6.4 rule 2 |
| Pinned version needs a newer engine than a target has | EC-P5 | simple | 6.4 rule 3 |
| Administrator test scheduled on a mixed fleet (unattended, at the desk, not set up) | EC-P8 | must | 6.6 rules A and D; test |
| New version of an administrator test, not yet accepted at any machine | EC-P9 | must | 6.6 rule B; test |
| Nobody at the desk approves within 30 minutes | EC-P8 | must | 6.6 rule C, `deferred`, hold 6 h or until ready; test |
| Mixed schedule on a system that can't run its administrator test | EC-P6 (+2 silently partial) → 8 | must | Held whole (6.6); test, Q12 |
| A level-2 user tries to schedule an administrator test | EC-P5 (+2 boundary) → 7 | must | 6.6 "Who can add one"; test |
| An engine 0.6 agent (no `elevation_*`) gets an administrator test | EC-P10 | must | Rule 0 first: "update the engine" (M3); test |
| User logs out while the desktop agent is waiting for approval | EC-P6 | simple | The tray's exit ends the job `deferred` (`approval_declined`); if the agent dies first, the job is `lost` as today. The next hello has no `elevation_desktop` |
| Level-2 user re-aims an administrator schedule through group membership | EC-P4 (+2 boundary) → 6 | simple | S3 rule on group edits; part of the level-2 test |
| An old server keeps re-issuing a job the agent defers | EC-P7 | handled by the agent | The engine's backoff (amendment 3.4); nothing on the server |
| Level-1 admin puts an unsigned or `text`-option test in an elevated suite | EC-P5 (+2 boundary) → 7 | must | The warning names it; audited; default machines run it de-elevated (6.7); test |
| A confirmed suite's member changes after confirmation | EC-P6 (+2 silently different) → 8 | must | Lapse rules and `suite_changed` (6.7); test |
| A compromised server marks a suite confirmed | EC-P2 (+2 boundary) → 4 | defer | Limited to signed, non-revoked members, each then trusted administrator code; the review rule stands behind it (6.7, engine amendment 3.8) |
| A new system joins the targets after confirmation and reports an unsigned repo member with a tree hash nobody confirmed | EC-P5 | simple | Held for that system with "confirm again" (6.7) |
| A local member of an elevated suite on an unattended system | EC-P5 | simple | Held with its own text (M5) |
| A signed suite with an administrator test added directly to a schedule | EC-P7 | must | Wrapped in a native suite first (H4); test |
| Rollback to 0.9.13 with suite rows in schedules | EC-P3 (+1 unclear failure) → 4 | defer | Rollback note (6.7) |
| The same administrator test waits on many triggers in a row | EC-P5 | simple | One pending trigger per schedule per system (6.4); the page says "waiting since ..." |
| A suite member measured elevated, compared with the same test measured de-elevated elsewhere | EC-P3 (+2 silently different) → 5 | simple | `info.elevated` is true to what happened on every entry; the result page shows it |
| `issued` more than a year old | EC-P5 | simple | Notice (8.1) |
| Disk full during extraction | EC-P5 | simple | Refused, work folder removed, nothing changed |
| A result with a `catalog_refused` entry, sent to a server older than 0.9.14 | EC-P3 (+2 data loss) → 5 | simple | C0 (section 11) |
| Rollback to 0.9.13 while pins exist | EC-P3 (+2 silently different) → 5 | simple | Release note and runbook line; rollback check asserts no crash |
| Catalog files lost but database kept (partial backup) | EC-P4 | defer, ~10 lines | Not served, notice, re-import repairs (needed for serving anyway) |
| Server restored from a backup older than its last import | EC-P4 | defer | Agents keep their newer catalog (forward-only); the admin re-imports. Runbook line |
| Agent imported a bundle locally that this server doesn't hold | EC-P4 | defer | Picker "Not in this server's catalog", no labels |
| Two versions of one test in one schedule | EC-P4 | defer | Use two schedules; unique key unchanged (6.1) |
| A whole fleet fetches a large pack at once and ties up the 8 PHP workers | EC-P3 | defer | Packs are small in practice; installer payloads come through mirrors |
| A non-free bundle imported by mistake on a shared server: no "withdraw" | EC-P3 | defer | Ask Dave whether a CLI withdraw is wanted (Q8) |
| openssl or zlib missing | EC-P3 | defer band, but §13 requires a test | Precondition message; serving still works |
| Key spelled in another case in a signed index | EC-P1 (+2 silently wrong) → 3 | defer band, done anyway (~30 lines) | 3.3 |
| A 16 MiB index exceeding the web memory limit | EC-P2 | skip | `memory_limit` raised for the import (3.1 step 0) |
| Two bundle files in the package folder | EC-P2 | skip | Message only (3.6) |
| A pack file silently corrupted on disk | EC-P2 | skip | Size checked; the engine refuses a bad hash; re-import repairs |

Places where the bump changes the band: the 0.9.14 result-loss case (3 → 5), the rollback-pins case
(3 → 5), the elevated-suite-member comparison (3 → 5), and the mixed schedule held whole (6 → 8). The
first three only get a note, a schema line or an info field. The last one decides behavior, and Dave
took it (Q12).

## 16. Rejected alternatives

- **Verifying with the engine binary.** See 3.2.
- **`ZipArchive` for the bundle.** It doesn't report prefix or trailing data, and the record
  checks would then read the zip one way while extraction read it another. One reader that both
  checks and extracts (`inflate_add`) has nothing to disagree with.
- **Parsing the stored index on every page and hello instead of an `entries` table.** That would
  be a 16 MiB decode on page loads. The table is a cache rebuilt at commit.
- **Capabilities in `SystemProperties` JSON** (no schema change). It would work, but the issue check
  would decode JSON for every system on every poll. A column fails closed (NULL means no) and is
  simple to query.
- **Pinned rows in a new table**, so an older server never sees them. On rollback the pinned tests
  would vanish from schedules instead of running unpinned. That isn't clearly better, and it would
  split the picker and the issue code in two.
- **A per-tenant tier switch now.** Q3 was decided against it for 0.10. The `tiers_for_account()`
  seam keeps it cheap later (about 0.3 points).
- **An explicit "always the newest" choice in the picker.** That would add a third state beside
  pinned and the mixed-fleet fallback, and it goes against the point of catalog spec 8.4.
- **Hashing packs on every request.** The engine hashes every byte, so the server checks the size only.
- **Refusing to schedule `[elevated_run]` tests** (my first Q2). Dave rejected it: every test runs.
  Waiting with a fix replaces refusing.
- **Failing a job when nobody approves.** That makes a person's absence look like a broken test, and
  it consumes the trigger. `deferred` plus a hold fits what really happened.
- **Splitting a mixed schedule** so the ordinary tests run on a system that can't run the
  administrator one. That gives a result that silently lacks a test the schedule names (Q12).

## 17. Checkpoints and size

Scale S1/M3/L8, about 8 points a month on the PHP track, the same as the tenancy table. Factors:
×1.8 for the verifier, which is security-critical (with an independent security review) and an import of E4.2 and
E4.3's logic into another language. Those engine checkpoints went through several NO-GO rounds,
and the project's one recorded figure, E3b, came in at ×2.1. ×1.6 for import and serving (tenant
and auth boundaries). ×1.5 for the picker and issue rules (an authorization rule and held triggers), ×1.3 for the page and ×1.2 for release and docs.

| | Scope | Base | Factor | Likely | Low | High | Scale | Confidence |
|---|---|---|---|---|---|---|---|---|
| **C0** | 0.9.14: two error codes in `json-events/v1.schema.json` and the two protocol-v2 copies, `PIN` notes, `json-events.md` | 0.3 | 1.2 | 0.4 | 0.3 | 0.6 | S | High |
| **C1** | Verifier: key table and `server-catalog-keys`; the zip reader (records, the data-descriptor rule, extraction); envelope and `openssl_verify`; strict JSON (3.3, ASCII-only keys); entry, pair, ledger, recovery and retired evaluation as a pure function; license check; engine-fixture parity; §13 index, recovery, stored-index and bundle-record tests with throwaway keys | 1.7 | 1.8 | 3.1 | 2.1 | 4.8 | M | Medium-low |
| **C2** | Storage and import: step 61, files, lock, commit and repair, collection, audit actions, `server-catalog-import`, `-status`, `-check`; packaged import at start and its notice; tenancy `tables.json` and SQL allow-list; migration rerun and rollback cases | 1.2 | 1.6 | 1.9 | 1.3 | 3.1 | M | Medium |
| **C3** | Serving: both GET routes, `served_tiers()`/`tiers_for_account()`, the auth matrix; hello capabilities (`elevation_*`, `not_set_up_reason`, the catalogue `admin` object with `accepted_versions`) and `catalog`; poll rehello, `hold_detail` and `ready_for_approval`; the protocol re-pin to v0.6.0 with the 0.7.0 local change and its conformance check (S5); N23 for the routes; docker end to end with a catalogtest engine | 1.3 | 1.6 | 2.1 | 1.4 | 3.4 | M | Medium |
| **C4** | Schedules: `Version` use, the pin rules, version choice from the catalog, labels and per-target status, "Pin to" and changing the version, issue rules 1-4 and 6.6 A-D inside selection (the job's version, unconsumed triggers, the hold per `deferred_kind`, `DeferredKind`), version-aware fixes (S4), `wont_run()` lines, the level-1 rules for administrator schedules including group edits (S3), tenant audit | 1.7 | 1.5 | 2.6 | 1.8 | 4.1 | M | Medium |
| **C5** | `?server_catalog` and the root section: tier table, test lists, withdrawn list, notices, upload, recent events | 0.6 | 1.3 | 0.8 | 0.5 | 1.3 | S | Medium |
| **C6** | Release gate (keys, no test key, bundle pin and the fifth guard), `catalog-bundle` and packaging, admin guide sections "Importing the test catalog" and "Tests that need administrator rights", release notes, rehearsal of the first signed release on a docker server | 0.8 | 1.2 | 1.0 | 0.7 | 1.6 | S | Medium |
| **Total E4.4 for its first release (C0-C6)** | | | | **11.9** | **8.1** | **18.9** | | |
| **C7** (one release later, Q23) | Native suites (6.7): the two tables, `Kind` on schedule rows, the suite page, suites in the picker, wrapping signed suites (H4), expansion at issue with the `suites` object and bound options; the elevated-suite confirmation page, its audit row and snapshot, lapse detection, the local-member hold (M5), the hold texts; the level-1 rules including suite rows (H5) | 2.0 | 1.5 | 3.0 | 2.1 | 4.8 | M | Medium-low |
| **Total E4.4 with C7** | | | | **14.9** | **10.2** | **23.7** | | |

**The engine and client amendment for scheduled administrator tests** (benchy-engine
`spec/elevated-scheduled.md` section 8, revision 5, repeated here so the whole cost is in one place):

| | Scope | Base | Factor | Likely | Low | High | Scale | Confidence |
|---|---|---|---|---|---|---|---|---|
| **EA1** | Spec rewrite of 9.4 and 9.5; protocol-v2 additions, suites included; conformance | 0.8 | 1.3 | 1.0 | 0.7 | 1.7 | S | Medium |
| **EA2** | Unattended: states, the protected binary and its updates, the constructed environment, the protected `<ProgramData>\Benchy` and fresh home, credentials and undo, the parent's DACLs, setup, coexistence | 2.2 | 2.0 | 4.4 | 2.9 | 7.3 | M-L | Medium-low |
| **EB** | De-elevated children for any elevated agent: restricted token, handle list, both work homes, streamed import child, local tests, parent-launched run host | 1.5 | 2.0 | 3.0 | 2.0 | 5.0 | M-L | Medium-low |
| **EA3** | At the desk: handshake, approval points, files, wait, `deferred_kind`, per-schedule backoff, re-scan, `accepted_versions` | 2.3 | 2.0 | 4.6 | 3.1 | 7.6 | M-L | Medium-low |
| **CL1** | Desktop client: handshake, notification and dialogs, foreground hand-off, setup and update, unattended display | 1.3 | 1.5 | 2.0 | 1.3 | 3.2 | M | Medium |
| **EA5** | Windows test-VM proof: both paths, PassMark on a fresh machine, the token, label, DACL and handle-list proofs, UAC-off, built-in Administrator | 1.4 | 1.4 | 2.0 | 1.3 | 3.2 | M | Medium |
| **Amendment for engine 0.7.0** | | | | **17.0** | **11.3** | **28.0** | | |
| **ES** (0.7.x, with C7) | Elevated suites: suite mode, both-tier check and seeded state, binding with options, unsigned fallback | 2.0 | 2.0 | 4.0 | 2.7 | 6.6 | M-L | Low |
| **This release: E4.4 (C0-C6) and amendment without ES** | | | | **28.9** | **19.4** | **46.9** | | |
| **Suites, one release later: C7 and ES** | | | | **7.0** | **4.8** | **11.4** | | |
| **Everything** | | | | **35.9** | **24.2** | **58.3** | | |
| *EA4* | *Linux root path, when a Linux test first needs root; not in the totals* | *1.8* | *1.8* | *3.2* | *2.2* | *5.0* | *M* | *Low* |

E4.4's first release (C0 to C6) is about 1.5 months on the PHP track (1.0 to 2.4). The amendment for
engine 0.7.0 runs on the engine and client tracks at the same time, about 2.1 months (1.4 to 3.5).
The suites release (C7 and ES) adds about 0.9 months. C4's administrator rules can be built
and tested against the protocol fields before EA2 and EA3 exist (a fake agent reports the
capabilities), so the tracks meet only at EA5. The Independence plan sized E4.4 at 1.8.
The difference is scope added since then: version pins and the picker rules (8.4), the
`[machine_install]` and `[elevated_run]` labels (9.4, 9.5), a §13 list that grew to about 80 server
tests, the raw zip reader 7.2 requires, the packaged import, the release gate and its fifth guard,
and C0. The other part is pricing the verifier like the engine work it mirrors. Dave's
administrator-test rule, its reviews and the suite rule added 5.1 to E4.4 (C1, C3, C4, C6, C7). **Cost check:** if
C1 comes in above 4.5, stop and re-plan with Dave. The cheapest cut is C5's web upload and the
version menu in C4 (auto-pin only), which saves about 0.8.

Order: C0 can ship alone in 0.9.14. C1 needs only PHP and the test container, so it can be written
while tenancy holds the harness. C2, C3 and C4 each need the harness, one full pass at a time. C5
can go with C4. C6 needs Dave's first signed release for the rehearsal; until then it is checked
with test keys. Each checkpoint gets an independent review (C1 and C3 as security checkpoints) and one
full `run.sh all`.

## 18. Questions for Dave

**Answered 2026-11-14.** Taken as recommended: 1 (C0 in 0.9.14, the rest in the release after it,
before T5, schema 61), 3 ("Free tests" and "Non-free tests"), 4 (keep the whole §13 list), 5 (local
change in 0.9.14's protocol copies, re-pin in C3), 6 (`gh api` from benchy-tests), 7 (the packaged
bundle only in packages), 8 (no withdraw now) and 9 (tag engine 0.6.0 when C3 starts).

**Rejected: 2.** Dave: "All tests must run, even those that require administrator. Zero exceptions.
Either the benchy client will have to run from an admin console or the desktop client triggers a
UAC prompt." This is now a standing rule: no refuse path because of the privilege a test needs.
Sections 6.3, 6.4, 6.6, 7, 14.3, 15, 16 and 17 changed, and benchy-engine
`spec/elevated-scheduled.md` amends catalog spec 9.4 and 9.5.

**Questions 10 to 23, all decided (2026-11-14).** Dave took every recommendation below, Q19 to Q23
in the last round. Q10, 11, 13, 17, 18, 19, 21 and 22 match the engine note's section 9 (Q1 to Q8).
Q12, Q14, Q20 and Q23 are the server's own. The text is kept as it was asked, with the decision being
the recommendation in each:

10. **Does the unattended path need the policy key** `elevated_run = "agent"`, even when the agent is
    already elevated? *Recommend yes.* Machines with UAC off, or using the built-in Administrator,
    run everything elevated without anyone deciding to. `setup-elevated` writes the line.
11. **At the desk: a notification first, or the UAC prompt straight away?** *Recommend the
    notification and an approval dialog, then UAC on the click.* A surprise secure-desktop prompt in
    front of whoever is typing teaches people to click Allow.
12. **Mixed schedules, now answered by Dave's suite rule.** A **suite** with an administrator test
    runs whole and elevated (6.7). Loose tests in a schedule run each with their own rights: the
    administrator test elevated, the others not (E2). In both cases, a system that can't elevate yet
    holds the whole schedule, with the fix shown, rather than running part of it. *Recommend keeping
    that hold.* Running the rest gives a result that silently lacks a test (+2).
13. **How long to wait for a click, and what then?** *Recommend 30 minutes, then `deferred`, with the
    schedule held on that system for 6 hours or until someone clicks "Approve scheduled run…".*
    Never `failed`, and the trigger isn't consumed.
14. **Who can schedule an administrator or machine-install test?** *Recommend tenant level 1 only*,
    with the machine's acceptance and consent as the real boundary. The alternative is anyone who can
    edit schedules (level 2 and up), which is simpler but lets more people decide when administrator
    code starts on someone's machine.
15. **Unattended setup on Windows.** Decided by E2: a logon task with highest privileges only. A
    service has no user token to drop ordinary tests to.
16. **E2: decided by Dave, option B.** An unattended agent runs every test that isn't `[elevated_run]`
    with a standard-user token. `info.elevated` stays on every entry, `"false"` for those tests. The
    one exception is the members of a confirmed elevated suite (6.7). Engine amendment 3.7 has the
    token, environment and no-shared-folder design, at 2.2 points.
17. **Linux root tests: build now or when needed?** *Recommend when the first Linux test needs root.*
    The design is in the engine note's section 6. Nothing converted needs it yet. PTS marks 13
    profiles `RequiresRoot`.
18. **Which engine release carries the amendment?** *Recommend 0.7.0*, so 0.6.0 (the catalog) is
    still tagged when C3 starts. The server sends administrator tests only to agents that report the
    new capabilities, so the two releases don't depend on each other.
19. **Unattended setup moves the machine's enrollment** away from the tray's agent, and `--undo`
    moves it back (engine Q6). The alternative is enrolling it as a second system, which keeps both
    but splits the machine's results across two system rows. *Recommend the move.*
20. **Group membership as a way to re-aim an administrator schedule (S3).** *Recommend requiring
    level 1* for a group change that adds a system to an enabled administrator schedule's targets.
    The alternative is to check targets only on the schedule page, which leaves the side door open.
21. **Unsigned members of an elevated suite (engine Q7).** Your rule says the whole suite runs as
    administrator. The confirmation, though, is something only the server vouches for, so a
    compromised server could claim it. *Recommend: signed members always run elevated in a confirmed
    suite. Unsigned members run elevated only on machines whose administrator opted in
    (`elevated_suite_unsigned = "allow"`), and elsewhere still run, without administrator rights,
    named in the result.* The warning says which machines are which. This narrows your wording on
    default machines.
22. **Local tests as suite members on unattended machines (engine Q8).** E1 makes an unattended agent
    ignore local tests, so such a suite waits there. *Recommend keeping E1*: local tests reach those
    machines through a server repo later.
23. **Should elevated suites ship with the first administrator tests, or one release later?** They
    cost 6.0 together (server C7 2.4, engine ES 3.6). *Recommend one release later*, so scheduled
    PassMark isn't held up behind them. Nothing would have to be undone.

## 19. Deferred (design log)

- Engine: the same case-folding rule as 3.3 in `ParseIndex`, plus a shared fixture for it
  (EC-P1 +2 → 3).
- A CLI withdraw for a tier (Q8, EC-P3).
- Two versions of one test in one schedule (EC-P4).
- Per-tenant tier filter inside `tiers_for_account()` (tenancy Q3, about 0.3 points).
- Two versions of one test inside one suite (the same limit as 6.1).
- Emailing server admins on `same_pair_differs` or a denied key. For now it is a notice that
  stays on the page.
- Limiting concurrent pack downloads (EC-P3).
- Linux root tests, engine EA4 (Q17).
- C2 review backlog (AP-5, GO, nothing at 7 or above; D1, D2, D3 and D5c were fixed in C2):
  - D4: `collect()` after a partial fetch or a failed read of the tier rows only skips deletion; it doesn't retry or report.
  - D5a: a refusal's audit row doesn't carry the claimed pair (only the key id when the envelope reads).
  - D5b: the 512M `memory_limit` raise lasts for the whole event-server process after a packaged import, not just the import.
  - D5d: `phoromatic_catalog::notices()` hashes each stored index file on every `?server_settings` view.
  - Nine EC-P0 to P2 items from the same review: skipped by the scale (no code, no test); listed in AP-5's report.

## Log

- 2026-11-14: Design written against Benchy 895500abd and engine b90eccf. PHP verifier checked
  by hand against the engine's committed fixtures (all seven give the expected outcome, including
  -1 for malformed DER). Questions 1 to 9 open.
- 2026-11-14: Dave answered: 1 and 3 to 9 as recommended, 2 rejected ("all tests must run, zero
  exceptions"). Added 6.6 (administrator tests: held with the fix instead of refused, the approval
  hold, level-1 only, trust chain) and the engine amendment `spec/elevated-scheduled.md` on
  benchy-engine branch `elevated-scheduled`. E4.4 re-sized to 11.2 (7.7 to 17.8), and the
  amendment is 9.0 (6.0 to 14.9). Questions 10 to 18 are open.
- 2026-11-14: Review findings applied. Server: GO for C0 to C3, NO-GO for 6.6 and C4, now fixed:
  rule B on the job's version (B1), holds per `deferred_kind` and rule A for missing installs (B2),
  level 1 for changes to administrator schedules (S3), version-aware fixes (S4), the protocol pin plan
  (S5), the zip data-descriptor rule (S1), ASCII-only keys (S2), and S6 to S9. Engine amendment
  revision 2 covers E1 to E9, with E2 as options A and B for Dave. E4.4 is now 11.9 (8.1 to 18.9),
  and the amendment 13.9 with B or 12.4 with A. Questions 10 to 20 are open, and 16 is now E2.
- 2026-11-14: Dave decided E2 (option B: ordinary tests run de-elevated on unattended agents) and the
  suite rule (a suite with an administrator test runs whole and elevated, after a level-1 final
  check on the console). Added 6.7 (native suites, the confirmation page, its audit, lapses, what the
  machine trusts), rule C's `suite_changed`, C7, and questions 21 to 23. E4.4 is now 14.3 (9.8 to
  22.7), the amendment 18.5 (12.4 to 30.3), together 32.8, or 26.8 with suites one release later.
- 2026-11-14: Second review: GO for C0 to C3, and for C4 to C6 after M3; NO-GO for C7 and the engine
  amendment, now fixed in revision 4. Server changes: H2 (only the agent parent calls the catalog
  routes), H4 (a signed suite with an administrator test is wrapped in a native suite), H5 (suite rows
  make a schedule an administrator schedule), H8 (inside a confirmed suite every signed member is
  trusted administrator code), M3 (rule 0 for engines older than 0.7.0; `installed` unknown for
  another version), M5 (the local-member hold), M6, and the stale section 15 rows. Dave answered Q19
  to Q23 and engine Q6 to Q11 as recommended. **No questions are open.** This release is 28.1 (18.9 to
  45.6), and the suites release that follows (C7 and ES) is 7.0, for 35.1 in all.
- 2026-11-16: C2 built on branch e44-c2 (schema step 63). AP-5 review GO; the deferred list above holds what is left. The amendment size table in 17 was stale and is corrected (EA2, EA3, the totals and the months, revision 5 of the engine spec).
