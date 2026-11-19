<#
	Benchy Windows Uninstaller
	URLs: http://www.phoronix.com, http://www.phoronix-test-suite.com/

	This program is free software; you can redistribute it and/or modify
	it under the terms of the GNU General Public License as published by
	the Free Software Foundation; either version 3 of the License, or
	(at your option) any later version.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
	GNU General Public License for more details.

	You should have received a copy of the GNU General Public License
	along with this program. If not, see <http://www.gnu.org/licenses/>.

	Registered in Apps & Features by install.ps1, so it serves installs made by install.bat and by the GUI installer.

	uninstall.ps1 [-Quiet] [-RemoveUserData] [-KeepResults] [-KeepSettings] [-KeepInstalledTests] [-ResultFile <path>]
	              [-AtReboot] [-FromGui] [-EnginePath <path>] [-Inventory]

	The user data is three parts: results (%USERPROFILE%\.benchy\test-results and the engine's results under
	%LOCALAPPDATA%\benchy-engine), settings (the rest of .benchy, the engine's settings, and the desktop client's
	settings folder) and installed tests (.benchy\installed-tests and the engine's installed tests). With none of
	-KeepResults, -KeepSettings or -KeepInstalledTests given, results and settings are kept and installed tests are
	removed (they can be downloaded again). With any of them given, exactly those are kept. -RemoveUserData removes all
	three and cannot be combined with a Keep switch (exit 2). Interactively (no Keep switch, no -Quiet) it asks.

	Engine 0.9.0 or later (its hello lists agent.reset) removes what Benchy put machine-wide and the engine's own
	data: this script first runs `benchy-engine --json agent reset --all --uninstall --keep-data <list> --at-reboot`
	(one UAC prompt, raised by the engine itself), then removes what the engine does not own. A prompt that is
	declined or expires ends with exit 5 and nothing changed. With no engine, or an older one, it does what it did
	before and the result says which machine-wide pieces may be left. -FromGui says the wizard already ran the reset.
	-EnginePath names the engine to use (the wizard's own copy); the default is the installed one.

	-Inventory changes nothing: it writes the list of what this script would remove (and the engine's inventory) to
	-ResultFile as JSON and exits 0. On a removal run -ResultFile gets {result, code, message, engine, left, kept}; left
	is [{path, reason, queued}]: the engine's reset_done.left plus what this script could not remove. -AtReboot queues
	what is in use (user data and install dir) in a RunOnce batch file instead of stopping with exit 40.

	Removes %LOCALAPPDATA%\Benchy (code, php, php.new, php.prev, update, the client and the engine), the Start Menu
	shortcut, that exact entry in the user PATH, PHP_BIN when it points into the install dir, and the Apps & Features
	entry. Keeps the user data unless asked: %USERPROFILE%\.benchy (results, settings, installed tests) and the desktop
	client's own settings, %LOCALAPPDATA%\Benchy\BenchyClient (the one folder left in the install dir). Both go together,
	when the person says yes to the question or -RemoveUserData is given. Always keeps the Visual C++ Runtime and
	Cygwin, which are shared, system-wide components. Asks first unless -Quiet; -Quiet keeps the user data unless
	-RemoveUserData is also given (Apps & Features runs it with -Quiet from its Quiet uninstall string).

	Everything in the install dir except this uninstaller (and the lib it needs) is deleted first; the shortcut,
	PATH, PHP_BIN and the Apps & Features entry go only once that worked, and the uninstaller itself goes last, only
	once the entry is gone. So Apps & Features never lists Benchy without an uninstaller that can be run again.
	Junctions and symbolic links are removed as links; what they point to is never deleted.

	Exit codes: 0 removed, 1 cancelled, 2 no user profile (LOCALAPPDATA or USERPROFILE unset) or bad arguments,
	3 refused because Benchy is running (or the engine's agent did not stop), 5 Windows did not give permission
	(the prompt was declined, expired or had no desktop; nothing changed), 3010 removed, with leftovers queued for the
	next restart, 40 not everything could be removed and some leftovers are not queued (the message says whether Benchy
	is still registered), 50 anything else.
#>

param(
	[switch]$Quiet,
	[switch]$RemoveUserData,
	[switch]$KeepResults,
	[switch]$KeepSettings,
	[switch]$KeepInstalledTests,
	[switch]$Inventory,
	[switch]$AtReboot,
	[switch]$FromGui,
	[string]$ResultFile,
	[string]$EnginePath
)

$ErrorActionPreference = 'Stop'
if ($Inventory) { $Quiet = $true }

if (-not $env:LOCALAPPDATA -or -not $env:USERPROFILE)
{
	Write-Host 'LOCALAPPDATA or USERPROFILE is not set, so there is no user profile to uninstall Benchy from. Run the uninstaller as the user who installed Benchy.' -ForegroundColor Red
	exit 2
}
if ($RemoveUserData -and ($KeepResults -or $KeepSettings -or $KeepInstalledTests))
{
	Write-Host '-RemoveUserData cannot be combined with -KeepResults, -KeepSettings or -KeepInstalledTests.' -ForegroundColor Red
	exit 2
}
$InstallDir = Join-Path $env:LOCALAPPDATA 'Benchy'
$LibRelative = 'pts-core\static\update\benchy-windows.ps1'

# Run from a copy in %TEMP%, so removing the install dir does not delete the folder this script runs from
$ScriptFull = [IO.Path]::GetFullPath($PSCommandPath)
$InstallFull = [IO.Path]::GetFullPath($InstallDir).TrimEnd('\') + '\'
if ($ScriptFull.StartsWith($InstallFull, [StringComparison]::OrdinalIgnoreCase))
{
	$TempCopy = Join-Path ([IO.Path]::GetTempPath()) "Benchy-uninstall-$([guid]::NewGuid().ToString('N'))"
	$Code = 50
	try
	{
		New-Item -ItemType Directory -Force -Path (Join-Path $TempCopy (Split-Path $LibRelative)) | Out-Null
		Copy-Item -LiteralPath $PSCommandPath -Destination (Join-Path $TempCopy 'uninstall.ps1')
		Copy-Item -LiteralPath (Join-Path $PSScriptRoot $LibRelative) -Destination (Join-Path $TempCopy $LibRelative)
		$Arguments = @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', (Join-Path $TempCopy 'uninstall.ps1'))
		if ($Quiet) { $Arguments += '-Quiet' }
		if ($RemoveUserData) { $Arguments += '-RemoveUserData' }
		foreach ($Name in @('KeepResults', 'KeepSettings', 'KeepInstalledTests', 'Inventory', 'AtReboot', 'FromGui'))
		{
			if ($PSBoundParameters.ContainsKey($Name) -and $PSBoundParameters[$Name]) { $Arguments += "-$Name" }
		}
		if ($ResultFile) { $Arguments += @('-ResultFile', $ResultFile) }
		if ($EnginePath) { $Arguments += @('-EnginePath', $EnginePath) }
		# Both the PowerShell location and the process directory, or the child would hold the install dir open
		Set-Location -LiteralPath ([IO.Path]::GetTempPath())
		[Environment]::CurrentDirectory = [IO.Path]::GetTempPath()
		& (Join-Path $PSHOME 'powershell.exe') @Arguments
		$Code = $LASTEXITCODE
	}
	catch
	{
		Write-Host "Benchy could not be uninstalled: $($_.Exception.Message)" -ForegroundColor Red
		if (-not $Quiet) { Read-Host 'Press Enter to close' | Out-Null }
	}
	finally
	{
		Remove-Item -LiteralPath $TempCopy -Recurse -Force -ErrorAction SilentlyContinue
	}
	exit $Code
}

function Read-YesNo([string]$Question)
{
	$Answer = ([string](Read-Host "$Question [y/N]")).Trim()
	return ($Answer -match '^(y|yes)$')
}

function Write-NotRemoved([string]$What, $Paths)
{
	$Paths = @($Paths)
	Write-Host "Could not remove $($Paths.Count) item(s) in ${What}, for example:" -ForegroundColor Red
	$Paths | Select-Object -First 5 | ForEach-Object { Write-Host "  $_" -ForegroundColor Red }
}

# --- Result file, engine and size helpers -----------------------------------

$script:Left = @()      # [{path, reason, queued}]: what is still there
$script:Kept = @()      # paths kept on purpose
$script:EngineInfo = [ordered]@{ used = $false; path = $null; version = $null; reset = $false; note = $null }

function Add-Left([string]$Path, [string]$Reason, [bool]$Queued = $false)
{
	$script:Left += [pscustomobject]@{ path = $Path; reason = $Reason; queued = $Queued }
}

function Write-UninstallResult([string]$Result, [int]$Code, [string]$Message, $Extra = $null)
{
	# The simple result file the wizard reads. Never fails the uninstall.
	if (-not $ResultFile) { return }
	try
	{
		$Body = [ordered]@{ result = $Result; code = $Code; message = $Message; engine = $script:EngineInfo; left = @($script:Left); kept = @($script:Kept) }
		if ($Extra) { foreach ($Key in $Extra.Keys) { $Body[$Key] = $Extra[$Key] } }
		$Json = $Body | ConvertTo-Json -Depth 6
		[IO.File]::WriteAllText([IO.Path]::GetFullPath($ResultFile), $Json, (New-Object System.Text.UTF8Encoding $false))
	}
	catch { Write-Host "Could not write the result file ${ResultFile}: $($_.Exception.Message)" -ForegroundColor Red }
}

function Get-FolderStats([string[]]$Paths)
{
	# Total bytes and file count under the paths, never following a link. Past 20,000 entries the numbers are
	# $null ("a lot"), as the engine's are.
	$Bytes = [long]0; $Count = 0; $Seen = 0; $Capped = $false
	$Stack = New-Object System.Collections.Generic.Stack[string]
	foreach ($Path in @($Paths | Where-Object { $_ })) { if (Test-Path -LiteralPath $Path) { $Stack.Push($Path) } }
	while ($Stack.Count -gt 0 -and -not $Capped)
	{
		$Dir = $Stack.Pop()
		try
		{
			foreach ($Info in (New-Object IO.DirectoryInfo $Dir).EnumerateFileSystemInfos())
			{
				if (++$Seen -gt 20000) { $Capped = $true; break }
				if ($Info.Attributes -band [IO.FileAttributes]::ReparsePoint) { continue }
				if ($Info -is [IO.DirectoryInfo]) { $Stack.Push($Info.FullName) }
				else { $Bytes += $Info.Length; $Count++ }
			}
		}
		catch { }
	}
	if ($Capped) { return [pscustomobject]@{ bytes = $null; count = $null } }
	return [pscustomobject]@{ bytes = $Bytes; count = $Count }
}

function Invoke-BenchyEngine([string]$Exe, [string[]]$EngineArgs, [int]$TimeoutSeconds)
{
	# Runs `<engine> --json <args>` and returns {Exit, Events (parsed JSON lines, at most 5000), TimedOut, Stderr}.
	$Result = [pscustomobject]@{ Exit = -1; Events = @(); TimedOut = $false; Stderr = '' }
	$Info = New-Object Diagnostics.ProcessStartInfo
	$Info.FileName = $Exe
	$Info.Arguments = '--json ' + ($EngineArgs -join ' ')
	$Info.UseShellExecute = $false
	$Info.CreateNoWindow = $true
	$Info.RedirectStandardOutput = $true
	$Info.RedirectStandardError = $true
	$Process = [Diagnostics.Process]::Start($Info)
	$Out = $Process.StandardOutput.ReadToEndAsync()
	$Err = $Process.StandardError.ReadToEndAsync()
	if (-not $Process.WaitForExit($TimeoutSeconds * 1000))
	{
		$Result.TimedOut = $true
		try { $Process.Kill() } catch { }
	}
	else { $Process.WaitForExit() }
	$Result.Exit = if ($Result.TimedOut) { -1 } else { $Process.ExitCode }
	$Events = @()
	foreach ($Line in ([string]$Out.Result -split "`n"))
	{
		if ($Events.Count -ge 5000) { break }
		$Line = $Line.Trim()
		if (-not $Line.StartsWith('{')) { continue }
		try { $Events += ($Line | ConvertFrom-Json) } catch { }
	}
	$Result.Events = $Events
	$Text = [string]$Err.Result
	$Result.Stderr = if ($Text.Length -gt 500) { $Text.Substring(0, 500) } else { $Text }
	return $Result
}

function Get-BenchyEngineInfo([string]$Exe)
{
	# Fills $script:EngineInfo from the engine's hello (its command words say whether it can reset). A missing,
	# unrunnable or older engine leaves reset false with a note; never throws.
	$script:EngineInfo.path = $Exe
	if (-not $Exe -or -not (Test-Path -LiteralPath $Exe -PathType Leaf))
	{
		$script:EngineInfo.note = 'The native engine is not installed.'
		return
	}
	try
	{
		$Run = Invoke-BenchyEngine $Exe @('agent', 'inventory') 60
		$Hello = @($Run.Events | Where-Object { $_.type -eq 'hello' })[0]
		if (-not $Hello) { $script:EngineInfo.note = 'The native engine did not answer.'; return }
		$script:EngineInfo.version = [string]$Hello.engine.version
		$script:EngineInfo.reset = (@($Hello.commands) -contains 'agent.reset')
		if (-not $script:EngineInfo.reset) { $script:EngineInfo.note = "The native engine $($script:EngineInfo.version) is older than 0.9.0." }
		$script:InventoryEvents = @($Run.Events)
	}
	catch { $script:EngineInfo.note = "The native engine could not be run: $($_.Exception.Message)" }
}

# What an engine older than 0.9.0, or none, leaves behind: named in the result so it is not a surprise
$MachineMayRemain = @(
	'the unattended agent, if one was set up (its scheduled task and its folders under Program Files and ProgramData)',
	'the administrator-test helpers and the Benchy policy lines and key (HKLM\SOFTWARE\Policies\Benchy)',
	"the native engine's data in $(Join-Path $env:LOCALAPPDATA 'benchy-engine') (results, settings, server pairing)"
)

function Add-CleanupBatch([string[]]$Paths)
{
	# One HKCU RunOnce batch file that removes the paths (and itself) at the next logon. Returns $true when queued.
	$Paths = @($Paths | Where-Object { $_ })
	if ($Paths.Count -eq 0) { return $true }
	try
	{
		$Batch = Join-Path $env:LOCALAPPDATA "Benchy-cleanup-$([guid]::NewGuid().ToString('N')).cmd"
		$Lines = @('@echo off')
		foreach ($Path in $Paths)
		{
			$Quoted = '"' + ($Path -replace '%', '%%') + '"'
			$Lines += "if exist $Quoted ( rmdir /s /q $Quoted 2>nul & del /f /q $Quoted 2>nul )"
		}
		$Lines += 'del /f /q "%~f0"'
		[IO.File]::WriteAllText($Batch, ($Lines -join "`r`n") + "`r`n", [Text.Encoding]::ASCII)
		$Key = [Microsoft.Win32.Registry]::CurrentUser.CreateSubKey('Software\Microsoft\Windows\CurrentVersion\RunOnce')
		try { $Key.SetValue('BenchyCleanup', "cmd.exe /c `"$Batch`"") } finally { $Key.Close() }
		return $true
	}
	catch { return $false }
}

function Remove-BenchyDir([string]$Path, [string[]]$Keep = @())
{
	# Removes $Path (a link as a link). Returns the paths that could not be removed.
	if (-not (Test-Path -LiteralPath $Path)) { return @() }
	if ((Get-Item -LiteralPath $Path -Force).Attributes -band [IO.FileAttributes]::ReparsePoint)
	{
		try { [IO.Directory]::Delete($Path, $false); return @() } catch { return @($Path) }
	}
	$Failed = @(Remove-BenchyTree $Path $Keep)
	if ($Failed.Count -eq 0 -and @($Keep | Where-Object { $_ }).Count -eq 0)
	{
		try { [IO.Directory]::Delete($Path, $false) } catch { }
	}
	return $Failed
}

$Code = 50
$UserData = Join-Path $env:USERPROFILE '.benchy'
try
{
	. (Join-Path $PSScriptRoot $LibRelative)

	$InstalledEngine = if ($EnginePath) { $EnginePath } else { Get-BenchyEngineExe $InstallDir }
	$ResultsDir = Join-Path $UserData 'test-results'
	$TestsDir = Join-Path $UserData 'installed-tests'
	$ClientData = Join-Path $InstallDir 'BenchyClient'
	$EngineData = Join-Path $env:LOCALAPPDATA 'benchy-engine'
	Get-BenchyEngineInfo $InstalledEngine

	if ($Inventory)
	{
		# Read-only: what this script owns, plus the engine's own inventory when it has one
		$Items = @()
		function New-InvItem([string]$Id, [string]$Kind, [string[]]$Paths, $Present, $Stats, $Keep)
		{
			return [pscustomobject]@{ id = $Id; scope = 'user'; kind = $Kind; paths = @($Paths); present = $Present
				bytes = $(if ($Stats) { $Stats.bytes } else { $null }); count = $(if ($Stats) { $Stats.count } else { $null }); keep = $Keep }
		}
		$Items += New-InvItem 'app_dir' 'folder' @($InstallDir) (Test-Path -LiteralPath $InstallDir) (Get-FolderStats @($InstallDir)) $null
		$Items += New-InvItem 'client_settings' 'folder' @($ClientData) (Test-Path -LiteralPath $ClientData) (Get-FolderStats @($ClientData)) 'settings'
		$Menu = @((Get-BenchyShortcutPath), (Get-BenchyPromptShortcutPath))
		$Items += New-InvItem 'start_menu' 'file' $Menu (@($Menu | Where-Object { Test-Path -LiteralPath $_ }).Count -gt 0) $null $null
		$RunKey = [Microsoft.Win32.Registry]::CurrentUser.OpenSubKey('Software\Microsoft\Windows\CurrentVersion\Run')
		$RunPresent = $false
		if ($RunKey) { try { $RunPresent = ($null -ne $RunKey.GetValue('Benchy', $null)) } finally { $RunKey.Close() } }
		$Items += New-InvItem 'run_value' 'registry' @('HKCU\Software\Microsoft\Windows\CurrentVersion\Run\Benchy') $RunPresent $null $null
		$Items += New-InvItem 'results' 'folder' @($ResultsDir) (Test-Path -LiteralPath $ResultsDir) (Get-FolderStats @($ResultsDir)) 'results'
		$OtherData = @()
		if (Test-Path -LiteralPath $UserData) { $OtherData = @(Get-ChildItem -LiteralPath $UserData -Force | Where-Object { $_.FullName -ne $ResultsDir -and $_.FullName -ne $TestsDir } | ForEach-Object { $_.FullName }) }
		$Items += New-InvItem 'settings' 'folder' @($UserData) ($OtherData.Count -gt 0) (Get-FolderStats $OtherData) 'settings'
		$Items += New-InvItem 'installed_tests' 'folder' @($TestsDir) (Test-Path -LiteralPath $TestsDir) (Get-FolderStats @($TestsDir)) 'tests'
		$EngineItems = @($script:InventoryEvents | Where-Object { $_.type -eq 'inventory_item' })
		$EngineSummary = @($script:InventoryEvents | Where-Object { $_.type -eq 'inventory' })[0]
		Write-UninstallResult 'inventory' 0 'Inventory only; nothing was changed.' @{
			items = $Items; engine_items = $EngineItems
			machine = $(if ($EngineSummary) { [bool]$EngineSummary.machine } else { $null })
			conflict = $(if ($EngineSummary) { $EngineSummary.conflict } else { $null })
			machine_may_remain = $(if ($script:EngineInfo.reset) { @() } else { $MachineMayRemain })
		}
		$Code = 0
		exit 0
	}

	$Running = @(Get-BenchyProcesses $InstallDir)
	if ($Running.Count -gt 0)
	{
		Stop-Benchy 3 "Benchy is running ($(Format-BenchyProcesses $Running)). $(Get-BenchyRunningAdvice $Running 'the uninstaller')"
	}
	# Not seen as a process (Benchy running as administrator, from an unelevated uninstaller): its exe is still held open
	$Held = @(Get-BenchyFilesInUse $InstallDir)
	if ($Held.Count -gt 0) { Stop-Benchy 3 (Get-BenchyInUseAdvice $Held 'the uninstaller') }

	# What to keep: -RemoveUserData removes all three; any Keep switch means exactly those; none means the defaults
	# (results and settings kept, installed tests removed) unless this is an interactive run, which asks below
	$Decided = $true
	if ($RemoveUserData) { $KeepR = $false; $KeepS = $false; $KeepT = $false }
	elseif ($KeepResults -or $KeepSettings -or $KeepInstalledTests) { $KeepR = [bool]$KeepResults; $KeepS = [bool]$KeepSettings; $KeepT = [bool]$KeepInstalledTests }
	else { $KeepR = $true; $KeepS = $true; $KeepT = $false; $Decided = $false }
	if (-not $Quiet)
	{
		$Question = if (-not ($KeepR -or $KeepS -or $KeepT)) { "Remove Benchy from $InstallDir and DELETE your results, settings and installed tests in ${UserData} and ${EngineData}, and the desktop client's settings in ${ClientData}?" } else { "Remove Benchy from ${InstallDir}?" }
		if (-not (Read-YesNo $Question))
		{
			Stop-Benchy 1 'Nothing was removed.'
		}
		if (-not $Decided -and ((Test-Path -LiteralPath $UserData) -or (Test-Path -LiteralPath $ClientData) -or (Test-Path -LiteralPath $EngineData)))
		{
			if (Read-YesNo "Also delete your results and settings in ${UserData} and ${EngineData}, and the desktop client's settings in ${ClientData}?")
			{
				$KeepR = $false; $KeepS = $false
			}
			if (Test-Path -LiteralPath $TestsDir) { $KeepT = Read-YesNo "Keep your installed tests in ${TestsDir} (they can be downloaded again)?" }
		}
	}
	$KeepClientData = $KeepS -and (Test-Path -LiteralPath $ClientData)
	$KeepList = @(@('results', 'settings', 'tests') | Where-Object { ($_ -eq 'results' -and $KeepR) -or ($_ -eq 'settings' -and $KeepS) -or ($_ -eq 'tests' -and $KeepT) })

	# The engine first: machine-wide parts, its own data and the server pairing. One UAC prompt, raised by the engine
	# (a decline or timeout changes nothing, exit 5). The wizard (-FromGui) has already run it.
	if (-not $FromGui)
	{
		if ($script:EngineInfo.reset)
		{
			$ResetArgs = @('agent', 'reset', '--all', '--uninstall')
			if ($KeepList.Count -gt 0) { $ResetArgs += @('--keep-data', ($KeepList -join ',')) }
			$ResetArgs += '--at-reboot'
			Write-Host "Removing what Benchy put on this computer outside its own folder (benchy-engine $($script:EngineInfo.version) agent reset)..."
			$script:EngineInfo.used = $true
			$Run = Invoke-BenchyEngine $InstalledEngine $ResetArgs 900
			foreach ($Event in $Run.Events)
			{
				if ($Event.type -eq 'reset_step') { Write-Host "  $($Event.step): $($Event.status)" }
			}
			$Done = @($Run.Events | Where-Object { $_.type -eq 'reset_done' })[0]
			$ErrorEvent = @($Run.Events | Where-Object { $_.type -eq 'error' })[0]
			if ($Done)
			{
				foreach ($Item in @($Done.left)) { Add-Left ([string]$Item.path) ([string]$Item.reason) ([bool]$Item.queued) }
			}
			elseif ($Run.TimedOut) { Stop-Benchy 50 'The engine did not finish removing Benchy''s machine-wide parts in 15 minutes. Nothing more was removed; run the uninstaller again.' }
			elseif ($ErrorEvent -and $ErrorEvent.code -eq 'permission')
			{
				Stop-Benchy 5 'Windows did not give permission to remove the parts of Benchy that are for all users (the prompt was declined, expired or could not be shown). Nothing has been removed yet. Run the uninstaller again and accept the prompt.'
			}
			elseif ($ErrorEvent -and $ErrorEvent.code -eq 'locked')
			{
				Stop-Benchy 3 'The Benchy agent is still running and did not stop in time. Close Benchy, wait for the running job to finish and run the uninstaller again.'
			}
			else
			{
				$Why = if ($ErrorEvent) { [string]$ErrorEvent.message } else { "the engine exited with code $($Run.Exit). $($Run.Stderr)" }
				Stop-Benchy 50 "The engine could not remove Benchy's machine-wide parts: $Why"
			}
		}
		else
		{
			Write-Host "$($script:EngineInfo.note) Removing Benchy's own folders only; these may be left: $($MachineMayRemain -join '; ')." -ForegroundColor Yellow
			if (Test-Path -LiteralPath $EngineData) { Add-Left $EngineData 'the native engine would remove this (engine 0.9.0 or later); delete it by hand if you want it gone' }
		}
	}

	# Order matters, so that Apps & Features never lists Benchy without a working uninstaller:
	# 1. Everything in the install dir except uninstall.ps1 and the lib it needs, while the shortcut, PATH, PHP_BIN
	#    and the entry still describe the install. A failure stops here (exit 40) with the entry and its uninstaller
	#    intact, so it can be run again (with -AtReboot it carries on and queues what is left).
	# 2. The shortcut, PATH, PHP_BIN and the entry, each on its own, so one failure does not skip the rest.
	# 3. Only once the entry is gone: the uninstaller, the lib and the empty folders.
	$SelfPath = Join-Path $InstallDir 'uninstall.ps1'
	$LibPath = Join-Path $InstallDir $LibRelative
	$InstallIsLink = $false
	if (Test-Path -LiteralPath $InstallDir)
	{
		$InstallIsLink = [bool]((Get-Item -LiteralPath $InstallDir -Force).Attributes -band [IO.FileAttributes]::ReparsePoint)
		if (-not $InstallIsLink)
		{
			$KeepNow = @($SelfPath, $LibPath)
			if ($KeepClientData) { $KeepNow += $ClientData }
			$Failed = @(Remove-BenchyTree $InstallDir $KeepNow)
			if ($Failed.Count -gt 0)
			{
				Write-NotRemoved $InstallDir $Failed
				if (-not $AtReboot) { Stop-Benchy 40 'Benchy was not fully removed and is still registered. Close every Benchy window and run the uninstaller again.' }
			}
		}
	}

	$ByHand = @()
	function Add-ByHand([string]$Path, [string]$Description)
	{
		$script:ByHand += $Description
		Add-Left $Path 'could not be removed'
	}
	$ShortcutPath = Get-BenchyShortcutPath
	try
	{
		if (Test-Path -LiteralPath $ShortcutPath)
		{
			Remove-Item -LiteralPath $ShortcutPath -Force
			Write-Host "Removed $ShortcutPath"
		}
	}
	catch { Add-ByHand $ShortcutPath "delete the shortcut $ShortcutPath" }
	$PromptShortcutPath = Get-BenchyPromptShortcutPath
	try
	{
		if (Test-Path -LiteralPath $PromptShortcutPath)
		{
			Remove-Item -LiteralPath $PromptShortcutPath -Force
			Write-Host "Removed $PromptShortcutPath"
		}
	}
	catch { Add-ByHand $PromptShortcutPath "delete the shortcut $PromptShortcutPath" }
	try
	{
		if (Remove-BenchyUserPath $InstallDir) { Write-Host "Removed $InstallDir from your user PATH" }
	}
	catch { Add-ByHand 'HKCU\Environment\Path' "remove $InstallDir from your user PATH" }
	try
	{
		$PhpBin = [Environment]::GetEnvironmentVariable('PHP_BIN', 'User')
		$PhpBinInside = $false
		if ($PhpBin)
		{
			# A malformed PHP_BIN is not Benchy's and is left alone
			try { $PhpBinInside = Test-BenchyPathInside $PhpBin.Trim('"') $InstallDir } catch { }
		}
		if ($PhpBinInside)
		{
			[Environment]::SetEnvironmentVariable('PHP_BIN', $null, 'User')
			Write-Host 'Removed PHP_BIN'
		}
	}
	catch { Add-ByHand 'HKCU\Environment\PHP_BIN' 'delete the user environment variable PHP_BIN' }
	try
	{
		if (Remove-BenchyAutostart) { Write-Host 'Removed the Benchy login entry (HKCU Run)' }
	}
	catch { Add-ByHand 'HKCU\Software\Microsoft\Windows\CurrentVersion\Run\Benchy' 'delete the registry value HKCU\Software\Microsoft\Windows\CurrentVersion\Run\Benchy' }
	$EntryRemoved = $false
	try
	{
		Remove-BenchyUninstallEntry
		$EntryRemoved = $true
		Write-Host 'Removed Benchy from Apps & Features'
	}
	catch { Add-ByHand "HKCU\$BenchyUninstallKey" "remove Benchy from Apps & Features (registry key HKCU\$BenchyUninstallKey)" }

	if ($EntryRemoved)
	{
		try
		{
			if ($InstallIsLink)
			{
				# The install dir itself is a link: remove the link, not what it points to
				[IO.Directory]::Delete($InstallDir, $false)
			}
			elseif (Test-Path -LiteralPath $InstallDir)
			{
				$KeepLast = @()
				if ($KeepClientData) { $KeepLast += $ClientData }
				$Failed = @(Remove-BenchyTree $InstallDir $KeepLast)
				if ($Failed.Count -gt 0)
				{
					Write-NotRemoved $InstallDir $Failed
					if ($AtReboot -and (Add-CleanupBatch $Failed))
					{
						foreach ($Path in $Failed) { Add-Left $Path 'in use; removed the next time Windows starts' $true }
					}
					else { Add-ByHand $InstallDir "delete what is left of $InstallDir" }
				}
				elseif (-not $KeepClientData)
				{
					try { [IO.Directory]::Delete($InstallDir, $false) }
					catch { Write-Host "Left the empty folder $InstallDir (it is in use); it can be deleted later." }
				}
			}
		}
		catch { Add-ByHand $InstallDir "delete what is left of $InstallDir" }
		if ($ByHand -notcontains "delete what is left of $InstallDir") { Write-Host $(if ($KeepClientData) { "Removed Benchy from $InstallDir (the desktop client's settings stay)" } else { "Removed $InstallDir" }) }
	}

	# The user data, by part: results (test-results), installed tests (installed-tests), settings (everything else)
	if (Test-Path -LiteralPath $UserData)
	{
		$DataFailed = @()
		$UserDataIsLink = [bool]((Get-Item -LiteralPath $UserData -Force).Attributes -band [IO.FileAttributes]::ReparsePoint)
		try
		{
			if (-not ($KeepR -or $KeepS -or $KeepT)) { $DataFailed += @(Remove-BenchyDir $UserData) }
			elseif ($UserDataIsLink -and -not ($KeepR -and $KeepS -and $KeepT)) { Add-Left $UserData 'a link, so only whole-folder choices are possible; nothing under it was touched' }
			elseif (-not $KeepS)
			{
				$Spare = @(); if ($KeepR) { $Spare += $ResultsDir }; if ($KeepT) { $Spare += $TestsDir }
				$DataFailed += @(Remove-BenchyDir $UserData $Spare)
			}
			else
			{
				if (-not $KeepR) { $DataFailed += @(Remove-BenchyDir $ResultsDir) }
				if (-not $KeepT) { $DataFailed += @(Remove-BenchyDir $TestsDir) }
			}
		}
		catch { $DataFailed += $UserData; Write-Host "Could not delete in ${UserData}: $($_.Exception.Message)" -ForegroundColor Red }
		if ($DataFailed.Count -gt 0)
		{
			Write-NotRemoved $UserData $DataFailed
			if ($AtReboot -and (Add-CleanupBatch $DataFailed)) { foreach ($Path in $DataFailed) { Add-Left $Path 'in use; removed the next time Windows starts' $true } }
			else { foreach ($Path in $DataFailed) { Add-Left $Path 'could not be removed'; $ByHand += "delete what is left of $Path" } }
		}
		elseif (-not ($KeepR -or $KeepS -or $KeepT)) { Write-Host "Removed $UserData" }
	}

	Write-Host ''
	Write-Host 'Kept:'
	if ($KeepS -and (Test-Path -LiteralPath $UserData)) { $script:Kept += $UserData }
	if ($KeepR -and (Test-Path -LiteralPath $ResultsDir)) { $script:Kept += $ResultsDir }
	if ($KeepT -and (Test-Path -LiteralPath $TestsDir)) { $script:Kept += $TestsDir }
	if ($KeepClientData) { $script:Kept += $ClientData }
	if (($KeepR -or $KeepS -or $KeepT) -and (Test-Path -LiteralPath $EngineData)) { $script:Kept += $EngineData }
	foreach ($Path in $script:Kept) { Write-Host "  $Path" }
	Write-Host '  the Microsoft Visual C++ Runtime (shared with other programs; remove it in Apps & Features if nothing needs it)'
	if (Test-Path -LiteralPath 'C:\cygwin64') { Write-Host '  C:\cygwin64 (system-wide Cygwin, possibly used by other software)' }
	if (Test-Path -LiteralPath 'C:\PHP') { Write-Host '  C:\PHP (left by an older Benchy launcher)' }

	$Unqueued = @($script:Left | Where-Object { -not $_.queued })
	$Queued = @($script:Left | Where-Object { $_.queued })
	$Extra = @{ machine_may_remain = $(if ($script:EngineInfo.reset -or $FromGui) { @() } else { $MachineMayRemain }) }
	if ($ByHand.Count -gt 0 -or $Unqueued.Count -gt 0)
	{
		$State = if ($EntryRemoved) { 'Benchy was uninstalled' } else { 'The program files were removed, but Benchy is still listed in Apps & Features; its uninstaller was kept, so it can be run again' }
		$Todo = if ($ByHand.Count -gt 0) { " Finish by hand: $($ByHand -join '; ')." } else { " Left behind: $(($Unqueued | ForEach-Object { $_.path }) -join '; ')." }
		Stop-Benchy 40 "$State.$Todo"
	}
	Write-Host ''
	if ($Queued.Count -gt 0)
	{
		Write-Host 'Benchy was uninstalled. The rest is removed the next time Windows starts.' -ForegroundColor Green
		$Code = 3010
		Write-UninstallResult 'removed_at_restart' 3010 'Benchy was uninstalled; some leftovers are removed at the next restart.' $Extra
	}
	else
	{
		Write-Host 'Benchy was uninstalled.' -ForegroundColor Green
		$Code = 0
		Write-UninstallResult 'removed' 0 'Benchy was uninstalled.' $Extra
	}
}
catch
{
	$Exception = $_.Exception
	$Code = if ($Exception -and $Exception.Data.Contains('BenchyCode')) { [int]$Exception.Data['BenchyCode'] } else { 50 }
	$Color = if ($Code -eq 1) { 'Yellow' } else { 'Red' }
	Write-Host $Exception.Message -ForegroundColor $Color
	$Why = switch ($Code) { 1 { 'cancelled' } 5 { 'permission' } 40 { 'left' } default { 'failed' } }
	Write-UninstallResult $Why $Code $Exception.Message @{ machine_may_remain = @() }
}
finally
{
	if (-not $Quiet) { Read-Host 'Press Enter to close' | Out-Null }
}

exit $Code
