#!/bin/bash
# deploy/windows-installer/stop-benchy.ps1 -Mode Stop, run in PowerShell 7 on Linux (installer.sh starts it in the pwsh container): what the
# client answers on its quit-for-update pipe decides what is ended. OK: it quits by itself. BUSY (client 0.12 or later, a local run or an upload
# going): it changed nothing and stays, so nothing may be ended. No answer at all (no pipe, or a client older than 0.12): the old rule.
# A real sleeping process stands in for the client; the pipe is a .NET named pipe (a socket under /tmp on Linux); the engine is a shell script.
# usage: stop_benchy_test.sh <stop-benchy.ps1> <scratch folder>
set -u
SRC=$1; S=$2
FAILS=0
ok() { echo "ok $1"; }
bad() { echo "FAIL $1"; FAILS=$((FAILS+1)); }
eq() { if [ "$2" = "$3" ]; then ok "$1"; else bad "$1 (got '$2', wanted '$3')"; fi; }
export USERNAME=tester LOCALAPPDATA=$S/la
mkdir -p "$S/early/pts-core/static/update" "$S/la"
cat > "$S/early/pts-core/static/update/benchy-windows.ps1" <<'EOF'
function Get-BenchyProcesses($Dir) {
	$id = [int](Get-Content $env:FAKE_PIDFILE)
	if (Get-Process -Id $id -ErrorAction SilentlyContinue) { [pscustomobject]@{ Name = 'BenchyClient.exe'; ProcessId = $id; CommandLine = '' } }
}
function Format-BenchyProcesses($P) { 'BenchyClient.exe' }
EOF
cat > "$S/engine.sh" <<'EOF'
#!/bin/bash
echo "{\"type\":\"agent_state\",\"state\":\"$(cat "$(dirname "$0")/state")\",\"agent_run\":null}"
EOF
chmod +x "$S/engine.sh"
# the client's side of the pipe: answers the one line with $1, and (for OK) ends the stand-in client as the real one would
cat > "$S/pipe.ps1" <<'EOF'
param([string]$Reply, [int]$KillPid, [int]$Delay = 0)
$sha = [Security.Cryptography.SHA1]::Create()
$hash = ($sha.ComputeHash([Text.Encoding]::UTF8.GetBytes([string]$env:USERNAME)) | ForEach-Object { $_.ToString('x2') }) -join ''
$srv = New-Object System.IO.Pipes.NamedPipeServerStream "benchy-client-$($hash.Substring(0, 16))", ([IO.Pipes.PipeDirection]::InOut), 1, ([IO.Pipes.PipeTransmissionMode]::Byte), ([IO.Pipes.PipeOptions]::Asynchronous)
$srv.WaitForConnection()
$buf = New-Object byte[] 256
$n = $srv.Read($buf, 0, 256)
[IO.File]::WriteAllText((Join-Path $env:LOCALAPPDATA 'pipe.got'), [Text.Encoding]::ASCII.GetString($buf, 0, $n))
Start-Sleep -Milliseconds $Delay
$b = [Text.Encoding]::UTF8.GetBytes("$Reply`n")
$srv.Write($b, 0, $b.Length); $srv.Flush()
if ($Reply.StartsWith('OK') -and $KillPid) { Stop-Process -Id $KillPid -Force }
Start-Sleep -Milliseconds 800
$srv.Dispose()
EOF
ini() { iconv -f UTF-16 -t UTF-8 "$S/stop.ini" 2>/dev/null | tr -d '\r' | awk -v want="$1" '/^\[/ { sec = substr($0, 2, length($0) - 2); next } { i = index($0, "="); if (i > 0 && sec "." substr($0, 1, i - 1) == want) { print substr($0, i + 1); exit } }'; }
# case <pipe reply or none> <agent state>: runs Stop against a fresh stand-in client; sets $alive (1 when it still runs)
case_run() {
	local reply=$1 state=$2 delay=${3:-0}
	sleep 300 & local client=$!
	echo $client > "$S/client.pid"; export FAKE_PIDFILE=$S/client.pid
	echo "$state" > "$S/state"; rm -f "$S/stop.ini" "$S/la/pipe.got"
	local srv=""
	if [ "$reply" != none ]; then timeout 25 pwsh -NoProfile -File "$S/pipe.ps1" -Reply "$reply" -KillPid $client -Delay $delay >/dev/null 2>&1 & srv=$!; sleep 3; fi
	pwsh -NoProfile -File "$SRC" -Mode Stop -IniPath "$S/stop.ini" -EarlyDir "$S/early" -Engine "$S/engine.sh" -Wait 4 >/dev/null 2>&1
	[ -n "$srv" ] && { wait $srv 2>/dev/null; }
	if kill -0 $client 2>/dev/null; then alive=1; kill $client 2>/dev/null; else alive=0; fi
	wait $client 2>/dev/null
}

case_run 'BUSY {"reason":"run"}' idle
eq "a client answering BUSY is not ended: result busy, the client still runs" "$(ini stop.result)/$alive" "busy/1"
eq "...the message tells the person to wait or stop the test" "$(ini stop.message | grep -c 'Wait for it to finish')" 1
eq "...the question was the quit-for-update line" "$(tr -d '\r\n' < "$S/la/pipe.got")" "BENCHY-CLIENT-QUIT-FOR-UPDATE 1"
case_run 'BUSY {}' idle 7000
eq "a 0.12 client that takes 7 s to say BUSY (an upload in progress, the agent idle) is waited for, not taken for an older client and ended" "$(ini stop.result)/$alive" "busy/1"
case_run 'BUSY {}' busy
eq "BUSY with the agent busy too: still busy, still running" "$(ini stop.result)/$alive" "busy/1"
case_run 'OK {"was_window_open":true,"agent_was_running":false,"job_title":""}' idle
eq "a client answering OK quits by itself: result ok, was_window kept from its answer" "$(ini stop.result)/$alive/$(ini stop.was_window)" "ok/0/1"
case_run 'WHAT' idle
eq "an answer nobody knows is not a reason to kill: failed, still running" "$(ini stop.result)/$alive" "failed/1"
case_run none idle
eq "no answer at all (no pipe) is a client older than 0.12: idle, so it is ended (result ok)" "$(ini stop.result)/$alive" "ok/0"
case_run none busy
eq "...and with a local run going (state busy) it is left alone: result busy, still running" "$(ini stop.result)/$alive" "busy/1"

[ "$FAILS" = 0 ]
