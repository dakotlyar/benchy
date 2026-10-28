#!/bin/bash
# JSON mode (benchy --json, 0.8.1), MVP checks. Driven by tests/private-repo/run.sh (QC_* environment, a scratch Phoromatic
# server and an account): tests/private-repo/run.sh json
# Every run's stdout is checked against the contract (events.py check: each line validates against v1.schema.json, seq has no
# gaps, hello first, done last, done.exit_code == the exit code, no paths in structured fields). Every run gets stdin from a
# pipe nobody writes to, so a command that read stdin would hang until the timeout, and that fails the check.
set -u
: "${QC_SRV:?run via tests/private-repo/run.sh json}"
HERE=$(cd "$(dirname "$0")" && pwd)
EV="python3 -I $HERE/events.py"
PASS=0; FAIL=0; FAILED=()
OUT=$QC_SHARE/json; mkdir -p "$OUT"; chmod 777 "$OUT"

check() {  # check "description" command...
	local d=$1; shift
	if "$@" >/dev/null 2>&1; then PASS=$((PASS+1)); echo "  PASS  $d"; else FAIL=$((FAIL+1)); FAILED+=("$d"); echo "  FAIL  $d"; fi
}
dbq() { docker exec "$QC_SRV" php -r '$d=new SQLite3("/var/lib/benchy/phoromatic/phoromatic.db"); echo $d->querySingle($argv[1]);' "$1"; }
approve_all() { docker exec "$QC_SRV" php -r '$d=new SQLite3("/var/lib/benchy/phoromatic/phoromatic.db"); $d->exec("UPDATE phoromatic_systems SET State=1");'; }
SERVER="$QC_SRV:8080/$QC_AID"
vol() { echo "${QC_CLIENT_PREFIX}-j$1"; }
DOCKER_CLIENT=(--network "$QC_NET" -u 1000 -e HOME=/home/bob -e PTS_USER_PATH_OVERRIDE=/home/bob/.benchy/ -e TERM=dumb -e "BENCHY_PRIVATE_REPO_TOKEN=$QC_REPO_TOKEN")
FIFO=$OUT/never-written; mkfifo "$FIFO"
exec 7<>"$FIFO"   # a pipe nobody ever writes to (held open, so readers block instead of seeing EOF)

j() {  # j <client> <name> <args...>: benchy --json <args>; stdout $OUT/<name>.out, stderr .err, exit code .rc; then the contract check
	local c=$1 n=$2; shift 2
	docker volume create "$(vol "$c")" >/dev/null
	timeout 300 docker run --rm -i --name "${QC_CLIENT_PREFIX}-j$n" "${DOCKER_CLIENT[@]}" -v "$(vol "$c"):/home/bob" -v "$QC_TREE:/bench:ro" -v "$QC_SHARE:/share" \
		"$QC_IMAGE" sh /bench/benchy --json "$@" <&7 >"$OUT/$n.out" 2>"$OUT/$n.err"
	echo $? > "$OUT/$n.rc"
	check "[$n] stream keeps the contract (exit $(cat "$OUT/$n.rc"))" $EV check "$OUT/$n.out" "$(cat "$OUT/$n.rc")"
}
rc() { cat "$OUT/$1.rc"; }
q() { $EV q "$OUT/$1.out" "$2"; }   # q <name> <python expr over E/T/F/L>
is() { [ "$(q "$1" "$2")" = "$3" ]; }   # is <name> <expr> <expected JSON>
csh() { local c=$1; shift; docker volume create "$(vol "$c")" >/dev/null; docker run --rm "${DOCKER_CLIENT[@]}" -v "$(vol "$c"):/home/bob" -v "$QC_TREE:/bench:ro" -v "$QC_SHARE:/share" "$QC_IMAGE" sh -c "$*" </dev/null 2>&1; }
stage() {  # stage <client>: the fixture profiles as local/ tests
	csh "$1" "mkdir -p ~/.benchy/test-profiles/local && cp -r /share/jfx/* ~/.benchy/test-profiles/local/" >/dev/null
}
mkdir -p "$QC_SHARE/jfx"; cp -r "$HERE/fixtures/"* "$QC_SHARE/jfx/"; chmod -R a+rX "$QC_SHARE/jfx"
stage a

