::	Phoronix Test Suite
::	URLs: http://www.phoronix.com, http://www.phoronix-test-suite.com/
::	Copyright (C) 2008 - 2021, Phoronix Media
::	Copyright (C) 2008 - 2021, Michael Larabel
::	phoronix-test-suite: The Phoronix Test Suite is an extensible open-source testing / benchmarking platform
::
::	This program is free software; you can redistribute it and/or modify
::	it under the terms of the GNU General Public License as published by
::	the Free Software Foundation; either version 3 of the License, or
::	(at your option) any later version.
::
::	This program is distributed in the hope that it will be useful,
::	but WITHOUT ANY WARRANTY; without even the implied warranty of
::	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
::	GNU General Public License for more details.
::
::	You should have received a copy of the GNU General Public License
::	along with this program. If not, see <http://www.gnu.org/licenses/>.
::

:: Full path to root directory of the actual Phoronix Test Suite code
@echo off
set PTS_DIR=%~dp0
set PTS_MODE=CLIENT
set PTS_LAUNCHER=%0

:: Updater crash recovery (the apply script arrives with the updater): update\state.json only exists while
:: apply-update.ps1 is swapping PHP or code, so finding it here means an apply was interrupted (power loss, closed
:: window). -Recover always goes back to the old state, and must run before PHP is looked for or started.
:: < NUL: PowerShell must not read this window's stdin, which carries benchy --json --json-args - input for PHP.
:: -InstallDir gets this file's directory plus a trailing dot: the directory ends in a backslash, and a backslash right
:: before the closing quote would escape it.
if exist "%~dp0update\state.json" powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0pts-core\static\update\apply-update.ps1" -InstallDir "%~dp0." -Recover < NUL

:: See if php was setup via Cygwin64, the benefit there is allowing access to PCNTL extensions, etc
:: If exist C:\cygwin64\bin\php.exe (
:: set PHP_BIN=C:\cygwin64\bin\php.exe
:: )

:: TODO: Other work to bring this up to sync with the *NIX phoronix-test-suite launcher
if defined PHP_BIN set "PHP_BIN=%PHP_BIN:"=%"
If exist "%PHP_BIN%" goto SkipBinSearch
:: A PHP_BIN that points at a file that is gone (a deleted PHP) falls through to the lookups below
set PHP_BIN=

:: install.ps1 puts PHP in php\ next to this file and sets PHP_BIN with setx; this covers a terminal opened before that
:: setx. A C:\PHP left by older Benchy launchers is still used, but nothing is downloaded here any more: the one PHP pin
:: and the verified download live in install.ps1 (pts-core\static\update\runtime-pins.json).
If exist "%~dp0php\php.exe" set "PHP_BIN=%~dp0php\php.exe"
If not defined PHP_BIN If exist C:\PHP\php.exe set "PHP_BIN=C:\PHP\php.exe"
If defined PHP_BIN goto SkipBinSearch
:: To stderr, so a JSON-mode caller's stdout stays empty (no done line: the caller reports the exit code and stderr)
echo No PHP found for Benchy. Run install.bat (from the extracted Benchy folder) to install Benchy and its PHP. 1>&2
exit /b 1

:SkipBinSearch

:: JSON mode (benchy.bat --json ..., or BENCHY_OUTPUT=json): stdout carries only PHP's events, so no banner and no result
:: viewer. cmd splits arguments at "=", so "--json=1" also arrives here as "--json".
set BENCHY_JSON=0
if /I "%~1"=="--json" set BENCHY_JSON=1
if /I "%BENCHY_OUTPUT%"=="json" set BENCHY_JSON=1
if "%BENCHY_JSON%"=="1" goto SkipResultViewer
echo Starting Benchy
set PTS_SILENT_MODE=1
start /B "" "%PHP_BIN%" "%PTS_DIR%\pts-core\phoronix-test-suite.php" load_dynamic_result_viewer.start 2> NUL
timeout /t 1 > NUL
:SkipResultViewer

set PTS_SILENT_MODE=0
"%PHP_BIN%" "%PTS_DIR%\pts-core\phoronix-test-suite.php" %*
:: PHP's exit code is this file's (JSON mode's done.exit_code, scripts): kept here, returned by the last line
set "BENCHY_RC=%ERRORLEVEL%"
:: Stop only the result viewer this window started, never any other php.exe (a Phoromatic client or a benchmark in
:: another window). start /B made it a child of this cmd.exe, which is also the parent of the powershell below. Its whole
:: tree goes: on Windows the viewer runs the actual web server as a second php.exe through exec(). It is stopped before
:: the updater handoff, so no exit path leaves it running. JSON mode started none.
if not "%BENCHY_JSON%"=="1" powershell -NoProfile -Command "$cmd = (Get-CimInstance Win32_Process -Filter ('ProcessId=' + $PID)).ParentProcessId; Get-CimInstance Win32_Process -Filter ('ParentProcessId=' + $cmd + ' AND Name=''php.exe''') | Where-Object { $_.CommandLine -like '*load_dynamic_result_viewer*' } | ForEach-Object { taskkill /F /T /PID $_.ProcessId 2>&1 | Out-Null }"
:: Updater handoff: PHP writes update\pending.json (last, after staging and its smoke test pass) and exits, then the
:: swap happens here, with no PHP of this window running. This must stay ONE line, and the last line that reads anything
:: the update may replace (the lines above it run before any swap): cmd
:: parses the whole line, the trailing exit /b included, before running it, so not one byte of this file is read after
:: apply-update.ps1 has replaced it. The apply script also stops the result viewer itself. < NUL as on the -Recover line.
if exist "%~dp0update\pending.json" powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0pts-core\static\update\apply-update.ps1" -InstallDir "%~dp0." < NUL & exit /b
exit /b %BENCHY_RC%
