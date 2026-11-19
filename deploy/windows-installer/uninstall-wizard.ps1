<#
	Used only by the GUI uninstaller (benchy.nsi, "Uninstall Benchy.exe" = uninstall.exe). NSIS cannot read JSON or follow an engine's
	event stream, so this does the machine parts of the wizard and writes what it found to files NSIS reads. No removal logic belongs
	here: it only orders the engine's `agent reset`, uninstall.ps1 and stop-benchy.ps1 and reports what they said.

	uninstall-wizard.ps1 -Mode Inventory -WorkDir <folder> -Engine <benchy-engine.exe> [-Server <host>] [-Title <system title>]
	uninstall-wizard.ps1 -Mode Run       -WorkDir <folder> -Engine <benchy-engine.exe> [-KeepResults 0|1] [-KeepTests 0|1] [-LogFile <path>]

	-WorkDir is the uninstaller's own temp folder: early\uninstall.ps1 (with early\pts-core\static\update\benchy-windows.ps1 next to it),
	early\install.ps1, stop-benchy.ps1, and where this script writes inv.ini, plan.json, progress.txt, stop.ini and result.ini. -Engine is the
	engine the uninstaller holds open (the one that matches this install), or the installed one, or empty.

	Inventory changes nothing. It runs `uninstall.ps1 -Inventory` with that engine and writes inv.ini (the rows of the first page, built from
	what is really there, the two keep boxes' texts, whether Windows will be asked) and plan.json (the step rows and which engine step
	belongs to which row; Run reads it).

	Run does the removal in the order of the design (4.4): 1 stop Benchy (stop-benchy.ps1 -Mode Stop), 2 `agent reset --all --uninstall
	--keep-data <list> --at-reboot` from the held engine (the one UAC prompt; a decline changes nothing), 3 `uninstall.ps1 -Quiet -FromGui
	-AtReboot <keep switches>`. progress.txt gets one line per change, "<row> start|done|fail". The exit code is the uninstaller's:
	0 removed, 3 Benchy is running and could not be stopped, 5 Windows did not give permission (nothing changed), 3010 removed except
	leftovers queued for the next restart, 40 not everything removed and some leftovers are not queued, 50 anything else. result.ini
	has [result] code, message, reason (the engine's permission reason), [left] and [kept] lists and the [still] flag.
#>

param(
	[Parameter(Mandatory)][ValidateSet('Inventory', 'Run')][string]$Mode,
	[Parameter(Mandatory)][string]$WorkDir,
	[string]$Engine,
	[string]$Server,
	[string]$Title,
	[int]$KeepResults = 1,
	[int]$KeepTests = 0,
	[string]$LogFile
)

$ErrorActionPreference = 'Stop'

$EarlyDir = Join-Path $WorkDir 'early'
$InvIni = Join-Path $WorkDir 'inv.ini'
$PlanPath = Join-Path $WorkDir 'plan.json'
$Progress = Join-Path $WorkDir 'progress.txt'
$ResultIni = Join-Path $WorkDir 'result.ini'
$PowerShellExe = Join-Path $PSHOME 'powershell.exe'

function Format-IniValue($Value)
{
	return (([string]$Value) -replace '\s+', ' ').Trim()
}

function Write-IniFile([string]$Path, $Sections)
{
	# UTF-16 LE with a BOM, which GetPrivateProfileStringW reads as Unicode; renamed into place when complete
	$Lines = @()
	foreach ($Name in $Sections.Keys)
	{
		$Lines += "[$Name]"
		foreach ($Key in $Sections[$Name].Keys) { $Lines += "$Key=$(Format-IniValue $Sections[$Name][$Key])" }
	}
	[IO.File]::WriteAllLines("$Path.tmp", [string[]]$Lines, [Text.Encoding]::Unicode)
	Move-Item -LiteralPath "$Path.tmp" -Destination $Path -Force
}

function Read-IniFile([string]$Path)
{
	$Values = @{}
	if (-not (Test-Path -LiteralPath $Path -PathType Leaf)) { return $Values }
	$Section = ''
	foreach ($Line in [IO.File]::ReadAllLines($Path, [Text.Encoding]::Unicode))
	{
		if ($Line -match '^\[(.+)\]$') { $Section = $Matches[1]; continue }
		$At = $Line.IndexOf('=')
		if ($At -gt 0) { $Values["$Section.$($Line.Substring(0, $At))"] = $Line.Substring($At + 1) }
	}
	return $Values
}

function Write-Log([string]$Text)
{
	if (-not $LogFile) { return }
	try { [IO.File]::AppendAllText($LogFile, "$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss') $Text`r`n") } catch { }
}

function Add-ProgressLine([int]$Row, [string]$State)
{
	# The wizard opens the file to read it every 400 ms and NSIS lets no writer in meanwhile: try again a few times (install.ps1's
	# Write-InstallStep tries 6 times; 10 here, since a lost line costs more than a short wait). A line that cannot be written is only a row that does not move; it must never stop the removal, least of
	# all while the elevated reset is running, so nothing here throws.
	for ($Try = 1; $Try -le 10; $Try++)
	{
		try
		{
			[IO.File]::AppendAllText($Progress, "$Row $State`r`n", [Text.Encoding]::ASCII)
			return
		}
		catch { Start-Sleep -Milliseconds 60 }
	}
}

function Format-Size($Bytes)
{
	if ($null -eq $Bytes) { return 'a lot of data' }
	$B = [double]$Bytes
	if ($B -ge 1GB) { return ('{0:N1} GB' -f ($B / 1GB)) }
	if ($B -ge 1MB) { return ('{0:N0} MB' -f ($B / 1MB)) }
	if ($B -ge 1KB) { return ('{0:N0} KB' -f ($B / 1KB)) }
	return "$([long]$B) bytes"
}

function Test-Present($Item)
{
	# present null (not readable unelevated) counts as there (design EC-17); only a clear false is absent
	return ($Item -and $Item.present -ne $false)
}

function Invoke-Script([string]$Script, [string[]]$ScriptArgs)
{
	# A script of this uninstaller, in its own Windows PowerShell; its exit code. Output goes to the log.
	$All = @('-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-File', $Script) + $ScriptArgs
	$Info = New-Object Diagnostics.ProcessStartInfo $PowerShellExe
	$Quoted = foreach ($A in $All) { if ($A -match '[\s"]' -or $A -eq '') { '"' + ($A -replace '"', '\"') + '"' } else { $A } }
	$Info.Arguments = $Quoted -join ' '
	$Info.UseShellExecute = $false
	$Info.CreateNoWindow = $true
	$Info.RedirectStandardOutput = $true
	$Info.RedirectStandardError = $true
	$Process = [Diagnostics.Process]::Start($Info)
	$Out = $Process.StandardOutput.ReadToEndAsync()
	$Err = $Process.StandardError.ReadToEndAsync()
	$Process.WaitForExit()
	Write-Log ([string]$Out.Result).TrimEnd()
	if ($Err.Result) { Write-Log ([string]$Err.Result).TrimEnd() }
	return $Process.ExitCode
}

# --- The plan: step rows, built from the inventory ---------------------------------------------------------------------------------

function Get-Plan($Inv)
{
	$Items = @{}
	foreach ($Item in @($Inv.items) + @($Inv.engine_items)) { if ($Item) { $Items[[string]$Item.id] = $Item } }
	$HasUnattended = (Test-Present $Items['unattended_agent']) -or (Test-Present $Items['agent_task'])
	$HasHelpers = (Test-Present $Items['protected_home']) -or (Test-Present $Items['elevated_run']) -or (Test-Present $Items['machine_install'])
	$HasPolicy = (Test-Present $Items['policy']) -or (Test-Present $Items['policy_key'])
	$Rows = @('Stop the server agent and close Benchy')
	$Map = [ordered]@{}
	if ($HasUnattended) { $Rows += 'Undo the unattended setup and task'; $Map['unattended_agent'] = $Rows.Count; $Map['agent_task'] = $Rows.Count }
	if ($HasHelpers) { $Rows += 'Remove the protected and work folders'; foreach ($Id in 'protected_home', 'elevated_run', 'machine_install') { $Map[$Id] = $Rows.Count } }
	if ($HasPolicy) { $Rows += 'Remove the policy and registry keys'; foreach ($Id in 'policy', 'policy_key') { $Map[$Id] = $Rows.Count } }
	$Rows += 'Remove the app, engines and installed tests'
	return [pscustomobject]@{
		rows = $Rows
		map = $Map
		last = $Rows.Count
		unattended = $HasUnattended
		helpers = $HasHelpers
		policy = $HasPolicy
		machine_install = (Test-Present $Items['machine_install'])
		reset = [bool]$Inv.engine.reset
		items = $Items
	}
}

# --- Inventory ---------------------------------------------------------------------------------------------------------------

function Invoke-Inventory
{
	$Json = Join-Path $WorkDir 'inv.json'
	Remove-Item -LiteralPath $Json -Force -ErrorAction SilentlyContinue
	[void](Invoke-Script (Join-Path $EarlyDir 'uninstall.ps1') @('-Inventory', '-ResultFile', $Json, '-EnginePath', $Engine))
	$Inv = [IO.File]::ReadAllText($Json) | ConvertFrom-Json
	$Plan = Get-Plan $Inv
	$Items = $Plan.items
	$Machine = [bool]$Inv.machine
	$Ini = [ordered]@{}
	$Ini['inv'] = [ordered]@{ ok = 1; machine = $(if ($Machine) { 1 } else { 0 }); reset = $(if ($Plan.reset) { 1 } else { 0 }); note = $Inv.engine.note
		conflict = $Inv.conflict; machine_install = $(if ($Plan.machine_install) { 1 } else { 0 }) }

	# Rows of the first page: [text, tag]. Only what is really there.
	$Rows = @()
	$Rows += , @('Benchy app, tray and both engines', 'your account')
	$Pair = if ($Server) { " with $Server" } else { '' }
	if (Test-Present $Items['agent_pairing']) { $Rows += , @("Server agent and pairing$Pair", 'your account') }
	if ($Plan.unattended)
	{
		$Account = ''
		foreach ($Id in 'unattended_agent', 'agent_task') { if ($Items[$Id] -and $Items[$Id].account) { $Account = [string]$Items[$Id].account } }
		$For = if ($Account) { " ($Account)" } else { '' }
		$Rows += , @("Unattended agent$For, task and folders", 'all users')
	}
	if ((Test-Present $Items['elevated_run']) -or (Test-Present $Items['machine_install'])) { $Rows += , @('Elevated run and machine install helpers', 'all users') }
	if ($Plan.policy) { $Rows += , @('Benchy policy and its registry keys', 'all users') }
	$Rows += , @('Start menu, log on entry, server address', 'your account')
	$Ini['rows'] = [ordered]@{ count = $Rows.Count }
	for ($I = 0; $I -lt $Rows.Count; $I++)
	{
		$Ini['rows']["text$($I + 1)"] = $Rows[$I][0]
		$Ini['rows']["tag$($I + 1)"] = $Rows[$I][1]
	}

	# The two keep boxes: what is in them, summed over both engines' folders
	$Count = 0; $Bytes = [long]0; $Lots = $false; $Where = @()
	foreach ($Id in 'engine_results', 'results')
	{
		$It = $Items[$Id]
		if (-not $It -or $It.present -eq $false) { continue }
		if ($null -eq $It.count) { $Lots = $true } else { $Count += [int]$It.count }
		if ($null -eq $It.bytes) { $Lots = $true } else { $Bytes += [long]$It.bytes }
		$Where += @($It.paths)
	}
	foreach ($Id in 'engine_settings', 'settings', 'client_settings')
	{
		$It = $Items[$Id]
		if (-not $It -or $It.present -eq $false) { continue }
		if ($null -eq $It.bytes) { $Lots = $true } else { $Bytes += [long]$It.bytes }
		$Where += @($It.paths)
	}
	$ResultsText = if ($Lots) { 'a lot of results and settings' } elseif ($Count -eq 0 -and $Bytes -eq 0) { 'none found' } else { "$Count results, $(Format-Size $Bytes)" }
	$TestBytes = [long]0; $TestLots = $false; $TestFound = $false
	foreach ($Id in 'engine_tests', 'installed_tests')
	{
		$It = $Items[$Id]
		if (-not $It -or $It.present -eq $false) { continue }
		$TestFound = $true
		if ($null -eq $It.bytes) { $TestLots = $true } else { $TestBytes += [long]$It.bytes }
	}
	$TestsText = if (-not $TestFound) { 'none installed' } elseif ($TestLots) { 'a lot of test files' } else { "$(Format-Size $TestBytes) of downloaded test files" }
	$Ini['keep'] = [ordered]@{ results = $ResultsText; tests = $TestsText }
	$Ini['steps'] = [ordered]@{ count = $Plan.rows.Count; last = $Plan.last }
	for ($I = 0; $I -lt $Plan.rows.Count; $I++) { $Ini['steps']["label$($I + 1)"] = $Plan.rows[$I] }
	Write-IniFile $InvIni $Ini
	$Out = $Plan | Select-Object rows, map, last, unattended, helpers, policy, machine_install, reset
	[IO.File]::WriteAllText($PlanPath, ($Out | ConvertTo-Json -Depth 5), (New-Object Text.UTF8Encoding $false))
}

# --- Run ---------------------------------------------------------------------------------------------------------------------

function Invoke-EngineReset($Plan, [string[]]$KeepList)
{
	# Streams `agent reset` events into the step rows. Returns {Done, Error, Exit, TimedOut}.
	$Out = [pscustomobject]@{ Done = $null; Error = $null; Exit = -1; TimedOut = $false; Stderr = '' }
	$EngineArgs = @('--json', 'agent', 'reset', '--all', '--uninstall')
	if ($KeepList.Count -gt 0) { $EngineArgs += @('--keep-data', ($KeepList -join ',')) }
	$EngineArgs += '--at-reboot'
	$Info = New-Object Diagnostics.ProcessStartInfo $Engine, ($EngineArgs -join ' ')
	$Info.UseShellExecute = $false
	$Info.CreateNoWindow = $true
	$Info.RedirectStandardInput = $true
	$Info.RedirectStandardOutput = $true
	$Info.RedirectStandardError = $true
	$Process = [Diagnostics.Process]::Start($Info)
	$Process.StandardInput.Close()
	$ErrTask = $Process.StandardError.ReadToEndAsync()
	# The ids each row waits for: a row is done once all of them were reported (the unknown ones belong to the last row)
	$Expect = @{}
	foreach ($Id in $Plan.map.PSObject.Properties.Name) { $R = [int]$Plan.map.$Id; if (-not $Expect.ContainsKey($R)) { $Expect[$R] = New-Object System.Collections.Generic.HashSet[string] }; [void]$Expect[$R].Add($Id) }
	$State = @{}      # row -> 'start' | 'done' | 'fail'
	$End = (Get-Date).AddSeconds(900)
	$Line = $Process.StandardOutput.ReadLineAsync()
	while ($true)
	{
		if ($Line.Wait(500))
		{
			$Text = $Line.Result
			if ($null -eq $Text) { break }
			Write-Log $Text
			if ($Text.TrimStart().StartsWith('{'))
			{
				$E = $null
				try { $E = $Text | ConvertFrom-Json } catch { }
				if ($E -and $E.type -eq 'reset_step')
				{
					$Row = if ($Plan.map.PSObject.Properties[[string]$E.id]) { [int]$Plan.map.([string]$E.id) } else { [int]$Plan.last }
					if ($Row -ge 2 -and $Row -lt $Plan.last)
					{
						if (-not $State.ContainsKey($Row)) { $State[$Row] = 'start'; Add-ProgressLine $Row 'start' }
						if ($E.status -eq 'failed') { $State[$Row] = 'fail' }
						if ($Expect.ContainsKey($Row)) { [void]$Expect[$Row].Remove([string]$E.id) }
						if ($Expect.ContainsKey($Row) -and $Expect[$Row].Count -eq 0 -and $State[$Row] -ne 'fail') { $State[$Row] = 'done'; Add-ProgressLine $Row 'done' }
					}
				}
				elseif ($E -and $E.type -eq 'reset_done') { $Out.Done = $E }
				elseif ($E -and $E.type -eq 'error') { $Out.Error = $E }
			}
			$Line = $Process.StandardOutput.ReadLineAsync()
		}
		elseif ((Get-Date) -gt $End)
		{
			$Out.TimedOut = $true
			try { $Process.Kill() } catch { }
			break
		}
	}
	if (-not $Out.TimedOut) { $Process.WaitForExit() }
	$Out.Exit = if ($Out.TimedOut) { -1 } else { $Process.ExitCode }
	$Text = [string]$ErrTask.Result
	$Out.Stderr = if ($Text.Length -gt 500) { $Text.Substring(0, 500) } else { $Text }
	# Every row of the machine steps ends here: done, unless a step of it failed
	for ($Row = 2; $Row -lt $Plan.last; $Row++)
	{
		if (-not $State.ContainsKey($Row)) { Add-ProgressLine $Row 'start'; $State[$Row] = 'start' }
		if ($State[$Row] -eq 'fail') { Add-ProgressLine $Row 'fail' }
		elseif ($State[$Row] -ne 'done') { Add-ProgressLine $Row 'done' }
	}
	return $Out
}

function Read-JsonFile([string]$Path)
{
	if (-not (Test-Path -LiteralPath $Path -PathType Leaf)) { return $null }
	try { return [IO.File]::ReadAllText($Path) | ConvertFrom-Json } catch { return $null }
}

function Invoke-Run
{
	$Plan = Read-JsonFile $PlanPath
	if (-not $Plan) { throw 'The uninstaller has no plan (inventory first).' }
	$Code = 50
	$Message = ''
	$Reason = ''
	$LeftAll = @()
	$KeptAll = @()
	Remove-Item -LiteralPath $Progress -Force -ErrorAction SilentlyContinue
	try
	{
		# 1. Stop Benchy (the wizard said what it stops; the engine uploads what finished first)
		Add-ProgressLine 1 'start'
		$StopIni = Join-Path $WorkDir 'stop.ini'
		Remove-Item -LiteralPath $StopIni -Force -ErrorAction SilentlyContinue
		[void](Invoke-Script (Join-Path $WorkDir 'stop-benchy.ps1') @('-Mode', 'Stop', '-IniPath', $StopIni, '-EarlyDir', $EarlyDir, '-Engine', $Engine))
		$Stop = Read-IniFile $StopIni
		$StopResult = $Stop['stop.result']
		if (-not $StopResult -or $StopResult -in @('busy', 'console', 'failed'))
		{
			Add-ProgressLine 1 'fail'
			$Message = if ($Stop['stop.message']) { $Stop['stop.message'] } else { 'Benchy could not be checked or closed.' }
			$Code = 3
			return
		}
		Add-ProgressLine 1 'done'

		# What to keep: results and settings go together (the first box), installed tests are the second
		$KeepList = @()
		if ($KeepResults -eq 1) { $KeepList += @('results', 'settings') }
		if ($KeepTests -eq 1) { $KeepList += 'tests' }
		$Switches = @('-Quiet', '-AtReboot')
		if ($KeepList.Count -eq 0) { $Switches += '-RemoveUserData' }
		else
		{
			if ($KeepResults -eq 1) { $Switches += @('-KeepResults', '-KeepSettings') }
			if ($KeepTests -eq 1) { $Switches += '-KeepInstalledTests' }
		}

		# 2. The engine undoes what it set up, and removes what is machine-wide and its own data: the one UAC prompt
		$ResetRan = $false
		if ($Plan.reset -and $Engine)
		{
			Write-Log "benchy-engine agent reset --all --uninstall (keep: $($KeepList -join ','))"
			$Reset = Invoke-EngineReset $Plan $KeepList
			$ResetRan = $true
			if ($Reset.Done) { foreach ($L in @($Reset.Done.left)) { $LeftAll += [pscustomobject]@{ path = [string]$L.path; reason = [string]$L.reason; queued = [bool]$L.queued } } }
			elseif ($Reset.TimedOut) { $Message = 'The engine did not finish removing Benchy''s parts for all users in 15 minutes. Nothing more was removed; run the uninstaller again.'; return }
			elseif ($Reset.Error -and $Reset.Error.code -eq 'permission')
			{
				$Reason = [string]$Reset.Error.detail.reason
				$Message = 'Windows did not give permission to remove the parts of Benchy that are for all users (the prompt was declined, expired or could not be shown). Nothing has been removed yet.'
				$Code = 5
				return
			}
			elseif ($Reset.Error -and $Reset.Error.code -eq 'locked')
			{
				$Message = 'The Benchy agent is still running and did not stop in time. Close Benchy, wait for the running job to finish and run the uninstaller again.'
				$Code = 3
				return
			}
			else
			{
				$Why = if ($Reset.Error) { [string]$Reset.Error.message } else { "the engine exited with code $($Reset.Exit). $($Reset.Stderr)" }
				$Message = "The engine could not remove Benchy's parts for all users: $Why"
				return
			}
		}
		else
		{
			Write-Log 'No engine that can reset; uninstall.ps1 removes the rest and says what may remain.'
			for ($Row = 2; $Row -lt $Plan.last; $Row++) { Add-ProgressLine $Row 'start'; Add-ProgressLine $Row 'done' }
		}

		# 3. Everything else: the app, the engines, the shortcut and entries, and the user data as asked
		Add-ProgressLine $Plan.last 'start'
		$PsJson = Join-Path $WorkDir 'uninstall-result.json'
		Remove-Item -LiteralPath $PsJson -Force -ErrorAction SilentlyContinue
		$PsArgs = @($Switches)
		if ($ResetRan) { $PsArgs += '-FromGui' }
		$PsArgs += @('-ResultFile', $PsJson, '-EnginePath', $Engine)
		$PsCode = Invoke-Script (Join-Path $EarlyDir 'uninstall.ps1') $PsArgs
		$Ps = Read-JsonFile $PsJson
		if ($Ps)
		{
			foreach ($L in @($Ps.left)) { if ($L) { $LeftAll += [pscustomobject]@{ path = [string]$L.path; reason = [string]$L.reason; queued = [bool]$L.queued } } }
			$KeptAll = @($Ps.kept | Where-Object { $_ })
			$Message = [string]$Ps.message
		}
		$Code = $PsCode
		if ($Code -eq 0 -or $Code -eq 3010)
		{
			# the engine's own leftovers count too (uninstall.ps1 only knows its own)
			if (@($LeftAll | Where-Object { -not $_.queued }).Count -gt 0) { $Code = 40; if (-not $Message) { $Message = 'Some of Benchy could not be removed.' } }
			elseif (@($LeftAll).Count -gt 0) { $Code = 3010 }
		}
		if ($Code -eq 0 -or $Code -eq 3010) { Add-ProgressLine $Plan.last 'done' } else { Add-ProgressLine $Plan.last 'fail' }
		if (-not $Message)
		{
			$Message = switch ($Code) { 0 { 'Benchy was uninstalled.' } 3010 { 'Benchy was uninstalled; some leftovers are removed at the next restart.' } default { "uninstall.ps1 stopped with exit code $Code before it could say why." } }
		}
	}
	catch
	{
		$Code = 50
		$Message = "The uninstaller could not finish: $($_.Exception.Message)"
		Write-Log $Message
	}
	finally
	{
		$Ini = [ordered]@{}
		$Ini['result'] = [ordered]@{ code = $Code; message = $Message; reason = $Reason }
		$Ini['left'] = [ordered]@{ count = @($LeftAll).Count }
		$N = 0
		foreach ($L in @($LeftAll)) { $N++; $Ini['left']["path$N"] = $L.path; $Ini['left']["reason$N"] = $L.reason }
		$Ini['kept'] = [ordered]@{ count = @($KeptAll).Count }
		$N = 0
		foreach ($K in @($KeptAll)) { $N++; $Ini['kept']["path$N"] = $K }
		$Ini['still'] = [ordered]@{ machine_install = $(if ($Plan -and $Plan.machine_install) { 1 } else { 0 }) }
		try { Write-IniFile $ResultIni $Ini } catch { }
		Write-Log "Uninstall finished with exit code $Code. $Message"
		$script:RunCode = $Code
	}
}

try
{
	switch ($Mode)
	{
		'Inventory' { Invoke-Inventory }
		'Run' { Invoke-Run }
	}
}
catch
{
	Write-Log "uninstall-wizard.ps1 $Mode failed: $($_.Exception.Message)"
	if ($Mode -eq 'Inventory') { try { Write-IniFile $InvIni ([ordered]@{ inv = [ordered]@{ ok = 0 } }) } catch { }; exit 1 }
	exit 50
}
if ($Mode -eq 'Run') { exit [int]$script:RunCode }
exit 0