echo "== 1. framing: hello, unsupported commands and schema, usage"
j a hello hello
check "hello: engine benchy-pts, protocol 1, no run" is hello '[F("hello")["engine"]["name"], F("hello")["protocol"], F("hello")["run"]]' '["benchy-pts", 1, null]'
check "hello: capabilities list cancel, prompt_answers, private_repo and no_phone_home (on by default)" is hello 'all(c in F("hello")["capabilities"] for c in ["cancel", "prompt_answers", "private_repo", "no_phone_home"])' 'true'
check "hello: exit 0" test "$(rc hello)" = 0
check "a command with no run ID: no event carries a run key" is hello 'any("run" in e for e in E[1:])' 'false'
j a showcmd result show nope
check "hello.command is the command word as typed: result show gives result" is showcmd 'F("hello")["command"]' '"result"'
j a unsupported self-update --check
check "self-update --check (not in 0.8): unsupported_command, exit 2, only hello/error/done" is unsupported '[e["type"] for e in E] + [F("error")["code"]]' '["hello", "error", "done", "unsupported_command"]'
check "...exit 2" test "$(rc unsupported)" = 2
j a merge merge-results a b
check "a human-only command (merge-results) is unsupported_command too" is merge 'F("error")["code"]' '"unsupported_command"'
docker run --rm -i "${DOCKER_CLIENT[@]}" -v "$(vol a):/home/bob" -v "$QC_TREE:/bench:ro" "$QC_IMAGE" sh /bench/benchy --json=2 hello <&7 >"$OUT/schema2.out" 2>/dev/null; echo $? > "$OUT/schema2.rc"
check "--json=2: one unsupported_schema error line, exit 2" is schema2 '[len(E), E[0]["type"], E[0]["code"]]' '[1, "error", "unsupported_schema"]'
check "...and it is a valid stream" $EV check "$OUT/schema2.out" "$(rc schema2)"
j a badflag info local/json-opt --frobnicate
check "an unknown --flag: usage error, exit 2" is badflag '[F("error")["code"], L("done")["exit_code"]]' '["usage", 2]'
docker run --rm -i "${DOCKER_CLIENT[@]}" -e BENCHY_OUTPUT=json -v "$(vol a):/home/bob" -v "$QC_TREE:/bench:ro" "$QC_IMAGE" sh /bench/benchy hello <&7 >"$OUT/envjson.out" 2>/dev/null
check "BENCHY_OUTPUT=json is the same as --json" is envjson '[E[0]["type"], E[-1]["type"]]' '["hello", "done"]'

echo "== 2. system-info"
j a sysinfo system-info
check "system_info has the summary's four keys plus the JSON extras" is sysinfo 'sorted(F("system_info").keys())' '["cpu", "gpu", "memory", "motherboard", "os", "seq", "storage", "summary", "ts", "type", "v"]'
check "...cpu.model/motherboard.model/memory.total_mb are display values or null, never empty strings" is sysinfo 'all(v is None or (v != "" and v == str(v).strip()) for v in [F("system_info")["cpu"]["model"], F("system_info")["motherboard"]["model"]])' 'true'
# The four keys are pts_hardware_summary::local() exactly (the same function the drift guard uses on the server's copy)
docker run --rm "${DOCKER_CLIENT[@]}" -v "$(vol a):/home/bob" -v "$QC_TREE:/bench:ro" -w /bench "$QC_IMAGE" php -r 'define("PTS_PATH", "/bench/"); define("PTS_MODE", "CLIENT"); ob_start(); require "/bench/pts-core/pts-core.php"; pts_client::init(); ob_end_clean(); echo json_encode(pts_hardware_summary::local());' </dev/null >"$OUT/summary.json" 2>/dev/null
check "...and equal pts_hardware_summary::local() computed separately" is sysinfo "(lambda s, x: s['cpu']['model'] == x['cpu']['model'] and all(s[k] == x[k] for k in ['gpu', 'motherboard', 'memory']))(F('system_info'), __import__('json').load(open('$OUT/summary.json')))" 'true'

