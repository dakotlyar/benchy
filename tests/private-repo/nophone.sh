#!/bin/bash
# No-phone-home (0.7.4). Driven by run.sh (QC_* environment, a scratch Phoromatic server and an account).
# The proof is network-level, not a code read: clients run on a docker network with no route out, openbenchmarking.org
# resolves to a logging sink (np_sink.py, HTTP and HTTPS) that answers "PTS" to the reachability probe, so the clients believe
# they are online, and hands out a GSID. Every request a client sends to openbenchmarking.org is logged there. A window passes
# when the sink saw nothing but the reachability probe (GET /PTS), and repository index requests and the Phoromatic relay
# discovery request (phoromatic_server_relay_check) carrying no GSID: the first two are out of scope for 0.7.4 (independence
# work, I1), the relay check is allowed by decision. Control runs (v0.7.2 itself, and NoPhoneHome FALSE where the server allows it)
# show the same sink does catch usage reports, GSID requests, auto-compare and result uploads when they are sent.
set -u
: "${QC_SRV:?run via run.sh}"
HERE=$(cd "$(dirname "$0")" && pwd)
REPO=$(cd "$HERE/../.." && pwd)
NP_OLD_REF=${NP_OLD_REF:-v0.7.2}   # the last release before no-phone-home, for the upgrade scenario
PASS=0; FAIL=0; FAILED=()

check() {  # check "description" command...
	local d=$1; shift
	if "$@" >/dev/null 2>&1; then PASS=$((PASS+1)); echo "  PASS  $d"; else FAIL=$((FAIL+1)); FAILED+=("$d"); echo "  FAIL  $d"; fi
}
contains() { grep -qaF -- "$1" <<<"$2"; }
not_contains() { ! grep -qaF -- "$1" <<<"$2"; }
count_of() { grep -caF -- "$1" <<<"$2"; }
PHPERR='^(PHP )?(Fatal error|Warning|Notice|Deprecated): |Uncaught|Stack trace'
no_crash() { ! grep -aEq "$PHPERR" <<<"$1"; }
clean() { grep -av '^MISSING\|^$\|kill: No such process\|^Benchy v'; }
dbq() { docker exec "$QC_SRV" php -r '$d=new SQLite3("/var/lib/benchy/phoromatic/phoromatic.db"); echo $d->querySingle($argv[1]);' "$1"; }
dbx() { docker exec "$QC_SRV" php -r '$d=new SQLite3("/var/lib/benchy/phoromatic/phoromatic.db"); $d->exec($argv[1]);' "$1"; }
approve_all() { dbx "UPDATE phoromatic_systems SET State=1"; }
policy() { docker exec "$QC_SRV" sh /bench/benchy phoromatic-client-phone-home "$@" 2>&1; }
SERVER="$QC_SRV:8080/$QC_AID"

NP=$QC_SHARE/np; mkdir -p "$NP/fx"
SINKLOG=$NP/sink.log; : > "$SINKLOG"; chmod 666 "$SINKLOG"
NP_NET=${QC_NET}-np
SINK=${QC_CLIENT_PREFIX}-npsink
OLD_TREE=$(mktemp -d /tmp/benchy-qc-old.XXXXXX)
np_cleanup() {
	docker rm -f "$SINK" >/dev/null 2>&1
	docker ps -aq --filter "name=${QC_CLIENT_PREFIX}-np" | xargs -r docker rm -f >/dev/null 2>&1
	docker network disconnect -f "$NP_NET" "$QC_SRV" >/dev/null 2>&1
	docker network rm "$NP_NET" >/dev/null 2>&1
	rm -rf "$OLD_TREE"
}
trap np_cleanup EXIT

# ---------- network: internal (no route out), the server and the sink on it
docker network create --internal "$NP_NET" >/dev/null || { echo "np network failed"; exit 2; }
docker network connect "$NP_NET" "$QC_SRV" || exit 2
openssl req -x509 -newkey rsa:2048 -nodes -subj /CN=openbenchmarking.org -days 2 -keyout "$NP/key.pem" -out "$NP/cert.pem" >/dev/null 2>&1 || exit 2
chmod 644 "$NP/key.pem"
docker run -d --name "$SINK" --network "$NP_NET" -v "$HERE/np_sink.py:/np_sink.py:ro" -v "$QC_SHARE:/share" "$QC_IMAGE" \
	python3 /np_sink.py /share/np/sink.log /share/np/cert.pem /share/np/key.pem >/dev/null || exit 2
