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

# Only named in messages, for installing the runtime by hand: the installer itself downloads the pinned VC_redist
# (runtime-pins.json, vcredist) from its versioned URLs, never from this floating one
$BenchyVcRedistUrl = 'https://aka.ms/vs/17/release/VC_redist.x64.exe'
$BenchyVcRejectedMessage = 'The Visual C++ Runtime installer changed after it was checked and was not run. Run the installer again.'
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
	# ini lines, required_extensions, vcredist_min, vcredist (version, file, size, sha256, urls)
	$PinsPath = Join-Path $TreeDir 'pts-core\static\update\runtime-pins.json'
	return (Get-Content -LiteralPath $PinsPath -Raw | ConvertFrom-Json).'windows-x64'.php
}

function Get-BenchyClientPin([string]$TreeDir)
{
	# The windows-x64 client block of the tree's runtime-pins.json (the Qt desktop client, from 0.9): version, artifact
	# (file, size, sha256), upstream_urls. $null for a tree that pins no client.
	$PinsPath = Join-Path $TreeDir 'pts-core\static\update\runtime-pins.json'
	return (Get-Content -LiteralPath $PinsPath -Raw | ConvertFrom-Json).'windows-x64'.client
}

function Get-BenchyEnginePin([string]$TreeDir)
{
	# The windows-x64 engine block of the tree's runtime-pins.json (the native engine, from 0.9.6): version, artifact
	# (file, size, sha256), upstream_urls. $null for a tree that pins no engine.
	$PinsPath = Join-Path $TreeDir 'pts-core\static\update\runtime-pins.json'
	return (Get-Content -LiteralPath $PinsPath -Raw | ConvertFrom-Json).'windows-x64'.engine
}

function Test-PinnedArtifact([string]$Path, $Artifact)
{
	# True when the file has exactly the pinned size and sha256 ($Artifact: file, size, sha256)
	if (-not (Test-Path -LiteralPath $Path -PathType Leaf)) { return $false }
	$Size = (Get-Item -LiteralPath $Path).Length
	$Hash = (Get-FileHash -LiteralPath $Path -Algorithm SHA256).Hash
	if ($Size -eq $Artifact.size -and $Hash -eq $Artifact.sha256)
	{
		Write-Host "    sha256 verified: $($Artifact.sha256)"
		return $true
	}
	Write-Host "    REJECTED: got $Size bytes, sha256 $Hash; the pin expects $($Artifact.size) bytes, sha256 $($Artifact.sha256)" -ForegroundColor Red
	return $false
}

function Get-PinPhpVersion($Pin)
{
	# A windows.php.net rebuild carries a -N suffix (8.5.12-1) that PHP_VERSION does not (8.5.12)
	return ($Pin.version -replace '-.*$', '')
}