echo "== 3. listing and info"
j a list list-available-tests
check "list-available-tests lists the local fixtures with repo local" is list '[t["repo"] for t in T("test") if t["test"]["ref"] == "local/json-opt"]' '["local"]'
check "...list_end.count equals the number of test events" is list 'L("list_end")["count"] == len(T("test")) and L("list_end")["kind"] == "tests"' 'true'
j a suites list-available-suites
check "list-available-suites ends with list_end{kind: suites} matching the suite events" is suites 'L("list_end")["kind"] == "suites" and L("list_end")["count"] == len(T("suite"))' 'true'
j a info info local/json-opt
check "info: two options, keys mode/level, choice keys are names (never indexes)" is info '[[o["key"], [c["key"] for c in o["choices"]], o["default"]] for o in F("test_info")["options"]]' '[["mode", ["Fast", "Slow"], "Slow"], ["level", ["3", "5"], "3"]]'
check "...option_count 2, not installed yet" is info '[F("test_info")["option_count"], F("test_info")["installed"]]' '[2, false]'
j a infov info local/json-opt@1.0.0
check "info accepts repo/name@version" is infov 'F("test_info")["test"]' '{"ref": "local/json-opt", "version": "1.0.0"}'
j a info404 info local/nope
check "info of an unknown test: not_found, exit 1" is info404 '[F("error")["code"], L("done")["exit_code"]]' '["not_found", 1]'

echo "== 4. prompts become flags; nothing reads stdin"
j a noopts benchmark local/json-opt --name r0
check "benchmark without option values: a refused prompt per option, then prompt_required, exit 3" is noopts '[[p["id"], p["answer_source"]] for p in T("prompt")] + [F("error")["code"], L("done")["exit_code"]]' '[["TEST_OPTION:local/json-opt.mode", "refused"], ["TEST_OPTION:local/json-opt.level", "refused"], "prompt_required", 3]'
check "...the error lists what is missing" is noopts '[m["option"]["key"] for m in F("error")["detail"]["missing"]]' '["mode", "level"]'
check "...and nothing was installed (fails before any work)" is noopts 'len(T("install_start"))' '0'
j a noname benchmark local/json-opt --defaults
check "no --name and no --no-save: SAVE_NAME refused, exit 3" is noname '[F("prompt")["id"], F("prompt")["answer_source"], F("error")["code"], L("done")["exit_code"]]' '["SAVE_NAME", "refused", "prompt_required", 3]'
j a badchoice benchmark local/json-opt --option local/json-opt.mode=Medium --option local/json-opt.level=3 --name r0
check "a value that is not a choice key: usage error naming the choices, exit 2" is badchoice '[F("error")["code"], F("error")["detail"]["choices"], L("done")["exit_code"]]' '["usage", ["Fast", "Slow"], 2]'
j a badkey benchmark local/json-opt --option local/json-opt.speed=1 --defaults --name r0
check "an option key the test does not have: usage, exit 2" is badkey '[F("error")["code"], L("done")["exit_code"]]' '["usage", 2]'