SINK_IP=$(docker inspect -f "{{(index .NetworkSettings.Networks \"$NP_NET\").IPAddress}}" "$SINK")
[ -n "$SINK_IP" ] || { echo "no sink ip"; exit 2; }
(cd "$REPO" && git archive "$NP_OLD_REF" | tar -x -C "$OLD_TREE") || { echo "cannot export $NP_OLD_REF"; exit 2; }

vol() { echo "${QC_CLIENT_PREFIX}-np$1"; }
npdocker() {  # npdocker <client> <tree> <command...>: one throw-away machine (HOME volume, hostname np<client>) on the sealed network
	local c=$1 tree=$2; shift 2
	docker volume create "$(vol "$c")" >/dev/null
	docker run --rm -i --network "$NP_NET" --hostname "np$c" --add-host "openbenchmarking.org:$SINK_IP" --add-host "www.openbenchmarking.org:$SINK_IP" \
		-u 1000 -e HOME=/home/bob -e TERM=dumb -e "BENCHY_PRIVATE_REPO_TOKEN=$QC_REPO_TOKEN" ${NP_ENV:+-e "$NP_ENV"} \
		-v "$(vol "$c"):/home/bob" -v "$tree:/bench:ro" -v "$QC_SHARE:/share" "$QC_IMAGE" "$@"
}
nb() { local c=$1; shift; npdocker "$c" "$QC_TREE" sh /bench/benchy "$@" 2>&1 </dev/null | clean; }      # this branch
nbold() { local c=$1; shift; npdocker "$c" "$OLD_TREE" sh /bench/benchy "$@" 2>&1 </dev/null | clean; } # NP_OLD_REF
nbin() { local c=$1 in=$2; shift 2; printf '%s\n' "$in" | npdocker "$c" "$QC_TREE" sh /bench/benchy "$@" 2>&1 | clean; }
nsh() { local c=$1; shift; npdocker "$c" "$QC_TREE" sh -c "$*" 2>&1; }
checkin() {  # checkin <client>: phoromatic.connect until the client's cached policy matches the server's (at most 120 s)
	local c=$1 want i name="${QC_CLIENT_PREFIX}-npci-$1"
	policy | grep -q 'policy: forbidden' && want=present || want=absent
	docker volume create "$(vol "$c")" >/dev/null
	docker rm -f "$name" >/dev/null 2>&1
	docker run -d --name "$name" --network "$NP_NET" --hostname "np$c" --add-host "openbenchmarking.org:$SINK_IP" --add-host "www.openbenchmarking.org:$SINK_IP" \
		-u 1000 -e HOME=/home/bob -e TERM=dumb -v "$(vol "$c"):/home/bob" -v "$QC_TREE:/bench:ro" "$QC_IMAGE" sh /bench/benchy phoromatic.connect "$SERVER" >/dev/null
	sleep 5
	for i in $(seq 1 115); do
		if [ $want = present ]; then [ -n "$(cached_policy "$c")" ] && break; else [ -z "$(cached_policy "$c")" ] && break; fi
		sleep 1
	done
	sleep 2
	docker logs "$name" > "$NP/checkin.$c.log" 2>&1
	if [ $i = 115 ]; then
		local msi; msi=$(dbq "SELECT MachineSelfID FROM phoromatic_systems WHERE Title='np$c' AND DeletedOn IS NULL")
		echo "    checkin $c: the cached policy never became $want; a start reply for it reads:"
		curl -s "http://127.0.0.1:$QC_PORT/phoromatic.php" -d "aid=$QC_AID" -d "msi=$msi" -d pts_core=99999 -d r=start | head -c 800 | sed 's/^/      | /'; echo
	fi
	docker rm -f "$name" >/dev/null 2>&1
}
cached_policy() { nsh "$1" 'cat ~/.benchy/modules-data/phoromatic/server-policy.json 2>/dev/null'; }
gsid_stored() { ${2:-nb} "$1" dump-core-storage | grep -a 'global_system_id:' | head -1 | sed 's/.*: *//'; }   # gsid_stored <client> [runner]

