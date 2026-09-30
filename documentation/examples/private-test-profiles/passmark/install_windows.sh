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
# Runs PassMark PerformanceTest unattended and prints "NAME: value" lines for results-definition.xml.
# UNVERIFIED on a real Windows host: the command-line switch and the results file name/format below
# are from memory of PassMark's documentation and must be checked against the installed version.
param([ValidateSet('ALL','CPU_ALL','ME_ALL','G2D_ALL','G3D_ALL','DI_ALL')][string]$Test = 'ALL')
$ErrorActionPreference = 'Stop'

$root = Join-Path ${env:ProgramFiles} 'PerformanceTest'
$exe = Join-Path $root 'PerformanceTest64.exe'
if (-not (Test-Path $exe)) { Write-Error "PerformanceTest is not installed at $root"; exit 2 }

# Test selection -> PerformanceTest "-r" automation code (verify these codes)
$codes = @{ ALL = 3; CPU_ALL = 1; ME_ALL = 5; G2D_ALL = 2; G3D_ALL = 4; DI_ALL = 6 }
$before = Get-Date
$proc = Start-Process -FilePath $exe -ArgumentList '-r', $codes[$Test] -WorkingDirectory $root -Wait -PassThru
if ($proc.ExitCode -ne 0) { Write-Error "PerformanceTest exited $($proc.ExitCode)"; exit $proc.ExitCode }

$result = Get-ChildItem $root -Filter 'results*.yml' | Where-Object { $_.LastWriteTime -ge $before } | Sort-Object LastWriteTime | Select-Object -Last 1
if (-not $result) { Write-Error 'No results file was produced (is PerformanceTest licensed/activated?)'; exit 3 }

$map = [ordered]@{
  'SUMM_ALL'      = 'PASSMARK_RATING'
  'SUMM_CPU'      = 'CPU_MARK'
  'SUMM_ME'       = 'MEMORY_MARK'
  'SUMM_2D'       = '2D_GRAPHICS_MARK'
  'SUMM_3D'       = '3D_GRAPHICS_MARK'
  'SUMM_DI'       = 'DISK_MARK'
}
foreach ($line in Get-Content $result.FullName) {
  if ($line -match '^\s*([A-Z0-9_]+)\s*:\s*([0-9.]+)') {
    if ($map.Contains($Matches[1])) { "$($map[$Matches[1]]): $($Matches[2])" }
  }
}
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