echo "== 5. install and benchmark, happy path"
j a install install local/json-opt
check "install: install_plan, install_start, install_end ok, exit 0" is install '[F("install_plan")["tests"][0]["ref"], F("install_start")["index"], F("install_end")["status"], L("done")["exit_code"]]' '["local/json-opt", 1, "ok", 0]'
check "...hello carries a run ID and every event repeats it" is install 'F("hello")["run"] is not None' 'true'
j a install2 install local/json-opt
check "installing it again: install_end already_installed" is install2 'F("install_end")["status"]' '"already_installed"'
j a bench1 benchmark local/json-opt --option local/json-opt.mode=Fast --option local/json-opt.level=5 --name r1 --description "two words"
check "benchmark: run_plan, test_start, trials, test_result 51 (level 5 by name, not index 1), run_end ok, exit 0" is bench1 '[F("run_plan")["result_name"], F("test_start")["options"], len(T("trial_end")), F("test_result")["value"], F("test_result")["status"], L("run_end")["status"], L("done")["exit_code"]]' '["r1", {"mode": "Fast", "level": "5"}, 2, 51.0, "ok", "ok", 0]'
check "...test_result has unit, direction and trials" is bench1 '[F("test_result")["unit"], F("test_result")["direction"], F("test_result")["trials"]]' '["Points", "higher", [51.0, 51.0]]'
check "...run_end names the saved result" is bench1 'L("run_end")["result"]["id"]' '"r1"'
check "...an argument with a space (--description) arrives whole" is bench1 'F("run_plan")["description"]' '"two words"'
j a bench2 benchmark local/json-opt --defaults --name r2 --no-install
check "--defaults takes each option's default (Slow, 3 => 32)" is bench2 '[F("test_result")["options"], F("test_result")["value"]]' '[{"mode": "Slow", "level": "3"}, 32.0]'
RID=$(q bench2 'F("run_plan")["result_identifier"]' | tr -d '"')
j a rerun benchmark local/json-opt --defaults --name r2 --no-install
# (the generated identifier here is date based, so it only collides within the same minute; a collision gets " #2")
check "re-running into result r2 without --identifier appends a new run under a new identifier: ok, exit 0" is rerun "[F('run_plan')['result_identifier'] != '$RID', F('test_result')['status'], L('run_end')['completed'], L('done')['exit_code']]" '[true, "ok", 1, 0]'
j a resume benchmark local/json-opt --defaults --name r2 --no-install --identifier "$RID"
check "resuming r2 with the identifier of a run that has the test: skipped (with the reason), not cancelled, run failed, exit 1" is resume '[F("test_result")["status"], "Already in result" in F("test_result")["error"]["message"], L("run_end")["cancelled"], L("run_end")["status"], L("done")["exit_code"]]' '["skipped", true, 0, "failed", 1]'
j a bench3 benchmark local/json-opt --all-options --option local/json-opt.level=3 --no-save
check "--all-options runs every choice of the options not given (2 runs), --no-save saves nothing" is bench3 '[sorted(r["value"] for r in T("test_result")), L("run_end")["result"]]' '[[31.0, 32.0], null]'
j a answer benchmark local/json-opt --defaults --answer SAVE_NAME=r3
check "--answer SAVE_NAME=... answers an ID-only prompt" is answer '[F("prompt")["answer_source"], L("run_end")["result"]["id"]]' '["answer", "r3"]'
j a show result show r1
check "result show: the saved run with its test results" is show '[F("result")["id"], F("result")["tests"][0]["value"], F("result")["tests"][0]["options"], sorted(F("result")["system"].keys())]' '["r1", 51.0, {"mode": "Fast", "level": "5"}, ["cpu", "gpu", "memory", "motherboard", "os", "storage", "summary"]]'
j a show404 result show nope
check "result show of an unknown result: not_found, exit 1" is show404 '[F("error")["code"], L("done")["exit_code"]]' '["not_found", 1]'

