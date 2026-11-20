<#
	Benchy runtime component applier
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

	Started by `benchy-engine update apply-components` (which has already verified the signed index, every staged file and the
	run lock, and unpacked each archive with its real sizes checked), by `engine rollback --components` (-Rollback), and by
	benchy.bat when it finds update\state.json (-Recover). It never downloads and never runs anything that was not unpacked from
	a verified archive. User level, except the Visual C++ runtime installer's own permission prompt.

	Order (design 13.3): Visual C++ runtime when a PHP needs a newer one (never rolled back), then PHP, the Benchy tree and the
	client in ONE journaled set of renames (update\state.json is written before the first rename; any failure puts everything back,
	and -Recover does the same after a crash: it always goes back to the old state). Windows PowerShell 5.1 compatible.

	Exit codes: 0 done, 2 bad plan or usage, 3 Benchy is running (nothing changed), 4 the client did not quit in time (nothing
	changed), 21 the Visual C++ runtime was not installed (nothing changed), 30 PHP did not pass its check (nothing changed),
	40 a rename failed (put back), 41 recovery could not finish, 42 an update is already in progress.
#>
param(
	[Parameter(Mandatory)][string]$InstallDir,
	[string]$PlanPath,
	[string]$StageRoot,
	[switch]$Recover,
	[switch]$Rollback,
	[string]$Component,
	[int]$ClientWaitSeconds = 600,
	[switch]$DefineOnly
)

$ErrorActionPreference = 'Stop'

# What the engine compares with the plan's applier level (plan.json, applier)
$BenchyApplierLevel = 1

. (Join-Path $PSScriptRoot 'benchy-windows.ps1')

$Script:ClientQuit = $false
$ApplyJournalSchema = 'benchy-apply-journal/1'
# Top-level names in the install dir that belong to something else than the Benchy tree: a tree swap never moves them, and a tree
# archive that carries one is refused
$ApplyKeepNames = @('php', 'php.new', 'php.prev', 'php.install-old', 'update', 'client', 'client.new', 'client.prev', 'engine', 'engine.new', 'engine.prev',
	'state', 'BenchyClient', 'cygwin-root.txt', 'uninstall.exe', 'tree.new', 'tree.prev', 'tree.swap')

function J([string]$Base, [string[]]$Parts)
{
	foreach ($Part in $Parts) { $Base = Join-Path $Base $Part }
	return $Base
}

# --- Test seam: a crash point does nothing, unless a test redefines it ---------------------------------------------------------
function Test-CrashPoint([string]$Name) { }

# --- Files that must be plain ---------------------------------------------------------------------------------------------------

function Test-ApplyPlain([string]$Path)
{
	# True when $Path exists and is not a link or reparse point
	$Item = Get-Item -LiteralPath $Path -Force -ErrorAction SilentlyContinue
	return ($null -ne $Item) -and -not ($Item.Attributes -band [IO.FileAttributes]::ReparsePoint)
}

function Test-ApplyName([string]$Name)
{
	# A single, ordinary file or folder name
	return $Name -match '^[A-Za-z0-9_][A-Za-z0-9._ +()-]{0,100}$' -and $Name -notmatch '[. ]$'
}

# --- The journal ----------------------------------------------------------------------------------------------------------------

function Get-ApplyJournalPath([string]$Install) { return (J $Install @('update', 'state.json')) }

function Write-ApplyJournal([string]$Install, [string]$Op, $Ops, [string]$Sequence)
{
	# Written whole, atomically, BEFORE the first rename. Paths are relative to the install dir. Recovery needs nothing else: each
	# operation is undone only if its result is there and its source is not.
	$Rel = @($Ops | ForEach-Object { [ordered]@{ op = $_.Op; from = $(if ($_.From) { Get-ApplyRel $Install $_.From }); to = $(if ($_.To) { Get-ApplyRel $Install $_.To }) } })
	$Doc = [ordered]@{ schema = $ApplyJournalSchema; op = $Op; sequence = $Sequence; started = (Get-Date).ToUniversalTime().ToString('yyyy-MM-ddTHH:mm:ssZ'); ops = $Rel }
	$Path = Get-ApplyJournalPath $Install
	$Tmp = "$Path.tmp"
	[IO.File]::WriteAllText($Tmp, ($Doc | ConvertTo-Json -Depth 5), (New-Object Text.UTF8Encoding $false))
	if (Test-Path -LiteralPath $Path) { Remove-Item -LiteralPath $Path -Force }
	[IO.File]::Move($Tmp, $Path)
}

