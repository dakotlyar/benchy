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
	docker run --rm -i --network "${CNET:-$QC_NET}" -u 1000 -e HOME=/home/bob -e TERM=dumb -e "BENCHY_PRIVATE_REPO_TOKEN=${CTOKEN-$QC_REPO_TOKEN}" -v "$(vol "$c"):/home/bob" -v "$QC_TREE:/bench:ro" -v "$QC_SHARE:/share" "$QC_IMAGE" "$@"
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
# The profile carries run.sh and the PowerShell script inside install_windows.sh; PowerShell is found by full path at install time
# and recorded as ps_exe= in platform.txt, then run.sh calls "$PS_EXE" ... -File "$WIN_PS1_PATH". Continuation lines are joined first.
PMJ=$(sed -e :a -e '/\\$/N; s/\\\n[[:space:]]*/ /; ta' "$PM/install_windows.sh")
check "there is at least one PowerShell -File invocation" bash -c 'test "$(grep -c -- "-File " <<<"$1")" -ge 1' _ "$PMJ"
# Every way of starting PowerShell - -File, -Command, the version probe, the installer launch - must either carry -ExecutionPolicy Bypass or go
# through "$PS_EXE" (the full path found at install time). Only real invocations count: a line that runs powershell.exe or "$PS_EXE" with options.
check "every PowerShell invocation (any form) carries -ExecutionPolicy Bypass or goes through \"\$PS_EXE\"" bash -c 'bad=$(grep -E "(^|[^A-Za-z_./-])(powershell(\.exe)?|\"?\$PS_EXE\"?) +-[A-Za-z]" <<<"$1" | grep -v "ExecutionPolicy Bypass" | grep -v "PS_EXE"); test -z "$bad"' _ "$PMJ"
check "there are PowerShell -Command invocations and they are covered by that rule" bash -c 'test "$(grep -cE "(powershell(\.exe)?|\$PS_EXE\"?) +-[A-Za-z]+ .*-Command" <<<"$1")" -ge 2' _ "$PMJ"
check "every PowerShell -File invocation sets -ExecutionPolicy Bypass" bash -c 'test "$(grep -c -- "-File " <<<"$1")" -eq "$(grep -- "-File " <<<"$1" | grep -c "ExecutionPolicy Bypass")"' _ "$PMJ"
check "every PowerShell -File path is the cygpath -w converted one" bash -c 'test "$(grep -c -- "-File " <<<"$1")" -eq "$(grep -- "-File " <<<"$1" | grep -c "\"\$WIN_PS1_PATH\"")" && grep -q "WIN_PS1_PATH=\$(cygpath -w" <<<"$1"' _ "$PMJ"
check "PowerShell is located by full path with a PATH fallback" bash -c 'grep -q "WindowsPowerShell/v1.0/powershell.exe" <<<"$1" && grep -q "PS_EXE=powershell.exe" <<<"$1"' _ "$PMJ"
check "the located PowerShell is recorded in platform.txt as ps_exe=" bash -c 'grep -q "^ps_exe=" <<<"$1"' _ "$PMJ"
check "the test executable wrapper is written before any early exit" bash -c 'test "$(grep -n "cat > \"\$HOME/passmark\"" "$1" | head -1 | cut -d: -f1)" -lt "$(grep -n "ERROR: powershell.exe not found" "$1" | head -1 | cut -d: -f1)"' _ "$PM/install_windows.sh"
check "downloads.xml has real hashes (no REPLACE_WITH placeholders left)" bash -c "! grep -q REPLACE_WITH $PM/downloads.xml"

echo "== 13. repository tokens: the CLI flow (author machine ct1, second ct2, unapproved ct3)"
TOK=$QC_REPO_TOKEN
make_profile 9.1.0 50
stage_profile ct1
bp ct1 private-repo-setup "$SERVER" >/dev/null
bp ct2 private-repo-setup "$SERVER" >/dev/null
approve_all
check "the harness token (created with private-repo-token-create) is stored only as a hash" test "$(dbq "SELECT COUNT(*) FROM phoromatic_private_repo_tokens WHERE TokenHash='$(printf %s "$TOK" | sha256sum | cut -d' ' -f1)'")" = 1
check "the raw token is not on the server disk" bash -c "! docker exec $QC_SRV grep -rlaF '$TOK' /var/lib/benchy /var/log /root/.benchy 2>/dev/null | grep -q ."

# an old client / one that never ran private-repo-login: no token at all
out=$(CTOKEN= bpin ct1 "no token" upload-private-test-profile local/hello-bench)
check "upload without a token explains how to get one" contains "repository token is required" "$out"
check "...and names private-repo-login" contains "private-repo-login" "$out"
check "...and stored nothing" bash -c "! docker exec $QC_SRV test -e $REPO/tests/hello-bench/9.1.0.zip"
check "...without crashing" no_crash "$out"
out=$(CTOKEN= bp ct1 private-repo-rebuild)
check "rebuild without a token is refused with the same guidance" contains "repository token is required" "$out"
out=$(CTOKEN= bp ct1 private-repo-delete private/hello-bench-1.0.0)
check "delete without a token is refused" contains "repository token is required" "$out"
check "...and the version is still there" srv "test -f $REPO/tests/hello-bench/1.0.0.zip"
bp ct1 private-repo-refresh >/dev/null
out=$(CTOKEN= bp ct1 private-repo-list)
check "reading still works without a token (list)" bash -c 'grep -aqE "private/|repository is empty" <<<"$1" && ! grep -aqi "token" <<<"$1"' _ "$out"

