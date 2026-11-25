<?php

/*
	Benchy
	phoromatic_server_update.php: the signed server release the web page can install (0.16.2, U1: the format, the store and the request; the root helper is deploy/deb-package/benchy-server/benchy-server-update)

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

// A server update bundle, benchy-server-update-X.zip, holds server.signed (a benchy-catalog-sig/1 signature line and a benchy-server-release/1 index,
// signed with the engine key of the key table that ships in the tree) and the two .deb files the index names. This class is the web process's
// side only, and it runs as the service user: it checks a bundle, holds it in the hand-off folder, and writes a request. It never installs anything.
// What it writes is read by the root helper, which trusts none of it: the helper checks the signature again against the installed key table.
//
//   hand-off folder (the service user's, 0750):   held/<sha256 of server.signed>.signed   held/files/<sha256 of a deb>   request.json
//   root's folder (read only for the web):         status.json   last.log
//
// A bundle of the running version is accepted too (it is the way back, should the update fail); an older one is refused.

class phoromatic_server_update
{
	const RELEASE_SCHEMA = 'benchy-server-release/1';
	const REQUEST_SCHEMA = 'benchy-server-update-request/1';
	const PLATFORM = 'linux-deb';
	const DB_SCHEMA = 68;                  // the schema this code migrates a database to; a test makes a fresh database and checks it, and release.sh writes it into the signed index
	const MAX_SIGNED = 65536;
	const MAX_BUNDLE = 67108864;           // 64 MiB, the whole zip
	const MAX_DEB = 67108864;
	const MAX_NOTES = 200;
	const HANDOFF = '/var/lib/phoromatic/server-update/';
	const ROOT_DIR = '/var/lib/benchy-server-update/';
	const STATES_RUNNING = array('preflight', 'backup', 'installing', 'starting', 'checking', 'rolling_back');
	const STATES_FINAL = array('done', 'refused', 'rolled_back', 'rollback_failed');
	const REPO = 'dakotlyar/benchy';

	// Tests only: another hand-off folder, another root folder, another source for GitHub, a stand-in for systemctl
	public static $handoff_override = null;
	public static $root_override = null;
	public static $api_base = 'https://api.github.com';
	public static $download_base = 'https://github.com';
	public static $allow_http = false;
	public static $probe = null;           // function($unit, $what) => the first word systemctl would print, or ''
	public static $running_override = null;

	// ---- places ----

	public static function handoff($sub = '')
	{
		return (self::$handoff_override !== null ? self::$handoff_override : self::HANDOFF) . $sub;
	}
	public static function root_dir($sub = '')
	{
		return (self::$root_override !== null ? self::$root_override : self::ROOT_DIR) . $sub;
	}
	public static function running_version()
	{
		return self::$running_override !== null ? self::$running_override : PTS_VERSION;
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
		return is_string($v) && preg_match('/^(0|[1-9][0-9]{0,8})\.(0|[1-9][0-9]{0,8})\.(0|[1-9][0-9]{0,8})\z/', $v) === 1;
	}
	// -1, 0 or 1; both must be good_version()s
	public static function compare($a, $b)
	{
		$x = array_map('intval', explode('.', $a));
		$y = array_map('intval', explode('.', $b));
		for($i = 0; $i < 3; $i++)
		{
			if($x[$i] !== $y[$i])
			{
				return $x[$i] < $y[$i] ? -1 : 1;
			}
		}
		return 0;
	}

	// ---- the index ----

	// The signed bytes after the signature check: exactly these keys, these types, these two packages. Returns the index as an array.
	public static function parse_release($raw)
	{
		if(!is_string($raw) || strlen($raw) > self::MAX_SIGNED || preg_match('//u', $raw) !== 1)
		{
			throw self::bad('the index is not UTF-8 text of a sensible size');
		}
		try
		{
			$ix = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
		}
		catch(JsonException $e)
		{
			throw self::bad('the index is not JSON: ' . $e->getMessage());
		}
		$keys = array('schema', 'version', 'issued', 'min_generation', 'platform', 'db_schema', 'packages', 'notes');
		if(!is_array($ix) || array_keys($ix) === range(0, count($ix) - 1) || count($ix) !== count($keys) || array_diff($keys, array_keys($ix)) !== array())
		{
			throw self::bad('the index does not have exactly the fields of ' . self::RELEASE_SCHEMA);
		}
		if($ix['schema'] !== self::RELEASE_SCHEMA)
		{
			throw self::bad('the index is ' . json_encode($ix['schema']) . '; this server reads ' . self::RELEASE_SCHEMA);
		}
		if($ix['platform'] !== self::PLATFORM)
		{
			throw self::bad('the index is for ' . json_encode($ix['platform']) . ', not ' . self::PLATFORM);
		}
		if(!self::good_version($ix['version']))
		{
			throw self::bad('the version is not X.Y.Z');
		}
		if(!is_string($ix['issued']) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})\z/', $ix['issued']) !== 1)
		{
			throw self::bad('issued is not an RFC 3339 time');
		}
		if($ix['min_generation'] !== null && (!is_int($ix['min_generation']) || $ix['min_generation'] < 1))
		{
			throw self::bad('min_generation is not an integer');
		}
		if(!is_int($ix['db_schema']) || $ix['db_schema'] < 1 || $ix['db_schema'] > 100000)
		{
			throw self::bad('db_schema is not an integer');
		}
		if(!is_string($ix['notes']) || strlen($ix['notes']) > self::MAX_NOTES || preg_match('/[\p{C}\p{Zl}\p{Zp}]/u', $ix['notes']) === 1)
		{
			throw self::bad('notes is not one short line of printable text');
		}
		if(!is_array($ix['packages']) || count($ix['packages']) !== 2 || array_keys($ix['packages']) !== array(0, 1))
		{
			throw self::bad('packages must list benchy and benchy-server');
		}
		$v = $ix['version'];
		$expect = array('benchy' => 'benchy_' . $v . '_all.deb', 'benchy-server' => 'benchy-server_' . $v . '_all.deb');
		$seen = array();
		foreach($ix['packages'] as $p)
		{
			if(!is_array($p) || count($p) !== 5 || array_diff(array('name', 'version', 'file', 'size', 'sha256'), array_keys((array)$p)) !== array())
			{
				throw self::bad('a package entry does not have exactly name, version, file, size and sha256');
			}
			if(!is_string($p['name']) || !isset($expect[$p['name']]) || isset($seen[$p['name']]))
			{
				throw self::bad('the packages must be benchy and benchy-server, once each');
			}
			if($p['version'] !== $v || $p['file'] !== $expect[$p['name']])
			{
				throw self::bad($p['name'] . ': the version and file name must be ' . $v . ' and ' . $expect[$p['name']]);
			}
			if(!is_int($p['size']) || $p['size'] < 1 || $p['size'] > self::MAX_DEB || !is_string($p['sha256']) || preg_match('/^[0-9a-f]{64}\z/', $p['sha256']) !== 1)
			{
				throw self::bad($p['name'] . ': size or sha256 is not valid');
			}
			$seen[$p['name']] = $p;
		}
		$ix['by_name'] = $seen;
		return $ix;
	}

	// The signature first, then the index. Returns array($index, $key, sha256 of the file). Throws the verifier's refusals.
	public static function check_signed($file)
	{
		if(strlen($file) > self::MAX_SIGNED)
		{
			throw self::refuse(phoromatic_catalog_refusal::LIMIT, 'server.signed is ' . strlen($file) . ' bytes; the limit is ' . self::MAX_SIGNED);
		}
		list($key, $raw) = phoromatic_catalog_verify::engine_signature($file, 0);
		$ix = self::parse_release($raw);
		if($ix['min_generation'] !== null && $ix['min_generation'] > $key['generation'])
		{
			throw self::bad('min_generation ' . $ix['min_generation'] . ' is above the signing key\'s generation ' . $key['generation']);
		}
		return array($ix, $key, hash('sha256', $file));
	}

	// ---- files ----

	protected static function ensure_dirs()
	{
		foreach(array('', 'held/', 'held/files/') as $sub)
		{
			$d = self::handoff($sub);
			if(!is_dir($d) && !@mkdir($d, 0750, true) && !is_dir($d))
			{
				throw self::refuse(phoromatic_catalog_refusal::STORAGE, 'The folder ' . $d . ' could not be made.');
			}
		}
	}
	protected static function write_atomic($path, $bytes)
	{
		$tmp = dirname($path) . '/.' . bin2hex(random_bytes(6)) . '.tmp';
		$h = @fopen($tmp, 'xb');
		if($h === false || @fwrite($h, $bytes) !== strlen($bytes))
		{
			if($h !== false)
			{
				fclose($h);
			}
			@unlink($tmp);
			throw self::refuse(phoromatic_catalog_refusal::STORAGE, 'A file could not be written in ' . dirname($path) . ' (is the disk full?).');
		}
		@fflush($h);
		if(function_exists('fsync'))
		{
			@fsync($h);
		}
		fclose($h);
		@chmod($tmp, 0640);
		if(!@rename($tmp, $path))
		{
			@unlink($tmp);
			throw self::refuse(phoromatic_catalog_refusal::STORAGE, 'A file could not be put in place in ' . dirname($path) . '.');
		}
	}

	// ---- what the server holds ----

	// The verified bundles, newest first: array of array('sha' =>, 'version' =>, 'db_schema' =>, 'issued' =>, 'notes' =>, 'generation' =>, 'index' =>)
	public static function held()
	{
		$out = array();
		foreach((array)glob(self::handoff('held/') . '*.signed') as $path)
		{
			$sha = basename($path, '.signed');
			if(preg_match('/^[0-9a-f]{64}\z/', $sha) !== 1 || is_link($path) || !is_file($path) || filesize($path) > self::MAX_SIGNED)
			{
				continue;
			}
			$file = @file_get_contents($path);
			try
			{
				if($file === false || hash('sha256', $file) !== $sha)
				{
					continue;
				}
				list($ix, $key) = self::check_signed($file);
				foreach($ix['by_name'] as $p)
				{
					$deb = self::handoff('held/files/' . $p['sha256']);
					if(is_link($deb) || !is_file($deb) || filesize($deb) !== $p['size'])
					{
						continue 2;
					}
				}
			}
			catch(phoromatic_catalog_refusal $r)
			{
				continue;
			}
			$out[] = array('sha' => $sha, 'version' => $ix['version'], 'db_schema' => $ix['db_schema'], 'issued' => $ix['issued'], 'notes' => $ix['notes'], 'generation' => $key['generation'], 'index' => $ix);
		}
		usort($out, function($a, $b) { return self::compare($b['version'], $a['version']); });
		return $out;
	}
	public static function find_held($sha)
	{
		if(!is_string($sha) || preg_match('/^[0-9a-f]{64}\z/', $sha) !== 1)
		{
			return null;
		}
		foreach(self::held() as $h)
		{
			if($h['sha'] === $sha)
			{
				return $h;
			}
		}
		return null;
	}
	// The newest held bundle that is newer than the running server, or null
	public static function newer()
	{
		foreach(self::held() as $h)
		{
			if(self::compare($h['version'], self::running_version()) > 0)
			{
				return $h;
			}
		}
		return null;
	}
	public static function has_base()
	{
		foreach(self::held() as $h)
		{
			if($h['version'] === self::running_version())
			{
				return true;
			}
		}
		return false;
	}
	// Drops held bundles older than the running server, and the files nothing names
	public static function prune()
	{
		$lock = @fopen(self::handoff('held/.import.lock'), 'c');
		if($lock === false || !flock($lock, LOCK_EX))
		{
			return;   // (nothing is pruned while an import runs, or when the folder does not exist yet)
		}
		try
		{
			self::prune_locked();
		}
		finally
		{
			flock($lock, LOCK_UN);
			fclose($lock);
		}
	}
	protected static function prune_locked()
	{
		$keep = array();
		foreach((array)glob(self::handoff('held/') . '*.signed') as $path)
		{
			$sha = basename($path, '.signed');
			$h = self::find_held($sha);
			if($h === null || self::compare($h['version'], self::running_version()) < 0)
			{
				@unlink($path);
			}
			else
			{
				foreach($h['index']['by_name'] as $p)
				{
					$keep[$p['sha256']] = true;
				}
			}
		}
		foreach((array)glob(self::handoff('held/files/') . '*') as $f)
		{
			if(!isset($keep[basename($f)]) && !is_dir($f))
			{
				@unlink($f);
			}
		}
	}

	// ---- importing a bundle ----

	// Checks a benchy-server-update-X.zip and holds it. $via is 'web', 'cli' or 'github'. Returns array('version' =>, 'sha' =>, 'unchanged' => bool).
	// Every check passes before anything is held; the refusal and the success are in the server audit.
	public static function import($bundle_path, $via, $actor_type, $actor, $actor_detail = null)
	{
		$info = array('via' => $via, 'bundle_sha256' => null, 'key_id' => null);
		try
		{
			$r = self::attempt($bundle_path, $info);
			if(self::find_held($r['sha']) === null)
			{
				throw phoromatic_store_status::not_served('server update', self::handoff('held/'), 'file_missing');
			}
			self::audit('server_update_imported', $actor_type, $actor, $actor_detail, $r['version'], array('via' => $via, 'version' => $r['version'], 'bundle_sha256' => $info['bundle_sha256'], 'key_id' => $info['key_id'], 'unchanged' => $r['unchanged']));
			return $r;
		}
		catch(phoromatic_catalog_refusal $r)
		{
			self::audit('server_update_import_refused', $actor_type, $actor, $actor_detail, null, array('via' => $via, 'reason' => $r->reason, 'message' => substr($r->getMessage(), 0, 500), 'bundle_sha256' => $info['bundle_sha256'], 'key_id' => $info['key_id']));
			throw $r;
		}
	}
	public static function audit($action, $actor_type, $actor, $actor_detail, $subject, $detail)
	{
		@phoromatic_server_audit::record(null, $actor_type, $actor, $action, array('actor_detail' => $actor_detail, 'subject_type' => 'server_update', 'subject_id' => $subject, 'detail' => $detail));
	}

	protected static function attempt($path, &$info)
	{
		if(!extension_loaded('openssl') || !extension_loaded('zlib'))
		{
			throw self::refuse(phoromatic_catalog_refusal::PRECONDITION, 'PHP needs the openssl and zlib extensions to check a server update.');
		}
		phoromatic_catalog_keys::load_engine();   // (throws key_table while the table is damaged)
		$have = trim((string)ini_get('memory_limit'));
		if($have !== '-1' && (int)$have * (['g' => 1073741824, 'm' => 1048576, 'k' => 1024][strtolower(substr($have, -1))] ?? 1) < 268435456)
		{
			@ini_set('memory_limit', '256M');
		}
		if(!is_file($path) || is_link($path) || !is_readable($path) || ($size = filesize($path)) === false)
		{
			throw self::refuse(phoromatic_catalog_refusal::BUNDLE_UNREADABLE, 'The bundle ' . $path . ' cannot be read.');
		}
		if($size > self::MAX_BUNDLE)
		{
			throw self::refuse(phoromatic_catalog_refusal::LIMIT, 'The bundle is ' . $size . ' bytes; the limit is ' . self::MAX_BUNDLE . '.');
		}
		$data = @file_get_contents($path);
		if($data === false)
		{
			throw self::refuse(phoromatic_catalog_refusal::BUNDLE_UNREADABLE, 'The bundle ' . $path . ' cannot be read.');
		}
		$info['bundle_sha256'] = hash('sha256', $data);
		$zip = phoromatic_catalog_zip::inspect($data, 'components');   // (plain file names only, no folders, deflate or store)
		unset($data);
		$member = $zip->lookup('server.signed');
		if($member === null)
		{
			throw self::refuse(phoromatic_catalog_refusal::BAD_ENVELOPE, 'The bundle has no server.signed.');
		}
		$file = $zip->read_all($member, self::MAX_SIGNED);
		try
		{
			$info['key_id'] = phoromatic_catalog_verify::key_id_of($file);
		}
		catch(phoromatic_catalog_refusal $r)
		{
		}
		list($ix, $key, $sha) = self::check_signed($file);
		$running = self::running_version();
		if(!self::good_version($running))
		{
			throw self::refuse(phoromatic_catalog_refusal::PRECONDITION, 'This server is version ' . $running . ', which is not a release number, so it cannot compare a server update with it.');
		}
		if(self::compare($ix['version'], $running) < 0)
		{
			throw self::refuse(phoromatic_catalog_refusal::DOWNGRADE, 'The bundle is for ' . $ix['version'] . ', older than the ' . $running . ' this server runs. A server is never taken back to an older version from the page.');
		}
		$names = array();
		foreach($ix['by_name'] as $p)
		{
			$names[$p['file']] = $p;
		}
		foreach($zip->files() as $ent)
		{
			if($ent['name'] !== 'server.signed' && !isset($names[$ent['name']]))
			{
				throw self::refuse(phoromatic_catalog_refusal::BUNDLE_PACK_UNLISTED, $ent['name'] . ' is in the bundle but the signed index does not name it.');
			}
		}
		self::ensure_dirs();
		$lock = @fopen(self::handoff('held/.import.lock'), 'c');
		if($lock === false || !flock($lock, LOCK_EX | LOCK_NB))
		{
			if($lock !== false)
			{
				fclose($lock);
			}
			throw self::refuse(phoromatic_catalog_refusal::BUSY, 'Another server update import is running.');
		}
		$staged = array();
		try
		{
			foreach($names as $name => $p)
			{
				$ent = $zip->lookup($name);
				if($ent === null)
				{
					throw self::refuse(phoromatic_catalog_refusal::BUNDLE_PACK_MISSING, $name . ' is named by the signed index but is not in the bundle.');
				}
				if($ent['usize'] !== $p['size'])
				{
					throw self::refuse(phoromatic_catalog_refusal::BUNDLE_PACK_HASH, $name . ' is ' . $ent['usize'] . ' bytes; the signed index says ' . $p['size'] . '.');
				}
				$dest = self::handoff('held/files/.' . bin2hex(random_bytes(6)) . '.part');
				$staged[] = $dest;
				$h = @fopen($dest, 'xb');
				if($h === false)
				{
					throw self::refuse(phoromatic_catalog_refusal::STORAGE, 'A file could not be staged in ' . dirname($dest) . '.');
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
				if(!hash_equals($p['sha256'], hash_final($hash)))
				{
					throw self::refuse(phoromatic_catalog_refusal::BUNDLE_PACK_HASH, $name . ' does not match the sha256 in the signed index.');
				}
				@chmod($dest, 0640);
			}
			$unchanged = is_file(self::handoff('held/' . $sha . '.signed'));
			foreach($names as $name => $p)
			{
				$dest = array_shift($staged);
				if(!@rename($dest, self::handoff('held/files/' . $p['sha256'])))
				{
					throw self::refuse(phoromatic_catalog_refusal::STORAGE, 'A file could not be put in place in ' . self::handoff('held/files/') . '.');
				}
			}
			self::write_atomic(self::handoff('held/' . $sha . '.signed'), $file);   // (last: a bundle is held only when all of it is)
		}
		finally
		{
			foreach($staged as $f)
			{
				@unlink($f);
			}
			flock($lock, LOCK_UN);
			fclose($lock);
		}
		self::prune();
		return array('version' => $ix['version'], 'sha' => $sha, 'unchanged' => $unchanged);
	}
	public static function import_text($r)
	{
		return 'Server update ' . $r['version'] . ($r['unchanged'] ? ' was already held.' : ' is checked and held. Nothing is installed until a server administrator asks for it.');
	}

	// ---- Check GitHub (only when pressed) ----

	protected static function curl($url, $max_bytes, $to_file = null)
	{
		if(preg_match('~^https?://[A-Za-z0-9.:_-]+/[A-Za-z0-9._/-]+\z~', $url) !== 1)
		{
			throw self::refuse(phoromatic_catalog_refusal::UNREACHABLE, 'That address is not one this server fetches from.');
		}
		$proto = self::$allow_http ? '=http,https' : '=https';
		$args = array('curl', '-sS', '--fail', '--location', '--max-redirs', '5', '--proto', $proto, '--proto-redir', $proto, '--max-time', $to_file === null ? '30' : '300',
			'--max-filesize', (string)$max_bytes, '-H', 'User-Agent: benchy-server', '-o', $to_file === null ? '-' : $to_file, '--', $url);
		$p = @proc_open($args, array(0 => array('file', '/dev/null', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
		if(!is_resource($p))
		{
			throw self::refuse(phoromatic_catalog_refusal::UNREACHABLE, 'curl could not be started.');
		}
		$body = stream_get_contents($pipes[1], $max_bytes + 1);
		$err = stream_get_contents($pipes[2], 2000);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$code = proc_close($p);
		if($code !== 0 || ($to_file === null && strlen((string)$body) > $max_bytes))
		{
			throw self::refuse(phoromatic_catalog_refusal::UNREACHABLE, 'GitHub could not be reached or has no such file (' . trim(substr((string)$err, 0, 200)) . ').');
		}
		return (string)$body;
	}
	public static function latest_tag()
	{
		$json = json_decode(self::curl(self::$api_base . '/repos/' . self::REPO . '/releases/latest', 1048576), true, 16);
		$tag = is_array($json) && isset($json['tag_name']) && is_string($json['tag_name']) ? $json['tag_name'] : '';
		if(preg_match('/^v((0|[1-9][0-9]{0,8})\.(0|[1-9][0-9]{0,8})\.(0|[1-9][0-9]{0,8}))\z/', $tag, $m) !== 1)
		{
			throw self::refuse(phoromatic_catalog_refusal::UNREACHABLE, 'GitHub\'s latest release has no usable version.');
		}
		return $m[1];
	}
	protected static function fetch_version($version, $actor_type, $actor, $actor_detail)
	{
		$tmp = tempnam(sys_get_temp_dir(), 'benchy-su-');
		try
		{
			self::curl(self::$download_base . '/' . self::REPO . '/releases/download/v' . $version . '/benchy-server-update-' . $version . '.zip', self::MAX_BUNDLE, $tmp);
			$r = self::import($tmp, 'github', $actor_type, $actor, $actor_detail);
			if($r['version'] !== $version)
			{
				throw self::refuse(phoromatic_catalog_refusal::BAD_INDEX, 'GitHub\'s file for ' . $version . ' is signed for ' . $r['version'] . '.');
			}
			return $r;
		}
		finally
		{
			@unlink($tmp);
		}
	}
	// Fetches the newest release and, if the server holds none, the bundle of the running version (the way back). Returns lines of text for the page.
	public static function check_github($actor_type, $actor, $actor_detail = null)
	{
		$lines = array();
		$latest = self::latest_tag();
		$running = self::running_version();
		if(!self::good_version($running))
		{
			throw self::refuse(phoromatic_catalog_refusal::PRECONDITION, 'This server is version ' . $running . ', which is not a release number.');
		}
		if(self::compare($latest, $running) > 0)
		{
			self::fetch_version($latest, $actor_type, $actor, $actor_detail);
			$lines[] = 'Version ' . $latest . ' is available and held.';
		}
		else
		{
			$lines[] = 'This server runs ' . $running . ', the newest release on GitHub is ' . $latest . '. There is nothing newer.';
		}
		if(!self::has_base())
		{
			try
			{
				self::fetch_version($running, $actor_type, $actor, $actor_detail);
				$lines[] = 'The bundle for the running version ' . $running . ' is held as the way back.';
			}
			catch(phoromatic_catalog_refusal $r)
			{
				$lines[] = 'The bundle for the running version ' . $running . ' could not be fetched (' . $r->getMessage() . ') so an update will be refused until it is uploaded.';
			}
		}
		return $lines;
	}

	// ---- the request ----

	public static function actor_label($actor, $account)
	{
		$s = preg_replace('/[^A-Za-z0-9@._ -]/', '_', (string)$actor . ($account !== '' && $account !== null ? '@' . $account : ''));
		$s = substr($s, 0, 64);
		return $s === '' ? 'unknown' : $s;
	}
	public static function request_bytes($sha, $requested_by)
	{
		return json_encode(array('schema' => self::REQUEST_SCHEMA, 'signed_sha256' => $sha, 'requested_by' => $requested_by, 'requested_at' => gmdate('Y-m-d\TH:i:s\Z')), JSON_UNESCAPED_SLASHES);
	}
	// Writes request.json (atomically) for a held bundle newer than the running server. Throws a refusal; the helper checks everything again.
	public static function request($sha, $requested_by)
	{
		$a = self::availability();
		if(!$a['ok'])
		{
			throw self::refuse(phoromatic_catalog_refusal::NOT_AVAILABLE, $a['why']);
		}
		$h = self::find_held($sha);
		if($h === null)
		{
			throw self::refuse(phoromatic_catalog_refusal::NOT_HELD, 'That update is not held (any more). Upload or fetch it again.');
		}
		if(self::compare($h['version'], self::running_version()) <= 0)
		{
			throw self::refuse(phoromatic_catalog_refusal::DOWNGRADE, 'This server already runs ' . self::running_version() . '; ' . $h['version'] . ' is not newer.');
		}
		if(!self::has_base())
		{
			throw self::refuse(phoromatic_catalog_refusal::NOT_HELD, 'The bundle for the running version ' . self::running_version() . ' (the way back if the update fails) is not held. Press Check GitHub or upload it first.');
		}
		$v = self::view();
		if($v['running'] || $v['state'] === 'queued' || in_array($v['state'], self::STATES_RUNNING, true))
		{
			throw self::refuse(phoromatic_catalog_refusal::NOT_AVAILABLE, 'An update is already waiting or running.');
		}
		$bytes = self::request_bytes($h['sha'], self::actor_label($requested_by, ''));
		$lock = @fopen(self::handoff('held/.request.lock'), 'c');
		if($lock === false || !flock($lock, LOCK_EX))
		{
			throw self::refuse(phoromatic_catalog_refusal::STORAGE, 'The request could not be written.');
		}
		try
		{
			if(file_exists(self::handoff('request.json')))
			{
				throw self::refuse(phoromatic_catalog_refusal::NOT_AVAILABLE, 'An update is already waiting or running.');
			}
			self::write_atomic(self::handoff('request.json'), $bytes);
		}
		finally
		{
			flock($lock, LOCK_UN);
			fclose($lock);
		}
		return $h;
	}

	// ---- what root says ----

	protected static function systemctl($args)
	{
		if(self::$probe !== null)
		{
			return (string)call_user_func(self::$probe, $args);
		}
		$p = @proc_open(array_merge(array('systemctl'), $args), array(0 => array('file', '/dev/null', 'r'), 1 => array('pipe', 'w'), 2 => array('file', '/dev/null', 'w')), $pipes);
		if(!is_resource($p))
		{
			return '';
		}
		$out = trim((string)stream_get_contents($pipes[1], 200));
		fclose($pipes[1]);
		proc_close($p);
		return $out;
	}
	// Whether this install can be updated from the page: the benchy-server package with its path unit enabled, and a hand-off folder this process can write
	public static function availability()
	{
		if(self::systemctl(array('is-enabled', 'benchy-server-update.path')) !== 'enabled')
		{
			return array('ok' => false, 'why' => 'The update service (benchy-server-update.path, from the benchy-server package) is not enabled on this server.');
		}
		if(self::systemctl(array('is-active', 'benchy-server-update.path')) === 'failed' || self::systemctl(array('is-active', 'benchy-server-update.service')) === 'failed')
		{
			// (the units stop after three starts in a minute, so a request that cannot be taken cannot start them over and over)
			return array('ok' => false, 'why' => 'The update service stopped after too many requests in a row. On the server run: sudo systemctl reset-failed benchy-server-update.service benchy-server-update.path && sudo systemctl start benchy-server-update.path');
		}
		if(!is_dir(self::handoff()) || !is_writable(self::handoff()))
		{
			return array('ok' => false, 'why' => 'The hand-off folder ' . self::handoff() . ' is missing or not writable by the server.');
		}
		return array('ok' => true, 'why' => '');
	}
	public static function status()
	{
		$path = self::root_dir('status.json');
		if(is_link($path) || !is_file($path) || filesize($path) > self::MAX_SIGNED)
		{
			return null;
		}
		$j = json_decode((string)@file_get_contents($path), true, 8);
		if(!is_array($j) || !isset($j['state'], $j['run_id']) || !is_string($j['state']) || !is_string($j['run_id']) || preg_match('/^[0-9a-f]{8,64}\z/', $j['run_id']) !== 1
			|| !in_array($j['state'], array_merge(self::STATES_RUNNING, self::STATES_FINAL), true))
		{
			return null;
		}
		foreach(array('from', 'to', 'step', 'message', 'requested_by', 'started', 'finished', 'backup', 'signed_sha256') as $k)
		{
			$j[$k] = isset($j[$k]) && is_string($j[$k]) ? substr($j[$k], 0, 2000) : '';
		}
		return $j;
	}
	// The last lines of root's log, control characters taken out
	public static function log_tail($lines = 40)
	{
		$path = self::root_dir('last.log');
		if(is_link($path) || !is_file($path))
		{
			return array();
		}
		$h = @fopen($path, 'rb');
		if($h === false)
		{
			return array();
		}
		$size = filesize($path);
		fseek($h, max(0, $size - 16384));
		$text = (string)stream_get_contents($h, 16384);
		fclose($h);
		$out = array_slice(explode("\n", rtrim($text, "\n")), -$lines);
		return array_map(function($l) { return substr(preg_replace('/[^\x20-\x7e]/', '?', $l), 0, 300); }, $out);
	}
	// What the page shows: root's status, or queued (a request waits and the service is not running) or interrupted (mid-run state, nothing running)
	public static function view()
	{
		$st = self::status();
		$unit = self::systemctl(array('is-active', 'benchy-server-update.service'));
		$running = $unit === 'active' || $unit === 'activating';
		$waiting = file_exists(self::handoff('request.json'));
		$state = $st === null ? 'none' : $st['state'];
		if($waiting && !$running)
		{
			$state = 'queued';
		}
		else if($st !== null && in_array($st['state'], self::STATES_RUNNING, true) && !$running && !$waiting)
		{
			$state = 'interrupted';
		}
		return array('state' => $state, 'status' => $st, 'running' => $running || $waiting, 'log' => self::log_tail());
	}
	public static function state_text($state, $st = null)
	{
		$words = array('none' => '', 'queued' => 'Waiting for the update service to pick the request up.', 'preflight' => 'Checking the update.', 'backup' => 'Stopping the server and copying the database.',
			'installing' => 'Installing the new version.', 'starting' => 'Starting the new version.', 'checking' => 'Checking that the new version works.', 'rolling_back' => 'Something went wrong. Going back to the previous version.',
			'done' => 'The update is done.', 'refused' => 'The update was refused. Nothing was changed.', 'rolled_back' => 'The update failed and the server was put back as it was.',
			'rollback_failed' => 'The update failed and going back failed too. The commands to put it right are in the log below.',
			'interrupted' => 'The update service stopped in the middle of a run (a reboot, or it was killed). On the server: run sudo dpkg --configure -a, then look at the log below and run benchy-server-setup --check.');
		$text = isset($words[$state]) ? $words[$state] : '';
		if($state === 'interrupted' && $st !== null)
		{
			$text .= ' The previous version\'s packages are in ' . self::ROOT_DIR . 'archive/' . $st['from'] . '/' . ($st['backup'] !== '' ? ' and the copy of the database taken before the update is ' . $st['backup'] : '') . '.';
		}
		return $text;
	}
	// Writes the one audit row for a finished run, the first time any process reads it (a marker file in the hand-off folder holds the run id)
	public static function audit_result_once($st, $actor_type = 'system', $actor = 'server-update')
	{
		if($st === null || !in_array($st['state'], self::STATES_FINAL, true))
		{
			return false;
		}
		$marker = self::handoff('audited-run');
		$h = @fopen($marker, 'c+');
		if($h === false || !flock($h, LOCK_EX))
		{
			return false;
		}
		$done = trim((string)stream_get_contents($h)) === $st['run_id'];
		if(!$done)
		{
			ftruncate($h, 0);
			rewind($h);
			fwrite($h, $st['run_id']);
			fflush($h);
			self::audit('server_update_result', $actor_type, $actor, null, $st['to'], array('run_id' => $st['run_id'], 'state' => $st['state'], 'from' => $st['from'], 'to' => $st['to'], 'requested_by' => $st['requested_by'], 'message' => substr($st['message'], 0, 300), 'backup' => $st['backup']));
		}
		flock($h, LOCK_UN);
		fclose($h);
		return !$done;
	}
}

?>