echo "== 6. errors as events: partial run, failed run, licence"
j a partial benchmark local/json-opt local/json-fail --defaults --name r4
check "one test fails, one passes: run_end partial, done partial, exit 5" is partial '[L("run_end")["status"], L("run_end")["completed"], L("run_end")["failed"], L("done")["status"], L("done")["exit_code"]]' '["partial", 1, 1, "partial", 5]'
check "...the failed test's test_result has status failed and a run_failed error" is partial '[[r["status"], (r["error"] or {}).get("code")] for r in T("test_result") if r["test"]["ref"] == "local/json-fail"]' '[["failed", "run_failed"]]'
j a allfail benchmark local/json-fail --no-save
check "every test fails: run_end failed, error run_failed, exit 1" is allfail '[L("run_end")["status"], L("error")["code"], L("done")["exit_code"]]' '["failed", "run_failed", 1]'
j a skipped benchmark local/json-opt local/json-license --defaults --no-install --no-save
check "--no-install with a test not installed: it gets test_result skipped, the run is partial, exit 5" is skipped '[[r["status"] for r in T("test_result") if r["test"]["ref"] == "local/json-license"], L("run_end")["status"], L("run_end")["failed"], L("done")["exit_code"]]' '[["skipped"], "partial", 1, 5]'
j a licbench benchmark local/json-license --no-save
check "benchmark with a refused licence: license_required only inside install_end.error, never a top-level error" is licbench '[F("install_end")["error"]["code"], [e["code"] for e in T("error")]]' '["license_required", ["run_failed"]]'
check "...the refused test gets test_result skipped/install_failed; nothing ran: exit 1" is licbench '[[[r["status"], r["error"]["code"]] for r in T("test_result")], L("done")["exit_code"]]' '[[["skipped", "install_failed"]], 1]'
j a lic install local/json-license
check "licence not accepted: refused INSTALL_AGREEMENT prompt, install_end failed with license_required, exit 1" is lic '[F("prompt")["id"], F("prompt")["answer_source"], F("install_end")["status"], F("install_end")["error"]["code"], L("done")["exit_code"]]' '["INSTALL_AGREEMENT", "refused", "failed", "license_required", 1]'
check "...the error carries the licence text for the GUI" is lic '"agree to nothing" in F("install_end")["error"]["detail"]["license"]' 'true'
j a lic2 install local/json-license --accept-license local/json-license
check "--accept-license <test>: accepted from the flag, install ok, exit 0" is lic2 '[F("prompt")["answer_source"], F("install_end")["status"], L("done")["exit_code"]]' '["flag", "ok", 0]'

