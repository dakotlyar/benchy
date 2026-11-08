# Run by installer.sh inside a PowerShell container (Linux): the file-in-use check and the client/engine swap from
# pts-core/static/update/benchy-windows.ps1, with the backslashes of its path literals turned into slashes (done by the caller;
# only those functions are loaded). The Windows-only behavior (a running exe, a directory rename under it) is Zeb's K-T1.
# Prints "ok <name>" or "FAIL <name>: why"; exits 1 on any FAIL.
param([Parameter(Mandatory)][string]$Functions)
$ErrorActionPreference = 'Stop'
. $Functions
$Script:Failed = 0
function Check([string]$Name, [scriptblock]$Test)
{
	try { $Why = & $Test; if ($Why) { Write-Output "FAIL ${Name}: $Why"; $Script:Failed++ } else { Write-Output "ok $Name" } }
	catch { Write-Output "FAIL ${Name}: $($_.Exception.Message)"; $Script:Failed++ }
}
$Root = Join-Path ([IO.Path]::GetTempPath()) ("swap-" + [guid]::NewGuid().ToString('N'))
function New-Install([string]$Name)
{
	$Dir = Join-Path $Root $Name
	foreach ($Pair in @(@('client', 'BenchyClient.exe'), @('engine/native', 'benchy-engine.exe')))
	{
		[void](New-Item -ItemType Directory -Force (Join-Path $Dir $Pair[0]))
		Set-Content -NoNewline -Path (Join-Path $Dir "$($Pair[0])/$($Pair[1])") -Value 'old'
	}
	return $Dir
}
function New-Zip([string]$Name, [string]$Entry, [string]$Text)
{
	$Src = Join-Path $Root "src-$Name"
	$Parent = Split-Path -Parent (Join-Path $Src $Entry)
	[void](New-Item -ItemType Directory -Force $Parent)
	Set-Content -NoNewline -Path (Join-Path $Src $Entry) -Value $Text
	$Zip = Join-Path $Root "$Name.zip"
	Compress-Archive -Path (Join-Path $Src '*') -DestinationPath $Zip
	return $Zip
}
function Get-Text([string]$Path) { return (Get-Content -Raw -Path $Path) }
function Throws40([scriptblock]$Do)
{
	try { & $Do } catch { return $_.Exception.Data['BenchyCode'] -eq 40 ? $_.Exception.Message : "exit code $($_.Exception.Data['BenchyCode']): $($_.Exception.Message)" }
	return $null
}
$ClientZip = New-Zip 'client' 'BenchyClient.exe' 'new'
$EngineZip = New-Zip 'engine' 'benchy-engine.exe' 'new'
$BadEngineZip = New-Zip 'badengine' 'sub/benchy-engine.exe' 'new'

