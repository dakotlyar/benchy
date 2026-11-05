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
  `json_args` (0.9), `run_lock` (0.9: `benchmark` and `install` refuse with `locked` while another run holds the lock), `no_phone_home` (when on). `global_repo` is reserved for a later release: the schema names it, but no Benchy version reports it yet.
- Structured fields never hold file paths, XML, engine class names or engine argument strings. Free text (`log.text`,
  `error.message`, `prompt.question`, descriptions) may mention paths; do not parse it.

Tests and suites are `{"ref": "repo/name", "version": "x.y.z"}` in events and `repo/name` or `repo/name@version` on
the command line. The private repository is always `private/`. Options are key/value pairs: the key is the option's
key and the value is one of its choice keys (as `info` lists them), or the typed value for a free option.

## Commands

| Command | Events (besides `hello`, `done`, `log`, `error`, `prompt`) |
|---|---|
| `hello` | none: the capability probe |
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

Any other command gives `error{code: unsupported_command}` and exit 2. Reserved for later milestones (additive, no
protocol change): `update_check` (0.12), `agent` (0.10), `config get|set` (0.11).

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

## Error codes and exit codes

`error.code`: `usage`, `unsupported_command`, `unsupported_schema`, `not_found`, `prompt_required`,
`license_required`, `dependency_missing`, `network`, `download_failed`, `install_failed`, `run_failed`, `permission`,
`cancelled`, `locked`, `server`, `internal`.

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