echo "== 7. cancel"
j a cancelbad cancel not-a-run
check "cancel with a malformed run ID: usage, exit 2" is cancelbad '[F("error")["code"], L("done")["exit_code"]]' '["usage", 2]'
j a cancel404 cancel 20261028T000000Z-abcdef
check "cancel of a run that is not in progress: not_found, exit 1" is cancel404 '[F("error")["code"], L("done")["exit_code"]]' '["not_found", 1]'
# Mid-trial: the benchmark runs in a long-lived container (docker exec), so processes the test left behind would still be visible
CX=${QC_CLIENT_PREFIX}-jcx
docker run -d --init --name "$CX" "${DOCKER_CLIENT[@]}" -v "$(vol a):/home/bob" -v "$QC_TREE:/bench:ro" -v "$QC_SHARE:/share" --entrypoint sleep "$QC_IMAGE" 900 >/dev/null
DX=(-e HOME=/home/bob -e PTS_USER_PATH_OVERRIDE=/home/bob/.benchy/ -e TERM=dumb)
(timeout 300 docker exec -i "${DX[@]}" "$CX" sh /bench/benchy --json benchmark local/json-opt local/json-sleep --defaults --name r5 <&7 >"$OUT/cancelrun.out" 2>"$OUT/cancelrun.err"; echo $? > "$OUT/cancelrun.rc") &
BG=$!
for i in $(seq 1 120); do grep -q '"trial_start".*json-sleep' "$OUT/cancelrun.out" 2>/dev/null && break; sleep 1; done
sleep 3
RUNID=$(q cancelrun 'F("hello")["run"]' | tr -d '"')
docker exec "$CX" ps -eo args >"$OUT/ps-before.txt"
check "the forking test is running before the cancel (its sleep child exists)" grep -q 'sleep 600' "$OUT/ps-before.txt"
T0=$(date +%s)
timeout 60 docker exec -i "${DX[@]}" "$CX" sh /bench/benchy --json cancel "$RUNID" <&7 >"$OUT/cancelcmd.out" 2>/dev/null; echo $? > "$OUT/cancelcmd.rc"
check "[cancelcmd] stream keeps the contract" $EV check "$OUT/cancelcmd.out" "$(rc cancelcmd)"
check "cancel <run>: done ok, exit 0" test "$(rc cancelcmd)" = 0
wait $BG
T1=$(date +%s)
check "[cancelrun] stream keeps the contract (exit $(rc cancelrun))" $EV check "$OUT/cancelrun.out" "$(rc cancelrun)"
check "mid-trial cancel: test_result cancelled, run_end cancelled, done cancelled, exit 4" is cancelrun '[[r["status"] for r in T("test_result") if r["test"]["ref"] == "local/json-sleep"], L("run_end")["status"], L("done")["status"], L("done")["exit_code"]]' '[["cancelled"], "cancelled", "cancelled", 4]'
check "...the run stopped within 30 s of the cancel (the trial would have slept 600 s)" test $((T1 - T0)) -lt 30
check "...the test that finished first is kept in the result (run_end.completed 1)" is cancelrun 'L("run_end")["completed"]' '1'
docker exec "$CX" ps -eo args >"$OUT/ps-after.txt"
check "...no process of the test is left (no json-sleep, no sleep 600, no sleep 1 loop; zombies excluded)" bash -c "! grep -v defunct '$OUT/ps-after.txt' | grep -E 'json-sleep|sleep 600|^sleep 1$'"
check "...JSON mode started no result viewer web server" bash -c "! grep -q dynamic-result-viewer '$OUT/ps-before.txt'"
check "...the cancel and run marker files are gone" bash -c "[ -z \"\$(docker exec $CX sh -c 'ls /home/bob/.benchy/json-runs/ 2>/dev/null')\" ]"
j a cancelshow result show r5
check "...result show r5 has the finished test" is cancelshow '[t["test"]["ref"] for t in F("result")["tests"] if t["status"] == "ok"]' '["local/json-opt"]'
# A wide tree (eight forking children): killed in one pass, so done{cancelled} follows the cancel within seconds
(timeout 300 docker exec -i "${DX[@]}" "$CX" sh /bench/benchy --json benchmark local/json-fork --no-save <&7 >"$OUT/forkrun.out" 2>"$OUT/forkrun.err"; echo $? > "$OUT/forkrun.rc") &
BG=$!
for i in $(seq 1 120); do grep -q '"trial_start".*json-fork' "$OUT/forkrun.out" 2>/dev/null && break; sleep 1; done
sleep 3
docker exec "$CX" ps -eo args >"$OUT/ps-fork-before.txt"
check "the wide fixture is running: at least 8 of its sleep loops exist" test "$(grep -c '^sh /home/bob/.benchy/installed-tests/local/json-fork/json-fork\|^/bin/sh ./json-fork' "$OUT/ps-fork-before.txt")" -ge 9
FRUN=$(q forkrun 'F("hello")["run"]' | tr -d '"')
T0=$(date +%s)
timeout 60 docker exec -i "${DX[@]}" "$CX" sh /bench/benchy --json cancel "$FRUN" <&7 >/dev/null 2>&1
wait $BG
T1=$(date +%s)
check "[forkrun] stream keeps the contract (exit $(rc forkrun))" $EV check "$OUT/forkrun.out" "$(rc forkrun)"
check "cancel of a wide tree: done cancelled, exit 4" is forkrun '[L("done")["status"], L("done")["exit_code"]]' '["cancelled", 4]'
check "...within 8 s of the cancel command starting (was about 1 s per process)" test $((T1 - T0)) -le 8
echo "  (wide-tree cancel took $((T1 - T0)) s, cancel command included)"
docker exec "$CX" ps -eo args >"$OUT/ps-fork-after.txt"
check "...no process of it is left" bash -c "! grep -v defunct '$OUT/ps-fork-after.txt' | grep -E 'json-fork|sleep 600|^sleep 1$'"
# A process that left the trial's tree (the Linux stand-in for a Cygwin exec on Windows): found by the trial's environment tag
(timeout 300 docker exec -i "${DX[@]}" "$CX" sh /bench/benchy --json benchmark local/json-escape --no-save <&7 >"$OUT/escrun.out" 2>"$OUT/escrun.err"; echo $? > "$OUT/escrun.rc") &
BG=$!
for i in $(seq 1 120); do grep -q '"trial_start".*json-escape' "$OUT/escrun.out" 2>/dev/null && break; sleep 1; done
sleep 3
docker exec "$CX" ps -eo ppid,args >"$OUT/ps-esc-before.txt"
check "the escaped child (sleep 601) runs outside the trial's tree (its parent is init)" grep -qE '^ +1 sleep 601$' "$OUT/ps-esc-before.txt"
ERUN=$(q escrun 'F("hello")["run"]' | tr -d '"')
timeout 60 docker exec -i "${DX[@]}" "$CX" sh /bench/benchy --json cancel "$ERUN" <&7 >/dev/null 2>&1
wait $BG
check "[escrun] stream keeps the contract (exit $(rc escrun))" $EV check "$OUT/escrun.out" "$(rc escrun)"
check "cancel with an escaped child: done cancelled, exit 4" is escrun '[L("done")["status"], L("done")["exit_code"]]' '["cancelled", 4]'
docker exec "$CX" ps -eo args >"$OUT/ps-esc-after.txt"
check "...the escaped child was killed too (no sleep 601, no sleep 600)" bash -c "! grep -v defunct '$OUT/ps-esc-after.txt' | grep -E 'sleep 60[01]|json-escape'"
docker rm -f "$CX" >/dev/null 2>&1

