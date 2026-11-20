# Run by installer.sh inside a PowerShell container (Linux): pts-core/static/update/apply-update.ps1 against a fake install, fake
# components and a fake process list. The Windows-only parts (a running exe holding a folder, the real php.exe, the UAC prompt, the
# registry) are Zeb's Lothal run. Prints "ok <name>" or "FAIL <name>: why"; exits 1 on any FAIL.
param(
	[Parameter(Mandatory)][string]$UpdateDir,
	[string]$Child,
	[string]$Root,
	[string]$At
)
$ErrorActionPreference = 'Stop'
. (Join-Path $UpdateDir 'apply-update.ps1') -InstallDir (Join-Path ([IO.Path]::GetTempPath()) 'unused') -DefineOnly

# --- What Windows would answer, faked (the lib's own versions are Windows-only) ---
$Script:ProcFn = $null
function Get-BenchyProcesses([string]$InstallDir) { if ($Script:ProcFn) { @(& $Script:ProcFn) } else { @() } }
function Get-BenchyFilesInUse([string]$InstallDir, [switch]$SkipPhp) { @() }
function Test-BenchyPathInside([string]$Path, [string]$Dir)
{
	$Full = [IO.Path]::GetFullPath($Path).TrimEnd('/') + '/'
	return $Full.StartsWith([IO.Path]::GetFullPath($Dir).TrimEnd('/') + '/')
}
function Get-BenchyVersion([string]$Dir) { $F = Join-Path $Dir 'VERSION'; if (Test-Path $F) { (Get-Content -Raw $F).Trim() } }
function Get-BenchyPin([string]$Dir) { [pscustomobject]@{ ini = @('extension=openssl'); required_extensions = @('openssl') } }
function Test-PhpRuns([string]$PhpExe, [string]$IniPath, [string]$SmokeScript, $RequiredExtensions, [string]$ExpectedVersion)
{
	$Text = (Get-Content -Raw -LiteralPath $PhpExe).Trim()
	$Script:PhpRuns += , $Text
	return [pscustomobject]@{ Ok = ($Text -like 'good*'); Message = "fake php says $Text"; PhpVersion = $ExpectedVersion; Extensions = @() }
}
function Update-ApplyUninstallEntry([string]$Install) { $Script:UninstallUpdated++ }
function Start-ClientAgain([string]$Install) { $Script:Relaunched++ }
$Script:PhpRuns = @(); $Script:UninstallUpdated = 0; $Script:Relaunched = 0

