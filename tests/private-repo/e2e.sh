#!/bin/bash
# CLI end-to-end scenarios for the private repository (test plan steps 1-7 and 9, plus overwrite/delete, disable switch,
# scheduled-run auto-install and the PassMark example). Driven by run.sh, which provides the QC_* environment, a scratch
# Phoromatic server and an account.
# Each "client" is a throw-away HOME (docker volume) running `sh /bench/benchy` as uid 1000, i.e. a distinct machine.
# Checks labelled KNOWN_BUG currently fail on purpose; they pin defects found during QC.
set -u
: "${QC_SRV:?run via run.sh}"
HERE=$(cd "$(dirname "$0")" && pwd)
PASS=0; FAIL=0; FAILED=()

check() {  # check "description" command...
	local d=$1; shift
	if "$@" >/dev/null 2>&1; then PASS=$((PASS+1)); echo "  PASS  $d"; else FAIL=$((FAIL+1)); FAILED+=("$d"); echo "  FAIL  $d"; fi
}
contains() { grep -qaF -- "$1" <<<"$2"; }
not_contains() { ! grep -qaF -- "$1" <<<"$2"; }
PHPERR='^(PHP )?(Fatal error|Warning|Notice|Deprecated): |Uncaught|Stack trace|<b>(Fatal error|Warning|Notice)</b>'
no_crash() { ! grep -aEq "$PHPERR" <<<"$1"; }
clean() { grep -av '^MISSING\|^$\|kill: No such process\|^Benchy v'; }

vol() { echo "${QC_CLIENT_PREFIX}-$1"; }
cdocker() {  # cdocker <client> <command...>: run inside the client's HOME volume as uid 1000
	local c=$1; shift
	docker volume create "$(vol "$c")" >/dev/null
	docker run --rm -i --network "${CNET:-$QC_NET}" -u 1000 -e HOME=/home/bob -e TERM=dumb -v "$(vol "$c"):/home/bob" -v "$QC_TREE:/bench:ro" -v "$QC_SHARE:/share" "$QC_IMAGE" "$@"
}
bp() { local c=$1; shift; cdocker "$c" sh /bench/benchy "$@" 2>&1 </dev/null | clean; }                      # benchy, no stdin
bpin() { local c=$1 in=$2; shift 2; printf '%s\n' "$in" | cdocker "$c" sh /bench/benchy "$@" 2>&1 | clean; } # benchy, one stdin line
csh() { local c=$1; shift; cdocker "$c" sh -c "$*" 2>&1; }
setcfg() { csh "$1" "sed -i 's#<PrivateRepository>.*</PrivateRepository>#<PrivateRepository>$2</PrivateRepository>#' ~/.benchy/user-config.xml" >/dev/null; }
dbq() { docker exec "$QC_SRV" php -r '$d=new SQLite3("/var/lib/benchy/phoromatic/phoromatic.db"); echo $d->querySingle($argv[1]);' "$1"; }
approve_all() { docker exec "$QC_SRV" php -r '$d=new SQLite3("/var/lib/benchy/phoromatic/phoromatic.db"); $d->exec("UPDATE phoromatic_systems SET State=1");'; }
srv() { docker exec "$QC_SRV" sh -c "$*"; }
REPO="/var/lib/benchy/phoromatic/accounts/$QC_AID/private-repo"
SERVER="$QC_SRV:8080/$QC_AID"

# ---------- fixtures
FX=$QC_SHARE/fx; mkdir -p "$FX/hello-bench" "$FX/hello-suite"
cat > "$FX/hello-bench/test-definition.xml" <<'XML'
<?xml version="1.0"?>
<PhoronixTestSuite>
  <TestInformation><Title>Hello Bench</Title><AppVersion>1.0</AppVersion><Description>Trivial shell test.</Description>
    <ResultScale>Score</ResultScale><Proportion>HIB</Proportion><TimesToRun>1</TimesToRun></TestInformation>
  <TestProfile><Version>1.0.0</Version><SupportedPlatforms>Linux</SupportedPlatforms><SoftwareType>Benchmark</SoftwareType>
    <TestType>System</TestType><License>Free</License><Status>Verified</Status><SupportedArchitectures>x86_64, aarch64</SupportedArchitectures>
    <EnvironmentSize>1</EnvironmentSize><ProjectURL>https://example.invalid/</ProjectURL><Maintainer>QC</Maintainer></TestProfile>
  <TestSettings><Default><Arguments></Arguments></Default></TestSettings>