# --- the file-in-use check
Check 'nothing held: nothing reported' { $I = New-Install a; if (@(Get-BenchyFilesInUse $I).Count) { 'reported' } }
Check 'no install at all: nothing reported' { if (@(Get-BenchyFilesInUse (Join-Path $Root nothing)).Count) { 'reported' } }
Check 'a held engine exe is found (no process is looked at)' {
	$I = New-Install b; $Exe = Join-Path $I 'engine/native/benchy-engine.exe'
	$S = [IO.File]::Open($Exe, 'Open', 'ReadWrite', 'None')
	try { $Held = @(Get-BenchyFilesInUse $I) } finally { $S.Dispose() }
	if ($Held.Count -ne 1 -or $Held[0] -ne $Exe) { "got: $($Held -join ',')" }
}
Check 'a held client exe is found, and the engine one is not' {
	$I = New-Install c; $Exe = Join-Path $I 'client/BenchyClient.exe'
	$S = [IO.File]::Open($Exe, 'Open', 'ReadWrite', 'None')
	try { $Held = @(Get-BenchyFilesInUse $I) } finally { $S.Dispose() }
	if ($Held.Count -ne 1 -or $Held[0] -ne $Exe) { "got: $($Held -join ',')" }
}
Check 'the .new and .prev folders are looked at too' {
	$I = New-Install d; [void](New-Item -ItemType Directory -Force (Join-Path $I 'engine.prev/native'))
	$Exe = Join-Path $I 'engine.prev/native/benchy-engine.exe'; Set-Content -NoNewline -Path $Exe -Value 'x'
	$S = [IO.File]::Open($Exe, 'Open', 'ReadWrite', 'None')
	try { $Held = @(Get-BenchyFilesInUse $I) } finally { $S.Dispose() }
	if ($Held.Count -ne 1) { "got: $($Held -join ',')" }
}
Check 'once the file is let go it is not reported' { $I = New-Install e; $S = [IO.File]::Open((Join-Path $I 'client/BenchyClient.exe'), 'Open', 'ReadWrite', 'None'); $S.Dispose(); if (@(Get-BenchyFilesInUse $I).Count) { 'reported' } }
Check 'the managed PHP is looked at too: a held php/php.exe is found' {
	$I = New-Install pa; [void](New-Item -ItemType Directory -Force (Join-Path $I 'php'))
	$Exe = Join-Path $I 'php/php.exe'; Set-Content -NoNewline -Path $Exe -Value 'x'
	$S = [IO.File]::Open($Exe, 'Open', 'ReadWrite', 'None')
	try { $Held = @(Get-BenchyFilesInUse $I) } finally { $S.Dispose() }
	if ($Held.Count -ne 1 -or $Held[0] -ne $Exe) { "got: $($Held -join ',')" }
}
Check 'a file held for good is given up on after about three seconds, not 0.4' {
	$I = New-Install ra; $Exe = Join-Path $I 'client/BenchyClient.exe'
	$S = [IO.File]::Open($Exe, 'Open', 'ReadWrite', 'None')
	try { $T = Get-Date; $Held = @(Get-BenchyFilesInUse $I); $Took = ((Get-Date) - $T).TotalSeconds } finally { $S.Dispose() }
	if ($Held.Count -ne 1) { "not reported: $($Held -join ',')" }
	elseif ($Took -lt 2.4 -or $Took -gt 6) { "took $Took s" }
}
Check 'a file a scanner lets go of after 1.5 seconds is not reported' {
	$I = New-Install rb; $Exe = Join-Path $I 'engine/native/benchy-engine.exe'; $Marker = Join-Path $Root 'rb-held'
	# Another process holds it (a scanner would), for 1.5 s from the moment it has it
	$Job = Start-Job { param($Exe, $Marker) $S = [IO.File]::Open($Exe, 'Open', 'ReadWrite', 'None'); Set-Content $Marker 'held'; Start-Sleep -Milliseconds 1500; $S.Dispose() } -ArgumentList $Exe, $Marker
	try
	{
		for ($N = 0; $N -lt 100 -and -not (Test-Path $Marker); $N++) { Start-Sleep -Milliseconds 100 }
		if (-not (Test-Path $Marker)) { 'the helper process never took the file' }
		else
		{
			$Held = @(Get-BenchyFilesInUse $I)
			if ($Held.Count -ne 0) { "reported: $($Held -join ',')" }
		}
	}
	finally { Remove-Job $Job -Force }
}
Check 'the in-use text says it may be administrator, and where to quit' {
	$T = Get-BenchyInUseAdvice @('C:\x\engine.exe') 'the installer'
	foreach ($Want in @('administrator', 'tray icon', 'Quit and stop the agent', 'close it', 'Nothing was changed')) { if ($T -notlike "*$Want*") { "missing '$Want' in: $T" } }
}
Check 'the running advice: client -> tray; engine only -> Quit and stop the agent, no tray; neither -> close windows' {
	$C = Get-BenchyRunningAdvice @([pscustomobject]@{ Name = 'BenchyClient.exe' }) 'the installer'
	$E = Get-BenchyRunningAdvice @([pscustomobject]@{ Name = 'benchy-engine.exe' }) 'the installer'
	$P = Get-BenchyRunningAdvice @([pscustomobject]@{ Name = 'php.exe' }) 'the installer'
	if ($C -notlike '*tray icon*') { "client: $C" }
	elseif ($E -like '*tray*' -or $E -notlike "*Open Benchy*Quit and stop the agent*") { "engine: $E" }
	elseif ($P -notlike 'Close every Benchy window*') { "php: $P" }
}