function Read-ApplyJournal([string]$Install)
{
	# The operations of an interrupted apply, with full paths, each checked to stay inside the install dir; throws when the file
	# can't be trusted (hand-edited: the old state is then put back by hand, and the message says so)
	$Path = Get-ApplyJournalPath $Install
	$Doc = Get-Content -LiteralPath $Path -Raw | ConvertFrom-Json
	if ($Doc.schema -ne $ApplyJournalSchema) { throw "update\state.json is not a journal this Benchy reads" }
	$Ops = @()
	foreach ($O in @($Doc.ops))
	{
		$Op = [string]$O.op
		if ($Op -ne 'move' -and $Op -ne 'rmdir') { throw 'the journal has an operation this Benchy does not know' }
		$From = if ($O.from) { J $Install ([string]$O.from -split '/') } else { $null }
		$To = if ($O.to) { J $Install ([string]$O.to -split '/') } else { $null }
		foreach ($X in @($From, $To)) { if ($X -and -not (Test-BenchyPathInside $X $Install)) { throw 'the journal names a path outside the install' } }
		foreach ($Rel in @([string]$O.from, [string]$O.to)) { if ($Rel -match '(^|/)\.\.(/|$)|^[/\\]|:') { throw 'the journal names a path outside the install' } }
		$Ops += [pscustomobject]@{ Op = $Op; From = $From; To = $To }
	}
	return $Ops
}