# ---------- sink log helpers
mark() { wc -l < "$SINKLOG"; }
since() { tail -n +$(( $1 + 1 )) "$SINKLOG"; }
summary() {  # one line per request: METHOD PATH r=... gsid=... test=...
	python3 -c '
import json, sys
for line in sys.stdin:
    e = json.loads(line); f = e["form"]
    print(e["method"], e["path"], "r=" + f.get("r", ""), "gsid=" + f.get("gsid", ""), "test=" + f.get("test_identifier", ""))'
}
violations() {  # anything but the reachability probe and GSID-less repository index and Phoromatic relay-discovery requests
	python3 -c '
import json, sys
for line in sys.stdin:
    e = json.loads(line); f = e["form"]
    if e["method"] == "GET" and e["path"] == "/PTS":
        continue
    if e["method"] == "POST" and e["path"] == "/f/client.php" and f.get("r") in ("repo_index", "phoromatic_server_relay_check") and not f.get("gsid") and not f.get("gsid_e"):
        continue
    print(e["method"], e["path"], "r=" + f.get("r", ""), "gsid=" + f.get("gsid", ""))'
}
show_window() { echo "    sink saw since mark: $(since "$1" | summary | sort | uniq -c | sed 's/^ */ /' | tr '\n' ';')"; }
quiet_window() {  # quiet_window <mark> <label>: no phone-home, and the client did think it was online
	local v; v=$(since "$1" | violations)
	check "$2: nothing but the probe and GSID-less index / relay-check requests reached openbenchmarking.org" test -z "$v"
	[ -n "$v" ] && echo "$v" | sed 's/^/      VIOLATION /'
	check "$2: the client was online as far as it knew (it probed openbenchmarking.org)" bash -c "tail -n +$(( $1 + 1 )) '$SINKLOG' | grep -q '\"path\": \"/PTS\"'"
}
saw() { since "$1" | summary | grep -qaE -- "$2"; }   # saw <mark> <regex over the summary lines>

# ---------- fixtures: a 2 s install and an 11 s run (long enough for the install and run-time reports), a failing install,
# a failing download
mk_def() {  # mk_def <dir> <title>
	mkdir -p "$1"
	cat > "$1/test-definition.xml" <<XML
<?xml version="1.0"?>
<PhoronixTestSuite>
  <TestInformation><Title>$2</Title><AppVersion>1.0</AppVersion><Description>No-phone-home fixture.</Description>
    <ResultScale>Score</ResultScale><Proportion>HIB</Proportion><TimesToRun>1</TimesToRun></TestInformation>
  <TestProfile><Version>1.0.0</Version><SupportedPlatforms>Linux</SupportedPlatforms><SoftwareType>Benchmark</SoftwareType>
    <TestType>System</TestType><License>Free</License><Status>Verified</Status><SupportedArchitectures>x86_64, aarch64</SupportedArchitectures>
    <EnvironmentSize>1</EnvironmentSize><ProjectURL>https://example.invalid/</ProjectURL><Maintainer>QC</Maintainer></TestProfile>
  <TestSettings><Default><Arguments></Arguments></Default></TestSettings>
</PhoronixTestSuite>
XML
	cat > "$1/results-definition.xml" <<'XML'
<?xml version="1.0"?>
<PhoronixTestSuite>
  <ResultsParser><OutputTemplate>Score: #_RESULT_#</OutputTemplate><ResultScale>Score</ResultScale></ResultsParser>
</PhoronixTestSuite>
XML
}
mk_def "$NP/fx/np-bench" "NP Bench"
cat > "$NP/fx/np-bench/install.sh" <<'SH'
#!/bin/sh
sleep 2
cat > np-bench <<'EOF'
#!/bin/sh
sleep 11
echo "Score: 7" > $LOG_FILE
EOF
chmod +x np-bench
SH
mk_def "$NP/fx/np-fail" "NP Fail"
printf '#!/bin/sh\necho "np-fail: deliberate install error"\necho 1 > ~/install-exit-status\n' > "$NP/fx/np-fail/install.sh"
mk_def "$NP/fx/np-dl" "NP Download"
printf '#!/bin/sh\necho "#!/bin/sh" > np-dl\nchmod +x np-dl\n' > "$NP/fx/np-dl/install.sh"
cat > "$NP/fx/np-dl/downloads.xml" <<'XML'
<?xml version="1.0"?>
<PhoronixTestSuite>
  <Downloads>
    <Package><URL>http://downloads.np.invalid/np-dl-1.0.tar.gz</URL><MD5>0123456789abcdef0123456789abcdef</MD5><FileName>np-dl-1.0.tar.gz</FileName><FileSize>1234</FileSize></Package>
  </Downloads>