function Test-PhpZip([string]$Path, $Pin)
{
	# True when the file has exactly the pinned size and sha256
	return (Test-PinnedArtifact $Path $Pin.artifact)
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
		# 0xC0000135 STATUS_DLL_NOT_FOUND: the loader could not find a DLL php.exe needs: vcruntime140.dll, or one of
		# PHP's own (php8.dll)
		$Result.Message = "PHP could not start: a DLL it needs is missing (the Microsoft Visual C++ Runtime, or one of PHP's own files) (exit code $(Format-BenchyExitCode $Run.ExitCode))."
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

# --- Native calls ------------------------------------------------------------

function Add-BenchyNativeType([string]$Name, [string]$Definition)
{
	# Compiles a P/Invoke type BenchyNative.<Name> once per process. The C# compiler looks for System.dll in the current
	# directory first: the GUI installer's temp dir holds NSIS's own System.dll (a 32-bit plugin), which broke the
	# compile. The .NET runtime directory has the real one.
	if ("BenchyNative.$Name" -as [type]) { return }
	$OldDirectory = [Environment]::CurrentDirectory
	try
	{
		[Environment]::CurrentDirectory = [Runtime.InteropServices.RuntimeEnvironment]::GetRuntimeDirectory()
		Add-Type -Namespace BenchyNative -Name $Name -MemberDefinition $Definition
	}
	finally
	{
		[Environment]::CurrentDirectory = $OldDirectory
	}
}

function Get-BenchyFinalPath($Handle)
{
	# The path of an open file with every junction and symbolic link resolved (GetFinalPathNameByHandleW, DOS volume
	# name), without the \\?\ prefix. Every folder in it is a real folder, so while the handle is open none of them can
	# be renamed or deleted, and the path names exactly the file the handle holds. Throws when it cannot be read.
	Add-BenchyNativeType FinalPath @'
[DllImport("kernel32.dll", SetLastError = true, CharSet = CharSet.Unicode)]
public static extern uint GetFinalPathNameByHandleW(Microsoft.Win32.SafeHandles.SafeFileHandle hFile, System.Text.StringBuilder lpszFilePath, uint cchFilePath, uint dwFlags);
'@
	$Buffer = New-Object System.Text.StringBuilder 1024
	$Length = [BenchyNative.FinalPath]::GetFinalPathNameByHandleW($Handle, $Buffer, 1024, 0)
	if ($Length -eq 0 -or $Length -ge 1024)
	{
		throw "GetFinalPathNameByHandle failed (Windows error $([Runtime.InteropServices.Marshal]::GetLastWin32Error()), length $Length)"
	}
	$Path = $Buffer.ToString()
	if ($Path.StartsWith('\\?\UNC\')) { return '\\' + $Path.Substring(8) }
	if ($Path.StartsWith('\\?\')) { return $Path.Substring(4) }
	return $Path
}

function Test-BenchyReparsePoint([string]$Path)
{
	# True when $Path itself (not what it points to) is a junction, symbolic link or other reparse point. Throws when
	# it does not exist.
	return [bool]([IO.File]::GetAttributes($Path) -band [IO.FileAttributes]::ReparsePoint)
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
	# Besides the pinned size and sha256 (defence in depth): a valid Authenticode signature whose signer is Microsoft
	# Corporation (the subject on VC_redist.x64.exe 14.44.35211:
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

function Open-VcRedistPinned([string]$Path)
{
	# Opens VC_redist.x64.exe read-only, sharing read only: while the stream is open no process can write, replace,
	# rename or delete the file, and Windows can still start it (the image loader only reads). Tries 5 times, 200 ms
	# apart, since a virus scanner may hold a freshly written file for a moment; then the last error is thrown.
	for ($Attempt = 1; ; $Attempt++)
	{
		try
		{
			return [IO.File]::Open($Path, [IO.FileMode]::Open, [IO.FileAccess]::Read, [IO.FileShare]::Read)
		}
		catch
		{
			if ($Attempt -ge 5) { throw }
			Start-Sleep -Milliseconds 200
		}
	}
}

function Get-VcRedistMismatch($Stream, $VcPin)
{
	# Checks an open VC_redist.x64.exe stream against the pin (runtime-pins.json, vcredist). Returns $null when its size
	# and sha256 match, otherwise what was found. Hashes with .NET directly, not Get-FileHash, which autoloads a module
	# from a user-writable module path.
	$Stream.Position = 0
	$Sha256 = [Security.Cryptography.SHA256]::Create()
	try
	{
		$Hash = -join @($Sha256.ComputeHash($Stream) | ForEach-Object { $_.ToString('x2') })
	}
	finally
	{
		$Sha256.Dispose()
	}
	$Size = $Stream.Length
	if ($Size -eq [long]$VcPin.size -and $Hash -eq ([string]$VcPin.sha256).ToLowerInvariant())
	{
		Write-Host "    size and sha256 match the pin: $Size bytes, $Hash"
		return $null
	}
	return "got $Size bytes, sha256 $Hash; the pin expects $($VcPin.size) bytes, sha256 $($VcPin.sha256)"
}

function Save-VcRuntimeInstaller([string]$Min, [string]$ExePath, $VcPin)
{
	# Downloads the pinned VC_redist.x64.exe (runtime-pins.json, vcredist) to $ExePath, trying each pinned URL in order,
	# and checks its size, sha256 and signature. $ExePath goes in a folder of its own, which holds nothing else
	# (VC_redist is a WiX Burn bundle that starts from there); the caller makes it, fresh and not a link. Returns $null
	# when it is ready to run, otherwise the message for exit code 21.
	$Saved = $false
	$LastProblem = $null
	foreach ($Url in @($VcPin.urls))
	{
		Write-Host "  $Url"
		try
		{
			$ProgressPreference = 'SilentlyContinue'
			Invoke-WebRequest -Uri $Url -OutFile $ExePath -UseBasicParsing
		}
		catch
		{
			$LastProblem = "it could not be downloaded ($($_.Exception.Message))"
			Write-Host "    not available there ($($_.Exception.Message))"
			Remove-Item -LiteralPath $ExePath -Force -ErrorAction SilentlyContinue
			continue
		}
		finally
		{
			$ProgressPreference = 'Continue'
		}

		try
		{
			$Stream = Open-VcRedistPinned $ExePath
			try { $Mismatch = Get-VcRedistMismatch $Stream $VcPin } finally { $Stream.Dispose() }
		}
		catch
		{
			$Mismatch = "it could not be read ($($_.Exception.Message))"
		}
		if (-not $Mismatch)
		{
			$Saved = $true
			break
		}
		$LastProblem = "the download did not match the pinned version $($VcPin.version) ($Mismatch)"
		Write-Host "    REJECTED: $Mismatch" -ForegroundColor Red
		Remove-Item -LiteralPath $ExePath -Force -ErrorAction SilentlyContinue
	}
	if (-not $Saved)
	{
		return "Benchy needs the Microsoft Visual C++ Runtime $Min or newer, and its installer was not run: $LastProblem. Connect to the internet, or install it from $BenchyVcRedistUrl, and run the installer again."
	}

	$SignatureProblem = Test-MicrosoftSignature $ExePath
	if ($SignatureProblem)
	{
		return "The downloaded Visual C++ Runtime installer was not run: $SignatureProblem."
	}
	Write-Host '    signed by Microsoft Corporation'
	return $null
}

function Get-VcRuntimeResult([string]$Min, [int]$Code)
{
	# Maps the exit code of VC_redist.x64.exe /install /quiet /norestart (or the Win32 error of a launch that failed,
	# such as 1223 ERROR_CANCELLED for a declined UAC prompt) and checks the runtime Windows now reports. Returns Ok,
	# Reboot, RebootRequired, Rejected (always false here) and Message, as Install-VcRuntime does.
	$Result = [pscustomobject]@{ Ok = $false; Reboot = $false; RebootRequired = $false; Rejected = $false; Message = $null }
	Write-Host "    exit code $Code"

	switch ($Code)
	{
		0 { $Result.Ok = $true }
		1638 { $Result.Ok = $true }  # a newer version is already installed
		3010 { $Result.Ok = $true; $Result.Reboot = $true }
		1641 { $Result.Ok = $true; $Result.Reboot = $true }
		{ $_ -eq 1602 -or $_ -eq 1223 }
		{
			$Result.Message = 'The Microsoft Visual C++ Runtime installer was cancelled at the Windows permission prompt. Benchy needs it: run the installer again and allow the prompt.'
			return $Result
		}
		{ $_ -eq 65535 -or $_ -eq -1 }
		{
			# What an elevated launch that never ran leaves behind, as when the UAC prompt is cancelled; a real installer
			# failure has its own code, so the wording admits it cannot tell
			$Result.Message = "The Microsoft Visual C++ Runtime installer was cancelled or could not start (exit code $(Format-BenchyExitCode $Code)). If the Windows permission prompt was cancelled, run the installer again and allow it."
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
		$Reported = Get-VcRuntimeVersion
		$Reported = if ($Reported) { "version $Reported" } else { 'no runtime' }
		if ($Result.Reboot)
		{
			# 3010/1641: the new runtime is in place only after a restart, so Windows still reports the old one
			$Result.RebootRequired = $true
			$Result.Message = "The Microsoft Visual C++ Runtime needs a restart before Benchy can use it (Windows reports $Reported, Benchy needs $Min or newer). Restart Windows, then run the installer again."
		}
		else
		{
			$Result.Message = "The Microsoft Visual C++ Runtime installer reported success, but Windows reports $Reported, and Benchy needs $Min or newer."
		}
	}
	return $Result
}

function Install-VcRuntime([string]$Min, $VcPin)
{
	# Downloads the pinned VC_redist.x64.exe to a folder of its own in %TEMP%, checks it, runs it quietly (it shows its
	# own UAC prompt when not elevated) and maps its exit code. Returns Ok, Reboot (a restart is recommended),
	# RebootRequired (the runtime is not usable until a restart; Ok is then false), Rejected (the file changed after it
	# was checked and was not run: exit code 22) and Message. Never reboots.
	# The file is held open (read-only, sharing read only) from the second size and sha256 check until the installer
	# has exited, so nothing can swap it between the check and the elevated start. It is started by the final path of
	# that handle (links resolved), and refused when its folder is a link, so re-pointing a junction cannot swap it
	# either.
	# The GUI installer does not use this: install.ps1 -VcRedistHandOff saves the installer for it (Save-VcRuntimeInstaller),
	# benchy.nsi does the held check and the start, and the exit code comes back with -VcRedistExitCode
	# (Get-VcRuntimeResult).
	$VcDir = Join-Path ([IO.Path]::GetTempPath()) "Benchy-vc-$([guid]::NewGuid().ToString('N'))"
	$ExePath = Join-Path $VcDir $VcPin.file
	# Fresh: without -Force, New-Item fails on anything already there (a junction someone made first) instead of using it
	New-Item -ItemType Directory -Path $VcDir | Out-Null
	try
	{
		$Problem = Save-VcRuntimeInstaller $Min $ExePath $VcPin
		if ($Problem)
		{
			return [pscustomobject]@{ Ok = $false; Reboot = $false; RebootRequired = $false; Rejected = $false; Message = $Problem }
		}

		Write-Host '  Checking it again, held open until it has run...'
		try
		{
			$Stream = Open-VcRedistPinned $ExePath
		}
		catch
		{
			Write-Host "    REJECTED: it could not be opened for the check ($($_.Exception.Message))" -ForegroundColor Red
			return [pscustomobject]@{ Ok = $false; Reboot = $false; RebootRequired = $false; Rejected = $true; Message = $BenchyVcRejectedMessage }
		}
		try
		{
			try
			{
				# After the handle is taken: a link in place of the folder now cannot be swapped back unnoticed, since the
				# final path below names the folder the handle really went through
				if (Test-BenchyReparsePoint $VcDir) { throw "$VcDir is a junction or symbolic link" }
				$FinalPath = Get-BenchyFinalPath $Stream.SafeFileHandle
			}
			catch
			{
				Write-Host "    REJECTED: $($_.Exception.Message)" -ForegroundColor Red
				return [pscustomobject]@{ Ok = $false; Reboot = $false; RebootRequired = $false; Rejected = $true; Message = $BenchyVcRejectedMessage }
			}
			$Mismatch = Get-VcRedistMismatch $Stream $VcPin
			if ($Mismatch)
			{
				Write-Host "    REJECTED: $Mismatch" -ForegroundColor Red
				return [pscustomobject]@{ Ok = $false; Reboot = $false; RebootRequired = $false; Rejected = $true; Message = $BenchyVcRejectedMessage }
			}

			Write-Host '  Running the Visual C++ Runtime installer (Windows may ask for permission)...'
			Write-Host "    $FinalPath"
			try
			{
				$Process = Start-Process -FilePath $FinalPath -ArgumentList '/install /quiet /norestart' -Wait -PassThru
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
					return [pscustomobject]@{ Ok = $false; Reboot = $false; RebootRequired = $false; Rejected = $false; Message = "The Microsoft Visual C++ Runtime installer could not be started: $($_.Exception.Message)" }
				}
				$Code = $Inner.NativeErrorCode
			}
		}
		finally
		{
			$Stream.Dispose()
		}
		return Get-VcRuntimeResult $Min $Code
	}
	finally
	{
		# Without following links: a junction put in place of the folder is removed as a link, its target left alone
		try
		{
			if (-not (Test-BenchyReparsePoint $VcDir)) { [void](Remove-BenchyTree $VcDir) }
			[IO.Directory]::Delete($VcDir, $false)
		}
		catch
		{
		}
	}
}

# --- Running Benchy processes -------------------------------------------------

function Get-BenchyProcesses([string]$InstallDir)
{
	# Processes that are Benchy from this install, returned as Win32_Process objects (ProcessId, Name, CommandLine):
	# - php.exe running from <InstallDir>\php\ (anything holding the live PHP's files open, the result viewer too);
	# - php.exe of any PHP whose command line runs <InstallDir>\pts-core (an install using an external PHP);
	# - cmd.exe running <InstallDir>\benchy.bat, or the Start Menu shortcut's "cmd.exe /k benchy.bat", which names no
	#   directory (it starts in the install dir), so that form matches whatever directory it was started in;
	# - BenchyClient.exe (the desktop client) running from <InstallDir>\client\ (or client.new, client.prev): an
	#   install swaps that folder, and a running client holds its files;
	# - benchy-engine.exe (the native engine, and with it the server agent the tray keeps running) running from
	#   <InstallDir>\engine\ (or engine.new, engine.prev), for the same reason. An engine from anywhere else (a dev
	#   build, another install) is not this install's and is left alone.
	# Command lines are compared with quotes dropped and runs of \ or / folded to one \, because benchy.bat builds
	# "%PTS_DIR%\pts-core" from a PTS_DIR that already ends in \.
	# That folding also turns the shortcut's "/k" into "\k", so its pattern accepts either slash.
	# Phase 1 note: apply-update.ps1 runs inside benchy.bat's cmd.exe, so it has to leave out its own ancestors.
	$Root = $InstallDir.TrimEnd('\')
	$PhpDir = "$Root\php\"
	$CodeDir = "$Root\pts-core\"
	$Launcher = "$Root\benchy.bat"
	$ClientPrefix = "$Root\client"
	$EnginePrefix = "$Root\engine"
	@(Get-CimInstance Win32_Process -Filter "Name = 'php.exe' OR Name = 'cmd.exe' OR Name = 'BenchyClient.exe' OR Name = 'benchy-engine.exe'" | Where-Object {
		$CommandLine = if ($_.CommandLine) { ($_.CommandLine -replace '"', '') -replace '[\\/]+', '\' } else { '' }
		if ($_.Name -eq 'BenchyClient.exe')
		{
			# client\, client.new\ or client.prev\ (the prefix, then a \ or a dot)
			$_.ExecutablePath -and $_.ExecutablePath.StartsWith($ClientPrefix, [StringComparison]::OrdinalIgnoreCase) -and
				$_.ExecutablePath.Length -gt $ClientPrefix.Length -and $_.ExecutablePath[$ClientPrefix.Length] -in @('\', '.')
		}
		elseif ($_.Name -eq 'benchy-engine.exe')
		{
			# engine\, engine.new\ or engine.prev\ (the prefix, then a \ or a dot)
			$_.ExecutablePath -and $_.ExecutablePath.StartsWith($EnginePrefix, [StringComparison]::OrdinalIgnoreCase) -and
				$_.ExecutablePath.Length -gt $EnginePrefix.Length -and $_.ExecutablePath[$EnginePrefix.Length] -in @('\', '.')
		}
		elseif ($_.Name -eq 'php.exe')
		{
			($_.ExecutablePath -and $_.ExecutablePath.StartsWith($PhpDir, [StringComparison]::OrdinalIgnoreCase)) -or
			$CommandLine.IndexOf($PhpDir, [StringComparison]::OrdinalIgnoreCase) -ge 0 -or
			$CommandLine.IndexOf($CodeDir, [StringComparison]::OrdinalIgnoreCase) -ge 0
		}
		else
		{
			$CommandLine.IndexOf($Launcher, [StringComparison]::OrdinalIgnoreCase) -ge 0 -or
			$CommandLine -match '(?i)\s[\\/]k\s+benchy\.bat\s*$'
		}
	})
}

function Format-BenchyProcesses($Processes)
{
	# "php.exe PID 123, cmd.exe PID 456" for refusal messages
	return (@($Processes | ForEach-Object { "$($_.Name) PID $($_.ProcessId)" }) -join ', ')
}

function Get-BenchyRunningAdvice($Processes, [string]$Again)
{
	# The tail of the exit-3 refusal. $Again is what to run again: 'the installer' or 'the uninstaller'. The tray
	# (BenchyClient.exe --tray) is only there while the client has an agent to show; a bare benchy-engine.exe (the server
	# agent with no tray, after "Quit, keep running") has no icon, so the text then says where Quit is: in the window.
	$Client = @($Processes | Where-Object { $_.Name -eq 'BenchyClient.exe' }).Count -gt 0
	$Engine = @($Processes | Where-Object { $_.Name -eq 'benchy-engine.exe' }).Count -gt 0
	if ($Client) { return "Quit Benchy from its tray icon first (right-click it, then Quit), close any other Benchy window, and run $Again again." }
	if ($Engine) { return "Open Benchy, then choose Quit and 'Quit and stop the agent' (or Cancel, to leave it running and stop here), close any other Benchy window, and run $Again again." }
	return "Close every Benchy window and run $Again again."
}

function Get-BenchyFilesInUse([string]$InstallDir, [switch]$SkipPhp)
{
	# The Benchy executables under the install dir (client\BenchyClient.exe, engine\native\benchy-engine.exe, and the .new
	# and .prev folders of a swap, and php\php.exe) that cannot be opened for writing with no sharing. Windows keeps a running exe
	# open for execution, whoever started it, so this finds a Benchy running as administrator that
	# Get-BenchyProcesses cannot see from an unelevated installer. Windows lets a folder holding a running exe be
	# renamed, so without this check a swap would move the engine out from under it. Returns the paths; none means
	# nothing is held.
	$Held = @()
	foreach ($Dir in @('client', 'client.new', 'client.prev'))
	{
		$Held += Get-BenchyFileInUse (Join-Path $InstallDir "$Dir\BenchyClient.exe")
	}
	foreach ($Dir in @('engine', 'engine.new', 'engine.prev'))
	{
		$Held += Get-BenchyFileInUse (Join-Path $InstallDir "$Dir\native\benchy-engine.exe")
	}
	# A Benchy command line running as administrator (benchy.bat, or the result viewer) holds the managed PHP open; the process list
	# cannot show it to an unelevated installer, and the installer replaces that PHP
	# (-SkipPhp: an installer that has just put its own PHP there has no reason to look)
	if (-not $SkipPhp) { $Held += Get-BenchyFileInUse (Join-Path $InstallDir 'php\php.exe') }
	return @($Held | Where-Object { $_ })
}

function Get-BenchyFileInUse([string]$Path)
{
	# $Path when it exists and is held open (a sharing violation on an open for read and write with no sharing), else
	# nothing. Any other failure (no such file, access denied) is not "in use"; the later steps report those.
	if (-not (Test-Path -LiteralPath $Path -PathType Leaf)) { return }
	# Tried for about three seconds: a virus scanner can hold a file for a good while after it was written or unpacked (a false
	# positive scan of a freshly extracted exe), a running program does not let go. A file nobody holds is opened at once.
	for ($Try = 1; $Try -le 10; $Try++)
	{
		try
		{
			$Stream = [IO.File]::Open($Path, [IO.FileMode]::Open, [IO.FileAccess]::ReadWrite, [IO.FileShare]::None)
			$Stream.Dispose()
			return
		}
		catch [IO.FileNotFoundException] { return }
		catch [IO.DirectoryNotFoundException] { return }
		catch [IO.IOException] { if ($Try -ge 10) { return $Path }; Start-Sleep -Milliseconds 300 }
		catch { return }
	}
}

function Get-BenchyInUseAdvice($Held, [string]$Again, [string]$Changed = 'Nothing was changed.')
{
	# The exit-3 text for Get-BenchyFilesInUse. $Changed ends it: the first check is before anything is touched; the one just before the
	# copy (empty $Changed: the caller's own closing line says what was left alone) comes after the PHP step.
	return "Benchy is running: $(@($Held) -join ', ') is in use. It may be running as administrator, which this program cannot see. Quit it from its tray icon (or open Benchy, then choose Quit and 'Quit and stop the agent'), or close it, and run $Again again. $Changed".TrimEnd()
}

# --- User environment, shortcut, Phoromatic -----------------------------------

function Send-BenchySettingChange
{
	# Tells Explorer and other top-level windows that the user environment changed (WM_SETTINGCHANGE, "Environment"),
	# so programs started from them see the new PATH. Best effort: a failure only means a sign-out is needed.
	try
	{
		Add-BenchyNativeType SettingChange @'
[DllImport("user32.dll", SetLastError = true, CharSet = CharSet.Unicode)]
public static extern IntPtr SendMessageTimeout(IntPtr hWnd, uint Msg, UIntPtr wParam, string lParam, uint fuFlags, uint uTimeout, out UIntPtr lpdwResult);
'@
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

function Get-BenchyPromptShortcutPath
{
	return Join-Path ([Environment]::GetFolderPath('StartMenu')) 'Programs\Benchy Command Prompt.lnk'
}

function Get-BenchyClientExe([string]$InstallDir)
{
	return Join-Path $InstallDir 'client\BenchyClient.exe'
}

function Save-BenchyShortcut([string]$Path, [string]$Target, [string]$Arguments, [string]$WorkingDirectory, [string]$Description, [string]$Icon)
{
	$WshShell = New-Object -ComObject WScript.Shell
	$Shortcut = $WshShell.CreateShortcut($Path)
	$Shortcut.TargetPath = $Target
	$Shortcut.Arguments = $Arguments
	$Shortcut.WorkingDirectory = $WorkingDirectory
	$Shortcut.Description = $Description
	$Shortcut.IconLocation = $Icon
	$Shortcut.Save()
	Write-Host "Created Start Menu shortcut: $Path"
}

function New-BenchyShortcut([string]$InstallDir)
{
	# "Benchy" opens the desktop client when one is installed (client\BenchyClient.exe, design C6), with "Benchy
	# Command Prompt" for the command line; without a client "Benchy" is the command prompt, as before 0.9.
	# The same icon as the Apps & Features entry.
	$Icon = (Join-Path $InstallDir 'pts-core\static\images\benchy.ico') + ',0'
	$Cmd = Join-Path $env:WINDIR 'System32\cmd.exe'
	$ClientExe = Get-BenchyClientExe $InstallDir
	$Prompt = Get-BenchyPromptShortcutPath
	if (Test-Path -LiteralPath $ClientExe -PathType Leaf)
	{
		Save-BenchyShortcut (Get-BenchyShortcutPath) $ClientExe '' (Split-Path $ClientExe) 'Benchy' $Icon
		Save-BenchyShortcut $Prompt $Cmd '/k benchy.bat' $InstallDir 'Benchy command prompt' $Icon
	}
	else
	{
		Save-BenchyShortcut (Get-BenchyShortcutPath) $Cmd '/k benchy.bat' $InstallDir 'Benchy' $Icon
		if (Test-Path -LiteralPath $Prompt) { Remove-Item -LiteralPath $Prompt -Force }
	}
}

function Rename-BenchyDirRetry([string]$Path, [string]$NewName)
{
	# Rename-Item, retried a few times with short sleeps: Defender (or the search indexer) can hold a file in a freshly
	# unpacked or just-closed folder for a moment, which fails the rename with "in use". Throws the last error.
	for ($Try = 1; ; $Try++)
	{
		try { Rename-Item -LiteralPath $Path -NewName $NewName -ErrorAction Stop; return }
		catch
		{
			if ($Try -ge 5) { throw }
			Start-Sleep -Milliseconds (500 * $Try)
		}
	}
}

function New-BenchyStagedDir([string]$InstallDir, $Spec)
{
	# Step one of a swap: a pinned zip (already checked against the pin) extracted to <InstallDir>\<Name>.new (below
	# $Spec.UnpackSub when given) and checked for the exe that must be in it ($Spec.ExeRel; $Spec.What names the part in
	# messages). Nothing live is touched, except that an earlier run that died between the two renames of a swap is put
	# right first: no live exe but a good .prev, which is then the only copy, so it is put back before anything is deleted.
	# Returns the paths for Install-BenchySwappedDirs. Throws (exit code 40) when it can't.
	$Name = $Spec.Name
	$What = $Spec.What
	$New = Join-Path $InstallDir "$Name.new"
	$Live = Join-Path $InstallDir $Name
	$Prev = Join-Path $InstallDir "$Name.prev"
	$ExeRel = $Spec.ExeRel
	$LiveExe = Join-Path $Live $ExeRel
	if ((-not (Test-Path -LiteralPath $LiveExe -PathType Leaf)) -and (Test-Path -LiteralPath (Join-Path $Prev $ExeRel) -PathType Leaf))
	{
		try
		{
			if (Test-Path -LiteralPath $Live) { Remove-Item -LiteralPath $Live -Recurse -Force -ErrorAction Stop }
			Rename-BenchyDirRetry $Prev $Name
		}
		catch { Stop-Benchy 40 "The previous $What in $Prev could not be put back as $Live ($($_.Exception.Message)). Close every Benchy window and run the installer again." }
		Write-Host "Restored the previous $What (an earlier install did not finish)."
	}
	if (Test-Path -LiteralPath $New)
	{
		try { Remove-Item -LiteralPath $New -Recurse -Force -ErrorAction Stop }
		catch { Stop-Benchy 40 "The old $What in $New could not be deleted ($($_.Exception.Message)). Close every Benchy window and run the installer again." }
	}
	try
	{
		$Dest = if ($Spec.UnpackSub) { Join-Path $New $Spec.UnpackSub } else { $New }
		[void](New-Item -ItemType Directory -Path $Dest -Force)
		Expand-Archive -LiteralPath $Spec.Zip -DestinationPath $Dest -Force
	}
	catch
	{
		Remove-Item -LiteralPath $New -Recurse -Force -ErrorAction SilentlyContinue
		Stop-Benchy 40 "The $What could not be unpacked to ${New}: $($_.Exception.Message)"
	}
	if (-not (Test-Path -LiteralPath (Join-Path $New $ExeRel) -PathType Leaf))
	{
		Remove-Item -LiteralPath $New -Recurse -Force -ErrorAction SilentlyContinue
		Stop-Benchy 40 "The $What zip has no $(Split-Path -Leaf $ExeRel) at its top level."
	}
	return [pscustomobject]@{ Name = $Name; What = $What; New = $New; Live = $Live; Prev = $Prev; ExeRel = $ExeRel; HadLive = $false; Moved = $false; Placed = $false }
}

function Install-BenchySwappedDirs([string]$InstallDir, $Specs)
{
	# One client and/or engine swap, as one step: every .new folder is extracted and checked first (a bad zip stops
	# everything with nothing live changed), then every live folder is renamed to .prev, then every .new to live. A
	# rename that fails puts back what was already moved, so the client and the engine are never from different
	# installs. What a swap replaces is kept as <Name>.prev for one version (the updater can roll back to it, as with
	# php.prev). Throws (exit code 40) when it can't.
	$Staged = @()
	try { foreach ($Spec in @($Specs)) { $Staged += New-BenchyStagedDir $InstallDir $Spec } }
	catch
	{
		foreach ($S in $Staged) { Remove-Item -LiteralPath $S.New -Recurse -Force -ErrorAction SilentlyContinue }
		throw
	}
	# Live to .prev, for all of them first: that is the rename an open file in the folder makes fail. What is in .prev
	# now is junk (New-BenchyStagedDir put back a .prev that was the only working copy), and would make the rename fail.
	foreach ($S in $Staged)
	{
		try
		{
			if (Test-Path -LiteralPath $S.Prev) { Remove-Item -LiteralPath $S.Prev -Recurse -Force -ErrorAction Stop }
		}
		catch
		{
			$Why = $_.Exception.Message
			$Left = @(Undo-BenchyStagedDirs $Staged)
			Stop-Benchy 40 "The old $($S.What) in $($S.Prev) could not be deleted ($Why). Close every Benchy window and run the installer again. $(Get-BenchyUndoText $Left 'The client and engine were left as they were.')"
		}
		$S.HadLive = Test-Path -LiteralPath $S.Live
		if (-not $S.HadLive) { continue }
		try { Rename-BenchyDirRetry $S.Live "$($S.Name).prev"; $S.Moved = $true }
		catch
		{
			$Why = $_.Exception.Message
			$Left = @(Undo-BenchyStagedDirs $Staged)
			Stop-Benchy 40 "The $($S.What) in $($S.Live) is in use and could not be replaced ($Why). Close Benchy and run the installer again. $(Get-BenchyUndoText $Left 'The client and engine were left as they were.')"
		}
	}
	foreach ($S in $Staged)
	{
		try { Rename-BenchyDirRetry $S.New $S.Name; $S.Placed = $true }
		catch
		{
			$Why = $_.Exception.Message
			$Left = @(Undo-BenchyStagedDirs $Staged)
			# "Put back" only when there was something to put back: a first install moved nothing
			$Back = if (@($Staged | Where-Object { $_.Moved }).Count -gt 0) { 'The earlier version was put back.' } else { 'Nothing of the client and engine was installed.' }
			Stop-Benchy 40 "The new $($S.What) could not be put in place ($Why). $(Get-BenchyUndoText $Left $Back) Run the installer again."
		}
	}
	foreach ($S in $Staged) { Write-Host "Installed the $($S.What) to $($S.Live)" }
}

function Undo-BenchyStagedDirs($Staged)
{
	# Best effort, newest step first: a .new that was placed goes back to .new (and is deleted), a live folder moved to .prev comes back.
	# Returns what could not be put back, one text each (nothing when all is as it was), for Get-BenchyUndoText.
	$Left = @()
	for ($I = @($Staged).Count - 1; $I -ge 0; $I--)
	{
		$S = @($Staged)[$I]
		if ($S.Placed)
		{
			try { Rename-BenchyDirRetry $S.Live "$($S.Name).new" }
			catch { $Left += "the new $($S.What) in $($S.Live) could not be moved out of the way ($($_.Exception.Message))" }
		}
		if ($S.Moved)
		{
			try { Rename-BenchyDirRetry $S.Prev $S.Name }
			catch { $Left += "the earlier $($S.What) is still in $($S.Prev) and could not be renamed back to $($S.Live) ($($_.Exception.Message))" }
		}
		Remove-Item -LiteralPath $S.New -Recurse -Force -ErrorAction SilentlyContinue
	}
	return $Left
}

function Get-BenchyUndoText($Left, [string]$AllWell)
{
	# What to tell the person after Undo-BenchyStagedDirs: $AllWell when everything went back, else what did not
	if (@($Left).Count -eq 0) { return $AllWell }
	return "Putting the earlier version back did not finish: $(@($Left) -join '; '). If a folder is missing, rename its .prev folder to its name by hand."
}

function Get-BenchyClientSwap([string]$Zip)
{
	# The client zip into client\ (BenchyClient.exe at its root)
	return [pscustomobject]@{ Zip = $Zip; Name = 'client'; ExeRel = 'BenchyClient.exe'; What = 'client'; UnpackSub = '' }
}

function Get-BenchyEngineExe([string]$InstallDir)
{
	# Where the desktop client looks for the native engine: <install dir>\engine\native\benchy-engine.exe
	return Join-Path $InstallDir 'engine\native\benchy-engine.exe'
}

function Get-BenchyEngineSwap([string]$Zip)
{
	# The engine zip (benchy-engine.exe and LICENSE at its root) into engine\native\: extracted to engine.new\native
	return [pscustomobject]@{ Zip = $Zip; Name = 'engine'; ExeRel = 'native\benchy-engine.exe'; What = 'engine'; UnpackSub = 'native' }
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

# --- Deleting without following links ----------------------------------------

function Remove-BenchyTree([string]$Path, [string[]]$Keep = @())
{
	# Deletes everything inside $Path (not $Path itself) without following a junction or symbolic link: a link is
	# removed as a link, so a junction to data elsewhere (say an installed-tests folder on another drive) leaves that
	# data alone. Files listed in $Keep (full paths) survive, with the folders that hold them. Returns the full paths
	# that could not be removed, each failure once (a folder whose contents failed is not listed again itself); none
	# means only the kept files are left.
	# Known limitation: Windows PowerShell 5.1 cannot list or delete paths longer than 260 characters here. Such an
	# item is reported as not removed (the caller exits 40 and names it), never skipped silently.
	# A null or empty entry in $Keep (a caller's "nothing to keep" can arrive as $null, in Windows PowerShell 5.1 too) is no entry
	$Keep = @($Keep | Where-Object { $_ })
	$Failed = @()
	$Children = $null
	try { $Children = @(Get-ChildItem -LiteralPath $Path -Force) }
	catch { return @($Path) }
	foreach ($Item in $Children)
	{
		$Full = $Item.FullName
		if ($Keep -contains $Full) { continue }
		try
		{
			if ($Item.Attributes -band [IO.FileAttributes]::ReadOnly)
			{
				$Item.Attributes = $Item.Attributes -band (-bnot [IO.FileAttributes]::ReadOnly)
			}
			if ($Item.Attributes -band [IO.FileAttributes]::ReparsePoint)
			{
				if ($Item.PSIsContainer) { [IO.Directory]::Delete($Full, $false) } else { [IO.File]::Delete($Full) }
			}
			elseif ($Item.PSIsContainer)
			{
				$ChildFailed = @(Remove-BenchyTree $Full $Keep)
				$Failed += $ChildFailed
				$HoldsKept = @($Keep | Where-Object { $_.StartsWith($Full + '\', [StringComparison]::OrdinalIgnoreCase) }).Count -gt 0
				if ($ChildFailed.Count -eq 0 -and -not $HoldsKept) { [IO.Directory]::Delete($Full, $false) }
			}
			else
			{
				[IO.File]::Delete($Full)
			}
		}
		catch
		{
			$Failed += $Full
		}
	}
	return $Failed
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
		# The same icon as the GUI installer's (deploy/windows-installer/benchy.nsi); a tree without one gets the default
		if (Test-Path -LiteralPath $Icon) { $Key.SetValue('DisplayIcon', $Icon, [Microsoft.Win32.RegistryValueKind]::String) }
		else { $Key.DeleteValue('DisplayIcon', $false) }
	}
	finally
	{
		$Key.Close()
	}
}

function Remove-BenchyAutostart
{
	# The tray's "start the server agent when I log in" setting (the desktop client writes it): the Run value
	# HKCU\Software\Microsoft\Windows\CurrentVersion\Run\Benchy, and the Explorer/Task Manager on-off switch for it,
	# Explorer\StartupApproved\Run\Benchy. Left behind, the login entry would point at a client that is gone.
	# Returns $true when a value was deleted. Throws when a value exists and can't be deleted.
	$Removed = $false
	foreach ($Path in @('Software\Microsoft\Windows\CurrentVersion\Run', 'Software\Microsoft\Windows\CurrentVersion\Explorer\StartupApproved\Run'))
	{
		$Key = [Microsoft.Win32.Registry]::CurrentUser.OpenSubKey($Path, $true)
		if (-not $Key) { continue }
		try
		{
			if ($null -ne $Key.GetValue('Benchy', $null))
			{
				$Key.DeleteValue('Benchy', $false)
				$Removed = $true
			}
		}
		finally { $Key.Close() }
	}
	return $Removed
}

function Remove-BenchyUninstallEntry
{
	[Microsoft.Win32.Registry]::CurrentUser.DeleteSubKeyTree($BenchyUninstallKey, $false)
}
