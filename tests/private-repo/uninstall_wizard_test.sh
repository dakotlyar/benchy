#!/bin/bash
# deploy/windows-installer/uninstall-wizard.ps1 -Mode Run, run in PowerShell 7 on Linux (installer.sh starts it in the pwsh container):
# the order of stop, `agent reset` and uninstall.ps1, the keep switches, the exit codes (0, 3, 5, 3010, 40, 50) and the progress and
# result files NSIS reads. The engine, stop-benchy.ps1 and uninstall.ps1 are stand-ins; nothing of Windows runs here (the only edit to
# the script is that it starts pwsh where it starts powershell.exe).
# (Linux keeps only the low byte of an exit code, so 3010 reads as 194 here; result.ini has the real one.)
# usage: uninstall_wizard_test.sh <the script> <scratch folder>
set -u
SRC=$1; S=$2
W=$S/w
FAILS=0
ok() { echo "ok $1"; }
bad() { echo "FAIL $1"; FAILS=$((FAILS+1)); }
sed "s#\$PowerShellExe = Join-Path \$PSHOME 'powershell.exe'#\$PowerShellExe = Join-Path \$PSHOME 'pwsh'#" "$SRC" > "$S/wizard.ps1"
grep -q "Join-Path \$PSHOME 'pwsh'" "$S/wizard.ps1" || { echo "FAIL the test could not patch the powershell.exe line"; exit 1; }

# The stand-in engine: what `agent reset` says is in $S/engine.mode (ok, decline, expired, locked, partial_queued, partial_unqueued, broken)
cat > "$S/engine.sh" <<'EOF'
#!/bin/bash
S=$(dirname "$0")
echo "$@" > "$S/engine.args"
mode=$(cat "$S/engine.mode")
step() { echo "{\"type\":\"reset_step\",\"step\":\"$1\",\"id\":\"$1\",\"scope\":\"machine\",\"status\":\"$2\"}"; }
case $mode in
ok)
	step unattended_agent done; step agent_task done; step protected_home done; step elevated_run skipped; step machine_install skipped
	step policy done; step policy_key done; step agent_pairing done; step engine_results done
	echo '{"type":"reset_done","removed":["unattended_agent"],"left":[],"conflict":null,"stopped":null}'; exit 0 ;;
decline) echo '{"type":"error","code":"permission","message":"declined","detail":{"reason":"uac_declined"}}'; exit 1 ;;
expired) echo '{"type":"error","code":"permission","message":"expired","detail":{"reason":"uac_expired"}}'; exit 1 ;;
locked) echo '{"type":"error","code":"locked","message":"the agent did not stop"}'; exit 1 ;;
partial_queued)
	step unattended_agent done; step protected_home failed
	echo '{"type":"reset_done","removed":[],"left":[{"path":"C:\\ProgramData\\Benchy\\work","reason":"a file is open","queued":true}],"conflict":null,"stopped":null}'; exit 1 ;;
partial_unqueued)
	step unattended_agent done; step protected_home failed
	echo '{"type":"reset_done","removed":[],"left":[{"path":"C:\\ProgramData\\Benchy\\work","reason":"a file is open","queued":false}],"conflict":null,"stopped":null}'; exit 1 ;;
broken) echo "boom" >&2; exit 7 ;;
esac
EOF
chmod +x "$S/engine.sh"

