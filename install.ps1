<#
	Benchy Windows Installer
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

	Per-user installer: installs to %LOCALAPPDATA%\Benchy (fixed; the updater only manages an install there), so no
	admin rights are needed for the install itself. Only the Visual C++ Runtime install may prompt for elevation, and
	only when the runtime is missing or older than the pin's vcredist_min.

	install.ps1 [-NonInteractive] [-PhoromaticServer <[https://]ip:port/accountid>] [-PhpZip <path> [-PhpZipMode Always|IfOlder]]
	            [-ClientZip <path>] [-UseExistingPhp] [-VcRedist Auto|Never] [-ResultFile <path>] [-LogFile <path>] [-InstallSource bat|gui]

	-NonInteractive   never prompt. Without -PhoromaticServer the Phoromatic setting is left as it is.
	-PhoromaticServer [https://]ip:port/accountid to save as the Phoromatic server (https:// for a server with the built-in HTTPS; then run benchy server-trust once).
	-PhpZip           install the PHP from this local zip instead of downloading; it must match the pin (exit code 11
	                  otherwise). It always installs: an existing managed PHP is replaced. Not with -UseExistingPhp.
	-PhpZipMode       IfOlder (the GUI installer): the -PhpZip PHP replaces an existing managed PHP only when that one
	                  fails Benchy's PHP check or is older than the pin, so a rerun repairs a damaged PHP but never
	                  downgrades one the updater moved forward. The existing PHP is run to decide. One that passes and
	                  is the pin's version or newer is kept, and so is one that passes but whose PHP_VERSION cannot be
	                  read (with a warning). The zip is checked against the pin either way. The result file then has
	                  php_zip_decision: installed (no managed php.exe), replaced (older), replaced-broken (failed the
	                  check), kept-newer-or-equal or kept-unknown. Always (the default) is -PhpZip alone.
	-ClientZip        also install the desktop client (BenchyClient-<ver>-windows-x64.zip) into <install dir>\client. It
	                  must match the client pin in runtime-pins.json (exit code 11 otherwise). It is unpacked to
	                  client.new and swapped in; the client it replaces is kept as client.prev. The Start Menu "Benchy"
	                  shortcut then opens the client, and "Benchy Command Prompt" opens benchy.bat. Without -ClientZip a
	                  client that is already installed is kept as it is (install.bat from a zip release installs the
	                  command line only).
	-UseExistingPhp   use a PHP that is already there (PHP_BIN, Benchy\php, php.exe on PATH, C:\PHP) instead of
	                  always installing Benchy's own. The updater cannot manage an install that uses another PHP.
	-VcRedist         Auto installs the runtime when it is missing or too old (may show a UAC prompt); Never fails
	                  with exit code 20 instead. Default: Auto, except Never for -NonInteractive when not elevated.
	-ResultFile       JSON result, written on success and on failure. Not inside the install dir.
	-LogFile          transcript; default %TEMP%\Benchy-install.log. Not inside the install dir.
	-InstallSource    recorded in the Apps & Features entry for diagnostics; the GUI installer passes gui.
	-VcRedistHandOff  (the GUI installer) when the VC++ runtime would be installed, save the checked VC_redist.x64.exe
	                  to this path (in a folder of its own, created if needed; exit code 22 when that folder is a
	                  junction or symbolic link) and stop with exit code 4 instead of
	                  running it. The caller checks it again against the size and sha256 built into it, holding it
	                  open, and runs it elevated from its own window, so the UAC prompt comes to the front (run from
	                  install.ps1's hidden console it only flashed in the taskbar), then runs install.ps1 again with
	                  -VcRedistExitCode, or with -VcRedistRejected when the check failed.
	-VcRedistExitCode (the GUI installer) the exit code of that VC_redist.x64.exe /install /quiet /norestart run, or
	                  the Win32 error when it could not start (1223: the UAC prompt was declined). Step 4 then maps it
	                  as if this script had run the installer, and the log is appended to, not restarted.
	-VcRedistRejected (the GUI installer) the saved VC_redist.x64.exe changed after this script checked it, so the
	                  caller did not run it: step 4 stops with exit code 22, and the log is appended to.

	Use the full parameter names: PowerShell also accepts a unique prefix, and -Ph matches two. Arguments PowerShell
	itself cannot bind (an ambiguous name, -PhpZip without a path) are rejected before this script runs, with exit
	code 1 and no result file; every other failure writes the result file.

	Exit codes (2 to 22 mean Benchy itself was not changed; only 21 with reboot_required may have installed the VC++
	runtime):
	  0     installed
	  3010  installed; the VC++ runtime asked for a reboot
	  2     refused: bad arguments, not a Benchy folder, running from inside the install dir, running as SYSTEM or
	        without a user profile (LOCALAPPDATA/TEMP unset), or not 64-bit Windows
	  3     refused: Benchy is running (benchy.bat, its PHP, or the desktop client from this install)
	  4     only with -VcRedistHandOff: the VC++ runtime installer is saved there for the caller to run; nothing was
	        changed
	  10    PHP download failed or did not match the pin
	  11    the -PhpZip or -ClientZip file does not match the pin
	  20    VC++ runtime missing and -VcRedist Never
	  21    VC++ runtime install cancelled at the UAC prompt, or failed; with reboot_required true in the result file,
	        it installed but is usable only after a restart (restart, then run the installer again)
	  22    the VC++ runtime installer failed its check just before it would run (its size or sha256 no longer matched
	        the pin when checked again, held open, or its folder was a junction or symbolic link), so it was not run
	  30    the PHP to be used does not start or lacks a required extension; nothing was copied, and a PHP this run
	        extracted is removed again (or moved to php.failed-<timestamp> when it cannot be deleted) and an older
	        managed PHP it was replacing is put back; the message names any folder that needs a hand
	  40    copying Benchy failed (partial copy; run the installer again)
	  50    anything else, including the installed copy failing its final self-check (see the log)
#>

param(
	[switch]$NonInteractive,
	[string]$PhoromaticServer,
	[string]$PhpZip,
	[string]$PhpZipMode,
	[string]$ClientZip,
	[switch]$UseExistingPhp,
	[string]$VcRedist,
	[string]$ResultFile,
	[string]$LogFile,
	[string]$InstallSource = 'bat',
	[string]$VcRedistHandOff,
	[string]$VcRedistExitCode,
	[switch]$VcRedistRejected
)

$ErrorActionPreference = 'Stop'

$Result = [ordered]@{
	result = 'failed'
	code = 50
	message = $null
	benchy_version = $null
	php_version = $null
	php_path = $null
	client_version = $null
	previous_version = $null
	reboot_recommended = $false
	reboot_required = $false
	vcredist = 'skipped'
	warnings = @()
}
$SourceDir = $PSScriptRoot
$InstallDir = $null
$PhoromaticPattern = '^(https://)?[A-Za-z0-9.-]+:[0-9]{1,5}/[A-Za-z0-9]+$'
$Transcribing = $false
$DownloadedZip = $null

function Stop-EarlyInstall([int]$Code, [string]$Message)
{
	# Stop-Benchy for the checks that run before the shared lib is loaded
	$Exception = New-Object System.Exception $Message
	$Exception.Data['BenchyCode'] = $Code
	throw $Exception
}

function Write-InstallResult
{
	if (-not $ResultFile) { return }
	try
	{
		$Json = New-Object PSObject -Property $Result | ConvertTo-Json -Depth 3
		[IO.File]::WriteAllText([IO.Path]::GetFullPath($ResultFile), $Json, (New-Object System.Text.UTF8Encoding $false))
	}
	catch
	{
		Write-Host "Could not write the result file ${ResultFile}: $($_.Exception.Message)" -ForegroundColor Red
	}
}

function Add-InstallWarning([string]$Text)
{
	Write-Host "Warning: $Text" -ForegroundColor Yellow
	$Result.warnings = @($Result.warnings) + $Text
}

function Find-ExistingPhp
{
	# Only with -UseExistingPhp: the search order install.ps1 used before it always installed its own PHP
	if ($env:PHP_BIN -and (Test-Path -LiteralPath $env:PHP_BIN.Trim('"'))) { return $env:PHP_BIN.Trim('"') }
	$Managed = Join-Path $InstallDir 'php\php.exe'
	if (Test-Path -LiteralPath $Managed) { return $Managed }
	$Cmd = Get-Command 'php.exe' -ErrorAction SilentlyContinue
	if ($Cmd) { return $Cmd.Source }
	# Where older benchy.bat launchers downloaded PHP to (benchy.bat still uses one it finds there)
	if (Test-Path 'C:\PHP\php.exe') { return 'C:\PHP\php.exe' }
	return $null
}

function Get-NativeArchitecture
{
	# The OS architecture, also from an x64 process emulated on ARM64 (where PROCESSOR_ARCHITECTURE says AMD64)
	$Base = [Microsoft.Win32.RegistryKey]::OpenBaseKey([Microsoft.Win32.RegistryHive]::LocalMachine, [Microsoft.Win32.RegistryView]::Registry64)
	try
	{
		$Key = $Base.OpenSubKey('SYSTEM\CurrentControlSet\Control\Session Manager\Environment')
		if (-not $Key) { return $env:PROCESSOR_ARCHITECTURE }
		try { return [string]$Key.GetValue('PROCESSOR_ARCHITECTURE') } finally { $Key.Close() }
	}
	finally
	{
		$Base.Close()
	}
}

function Get-FailedPhpName
{
	return 'php.failed-' + (Get-Date -Format 'yyyyMMdd-HHmmss')
}

function Clear-FailedPhp
{
	# Removes the managed php folder after a failed setup. Returns a note for the message, or $null when it is gone.
	# A folder that cannot be deleted is renamed to php.failed-<timestamp>, so no broken PHP is left where the next
	# run (or benchy.bat) would pick it up; a later install deletes it.
	if (-not (Test-Path -LiteralPath $ManagedPhpDir)) { return $null }
	Remove-Item -LiteralPath $ManagedPhpDir -Recurse -Force -ErrorAction SilentlyContinue
	if (-not (Test-Path -LiteralPath $ManagedPhpDir)) { return $null }
	$FailedName = Get-FailedPhpName
	try
	{
		Rename-Item -LiteralPath $ManagedPhpDir -NewName $FailedName
		return "The PHP that failed could not be deleted and was moved to $(Join-Path $InstallDir $FailedName)."
	}
	catch
	{
		return "The PHP that failed could not be deleted or moved; delete $ManagedPhpDir before running the installer again."
	}
}

function Restore-AsidePhp
{
	# Puts php.install-old back as php after a failed swap. Returns notes for the message (none when restored).
	$Notes = @()
	$Note = Clear-FailedPhp
	if ($Note) { $Notes += $Note }
	if (Test-Path -LiteralPath $ManagedPhpDir)
	{
		$Notes += "The previous PHP is still in $AsideDir; rename it to php by hand."
		return $Notes
	}
	try
	{
		Rename-Item -LiteralPath $AsideDir -NewName 'php'
	}
	catch
	{
		$Notes += "The previous PHP could not be put back and is in $AsideDir; rename it to php by hand ($($_.Exception.Message))."
	}
	return $Notes
}

function Read-PhoromaticServer
{
	while ($true)
	{
		$Answer = ([string](Read-Host 'Phoromatic server to connect to, as ip:port/accountid or https://host:port/accountid (leave blank to skip)')).Trim()
		if (-not $Answer -or $Answer -match $PhoromaticPattern) { return $Answer }
		Write-Host "  '$Answer' is not [https://]ip:port/accountid, for example 10.0.0.5:8080/ABC123." -ForegroundColor Yellow
	}
}

try
{
	if (-not $env:LOCALAPPDATA -or -not $env:TEMP)
	{
		Stop-EarlyInstall 2 'LOCALAPPDATA or TEMP is not set, so there is no user profile to install into. Run the installer as a normal logged-on user.'
	}
	$InstallDir = Join-Path $env:LOCALAPPDATA 'Benchy'
	if (-not $LogFile) { $LogFile = Join-Path $env:TEMP 'Benchy-install.log' }
	if (-not (Test-Path -LiteralPath (Join-Path $SourceDir 'pts-core\phoronix-test-suite.php')))
	{
		Stop-EarlyInstall 2 "$SourceDir is not a Benchy folder (pts-core\phoronix-test-suite.php is missing)."
	}
	. (Join-Path $SourceDir 'pts-core\static\update\benchy-windows.ps1')

	# --- 1. Preflight: everything up to step 5 writes nothing -----------------

	# The log and the result file go outside the install dir: robocopy /MIR would delete them
	foreach ($OutFile in @($LogFile, $ResultFile))
	{
		if ($OutFile -and (Test-BenchyPathInside $OutFile $InstallDir))
		{
			Stop-Benchy 2 "The log and result files must be outside $InstallDir ($OutFile is not)."
		}
	}
	try
	{
		# The second run after a -VcRedistHandOff adds to the first one's log
		Start-Transcript -LiteralPath $LogFile -Force -Append:([bool]$VcRedistExitCode -or $VcRedistRejected) | Out-Null
		$Transcribing = $true
	}
	catch
	{
		Write-Host "Could not start the log at ${LogFile}: $($_.Exception.Message)" -ForegroundColor Yellow
	}

	Write-Host 'Benchy Windows Installer' -ForegroundColor Cyan
	Write-Host "Installing to: $InstallDir"
	Write-Host "Source: $SourceDir"
	Write-Host "64-bit PowerShell: $([Environment]::Is64BitProcess)"
	Write-Host ''

	if ($args.Count -gt 0) { Stop-Benchy 2 "Unknown argument(s): $($args -join ' ')" }
	if ($InstallSource -notin @('bat', 'gui')) { Stop-Benchy 2 "-InstallSource must be bat or gui, not '$InstallSource'." }
	if ($VcRedist -and $VcRedist -notin @('Auto', 'Never')) { Stop-Benchy 2 "-VcRedist must be Auto or Never, not '$VcRedist'." }
	if ($PhpZip -and $UseExistingPhp) { Stop-Benchy 2 '-PhpZip installs that PHP, so it cannot be combined with -UseExistingPhp.' }
	if ($PhpZipMode -and $PhpZipMode -notin @('Always', 'IfOlder')) { Stop-Benchy 2 "-PhpZipMode must be Always or IfOlder, not '$PhpZipMode'." }
	if ($PhpZipMode -and -not $PhpZip) { Stop-Benchy 2 '-PhpZipMode needs -PhpZip.' }
	if ($PSBoundParameters.ContainsKey('ClientZip') -and -not $ClientZip) { Stop-Benchy 2 '-ClientZip needs a path.' }
	if ($VcRedistExitCode -and $VcRedistExitCode -notmatch '^-?[0-9]{1,10}$') { Stop-Benchy 2 "-VcRedistExitCode must be a number, not '$VcRedistExitCode'." }
	if ($VcRedistExitCode -and $VcRedistRejected) { Stop-Benchy 2 '-VcRedistExitCode and -VcRedistRejected cannot be combined.' }
	if ($PhoromaticServer -and $PhoromaticServer -notmatch $PhoromaticPattern)
	{
		Stop-Benchy 2 "-PhoromaticServer must be [https://]ip:port/accountid, for example 10.0.0.5:8080/ABC123 or https://seinar:39443/ABC123 (got '$PhoromaticServer')."
	}

	if (-not [Environment]::Is64BitOperatingSystem) { Stop-Benchy 2 'Benchy needs 64-bit Windows (its PHP is x64 only).' }
	if ((Get-NativeArchitecture) -eq 'ARM64')
	{
		Add-InstallWarning 'This is ARM64 Windows. Benchy installs its x64 PHP, which runs under emulation; this is untested.'
	}

	$Identity = [Security.Principal.WindowsIdentity]::GetCurrent()
	if ($Identity.User.Value -in @('S-1-5-18', 'S-1-5-19', 'S-1-5-20'))
	{
		Stop-Benchy 2 "Benchy installs per user and cannot be installed as $($Identity.Name). Run the installer as the user who will run Benchy."
	}
	$IsElevated = (New-Object Security.Principal.WindowsPrincipal $Identity).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)

	if (Test-BenchyPathInside $SourceDir $InstallDir)
	{
		# Mirroring the install dir (or a folder inside it) onto itself would skip the copy or delete the source
		Stop-Benchy 2 "Run the installer from the extracted Benchy folder, not from $InstallDir (it is running from $SourceDir)."
	}
	if (Test-BenchyPathInside $InstallDir $SourceDir)
	{
		# Mirroring a folder onto a folder inside it would copy the install into itself
		Stop-Benchy 2 "Extract Benchy to its own folder: $SourceDir contains the install dir $InstallDir."
	}

	# --- 2. Running Benchy ------------------------------------------------------

	$Running = @(Get-BenchyProcesses $InstallDir)
	if ($Running.Count -gt 0)
	{
		Stop-Benchy 3 "Benchy is running ($(Format-BenchyProcesses $Running)). Close every Benchy window and run the installer again."
	}

	# --- 3. PHP: pin, bundled zip, download -------------------------------------

	$Pin = Get-BenchyPin $SourceDir
	$PinPhpVersion = Get-PinPhpVersion $Pin
	try { [void][version]$PinPhpVersion }
	catch { Stop-Benchy 2 "This Benchy package is damaged: the PHP version in runtime-pins.json ('$($Pin.version)') is not a version number." }
	$Result.benchy_version = Get-BenchyVersion $SourceDir
	$PreviousEntry = Get-BenchyUninstallEntry
	$Result.previous_version = if ($PreviousEntry -and $PreviousEntry.DisplayVersion) { [string]$PreviousEntry.DisplayVersion } else { Get-BenchyVersion $InstallDir }

	if ($PhpZip)
	{
		Write-Host "Checking the bundled PHP zip $PhpZip ..."
		if (-not (Test-PhpZip $PhpZip $Pin)) { Stop-Benchy 11 "The PHP zip $PhpZip does not match the pinned PHP $($Pin.version). Nothing was installed." }
	}
	if ($ClientZip)
	{
		$ClientPin = Get-BenchyClientPin $SourceDir
		if (-not $ClientPin) { Stop-Benchy 2 'This Benchy release pins no desktop client (runtime-pins.json has no windows-x64.client), so -ClientZip cannot be used.' }
		Write-Host "Checking the client zip $ClientZip ..."
		if (-not (Test-PinnedArtifact $ClientZip $ClientPin.artifact)) { Stop-Benchy 11 "The client zip $ClientZip does not match the pinned client $($ClientPin.version). Nothing was installed." }
	}

	$ManagedPhpDir = Join-Path $InstallDir 'php'
	$ManagedPhp = Join-Path $ManagedPhpDir 'php.exe'
	# A verified -PhpZip means "install this PHP": it replaces an existing managed PHP (with -PhpZipMode IfOlder, step 5
	# may keep it instead)
	$PhpExe = $null
	if ($UseExistingPhp) { $PhpExe = Find-ExistingPhp }
	elseif (-not $PhpZip -and (Test-Path -LiteralPath $ManagedPhp)) { $PhpExe = $ManagedPhp }
	$InstallPhp = -not $PhpExe
	if ($InstallPhp) { $PhpExe = $ManagedPhp }
	$PhpIsManaged = (Test-BenchyPathInside $PhpExe $ManagedPhpDir)
	$Result.php_path = $PhpExe

	if ($InstallPhp -and -not $PhpZip)
	{
		Write-Host "Downloading PHP $($Pin.version) (NTS x64)..."
		$DownloadedZip = Join-Path $env:TEMP "Benchy-$($Pin.artifact.file)"
		try
		{
			Get-PinnedPhpZip $Pin $DownloadedZip
		}
		catch
		{
			Stop-Benchy 10 $_.Exception.Message
		}
		$PhpZip = $DownloadedZip
	}

	# --- 4. Visual C++ runtime ----------------------------------------------------

	if (-not $VcRedist) { $VcRedist = if ($NonInteractive -and -not $IsElevated) { 'Never' } else { 'Auto' } }
	$VcMin = [string]$Pin.vcredist_min
	$VcInstalled = Get-VcRuntimeVersion
	if ($VcRedistRejected)
	{
		# The second run after -VcRedistHandOff: the caller's held check of the saved installer failed, so it did not run
		$Result.vcredist = 'rejected'
		Stop-Benchy 22 $BenchyVcRejectedMessage
	}
	elseif ($VcRedistExitCode)
	{
		# The second run after -VcRedistHandOff: the caller ran the installer this script saved
		# The GUI installer passes it unsigned (a DWORD); back to the signed value Start-Process would have given
		$Code = [long]$VcRedistExitCode
		if ($Code -gt [int]::MaxValue) { $Code -= 4294967296 }
		$Vc = Get-VcRuntimeResult $VcMin ([int]$Code)
		if (-not $Vc.Ok)
		{
			$Result.reboot_required = [bool]$Vc.RebootRequired
			Stop-Benchy 21 $Vc.Message
		}
		$Result.vcredist = 'installed'
		$Result.reboot_recommended = [bool]$Vc.Reboot
		Write-Host "Microsoft Visual C++ Runtime $(Get-VcRuntimeVersion) installed."
	}
	elseif (Test-VcRuntime $VcMin)
	{
		Write-Host "Microsoft Visual C++ Runtime $VcInstalled is installed (needs $VcMin or newer)."
		$Result.vcredist = 'present'
	}
	elseif ($VcRedist -eq 'Never')
	{
		$Found = if ($VcInstalled) { "version $VcInstalled is installed" } else { 'it is not installed' }
		Stop-Benchy 20 "Benchy needs the Microsoft Visual C++ Runtime $VcMin or newer, and $Found. Run the installer interactively, or as administrator, or install it from $BenchyVcRedistUrl first."
	}
	else
	{
		$Found = if ($VcInstalled) { "version $VcInstalled is older than $VcMin" } else { 'it is not installed' }
		Write-Host "Installing the Microsoft Visual C++ Runtime ($Found)..."
		$VcPin = $Pin.vcredist
		if (-not $VcPin -or -not $VcPin.file -or -not $VcPin.size -or -not $VcPin.sha256 -or @($VcPin.urls).Count -eq 0)
		{
			Stop-Benchy 2 'This Benchy package is damaged: runtime-pins.json has no complete vcredist pin (file, size, sha256, urls).'
		}
		if ($VcRedistHandOff)
		{
			# The GUI installer makes this folder fresh; refuse it, before downloading anything, if it is a link
			$VcDir = Split-Path -Parent $VcRedistHandOff
			if (-not (Test-Path -LiteralPath $VcDir)) { New-Item -ItemType Directory -Path $VcDir | Out-Null }
			if (Test-BenchyReparsePoint $VcDir)
			{
				$Result.vcredist = 'rejected'
				Stop-Benchy 22 "The folder for the Visual C++ Runtime installer ($VcDir) is a junction or symbolic link, so the installer was not downloaded or run. Run the installer again."
			}
			$Problem = Save-VcRuntimeInstaller $VcMin $VcRedistHandOff $VcPin
			if ($Problem) { Stop-Benchy 21 $Problem }
			$Result.vcredist = 'handoff'
			Stop-Benchy 4 "The Visual C++ Runtime installer is ready at $VcRedistHandOff."
		}
		$Vc = Install-VcRuntime $VcMin $VcPin
		if ($Vc.Rejected)
		{
			$Result.vcredist = 'rejected'
			Stop-Benchy 22 $Vc.Message
		}
		if (-not $Vc.Ok)
		{
			$Result.reboot_required = [bool]$Vc.RebootRequired
			Stop-Benchy 21 $Vc.Message
		}
		$Result.vcredist = 'installed'
		$Result.reboot_recommended = [bool]$Vc.Reboot
		Write-Host "Microsoft Visual C++ Runtime $(Get-VcRuntimeVersion) installed."
	}

	# --- 5. PHP: check the one that will be used, before anything else is written ------

	# Exit code 30 leaves the install as it was: an existing PHP is only run, and a PHP this step extracts replaces
	# the old managed one only once it passes (the old one is moved aside and put back on failure).
	$SourceSmoke = Join-Path $SourceDir 'pts-core\static\update\php-smoke.php'
	$AsideName = 'php.install-old'
	$AsideDir = Join-Path $InstallDir $AsideName
	if (Test-Path -LiteralPath $AsideDir)
	{
		# Left by an install interrupted mid-swap, or one that could not delete it. It is only deleted when the live
		# PHP works; otherwise it is the good copy and goes back to php (the live one is moved to php.failed-*).
		$LiveOk = (Test-Path -LiteralPath $ManagedPhp) -and (Test-PhpRuns $ManagedPhp (Join-Path $ManagedPhpDir 'php.ini') $SourceSmoke $Pin.required_extensions).Ok
		if ($LiveOk)
		{
			try { Remove-Item -LiteralPath $AsideDir -Recurse -Force }
			catch { Add-InstallWarning "Could not delete the old PHP in $AsideDir; the next install will try again." }
		}
		else
		{
			Write-Host "Putting back the PHP an earlier install left in $AsideDir ..."
			$Notes = @(Restore-AsidePhp)
			if ($Notes.Count -gt 0) { Stop-Benchy 30 ("An earlier install left PHP half replaced. " + ($Notes -join ' ')) }
		}
	}
	Get-ChildItem -LiteralPath $InstallDir -Directory -Filter 'php.failed-*' -Force -ErrorAction SilentlyContinue | ForEach-Object {
		Remove-Item -LiteralPath $_.FullName -Recurse -Force -ErrorAction SilentlyContinue
	}

	if ($PhpZipMode -eq 'IfOlder')
	{
		# Decided here, after the leftover repair above, which can change what is in php. The existing PHP is run: one
		# that fails the check is replaced, one that passes is replaced only when older than the pin.
		if (-not (Test-Path -LiteralPath $ManagedPhp))
		{
			$Result.php_zip_decision = 'installed'
			Write-Host "No managed PHP in $ManagedPhpDir yet: installing the bundled PHP $($Pin.version)."
		}
		else
		{
			$Existing = Test-PhpRuns $ManagedPhp (Join-Path $ManagedPhpDir 'php.ini') $SourceSmoke $Pin.required_extensions
			$ExistingVersion = $null
			try
			{
				# Major.minor.build on both sides: [version]'8.5.12' has revision -1, which compares lower than 8.5.12.0
				$V = [version]($Existing.PhpVersion -replace '[^0-9.].*$', '')
				$ExistingVersion = New-Object System.Version $V.Major, $V.Minor, ([Math]::Max($V.Build, 0))
			}
			catch { }
			$P = [version]$PinPhpVersion
			$PinVersion = New-Object System.Version $P.Major, $P.Minor, ([Math]::Max($P.Build, 0))
			if (-not $Existing.Ok)
			{
				$Result.php_zip_decision = 'replaced-broken'
				Write-Host "The PHP in $ManagedPhpDir cannot run Benchy ($($Existing.Message)): replacing it with the bundled PHP $($Pin.version)."
				if ($ExistingVersion -and $ExistingVersion -gt $PinVersion)
				{
					Add-InstallWarning "The PHP in $ManagedPhpDir was $($Existing.PhpVersion), newer than the bundled PHP $($Pin.version), but it could not run Benchy, so it was replaced with the older bundled one."
				}
			}
			elseif (-not $ExistingVersion)
			{
				$Result.php_zip_decision = 'kept-unknown'
				$InstallPhp = $false
				Add-InstallWarning "The PHP in $ManagedPhpDir runs Benchy, but its version ('$($Existing.PhpVersion)') could not be read, so this installer keeps it instead of installing the bundled PHP $($Pin.version)."
			}
			elseif ($ExistingVersion -lt $PinVersion)
			{
				$Result.php_zip_decision = 'replaced'
				Write-Host "The PHP in $ManagedPhpDir is $($Existing.PhpVersion), older than the pinned $PinPhpVersion`: replacing it with the bundled PHP."
			}
			else
			{
				$Result.php_zip_decision = 'kept-newer-or-equal'
				$InstallPhp = $false
				Write-Host "The PHP in $ManagedPhpDir is $($Existing.PhpVersion), not older than the pinned $PinPhpVersion`: keeping it."
			}
		}
		Write-Host "PHP zip decision: $($Result.php_zip_decision)"
	}

	if ($InstallPhp)
	{
		Write-Host "Installing PHP $($Pin.version) to $ManagedPhpDir ..."
		$HadPhpDir = Test-Path -LiteralPath $ManagedPhpDir
		if ($HadPhpDir -and (Test-Path -LiteralPath $AsideDir))
		{
			# The old-PHP leftover above could not be deleted, so there is nowhere to move the current PHP to
			Stop-Benchy 30 "The old PHP in $AsideDir could not be deleted, so the PHP in $ManagedPhpDir cannot be replaced safely. Close every Benchy window, delete $AsideDir, and run the installer again."
		}
		if ($HadPhpDir)
		{
			# Nothing has been written to the install dir yet, so a failure here has nothing to roll back; a failed directory
			# rename leaves php where it was and creates no php.install-old
			try { Rename-Item -LiteralPath $ManagedPhpDir -NewName $AsideName -ErrorAction Stop }
			catch
			{
				Stop-Benchy 30 "The PHP in $ManagedPhpDir is in use and could not be moved aside ($($_.Exception.Message)). Close every Benchy window and anything using files in it, and run the installer again."
			}
		}
		try
		{
			Install-PhpFromZip $PhpZip $ManagedPhpDir $Pin
			$Check = Test-PhpRuns $ManagedPhp (Join-Path $ManagedPhpDir 'php.ini') $SourceSmoke $Pin.required_extensions $PinPhpVersion
		}
		catch
		{
			$Check = [pscustomobject]@{ Ok = $false; Message = "PHP could not be set up in ${ManagedPhpDir}: $($_.Exception.Message)" }
		}
		if (-not $Check.Ok)
		{
			$Notes = if ($HadPhpDir) { @(Restore-AsidePhp) } else { @(Clear-FailedPhp | Where-Object { $_ }) }
			Stop-Benchy 30 ((@($Check.Message) + $Notes) -join ' ')
		}
		if ($HadPhpDir)
		{
			# The new PHP works; failing to delete the old one only leaves it for the next install to clean up
			try { Remove-Item -LiteralPath $AsideDir -Recurse -Force }
			catch { Add-InstallWarning "Could not delete the old PHP in $AsideDir; the next install will try again." }
		}
		Write-Host "  $($Check.Message)"
	}
	else
	{
		# A PHP that is already there: the managed one from an earlier install (kept as it is), or with -UseExistingPhp
		# any other PHP, which runs with its own php.ini
		$IniPath = if ($PhpIsManaged) { Join-Path $ManagedPhpDir 'php.ini' } else { $null }
		$Check = Test-PhpRuns $PhpExe $IniPath $SourceSmoke $Pin.required_extensions
		if (-not $Check.Ok) { Stop-Benchy 30 "The PHP at $PhpExe cannot run Benchy: $($Check.Message)" }
		Write-Host "  $($Check.Message)"
		$Older = $false
		try { $Older = [version]($Check.PhpVersion -replace '[^0-9.].*$', '') -lt [version]$PinPhpVersion } catch { }
		if ($PhpIsManaged -and $Older)
		{
			Add-InstallWarning "The PHP in $ManagedPhpDir is $($Check.PhpVersion), older than this release's pinned PHP $($Pin.version). This installer keeps it. To move to $($Pin.version): close every Benchy window, delete $ManagedPhpDir, and run the installer again."
		}
	}
	$Result.php_version = $Check.PhpVersion

	# First write to the user environment
	Write-Host "Using PHP: $PhpExe"
	Set-BenchyPhpBin $PhpExe

	# --- 6. Clear staged-but-unapplied updater state --------------------------------

	# An update staged before this install would otherwise apply on the next benchy exit and could silently replace
	# the version just installed. php.prev and update\backup (including update\backup\code) are kept, so a code
	# --rollback after an install goes back to the code that was there before this install.
	$UpdateDir = Join-Path $InstallDir 'update'
	$StateFile = Join-Path $UpdateDir 'state.json'
	if (Test-Path -LiteralPath $StateFile)
	{
		$ApplyScript = Join-Path $InstallDir 'pts-core\static\update\apply-update.ps1'
		if (Test-Path -LiteralPath $ApplyScript)
		{
			Write-Host 'Finishing an interrupted update first...'
			& (Join-Path $PSHOME 'powershell.exe') -NoProfile -ExecutionPolicy Bypass -File $ApplyScript -InstallDir $InstallDir -Recover | Out-Host
			if (Test-Path -LiteralPath $StateFile)
			{
				Stop-Benchy 50 "An interrupted update in $UpdateDir could not be recovered (apply-update.ps1 -Recover exit code $LASTEXITCODE). See $UpdateDir\apply.log."
			}
		}
		else
		{
			Add-InstallWarning "$StateFile exists, but this install has no apply-update.ps1 to recover it; it was left in place."
		}
	}
	foreach ($Stale in @((Join-Path $UpdateDir 'pending.json'), (Join-Path $UpdateDir 'staging'), (Join-Path $InstallDir 'php.new')))
	{
		if (Test-Path -LiteralPath $Stale)
		{
			Remove-Item -LiteralPath $Stale -Recurse -Force
			Write-Host "Removed staged update leftover: $Stale"
		}
	}

	# --- 7. Copy Benchy into the install dir ------------------------------------------

	Write-Host "Copying Benchy to $InstallDir ..."
	# /MIR, so files a newer release removed are deleted too (Copy-Item -Force never did). PHP and the updater's own
	# directories are kept: full destination paths in /XD protect them from the purge, where bare names would also skip
	# every source directory of that name (pts-core\static\update). /R:2 /W:2 instead of robocopy's default of retrying a
	# locked file a million times. User data is not in here (it lives in %USERPROFILE%\.benchy).
	# php.install-old and php.failed-* are leftovers this script deletes itself, best effort; if robocopy purged them
	# instead, a locked file there would fail the whole copy.
	# client, client.new and client.prev are the desktop client's (step 7b), not the release tree's. state is the neutral
	# run-lock dir (%LOCALAPPDATA%\Benchy\state, design 12.9) if it is ever under the install dir: robocopy must not purge it.
	$KeepDirs = @('php', 'php.new', 'php.prev', 'update', 'php.install-old', 'client', 'client.new', 'client.prev', 'state') | ForEach-Object { Join-Path $InstallDir $_ }
	$KeepDirs += 'php.failed-*'
	& robocopy $SourceDir $InstallDir /MIR /XD (Join-Path $SourceDir '.git') @KeepDirs /R:2 /W:2 /NFL /NDL /NJH /NP | Out-Null
	if ($LASTEXITCODE -ge 8)
	{
		Stop-Benchy 40 "robocopy could not copy Benchy to $InstallDir (exit code $LASTEXITCODE). Close every Benchy window and run the installer again."
	}

	# --- 7b. The desktop client ------------------------------------------------------------

	if ($ClientZip)
	{
		Write-Host "Installing the Benchy client $($ClientPin.version) ..."
		Install-BenchyClient $InstallDir $ClientZip
		$Result.client_version = [string]$ClientPin.version
	}
	elseif (Test-Path -LiteralPath (Get-BenchyClientExe $InstallDir) -PathType Leaf)
	{
		Write-Host 'Keeping the installed Benchy client.'
	}

	# --- 8. PATH, shortcuts, Apps & Features, Phoromatic ----------------------------------

	Set-BenchyUserPath $InstallDir
	New-BenchyShortcut $InstallDir
	Set-BenchyUninstallEntry $InstallDir $Result.benchy_version $InstallSource
	Write-Host 'Registered Benchy in Apps & Features.'

	if (-not $PhoromaticServer -and -not $NonInteractive)
	{
		Write-Host ''
		$PhoromaticServer = Read-PhoromaticServer
	}
	if ($PhoromaticServer)
	{
		Set-BenchyPhoromaticServer $InstallDir $PhpExe $PhoromaticServer
	}

	# --- 9. Self-check ---------------------------------------------------------------------

	# The installed PHP against the installed tree, without benchy.bat (which would start the Cygwin dependency setup)
	Write-Host ''
	Write-Host 'Running self-check...' -ForegroundColor Cyan
	$InstalledSmoke = Join-Path $InstallDir 'pts-core\static\update\php-smoke.php'
	$IniPath = if ($PhpIsManaged) { Join-Path $ManagedPhpDir 'php.ini' } else { $null }
	$Check = Test-PhpRuns $PhpExe $IniPath $InstalledSmoke $Pin.required_extensions
	if (-not $Check.Ok) { Stop-Benchy 50 "The installed copy failed its self-check: $($Check.Message)" }
	Write-Host "  $($Check.Message)"
	$Result.php_version = $Check.PhpVersion

	$Result.result = 'ok'
	$Result.code = if ($Result.reboot_recommended) { 3010 } else { 0 }
	$Result.message = "Benchy $($Result.benchy_version) is installed."
}
catch
{
	$Result.result = 'failed'
	$Exception = $_.Exception
	$Result.code = if ($Exception -and $Exception.Data.Contains('BenchyCode')) { [int]$Exception.Data['BenchyCode'] } else { 50 }
	$Result.message = ($Exception.Message -replace '\s+', ' ').Trim()
	if ($Result.code -eq 50 -and $_.InvocationInfo) { Write-Host $_.InvocationInfo.PositionMessage }
}
finally
{
	if ($DownloadedZip) { Remove-Item -LiteralPath $DownloadedZip -Force -ErrorAction SilentlyContinue }

	Write-Host ''
	if ($Result.result -eq 'ok')
	{
		if ($Result.client_version -or ($InstallDir -and (Test-Path -LiteralPath (Join-Path $InstallDir 'client\BenchyClient.exe'))))
		{
			Write-Host "Done. $($Result.message) Open it from the Benchy Start Menu shortcut; for the `"benchy`" command, open a new terminal or the Benchy Command Prompt shortcut." -ForegroundColor Green
		}
		else
		{
			Write-Host "Done. $($Result.message) Open a new terminal (or the Benchy Start Menu shortcut) to use the `"benchy`" command." -ForegroundColor Green
		}
		Write-Host 'The first time Benchy runs it may need to set up its dependencies, which may ask for permission.'
		if ($Result.reboot_recommended) { Write-Host 'A reboot is recommended to finish the Visual C++ Runtime install.' -ForegroundColor Yellow }
		foreach ($Warning in $Result.warnings) { Write-Host "Warning: $Warning" -ForegroundColor Yellow }
	}
	elseif ($Result.code -eq 4)
	{
		# Not a failure: the caller runs the VC++ installer, then runs this script again
		Write-Host $Result.message
	}
	else
	{
		Write-Host "Benchy was not installed (exit code $($Result.code)): $($Result.message)" -ForegroundColor Red
		if ($Result.reboot_required)
		{
			Write-Host 'The Microsoft Visual C++ Runtime was installed, but Windows needs a restart before Benchy can use it. Benchy itself was not installed: restart, then run the installer again.'
		}
		elseif ($Result.code -ge 2 -and $Result.code -le 22) { Write-Host 'Nothing was changed.' }
	}
	if ($LogFile -and $Result.code -ne 4) { Write-Host "Log: $LogFile" }

	Write-InstallResult
	if ($Transcribing) { Stop-Transcript | Out-Null }
}

exit $Result.code
