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
	const MAX_ENTRY_BYTES = 10485760; // 10 MB, declared size of any single entry
	const MAX_COMPRESSION_RATIO = 200; // declared / compressed size; zip-bomb tell
	const MAX_REWRITE_BYTES = 5242880; // archives with backslash paths are recompressed by the server; keep that cheap
	const STALE_TEMP_SECONDS = 3600;
	const RATIO_CHECK_MIN_BYTES = 1048576; // tiny entries (a few KB of spaces) can legitimately exceed the ratio

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

		// corp and benchy are kept for the shared repositories a later release adds
		if(!self::valid_name($name) || in_array($name, array('local', 'pts', 'system', 'git', 'windows', 'off', 'corp', 'benchy')) || in_array($name, pts_openbenchmarking::official_repositories()))
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
		return phoromatic_server::phoromatic_account_path($account_id, 'private-repo') . 'private-repo/';
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
		if($account_id !== false)
		{
			// Until the repository is per tenant (T3) every account is served the first account's: the one account a request may read
			// besides its own, named here and nowhere else
			phoromatic_tenant_context::allow($account_id, 'private-repo');
		}
		return $account_id;
	}
	public static function version_exists($account_id, $kind, $name, $version)
	{
		return self::valid_kind($kind) && self::valid_name($name) && self::valid_version($version) && is_file(self::item_dir($account_id, $kind, $name) . $version . '.zip');
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
	const WINDOWS_RESERVED = '/\A(?:con|prn|aux|nul|conin\$|conout\$|com[0-9\x{00B9}\x{00B2}\x{00B3}]|lpt[0-9\x{00B9}\x{00B2}\x{00B3}])\z/iu';
	public static function windows_unsafe_message($bad)
	{
		return 'The archive has entry names that Windows clients would extract differently from what the review shows, or cannot extract: ' . implode('; ', array_slice($bad, 0, 5)) . '. Rename them (no trailing dot or space, no ":", no "~1"-style names, no CON/NUL/COM1-style device names, no names that differ only by case) and re-create the zip.';
	}
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
	public static function visible_name($name)
	{
		// Entry names, URLs and other short values shown in tables: same rendering as file content, plus tab and newline (which are
		// harmless in a script but would break or spoof a table cell)
		return str_replace(array("\n", "\t"), array('<U+000A>', '<U+0009>'), self::visible_text((string)$name));
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

		$declared_total = 0;
		$actual_total = 0;
		$has_definition = false;
		$xml = null;
		$seen = array();
		$duplicate = null;
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

			// Two entries that normalise to the same path (a duplicate, or a\b next to a/b) would let one hide the other
			// (case-insensitive and ignoring ./ and // so Windows/macOS clients cannot see two entries collapse into one either)
			$dup_key_plain = implode('/', array_filter(explode('/', $entry), function($seg) { return $seg !== '' && $seg !== '.'; }));
			$dup_key = strtolower($dup_key_plain);
			if(isset($seen[$dup_key]) && $duplicate === null)
			{
				// Reported after the scan so a more specific problem inside either copy (e.g. CRLF) is the one the user sees
				$duplicate = 'The archive contains the same path twice: ' . preg_replace('/[^ -~]/', '?', $entry);
			}
			$seen[$dup_key] = true;

			$opsys = null;
			$attr = null;
			if($zip->getExternalAttributesIndex($i, $opsys, $attr) && $opsys == ZipArchive::OPSYS_UNIX && ((($attr >> 16) & 0170000) == 0120000))
			{
				$zip->close();
				$error = 'The archive contains a symbolic link: ' . $entry;
				return false;
			}

			// Cheap checks on the declared sizes first; they can lie, so the stream below counts the real bytes
			$shown = preg_replace('/[^ -~]/', '?', $entry);
			$declared_total += $stat['size'];
			if($declared_total > self::MAX_UNCOMPRESSED_BYTES)
			{
				$zip->close();
				$error = 'The archive expands to more than ' . (self::MAX_UNCOMPRESSED_BYTES / 1048576) . ' MB.';
				return false;
			}
			if($stat['size'] > self::MAX_ENTRY_BYTES)
			{
				$zip->close();
				$error = 'The archive entry ' . $shown . ' is larger than ' . (self::MAX_ENTRY_BYTES / 1048576) . ' MB.';
				return false;
			}
			if($stat['size'] >= self::RATIO_CHECK_MIN_BYTES && $stat['size'] > $stat['comp_size'] * self::MAX_COMPRESSION_RATIO)
			{
				$zip->close();
				$error = 'The archive entry ' . $shown . ' has an implausible compression ratio (over ' . self::MAX_COMPRESSION_RATIO . ':1); refusing a possible zip bomb.';
				return false;
			}

			if(substr($stat['name'], -1) == '/' || substr($stat['name'], -1) == '\\')
			{
				continue; // directory entry
			}

			$is_definition = $dup_key_plain === $definition_file;
			$has_definition = $has_definition || $is_definition;
			$data = null;
			if(!self::scan_entry($zip, $i, $stat['comp_size'], $stat['crc'], $actual_total, strtolower(substr($entry, -3)) == '.sh', $is_definition, $data, $error, $shown))
			{
				$zip->close();
				return false;
			}
			if($is_definition)
			{
				$xml = $data;
			}
		}

		if($needs_rewrite && $actual_total > self::MAX_REWRITE_BYTES)
		{
			$zip->close();
			$error = 'Archives with backslash paths can expand to at most ' . (self::MAX_REWRITE_BYTES / 1048576) . ' MB (the server has to rewrite them). Recreate the zip with forward slashes.';
			return false;
		}

		if($duplicate !== null)
		{
			$zip->close();
			$error = $duplicate;
			return false;
		}

		if(!$has_definition)
		{
			$zip->close();
			$error = 'The archive has no ' . $definition_file . ' at its root.';
			return false;
		}

		$zip->close();

		if($xml === null || trim($xml) === '')
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
	protected static function scan_entry($zip, $index, $comp_size, $crc, &$actual_total, $check_crlf, $keep, &$data, &$error, $shown)
	{
		// Streams one entry once and enforces the size, total and ratio limits on the bytes actually produced (declared
		// sizes in a zip can lie), aborting early. Optionally checks for CRLF and keeps the content. False with $error set.
		if(($fp = $zip->getStreamIndex($index)) === false)
		{
			$error = 'The archive entry ' . $shown . ' cannot be read.';
			return false;
		}
		$n = 0;
		$prev = '';
		$crc_state = hash_init('crc32b');
		$data = $keep ? '' : null;
		while(!feof($fp))
		{
			$chunk = @fread($fp, 65536);
			if($chunk === false || ($chunk === '' && !feof($fp)))
			{
				// A stream error (bad deflate data, CRC mismatch) also sets EOF, so it must be told apart here
				fclose($fp);
				$error = 'The archive entry ' . $shown . ' is corrupt.';
				return false;
			}
			if($chunk === '')
			{
				break;
			}
			$n += strlen($chunk);
			$actual_total += strlen($chunk);
			$fail = null;
			if($actual_total > self::MAX_UNCOMPRESSED_BYTES)
			{
				$fail = 'The archive expands to more than ' . (self::MAX_UNCOMPRESSED_BYTES / 1048576) . ' MB.';
			}
			else if($n > self::MAX_ENTRY_BYTES)
			{
				$fail = 'The archive entry ' . $shown . ' is larger than ' . (self::MAX_ENTRY_BYTES / 1048576) . ' MB.';
			}
			else if($n >= self::RATIO_CHECK_MIN_BYTES && $n > $comp_size * self::MAX_COMPRESSION_RATIO)
			{
				$fail = 'The archive entry ' . $shown . ' has an implausible compression ratio (over ' . self::MAX_COMPRESSION_RATIO . ':1); refusing a possible zip bomb.';
			}
			else if($check_crlf && strpos($prev . $chunk, "\r\n") !== false)
			{
				$fail = $shown . ' has Windows (CRLF) line endings, which break sh. Convert it to LF.';
			}
			if($fail != null)
			{
				fclose($fp);
				$error = $fail;
				return false;
			}
			$prev = substr($chunk, -1);
			hash_update($crc_state, $chunk);
			if($keep)
			{
				$data .= $chunk;
			}
		}
		fclose($fp);
		// libzip does not report a CRC mismatch through the PHP stream, so check it here
		if(hash_final($crc_state) !== sprintf('%08x', $crc & 0xffffffff))
		{
			$error = 'The archive entry ' . $shown . ' is corrupt (checksum mismatch).';
			return false;
		}
		return true;
	}
	protected static function read_entry_bounded($zip, $index, $max_bytes)
	{
		// Reads an entry through a stream, never more than $max_bytes. False if unreadable or over the bound.
		if($index === false || ($fp = $zip->getStreamIndex($index)) === false)
		{
			return false;
		}
		$data = '';
		while(!feof($fp) && strlen($data) <= $max_bytes)
		{
			$chunk = @fread($fp, 65536);
			if($chunk === false)
			{
				fclose($fp);
				return false;
			}
			if($chunk === '')
			{
				break;
			}
			$data .= $chunk;
		}
		fclose($fp);
		return strlen($data) > $max_bytes ? false : $data;
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

		$total = 0;
		for($i = 0; $i < $in->numFiles; $i++)
		{
			$name = str_replace('\\', '/', $in->getNameIndex($i));
			if(substr($name, -1) == '/')
			{
				if(!$out->addEmptyDir(rtrim($name, '/')))
				{
					return false;
				}
			}
			else
			{
				$data = self::read_entry_bounded($in, $i, self::MAX_ENTRY_BYTES);
				$total += $data === false ? 0 : strlen($data);
				if($data === false || $total > self::MAX_REWRITE_BYTES || !$out->addFromString($name, $data))
				{
					return false;
				}
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
	public static function upload($account_id, $system_id, $kind, $name, $zip_base64, $zip_sha1, $commit_description, $overwrite = false, $attempt_callback = null)
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

		@pts_file_io::mkdir(self::repo_path($account_id));
		self::sweep_stale_temp_files($account_id);
		$tmp_file = self::repo_path($account_id) . 'upload-' . substr(md5(uniqid('', true)), 0, 12) . '.zip';
		if(@file_put_contents($tmp_file, $zip) !== strlen($zip))
		{
			@unlink($tmp_file);
			return array('error' => 'The server could not write the upload to its storage (disk full or not writable?).', 'audit' => array('sha256' => hash('sha256', $zip), 'sha1' => sha1($zip), 'size' => strlen($zip)));
		}

		$result = self::store_zip($account_id, 'system:' . $system_id, $kind, $name, $tmp_file, $commit_description, $overwrite, 'cli', $attempt_callback);
		if(!isset($result['error']) && !isset($result['upload']))
		{
			return array('error' => 'Unexpected error storing the package.');
		}
		return $result;
	}
	// What the running upload has done so far, for the endpoint's shutdown handler: array('stage' => attempted|stored, 'facts' => ...)
	public static $upload_progress = array();
	// Versions the last index rebuild left out because their archive has no valid metadata next to it
	public static $last_skipped = array();
	protected static function files_starting_with($dir, $prefix)
	{
		// Not glob(): the data path may contain [ ] * ? characters
		$found = array();
		foreach((array)@scandir($dir) as $f)
		{
			if(is_string($f) && $f !== '.' && $f !== '..' && ($prefix !== '' || $f[0] != '.') && strpos($f, $prefix) === 0)
			{
				$found[] = rtrim($dir, '/') . '/' . $f;
			}
		}
		return $found;
	}
	public static function list_versions($account_id)
	{
		// For the web page: every stored version with its metadata, plus archives that have no valid metadata next to them
		// (not listed in the index; they can be deleted or re-uploaded with overwrite). Newest version first within a name.
		$versions = array();
		$skipped = array();
		foreach(array('test' => 'tests', 'suite' => 'suites') as $kind => $section)
		{
			foreach(self::files_starting_with(self::repo_path($account_id) . $section . '/', '') as $item_dir)
			{
				$name = basename($item_dir);
				if(!is_dir($item_dir) || !self::valid_name($name))
				{
					continue;
				}
				foreach(self::files_starting_with($item_dir . '/', '') as $zip_file)
				{
					$version = basename($zip_file, '.zip');
					if(substr($zip_file, -4) != '.zip' || !self::valid_version($version))
					{
						continue;
					}
					$meta = is_file($item_dir . '/' . $version . '.json') ? json_decode(@file_get_contents($item_dir . '/' . $version . '.json'), true) : null;
					if(!is_array($meta) || !isset($meta['info']) || !is_array($meta['info']))
					{
						$skipped[] = array('kind' => $kind, 'name' => $name, 'version' => $version, 'size' => filesize($zip_file));
						continue;
					}
					// Metadata can be hand-edited: take only plain scalars of the expected kind so one odd file cannot break the page
					$text = function($key) use ($meta) {
						return isset($meta[$key]) && is_scalar($meta[$key]) ? (string)$meta[$key] : null;
					};
					$versions[] = array('kind' => $kind, 'name' => $name, 'version' => $version, 'size' => filesize($zip_file),
						'title' => (string)$text('title'),
						'sha256' => $text('sha256'), 'sha1' => $text('sha1'),
						'uploaded' => isset($meta['uploaded']) && is_numeric($meta['uploaded']) ? (int)$meta['uploaded'] : null,
						'uploaded_by' => $text('uploaded_by') !== null ? $text('uploaded_by') : ($text('system_id') !== null ? 'system:' . $text('system_id') : null),
						'source' => $text('source'),
						'description' => (string)$text('commit_description'));
				}
			}
		}
		usort($versions, function($a, $b) {
			return strcmp($a['kind'] . $a['name'], $b['kind'] . $b['name']) ?: version_compare($b['version'], $a['version']);
		});
		return array('versions' => $versions, 'skipped' => $skipped);
	}
	public static function sweep_stale_temp_files($account_id)
	{
		// Leftovers of uploads that died mid-way (fatal error, timeout, killed worker): upload-*.zip and its .fixed* rewrite
		foreach(self::files_starting_with(self::repo_path($account_id), 'upload-') as $file)
		{
			if(is_file($file) && filemtime($file) < time() - self::STALE_TEMP_SECONDS)
			{
				@unlink($file);
			}
		}
	}
	public static function store_zip($account_id, $actor, $kind, $name, $zip_file, $commit_description, $overwrite = false, $source = 'cli', $attempt_callback = null)
	{
		// Inspects and stores an already-received archive; shared by the CLI upload and (later) the web confirm step.
		// Takes ownership of $zip_file: it is renamed into the store or deleted, whatever the outcome (also on a fatal error).
		// $attempt_callback($facts) runs inside the lock after validation and BEFORE anything is changed; if it returns false
		// (e.g. the audit row could not be written) nothing is stored.
		// Returns array('upload' => legacy wire fields, 'audit' => facts) or array('error' => ..., 'audit' => facts,
		// 'attempted' => bool (callback ran and succeeded), 'stored' => bool (the archive is in the store despite the error))
		register_shutdown_function(function() use ($zip_file) {
			// Also runs after a fatal error or timeout; the zip library's temp file is named <file>.fixed.<random>
			@unlink($zip_file);
			foreach(self::files_starting_with(dirname($zip_file), basename($zip_file) . '.fixed') as $leftover)
			{
				@unlink($leftover);
			}
		});

		if(!self::valid_kind($kind) || !self::valid_name($name))
		{
			@unlink($zip_file);
			return array('error' => 'Invalid kind or name.');
		}

		$ret = self::with_lock($account_id, function() use ($account_id, $actor, $kind, $name, $zip_file, $commit_description, $overwrite, $source, $attempt_callback) {
			$tmp_file = $zip_file;
			$facts = array();
			$attempted = false;
			$stored = false;
			try
			{
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
						@unlink($tmp_file . '.fixed');
						unlink($tmp_file);
						return array('error' => 'Unable to normalize the archive paths.');
					}
					$warnings = array();
					$needs_rewrite = false;
					$info = self::inspect_zip($tmp_file, $kind, $error, $warnings, $needs_rewrite);
				}
				if($info !== false)
				{
					// Same Windows-name rule as the web staging step, so a package sent by upload-private-test-profile is judged alike
					$bad = self::windows_unsafe_entries($tmp_file);
					if(!empty($bad))
					{
						$info = false;
						$error = self::windows_unsafe_message($bad);
					}
				}
				$zip = file_get_contents($tmp_file);
				$facts = array('sha256' => hash('sha256', $zip), 'sha1' => sha1($zip), 'size' => strlen($zip));
				if($info === false)
				{
					unlink($tmp_file);
					return array('error' => $error, 'audit' => $facts);
				}

				$version = $info['version'];
				$dir = self::item_dir($account_id, $kind, $name);
				$zip_dest = $dir . $version . '.zip';
				$replaced = is_file($zip_dest);
				$facts['version'] = $version;
				$facts['warnings'] = $warnings;

				if($replaced)
				{
					if(!$overwrite)
					{
						unlink($tmp_file);
						return array('error' => $kind . ' ' . $name . '-' . $version . ' already exists; bump the version (versions are immutable) or re-upload with overwrite.', 'audit' => $facts);
					}
					$facts['prev_sha256'] = hash_file('sha256', $zip_dest);
				}

				// Last point before the store changes: the audit trail must be able to record the attempt
				// (The callback returns true to go ahead, false when the audit row could not be written, or a string with the reason
				// it wants the store refused, e.g. the web confirm step when the version it reviewed has changed meanwhile.)
				$go_ahead = $attempt_callback != null ? call_user_func($attempt_callback, $facts) : true;
				if($go_ahead !== true)
				{
					unlink($tmp_file);
					return array('error' => is_string($go_ahead) ? $go_ahead : 'The audit log could not be written, so the upload was refused and nothing was stored.', 'audit' => $facts, 'audit_failed' => !is_string($go_ahead));
				}
				$attempted = true;
				self::$upload_progress = array('stage' => 'attempted', 'facts' => $facts);

				if($replaced)
				{
					if(($facts['trash'] = self::move_to_trash($account_id, $kind, $name, $version)) === false)
					{
						unlink($tmp_file);
						return array('error' => 'Unable to move the replaced version to the trash directory.', 'audit' => $facts, 'attempted' => true);
					}
				}

				pts_file_io::mkdir($dir);
				if(!rename($tmp_file, $zip_dest))
				{
					unlink($tmp_file);
					return array('error' => 'Unable to store the package on the server.', 'audit' => $facts, 'attempted' => true);
				}
				$stored = true;
				self::$upload_progress = array('stage' => 'stored', 'facts' => $facts);
			}
			catch(Throwable $e)
			{
				@unlink($tmp_file);
				return array('error' => 'Unexpected error storing the package.', 'audit' => $facts, 'attempted' => $attempted, 'stored' => $stored);
			}

			// From here the archive is in the store; every failure is reported as stored
			try
			{
				$meta = array(
					'sha1' => $facts['sha1'],
					'sha256' => $facts['sha256'],
					'size' => $facts['size'],
					'uploaded' => time(),
					'uploaded_by' => $actor,
					'source' => $source,
					'commit_description' => $commit_description,
					'title' => $info['title'],
					'info' => $info
					);
				if(strpos($actor, 'system:') === 0)
				{
					$meta['system_id'] = substr($actor, 7);
				}
				$json = json_encode($meta, JSON_INVALID_UTF8_SUBSTITUTE);
				if($json === false || @file_put_contents($dir . $version . '.json', $json) !== strlen($json))
				{
					// No half-written metadata may stay behind: it would look like a version that exists
					@unlink($dir . $version . '.json');
					return array('error' => 'The package was stored but its metadata could not be written (disk full?), so it is not listed. Free some space and upload it again with overwrite.', 'audit' => $facts, 'attempted' => true, 'stored' => true);
				}
				if(self::rebuild_index_unlocked($account_id) === false)
				{
					return array('error' => 'The package was stored but the index could not be rebuilt; run private-repo-rebuild.', 'audit' => $facts, 'attempted' => true, 'stored' => true);
				}
				$facts['entries'] = self::count_entries($zip_dest);
			}
			catch(Throwable $e)
			{
				return array('error' => 'The package was stored but finishing the upload failed; run private-repo-rebuild.', 'audit' => $facts, 'attempted' => true, 'stored' => true);
			}
			return array('upload' => array('id' => self::repository_name() . '/' . $name . '-' . $version, 'sha1' => $meta['sha1'], 'replaced' => $replaced, 'warnings' => $warnings), 'audit' => $facts);
		});

		if(isset($ret['error']) && !isset($ret['audit']) && is_file($zip_file))
		{
			// The lock itself failed
			unlink($zip_file);
		}
		return $ret;
	}
	protected static function count_entries($zip_file)
	{
		$zip = new ZipArchive();
		if($zip->open($zip_file) !== true)
		{
			return null;
		}
		$n = $zip->numFiles;
		$zip->close();
		return $n;
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
		// The trash name of the archive, for the audit trail
		return $stamp . '-' . $kind . '-' . $name . '-' . $version . '.zip';
	}
	public static function delete($account_id, $kind, $name, $version, $attempt_callback = null)
	{
		// $attempt_callback($facts) runs inside the lock before anything changes; false aborts with nothing deleted.
		// Errors carry 'audit' facts, 'attempted' and (when the file was already moved) 'done' => true.
		if(!self::valid_kind($kind) || !self::valid_name($name) || !self::valid_version($version))
		{
			return array('error' => 'Invalid kind, name or version.');
		}

		return self::with_lock($account_id, function() use ($account_id, $kind, $name, $version, $attempt_callback) {
			$zip_file = self::item_dir($account_id, $kind, $name) . $version . '.zip';
			if(!is_file($zip_file))
			{
				return array('error' => 'No such ' . $kind . ' version.');
			}
			$facts = array('sha256' => hash_file('sha256', $zip_file), 'sha1' => sha1_file($zip_file));
			if($attempt_callback != null && !call_user_func($attempt_callback, $facts))
			{
				return array('error' => 'The audit log could not be written, so nothing was deleted.', 'audit' => $facts, 'audit_failed' => true);
			}
			if(($facts['trash'] = self::move_to_trash($account_id, $kind, $name, $version)) === false)
			{
				return array('error' => 'Unable to remove the version; check the server storage permissions.', 'audit' => $facts, 'attempted' => true);
			}
			if(self::rebuild_index_unlocked($account_id) === false)
			{
				return array('error' => 'The version was moved to the trash but the index could not be rebuilt; run private-repo-rebuild.', 'audit' => $facts, 'attempted' => true, 'done' => true);
			}
			return array('delete' => array('id' => self::repository_name() . '/' . $name . '-' . $version), 'audit' => $facts);
		});
	}
	public static function rebuild_index($account_id, $attempt_callback = null)
	{
		// With a callback it runs inside the lock first; false aborts. Returns the index, false on failure, or
		// array('audit_failed' => true) when the callback refused.
		return self::with_lock($account_id, function() use ($account_id, $attempt_callback) {
			if($attempt_callback != null && !call_user_func($attempt_callback))
			{
				return array('audit_failed' => true);
			}
			return self::rebuild_index_unlocked($account_id);
		});
	}
	protected static function rebuild_index_unlocked($account_id)
	{
		$index = array('main' => array('generated' => time()), 'tests' => array(), 'suites' => array());
		self::$last_skipped = array();

		foreach(array('test' => 'tests', 'suite' => 'suites') as $kind => $section)
		{
			foreach(self::files_starting_with(self::repo_path($account_id) . $section . '/', '') as $item_dir)
			{
				$name = basename($item_dir);
				$versions = array();
				if(!is_dir($item_dir))
				{
					continue;
				}
				foreach(self::files_starting_with($item_dir . '/', '') as $meta_file)
				{
					if(substr($meta_file, -5) != '.json')
					{
						continue;
					}
					$meta = json_decode(file_get_contents($meta_file), true);
					$version = basename($meta_file, '.json');
					if(!is_array($meta) || !isset($meta['info']) || !is_file($item_dir . '/' . $version . '.zip'))
					{
						continue;
					}
					$versions[$version] = $meta;
				}
				foreach(self::files_starting_with($item_dir . '/', '') as $zip_file)
				{
					if(substr($zip_file, -4) == '.zip' && !isset($versions[basename($zip_file, '.zip')]))
					{
						self::$last_skipped[] = $kind . ' ' . $name . '-' . basename($zip_file, '.zip');
					}
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
		$encoded = json_encode($index);
		// A full disk usually gives a short write rather than false; never rename a truncated index over the good one
		if($encoded === false || @file_put_contents($tmp_file, $encoded) !== strlen($encoded) || !@rename($tmp_file, $index_file))
		{
			@unlink($tmp_file);
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
