<#
	Benchy Windows shared functions
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

	Dot-sourced by install.ps1, uninstall.ps1 and the updater's apply-update.ps1, so the PHP pin check, the php.ini
	writer, the Visual C++ runtime handling, the PHP smoke run, the running-process check and the Apps & Features entry
	exist once. Windows PowerShell 5.1 compatible. Defines functions only; dot-sourcing it changes nothing.
#>

$BenchyVcRedistUrl = 'https://aka.ms/vs/17/release/VC_redist.x64.exe'
$BenchyUninstallKey = 'Software\Microsoft\Windows\CurrentVersion\Uninstall\Benchy'

# --- Errors carrying an exit code ------------------------------------------

function Stop-Benchy([int]$Code, [string]$Message)
{
	# Throws an error that the calling script's top-level catch turns into its exit code and one-line message
	$Exception = New-Object System.Exception $Message
	$Exception.Data['BenchyCode'] = $Code
	throw $Exception
}

function Get-BenchyErrorCode($ErrorRecord, [int]$Default = 50)
{
	$Exception = $ErrorRecord.Exception
	if ($Exception -and $Exception.Data.Contains('BenchyCode')) { return [int]$Exception.Data['BenchyCode'] }
	return $Default
}

# --- Paths and versions ---------------------------------------------------

function Get-BenchyInstallDir
{
	# Fixed: the updater only manages an install at exactly this path (windows-managed)
	return Join-Path $env:LOCALAPPDATA 'Benchy'
}