</PhoronixTestSuite>
XML
cat > "$FX/hello-bench/results-definition.xml" <<'XML'
<?xml version="1.0"?>
<PhoronixTestSuite>
  <ResultsParser><OutputTemplate>Score: #_RESULT_#</OutputTemplate><ResultScale>Score</ResultScale></ResultsParser>
</PhoronixTestSuite>
XML
cat > "$FX/hello-suite/suite-definition.xml" <<'XML'
<?xml version="1.0"?>
<PhoronixTestSuite>
  <SuiteInformation><Title>Hello Suite</Title><Version>1.0.0</Version><TestType>System</TestType><Description>Hello suite.</Description><Maintainer>QC</Maintainer></SuiteInformation>
  <Execute><Test>private/hello-bench</Test></Execute>
</PhoronixTestSuite>
XML
make_profile() {  # make_profile <version> <score>
	sed -i "s#<Version>[0-9.]*</Version>#<Version>$1</Version>#" "$FX/hello-bench/test-definition.xml"
	printf '#!/bin/sh\necho "#!/bin/sh\necho '"'"'Score: %s'"'"' > \\$LOG_FILE" > hello-bench\nchmod +x hello-bench\n' "$2" > "$FX/hello-bench/install.sh"
}
stage_profile() { csh "$1" "rm -rf ~/.benchy/test-profiles/local/hello-bench; mkdir -p ~/.benchy/test-profiles/local; cp -r /share/fx/hello-bench ~/.benchy/test-profiles/local/hello-bench" >/dev/null; }
score_of() { bp "$1" debug-benchmark "$2" | grep -a '^Score:' | head -1; }
make_profile 1.0.0 41

echo "== 1. author machine (c1): a local profile builds and runs"
stage_profile c1
check "validate-test-profile accepts the fixture" contains "Test Profile XML Is Valid." "$(bp c1 validate-test-profile local/hello-bench)"
bp c1 install local/hello-bench >/dev/null
check "local/hello-bench runs locally and parses Score: 41" contains "Score: 41" "$(score_of c1 local/hello-bench)"

echo "== 2. setup + upload (approval gate first)"
out=$(bp c1 private-repo-setup "$SERVER")
check "setup against an unapproved machine reports waiting-for-approval" contains "Waiting For Approval" "$out"
out=$(bpin c1 "first" upload-private-test-profile local/hello-bench)
check "upload from an unapproved machine is refused" contains "ERROR:" "$out"
check "nothing was stored by the unapproved machine" bash -c "! docker exec $QC_SRV test -e $REPO/tests/hello-bench"
approve_all
out=$(bpin c1 "first" upload-private-test-profile local/hello-bench)
check "upload prints the private id" contains "Uploaded private/hello-bench-1.0.0" "$out"
check "zip present in the server store" srv "test -f $REPO/tests/hello-bench/1.0.0.zip"
check "meta json present" srv "test -f $REPO/tests/hello-bench/1.0.0.json"
check "index.json lists the test" srv "grep -q hello-bench $REPO/index.json"
out=$(bpin c1 "again" upload-private-test-profile local/hello-bench)
check "re-upload of the same version is rejected (immutability)" contains "already exists" "$out"
check "no scratch zip left behind on the client" test -z "$(csh c1 'ls ~/.benchy/openbenchmarking.org/*.zip 2>/dev/null')"

echo "== 3. CLI-level negatives"
csh c1 "cp -r ~/.benchy/test-profiles/local/hello-bench ~/.benchy/test-profiles/local/crlf-test; sed -i 's#<Title>Hello Bench#<Title>CRLF Test#' ~/.benchy/test-profiles/local/crlf-test/test-definition.xml; printf '#!/bin/sh\r\necho hi\r\n' > ~/.benchy/test-profiles/local/crlf-test/install.sh" >/dev/null
out=$(bpin c1 "crlf" upload-private-test-profile local/crlf-test)
check "CRLF install.sh is rejected with a clear message" contains "CRLF" "$out"
csh c1 "rm -rf ~/.benchy/test-profiles/local/crlf-test; cp -r ~/.benchy/test-profiles/local/hello-bench ~/.benchy/test-profiles/local/big-test; sed -i 's#<Title>Hello Bench#<Title>Big Test#' ~/.benchy/test-profiles/local/big-test/test-definition.xml; (echo '#!/bin/sh'; base64 -w 76 /dev/urandom | head -c 9000000 | sed 's/^/# /') > ~/.benchy/test-profiles/local/big-test/install.sh" >/dev/null
out=$(bpin c1 "big" upload-private-test-profile local/big-test)
check "over-5MB profile is refused" contains "larger than 5 MB" "$out"
csh c1 "rm -rf ~/.benchy/test-profiles/local/big-test" >/dev/null
out=$(bpin c1 "x" upload-private-test-profile local/does-not-exist)
check "uploading a nonexistent profile does not crash" no_crash "$out"