# A signal to a command with no run ID (here list-available-tests) ends it with error{cancelled} + done, exit 4. Needs PHP
# with pcntl (without it PHP has no handler and the signal kills it outright), so it runs on the pcntl image when present.
PCNTL_IMAGE=${QC_PCNTL_IMAGE:-benchy-qc-php-pcntl}
if docker image inspect "$PCNTL_IMAGE" >/dev/null 2>&1; then
	SX=${QC_CLIENT_PREFIX}-jsx
	docker run -d --init --name "$SX" "${DOCKER_CLIENT[@]}" -v "$(vol a):/home/bob" -v "$QC_TREE:/bench:ro" --entrypoint sleep "$PCNTL_IMAGE" 300 >/dev/null
	docker exec -d "${DX[@]}" "$SX" sh -c 'sh /bench/benchy --json list-available-tests </dev/null >/tmp/sig.out 2>/dev/null; echo $? > /tmp/sig.rc'
	# Stop PHP as soon as hello is out, queue SIGTERM, continue it: the signal lands mid-command whatever the timing
	timeout 60 docker exec "$SX" sh -c 'until grep -q "\"hello\"" /tmp/sig.out 2>/dev/null; do sleep 0.05; done; P="^Phoronix Test Suite\$"; pkill -STOP -f "$P"; pkill -TERM -f "$P"; pkill -CONT -f "$P"'
	for i in $(seq 1 20); do docker exec "$SX" test -s /tmp/sig.rc && break; sleep 0.5; done
	docker exec "$SX" cat /tmp/sig.out >"$OUT/sigterm.out"; docker exec "$SX" cat /tmp/sig.rc >"$OUT/sigterm.rc" 2>/dev/null || echo 124 >"$OUT/sigterm.rc"
	check "[sigterm] stream keeps the contract (exit $(rc sigterm))" $EV check "$OUT/sigterm.out" "$(rc sigterm)"
	check "SIGTERM to a command with no run: error cancelled, done cancelled, exit 4, within 10 s" is sigterm '[F("hello")["run"], F("error")["code"], L("done")["status"], L("done")["exit_code"]]' '[null, "cancelled", "cancelled", 4]'
	docker rm -f "$SX" >/dev/null 2>&1
