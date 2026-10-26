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
:: and its exit code is returned (see install.ps1 for the list).

:: Paths are relative to this file, so it works from any current directory
:: (a goto rather than an if block, as a ")" in the path, e.g. "Program Files (x86)", would end the block early)
If Exist "%~dp0pts-core\phoronix-test-suite.php" goto Install
echo install.bat must be run from the extracted Benchy folder (pts-core\phoronix-test-suite.php is missing next to it).
exit /b 2

:Install
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0install.ps1" %*
exit /b %ERRORLEVEL%