</PhoronixTestSuite>
XML
chmod -R a+rX "$NP/fx"
stage() { nsh "$1" 'mkdir -p ~/.benchy/test-profiles/local && for t in np-bench np-fail np-dl; do rm -rf ~/.benchy/test-profiles/local/$t; cp -r /share/np/fx/$t ~/.benchy/test-profiles/local/; done' >/dev/null; }
BATCH="Configured=TRUE PromptForTestIdentifier=FALSE PromptForTestDescription=FALSE PromptSaveName=FALSE RunAllTestCombinations=FALSE SaveResults=TRUE UploadResults=TRUE OpenBrowser=FALSE"
workload() {  # workload <client> [runner]: what a user does; prints the combined output
	local c=$1 run=${2:-nb}
	$run "$c" install local/np-bench
	$run "$c" install local/np-fail
	$run "$c" install local/np-dl
	$run "$c" batch-benchmark local/np-bench
}

echo "== np 0. the server's policy defaults to forbidden and rides on every reply"
out=$(policy)
check "phoromatic-client-phone-home shows forbidden on a fresh server" contains "Client phone-home policy: forbidden" "$out"
check "...and runs cleanly" no_crash "$out"
reply=$(curl -s "http://127.0.0.1:$QC_PORT/phoromatic.php" -d aid=NOSUCHACCT -d msi=np-probe -d pts_core=99999 -d r=ping)
check "an error reply (Invalid User) carries policy.no_phone_home=true" bash -c 'python3 -c "import json,sys; j=json.loads(sys.argv[1]); assert j[\"phoromatic\"][\"policy\"]=={\"no_phone_home\": True} and \"error\" in j[\"phoromatic\"]" "$1"' _ "$reply"
out=$(policy bogus)
check "an unknown policy value is refused with usage" contains "Usage: phoromatic-client-phone-home" "$out"
check "...and the policy is unchanged" contains "policy: forbidden" "$(policy)"

echo "== np 1. fresh client, default configuration (+ AlwaysUploadResultsToOpenBenchmarking and batch UploadResults on)"
M=$(mark)
nb a1 user-config-set $BATCH AlwaysUploadResultsToOpenBenchmarking=TRUE >/dev/null
stage a1
out=$(workload a1)
check "the run happened (a result was saved)" test -n "$(nsh a1 'ls ~/.benchy/test-results/ 2>/dev/null')"
check "the failing install did fail" contains "The installer exited with a non-zero exit status" "$out"
check "the failing download did fail" contains "np-dl-1.0.tar.gz" "$out"
check "no PHP warnings" no_crash "$out"
check "a fresh client is not shown the upgrade notice" not_contains "no longer reports to openbenchmarking.org" "$out"
quiet_window "$M" "fresh client"
show_window "$M"
check "no GSID is stored" test -z "$(gsid_stored a1)"
check "user-config.xml gains NoPhoneHome TRUE" nsh a1 "grep -q '<NoPhoneHome>TRUE</NoPhoneHome>' ~/.benchy/user-config.xml"
check "ob_auto_compare is still listed in AutoLoadModules (dropped at load, not rewritten)" nsh a1 "grep -q 'ob_auto_compare' ~/.benchy/user-config.xml"
out=$(NP_ENV=PTS_MODULES=ob_auto_compare nb a1 list-installed-tests)
check "asking for ob_auto_compare via PTS_MODULES is refused with one line" test "$(count_of 'ob_auto_compare is not loaded' "$out")" = 1
out=$(nb a1 test-module ob_auto_compare)
check "test-module ob_auto_compare is refused too" contains "ob_auto_compare is not loaded" "$out"
out=$(NP_ENV=PTS_MODULES=OB_Auto_Compare nb a1 list-installed-tests)
check "a mixed-case PTS_MODULES=OB_Auto_Compare is refused too (Windows would load it)" test "$(count_of 'ob_auto_compare is not loaded' "$out")" = 1
nb a1 user-config-set AutoLoadModules="toggle_screensaver, OB_Auto_Compare" >/dev/null
out=$(nb a1 list-installed-tests)
check "a mixed-case OB_Auto_Compare in AutoLoadModules is dropped silently at load" bash -c '! grep -aq "is not loaded" <<<"$1" && ! grep -aEq "$2" <<<"$1"' _ "$out" "$PHPERR"