# a wrong token
WRONG=bpr_AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA
out=$(CTOKEN=$WRONG bp ct1 private-repo-rebuild)
check "a wrong token is refused as not accepted" contains "not accepted" "$out"

# private-repo-login
out=$(CTOKEN= bpin ct1 "garbage" private-repo-login)
check "login refuses something that is not a token" contains "does not look like" "$out"
check "...and saves nothing" test -z "$(csh ct1 'ls ~/.benchy/private-repo-token 2>/dev/null')"
out=$(CTOKEN= bpin ct1 "$TOK" private-repo-login)
check "login saves a valid-looking token" contains "Token saved" "$out"
check "the saved token file is 0600" test "$(csh ct1 'stat -c %a ~/.benchy/private-repo-token')" = 600
check "the saved token is the one given" test "$(csh ct1 'cat ~/.benchy/private-repo-token')" = "$TOK"
check "the token never appears on the command line (stdin only)" not_contains "$TOK" "$out"
csh ct2 'mkdir -p ~/.benchy; echo precious > ~/victim; ln -s ~/victim ~/.benchy/private-repo-token' >/dev/null
out=$(CTOKEN= bpin ct2 "$TOK" private-repo-login)
check "login over a symlinked token file succeeds" contains "Token saved" "$out"
check "...without writing through the symlink" test "$(csh ct2 'cat ~/victim')" = precious
check "...the token file is now a regular 0600 file" test "$(csh ct2 'test ! -L ~/.benchy/private-repo-token && stat -c %a ~/.benchy/private-repo-token')" = 600
check "...and no temp files are left behind" test -z "$(csh ct2 'ls ~/.benchy | grep "private-repo-token\..*tmp"')"
check "login reports that the token is not yet verified" contains "not yet verified" "$out"
out=$(CTOKEN= bpin ct1 "with the saved token" upload-private-test-profile local/hello-bench)
check "upload works with only the saved token" contains "Uploaded private/hello-bench-9.1.0" "$out"
out=$(CTOKEN=$WRONG bp ct1 private-repo-rebuild)
check "the environment variable takes precedence over the saved file" contains "not accepted" "$out"
out=$(CTOKEN= bp ct1 private-repo-rebuild)
check "rebuild works with the saved token" contains "Index rebuilt" "$out"
out=$(CTOKEN= bp ct1 private-repo-delete private/hello-bench-9.1.0)
check "delete works with the saved token" contains "Removed private/hello-bench-9.1.0" "$out"
check "...and the version is gone" bash -c "! docker exec $QC_SRV test -f $REPO/tests/hello-bench/9.1.0.zip"

# environment-only client, revocation
T2=$(docker exec "$QC_SRV" sh /bench/benchy private-repo-token-create e2e-second | grep -o 'bpr_[A-Za-z0-9_-]\{43\}' | head -1)
check "a second token was created" test -n "$T2"
out=$(CTOKEN=$T2 bp ct2 private-repo-rebuild)
check "the environment-only client works with the new token" contains "Index rebuilt" "$out"
check "the audit row carries the token label" test "$(dbq "SELECT COUNT(*) FROM phoromatic_private_repo_audit WHERE Action='rebuild' AND ActorDetail='e2e-second'")" -ge 1
T2ID=$(dbq "SELECT TokenID FROM phoromatic_private_repo_tokens WHERE Label='e2e-second'")
out=$(docker exec "$QC_SRV" sh /bench/benchy private-repo-token-revoke "$T2ID")
check "the token is revoked on the server" contains "revoked" "$out"
out=$(CTOKEN=$T2 bp ct2 private-repo-rebuild)
check "a revoked token is refused immediately" contains "not accepted" "$out"
check "...while the other token still works" contains "Index rebuilt" "$(CTOKEN=$TOK bp ct2 private-repo-rebuild)"

# a valid token is not enough from a machine the account has not approved
bp ct3 private-repo-setup "$SERVER" >/dev/null
stage_profile ct3
out=$(CTOKEN=$TOK bpin ct3 "unapproved" upload-private-test-profile local/hello-bench)
check "an unapproved machine with a valid token is refused" contains "ERROR:" "$out"
check "...and nothing was stored" bash -c "! docker exec $QC_SRV test -e $REPO/tests/hello-bench/9.1.0.zip"

# the audit trail saw all of it
for a in cli_auth_failed token_created token_revoked upload_committed delete rebuild; do
	check "audit has $a rows" test "$(dbq "SELECT COUNT(*) FROM phoromatic_private_repo_audit WHERE Action='$a'")" -ge 1
