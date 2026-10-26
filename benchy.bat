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

:: See if php was setup via Cygwin64, the benefit there is allowing access to PCNTL extensions, etc
:: If exist C:\cygwin64\bin\php.exe (
:: set PHP_BIN=C:\cygwin64\bin\php.exe
:: )

:: TODO: Other work to bring this up to sync with the *NIX phoronix-test-suite launcher
If defined PHP_BIN goto SkipBinSearch

:: install.ps1 puts PHP in php\ next to this file and sets PHP_BIN with setx; this covers a terminal opened before that
:: setx. A C:\PHP left by older Benchy launchers is still used, but nothing is downloaded here any more: the one PHP pin
:: and the verified download live in install.ps1 (pts-core\static\update\runtime-pins.json).
If exist "%~dp0php\php.exe" set "PHP_BIN=%~dp0php\php.exe"
If not defined PHP_BIN If exist C:\PHP\php.exe set "PHP_BIN=C:\PHP\php.exe"
If defined PHP_BIN goto SkipBinSearch
echo No PHP found for Benchy. Run install.bat (from the extracted Benchy folder) to install Benchy and its PHP.
exit /b 1

:SkipBinSearch

echo Starting Benchy
set PTS_SILENT_MODE=1
start /B %PHP_BIN% "%PTS_DIR%\pts-core\phoronix-test-suite.php" load_dynamic_result_viewer.start 2> NUL
timeout /t 1 > NUL

set PTS_SILENT_MODE=0
%PHP_BIN% "%PTS_DIR%\pts-core\phoronix-test-suite.php" %*
:: Stop only the result viewer this window started, never any other php.exe (a Phoromatic client or a benchmark in
:: another window). start /B made it a child of this cmd.exe, which is also the parent of the powershell below. Its whole
:: tree goes: on Windows the viewer runs the actual web server as a second php.exe through exec().
powershell -NoProfile -Command "$cmd = (Get-CimInstance Win32_Process -Filter ('ProcessId=' + $PID)).ParentProcessId; Get-CimInstance Win32_Process -Filter ('ParentProcessId=' + $cmd + ' AND Name=''php.exe''') | Where-Object { $_.CommandLine -like '*load_dynamic_result_viewer*' } | ForEach-Object { taskkill /F /T /PID $_.ProcessId 2>&1 | Out-Null }"