echo "== np 2. upgrade from $NP_OLD_REF: AnonymousUsageReporting TRUE and ob_auto_compare saved, a GSID obtained"
M=$(mark)
nbold b1 user-config-set $BATCH >/dev/null
stage b1
nbold b1 install local/np-bench >/dev/null
check "control: $NP_OLD_REF itself requests a GSID from the sink" saw "$M" 'r=request_gsid'
check "control: $NP_OLD_REF reports the install (usage reporting)" saw "$M" '/extern/statistics/report-test-install.php'
check "the old client stored the sink's GSID" test "$(gsid_stored b1 nbold)" = NPSINK123
check "the old config has AnonymousUsageReporting TRUE and ob_auto_compare" nsh b1 "grep -q '<AnonymousUsageReporting>TRUE' ~/.benchy/user-config.xml && grep -q ob_auto_compare ~/.benchy/user-config.xml && ! grep -q NoPhoneHome ~/.benchy/user-config.xml"
M=$(mark)
out1=$(nb b1 list-installed-tests)
out2=$(nb b1 list-installed-tests)
check "first run after the upgrade prints the notice once" test "$(count_of 'Benchy no longer reports to openbenchmarking.org (Options/OpenBenchmarking/NoPhoneHome).' "$out1")" = 1
check "...and never again" not_contains "no longer reports" "$out2"
check "the saved AnonymousUsageReporting TRUE is left as it is" nsh b1 "grep -q '<AnonymousUsageReporting>TRUE' ~/.benchy/user-config.xml"
nsh b1 'rm -rf ~/.benchy/installed-tests/local/np-bench' >/dev/null
out=$(workload b1)
check "the upgraded client ran the workload (result saved)" test -n "$(nsh b1 'ls ~/.benchy/test-results/ 2>/dev/null')"
check "no PHP warnings" no_crash "$out"
quiet_window "$M" "upgraded client"
show_window "$M"
check "the stored GSID is kept (unused)" test "$(gsid_stored b1)" = NPSINK123
checkin b1
check "the upgraded client's check-in sent no GSID to Phoromatic (phoromatic_systems.GSID empty)" test -z "$(dbq "SELECT GSID FROM phoromatic_systems WHERE Title='npb1'")"
check "...the system row exists" test -n "$(dbq "SELECT SystemID FROM phoromatic_systems WHERE Title='npb1'")"
quiet_window "$M" "upgraded client incl. check-in"

echo "== np 3. server forbidden overrides a local NoPhoneHome FALSE (local runs included)"
nb c1 user-config-set $BATCH NoPhoneHome=FALSE AnonymousUsageReporting=TRUE AlwaysUploadResultsToOpenBenchmarking=TRUE >/dev/null
stage c1
checkin c1
check "the check-in cached the server's policy" contains '"no_phone_home":true' "$(cached_policy c1)"
check "...naming the server" contains "\"server\":\"$QC_SRV:8080\"" "$(cached_policy c1)"
M=$(mark)
out=$(workload c1)
check "the run happened" test -n "$(nsh c1 'ls ~/.benchy/test-results/ 2>/dev/null')"
check "the client says the server keeps NoPhoneHome on" contains "your Phoromatic Server forbids phoning home" "$out"
check "no PHP warnings" no_crash "$out"
out=$(NP_ENV=PTS_MODULES=ob_auto_compare nb c1 list-installed-tests)
check "ob_auto_compare is refused while enforced" contains "ob_auto_compare is not loaded" "$out"
quiet_window "$M" "local FALSE under a forbidding server"
show_window "$M"

echo "== np 4. server set to client_choice: a local FALSE phones home again, as before 0.7.4 (control)"
out=$(policy client_choice)
check "the policy is now client_choice" contains "policy: client_choice" "$out"
reply=$(curl -s "http://127.0.0.1:$QC_PORT/phoromatic.php" -d aid=NOSUCHACCT -d msi=np-probe -d pts_core=99999 -d r=ping)
check "replies now carry policy.no_phone_home=false" bash -c 'python3 -c "import json,sys; assert json.loads(sys.argv[1])[\"phoromatic\"][\"policy\"]=={\"no_phone_home\": False}" "$1"' _ "$reply"
checkin c1
check "the next check-in removed the cached policy" test -z "$(cached_policy c1)"
M=$(mark)
nb c1 force-install local/np-bench >/dev/null
out=$(workload c1)
check "control: install reported" saw "$M" '/extern/statistics/report-test-install.php'
check "control: install failure reported" saw "$M" '/extern/statistics/report-test-install-failure.php'
check "control: download failure reported" saw "$M" '/extern/statistics/report-download-failure.php'
check "control: the result was uploaded automatically" saw "$M" 'r=upload_test_result'
check "control: requests carry the GSID" saw "$M" 'gsid=NPSINK123'
# (Run-time reports and ob_auto_compare's comparison requests did not fire for this local fixture even in the control, so they
# have no positive control here; the quiet windows still fail on any request to openbenchmarking.org outside the allowlist.)
show_window "$M"

