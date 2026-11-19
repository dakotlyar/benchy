#!/bin/bash
# install.ps1 -CheckRunning, run in PowerShell 7 on Linux (installer.sh starts it in the pwsh container): what the wizards call a local run, a server
# job and the agent. The process list is a stand-in for Get-BenchyProcesses (Win32_Process objects with a parent).
# usage: checkrunning_test.sh <install.ps1> <scratch folder>
set -u
SRC=$1; S=$2
FAILS=0
ok() { echo "ok $1"; }
bad() { echo "FAIL $1"; FAILS=$((FAILS+1)); }
eq() { if [ "$2" = "$3" ]; then ok "$1"; else bad "$1 (got '$2', wanted '$3')"; fi; }
export LOCALAPPDATA=$S/la
mkdir -p "$S/t/pts-core/static/update" "$S/la"
cp "$SRC" "$S/t/install.ps1"
# the stand-in list: <name>|<pid>|<parent>|<command line>, one process per line in $S/procs
cat > "$S/t/pts-core/static/update/benchy-windows.ps1" <<'EOF'
function Get-BenchyProcesses($Dir) {
	foreach ($l in Get-Content (Join-Path $env:LOCALAPPDATA 'procs')) {
		$p = $l -split '\|'
		[pscustomobject]@{ Name = $p[0]; ProcessId = [int]$p[1]; ParentProcessId = [int]$p[2]; CommandLine = $p[3] }
	}
}
function Format-BenchyProcesses($P) { (@($P | ForEach-Object { $_.Name }) -join ', ') }
EOF
run() { printf '%s\n' "$@" > "$S/la/procs"; rm -f "$S/r.json"; pwsh -NoProfile -File "$S/t/install.ps1" -CheckRunning -ResultFile "$S/r.json" >/dev/null 2>&1
	pwsh -NoProfile -Command "\$r = Get-Content '$S/r.json' -Raw | ConvertFrom-Json; \"client=\$(\$r.client) agent=\$(\$r.agent) run=\$(\$r.run) count=\$(@(\$r.running).Count)\""; }
eq "a server job with the client open: the agent's benchmark child and the client's status poll are not a local run" \
	"$(run 'BenchyClient.exe|50|1|C:\x\client\BenchyClient.exe' 'benchy-engine.exe|100|50|benchy-engine.exe agent run' 'benchy-engine.exe|101|100|benchy-engine.exe benchmark job-1' 'benchy-engine.exe|102|50|benchy-engine.exe --json agent status')" \
	"client=True agent=True run=False count=3"
eq "...nor what that child starts" \
	"$(run 'benchy-engine.exe|100|1|benchy-engine.exe agent run' 'benchy-engine.exe|101|100|benchy-engine.exe benchmark j' 'benchy-engine.exe|103|101|benchy-engine.exe self-test')" \
	"client=False agent=True run=False count=3"
eq "a run the client started (an engine nobody's agent started) is a local run, with the agent up too" \
	"$(run 'BenchyClient.exe|50|1|c' 'benchy-engine.exe|100|1|benchy-engine.exe agent run' 'benchy-engine.exe|110|50|benchy-engine.exe benchmark local')" \
	"client=True agent=True run=True count=3"
eq "a run from the command line with no agent is a local run" "$(run 'benchy-engine.exe|120|7|benchy-engine.exe benchmark cli')" "client=False agent=False run=True count=1"
eq "only a status poll running: nothing is running" "$(run 'benchy-engine.exe|102|50|benchy-engine.exe --json agent status')" "client=False agent=False run=False count=0"
eq "an idle tray client whose cmd.exe and php.exe run under it: no command prompt window, no PHP of the person's" \
	"$(run 'BenchyClient.exe|50|1|c --tray' 'cmd.exe|60|50|cmd.exe /c x' 'php.exe|61|60|php.exe y' 2>&1 | head -1)/$(pwsh -NoProfile -Command "\$r = Get-Content '$S/r.json' -Raw | ConvertFrom-Json; \"console=\$(\$r.console) php=\$(\$r.php)\"")" \
	"client=True agent=False run=False count=3/console=False php=False"
run 'cmd.exe|70|7|cmd.exe /k benchy.bat' 'php.exe|71|70|php.exe z' >/dev/null
eq "a cmd.exe and php.exe nobody's client started are the person's: console and php are reported" "$(pwsh -NoProfile -Command "\$r = Get-Content '$S/r.json' -Raw | ConvertFrom-Json; \"console=\$(\$r.console) php=\$(\$r.php)\"")" "console=True php=True"
[ "$FAILS" = 0 ]
