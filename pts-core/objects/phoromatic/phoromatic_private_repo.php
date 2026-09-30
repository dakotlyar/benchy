<?php

/*
	Benchy
	Private test profile / test suite repository, served by the Phoromatic Server.

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

// Storage layout, under phoromatic_account_path($aid) . 'private-repo/':
//   tests/<name>/<version>.zip + .json, suites/<name>/<version>.zip + .json
//   index.json (OB-format, derived from the above), trash/, .lock

class phoromatic_private_repo
{
	const MAX_ZIP_BYTES = 5242880; // 5 MB
	const MAX_UNCOMPRESSED_BYTES = 26214400; // 25 MB
	const MAX_ENTRIES = 500;

	public static function configured_name()
	{
		// Empty falls back to the default name; use "off" to disable
		return pts_config::read_user_config('PhoronixTestSuite/Options/OpenBenchmarking/PrivateRepository', 'private');
	}
	public static function reserved_names()
	{
		// Names that must never be treated as OpenBenchmarking.org repositories nor served by the public mirror,
		// whether or not the feature is currently enabled (a leftover index may still be on disk)
		$names = array_unique(array_filter(array('private', self::configured_name(), self::repository_name())));
		// An invalid configured value such as "pts" must not hide the official repository of that name
		return array_values(array_diff($names, array('local', 'pts', 'system', 'git', 'windows'), pts_openbenchmarking::official_repositories()));
	}
	public static function repository_name()
	{
		// Set the option to "off" to disable the private repository. Names that could shadow an official
		// repository, or that are not valid repository names, also disable it rather than risk clobbering those.
		$name = self::configured_name();

		if(!self::valid_name($name) || in_array($name, array('local', 'pts', 'system', 'git', 'windows', 'off')) || in_array($name, pts_openbenchmarking::official_repositories()))
		{
			return false;
		}

		return $name;
	}
	public static function is_private_repository($repo_name)
	{
		$private_repo = self::repository_name();
		return $private_repo != false && $repo_name === $private_repo;
	}
	public static function valid_name($name)
	{
		// Names ending in -NNN.. look like a version suffix to name resolution, so foo-1.0.0 could be read as foo
		return is_string($name) && preg_match('/^[a-z0-9][a-z0-9-]*\z/', $name) === 1 && preg_match('/-[0-9.]{3,8}\z/', $name) !== 1;
	}
	public static function valid_version($version)
	{
		// x.y.z, at most 8 characters (the client's name/version split ignores longer suffixes)
		return is_string($version) && strlen($version) <= 8 && preg_match('/^[0-9]+\.[0-9]+\.[0-9]+\z/', $version) === 1;
	}
	public static function valid_kind($kind)
	{
		return $kind == 'test' || $kind == 'suite';
	}
	public static function repo_path($account_id)
	{
		return phoromatic_server::phoromatic_account_path($account_id) . 'private-repo/';
	}
	public static function server_account_id()
	{
		// Used where no Phoromatic request/session context exists (the test/suite listing on the server).
		// Single-user setup: the first (oldest) account on this server is the one that owns the repository.
		static $account_id = null;

		if(defined('ACCOUNT_ID'))
		{
			return ACCOUNT_ID;
		}

		if($account_id === null)
		{
			phoromatic_server::prepare_database();
			$account_id = phoromatic_server::$db->querySingle('SELECT AccountID FROM phoromatic_accounts ORDER BY CreatedOn ASC LIMIT 1');
			$account_id = !empty($account_id) ? $account_id : false;
		}
		return $account_id;
	}
	protected static function item_dir($account_id, $kind, $name)
	{
		return self::repo_path($account_id) . $kind . 's/' . $name . '/';
	}
	protected static function with_lock($account_id, $callback)
	{
		pts_file_io::mkdir(self::repo_path($account_id));
		$lock = fopen(self::repo_path($account_id) . '.lock', 'c');
		if($lock == false || !flock($lock, LOCK_EX))
		{
			return array('error' => 'Unable to lock the private repository.');
		}
		$ret = call_user_func($callback);
		flock($lock, LOCK_UN);
		fclose($lock);
		return $ret;
	}
	protected static function xml_value(&$dom, $xpath_query)
	{
		$xpath = new DOMXPath($dom);
		$nodes = $xpath->query($xpath_query);
		return $nodes->length > 0 ? trim($nodes->item(0)->nodeValue) : '';
	}
	protected static function inspect_zip($zip_file, $kind, &$error, &$warnings, &$needs_rewrite = false)
	{
		// Checks the archive for zip-slip / zip-bomb / symlinks, validates the definition XML, returns the parsed info
		$definition_file = $kind == 'test' ? 'test-definition.xml' : 'suite-definition.xml';
		$xsd = pts_openbenchmarking::openbenchmarking_standards_path() . 'schemas/' . ($kind == 'test' ? 'test-profile.xsd' : 'test-suite.xsd');

		if(!class_exists('ZipArchive'))
		{
			$error = 'The server lacks PHP ZIP support.';
			return false;
		}

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

		$total_size = 0;
		$has_definition = false;
		for($i = 0; $i < $zip->numFiles; $i++)
		{
			$stat = $zip->statIndex($i);
			$entry = $stat['name'];

			if(strpos($entry, '\\') !== false)
			{
				// Zips made on Windows can carry backslashes; treat them as separators and rewrite the archive afterwards
				$needs_rewrite = true;
				$entry = str_replace('\\', '/', $entry);
			}

			if($entry === '' || $entry[0] == '/' || strpos($entry, ':') !== false || strpos($entry, "\0") !== false || in_array('..', explode('/', $entry)))
			{
				$zip->close();
				$error = 'The archive contains an unsafe path: ' . preg_replace('/[^ -~]/', '?', $entry);
				return false;
			}

			$opsys = null;
			$attr = null;
			if($zip->getExternalAttributesIndex($i, $opsys, $attr) && $opsys == ZipArchive::OPSYS_UNIX && ((($attr >> 16) & 0170000) == 0120000))
			{
				$zip->close();
				$error = 'The archive contains a symbolic link: ' . $entry;
				return false;
			}

			$total_size += $stat['size'];
			if($total_size > self::MAX_UNCOMPRESSED_BYTES)
			{
				$zip->close();
				$error = 'The archive expands to more than ' . (self::MAX_UNCOMPRESSED_BYTES / 1048576) . ' MB.';
				return false;
			}

			if($entry == $definition_file)
			{
				$has_definition = true;
			}
			else if(substr($entry, -3) == '.sh' && $stat['size'] > 0 && strpos($zip->getFromIndex($i), "\r\n") !== false)
			{
				$zip->close();
				$error = $entry . ' has Windows (CRLF) line endings, which break sh. Convert it to LF.';
				return false;
			}
		}

		if(!$has_definition)
		{
			$zip->close();
			$error = 'The archive has no ' . $definition_file . ' at its root.';
			return false;
		}

		$xml = $zip->getFromName($definition_file);
		$zip->close();

		if($xml === false || trim($xml) === '')
		{
			$error = $definition_file . ' is empty.';
			return false;
		}

		libxml_use_internal_errors(true);
		libxml_clear_errors();
		$dom = new DOMDocument();
		if(!$dom->loadXML($xml, LIBXML_NONET) || !$dom->schemaValidate($xsd))
		{
			$messages = array();
			foreach(libxml_get_errors() as $e)
			{
				$messages[] = trim($e->message);
			}
			libxml_clear_errors();
			$error = $definition_file . ' failed schema validation: ' . implode('; ', array_slice($messages, 0, 3));
			return false;
		}

		$info = array();
		if($kind == 'test')
		{
			$info['version'] = self::xml_value($dom, '/PhoronixTestSuite/TestProfile/Version');
			$info['title'] = self::xml_value($dom, '/PhoronixTestSuite/TestInformation/Title');
			$info['description'] = self::xml_value($dom, '/PhoronixTestSuite/TestInformation/Description');
			$info['test_type'] = self::xml_value($dom, '/PhoronixTestSuite/TestProfile/TestType');
			$info['software_type'] = self::xml_value($dom, '/PhoronixTestSuite/TestProfile/SoftwareType');
			$info['status'] = self::xml_value($dom, '/PhoronixTestSuite/TestProfile/Status');
			$info['supported_platforms'] = array_values(array_filter(array_map('trim', explode(',', self::xml_value($dom, '/PhoronixTestSuite/TestProfile/SupportedPlatforms')))));
			$info['internal_tags'] = array_values(array_filter(array_map('trim', explode(',', self::xml_value($dom, '/PhoronixTestSuite/TestProfile/InternalTags')))));
			$info['test_extends'] = self::xml_value($dom, '/PhoronixTestSuite/TestProfile/Extends');
		}
		else
		{
			$info['version'] = self::xml_value($dom, '/PhoronixTestSuite/SuiteInformation/Version');
			$info['title'] = self::xml_value($dom, '/PhoronixTestSuite/SuiteInformation/Title');
			$info['description'] = self::xml_value($dom, '/PhoronixTestSuite/SuiteInformation/Description');
			$info['test_type'] = self::xml_value($dom, '/PhoronixTestSuite/SuiteInformation/TestType');

			// Referenced tests that cannot be found are a warning rather than an error
			$xpath = new DOMXPath($dom);
			foreach($xpath->query('/PhoronixTestSuite/Execute/Test') as $node)
			{
				$ref = trim($node->nodeValue);
				if(!self::reference_resolves($ref))
				{
					$warnings[] = 'Suite references ' . $ref . ' which is ' . (strpos($ref, 'local/') === 0 ? 'a local test that other systems cannot fetch.' : 'not in the private repository or a cached official repository.');
				}
			}
		}

		if(!self::valid_version($info['version']))
		{
			$error = 'The ' . $kind . ' version "' . $info['version'] . '" must look like x.y.z (numeric).';
			return false;
		}

		return $info;
	}
	protected static function rewrite_zip_with_forward_slashes($zip_file)
	{
		$in = new ZipArchive();
		$out = new ZipArchive();
		$new_file = $zip_file . '.fixed';
		if($in->open($zip_file) !== true || $out->open($new_file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true)
		{
			return false;
		}

		for($i = 0; $i < $in->numFiles; $i++)
		{
			$name = str_replace('\\', '/', $in->getNameIndex($i));
			if(substr($name, -1) == '/')
			{
				$out->addEmptyDir(rtrim($name, '/'));
			}
			else
			{
				$out->addFromString($name, $in->getFromIndex($i));
			}
		}
		$in->close();

		return $out->close() && rename($new_file, $zip_file);
	}
	protected static function reference_resolves($ref)
	{
		if(strpos($ref, '/') === false)
		{
			return true;
		}
		$repo = substr($ref, 0, strpos($ref, '/'));
		$test = substr($ref, strpos($ref, '/') + 1);
		if(($c = strrpos($test, '-')) !== false && preg_match('/^[0-9.]+$/', substr($test, $c + 1)))
		{
			$test = substr($test, 0, $c);
		}
		if($repo == 'local')
		{
			// Other machines cannot fetch local/ tests, so a suite depending on one will not install
			return false;
		}
		$index = $repo == self::repository_name() ? self::read_index(self::server_account_id()) : pts_openbenchmarking::read_repository_index($repo);
		return is_array($index) && (isset($index['tests'][$test]) || isset($index['suites'][$test]));
	}
	public static function upload($account_id, $system_id, $kind, $name, $zip_base64, $zip_sha1, $commit_description, $overwrite = false)
	{
		// Returns array('upload' => ...) on success or array('error' => ...)
		if(self::repository_name() == false)
		{
			return array('error' => 'The private repository is disabled on this server.');
		}
		if(!is_string($kind) || !is_string($zip_base64) || !is_string($zip_sha1) || !is_string($commit_description))
		{
			return array('error' => 'Malformed request.');
		}
		if(!self::valid_kind($kind))
		{
			return array('error' => 'Invalid kind; expected test or suite.');
		}
		if(!self::valid_name($name))
		{
			return array('error' => 'Invalid name; use lower-case letters, numbers and dashes only (and start with a letter or number).');
		}
		if(empty($zip_base64) || strlen($zip_base64) > (self::MAX_ZIP_BYTES * 4 / 3) + 16)
		{
			return array('error' => 'The upload is missing or larger than ' . (self::MAX_ZIP_BYTES / 1048576) . ' MB. Larger payloads should be referenced through downloads.xml instead.');
		}
		$zip = base64_decode($zip_base64, true);
		if($zip === false || $zip === '' || strlen($zip) > self::MAX_ZIP_BYTES)
		{
			return array('error' => 'The upload is corrupt or larger than ' . (self::MAX_ZIP_BYTES / 1048576) . ' MB.');
		}
		if(sha1($zip) != $zip_sha1)
		{
			return array('error' => 'SHA1 mismatch; the upload was corrupted in transit.');
		}

		return self::with_lock($account_id, function() use ($account_id, $system_id, $kind, $name, $zip, $commit_description, $overwrite) {
			$tmp_file = self::repo_path($account_id) . 'upload-' . substr(md5(uniqid('', true)), 0, 12) . '.zip';
			file_put_contents($tmp_file, $zip);

			$error = null;
			$warnings = array();
			$needs_rewrite = false;
			try
			{
				$info = self::inspect_zip($tmp_file, $kind, $error, $warnings, $needs_rewrite);
			}
			catch(Throwable $e)
			{
				$info = false;
				$error = 'The package could not be parsed.';
			}
			if($info !== false && $needs_rewrite)
			{
				// Backslash entry names were accepted as separators; store a clean archive and inspect that one
				if(!self::rewrite_zip_with_forward_slashes($tmp_file))
				{
					unlink($tmp_file);
					return array('error' => 'Unable to normalize the archive paths.');
				}
				$warnings = array();
				$needs_rewrite = false;
				$info = self::inspect_zip($tmp_file, $kind, $error, $warnings, $needs_rewrite);
				$zip = file_get_contents($tmp_file);
			}
			if($info === false)
			{
				unlink($tmp_file);
				return array('error' => $error);
			}

			$version = $info['version'];
			$dir = self::item_dir($account_id, $kind, $name);
			$zip_dest = $dir . $version . '.zip';
			$replaced = is_file($zip_dest);

			if($replaced)
			{
				if(!$overwrite)
				{
					unlink($tmp_file);
					return array('error' => $kind . ' ' . $name . '-' . $version . ' already exists; bump the version (versions are immutable) or re-upload with overwrite.');
				}

				if(!self::move_to_trash($account_id, $kind, $name, $version))
				{
					unlink($tmp_file);
					return array('error' => 'Unable to move the replaced version to the trash directory.');
				}
			}

			pts_file_io::mkdir($dir);
			if(!rename($tmp_file, $zip_dest))
			{
				unlink($tmp_file);
				return array('error' => 'Unable to store the package on the server.');
			}
			$meta = array(
				'sha1' => sha1($zip),
				'size' => strlen($zip),
				'uploaded' => time(),
				'system_id' => $system_id,
				'commit_description' => $commit_description,
				'title' => $info['title'],
				'info' => $info
				);
			file_put_contents($dir . $version . '.json', json_encode($meta));
			if(self::rebuild_index_unlocked($account_id) === false)
			{
				return array('error' => 'The package was stored but the index could not be rebuilt; run private-repo-rebuild.');
			}

			return array('upload' => array('id' => self::repository_name() . '/' . $name . '-' . $version, 'sha1' => $meta['sha1'], 'replaced' => $replaced, 'warnings' => $warnings));
		});
	}
	protected static function move_to_trash($account_id, $kind, $name, $version)
	{
		$dir = self::item_dir($account_id, $kind, $name);
		$trash = self::repo_path($account_id) . 'trash/';
		pts_file_io::mkdir($trash);
		$stamp = date('Ymd-His') . '-' . substr(md5(uniqid('', true)), 0, 4);
		foreach(array('zip', 'json') as $ext)
		{
			if(is_file($dir . $version . '.' . $ext))
			{
				if(!rename($dir . $version . '.' . $ext, $trash . $stamp . '-' . $kind . '-' . $name . '-' . $version . '.' . $ext))
				{
					return false;
				}
			}
		}
		return true;
	}
	public static function delete($account_id, $kind, $name, $version)
	{
		if(!self::valid_kind($kind) || !self::valid_name($name) || !self::valid_version($version))
		{
			return array('error' => 'Invalid kind, name or version.');
		}

		return self::with_lock($account_id, function() use ($account_id, $kind, $name, $version) {
			if(!is_file(self::item_dir($account_id, $kind, $name) . $version . '.zip'))
			{
				return array('error' => 'No such ' . $kind . ' version.');
			}
			if(!self::move_to_trash($account_id, $kind, $name, $version) || self::rebuild_index_unlocked($account_id) === false)
			{
				return array('error' => 'Unable to remove the version; check the server storage permissions.');
			}
			return array('delete' => array('id' => self::repository_name() . '/' . $name . '-' . $version));
		});
	}
	public static function rebuild_index($account_id)
	{
		return self::with_lock($account_id, function() use ($account_id) {
			return self::rebuild_index_unlocked($account_id);
		});
	}
	protected static function rebuild_index_unlocked($account_id)
	{
		$index = array('main' => array('generated' => time()), 'tests' => array(), 'suites' => array());

		foreach(array('test' => 'tests', 'suite' => 'suites') as $kind => $section)
		{
			foreach(glob(self::repo_path($account_id) . $section . '/*', GLOB_ONLYDIR) as $item_dir)
			{
				$name = basename($item_dir);
				$versions = array();
				foreach(glob($item_dir . '/*.json') as $meta_file)
				{
					$meta = json_decode(file_get_contents($meta_file), true);
					$version = basename($meta_file, '.json');
					if(!is_array($meta) || !isset($meta['info']) || !is_file($item_dir . '/' . $version . '.zip'))
					{
						continue;
					}
					$versions[$version] = $meta;
				}
				if(empty($versions))
				{
					continue;
				}

				uksort($versions, 'version_compare');
				$versions = array_reverse($versions, true);
				$latest = reset($versions);
				$oldest = end($versions);
				$info = $latest['info'];

				$entry = array(
					'title' => $info['title'],
					'description' => $info['description'],
					'test_type' => $info['test_type'],
					'status' => isset($info['status']) ? $info['status'] : '',
					'package_hash' => $latest['sha1'],
					'package_hashes' => array_map(function($m) { return $m['sha1']; }, $versions),
					'popularity' => '0',
					'downloads' => '0',
					'last_updated' => (string)$latest['uploaded'],
					'first_added' => (string)$oldest['uploaded'],
					'versions' => array_map('strval', array_keys($versions))
					);
				if($kind == 'test')
				{
					$entry['software_type'] = $info['software_type'];
					$entry['supported_platforms'] = $info['supported_platforms'];
					$entry['internal_tags'] = $info['internal_tags'];
					$entry['test_extends'] = $info['test_extends'];
				}
				$index[$section][$name] = $entry;
			}
		}

		$index_file = self::repo_path($account_id) . 'index.json';
		$tmp_file = $index_file . '.tmp';
		if(file_put_contents($tmp_file, json_encode($index)) === false || !rename($tmp_file, $index_file))
		{
			return false;
		}

		return $index;
	}
	public static function sync_server_cache($kind, $name, $version)
	{
		// The web UI lists tests from this server's own OpenBenchmarking-style cache, so after a change
		// make it match the store right away rather than waiting for the hourly event server pass
		$repo = self::repository_name();
		if($repo == false)
		{
			return;
		}
		$id = $repo . '/' . $name . '-' . $version;
		$profile_dir = $kind == 'test' ? PTS_TEST_PROFILE_PATH . $id : PTS_TEST_SUITE_PATH . $id;

		// An overwritten or deleted version must not linger as a stale extraction
		if(is_dir($profile_dir))
		{
			pts_file_io::delete($profile_dir, null, true);
		}
		if(is_file(PTS_OPENBENCHMARKING_SCRATCH_PATH . $id . '.zip'))
		{
			unlink(PTS_OPENBENCHMARKING_SCRATCH_PATH . $id . '.zip');
		}

		pts_openbenchmarking::refresh_repository_lists(array($repo), true);

		if(self::read_package(self::server_account_id(), $kind, $name . '-' . $version) != false)
		{
			$kind == 'test' ? pts_openbenchmarking::download_test_profile($id) : pts_openbenchmarking::download_test_suite($id);
		}
	}
	public static function read_index($account_id)
	{
		if($account_id == false)
		{
			return false;
		}
		$index_file = self::repo_path($account_id) . 'index.json';
		return is_file($index_file) ? json_decode(file_get_contents($index_file), true) : false;
	}
	public static function read_package($account_id, $kind, $id)
	{
		// $id like foo-1.0.0; returns array(sha1, zip_bytes) or false. The path is built only from validated parts.
		if(!self::valid_kind($kind) || !is_string($id) || ($c = strrpos($id, '-')) === false)
		{
			return false;
		}
		$name = substr($id, 0, $c);
		$version = substr($id, $c + 1);
		if(!self::valid_name($name) || !self::valid_version($version))
		{
			return false;
		}

		$zip_file = self::item_dir($account_id, $kind, $name) . $version . '.zip';
		if(!is_file($zip_file))
		{
			return false;
		}
		$zip = file_get_contents($zip_file);
		return array(sha1($zip), $zip);
	}
}

?>