echo "== np 5. client_choice and the default (NoPhoneHome on): still quiet"
M=$(mark)
nb d1 user-config-set $BATCH AlwaysUploadResultsToOpenBenchmarking=TRUE >/dev/null
checkin d1
stage d1
out=$(workload d1)
check "no cached policy under client_choice" test -z "$(cached_policy d1)"
quiet_window "$M" "default client under client_choice"

echo "== np 6. client_choice, NoPhoneHome FALSE and AnonymousUsageReporting FALSE: only what 0.7.2 sent"
nb d2 user-config-set $BATCH NoPhoneHome=FALSE AnonymousUsageReporting=FALSE UploadResults=FALSE >/dev/null
stage d2
M=$(mark)
out=$(workload d2)
check "a GSID is requested (NoPhoneHome FALSE)" saw "$M" 'r=request_gsid'
check "no install / install-failure / run-time usage reports (AnonymousUsageReporting FALSE)" bash -c "! tail -n +$(( M + 1 )) '$SINKLOG' | grep -qE 'report-test-(install|install-failure|completion)\.php'"
check "no automatic result upload (UploadResults FALSE)" bash -c "! tail -n +$(( M + 1 )) '$SINKLOG' | grep -q '\"r\": \"upload_test_result\"'"
since "$M" | grep -q 'report-download-failure' && echo "    note: download_failure was reported despite AnonymousUsageReporting FALSE (the 0.7.2 leak; guarded on rehash-073 / 0.7.3)"
show_window "$M"

echo "== np 7. the upgraded client set to FALSE under client_choice uses its stored GSID again"
nb b1 user-config-set NoPhoneHome=FALSE >/dev/null
checkin b1
M=$(mark)
nb b1 batch-benchmark local/np-bench >/dev/null
check "requests carry the stored GSID NPSINK123" saw "$M" 'gsid=NPSINK123'
check "no new GSID was requested" bash -c "! tail -n +$(( M + 1 )) '$SINKLOG' | grep -q '\"r\": \"request_gsid\"'"

echo "== np 8. pointing a client at another server drops the cached policy"
policy forbidden >/dev/null
checkin c1
check "c1 has the forbidding policy cached again" contains '"no_phone_home":true' "$(cached_policy c1)"
nb c1 phoromatic.upload-result 127.0.0.1:9/NOACCOUNT >/dev/null
check "re-pointed at another server: the cache is gone" test -z "$(cached_policy c1)"
checkin c1
check "back on its own server it is cached again" contains '"no_phone_home":true' "$(cached_policy c1)"
nsh c1 'echo "not json" > ~/.benchy/modules-data/phoromatic/server-policy.json' >/dev/null
nb c1 phoromatic.upload-result 127.0.0.1:9/NOACCOUNT >/dev/null
check "an unreadable cache is kept when re-pointed" test "$(cached_policy c1)" = "not json"
check "...and keeps forbidding (local FALSE stays overridden)" contains "your Phoromatic Server forbids phoning home" "$(nb c1 list-installed-tests)"
checkin c1
check "the next check-in replaces it with the server's policy" contains '"no_phone_home":true' "$(cached_policy c1)"
nsh c1 'rm -f ~/.benchy/modules-data/phoromatic/server-policy.json; mkdir ~/.benchy/modules-data/phoromatic/server-policy.json' >/dev/null
out=$(npdocker c1 "$QC_TREE" timeout 30 sh /bench/benchy phoromatic.connect "$SERVER" </dev/null 2>&1)
check "a policy that cannot be cached is warned about" contains "its policy could not be saved" "$out"
check "...without PHP warnings" no_crash "$out"
nsh c1 'rmdir ~/.benchy/modules-data/phoromatic/server-policy.json' >/dev/null
checkin c1
check "once writable again it is cached at the next check-in" contains '"no_phone_home":true' "$(cached_policy c1)"