echo "== 4. second machine (c2): fresh ~/.benchy pulls and runs it"
bp c2 private-repo-setup "$SERVER" >/dev/null; approve_all
bp c2 private-repo-refresh >/dev/null
check "list-available-tests shows private/hello-bench" contains "private/hello-bench" "$(bp c2 list-available-tests)"
check "private-repo-list shows it" contains "1.0.0" "$(bp c2 private-repo-list)"
bp c2 install private/hello-bench >/dev/null
check "installed test directory exists on c2" csh c2 "test -d ~/.benchy/installed-tests/private/hello-bench-1.0.0"
check "c2 runs it: Score 41" contains "Score: 41" "$(score_of c2 private/hello-bench)"
check "c1 and c2 are distinct approved machines on the server" test "$(dbq "SELECT COUNT(DISTINCT MachineSelfID) FROM phoromatic_systems WHERE State=1")" -ge 2

echo "== 5. version bump: latest wins, old version still installable"
make_profile 1.0.1 42; stage_profile c1
bpin c1 "bump" upload-private-test-profile local/hello-bench >/dev/null
bp c2 private-repo-refresh >/dev/null
check "index lists 1.0.1 first" contains "1.0.1, 1.0.0" "$(bp c2 private-repo-list)"
bp c2 install private/hello-bench >/dev/null
check "unversioned install resolves to 1.0.1" csh c2 "test -d ~/.benchy/installed-tests/private/hello-bench-1.0.1"
check "1.0.1 scores 42" contains "Score: 42" "$(score_of c2 private/hello-bench-1.0.1)"
bp c3 private-repo-setup "$SERVER" >/dev/null; approve_all
bp c3 install private/hello-bench-1.0.0 >/dev/null
check "a third machine can still install the old 1.0.0 explicitly" contains "Score: 41" "$(score_of c3 private/hello-bench-1.0.0)"

echo "== 6. suite containing the private test"
csh c1 "mkdir -p ~/.benchy/test-suites/local; cp -r /share/fx/hello-suite ~/.benchy/test-suites/local/" >/dev/null
out=$(bpin c1 "suite" upload-private-test-suite local/hello-suite)
check "suite upload succeeds" contains "Uploaded private/hello-suite-1.0.0" "$out"
bp c3 private-repo-refresh >/dev/null
bp c3 install private/hello-suite >/dev/null
check "suite install pulled its private test" csh c3 "test -d ~/.benchy/installed-tests/private/hello-bench-1.0.1"
check "suite runs and produces a result" contains "Score: 42" "$(score_of c3 private/hello-suite)"

echo "== 7. offline behaviour (server unreachable)"
CNET=none  # clients with no network at all: the server name cannot resolve
out=$(bp c3 private-repo-refresh)
check "refresh while offline warns and keeps the cached index" contains "Using the cached index" "$out"
check "KNOWN_BUG: refresh that failed offline must not then claim the index is up to date" not_contains "index is up to date" "$out"
check "list-available-tests still shows the private tests offline" contains "private/hello-bench" "$(bp c3 list-available-tests)"
check "an already-installed private test still runs offline" contains "Score: 42" "$(score_of c3 private/hello-bench-1.0.1)"
out=$(bp c4 private-repo-setup "$SERVER")
check "a never-synced machine offline fails softly" no_crash "$out"
unset CNET