done
check "audit recorded a missing-token and a wrong-token failure" test "$(dbq "SELECT COUNT(DISTINCT CASE WHEN Detail LIKE '%missing%' THEN 'm' WHEN Detail LIKE '%invalid%' THEN 'i' END) FROM phoromatic_private_repo_audit WHERE Action='cli_auth_failed'")" -ge 2

echo "== 14. upload through the web page (stage, review, confirm), then a client installs and runs it"
make_profile 9.2.0 77
rm -f "$QC_SHARE/hello-web.zip"
python3 - "$FX/hello-bench" "$QC_SHARE/hello-web.zip" <<'PYZIP'
import os, sys, zipfile
src, out = sys.argv[1:3]
with zipfile.ZipFile(out, 'w', zipfile.ZIP_DEFLATED) as z:
    for f in sorted(os.listdir(src)):
        info = zipfile.ZipInfo(f); info.compress_type = zipfile.ZIP_DEFLATED; info.external_attr = (0o755 if f == 'install.sh' else 0o644) << 16
        z.writestr(info, open(os.path.join(src, f), 'rb').read())
PYZIP
out=$(python3 "$HERE/upload_via_ui.py" "$QC_SHARE/hello-web.zip" --name hello-bench --desc "web upload e2e" 2>&1)
check "the page stages, reviews and adds the profile" contains "RESULT: Added private/hello-bench-9.2.0" "$out"
check "the stored archive is byte for byte the uploaded one" test "$(srv "sha256sum $REPO/tests/hello-bench/9.2.0.zip" | cut -d' ' -f1)" = "$(sha256sum "$QC_SHARE/hello-web.zip" | cut -d' ' -f1)"
check "the stored metadata records the web user and source" srv "grep -q '\"source\":\"web\"' $REPO/tests/hello-bench/9.2.0.json && grep -q '\"uploaded_by\":\"$QC_USER\"' $REPO/tests/hello-bench/9.2.0.json"
check "no staged copy of it is left behind" test -z "$(srv "grep -l '\"name\":\"hello-bench\"' $REPO/staging/*/meta.json 2>/dev/null")"
check "audit has staged, attempt and committed rows from the web user" test "$(dbq "SELECT COUNT(*) FROM phoromatic_private_repo_audit WHERE Name='hello-bench' AND Version='9.2.0' AND Actor='$QC_USER' AND Action IN ('upload_staged','upload_attempt','upload_committed')")" = 3
bp ctw private-repo-setup "$SERVER" >/dev/null
approve_all
bp ctw private-repo-refresh >/dev/null
check "a fresh client lists the web-uploaded version" contains "9.2.0" "$(bp ctw private-repo-list | grep -a '^private/hello-bench ')"
bp ctw install private/hello-bench-9.2.0 >/dev/null
check "the client installs it and runs it (Score: 77)" contains "Score: 77" "$(score_of ctw private/hello-bench-9.2.0)"
out=$(python3 "$HERE/upload_via_ui.py" "$QC_SHARE/hello-web.zip" --name hello-bench 2>&1)
check "uploading the same version again is refused at confirm unless overwrite is ticked" contains "already exists" "$out"
check "...and it was not stored a second time" test "$(dbq "SELECT COUNT(*) FROM phoromatic_private_repo_audit WHERE Name='hello-bench' AND Version='9.2.0' AND Action='upload_committed'")" = 1
out=$(python3 "$HERE/upload_via_ui.py" "$QC_SHARE/hello-web.zip" --name hello-bench --cancel 2>&1)
check "cancelling a staged upload through the page removes it" contains "RESULT: Upload cancelled" "$out"
check "...an upload_cancelled audit row was written" test "$(dbq "SELECT COUNT(*) FROM phoromatic_private_repo_audit WHERE Name='hello-bench' AND Action='upload_cancelled'")" -ge 1
SID=$(grep -o 'STAGE: [0-9a-f]*' <<<"$out" | cut -d' ' -f2)
check "...it had a stage id" test -n "$SID"
check "...and that stage is gone from the server" srv "test ! -e $REPO/staging/$SID"

# the PassMark example, zipped from the repository, through the page under another name
rm -f "$QC_SHARE/passmark-web.zip"
(cd "$PM" && python3 - "$QC_SHARE/passmark-web.zip" <<'PYZIP'
import os, sys, zipfile
with zipfile.ZipFile(sys.argv[1], 'w', zipfile.ZIP_DEFLATED) as z:
    for f in sorted(os.listdir('.')):
        z.write(f, f)
PYZIP
)
out=$(python3 "$HERE/upload_via_ui.py" "$QC_SHARE/passmark-web.zip" --name passmark-web 2>&1)
check "the PassMark example uploads through the page" contains "RESULT: Added private/passmark-web-1.0.0" "$out"
check "...as a Windows-only entry in the index" srv "grep -q passmark-web $REPO/index.json"

echo
echo "e2e: $PASS passed, $FAIL failed"
for f in "${FAILED[@]:-}"; do [ -n "$f" ] && echo "  FAILED: $f"; done
[ "$FAIL" -eq 0 ]