function Get-ApplyRel([string]$Install, [string]$Full)
{
	# $Full relative to $Install with forward slashes, for the journal
	$Root = [IO.Path]::GetFullPath($Install).TrimEnd('\', '/')
	$Abs = [IO.Path]::GetFullPath($Full)
	return $Abs.Substring($Root.Length).TrimStart('\', '/').Replace('\', '/')
}

# --- One rename, with a retry for a scanner holding a freshly written file, and its undo ---------------------------------------

function Move-ApplyItem([string]$From, [string]$To)
{
	$Parent = Split-Path -Parent $To
	if ($Parent -and -not (Test-Path -LiteralPath $Parent)) { [void](New-Item -ItemType Directory -Path $Parent -Force) }
	for ($Try = 1; ; $Try++)
	{
		try
		{
			if (Test-Path -LiteralPath $From -PathType Container) { [IO.Directory]::Move($From, $To) } else { [IO.File]::Move($From, $To) }
			return
		}
		catch
		{
			if ($Try -ge 5) { throw }
			Start-Sleep -Milliseconds (500 * $Try)
		}
	}
}

function Undo-ApplyOps($Ops)
{
	# Newest first. An operation is undone only when its result is there and its source is not, so running this twice, or after a
	# crash at any point, is harmless. Returns what could not be put back, one text each.
	$Left = @()
	$All = @($Ops)
	for ($I = $All.Count - 1; $I -ge 0; $I--)
	{
		$O = $All[$I]
		try
		{
			if ($O.Op -eq 'rmdir')
			{
				if (-not (Test-Path -LiteralPath $O.To)) { [void](New-Item -ItemType Directory -Path $O.To -Force) }
			}
			elseif ((Test-Path -LiteralPath $O.To) -and -not (Test-Path -LiteralPath $O.From))
			{
				Move-ApplyItem $O.To $O.From
			}
		}
		catch { $Left += "$($O.To) could not be moved back to $($O.From) ($($_.Exception.Message))" }
	}
	return $Left
}

function Invoke-ApplyOps([string]$Install, [string]$Op, $Ops, [string]$Sequence)
{
	# Journal first, then every operation in order; any failure undoes all of them and rethrows with exit code 40
	Write-ApplyJournal $Install $Op $Ops $Sequence
	Test-CrashPoint 'journal'
	$Done = @()
	$N = 0
	try
	{
		foreach ($O in @($Ops))
		{
			if ($O.Op -eq 'rmdir') { if (Test-Path -LiteralPath $O.To) { Remove-Item -LiteralPath $O.To -Force } }
			else { Move-ApplyItem $O.From $O.To }
			$Done += $O
			$N++
			Test-CrashPoint "move-$N"
		}
	}
	catch
	{
		$Why = $_.Exception.Message
		$Left = @(Undo-ApplyOps $Done)
		if ($Left.Count -gt 0) { Stop-Benchy 41 "A step of the update failed ($Why) and putting the earlier version back did not finish: $($Left -join '; '). Run benchy once more to finish putting it back." }
		Remove-ApplyJournal $Install
		Remove-ApplyLeftovers $Install
		Stop-Benchy 40 "The update could not be put in place ($Why). The earlier version was put back."
	}
}

function Remove-ApplyJournal([string]$Install)
{
	Remove-Item -LiteralPath (Get-ApplyJournalPath $Install) -Force -ErrorAction SilentlyContinue
}

# --- The apply lock: Recover must never undo an apply that is still running ----------------------------------------------------

function Open-ApplyLock([string]$Install)
{
	# Held exclusively (no sharing) for the whole apply or recover. Returns the stream, or $null when somebody holds it.
	$Path = J $Install @('update', 'apply.lock')
	try { return [IO.File]::Open($Path, [IO.FileMode]::OpenOrCreate, [IO.FileAccess]::ReadWrite, [IO.FileShare]::None) }
	catch [IO.IOException] { return $null }
}

# --- What is running ------------------------------------------------------------------------------------------------------------

function Get-ApplyBlockers([string]$Install)
{
	# php.exe and benchy.bat windows of this install: PHP and the tree are replaced, and a PHP run (a test, the result viewer, the PHP
	# Phoromatic client) holds them. The client and the engine are not looked at here: the client has its own quit handshake, and the
	# engine running this script is neither PHP nor the tree.
	return @(Get-BenchyProcesses $Install | Where-Object { $_.Name -eq 'php.exe' -or $_.Name -eq 'cmd.exe' })
}

function Get-ApplyClientProcesses([string]$Install)
{
	return @(Get-BenchyProcesses $Install | Where-Object { $_.Name -eq 'BenchyClient.exe' })
}

# --- The client quit and relaunch handshake (design 13.9) -----------------------------------------------------------------------

function Clear-ClientRequests([string]$Install)
{
	foreach ($Name in @('client-quit.request', 'client-relaunch.json'))
	{
		$P = J $Install @('update', $Name)
		$Item = Get-Item -LiteralPath $P -Force -ErrorAction SilentlyContinue
		if ($Item -and -not $Item.PSIsContainer -and -not ($Item.Attributes -band [IO.FileAttributes]::ReparsePoint)) { Remove-Item -LiteralPath $P -Force -ErrorAction SilentlyContinue }
	}
}

function Request-ClientQuit([string]$Install, [int]$WaitSeconds)
{
	# Creates update\client-quit.request and waits for the client process to go. Returns $true when it has (or was not running).
	# The client deletes the request itself when it starts quitting.
	if (@(Get-ApplyClientProcesses $Install).Count -eq 0) { return $true }
	[IO.File]::WriteAllText((J $Install @('update', 'client-quit.request')), 'quit')
	$Deadline = (Get-Date).AddSeconds($WaitSeconds)
	while ((Get-Date) -lt $Deadline)
	{
		if (@(Get-ApplyClientProcesses $Install).Count -eq 0) { $Script:ClientQuit = $true; return $true }
		Start-Sleep -Seconds 1
	}
	return $false
}

function Get-ClientRelaunchArgs([string]$Install)
{
	# {"args": [...]} written by the client before it quit; missing or unreadable means --tray
	try
	{
		$Doc = Get-Content -LiteralPath (J $Install @('update', 'client-relaunch.json')) -Raw | ConvertFrom-Json
		$Args2 = @($Doc.args | ForEach-Object { [string]$_ })
		foreach ($A in $Args2) { if ($A -notmatch '^--[a-z][a-z0-9-]{0,30}$') { return @('--tray') } }
		return $Args2
	}
	catch { return @('--tray') }
}

function Start-ClientAgain([string]$Install)
{
	$Exe = J $Install @('client', 'BenchyClient.exe')
	if (-not (Test-Path -LiteralPath $Exe -PathType Leaf)) { return }
	$Start = @{ FilePath = $Exe; WorkingDirectory = $HOME }
	$Relaunch = @(Get-ClientRelaunchArgs $Install)
	if ($Relaunch.Count -gt 0) { $Start['ArgumentList'] = $Relaunch }
	try { Start-Process @Start | Out-Null } catch { Write-Host "The Benchy client could not be started again ($($_.Exception.Message))." }
}

# --- Visual C++ runtime from a staged, already verified installer ---------------------------------------------------------------

function Install-StagedVcRuntime([string]$StagedExe, [string]$Min, [long]$Size, [string]$Sha256)
{
	# The engine checked this file's size and sha256 against the signed index. Here it is copied to a folder of its own, held open (so
	# nothing can swap it), checked again, checked for Microsoft's signature, and run (Windows asks for permission). Same result
	# object as Install-VcRuntime: Ok, Reboot, RebootRequired, Rejected, Message.
	$VcDir = Join-Path ([IO.Path]::GetTempPath()) "Benchy-vc-$([guid]::NewGuid().ToString('N'))"
	$ExePath = Join-Path $VcDir 'VC_redist.x64.exe'
	New-Item -ItemType Directory -Path $VcDir | Out-Null
	try
	{
		Copy-Item -LiteralPath $StagedExe -Destination $ExePath
		$Stream = Open-VcRedistPinned $ExePath
		try
		{
			if (Test-BenchyReparsePoint $VcDir) { throw "$VcDir is a junction or symbolic link" }
			$FinalPath = Get-BenchyFinalPath $Stream.SafeFileHandle
			$Mismatch = Get-VcRedistMismatch $Stream ([pscustomobject]@{ size = $Size; sha256 = $Sha256; version = $Min })
			if ($Mismatch) { return [pscustomobject]@{ Ok = $false; Reboot = $false; RebootRequired = $false; Rejected = $true; Message = $BenchyVcRejectedMessage } }
			$Problem = Test-MicrosoftSignature $ExePath
			if ($Problem) { return [pscustomobject]@{ Ok = $false; Reboot = $false; RebootRequired = $false; Rejected = $true; Message = "The Visual C++ Runtime installer was not run: $Problem." } }
			Write-Host 'Running the Visual C++ Runtime installer (Windows may ask for permission)...'
			try
			{
				$Process = Start-Process -FilePath $FinalPath -ArgumentList '/install /quiet /norestart' -Wait -PassThru
				$Code = [int]$Process.ExitCode
			}
			catch
			{
				$Inner = $_.Exception
				while ($Inner -and -not ($Inner -is [System.ComponentModel.Win32Exception])) { $Inner = $Inner.InnerException }
				if (-not $Inner) { return [pscustomobject]@{ Ok = $false; Reboot = $false; RebootRequired = $false; Rejected = $false; Message = "The Visual C++ Runtime installer could not be started: $($_.Exception.Message)" } }
				$Code = $Inner.NativeErrorCode
			}
		}
		finally { $Stream.Dispose() }
		return Get-VcRuntimeResult $Min $Code
	}
	catch
	{
		return [pscustomobject]@{ Ok = $false; Reboot = $false; RebootRequired = $false; Rejected = $true; Message = "The Visual C++ Runtime installer was not run: $($_.Exception.Message)" }
	}
	finally
	{
		try
		{
			if (-not (Test-BenchyReparsePoint $VcDir)) { [void](Remove-BenchyTree $VcDir) }
			[IO.Directory]::Delete($VcDir, $false)
		}
		catch { }
	}
}

# --- Building the operations ---------------------------------------------------------------------------------------------------

function Get-TreeLiveNames([string]$Install)
{
	# Top-level items of the install that belong to the Benchy tree: everything but the kept names, php.failed-* and leftovers
	return @(Get-ChildItem -LiteralPath $Install -Force | Where-Object {
		$N = $_.Name
		($ApplyKeepNames -notcontains $N) -and ($N -notlike 'php.failed-*') -and ($ApplyKeepNames.ToLowerInvariant() -notcontains $N.ToLowerInvariant())
	} | ForEach-Object { $_.Name })
}

function New-DirSwapOps([string]$Install, [string]$Name)
{
	# <Name> to <Name>.prev (when there is one), then <Name>.new to <Name>
	$Ops = @()
	if (Test-Path -LiteralPath (J $Install @($Name))) { $Ops += [pscustomobject]@{ Op = 'move'; From = (J $Install @($Name)); To = (J $Install @("$Name.prev")) } }
	$Ops += [pscustomobject]@{ Op = 'move'; From = (J $Install @("$Name.new")); To = (J $Install @($Name)) }
	return $Ops
}

function New-TreeSwapOps([string]$Install)
{
	# Every Benchy-tree item of the install goes into tree.prev\, then every item of tree.new\ comes in
	$Ops = @()
	foreach ($N in @(Get-TreeLiveNames $Install))
	{
		$Ops += [pscustomobject]@{ Op = 'move'; From = (J $Install @($N)); To = (J $Install @('tree.prev', $N)) }
	}
	foreach ($Item in @(Get-ChildItem -LiteralPath (J $Install @('tree.new')) -Force))
	{
		$Ops += [pscustomobject]@{ Op = 'move'; From = $Item.FullName; To = (J $Install @($Item.Name)) }
	}
	return $Ops
}

function Remove-ApplyDir([string]$Path)
{
	# Deletes a folder without following links; throws when something is left (a deleted .prev that is not really gone must not
	# be mistaken for an empty slot)
	if (-not (Test-Path -LiteralPath $Path)) { return }
	if (Test-BenchyReparsePoint $Path) { [IO.Directory]::Delete($Path, $false); return }
	$Failed = @(Remove-BenchyTree $Path)
	if ($Failed.Count -gt 0) { throw "$($Failed[0]) could not be deleted" }
	[IO.Directory]::Delete($Path, $false)
}

function Remove-ApplyLeftovers([string]$Install)
{
	foreach ($N in @('php.new', 'client.new', 'tree.new')) { Remove-ApplyDir (J $Install @($N)) }
	$TreePrev = J $Install @('tree.prev')
	if ((Test-ApplyPlain $TreePrev) -and @(Get-ChildItem -LiteralPath $TreePrev -Force).Count -eq 0) { [IO.Directory]::Delete($TreePrev, $false) }
}

function Clear-ApplyStaged([string]$Install)
{
	# A killed apply leaves its plan and the unpacked, staged files: they are for an apply that did not happen, and the engine stages afresh
	$Plan = J $Install @('update', 'plan.json')
	if (Test-ApplyPlain $Plan) { Remove-Item -LiteralPath $Plan -Force -ErrorAction SilentlyContinue }
	try { Remove-ApplyDir (J $Install @('update', 'staging')) } catch { }
	Clear-ClientRequests $Install
}

function Copy-ApplyRecoverScripts([string]$Install)
{
	# update\recover\apply-update.ps1 and benchy-windows.ps1: what benchy.bat and the engine recover with when the tree is mid-swap
	$Dir = J $Install @('update', 'recover')
	Remove-ApplyDir $Dir
	[void](New-Item -ItemType Directory -Path $Dir)
	foreach ($Name in @('apply-update.ps1', 'benchy-windows.ps1')) { Copy-Item -LiteralPath (Join-Path $PSScriptRoot $Name) -Destination (Join-Path $Dir $Name) }
}

# --- Recover --------------------------------------------------------------------------------------------------------------------

function Invoke-ApplyRecover([string]$Install)
{
	$Journal = Get-ApplyJournalPath $Install
	if (-not (Test-Path -LiteralPath $Journal)) { Write-Host 'Nothing to recover.'; return 0 }
	$Lock = Open-ApplyLock $Install
	if (-not $Lock) { Write-Host 'An update is being applied right now; not touching it.'; return 42 }
	try
	{
		try { $Ops = @(Read-ApplyJournal $Install) }
		catch { Stop-Benchy 41 "An update was interrupted and its journal ($Journal) can not be read ($($_.Exception.Message)). Benchy is not usable until it is put right: rename php.prev, client.prev and the items in tree.prev back by hand, then delete $Journal." }
		$Left = @(Undo-ApplyOps $Ops)
		if ($Left.Count -gt 0) { Stop-Benchy 41 "An update was interrupted and putting the earlier version back did not finish: $($Left -join '; ')." }
		Remove-ApplyJournal $Install   # first: the leftovers are what makes undoing the journal a second time unsafe
		Remove-ApplyLeftovers $Install
		Clear-ApplyStaged $Install
		Write-Host 'An interrupted update was undone: the earlier version is back.'
		return 0
	}
	finally { $Lock.Dispose() }
}

# --- The plan -------------------------------------------------------------------------------------------------------------------

function Read-ApplyPlan([string]$Install, [string]$PlanPath, [string]$StageRoot)
{
	# The engine has verified this plan against the signed index; the checks here only keep what the script joins to a path plain
	$Update = J $Install @('update')
	if ([IO.Path]::GetFullPath($PlanPath) -ne [IO.Path]::GetFullPath((J $Install @('update', 'plan.json')))) { Stop-Benchy 2 'The plan is not update\plan.json.' }
	if (-not (Test-BenchyPathInside $StageRoot (J $Install @('update', 'staging')))) { Stop-Benchy 2 'The staging folder is not under update\staging.' }
	$Plan = Get-Content -LiteralPath $PlanPath -Raw | ConvertFrom-Json
	if ($Plan.schema -ne 'benchy-component-plan/1') { Stop-Benchy 2 'The plan is not a component plan this applier reads.' }
	if ([int]$Plan.applier -gt $BenchyApplierLevel) { Stop-Benchy 2 "The plan needs applier level $($Plan.applier); this one is level $BenchyApplierLevel." }
	$Steps = @()
	foreach ($S in @($Plan.steps))
	{
		$Name = [string]$S.component
		if (@('vcredist', 'php', 'benchy', 'client') -notcontains $Name) { Stop-Benchy 2 "This applier does not install '$Name'." }
		$File = [string]$S.file
		if ($File -notmatch '^[a-z0-9_-]+-[0-9a-f]{16}\.(zip|exe|bin)$') { Stop-Benchy 2 'The plan names a staged file this applier does not accept.' }
		if ([string]$S.to -notmatch '^[0-9]+(\.[0-9]+){1,3}(-[0-9]{1,4})?$') { Stop-Benchy 2 'The plan has a version this applier does not read.' }
		$Steps += [pscustomobject]@{ Name = $Name; To = [string]$S.to; File = (J $StageRoot @($File)); Size = [long]$S.size; Sha256 = [string]$S.sha256; Unpacked = (J $StageRoot @('x', $Name)) }
	}
	if ($Steps.Count -eq 0) { Stop-Benchy 2 'The plan has nothing in it.' }
	return [pscustomobject]@{ Sequence = [string]$Plan.sequence; Steps = $Steps }
}

# --- Checks of what is about to go in ------------------------------------------------------------------------------------------

function Test-NewTree([string]$Dir, [string]$To)
{
	# Throws (exit 2) unless the unpacked Benchy tree is whole: its launcher, its core, this script, and the version the plan names
	foreach ($Rel in @(@('benchy.bat'), @('pts-core', 'phoronix-test-suite.php'), @('pts-core', 'static', 'update', 'apply-update.ps1'), @('pts-core', 'static', 'update', 'php-smoke.php'), @('pts-core', 'static', 'update', 'runtime-pins.json')))
	{
		if (-not (Test-Path -LiteralPath (J $Dir $Rel) -PathType Leaf)) { Stop-Benchy 2 "The Benchy tree to install has no $($Rel -join '\')." }
	}
	foreach ($Item in @(Get-ChildItem -LiteralPath $Dir -Force))
	{
		if ($Item.Attributes -band [IO.FileAttributes]::ReparsePoint) { Stop-Benchy 2 "The Benchy tree to install has a link at $($Item.Name)." }
		if (($ApplyKeepNames.ToLowerInvariant() -contains $Item.Name.ToLowerInvariant()) -or $Item.Name -like 'php.failed-*') { Stop-Benchy 2 "The Benchy tree to install has an item named $($Item.Name), which belongs to something else in the install." }
	}
	$Have = Get-BenchyVersion $Dir
	if ($Have -ne $To) { Stop-Benchy 2 "The Benchy tree to install is version '$Have', not $To." }
}

function Get-PhpVersionParts([string]$To)
{
	$Base, $Rebuild = $To -split '-', 2
	return [pscustomobject]@{ Version = $Base; Rebuild = $(if ($Rebuild) { [int]$Rebuild } else { 0 }) }
}

# --- Apply ----------------------------------------------------------------------------------------------------------------------

function Invoke-ApplyComponents([string]$Install, $Plan, [int]$WaitSeconds)
{
	$ByName = @{}
	foreach ($S in $Plan.Steps) { $ByName[$S.Name] = $S }
	$HasPhp = $ByName.ContainsKey('php'); $HasTree = $ByName.ContainsKey('benchy'); $HasClient = $ByName.ContainsKey('client')

	# 1. Everything is looked at before anything is touched
	$Blockers = @(Get-ApplyBlockers $Install)
	if (($HasPhp -or $HasTree) -and $Blockers.Count -gt 0)
	{
		Stop-Benchy 3 "Benchy is running ($(Format-BenchyProcesses $Blockers)), so the update waits. Nothing was changed."
	}
	foreach ($S in $Plan.Steps)
	{
		if ($S.Name -ne 'vcredist' -and -not (Test-Path -LiteralPath $S.Unpacked -PathType Container)) { Stop-Benchy 2 "The unpacked $($S.Name) is missing. Nothing was changed." }
		if ($S.Name -eq 'vcredist' -and -not (Test-Path -LiteralPath $S.File -PathType Leaf)) { Stop-Benchy 2 'The staged Visual C++ Runtime installer is missing. Nothing was changed.' }
	}
	$TreeDir = $Install
	if ($HasTree) { $TreeDir = $ByName['benchy'].Unpacked; Test-NewTree $TreeDir $ByName['benchy'].To }
	if ($HasClient -and -not (Test-Path -LiteralPath (J $ByName['client'].Unpacked @('BenchyClient.exe')) -PathType Leaf)) { Stop-Benchy 2 'The client to install has no BenchyClient.exe at its top level. Nothing was changed.' }
	$Pin = $null
	if ($HasPhp)
	{
		$Pin = Get-BenchyPin $TreeDir
		if (-not (Test-Path -LiteralPath (J $ByName['php'].Unpacked @('php.exe')) -PathType Leaf)) { Stop-Benchy 2 'The PHP to install has no php.exe at its top level. Nothing was changed.' }
	}

	# A tree with no applier of its own (every install before this update) has no script to recover with once the old tree is moved
	# and before the new one is in. This script may itself be running from the unpacked tree, which is about to be moved, so the copy of
	# it and its lib goes to update\recover before anything at all is moved or installed
	Copy-ApplyRecoverScripts $Install

	# 2. The Visual C++ runtime: first, never rolled back (a newer one is backward compatible)
	if ($ByName.ContainsKey('vcredist'))
	{
		$V = $ByName['vcredist']
		$Min = ($V.To -replace '-.*$', '' -split '\.' | Select-Object -First 3) -join '.'
		$Result = Install-StagedVcRuntime $V.File $Min $V.Size $V.Sha256
		if (-not $Result.Ok) { Stop-Benchy 21 "$($Result.Message) Nothing was changed." }
		Write-Host 'The Visual C++ Runtime is installed.'
	}

	# 3. PHP: unpacked to php.new and run before anything moves (with an ini that points at php.new's own extensions)
	Remove-ApplyLeftovers $Install
	if ($HasPhp)
	{
		$P = $ByName['php']
		Move-ApplyItem $P.Unpacked (J $Install @('php.new'))
		$New = J $Install @('php.new')
		Write-BenchyPhpIni $New $Pin (J $New @('ext'))
		$Parts = Get-PhpVersionParts $P.To
		$Smoke = Test-PhpRuns (J $New @('php.exe')) (J $New @('php.ini')) (J $TreeDir @('pts-core', 'static', 'update', 'php-smoke.php')) $Pin.required_extensions $Parts.Version
		Test-CrashPoint 'php-smoke'
		if (-not $Smoke.Ok) { Remove-ApplyDir $New; Stop-Benchy 30 "The new PHP does not pass its check ($($Smoke.Message)). Nothing was changed." }
		Write-BenchyPhpIni $New $Pin (J $Install @('php', 'ext'))
		$Record = [ordered]@{ version = $Parts.Version; rebuild = $Parts.Rebuild }
		[IO.File]::WriteAllText((J $New @('benchy-php.json')), ($Record | ConvertTo-Json), (New-Object Text.UTF8Encoding $false))
	}
	if ($HasTree) { Move-ApplyItem $ByName['benchy'].Unpacked (J $Install @('tree.new')) }

	# 4. The client has to be gone before its folder moves (13.9)
	if ($HasClient)
	{
		Move-ApplyItem $ByName['client'].Unpacked (J $Install @('client.new'))
		if (-not (Request-ClientQuit $Install $WaitSeconds)) { Remove-ApplyLeftovers $Install; Stop-Benchy 4 "Benchy did not close within $([int]($WaitSeconds / 60)) minutes, so the update was not applied. Nothing was changed." }
		$Held = @(Get-BenchyFilesInUse $Install -SkipPhp | Where-Object { $_ -like '*BenchyClient.exe' })
		if ($Held.Count -gt 0) { Remove-ApplyLeftovers $Install; Stop-Benchy 3 "The client is still in use ($($Held -join ', ')). Nothing was changed." }
	}
	if ($HasPhp -or $HasTree)
	{
		$Again = @(Get-ApplyBlockers $Install)
		if ($Again.Count -gt 0) { Remove-ApplyLeftovers $Install; Stop-Benchy 3 "Benchy was started while the update was working ($(Format-BenchyProcesses $Again)). Nothing was changed." }
		$HeldPhp = @(Get-BenchyFilesInUse $Install | Where-Object { $_ -like '*php.exe' })
		if ($HasPhp -and $HeldPhp.Count -gt 0) { Remove-ApplyLeftovers $Install; Stop-Benchy 3 "PHP is in use ($($HeldPhp -join ', ')). Nothing was changed." }
	}

	# 5. One journaled set of renames for PHP, the tree and the client
	foreach ($N in @('php', 'client', 'tree')) { if ($ByName.ContainsKey($(if ($N -eq 'tree') { 'benchy' } else { $N }))) { Remove-ApplyDir (J $Install @("$N.prev")) } }
	if ($HasTree) { [void](New-Item -ItemType Directory -Path (J $Install @('tree.prev')) -Force) }
	$Ops = @()
	if ($HasPhp) { $Ops += @(New-DirSwapOps $Install 'php') }
	if ($HasTree) { $Ops += @(New-TreeSwapOps $Install) }
	if ($HasClient) { $Ops += @(New-DirSwapOps $Install 'client') }
	Test-CrashPoint 'recover-copied'
	Invoke-ApplyOps $Install 'apply' $Ops $Plan.Sequence
	Test-CrashPoint 'swapped'

	# 6. The swapped-in PHP is run once more, in its final place; a failure puts everything back (client included)
	if ($HasPhp)
	{
		$Final = Test-PhpRuns (J $Install @('php', 'php.exe')) (J $Install @('php', 'php.ini')) (J $Install @('pts-core', 'static', 'update', 'php-smoke.php')) $Pin.required_extensions (Get-PhpVersionParts $ByName['php'].To).Version
		if (-not $Final.Ok)
		{
			$Left = @(Undo-ApplyOps $Ops)
			if ($Left.Count -gt 0) { Stop-Benchy 41 "The new PHP failed its check in place ($($Final.Message)) and putting the earlier version back did not finish: $($Left -join '; ')." }
			Remove-ApplyJournal $Install
			Remove-ApplyLeftovers $Install
			Stop-Benchy 30 "The new PHP failed its check in place ($($Final.Message)). The earlier version was put back."
		}
	}
	Remove-ApplyJournal $Install
	Remove-ApplyLeftovers $Install   # tree.new is an empty folder by now
	if ($HasTree) { Update-ApplyUninstallEntry $Install }
	Write-Host "Updated: $(@($Plan.Steps | ForEach-Object { "$($_.Name) $($_.To)" }) -join ', ')."
}

function Update-ApplyUninstallEntry([string]$Install)
{
	# Apps & Features shows the tree's version; the install source stays what it was. Best effort: a registry that can't be written
	# never undoes an update that is in place.
	try
	{
		$Entry = Get-BenchyUninstallEntry
		$Source = if ($Entry -and $Entry.InstallSource) { [string]$Entry.InstallSource } else { 'bat' }
		Set-BenchyUninstallEntry $Install (Get-BenchyVersion $Install) $Source
	}
	catch { Write-Host "Apps & Features was not updated ($($_.Exception.Message))." }
}

# --- Rollback -------------------------------------------------------------------------------------------------------------------

function Invoke-ApplyRollback([string]$Install, [string]$Only)
{
	# Swaps each component with its .prev (so a rollback can itself be rolled back). The engine has already checked the floor and
	# the revoked list; here PHP and the tree must be quiet and the client must not be running.
	$Names = @('php', 'benchy', 'client') | Where-Object { (-not $Only) -or $Only -eq $_ }
	$Have = @($Names | Where-Object {
		$Prev = if ($_ -eq 'benchy') { 'tree.prev' } else { "$_.prev" }
		(Test-ApplyPlain (J $Install @($Prev))) -and (@(Get-ChildItem -LiteralPath (J $Install @($Prev)) -Force).Count -gt 0)
	})
	if ($Have.Count -eq 0) { Stop-Benchy 2 'There is no earlier version of that to go back to.' }
	$Blockers = @(Get-ApplyBlockers $Install)
	if (($Have -contains 'php' -or $Have -contains 'benchy') -and $Blockers.Count -gt 0) { Stop-Benchy 3 "Benchy is running ($(Format-BenchyProcesses $Blockers)). Nothing was changed." }
	if (($Have -contains 'client') -and @(Get-ApplyClientProcesses $Install).Count -gt 0) { Stop-Benchy 3 'The Benchy window is open: quit Benchy from its tray icon first. Nothing was changed.' }
	Remove-ApplyLeftovers $Install
	$Ops = @()
	foreach ($N in $Have)
	{
		if ($N -eq 'benchy')
		{
			foreach ($Live in @(Get-TreeLiveNames $Install)) { $Ops += [pscustomobject]@{ Op = 'move'; From = (J $Install @($Live)); To = (J $Install @('tree.swap', $Live)) } }
			foreach ($Item in @(Get-ChildItem -LiteralPath (J $Install @('tree.prev')) -Force)) { $Ops += [pscustomobject]@{ Op = 'move'; From = $Item.FullName; To = (J $Install @($Item.Name)) } }
			$Ops += [pscustomobject]@{ Op = 'rmdir'; From = $null; To = (J $Install @('tree.prev')) }
			$Ops += [pscustomobject]@{ Op = 'move'; From = (J $Install @('tree.swap')); To = (J $Install @('tree.prev')) }
		}
		else
		{
			$Ops += [pscustomobject]@{ Op = 'move'; From = (J $Install @($N)); To = (J $Install @("$N.swap")) }
			$Ops += [pscustomobject]@{ Op = 'move'; From = (J $Install @("$N.prev")); To = (J $Install @($N)) }
			$Ops += [pscustomobject]@{ Op = 'move'; From = (J $Install @("$N.swap")); To = (J $Install @("$N.prev")) }
		}
	}
	Invoke-ApplyOps $Install 'rollback' $Ops 'rollback'
	Test-CrashPoint 'rolled-back'
	if ($Have -contains 'php')
	{
		$Pin = Get-BenchyPin $Install
		$Check = Test-PhpRuns (J $Install @('php', 'php.exe')) (J $Install @('php', 'php.ini')) (J $Install @('pts-core', 'static', 'update', 'php-smoke.php')) $Pin.required_extensions
		if (-not $Check.Ok)
		{
			$Left = @(Undo-ApplyOps $Ops)
			if ($Left.Count -gt 0) { Stop-Benchy 41 "The earlier PHP does not run ($($Check.Message)) and the newer one could not be put back: $($Left -join '; ')." }
			Remove-ApplyJournal $Install
			Stop-Benchy 30 "The earlier PHP does not run ($($Check.Message)). The newer one was left in place."
		}
	}
	Remove-ApplyJournal $Install
	if ($Have -contains 'benchy') { Update-ApplyUninstallEntry $Install }
	Write-Host "Went back: $($Have -join ', ')."
}

# --- Main -----------------------------------------------------------------------------------------------------------------------

function Invoke-BenchyApplyMain
{
	$Install = [IO.Path]::GetFullPath($InstallDir).TrimEnd('\', '/')
	$ExitCode = 0
	$Mode = if ($Recover) { 'recover' } elseif ($Rollback) { 'rollback' } else { 'apply' }
	try
	{
		if (-not (Test-ApplyPlain (J $Install @('update')))) { Stop-Benchy 2 "$Install\update is missing or is a link." }
		if ($Mode -eq 'recover') { return (Invoke-ApplyRecover $Install) }
		if (Test-Path -LiteralPath (Get-ApplyJournalPath $Install)) { Stop-Benchy 42 'An update was interrupted and has not been recovered yet; running benchy.bat finishes that. Nothing was changed.' }
		$Lock = Open-ApplyLock $Install
		if (-not $Lock) { Stop-Benchy 42 'Another update is already running. Nothing was changed.' }
		try
		{
			if ($Mode -eq 'rollback') { Invoke-ApplyRollback $Install $Component }
			else
			{
				$Plan = Read-ApplyPlan $Install $PlanPath $StageRoot
				try { Invoke-ApplyComponents $Install $Plan $ClientWaitSeconds }
				finally
				{
					# The client quit, whatever the outcome: start it again (the old one, when the update did not happen)
					if ($Script:ClientQuit) { Start-ClientAgain $Install }
				}
			}
		}
		finally
		{
			# Whatever failed before the journal was written leaves nothing behind: the old install, intact (with a journal, -Recover is what finishes)
			if (-not (Test-Path -LiteralPath (Get-ApplyJournalPath $Install))) { try { Remove-ApplyLeftovers $Install } catch { } }
			$Lock.Dispose()
		}
	}
	catch
	{
		$ExitCode = Get-BenchyErrorCode $_ 50
		[Console]::Error.WriteLine($_.Exception.Message)
	}
	finally
	{
		# A request the client has not acted on, or a relaunch note nobody will use, must not outlive this run (13.9)
		if ($Mode -eq 'apply' -or $Mode -eq 'rollback') { Clear-ClientRequests $Install }
	}
	return $ExitCode
}

if (-not $DefineOnly)
{
	$Result = @(Invoke-BenchyApplyMain)
	exit [int]$Result[-1]
}