echo "== 8. overwrite and delete versus client caches"
make_profile 1.0.1 43; stage_profile c1
out=$(bpin c1 "ow" upload-private-test-profile local/hello-bench --overwrite)
check "--overwrite replaces 1.0.1" contains "Uploaded private/hello-bench-1.0.1" "$out"
check "replaced version is kept in the server trash" srv "ls $REPO/trash | grep -q 'test-hello-bench-1.0.1.zip'"
check "server-side extracted copy was refreshed" srv "grep -q 'Score: 43' /var/lib/benchy/test-profiles/private/hello-bench-1.0.1/install.sh"
bp c2 private-repo-refresh >/dev/null
bp c2 install private/hello-bench-1.0.1 >/dev/null
check "KNOWN_BUG: a client that had 1.0.1 installs the overwritten content, not its stale cached copy" csh c2 "grep -q 'Score: 43' ~/.benchy/test-profiles/private/hello-bench-1.0.1/install.sh"
bp c5 private-repo-setup "$SERVER" >/dev/null; approve_all
bp c5 install private/hello-bench-1.0.1 >/dev/null
check "a fresh machine does get the overwritten content" csh c5 "grep -q 'Score: 43' ~/.benchy/test-profiles/private/hello-bench-1.0.1/install.sh"
out=$(bp c1 private-repo-delete private/hello-bench-1.0.1)
check "private-repo-delete removes the version" contains "Removed private/hello-bench-1.0.1" "$out"
check "server extracted copy and zip cache for it are gone" bash -c "! docker exec $QC_SRV test -d /var/lib/benchy/test-profiles/private/hello-bench-1.0.1 && ! docker exec $QC_SRV test -f /var/cache/benchy/openbenchmarking.org/private/hello-bench-1.0.1.zip"
bp c6 private-repo-setup "$SERVER" >/dev/null; approve_all; bp c6 private-repo-refresh >/dev/null
check "a fresh machine no longer sees the deleted version" not_contains "1.0.1" "$(bp c6 private-repo-list | grep -a '^private/hello-bench ')"
bp c6 install private/hello-bench-1.0.1 >/dev/null
check "installing the deleted version installs nothing" bash -c "! docker run --rm -v $(vol c6):/h $QC_IMAGE test -d /h/.benchy/installed-tests/private/hello-bench-1.0.1"

echo "== 9. regression: stock commands with no private server configured (fresh client c7)"
for cmd in openbenchmarking-repositories dump-openbenchmarking-indexes list-available-tests openbenchmarking-changes list-available-suites; do
	bp c7 $cmd > "$QC_SHARE/out.$cmd"   # via a file: some of these outputs exceed the argv size limit
	check "$cmd: output present, no PHP warnings or fatals" bash -c 'test -s "$1" && ! grep -aEq "$2" "$1"' _ "$QC_SHARE/out.$cmd" "$PHPERR"
done
check "no private.index or private dir is created when no server is configured" csh c7 "test ! -e ~/.benchy/openbenchmarking.org/private.index && test ! -e ~/.benchy/openbenchmarking.org/private"
check "openbenchmarking-repositories does not list 'private' without a server" not_contains "private" "$(bp c7 openbenchmarking-repositories)"
check "private-repo-list with no server is a clear message, not a crash" no_crash "$(bp c7 private-repo-list)"
check "installing an unknown stock test behaves as before" contains "not" "$(bp c7 install pts/does-not-exist-abc)"

echo "== 10. PrivateRepository disabled or invalid"
for v in off pts system local git "Bad Name" "private-1.0.0"; do
	setcfg c3 "$v"; setcfg c1 "$v"
	out=$(bp c3 private-repo-refresh)
	check "PrivateRepository='$v': refresh says disabled, no crash" bash -c 'grep -aq "disabled or its name is invalid" <<<"$1"' _ "$out"
	out=$(bpin c1 "x" upload-private-test-profile local/hello-bench)
	check "PrivateRepository='$v': upload refuses safely" bash -c 'grep -aq "disabled or its name is invalid" <<<"$1"' _ "$out"
	out=$(bp c3 list-available-tests)
	check "PrivateRepository='$v': stock list-available-tests unaffected" bash -c 'grep -aq "pts/" <<<"$1" && ! grep -aEq "Fatal|Uncaught" <<<"$1"' _ "$out"
done
setcfg c3 off
check "KNOWN_BUG: with the feature off, a leftover private.index is not offered as a repository any more" not_contains "private/hello-bench" "$(bp c3 list-available-tests)"
setcfg c3 private; setcfg c1 private

