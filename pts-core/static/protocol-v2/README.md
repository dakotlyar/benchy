# Agent protocol v2

How a native engine on a machine talks to a Benchy (Phoromatic) server: it
enrols once, then polls for work, runs it, reports as it goes and uploads the
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
* After `enrol`, every call has `Authorization: Bearer bsa_...`. The server keeps
  only the token's SHA-256.
* Request bodies are at most 1 MiB, except the result upload (16 MiB).
* `{id}` in a URL is the integer `job.id` from a poll reply.

## Endpoints

| Call | Request def | Reply def (success) |
|---|---|---|
| `POST /api/v2/enrol` (no auth) | `enrol_request` | `enrol_response` (201) |
| `POST /api/v2/agent/hello` | `hello_request` | `hello_response` |
| `POST /api/v2/agent/poll` | `poll_request` | `poll_response` |
| `POST /api/v2/jobs/{id}/events` | `events_request` | `events_response` |
| `POST /api/v2/jobs/{id}/result` | multipart, see below | `result_response` |
| `POST /api/v2/jobs/{id}/end` | `end_request` | `end_response` |

Any failure is `error_reply`. A `/jobs/{id}` URL that names a job of another
account is `404 not_found`, the same as a job that doesn't exist, so job ids reveal
nothing across accounts; 409 `job_not_yours` is only for another system's job inside the
agent's own account.

**enrol.** An admin makes a single-use code (`ben_` plus 43 characters, good for
24 hours) on the Systems page and hands it over, usually inside a pairing string
`benchy-enrol:https://host:port?code=ben_...&ca=<sha256 hex>`. The agent sends the
code with a description of the machine (`machine_id` is a SHA-256, never the raw
id) and gets back a long-lived system token (`bsa_` plus 43). The token is shown
to nobody. Enrolling a system again revokes its previous token.

**hello.** Sent when the agent starts, every 6 hours, and whenever a reply says
`rehello`. It carries the sysinfo and the agent's catalogue of runnable native
tests (the contract's `test_info` shape), which the schedule page offers to the
admin. It also says which job the agent is still holding (`current_job`) and how
many interrupted run directories it has kept (`kept_run_dirs`). The reply says
what the server thinks the system is (`policy`, `approved`), how often to poll,
and which download `mirrors` to try after the user's own.

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
`cancel` in the reply is always `false` for now; an agent that gets `true` cancels
its child.

**result.** `multipart/form-data` with a `result` part (`application/json`: a saved
result, `result-v1`, with exactly one run, whose `run` is the job's run id) and an
optional `events` part (the whole event stream as gzip-compressed JSON lines,
4 MiB at most). The reply is `{upload_id, url}`. A second upload for the same run id
returns the first reply, so an agent can retry freely. A run that has no `ok` entry
is `422 result_invalid`; an agent stops retrying on a 422.

**end.** The last call for every job, and safe to repeat. `status` is `ok`,
`partial`, `failed`, `cancelled` or `deferred`. `deferred` means the agent never got
the machine (a local run won the race): the server doesn't count the trigger as
run. `cancelled` with reason `local_cancel` does count it, so a job a person stopped
isn't started again a minute later. `lost` is a status only the server sets.

## Errors

| HTTP | `error.code` |
|---|---|
| 400 | `bad_request` |
| 401 | `unauthenticated`, `token_revoked`; on `enrol` only: `code_invalid` (unknown or revoked), `code_expired`, `code_used` |
| 403 | `tenant_suspended`, `system_not_approved` |
| 404 | `not_found` |
| 409 | `job_not_yours` (a job of another system in the same account), `job_ended` |
| 413 | `too_large` |
| 422 | `result_invalid` |
| 426 | `https_required` |
| 429 | `rate_limited` (a `Retry-After` header is welcome, not required) |
| 5xx | `server_error` |

The server sends only the codes in this table (they are `server_error_code` in the
schema). `error.code` itself is an open string, matching `^[a-z][a-z0-9_]*$`: an agent
accepts any code, and one it doesn't know it handles by the HTTP status. That keeps a
later auth scheme (which would bring its own codes) from needing a new protocol version. `token_revoked`
and `unauthenticated` stop the agent. Network errors, 5xx and 429 are retried
with backoff. `tenant_suspended` waits an hour.

How the engine reports these to its caller (as `permission`, `network` or `server`
errors with a `detail.reason`) is in contract v1, section 12.10.

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