function Write-Text([string]$Path, [string]$Text)
{
	[void](New-Item -ItemType Directory -Force (Split-Path -Parent $Path))
	[IO.File]::WriteAllText($Path, $Text)
}
function New-Install([string]$Base)
{
	$I = Join-Path $Base 'install'
	Write-Text (Join-Path $I 'php/php.exe') 'good-old'
	Write-Text (Join-Path $I 'php/php.ini') 'old ini'
	Write-Text (Join-Path $I 'benchy.bat') 'bat-old'
	Write-Text (Join-Path $I 'VERSION') '1.0.0'
	Write-Text (Join-Path $I 'pts-core/phoronix-test-suite.php') 'core-old'
	Write-Text (Join-Path $I 'pts-core/gone-in-new.txt') 'only old'
	Write-Text (Join-Path $I 'client/BenchyClient.exe') 'client-old'
	Write-Text (Join-Path $I 'engine/native/benchy-engine.exe') 'engine'
	Write-Text (Join-Path $I 'state/keep.txt') 'state'
	Write-Text (Join-Path $I 'cygwin-root.txt') 'unmanaged'
	[void](New-Item -ItemType Directory -Force (Join-Path $I 'update'))
	return $I
}
function Add-Staging([string]$I, [hashtable]$Opt = @{})
{
	$S = Join-Path $I 'update/staging/7'
	Write-Text (Join-Path $S 'x/php/php.exe') $(if ($Opt.BadPhp) { 'bad-new' } else { 'good-new' })
	Write-Text (Join-Path $S 'x/client/BenchyClient.exe') 'client-new'
	foreach ($F in @('benchy.bat:bat-new', 'VERSION:2.0.0', 'pts-core/phoronix-test-suite.php:core-new', 'pts-core/static/update/apply-update.ps1:script', 'pts-core/static/update/php-smoke.php:smoke', 'pts-core/static/update/runtime-pins.json:{}'))
	{
		$P, $T = $F -split ':', 2
		Write-Text (Join-Path $S "x/benchy/$P") $T
	}
	if ($Opt.TreeHasUpdate) { Write-Text (Join-Path $S 'x/benchy/update/evil.txt') 'x' }
	$Steps = @(
		@{ order = 1; component = 'php'; to = '8.5.13-1'; file = 'php-0123456789abcdef.zip'; size = 1; sha256 = 'a' * 64 },
		@{ order = 2; component = 'benchy'; to = '2.0.0'; file = 'benchy-0123456789abcdef.zip'; size = 1; sha256 = 'b' * 64 },
		@{ order = 3; component = 'client'; to = '0.15.1'; file = 'client-0123456789abcdef.zip'; size = 1; sha256 = 'c' * 64 })
	if ($Opt.Only) { $Steps = @($Steps | Where-Object { $Opt.Only -contains $_.component }) }
	$Plan = @{ schema = 'benchy-component-plan/1'; sequence = 7; generation = 1; index_sha256 = 'd' * 64; platform = 'windows-x64'; applier = 1; steps = $Steps }
	Write-Text (Join-Path $I 'update/plan.json') ($Plan | ConvertTo-Json -Depth 5)
	return $S
}
function Get-Snapshot([string]$I)
{
	# every file outside update\ with its content, in one string
	$Lines = foreach ($F in Get-ChildItem -LiteralPath $I -Recurse -Force -File | Sort-Object FullName)
	{
		$Rel = $F.FullName.Substring($I.Length)
		if ($Rel -like '/update/*') { continue }
		"${Rel}=$([IO.File]::ReadAllText($F.FullName))"
	}
	return ($Lines -join "`n")
}
function Invoke-Apply([string]$I, [string]$S, [int]$Wait = 2)
{
	$Plan = Read-ApplyPlan $I (Join-Path $I 'update/plan.json') $S
	Invoke-ApplyComponents $I $Plan $Wait
}
function Get-Code([scriptblock]$Do)
{
	try { & $Do | Out-Null; return 0 } catch { if ($_.Exception.Data.Contains('BenchyCode')) { return [int]$_.Exception.Data['BenchyCode'] } else { return "other: $($_.Exception.Message)" } }
}

# --- Child mode: a crash at a named point (a hard exit, no undo, nothing cleaned) ---
if ($Child -eq 'crash')
{
	function Test-CrashPoint([string]$Name) { if ($Name -eq $At) { [Environment]::Exit(99) } }
	$S = Join-Path $Root 'install/update/staging/7'
	Invoke-Apply (Join-Path $Root 'install') $S
	exit 0
}

$Script:Failed = 0
function Check([string]$Name, [scriptblock]$Test)
{
	try { $Why = & $Test; if ($Why) { Write-Output "FAIL ${Name}: $Why"; $Script:Failed++ } else { Write-Output "ok $Name" } }
	catch { Write-Output "FAIL ${Name}: $($_.Exception.Message)"; $Script:Failed++ }
}
$Work = Join-Path ([IO.Path]::GetTempPath()) ('applier-' + [guid]::NewGuid().ToString('N'))
$N = 0
function New-Case { $Script:N++; $B = Join-Path $Work "c$Script:N"; return (New-Install $B) }
function Read-File([string]$P) { if (Test-Path -LiteralPath $P) { [IO.File]::ReadAllText($P) } else { '<missing>' } }

