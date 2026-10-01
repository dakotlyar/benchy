#!/bin/sh
# install_windows.sh - Windows installation script for passmark.
#
# PURPOSE
#   Called by Benchy on each Windows client during the install phase.
#   Benchy runs this through its Cygwin bash.
#
#   Key design rules:
#     - Step 3 writes the test executable wrapper BEFORE any check that could
#       exit early, so Benchy always finds the executable even if prereqs are absent.
#     - PowerShell is located by direct path rather than "command -v" because
#       the latter may fail in Cygwin sh even when PowerShell is on PATH.
#     - Benchy downloads petst.exe (the PerformanceTEST installer) to the install
#       directory via downloads.xml before this script runs.
#
# ENVIRONMENT VARIABLES set by Benchy:
#   PTS_TEST_INSTALL_DIR - absolute Cygwin path to install into.
#   PTS_PROFILE_PATH     - directory containing this script and profile assets.
#
# EXIT CODES: 0 = success, 1 = unrecoverable failure.

set +e

log() { echo "[passmark install] $*"; }

log "Starting Windows installation."

# -- 1. Resolve paths ----------------------------------------------------------
if [ -n "$PTS_TEST_INSTALL_DIR" ]; then
    INSTALL_DIR="$PTS_TEST_INSTALL_DIR"
else
    INSTALL_DIR="$HOME"
fi

SCRIPT_DIR="$(cd "$(dirname "$0")" 2>/dev/null && pwd)"
if [ -n "$PTS_PROFILE_PATH" ]; then
    PROFILE_DIR="$PTS_PROFILE_PATH"
else
    # Benchy does not set PTS_PROFILE_PATH; use the directory containing this
    # script, which is always the extracted ZIP directory and always contains
    # run.sh, passmark-benchmark.ps1, petst.exe, and all other profile assets.
    PROFILE_DIR="$SCRIPT_DIR"
fi

BENCHMARK_DIR="$INSTALL_DIR/benchmark"

log "Install directory : $INSTALL_DIR"
log "Profile directory : $PROFILE_DIR"

# -- 2. Create base install directory ------------------------------------------
mkdir -p "$INSTALL_DIR"

# -- 3. Write test executable wrapper (MUST precede any early exit) ------------
log "Writing test executable wrapper..."
cat > "$HOME/passmark" << 'WRAPPER'
#!/bin/sh
# passmark - test executable called by Benchy at each benchmark run.
# Benchy may pass the workload as $1 (argv) or as $ARGUMENTS (env var).
INSTALL_DIR="$(cd "$(dirname "$0")" && pwd)"
export PTS_TEST_INSTALL_DIR="$INSTALL_DIR"
RUN_SH="$INSTALL_DIR/run.sh"
WORKLOAD="${1:-${ARGUMENTS:-}}"
if [ ! -f "$RUN_SH" ]; then
    echo "[passmark] ERROR: run.sh not found at $RUN_SH" >&2
    exit 1
fi
if [ -n "$LOG_FILE" ]; then
    sh "$RUN_SH" "$WORKLOAD" 2>&1 | tee "$LOG_FILE"
else
    sh "$RUN_SH" "$WORKLOAD" 2>&1
fi
WRAPPER
chmod +x "$HOME/passmark"
log "Wrapper written: $HOME/passmark"

# -- 4/5. Write run.sh and the PowerShell workload script -------------------------
# Benchy test profiles may only contain a fixed set of filenames (no run.sh / *.ps1),
# so those two files are carried inside this script and written out here.
mkdir -p "$BENCHMARK_DIR"

cat > "$INSTALL_DIR/run.sh" << 'EOF_PASSMARK_RUN_SH'
#!/bin/sh
# run.sh - Benchy entry point for passmark.
#
# Benchy always calls this script to execute one benchmark run.
# It receives the test selection via the $ARGUMENTS environment variable.
#
# ARGUMENTS values (from <TestSettings>)
#   ALL     - run all tests and report overall PassMark Rating.
#   CPU_ALL - CPU tests only.
#   ME_ALL  - Memory tests only.
#   G2D_ALL - 2D Graphics tests only.
#   G3D_ALL - 3D Graphics tests only.
#   DI_ALL  - Disk tests only.
#
# STDOUT CONTRACT (must match results-definition.xml <OutputTemplate>)
#   Output lines matching the configured LineHint patterns are parsed.
#
# EXIT CODES: 0 = ran, 1 = could not run.

