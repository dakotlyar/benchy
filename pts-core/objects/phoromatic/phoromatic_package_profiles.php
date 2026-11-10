<?php

/*
	Benchy
	phoromatic_package_profiles.php: the official test profiles that ship with the installed package, as the zip a client downloads

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

// The OpenBenchmarking mirror (public_html/openbenchmarking-cache.php) serves a profile from a zip that was downloaded or bundled. The bundled
// ob-cache holds the official repositories (pts, system, git) as folders only, with no zips, so for those the zip is built here from the package's
// own copy: <package>/ob-cache/test-profiles/<repository>/<profile>/, never from the writable test-profiles folder of the service's user, which is
// a working copy that anyone with that user's rights, or a profile installed by hand, could have changed.
//
// What is checked on every request, before any byte is served (a failure is a log line and "not served", never a fallback to another folder):
//  - the repository is an official one and the profile name is one safe path segment (phoromatic_tenant_path::segment)
//  - the folder is where the package puts it: its real path is <real path of the package's test-profiles>/<repository>/<profile>, which is itself
//    inside the package root, so no link anywhere on the way can lead elsewhere
//  - nothing in it is a link, or anything but a folder or a plain file
//  - nothing from the profile up to the test-profiles folder can be written by the user the server runs as (owner, group and other bits read
//    against that user's own ids, so it also holds for a root service, which can write anything)
// The zip is rebuilt on each request rather than cached: it is a pure function of a tree that only root changes, the cost is a few small files,
// and a cache the service could write would be a place to tamper with, while one only root could write would need a second privileged step.
// Entries are sorted, with one fixed time, mode and compression, so a profile gives the same bytes on every request.

class phoromatic_package_profiles
{
	const OFFICIAL = array('pts', 'system', 'git');
	const ENTRY_TIME = 946684800;       // 2000-01-01
	protected static $last_error = null;

	public static function last_error()
	{
		return self::$last_error;
	}
	protected static function refuse($why)
	{
		self::$last_error = $why;
		pts_logger::add_to_log('OpenBenchmarking mirror: a package profile was not served: ' . $why);
		return false;
	}
	public static function service_can_write($path)
	{
		// True if the user the server runs as could write $path (or if that cannot be told), from the owner, group and other bits against
		// that user's ids: not is_writable(), which is always true for root
		if(!function_exists('posix_geteuid') || ($st = @lstat($path)) === false)
		{
			return true;
		}
		$mode = $st['mode'];
		if($st['uid'] === posix_geteuid())
		{
			return ($mode & 0200) !== 0;
		}
		if(in_array($st['gid'], array_merge(array(posix_getegid()), (array)posix_getgroups()), true))
		{
			return ($mode & 0020) !== 0;
		}
		return ($mode & 0002) !== 0;
	}
	public static function profile_dir($repository, $profile)
	{
		// The package folder of an official profile after every check, or false (and the reason in last_error() and the log)
		self::$last_error = null;
		if(!in_array($repository, self::OFFICIAL, true))
		{
			return self::refuse('repository ' . phoromatic_audit_core::clean($repository, 40) . ' is not an official one');
		}
		if(($profile = phoromatic_tenant_path::segment($profile, 200)) === false)
		{
			return self::refuse('the profile name is not a plain name');
		}
		if(!defined('PTS_INTERNAL_OB_CACHE') || !PTS_INTERNAL_OB_CACHE || !defined('PTS_PATH'))
		{
			return self::refuse('this installation has no bundled profiles');
		}
		$package = realpath(PTS_PATH);
		$base = realpath(PTS_INTERNAL_OB_CACHE . 'test-profiles');
		if($package === false || $base === false || strpos($base . '/', rtrim($package, '/') . '/') !== 0)
		{
			return self::refuse('the bundled profiles are not inside the package folder');
		}
		$dir = $base . '/' . $repository . '/' . $profile;
		if(is_link($dir) || !is_dir($dir) || realpath($dir) !== $dir)
		{
			return self::refuse('the folder of ' . $repository . '/' . $profile . ' is missing or is reached through a link');
		}
		foreach(array($dir, $base . '/' . $repository, $base) as $folder)
		{
			if(self::service_can_write($folder))
			{
				return self::refuse($folder . ' can be written by the user the server runs as');
			}
		}
		return $dir;
	}
	public static function zip($repository, $profile)
	{
		// The zip (bytes) of the package's copy of the profile, or false
		if(($dir = self::profile_dir($repository, $profile)) === false)
		{
			return false;
		}
		$files = array();
		$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
		foreach($iterator as $path => $info)
		{
			if($info->isLink() || (!$info->isFile() && !$info->isDir()) || self::service_can_write($path))
			{
				return self::refuse($path . ' is a link, a special file or can be written by the user the server runs as');
			}
			if($info->isFile())
			{
				$files[substr($path, strlen($dir) + 1)] = $path;
			}
		}
		if(empty($files) || !class_exists('ZipArchive'))
		{
			return self::refuse(empty($files) ? 'the folder is empty' : 'PHP has no zip support');
		}
		ksort($files, SORT_STRING);
		$temp = tempnam(sys_get_temp_dir(), 'benchy-zip-');
		$zip = new ZipArchive();
		if($temp === false || $zip->open($temp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true)
		{
			return self::refuse('the zip could not be started');
		}
		foreach($files as $name => $path)
		{
			$content = file_get_contents($path);
			if($content === false || !$zip->addFromString($name, $content) || !$zip->setMtimeName($name, self::ENTRY_TIME) || !$zip->setExternalAttributesName($name, ZipArchive::OPSYS_UNIX, 0100644 << 16)
				|| !$zip->setCompressionName($name, ZipArchive::CM_DEFLATE, 6))
			{
				$zip->close();
				@unlink($temp);
				return self::refuse($path . ' could not be added');
			}
		}
		$closed = $zip->close();
		$bytes = $closed ? file_get_contents($temp) : false;
		@unlink($temp);
		return $bytes === false || $bytes === '' ? self::refuse('the zip could not be written') : $bytes;
	}
}

?>
