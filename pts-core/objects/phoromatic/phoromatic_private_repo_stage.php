<?php

/*
	Benchy
	Staged web uploads for the private repository: stage, preview, confirm, cancel.

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

// Layout: <repo>/staging/<32 hex id>/{upload.zip, meta.json}. A stage becomes visible when meta.json exists. Nothing under staging/ is
// ever read by the index or fetch code (rebuild_index globs only tests/ and suites/; fetch builds paths from validated name/version).
// The preview data is computed once at stage time and kept in meta.json, so the confirm step never trusts form fields for it.
//
// Every operation on a stage (confirm, cancel, expiry sweep) first CLAIMS it by renaming upload.zip to <operation>.zip. A rename is
// atomic, so exactly one of any number of racing operations gets the claim and the others back off; whoever lost never audits.
//
// The preview must show what clients will run. Scripts and definition files are always rendered, never classified away as binary:
// control and invisible characters are made visible, long lines wrap, entry names must be canonical, and if the scripts cannot
// be shown completely the upload cannot be confirmed here.

class phoromatic_private_repo_stage extends phoromatic_private_repo
{
	const TTL = 1800; // 30 minutes
	const MAX_LIVE_PER_USER = 5;
	const MAX_TOTAL_BYTES = 26214400; // 25 MB of staged archives
	const PREVIEW_FILE_BYTES = 262144; // largest single file the review shows in full
	const PREVIEW_TOTAL_BYTES = 2097152; // all shown files together
	const HINT_SCAN_BYTES = 262144;
	const SCRIPT_EXTENSIONS = array('sh', 'bash', 'zsh', 'ksh', 'csh', 'fish', 'bat', 'cmd', 'ps1', 'psm1', 'psd1', 'py', 'pl', 'rb', 'js', 'vbs', 'vbe', 'jse', 'wsf', 'hta', 'php', 'lua', 'awk', 'tcl', 'reg', 'mk');
	const SPECIAL_FILES = array('test-definition.xml', 'suite-definition.xml', 'results-definition.xml', 'downloads.xml');

	public static function staging_path($account_id)
	{
		return self::repo_path($account_id) . 'staging/';
	}
	public static function valid_stage_id($id)
	{
		return is_string($id) && preg_match('/^[0-9a-f]{32}\z/', $id) === 1;
	}
	protected static function stage_dir($account_id, $stage_id)
	{
		return self::staging_path($account_id) . $stage_id . '/';
	}
	protected static function remove_dir($dir)
	{
		foreach((array)@scandir($dir) as $f)
		{
			if(is_string($f) && $f !== '.' && $f !== '..' && is_file($dir . $f))
			{
				@unlink($dir . $f);
			}
		}
		return @rmdir($dir);
	}
	public static function discard($account_id, $stage_id)
	{
		return self::valid_stage_id($stage_id) && self::remove_dir(self::stage_dir($account_id, $stage_id));
	}
	protected static function read_meta($dir)
	{
		if(!is_file($dir . 'meta.json'))
		{
			return null;
		}
		$meta = json_decode(@file_get_contents($dir . 'meta.json'), true);
		// The id inside must be the directory's own name: a copied or edited meta.json cannot adopt another stage
		return is_array($meta) && isset($meta['id']) && $meta['id'] === basename(rtrim($dir, '/')) && isset($meta['user_id']) && isset($meta['expires']) && isset($meta['sha256']) ? $meta : null;
	}
	protected static function stage_dirs($account_id)
	{
		$dirs = array();
		foreach(self::files_starting_with(self::staging_path($account_id), '') as $dir)
		{
			$dir = rtrim($dir, '/') . '/';
			if(is_dir($dir) && self::valid_stage_id(basename($dir)))
			{
				$dirs[] = $dir;
			}
		}
		return $dirs;
	}
	public static function live_stages($account_id, $user_id = null)
	{
		// Metadata of every unexpired stage (of one user if given), newest first
		$stages = array();
		foreach(self::stage_dirs($account_id) as $dir)
		{
			if(($meta = self::read_meta($dir)) === null || $meta['expires'] < time())
			{
				continue;
			}
			if($user_id === null || (string)$meta['user_id'] === (string)$user_id)
			{
				$stages[] = $meta;
			}
		}
		usort($stages, function($a, $b) {
			return $b['created'] - $a['created'];
		});
		return $stages;
	}
	protected static function quota_snapshot($account_id)
	{
		// Counts every stage that is not expired, including ones that are still being built (no meta.json yet: their owner file
		// says whose they are) or are claimed by a confirm/cancel in flight
		$per_user = array();
		$bytes = 0;
		foreach(self::stage_dirs($account_id) as $dir)
		{
			$meta = self::read_meta($dir);
			if($meta !== null)
			{
				if($meta['expires'] < time())
				{
					continue;
				}
				$user = (string)$meta['user_id'];
				$size = (int)$meta['size'];
			}
			else
			{
				$owner = json_decode(@file_get_contents($dir . 'owner'), true);
				if(!is_array($owner) || time() - (int)@filemtime($dir) > 600)
				{
					continue; // abandoned; the sweep removes it
				}
				$user = (string)$owner['user_id'];
				$size = (int)$owner['size'];
			}
			$per_user[$user] = (isset($per_user[$user]) ? $per_user[$user] : 0) + 1;
			$bytes += $size;
		}
		return array($per_user, $bytes);
	}
	public static function sweep($account_id, $expired_callback = null)
	{
		// Lazy cleanup (no cron): expired stages (audited; if the audit row cannot be written the stage stays), stages that never got metadata, anything abandoned for a day. An expired stage is
		// claimed first, so a confirm or cancel that is in flight is never pulled out from under, and when several sweeps race
		// only the one holding the claim removes it and calls $expired_callback (the audit row). The callback must be idempotent per
		// stage (expired_audit() is: it does not insert a second upload_expired row for a stage that already has one), because a
		// sweep that wrote the row but could not finish (mirror line failed, claim could not be restored) is retried by a later one.
		// A claim whose owner died (still there after a day) is audited like any other expiry before it is removed.
		$removed = 0;
		foreach(self::stage_dirs($account_id) as $dir)
		{
			$meta = self::read_meta($dir);
			$age = time() - (int)@filemtime($dir);
			if($meta === null)
			{
				if($age > 600 || $age > 86400)
				{
					self::remove_dir($dir);
					$removed++;
				}
				continue;
			}
			if($meta['expires'] < time())
			{
				if($expired_callback == null)
				{
					continue; // expiry is audited, so only a sweep that can write the audit row removes an expired stage
				}
				if(@rename($dir . 'upload.zip', $dir . 'expiring.zip'))
				{
					if(!call_user_func($expired_callback, $meta))
					{
						// No audit row, no removal: put the archive back so the next sweep tries again. If even that rename fails the
						// stage is left as it is (claim file in place, never removed here); the dead-claim path below audits it after a day.
						if(!@rename($dir . 'expiring.zip', $dir . 'upload.zip'))
						{
							error_log('private repo: could not release the expiry claim on staged upload ' . basename($dir));
						}
						continue;
					}
					self::remove_dir($dir);
					$removed++;
				}
				else if($age > 86400 && self::remove_dead_claim($dir, $expired_callback, $meta))
				{
					$removed++;
				}
			}
		}
		return $removed;
	}
	protected static function remove_dead_claim($dir, $expired_callback, $meta)
	{
		// A claim whose owner died long ago: nobody holds a live claim here, so racing sweeps would all audit it. They are serialized with
		// an exclusive lock on the stage's own meta.json instead (no new file: creating one would change the directory's age, which this path
		// depends on); the audit callback (idempotent: it checks for an existing row) then runs
		// for one sweep at a time, and a sweep that gets the lock after the stage is gone, or after the archive was put back, backs off.
		// Audited first and kept if the row cannot be written (fail-closed), like the live-claim path.
		$lock = @fopen($dir . 'meta.json', 'r');
		if($lock === false)
		{
			return false; // the sweep that won already removed the stage: nothing left to do
		}
		if(!@flock($lock, LOCK_EX))
		{
			fclose($lock);
			return false;
		}
		$done = false;
		if(!is_file($dir . 'upload.zip') && call_user_func($expired_callback, $meta))
		{
			$done = self::remove_dir($dir);
		}
		fclose($lock);
		return $done;
	}
	public static function kind_from_zip($zip_file, &$error)
	{
		// test or suite, from the definition file at the archive root; exactly one must be there
		$zip = new ZipArchive();
		if($zip->open($zip_file) !== true)
		{
			$error = 'The upload is not a valid zip archive.';
			return false;
		}
		if($zip->numFiles > self::MAX_ENTRIES)
		{
			$zip->close();
			$error = 'The archive has too many files (limit ' . self::MAX_ENTRIES . ').';
			return false;
		}
		$has = array('test' => false, 'suite' => false);
		for($i = 0; $i < $zip->numFiles; $i++)
		{
			$name = str_replace('\\', '/', (string)$zip->getNameIndex($i));
			$has['test'] = $has['test'] || $name === 'test-definition.xml';
			$has['suite'] = $has['suite'] || $name === 'suite-definition.xml';
		}
		$zip->close();
		if($has['test'] && $has['suite'])
		{
			$error = 'The archive contains both test-definition.xml and suite-definition.xml; a package is either a test profile or a test suite.';
			return false;
		}
		if(!$has['test'] && !$has['suite'])
		{
			$error = 'The archive has neither test-definition.xml nor suite-definition.xml at its root.';
			return false;
		}
		return $has['test'] ? 'test' : 'suite';
	}
	public static function noncanonical_entries($zip_file)
	{
		// Entry names that the review cannot be sure to match to what gets extracted: "./x", "a//b" and case variants of the special
		// files ("Downloads.xml" is downloads.xml on Windows and macOS). Backslashes are treated as separators, as inspect_zip does.
		$bad = array();
		$zip = new ZipArchive();
		if($zip->open($zip_file) === true)
		{
			for($i = 0; $i < $zip->numFiles && $i < self::MAX_ENTRIES + 1; $i++)
			{
				$name = str_replace('\\', '/', (string)$zip->getNameIndex($i));
				$trimmed = rtrim($name, '/');
				$canonical = implode('/', array_filter(explode('/', $trimmed), function($seg) { return $seg !== '' && $seg !== '.'; }));
				$lower = strtolower($canonical);
				if($trimmed !== $canonical || (in_array($lower, self::SPECIAL_FILES, true) && $canonical !== $lower))
				{
					$bad[] = self::visible_name(self::truncate($name, 80));
				}
			}
			$zip->close();
		}
		return $bad;
	}
	const WINDOWS_RESERVED = '/\A(?:con|prn|aux|nul|conin\$|conout\$|com[0-9\x{00B9}\x{00B2}\x{00B3}]|lpt[0-9\x{00B9}\x{00B2}\x{00B3}])\z/iu';
	public static function windows_unsafe_entries($zip_file)
	{
		// Names that Windows (NTFS) extracts as something else than the review shows: a trailing dot or space is dropped ("downloads.xml."
		// becomes downloads.xml), "DOWNLO~1.XML" is a second name for downloads.xml, device names (CON, nul.sh, COM1) are not files,
		// a colon is an alternate data stream, and names that differ only by case collide. Windows is a real client platform here.
		$bad = array();
		$seen = array();
		$dirs = array(); // lower-cased directory path => first spelling seen (explicit entries and parents of deeper entries)
		$zip = new ZipArchive();
		if($zip->open($zip_file) === true)
		{
			for($i = 0; $i < $zip->numFiles && $i < self::MAX_ENTRIES + 1; $i++)
			{
				$name = str_replace('\\', '/', (string)$zip->getNameIndex($i));
				$problem = null;
				$key = array();
				foreach(explode('/', rtrim($name, '/')) as $seg)
				{
					if($seg === '' || $seg === '.' || $seg === '..')
					{
						$key[] = $seg; // traversal and empty segments are the business of the canonical-name and path checks
						continue;
					}
					if($problem !== null)
					{
						$key[] = $seg;
						continue;
					}
					$base = preg_replace('/ +\z/', '', explode('.', $seg, 2)[0]);
					if(preg_match('/[<>"|?*\x00-\x1F]/', $seg))
					{
						$problem = 'contains a character Windows does not allow (one of < > " | ? * or a control character)';
					}
					else if(preg_match('/[. ]\z/', $seg))
					{
						$problem = 'ends in a dot or space';
					}
					else if(strpos($seg, ':') !== false)
					{
						$problem = 'contains a colon';
					}
					else if(preg_match('/~[0-9]/', $seg))
					{
						$problem = 'looks like a Windows short (8.3) name';
					}
					else if(preg_match(self::WINDOWS_RESERVED, $base) === 1)
					{
						$problem = 'is a Windows device name';
					}
					$key[] = function_exists('mb_strtolower') ? mb_strtolower($seg, 'UTF-8') : strtolower($seg);
				}
				$is_dir = substr($name, -1) == '/';
				$parts = $key;
				$key = implode('/', $key);
				if($problem === null)
				{
					// Directories: every parent of this entry, and the entry itself when it is a folder entry
					$dir_keys = array();
					$orig = explode('/', rtrim($name, '/'));
					for($d = 1; $d <= count($parts) - ($is_dir ? 0 : 1); $d++)
					{
						$dir_keys[implode('/', array_slice($parts, 0, $d))] = implode('/', array_slice($orig, 0, $d));
					}
					foreach($dir_keys as $dk => $spelling)
					{
						if(isset($seen[$dk]))
						{
							$problem = 'is a folder here but "' . self::visible_name(self::truncate($seen[$dk], 60)) . '" is a file whose name differs at most by case';
						}
						if(!isset($dirs[$dk]))
						{
							$dirs[$dk] = $spelling;
						}
					}
					if(!$is_dir && $problem === null)
					{
						if(isset($seen[$key]) && $seen[$key] !== $name)
						{
							$problem = 'differs from "' . self::visible_name(self::truncate($seen[$key], 60)) . '" only by case';
						}
						else if(isset($dirs[$key]))
						{
							$problem = 'is a file here but "' . self::visible_name(self::truncate($dirs[$key], 60)) . '" is a folder whose name differs at most by case';
						}
						$seen[$key] = $name;
					}
				}
				if($problem !== null)
				{
					$bad[] = self::visible_name(self::truncate($name, 80)) . ' (' . $problem . ')';
				}
			}
			$zip->close();
		}
		return $bad;
	}
	public static function name_from_filename($filename)
	{
		// "My-Test-1.2.3.zip" -> "my-test", "My Test (1).zip" -> "my-test-1"
		$base = strtolower(preg_replace('/\.zip\z/i', '', basename((string)$filename)));
		$base = preg_replace('/-[0-9]+\.[0-9]+\.[0-9]+\z/', '', $base);
		return trim(preg_replace('/-+/', '-', preg_replace('/[^a-z0-9-]+/', '-', $base)), '-');
	}
	protected static function reserve($account_id, $user_id, $size, $stage_id)
	{
		// Quota check and slot reservation under one lock, so parallel uploads cannot all pass the check. Returns an error or null.
		@mkdir(self::staging_path($account_id), 0700, true);
		$lock = fopen(self::staging_path($account_id) . '.lock', 'c');
		if($lock === false || !flock($lock, LOCK_EX))
		{
			return 'The server could not lock its staging area.';
		}
		$error = null;
		list($per_user, $bytes) = self::quota_snapshot($account_id);
		if((isset($per_user[(string)$user_id]) ? $per_user[(string)$user_id] : 0) >= self::MAX_LIVE_PER_USER)
		{
			$error = 'You already have ' . self::MAX_LIVE_PER_USER . ' uploads waiting for review. Confirm or cancel one first (they also expire after 30 minutes).';
		}
		else if($bytes + $size > self::MAX_TOTAL_BYTES)
		{
			$error = 'The server is holding too many staged uploads right now (limit ' . (self::MAX_TOTAL_BYTES / 1048576) . ' MB in total). Confirm or cancel some, or try again in a few minutes.';
		}
		else
		{
			$dir = self::stage_dir($account_id, $stage_id);
			$owner = json_encode(array('user_id' => (string)$user_id, 'size' => $size));
			if(!@mkdir($dir, 0700) || @file_put_contents($dir . 'owner', $owner) !== strlen($owner))
			{
				@self::remove_dir($dir);
				$error = 'The server could not create its staging area (disk full or not writable?).';
			}
		}
		flock($lock, LOCK_UN);
		fclose($lock);
		return $error;
	}
	public static function stage_upload($account_id, $user, $uploaded_file, $original_name, $name_override, $commit_description, $source_ip, $audit_callback)
	{
		// $uploaded_file is a PHP upload (is_uploaded_file) and is moved into staging; never read into memory.
		// array('stage' => meta) or array('error' => ..., 'audit' => facts, 'rejected' => true/false)
		if(self::repository_name() == false)
		{
			return array('error' => 'The private repository is disabled on this server.');
		}
		if(!is_string($commit_description) || strlen($commit_description) > 1000)
		{
			return array('error' => 'The commit description is limited to 1000 characters.');
		}
		$size = @filesize($uploaded_file);
		if($size === false || $size < 1 || $size > self::MAX_ZIP_BYTES)
		{
			return array('error' => 'The zip is empty or larger than ' . (self::MAX_ZIP_BYTES / 1048576) . ' MB. Larger payloads belong in downloads.xml, not in the package.', 'rejected' => true);
		}
		if(@file_get_contents($uploaded_file, false, null, 0, 4) !== "PK\x03\x04")
		{
			return array('error' => 'That file is not a zip archive.', 'rejected' => true);
		}

		self::sweep($account_id);
		$stage_id = bin2hex(random_bytes(16));
		if(($error = self::reserve($account_id, $user['UserID'], $size, $stage_id)) !== null)
		{
			return array('error' => $error);
		}
		$dir = self::stage_dir($account_id, $stage_id);
		$zip_file = $dir . 'upload.zip';
		// Whatever happens below (also a fatal error), a half-built stage must not linger
		$finished = false;
		register_shutdown_function(function() use ($dir, &$finished) {
			if(!$finished)
			{
				self::remove_dir($dir);
			}
		});
		if(!move_uploaded_file($uploaded_file, $zip_file))
		{
			self::remove_dir($dir);
			return array('error' => 'The server could not store the upload (disk full or not writable?).');
		}

		$facts = array('sha256' => hash_file('sha256', $zip_file), 'sha1' => sha1_file($zip_file), 'size' => $size);
		$reject = function($error) use ($dir, &$facts, &$finished) {
			self::remove_dir($dir);
			$finished = true;
			return array('error' => $error, 'audit' => $facts, 'rejected' => true);
		};

		$error = null;
		$bad = self::noncanonical_entries($zip_file);
		if(!empty($bad))
		{
			return $reject('The archive has entry names that are not in canonical form (' . implode(', ', array_slice($bad, 0, 5)) . '). Names like "./x", "a//b" or "Downloads.xml" can be read differently by different tools, so the review could not be trusted. Re-create the zip with plain relative paths and exact lower-case names for the definition files.');
		}
		$bad = self::windows_unsafe_entries($zip_file);
		if(!empty($bad))
		{
			return $reject('The archive has entry names that Windows clients would extract differently from what the review shows, or cannot extract: ' . implode('; ', array_slice($bad, 0, 5)) . '. Rename them (no trailing dot or space, no ":", no "~1"-style names, no CON/NUL/COM1-style device names, no names that differ only by case) and re-create the zip.');
		}
		if(($kind = self::kind_from_zip($zip_file, $error)) === false)
		{
			return $reject($error);
		}
		$warnings = array();
		$needs_rewrite = false;
		try
		{
			$info = self::inspect_zip($zip_file, $kind, $error, $warnings, $needs_rewrite);
			if($info !== false && $needs_rewrite)
			{
				$facts['original_sha256'] = $facts['sha256'];
				if(!self::rewrite_zip_with_forward_slashes($zip_file))
				{
					foreach(self::files_starting_with($dir, 'upload.zip.fixed') as $left)
					{
						@unlink($left);
					}
					return $reject('Unable to normalize the archive paths.');
				}
				$warnings = array();
				$needs_rewrite = false;
				$info = self::inspect_zip($zip_file, $kind, $error, $warnings, $needs_rewrite);
				$facts['sha256'] = hash_file('sha256', $zip_file);
				$facts['sha1'] = sha1_file($zip_file);
				$facts['size'] = filesize($zip_file);
			}
		}
		catch(Throwable $e)
		{
			$info = false;
			$error = 'The package could not be parsed.';
		}
		if($info === false)
		{
			return $reject($error);
		}

		$name = $name_override !== null && $name_override !== '' ? $name_override : self::name_from_filename($original_name);
		if(!self::valid_name($name))
		{
			return $reject('The name "' . preg_replace('/[^ -~]/', '?', self::truncate($name, 60)) . '" is not valid: use lower-case letters, numbers and dashes only (starting with a letter or number, and not ending in something that looks like a version). Type a name in the Name box to choose one.');
		}
		$facts['version'] = $info['version'];

		$existing = null;
		$existing_zip = self::item_dir($account_id, $kind, $name) . $info['version'] . '.zip';
		if(is_file($existing_zip))
		{
			$existing = array('sha256' => hash_file('sha256', $existing_zip), 'size' => filesize($existing_zip));
			$facts['prev_sha256'] = $existing['sha256'];
		}

		$meta = array('id' => $stage_id, 'user_id' => (string)$user['UserID'], 'user_name' => $user['UserName'], 'created' => time(), 'expires' => time() + self::TTL,
			'kind' => $kind, 'name' => $name, 'version' => $info['version'], 'title' => $info['title'], 'info' => $info, 'warnings' => $warnings,
			'size' => $facts['size'], 'sha256' => $facts['sha256'], 'sha1' => $facts['sha1'], 'commit_description' => $commit_description,
			'original_name' => self::storable_text(self::truncate($original_name, 120)), 'source_ip' => $source_ip, 'existing' => $existing,
			'preview' => self::build_preview($zip_file, $existing !== null ? $existing_zip : null));

		// The audit row comes before the stage becomes visible; without it the upload is refused
		$facts['warnings'] = $warnings;
		if($audit_callback != null && !call_user_func($audit_callback, $meta, $facts))
		{
			self::remove_dir($dir);
			$finished = true;
			return array('error' => 'The audit log could not be written, so the upload was refused and nothing was kept.', 'audit' => $facts, 'audit_failed' => true);
		}
		$json = json_encode($meta, JSON_INVALID_UTF8_SUBSTITUTE);
		if($json === false || @file_put_contents($dir . 'meta.json.tmp', $json) !== strlen($json) || !@rename($dir . 'meta.json.tmp', $dir . 'meta.json'))
		{
			self::remove_dir($dir);
			$finished = true;
			return array('error' => 'The server could not store the review data (disk full or not writable?).', 'audit' => $facts);
		}
		@unlink($dir . 'owner');
		$finished = true;
		return array('stage' => $meta, 'audit' => $facts);
	}
	public static function get_stage($account_id, $stage_id, $user_id)
	{
		// The stage's metadata, or array('error' => ...). Another user's stage looks exactly like a missing one.
		if(!self::valid_stage_id($stage_id) || ($meta = self::read_meta(self::stage_dir($account_id, $stage_id))) === null || (string)$meta['user_id'] !== (string)$user_id)
		{
			return array('error' => 'That upload was not found. It may have been confirmed, cancelled or expired (staged uploads last 30 minutes).');
		}
		if($meta['expires'] < time())
		{
			return array('error' => 'That upload has expired (staged uploads last 30 minutes). Please upload it again.', 'expired' => $meta);
		}
		if(!is_file(self::stage_dir($account_id, $stage_id) . 'upload.zip'))
		{
			return array('error' => 'That upload is being confirmed or cancelled right now (perhaps in another tab). Reload in a moment.');
		}
		return $meta;
	}
	public static function cancel($account_id, $meta, $audit_callback)
	{
		// Claims the stage first, so a confirm running at the same moment cannot also be audited as cancelled (or the reverse).
		// $audit_callback() writes the audit row; if it cannot, the stage is given back untouched.
		$dir = self::stage_dir($account_id, $meta['id']);
		if(!@rename($dir . 'upload.zip', $dir . 'cancelling.zip'))
		{
			return array('error' => 'That upload is already being confirmed or cancelled, or has expired.', 'claim_lost' => true);
		}
		if($audit_callback != null && !call_user_func($audit_callback))
		{
			@rename($dir . 'cancelling.zip', $dir . 'upload.zip');
			return array('error' => 'The audit log could not be written, so the upload was not cancelled; it stays staged until it expires.');
		}
		self::remove_dir($dir);
		return array('cancelled' => true);
	}
	public static function commit($account_id, $meta, $posted_sha256, $overwrite, $accept_unreviewable, $actor, $attempt_callback)
	{
		// $meta comes from get_stage(). Single use: the staged archive is claimed by an atomic rename, so of several parallel
		// confirms exactly one proceeds. The archive is copied and the COPY is verified against the checksum recorded at stage time
		// and the posted one, then handed to store_zip(), which inspects it again under the repository lock.
		// Returns store_zip()'s array plus 'stage_error' => true when the stage itself was the problem, 'restored' => true when a
		// failed commit left the stage usable.
		$dir = self::stage_dir($account_id, $meta['id']);
		$preview = $meta['preview'];
		if(!is_string($posted_sha256) || !hash_equals($meta['sha256'], strtolower($posted_sha256)))
		{
			return array('error' => 'The upload no longer matches what was reviewed; nothing was stored. Please upload it again.', 'stage_error' => true);
		}
		if(isset($preview['complete']) && $preview['complete'] === false)
		{
			return array('error' => 'This package is too large to review completely here (' . $preview['incomplete_reason'] . '), so it cannot be confirmed from the web page. Upload it from a system with upload-private-test-profile, or split the package.', 'restored' => true);
		}
		if(!empty($preview['unreviewable']) && !$accept_unreviewable)
		{
			return array('error' => 'This package contains ' . count($preview['unreviewable']) . ' file(s) that could not be shown for review. Tick the second box to accept that, or cancel.', 'restored' => true);
		}
		$existing_zip = self::item_dir($account_id, $meta['kind'], $meta['name']) . $meta['version'] . '.zip';
		$existing_now = is_file($existing_zip) ? hash_file('sha256', $existing_zip) : null;
		if($existing_now !== ($meta['existing'] !== null ? $meta['existing']['sha256'] : null))
		{
			return array('error' => 'Version ' . $meta['version'] . ' of ' . $meta['name'] . ' changed since you reviewed this upload (it was ' . ($meta['existing'] !== null ? 'overwritten, deleted' : 'added') . ' meanwhile), so the comparison you saw is out of date. Please upload again to review the current state.', 'stage_error' => true);
		}
		if($meta['existing'] !== null && !$overwrite)
		{
			return array('error' => 'Version ' . $meta['version'] . ' already exists. Tick the overwrite box to replace it, or bump the version in the package.', 'restored' => true);
		}
		$claimed = $dir . 'committing.zip';
		if(!@rename($dir . 'upload.zip', $claimed))
		{
			return array('error' => 'That upload is already being confirmed or cancelled, was confirmed, or has expired.', 'stage_error' => true, 'claim_lost' => true);
		}
		@mkdir(self::repo_path($account_id), 0770, true);
		$tmp = self::repo_path($account_id) . 'upload-' . substr(md5(uniqid('', true)), 0, 12) . '.zip';
		if(!@copy($claimed, $tmp))
		{
			@unlink($tmp);
			@rename($claimed, $dir . 'upload.zip');
			return array('error' => 'The server could not copy the upload into place (disk full or not writable?).', 'restored' => true);
		}
		if(hash_file('sha256', $tmp) !== $meta['sha256'])
		{
			@unlink($tmp);
			self::remove_dir($dir);
			return array('error' => 'The staged archive no longer matches the checksum recorded when it was reviewed; it was discarded and nothing was stored.', 'stage_error' => true, 'tampered' => true);
		}
		$result = self::store_zip($account_id, $actor, $meta['kind'], $meta['name'], $tmp, $meta['commit_description'], (bool)$overwrite, 'web', $attempt_callback);
		if(isset($result['error']) && empty($result['stored']))
		{
			@rename($claimed, $dir . 'upload.zip');
			$result['restored'] = true;
		}
		else if(isset($result['upload']) && isset($result['audit']['sha256']) && $result['audit']['sha256'] !== $meta['sha256'])
		{
			// What was stored is not what was reviewed. Take it back out (it goes to the trash) and say so loudly.
			self::delete($account_id, $meta['kind'], $meta['name'], $meta['version']);
			self::remove_dir($dir);
			return array('error' => 'INTEGRITY ERROR: the stored archive did not match the reviewed one, so it was removed again (see the trash directory). Nothing from this upload is in the repository; please upload again.', 'audit' => $result['audit'], 'attempted' => true, 'integrity' => true);
		}
		return $result;
	}
	protected static function is_script($path, $head)
	{
		$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
		return in_array($ext, self::SCRIPT_EXTENSIONS, true) || strpos($head, '#!') === 0;
	}
	public static function visible_text($data)
	{
		// What a reviewer reads must be what is in the file: control characters, invisible and bidi-formatting characters, blank-looking fillers and spaces (NBSP, em/en spaces, Hangul and Braille blanks), variation selectors and tag characters, NUL and
		// bytes that are not valid UTF-8 are made visible instead of being swallowed by the display. A CR that is part of CRLF stays.
		$invisible = '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]|\r(?!\n)|[\x{0080}-\x{00A0}\x{00AD}\x{034F}\x{061C}\x{115F}\x{1160}\x{1680}\x{17B4}\x{17B5}\x{180B}-\x{180E}\x{2000}-\x{200F}\x{2028}-\x{202F}\x{205F}-\x{206F}\x{2800}\x{3000}\x{3164}\x{FE00}-\x{FE0F}\x{FEFF}\x{FFA0}\x{FFF9}-\x{FFFB}\x{E0000}-\x{E007F}]/u';
		if(preg_match('//u', $data) === 1)
		{
			return preg_replace_callback($invisible, function($m) {
				$c = $m[0];
				$n = strlen($c);
				$cp = $n == 1 ? ord($c) : ($n == 2 ? ((ord($c[0]) & 0x1F) << 6) | (ord($c[1]) & 0x3F) : ($n == 3 ? (((ord($c[0]) & 0x0F) << 12) | ((ord($c[1]) & 0x3F) << 6) | (ord($c[2]) & 0x3F)) : (((ord($c[0]) & 0x07) << 18) | ((ord($c[1]) & 0x3F) << 12) | ((ord($c[2]) & 0x3F) << 6) | (ord($c[3]) & 0x3F))));
				return $cp === 0 ? '\\0' : sprintf('<U+%04X>', $cp);
			}, $data);
		}
		// Not valid UTF-8: show every byte that is not plain printable ASCII (or tab/newline) by its value
		return preg_replace_callback('/[^\x20-\x7E\t\n]/', function($m) {
			return sprintf('<0x%02X>', ord($m[0]));
		}, $data);
	}
	protected static function truncate($text, $chars)
	{
		// Cut after $chars characters, never inside a multi-byte one (a byte cut would turn the tail into <0xNN> noise). Text that is
		// not valid UTF-8 has no characters to respect and is cut by bytes; visible_text() shows those bytes by value anyway.
		$text = (string)$text;
		if(preg_match('//u', $text) === 1)
		{
			return preg_match('/\A.{0,' . (int)$chars . '}/su', $text, $m) === 1 ? $m[0] : '';
		}
		return substr($text, 0, $chars);
	}
	protected static function storable_text($text)
	{
		// Kept as text (rendered through visible_name() when shown); anything meta.json could not encode is flattened to '?'
		return preg_match('//u', $text) === 1 ? $text : preg_replace('/[^ -~]/', '?', $text);
	}
	public static function visible_name($name)
	{
		// Entry names, URLs and other short values shown in tables: same rendering as file content, plus tab and newline (which are
		// harmless in a script but would break or spoof a table cell)
		return str_replace(array("\n", "\t"), array('<U+000A>', '<U+0009>'), self::visible_text((string)$name));
	}
	protected static function script_hints($data)
	{
		// Cheap pattern matches that point a reviewer at lines worth reading. Hints, not a verdict: absence proves nothing. Only the
		// first HINT_SCAN_BYTES are scanned and every pattern is bounded, so a hostile file cannot make this slow.
		$text = substr($data, 0, self::HINT_SCAN_BYTES);
		$patterns = array(
			'downloads and pipes into a shell' => '/\b(?:curl|wget)\b[^\n|]{0,300}\|\s{0,10}(?:sudo\s{1,10})?(?:ba|z)?sh\b/i',
			'runs downloaded or encoded code' => '/\b(?:eval|Invoke-Expression|iex)\b|base64\s{1,10}(?:-d|--decode)|-EncodedCommand|DownloadString|DownloadFile/i',
			'recursive delete' => '/\brm\s{1,10}-[a-z]{0,6}r[a-z]{0,6}\s{1,10}[\/~$]|Remove-Item[^\n]{0,200}-Recurse/i',
			'changes system accounts or services' => '/\b(?:useradd|usermod|passwd|chpasswd|systemctl|sc\.exe|net\s{1,10}user|reg\s{1,10}add|schtasks)\b/i',
			'uses sudo or elevated rights' => '/\bsudo\b|RunAs|-Verb\s{1,10}RunAs/i');
		$hints = array();
		foreach($patterns as $label => $regex)
		{
			if(@preg_match($regex, $text) === 1)
			{
				$hints[] = $label;
			}
		}
		return $hints;
	}
	protected static function entry_hashes($zip_file)
	{
		$hashes = array();
		$zip = new ZipArchive();
		if($zip->open($zip_file) === true)
		{
			for($i = 0; $i < $zip->numFiles && $i < self::MAX_ENTRIES; $i++)
			{
				$name = str_replace('\\', '/', (string)$zip->getNameIndex($i));
				if(substr($name, -1) == '/')
				{
					continue;
				}
				$data = self::read_entry_bounded($zip, $i, self::MAX_ENTRY_BYTES);
				$hashes[$name] = $data === false ? null : hash('sha256', $data);
			}
			$zip->close();
		}
		return $hashes;
	}
	protected static function review_priority($name, $category)
	{
		// What the client runs first is read first: install scripts, then the other lifecycle scripts, then definitions and downloads,
		// then every other script, then plain text files
		$base = strtolower(basename($name));
		if($category == 'script' || $category == 'definition')
		{
			if(preg_match('/^install/', $base))
			{
				return 0;
			}
			if(preg_match('/^(pre|post|interim|support-check)/', $base))
			{
				return 1;
			}
			return $category == 'definition' ? 2 : 3;
		}
		return 4;
	}
	protected static function build_preview($zip_file, $existing_zip)
	{
		$preview = array('files' => array(), 'scripts' => array(), 'texts' => array(), 'downloads' => array(), 'diff' => null, 'complete' => true, 'incomplete_reason' => '', 'unreviewable' => array());
		$zip = new ZipArchive();
		if($zip->open($zip_file) !== true)
		{
			$preview['complete'] = false;
			$preview['incomplete_reason'] = 'the archive could not be read';
			return $preview;
		}
		$items = array();
		for($i = 0; $i < $zip->numFiles && $i < self::MAX_ENTRIES; $i++)
		{
			$name = str_replace('\\', '/', (string)$zip->getNameIndex($i));
			if(substr($name, -1) == '/')
			{
				continue;
			}
			$data = self::read_entry_bounded($zip, $i, self::MAX_ENTRY_BYTES);
			if($data === false)
			{
				$items[] = array('path' => $name, 'size' => null, 'sha256' => null, 'category' => 'binary', 'data' => null);
				continue;
			}
			$special = in_array(strtolower($name), self::SPECIAL_FILES, true);
			if($special || self::is_script($name, $data))
			{
				$category = $special ? 'definition' : 'script';      // never "binary": it is rendered whatever it contains
			}
			else
			{
				$head = substr($data, 0, 8192);
				$controls = preg_match_all('/[\x00-\x08\x0E-\x1F]/', $head);
				$category = strpos($head, "\0") === false && $controls <= max(2, strlen($head) / 50) ? 'text' : 'binary';
			}
			$items[] = array('path' => $name, 'size' => strlen($data), 'sha256' => hash('sha256', $data), 'category' => $category, 'data' => $data);
			if(strtolower($name) === 'downloads.xml')
			{
				$preview['downloads'] = self::parse_downloads($data);
				$preview['downloads_present'] = true;
				$preview['downloads_namespaced'] = self::downloads_namespaced($data);
			}
		}
		$zip->close();

		usort($items, function($a, $b) {
			return (self::review_priority($a['path'], $a['category']) - self::review_priority($b['path'], $b['category'])) ?: strcmp($a['path'], $b['path']);
		});
		$shown_total = 0;
		$decoys = array();
		foreach($items as $it)
		{
			$entry = array('path' => $it['path'], 'size' => $it['size'], 'sha256' => $it['sha256'], 'category' => $it['category'], 'shown' => false, 'reason' => '');
			if($it['data'] === null)
			{
				$entry['reason'] = 'unreadable';
			}
			else if($it['category'] == 'binary')
			{
				$entry['reason'] = 'binary';
			}
			else if($it['size'] > self::PREVIEW_FILE_BYTES)
			{
				$entry['reason'] = 'too large to show (over ' . (self::PREVIEW_FILE_BYTES / 1024) . ' KB)';
			}
			else if($shown_total + $it['size'] > self::PREVIEW_TOTAL_BYTES)
			{
				$entry['reason'] = 'preview budget used up (' . (self::PREVIEW_TOTAL_BYTES / 1048576) . ' MB in total)';
			}
			else
			{
				$entry['shown'] = true;
				$shown_total += $it['size'];
				$rec = array('path' => $it['path'], 'category' => $it['category'], 'size' => $it['size'], 'sha256' => $it['sha256'], 'text' => self::visible_text($it['data']),
					'hints' => $it['category'] == 'script' ? self::script_hints($it['data']) : array());
				$preview[$it['category'] == 'text' ? 'texts' : 'scripts'][] = $rec;
			}
			if(!$entry['shown'])
			{
				$preview['unreviewable'][] = array('path' => $it['path'], 'reason' => $entry['reason'], 'category' => $it['category']);
				if($it['category'] == 'script' || $it['category'] == 'definition')
				{
					// A script that cannot be read in full must not be confirmable
					$preview['complete'] = false;
					$decoys[] = $it['path'] . ' (' . $entry['reason'] . ')';
				}
			}
			$preview['files'][] = $entry;
		}
		if(!$preview['complete'])
		{
			$preview['incomplete_reason'] = 'not shown in full: ' . implode(', ', array_slice($decoys, 0, 5)) . (count($decoys) > 5 ? ' and ' . (count($decoys) - 5) . ' more' : '');
			// Non-script files that could not be shown matter less than the scripts that could not
		}
		usort($preview['files'], function($a, $b) {
			return strcmp($a['path'], $b['path']);
		});

		if($existing_zip !== null)
		{
			$old = self::entry_hashes($existing_zip);
			$new = array();
			foreach($preview['files'] as $f)
			{
				$new[$f['path']] = $f['sha256'];
			}
			$preview['diff'] = array('added' => array_values(array_diff(array_keys($new), array_keys($old))), 'removed' => array_values(array_diff(array_keys($old), array_keys($new))), 'changed' => array());
			foreach($new as $path => $hash)
			{
				if(isset($old[$path]) && $old[$path] !== $hash)
				{
					$preview['diff']['changed'][] = $path;
				}
			}
		}
		return $preview;
	}
	protected static function parse_downloads($xml)
	{
		// One row per package and URL: this is the arbitrary-download-then-run path that the archive contents alone do not show.
		// Parsed with SimpleXML and the same flags and property walk as the client (pts_test_profile::get_downloads:
		// $xml->Downloads->Package), not with a second parser, so the table cannot disagree with what clients fetch (they differ
		// on XML namespaces: SimpleXML only sees un-namespaced children here). Any root element name, first Downloads child.
		$rows = array();
		libxml_use_internal_errors(true);
		$x = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_COMPACT | LIBXML_PARSEHUGE | LIBXML_NONET);
		if($x === false)
		{
			libxml_clear_errors();
			return $rows;
		}
		if($x->Downloads && $x->Downloads->Package)
		{
			foreach($x->Downloads->Package as $pkg)
			{
				$field = function($tag) use ($pkg) {
					return isset($pkg->$tag) ? trim($pkg->$tag->__toString()) : '';
				};
				$file = $field('FileName');
				foreach(array_filter(array_map('trim', explode(',', $field('URL')))) as $url)
				{
					$rows[] = array('url' => $url, 'md5' => $field('MD5'), 'sha256' => $field('SHA256'), 'size' => $field('FileSize'), 'platform' => $field('PlatformSpecific'), 'file' => $file,
						'https' => stripos($url, 'https://') === 0, 'checksum' => $field('MD5') !== '' || $field('SHA256') !== '',
						'odd_file' => $file !== '' && (strpos($file, '/') !== false || strpos($file, '\\') !== false || strpos($file, '..') !== false));
				}
			}
		}
		libxml_clear_errors();
		return $rows;
	}
	protected static function downloads_namespaced($xml)
	{
		// True when any element of downloads.xml is in an XML namespace. The client ignores namespaced elements, other tools do not, and
		// the reviewer must be told that what a reader sees in the file need not be what is fetched.
		libxml_use_internal_errors(true);
		$dom = new DOMDocument();
		if(!@$dom->loadXML($xml, LIBXML_NONET | LIBXML_PARSEHUGE))
		{
			libxml_clear_errors();
			return false;
		}
		$found = (new DOMXPath($dom))->query('//*[namespace-uri() != ""]')->length > 0;
		libxml_clear_errors();
		return $found;
	}
}

?>
