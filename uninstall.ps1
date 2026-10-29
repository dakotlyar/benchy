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

	Everything in the install dir except this uninstaller (and the lib it needs) is deleted first; the shortcut,
	PATH, PHP_BIN and the Apps & Features entry go only once that worked, and the uninstaller itself goes last, only
	once the entry is gone. So Apps & Features never lists Benchy without an uninstaller that can be run again.
	Junctions and symbolic links are removed as links; what they point to is never deleted.

	Exit codes: 0 removed, 1 cancelled, 2 no user profile (LOCALAPPDATA or USERPROFILE unset), 3 refused because
	Benchy is running, 40 not everything could be removed (the message says whether Benchy is still registered),
	50 anything else.
#>

param(
	[switch]$Quiet,
	[switch]$RemoveUserData
)

$ErrorActionPreference = 'Stop'

if (-not $env:LOCALAPPDATA -or -not $env:USERPROFILE)
{
	Write-Host 'LOCALAPPDATA or USERPROFILE is not set, so there is no user profile to uninstall Benchy from. Run the uninstaller as the user who installed Benchy.' -ForegroundColor Red
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
		$Question = if ($RemoveData) { "Remove Benchy from $InstallDir and DELETE your results, settings and installed tests in ${UserData}?" } else { "Remove Benchy from ${InstallDir}?" }
		if (-not (Read-YesNo $Question))
		{
			Stop-Benchy 1 'Nothing was removed.'
		}
		if (-not $RemoveData -and (Test-Path -LiteralPath $UserData))
		{
			$RemoveData = Read-YesNo "Also delete your results, settings and installed tests in ${UserData}?"
		}
	}

	# Order matters, so that Apps & Features never lists Benchy without a working uninstaller:
	# 1. Everything in the install dir except uninstall.ps1 and the lib it needs, while the shortcut, PATH, PHP_BIN
	#    and the entry still describe the install. A failure stops here (exit 40) with the entry and its uninstaller
	#    intact, so it can be run again.
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
			$Failed = @(Remove-BenchyTree $InstallDir @($SelfPath, $LibPath))
			if ($Failed.Count -gt 0)
			{
				Write-NotRemoved $InstallDir $Failed
				Stop-Benchy 40 'Benchy was not fully removed and is still registered. Close every Benchy window and run the uninstaller again.'
			}
		}
	}

	$ByHand = @()
	$ShortcutPath = Get-BenchyShortcutPath
	try
	{
		if (Test-Path -LiteralPath $ShortcutPath)
		{
			Remove-Item -LiteralPath $ShortcutPath -Force
			Write-Host "Removed $ShortcutPath"
		}
	}
	catch { $ByHand += "delete the shortcut $ShortcutPath" }
	$PromptShortcutPath = Get-BenchyPromptShortcutPath
	try
	{
		if (Test-Path -LiteralPath $PromptShortcutPath)
		{
			Remove-Item -LiteralPath $PromptShortcutPath -Force
			Write-Host "Removed $PromptShortcutPath"
		}
	}
	catch { $ByHand += "delete the shortcut $PromptShortcutPath" }
	try
	{
		if (Remove-BenchyUserPath $InstallDir) { Write-Host "Removed $InstallDir from your user PATH" }
	}
	catch { $ByHand += "remove $InstallDir from your user PATH" }
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
	catch { $ByHand += 'delete the user environment variable PHP_BIN' }
	$EntryRemoved = $false
	try
	{
		Remove-BenchyUninstallEntry
		$EntryRemoved = $true
		Write-Host 'Removed Benchy from Apps & Features'
	}
	catch { $ByHand += "remove Benchy from Apps & Features (registry key HKCU\$BenchyUninstallKey)" }

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
				$Failed = @(Remove-BenchyTree $InstallDir)
				if ($Failed.Count -gt 0)
				{
					Write-NotRemoved $InstallDir $Failed
					$ByHand += "delete what is left of $InstallDir"
				}
				else
				{
					try { [IO.Directory]::Delete($InstallDir, $false) }
					catch { Write-Host "Left the empty folder $InstallDir (it is in use); it can be deleted later." }
				}
			}
		}
		catch { $ByHand += "delete what is left of $InstallDir" }
		if ($ByHand -notcontains "delete what is left of $InstallDir") { Write-Host "Removed $InstallDir" }
	}

	$DataIncomplete = $false
	if ($RemoveData -and (Test-Path -LiteralPath $UserData))
	{
		try
		{
			if ((Get-Item -LiteralPath $UserData -Force).Attributes -band [IO.FileAttributes]::ReparsePoint)
			{
				[IO.Directory]::Delete($UserData, $false)
			}
			else
			{
				$Failed = @(Remove-BenchyTree $UserData)
				if ($Failed.Count -gt 0)
				{
					$DataIncomplete = $true
					Write-NotRemoved $UserData $Failed
				}
				else
				{
					try { [IO.Directory]::Delete($UserData, $false) } catch { }
				}
			}
		}
		catch
		{
			$DataIncomplete = $true
			Write-Host "Could not delete ${UserData}: $($_.Exception.Message)" -ForegroundColor Red
		}
		if (-not $DataIncomplete) { Write-Host "Removed $UserData" }
	}

	Write-Host ''
	Write-Host 'Kept:'
	if (-not $RemoveData -and (Test-Path -LiteralPath $UserData)) { Write-Host "  $UserData (your results, settings and installed tests)" }
	Write-Host '  the Microsoft Visual C++ Runtime (shared with other programs; remove it in Apps & Features if nothing needs it)'
	if (Test-Path -LiteralPath 'C:\cygwin64') { Write-Host '  C:\cygwin64 (system-wide Cygwin, possibly used by other software)' }
	if (Test-Path -LiteralPath 'C:\PHP') { Write-Host '  C:\PHP (left by an older Benchy launcher)' }

	if ($DataIncomplete) { $ByHand += "delete what is left of $UserData" }
	if ($ByHand.Count -gt 0)
	{
		$State = if ($EntryRemoved) { 'Benchy was uninstalled' } else { 'The program files were removed, but Benchy is still listed in Apps & Features; its uninstaller was kept, so it can be run again' }
		Stop-Benchy 40 "$State. Finish by hand: $($ByHand -join '; ')."
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
