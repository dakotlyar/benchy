# Agent protocol v2

How a native engine on a machine talks to a Benchy (Phoromatic) server: it
enrolls once, then polls for work, runs it, reports as it goes and uploads the
result. This directory is the contract between the two halves. The design is
section T10 of `benchy-independence.md` in the staff repo; where this file and
that section differ, this file is what the engine and the server are built to.

The engine side is `benchy-engine agent` (contract v1, section 12.10). The server
side is `pts-core/phoromatic/public_html/api/v2/` in Benchy. Benchy keeps a copy of this
directory and `../result-v1.schema.json` at a pinned ref, the way the engine keeps
`spec/contract-v1/`, but with the arrow the other way round: the engine repo owns
these files.

## Files

| File | What |
|---|---|
| `v2.schema.json` | one JSON Schema, with a `$def` for every request and reply body (table below) |
| `../result-v1.schema.json` | the native result format, and the body of a result upload (`#/$defs/upload`) |
| `examples/<def>.json` | one valid body per message def; the tests check them, and a server can use them as fixtures |
| `embed.go` | the same files as Go values, for the engine's fake server |

Validate a body against the def named in the table, for example
`v2.schema.json#/$defs/poll_request`. Readers ignore fields they don't know.
Within v2 a change only adds things. A change that breaks a reader is v3.

## The basics

* JSON over HTTPS, UTF-8. The server refuses plain HTTP with `426 https_required`
  (a server setting can allow it for test rigs).
* No account id in any URL. The token says which account and which system it
  belongs to, so renaming a tenant or moving a system never touches an agent.
* Every reply, success or error, carries `{"api": 2, "server": "<version>"}`.
* After `enroll`, every call has `Authorization: Bearer bsa_...`. The server keeps
  only the token's SHA-256.
* Request bodies are at most 1 MiB, except the result upload (16 MiB).
* `{id}` in a URL is the integer `job.id` from a poll reply.

## Endpoints

| Call | Request def | Reply def (success) |
|---|---|---|
| `POST /api/v2/enroll` (no auth); also `POST /api/v2/enrol` | `enroll_request` | `enroll_response` (201) |
| `POST /api/v2/agent/hello` | `hello_request` | `hello_response` |
| `POST /api/v2/agent/poll` | `poll_request` | `poll_response` |
| `POST /api/v2/jobs/{id}/events` | `events_request` | `events_response` |
| `POST /api/v2/jobs/{id}/result` | multipart, see below | `result_response` |
| `POST /api/v2/jobs/{id}/end` | `end_request` | `end_response` |
| `POST /api/v2/results` | multipart, see "Local results" below | `local_result_response` (201, or 200 for a repeat) |
| `GET /api/v2/catalog` | none | `catalog_list_response`: `catalog: {tiers: [...]}` at the top level beside `api` and `server`, the caller's catalogs (catalog-index.md 11.1; server NC3, engine 0.13.0) |
| `GET /api/v2/catalog/{tier}/index.signed` | none | the signed index, as bytes (catalog-index.md 11); `404 not_found` for a catalog the caller's tenant isn't granted |
| `GET /api/v2/catalog/packs/{pack_sha256}` | none | the pack, as bytes |
| `GET /api/v2/engine/index.signed` | none | the signed engine index (`engine.signed`), as bytes; 404 when the server has none (engine self-update) |
| `GET /api/v2/engine/files/{sha256}` | none | an engine archive named by the signed index, as bytes |
| `GET /api/v2/server` (no auth) | none | `server_info` |

**Signed catalog (E4.3, additive).** `hello_response.catalog` is `{"tiers": [{"tier", "generation", "sequence"}]}`
when the server holds a signed catalog, and absent otherwise. An agent whose own pair for a tier is lower
fetches `index.signed` for it and checks it itself: the server can't forge it. A pack is fetched by its hash
alone, and checked against the signed index before anything is unpacked. A 404 on either route means the
server has no catalog, and the agent keeps its built-ins. `job_test.version` is an optional `x.y.z`: the agent
runs exactly that version or fails the job, and a server must not send it to an agent whose hello capabilities
lack `catalog_v1`.

**Named catalogs (catalog-index.md 11.1, additive).** `tiers[].tier` is any catalog name
(`^[a-z][a-z0-9]{1,23}$`, reserved names excluded) and each item MAY carry `title` (at most 40 bytes). A server lists a
name other than `public` and `private` in hello only to an agent whose hello capabilities include
`catalog_named_v1`, and lists only the catalogs the agent's tenant is granted. `GET /api/v2/catalog` returns the
tenant's catalogs in the order `public`, `private`, then by name, with no capability filter; a server older than the
route answers 404. A catalog the tenant isn't granted answers 404 on its index and on a pack only that catalog names.

