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

class phoromatic_private_repo_stage extends phoromatic_private_repo
{
	const TTL = 1800; // 30 minutes
	const MAX_LIVE_PER_USER = 5;
	const MAX_TOTAL_BYTES = 26214400; // 25 MB of staged archives
	const SCRIPT_PREVIEW_BYTES = 65536; // per script
	const SCRIPT_PREVIEW_TOTAL = 524288; // all scripts together
	const SCRIPT_EXTENSIONS = array('sh', 'bash', 'bat', 'cmd', 'ps1', 'psm1', 'py', 'pl', 'rb', 'js', 'vbs');
	const DEFINITION_FILES = array('test-definition.xml', 'suite-definition.xml', 'results-definition.xml', 'downloads.xml');

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
		foreach(self::files_starting_with($dir, '') as $f)
		{
			@unlink($f);
		}
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
		return is_array($meta) && isset($meta['id']) && isset($meta['user_id']) && isset($meta['expires']) && isset($meta['sha256']) ? $meta : null;
	}
	public static function live_stages($account_id, $user_id = null)
	{
		// Metadata of every unexpired stage (of one user if given), newest first
		$stages = array();
		foreach(self::files_starting_with(self::staging_path($account_id), '') as $dir)
		{
			$dir = rtrim($dir, '/') . '/';
			if(!self::valid_stage_id(basename($dir)) || !is_dir($dir) || ($meta = self::read_meta($dir)) === null || $meta['expires'] < time())
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
	public static function sweep($account_id, $expired_callback = null)
	{
		// Lazy cleanup (no cron): expired stages, stages without metadata older than ten minutes, anything older than a day.
		// $expired_callback($meta) lets the caller write an audit row for each expired stage (best effort).
		$removed = 0;
		foreach(self::files_starting_with(self::staging_path($account_id), '') as $dir)
		{
			$dir = rtrim($dir, '/') . '/';
			if(!is_dir($dir) || !self::valid_stage_id(basename($dir)))
			{
				continue;
			}
			$meta = self::read_meta($dir);
			$age = time() - (int)@filemtime($dir);
			if(($meta !== null && $meta['expires'] < time()) || ($meta === null && $age > 600) || $age > 86400)
			{
				if($meta !== null && $expired_callback != null)
				{
					call_user_func($expired_callback, $meta);
				}
				self::remove_dir($dir);
				$removed++;
			}
		}
		return $removed;
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
		$has = array('test' => false, 'suite' => false);
		for($i = 0; $i < $zip->numFiles && $i < self::MAX_ENTRIES + 1; $i++)
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
	public static function name_from_filename($filename)
	{
		// "My-Test-1.2.3.zip" -> "my-test"
		$base = strtolower(preg_replace('/\.zip\z/i', '', basename((string)$filename)));
		$base = preg_replace('/-[0-9]+\.[0-9]+\.[0-9]+\z/', '', $base);
		return preg_replace('/[^a-z0-9-]+/', '-', trim($base, '-'));
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
		$live = self::live_stages($account_id);
		$mine = 0;
		$total = 0;
		foreach($live as $m)
		{
			$mine += (string)$m['user_id'] === (string)$user['UserID'] ? 1 : 0;
			$total += (int)$m['size'];
		}
		if($mine >= self::MAX_LIVE_PER_USER)
		{
			return array('error' => 'You already have ' . self::MAX_LIVE_PER_USER . ' uploads waiting for review. Confirm or cancel one first (they also expire after 30 minutes).');
		}
		if($total + $size > self::MAX_TOTAL_BYTES)
		{
			return array('error' => 'The server is holding too many staged uploads right now (limit ' . (self::MAX_TOTAL_BYTES / 1048576) . ' MB in total). Confirm or cancel some, or try again in a few minutes.');
		}

		$stage_id = bin2hex(random_bytes(16));
		$dir = self::stage_dir($account_id, $stage_id);
		@mkdir(self::staging_path($account_id), 0700, true);
		if(!@mkdir($dir, 0700))
		{
			return array('error' => 'The server could not create its staging area (disk full or not writable?).');
		}
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
		$reject = function($error) use ($dir, $facts, &$finished) {
			self::remove_dir($dir);
			$finished = true;
			return array('error' => $error, 'audit' => $facts, 'rejected' => true);
		};

		$error = null;
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
				$facts = array('sha256' => hash_file('sha256', $zip_file), 'sha1' => sha1_file($zip_file), 'size' => filesize($zip_file));
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
			return $reject('The name "' . preg_replace('/[^ -~]/', '?', substr($name, 0, 60)) . '" is not valid: use lower-case letters, numbers and dashes only (starting with a letter or number, and not ending in something that looks like a version).');
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
			'original_name' => preg_replace('/[^ -~]/', '?', substr((string)$original_name, 0, 120)), 'source_ip' => $source_ip, 'existing' => $existing,
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
		return $meta;
	}
	public static function commit($account_id, $meta, $posted_sha256, $overwrite, $actor, $attempt_callback)
	{
		// $meta comes from get_stage(). Single use: the staged archive is claimed by an atomic rename, so of several parallel
		// confirms exactly one proceeds. The archive is then verified against the checksum recorded at stage time and the
		// posted one, copied, and handed to store_zip(), which inspects it again under the repository lock.
		// Returns store_zip()'s array plus 'stage_error' => true when the stage itself was the problem, 'restored' => true when a
		// failed commit left the stage usable.
		$dir = self::stage_dir($account_id, $meta['id']);
		if(!is_string($posted_sha256) || !hash_equals($meta['sha256'], strtolower($posted_sha256)))
		{
			return array('error' => 'The upload no longer matches what was reviewed; nothing was stored. Please upload it again.', 'stage_error' => true);
		}
		if($meta['existing'] !== null && !$overwrite)
		{
			return array('error' => 'Version ' . $meta['version'] . ' already exists. Tick the overwrite box to replace it, or bump the version in the package.', 'restored' => true);
		}
		$claimed = $dir . 'committing.zip';
		if(!@rename($dir . 'upload.zip', $claimed))
		{
			return array('error' => 'That upload is already being confirmed, or was confirmed or cancelled.', 'stage_error' => true);
		}
		if(!is_file($claimed) || hash_file('sha256', $claimed) !== $meta['sha256'])
		{
			self::remove_dir($dir);
			return array('error' => 'The staged archive no longer matches the checksum recorded when it was reviewed; it was discarded and nothing was stored.', 'stage_error' => true, 'tampered' => true);
		}
		@mkdir(self::repo_path($account_id), 0770, true);
		$tmp = self::repo_path($account_id) . 'upload-' . substr(md5(uniqid('', true)), 0, 12) . '.zip';
		if(!@copy($claimed, $tmp))
		{
			@unlink($tmp);
			@rename($claimed, $dir . 'upload.zip');
			return array('error' => 'The server could not copy the upload into place (disk full or not writable?).', 'restored' => true);
		}
		$result = self::store_zip($account_id, $actor, $meta['kind'], $meta['name'], $tmp, $meta['commit_description'], (bool)$overwrite, 'web', $attempt_callback);
		if(isset($result['error']) && empty($result['stored']))
		{
			@rename($claimed, $dir . 'upload.zip');
			$result['restored'] = true;
		}
		return $result;
	}
	protected static function is_script($path, $head)
	{
		$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
		return in_array($ext, self::SCRIPT_EXTENSIONS, true) || strpos($head, '#!') === 0;
	}
	protected static function script_hints($text)
	{
		// Cheap pattern matches that point a reviewer at lines worth reading. Hints, not a verdict: absence proves nothing.
		$patterns = array(
			'downloads and pipes into a shell' => '/\b(curl|wget)\b[^\n|]*\|\s*(sudo\s+)?(ba|z)?sh\b/i',
			'runs downloaded or encoded code' => '/\b(eval|Invoke-Expression|iex)\b|base64\s+(-d|--decode)|-EncodedCommand|DownloadString|DownloadFile/i',
			'recursive delete' => '/\brm\s+-[a-z]*r[a-z]*f?\s+[\/~$]|Remove-Item[^\n]*-Recurse/i',
			'changes system accounts or services' => '/\b(useradd|usermod|passwd|chpasswd|systemctl|sc\.exe|net\s+user|reg\s+add|schtasks)\b/i',
			'uses sudo or elevated rights' => '/\bsudo\b|RunAs|-Verb\s+RunAs/i');
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
	protected static function build_preview($zip_file, $existing_zip)
	{
		$preview = array('files' => array(), 'scripts' => array(), 'downloads' => array(), 'diff' => null);
		$zip = new ZipArchive();
		if($zip->open($zip_file) !== true)
		{
			return $preview;
		}
		$shown_total = 0;
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
				$preview['files'][] = array('path' => $name, 'size' => null, 'sha256' => null, 'text' => false);
				continue;
			}
			$is_text = strpos(substr($data, 0, 8192), "\0") === false;
			$preview['files'][] = array('path' => $name, 'size' => strlen($data), 'sha256' => hash('sha256', $data), 'text' => $is_text);

			$is_definition = in_array($name, self::DEFINITION_FILES, true);
			if($is_text && ($is_definition || self::is_script($name, $data)))
			{
				$room = max(0, min(self::SCRIPT_PREVIEW_BYTES, self::SCRIPT_PREVIEW_TOTAL - $shown_total));
				$shown = substr($data, 0, $room);
				$shown_total += strlen($shown);
				$preview['scripts'][] = array('path' => $name, 'kind' => $is_definition ? 'definition' : 'script', 'size' => strlen($data), 'sha256' => hash('sha256', $data),
					'text' => $shown, 'hidden_bytes' => strlen($data) - strlen($shown), 'hints' => $is_definition ? array() : self::script_hints($data));
			}
			if($name === 'downloads.xml')
			{
				$preview['downloads'] = self::parse_downloads($data);
			}
		}
		$zip->close();

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
		// One row per package and URL: this is the arbitrary-download-then-run path that the archive contents alone do not show
		$rows = array();
		libxml_use_internal_errors(true);
		$dom = new DOMDocument();
		if(!@$dom->loadXML($xml, LIBXML_NONET))
		{
			libxml_clear_errors();
			return $rows;
		}
		$xpath = new DOMXPath($dom);
		foreach($xpath->query('/PhoronixTestSuite/Downloads/Package') as $package)
		{
			$field = function($tag) use ($xpath, $package) {
				$n = $xpath->query($tag, $package);
				return $n->length > 0 ? trim($n->item(0)->nodeValue) : '';
			};
			foreach(array_filter(array_map('trim', explode(',', $field('URL')))) as $url)
			{
				$rows[] = array('url' => $url, 'md5' => $field('MD5'), 'sha256' => $field('SHA256'), 'size' => $field('FileSize'), 'platform' => $field('PlatformSpecific'), 'file' => $field('FileName'),
					'https' => stripos($url, 'https://') === 0, 'checksum' => $field('MD5') !== '' || $field('SHA256') !== '');
			}
		}
		libxml_clear_errors();
		return $rows;
	}
}

?>
