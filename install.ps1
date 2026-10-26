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

	Per-user installer: installs to %LOCALAPPDATA%\Benchy so no admin
	rights are needed for the install itself. Only the one-time Visual
	C++ Redistributable install (when PHP has to be downloaded) may
	prompt for elevation; that's inherent to the redistributable, not
	something this script can avoid.
#>

$ErrorActionPreference = 'Stop'

$InstallDir = Join-Path $env:LOCALAPPDATA 'Benchy'
$PhpDir     = Join-Path $InstallDir 'php'
$PhpExe     = Join-Path $PhpDir 'php.exe'

# The one Windows PHP pin for this release (version, zip, size, sha256, URLs, php.ini lines).
$PinsPath    = Join-Path $PSScriptRoot 'pts-core\static\update\runtime-pins.json'
$PhpPin      = (Get-Content -LiteralPath $PinsPath -Raw | ConvertFrom-Json).'windows-x64'.php
$PhpVersion  = $PhpPin.version
$VcRedistUrl = 'https://aka.ms/vs/17/release/VC_redist.x64.exe'

Write-Host 'Benchy Windows Installer' -ForegroundColor Cyan
Write-Host "Installing to: $InstallDir"
Write-Host ''

New-Item -ItemType Directory -Force -Path $InstallDir | Out-Null

# --- Locate an existing usable PHP, or fetch one -------------------------

function Find-ExistingPhp
{
	if ($env:PHP_BIN -and (Test-Path $env:PHP_BIN)) { return $env:PHP_BIN }
	if (Test-Path $PhpExe) { return $PhpExe }

	$cmd = Get-Command 'php.exe' -ErrorAction SilentlyContinue
	if ($cmd) { return $cmd.Source }

	# Same location benchy.bat's own independent fallback uses.
	if (Test-Path 'C:\PHP\php.exe') { return 'C:\PHP\php.exe' }

	return $null
}

function Get-PinnedPhpZip($Pin, $ZipPath)
{
	# Tries each pinned URL in order: windows.php.net moves superseded builds to releases/archives/, so the first URL
	# stops working the day a newer build ships. Nothing is extracted unless size and sha256 match the pin.
	foreach ($Url in $Pin.upstream_urls)
	{
		Write-Host "  $Url"
		try
		{
			$ProgressPreference = 'SilentlyContinue'
			Invoke-WebRequest -Uri $Url -OutFile $ZipPath -UseBasicParsing
		}
		catch
		{
			Write-Host "    not available there ($($_.Exception.Message))"
			Remove-Item -LiteralPath $ZipPath -Force -ErrorAction SilentlyContinue
			continue
		}
		finally
		{
			$ProgressPreference = 'Continue'
		}

		$Size = (Get-Item -LiteralPath $ZipPath).Length
		$Hash = (Get-FileHash -LiteralPath $ZipPath -Algorithm SHA256).Hash
		if ($Size -eq $Pin.artifact.size -and $Hash -eq $Pin.artifact.sha256)
		{
			Write-Host "    sha256 verified: $($Pin.artifact.sha256)"
			return
		}
		Remove-Item -LiteralPath $ZipPath -Force
		Write-Host "    REJECTED: got $Size bytes, sha256 $Hash; the pin expects $($Pin.artifact.size) bytes, sha256 $($Pin.artifact.sha256)" -ForegroundColor Red
	}
	throw "Could not download PHP $($Pin.version) matching the pinned sha256 from any of its URLs. Nothing was installed."
}

$FoundPhp = Find-ExistingPhp

if ($FoundPhp -and (Test-Path -LiteralPath $PhpExe) -and (Resolve-Path -LiteralPath $FoundPhp).Path -eq (Resolve-Path -LiteralPath $PhpExe).Path)
{
	# A managed PHP from an earlier install is kept as it is; say so when it is older than this release's pin
	$ManagedVersion = $null
	$ManagedIsOlder = $false
	try
	{
		$ManagedVersion = [string](& $PhpExe -n -r 'echo PHP_VERSION;') -replace '[^0-9.].*$', ''
		$ManagedIsOlder = [version]$ManagedVersion -lt [version]$PhpVersion
	}
	catch { }
	if (-not $ManagedVersion)
	{
		Write-Host "Warning: could not read the version of $PhpExe." -ForegroundColor Yellow
	}
	elseif ($ManagedIsOlder)
	{
		Write-Host "Warning: the PHP in $PhpDir is $ManagedVersion, older than this release's pinned PHP $PhpVersion." -ForegroundColor Yellow
		Write-Host "  This installer does not replace it in place. To move to ${PhpVersion}: close every Benchy window," -ForegroundColor Yellow
		Write-Host "  delete $PhpDir, and run install.bat again." -ForegroundColor Yellow
	}
}

