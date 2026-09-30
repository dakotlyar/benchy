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

# Keep this in sync with the fallback download in benchy.bat.
$PhpVersion  = '8.5.11'
$PhpZipUrl   = "https://windows.php.net/downloads/releases/php-$PhpVersion-nts-Win32-vs17-x64.zip"
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

$FoundPhp = Find-ExistingPhp

if (-not $FoundPhp)
{
	Write-Host "No usable php.exe found -- downloading PHP $PhpVersion (NTS x64)..."
	New-Item -ItemType Directory -Force -Path $PhpDir | Out-Null

	$ZipPath = Join-Path $InstallDir 'php.zip'
	$ProgressPreference = 'SilentlyContinue'
	Invoke-WebRequest -Uri $PhpZipUrl -OutFile $ZipPath -UseBasicParsing
	$ProgressPreference = 'Continue'

	Expand-Archive -Path $ZipPath -DestinationPath $PhpDir -Force
	Remove-Item $ZipPath -Force

	# A fresh windows.php.net release ships every extension DLL but no
	# active php.ini, so openssl/curl/mbstring/zip are all disabled by
	# default until one is written.
	$ExtDir = (Join-Path $PhpDir 'ext') -replace '\\', '/'
	$PhpIniLines = @(
		"extension_dir = $ExtDir",
		'extension=openssl',
		'extension=curl',
		'extension=mbstring',
		'extension=zip',
		'allow_url_fopen=On'
	)
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
	Write-Host "Copying Benchy to $InstallDir ..."
	Get-ChildItem -Path $SourceDir -Force |
		Where-Object { $_.Name -ne '.git' } |
		Copy-Item -Destination $InstallDir -Recurse -Force
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