set -e

log() { echo "[passmark] $*"; }

# -- 1. Platform detection ------------------------------------------------------
OS_TYPE=$(uname -s 2>/dev/null || echo "unknown")

case "$OS_TYPE" in
    Linux)
        log "Platform: Linux - PassMark workloads run on Windows client nodes only."
        exit 0
        ;;
    Darwin)
        log "Platform: macOS - not supported."
        exit 0
        ;;
    Windows_NT|MINGW*|CYGWIN*|MSYS*)
        log "Platform: Windows - proceeding."
        ;;
    *)
        log "Platform: $OS_TYPE - attempting Windows execution path."
        ;;
esac

# -- 2. Resolve install directory -----------------------------------------------
if [ -n "$PTS_TEST_INSTALL_DIR" ]; then
    INSTALL_DIR="$PTS_TEST_INSTALL_DIR"
else
    INSTALL_DIR="$(cd "$(dirname "$0")" && pwd)"
    log "WARNING: PTS_TEST_INSTALL_DIR not set; using script dir: $INSTALL_DIR"
fi

if [ ! -f "$INSTALL_DIR/platform.txt" ]; then
    log "ERROR: platform.txt not found in $INSTALL_DIR"
    log "Run: benchy install private/passmark"
    exit 1
fi

SUPPORTED=$(grep -E '^supported=' "$INSTALL_DIR/platform.txt" | cut -d= -f2 | tr -d '[:space:]')
if [ "$SUPPORTED" != "true" ]; then
    log "Platform marker says unsupported."
    exit 0
fi

PT_EXE_WIN=$(grep -E '^pt_exe=' "$INSTALL_DIR/platform.txt" | cut -d= -f2-)
PS_EXE=$(grep -E '^ps_exe=' "$INSTALL_DIR/platform.txt" | cut -d= -f2-)
[ -n "$PS_EXE" ] || PS_EXE=powershell.exe

# -- 3. Resolve workload --------------------------------------------------------
WORKLOAD="${1:-${ARGUMENTS:-ALL}}"
log "Workload: $WORKLOAD"

case "$WORKLOAD" in
    ALL|CPU_ALL|ME_ALL|G2D_ALL|G3D_ALL|DI_ALL) ;;
    *)
        log "ERROR: Unrecognised workload '$WORKLOAD'."
        log "Valid values: ALL | CPU_ALL | ME_ALL | G2D_ALL | G3D_ALL | DI_ALL"
        exit 1
        ;;
esac

# -- 4. Run the PowerShell workload script -------------------------------------
BENCHMARK_DIR="$INSTALL_DIR/benchmark"
PS1_PATH="$BENCHMARK_DIR/passmark-benchmark.ps1"

if [ ! -f "$PS1_PATH" ]; then
    log "ERROR: workload script not found: $PS1_PATH"
    log "Re-run: benchy install private/passmark"
    exit 1
fi

WIN_PS1_PATH=$(cygpath -w "$PS1_PATH" 2>/dev/null || echo "$PS1_PATH")

log "Launching passmark-benchmark.ps1 (workload: $WORKLOAD)"

"$PS_EXE" \
    -NoProfile \
    -NonInteractive \
    -ExecutionPolicy Bypass \
    -File "$WIN_PS1_PATH" \
    -Workload "$WORKLOAD" \
    -PtExe "$PT_EXE_WIN" \
    2>&1

EXIT_CODE=$?
log "Run complete (exit code $EXIT_CODE)."
exit $EXIT_CODE
EOF_PASSMARK_RUN_SH
chmod +x "$INSTALL_DIR/run.sh"
log "Wrote run.sh"

cat > "$BENCHMARK_DIR/passmark-benchmark.ps1" << 'EOF_PASSMARK_PS1'
#Requires -Version 5.1
<#
.SYNOPSIS
    Runs PassMark PerformanceTEST for the Phoronix Test Suite.

