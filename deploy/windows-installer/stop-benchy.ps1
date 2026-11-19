<#
	Used only by the GUI installer (benchy.nsi), and by the uninstaller after it. NSIS cannot read JSON, talk to a named pipe or wait for
	a process politely, so this does the Benchy-running part of the wizard pages and writes what it found to an INI file NSIS reads with
	ReadINIStr. No install logic belongs here.

	stop-benchy.ps1 -Mode Info -IniPath <ini> -EarlyDir <folder> -Engine <installed benchy-engine.exe>
	                [-NewEngineZip <zip> -NewEngineDir <folder>]
	stop-benchy.ps1 -Mode Stop -IniPath <ini> -EarlyDir <folder> -Engine <installed benchy-engine.exe> [-Wait 120]

	-EarlyDir is the folder the installer unpacked first, for its pages: install.ps1, pts-core\static\update\benchy-windows.ps1 and
	runtime-pins.json, laid out as in the tree.

	Info changes nothing of Benchy. It runs `install.ps1 -CheckRunning` (what runs from the install), checks whether the Visual C++ Runtime
	is needed ([vc] needed, from the lib and the pin), unpacks benchy-engine.exe from the
	installer's own engine zip to -NewEngineDir (the installer's page asks that engine, not the installed one), and runs `agent status`
	on it (the installed engine when there is no zip). [running] is what -CheckRunning found, [agent] is the agent's answer: state,
	agent_run, job_title, mode, server, system_title, protected_version (the unattended agent's engine, when it has one), conflict.

	Stop is the 4.3 rule of the design: a client that knows `quit-for-update` is asked to quit over its single-instance pipe and given
	-Wait seconds. It answers BUSY (and changes nothing, and does not quit) when a local run or an upload is in progress: result busy, and
	nothing is ended. Only no answer at all (no pipe, or none in 30 s: a busy 0.12 client can be slow to answer) is an older client, which is left alone when a local run is going
	(state busy: result busy), and otherwise the
	agent is cancelled through the engine (`cancel <agent_run>`, 90 s, the finished tests upload first) and the client process ended. An agent
	with no client is cancelled the same way. A benchy.bat console or a PHP of this install is only named (result console). [stop]:
	result none|ok|busy|console|failed, message, was_client, was_window (the client had a window, not only the tray), was_agent, job_title.
	The unattended agent is not touched: it runs from Program Files.

	The pipe line is `BENCHY-CLIENT-QUIT-FOR-UPDATE 1`, answered `OK` and optionally a JSON object ({was_window_open, agent_was_running,
	job_title}) on the same line. Exit code 0 whenever the INI was written.
#>

param(
	[Parameter(Mandatory)][ValidateSet('Info', 'Stop')][string]$Mode,
	[Parameter(Mandatory)][string]$IniPath,
	[string]$EarlyDir,
	[string]$Engine,
	[string]$NewEngineZip,
	[string]$NewEngineDir,
	[int]$Wait = 120
)

$ErrorActionPreference = 'Stop'

$InstallDir = Join-Path $env:LOCALAPPDATA 'Benchy'
$Sections = [ordered]@{}

function Format-IniValue($Value)
{
	return (([string]$Value) -replace '\s+', ' ').Trim()
}

function Set-IniValue([string]$Section, [string]$Key, $Value)
{
	if (-not $Sections.Contains($Section)) { $Sections[$Section] = [ordered]@{} }
	$Sections[$Section][$Key] = Format-IniValue $Value
}

function Write-Ini
{
	$Lines = @()
	foreach ($Name in $Sections.Keys)
	{
		$Lines += "[$Name]"
		foreach ($Key in $Sections[$Name].Keys) { $Lines += "$Key=$($Sections[$Name][$Key])" }
	}
	# UTF-16 LE with a BOM, which GetPrivateProfileStringW reads as Unicode; renamed into place when complete
	$Tmp = "$IniPath.tmp"
	[IO.File]::WriteAllLines($Tmp, [string[]]$Lines, [Text.Encoding]::Unicode)
	Move-Item -LiteralPath $Tmp -Destination $IniPath -Force
}

function Invoke-Engine([string]$Exe, [string]$Arguments, [int]$TimeoutMs)
{
	# Runs the engine to completion (or $TimeoutMs, then ends it) with stdin empty. Returns ExitCode and the stdout lines, or $null.
	if (-not $Exe -or -not (Test-Path -LiteralPath $Exe -PathType Leaf)) { return $null }
	try
	{
		$Info = New-Object System.Diagnostics.ProcessStartInfo $Exe, $Arguments
		$Info.UseShellExecute = $false
		$Info.CreateNoWindow = $true
		$Info.RedirectStandardInput = $true
		$Info.RedirectStandardOutput = $true
		$Info.RedirectStandardError = $true
		$Process = [System.Diagnostics.Process]::Start($Info)
		$Process.StandardInput.Close()
		$Out = $Process.StandardOutput.ReadToEndAsync()
		$Err = $Process.StandardError.ReadToEndAsync()
		if (-not $Process.WaitForExit($TimeoutMs))
		{
			try { $Process.Kill() } catch { }
			return $null
		}
		[void]$Err.Result
		return [pscustomobject]@{ ExitCode = $Process.ExitCode; Lines = @($Out.Result -split "`r?`n" | Where-Object { $_ }) }
	}
	catch { return $null }
}

function Get-AgentStatus([string]$Exe)
{
	# The last agent_state event of `agent status`, or $null
	$R = Invoke-Engine $Exe '--json agent status' 15000
	if (-not $R) { return $null }
	$State = $null
	foreach ($Line in $R.Lines)
	{
		try { $E = $Line | ConvertFrom-Json } catch { continue }
		if ($E.type -eq 'agent_state') { $State = $E }
	}
	return $State
}

function Set-AgentValues($State)
{
	if (-not $State) { Set-IniValue 'agent' 'ok' 0; return }
	Set-IniValue 'agent' 'ok' 1
	Set-IniValue 'agent' 'state' $State.state
	Set-IniValue 'agent' 'agent_run' $State.agent_run
	Set-IniValue 'agent' 'job_title' $(if ($State.job) { $State.job.title } else { '' })
	Set-IniValue 'agent' 'mode' $State.mode
	Set-IniValue 'agent' 'server' $State.server
	Set-IniValue 'agent' 'system_title' $(if ($State.system) { $State.system.title } else { '' })
	Set-IniValue 'agent' 'protected_version' $(if ($State.protected_engine) { $State.protected_engine.version } else { '' })
	Set-IniValue 'agent' 'conflict' $State.conflict
}

function Expand-NewEngine
{
	# benchy-engine.exe from the installer's own engine zip, to run its `agent status`
	if (-not $NewEngineZip -or -not $NewEngineDir -or -not (Test-Path -LiteralPath $NewEngineZip -PathType Leaf)) { return $null }
	try
	{
		Add-Type -AssemblyName System.IO.Compression.FileSystem
		New-Item -ItemType Directory -Path $NewEngineDir -Force | Out-Null
		$Zip = [IO.Compression.ZipFile]::OpenRead($NewEngineZip)
		try
		{
			$Entry = $Zip.Entries | Where-Object { $_.FullName -eq 'benchy-engine.exe' } | Select-Object -First 1
			if (-not $Entry) { return $null }
			$Target = Join-Path $NewEngineDir 'benchy-engine.exe'
			[IO.Compression.ZipFileExtensions]::ExtractToFile($Entry, $Target, $true)
			return $Target
		}
		finally { $Zip.Dispose() }
	}
	catch { return $null }
}

function Send-QuitForUpdate
{
	# Returns {Kind: ok|busy|other, Text} (Text is what followed OK or BUSY, '' when nothing did), or $null when nothing answered: no
	# pipe, no reply within 30 s, or the pipe closed with no reply (no client, or one older than 0.12). A client 0.12 or later says BUSY
	# when a local run or an upload is in progress; it has then changed nothing and does not quit.
	$Sha = [Security.Cryptography.SHA1]::Create()
	$Hash = ($Sha.ComputeHash([Text.Encoding]::UTF8.GetBytes([string]$env:USERNAME)) | ForEach-Object { $_.ToString('x2') }) -join ''
	$Pipe = New-Object System.IO.Pipes.NamedPipeClientStream '.', "benchy-client-$($Hash.Substring(0, 16))", ([IO.Pipes.PipeDirection]::InOut), ([IO.Pipes.PipeOptions]::Asynchronous)
	try
	{
		$Pipe.Connect(3000)
		$Bytes = [Text.Encoding]::ASCII.GetBytes("BENCHY-CLIENT-QUIT-FOR-UPDATE 1`n")
		$Pipe.Write($Bytes, 0, $Bytes.Length)
		$Pipe.Flush()
		$Buffer = New-Object byte[] 2048
		$Read = $Pipe.ReadAsync($Buffer, 0, $Buffer.Length)
		if (-not $Read.Wait(30000)) { return $null }
		$Text = [Text.Encoding]::UTF8.GetString($Buffer, 0, $Read.Result).Trim()
		if ($Text -eq '') { return $null }
		if ($Text.StartsWith('OK')) { return [pscustomobject]@{ Kind = 'ok'; Text = $Text.Substring(2).Trim() } }
		if ($Text.StartsWith('BUSY')) { return [pscustomobject]@{ Kind = 'busy'; Text = $Text.Substring(4).Trim() } }
		return [pscustomobject]@{ Kind = 'other'; Text = $Text }
	}
	catch { return $null }
	finally { $Pipe.Dispose() }
}

function Get-Running
{
	@(Get-BenchyProcesses $InstallDir)
}

function Wait-NoneOf([scriptblock]$Pick, [int]$Seconds)
{
	# True once nothing $Pick selects from what is running is left, within $Seconds
	$End = (Get-Date).AddSeconds($Seconds)
	do
	{
		if (@(Get-Running | Where-Object $Pick).Count -eq 0) { return $true }
		Start-Sleep -Milliseconds 500
	} while ((Get-Date) -lt $End)
	return (@(Get-Running | Where-Object $Pick).Count -eq 0)
}

function Stop-Agent($State)
{
	# cancel <agent_run>: the child is cancelled, finished tests upload, the agent ends. Waits for its processes.
	if (-not $State -or -not $State.agent_run) { return }
	[void](Invoke-Engine $Engine "--json cancel $($State.agent_run)" 90000)
	[void](Wait-NoneOf { $_.Name -eq 'benchy-engine.exe' -and ([string]$_.CommandLine) -match '(^|\s)agent\s+run(\s|$)' } 90)
}

function Invoke-Stop
{
	. (Join-Path $EarlyDir 'pts-core\static\update\benchy-windows.ps1')
	$Procs = Get-Running
	$Clients = @($Procs | Where-Object { $_.Name -eq 'BenchyClient.exe' })
	$State = Get-AgentStatus $Engine
	$AgentUp = [bool]($State -and $State.agent_run -and $State.state -ne 'stopped')
	$Job = if ($State -and $State.job) { [string]$State.job.title } else { '' }
	$WasWindow = [bool](@($Clients | Where-Object { ([string]$_.CommandLine) -notmatch '(^|\s)--tray(\s|$)' }).Count -gt 0)
	Set-IniValue 'stop' 'was_client' $(if ($Clients.Count -gt 0) { 1 } else { 0 })
	Set-IniValue 'stop' 'was_agent' $(if ($AgentUp) { 1 } else { 0 })
	Set-IniValue 'stop' 'job_title' $Job
	Set-IniValue 'stop' 'result' 'none'
	Set-IniValue 'stop' 'message' ''

	if ($Clients.Count -gt 0)
	{
		$Answer = Send-QuitForUpdate
		if ($null -ne $Answer -and $Answer.Kind -eq 'busy')
		{
			# A client 0.12 or later with a local run or an upload going: it changed nothing and stays. Nothing is ended here.
			Set-IniValue 'stop' 'result' 'busy'
			Set-IniValue 'stop' 'message' 'Benchy is running a test or sending results to the server. Wait for it to finish, or stop the test in Benchy, then try again.'
			Set-IniValue 'stop' 'was_window' $(if ($WasWindow) { 1 } else { 0 })
			return
		}
		elseif ($null -ne $Answer -and $Answer.Kind -eq 'other')
		{
			# Something answered that this script does not know: it is not killed on a guess
			Set-IniValue 'stop' 'result' 'failed'
			Set-IniValue 'stop' 'message' 'Benchy answered in a way this program does not know. Quit it from its tray icon (right-click it, then Quit), then try again.'
			Set-IniValue 'stop' 'was_window' $(if ($WasWindow) { 1 } else { 0 })
			return
		}
		elseif ($null -ne $Answer)
		{
			# A client 0.12 or later: it cancels its own run and stops its own agent, then exits
			if ($Answer.Text.StartsWith('{'))
			{
				try { $A = $Answer.Text | ConvertFrom-Json; $WasWindow = [bool]$A.was_window_open; if ($A.job_title) { Set-IniValue 'stop' 'job_title' $A.job_title } } catch { }
			}
			if (-not (Wait-NoneOf { $_.Name -eq 'BenchyClient.exe' } $Wait))
			{
				Set-IniValue 'stop' 'result' 'failed'
				Set-IniValue 'stop' 'message' "Benchy did not close within $Wait seconds. Quit it from its tray icon (right-click it, then Quit), then try again."
				Set-IniValue 'stop' 'was_window' $(if ($WasWindow) { 1 } else { 0 })
				return
			}
		}
		else
		{
			# Older than 0.12: it can't be asked. A local run is the person's to finish or stop.
			if ($State -and $State.state -eq 'busy')
			{
				Set-IniValue 'stop' 'result' 'busy'
				Set-IniValue 'stop' 'message' 'A test is running in Benchy. Finish or stop it, then try again.'
				Set-IniValue 'stop' 'was_window' $(if ($WasWindow) { 1 } else { 0 })
				return
			}
			if ($AgentUp) { Stop-Agent $State }
			# An idle client holds no unsaved state, and its job object only holds the engine children it started
			foreach ($C in $Clients) { try { Stop-Process -Id $C.ProcessId -Force -ErrorAction Stop } catch { } }
			[void](Wait-NoneOf { $_.Name -eq 'BenchyClient.exe' } 10)
		}
		Set-IniValue 'stop' 'result' 'ok'
	}
	elseif ($AgentUp)
	{
		# "Quit, keep running": the agent with no client
		Stop-Agent $State
		Set-IniValue 'stop' 'result' 'ok'
	}
	Set-IniValue 'stop' 'was_window' $(if ($WasWindow) { 1 } else { 0 })

	# An agent that was cancelled finishes its cancel and its upload after the client is gone: its engine processes get up to 90 s
	[void](Wait-NoneOf { $_.Name -eq 'benchy-engine.exe' } 90)
	# What is left: a console or a PHP is only named, a client or engine that would not end is a failure
	$Left = Get-Running
	$Console = @($Left | Where-Object { $_.Name -in @('cmd.exe', 'php.exe') })
	$Other = @($Left | Where-Object { $_.Name -in @('BenchyClient.exe', 'benchy-engine.exe') })
	if ($Other.Count -gt 0)
	{
		Set-IniValue 'stop' 'result' 'failed'
		Set-IniValue 'stop' 'message' "Benchy could not be closed ($(Format-BenchyProcesses $Other)). If it runs as administrator, quit it from there, then try again."
	}
	elseif ($Console.Count -gt 0)
	{
		Set-IniValue 'stop' 'result' 'console'
		Set-IniValue 'stop' 'message' "Close the Benchy command prompt window (and anything running Benchy's PHP: $(Format-BenchyProcesses $Console)), then try again."
	}
}

try
{
	switch ($Mode)
	{
		'Info'
		{
			$Json = "$IniPath.running.json"
			Remove-Item -LiteralPath $Json -Force -ErrorAction SilentlyContinue
			$Set = $false
			$InstallScript = Join-Path $EarlyDir 'install.ps1'
			if (Test-Path -LiteralPath $InstallScript -PathType Leaf)
			{
				# The script's own exit stays inside it when it is run like this
				& $InstallScript -CheckRunning -ResultFile $Json
				if (Test-Path -LiteralPath $Json -PathType Leaf)
				{
					$R = [IO.File]::ReadAllText($Json) | ConvertFrom-Json
					Set-IniValue 'running' 'ok' 1
					Set-IniValue 'running' 'client' $(if ($R.client) { 1 } else { 0 })
					Set-IniValue 'running' 'client_tray' $(if ($R.client_tray) { 1 } else { 0 })
					Set-IniValue 'running' 'agent' $(if ($R.agent) { 1 } else { 0 })
					Set-IniValue 'running' 'run' $(if ($R.run) { 1 } else { 0 })
					Set-IniValue 'running' 'console' $(if ($R.console) { 1 } else { 0 })
					Set-IniValue 'running' 'php' $(if ($R.php) { 1 } else { 0 })
					Set-IniValue 'running' 'text' $R.text
					$Set = $true
				}
				Remove-Item -LiteralPath $Json -Force -ErrorAction SilentlyContinue
			}
			if (-not $Set) { Set-IniValue 'running' 'ok' 0 }
			# Does this machine need the Visual C++ Runtime? Only then the installer's button carries the shield
			$VcNeeded = 0
			try
			{
				. (Join-Path $EarlyDir 'pts-core\static\update\benchy-windows.ps1')
				$Pin = Get-BenchyPin $EarlyDir
				if (-not (Test-VcRuntime ([string]$Pin.vcredist_min))) { $VcNeeded = 1 }
			}
			catch { }
			Set-IniValue 'vc' 'needed' $VcNeeded
			$New = Expand-NewEngine
			Set-IniValue 'new' 'engine' $(if ($New) { 1 } else { 0 })
			Set-AgentValues (Get-AgentStatus $(if ($New) { $New } else { $Engine }))
		}
		'Stop' { Invoke-Stop }
	}
	Write-Ini
}
catch
{
	if ($Mode -eq 'Stop')
	{
		Set-IniValue 'stop' 'result' 'failed'
		Set-IniValue 'stop' 'message' "Benchy could not be checked or closed: $($_.Exception.Message)"
		try { Write-Ini } catch { exit 1 }
		exit 0
	}
	exit 1
}
exit 0
