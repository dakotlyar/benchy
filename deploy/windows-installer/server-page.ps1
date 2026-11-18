<#
	Used only by the GUI installer (benchy.nsi), for its "Connect to a Benchy server" page and /SERVER=. NSIS has no JSON reader and
	cannot run a program hidden without waiting for it, so this does the two things the page cannot do itself. No install logic and
	no pairing belong here: it finds servers, and it saves a v1 string through Benchy's own setter.

	server-page.ps1 -Mode Discover           -Engine <benchy-engine.exe> -IniPath <file>
	server-page.ps1 -Mode DiscoverBackground -Engine <benchy-engine.exe> -IniPath <file>
	server-page.ps1 -Mode SetPhoromatic      -InstallDir <folder> -PhpExe <php.exe> -Server <[https://]ip:port/accountid>

	Discover runs `benchy-engine.exe --json discover --timeout 4s` hidden, gives it 6 seconds in all (then ends it), and writes
	<file> for NSIS's ReadINIStr: [discover] status=done|timeout|error and count, then [server1], [server2] ... with name, address,
	source and probe, for every server the engine found whose probe said ok (policy first, then the others in the order found).
	The file appears when the search is over (written beside it and renamed), so the installer can wait for it to exist.
	DiscoverBackground starts the same thing in a second hidden PowerShell and returns at once.
	Nothing found here is trusted or stored by this script: the installer saves only what the person picked.
#>

param(
	[Parameter(Mandatory)][ValidateSet('Discover', 'DiscoverBackground', 'SetPhoromatic')][string]$Mode,
	[string]$Engine,
	[string]$IniPath,
	[string]$InstallDir,
	[string]$PhpExe,
	[string]$Server
)

$ErrorActionPreference = 'Stop'

function Format-IniValue($Value)
{
	# One line per value; ReadINIStr would stop at a line break
	return (([string]$Value) -replace '\s+', ' ').Trim()
}

function Write-DiscoverIni([string]$Status, $Servers)
{
	$Lines = @('[discover]', "status=$Status", "count=$(@($Servers).Count)")
	$n = 0
	foreach ($S in @($Servers))
	{
		$n++
		$Lines += "[server$n]"
		$Lines += "name=$(Format-IniValue $S.name)"
		$Lines += "address=$(Format-IniValue $S.address)"
		$Lines += "source=$(Format-IniValue $S.source)"
		$Lines += "probe=$(Format-IniValue $S.probe)"
	}
	# UTF-16 LE with a BOM, which GetPrivateProfileStringW reads as Unicode; renamed into place when complete
	$Tmp = "$IniPath.tmp"
	[IO.File]::WriteAllLines($Tmp, [string[]]$Lines, [Text.Encoding]::Unicode)
	Move-Item -LiteralPath $Tmp -Destination $IniPath -Force
}

function Invoke-Discover
{
	$Servers = @()
	$Status = 'error'
	$Out = "$IniPath.out"
	try
	{
		Remove-Item -LiteralPath $IniPath, $Out -Force -ErrorAction SilentlyContinue
		if (-not (Test-Path -LiteralPath $Engine -PathType Leaf)) { throw 'no engine' }
		$P = Start-Process -FilePath $Engine -ArgumentList @('--json', 'discover', '--timeout', '4s') -WindowStyle Hidden -PassThru -RedirectStandardOutput $Out -RedirectStandardError "$IniPath.err"
		if ($P.WaitForExit(6000)) { $Status = 'done' }
		else
		{
			$Status = 'timeout'
			try { $P.Kill() } catch { }
		}
		foreach ($Line in @(Get-Content -LiteralPath $Out -ErrorAction SilentlyContinue))
		{
			try { $E = $Line | ConvertFrom-Json } catch { continue }
			if ($E.type -eq 'server_found' -and $E.probe -eq 'ok' -and $E.address -match '^https://[A-Za-z0-9.-]+(:[0-9]{1,5})?$') { $Servers += $E }
		}
		# One entry per address (the same server found by two ways, say the hint and mDNS, counts once), the policy server first:
		# the installer preselects it
		$Servers = @($Servers | Sort-Object { if ($_.source -eq 'policy') { 0 } else { 1 } } | Group-Object -Property address | ForEach-Object { $_.Group[0] })
	}
	catch { }
	finally { Remove-Item -LiteralPath $Out, "$IniPath.err" -Force -ErrorAction SilentlyContinue }
	Write-DiscoverIni $Status $Servers
}

try
{
	switch ($Mode)
	{
		'Discover' { Invoke-Discover }
		'DiscoverBackground'
		{
			$Self = $MyInvocation.MyCommand.Path
			$Ps = Join-Path $PSHOME 'powershell.exe'
			Start-Process -FilePath $Ps -WindowStyle Hidden -ArgumentList @('-NoProfile', '-NonInteractive', '-InputFormat', 'None', '-ExecutionPolicy', 'Bypass', '-File', "`"$Self`"", '-Mode', 'Discover', '-Engine', "`"$Engine`"", '-IniPath', "`"$IniPath`"")
		}
		'SetPhoromatic'
		{
			# Benchy's own setter (the one install.ps1 uses), from the lib of the tree that was just installed
			if ($Server -notmatch '^(https://)?[A-Za-z0-9.-]+:[0-9]{1,5}/[A-Za-z0-9]+$') { exit 2 }
			. (Join-Path $InstallDir 'pts-core\static\update\benchy-windows.ps1')
			Set-BenchyPhoromaticServer $InstallDir $PhpExe $Server
		}
	}
}
catch
{
	exit 1
}
exit 0
