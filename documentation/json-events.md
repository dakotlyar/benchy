# JSON mode (`benchy --json`)

JSON mode is the machine-readable interface of the Benchy CLI: the contract the Qt client and scripts drive Benchy
through. It is engine-neutral: no field depends on how the engine works inside. The reference is
`pts-core/static/json-events/v1.schema.json` (JSON Schema 2020-12). This page is a summary of it.

## Switching it on

    benchy --json <command> [arguments] [flags]          # Linux, macOS
    benchy.bat --json <command> [arguments] [flags]      # Windows (no banner, no result viewer, exit code passed through)

`--json` must be the first argument. Setting `BENCHY_OUTPUT=json` in the environment does the same. `--json=1` asks
for protocol major 1 explicitly. Asking for a major the engine does not speak (`--json=2`) prints one `error` line
(`unsupported_schema`) and exits 2; that is the only stream with no `hello` and no `done`.

## The stream

- stdout is UTF-8 JSON Lines: one event object per line, `\n` terminated (tolerate a trailing `\r`), no BOM, nothing
  else. stderr is free text for people (PHP warnings, debug output); never parse it.
- Every event has `v` (1), `type`, `seq` (1, 2, 3, ... per process: a gap means a lost line) and `ts` (UTC, RFC 3339
  with milliseconds). A command that does work has a run ID, announced in `hello.run` and repeated as `run` on every
  later event.
- The first line is always `hello` and the last always `done`. `done.exit_code` is always the process exit code. A
  process that exits without `done` crashed: report its exit code and the end of stderr.
- Nothing reads standard input, except `--json-args -` (below). Prompts are answered from flags or refused (see below).
- Versioning: within major 1 changes are additive only (new events, new optional fields). Ignore unknown event types
  and unknown fields. `hello.capabilities` lists optional behaviours: `cancel`, `prompt_answers`, `private_repo`,
  `json_args` (0.9), `run_lock` (0.9: `benchmark` and `install` refuse with `locked` while another run holds the lock), `no_phone_home` (when on), `all_options` (the native engine, 0.10.0: `benchmark --all-options` runs every combination of an option's choices, at most 100 runs; the PHP engine has always had the flag and doesn't list the capability). `global_repo` is reserved for a later release: the schema names it, but no Benchy version reports it yet.
- Structured fields never hold file paths, XML, engine class names or engine argument strings. Free text (`log.text`,
  `error.message`, `prompt.question`, descriptions) may mention paths; do not parse it.

Tests and suites are `{"ref": "repo/name", "version": "x.y.z"}` in events and `repo/name` or `repo/name@version` on
the command line. The private repository is always `private/`. Options are key/value pairs: the key is the option's
key and the value is one of its choice keys (as `info` lists them), or the typed value for a free option.

## Commands

| Command | Events (besides `hello`, `done`, `log`, `error`, `prompt`) |
|---|---|
| `hello` | none: the capability probe |
| `setup cygwin` (Windows) | `setup` start and end (the install; Windows asks for administrator approval) |
| `system-info` | `system_info` |
| `list-available-tests` | `test` per test, then `list_end` |
| `list-available-suites` | `suite` per suite, then `list_end` |
| `info <ref>` | `test_info` or `suite_info` |
| `install <refs...>` | `install_plan`, `install_start`, `download_start`/`download_progress`/`download_end`, `install_progress`, `install_end` |
| `benchmark <refs...>` | install events for tests not installed yet, then `run_plan`, `test_start`, `trial_start`/`trial_end`, `test_result`, `run_end` |
| `cancel <run>` | none (asks the run to stop) |
| `result show <id>` | `result` (`--identifier` picks one run of a result with several) |
| `private-repo-refresh` | `repo_refresh` |
| `phoromatic.upload-result [server] <id>` | `upload` |
| `test.templates` (native 0.11.0) | `templates` |
| `test.files.add <dir> <url>` (native 0.11.0) | `file_added` |
| `test.publish` (native 0.11.0) | `published` |
| `engine update --check` / `--apply` / `--reset-state --yes` (native 0.11.0) | `engine_update` (`--reset-state` has none) |
| `engine rollback [--allow-revoked]` (native 0.11.0) | `engine_update` |
| `engine keys` (native 0.11.0) | `engine_keys` |