.PARAMETER Workload
    PTS test identifier: ALL | CPU_ALL | ME_ALL | G2D_ALL | G3D_ALL | DI_ALL

.PARAMETER PtExe
    Full Windows path to PerformanceTest64.exe.
    If omitted, the script probes the default install location.
#>
param(
    [string]$Workload = 'ALL',
    [string]$PtExe   = ''
)

$ErrorActionPreference = 'Stop'

function Write-Log { param([string]$Msg) Write-Host "[passmark] $Msg" }

# -- Locate PerformanceTest64.exe -----------------------------------------------
if (-not $PtExe -or -not (Test-Path $PtExe)) {
    $PtExe = 'C:\Program Files\PerformanceTest\PerformanceTest64.exe'
}
if (-not (Test-Path $PtExe)) {
    Write-Log "ERROR: PerformanceTest64.exe not found at: $PtExe"
    Write-Log "Install PassMark PerformanceTEST and re-run: benchy install private/passmark"
    exit 1
}
Write-Log "PerformanceTEST: $PtExe"
Write-Log "Workload       : $Workload"

# -- Build the .ptscript -------------------------------------------------------
$Ts          = [DateTimeOffset]::UtcNow.ToUnixTimeSeconds()
$TempDir     = $env:TEMP
$ScriptFile  = Join-Path $TempDir "pts_passmark_$Ts.ptscript"
$ResultsPath = Join-Path $TempDir "pts_passmark_$Ts.txt"

# Use absolute paths in the ptscript so PerformanceTEST writes to a known
# location regardless of its own working directory.
$PtScript = @"

CLEARRESULTS

RUN $Workload

EXPORTTEXTF "$ResultsPath"

EXIT
"@

# ASCII avoids BOM which some versions of PerformanceTEST misread.
[System.IO.File]::WriteAllText($ScriptFile, $PtScript, [System.Text.Encoding]::ASCII)
Write-Log "Script: $ScriptFile"

# -- Remove any stale results file ---------------------------------------------
if (Test-Path $ResultsPath) { Remove-Item $ResultsPath -Force }

# -- Run PerformanceTEST -------------------------------------------------------
Write-Log "Running PerformanceTEST..."
$proc = Start-Process -FilePath $PtExe `
    -ArgumentList '/s', "`"$ScriptFile`"", '/i' `
    -Wait -PassThru
Write-Log "PerformanceTEST exit code: $($proc.ExitCode)"

# -- Emit results --------------------------------------------------------------
if (-not (Test-Path $ResultsPath)) {
    Write-Log "ERROR: Results file not found: $ResultsPath"
    Remove-Item $ScriptFile -Force -ErrorAction SilentlyContinue
    exit 1
}

Get-Content $ResultsPath | ForEach-Object { Write-Host $_ }

Remove-Item $ScriptFile  -Force -ErrorAction SilentlyContinue
Remove-Item $ResultsPath -Force -ErrorAction SilentlyContinue
EOF_PASSMARK_PS1
log "Wrote passmark-benchmark.ps1"

# -- 6. Locate PowerShell ------------------------------------------------------
PS_EXE=""
for CANDIDATE in \
    "/c/Windows/System32/WindowsPowerShell/v1.0/powershell.exe" \
    "/cygdrive/c/Windows/System32/WindowsPowerShell/v1.0/powershell.exe" \
    "powershell.exe"; do
    if [ -f "$CANDIDATE" ]; then
        PS_EXE="$CANDIDATE"
        break
    fi
done
if [ -z "$PS_EXE" ]; then
    if powershell.exe -NoProfile -ExecutionPolicy Bypass -Command "exit 0" > /dev/null 2>&1; then
        PS_EXE="powershell.exe"
    fi
fi

if [ -z "$PS_EXE" ]; then
    log "ERROR: powershell.exe not found. Windows PowerShell 5.1+ is required."
    exit 1
fi
PS_VER=$("$PS_EXE" -NoProfile -Command '$PSVersionTable.PSVersion.ToString()' 2>/dev/null || echo "unknown")
log "PowerShell: $PS_EXE  (version: $PS_VER)"