Any failure is `error_reply`. A `/jobs/{id}` URL that names a job of another
account is `404 not_found`, the same as a job that doesn't exist, so job ids reveal
nothing across accounts; 409 `job_not_yours` is only for another system's job inside the
agent's own account.

**enroll.** An admin makes a single-use code (`ben_` plus 43 characters, good for
24 hours) on the Systems page and hands it over, usually inside a pairing string
`benchy-enroll:https://host:port?code=ben_...&ca=<sha256 hex>`. The agent sends the
code with a description of the machine (see `machine_id` below) and gets back a long-lived system token (`bsa_` plus 43). The token is shown
to nobody. Enrolling a system again revokes its previous token.

*Two spellings of the path.* The path is `/api/v2/enroll`. Servers up to Benchy 0.9.4 only
have the old spelling, `/api/v2/enrol`, and Benchy 0.9.5 and later serve both, with
identical bodies and replies. An agent posts to `/enroll` first and goes to `/enrol` only
when the answer is a 404 (any other answer, an error included, means the path exists and
is final). A pairing string starts with `benchy-enroll:`; the old `benchy-enrol:` is
accepted by the agent and may still be issued by an older server.

**Join codes (server discovery D2, local).** `enroll_request` carries either `code` (the `ben_` code above) or `join_code`, never both
(a body with both, or with neither, is `400 bad_request`). A join code is meant to be read aloud or typed: ten characters from the Crockford
base32 alphabet (`0123456789ABCDEFGHJKMNPQRSTVWXYZ`), shown as `K7Q-29X-A1FH`. On the wire it is the canonical form `K7Q29XA1FH`: upper
case, no hyphens or spaces. A client normalizes what a person typed first (case, hyphens and spaces are ignored; `I` and `L` read as `1`, `O`
as `0`).

* The first six characters, R, are random (30 bits). The last four, C, are a check (20 bits) that ties the code to the server that made it:
  `C = base32(first 20 bits of SHA-256("benchy-join-v1" 0x00 R 0x00 anchor))`, where R is the six characters as upper-case text, the 20 bits
  are the top 20 bits of the digest (the first two bytes and the high half of the third), and base32 writes them as four characters, 5 bits
  each, most significant first.
* The anchor is what a client can learn about a server without trusting it. In `internal` TLS mode it is the SHA-256 of the root CA
  (`server_info.tls.ca_sha256`, the same CA `server.php?benchy_ca` serves), as lower-case hex. In `file` and `acme` mode it is `"name:"` plus the
  primary name: the first entry of `server_info.tls.names`, lower case (the names are plain ASCII, so that is already its IDNA A-label).
* Test vectors, so two implementations can be compared. With the anchor `b0d6e1c2f3a4958677685746352413020f1e2d3c4b5a69788796a5b4c3d2e1f0`
  (internal mode): `K7Q29X` gives `K7Q29XJS23`, `000000` gives `000000SDC8`, `ZZZZZZ` gives `ZZZZZZVNC9`. With the anchor
  `name:seinar.example.net`: `K7Q29X` gives `K7Q29XA1FH`, `000000` gives `000000XD5D`, `ZZZZZZ` gives `ZZZZZZ1MEA`.
* **Check before sending.** The client works out the anchor of the server it is about to use, recomputes C and, when it differs, does not send
  the request at all: a rogue server or a man in the middle never sees the code. Only when it matches does the client pin that CA (internal
  mode) or insist on a verified connection to the primary name (the other modes), and send `join_code` over that connection. The server does not
  repeat the check: its anchor is public, so it would prove nothing.
* The server keeps only the SHA-256 of the ten characters, finds a join code only among join codes (an ordinary code sent as `join_code`, or a
  join code sent as `code`, is `401 code_invalid`), and takes one for an hour, once. Errors are the existing `code_invalid`, `code_expired` and
  `code_used`. The code belongs to the account that made it, as an ordinary code does.
* **Lockout.** Failed join codes count toward the same per-address lockout as ordinary codes (10 in 15 minutes, then `429 rate_limited` with
  `Retry-After`). On top of that, 100 failed join codes from all addresses together inside 10 minutes switch join codes off server-wide for 10
  minutes: every `join_code` request then gets `429 rate_limited` (with `Retry-After`) and is not counted again, while ordinary codes keep working.
  `server_info.join_codes` stays `true` while the built-in HTTPS is on; a client that is told 429 shows the server's message.