Only `benchmark`, `install` and `setup` ever install anything. Every other command, `hello` included, starts without
installing, so on a fresh Windows machine `hello` and the listings answer at once. `hello.commands` lists the command words
the engine implements (`setup` only on Windows), and `hello.setup_needed` (optional, 0.9.15) is `["cygwin"]` when Cygwin's
`bash.exe`, `unzip.exe` or `which.exe` is missing: a client shows its own explanation and then runs `setup cygwin`. The
`setup` events (`component`, `phase` start or end, `status` ok or failed on end) bracket the install for `setup`,
`install` and `benchmark` alike, and only appear when Cygwin is actually missing (a `setup cygwin` with nothing to do has
no `setup` events), and a failed install ends in `error{code: dependency_missing}`.

Any other command gives `error{code: unsupported_command}` and exit 2. Reserved for later milestones (additive, no
protocol change): `update_check` (0.12), `agent` (0.10), `config get|set` (0.11).

### Tags, categories and trial logs (native engine 0.10.0)

Additive and optional, like everything else in major 1. A client reads whether the key is there, never the engine's version.

- **`tags`** on `test` and `test_info`: an array of at most six distinct tag keys, in the order the test lists them. Each key is lower-case words joined by single hyphens (`^[a-z0-9]+(-[a-z0-9]+)*$`, 32 bytes at most), for example `compression` or `multi-thread`. An engine that has the field always sends it, `[]` when the test has none; an engine that doesn't (the PHP engine, and native engines before 0.10.0) leaves it out. `category` is unchanged: the manifest's own text, which for an older test may still be a Phoronix name such as `Processor`. A client groups by the key the name stands for (`Processor` is `cpu`, `Disk` is `storage`, anything unknown is Other) and writes a plain label for every tag it knows; a tag it doesn't know shows as its key.
- **`trial_log`** `{test, trial, path, tail?}`: where a trial's full output was saved on the machine that ran it. The native engine emits it after each trial's script and before `trial_end`, in every run. When a script fails, the failure message ends with `Full output: <path>`. The path is for the person at that machine: it is free text for a client to show, not something to open from another computer. The log files are kept for the last 30 runs or 200 MB, whichever is smaller, plus the last 10 failed runs. On a trial that failed, `tail` is also present: the end of the trial's output (at most 40 lines and 4 KiB, valid UTF-8, control characters other than newline and tab removed), so a server that never receives the log file can show why. It is raw script output and may name paths, user names or other machine details; it is meant for your own server, and a Benchy agent forwards it without `--json-verbose`. Engines from before this field send only the path. The PHP engine doesn't emit the event.

## Prompts become flags