# setup <reset ok in the plan: true|false>: a fresh work folder with a plan of five rows, and the stand-in scripts
setup() {
	rm -rf "$W"; mkdir -p "$W/early"
	echo "{\"rows\":[\"a\",\"b\",\"c\",\"d\",\"e\"],\"map\":{\"unattended_agent\":2,\"agent_task\":2,\"protected_home\":3,\"elevated_run\":3,\"machine_install\":3,\"policy\":4,\"policy_key\":4},\"last\":5,\"reset\":$1,\"machine_install\":true}" > "$W/plan.json"
	# stop-benchy.ps1: [stop] result from $S/stop.mode, in the UTF-16 INI the real one writes
	cat > "$W/stop-benchy.ps1" <<'EOF'
param([string]$Mode, [string]$IniPath, [string]$EarlyDir, [string]$Engine)
$r = (Get-Content (Join-Path (Split-Path $PSScriptRoot) 'stop.mode') -ErrorAction SilentlyContinue)
$msg = if ($r -eq 'console') { 'Close the Benchy command prompt window, then try again.' } else { '' }
[IO.File]::WriteAllLines($IniPath, [string[]]@('[stop]', "result=$r", "message=$msg"), [Text.Encoding]::Unicode)
EOF
	# uninstall.ps1: records its arguments, writes the result JSON uninstall.mode asks for, and exits with its code
	cat > "$W/early/uninstall.ps1" <<'EOF'
param([switch]$Quiet, [switch]$RemoveUserData, [switch]$KeepResults, [switch]$KeepSettings, [switch]$KeepInstalledTests, [switch]$AtReboot, [switch]$FromGui, [string]$ResultFile, [string]$EnginePath)
$S = Split-Path (Split-Path $PSScriptRoot)
$line = ($PSBoundParameters.Keys | Sort-Object | ForEach-Object { if ($PSBoundParameters[$_] -is [switch]) { "-$_" } else { "-$_ x" } }) -join ' '
[IO.File]::WriteAllText((Join-Path $S 'uninstall.args'), $line)
$m = (Get-Content (Join-Path $S 'uninstall.mode')).Trim()
$code = 0; $msg = 'Benchy was uninstalled.'; $left = @()
# (a real exit of 3010 would read as 194 on Linux, so the stand-in exits 0 and the wizard turns the queued leftover into 3010)
if ($m -eq 'queued') { $code = 0; $msg = 'queued'; $left = @(@{ path = 'C:\x\php.exe'; reason = 'in use'; queued = $true }) }
if ($m -eq 'fail40') { $code = 40; $msg = 'Benchy was not fully removed.' }
[IO.File]::WriteAllText($ResultFile, (@{ result = 'x'; code = $code; message = $msg; left = $left; kept = @('C:\Users\u\.benchy') } | ConvertTo-Json -Depth 5))
exit $code
EOF
	rm -f "$S/uninstall.args" "$S/engine.args"
}

# run <engine mode> <stop mode> <uninstall mode> <keep results> <keep tests>: prints the exit code
run() {
	echo "$1" > "$S/engine.mode"; echo "$2" > "$S/stop.mode"; echo "$3" > "$S/uninstall.mode"
	pwsh -NoProfile -File "$S/wizard.ps1" -Mode Run -WorkDir "$W" -Engine "$S/engine.sh" -KeepResults "${4:-1}" -KeepTests "${5:-0}" -LogFile "$W/log.txt" >/dev/null 2>&1
	echo $?
}
# result.ini is UTF-16: ini <section.key> reads one value
ini() { iconv -f UTF-16 -t UTF-8 "$W/result.ini" 2>/dev/null | tr -d '\r' | awk -v want="$1" '/^\[/ { sec = substr($0, 2, length($0) - 2); next } { i = index($0, "="); if (i > 0 && sec "." substr($0, 1, i - 1) == want) { print substr($0, i + 1); exit } }'; }
prog() { tr -d '\r' < "$W/progress.txt" | tr '\n' ',' ; }
eq() { if [ "$2" = "$3" ]; then ok "$1"; else bad "$1 (got '$2', wanted '$3')"; fi; }

setup true; rc=$(run ok ok ok 1 0)
eq "everything goes: exit 0" "$rc" 0
eq "...stop, reset, then uninstall.ps1 with -FromGui (the reset ran) and the default keeps" "$(cat "$S/uninstall.args")" "-AtReboot -EnginePath x -FromGui -KeepResults -KeepSettings -Quiet -ResultFile x"
eq "...the engine is told to keep results and settings, remove tests, and queue at reboot" "$(cat "$S/engine.args")" "--json agent reset --all --uninstall --keep-data results,settings --at-reboot"
eq "...every row starts and ends in order" "$(prog)" "1 start,1 done,2 start,2 done,3 start,3 done,4 start,4 done,5 start,5 done,"
eq "...result.ini says code 0 and the kept path" "$(ini result.code)/$(ini kept.path1)" '0/C:\Users\u\.benchy'