**machine_id.** Every `hello` and `poll` carries the machine's id, and `enroll` carries it as
`machine.machine_id`. It is `HMAC-SHA256(key = /etc/machine-id or the Windows MachineGuid,
message = "benchy-engine machine id v1")` in 64 hex digits, so it can't be matched against
another program's plain hash of the same id, and the raw id never leaves the machine. With
no machine id the key is `"no-machine-id:" + hostname`. The server stores the one it
sees at enroll and compares: on a mismatch it issues no job and says so in `hold` (a cloned
VM is sharing the token; the clone should re-enroll).

**hello.** Sent when the agent starts, every 6 hours, and whenever a reply says
`rehello`. It carries the sysinfo and the agent's catalogue of runnable native
tests (the contract's `test_info` shape), which the schedule page offers to the
admin. It also says which job the agent is still holding (`current_job`) and how
many interrupted run directories it has kept (`kept_run_dirs`). The reply says
what the server thinks the system is (`policy`, `approved`), how often to poll,
and which download `mirrors` to try after the user's own. Mirrors must be `https://` URLs on the server's own hostname (a certificate names a host, and a private CA is trusted for that host only). The agent drops any other entry with one warning, whatever it is: `http`, `file:///`, a path, a UNC share (which would send the user's Windows credentials to another machine), or another host. The user's own `BENCHY_MIRRORS` keeps its full syntax.

**poll.** The heartbeat while idle. `state` is `idle`, or `busy` when a person is
running something on the machine (the agent probes the local run lock before
each poll). The reply is either a `job` or a `hold`: a sentence for people
saying why there is nothing to do ("This system is set to the PTS engine."). A job
the agent already holds comes back unchanged, and the agent doesn't run it a second
time: a poll reply whose job is the one in `current_job` is not new work. A job the
server thinks is running but the agent doesn't report as `current_job` ends as `lost`.

While `current_job` is set, the agent takes no new job, and the server issues none.
That includes the time a finished job's result is still waiting in the agent's outbox
for the upload to get through. The outbox entry keeps both halves, the upload and the
`end` call that follows it, and the job stays `current_job` until both have been
accepted.

**events.** Batches of the child engine's contract v1 event objects, exactly as it
printed them, in order. Every item needs `v`, `type`, `seq` and `ts`; validate the
whole object against `../contract-v1/v1.schema.json`. The server de-duplicates on
`(run, seq)`, so resending a batch is safe. While a job runs this is also the
heartbeat, so a batch goes out at least every 60 seconds, and when the child has
printed nothing an empty batch (`"events": []`) is the heartbeat.
The stream can also hold a few events the agent wrote itself (a warning that it dropped
something from the job, say): `log` events with `source: engine` that carry the agent's
own run id, and sequence numbers of the agent's own. There is no `hello` for that run
id; they are not part of the child's `(run, seq)` space and the server should keep them
as they are. `cancel` in the reply is always `false` for now; an agent that gets `true`
cancels its child.

**result.** `multipart/form-data` with a `result` part (`application/json`: a saved
result, `result-v1`, with exactly one run, whose `run` is the job's run id) and an
optional `events` part (the whole event stream as gzip-compressed JSON lines,
4 MiB at most). The reply is `{upload_id, url}`. A second upload for the same run id
returns the first reply, so an agent can retry freely. A run that has no `ok` entry
is `422 result_invalid`; an agent stops retrying on a 422.

**end.** The last call for every job, and safe to repeat. `status` is `ok`,
`partial`, `failed`, `cancelled` or `deferred`. `deferred` means the agent never got
the machine (a local run won the race), or was stopped before it started the child: the
server doesn't count the trigger as run. `cancelled` with reason `local_cancel` does count it, so a job a person stopped
isn't started again a minute later. `lost` is a status only the server sets.

## Errors

| HTTP | `error.code` |
|---|---|
| 400 | `bad_request` |
| 401 | `unauthenticated`, `token_revoked`; on `enroll` only: `code_invalid` (unknown or revoked), `code_expired`, `code_used` |
| 403 | `tenant_suspended`, `system_not_approved`, `local_results_off` |
| 404 | `not_found` |
| 409 | `job_not_yours` (a job of another system in the same account), `job_ended`, `run_conflict` |
| 413 | `too_large` |
| 422 | `result_invalid` |
| 426 | `https_required` |
| 429 | `rate_limited` (a `Retry-After` header is welcome, not required; also the answer to every join code while join codes are switched off after too many wrong ones) |
| 5xx | `server_error` |

The server sends only the codes in this table (they are `server_error_code` in the
schema). `error.code` itself is an open string, matching `^[a-z][a-z0-9_]*$`: an agent
accepts any code, and one it doesn't know it handles by the HTTP status. That keeps a
later auth scheme (which would bring its own codes) from needing a new protocol version. `token_revoked`
and `unauthenticated` stop the agent. Network errors, 5xx and 429 are retried
with backoff. `tenant_suspended` waits an hour.

How the engine reports these to its caller (as `permission`, `network` or `server`
errors with a `detail.reason`) is in contract v1, section 12.10.

## Local results (Benchy 0.10.0, additive)

`POST /api/v2/results` takes one run that a person ran at the machine, with no job behind it. It is filed under the system the token belongs to, and no other. The body is multipart with one
`result` part (result-v1.schema.json `$defs/upload`: exactly one run, versions filled in, 16 MiB at most); a part named `events` is ignored. The reply is `201 {upload_id, url, duplicate: false, origin: "local"}`.

The run id is the key. `(account, system, run)` is unique across local uploads and jobs, so a repeat (a lost reply, a double click) is `200` with the first reply and `duplicate: true`, and a run that a scheduled
job already sent is that job's upload with `origin: "job"`. The same run id with other content is `409 run_conflict`.

The server checks, in this order: HTTPS and the token (426, 401, 403 `tenant_suspended`), the system's approval (403 `system_not_approved`), the account's switch (403 `local_results_off`, permanent until
an administrator turns it on), at most 120 uploads per system per hour (429 with `Retry-After`), the size, JSON and shape (413, 400, 422 `result_invalid`, which includes a run with no `ok` test), and the machine:
a run whose `machine_id` is not the one the token was enrolled with is `422` ("This run was made on another computer."); a run with none is accepted. A bench system's result is filed under its open
session as an ad-hoc upload.

`runs[].ran_by` and each entry's `source` are what the machine reported. The server shows them as that, and checks a catalog claim against its own catalog without ever refusing a result for it.

## Why it is shaped like this

* **Bearer token, not request signing.** HTTPS is required, the server stores only
  a hash, and signing would mean storing a usable key. `hello_response.auth` lists
  the accepted schemes (`["bearer"]`), so another one can be added later without
  a new protocol version.
* **The agent is a client of its own engine.** It never runs a test itself. The
  events a job produces are the engine's own, forwarded as they are, so the
  server needs no second event vocabulary.
* **One format on the wire for results.** The upload is the file the engine
  already saves. Turning it into `composite.xml` is the server's job, and only the
  server knows both formats.

**Who are you (server discovery, local).** `GET /api/v2/server` needs no token and answers over HTTPS only (a plain-HTTP
request is `426 https_required` even where a test rig allows HTTP for the rest). It says what a client needs to show a
found server and to know how its certificate is made: the network name, the version, the protocol list, the certificate
mode and names, the internal CA's SHA-256 where there is one, and whether join codes are accepted. It never says anything
about accounts or counts. All of it is a hint that anyone on the network can forge, so a client fetches it with
certificate verification off, labels it unverified and keeps none of it as trust. More than 60 requests a minute from one
address get `429 rate_limited`.

## Local changes in this copy (Benchy)

This folder is the engine's `protocol-v2` plus the engine 0.7.0 fields that the engine's `main` doesn't carry yet (see `PIN`). They only add things and a reader ignores them when it doesn't know them:

- `hello_request.engine.capabilities`: the words `elevation_unattended` and `elevation_desktop`.
- `hello_request.engine.not_set_up_reason`: sent only when neither word is set.
- `catalogue_test.admin`: `elevated_run`, `machine_install`, `accepted_versions`, `installed`.
- `poll_request.ready_for_approval`: `{schedule_id}`.
- `poll_response.hold_detail`: `{kind, tests: [{ref, version}], fix}`.
- `end_request.deferred_kind`: with `status: deferred`, what the job was waiting for (`approval_declined`, `uac_declined`, `not_set_up`, `acceptance_needed`, `install_needs_setup`, `suite_changed`). Absent keeps the old meaning. The server holds that schedule on that system until the cause changes, or for 6 hours at most.
- `server_info` (a new definition, with `GET /api/v2/server` and `examples/server_info.json`): the reply described under "Who are you" above.
- `enroll_request.join_code` (and `examples/enroll_request_join.json`): a join code in place of `code`; `code` is no longer required on its own, exactly one of the two is. See "Join codes" under `enroll`.
- `POST /api/v2/results` (local result upload, a new definition `local_result_response`, `examples/local_result_response.json`, the two error codes `local_results_off` and `run_conflict` and the section "Local results" below). The optional result-v1 fields `runs[].machine_id`, `runs[].ran_by` and `result_entry.source` that it reads are in `result-v1.schema.json`.