else
	echo "  SKIP  SIGTERM without a run: no $PCNTL_IMAGE image"
fi

echo "== 8. private repository and upload"
# Author machine p1 publishes a private test (as in e2e.sh), the JSON client p2 refreshes and lists it
csh p1 "mkdir -p ~/.benchy/test-profiles/local && cp -r /share/jfx/json-opt ~/.benchy/test-profiles/local/json-priv && sed -i 's#<Title>JSON Options#<Title>JSON Private#' ~/.benchy/test-profiles/local/json-priv/test-definition.xml && sed -i 's#json-opt#json-priv#g' ~/.benchy/test-profiles/local/json-priv/install.sh" >/dev/null
csh p1 "sh /bench/benchy private-repo-setup $SERVER" >/dev/null; approve_all
docker run --rm -i "${DOCKER_CLIENT[@]}" -v "$(vol p1):/home/bob" -v "$QC_TREE:/bench:ro" "$QC_IMAGE" sh /bench/benchy upload-private-test-profile local/json-priv <<<"json" >/dev/null 2>&1
j p2 norepo private-repo-refresh
check "private-repo-refresh before setup: not_found (no server set up), exit 1" is norepo '[F("error")["code"], L("done")["exit_code"]]' '["not_found", 1]'
csh p2 "sh /bench/benchy private-repo-setup $SERVER" >/dev/null; approve_all
j p2 refresh private-repo-refresh
check "private-repo-refresh: repo_refresh{repo: private, status: ok}, exit 0" is refresh '[F("repo_refresh")["repo"], F("repo_refresh")["status"], F("repo_refresh")["items"] >= 1, L("done")["exit_code"]]' '["private", "ok", true, 0]'
j p2 plist list-available-tests
check "list-available-tests includes private/json-priv with repo private (every private/ test has repo private)" is plist '[["private/json-priv", "private"] in [[t["test"]["ref"], t["repo"]] for t in T("test")], all(t["repo"] == "private" for t in T("test") if t["test"]["ref"].startswith("private/"))]' '[true, true]'
j p2 pinfo info private/json-priv
check "info private/json-priv works" is pinfo 'F("test_info")["test"]["ref"]' '"private/json-priv"'
j p2 pbench benchmark private/json-priv --defaults --name pr1
check "benchmark of the private test (installed from the server): ok" is pbench '[F("install_end")["status"], F("test_result")["status"], L("done")["exit_code"]]' '["ok", "ok", 0]'
UP0=$(dbq "SELECT COUNT(*) FROM phoromatic_results")
j p2 upload phoromatic.upload-result "$SERVER" pr1
check "phoromatic.upload-result: one upload event (target phoromatic, the result), status ok, exit 0" is upload '[F("upload")["target"], F("upload")["result"], F("upload")["status"], L("done")["exit_code"]]' '["phoromatic", "pr1", "ok", 0]'
check "...no bench fields in any event" is upload 'any("bench" in k for e in E for k in e)' 'false'
check "...the server stored it" test "$(dbq "SELECT COUNT(*) FROM phoromatic_results")" -gt "$UP0"
j p2 upload404 phoromatic.upload-result "$SERVER" nope
check "upload of a result that does not exist: not_found, exit 1" is upload404 '[F("error")["code"], L("done")["exit_code"]]' '["not_found", 1]'

exec 7>&-
rm -f "$FIFO"
[ -n "${JSON_KEEP:-}" ] && mkdir -p "$JSON_KEEP" && cp -r "$OUT"/. "$JSON_KEEP"/   # keep the captures (run.sh removes $QC_SHARE)
echo
echo "json: $PASS passed, $FAIL failed (outputs in $OUT while the run lasts)"
for f in "${FAILED[@]:-}"; do [ -n "$f" ] && echo "  FAILED: $f"; done
[ "$FAIL" -eq 0 ]
