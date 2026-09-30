#!/bin/sh
# Windows install, run under Cygwin bash (Benchy installs Cygwin on first run).
# The PerformanceTest installer is fetched via downloads.xml as petst.exe and is not part of this profile.
# Profile packages may only contain the fixed set of files validate-test-profile permits, so the
# PowerShell scripts are written out from here.

INSTALL_DIR=$PWD   # the installer runs with the test install directory as its working directory

cat > passmark-install.ps1 <<'EOPS'
# Silent install of PassMark PerformanceTest from the downloaded petst.exe.
# UNVERIFIED on a real Windows host: confirm the silent switch against the installer (Inno Setup style shown).
param([Parameter(Mandatory=$true)][string]$Installer)
$ErrorActionPreference = 'Stop'
if (-not (Test-Path $Installer)) { Write-Error "Installer not found: $Installer"; exit 2 }
$proc = Start-Process -FilePath $Installer -ArgumentList '/VERYSILENT','/SUPPRESSMSGBOXES','/NORESTART' -Wait -PassThru
exit $proc.ExitCode
EOPS

cat > passmark-benchmark.ps1 <<'EOPS'
# Runs PassMark PerformanceTest unattended via a .ptscript and prints its text export, whose lines look like
# "CPU Mark (Composite average) : 12345" (matched by results-definition.xml).
# Requires a licensed, activated PerformanceTest; it is not activated by this profile.
param([ValidateSet('ALL','CPU_ALL','ME_ALL','G2D_ALL','G3D_ALL','DI_ALL')][string]$Test = 'ALL')
$ErrorActionPreference = 'Stop'

$exe = Join-Path ${env:ProgramFiles} 'PerformanceTest\PerformanceTest64.exe'
if (-not (Test-Path $exe)) { Write-Error "PerformanceTest is not installed at $exe"; exit 2 }

$ts = [DateTimeOffset]::UtcNow.ToUnixTimeSeconds()
$scriptFile = Join-Path $env:TEMP "benchy_passmark_$ts.ptscript"
$resultsFile = Join-Path $env:TEMP "benchy_passmark_$ts.txt"

$ptscript = @"

CLEARRESULTS

RUN $Test

EXPORTTEXTF "$resultsFile"

EXIT
"@
# ASCII without BOM: some PerformanceTest versions misread a BOM.
[System.IO.File]::WriteAllText($scriptFile, $ptscript, [System.Text.Encoding]::ASCII)

$proc = Start-Process -FilePath $exe -ArgumentList '/s', "`"$scriptFile`"", '/i' -Wait -PassThru
Write-Host "PerformanceTest exit code: $($proc.ExitCode)"

if (-not (Test-Path $resultsFile)) {
  Remove-Item $scriptFile -Force -ErrorAction SilentlyContinue
  Write-Error 'No results file was produced (is PerformanceTest licensed/activated?)'
  exit 3
}
Get-Content $resultsFile | ForEach-Object { Write-Host $_ }
Remove-Item $scriptFile, $resultsFile -Force -ErrorAction SilentlyContinue
EOPS

powershell.exe -NoProfile -ExecutionPolicy Bypass -File "$(cygpath -w "$INSTALL_DIR/passmark-install.ps1")" -Installer "$(cygpath -w "$INSTALL_DIR/petst.exe")"
echo $? > "$INSTALL_DIR/install-exit-status"

# The test executable; $1 is ALL, CPU_ALL, ME_ALL, G2D_ALL, G3D_ALL or DI_ALL. No home-directory path is baked in.
cat > passmark <<'EOS'
#!/bin/sh
cd "$(dirname "$0")" || exit 1
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "$(cygpath -w "$PWD/passmark-benchmark.ps1")" -Test "${1:-ALL}" > "$LOG_FILE" 2>&1
EOS
chmod +x passmark