echo "== np 9. Phoromatic account setting UploadResultsToOpenBenchmarking"
dbx "UPDATE phoromatic_account_settings SET UploadResultsToOpenBenchmarking=1 WHERE AccountID='$QC_AID'"
check "hidden on the Settings page while forbidden" test "$(python3 "$HERE/nophone_web.py" show)" = HIDDEN
python3 "$HERE/nophone_web.py" save >/dev/null
check "saving the page while hidden leaves the stored value alone" test "$(dbq "SELECT UploadResultsToOpenBenchmarking FROM phoromatic_account_settings WHERE AccountID='$QC_AID'")" = 1
policy client_choice >/dev/null
check "shown again under client_choice" test "$(python3 "$HERE/nophone_web.py" show)" = SHOWN
policy forbidden >/dev/null

# Scheduled runs from the server, with the account setting on and the client's own NoPhoneHome FALSE
nb p1 user-config-set NoPhoneHome=FALSE >/dev/null
stage p1
nb p1 private-repo-setup "$SERVER" >/dev/null; approve_all
nbin p1 "np" upload-private-test-profile local/np-bench >/dev/null
checkin p1
sched_run() {  # sched_run <label>: a run-now schedule of private/np-bench through the web UI, then the client connects and runs it
	local before; before=$(dbq "SELECT COUNT(*) FROM phoromatic_results WHERE SystemID IN (SELECT SystemID FROM phoromatic_systems WHERE Title='npp1')")
	python3 "$HERE/schedule_via_ui.py" private/np-bench > "$NP/sched.$1" 2>&1
	docker rm -f "${QC_CLIENT_PREFIX}-npp1run" >/dev/null 2>&1
	(timeout 320 docker run --rm --name "${QC_CLIENT_PREFIX}-npp1run" --network "$NP_NET" --hostname npp1 --add-host "openbenchmarking.org:$SINK_IP" --add-host "www.openbenchmarking.org:$SINK_IP" \
		-u 1000 -e HOME=/home/bob -v "$(vol p1):/home/bob" -v "$QC_TREE:/bench:ro" "$QC_IMAGE" sh /bench/benchy phoromatic.connect "$SERVER" </dev/null >"$NP/p1.$1" 2>&1 &)
	for i in $(seq 1 150); do
		[ "$(dbq "SELECT COUNT(*) FROM phoromatic_results WHERE SystemID IN (SELECT SystemID FROM phoromatic_systems WHERE Title='npp1')")" -gt "$before" ] && break; sleep 2
	done
	sleep 8   # an OpenBenchmarking.org upload, if any, follows the Phoromatic one
	docker rm -f "${QC_CLIENT_PREFIX}-npp1run" >/dev/null 2>&1
	[ -n "${NP_DEBUG_DIR:-}" ] && cp "$NP/sched.$1" "$NP/p1.$1" "$NP/"checkin.*.log "$NP_DEBUG_DIR/" 2>/dev/null
	if [ "$(dbq "SELECT COUNT(*) FROM phoromatic_results WHERE SystemID IN (SELECT SystemID FROM phoromatic_systems WHERE Title='npp1')")" -le "$before" ]; then
		echo "    sched_run $1 failed; schedule helper said: $(tr '\n' ' ' < "$NP/sched.$1")"; tail -15 "$NP/p1.$1" | sed 's/^/      p1| /'
	fi
	test "$(dbq "SELECT COUNT(*) FROM phoromatic_results WHERE SystemID IN (SELECT SystemID FROM phoromatic_systems WHERE Title='npp1')")" -gt "$before"
}
M=$(mark)
check "scheduled run under forbidden: the result reached the Phoromatic server" sched_run forbidden
quiet_window "$M" "scheduled run, account upload setting on, local FALSE, forbidden"
show_window "$M"
policy client_choice >/dev/null
M=$(mark)
check "scheduled run under client_choice: the result reached the Phoromatic server" sched_run choice
check "control: with client_choice and local FALSE the account setting uploads to openbenchmarking.org" saw "$M" 'r=upload_test_result'
show_window "$M"
policy forbidden >/dev/null
dbx "UPDATE phoromatic_account_settings SET UploadResultsToOpenBenchmarking=0 WHERE AccountID='$QC_AID'"

