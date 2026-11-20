<?php

/*
	Phoronix Test Suite
	URLs: http://www.phoronix.com, http://www.phoronix-test-suite.com/
	Copyright (C) 2018 - 2021, Phoronix Media
	Copyright (C) 2018 - 2021, Michael Larabel

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
*/

class microsoft_dependency_handler implements pts_dependency_handler
{
	public static function startup_handler($install = true)
	{
		if(ini_get('enable_dl'))
		{
			// Ensure needed extensions are loaded
			$php_bin_dir = dirname(getenv('PHP_BIN'));
			foreach(array('openssl', 'zip') as $needed_ext)
			{
				if(!extension_loaded($needed_ext) && is_file($php_bin_dir . '\ext\php_' . $needed_ext . '.dll'))
				{
					dl('php_' . $needed_ext . '.dll');
				}
			}
		}
		if($install && !pts_external_dependencies::cygwin_ready())
		{
			echo PHP_EOL . 'Benchy on Windows depends upon Cygwin for a Bash interpreter and other basic commands... Setting up.' . PHP_EOL;
			$cwd = getcwd();
			$cygwin_location = self::get_cygwin();
			chdir(dirname($cygwin_location));
			echo PHP_EOL . 'Configuring Cygwin for handling of some portable test scripts, extracting .tar.gz/xz files, and other script helpers... More details on the Phoronix Test Suite for Windows @ https://github.com/phoronix-test-suite/phoronix-test-suite/blob/master/documentation/ ' . PHP_EOL;
			shell_exec(basename($cygwin_location) . ' -q -P unzip -P wget -P psmisc -P procps-ng -P bc -P which -W -s https://cygwin.osuosl.org/');
			chdir($cwd);
		}

		if(is_file('C:\cygwin64\etc\fstab') && stripos(file_get_contents('C:\cygwin64\etc\fstab'), 'noacl') === false)
		{
			// noacl is needed to not mess with file permissions
			file_put_contents('C:\cygwin64\etc\fstab', 'none /cygdrive cygdrive binary,noacl,posix=0,user 0 0');
		}
	}
	public static function what_provides($files_needed)
	{
		$packages_needed = array();
		foreach(pts_arrays::to_array($files_needed) as $file)
		{
			switch($file)
			{
				case 'Rscript':
					// R
					if(!is_dir('C:\Program Files\R'))
					{
						$packages_needed[] = 'https://cran.r-project.org/bin/windows/base/R-3.4.3-win.exe';
					}
					break;
				case 'Go':
					// Golang
					if(!is_dir('C:\Go'))
					{
						$packages_needed[] = 'https://dl.google.com/go/go1.10.windows-amd64.msi';
					}
					break;

			}
		}
		return $packages_needed;
	}
	public static function file_download_location()
	{
		// TODO determine what logic may need to be applied or if to punt it as an option, etc
		$download_directory = trim(shell_exec('powershell -NoProfile "(New-Object -ComObject Shell.Application).NameSpace(\'shell:Downloads\').Self.Path"'));
		
		// Fall back to user profile directory
		if(empty($download_directory) || !is_dir($download_directory)) {
			return getenv('USERPROFILE') . '\Downloads\\';
		}

		return pts_strings::add_trailing_slash($download_directory);
	}
	protected static function get_cygwin()
	{
		$cygwin_location = self::file_download_location() . 'cygwin-setup-x86_64.exe';
		if(is_file($cygwin_location) && !self::looks_like_exe($cygwin_location))
		{
			// a partial or empty file left by an earlier failed download
			unlink($cygwin_location);
		}
		if(!is_file($cygwin_location))
		{
			echo 'Downloading Cygwin...';
			if(!self::fetch_checked('https://cygwin.com/setup-x86_64.exe', $cygwin_location) && pts_network::$last_https_error != '')
			{
				echo PHP_EOL . 'Could not download the Cygwin setup (' . pts_network::$last_https_error . ').' . PHP_EOL;
			}
			// the caller runs it anyway and the install check then reports the failure; nothing half-downloaded stays behind
		}

		return $cygwin_location;
	}
	public static function fetch_checked($url, $destination, $downloader = null)
	{
		// Download to a .part file, keep it only when it is a whole Windows executable, and put it in place with a rename;
		// a failed, truncated or wrong download leaves nothing behind
		$partial = $destination . '.part';
		if(is_file($partial))
		{
			unlink($partial);
		}
		// $downloader is a test seam; the real one is https-only, verified, fail-closed
		$downloaded = $downloader ? call_user_func($downloader, $url, $partial) : pts_network::download_https_verified($url, $partial);
		if($downloaded && self::looks_like_exe($partial) && @rename($partial, $destination))
		{
			return true;
		}
		if(is_file($partial))
		{
			unlink($partial);
		}
		return false;
	}
	protected static function looks_like_exe($file)
	{
		// Non-empty, a Windows executable header (MZ), and a plausible size (the real setup is over 1 MB; a cut-off stream download that still starts with MZ must not pass)
		return is_file($file) && filesize($file) > 1000000 && file_get_contents($file, false, null, 0, 2) === 'MZ';
	}
	public static function install_dependencies($os_packages_to_install)
	{
		$files_to_download = array();
		$pass_to_cygwin = array();

		foreach($os_packages_to_install as $pkg_line)
		{
			foreach(explode(' ', $pkg_line) as $item_check)
			{
				if(in_array(substr($item_check, 0, 4), array('http', 'ftp:')))
				{
					// File to download and install
					$files_to_download[] = $item_check;
				}
				else
				{
					// Assuming packages desired by Cygwin
					// TODO make better assumptions about this...
					$pass_to_cygwin[] = $item_check;
				}
			}
		}

		$cwd = getcwd();
		if(!empty($files_to_download))
		{
			$download_location = self::file_download_location();
			echo PHP_EOL . 'Files needed for download to meet external dependencies...';
			echo PHP_EOL . 'Download Location: ' . $download_location . PHP_EOL;

			chdir($download_location);
			foreach($files_to_download as $url)
			{
				$download_destination = $download_location . basename($url);
			//	echo '        ' . $url . ' - ' . $download_destination . PHP_EOL;
				if(is_file($download_destination))
				{
					echo 'File Already Present' . PHP_EOL;
				}
				else
				{
					//echo 'Downloading...' . PHP_EOL;
					$download_package = new pts_test_file_download($url);
					pts_client::$display->test_install_download_file('DOWNLOAD', $download_package);
					pts_network::download_file($url, $download_destination);
				}
				//echo 'Executing...' . PHP_EOL;
				shell_exec(basename($url));
			}
		}
		if(!empty($pass_to_cygwin))
		{
			echo PHP_EOL . 'Cygwin dependencies needed: ' . implode(' ', $pass_to_cygwin) . PHP_EOL;
			$cygwin_location = self::get_cygwin();
			chdir(dirname($cygwin_location));
			$cygwin_cmd = basename($cygwin_location) . ' -q -P ' . implode(' -P ', $pass_to_cygwin) . ' -W';
			echo PHP_EOL . 'RUNNING: ' . $cygwin_cmd;
			shell_exec($cygwin_cmd);

		}
		chdir($cwd);
	}
}


?>