# -- 7. Check / install PerformanceTEST ----------------------------------------
log "Checking PassMark PerformanceTEST prerequisite..."

find_pt_exe() {
    for CANDIDATE in \
        "/c/Program Files/PerformanceTest/PerformanceTest64.exe" \
        "/cygdrive/c/Program Files/PerformanceTest/PerformanceTest64.exe"; do
        if [ -f "$CANDIDATE" ]; then
            echo "$CANDIDATE"
            return 0
        fi
    done
    if [ -n "$PS_EXE" ]; then
        PS_PATH=$("$PS_EXE" -NoProfile -NonInteractive -Command \
            'try { $p = (Get-ItemProperty "HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\Uninstall\*","HKCU:\SOFTWARE\Microsoft\Windows\CurrentVersion\Uninstall\*" -ErrorAction SilentlyContinue | Where-Object { $_.DisplayName -like "*PerformanceTest*" } | Select-Object -First 1).InstallLocation; if ($p) { Join-Path $p "PerformanceTest64.exe" } } catch {}' \
            2>/dev/null | tr -d '\r')
        if [ -n "$PS_PATH" ]; then
            PS_PATH_UNIX=$(cygpath -u "$PS_PATH" 2>/dev/null || echo "")
            if [ -n "$PS_PATH_UNIX" ] && [ -f "$PS_PATH_UNIX" ]; then
                echo "$PS_PATH_UNIX"
                return 0
            fi
        fi
    fi
    return 1
}

PT_EXE=$(find_pt_exe) || PT_EXE=""

if [ -z "$PT_EXE" ]; then
    log "PerformanceTEST not found. Locating installer..."

    # Benchy downloads petst.exe to PTS_TEST_INSTALL_DIR via downloads.xml.
    INSTALLER=""
    for CANDIDATE in \
        "$INSTALL_DIR/petst.exe" \
        "$PROFILE_DIR/petst.exe" \
        "./petst.exe"; do
        if [ -f "$CANDIDATE" ]; then
            INSTALLER="$CANDIDATE"
            break
        fi
    done

    if [ -z "$INSTALLER" ]; then
        log "ERROR: petst.exe not found. Benchy should have downloaded it via downloads.xml."
        log "Try: benchy install private/passmark"
        exit 1
    fi

    INSTALLER_WIN=$(cygpath -w "$INSTALLER" 2>/dev/null || echo "$INSTALLER")
    log "Running installer (silent): $INSTALLER_WIN"

    "$PS_EXE" -NoProfile -NonInteractive -Command "
\$proc = Start-Process -FilePath '$INSTALLER_WIN' -ArgumentList '/VERYSILENT', '/NORESTART' -Wait -PassThru
Write-Host \"Installer exit code: \$(\$proc.ExitCode)\"
exit \$proc.ExitCode
" 2>&1

    PT_EXE=$(find_pt_exe) || PT_EXE=""

    if [ -z "$PT_EXE" ]; then
        log "Silent install did not find PerformanceTEST. Falling back to interactive install..."
        /cygdrive/c/Windows/system32/cmd.exe /c "$INSTALLER_WIN"
        PT_EXE=$(find_pt_exe) || PT_EXE=""
    fi

    if [ -z "$PT_EXE" ]; then
        log "ERROR: PerformanceTest64.exe not found after install."
        log "Please install PassMark PerformanceTEST manually, then re-run:"
        log "  benchy install private/passmark"
        exit 1
    fi

    log "PerformanceTEST installed: $PT_EXE"
fi

PT_EXE_WIN=$(cygpath -w "$PT_EXE" 2>/dev/null || echo "$PT_EXE")
log "PerformanceTEST: $PT_EXE_WIN"

# -- 8. Write platform marker --------------------------------------------------
cat > "$INSTALL_DIR/platform.txt" << EOF
platform=windows
supported=true
pt_exe=$PT_EXE_WIN
ps_exe=$PS_EXE
EOF
log "Platform marker written."

log "Installation complete."
exit 0
