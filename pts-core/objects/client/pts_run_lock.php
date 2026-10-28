<?php

/*
	Benchy
	pts_run_lock.php: one benchmark/install at a time per user path (Benchy 0.9)

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

// Two benchmarks on one box spoil each other's numbers. Every install and test run (JSON mode, the human commands, Phoromatic task
// runs) takes an exclusive flock() on <PTS_USER_PATH>/json-runs/run.lock for its duration. The holder's run ID, PID and command are
// written into it and into run.lock.holder (on Windows flock() locks the whole file, so other processes can read only the second
// one). The operating system releases the lock when the process ends, however it ends, so a lock whose holder died is simply free.
// JSON mode refuses on contention (error locked); everything else warns and goes on. Scope: one user path. The lock is re-entrant
// within a process (a JSON benchmark installs, then runs).

class pts_run_lock
{
	private static $handle = null;
	private static $depth = 0;

	public static function path()
	{
		return PTS_USER_PATH . 'json-runs/run.lock';
	}
	public static function acquire($command)
	{
		// true when this process holds the lock now (also when it could not be opened at all: never block a run on that), else the
		// holder as array(run, command, pid), each null when unknown
		if(self::$handle !== null)
		{
			self::$depth++;
			return true;
		}
		pts_file_io::mkdir(dirname(self::path()));
		$h = @fopen(self::path(), phodevi::is_windows() ? 'c+' : 'c+e'); // close-on-exec: a trial's processes must not inherit (and keep) the lock
		if($h === false)
		{
			return true;
		}
		if(!@flock($h, LOCK_EX | LOCK_NB))
		{
			fclose($h);
			return self::holder();
		}
		$who = json_encode(array('run' => pts_json_output::is_active() ? pts_json_output::run_id() : null, 'command' => (string)$command, 'pid' => getmypid()));
		ftruncate($h, 0);
		rewind($h);
		fwrite($h, $who);
		fflush($h);
		@file_put_contents(self::path() . '.holder', $who);
		self::$handle = $h;
		self::$depth = 1;
		return true;
	}
	public static function release($all = false)
	{
		if(self::$handle === null)
		{
			return;
		}
		self::$depth = $all ? 0 : self::$depth - 1;
		if(self::$depth > 0)
		{
			return;
		}
		@unlink(self::path() . '.holder');
		ftruncate(self::$handle, 0);
		flock(self::$handle, LOCK_UN);
		fclose(self::$handle);
		self::$handle = null;
	}
	public static function holder()
	{
		foreach(array(self::path(), self::path() . '.holder') as $f)
		{
			$d = is_file($f) ? json_decode((string)@file_get_contents($f), true) : null;
			if(is_array($d))
			{
				return array('run' => isset($d['run']) ? $d['run'] : null, 'command' => isset($d['command']) ? $d['command'] : null, 'pid' => isset($d['pid']) && is_numeric($d['pid']) ? (int)$d['pid'] : null);
			}
		}
		return array('run' => null, 'command' => null, 'pid' => null);
	}
	public static function take_or_warn($command)
	{
		// Outside JSON mode: take the lock; when another run holds it, say so and go on. true when this call took (or re-entered) it.
		$got = self::acquire($command);
		if($got === true)
		{
			return true;
		}
		echo PHP_EOL . 'WARNING: another Benchy ' . ($got['command'] !== null ? $got['command'] . ' ' : '') . 'run is in progress on this user path' . ($got['pid'] !== null ? ' (PID ' . $got['pid'] . ')' : '') . '; results of this run may be affected.' . PHP_EOL . PHP_EOL;
		return false;
	}
}

?>
