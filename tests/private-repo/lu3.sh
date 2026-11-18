#!/bin/bash
# LU-S3 (0.10.0): the PHP upload fixes of phoromatic.upload-result. Driven by run.sh lu3 (QC_* environment, the shared scratch server and an
# account). Client-side proofs run against lu3_stub.py, a stand-in server that logs every request, so "nothing was sent to that server" is
# read from a log, not from the code; the server's own parts (approval, one upload id for the same result) run against the real server.
#   1 an upload id means success: Waiting For Approval and a reply without an id are failures (JSON reason, text sentence)
#   2 only the paired server: no pairing means not_found and a decoy server in the client's list gets no request at all; control: the same
#     decoy named on the command line does get one
#   3 an unreachable paired server fails at once (network), and nothing goes to the decoy
#   4 system logs are not sent unless --system-logs is given (they are present in the result's folder)
#   5 text mode says every run saved under the name is uploaded
#   6 the server answers the same ad-hoc bytes (a retry after a lost reply) with the first upload's id and files one row
set -u
: "${QC_SRV:?run via run.sh lu3}"
HERE=$(cd "$(dirname "$0")" && pwd)
. "$HERE/state.sh"
qc_phase_reset lu3
EV="python3 -I $HERE/../json-events/events.py"
PASS=0; FAIL=0; FAILED=()
check() {  # check "description" command...
	local d=$1 out; shift
	if out=$("$@" 2>&1); then PASS=$((PASS+1)); echo "  PASS  $d"; else FAIL=$((FAIL+1)); FAILED+=("$d"); echo "  FAIL  $d"; [ -n "$out" ] && printf '%s\n' "$out" | head -c 6000 | sed 's/^/        | /'; fi
}
contains() { grep -qaF -- "$1" <<<"$2"; }
not_contains() { ! grep -qaF -- "$1" <<<"$2"; }
dbq() { docker exec "$QC_SRV" php -r '$d=new SQLite3("/var/lib/benchy/phoromatic/phoromatic.db"); echo $d->querySingle($argv[1]);' "$1"; }
dbx() { docker exec "$QC_SRV" php -r '$d=new SQLite3("/var/lib/benchy/phoromatic/phoromatic.db"); $d->exec($argv[1]);' "$1"; }
L3=$QC_SHARE/lu3; mkdir -p "$L3"; chmod 777 "$L3"
P=${QC_CLIENT_PREFIX}-lu3p; D=${QC_CLIENT_PREFIX}-lu3d
echo ok > "$L3/mode"; : > "$L3/p.log"; : > "$L3/d.log"; chmod 666 "$L3"/*
cleanup() { docker rm -f "$P" "$D" >/dev/null 2>&1; }
trap cleanup EXIT
for n in "$P:p" "$D:d"; do
	docker run -d --name "${n%%:*}" --network "$QC_NET" -v "$QC_TREE":/bench:ro -v "$QC_SHARE":/share "$QC_IMAGE" python3 /bench/tests/private-repo/lu3_stub.py /share/lu3/${n##*:}.log /share/lu3/mode >/dev/null || { echo "stub failed"; exit 2; }
done
PS="$P:8080/LU3ACC"; DS="$D:8080/LU3ACC"; SERVER="$QC_SRV:8080/$QC_AID"
sleep 2
vol() { echo "${QC_CLIENT_PREFIX}-lu$1"; }
cl() {  # cl <client> <command...>: one throw-away machine (hostname lu3<client>), bounded, stdin closed
	local c=$1; shift
	docker volume create "$(vol "$c")" >/dev/null
	timeout 300 docker run --rm -i --network "$QC_NET" --hostname "lu3$c" -u 1000 -e HOME=/home/bob -e PTS_USER_PATH_OVERRIDE=/home/bob/.benchy/ -e TERM=dumb \
		-v "$(vol "$c"):/home/bob" -v "$QC_TREE:/bench:ro" -v "$QC_SHARE:/share" "$QC_IMAGE" "$@" </dev/null 2>&1 | head -c 400000
}
OUT=$L3/out; mkdir -p "$OUT"; chmod 777 "$OUT"
j() {  # j <client> <name> <benchy args...>: benchy --json; .out, .rc, .secs; contract check
	local c=$1 n=$2; shift 2
	local t0=$SECONDS
	docker volume create "$(vol "$c")" >/dev/null
	timeout 300 docker run --rm -i --network "$QC_NET" --hostname "lu3$c" -u 1000 -e HOME=/home/bob -e PTS_USER_PATH_OVERRIDE=/home/bob/.benchy/ -e TERM=dumb \
		-v "$(vol "$c"):/home/bob" -v "$QC_TREE:/bench:ro" -v "$QC_SHARE:/share" "$QC_IMAGE" sh /bench/benchy --json "$@" </dev/null >"$OUT/$n.out" 2>"$OUT/$n.err"
	echo $? > "$OUT/$n.rc"; echo $((SECONDS - t0)) > "$OUT/$n.secs"
	check "[$n] stream keeps the contract (exit $(cat "$OUT/$n.rc"))" $EV check "$OUT/$n.out" "$(cat "$OUT/$n.rc")"
}
q() { $EV q "$OUT/$1.out" "$2"; }
is() { [ "$(q "$1" "$2")" = "$3" ]; }
rc() { cat "$OUT/$1.rc"; }
nlog() { wc -l < "$L3/$1.log"; }
newreqs() { tail -n +$(( $2 + 1 )) "$L3/$1.log" | python3 -c '
import json, sys
for l in sys.stdin:
    e = json.loads(l); print("   ", e["path"], e["r"], sorted(k for k in e["fields"] if k in ("h", "s", "nm", "msi", "composite_xml")))'; }
posts() { tail -n +$(( $2 + 1 )) "$L3/$1.log" | grep -c "\"r\": \"$3\""; }   # posts <p|d> <from line> <request name>
logfield() { tail -n +$(( $2 + 1 )) "$L3/$1.log" | python3 -c '
import json, sys
print(sum(1 for l in sys.stdin if json.loads(l)["r"] == "result_upload" and sys.argv[1] in json.loads(l)["fields"]))' "$3"; }

# The result to upload, with a system-logs folder so there is something to leave out
mkdir -p "$QC_SHARE/lu3fx"; cp -r "$HERE/../json-events/fixtures/json-opt" "$QC_SHARE/lu3fx/"; chmod -R a+rX "$QC_SHARE/lu3fx"
cl a sh -c 'mkdir -p ~/.benchy/test-profiles/local && cp -r /share/lu3fx/* ~/.benchy/test-profiles/local/' >/dev/null
j a mk benchmark local/json-opt --defaults --name lu3r
check "a result to upload exists" is mk '[F("test_result")["status"], L("done")["exit_code"]]' '["ok", 0]'
cl a sh -c 'mkdir -p ~/.benchy/test-results/lu3r/system-logs && echo log > ~/.benchy/test-results/lu3r/system-logs/x.txt && ls ~/.benchy/test-results/lu3r/' >/dev/null
check "...with a system-logs folder" bash -c "grep -qx x.txt <<<\"\$1\"" _ "$(cl a sh -c 'ls ~/.benchy/test-results/lu3r/system-logs')"
# the other machines get the same result and logs
cl a sh -c 'cp -r ~/.benchy/test-results/lu3r /share/lu3/lu3r' >/dev/null
for c in b c d e; do cl $c sh -c 'mkdir -p ~/.benchy/test-results && cp -r /share/lu3/lu3r ~/.benchy/test-results/' >/dev/null; done

echo "== 1. an upload id means success"
M=$(nlog p); j a ok phoromatic.upload-result "$PS" lu3r
check "stub answers an id: upload event ok with upload_id 7, exit 0" is ok '[F("upload")["status"], F("upload")["upload_id"], L("done")["exit_code"]]' '["ok", 7, 0]'
check "...one result_upload posted" test "$(posts p "$M" result_upload)" = 1
echo approval > "$L3/mode"
j a appr phoromatic.upload-result "$PS" lu3r
check "Waiting For Approval: upload event failed, reason not_approved, no upload_id, error server, exit 1" is appr '[F("upload")["status"], F("upload")["reason"], "upload_id" in F("upload"), F("error")["code"], L("done")["exit_code"]]' '["failed", "not_approved", false, "server", 1]'
out=$(cl a sh /bench/benchy phoromatic.upload-result "$PS" lu3r)
check "...text mode: says the system is waiting for approval, never says Result Uploaded" bash -c 'grep -qa "waiting for approval" <<<"$1" && ! grep -qa "Result Uploaded" <<<"$1"' _ "$out"
echo noid > "$L3/mode"
j a noid phoromatic.upload-result "$PS" lu3r
check "a reply with no upload id: failed (reason server), exit 1" is noid '[F("upload")["status"], F("upload")["reason"], L("done")["exit_code"]]' '["failed", "server", 1]'
out=$(cl a sh /bench/benchy phoromatic.upload-result "$PS" lu3r)
check "...text mode: Upload Failed, not Result Uploaded" bash -c 'grep -qa "Upload Failed" <<<"$1" && ! grep -qa "Result Uploaded" <<<"$1"' _ "$out"
echo zero > "$L3/mode"
j a zero phoromatic.upload-result "$PS" lu3r
check "upload id 0 is not success" is zero '[F("upload")["status"], L("done")["exit_code"]]' '["failed", 1]'
echo notice > "$L3/mode"
j a notice phoromatic.upload-result "$PS" lu3r
check "a PHP notice before the JSON of the reply: still ok with the id" is notice '[F("upload")["status"], F("upload")["upload_id"]]' '["ok", 7]'
echo ok > "$L3/mode"

echo "== 4/5. system logs and the multi-run line"
M=$(nlog p); j a nologs phoromatic.upload-result "$PS" lu3r
check "default: result_upload carries no system_logs_zip although the folder exists" test "$(logfield p "$M" system_logs_zip)" = 0
check "...and no result_log_upload follows" test "$(posts p "$M" result_log_upload)" = 0
M=$(nlog p); j a logs phoromatic.upload-result "$PS" lu3r --system-logs
check "--system-logs: the logs are sent" test "$(logfield p "$M" system_logs_zip)" = 1
M=$(nlog p); out=$(cl a sh /bench/benchy phoromatic.upload-result "$PS" lu3r)
check "text mode: Result Uploaded and the line that every run saved as lu3r is uploaded" bash -c 'grep -qa "Result Uploaded" <<<"$1" && grep -qa "Every run saved as lu3r is uploaded" <<<"$1"' _ "$out"
check "...text mode sends no logs either" test "$(logfield p "$M" system_logs_zip)" = 0
M=$(nlog p); out=$(cl a sh /bench/benchy phoromatic.upload-result "$PS" lu3r --system-logs)
check "text mode --system-logs sends them" test "$(logfield p "$M" system_logs_zip)" = 1
# A stored "upload system logs" module option (a scheduled run saves the account's setting there) must not leak into an ad-hoc upload
cl a sh -c 'mkdir -p ~/.benchy/modules-data/phoromatic && echo "upload_system_logs = 1" > ~/.benchy/modules-data/phoromatic/module-settings.ini' >/dev/null
M=$(nlog p); j a stored phoromatic.upload-result "$PS" lu3r
check "a saved upload_system_logs=1 option does not send logs with an ad-hoc upload" test "$(logfield p "$M" system_logs_zip)" = 0
cl a sh -c 'cd ~/.benchy/test-results/lu3r && mkdir -p installation-logs test-logs && echo i > installation-logs/i.txt && echo t > test-logs/t.txt && printf "upload_system_logs = 1\nupload_install_logs = 1\nupload_run_logs = 1\n" > ~/.benchy/modules-data/phoromatic/module-settings.ini' >/dev/null
M=$(nlog p); j a stored2 phoromatic.upload-result "$PS" lu3r
check "saved install-log and run-log options send nothing with an ad-hoc upload" test "$(posts p "$M" result_log_upload)" = 0
M=$(nlog p); j a stored3 phoromatic.upload-result "$PS" lu3r --system-logs
check "with --system-logs the logs are asked for (a follow-up upload happens)" bash -c "[ \"$(posts p "$M" result_log_upload)\" -ge 1 ]"

echo "== 2. only the paired server"
cl b sh /bench/benchy user-config-set "PhoromaticServers=$D:8080" >/dev/null
MP=$(nlog p); MD=$(nlog d)
j b nopair phoromatic.upload-result lu3r
check "no pairing, a decoy in the client's list: not_found, exit 1" is nopair '[F("error")["code"], L("done")["exit_code"]]' '["not_found", 1]'
check "...the decoy got no request" bash -c "[ \"$(nlog d)\" = \"$MD\" ] || { echo 'the decoy was sent:'; $(declare -f newreqs); L3=$L3; newreqs d $MD; exit 1; }"
out=$(cl b sh /bench/benchy phoromatic.upload-result lu3r)
check "...text mode: tells the person to connect, sends nothing" bash -c 'grep -qa "not connected to a Phoromatic Server" <<<"$1"' _ "$out"
check "...the decoy still got no request" test "$(nlog d)" = "$MD"
cl b sh -c "mkdir -p ~/.benchy/modules-data/phoromatic && echo '$PS' > ~/.benchy/modules-data/phoromatic/last-phoromatic-server" >/dev/null
j b paired phoromatic.upload-result lu3r
check "paired through last-phoromatic-server: it goes to the paired server, status ok" is paired '[F("upload")["status"], F("upload")["server"]]' "[\"ok\", \"$P:8080\"]"
check "...one upload there, none at the decoy" bash -c "[ \"\$(tail -n +$((MP + 1)) $L3/p.log | grep -c '\"r\": \"result_upload\"')\" = 1 ] && [ \"$(nlog d)\" = \"$MD\" ]"
j b control phoromatic.upload-result "$DS" lu3r
check "control: the same decoy named on the command line does get the upload" test "$(posts d "$MD" result_upload)" = 1

echo "== 3. an unreachable paired server fails at once"
MD=$(nlog d)
cl c sh -c "mkdir -p ~/.benchy/modules-data/phoromatic && echo '127.0.0.1:9/LU3ACC' > ~/.benchy/modules-data/phoromatic/last-phoromatic-server" >/dev/null
cl c sh /bench/benchy user-config-set "PhoromaticServers=$D:8080" >/dev/null
j c down phoromatic.upload-result lu3r
check "paired server not answering: error network, exit 1" is down '[F("error")["code"], L("done")["exit_code"]]' '["network", 1]'
check "...within 45 s (the old loop waited about 11 minutes)" test "$(cat "$OUT/down.secs")" -lt 45
check "...and nothing went to the decoy" test "$(nlog d)" = "$MD"
T0=$SECONDS; out=$(cl c sh /bench/benchy phoromatic.upload-result lu3r)
check "text mode: says the server did not answer, quickly" bash -c 'grep -qa "did not answer" <<<"$1" && [ "$2" -lt 45 ]' _ "$out" "$((SECONDS - T0))"
check "...the decoy still got nothing" test "$(nlog d)" = "$MD"

echo "== 6. the real server: approval, then one upload id for the same result"
j d first phoromatic.upload-result "$SERVER" lu3r
dbx "UPDATE phoromatic_systems SET State = 1 WHERE Title = 'lu3d'"
CNT0=$(dbq "SELECT COUNT(*) FROM phoromatic_results")
j d up1 phoromatic.upload-result "$SERVER" lu3r
ID1=$(q up1 'F("upload")["upload_id"]')
check "approved system: ok with an upload id, one row filed" bash -c "[ \"$ID1\" -gt 0 ] && [ \"$(dbq "SELECT COUNT(*) FROM phoromatic_results")\" = $((CNT0 + 1)) ]"
dbx "UPDATE phoromatic_systems SET State = 0 WHERE Title = 'lu3d'"
j d pend phoromatic.upload-result "$SERVER" lu3r
check "a system waiting for approval: failed, not_approved, exit 1" is pend '[F("upload")["status"], F("upload")["reason"], L("done")["exit_code"]]' '["failed", "not_approved", 1]'
dbx "UPDATE phoromatic_systems SET State = 1 WHERE Title = 'lu3d'"
j d up2 phoromatic.upload-result "$SERVER" lu3r
check "uploading the result again later is a new upload (the client stamps LastModified into every copy, so its bytes differ)" bash -c '[ -n "$1" ] && [ "$1" != "$2" ]' _ "$(q up2 'F("upload")["upload_id"]')" "$ID1"
# The case the de-duplication is for: the client's retries after a lost reply send the same bytes. Replayed here with one body.
cl a sh -c 'cp ~/.benchy/test-results/lu3r/composite.xml /share/lu3/composite.xml' >/dev/null
replay() {  # replay <system title>: POST the same composite as the client does, print the upload id
	python3 -I - "$QC_PORT" "$QC_AID" "$(dbq "SELECT MachineSelfID FROM phoromatic_systems WHERE Title = '$1' AND DeletedOn IS NULL")" "$L3/composite.xml" <<'PY'
import base64, hashlib, json, sys, urllib.parse, urllib.request
port, aid, msi, path = sys.argv[1:5]
xml = open(path, 'rb').read()
body = urllib.parse.urlencode({'r': 'result_upload', 'aid': aid, 'msi': msi, 'pts_core': '99999', 'sched': '0', 'composite_xml': base64.b64encode(xml).decode(), 'composite_xml_hash': hashlib.sha1(xml).hexdigest()}).encode()
print(json.load(urllib.request.urlopen('http://127.0.0.1:%s/phoromatic.php' % port, body, 60))['phoromatic'].get('upload_id'))
PY
}
R1=$(replay lu3d); CNT2=$(dbq "SELECT COUNT(*) FROM phoromatic_results")
R2=$(replay lu3d); R3=$(replay lu3d)
check "the same bytes sent again (a retry after a lost reply): the first upload's id, no second row" bash -c '[ -n "$1" ] && [ "$1" = "$2" ] && [ "$1" = "$3" ] && [ "$4" = "$5" ]' _ "$R1" "$R2" "$R3" "$CNT2" "$(dbq "SELECT COUNT(*) FROM phoromatic_results")"
j e firste phoromatic.upload-result "$SERVER" lu3r
dbx "UPDATE phoromatic_systems SET State = 1 WHERE Title = 'lu3e'"
RE=$(replay lu3e)
check "another system sending those same bytes gets its own upload" bash -c '[ -n "$1" ] && [ "$1" != "$2" ]' _ "$RE" "$R1"

echo
echo "lu3: $PASS passed, $FAIL failed"
[ "$FAIL" = 0 ] || { printf '  failed: %s\n' "${FAILED[@]}"; exit 1; }
