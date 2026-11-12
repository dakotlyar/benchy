<?php

/*
	Benchy
	phoromatic_settings_store.php: the Phoromatic server's settings file (phoromatic-settings.pt2so), read, changed and saved with care.

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

// The same file format as pts_storage_object (it reads and writes through that class's own objects, so an older release reads the file), but only
// the server uses this class. The client's storage objects (core.pt2so and the rest) stay exactly as they were in 0.9.11: pts_storage_object
// itself is untouched. What this adds for the server's file:
//   - a save is written to a new 0600 file beside it and renamed over it, so a reader sees the old file or the new one, never half
//   - the read, change and write of a setting is done under a lock (<file>.lock), so two processes cannot lose each other's change
//   - a file that is there and cannot be read is an error, never an empty store
//   - root does not read-modify-write or lock the file in a folder that belongs to someone else or that others can write to (see root_refusal)
class phoromatic_settings_store
{
	public static $swap_hook = null;   // for tests only: called with the temporary file's name after it was written (and only when BENCHY_STORAGE_TEST_HOOK is defined)

	public static function root_refusal($folder)
	{
		// null when this process may change files in $folder, else the sentence that says why not. Only root is ever refused: as root, a folder
		// that belongs to another user (or that group or others can write to) lets that user plant links and replace files that root would then
		// follow or trust, and root does not switch to that user to work around it. A folder that does not exist yet is made by whoever is
		// running, so it is not refused.
		if(!function_exists('posix_geteuid') || posix_geteuid() !== 0 || !is_dir($folder) || ($st = @stat($folder)) === false)
		{
			return null;
		}
		$folder = rtrim($folder, '/') . '/';
		if($st['uid'] !== 0)
		{
			$name = ($pw = function_exists('posix_getpwuid') ? posix_getpwuid($st['uid']) : false) ? $pw['name'] : (string)$st['uid'];
			return 'The storage folder ' . $folder . ' belongs to ' . $name . ', not to root, and this command is running as root. Root does not change files in a folder another user controls. '
				. 'Run Benchy\'s server commands as that user (sudo -u ' . $name . ' benchy ...), or fix the folder\'s owner and mode (owned by root, not writable by its group or by others).';
		}
		if(($st['mode'] & 0022) !== 0)
		{
			return 'The storage folder ' . $folder . ' is writable by its group or by others (mode ' . substr(sprintf('%o', $st['mode']), -4) . '), and this command is running as root. Root does not change files in a folder others can write to. '
				. 'Fix the folder\'s mode (chmod go-w), or give it to the user that runs the server and run Benchy\'s server commands as that user (sudo -u USER benchy ...).';
		}
		return null;
	}
	private static function refuse_root($file)
	{
		if(($why = self::root_refusal(dirname($file))) !== null)
		{
			throw new Exception($why . ' Nothing was changed.');
		}
	}
	public static function recover($file)
	{
		// The stored object, or false when there is no file (a first start). A file that is there and cannot be read or understood throws: it is
		// not an empty store, and treating it as one would make every setting look unset.
		if($file === null || $file === '' || (!is_file($file) && !is_link($file)))
		{
			return false;
		}
		$storage = is_readable($file) && is_file($file) ? pts_storage_object::recover_from_file($file) : false;
		if($storage === false)
		{
			throw new Exception('The settings file ' . $file . ' is there but cannot be read (is it readable by this user, and not damaged?). Nothing was changed; fix or restore the file.');
		}
		return $storage;
	}
	public static function read_from_file($file, $identifier)
	{
		$storage = self::recover($file);
		return $storage === false ? false : $storage->read_object($identifier);
	}
	public static function save($storage, $file)
	{
		// What pts_storage_object::save_to_file builds (checksum, serialize, base64, wrap), without its write: that one is written here, beside
		// the file and renamed over it. The temporary file is made new by tempnam (random name, exclusive create, 0600) and written through the
		// handle that made it. There is no write in place: when it cannot be done the old file is kept and this throws.
		self::refuse_root($file);
		$data = Closure::bind(function()
		{
			$this->object_cs = md5(serialize($this->get_objects()));
			return wordwrap(base64_encode(serialize($this)), 80, PHP_EOL, true);
		}, $storage, 'pts_storage_object')();
		self::remove_stale_temporaries($file);
		$made = self::write_private($file, $data);
		if($made !== false)
		{
			list($temporary, $ino, $dev) = $made;
			if(self::$swap_hook !== null && defined('BENCHY_STORAGE_TEST_HOOK'))
			{
				call_user_func(self::$swap_hook, $temporary);   // (tests only: replaces the file here, as someone racing this process would)
			}
			$again = @lstat($temporary);
			if($again === false || ($again['mode'] & 0170000) !== 0100000 || $again['ino'] !== $ino || $again['dev'] !== $dev)
			{
				@unlink($temporary);   // (removes a link that was put there, never what it points at)
				throw new Exception('The temporary file for ' . $file . ' was replaced while it was being saved; the old file was kept.');
			}
			if(@rename($temporary, $file))
			{
				return;
			}
			@unlink($temporary);
		}
		throw new Exception($file . ' could not be saved (a temporary file could not be made or moved into place in its folder); the old file was kept.');
	}
	private static function write_private($destination, $data)
	{
		// array(name, inode, device) of a new file beside $destination, or false. The prefix is short (tempnam cuts it at 63 characters), tells the
		// file's name by a hash, and carries the pid so a clean-up can tell whose it was.
		$real = realpath(dirname($destination));
		$temporary = $real === false ? false : @tempnam($real, self::temporary_prefix($destination) . getmypid() . '.');
		if($temporary === false)
		{
			return false;
		}
		if(dirname($temporary) !== rtrim($real, '/') && dirname($temporary) !== $real)
		{
			@unlink($temporary);   // (tempnam fell back to the system temporary folder: a rename from there would not be atomic)
			return false;
		}
		$link = @lstat($temporary);
		$handle = ($link !== false && ($link['mode'] & 0170000) === 0100000) ? @fopen($temporary, 'r+') : false;
		$ok = false;
		if($handle !== false)
		{
			$stat = fstat($handle);
			$ok = $stat !== false && $link['ino'] === $stat['ino'] && $link['dev'] === $stat['dev'] && fwrite($handle, $data) === strlen($data);
			fclose($handle);
		}
		if(!$ok)
		{
			@unlink($temporary);
			return false;
		}
		return array($temporary, $link['ino'], $link['dev']);
	}
	private static function temporary_prefix($destination)
	{
		return '.pts-' . substr(md5(basename($destination)), 0, 8) . '.';
	}
	private static function remove_stale_temporaries($destination)
	{
		// temporary files (.pts-<hash of the name>.<pid>.<random>) a killed process left beside the file: only those of a process that no longer
		// exists AND that are ten minutes old (a recycled pid, or a process of another user we cannot see, is never mistaken for a dead one).
		// Plain files only; the name is matched literally.
		$folder = dirname($destination);
		$prefix = self::temporary_prefix($destination);
		foreach((array)@scandir($folder) as $name)
		{
			if(strpos($name, $prefix) !== 0 || !preg_match('/^' . preg_quote($prefix, '/') . '(\d+)\.[A-Za-z0-9]{6,}$/', $name, $m) || (int)$m[1] === getmypid())
			{
				continue;
			}
			$leftover = $folder . '/' . $name;
			$st = @lstat($leftover);
			if($st === false || ($st['mode'] & 0170000) !== 0100000 || time() - $st['mtime'] < 600)
			{
				continue;
			}
			$gone = function_exists('posix_kill') ? !@posix_kill((int)$m[1], 0) && posix_get_last_error() === 3 : (time() - $st['mtime']) > 86400;
			if($gone)
			{
				@unlink($leftover);
			}
		}
	}
	public static function locked($file, $callback)
	{
		// Runs $callback() holding an exclusive lock for this settings file (the file <file>.lock). Holding it again inside $callback (the same
		// process) is fine. If the lock file cannot be opened the callback runs without it, as it did before there was a lock.
		static $held = array();
		if(empty($file))
		{
			return $callback();   // no store path: nothing to lock, and the callback finds nothing to change
		}
		if(isset($held[$file]))
		{
			return $callback();
		}
		self::refuse_root($file);
		$lock = @fopen($file . '.lock', 'c');
		if($lock !== false)
		{
			@chmod($file . '.lock', 0600);
			flock($lock, LOCK_EX);
		}
		$held[$file] = true;
		try
		{
			return $callback();
		}
		finally
		{
			unset($held[$file]);
			if($lock !== false)
			{
				flock($lock, LOCK_UN);
				fclose($lock);
			}
		}
	}
	public static function set_in_file($file, $identifier, $object)
	{
		self::locked($file, function() use ($file, $identifier, $object)
		{
			if(($storage = self::recover($file)) !== false)
			{
				$storage->add_object($identifier, $object);
				self::save($storage, $file);
			}
		});
	}
	public static function remove_in_file($file, $identifier)
	{
		self::locked($file, function() use ($file, $identifier)
		{
			if(($storage = self::recover($file)) !== false)
			{
				$storage->remove_object($identifier);
				self::save($storage, $file);
			}
		});
	}
}

?>
