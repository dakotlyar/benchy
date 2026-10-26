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

	uninstall.ps1 [-Quiet] [-RemoveUserData]

	Removes %LOCALAPPDATA%\Benchy (code, php, php.new, php.prev, update), the Start Menu shortcut, that exact entry in
	the user PATH, PHP_BIN when it points into the install dir, and the Apps & Features entry. Keeps
	%USERPROFILE%\.benchy (results, settings, installed tests) unless asked, and always keeps the Visual C++ Runtime and
	Cygwin, which are shared, system-wide components. Asks first unless -Quiet; -Quiet keeps the user data unless
	-RemoveUserData is also given.

	Exit codes: 0 removed, 1 cancelled, 3 refused because Benchy is running, 40 the install dir could not be removed
	completely (run the uninstaller again after closing Benchy), 50 anything else.
#>

param(
	[switch]$Quiet,
	[switch]$RemoveUserData
)

$ErrorActionPreference = 'Stop'

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

$Code = 50
$UserData = Join-Path $env:USERPROFILE '.benchy'
try
{
	. (Join-Path $PSScriptRoot $LibRelative)

	$Running = @(Get-BenchyProcesses $InstallDir)
	if ($Running.Count -gt 0)
	{
		Stop-Benchy 3 "Benchy is running ($(Format-BenchyProcesses $Running)). Close every Benchy window and run the uninstaller again."
	}

	$RemoveData = [bool]$RemoveUserData
	if (-not $Quiet)
	{
		if (-not (Read-YesNo "Remove Benchy from ${InstallDir}?"))
		{
			Stop-Benchy 1 'Nothing was removed.'
		}
		if (-not $RemoveData -and (Test-Path -LiteralPath $UserData))
		{
			$RemoveData = Read-YesNo "Also delete your results, settings and installed tests in ${UserData}?"
		}
	}

	$ShortcutPath = Get-BenchyShortcutPath
	if (Test-Path -LiteralPath $ShortcutPath)
	{
		Remove-Item -LiteralPath $ShortcutPath -Force
		Write-Host "Removed $ShortcutPath"
	}
	if (Remove-BenchyUserPath $InstallDir) { Write-Host "Removed $InstallDir from your user PATH" }
	$PhpBin = [Environment]::GetEnvironmentVariable('PHP_BIN', 'User')
	if ($PhpBin -and (Test-BenchyPathInside $PhpBin.Trim('"') $InstallDir))
	{
		[Environment]::SetEnvironmentVariable('PHP_BIN', $null, 'User')
		Write-Host 'Removed PHP_BIN'
	}

	$Incomplete = $false
	if (Test-Path -LiteralPath $InstallDir)
	{
		try
		{
			Remove-Item -LiteralPath $InstallDir -Recurse -Force
			Write-Host "Removed $InstallDir"
		}
		catch
		{
			$Incomplete = $true
			Write-Host "Could not remove all of ${InstallDir}: $($_.Exception.Message)" -ForegroundColor Red
		}
	}
	if (-not $Incomplete -or -not (Test-Path -LiteralPath (Join-Path $InstallDir 'uninstall.ps1')))
	{
		# Kept while the uninstaller is still there to run again; dropped once it is gone, even if other files remain
		Remove-BenchyUninstallEntry
		Write-Host 'Removed Benchy from Apps & Features'
	}

	if ($RemoveData -and (Test-Path -LiteralPath $UserData))
	{
		try
		{
			Remove-Item -LiteralPath $UserData -Recurse -Force
			Write-Host "Removed $UserData"
		}
		catch
		{
			$Incomplete = $true
			Write-Host "Could not remove all of ${UserData}: $($_.Exception.Message)" -ForegroundColor Red
		}
	}

	Write-Host ''
	Write-Host 'Kept:'
	if (-not $RemoveData -and (Test-Path -LiteralPath $UserData)) { Write-Host "  $UserData (your results, settings and installed tests)" }
	Write-Host '  the Microsoft Visual C++ Runtime (shared with other programs; remove it in Apps & Features if nothing needs it)'
	if (Test-Path -LiteralPath 'C:\cygwin64') { Write-Host '  C:\cygwin64 (system-wide Cygwin, possibly used by other software)' }
	if (Test-Path -LiteralPath 'C:\PHP') { Write-Host '  C:\PHP (left by an older Benchy launcher)' }

	if ($Incomplete)
	{
		Stop-Benchy 40 'Some files could not be removed. Close every Benchy window and run the uninstaller again, or delete what is left by hand.'
	}
	Write-Host ''
	Write-Host 'Benchy was uninstalled.' -ForegroundColor Green
	$Code = 0
}
catch
{
	$Exception = $_.Exception
	$Code = if ($Exception -and $Exception.Data.Contains('BenchyCode')) { [int]$Exception.Data['BenchyCode'] } else { 50 }
	$Color = if ($Code -eq 1) { 'Yellow' } else { 'Red' }
	Write-Host $Exception.Message -ForegroundColor $Color
}
finally
{
	if (-not $Quiet) { Read-Host 'Press Enter to close' | Out-Null }
}

exit $Code