setup true; rc=$(run decline ok ok 1 0)
eq "a declined prompt: exit 5, the reason kept" "$rc/$(ini result.reason)" "5/uac_declined"
eq "...nothing after the reset ran (uninstall.ps1 never started) and the last row never began" "$([ -e "$S/uninstall.args" ] && echo ran || echo not)/$(prog | grep -c '5 start')" "not/0"
setup true; rc=$(run expired ok ok 1 0)
eq "an expired prompt is exit 5 too" "$rc/$(ini result.reason)" "5/uac_expired"

setup true; rc=$(run locked ok ok 1 0)
eq "an agent that will not stop: exit 3, nothing removed" "$rc/$([ -e "$S/uninstall.args" ] && echo ran || echo not)" "3/not"
setup true; rc=$(run ok console ok 1 0)
eq "a console that cannot be closed: exit 3 before the engine is asked anything" "$rc/$([ -e "$S/engine.args" ] && echo ran || echo not)/$(ini result.message | cut -c1-12)" "3/not/Close the Be"
setup true; rc=$(run ok busy ok 1 0)
eq "an older client with a run going (busy) is exit 3 as well" "$rc" 3

setup true; rc=$(run ok ok ok 0 0)
eq "keep nothing: -RemoveUserData and no --keep-data" "$(cat "$S/uninstall.args")|$(cat "$S/engine.args")" "-AtReboot -EnginePath x -FromGui -Quiet -RemoveUserData -ResultFile x|--json agent reset --all --uninstall --at-reboot"
setup true; rc=$(run ok ok ok 0 1)
eq "keep only the tests" "$(cat "$S/uninstall.args")|$(cat "$S/engine.args")" "-AtReboot -EnginePath x -FromGui -KeepInstalledTests -Quiet -ResultFile x|--json agent reset --all --uninstall --keep-data tests --at-reboot"
setup true; rc=$(run ok ok ok 1 1)
eq "keep both" "$(cat "$S/engine.args")" "--json agent reset --all --uninstall --keep-data results,settings,tests --at-reboot"

setup true; rc=$(run partial_queued ok ok 1 0)
eq "the engine's queued leftovers: exit 3010 with the path and reason in result.ini" "$rc/$(ini result.code)/$(ini left.count)/$(ini left.path1)/$(ini left.reason1)" '194/3010/1/C:\ProgramData\Benchy\work/a file is open'
setup true; rc=$(run partial_unqueued ok ok 1 0)
eq "an engine leftover nothing will remove: exit 40" "$rc" 40
setup true; rc=$(run ok ok queued 1 0)
eq "uninstall.ps1's own queued leftovers: exit 3010" "$rc/$(ini result.code)/$(ini left.count)" "194/3010/1"
setup true; rc=$(run ok ok fail40 1 0)
eq "uninstall.ps1 exit 40 is passed through, with its message" "$rc/$(ini result.message | cut -c1-20)" "40/Benchy was not fully"
setup true; rc=$(run broken ok ok 1 0)
eq "an engine that dies: exit 50, uninstall.ps1 not run" "$rc/$([ -e "$S/uninstall.args" ] && echo ran || echo not)" "50/not"

setup false; rc=$(run ok ok ok 1 0)
eq "an engine that cannot reset (plan.reset false): no reset call, uninstall.ps1 runs WITHOUT -FromGui so it asks the engine itself" "$rc/$([ -e "$S/engine.args" ] && echo ran || echo not)/$(cat "$S/uninstall.args")" "0/not/-AtReboot -EnginePath x -KeepResults -KeepSettings -Quiet -ResultFile x"

setup true; rc=$(run ok ok ok 1 0)
eq "the plan's still-installed flag reaches result.ini" "$(ini still.machine_install)" 1