Check 'apply: PHP, tree and client are swapped, the old ones kept, nothing else touched' {
	$I = New-Case; $S = Add-Staging $I; $Script:Relaunched = 0
	$Script:ProcFn = { }
	Invoke-Apply $I $S
	$W = @()
	if ((Read-File "$I/php/php.exe") -ne 'good-new') { $W += 'php' }
	if ((Read-File "$I/php.prev/php.exe") -ne 'good-old') { $W += 'php.prev' }
	if ((Read-File "$I/php/benchy-php.json") -notmatch '"version":\s*"8.5.13"' -or (Read-File "$I/php/benchy-php.json") -notmatch '"rebuild":\s*1') { $W += 'benchy-php.json' }
	if ((Read-File "$I/benchy.bat") -ne 'bat-new' -or (Read-File "$I/pts-core/phoronix-test-suite.php") -ne 'core-new') { $W += 'tree' }
	if ((Read-File "$I/pts-core/gone-in-new.txt") -ne '<missing>') { $W += 'old tree file still live' }
	if ((Read-File "$I/tree.prev/benchy.bat") -ne 'bat-old' -or (Read-File "$I/tree.prev/pts-core/gone-in-new.txt") -ne 'only old') { $W += 'tree.prev' }
	if ((Read-File "$I/client/BenchyClient.exe") -ne 'client-new' -or (Read-File "$I/client.prev/BenchyClient.exe") -ne 'client-old') { $W += 'client' }
	if ((Read-File "$I/engine/native/benchy-engine.exe") -ne 'engine' -or (Read-File "$I/state/keep.txt") -ne 'state' -or (Read-File "$I/cygwin-root.txt") -ne 'unmanaged') { $W += 'kept items touched' }
	if (Test-Path "$I/update/state.json") { $W += 'journal left' }
	if ((Test-Path "$I/php.new") -or (Test-Path "$I/client.new") -or (Test-Path "$I/tree.new")) { $W += '.new left' }
	if ($Script:PhpRuns.Count -lt 2) { $W += 'php not run before and after' }
	if ($Script:UninstallUpdated -lt 1) { $W += 'Apps & Features not updated' }
	if ($W) { 'wrong: ' + ($W -join ', ') }
}
Check 'apply twice (a second update): .prev holds the version before, the older one is gone' {
	$I = New-Case; $S = Add-Staging $I; Invoke-Apply $I $S
	$S2 = Join-Path $I 'update/staging/8'; Remove-Item -Recurse -Force "$I/update/staging"
	Write-Text "$S2/x/php/php.exe" 'good-newer'
	$Plan = @{ schema = 'benchy-component-plan/1'; sequence = 8; applier = 1; steps = @(@{ component = 'php'; to = '8.5.14'; file = 'php-0123456789abcdef.zip'; size = 1; sha256 = 'a' * 64 }) }
	Write-Text "$I/update/plan.json" ($Plan | ConvertTo-Json -Depth 5)
	Invoke-Apply $I $S2
	if ((Read-File "$I/php/php.exe") -ne 'good-newer' -or (Read-File "$I/php.prev/php.exe") -ne 'good-new') { 'php chain wrong' }
}
Check 'busy: a php.exe or benchy.bat window stops it with exit 3 and nothing changed' {
	$I = New-Case; $S = Add-Staging $I; $Before = Get-Snapshot $I
	$Script:ProcFn = { [pscustomobject]@{ Name = 'php.exe'; ProcessId = 42 } }
	$C = Get-Code { Invoke-Apply $I $S }
	$Script:ProcFn = $null
	if ($C -ne 3) { return "exit $C" }
	if ((Get-Snapshot $I) -ne $Before) { return 'the install changed' }
	if (Test-Path "$I/update/state.json") { 'journal left' }
}
Check 'a client-only plan is not blocked by a running php.exe' {
	$I = New-Case; $S = Add-Staging $I @{ Only = @('client') }
	$Script:ProcFn = { [pscustomobject]@{ Name = 'php.exe'; ProcessId = 42 } }
	$C = Get-Code { Invoke-Apply $I $S }
	$Script:ProcFn = $null
	if ($C -ne 0) { return "exit $C" }
	if ((Read-File "$I/client/BenchyClient.exe") -ne 'client-new') { 'client not swapped' }
}
Check 'a new PHP that does not run: exit 30, nothing changed, php.new gone' {
	$I = New-Case; $S = Add-Staging $I @{ BadPhp = $true }; $Before = Get-Snapshot $I
	$C = Get-Code { Invoke-Apply $I $S }
	if ($C -ne 30) { return "exit $C" }
	if ((Get-Snapshot $I) -ne $Before) { return 'the install changed' }
}
Check 'a PHP that runs in the temp check but not in place: everything is put back, client included' {
	$I = New-Case; $S = Add-Staging $I; $Before = Get-Snapshot $I
	$Real = ${function:Test-PhpRuns}
	$Script:Calls = 0
	function Test-PhpRuns([string]$PhpExe, [string]$IniPath, [string]$SmokeScript, $RequiredExtensions, [string]$ExpectedVersion)
	{
		$Script:Calls++
		[pscustomobject]@{ Ok = ($Script:Calls -eq 1); Message = 'in place it fails'; PhpVersion = ''; Extensions = @() }
	}
	$C = Get-Code { Invoke-Apply $I $S }
	Set-Item function:Test-PhpRuns $Real
	if ($C -ne 30) { return "exit $C" }
	if ((Get-Snapshot $I) -ne $Before) { return 'the install changed' }
	if (Test-Path "$I/update/state.json") { 'journal left' }
}
Check 'a tree archive carrying update\ (or any kept name) is refused before anything moves' {
	$I = New-Case; $S = Add-Staging $I @{ TreeHasUpdate = $true }; $Before = Get-Snapshot $I
	$C = Get-Code { Invoke-Apply $I $S }
	if ($C -ne 2) { return "exit $C" }
	if ((Get-Snapshot $I) -ne $Before) { 'the install changed' }
}
Check 'a tree whose version is not the planned one is refused' {
	$I = New-Case; $S = Add-Staging $I; Write-Text "$S/x/benchy/VERSION" '9.9.9'; $Before = Get-Snapshot $I
	if ((Get-Code { Invoke-Apply $I $S }) -ne 2 -or (Get-Snapshot $I) -ne $Before) { 'not refused cleanly' }
}
Check 'plan files: a name that is not a staged name, an unknown component, or a staging folder outside update\staging are refused' {
	$I = New-Case; $S = Add-Staging $I
	$P = Get-Content -Raw "$I/update/plan.json" | ConvertFrom-Json
	$P.steps[0].file = '..\..\evil.zip'; Write-Text "$I/update/plan.json" ($P | ConvertTo-Json -Depth 5)
	$C1 = Get-Code { Read-ApplyPlan $I "$I/update/plan.json" $S }
	$I2 = New-Case; $S2 = Add-Staging $I2
	$P = Get-Content -Raw "$I2/update/plan.json" | ConvertFrom-Json
	$P.steps[0].component = 'cygwin'; Write-Text "$I2/update/plan.json" ($P | ConvertTo-Json -Depth 5)
	$C2 = Get-Code { Read-ApplyPlan $I2 "$I2/update/plan.json" $S2 }
	$I3 = New-Case; $null = Add-Staging $I3
	$C3 = Get-Code { Read-ApplyPlan $I3 "$I3/update/plan.json" "$Work/elsewhere" }
	if ($C1 -ne 2 -or $C2 -ne 2 -or $C3 -ne 2) { "codes $C1 $C2 $C3" }
}
Check 'client handshake: the quit request is made, the applier waits, then swaps and starts the client again' {
	$I = New-Case; $S = Add-Staging $I @{ Only = @('client') }; $Script:Relaunched = 0
	$Script:Polls = 0
	$Script:ProcFn = { $Script:Polls++; if (-not (Test-Path "$I/update/client-quit.request")) { [pscustomobject]@{ Name = 'BenchyClient.exe'; ProcessId = 1 } } elseif ($Script:Polls -lt 4) { [pscustomobject]@{ Name = 'BenchyClient.exe'; ProcessId = 1 } } }
	# the fake client deletes the request when it starts quitting (13.9) and writes its relaunch note
	$Orig = ${function:Request-ClientQuit}
	function Request-ClientQuit([string]$Install, [int]$WaitSeconds)
	{
		$R = & $Orig $Install $WaitSeconds
		return $R
	}
	$Script:ProcFn = { $Script:Polls++; if ($Script:Polls -lt 4) { [pscustomobject]@{ Name = 'BenchyClient.exe'; ProcessId = 1 } } }
	$C = Get-Code { Invoke-Apply $I $S 20 }
	Set-Item function:Request-ClientQuit $Orig
	$Script:ProcFn = $null
	if ($C -ne 0) { return "exit $C" }
	if ($Script:Polls -lt 4) { return "did not wait ($Script:Polls polls)" }
	if (-not $Script:ClientQuit) { return 'no relaunch flagged' }
	if ((Read-File "$I/client/BenchyClient.exe") -ne 'client-new') { 'client not swapped' }
}
Check 'client handshake: a client that never quits: exit 4, nothing changed, both request files deleted by the caller' {
	$I = New-Case; $S = Add-Staging $I @{ Only = @('client') }; $Before = Get-Snapshot $I
	Write-Text "$I/update/client-relaunch.json" '{"args": []}'
	$Script:ProcFn = { [pscustomobject]@{ Name = 'BenchyClient.exe'; ProcessId = 1 } }
	$Script:ClientQuit = $false
	$C = Get-Code { Invoke-Apply $I $S 2 }
	$Script:ProcFn = $null
	if ($C -ne 4) { return "exit $C" }
	if ((Get-Snapshot $I) -ne $Before) { return 'the install changed' }
	Clear-ClientRequests $I
	if ((Test-Path "$I/update/client-quit.request") -or (Test-Path "$I/update/client-relaunch.json")) { 'a request file is left' }
}
Check 'relaunch arguments: the client''s own list, --tray when missing or damaged, nothing else accepted' {
	$I = New-Case
	$A0 = @(Get-ClientRelaunchArgs $I)
	Write-Text "$I/update/client-relaunch.json" '{"args": []}'; $A1 = @(Get-ClientRelaunchArgs $I)
	Write-Text "$I/update/client-relaunch.json" '{"args": ["--tray"]}'; $A2 = @(Get-ClientRelaunchArgs $I)
	Write-Text "$I/update/client-relaunch.json" '{"args": ["calc.exe & x"]}'; $A3 = @(Get-ClientRelaunchArgs $I)
	Write-Text "$I/update/client-relaunch.json" 'not json'; $A4 = @(Get-ClientRelaunchArgs $I)
	if ($A0.Count -ne 1 -or $A0[0] -ne '--tray' -or $A1.Count -ne 0 -or $A2[0] -ne '--tray' -or $A3[0] -ne '--tray' -or $A4[0] -ne '--tray') { 'wrong' }
}
Check 'a rename that fails half way: everything put back, journal removed, exit 40' {
	$I = New-Case; $S = Add-Staging $I; $Before = Get-Snapshot $I
	$Real = ${function:Move-ApplyItem}; $Script:Moves = 0
	function Move-ApplyItem([string]$From, [string]$To)
	{
		if ($To -like '*/tree.prev/*') { $Script:Moves++; if ($Script:Moves -eq 2) { throw 'in use' } }
		& $Real $From $To
	}
	$C = Get-Code { Invoke-Apply $I $S }
	Set-Item function:Move-ApplyItem $Real
	if ($C -ne 40) { return "exit $C" }
	$Snap = Get-Snapshot $I
	if ($Snap -ne $Before) { return 'not as it was' }
	if (Test-Path "$I/update/state.json") { 'journal left' }
}

