<?php

/*
	Benchy
	phoromatic_component_store.php: the signed runtime-component bundle the server holds and offers to paired machines (runtime component updates, R1)

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

// The server's side of benchy-engine's `components.signed` (internal/engupd cindex.go, design 13.2): PHP, the Windows PHP tree, the client, the Cygwin
// set and the VC++ runtime. It is phoromatic_engine_store's sibling, not a rewrite of it: the engine store is shipped and stays as it is, and this
// one reuses everything that isn't specific (the engine key table, the signature check, the zip reader, the refusal words, the audit).
//
// What differs from the engine store: the index lists every component's current release, and a bundle is PARTIAL: components.signed plus only the
// files the server doesn't hold yet. Every file the index names has to be in the bundle or already held, with its size and hash; the bundle may
// hold nothing the index doesn't name. A release only moves forward: no track's version goes down, no component or track disappears, and a
// version is never offered again with other bytes. Storage is <storage>/components/: signed/<sha>.signed, files/<sha> and state.json, written last.
// Nothing is served unless current() has verified it just now. Like the catalog it is a gate, not the trust anchor: every engine checks the same bytes
// again against its own embedded keys, and the server never executes or unpacks any component.

class phoromatic_component_store
{
	const INDEX_SCHEMA = 'benchy-component-index/1';
	const PLATFORM = 'windows-x64';
	const STATE_VERSION = 1;
	const MAX_BUNDLE = 134217728;       // 128 MiB
	const MAX_SIGNED = 65536;
	const MAX_FILE = 268435456;
	const MAX_SEQUENCE = 9007199254740991;
	const LOCK_WAIT_SECONDS = 5;
	const MEMORY = 536870912;
	const KNOWN = array('vcredist', 'cygwin', 'php', 'benchy', 'client');   // install order; an entry with another name is ignored, as the engine ignores it
	const VERSION = '/^(0|[1-9][0-9]{0,8})(\.(0|[1-9][0-9]{0,8})){1,3}(-(0|[1-9][0-9]{0,3}))?\z/';

	// Tests only: where the files go, instead of the server's storage folder
	public static $dir_override = null;

	// ---- the files ----

	public static function dir($sub = '')
	{
		return (self::$dir_override !== null ? self::$dir_override : phoromatic_server::phoromatic_path(false)) . 'components/' . $sub;
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
			if(!is_dir($d) && !@mkdir($d, 0700, true) && !is_dir($d))
			{
				throw self::refuse(phoromatic_catalog_refusal::STORAGE, 'The components folder ' . $d . ' could not be made.');
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

	// ---- versions ----

	public static function good_version($v)
	{
		return is_string($v) && preg_match(self::VERSION, $v) === 1;
	}
	// -1, 0, 1: the numeric fields (missing ones are zero), then the rebuild after a dash
	public static function compare($a, $b)
	{
		$pa = explode('-', $a);
		$pb = explode('-', $b);
		$fa = array_map('intval', explode('.', $pa[0]));
		$fb = array_map('intval', explode('.', $pb[0]));
		for($i = 0; $i < 4; $i++)
		{
			$x = isset($fa[$i]) ? $fa[$i] : 0;
			$y = isset($fb[$i]) ? $fb[$i] : 0;
			if($x !== $y)
			{
				return $x < $y ? -1 : 1;
			}
		}
		$ra = isset($pa[1]) ? (int)$pa[1] : 0;
		$rb = isset($pb[1]) ? (int)$pb[1] : 0;
		return $ra === $rb ? 0 : ($ra < $rb ? -1 : 1);
	}
	// The release's version as the engine compares it: the version, with the rebuild as a suffix
	protected static function full_version($version, $rebuild)
	{
		$dash = strpos($version, '-');
		if($rebuild !== 0)
		{
			if($dash !== false && (int)substr($version, $dash + 1) !== $rebuild)
			{
				throw self::bad('the version ' . $version . ' and the rebuild ' . $rebuild . ' disagree');
			}
			return $dash === false ? $version . '-' . $rebuild : $version;
		}
		return $version;
	}

	// ---- the state ----

	public static function state()
	{
		$path = self::dir('state.json');
		if(!is_file($path))
		{
			return null;
		}
		$s = json_decode((string)@file_get_contents($path), true, 16);
		$ok = is_array($s) && isset($s['state'], $s['generation'], $s['sequence'], $s['retired_below'], $s['signed_sha256'], $s['files'], $s['components'], $s['versions'])
			&& $s['state'] === self::STATE_VERSION && is_int($s['generation']) && is_int($s['sequence']) && is_int($s['retired_below']) && $s['generation'] >= 1 && $s['sequence'] >= 1
			&& is_string($s['signed_sha256']) && preg_match('/^[0-9a-f]{64}\z/', $s['signed_sha256']) === 1 && is_array($s['files']) && is_array($s['components']) && is_array($s['versions']);
		if($ok)
		{
			foreach($s['files'] as $sha => $size)
			{
				$ok = $ok && preg_match('/^[0-9a-f]{64}\z/', (string)$sha) === 1 && is_int($size) && $size > 0;
			}
			foreach($s['versions'] as $name => $tracks)
			{
				$ok = $ok && is_string($name) && is_array($tracks);
				foreach($ok ? $tracks : array() as $t => $v)
				{
					$ok = $ok && is_array($v) && isset($v['version'], $v['sha256']) && self::good_version($v['version']) && preg_match('/^[0-9a-f]{64}\z/', (string)$v['sha256']) === 1;
				}
			}
		}
		if(!$ok)
		{
			throw self::refuse(phoromatic_catalog_refusal::STATE_CORRUPT, 'The stored component state is damaged. Import the bundle again after removing ' . $path . '.');
		}
		return $s;
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

	// One release (a track's, or a flat component's): array(version, file, size, sha256, requires, notes, applier)
	protected static function parse_release($o, $what, &$names)
	{
		$version = self::field($o, 'version', 'string', $what);
		$rebuild = self::field($o, 'rebuild', 'int', $what);
		if(!self::good_version($version) || $rebuild < 0 || $rebuild > 9999)
		{
			throw self::bad($what . ': the version is not two to four numbers, such as 8.5.13');
		}
		$rel = array('version' => self::full_version($version, $rebuild));
		if(!property_exists($o, 'artifact') || !is_object($o->artifact))
		{
			throw self::bad($what . ' has no artifact');
		}
		$a = $o->artifact;
		$rel['file'] = self::field($a, 'file', 'string', $what);
		$rel['size'] = self::field($a, 'size', 'int', $what);
		$rel['sha256'] = self::field($a, 'sha256', 'string', $what);
		if(preg_match('/^[A-Za-z0-9][A-Za-z0-9._+-]{0,127}\z/', $rel['file']) !== 1 || strpos($rel['file'], '..') !== false)
		{
			throw self::bad('the ' . $what . ' artifact name ' . json_encode($rel['file']) . ' is not a plain file name');
		}
		if($rel['size'] < 1 || $rel['size'] > self::MAX_FILE)
		{
			throw self::bad('the ' . $what . ' artifact size is outside 1 to ' . self::MAX_FILE);
		}
		if(preg_match('/^[0-9a-f]{64}\z/', $rel['sha256']) !== 1)
		{
			throw self::bad('the ' . $what . ' artifact hash is not 64 lowercase hex digits');
		}
		$low = strtolower($rel['file']);
		if(isset($names[$low]))
		{
			throw self::bad('the file name ' . $rel['file'] . ' is used twice');
		}
		$names[$low] = true;
		if(property_exists($o, 'upstream') && $o->upstream !== null)
		{
			if(!is_object($o->upstream) || strlen(self::field($o->upstream, 'url', 'string', $what)) > 400 || preg_match('/^[0-9a-f]{64}\z/', self::field($o->upstream, 'sha256', 'string', $what)) !== 1)
			{
				throw self::bad('the ' . $what . ' upstream record is not usable');
			}
		}
		$rel['notes'] = self::field($o, 'notes', 'string', $what);
		if(strlen($rel['notes']) > 4000 || strlen(self::field($o, 'gate', 'string', $what)) > 1000 || strlen(self::field($o, 'authenticode', 'string', $what)) > 200)
		{
			throw self::bad('a ' . $what . ' text field is too long');
		}
		$rel['applier'] = self::field($o, 'applier', 'int', $what);
		if($rel['applier'] < 0 || $rel['applier'] > 1000)
		{
			throw self::bad('the ' . $what . ' applier level is out of range');
		}
		$rel['requires'] = array();
		if(property_exists($o, 'requires') && $o->requires !== null)
		{
			if(!is_object($o->requires) || count(get_object_vars($o->requires)) > 8)
			{
				throw self::bad($what . ' requires more than 8 things, or not a map');
			}
			foreach(get_object_vars($o->requires) as $k => $v)
			{
				if(preg_match('/^[a-z][a-z0-9_-]{0,31}\z/', (string)$k) !== 1 || !self::good_version($v))
				{
					throw self::bad($what . ' requires ' . json_encode((string)$k) . ' with a version that is not usable');
				}
				$rel['requires'][(string)$k] = $v;
			}
		}
		return $rel;
	}

	// The structure checks of benchy-engine engupd.ComponentIndex.Validate, on the bytes the signature covered. Returns the index as an array.
	public static function parse_index($raw)
	{
		$root = phoromatic_catalog_json::decode($raw, 'comp_root', phoromatic_catalog_refusal::BAD_INDEX);
		if(self::field($root, 'schema', 'string', 'the index') !== self::INDEX_SCHEMA)
		{
			throw self::bad('the index schema is not ' . self::INDEX_SCHEMA);
		}
		$ix = array('sequence' => self::field($root, 'sequence', 'int', 'the index'), 'issued' => self::field($root, 'issued', 'string', 'the index'), 'min_generation' => null,
			'platform' => self::field($root, 'platform', 'string', 'the index'), 'components' => array());
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
		if($ix['platform'] === '' || strlen($ix['platform']) > 32)
		{
			throw self::bad('the platform is missing');
		}
		if(!property_exists($root, 'components') || !is_object($root->components) || count(get_object_vars($root->components)) < 1 || count(get_object_vars($root->components)) > 16)
		{
			throw self::bad('the index holds no components, or more than 16');
		}
		$names = array();
		$all = get_object_vars($root->components);
		ksort($all);
		foreach($all as $name => $e)
		{
			$name = (string)$name;
			if(preg_match('/^[a-z][a-z0-9_-]{0,31}\z/', $name) !== 1)
			{
				throw self::bad(json_encode($name) . ' is not a component name');
			}
			if(!in_array($name, self::KNOWN, true))
			{
				continue;
			}
			if(!is_object($e))
			{
				throw self::bad('the ' . $name . ' entry is not an object');
			}
			phoromatic_catalog_json::check_record($e, 'comp_entry', phoromatic_catalog_refusal::BAD_INDEX);
			$ent = array('tracks' => array(), 'default_track' => '', 'revoked' => array(), 'floor' => self::field($e, 'floor', 'string', $name));
			$has_tracks = property_exists($e, 'tracks') && $e->tracks !== null && is_object($e->tracks) && count(get_object_vars($e->tracks)) > 0;
			if($has_tracks && self::field($e, 'version', 'string', $name) !== '')
			{
				throw self::bad($name . ' has both tracks and a flat release');
			}
			if($has_tracks)
			{
				$tracks = get_object_vars($e->tracks);
				if(count($tracks) > 8)
				{
					throw self::bad($name . ' has more than 8 tracks');
				}
				foreach($tracks as $tn => $t)
				{
					$tn = (string)$tn;
					if(preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,15}\z/', $tn) !== 1 || !is_object($t))
					{
						throw self::bad($name . ': ' . json_encode($tn) . ' is not a usable track');
					}
					phoromatic_catalog_json::check_record($t, 'comp_release', phoromatic_catalog_refusal::BAD_INDEX);
					$ent['tracks'][$tn] = self::parse_release($t, $name . ' track ' . $tn, $names);
				}
				$ent['default_track'] = self::field($e, 'default_track', 'string', $name);
				if(($ent['default_track'] === '' && count($tracks) > 1) || ($ent['default_track'] !== '' && !isset($ent['tracks'][$ent['default_track']])))
				{
					throw self::bad($name . ' has no usable default_track');
				}
				if($ent['default_track'] === '')
				{
					$ent['default_track'] = (string)array_key_first($ent['tracks']);
				}
			}
			else if(self::field($e, 'version', 'string', $name) !== '')
			{
				$ent['tracks'][''] = self::parse_release($e, $name, $names);
			}
			else
			{
				throw self::bad($name . ' has neither tracks nor a release');
			}
			$revoked = property_exists($e, 'revoked') && $e->revoked !== null ? $e->revoked : array();
			if(!is_array($revoked) || count($revoked) > 1000)
			{
				throw self::bad($name . ': revoked is not a list of at most 1000 entries');
			}
			foreach($revoked as $r)
			{
				$v = is_object($r) ? self::field($r, 'version', 'string', 'a revocation') : '';
				if(!self::good_version($v) || strlen(self::field($r, 'reason', 'string', 'a revocation')) > 500)
				{
					throw self::bad($name . ' has a revocation with a bad version or a reason over 500 bytes');
				}
				foreach($ent['tracks'] as $rel)
				{
					if(self::compare($rel['version'], $v) === 0)
					{
						throw self::bad($name . ' ' . $v . ' is both offered and revoked');
					}
				}
				$ent['revoked'][] = array('version' => $v, 'reason' => self::field($r, 'reason', 'string', 'a revocation'));
			}
			if($ent['floor'] !== '')
			{
				if(!self::good_version($ent['floor']))
				{
					throw self::bad('the ' . $name . ' floor is not a version');
				}
				foreach($ent['tracks'] as $rel)
				{
					if(self::compare($rel['version'], $ent['floor']) < 0)
					{
						throw self::bad($name . ' ' . $rel['version'] . ' is below its floor ' . $ent['floor']);
					}
				}
			}
			$ix['components'][$name] = $ent;
		}
		return $ix;
	}

	// Every file the index names, by hash: sha => array(file, size)
	protected static function named_files($ix)
	{
		$files = array();
		foreach($ix['components'] as $ent)
		{
			foreach($ent['tracks'] as $rel)
			{
				$files[$rel['sha256']] = array('file' => $rel['file'], 'size' => $rel['size']);
			}
		}
		return $files;
	}

	// ---- what the server offers now, verified now ----

	// The index the server serves, or null (and $why says in a word, as phoromatic_engine_store::current does). Everything a route or the page hands out comes
	// from here, so nothing unverified is ever offered.
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
			foreach(self::named_files($ix) as $sha => $f)
			{
				if(!isset($state['files'][$sha]) || $state['files'][$sha] !== $f['size'])
				{
					$why = 'file_missing';
					return null;
				}
			}
			$why = null;
			return array('sequence' => $ix['sequence'], 'generation' => $state['generation'], 'key_id' => $key['id'], 'issued' => $ix['issued'], 'platform' => $ix['platform'], 'components' => $ix['components'],
				'floors' => $state['components'], 'signed_sha256' => $state['signed_sha256'], 'signed' => $file, 'files' => $state['files'], 'retired_below' => $state['retired_below'],
				'imported_on' => isset($state['imported_on']) ? $state['imported_on'] : null, 'imported_by' => isset($state['imported_by']) ? $state['imported_by'] : null);
		}
		catch(phoromatic_catalog_refusal $r)
		{
			$why = $r->reason;
			return null;
		}
	}

	// The size of a file the server may serve by this hash, or null
	public static function served_size($sha)
	{
		$c = self::current();
		return $c !== null && isset($c['files'][$sha]) ? $c['files'][$sha] : null;
	}

	// The rows of the Updates page, one per component the server holds, in install order: array(name, tracks => array(track => version, ...), default_track, notes,
	// requires, revoked (versions withdrawn so far, including earlier indexes'), floor). Empty when nothing verifies.
	public static function rows()
	{
		$c = self::current();
		$rows = array();
		if($c === null)
		{
			return $rows;
		}
		foreach(self::KNOWN as $name)
		{
			if(!isset($c['components'][$name]))
			{
				continue;
			}
			$e = $c['components'][$name];
			$def = $e['default_track'] !== '' || count($e['tracks']) === 1 ? ($e['default_track'] !== '' ? $e['default_track'] : (string)array_key_first($e['tracks'])) : '';
			$tracks = array();
			foreach($e['tracks'] as $tn => $rel)
			{
				$tracks[$tn] = array('version' => $rel['version'], 'size' => $rel['size'], 'file' => $rel['file'], 'requires' => $rel['requires'], 'notes' => $rel['notes'], 'applier' => $rel['applier']);
			}
			$seen = isset($c['floors'][$name]) && is_array($c['floors'][$name]) ? $c['floors'][$name] : array();
			$rows[] = array('name' => $name, 'tracks' => $tracks, 'default_track' => $def, 'sequence' => $c['sequence'],
				'revoked' => isset($seen['revoked']) && is_array($seen['revoked']) ? $seen['revoked'] : array(), 'floor' => isset($seen['floor']) ? (string)$seen['floor'] : '');
		}
		return $rows;
	}

	// ---- importing ----

	// $via is cli or web; returns the outcome array (status imported or unchanged); throws phoromatic_catalog_refusal with its audit row written.
	public static function import($bundle_path, $via, $actor_type, $actor, $actor_detail = null, $account = null)
	{
		$info = array('via' => $via, 'bundle_sha256' => null, 'key_id' => null);
		try
		{
			return self::attempt($bundle_path, $via, $actor_type, $actor, $actor_detail, $account, $info);
		}
		catch(phoromatic_catalog_refusal $r)
		{
			self::audit('components_import_refused', $actor_type, $actor, $actor_detail, null, array('via' => $via, 'reason' => $r->reason, 'message' => substr($r->getMessage(), 0, 500), 'bundle_sha256' => $info['bundle_sha256'], 'key_id' => $info['key_id']));
			throw $r;
		}
	}
	protected static function audit($action, $actor_type, $actor, $actor_detail, $subject, $detail)
	{
		@phoromatic_server_audit::record(null, $actor_type, $actor, $action, array('actor_detail' => $actor_detail, 'subject_type' => 'components', 'subject_id' => $subject, 'detail' => $detail));
	}

	protected static function attempt($path, $via, $actor_type, $actor, $actor_detail, $account, &$info)
	{
		if(($problem = phoromatic_catalog_import::this_php_problem()) !== null)
		{
			throw self::refuse(phoromatic_catalog_refusal::PRECONDITION, str_replace('the catalog', 'the component bundle', $problem));
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
			throw self::refuse(phoromatic_catalog_refusal::STORAGE, 'The components lock file could not be opened.');
		}
		@chmod(self::dir('import.lock'), 0600);
		for($i = 0; $i < self::LOCK_WAIT_SECONDS * 10 && !($locked = flock($lock, LOCK_EX | LOCK_NB)); $i++)
		{
			usleep(100000);
		}
		if(empty($locked))
		{
			fclose($lock);
			throw self::refuse(phoromatic_catalog_refusal::BUSY, 'Another component import is running.');
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
		$zip = phoromatic_catalog_zip::inspect($bundle, 'components');
		$member = $zip->lookup('components.signed');
		if($member === null)
		{
			throw self::refuse(phoromatic_catalog_refusal::BAD_ENVELOPE, 'The bundle has no components.signed.');
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
		if($ix['platform'] !== self::PLATFORM)
		{
			throw self::bad('the index is for ' . $ix['platform'] . '; this server holds ' . self::PLATFORM . ' components');
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
			if(!$unchanged)
			{
				self::forward_only($state, $ix);
			}
		}
		// Step 2: the bundle holds nothing the index doesn't name, each file in it matches its size and hash, and every file the index names is in it or already held
		$named = self::named_files($ix);
		$by_name = array();
		foreach($named as $fsha => $f)
		{
			$by_name[$f['file']] = $fsha;
		}
		foreach($zip->files() as $ent)
		{
			if($ent['name'] !== 'components.signed' && !isset($by_name[$ent['name']]))
			{
				throw self::refuse(phoromatic_catalog_refusal::BUNDLE_PACK_UNLISTED, $ent['name'] . ' is in the bundle but the signed index does not name it.');
			}
		}
		$staged = array();
		foreach($named as $fsha => $f)
		{
			$ent = $zip->lookup($f['file']);
			if($ent === null)
			{
				$st = @stat(self::file_path($fsha));
				if($state === null || !isset($state['files'][$fsha]) || $st === false || $st['size'] !== $f['size'])
				{
					throw self::refuse(phoromatic_catalog_refusal::BUNDLE_PACK_MISSING, $f['file'] . ' is named by the signed index but is neither in the bundle nor already held by this server. Import the bundle that carries it first.');
				}
				continue;
			}
			if($ent['usize'] !== $f['size'])
			{
				throw self::refuse(phoromatic_catalog_refusal::BUNDLE_PACK_HASH, $f['file'] . ' is ' . $ent['usize'] . ' bytes; the signed index says ' . $f['size'] . '.');
			}
			$dest = $work . $fsha . '.part';
			$h = @fopen($dest, 'wb');
			if($h === false)
			{
				throw self::refuse(phoromatic_catalog_refusal::STORAGE, 'A file could not be staged in ' . $work . ' (is the disk full?).');
			}
			$hash = hash_init('sha256');
			try
			{
				$zip->stream($ent, function($chunk) use ($h, $hash)
				{
					hash_update($hash, $chunk);
					if(@fwrite($h, $chunk) !== strlen($chunk))
					{
						throw self::refuse(phoromatic_catalog_refusal::STORAGE, 'A file could not be staged (is the disk full?).');
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
			if(!hash_equals($fsha, hash_final($hash)))
			{
				throw self::refuse(phoromatic_catalog_refusal::BUNDLE_PACK_HASH, $f['file'] . ' does not match the hash in the signed index.');
			}
			$staged[$fsha] = $dest;
		}
		unset($bundle, $zip);
		// Step 3: store. The files and the signed file first, state.json last: until it moves, the old index is what is served.
		$files = array();
		foreach($named as $fsha => $f)
		{
			$files[$fsha] = $f['size'];
		}
		$added = 0;
		foreach($staged as $fsha => $dest)
		{
			$st = @stat(self::file_path($fsha));
			if($st !== false && $st['size'] === $files[$fsha])
			{
				continue;
			}
			if(!@rename($dest, self::file_path($fsha)))
			{
				throw self::refuse(phoromatic_catalog_refusal::STORAGE, 'A file could not be put in place.');
			}
			@chmod(self::file_path($fsha), 0600);
			$added++;
		}
		if(!is_file(self::signed_path($sha)) || @file_get_contents(self::signed_path($sha)) !== $file)
		{
			self::write_atomic(self::signed_path($sha), $file);
		}
		$now = phoromatic_server::current_time();
		$comp = $state === null ? array() : $state['components'];
		$versions = array();
		foreach($ix['components'] as $name => $ent)
		{
			$seen = isset($comp[$name]) ? $comp[$name] : array('revoked' => array(), 'floor' => '');
			foreach($ent['revoked'] as $r)
			{
				if(!in_array($r['version'], $seen['revoked'], true))
				{
					$seen['revoked'][] = $r['version'];
				}
			}
			usort($seen['revoked'], array(__CLASS__, 'compare'));
			if($ent['floor'] !== '' && ($seen['floor'] === '' || self::compare($ent['floor'], $seen['floor']) > 0))
			{
				$seen['floor'] = $ent['floor'];
			}
			$comp[$name] = $seen;
			foreach($ent['tracks'] as $tn => $rel)
			{
				$versions[$name][$tn] = array('version' => $rel['version'], 'sha256' => $rel['sha256']);
			}
		}
		if($unchanged)
		{
			$new = $state;   // the same index: only missing files may have been put back
		}
		else
		{
			$new = array('state' => self::STATE_VERSION, 'generation' => $key['generation'], 'sequence' => $ix['sequence'], 'signed_sha256' => $sha, 'key_id' => $key['id'],
				'retired_below' => max($state === null ? 0 : $state['retired_below'], $ix['min_generation'] === null ? 0 : $ix['min_generation']), 'issued' => $ix['issued'],
				'files' => $files, 'components' => $comp, 'versions' => $versions, 'imported_on' => $now, 'imported_by' => (string)$actor, 'via' => $via, 'bundle_sha256' => $info['bundle_sha256'], 'account' => $account);
			self::write_atomic(self::dir('state.json'), json_encode($new, JSON_UNESCAPED_SLASHES) . "\n");
		}
		self::collect($new);
		$out = array('status' => $unchanged ? 'unchanged' : 'imported', 'sequence' => $ix['sequence'], 'generation' => $key['generation'], 'key_id' => $key['id'], 'added' => $added,
			'components' => array_keys($ix['components']), 'changed' => self::changed($state, $ix));
		self::audit('components_imported', $actor_type, $actor, $actor_detail, (string)$ix['sequence'], array('via' => $via, 'generation' => $key['generation'], 'sequence' => $ix['sequence'], 'key_id' => $key['id'],
			'bundle_sha256' => $info['bundle_sha256'], 'unchanged' => $unchanged, 'added' => $added, 'changed' => $out['changed']));
		return $out;
	}

	// The release names that differ from the stored ones, as "php 8.5.14", for the outcome and the audit row
	protected static function changed($state, $ix)
	{
		$out = array();
		foreach($ix['components'] as $name => $ent)
		{
			foreach($ent['tracks'] as $tn => $rel)
			{
				if($state === null || !isset($state['versions'][$name][$tn]) || $state['versions'][$name][$tn]['sha256'] !== $rel['sha256'])
				{
					$out[] = $name . ' ' . $rel['version'];
				}
			}
		}
		return $out;
	}

	// A newer index may not take anything back: no component or track disappears, no track's version goes down, and a version never comes again with other bytes
	protected static function forward_only($state, $ix)
	{
		foreach($state['versions'] as $name => $tracks)
		{
			if(!in_array($name, self::KNOWN, true))
			{
				continue;
			}
			foreach($tracks as $tn => $old)
			{
				if(!isset($ix['components'][$name]['tracks'][$tn]))
				{
					throw self::refuse(phoromatic_catalog_refusal::BAD_INDEX, 'The bundle drops ' . $name . ($tn !== '' ? ' track ' . $tn : '') . ', which this server offers. A release never takes a component away.');
				}
				$new = $ix['components'][$name]['tracks'][$tn];
				$c = self::compare($new['version'], $old['version']);
				if($c < 0)
				{
					throw self::refuse(phoromatic_catalog_refusal::BAD_INDEX, 'The bundle offers ' . $name . ' ' . $new['version'] . ', below the ' . $old['version'] . ' this server offers. A release never goes down.');
				}
				if($c === 0 && $new['sha256'] !== $old['sha256'])
				{
					throw self::refuse(phoromatic_catalog_refusal::BAD_INDEX, 'The bundle offers ' . $name . ' ' . $new['version'] . ' with different bytes than the one this server offers. A release is never replaced under the same version.');
				}
			}
		}
		foreach($state['components'] as $name => $seen)
		{
			if(!isset($ix['components'][$name]))
			{
				continue;
			}
			foreach($seen['revoked'] as $v)
			{
				$held = false;
				foreach($ix['components'][$name]['revoked'] as $r)
				{
					$held = $held || $r['version'] === $v;
				}
				if(!$held)
				{
					throw self::refuse(phoromatic_catalog_refusal::REVOCATION_DROPPED, 'The bundle no longer lists ' . $name . ' ' . $v . ' as revoked. A revocation is never taken back.');
				}
			}
			if($seen['floor'] !== '' && ($ix['components'][$name]['floor'] === '' || self::compare($ix['components'][$name]['floor'], $seen['floor']) < 0))
			{
				throw self::refuse(phoromatic_catalog_refusal::FLOOR, 'The bundle lowers the ' . $name . ' floor below ' . $seen['floor'] . '.');
			}
		}
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
	// Deletes the signed files and files the state no longer names. Only with the lock held, and only once state.json is in place.
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
		$what = empty($o['changed']) ? 'nothing new' : implode(', ', $o['changed']);
		if($o['status'] === 'unchanged')
		{
			return 'The component index (sequence ' . $o['sequence'] . ') was already imported' . ($o['added'] > 0 ? '; missing files were put back.' : '; nothing changed.');
		}
		return 'Imported the component index (key generation ' . $o['generation'] . ', sequence ' . $o['sequence'] . '): ' . $what . '. Paired machines are offered it when they next check in.';
	}
}

?>