function Test-BenchyPathInside([string]$Path, [string]$Dir)
{
	# True when $Path is $Dir or anything below it (case-insensitive, as Windows paths are)
	$Full = [IO.Path]::GetFullPath($Path).TrimEnd('\') + '\'
	$DirFull = [IO.Path]::GetFullPath($Dir).TrimEnd('\') + '\'
	return $Full.StartsWith($DirFull, [StringComparison]::OrdinalIgnoreCase)
}

function Get-BenchyVersion([string]$TreeDir)
{
	# PTS_VERSION of a Benchy tree, read from the source so no PHP has to run; $null when the tree is not there
	$CorePath = Join-Path $TreeDir 'pts-core\pts-core.php'
	if (-not (Test-Path -LiteralPath $CorePath)) { return $null }
	$Match = Select-String -LiteralPath $CorePath -Pattern "pts_define\('PTS_VERSION',\s*'([^']+)'\)" | Select-Object -First 1
	if ($Match) { return $Match.Matches[0].Groups[1].Value }
	return $null
}

# --- PHP pin ----------------------------------------------------------------

function Get-BenchyPin([string]$TreeDir)
{
	# The windows-x64 PHP block of the tree's runtime-pins.json: version, artifact (file, size, sha256), upstream_urls,
	# ini lines, required_extensions, vcredist_min
	$PinsPath = Join-Path $TreeDir 'pts-core\static\update\runtime-pins.json'
	return (Get-Content -LiteralPath $PinsPath -Raw | ConvertFrom-Json).'windows-x64'.php
}

function Get-PinPhpVersion($Pin)
{
	# A windows.php.net rebuild carries a -N suffix (8.5.12-1) that PHP_VERSION does not (8.5.12)
	return ($Pin.version -replace '-.*$', '')
}

function Test-PhpZip([string]$Path, $Pin)
{
	# True when the file has exactly the pinned size and sha256
	if (-not (Test-Path -LiteralPath $Path -PathType Leaf)) { return $false }
	$Size = (Get-Item -LiteralPath $Path).Length
	$Hash = (Get-FileHash -LiteralPath $Path -Algorithm SHA256).Hash
	if ($Size -eq $Pin.artifact.size -and $Hash -eq $Pin.artifact.sha256)
	{
		Write-Host "    sha256 verified: $($Pin.artifact.sha256)"
		return $true
	}
	Write-Host "    REJECTED: got $Size bytes, sha256 $Hash; the pin expects $($Pin.artifact.size) bytes, sha256 $($Pin.artifact.sha256)" -ForegroundColor Red
	return $false
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

		if (Test-PhpZip $ZipPath $Pin)
		{
			return
		}
		Remove-Item -LiteralPath $ZipPath -Force
	}
	throw "Could not download PHP $($Pin.version) matching the pinned sha256 from any of its URLs. Nothing was installed."
}

function Install-PhpFromZip([string]$Zip, [string]$Dest, $Pin, [string]$ExtensionDir)
{
	# Extracts a verified PHP zip into $Dest and writes $Dest\php.ini. A windows.php.net release ships every extension
	# DLL but no active php.ini, so openssl/curl/mbstring/zip stay disabled until one is written. extension_dir defaults
	# to $Dest\ext; the updater passes the final php\ext path when it stages into php.new.
	if (-not $ExtensionDir) { $ExtensionDir = Join-Path $Dest 'ext' }
	New-Item -ItemType Directory -Force -Path $Dest | Out-Null
	Expand-Archive -LiteralPath $Zip -DestinationPath $Dest -Force
	$PhpIniLines = @("extension_dir = $($ExtensionDir -replace '\\', '/')") + @($Pin.ini)
	Set-Content -LiteralPath (Join-Path $Dest 'php.ini') -Value $PhpIniLines -Encoding ASCII
}

# --- Running a native program -----------------------------------------------

function Invoke-BenchyNative([string]$FilePath, [string]$Arguments, [string]$WorkingDirectory)
{
	# Runs a program to completion with stdin empty (so a prompt can never hang an unattended run) and stdout/stderr
	# captured. $Arguments is one command line string; quote paths in it. Returns ExitCode, StdOut, StdErr.
	# Start-Process instead of the & operator: Windows PowerShell 5.1 turns native stderr into terminating errors
	# under ErrorActionPreference Stop, and & cannot redirect stdin from nothing.
	$Tmp = [IO.Path]::GetTempPath()
	$Id = [guid]::NewGuid().ToString('N')
	$In = Join-Path $Tmp "benchy-$Id.in"
	$Out = Join-Path $Tmp "benchy-$Id.out"
	$Err = Join-Path $Tmp "benchy-$Id.err"
	[IO.File]::WriteAllText($In, '')
	try
	{
		$StartArgs = @{
			FilePath = $FilePath
			Wait = $true
			PassThru = $true
			NoNewWindow = $true
			RedirectStandardInput = $In
			RedirectStandardOutput = $Out
			RedirectStandardError = $Err
		}
		if ($Arguments) { $StartArgs['ArgumentList'] = $Arguments }
		if ($WorkingDirectory) { $StartArgs['WorkingDirectory'] = $WorkingDirectory }
		$Process = Start-Process @StartArgs
		return [pscustomobject]@{
			ExitCode = [int]$Process.ExitCode
			StdOut = [IO.File]::ReadAllText($Out)
			StdErr = [IO.File]::ReadAllText($Err)
		}
	}
	finally
	{
		Remove-Item -LiteralPath $In, $Out, $Err -Force -ErrorAction SilentlyContinue
	}
}

function Format-BenchyExitCode([int]$Code)
{
	# Decimal plus hex for NTSTATUS-style codes, e.g. -1073741515 (0xC0000135)
	if ($Code -lt 0) { return '{0} (0x{1:X8})' -f $Code, $Code }
	return [string]$Code
}

# --- PHP smoke run ------------------------------------------------------------

function Test-PhpRuns([string]$PhpExe, [string]$IniPath, [string]$SmokeScript, $RequiredExtensions, [string]$ExpectedVersion)
{
	# Runs php-smoke.php and checks the result. With $IniPath, PHP runs with -n -c <ini> (only that ini); without it,
	# PHP uses its own ini (an external PHP). $ExpectedVersion is a PHP_VERSION (no -N build suffix) or empty to skip.
	# Returns Ok, Message, PhpVersion, Extensions.
	$Result = [pscustomobject]@{ Ok = $false; Message = $null; PhpVersion = $null; Extensions = @() }
	if (-not (Test-Path -LiteralPath $PhpExe -PathType Leaf))
	{
		$Result.Message = "$PhpExe does not exist."
		return $Result
	}
	$Arguments = if ($IniPath) { "-n -c `"$IniPath`" `"$SmokeScript`"" } else { "`"$SmokeScript`"" }
	try
	{
		$Run = Invoke-BenchyNative $PhpExe $Arguments
	}
	catch
	{
		$Result.Message = "$PhpExe could not be started: $($_.Exception.Message)"
		return $Result
	}

	if ($Run.ExitCode -eq -1073741515)
	{
		# 0xC0000135 STATUS_DLL_NOT_FOUND: the loader could not find a DLL php.exe needs, here vcruntime140.dll
		$Result.Message = "PHP could not start: the Microsoft Visual C++ Runtime is missing or broken (exit code $(Format-BenchyExitCode $Run.ExitCode))."
		return $Result
	}
	if ($Run.ExitCode -ne 0)
	{
		$Detail = (($Run.StdErr + ' ' + $Run.StdOut) -replace '\s+', ' ').Trim()
		if ($Detail.Length -gt 300) { $Detail = $Detail.Substring(0, 300) + '...' }
		$Result.Message = "PHP exited with code $(Format-BenchyExitCode $Run.ExitCode). $Detail".Trim()
		return $Result
	}

	$JsonLine = @($Run.StdOut -split "`r?`n" | Where-Object { $_.Trim() }) | Select-Object -Last 1
	try
	{
		if (-not $JsonLine) { throw 'no output' }
		$Smoke = $JsonLine | ConvertFrom-Json
		if ($null -eq $Smoke.php_version) { throw 'no php_version' }
	}
	catch
	{
		$Result.Message = "PHP ran, but its self-test output was not readable: $JsonLine"
		return $Result
	}
	$Result.PhpVersion = [string]$Smoke.php_version
	$Result.Extensions = @($Smoke.extensions | ForEach-Object { ([string]$_).ToLowerInvariant() })

	if ($ExpectedVersion -and $Result.PhpVersion -ne $ExpectedVersion)
	{
		$Result.Message = "PHP reports version $($Result.PhpVersion), expected $ExpectedVersion."
		return $Result
	}
	$Missing = @($RequiredExtensions | Where-Object { $Result.Extensions -notcontains ([string]$_).ToLowerInvariant() })
	if ($Missing.Count -gt 0)
	{
		$Result.Message = "PHP $($Result.PhpVersion) is missing required extensions: $($Missing -join ', ')."
		return $Result
	}
	$Result.Ok = $true
	$Result.Message = "PHP $($Result.PhpVersion) runs, with $($RequiredExtensions -join ', ')."
	return $Result
}

# --- Visual C++ runtime -------------------------------------------------------

function Get-VcRuntimeVersion
{
	# The installed VC++ 2015-2022 x64 runtime version, or $null. Always the 64-bit registry view, so a 32-bit host
	# process cannot read WOW6432Node by mistake. Reading HKLM needs no admin rights.
	$Base = [Microsoft.Win32.RegistryKey]::OpenBaseKey([Microsoft.Win32.RegistryHive]::LocalMachine, [Microsoft.Win32.RegistryView]::Registry64)
	try
	{
		$Key = $Base.OpenSubKey('SOFTWARE\Microsoft\VisualStudio\14.0\VC\Runtimes\x64')
		if (-not $Key) { return $null }
		try
		{
			if ([int]$Key.GetValue('Installed', 0) -ne 1) { return $null }
			$Major = $Key.GetValue('Major')
			$Minor = $Key.GetValue('Minor')
			$Bld = $Key.GetValue('Bld')
			if ($null -eq $Major -or $null -eq $Minor) { return $null }
			if ($null -eq $Bld) { $Bld = 0 }
			return New-Object System.Version ([int]$Major), ([int]$Minor), ([int]$Bld)
		}
		finally
		{
			$Key.Close()
		}
	}
	finally
	{
		$Base.Close()
	}
}

function Test-VcRuntime([string]$Min)
{
	# True when the installed runtime is at least $Min (vcredist_min, e.g. "14.44")
	$Installed = Get-VcRuntimeVersion
	return ($null -ne $Installed -and $Installed -ge [version]$Min)
}

function Test-MicrosoftSignature([string]$Path)
{
	# The redistributable's aka.ms URL floats, so it cannot be pinned by hash. Instead require a valid Authenticode
	# signature whose signer is Microsoft Corporation (the subject on VC_redist.x64.exe 14.44.35211:
	# CN=Microsoft Corporation, O=Microsoft Corporation, L=Redmond, S=Washington, C=US).
	$Signature = Get-AuthenticodeSignature -LiteralPath $Path
	if ($Signature.Status -ne 'Valid')
	{
		return "its signature is not valid ($($Signature.Status): $($Signature.StatusMessage))"
	}
	$Subject = $Signature.SignerCertificate.Subject
	$Parts = @($Subject -split ',\s*')
	if ($Parts -notcontains 'CN=Microsoft Corporation' -or $Parts -notcontains 'O=Microsoft Corporation')
	{
		return "it is signed by '$Subject', not Microsoft Corporation"
	}
	return $null
}

function Install-VcRuntime([string]$Min)
{
	# Downloads VC_redist.x64.exe to %TEMP%, checks its signature, runs it quietly (it shows its own UAC prompt when
	# not elevated) and maps its exit code. Returns Ok, Reboot, Message. Never reboots.
	$Result = [pscustomobject]@{ Ok = $false; Reboot = $false; Message = $null }
	$ExePath = Join-Path ([IO.Path]::GetTempPath()) "Benchy-VC_redist.x64-$([guid]::NewGuid().ToString('N')).exe"
	try
	{
		Write-Host "  $BenchyVcRedistUrl"
		try
		{
			$ProgressPreference = 'SilentlyContinue'
			Invoke-WebRequest -Uri $BenchyVcRedistUrl -OutFile $ExePath -UseBasicParsing
		}
		catch
		{
			$Result.Message = "Benchy needs the Microsoft Visual C++ Runtime $Min or newer, and it could not be downloaded ($($_.Exception.Message)). Connect to the internet, or install it from $BenchyVcRedistUrl, and run the installer again."
			return $Result
		}
		finally
		{
			$ProgressPreference = 'Continue'
		}

		$SignatureProblem = Test-MicrosoftSignature $ExePath
		if ($SignatureProblem)
		{
			$Result.Message = "The downloaded Visual C++ Runtime installer was not run: $SignatureProblem."
			return $Result
		}
		Write-Host '    signed by Microsoft Corporation'

		Write-Host '  Running the Visual C++ Runtime installer (Windows may ask for permission)...'
		try
		{
			$Process = Start-Process -FilePath $ExePath -ArgumentList '/install /quiet /norestart' -Wait -PassThru
			$Code = [int]$Process.ExitCode
		}
		catch
		{
			# Start-Process wraps a failure to start (such as a declined UAC prompt, ERROR_CANCELLED 1223) in its own
			# error; the Win32 code, when there is one, is further down the chain
			$Inner = $_.Exception
			while ($Inner -and -not ($Inner -is [System.ComponentModel.Win32Exception])) { $Inner = $Inner.InnerException }
			if (-not $Inner)
			{
				$Result.Message = "The Microsoft Visual C++ Runtime installer could not be started: $($_.Exception.Message)"
				return $Result
			}
			$Code = $Inner.NativeErrorCode
		}
		Write-Host "    exit code $Code"

		switch ($Code)
		{
			0 { $Result.Ok = $true }
			1638 { $Result.Ok = $true }  # a newer version is already installed
			3010 { $Result.Ok = $true; $Result.Reboot = $true }
			1641 { $Result.Ok = $true; $Result.Reboot = $true }
			{ $_ -eq 1602 -or $_ -eq 1223 }
			{
				$Result.Message = 'Benchy needs the Microsoft Visual C++ Runtime. Run the installer again and allow the prompt.'
				return $Result
			}
			default
			{
				$Result.Message = "The Microsoft Visual C++ Runtime installer failed with exit code $(Format-BenchyExitCode $Code)."
				return $Result
			}
		}

		if (-not (Test-VcRuntime $Min))
		{
			$Result.Ok = $false
			$Result.Message = "The Visual C++ Runtime installer finished, but Windows still reports version $(Get-VcRuntimeVersion), older than $Min."
		}
		return $Result
	}
	finally
	{
		Remove-Item -LiteralPath $ExePath -Force -ErrorAction SilentlyContinue
	}
}

# --- Running Benchy processes -------------------------------------------------

function Get-BenchyProcesses([string]$InstallDir)
{
	# php.exe processes running from <InstallDir>\php\: anything that holds the live PHP's files open, including the
	# background result viewer. Falls back to the command line when ExecutablePath cannot be read.
	$PhpDir = (Join-Path $InstallDir 'php').TrimEnd('\') + '\'
	@(Get-CimInstance Win32_Process -Filter "Name = 'php.exe'" | Where-Object {
		if ($_.ExecutablePath) { $_.ExecutablePath.StartsWith($PhpDir, [StringComparison]::OrdinalIgnoreCase) }
		else { $_.CommandLine -and $_.CommandLine.IndexOf($PhpDir, [StringComparison]::OrdinalIgnoreCase) -ge 0 }
	})
}

# --- User environment, shortcut, Phoromatic -----------------------------------

function Send-BenchySettingChange
{
	# Tells Explorer and other top-level windows that the user environment changed (WM_SETTINGCHANGE, "Environment"),
	# so programs started from them see the new PATH. Best effort: a failure only means a sign-out is needed.
	try
	{
		if (-not ('BenchyNative.SettingChange' -as [type]))
		{
			Add-Type -Namespace BenchyNative -Name SettingChange -MemberDefinition @'
[DllImport("user32.dll", SetLastError = true, CharSet = CharSet.Unicode)]
public static extern IntPtr SendMessageTimeout(IntPtr hWnd, uint Msg, UIntPtr wParam, string lParam, uint fuFlags, uint uTimeout, out UIntPtr lpdwResult);
'@
		}
		$Ignored = [UIntPtr]::Zero
		# HWND_BROADCAST, WM_SETTINGCHANGE, SMTO_ABORTIFHUNG, 5 s per window
		[void][BenchyNative.SettingChange]::SendMessageTimeout([IntPtr]0xffff, 0x1A, [UIntPtr]::Zero, 'Environment', 2, 5000, [ref]$Ignored)
	}
	catch
	{
	}
}

function Edit-BenchyUserPath([string]$InstallDir, [bool]$Add)
{
	# Reads and writes HKCU\Environment Path directly: [Environment]::Get/SetEnvironmentVariable would expand
	# %VAR% entries and write the value back as REG_SZ, flattening a REG_EXPAND_SZ PATH. Returns $true when it changed.
	$Key = [Microsoft.Win32.Registry]::CurrentUser.CreateSubKey('Environment')
	try
	{
		$Kind = [Microsoft.Win32.RegistryValueKind]::ExpandString
		if ($Key.GetValueNames() -contains 'Path')
		{
			$Existing = $Key.GetValueKind('Path')
			if ($Existing -eq [Microsoft.Win32.RegistryValueKind]::String) { $Kind = $Existing }
		}
		$UserPath = [string]$Key.GetValue('Path', '', [Microsoft.Win32.RegistryValueOptions]::DoNotExpandEnvironmentNames)

		if ($Add)
		{
			if ($UserPath.Split(';') -contains $InstallDir) { return $false }
			$NewPath = if ($UserPath) { "$UserPath;$InstallDir" } else { $InstallDir }
		}
		else
		{
			# Removes only entries that are exactly the install dir (with or without a trailing backslash)
			if (-not $UserPath) { return $false }
			$Target = $InstallDir.TrimEnd('\')
			$Entries = @($UserPath.Split(';'))
			$Kept = @($Entries | Where-Object { $_.TrimEnd('\') -ne $Target })
			if ($Kept.Count -eq $Entries.Count) { return $false }
			$NewPath = $Kept -join ';'
		}

		if ($NewPath) { $Key.SetValue('Path', $NewPath, $Kind) }
		else { $Key.DeleteValue('Path', $false) }
	}
	finally
	{
		$Key.Close()
	}
	Send-BenchySettingChange
	return $true
}

function Set-BenchyUserPath([string]$InstallDir)
{
	if (Edit-BenchyUserPath $InstallDir $true)
	{
		Write-Host "Added $InstallDir to your user PATH (new terminals will pick this up)."
	}
}

function Remove-BenchyUserPath([string]$InstallDir)
{
	return (Edit-BenchyUserPath $InstallDir $false)
}

function Set-BenchyPhpBin([string]$PhpExe)
{
	# Persists PHP_BIN so a later plain `benchy.bat` run (no install.ps1 involved) picks up this PHP
	if ([Environment]::GetEnvironmentVariable('PHP_BIN', 'User') -ne $PhpExe)
	{
		[Environment]::SetEnvironmentVariable('PHP_BIN', $PhpExe, 'User')
	}
	$env:PHP_BIN = $PhpExe
}

function Get-BenchyShortcutPath
{
	return Join-Path ([Environment]::GetFolderPath('StartMenu')) 'Programs\Benchy.lnk'
}

function New-BenchyShortcut([string]$InstallDir)
{
	$ShortcutPath = Get-BenchyShortcutPath
	$WshShell = New-Object -ComObject WScript.Shell
	$Shortcut = $WshShell.CreateShortcut($ShortcutPath)
	$Shortcut.TargetPath = Join-Path $env:WINDIR 'System32\cmd.exe'
	$Shortcut.Arguments = '/k benchy.bat'
	$Shortcut.WorkingDirectory = $InstallDir
	$Shortcut.Description = 'Benchy'
	$Shortcut.Save()
	Write-Host "Created Start Menu shortcut: $ShortcutPath"
}

function Set-BenchyPhoromaticServer([string]$InstallDir, [string]$PhpExe, [string]$Server)
{
	# Benchy's own user-config-set (it already merges into or creates user-config.xml), run by calling PHP directly
	# with PTS_SILENT_MODE=1 rather than through benchy.bat. benchy.bat resets PTS_SILENT_MODE to 0 for the main
	# command, which runs the Windows dependency handler (a Cygwin download and install that asks for elevation) and
	# starts the background result viewer. PTS_IGNORE_MODULES=1 skips loading modules, which this command does not use.
	$Saved = @{}
	$Vars = @{ PTS_MODE = 'CLIENT'; PTS_SILENT_MODE = '1'; PTS_IGNORE_MODULES = '1'; PTS_DIR = $InstallDir.TrimEnd('\') + '\'; PHP_BIN = $PhpExe }
	foreach ($Name in $Vars.Keys)
	{
		$Saved[$Name] = [Environment]::GetEnvironmentVariable($Name, 'Process')
		[Environment]::SetEnvironmentVariable($Name, $Vars[$Name], 'Process')
	}
	try
	{
		$Script = Join-Path $InstallDir 'pts-core\phoronix-test-suite.php'
		$Run = Invoke-BenchyNative $PhpExe "`"$Script`" user-config-set `"PhoromaticServers=$Server`"" $InstallDir
		if ($Run.StdOut.Trim()) { Write-Host $Run.StdOut.Trim() }
		if ($Run.ExitCode -ne 0)
		{
			throw "Benchy could not save the Phoromatic server (exit code $(Format-BenchyExitCode $Run.ExitCode)). $($Run.StdErr.Trim())"
		}
	}
	finally
	{
		foreach ($Name in $Saved.Keys) { [Environment]::SetEnvironmentVariable($Name, $Saved[$Name], 'Process') }
	}
}

# --- Apps & Features entry ----------------------------------------------------

function Get-BenchyUninstallEntry
{
	# The HKCU uninstall key's values, or $null when Benchy is not registered
	$Key = [Microsoft.Win32.Registry]::CurrentUser.OpenSubKey($BenchyUninstallKey)
	if (-not $Key) { return $null }
	try
	{
		$Values = @{}
		foreach ($Name in $Key.GetValueNames()) { $Values[$Name] = $Key.GetValue($Name) }
		return [pscustomobject]$Values
	}
	finally
	{
		$Key.Close()
	}
}

function Set-BenchyUninstallEntry([string]$InstallDir, [string]$Version, [string]$Source)
{
	# HKCU, so no admin. Install source (gui|bat) is the only value that may differ between the two install paths.
	# The updater calls this after a code apply or rollback to keep DisplayVersion current.
	$Uninstaller = Join-Path $InstallDir 'uninstall.ps1'
	$Command = "powershell.exe -NoProfile -ExecutionPolicy Bypass -File `"$Uninstaller`""
	$SizeKb = [int][Math]::Ceiling(((Get-ChildItem -LiteralPath $InstallDir -Recurse -Force -File -ErrorAction SilentlyContinue | Measure-Object -Property Length -Sum).Sum) / 1KB)
	$Icon = Join-Path $InstallDir 'pts-core\static\images\benchy.ico'

	$Key = [Microsoft.Win32.Registry]::CurrentUser.CreateSubKey($BenchyUninstallKey)
	try
	{
		$Key.SetValue('DisplayName', 'Benchy', [Microsoft.Win32.RegistryValueKind]::String)
		$Key.SetValue('DisplayVersion', $Version, [Microsoft.Win32.RegistryValueKind]::String)
		$Key.SetValue('Publisher', 'Dave Kotlyar', [Microsoft.Win32.RegistryValueKind]::String)
		$Key.SetValue('InstallLocation', $InstallDir, [Microsoft.Win32.RegistryValueKind]::String)
		$Key.SetValue('NoModify', 1, [Microsoft.Win32.RegistryValueKind]::DWord)
		$Key.SetValue('NoRepair', 1, [Microsoft.Win32.RegistryValueKind]::DWord)
		$Key.SetValue('EstimatedSize', $SizeKb, [Microsoft.Win32.RegistryValueKind]::DWord)
		$Key.SetValue('UninstallString', $Command, [Microsoft.Win32.RegistryValueKind]::String)
		$Key.SetValue('QuietUninstallString', "$Command -Quiet", [Microsoft.Win32.RegistryValueKind]::String)
		if ($Source) { $Key.SetValue('InstallSource', $Source, [Microsoft.Win32.RegistryValueKind]::String) }
		# The icon arrives with the GUI installer work; until the tree has one, Apps & Features shows its default
		if (Test-Path -LiteralPath $Icon) { $Key.SetValue('DisplayIcon', $Icon, [Microsoft.Win32.RegistryValueKind]::String) }
		else { $Key.DeleteValue('DisplayIcon', $false) }
	}
	finally
	{
		$Key.Close()
	}
}

function Remove-BenchyUninstallEntry
{
	[Microsoft.Win32.Registry]::CurrentUser.DeleteSubKeyTree($BenchyUninstallKey, $false)
}