# --- Crash at every journaled point, then recover ---
$Pwsh = (Get-Process -Id $PID).Path
$Points = @('journal') + @(1..13 | ForEach-Object { "move-$_" }) + @('swapped')
foreach ($Point in $Points)
{
	Check "crash at $Point, then -Recover: the old state is back" {
		$B = Join-Path $Work "crash-$Point"; $I = New-Install $B; $null = Add-Staging $I
		$Before = Get-Snapshot $I
		$null = & $Pwsh -NoProfile -File $PSCommandPath -UpdateDir $UpdateDir -Child crash -Root $B -At $Point 2>&1
		$Code = $LASTEXITCODE
		if ($Code -eq 0) { return $null }   # the point does not exist in this plan (fewer moves): it completed
		if ($Code -ne 99) { return "child exit $Code" }
		if (-not (Test-Path "$I/update/state.json")) { return 'no journal after the crash' }
		$R = Get-Code { Invoke-ApplyRecover $I }
		if ($R -ne 0) { return "recover exit $R" }
		if ((Get-Snapshot $I) -ne $Before) { return 'not the old state' }
		if (Test-Path "$I/update/state.json") { return 'journal left' }
		if ((Test-Path "$I/php.new") -or (Test-Path "$I/client.new") -or (Test-Path "$I/tree.new")) { '.new left' }
	}
}
Check 'recover twice is harmless; recover with no journal does nothing' {
	$B = Join-Path $Work 'r2'; $I = New-Install $B; $null = Add-Staging $I; $Before = Get-Snapshot $I
	$null = & $Pwsh -NoProfile -File $PSCommandPath -UpdateDir $UpdateDir -Child crash -Root $B -At 'move-5' 2>&1
	# a crash inside recover itself: the same journal is undone again, before any leftover was cleaned
	$Ops = @(Read-ApplyJournal $I)
	$null = Undo-ApplyOps $Ops
	$null = Undo-ApplyOps $Ops
	$A = Invoke-ApplyRecover $I
	$Bc = Invoke-ApplyRecover $I
	if ($A -ne 0 -or $Bc -ne 0 -or (Get-Snapshot $I) -ne $Before) { 'not idempotent' }
}
Check 'recover refuses a journal that names a path outside the install, and moves nothing' {
	$B = Join-Path $Work 'r3'; $I = New-Install $B; $Before = Get-Snapshot $I
	$Outside = Join-Path $B 'outside.txt'; Write-Text $Outside 'mine'
	$J = @{ schema = 'benchy-apply-journal/1'; op = 'apply'; ops = @(@{ op = 'move'; from = '../outside.txt'; to = 'benchy.bat' }) }
	Write-Text "$I/update/state.json" ($J | ConvertTo-Json -Depth 5)
	$C = Get-Code { Invoke-ApplyRecover $I }
	if ($C -ne 41) { return "exit $C" }
	if ((Read-File $Outside) -ne 'mine' -or (Get-Snapshot $I) -ne $Before) { 'something moved' }
}
Check 'the apply lock: while one run holds it, a second apply and a recover both refuse (exit 42) and move nothing' {
	$I = New-Case; $S = Add-Staging $I; $Before = Get-Snapshot $I
	$Held = Open-ApplyLock $I
	try
	{
		$A = Open-ApplyLock $I
		if ($A) { $A.Dispose(); return 'a second holder got the lock' }
		Write-Text "$I/update/state.json" '{"schema":"benchy-apply-journal/1","op":"apply","ops":[]}'
		$R = Invoke-ApplyRecover $I
		if ($R -ne 42) { return "recover exit $R" }
	}
	finally { $Held.Dispose() }
	Remove-Item "$I/update/state.json"
	if ((Get-Snapshot $I) -ne $Before) { 'something moved' }
}
Check 'rollback: PHP, tree and client go back, the newer ones become .prev, and a rollback can be rolled back' {
	$I = New-Case; $S = Add-Staging $I; Invoke-Apply $I $S; $Script:ProcFn = $null
	$AfterApply = Get-Snapshot $I
	Invoke-ApplyRollback $I ''
	$W = @()
	if ((Read-File "$I/php/php.exe") -ne 'good-old' -or (Read-File "$I/php.prev/php.exe") -ne 'good-new') { $W += 'php' }
	if ((Read-File "$I/benchy.bat") -ne 'bat-old' -or (Read-File "$I/tree.prev/benchy.bat") -ne 'bat-new') { $W += 'tree' }
	if ((Read-File "$I/pts-core/gone-in-new.txt") -ne 'only old') { $W += 'old tree file missing' }
	if ((Read-File "$I/client/BenchyClient.exe") -ne 'client-old') { $W += 'client' }
	if ((Read-File "$I/engine/native/benchy-engine.exe") -ne 'engine' -or (Read-File "$I/state/keep.txt") -ne 'state') { $W += 'kept items' }
	if (Test-Path "$I/update/state.json") { $W += 'journal left' }
	Invoke-ApplyRollback $I ''
	if ((Get-Snapshot $I) -ne $AfterApply) { $W += 'rollback of the rollback differs' }
	if ($W) { 'wrong: ' + ($W -join ', ') }
}
Check 'rollback of one component leaves the others; with nothing to go back to: exit 2; while php runs: exit 3' {
	$I = New-Case; $S = Add-Staging $I; Invoke-Apply $I $S
	Invoke-ApplyRollback $I 'client'
	if ((Read-File "$I/client/BenchyClient.exe") -ne 'client-old' -or (Read-File "$I/php/php.exe") -ne 'good-new' -or (Read-File "$I/benchy.bat") -ne 'bat-new') { return 'not only the client' }
	$None = New-Case
	$C1 = Get-Code { Invoke-ApplyRollback $None '' }
	$Script:ProcFn = { [pscustomobject]@{ Name = 'cmd.exe'; ProcessId = 5 } }
	$C2 = Get-Code { Invoke-ApplyRollback $I 'php' }
	$Script:ProcFn = $null
	if ($C1 -ne 2 -or $C2 -ne 3) { "codes $C1 $C2" }
}
Check 'rollback to a PHP that does not run is undone (the newer one stays)' {
	$I = New-Case; $S = Add-Staging $I; Invoke-Apply $I $S; Write-Text "$I/php.prev/php.exe" 'bad-old'
	$Before = Get-Snapshot $I
	$C = Get-Code { Invoke-ApplyRollback $I 'php' }
	if ($C -ne 30 -or (Get-Snapshot $I) -ne $Before) { "exit $C or changed" }
}
Check 'main: -Recover and -Rollback go through the same entry point benchy.bat and the engine use' {
	$I = New-Case
	$Out = & $Pwsh -NoProfile -File (Join-Path $UpdateDir 'apply-update.ps1') -InstallDir "$I/." -Recover 2>&1
	if ($LASTEXITCODE -ne 0) { return "recover with no journal exited $LASTEXITCODE`: $Out" }
	$null = & $Pwsh -NoProfile -File (Join-Path $UpdateDir 'apply-update.ps1') -InstallDir "$I/." -Rollback 2>&1
	if ($LASTEXITCODE -ne 2) { "rollback with nothing to go back to exited $LASTEXITCODE" }
}
Remove-Item -Recurse -Force $Work -ErrorAction SilentlyContinue
if ($Script:Failed -gt 0) { exit 1 }
