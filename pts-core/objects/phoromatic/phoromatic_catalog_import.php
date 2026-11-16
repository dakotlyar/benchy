<?php

/*
	Benchy
	phoromatic_catalog_import.php: imports a signed catalog bundle into the server (E4.4 C2, server design 3.1 and 3.4)

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

// One function, run(), serves the command line, the web page and the packaged import. phoromatic_catalog_verify does steps 1 to 10 and
// writes nothing; this class takes the lock, stages the packs while they are hashed, and then makes the catalog live in one database
// transaction. Nothing reaches signed/, packs/ or the database before every check has passed. Every outcome goes to the server audit: a
// refusal as catalog_import_refused after the work folder is gone and outside any transaction, a success as catalog_imported inside the
// transaction that made it, so there is never a row for an import that didn't happen or an import without a row.

class phoromatic_catalog_import
{
	const MAX_BUNDLE = 134217728;      // 128 MiB (catalog spec 7.2)
	const LOCK_WAIT_SECONDS = 5;
	const WORK_MAX_AGE = 3600;
	const MEMORY = 536870912;          // 512M, for the one request

	protected static $packaged_tried = false;

	// ---- the entry point ----

	// $via is cli, web or package; $actor_type one of the server audit's (cli, user, root, system). Returns the outcome array (status
	// 'imported' or 'unchanged', and the counts); throws phoromatic_catalog_refusal, with its audit row already written.
	public static function run($bundle_path, $via, $actor_type, $actor, $actor_detail = null, $account = null)
	{
		$info = array('via' => $via, 'bundle_sha256' => null, 'key_id' => null);
		try
		{
			return self::attempt($bundle_path, $via, $actor_type, $actor, $actor_detail, $account, $info);
		}
		catch(phoromatic_catalog_refusal $r)
		{
			// (a packaged bundle below what the server holds is not an alarm: it is recorded as "older" and nothing more)
			if(!($via === 'package' && $r->reason === phoromatic_catalog_refusal::ROLLBACK))
			{
				self::audit_refusal($r, $info, $actor_type, $actor, $actor_detail);
			}
			throw $r;
		}
	}
	protected static function refuse($reason, $message)
	{
		return new phoromatic_catalog_refusal($reason, $message);
	}

	// What stops an import before it starts, as words, or null. The arguments are what the PHP build has; a test passes others.
	public static function capability_problem($have_openssl, $have_curve, $have_zlib)
	{
		if(!$have_openssl)
		{
			return 'PHP\'s openssl extension is needed to check the catalog\'s signature (the php-cli package on Debian and Ubuntu has it).';
		}
		if(!$have_curve)
		{
			return 'This PHP\'s openssl does not support the P-256 curve (prime256v1) that the catalog is signed with.';
		}
		if(!$have_zlib)
		{
			return 'PHP\'s zlib extension is needed to read the catalog bundle.';
		}
		return null;
	}
	protected static function this_php_problem()
	{
		$openssl = extension_loaded('openssl') && defined('OPENSSL_ALGO_SHA256') && function_exists('openssl_verify');
		return self::capability_problem($openssl, $openssl && in_array('prime256v1', openssl_get_curve_names(), true), function_exists('inflate_init'));
	}
	protected static function raise_memory()
	{
		$have = trim((string)ini_get('memory_limit'));
		if($have === '-1')
		{
			return;
		}
		$n = (int)$have;
		$unit = strtolower(substr($have, -1));
		$bytes = $unit === 'g' ? $n * 1073741824 : ($unit === 'm' ? $n * 1048576 : ($unit === 'k' ? $n * 1024 : $n));
		if($bytes < self::MEMORY)
		{
			@ini_set('memory_limit', '512M');
		}
	}

	protected static function attempt($path, $via, $actor_type, $actor, $actor_detail, $account, &$info)
	{
		// Step 0
		if(($problem = self::this_php_problem()) !== null)
		{
			throw self::refuse(phoromatic_catalog_refusal::PRECONDITION, $problem);
		}
		if(!phoromatic_catalog::ready())
		{
			throw self::refuse(phoromatic_catalog_refusal::PRECONDITION, 'The server\'s database has not been upgraded for the catalog yet. Start the server once on this version, then try again.');
		}
		phoromatic_catalog_keys::load();   // (throws key_table: imports are refused while the stored catalog is still served; the verifier below loads the table itself)
		self::raise_memory();
		if(!is_file($path) || !is_readable($path) || ($size = filesize($path)) === false)
		{
			throw self::refuse(phoromatic_catalog_refusal::BUNDLE_UNREADABLE, 'The bundle ' . $path . ' cannot be read.');
		}
		if($size > self::MAX_BUNDLE)
		{
			throw self::refuse(phoromatic_catalog_refusal::LIMIT, 'The bundle is ' . $size . ' bytes; the limit is ' . self::MAX_BUNDLE . '.');
		}
		$info['bundle_sha256'] = hash_file('sha256', $path);
		phoromatic_catalog::ensure_dirs();
		$lock = @fopen(phoromatic_catalog::dir('import.lock'), 'c');
		if($lock === false)
		{
			throw self::refuse(phoromatic_catalog_refusal::STORAGE, 'The catalog lock file could not be opened.');
		}
		@chmod(phoromatic_catalog::dir('import.lock'), 0600);
		for($i = 0; $i < self::LOCK_WAIT_SECONDS * 10 && !($locked = flock($lock, LOCK_EX | LOCK_NB)); $i++)
		{
			usleep(100000);
		}
		if(empty($locked))
		{
			fclose($lock);
			throw self::refuse(phoromatic_catalog_refusal::BUSY, 'Another catalog import is running.');
		}
		$work = null;
		$old_umask = umask(077);
		try
		{
			$work = phoromatic_catalog::dir('work/' . bin2hex(random_bytes(8)) . '/');
			if(!@mkdir($work, 0700))
			{
				throw self::refuse(phoromatic_catalog_refusal::STORAGE, 'A work folder could not be made in ' . phoromatic_catalog::dir('work/') . '.');
			}
			return self::locked($path, $via, $actor_type, $actor, $actor_detail, $account, $info, $work);
		}
		finally
		{
			umask($old_umask);
			if($work !== null)
			{
				self::remove_dir($work);
			}
			flock($lock, LOCK_UN);
			fclose($lock);
		}
	}

	// Steps 1 to 11, with the lock held
	protected static function locked($path, $via, $actor_type, $actor, $actor_detail, $account, &$info, $work)
	{
		$bundle = @file_get_contents($path);
		if($bundle === false)
		{
			throw self::refuse(phoromatic_catalog_refusal::BUNDLE_UNREADABLE, 'The bundle ' . $path . ' cannot be read.');
		}
		// (for the audit of a refusal: which key the file says signed it. Only the signature line is read, nothing is parsed before the signature passes)
		try
		{
			$zip = phoromatic_catalog_zip::inspect($bundle);
			$member = $zip->lookup('index.signed');
			$file = $member === null ? null : $zip->read_all($member, phoromatic_catalog_verify::MAX_INDEX_BYTES);
			$info['key_id'] = $file === null ? null : phoromatic_catalog_verify::key_id_of($file);
		}
		catch(phoromatic_catalog_refusal $r)
		{
			$file = null;
		}
		unset($zip, $member);

		$state = phoromatic_catalog::state();
		$handles = array();
		$current = null;
		$sink = function($name, $chunk) use ($work, &$handles, &$current)
		{
			// (the zip reader has already refused any name but packs/<64 hex>.zip, so the file name below is hex and a suffix)
			if($current !== $name)
			{
				if($current !== null)
				{
					self::finish_stage($handles[$current]);
				}
				$current = $name;
				if(!isset($handles[$name]))
				{
					$h = @fopen($work . basename($name) . '.part', 'wb');
					if($h === false)
					{
						throw self::refuse(phoromatic_catalog_refusal::STORAGE, 'A pack could not be staged in ' . $work . ' (is the disk full?).');
					}
					$handles[$name] = $h;
				}
			}
			if(@fwrite($handles[$name], $chunk) !== strlen($chunk))
			{
				throw self::refuse(phoromatic_catalog_refusal::STORAGE, 'A pack could not be staged in ' . $work . ' (is the disk full?).');
			}
		};
		try
		{
			$decision = phoromatic_catalog_verify::check_bundle(phoromatic_catalog_keys::load(), $state, $bundle, array('phoromatic_catalog', 'stored_signed'), null, $sink);
		}
		finally
		{
			foreach($handles as $name => $h)
			{
				if(is_resource($h))
				{
					self::finish_stage($h);
				}
			}
		}
		if($file === null || hash('sha256', $file) !== $decision['signed_sha'])
		{
			throw self::refuse(phoromatic_catalog_refusal::STATE_CORRUPT, 'The index.signed that was checked is not the one that was read.');   // (cannot happen: the verifier read the same bytes)
		}
		unset($bundle);
		$tier = $decision['tier'];
		$info['tier'] = $tier;
		if($via === 'package' && $tier !== 'public')
		{
			throw self::refuse(phoromatic_catalog_refusal::PACKAGE_TIER, 'The bundle that came with this version of Benchy holds the ' . $tier . ' tier; only the public tier is ever packaged.');
		}
		$staged = array();   // pack sha256 => work file, once the verifier has shown the name is the hash
		foreach($decision['packs'] as $sha => $_size)
		{
			$staged[(string)$sha] = $work . (string)$sha . '.zip.part';
		}
		try
		{
			if($decision['unchanged'])
			{
				return self::repair($decision, $state, $file, $staged, $via, $actor_type, $actor, $actor_detail, $info);
			}
			return self::commit($decision, $state, $file, $staged, $via, $actor_type, $actor, $actor_detail, $account, $info);
		}
		finally
		{
			self::collect();
		}
	}

	// ---- files ----

	protected static function finish_stage($h)
	{
		if(is_resource($h))
		{
			@fflush($h);
			if(function_exists('fsync'))
			{
				@fsync($h);
			}
			@fclose($h);
		}
	}
	protected static function sync_dir($dir)
	{
		// (a folder can be opened for reading on Linux, and fsync on it makes the renames in it durable; where that is not so, nothing is lost but the sync)
		if(function_exists('fsync') && ($h = @fopen($dir, 'r')) !== false)
		{
			@fsync($h);
			@fclose($h);
		}
	}
	protected static function write_atomic($dest, $bytes)
	{
		$tmp = $dest . '.tmp' . bin2hex(random_bytes(4));
		$h = @fopen($tmp, 'wb');
		if($h === false || @fwrite($h, $bytes) !== strlen($bytes))
		{
			if($h !== false)
			{
				fclose($h);
			}
			@unlink($tmp);
			throw self::refuse(phoromatic_catalog_refusal::STORAGE, 'The file ' . basename($dest) . ' could not be written (is the disk full?).');
		}
		self::finish_stage($h);
		if(!@rename($tmp, $dest))
		{
			@unlink($tmp);
			throw self::refuse(phoromatic_catalog_refusal::STORAGE, 'The file ' . basename($dest) . ' could not be put in place.');
		}
		@chmod($dest, 0600);
		self::sync_dir(dirname($dest));
	}
	// Puts the staged packs the index names into packs/ when the file there is missing or the wrong size. Returns how many it put.
	protected static function place_packs($ix_packs, $staged)
	{
		$put = 0;
		foreach($ix_packs as $sha => $size)
		{
			$sha = (string)$sha;
			if(phoromatic_catalog::pack_present($sha, $size))
			{
				continue;
			}
			if(!isset($staged[$sha]) || !is_file($staged[$sha]))
			{
				throw self::refuse(phoromatic_catalog_refusal::STORAGE, 'The pack ' . substr($sha, 0, 12) . ' was not staged.');
			}
			if(!@rename($staged[$sha], phoromatic_catalog::pack_path($sha)))
			{
				throw self::refuse(phoromatic_catalog_refusal::STORAGE, 'The pack ' . substr($sha, 0, 12) . ' could not be put in place.');
			}
			@chmod(phoromatic_catalog::pack_path($sha), 0600);
			$put++;
		}
		if($put > 0)
		{
			self::sync_dir(phoromatic_catalog::dir('packs/'));
		}
		return $put;
	}
	protected static function remove_dir($dir)
	{
		if(!is_dir($dir) || is_link($dir))
		{
			return;
		}
		foreach(scandir($dir) ?: array() as $f)
		{
			if($f !== '.' && $f !== '..')
			{
				@unlink($dir . $f);
			}
		}
		@rmdir($dir);
	}

	// Deletes what no tier row or entry names (server design 3.4). Only ever called with the lock held. If the database can't be read, it
	// deletes nothing: a failed query must never look like "nothing is referenced".
	public static function collect()
	{
		try
		{
			$tiers = self::query_all('SELECT SignedSHA256 FROM phoromatic_catalog_tiers');
			$packs = self::query_all('SELECT DISTINCT PackSHA256 FROM phoromatic_catalog_entries WHERE PackSHA256 IS NOT NULL');
			if($tiers === false || $packs === false)
			{
				return;
			}
			$keep_signed = array();
			foreach($tiers as $r)
			{
				$keep_signed[$r['SignedSHA256'] . '.signed'] = true;
			}
			$keep_packs = array();
			foreach($packs as $r)
			{
				$keep_packs[$r['PackSHA256'] . '.zip'] = true;
			}
			foreach(array('signed/' => $keep_signed, 'packs/' => $keep_packs) as $sub => $keep)
			{
				$d = phoromatic_catalog::dir($sub);
				foreach(is_dir($d) ? (scandir($d) ?: array()) : array() as $f)
				{
					if($f !== '.' && $f !== '..' && !isset($keep[$f]) && is_file($d . $f))
					{
						@unlink($d . $f);
					}
				}
			}
			$w = phoromatic_catalog::dir('work/');
			foreach(is_dir($w) ? (scandir($w) ?: array()) : array() as $f)
			{
				if($f !== '.' && $f !== '..' && is_dir($w . $f) && !is_link($w . $f) && filemtime($w . $f) < time() - self::WORK_MAX_AGE)
				{
					self::remove_dir($w . $f . '/');
				}
			}
		}
		catch(Throwable $e)
		{
			// (collection is housekeeping: the next import or start tries again)
		}
	}
	protected static function query_all($sql)
	{
		if(($stmt = @phoromatic_server::$db->prepare($sql)) === false || ($result = @$stmt->execute()) === false)
		{
			return false;
		}
		$rows = array();
		while($row = $result->fetchArray(SQLITE3_ASSOC))
		{
			$rows[] = $row;
		}
		return $rows;
	}

	// ---- commit and repair ----

	protected static function run_sql($sql, $binds = array())
	{
		$stmt = phoromatic_server::$db->prepare($sql);
		if($stmt === false)
		{
			throw new Exception('prepare failed: ' . phoromatic_server::$db->lastErrorMsg());
		}
		foreach($binds as $k => $v)
		{
			$stmt->bindValue($k, $v);
		}
		if(@$stmt->execute() === false)
		{
			throw new Exception(phoromatic_server::$db->lastErrorMsg());
		}
	}
	protected static function entry_row($tier, $kind, $e)
	{
		$m = $kind === 'test' ? $e['manifest'] : null;
		$install = null;
		if($m !== null && property_exists($m, 'machine_install') && is_object($m->machine_install) && isset($m->machine_install->product) && is_string($m->machine_install->product))
		{
			$install = $m->machine_install->product;
		}
		$title = $kind === 'suite' ? $e['title'] : (isset($m->title) && is_string($m->title) && $m->title !== '' ? $m->title : $e['id']);
		return array(':tier' => $tier, ':kind' => $kind, ':id' => $e['id'], ':version' => $e['version'], ':pack' => $kind === 'test' ? $e['pack_sha256'] : null,
			':size' => $kind === 'test' ? $e['pack_size'] : null, ':min_engine' => $e['min_engine'], ':platforms' => $kind === 'test' ? json_encode($e['platforms']) : null,
			':license' => $kind === 'test' ? $e['license_class'] : null, ':title' => $title, ':install' => $install,
			':elevated' => $m !== null && property_exists($m, 'elevated_run') && $m->elevated_run !== null && $m->elevated_run !== false ? 1 : 0,
			':json' => json_encode($e, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR));
	}
	protected static function actor_fields($actor_detail, $tier, $detail)
	{
		return array('actor_detail' => $actor_detail, 'subject_type' => 'catalog_tier', 'subject_id' => $tier, 'detail' => $detail);
	}

	// A new index: the files first, then one transaction (3.4)
	protected static function commit($decision, $state, $file, $staged, $via, $actor_type, $actor, $actor_detail, $account, $info)
	{
		$ix = $decision['index'];
		$tier = $decision['tier'];
		$key = $decision['key'];
		$sha = $decision['signed_sha'];
		$old = isset($state['tiers'][$tier]) ? $state['tiers'][$tier] : null;
		$packs = array();
		foreach($ix['tests'] as $t)
		{
			$packs[$t['pack_sha256']] = $t['pack_size'];
		}
		self::write_atomic(phoromatic_catalog::signed_path($sha), $file);
		self::place_packs($packs, $staged);
		$now = phoromatic_server::current_time();
		$old_revoked = $old === null ? array() : array_flip($old['revoked']);
		$added = array('tests' => 0, 'suites' => 0, 'revoked' => 0);
		foreach($ix['tests'] as $t)
		{
			$added['tests'] += $old !== null && isset($old['tests'][$t['id'] . '@' . $t['version']]) ? 0 : 1;
		}
		foreach($ix['suites'] as $s)
		{
			$added['suites'] += $old !== null && isset($old['suites'][$s['id'] . '@' . $s['version']]) ? 0 : 1;
		}
		foreach($ix['revoked'] as $r)
		{
			$added['revoked'] += isset($old_revoked[$r['id'] . '@' . $r['version']]) ? 0 : 1;
		}
		$retired_to = max($state['retired_below'], $decision['next']['retired_below']);
		$from = $old === null ? null : array($old['generation'], $old['sequence']);
		$db = phoromatic_server::$db;
		if(@$db->exec('BEGIN IMMEDIATE') === false)
		{
			throw self::refuse(phoromatic_catalog_refusal::BUSY, 'The database is busy (' . $db->lastErrorMsg() . ').');
		}
		try
		{
			self::run_sql('INSERT OR IGNORE INTO phoromatic_catalog_state (Singleton, StateVersion, RetiredBelow) VALUES (1, ' . phoromatic_catalog::STATE_VERSION . ', 0)');
			self::run_sql('UPDATE phoromatic_catalog_state SET RetiredBelow = MAX(RetiredBelow, :n), ChangedOn = :now WHERE Singleton = 1', array(':n' => $retired_to, ':now' => $now));
			self::run_sql('INSERT OR REPLACE INTO phoromatic_catalog_tiers (Tier, Generation, Sequence, SignedSHA256, KeyID, Issued, MinGeneration, TestCount, SuiteCount, RevokedCount, BundleSHA256, ImportedOn, ImportedBy, ImportedVia, ImportedAccount) VALUES (:tier, :g, :s, :sha, :key, :issued, :min, :tests, :suites, :revoked, :bundle, :now, :by, :via, :account)',
				array(':tier' => $tier, ':g' => $key['generation'], ':s' => $ix['sequence'], ':sha' => $sha, ':key' => $key['id'], ':issued' => $ix['issued'], ':min' => $ix['min_generation'], ':tests' => count($ix['tests']),
					':suites' => count($ix['suites']), ':revoked' => count($ix['revoked']), ':bundle' => $info['bundle_sha256'], ':now' => $now, ':by' => (string)$actor, ':via' => $via, ':account' => $account));
			if($decision['recovery'])
			{
				self::run_sql('DELETE FROM phoromatic_catalog_ledger WHERE Tier = :tier', array(':tier' => $tier));
				self::run_sql('DELETE FROM phoromatic_catalog_revoked WHERE Tier = :tier', array(':tier' => $tier));
			}
			foreach($ix['tests'] as $t)
			{
				self::run_sql('INSERT OR IGNORE INTO phoromatic_catalog_ledger (Tier, Kind, Ref, Hash) VALUES (:tier, \'test\', :ref, :hash)', array(':tier' => $tier, ':ref' => $t['id'] . '@' . $t['version'], ':hash' => $t['tree_sha256']));
			}
			foreach($ix['suites'] as $s)
			{
				self::run_sql('INSERT OR IGNORE INTO phoromatic_catalog_ledger (Tier, Kind, Ref, Hash) VALUES (:tier, \'suite\', :ref, :hash)', array(':tier' => $tier, ':ref' => $s['id'] . '@' . $s['version'], ':hash' => $s['suite_sha256']));
			}
			foreach($ix['revoked'] as $r)
			{
				self::run_sql('INSERT OR REPLACE INTO phoromatic_catalog_revoked (Tier, Ref, Reason) VALUES (:tier, :ref, :reason)', array(':tier' => $tier, ':ref' => $r['id'] . '@' . $r['version'], ':reason' => $r['reason']));
			}
			self::run_sql('DELETE FROM phoromatic_catalog_entries WHERE Tier = :tier', array(':tier' => $tier));
			foreach(array('test' => $ix['tests'], 'suite' => $ix['suites']) as $kind => $list)
			{
				foreach($list as $e)
				{
					self::run_sql('INSERT INTO phoromatic_catalog_entries (Tier, Kind, ID, Version, PackSHA256, PackSize, MinEngine, Platforms, LicenseClass, Title, MachineInstall, ElevatedRun, EntryJSON) VALUES (:tier, :kind, :id, :version, :pack, :size, :min_engine, :platforms, :license, :title, :install, :elevated, :json)',
						self::entry_row($tier, $kind, $e));
				}
			}
			if(!phoromatic_server_audit::record(null, $actor_type, $actor, 'catalog_imported', self::actor_fields($actor_detail, $tier, array('via' => $via, 'bundle_sha256' => $info['bundle_sha256'], 'key_id' => $key['id'],
				'generation' => $key['generation'], 'sequence' => $ix['sequence'], 'from' => $from, 'recovery' => $decision['recovery'], 'unchanged' => false, 'tests_added' => $added['tests'], 'suites_added' => $added['suites'],
				'revoked_added' => $added['revoked'], 'retired_below' => array($state['retired_below'], $retired_to), 'restored' => array('index' => 0, 'packs' => 0)))))
			{
				throw new Exception('the audit row could not be written: ' . phoromatic_server_audit::last_error());
			}
			if(@$db->exec('COMMIT') === false)
			{
				throw new Exception('commit failed: ' . $db->lastErrorMsg());
			}
		}
		catch(Exception $e)
		{
			@$db->exec('ROLLBACK');
			phoromatic_server_audit::discard();
			throw self::refuse(phoromatic_catalog_refusal::STATE_WRITE, 'The catalog could not be saved and nothing was changed (' . $e->getMessage() . ').');
		}
		phoromatic_server_audit::flush();
		return array('status' => 'imported', 'unchanged' => false, 'tier' => $tier, 'generation' => $key['generation'], 'sequence' => $ix['sequence'], 'from' => $from, 'recovery' => $decision['recovery'],
			'tests_added' => $added['tests'], 'suites_added' => $added['suites'], 'revoked_added' => $added['revoked'], 'restored' => array('index' => 0, 'packs' => 0));
	}

	// The index is the one already accepted: put back what is missing, and say so in the audit (3.4, and catalog spec 11)
	protected static function repair($decision, $state, $file, $staged, $via, $actor_type, $actor, $actor_detail, $info)
	{
		$ix = $decision['index'];
		$tier = $decision['tier'];
		$ts = $state['tiers'][$tier];
		$restored = array('index' => 0, 'packs' => 0);
		if($ix === null)
		{
			list(, $raw) = phoromatic_catalog_verify::split($file);
			$ix = phoromatic_catalog_verify::parse_index($raw, PHP_INT_MAX);
		}
		if($decision['same_file'] && phoromatic_catalog::stored_signed($tier) === null)
		{
			self::write_atomic(phoromatic_catalog::signed_path($ts['signed_sha256']), $file);
			$restored['index'] = 1;
		}
		$packs = array();
		foreach($ix['tests'] as $t)
		{
			$packs[$t['pack_sha256']] = $t['pack_size'];
		}
		$restored['packs'] = self::place_packs($packs, $staged);
		$db = phoromatic_server::$db;
		if(@$db->exec('BEGIN IMMEDIATE') === false)
		{
			throw self::refuse(phoromatic_catalog_refusal::BUSY, 'The database is busy (' . $db->lastErrorMsg() . ').');
		}
		try
		{
			if($restored['index'] + $restored['packs'] > 0)
			{
				self::run_sql('INSERT OR IGNORE INTO phoromatic_catalog_state (Singleton, StateVersion, RetiredBelow) VALUES (1, ' . phoromatic_catalog::STATE_VERSION . ', 0)');
				self::run_sql('UPDATE phoromatic_catalog_state SET ChangedOn = :now WHERE Singleton = 1', array(':now' => phoromatic_server::current_time()));
			}
			$rows = phoromatic_catalog::tier_rows();
			if(!phoromatic_server_audit::record(null, $actor_type, $actor, 'catalog_imported', self::actor_fields($actor_detail, $tier, array('via' => $via, 'bundle_sha256' => $info['bundle_sha256'],
				'key_id' => isset($rows[$tier]) ? $rows[$tier]['KeyID'] : null, 'generation' => $ts['generation'], 'sequence' => $ts['sequence'], 'from' => array($ts['generation'], $ts['sequence']), 'recovery' => false,
				'unchanged' => true, 'tests_added' => 0, 'suites_added' => 0, 'revoked_added' => 0, 'retired_below' => array($state['retired_below'], $state['retired_below']), 'restored' => $restored))))
			{
				throw new Exception('the audit row could not be written: ' . phoromatic_server_audit::last_error());
			}
			if(@$db->exec('COMMIT') === false)
			{
				throw new Exception('commit failed: ' . $db->lastErrorMsg());
			}
		}
		catch(Exception $e)
		{
			@$db->exec('ROLLBACK');
			phoromatic_server_audit::discard();
			throw self::refuse(phoromatic_catalog_refusal::STATE_WRITE, 'The repair could not be saved (' . $e->getMessage() . ').');
		}
		phoromatic_server_audit::flush();
		return array('status' => 'unchanged', 'unchanged' => true, 'tier' => $tier, 'generation' => $ts['generation'], 'sequence' => $ts['sequence'], 'from' => array($ts['generation'], $ts['sequence']), 'recovery' => false,
			'tests_added' => 0, 'suites_added' => 0, 'revoked_added' => 0, 'restored' => $restored);
	}

	// ---- the audit row of a refusal ----

	protected static function audit_refusal($r, $info, $actor_type, $actor, $actor_detail)
	{
		$detail = array('via' => $info['via'], 'bundle_sha256' => $info['bundle_sha256'], 'reason' => $r->reason, 'message' => substr($r->getMessage(), 0, 300));
		if(!empty($info['key_id']))
		{
			$detail['key_id'] = $info['key_id'];
		}
		@phoromatic_server_audit::record(null, $actor_type, $actor, 'catalog_import_refused', self::actor_fields($actor_detail, isset($info['tier']) ? $info['tier'] : null, $detail));
		phoromatic_server_audit::flush();
	}

	// ---- check: steps 1 to 10 against an empty state and the key table's floors, writing nothing ----

	// array(tier, generation, sequence, tests, suites, revoked). Needs no server storage.
	public static function check_only($path)
	{
		if(($problem = self::this_php_problem()) !== null)
		{
			throw self::refuse(phoromatic_catalog_refusal::PRECONDITION, $problem);
		}
		self::raise_memory();
		if(!is_file($path) || !is_readable($path) || ($size = filesize($path)) === false)
		{
			throw self::refuse(phoromatic_catalog_refusal::BUNDLE_UNREADABLE, 'The bundle ' . $path . ' cannot be read.');
		}
		if($size > self::MAX_BUNDLE)
		{
			throw self::refuse(phoromatic_catalog_refusal::LIMIT, 'The bundle is ' . $size . ' bytes; the limit is ' . self::MAX_BUNDLE . '.');
		}
		$d = phoromatic_catalog_verify::check_bundle(phoromatic_catalog_keys::load(), phoromatic_catalog_verify::new_state(), file_get_contents($path));
		$ix = $d['index'];
		return array('tier' => $d['tier'], 'generation' => $d['key']['generation'], 'sequence' => $ix['sequence'], 'tests' => count($ix['tests']), 'suites' => count($ix['suites']), 'revoked' => count($ix['revoked']));
	}

	// ---- the packaged public bundle (server design 3.6) ----

	// Called by the event server on each pass; does its work once per process, when the schema is ready. Never throws.
	public static function packaged()
	{
		if(self::$packaged_tried || !phoromatic_catalog::ready())
		{
			return;
		}
		self::$packaged_tried = true;
		try
		{
			$files = glob(PTS_PATH . 'catalog/benchy-catalog-*.zip') ?: array();
			if(count($files) === 0)
			{
				return;
			}
			if(count($files) > 1)
			{
				self::record_packaged(null, 'refused (several_bundles): this version of Benchy came with more than one catalog bundle, so none was imported');
				return;
			}
			$sha = hash_file('sha256', $files[0]);
			if($sha !== false && phoromatic_catalog::state_row()['PackagedSHA256'] === $sha)
			{
				return;
			}
			try
			{
				$result = self::run($files[0], 'package', 'system', 'package');
				self::record_packaged($sha, $result['status']);
			}
			catch(phoromatic_catalog_refusal $r)
			{
				if($r->reason === phoromatic_catalog_refusal::ROLLBACK)
				{
					self::record_packaged($sha, 'older');
				}
				else if(!phoromatic_catalog_refusal::transient($r->reason))
				{
					self::record_packaged($sha, 'refused (' . $r->reason . '): ' . substr($r->getMessage(), 0, 300));
				}
			}
		}
		catch(Throwable $e)
		{
			// (it never fails the start; the next start tries again)
		}
	}
	protected static function record_packaged($sha, $outcome)
	{
		try
		{
			self::run_sql('INSERT OR IGNORE INTO phoromatic_catalog_state (Singleton, StateVersion, RetiredBelow) VALUES (1, ' . phoromatic_catalog::STATE_VERSION . ', 0)');
			self::run_sql('UPDATE phoromatic_catalog_state SET PackagedSHA256 = :sha, PackagedOutcome = :outcome, PackagedOn = :now WHERE Singleton = 1', array(':sha' => $sha, ':outcome' => $outcome, ':now' => phoromatic_server::current_time()));
		}
		catch(Throwable $e)
		{
			// (the next start imports it again, and it is unchanged)
		}
	}
}

?>