# --- the swap
Check 'both folders swap in; what they replaced is kept as .prev' {
	$I = New-Install s1
	Install-BenchySwappedDirs $I @((Get-BenchyClientSwap $ClientZip), (Get-BenchyEngineSwap $EngineZip)) | Out-Null
	if ((Get-Text "$I/client/BenchyClient.exe") -ne 'new') { 'client not new' }
	elseif ((Get-Text "$I/engine/native/benchy-engine.exe") -ne 'new') { 'engine not new' }
	elseif ((Get-Text "$I/client.prev/BenchyClient.exe") -ne 'old' -or (Get-Text "$I/engine.prev/native/benchy-engine.exe") -ne 'old') { 'prev not old' }
	elseif ((Test-Path "$I/client.new") -or (Test-Path "$I/engine.new")) { '.new left' }
}
Check 'a first install (nothing live yet) works' {
	$I = Join-Path $Root s2; [void](New-Item -ItemType Directory -Force $I)
	Install-BenchySwappedDirs $I @((Get-BenchyClientSwap $ClientZip), (Get-BenchyEngineSwap $EngineZip)) | Out-Null
	if ((Get-Text "$I/engine/native/benchy-engine.exe") -ne 'new' -or (Get-Text "$I/client/BenchyClient.exe") -ne 'new') { 'not installed' }
}
Check 'an engine zip with no exe at its root: exit 40, and the client is not swapped either' {
	$I = New-Install s3
	$M = Throws40 { Install-BenchySwappedDirs $I @((Get-BenchyClientSwap $ClientZip), (Get-BenchyEngineSwap $BadEngineZip)) }
	if (-not $M) { 'no error' }
	elseif ((Get-Text "$I/client/BenchyClient.exe") -ne 'old') { 'the client was swapped' }
	elseif ((Test-Path "$I/client.new") -or (Test-Path "$I/engine.new") -or (Test-Path "$I/client.prev")) { 'leftovers' }
}
Check 'a failed engine rename puts the client (and the engine) back' {
	$I = New-Install s4
	function Rename-BenchyDirRetry([string]$Path, [string]$NewName)
	{
		if ($Path -like '*engine.new' -and $NewName -eq 'engine') { throw 'in use (test)' }
		Rename-Item -LiteralPath $Path -NewName $NewName -ErrorAction Stop
	}
	$M = Throws40 { Install-BenchySwappedDirs $I @((Get-BenchyClientSwap $ClientZip), (Get-BenchyEngineSwap $EngineZip)) }
	Remove-Item function:Rename-BenchyDirRetry
	if (-not $M) { 'no error' }
	elseif ((Get-Text "$I/client/BenchyClient.exe") -ne 'old') { 'the client is not the old one' }
	elseif ((Get-Text "$I/engine/native/benchy-engine.exe") -ne 'old') { 'the engine is not the old one' }
	elseif ((Test-Path "$I/client.new") -or (Test-Path "$I/engine.new")) { '.new left' }
}
Check 'a failed move of the live engine to .prev puts the client back too' {
	$I = New-Install s5
	function Rename-BenchyDirRetry([string]$Path, [string]$NewName)
	{
		if ($NewName -eq 'engine.prev') { throw 'in use (test)' }
		Rename-Item -LiteralPath $Path -NewName $NewName -ErrorAction Stop
	}
	$M = Throws40 { Install-BenchySwappedDirs $I @((Get-BenchyClientSwap $ClientZip), (Get-BenchyEngineSwap $EngineZip)) }
	Remove-Item function:Rename-BenchyDirRetry
	if (-not $M) { 'no error' }
	elseif ($M -notlike '*in use*client and engine were left as they were*') { "message: $M" }
	elseif ($M -like '*Nothing was changed*') { "still says nothing was changed: $M" }
	elseif ((Get-Text "$I/client/BenchyClient.exe") -ne 'old' -or (Get-Text "$I/engine/native/benchy-engine.exe") -ne 'old') { 'live folders changed' }
	elseif ((Test-Path "$I/client.new") -or (Test-Path "$I/client.prev")) { 'leftovers' }
}
Check 'a double failure (the move fails, then putting the client back fails) does not claim the earlier version is back' {
	$I = New-Install s5b
	function Rename-BenchyDirRetry([string]$Path, [string]$NewName)
	{
		if ($NewName -eq 'engine.prev') { throw 'in use (test)' }
		if ($NewName -eq 'client') { throw 'also in use (test)' }
		Rename-Item -LiteralPath $Path -NewName $NewName -ErrorAction Stop
	}
	$M = Throws40 { Install-BenchySwappedDirs $I @((Get-BenchyClientSwap $ClientZip), (Get-BenchyEngineSwap $EngineZip)) }
	Remove-Item function:Rename-BenchyDirRetry
	if (-not $M) { 'no error' }
	elseif ($M -like '*left as they were*' -or $M -like '*Nothing was changed*') { "claims it is all back: $M" }
	elseif ($M -notlike '*did not finish*client.prev*') { "message: $M" }
}
Check 'a failed placement with the undo failing too does not say the earlier version was put back' {
	$I = New-Install s5c
	function Rename-BenchyDirRetry([string]$Path, [string]$NewName)
	{
		if ($Path -like '*engine.new' -and $NewName -eq 'engine') { throw 'in use (test)' }
		if ($Path -like '*client.prev' -and $NewName -eq 'client') { throw 'also in use (test)' }
		Rename-Item -LiteralPath $Path -NewName $NewName -ErrorAction Stop
	}
	$M = Throws40 { Install-BenchySwappedDirs $I @((Get-BenchyClientSwap $ClientZip), (Get-BenchyEngineSwap $EngineZip)) }
	Remove-Item function:Rename-BenchyDirRetry
	if (-not $M) { 'no error' }
	elseif ($M -like '*was put back*') { "claims it is back: $M" }
	elseif ($M -notlike '*did not finish*') { "message: $M" }
}
Check 'the undo text: all well passes the given sentence through, a failure lists what is left' {
	if ((Get-BenchyUndoText @() 'All good.') -ne 'All good.') { 'not passed through' }
	elseif ((Get-BenchyUndoText @('x is still in y') 'All good.') -notlike '*did not finish*x is still in y*') { 'failure not listed' }
}
Check 'Remove-BenchyTree with the BenchyClient folder in its keep list leaves that folder and its files, and removes the rest' {
	$I = New-Install ub; $Data = Join-Path $I 'BenchyClient'
	[void](New-Item -ItemType Directory -Force (Join-Path $Data 'cache')); Set-Content -NoNewline -Path (Join-Path $Data 'cache/x.bin') -Value 'x'; Set-Content -NoNewline -Path (Join-Path $Data 'settings.json') -Value 's'
	$Failed = @(Remove-BenchyTree $I @($Data))
	if ($Failed.Count) { "failed: $($Failed -join ',')" }
	elseif (-not ((Test-Path (Join-Path $Data 'settings.json')) -and (Test-Path (Join-Path $Data 'cache/x.bin')))) { 'the client data is gone' }
	elseif ((Test-Path (Join-Path $I 'client')) -or (Test-Path (Join-Path $I 'engine'))) { 'the rest is still there' }
}
Check 'an earlier run that died between the renames (no live exe, a good .prev) is put right first' {
	$I = New-Install s6
	Rename-Item "$I/engine" 'engine.prev'
	Install-BenchySwappedDirs $I @((Get-BenchyEngineSwap $EngineZip)) | Out-Null
	if ((Get-Text "$I/engine/native/benchy-engine.exe") -ne 'new' -or (Get-Text "$I/engine.prev/native/benchy-engine.exe") -ne 'old') { 'not restored then swapped' }
}
Check 'only the client: the engine is left alone' {
	$I = New-Install s7
	Install-BenchySwappedDirs $I @((Get-BenchyClientSwap $ClientZip)) | Out-Null
	if ((Get-Text "$I/client/BenchyClient.exe") -ne 'new' -or (Get-Text "$I/engine/native/benchy-engine.exe") -ne 'old' -or (Test-Path "$I/engine.prev")) { 'wrong' }
}
Remove-Item -Recurse -Force $Root
if ($Script:Failed) { exit 1 }
