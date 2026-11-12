<?php

/*
	Phoronix Test Suite
	URLs: http://www.phoronix.com, http://www.phoronix-test-suite.com/
	Copyright (C) 2009 - 2011, Phoronix Media
	Copyright (C) 2009 - 2011, Michael Larabel
	pts_storage_object.php: An object for storing other PTS objects on the disk

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

class pts_storage_object
{
	private $object_cache;
	private $object_cs;
	private $creation_time;
	private $span_reboots;
	private $span_versions;
	private $pts_version;
	public static $swap_hook = null;   // for tests only: called with the temporary file's name after it was written

	public function __construct($span_reboots = true, $span_versions = true)
	{
		$this->creation_time = time();
		$this->span_reboots = $span_reboots;
		$this->span_versions = $span_versions;
		$this->pts_version = PTS_CORE_VERSION;
		$this->object_cache = array();
	}
	public function add_object($identifier, $object)
	{
		$this->object_cache[$identifier] = $object;
	}
	public function read_object($identifier)
	{
		return isset($this->object_cache[$identifier]) ? $this->object_cache[$identifier] : false;
	}
	public function remove_object($identifier)
	{
		unset($this->object_cache[$identifier]);
	}
	public function get_objects()
	{
		return $this->object_cache;
	}
	public function save_to_file($destination)
	{
		$this->object_cs = md5(serialize($this->get_objects())); // Checksum
		$string_version = base64_encode(serialize($this));
		$data = wordwrap($string_version, 80, PHP_EOL, true);
		// Written beside the file and renamed over it, so a process reading it while it is saved sees the old file or the new one, never half.
		// The temporary file is made new by tempnam (random name, exclusive create, 0600 from the start: a file or link planted at a guessable name is never used), written
		// through the handle that made it, and checked to be a plain file before anything is done to its owner. It takes the owner and group of
		// the file it replaces, as lstat() reports them, when root saves a file another user owns (a rename would hand it to root); if that
		// cannot be done the old file is kept and an error is raised, never a root-owned file left in its place.
		self::remove_stale_temporaries($destination);
		$made = self::write_private($destination, $data);
		if($made !== false)
		{
			list($temporary, $ino, $dev) = $made;
			if(self::$swap_hook !== null)
			{
				call_user_func(self::$swap_hook, $temporary);   // (a test replaces the file here, as a folder owner racing this process would)
			}
			$stat = (is_link($destination) || file_exists($destination)) ? @lstat($destination) : false;
			if($stat !== false && ($stat['mode'] & 0170000) === 0100000 && function_exists('posix_geteuid') && posix_geteuid() === 0)
			{
				// lchown/lchgrp act on the name itself and never follow a link; the file is looked at again before the rename
				if(!@lchown($temporary, $stat['uid']) || !@lchgrp($temporary, $stat['gid']))
				{
					@unlink($temporary);
					throw new Exception('Could not give ' . $destination . ' back to its owner after saving it; the old file was kept.');
				}
			}
			$again = @lstat($temporary);
			if($again === false || ($again['mode'] & 0170000) !== 0100000 || $again['ino'] !== $ino || $again['dev'] !== $dev)
			{
				@unlink($temporary);   // (removes a link that was put there, never what it points at)
				throw new Exception('The temporary file for ' . $destination . ' was replaced while it was being saved; the old file was kept.');
			}
			if(@rename($temporary, $destination))
			{
				return;
			}
			@unlink($temporary);
		}
		// The last resort: written in place, never through a link (or over anything that is not a plain file). Still 0600, and the owner is the
		// file's own as nothing was replaced.
		if(is_link($destination) || (file_exists($destination) && !is_file($destination)))
		{
			throw new Exception($destination . ' is a link or not a plain file, so it was not written.');
		}
		$old = umask(0077);
		try
		{
			file_put_contents($destination, $data);
		}
		finally
		{
			umask($old);
		}
		if(!is_link($destination))
		{
			chmod($destination, 0600);
		}
	}
	private static function write_private($destination, $data)
	{
		// A new file beside $destination, made by the system's own exclusive create (tempnam: a random name, mode 0600, never an existing file or
		// link), written through a handle and checked to be a plain file of this process. array(name, inode, device), or false. The prefix is short
		// (tempnam cuts it at 63 characters), tells the file's name by a hash, and carries the pid so a clean-up can tell whose it was.
		$folder = dirname($destination);
		$real = realpath($folder);
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
			// (tempnam made it 0600: nothing here acts on the path, only on the handle)
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
		// temporary files (.pts-<hash of the name>.<pid>.<random>) a killed process left beside the file: only those of a process that no longer exists AND that are a few minutes old (a
		// recycled pid, or a process of another user we cannot see, is never mistaken for a dead one). Plain files only; the name is matched
		// literally (a path with glob characters in it matches nothing else).
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
	public function get_pts_version()
	{
		return $this->pts_version;
	}
	public function get_object_checksum()
	{
		return $this->object_cs;
	}
	public function get_span_reboots()
	{
		return $this->span_reboots;
	}
	public function get_span_versions()
	{
		return $this->span_versions !== false;
	}
	public function get_creation_time()
	{
		return $this->creation_time;
	}
	public static function recover_from_file($read_from_file)
	{
		$restore_obj = false;

		if($read_from_file !== null && $read_from_file !== '' && is_file($read_from_file))
		{
			$restore = unserialize(base64_decode(file_get_contents($read_from_file)));

			if($restore instanceof pts_storage_object)
			{
				if(($restore->get_span_versions() || $restore->get_pts_version() == PTS_CORE_VERSION) && md5(serialize($restore->get_objects())) == $restore->get_object_checksum())
				{
					if($restore->get_span_reboots() == false)
					{
						$continue_loading = $restore->get_creation_time() > (time() - phodevi::system_uptime());
					}
					else
					{
						$continue_loading = true;
					}

					if($continue_loading)
					{
						$restore_obj = $restore;
					}
				}
			}
		}

		return $restore_obj;
	}
	public static function locked($storage_file, $callback)
	{
		// Runs $callback() holding an exclusive lock for this storage file (the file <storage file>.lock), so two processes doing read, change
		// and write on it cannot lose each other's change. Holding it again inside $callback (the same process) is fine. If the lock file
		// cannot be opened the callback runs without it, as it did before there was a lock.
		static $held = array();
		if(empty($storage_file))
		{
			return $callback();   // no store path: nothing to lock, and the callback finds nothing to change
		}
		if(isset($held[$storage_file]))
		{
			return $callback();
		}
		$lock = @fopen($storage_file . '.lock', 'c');
		if($lock !== false)
		{
			@chmod($storage_file . '.lock', 0600);
			flock($lock, LOCK_EX);
		}
		$held[$storage_file] = true;
		try
		{
			return $callback();
		}
		finally
		{
			unset($held[$storage_file]);
			if($lock !== false)
			{
				flock($lock, LOCK_UN);
				fclose($lock);
			}
		}
	}
	public static function set_in_file($storage_file, $identifier, $object)
	{
		self::locked($storage_file, function() use ($storage_file, $identifier, $object)
		{
			$storage = self::recover_from_file($storage_file);

			if($storage != false)
			{
				$storage->add_object($identifier, $object);
				$storage->save_to_file($storage_file);
			}
		});
	}
	public static function remove_in_file($storage_file, $identifier)
	{
		self::locked($storage_file, function() use ($storage_file, $identifier)
		{
			$storage = self::recover_from_file($storage_file);

			if($storage != false)
			{
				$storage->remove_object($identifier);
				$storage->save_to_file($storage_file);
			}
		});
	}
	public static function add_in_file($storage_file, $identifier, $add)
	{
		self::locked($storage_file, function() use ($storage_file, $identifier, $add)
		{
			$storage = self::recover_from_file($storage_file);

			if($storage != false)
			{
				$current_value = $storage->read_object($identifier);
				$current_value = is_numeric($current_value) ? $current_value : 0;

				$storage->add_object($identifier, $current_value + $add);
				$storage->save_to_file($storage_file);
			}
		});
	}
	public static function read_from_file($storage_file, $identifier)
	{
		$storage = self::recover_from_file($storage_file);
		$object = false;

		if($storage != false)
		{
			$object = $storage->read_object($identifier);
		}

		return $object;
	}
}

?>
