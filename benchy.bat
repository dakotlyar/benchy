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
  
:: Download PHP for Windows and then extract it
If not exist C:\PHP\php.exe (
echo Attempting to download and setup Windows PHP release.
If not exist php.zip (
powershell -NoProfile -command "& { $ProgressPreference = 'SilentlyContinue'; iwr https://windows.php.net/downloads/releases/php-8.5.11-nts-Win32-vs17-x64.zip -OutFile php.zip; $ProgressPreference = 'Continue' }"
)
powershell -NoProfile -command "& { Expand-Archive php.zip -DestinationPath C:\PHP -Force }"
:: A fresh windows.php.net release ships every extension DLL but no active php.ini, so
:: openssl/curl/mbstring/zip are all disabled by default until one is written.
powershell -NoProfile -command "& { Set-Content -Path 'C:\PHP\php.ini' -Value @('extension_dir = C:/PHP/ext', 'extension=openssl', 'extension=curl', 'extension=mbstring', 'extension=zip', 'allow_url_fopen=On') -Encoding ASCII }"
If not exist VC_redist.x64.exe (
echo Attempting to download and run Visual C++ Redistributable for Visual Studio 2022 support.
powershell -NoProfile -command "& { $ProgressPreference = 'SilentlyContinue'; iwr https://aka.ms/vs/17/release/VC_redist.x64.exe -OutFile VC_redist.x64.exe; $ProgressPreference = 'Continue' }"
VC_redist.x64.exe /install /quiet
)
  )
:: Use the newly downloaded PHP location
set PHP_BIN=C:\PHP\php.exe

:SkipBinSearch

echo Starting Benchy
set PTS_SILENT_MODE=1
start /B %PHP_BIN% "%PTS_DIR%\pts-core\phoronix-test-suite.php" load_dynamic_result_viewer.start 2> NUL
timeout /t 1 > NUL

set PTS_SILENT_MODE=0
%PHP_BIN% "%PTS_DIR%\pts-core\phoronix-test-suite.php" %*
taskkill /F /IM php.exe > NUL