# --- -Mode Inventory: the first page is built from what is there --------------------------------------------------------------
# uninstall.ps1 -Inventory stand-in: writes $S/inv.json (made per case) to -ResultFile
inv_setup() {
	rm -rf "$W"; mkdir -p "$W/early"
	cat > "$W/early/uninstall.ps1" <<'EOF'
param([switch]$Inventory, [string]$ResultFile, [string]$EnginePath)
Copy-Item -LiteralPath (Join-Path (Split-Path (Split-Path $PSScriptRoot)) 'inv.json') -Destination $ResultFile
EOF
}
# inv_json <machine presence: true|false|null> <account or "">
inv_json() {
	local m=$1 acct=${2:-}
	[ -n "$acct" ] && acct="\"$acct\"" || acct=null
	cat > "$S/inv.json" <<EOF
{"result":"inventory","code":0,"engine":{"reset":true,"note":null},"machine":$([ "$m" = false ] && echo false || echo true),"conflict":null,
 "items":[{"id":"app_dir","present":true,"bytes":1000,"count":10},{"id":"start_menu","present":true},{"id":"run_value","present":true},
  {"id":"results","present":true,"bytes":2000000,"count":5},{"id":"settings","present":true,"bytes":3000000,"count":null},
  {"id":"installed_tests","present":true,"bytes":6000000000,"count":null},{"id":"client_settings","present":false,"bytes":null}],
 "engine_items":[{"id":"agent_pairing","present":true},{"id":"engine_results","present":true,"bytes":1000000,"count":7},{"id":"engine_settings","present":true,"bytes":10,"count":1},
  {"id":"engine_tests","present":false},{"id":"server_hint","present":true},
  {"id":"unattended_agent","present":$m,"account":$acct},{"id":"agent_task","present":$m,"account":$acct},{"id":"protected_home","present":$m},
  {"id":"elevated_run","present":$m},{"id":"machine_install","present":$m},{"id":"policy","present":$m},{"id":"policy_key","present":$m}]}
EOF
}
inv() { pwsh -NoProfile -File "$S/wizard.ps1" -Mode Inventory -WorkDir "$W" -Engine "$S/engine.sh" -Server "seinar" -Title "KAMINO" -LogFile "$W/log.txt" >/dev/null 2>&1; echo $?; }
iniw() { iconv -f UTF-16 -t UTF-8 "$W/inv.ini" 2>/dev/null | tr -d '\r' | awk -v want="$1" '/^\[/ { sec = substr($0, 2, length($0) - 2); next } { i = index($0, "="); if (i > 0 && sec "." substr($0, 1, i - 1) == want) { print substr($0, i + 1); exit } }'; }

inv_setup; inv_json false; rc=$(inv)
eq "inventory on a computer never set up unattended: three rows, no machine parts, two step rows (EC-1)" "$rc/$(iniw inv.machine)/$(iniw rows.count)/$(iniw steps.count)" "0/0/3/2"
eq "...the rows are the app, the server agent with its pairing, and the Start menu" "$(iniw rows.text1)|$(iniw rows.text2)|$(iniw rows.text3)" "Benchy app, tray and both engines|Server agent and pairing with seinar|Start menu, log on entry, server address"
eq "...results and tests are summed over both engines' folders" "$(iniw keep.results)|$(iniw keep.tests)" "12 results, 6 MB|5.6 GB of downloaded test files"
inv_setup; inv_json true bench; rc=$(inv)
eq "inventory with the unattended setup: six rows, five steps, the account named, all-users tags (EC-16)" "$rc/$(iniw inv.machine)/$(iniw rows.count)/$(iniw steps.count)/$(iniw rows.text3)/$(iniw rows.tag3)" "0/1/6/5/Unattended agent (bench), task and folders/all users"
eq "...the plan maps the engine's ids to the rows" "$(pwsh -NoProfile -Command "\$p = Get-Content '$W/plan.json' -Raw | ConvertFrom-Json; \"\$(\$p.map.agent_task),\$(\$p.map.elevated_run),\$(\$p.map.policy_key),\$(\$p.last),\$(\$p.machine_install)\"")" "2,3,4,5,True"
inv_setup; inv_json null ""; rc=$(inv)
eq "a presence that could not be read (null) counts as there: the machine rows are listed (EC-17)" "$(iniw rows.count)/$(iniw inv.machine)" "6/1"

[ "$FAILS" = 0 ]