echo "== np 11. a connect process that started before it knew the policy stops sending its GSID at the first forbidding reply"
# p1 (local FALSE) got a GSID under client_choice above and its last check-in there removed the cached policy, so a new connect
# process defines PTS_GSID at startup; the forbidding reply then has to stop it reaching openbenchmarking.org (the forced
# repository refresh and any acquire_* of the benchmark task run in that same process)
gsid_stops() {  # gsid_stops <mark>: once a request went out without the GSID (the policy was known), none carried it again
	since "$1" | python3 -c '
import json, sys
known = False
for line in sys.stdin:
    e = json.loads(line); f = e["form"]
    if e["method"] != "POST":
        continue
    has = bool(f.get("gsid") or f.get("gsid_e"))
    if known and has:
        sys.exit(1)
    known = known or not has
sys.exit(0)'; }
check "precondition: p1 has a stored GSID" test "$(gsid_stored p1)" = NPSINK123
check "precondition: p1 has no cached policy" test -z "$(cached_policy p1)"
# The process's start-up requests (relay check, index refresh) go out with the GSID before its first check-in, the documented
# gap; the benchmark task arrives in a check-in reply, so its forced index refresh comes after the policy is known
M=$(mark)
check "scheduled run in a connect process started without the policy: the result reached the Phoromatic server" sched_run fresh
check "...the process cached the forbidding policy" contains '"no_phone_home":true' "$(cached_policy p1)"
check "...the task's forced index refresh reached openbenchmarking.org without the GSID" saw "$M" 'r=repo_index gsid= '
check "...and once a request went without the GSID, none carried it again" gsid_stops "$M"
show_window "$M"

echo "== np 10. prompts with standard input closed end instead of looping (a looping prompt once ate ~20 GB of captured output)"
# Every run here is bounded: timeout inside the container and the captured output capped, so a regression fails the check
# instead of filling memory. NP_EXIT is the command's exit status; 124 means timeout killed it, i.e. it was still prompting.
nbeof() {  # nbeof <client> [VAR=value...] <benchy args...>
	local c=$1; shift
	npdocker "$c" "$QC_TREE" sh -c 'timeout 60 env "$@" </dev/null 2>&1; echo "NP_EXIT=$?"' _ "$@" </dev/null 2>&1 | head -c 200000 | clean
}
exit_of() { grep -ao 'NP_EXIT=[0-9]*' <<<"$1" | tail -1 | cut -d= -f2; }
no_system_logs_sent() { tail -n +$(( $1 + 1 )) "$SINKLOG" | python3 -c '
import json, sys
sys.exit(1 if any(json.loads(l)["form"].get("system_logs_zip") for l in sys.stdin) else 0)'; }
R=$(nsh a1 'ls ~/.benchy/test-results/ 2>/dev/null' | head -1)
nsh a1 "mkdir -p ~/.benchy/test-results/$R/system-logs && echo np > ~/.benchy/test-results/$R/system-logs/np.txt" >/dev/null
M=$(mark)
out=$(nbeof a1 sh /bench/benchy upload-result "$R")
check "upload-result (system logs present) with stdin at EOF exits, not killed by timeout" bash -c '[ -n "$1" ] && [ "$1" != 124 ]' _ "$(exit_of "$out")"
check "...asks about the system logs once" test "$(count_of 'attach the system logs' "$out")" = 1
check "...prints the closed-input notice once" test "$(count_of 'standard input is closed' "$out")" = 1
check "...without PHP warnings" no_crash "$out"
check "...and sends no system logs" no_system_logs_sent "$M"
show_window "$M"
M=$(mark)
out=$(nbeof a1 TEST_RESULTS_NAME=npeof TEST_RESULTS_IDENTIFIER=npeof sh /bench/benchy benchmark local/np-bench)
check "interactive benchmark with stdin at EOF finishes with status 0" test "$(exit_of "$out")" = 0
check "...asks about uploading to openbenchmarking.org once" test "$(count_of 'upload the results to OpenBenchmarking.org' "$out")" = 1
check "...without PHP warnings" no_crash "$out"
check "...and the answer is no: nothing was uploaded" bash -c "! tail -n +$(( M + 1 )) '$SINKLOG' | grep -q '\"r\": \"upload_test_result\"'"
check "...the result was saved" nsh a1 'test -d ~/.benchy/test-results/npeof'
out=$(nbeof a1 sh /bench/benchy create-test-profile)
# (the benchy launcher script exits 0 whatever PHP's status, so only "not killed by timeout" can be asserted here)
check "create-test-profile with stdin at EOF exits, not killed by timeout" bash -c '[ -n "$1" ] && [ "$1" != 124 ]' _ "$(exit_of "$out")"
check "...saying an answer is required" test "$(count_of 'An answer is required but standard input is closed' "$out")" = 1
check "...without PHP warnings" no_crash "$out"

echo
echo "nophone: $PASS passed, $FAIL failed"
for f in "${FAILED[@]:-}"; do [ -n "$f" ] && echo "  FAILED: $f"; done
[ "$FAIL" -eq 0 ]