| Prompt ID | Flag | When neither is given |
|---|---|---|
| `TEST_OPTION:<ref>.<key>` | `--option <ref>.<key>=<choice key or value>` (repeatable; several values for one key run each), `--defaults` (each option's default), `--all-options` (every choice) | refused, listing every missing option |
| `SAVE_NAME` | `--name <id>` | refused, unless `--no-save` |
| `TEST_IDENTIFIER` | `--identifier <text>` | the engine's generated identifier |
| `TEST_DESCRIPTION` | `--description <text>` | the engine's generated description |
| `SAVE_RESULTS` | `--no-save` | saved |
| `UPLOAD_OB`, `OPEN_BROWSER` | none | always no |
| `STOP_AND_INSTALL` | `--no-install` | missing tests are installed first |
| `HW_MISMATCH`, `SW_MISMATCH` | `--force-append` | refused (appending to a saved run whose hardware or software differs) |
| `INSTALL_AGREEMENT` | `--accept-license <ref>` (repeatable), `--accept-licenses` | that test's `install_end` fails with `license_required` (the run goes on) |
| `MISSING_DEPENDENCIES` | `--skip-missing-dependencies` (drops the tests that need them) | refused with `dependency_missing`; JSON mode never installs system packages |

`--answer <ID>=<value>` (repeatable) answers any prompt by its ID. A refused prompt is reported as a `prompt` event with
`answer_source: "refused"`, then `error{code: prompt_required}` with `detail.id`, and exit 3. The GUI gets everything it
needs to ask the user up front from `info`.

`--json-verbose` adds `install_output` and `test_output` events (the scripts' output).

## Arguments from stdin or a file: `--json-args`

`--json-args -` (or `--json-args <file>`, for scripts) takes more arguments as a UTF-8 JSON array of strings, at most
1 MiB. It may appear anywhere after the command, once. The strings are appended after the last command-line argument and
read like command-line arguments (test refs and flags alike), so they cannot change the command. They may not contain
`--json`, `--json=N` or `--json-args`. Anything else (not UTF-8, not an array, a non-string item, over 1 MiB, unreadable,
given twice) is `error{code: usage}` and exit 2. With `-`, standard input is read once, to EOF, before anything else
starts: write the array, then close stdin. Tests and their scripts still get no stdin. Use this for free text such as
result names and descriptions: on Windows, `cmd.exe` and PHP's ANSI command line mangle characters such as `%`, `!`,
`^`, `"` and anything outside the code page, while stdin carries the bytes as they are. Engines that support it list
`json_args` in `hello.capabilities`.

## Cancellation

`benchy --json cancel <run>` (the run ID from `hello.run`) asks a running `install` or `benchmark` to stop. It is
checked between downloads (and during one), between installs, between tests, between trials and every 250 ms during a
trial, whose process tree is then killed (`taskkill /T` on Windows). The cancelled test gets
`test_result{status: cancelled}`, tests already finished stay in the saved result, and the run ends with
`run_end{status: cancelled}` and `done{status: cancelled}`, exit 4. On Linux SIGINT and SIGTERM do the same when PHP has
pcntl. Do not use Ctrl+C or console events on Windows.

### Authoring additions (native engine 0.11.0)

Additive and optional, like everything else in major 1. A client reads whether the command word is in `hello.commands`, never the engine's version. `test.templates`, `test.files.add` and `test.publish` are authoring commands: none of them runs a test.

- **`templates`** `{templates[]}`: the one event of `test.templates`. Each template is `{key, title, description, platforms[], category, tags[], questions[]}`, and a question is `{key, label, kind, default, help, choices[]}` with the shape a test's own options have. `kind` is `choice`, `text` or `number`, plus `metric_list`, which only templates use: rows of name, unit and direction (`higher` or `lower`). A client that doesn't know a kind hides that question, and the scaffold then writes a single TODO metric. On the command line a question's flag is its key with dashes (`results_format` is `--results-format`), except `metrics`, which is `--metric "name:unit:higher"`, repeated. `test.new --template` takes `cli`, `app-bench` or `desktop-app`; `test.new` also gains the usage reasons `bad_results_format`, `bad_metrics` and `bad_app`.
- **`suggestion`** on a `parse_trace` entry: `{regex, kind: relaxed|from_line, changes[], file, line, column_start, column_end, replacement, line_no, value}`, present when a `[[parse]]` rule matched nothing and a corrected regex passed every check. `file`, `line`, `column_start`, `column_end` (1-based, bytes, the end exclusive) and `replacement` say where the rule's regex value is written in `benchy-test.toml` and the exact TOML text to put there, quotes included; they are null when the test's directory isn't known. A suggestion is only ever offered: `test.parse --apply` writes the regex values (and nothing else in the file) and logs each change as a `log` notice.
- **`file_added`** `{file, name, size, sha256, url, platforms[]}`: the one event of a successful `test.files.add`. The file was downloaded once into the download cache and the entry appended to `files.toml`. Refusals are `usage` with `detail.reason` `bad_name`, `bad_platform`, `name_taken` or `bad_files_toml`; a download that failed is `download_failed`.
- **`published`** `{target: local|dir, id, version, dir, files}`: the one event of a successful `test.publish`. `--local` copies the test into the engine's own catalog folder, `--to <folder>` into a folder you name. A test with validation errors is refused as `usage`, `detail.reason: validation_failed`, with the diagnostics before it; `--repo` is refused with `detail.reason: repo_unsupported` for now.

### Engine updates (native engine 0.11.0)

The native engine can replace itself with a newer build that the server it is paired with holds. Only that server is ever asked: there is no URL or file argument. The engine checks the publisher's signature itself, so the server can't hand it anything else. All of it is additive; a client gates on `engine.update`, `engine.rollback` and `engine.keys` in `hello.commands`.

- **`engine_update`** `{status, current, available, current_revoked, applied, from, to, restart_requested, rolled_back_from?}`: the one event of `engine update --check`, `engine update --apply` and `engine rollback`. `status` is `up_to_date`, `available` or `no_index` (the server has no engine to offer; not an error). `available` is `{version, notes, size, sequence}` or null. `current_revoked` is true when the server has withdrawn the running version. After an `--apply` or a rollback that changed the engine, `applied` is true, `from` and `to` name the versions, and `restart_requested` says the engine asked the running desk agent to restart on the new binary: the agent finishes its current job and exits, and whatever started it starts it again (the Benchy tray does). The previous engine is kept, and `engine rollback` goes back to it.
- **`engine_keys`** `{keys[], denied_key_ids[], test_keys}`: the engine-release key ids this engine trusts (ids only, never key material). `test_keys` is true in a development build, which accepts no update.
- **Runtime components in `engine_update`** (engines with the `component-updates` hello capability; an engine without it sends none of this, and the client shows nothing). `engine update --check --components` adds `components` to the event: a list of `{name, from, to, notes, needs_admin, busy}`, one per runtime component (`php`, `client`, `benchy`, `cygwin`; `vcredist` only as a requirement of another) that the paired server holds a newer version of. `notes` is plain text, `needs_admin` is true when the step needs a person at the desk, `busy` when something of this install is running and the step has to wait. Plain `engine update --apply` verifies and stages everything on offer (the engine itself included) and its event may carry `applier_required: true` and `plan`, the absolute path of `<install dir>\update\plan.json`. The client then starts `benchy-engine update apply-components --plan <plan>` detached (no window, its own process group, out of the client's job); the exact contract is section 13.9 of the design (staff repo, `ideas/developing/benchy-engine-independent-updates.md`): the applier creates `<install dir>\update\client-quit.request`, the client quits when idle and deletes it, and the applier starts the client again with the `args` in `<install dir>\update\client-relaunch.json`.
- **Errors.** `engine_update_refused` (nothing on the machine changed) carries a stable `detail.reason`: `bad_index`, `rollback`, `same_pair_differs`, `downgrade`, `revoked_version`, `below_floor`, `no_artifact`, `protocol_mismatch`, `same_version_differs`, `bad_download`, `bad_archive`, `smoke_failed`, `path_not_writable`, `protected_engine`, `no_previous_engine`, `rolled_back`, `running_as_previous`, `install_broken`, or one of the catalog envelope's own (`bad_signature`, `unknown_key`, `denied_key`, `retired_key`, `bad_envelope`, `limit`). `engine_update_state_corrupt` means the engine's own memory of retired keys and withdrawn versions can't be read; `engine update --reset-state --yes` clears it. A run holding the engine is `locked` with `detail.reason: busy`, and a machine that isn't paired is `permission` with `detail.reason: not_enrolled`.
- **On the server.** `hello_response` may carry `engine: {available: {version, sequence}}` (optional, Benchy 0.13.0 and later), and `GET /api/v2/engine/index.signed` and `GET /api/v2/engine/files/{sha256}` serve the signed index and the archives to paired, approved systems. An engine older than 0.11.0 ignores all of it.

## Error codes and exit codes

`error.code`: `usage`, `unsupported_command`, `unsupported_schema`, `not_found`, `prompt_required`,
`license_required`, `dependency_missing`, `network`, `download_failed`, `install_failed`, `run_failed`, `permission`,
`cancelled`, `locked`, `server`, `internal`, `catalog_refused`, `catalog_state_corrupt`, `engine_update_refused`,
`engine_update_state_corrupt`.

`catalog_refused` and `catalog_state_corrupt` (0.9.14, additive): the engine's signed test catalog (0.6 and later) refuses a catalog or a pack that fails verification (`catalog_refused`, with a stable `detail.reason`), or can't read its own state (`catalog_state_corrupt`). Both can be the error of a single test in a result, so a result with one of them is still a valid result.

`locked` (0.9): `benchmark` and `install` run one at a time per user path. When another run holds the lock (a JSON run,
a terminal `benchmark`/`install`, or a Phoromatic task), a JSON `benchmark`/`install` gets `error{code: locked, detail:
{run, command, pid}}` (each may be null; 0.9.1, additive: `detail.engine`, the name of the engine holding the lock, e.g. `benchy-pts`, null when unknown) and exit 1 before any other event. The lock is free again by the time the
holder's `done` is written, and whenever the holder process ends, however it ends. Runs outside JSON mode only warn.

`network` with `detail: {tls: "verify_failed", host, port}` (0.9, additive): the certificate of a Benchy server (`https://`) could not be
verified against the CA file (`BENCHY_SERVER_CAFILE`, else `server-ca.pem` in the user path) or (on Linux) the system store. The request failed and
nothing was sent; there is no fallback to HTTP. The `message` names the host and the fix (`benchy server-trust https://HOST:PORT/ACCOUNT
--fingerprint SHA256`). It is non-fatal on its own; the command decides whether it can go on, and the same reason is logged at most once an hour.

`log.source` is `engine` for the engine's own messages (known startup notices such as no Internet connectivity or a
missing PHP extension come once each, at level `notice` or `warning`) and `output` for any other text the engine
printed, captured. `output` lines are legal but not expected; the human system table is not printed in JSON mode.

Exit codes: 0 ok; 1 error; 2 usage, unsupported command or schema; 3 a prompt needed an answer; 4 cancelled; 5 partial
(some tests failed or were skipped, the rest succeeded; a test the run had to leave out gets `test_result{status: skipped}`); 10 an update is available (reserved for `self-update --check`).

## Example

    $ benchy --json benchmark pts/compress-zstd --option pts/compress-zstd.compression-level=3 --name zstd-1
    {"v":1,"type":"hello","seq":1,"ts":"...","engine":{"name":"benchy-pts","version":"0.8.1"},"protocol":1,...,"run":"20261104T101203Z-a1b2c3"}
    {"v":1,"type":"run_plan","seq":9,...,"result_name":"zstd-1","tests":[{"test":{"ref":"pts/compress-zstd","version":"1.6.0"},"options":{"compression-level":"3"},...}]}
    ...
    {"v":1,"type":"test_result","seq":31,...,"metric":"Compression Speed","value":1234.5,"unit":"MB/s","direction":"higher","trials":[...],"status":"ok","error":null}
    {"v":1,"type":"run_end","seq":32,...,"status":"ok","result":{"id":"zstd-1","title":"zstd-1"},"completed":1,"failed":0,"cancelled":0}
    {"v":1,"type":"done","seq":33,...,"status":"ok","exit_code":0}
