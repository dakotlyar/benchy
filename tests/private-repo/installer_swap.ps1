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
Check 'Remove-BenchyTree with an empty keep list, an empty array or $null removes everything and reports nothing' {
	foreach ($Case in @('empty', 'null', 'omitted'))
	{
		$I = New-Install "ue-$Case"
		$Failed = switch ($Case) { 'empty' { @(Remove-BenchyTree $I @()) } 'null' { @(Remove-BenchyTree $I $null) } default { @(Remove-BenchyTree $I) } }
		if ($Failed.Count) { return "$Case failed: $($Failed -join ',')" }
		if (@(Get-ChildItem -Force $I).Count) { return "$Case left something behind" }
	}
}
Check 'a keep list holding a $null next to a real entry still works' {
	$I = New-Install uf; $Data = Join-Path $I 'BenchyClient'; [void](New-Item -ItemType Directory -Force $Data); Set-Content -NoNewline -Path (Join-Path $Data 's') -Value 's'
	$Failed = @(Remove-BenchyTree $I @($null, $Data, ''))
	if ($Failed.Count) { "failed: $($Failed -join ',')" } elseif (-not (Test-Path (Join-Path $Data 's'))) { 'kept data gone' }
}
Check 'a failed first install (nothing was moved) does not say the earlier version was put back' {
	$I = Join-Path $Root 'fi'; [void](New-Item -ItemType Directory -Force $I)
	function Rename-BenchyDirRetry([string]$Path, [string]$NewName)
	{
		if ($Path -like '*engine.new' -and $NewName -eq 'engine') { throw 'in use (test)' }
		Rename-Item -LiteralPath $Path -NewName $NewName -ErrorAction Stop
	}
	$M = Throws40 { Install-BenchySwappedDirs $I @((Get-BenchyClientSwap $ClientZip), (Get-BenchyEngineSwap $EngineZip)) }
	Remove-Item function:Rename-BenchyDirRetry
	if (-not $M) { 'no error' }
	elseif ($M -like '*put back*') { "says put back: $M" }
	elseif ($M -notlike '*Nothing of the client and engine was installed*') { "message: $M" }
}
Check '-SkipPhp leaves php/php.exe out of the in-use check' {
	$I = New-Install pb; [void](New-Item -ItemType Directory -Force (Join-Path $I 'php'))
	$Exe = Join-Path $I 'php/php.exe'; Set-Content -NoNewline -Path $Exe -Value 'x'
	$S = [IO.File]::Open($Exe, 'Open', 'ReadWrite', 'None')
	try { $Held = @(Get-BenchyFilesInUse $I -SkipPhp) } finally { $S.Dispose() }
	if ($Held.Count) { "reported: $($Held -join ',')" }
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

# ---- the keep-newer rule (engine self-update, U1): Test-BenchyKeepNewerEngine, with the exe's version read from its text instead of a Windows resource
function Get-BenchyExeVersion([string]$Exe) { return (Get-Content -LiteralPath $Exe -Raw).Trim() }
function New-Kept([string]$Name, [string]$ExeVersion, $Installed)
{
	$I = New-Install $Name
	Set-Content -NoNewline -Path (Join-Path $I 'engine/native/benchy-engine.exe') -Value $ExeVersion
	$H = Join-Path $Root "home-$Name"
	[void](New-Item -ItemType Directory -Force (Join-Path $H 'engine-updates'))
	if ($Installed) { Set-Content -Path (Join-Path $H 'engine-updates/state.json') -Value ($Installed | ConvertTo-Json -Depth 4) }
	return @($I, $H)
}
function Sha([string]$Path) { return (Get-FileHash -LiteralPath $Path -Algorithm SHA256).Hash.ToLowerInvariant() }
Check 'keep-newer: a newer engine that an update installed is kept' {
	$I, $H = New-Kept k1 '0.12.0' $null
	@{ installed = @{ version = '0.12.0'; sha256 = (Sha "$I/engine/native/benchy-engine.exe") } } | ConvertTo-Json -Depth 4 | Set-Content (Join-Path $H 'engine-updates/state.json')
	$K = Test-BenchyKeepNewerEngine $I '0.11.0' $H
	if ($K -ne '0.12.0') { "got '$K'" }
}
Check 'keep-newer: the same or an older engine is not kept (the pinned one goes in)' {
	foreach ($V in '0.11.0', '0.10.1')
	{
		$I, $H = New-Kept "k2$V" $V $null
		@{ installed = @{ version = $V; sha256 = (Sha "$I/engine/native/benchy-engine.exe") } } | ConvertTo-Json -Depth 4 | Set-Content (Join-Path $H 'engine-updates/state.json')
		if (Test-BenchyKeepNewerEngine $I '0.11.0' $H) { "kept $V" }
	}
}
Check 'keep-newer: a newer engine nothing recorded (placed by hand) is replaced' {
	$I, $H = New-Kept k3 '0.12.0' $null
	if (Test-BenchyKeepNewerEngine $I '0.11.0' $H) { 'kept with no record' }
}
Check 'keep-newer: a record for other bytes, or another version, or a broken file, is not enough' {
	$I, $H = New-Kept k4 '0.12.0' $null
	$St = Join-Path $H 'engine-updates/state.json'
	@{ installed = @{ version = '0.12.0'; sha256 = ('a' * 64) } } | ConvertTo-Json -Depth 4 | Set-Content $St
	if (Test-BenchyKeepNewerEngine $I '0.11.0' $H) { 'kept with another hash' }
	@{ installed = @{ version = '0.12.1'; sha256 = (Sha "$I/engine/native/benchy-engine.exe") } } | ConvertTo-Json -Depth 4 | Set-Content $St
	if (Test-BenchyKeepNewerEngine $I '0.11.0' $H) { 'kept with another version' }
	Set-Content -NoNewline -Path $St -Value '{'
	if (Test-BenchyKeepNewerEngine $I '0.11.0' $H) { 'kept with a broken state file' }
}
Check 'keep-newer: no installed engine, or one with no version: nothing kept' {
	$I = Join-Path $Root 'k5'
	if (Test-BenchyKeepNewerEngine $I '0.11.0' (Join-Path $Root 'nohome')) { 'kept a missing engine' }
}

# ---- keep-newer for the client and the tree (runtime component updates)
Check 'keep-newer: a newer client is kept, the same or older one is not' {
	foreach ($Case in @(@('0.14.1', $true), @('0.14.0', $false), @('0.13.0', $false)))
	{
		$I = New-Install "c$($Case[0])"
		Set-Content -NoNewline -Path (Join-Path $I 'client/BenchyClient.exe') -Value $Case[0]
		$K = Test-BenchyKeepNewerClient $I '0.14.0'
		if ([bool]$K -ne $Case[1]) { "client $($Case[0]): got '$K'" }
	}
}
Check 'keep-newer: no installed client, or one with no readable version: nothing kept' {
	if (Test-BenchyKeepNewerClient (Join-Path $Root 'nothing') '0.14.0') { 'kept a missing client' }
	$I = New-Install cbad
	Set-Content -NoNewline -Path (Join-Path $I 'client/BenchyClient.exe') -Value 'garbage'
	if (Test-BenchyKeepNewerClient $I '0.14.0') { 'kept an unreadable version' }
}
# Get-BenchyVersion's regex does not survive the backslash-to-slash rewrite the functions go through for Linux: same read, written for it
function Get-BenchyVersion([string]$TreeDir)
{
	$P = Join-Path $TreeDir 'pts-core/pts-core.php'
	if (-not (Test-Path -LiteralPath $P)) { return $null }
	$M = Select-String -LiteralPath $P -Pattern "PTS_VERSION', '([^']+)'" | Select-Object -First 1
	if ($M) { return $M.Matches[0].Groups[1].Value }
	return $null
}
function New-Tree([string]$Name, [string]$Version)
{
	$D = Join-Path $Root $Name
	[void](New-Item -ItemType Directory -Force (Join-Path $D 'pts-core'))
	Set-Content -Path (Join-Path $D 'pts-core/pts-core.php') -Value "pts_define('PTS_VERSION', '$Version');"
	return $D
}
Check 'tree: an installed Benchy newer than the installer is reported; same, older, missing or unreadable is not' {
	$Src = New-Tree src '0.13.0'
	if ((Test-BenchyInstalledTreeNewer (New-Tree t1 '0.13.4') $Src) -ne '0.13.4') { 'newer not reported' }
	if ((Test-BenchyInstalledTreeNewer (New-Tree t2 '0.14.0') $Src) -ne '0.14.0') { 'newer minor not reported' }
	foreach ($V in '0.13.0', '0.12.1') { if (Test-BenchyInstalledTreeNewer (New-Tree "t3$V" $V) $Src) { "reported $V" } }
	if (Test-BenchyInstalledTreeNewer (Join-Path $Root 'none') $Src) { 'reported a missing tree' }
	if (Test-BenchyInstalledTreeNewer (New-Tree t4 'weird') $Src) { 'reported an unreadable version' }
}

# ---- the pinned CA bundle (runtime-pins.json windows-x64.cacert): the tree's copy matches its pin, and the php.ini the installer writes points curl and OpenSSL at it
Check 'cacert: the bundle in the tree matches its pin (size and sha256)' {
	$P = Get-BenchyCacertPin '/tree'
	if (-not $P) { return 'no pin' }
	if (-not (Test-PinnedArtifact (Get-BenchyCacertPath '/tree') $P.artifact)) { 'does not match' }
	$Bad = Join-Path $Root 'bad.pem'
	Set-Content -Path $Bad -Value 'tampered'
	if (Test-PinnedArtifact $Bad $P.artifact) { 'a tampered bundle passed' }
}
Check 'cacert: the php.ini written by the installer sets curl.cainfo and openssl.cafile to the tree bundle, and keeps the pinned lines' {
	$Z = New-Zip 'phpini' 'php.exe' 'x'
	$Dest = Join-Path $Root 'inst/php'
	Install-PhpFromZip $Z $Dest ([pscustomobject]@{ ini = @('extension=curl') }) $null
	$Ini = Get-Content -Raw (Join-Path $Dest 'php.ini')
	$Want = (Join-Path $Root 'inst/pts-core/static/update/cacert.pem')
	if ($Ini -notmatch [regex]::Escape("curl.cainfo = `"$Want`"")) { "no curl.cainfo: $Ini" }
	if ($Ini -notmatch [regex]::Escape("openssl.cafile = `"$Want`"")) { "no openssl.cafile: $Ini" }
	if ($Ini -notmatch 'extension=curl') { 'pinned ini line lost' }
}
Remove-Item -Recurse -Force $Root
if ($Script:Failed) { exit 1 }