echo "== 11. scheduled run from the web UI auto-installs a private test on a client that lacks it"
bp c8 private-repo-setup "$SERVER" >/dev/null; approve_all
python3 "$HERE/schedule_via_ui.py" private/hello-bench > "$QC_SHARE/sched.out" 2>&1
cat "$QC_SHARE/sched.out"
check "the web UI offers private tests in the add-test list" grep -q '^OK listed' "$QC_SHARE/sched.out"
check "schedule with a private test created and run-now requested through the web UI" grep -q '^OK schedule' "$QC_SHARE/sched.out"
docker rm -f "${QC_CLIENT_PREFIX}-c8run" >/dev/null 2>&1
(timeout 170 docker run --rm --name "${QC_CLIENT_PREFIX}-c8run" --network "$QC_NET" -u 1000 -e HOME=/home/bob -v "$(vol c8):/home/bob" -v "$QC_TREE:/bench:ro" "$QC_IMAGE" sh /bench/benchy phoromatic.connect "$SERVER" </dev/null >"$QC_SHARE/c8.out" 2>&1 &)
for i in $(seq 1 80); do
	csh c8 "grep -q 'Test Run Process Ended' ~/.benchy/phoronix-test-suite-benchmark.log 2>/dev/null" >/dev/null 2>&1 && break; sleep 2
done
docker rm -f "${QC_CLIENT_PREFIX}-c8run" >/dev/null 2>&1
check "scheduled run auto-installed the private test on the client" csh c8 "test -d ~/.benchy/installed-tests/private/hello-bench-1.0.0"
check "scheduled run executed the private test" csh c8 "grep -q 'Executing Test: private/hello-bench' ~/.benchy/phoronix-test-suite-benchmark.log"
check "the result reached the Phoromatic server" test "$(dbq 'SELECT COUNT(*) FROM phoromatic_results')" -ge 1

echo "== 12. PassMark example profile: validate, upload, static Windows review"
PM=$QC_TREE/documentation/examples/private-test-profiles/passmark
csh c1 "rm -rf ~/.benchy/test-profiles/local/passmark; cp -r /bench/documentation/examples/private-test-profiles/passmark ~/.benchy/test-profiles/local/passmark" >/dev/null
out=$(bp c1 validate-test-profile local/passmark)
check "validate-test-profile accepts the PassMark example" bash -c 'grep -aq "Test Profile XML Is Valid" <<<"$1" && ! grep -aiq "not allowed" <<<"$1"' _ "$out"
out=$(bpin c1 "passmark" upload-private-test-profile local/passmark)
check "PassMark example uploads" contains "Uploaded private/passmark-1.0.0" "$out"
check "PassMark is Windows-only in the index" srv "grep -q '\"supported_platforms\":\\[\"Windows\"\\]' $REPO/index.json"
check "install_windows.sh has no CRLF" bash -c "! grep -q \$'\\r' $PM/install_windows.sh"
check "install_windows.sh parses under sh -n" sh -n "$PM/install_windows.sh"
check "install_windows.sh parses under bash -n (Cygwin sh is bash)" bash -n "$PM/install_windows.sh"
check "embedded PowerShell is pure ASCII (PS 5.1 misreads BOM-less UTF-8)" bash -c "! LC_ALL=C grep -qP '[^\\x00-\\x7F]' $PM/install_windows.sh"
check "every powershell.exe call sets -ExecutionPolicy Bypass" bash -c "test \$(grep -c 'powershell.exe' $PM/install_windows.sh) -eq \$(grep 'powershell.exe' $PM/install_windows.sh | grep -c 'ExecutionPolicy Bypass')"
check "every powershell.exe -File path goes through cygpath -w" bash -c "test \$(grep -c 'powershell.exe' $PM/install_windows.sh) -eq \$(grep 'powershell.exe' $PM/install_windows.sh | grep -c 'cygpath -w')"
check "downloads.xml placeholders are still unreplaced (expected; must be filled before a real install)" grep -q REPLACE_WITH "$PM/downloads.xml"

echo
echo "e2e: $PASS passed, $FAIL failed"
for f in "${FAILED[@]:-}"; do [ -n "$f" ] && echo "  FAILED: $f"; done
[ "$FAIL" -eq 0 ]
