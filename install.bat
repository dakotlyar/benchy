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

:: Ensure the user is in the correct directory
If Not Exist "pts-core\phoronix-test-suite.php" (
echo To install Benchy you must first change directories to benchy. For support visit: http://www.phoronix-test-suite.com/
exit
)

powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0install.ps1"