if (-not $FoundPhp)
{
	Write-Host "No usable php.exe found -- downloading PHP $PhpVersion (NTS x64)..."
	$ZipPath = Join-Path $InstallDir 'php.zip'
	Get-PinnedPhpZip $PhpPin $ZipPath

	New-Item -ItemType Directory -Force -Path $PhpDir | Out-Null
	Expand-Archive -Path $ZipPath -DestinationPath $PhpDir -Force
	Remove-Item $ZipPath -Force

	# A fresh windows.php.net release ships every extension DLL but no
	# active php.ini, so openssl/curl/mbstring/zip are all disabled by
	# default until one is written.
	$ExtDir = (Join-Path $PhpDir 'ext') -replace '\\', '/'
	$PhpIniLines = @("extension_dir = $ExtDir") + @($PhpPin.ini)
	Set-Content -Path (Join-Path $PhpDir 'php.ini') -Value $PhpIniLines -Encoding ASCII

	Write-Host 'Installing the Visual C++ Redistributable this PHP build needs (may prompt for elevation)...'
	$VcRedistPath = Join-Path $InstallDir 'VC_redist.x64.exe'
	$ProgressPreference = 'SilentlyContinue'
	Invoke-WebRequest -Uri $VcRedistUrl -OutFile $VcRedistPath -UseBasicParsing
	$ProgressPreference = 'Continue'
	Start-Process -FilePath $VcRedistPath -ArgumentList '/install', '/quiet' -Wait
	Remove-Item $VcRedistPath -Force -ErrorAction SilentlyContinue

	$FoundPhp = $PhpExe

	# Persist PHP_BIN for future sessions so a later, plain `benchy.bat`
	# run (no install.ps1 involved) picks up this copy instead of trying
	# its own C:\PHP-based fallback -- which would need admin rights and
	# duplicate the download we just did.
	setx PHP_BIN "$FoundPhp" | Out-Null
}

Write-Host "Using PHP: $FoundPhp"
$env:PHP_BIN = $FoundPhp

# --- Copy Benchy into the install dir -------------------------------------

$SourceDir = $PSScriptRoot
if ((Resolve-Path $SourceDir).Path -ne (Resolve-Path -LiteralPath $InstallDir -ErrorAction SilentlyContinue).Path)
{
	$SourceFull = (Resolve-Path $SourceDir).Path.TrimEnd('\') + '\'
	if ($SourceFull.StartsWith($InstallDir.TrimEnd('\') + '\', [StringComparison]::OrdinalIgnoreCase))
	{
		# Mirroring a folder inside the install dir onto the install dir would delete that folder while copying from it
		throw "Run install.bat from the extracted Benchy folder outside $InstallDir (it is running from $SourceDir)."
	}
	Write-Host "Copying Benchy to $InstallDir ..."
	# /MIR, so files a newer release removed are deleted too (Copy-Item -Force never did). PHP and the updater's own
	# directories are kept: full destination paths in /XD protect them from the purge, where bare names would also skip
	# every source directory of that name (pts-core\static\update). /R:2 /W:2 instead of robocopy's default of retrying a
	# locked file a million times. User data is not in here (it lives in %USERPROFILE%\.benchy).
	$KeepDirs = @('php', 'php.new', 'php.prev', 'update') | ForEach-Object { Join-Path $InstallDir $_ }
	& robocopy $SourceDir $InstallDir /MIR /XD (Join-Path $SourceDir '.git') @KeepDirs /R:2 /W:2 /NFL /NDL /NJH /NP | Out-Null
	if ($LASTEXITCODE -ge 8)
	{
		throw "robocopy could not copy Benchy to $InstallDir (exit code $LASTEXITCODE). Close every Benchy window and run install.bat again."
	}
}

# --- User-scope PATH -------------------------------------------------------
# Uses the .NET API rather than `setx PATH "%PATH%;..."` -- that literal
# form re-writes the *combined* machine+user PATH into the user hive on
# every run, growing it a little more each time. This only touches the
# user-scope value, still with no admin rights required.

$UserPath = [Environment]::GetEnvironmentVariable('Path', 'User')
if (-not $UserPath) { $UserPath = '' }
if ($UserPath.Split(';') -notcontains $InstallDir)
{
	$NewPath = if ($UserPath) { "$UserPath;$InstallDir" } else { $InstallDir }
	[Environment]::SetEnvironmentVariable('Path', $NewPath, 'User')
	Write-Host "Added $InstallDir to your user PATH (new terminals will pick this up)."
}

# --- Start Menu shortcut ---------------------------------------------------

$StartMenuPrograms = Join-Path ([Environment]::GetFolderPath('StartMenu')) 'Programs'
$ShortcutPath = Join-Path $StartMenuPrograms 'Benchy.lnk'
$WshShell = New-Object -ComObject WScript.Shell
$Shortcut = $WshShell.CreateShortcut($ShortcutPath)
$Shortcut.TargetPath = Join-Path $env:WINDIR 'System32\cmd.exe'
$Shortcut.Arguments = '/k benchy.bat'
$Shortcut.WorkingDirectory = $InstallDir
$Shortcut.Description = 'Benchy'
$Shortcut.Save()
Write-Host "Created Start Menu shortcut: $ShortcutPath"

# --- Optional Phoromatic server registration -------------------------------

Write-Host ''
$Phoromatic = Read-Host 'Phoromatic server to connect to, as ip:port/accountid (leave blank to skip)'
if ($Phoromatic)
{
	Push-Location $InstallDir
	try
	{
		# Reuse Benchy's own user-config-set command rather than hand-editing
		# XML here -- it already knows how to merge into/create user-config.xml.
		& cmd.exe /c "benchy.bat user-config-set `"PhoromaticServers=$Phoromatic`""
	}
	finally
	{
		Pop-Location
	}
}

# --- Self-check -------------------------------------------------------------

Write-Host ''
Write-Host 'Running self-check (benchy php-conf)...' -ForegroundColor Cyan
Push-Location $InstallDir
try
{
	& cmd.exe /c 'benchy.bat php-conf'
}
finally
{
	Pop-Location
}

Write-Host ''
Write-Host 'Done. Open a new terminal (or the Benchy Start Menu shortcut) to use the "benchy" command.' -ForegroundColor Green
