@echo off

:: Phoronix Test Suite
:: URLs: http://www.phoronix.com, http://www.phoronix-test-suite.com/
:: Copyright (C) 2018 - 2020, Phoronix Media
::
:: This program is free software; you can redistribute it and/or modify
:: it under the terms of the GNU General Public License as published by
:: the Free Software Foundation; either version 3 of the License, or
:: (at your option) any later version.
::
:: This program is distributed in the hope that it will be useful,
:: but WITHOUT ANY WARRANTY; without even the implied warranty of
:: MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
:: GNU General Public License for more details.
::
:: You should have received a copy of the GNU General Public License
:: along with this program. If not, see <http://www.gnu.org/licenses/>.

:: Generic Benchy installer for Windows
::
:: This is a thin entry point so double-clicking still works without
:: PowerShell's default execution-policy blocking scripts. All of the
:: actual install logic (per-user install under %LOCALAPPDATA%, no admin
:: rights needed, PHP download/setup, PATH, Start Menu shortcut) lives in
:: install.ps1 -- see that file for details.
::
:: Arguments are passed on to install.ps1, for scripted installs, e.g.
::   install.bat -NonInteractive -PhoromaticServer 10.0.0.5:8080/ABC123
:: and its exit code is returned (see install.ps1 for the list). Scripts and deployment tools should always
:: pass -NonInteractive: it never prompts, and a run with arguments never waits at the failure pause below.

:: Paths are relative to this file, so it works from any current directory
:: (a goto rather than an if block, as a ")" in the path, e.g. "Program Files (x86)", would end the block early)
If Exist "%~dp0pts-core\phoronix-test-suite.php" goto Install
echo install.bat must be run from the extracted Benchy folder (pts-core\phoronix-test-suite.php is missing next to it).
exit /b 2

:Install
set "BENCHY_SELF=%~f0"
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0install.ps1" %*
set "BENCHY_RC=%ERRORLEVEL%"
if "%BENCHY_RC%"=="0" exit /b 0
if "%BENCHY_RC%"=="3010" exit /b 3010

:: On failure, keep a double-clicked window open so the message can be read. Never pause a scripted run:
:: double-clicking passes no arguments, so any argument means a script.
if not "%~1"=="" exit /b %BENCHY_RC%
call :LaunchedByDoubleClick || exit /b %BENCHY_RC%
echo.
echo Benchy was not installed (exit code %BENCHY_RC%). See the messages above.
pause
exit /b %BENCHY_RC%

:LaunchedByDoubleClick
:: Explorer starts a double-clicked .bat as: cmd.exe /c ""<full path of this file>" ". A prompt (no /c) or a
:: script naming the file another way does not match. Delayed expansion, because the command line holds quotes
:: and the path may hold & or ); a path with ! in it does not match, which only means no pause.
setlocal EnableDelayedExpansion
set "BENCHY_CMD=!cmdcmdline!"
if /i "!BENCHY_CMD:/c=!"=="!BENCHY_CMD!" exit /b 1
if /i "!BENCHY_CMD:%BENCHY_SELF%=!"=="!BENCHY_CMD!" exit /b 1
endlocal
:: And only with someone there to press a key: an interactive session, stdin not redirected, and this cmd.exe
:: started by Explorer. A scheduled task, Start-Process or a subprocess call with no arguments gives the same
:: command line as a double-click, but a different parent. (The probe's parent is this cmd.exe; its parent is
:: the one that started the .bat.) Any error in the probe means no pause.
powershell -NoProfile -Command "try { $Cmd = Get-CimInstance Win32_Process -Filter ('ProcessId=' + (Get-CimInstance Win32_Process -Filter ('ProcessId=' + $PID)).ParentProcessId); $Starter = Get-CimInstance Win32_Process -Filter ('ProcessId=' + $Cmd.ParentProcessId); if ([Environment]::UserInteractive -and -not [Console]::IsInputRedirected -and $Cmd.Name -eq 'cmd.exe' -and $Starter.Name -eq 'explorer.exe') { exit 0 } } catch { }; exit 1"
exit /b %ERRORLEVEL%
