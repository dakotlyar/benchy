<?php

/*
	Benchy
	phoromatic_engine_store.php: the signed engine bundle the server holds and offers to paired machines (engine self-update, U1)

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

// The server's side of benchy-engine's `engine.signed` (benchy-engine internal/engupd, design sections 1 and 2). An admin imports a bundle: a zip
// of engine.signed and the archives it names. Every check passes before anything is stored; the bundle then becomes the one release the server
// offers. Like the catalog it is a gate, not the trust anchor: every engine verifies the same bytes again against its own embedded keys.
//
// Storage is files under <storage>/engine/ (no database step): signed/<sha>.signed, files/<sha> (the archives, named by their hash) and
// state.json, which is written last and is what says which of the others is current. Nothing is served unless current() has verified it just now:
// the signature against the key table, the signed file against the hash state.json holds, and every archive's presence and size.
// The engine key table is pts-core/static/engine/keys.json, its own file: a catalog key signs catalogs and an engine key signs engines, and
// neither table is read for the other (design 2).

class phoromatic_engine_store
{
		const INDEX_SCHEMA = 'benchy-engine-index/1';
	const STATE_VERSION = 1;
	const MAX_BUNDLE = 134217728;       // 128 MiB
	const MAX_SIGNED = 65536;           // engine.signed, signature line included (the engine's MaxSignedBytes)
	const MAX_ARCHIVE = 268435456;      // the engine's MaxArchive
	const MAX_EXE = 268435456;
	const MAX_SEQUENCE = 9007199254740991;
	const LOCK_WAIT_SECONDS = 5;
	const MEMORY = 536870912;
	const PLATFORMS = array('windows-x64' => array('.zip', 'benchy-engine.exe'), 'linux-x64' => array('.tar.gz', 'benchy-engine'), 'linux-arm64' => array('.tar.gz', 'benchy-engine'));

	// ---- the key table ----

	// The table is phoromatic_catalog_keys::load_engine(): nothing but pts-core/static/engine/keys.json can change it (no setting, variable or flag).

	// What `benchy-engine engine keys` prints: the generation lines, the denied lines, and TEST KEYS for the development table
	public static function keys_text()
	{
		$keys = phoromatic_catalog_keys::load_engine($test);
		return $keys->summary_text() . ($test ? "TEST KEYS: this server trusts development keys nobody holds, and can't take an engine bundle\n" : '');
	}

	// ---- the files ----

	public static function dir($sub = '')
	{
		return phoromatic_server::phoromatic_path(false) . 'engine/' . $sub;
	}
	public static function signed_path($sha)
	{
		return self::dir('signed/' . $sha . '.signed');
	}
	public static function file_path($sha)
	{
		return self::dir('files/' . $sha);
	}
	public static function ensure_dirs()
	{
		foreach(array('', 'signed/', 'files/', 'work/') as $sub)
		{
			$d = self::dir($sub);
			if(!is_dir($d) && !@mkdir($d, 0700) && !is_dir($d))
			{
				throw new phoromatic_catalog_refusal(phoromatic_catalog_refusal::STORAGE, 'The engine folder ' . $d . ' could not be made.');
			}
			@chmod($d, 0700);
		}
	}
	protected static function refuse($reason, $message)
	{
		return new phoromatic_catalog_refusal($reason, $message);
	}
	protected static function bad($message)
	{
		return new phoromatic_catalog_refusal(phoromatic_catalog_refusal::BAD_INDEX, $message);
	}

	// ---- the state ----

	// The stored state as an array, null when nothing was ever imported; throws state_corrupt for a file that is there and wrong
	public static function state()
	{
		$path = self::dir('state.json');
		if(!is_file($path))
		{
			return null;
		}
		$s = json_decode((string)@file_get_contents($path), true, 16);
		$ok = is_array($s) && isset($s['state'], $s['generation'], $s['sequence'], $s['retired_below'], $s['signed_sha256'], $s['version'], $s['files'], $s['revoked'])
			&& $s['state'] === self::STATE_VERSION && is_int($s['generation']) && is_int($s['sequence']) && is_int($s['retired_below']) && $s['generation'] >= 1 && $s['sequence'] >= 1
			&& is_string($s['signed_sha256']) && preg_match('/^[0-9a-f]{64}\z/', $s['signed_sha256']) === 1 && is_string($s['version']) && self::good_version($s['version'])
			&& is_array($s['files']) && is_array($s['revoked']);
		if($ok)
		{
			foreach($s['files'] as $sha => $size)
			{
				$ok = $ok && preg_match('/^[0-9a-f]{64}\z/', (string)$sha) === 1 && is_int($size) && $size > 0;
			}
		}
		if(!$ok)
		{
			throw self::refuse(phoromatic_catalog_refusal::STATE_CORRUPT, 'The stored engine state is damaged. Import the bundle again after removing ' . $path . '.');
		}
		return $s;
	}

	public static function good_version($v)
	{
		return is_string($v) && preg_match('/^(0|[1-9][0-9]{0,5})\.(0|[1-9][0-9]{0,5})\.(0|[1-9][0-9]{0,5})\z/', $v) === 1;
	}
	public static function compare($a, $b)
	{
		$a = array_map('intval', explode('.', $a));
		$b = array_map('intval', explode('.', $b));
		for($i = 0; $i < 3; $i++)
		{
			if($a[$i] !== $b[$i])
			{
				return $a[$i] < $b[$i] ? -1 : 1;
			}
		}
		return 0;
	}

	// ---- the index ----

	protected static function rfc3339($t)
	{
		return is_string($t) && preg_match('/^(\d{4})-(\d\d)-(\d\d)T(\d\d):(\d\d):(\d\d)(\.\d+)?(Z|[+-](\d\d):(\d\d))\z/', $t, $m) === 1
			&& checkdate((int)$m[2], (int)$m[3], (int)$m[1]) && (int)$m[4] < 24 && (int)$m[5] < 60 && (int)$m[6] < 60 && (!isset($m[9]) || ((int)$m[9] < 24 && (int)$m[10] < 60));
	}
	protected static function field($o, $k, $type, $what)
	{
		if(!property_exists($o, $k) || $o->$k === null)
		{
			return $type === 'string' ? '' : 0;
		}
		if(($type === 'string' && !is_string($o->$k)) || ($type === 'int' && !is_int($o->$k)))
		{
			throw self::bad($what . ': ' . $k . ' is not ' . ($type === 'int' ? 'an integer' : 'a string'));
		}
		return $o->$k;
	}

	// The structure checks of benchy-engine engupd.Index.Validate, on the bytes the signature covered. Returns the index as an array.
	public static function parse_index($raw)
	{
		$root = phoromatic_catalog_json::decode($raw, 'engine_root', phoromatic_catalog_refusal::BAD_INDEX);
		if(self::field($root, 'schema', 'string', 'the index') !== self::INDEX_SCHEMA)
		{
			throw self::bad('the index schema is not ' . self::INDEX_SCHEMA);
		}
		$ix = array('sequence' => self::field($root, 'sequence', 'int', 'the index'), 'issued' => self::field($root, 'issued', 'string', 'the index'), 'min_generation' => null, 'floor' => self::field($root, 'floor', 'string', 'the index'),
			'artifacts' => array(), 'revoked' => array());
		if($ix['sequence'] < 1 || $ix['sequence'] > self::MAX_SEQUENCE)
		{
			throw self::bad('the sequence is outside 1 to 2^53-1');
		}
		if(!self::rfc3339($ix['issued']))
		{
			throw self::bad('issued is not an RFC 3339 time');
		}
		if(property_exists($root, 'min_generation') && $root->min_generation !== null)
		{
			if(!is_int($root->min_generation) || $root->min_generation < 1)
			{
				throw self::bad('min_generation is not an integer of 1 or more');
			}
			$ix['min_generation'] = $root->min_generation;
		}
		if(!property_exists($root, 'release') || !is_object($root->release))
		{
			throw self::bad('the index has no release');
		}
		$rel = $root->release;
		$ix['version'] = self::field($rel, 'version', 'string', 'the release');
		if(!self::good_version($ix['version']))
		{
			throw self::bad('the release version is not three numbers, such as 0.11.0');
		}
		$ix['contract_protocol'] = self::field($rel, 'contract_protocol', 'int', 'the release');
		$ix['agent_protocol'] = self::field($rel, 'agent_protocol', 'int', 'the release');
		if($ix['contract_protocol'] < 1 || $ix['agent_protocol'] < 1)
		{
			throw self::bad('a protocol major is below 1');
		}
		$ix['notes'] = self::field($rel, 'notes', 'string', 'the release');
		if(strlen($ix['notes']) > 4000)
		{
			throw self::bad('the release notes are longer than 4000 bytes');
		}
		if(!property_exists($rel, 'artifacts') || !is_object($rel->artifacts) || count(get_object_vars($rel->artifacts)) === 0)
		{
			throw self::bad('the release has no artifacts');
		}
		foreach(get_object_vars($rel->artifacts) as $plat => $a)
		{
			if(!isset(self::PLATFORMS[$plat]))
			{
				continue;   // a platform this server doesn't know: ignored, as the engine ignores it
			}
			if(!is_object($a))
			{
				throw self::bad('the ' . $plat . ' artifact is not an object');
			}
			phoromatic_catalog_json::check_record($a, 'engine_artifact', phoromatic_catalog_refusal::BAD_INDEX);
			$exe = property_exists($a, 'exe') && is_object($a->exe) ? $a->exe : new stdClass();
			phoromatic_catalog_json::check_record($exe, 'engine_exe', phoromatic_catalog_refusal::BAD_INDEX);
			$art = array('file' => self::field($a, 'file', 'string', $plat), 'size' => self::field($a, 'size', 'int', $plat), 'sha256' => self::field($a, 'sha256', 'string', $plat),
				'exe_name' => self::field($exe, 'name', 'string', $plat), 'exe_size' => self::field($exe, 'size', 'int', $plat), 'exe_sha256' => self::field($exe, 'sha256', 'string', $plat));
			$name = 'benchy-engine-' . $ix['version'] . '-' . $plat . self::PLATFORMS[$plat][0];
			if($art['file'] !== $name)
			{
				throw self::bad('the ' . $plat . ' archive is named ' . json_encode($art['file']) . ', not ' . json_encode($name));
			}
			if($art['size'] < 1 || $art['size'] > self::MAX_ARCHIVE)
			{
				throw self::bad('the ' . $plat . ' archive size is outside 1 to ' . self::MAX_ARCHIVE);
			}
			if(preg_match('/^[0-9a-f]{64}\z/', $art['sha256']) !== 1 || preg_match('/^[0-9a-f]{64}\z/', $art['exe_sha256']) !== 1)
			{
				throw self::bad('a ' . $plat . ' hash is not 64 lowercase hex digits');
			}
			if($art['exe_name'] !== self::PLATFORMS[$plat][1])
			{
				throw self::bad('the ' . $plat . ' binary is not named ' . self::PLATFORMS[$plat][1]);
			}
			if($art['exe_size'] < 1 || $art['exe_size'] > self::MAX_EXE)
			{
				throw self::bad('the ' . $plat . ' binary size is outside 1 to ' . self::MAX_EXE);
			}
			$ix['artifacts'][$plat] = $art;
		}
		$revoked = property_exists($root, 'revoked') && $root->revoked !== null ? $root->revoked : array();
		if(!is_array($revoked) || count($revoked) > 1000)
		{
			throw self::bad('revoked is not a list of at most 1000 entries');
		}
		foreach($revoked as $r)
		{
			if(!is_object($r))
			{
				throw self::bad('a revocation is not an object');
			}
			$v = self::field($r, 'version', 'string', 'a revocation');
			$why = self::field($r, 'reason', 'string', 'a revocation');
			if(!self::good_version($v) || strlen($why) > 500)
			{
				throw self::bad('a revocation has a bad version or a reason over 500 bytes');
			}
			if($v === $ix['version'])
			{
				throw self::bad('the release ' . $ix['version'] . ' is also revoked');
			}
			$ix['revoked'][] = array('version' => $v, 'reason' => $why);
		}
		if($ix['floor'] !== '' && (!self::good_version($ix['floor']) || self::compare($ix['version'], $ix['floor']) < 0))
		{
			throw self::bad('the floor is not a version, or the release is below it');
		}
		return $ix;
	}

	// ---- what the server offers now, verified now ----

	// The release the server serves, or null (and $why says in a word: none, key_table, state_corrupt, denied_key, retired_key, bad_signature,
	// file_missing, ...). Everything a route or the hello hands out comes from here, so nothing unverified is ever offered.
	public static function current(&$why = null)
	{
		$why = 'none';
		try
		{
			$state = self::state();
			if($state === null)
			{
				return null;
			}
			$why = 'file_missing';
			$file = @file_get_contents(self::signed_path($state['signed_sha256']));
			if($file === false || strlen($file) > self::MAX_SIGNED || hash('sha256', $file) !== $state['signed_sha256'])
			{
				return null;
			}
			list($key, $raw) = phoromatic_catalog_verify::engine_signature($file, $state['retired_below']);
			$ix = self::parse_index($raw);
			if($ix['sequence'] !== $state['sequence'] || $key['generation'] !== $state['generation'])
			{
				$why = 'state_corrupt';
				return null;
			}
			foreach($state['files'] as $sha => $size)
			{
				$st = @stat(self::file_path($sha));
				if($st === false || $st['size'] !== $size)
				{
					$why = 'file_missing';
					return null;
				}
			}
			foreach($ix['artifacts'] as $art)
			{
				if(!isset($state['files'][$art['sha256']]) || $state['files'][$art['sha256']] !== $art['size'])
				{
					$why = 'file_missing';
					return null;
				}
			}
			$why = null;
			return array('version' => $ix['version'], 'sequence' => $ix['sequence'], 'generation' => $state['generation'], 'key_id' => $key['id'], 'notes' => $ix['notes'], 'issued' => $ix['issued'],
				'artifacts' => $ix['artifacts'], 'revoked' => $ix['revoked'], 'floor' => $state['floor'], 'signed_sha256' => $state['signed_sha256'], 'signed' => $file, 'files' => $state['files'],
				'imported_on' => isset($state['imported_on']) ? $state['imported_on'] : null, 'imported_by' => isset($state['imported_by']) ? $state['imported_by'] : null, 'retired_below' => $state['retired_below']);
		}
		catch(phoromatic_catalog_refusal $r)
		{
			$why = $r->reason;
			return null;
		}
	}

	// The size of an archive the server may serve by this hash, or null
	public static function served_size($sha)
	{
		$c = self::current();
		return $c !== null && isset($c['files'][$sha]) ? $c['files'][$sha] : null;
	}

	// For hello_response.engine.available: array('version' =>, 'sequence' =>) or null
	public static function available()
	{
		$c = self::current();
		return $c === null ? null : array('version' => $c['version'], 'sequence' => $c['sequence']);
	}

	// ---- importing ----

	// $via is cli or web; returns the outcome array (status imported or unchanged); throws phoromatic_catalog_refusal with its audit row written.
	public static function import($bundle_path, $via, $actor_type, $actor, $actor_detail = null, $account = null)
	{
		$info = array('via' => $via, 'bundle_sha256' => null, 'key_id' => null);
		try
		{
			$out = self::attempt($bundle_path, $via, $actor_type, $actor, $actor_detail, $account, $info);
			self::served_check($out);
			self::audit(...$info['audit']);
			return $out;
		}
		catch(phoromatic_catalog_refusal $r)
		{
			self::audit('engine_import_refused', $actor_type, $actor, $actor_detail, null, array('via' => $via, 'reason' => $r->reason, 'message' => substr($r->getMessage(), 0, 500), 'bundle_sha256' => $info['bundle_sha256'], 'key_id' => $info['key_id']));
			throw $r;
		}
	}
	// After the files and state are written, ask the store what it would offer now: an import that stored something nobody is offered fails here, in the same process
	protected static function served_check($out)
	{
		$c = self::current($why);
		if($c === null || $c['sequence'] !== $out['sequence'] || $c['generation'] !== $out['generation'])
		{
			throw phoromatic_store_status::not_served('engine', self::dir(), $c === null ? $why : 'state_corrupt');
		}
	}
	protected static function audit($action, $actor_type, $actor, $actor_detail, $subject, $detail)
	{
		@phoromatic_server_audit::record(null, $actor_type, $actor, $action, array('actor_detail' => $actor_detail, 'subject_type' => 'engine', 'subject_id' => $subject, 'detail' => $detail));
	}

	protected static function attempt($path, $via, $actor_type, $actor, $actor_detail, $account, &$info)
	{
		if(($problem = phoromatic_catalog_import::this_php_problem()) !== null)
		{
			throw self::refuse(phoromatic_catalog_refusal::PRECONDITION, str_replace('the catalog', 'the engine bundle', $problem));
		}
		phoromatic_catalog_keys::load_engine();   // (throws key_table: imports are refused while it is damaged)
		$have = trim((string)ini_get('memory_limit'));
		if($have !== '-1' && (int)$have * (['g' => 1073741824, 'm' => 1048576, 'k' => 1024][strtolower(substr($have, -1))] ?? 1) < self::MEMORY)
		{
			@ini_set('memory_limit', '512M');
		}
		if(!is_file($path) || !is_readable($path) || ($size = filesize($path)) === false)
		{
			throw self::refuse(phoromatic_catalog_refusal::BUNDLE_UNREADABLE, 'The bundle ' . $path . ' cannot be read.');
		}
		if($size > self::MAX_BUNDLE)
		{
			throw self::refuse(phoromatic_catalog_refusal::LIMIT, 'The bundle is ' . $size . ' bytes; the limit is ' . self::MAX_BUNDLE . '.');
		}
		$info['bundle_sha256'] = hash_file('sha256', $path);
		self::ensure_dirs();
		$lock = @fopen(self::dir('import.lock'), 'c');
		if($lock === false)
		{
			throw self::refuse(phoromatic_catalog_refusal::STORAGE, 'The engine lock file could not be opened.');
		}
		@chmod(self::dir('import.lock'), 0600);
		for($i = 0; $i < self::LOCK_WAIT_SECONDS * 10 && !($locked = flock($lock, LOCK_EX | LOCK_NB)); $i++)
		{
			usleep(100000);
		}
		if(empty($locked))
		{
			fclose($lock);
			throw self::refuse(phoromatic_catalog_refusal::BUSY, 'Another engine import is running.');
		}
		$work = self::dir('work/' . bin2hex(random_bytes(8)) . '/');
		$old_umask = umask(077);
		try
		{
			if(!@mkdir($work, 0700))
			{
				throw self::refuse(phoromatic_catalog_refusal::STORAGE, 'A work folder could not be made in ' . self::dir('work/') . '.');
			}
			return self::locked($path, $via, $actor_type, $actor, $actor_detail, $account, $info, $work);
		}
		finally
		{
			umask($old_umask);
			self::remove_dir($work);
			flock($lock, LOCK_UN);
			fclose($lock);
		}
	}

	protected static function locked($path, $via, $actor_type, $actor, $actor_detail, $account, &$info, $work)
	{
		$bundle = @file_get_contents($path);
		if($bundle === false)
		{
			throw self::refuse(phoromatic_catalog_refusal::BUNDLE_UNREADABLE, 'The bundle ' . $path . ' cannot be read.');
		}
		$zip = phoromatic_catalog_zip::inspect($bundle, true);
		$member = $zip->lookup('engine.signed');
		if($member === null)
		{
			throw self::refuse(phoromatic_catalog_refusal::BAD_ENVELOPE, 'The bundle has no engine.signed.');
		}
		$file = $zip->read_all($member, self::MAX_SIGNED);
		try
		{
			$info['key_id'] = phoromatic_catalog_verify::key_id_of($file);   // (for the audit of a refusal: only the signature line is read)
		}
		catch(phoromatic_catalog_refusal $r)
		{
		}
		$state = self::state();
		// Step 1: the signature, before anything is parsed
		list($key, $raw) = phoromatic_catalog_verify::engine_signature($file, $state === null ? 0 : $state['retired_below']);
		$ix = self::parse_index($raw);
		if($ix['min_generation'] !== null && $ix['min_generation'] > $key['generation'])
		{
			throw self::bad('min_generation ' . $ix['min_generation'] . ' is above the signing key\'s generation ' . $key['generation']);
		}
		$sha = hash('sha256', $file);
		$unchanged = false;
		if($state !== null)
		{
			$cmp = $key['generation'] !== $state['generation'] ? ($key['generation'] < $state['generation'] ? -1 : 1) : ($ix['sequence'] < $state['sequence'] ? -1 : ($ix['sequence'] > $state['sequence'] ? 1 : 0));
			if($cmp < 0)
			{
				throw self::refuse(phoromatic_catalog_refusal::ROLLBACK, 'The bundle is at generation ' . $key['generation'] . ', sequence ' . $ix['sequence'] . '; this server already holds generation ' . $state['generation'] . ', sequence ' . $state['sequence'] . '.');
			}
			if($cmp === 0 && $sha !== $state['signed_sha256'])
			{
				throw self::refuse(phoromatic_catalog_refusal::SAME_PAIR_DIFFERS, 'The bundle has the same generation and sequence as the one this server holds, with different contents.');
			}
			$unchanged = $cmp === 0;
		}
		// Step 2: every archive the index names is in the bundle, with its size and hash, and the bundle holds nothing else
		$expected = array();
		foreach($ix['artifacts'] as $plat => $art)
		{
			$expected[$art['file']] = $art;
		}
		foreach($zip->files() as $ent)
		{
			if($ent['name'] !== 'engine.signed' && !isset($expected[$ent['name']]))
			{
				throw self::refuse(phoromatic_catalog_refusal::BUNDLE_PACK_UNLISTED, $ent['name'] . ' is in the bundle but the signed index does not name it.');
			}
		}
		$staged = array();
		foreach($expected as $name => $art)
		{
			$ent = $zip->lookup($name);
			if($ent === null)
			{
				throw self::refuse(phoromatic_catalog_refusal::BUNDLE_PACK_MISSING, $name . ' is named by the signed index but is not in the bundle.');
			}
			if($ent['usize'] !== $art['size'])
			{
				throw self::refuse(phoromatic_catalog_refusal::BUNDLE_PACK_HASH, $name . ' is ' . $ent['usize'] . ' bytes; the signed index says ' . $art['size'] . '.');
			}
			if(isset($staged[$art['sha256']]))
			{
				continue;   // (two platforms can't share an archive: the names differ, so the hashes would too; a repeat is the same bytes)
			}
			$dest = $work . $art['sha256'] . '.part';
			$h = @fopen($dest, 'wb');
			if($h === false)
			{
				throw self::refuse(phoromatic_catalog_refusal::STORAGE, 'An archive could not be staged in ' . $work . ' (is the disk full?).');
			}
			$hash = hash_init('sha256');
			try
			{
				$zip->stream($ent, function($chunk) use ($h, $hash)
				{
					hash_update($hash, $chunk);
					if(@fwrite($h, $chunk) !== strlen($chunk))
					{
						throw self::refuse(phoromatic_catalog_refusal::STORAGE, 'An archive could not be staged (is the disk full?).');
					}
				});
			}
			finally
			{
				@fflush($h);
				if(function_exists('fsync'))
				{
					@fsync($h);
				}
				fclose($h);
			}
			if(!hash_equals($art['sha256'], hash_final($hash)))
			{
				throw self::refuse(phoromatic_catalog_refusal::BUNDLE_PACK_HASH, $name . ' does not match the hash in the signed index.');
			}
			$staged[$art['sha256']] = $dest;
		}
		unset($bundle, $zip);
		// Step 3: store. The archives and the signed file first, state.json last: until it moves, the old release is what is served.
		$files = array();
		foreach($ix['artifacts'] as $art)
		{
			$files[$art['sha256']] = $art['size'];
		}
		$restored = 0;
		foreach($files as $fsha => $fsize)
		{
			$st = @stat(self::file_path($fsha));
			if($st !== false && $st['size'] === $fsize)
			{
				continue;
			}
			if(!@rename($staged[$fsha], self::file_path($fsha)))
			{
				throw self::refuse(phoromatic_catalog_refusal::STORAGE, 'An archive could not be put in place.');
			}
			@chmod(self::file_path($fsha), 0600);
			$restored++;
		}
		if(!is_file(self::signed_path($sha)) || @file_get_contents(self::signed_path($sha)) !== $file)
		{
			self::write_atomic(self::signed_path($sha), $file);
			$restored++;
		}
		$now = phoromatic_server::current_time();
		$revoked = $state === null ? array() : $state['revoked'];
		foreach($ix['revoked'] as $r)
		{
			if(!in_array($r['version'], $revoked, true))
			{
				$revoked[] = $r['version'];
			}
		}
		usort($revoked, array(__CLASS__, 'compare'));
		$floor = $state === null ? '' : (string)$state['floor'];
		if($ix['floor'] !== '' && ($floor === '' || self::compare($ix['floor'], $floor) > 0))
		{
			$floor = $ix['floor'];
		}
		if($unchanged)
		{
			$new = $state;   // the same release: only the files may have been put back
		}
		else
		{
			$new = array('state' => self::STATE_VERSION, 'generation' => $key['generation'], 'sequence' => $ix['sequence'], 'signed_sha256' => $sha, 'key_id' => $key['id'],
				'retired_below' => max($state === null ? 0 : $state['retired_below'], $ix['min_generation'] === null ? 0 : $ix['min_generation']), 'version' => $ix['version'], 'issued' => $ix['issued'],
				'files' => $files, 'revoked' => $revoked, 'floor' => $floor, 'imported_on' => $now, 'imported_by' => (string)$actor, 'via' => $via, 'bundle_sha256' => $info['bundle_sha256'], 'account' => $account);
			self::write_atomic(self::dir('state.json'), json_encode($new, JSON_UNESCAPED_SLASHES) . "\n");
		}
		self::collect($new);
		$out = array('status' => $unchanged ? 'unchanged' : 'imported', 'version' => $ix['version'], 'sequence' => $ix['sequence'], 'generation' => $key['generation'], 'key_id' => $key['id'],
			'from' => $state === null ? null : $state['version'], 'restored' => $unchanged ? $restored : 0, 'platforms' => array_keys($ix['artifacts']));
		// written by import() once served_check has passed, so a refused import leaves only the refusal row
		$info['audit'] = array('engine_imported', $actor_type, $actor, $actor_detail, $ix['version'], array('via' => $via, 'version' => $ix['version'], 'generation' => $key['generation'], 'sequence' => $ix['sequence'], 'key_id' => $key['id'],
			'bundle_sha256' => $info['bundle_sha256'], 'unchanged' => $unchanged, 'restored' => $out['restored'], 'from' => $out['from']));
		return $out;
	}

	// ---- files ----

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
		@fflush($h);
		if(function_exists('fsync'))
		{
			@fsync($h);
		}
		fclose($h);
		if(!@rename($tmp, $dest))
		{
			@unlink($tmp);
			throw self::refuse(phoromatic_catalog_refusal::STORAGE, 'The file ' . basename($dest) . ' could not be put in place.');
		}
		@chmod($dest, 0600);
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
	// Deletes the signed files and archives the state no longer names (one release is kept). Only with the lock held, and only once state.json is in place.
	protected static function collect($state)
	{
		foreach(array('signed/' => array($state['signed_sha256'] . '.signed' => true), 'files/' => array_fill_keys(array_keys($state['files']), true)) as $sub => $keep)
		{
			foreach(scandir(self::dir($sub)) ?: array() as $f)
			{
				if($f !== '.' && $f !== '..' && !isset($keep[$f]) && is_file(self::dir($sub) . $f))
				{
					@unlink(self::dir($sub) . $f);
				}
			}
		}
		$w = self::dir('work/');
		foreach(scandir($w) ?: array() as $f)
		{
			if($f !== '.' && $f !== '..' && is_dir($w . $f) && !is_link($w . $f) && filemtime($w . $f) < time() - 3600)
			{
				self::remove_dir($w . $f . '/');
			}
		}
	}

	// ---- words ----

	public static function import_text($o)
	{
		if($o['status'] === 'unchanged')
		{
			return 'Engine ' . $o['version'] . ' (sequence ' . $o['sequence'] . ') was already imported' . ($o['restored'] > 0 ? '; missing files were put back.' : '; nothing changed.');
		}
		return 'Imported engine ' . $o['version'] . ' (key generation ' . $o['generation'] . ', sequence ' . $o['sequence'] . ') for ' . implode(', ', $o['platforms']) . ($o['from'] !== null ? '. It replaces ' . $o['from'] . '.' : '.') . ' Paired machines are offered it when they next check in.';
	}
}

?>
