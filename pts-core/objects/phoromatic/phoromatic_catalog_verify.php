<?php

/*
	Benchy
	phoromatic_catalog_verify.php: verifies a signed catalog index and its bundle (E4.4 C1, catalog spec 6.2, 7.2 and server design 3.1)

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

// The verifier of catalog spec 6.2 and server design 3.1, in PHP: a port of benchy-engine internal/catindex (envelope.go, index.go,
// state.go) and the bundle checks of internal/catstore/accept.go, with the same steps in the same order and the same reason words. It is a
// gate and a convenience, not the trust anchor: the engine verifies everything again. Nothing here reads or writes the database or the
// disk. The state it works on is the engine's state.json as a PHP array:
//
//   array('retired_below' => int, 'tiers' => array(tier => array('generation' => int, 'sequence' => int, 'signed_sha256' => hex,
//         'tests' => array('id@version' => tree_sha256), 'suites' => array('id@version' => suite_sha256), 'revoked' => array('id@version'))))
//
// Differences from the engine, on purpose: the server doesn't validate manifests (catalog spec 11 step 3), only that each is an object, and
// it adds the license check of catalog spec 9.3 (reason license_tier). It refuses JSON the engine would read differently (design 3.3).

class phoromatic_catalog_verify
{
	const MAX_INDEX_BYTES = 16781312;   // 16 MiB plus 4 KiB
	const MAX_SIG_LINE = 4096;
	const SIG_SCHEMA = 'benchy-catalog-sig/1';
	const INDEX_SCHEMA = 'benchy-catalog-index/1';
	const MAX_TESTS = 1000;
	const MAX_SUITES = 1000;
	const MAX_SEQUENCE = 9007199254740991; // 2^53 - 1
	const MAX_ID_BYTES = 71;
	const MAX_PACK_BYTES = 67108864;

	// The state comes from the server's database. A number that came back as a string would make every pair comparison below loosely typed
	// and quietly switch off the rollback and equal-pair checks, so anything but integers where integers belong is refused first.
	public static function check_state($state)
	{
		$bad = function($why) { return self::refuse(phoromatic_catalog_refusal::STATE_CORRUPT, 'the stored catalog state is damaged: ' . $why); };
		if(!is_array($state) || !isset($state['retired_below'], $state['tiers']) || !is_int($state['retired_below']) || !is_array($state['tiers']))
		{
			throw $bad('retired_below and tiers must be there, as an integer and a list');
		}
		foreach($state['tiers'] as $tier => $ts)
		{
			if(($tier !== 'public' && $tier !== 'private') || !is_array($ts) || !isset($ts['generation'], $ts['sequence'], $ts['signed_sha256'], $ts['tests'], $ts['suites'], $ts['revoked'])
				|| !is_int($ts['generation']) || !is_int($ts['sequence']) || !is_string($ts['signed_sha256']) || !is_array($ts['tests']) || !is_array($ts['suites']) || !is_array($ts['revoked']))
			{
				throw $bad('the ' . var_export($tier, true) . ' tier is not a pair of integers, a hash and three lists');
			}
		}
	}

	public static function new_state()
	{
		return array('retired_below' => 0, 'tiers' => array());
	}

	private static function refuse($reason, $message)
	{
		return new phoromatic_catalog_refusal($reason, $message);
	}

	private static function bad($message)
	{
		return new phoromatic_catalog_refusal(phoromatic_catalog_refusal::BAD_INDEX, $message);
	}

	// ---- the envelope (6.2 steps 3 to 5) ----

	// Cuts index.signed at its first newline: the signature line and the index bytes the signature covers
	public static function split($file)
	{
		if(strlen($file) > self::MAX_INDEX_BYTES)
		{
			throw self::refuse(phoromatic_catalog_refusal::LIMIT, 'index.signed is ' . strlen($file) . ' bytes; the limit is ' . self::MAX_INDEX_BYTES);
		}
		$i = strpos($file, "\n");
		if($i === false)
		{
			throw self::refuse(phoromatic_catalog_refusal::BAD_ENVELOPE, 'index.signed has no signature line');
		}
		if($i > self::MAX_SIG_LINE)
		{
			throw self::refuse(phoromatic_catalog_refusal::LIMIT, 'the signature line is ' . $i . ' bytes; the limit is ' . self::MAX_SIG_LINE);
		}
		return array(substr($file, 0, $i), substr($file, $i + 1));
	}

	private static function parse_envelope($line)
	{
		try
		{
			$e = phoromatic_catalog_json::decode($line, 'sig', phoromatic_catalog_refusal::BAD_ENVELOPE);
		}
		catch(phoromatic_catalog_refusal $r)
		{
			throw self::refuse(phoromatic_catalog_refusal::BAD_ENVELOPE, 'the signature line is not JSON of the expected shape: ' . $r->getMessage());
		}
		$out = array();
		foreach(array('schema', 'key_id', 'sig') as $k)
		{
			$v = property_exists($e, $k) ? $e->$k : null;
			if($v !== null && !is_string($v))
			{
				throw self::refuse(phoromatic_catalog_refusal::BAD_ENVELOPE, 'the signature line\'s ' . $k . ' is not a string');
			}
			$out[$k] = $v === null ? '' : $v;
		}
		if($out['schema'] !== self::SIG_SCHEMA)
		{
			throw self::refuse(phoromatic_catalog_refusal::BAD_ENVELOPE, 'the signature line is ' . json_encode($out['schema']) . '; this server reads ' . json_encode(self::SIG_SCHEMA));
		}
		return $out;
	}

	// The key id a file's signature line names, for a caller that must tell which key signed a file without trusting it
	public static function key_id_of($file)
	{
		list($line) = self::split($file);
		$e = self::parse_envelope($line);
		return $e['key_id'];
	}

	public static function file_sha256($file)
	{
		return hash('sha256', $file);
	}

	// 6.2 steps 3 to 5: an unknown or denied key is refused, a key below $retired_below is refused, and the signature over the index
	// bytes must be valid. Nothing parses the index. Returns array($key, $index_bytes).
	private static function verify_signature($trust, $file, $retired_below)
	{
		list($line, $index) = self::split($file);
		$e = self::parse_envelope($line);
		if($trust->is_denied($e['key_id']))
		{
			throw self::refuse(phoromatic_catalog_refusal::DENIED_KEY, 'the index is signed by key ' . $e['key_id'] . ', which is known to be compromised');
		}
		$key = $trust->lookup($e['key_id']);
		if($key === null)
		{
			throw self::refuse(phoromatic_catalog_refusal::UNKNOWN_KEY, 'the index is signed by key ' . $e['key_id'] . ', which this server doesn\'t know');
		}
		if($key['generation'] < $retired_below)
		{
			throw self::refuse(phoromatic_catalog_refusal::RETIRED_KEY, 'the index is signed by a key of generation ' . $key['generation'] . ', which has been retired (below ' . $retired_below . ')');
		}
		// Go's base64.StdEncoding ignores \r and \n anywhere and refuses everything else outside the alphabet, and it needs the padding.
		// PHP's strict mode takes spaces and tabs and missing padding, which would make the server accept a file every engine refuses.
		$b64 = str_replace(array("\r", "\n"), '', $e['sig']);
		$der = preg_match('~^[A-Za-z0-9+/]*={0,2}\z~', $b64) === 1 && strlen($b64) % 4 === 0 ? base64_decode($b64, true) : false;
		if($der === false || $der === '')
		{
			throw self::refuse(phoromatic_catalog_refusal::BAD_SIGNATURE, 'the signature is not base64');
		}
		// openssl_verify gives 1 on a good signature, 0 on a bad one and -1 (truthy) on malformed input: only 1 counts (catalog spec 5.1)
		if(@openssl_verify($index, $der, $key['pem'], OPENSSL_ALGO_SHA256) !== 1)
		{
			while(openssl_error_string() !== false);
			throw self::refuse(phoromatic_catalog_refusal::BAD_SIGNATURE, 'the signature does not match the index');
		}
		return array($key, $index);
	}

	// ---- the index (6.2 steps 6 and 8) ----

	public static function good_version($v)
	{
		return is_string($v) && preg_match('/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\z/', $v) === 1;
	}

	public static function good_id($id)
	{
		return is_string($id) && strlen($id) <= self::MAX_ID_BYTES && preg_match('/^benchy\/[a-z0-9]+([._-][a-z0-9]+)*\z/', $id) === 1;
	}

	private static function good_hex($h)
	{
		return is_string($h) && preg_match('/^[0-9a-f]{64}\z/', $h) === 1;
	}

	private static function rfc3339($t)
	{
		if(preg_match('/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(\.\d+)?(Z|[+-](\d{2}):(\d{2}))\z/', $t, $m) !== 1)
		{
			return false;
		}
		return checkdate((int)$m[2], (int)$m[3], (int)$m[1]) && (int)$m[4] < 24 && (int)$m[5] < 60 && (int)$m[6] < 60 && (!isset($m[9]) || ((int)$m[9] < 24 && (int)$m[10] < 60));
	}

	// the value of a string field: absent and null are '' (as with Go), anything but a string is refused
	private static function str($o, $k, $what)
	{
		if(!property_exists($o, $k) || $o->$k === null)
		{
			return '';
		}
		if(!is_string($o->$k))
		{
			throw self::bad($what . ': ' . $k . ' is not a string');
		}
		return $o->$k;
	}

	private static function int($o, $k, $what)
	{
		if(!property_exists($o, $k) || $o->$k === null)
		{
			return 0;
		}
		if(!is_int($o->$k))
		{
			throw self::bad($what . ': ' . $k . ' is not an integer');
		}
		return $o->$k;
	}

	private static function lst($o, $k, $what)
	{
		if(!property_exists($o, $k) || $o->$k === null)
		{
			return array();
		}
		if(!is_array($o->$k))
		{
			throw self::bad($what . ': ' . $k . ' is not a list');
		}
		return $o->$k;
	}

	private static function rec($v, $what)
	{
		if(!is_object($v))
		{
			throw self::bad($what . ' is not an object');
		}
		return $v;
	}

	// the hash over a suite's tests (catalog spec 3): each test, NUL, the version or '', NUL, the option count, NUL, then each option by byte
	// order of key as key, NUL, value, NUL. $tests is a list of array('test' =>, 'version' => ?string, 'options' => list of array(key, value))
	public static function suite_sha256($tests)
	{
		$h = hash_init('sha256');
		foreach($tests as $e)
		{
			$opts = $e['options'];
			foreach($opts as $o)
			{
				if(preg_match('/^[A-Za-z0-9_-]+\z/', $o[0]) !== 1)
				{
					throw self::bad('the option key ' . json_encode($o[0]) . ' is not [A-Za-z0-9_-]+');
				}
				if(strpos($o[1], "\0") !== false)
				{
					throw self::bad('the value of option ' . $o[0] . ' holds a NUL');
				}
			}
			usort($opts, function($a, $b) { return strcmp($a[0], $b[0]); });
			hash_update($h, $e['test'] . "\0" . ($e['version'] === null ? '' : $e['version']) . "\0" . count($opts) . "\0");
			foreach($opts as $o)
			{
				hash_update($h, $o[0] . "\0" . $o[1] . "\0");
			}
		}
		return hash_final($h);
	}

	// Reads the index bytes (only ever called after the signature passed), checks the header against the signing key's generation and the
	// structure of every entry, including entries an engine will skip. Unknown keys are ignored. $max_generation is the signing key's
	// generation (PHP_INT_MAX when none signs it, as for an index already accepted). Returns the index as an array.
	public static function parse_index($raw, $max_generation)
	{
		$root = phoromatic_catalog_json::decode($raw, 'root', phoromatic_catalog_refusal::BAD_INDEX);
		$ix = array(
			'schema' => self::str($root, 'schema', 'the index'), 'repo' => self::str($root, 'repo', 'the index'), 'tier' => self::str($root, 'tier', 'the index'),
			'sequence' => self::int($root, 'sequence', 'the index'), 'issued' => self::str($root, 'issued', 'the index'), 'min_generation' => null,
			'tests' => array(), 'suites' => array(), 'revoked' => array());
		if(property_exists($root, 'min_generation') && $root->min_generation !== null)
		{
			if(!is_int($root->min_generation))
			{
				throw self::bad('the index: min_generation is not an integer');
			}
			$ix['min_generation'] = $root->min_generation;
		}
		foreach(self::lst($root, 'tests', 'the index') as $t)
		{
			$t = self::rec($t, 'a test entry');
			$p = array('id' => self::str($t, 'id', 'a test'), 'version' => self::str($t, 'version', 'a test'), 'format' => self::str($t, 'format', 'a test'),
				'pack_sha256' => self::str($t, 'pack_sha256', 'a test'), 'pack_size' => self::int($t, 'pack_size', 'a test'), 'tree_sha256' => self::str($t, 'tree_sha256', 'a test'),
				'min_engine' => self::str($t, 'min_engine', 'a test'), 'platforms' => array(), 'license_class' => self::str($t, 'license_class', 'a test'),
				'software_license' => self::str($t, 'software_license', 'a test'), 'manifest' => null);
			foreach(self::lst($t, 'platforms', 'a test') as $pl)
			{
				if($pl !== null && !is_string($pl))
				{
					throw self::bad('a test: platforms holds something that is not a string');
				}
				$p['platforms'][] = (string)$pl;
			}
			if(!property_exists($t, 'manifest') || !is_object($t->manifest))
			{
				throw self::bad('test ' . $p['id'] . ': the manifest is not an object');
			}
			$p['manifest'] = $t->manifest;
			$ix['tests'][] = $p;
		}
		foreach(self::lst($root, 'suites', 'the index') as $s)
		{
			$s = self::rec($s, 'a suite entry');
			$p = array('id' => self::str($s, 'id', 'a suite'), 'version' => self::str($s, 'version', 'a suite'), 'title' => self::str($s, 'title', 'a suite'),
				'description' => self::str($s, 'description', 'a suite'), 'min_engine' => self::str($s, 'min_engine', 'a suite'), 'suite_sha256' => self::str($s, 'suite_sha256', 'a suite'), 'tests' => array());
			foreach(self::lst($s, 'tests', 'a suite') as $e)
			{
				$e = self::rec($e, 'a suite test');
				$ver = null;
				if(property_exists($e, 'version') && $e->version !== null)
				{
					if(!is_string($e->version))
					{
						throw self::bad('a suite test: version is not a string');
					}
					$ver = $e->version;
				}
				$opts = array();
				if(property_exists($e, 'options') && $e->options !== null)
				{
					if(!is_object($e->options))
					{
						throw self::bad('a suite test: options is not an object');
					}
					foreach(get_object_vars($e->options) as $ok => $ov)
					{
						if($ov !== null && !is_string($ov))
						{
							throw self::bad('a suite test: an option value is not a string');
						}
						$opts[] = array((string)$ok, (string)$ov);
					}
				}
				$p['tests'][] = array('test' => self::str($e, 'test', 'a suite test'), 'version' => $ver, 'options' => $opts);
			}
			$ix['suites'][] = $p;
		}
		foreach(self::lst($root, 'revoked', 'the index') as $r)
		{
			$r = self::rec($r, 'a revocation');
			$ix['revoked'][] = array('id' => self::str($r, 'id', 'a revocation'), 'version' => self::str($r, 'version', 'a revocation'), 'reason' => self::str($r, 'reason', 'a revocation'));
		}

		if($ix['schema'] !== self::INDEX_SCHEMA)
		{
			throw self::bad('the index schema is ' . json_encode($ix['schema']) . '; this server reads ' . json_encode(self::INDEX_SCHEMA));
		}
		if($ix['repo'] !== 'benchy')
		{
			throw self::bad('the index repo is ' . json_encode($ix['repo']) . '; only "benchy" is valid');
		}
		if($ix['tier'] !== 'public' && $ix['tier'] !== 'private')
		{
			throw self::refuse(phoromatic_catalog_refusal::BAD_TIER, 'the index tier is ' . json_encode($ix['tier']) . ', not public or private');
		}
		if($ix['sequence'] < 1 || $ix['sequence'] > self::MAX_SEQUENCE)
		{
			throw self::bad('the sequence ' . $ix['sequence'] . ' is outside 1 to 2^53-1');
		}
		if(!self::rfc3339($ix['issued']))
		{
			throw self::bad('issued is not a time');
		}
		if($ix['min_generation'] !== null && ($ix['min_generation'] < 1 || $ix['min_generation'] > $max_generation))
		{
			throw self::bad('min_generation ' . $ix['min_generation'] . ' is outside 1 to the signing key\'s generation ' . $max_generation);
		}
		if(count($ix['tests']) > self::MAX_TESTS || count($ix['suites']) > self::MAX_SUITES)
		{
			throw self::refuse(phoromatic_catalog_refusal::LIMIT, 'the index holds ' . count($ix['tests']) . ' tests and ' . count($ix['suites']) . ' suites; at most ' . self::MAX_TESTS . ' and ' . self::MAX_SUITES);
		}
		self::check_entries($ix);
		return $ix;
	}

	private static function check_entries($ix)
	{
		$seen = array();
		$test_ids = array();
		$have_test = array();
		foreach($ix['tests'] as $t)
		{
			$ref = $t['id'] . '@' . $t['version'];
			if(!self::good_id($t['id']))
			{
				throw self::bad('test ' . json_encode($t['id']) . ': the id is not benchy/<lowercase name> within ' . self::MAX_ID_BYTES . ' bytes');
			}
			else if(!self::good_version($t['version']))
			{
				throw self::bad('test ' . $t['id'] . ': the version ' . json_encode($t['version']) . ' is not x.y.z');
			}
			else if($t['format'] !== 'native')
			{
				throw self::bad('test ' . $ref . ': the format is ' . json_encode($t['format']) . ', not native');
			}
			else if(!self::good_hex($t['pack_sha256']) || !self::good_hex($t['tree_sha256']))
			{
				throw self::bad('test ' . $ref . ': pack_sha256 or tree_sha256 is not 64 lowercase hex digits');
			}
			else if($t['pack_size'] < 1 || $t['pack_size'] > self::MAX_PACK_BYTES)
			{
				throw self::bad('test ' . $ref . ': pack_size ' . $t['pack_size'] . ' is outside 1 to ' . self::MAX_PACK_BYTES);
			}
			else if(!self::good_version($t['min_engine']))
			{
				throw self::bad('test ' . $ref . ': min_engine ' . json_encode($t['min_engine']) . ' is not x.y.z');
			}
			else if(count($t['platforms']) === 0)
			{
				throw self::bad('test ' . $ref . ': platforms is empty');
			}
			else if($t['license_class'] === '')
			{
				throw self::bad('test ' . $ref . ': license_class is empty');
			}
			if(isset($seen[$ref]))
			{
				throw self::bad($ref . ' is listed twice');
			}
			$seen[$ref] = true;
			$test_ids[$t['id']] = true;
			$have_test[$ref] = true;
		}
		$suite_ids = array();
		foreach($ix['suites'] as $s)
		{
			$ref = $s['id'] . '@' . $s['version'];
			if(!self::good_id($s['id']))
			{
				throw self::bad('suite ' . json_encode($s['id']) . ': the id is not benchy/<lowercase name> within ' . self::MAX_ID_BYTES . ' bytes');
			}
			else if(!self::good_version($s['version']))
			{
				throw self::bad('suite ' . $s['id'] . ': the version ' . json_encode($s['version']) . ' is not x.y.z');
			}
			else if(!self::good_version($s['min_engine']))
			{
				throw self::bad('suite ' . $s['id'] . ': min_engine ' . json_encode($s['min_engine']) . ' is not x.y.z');
			}
			else if(!self::good_hex($s['suite_sha256']))
			{
				throw self::bad('suite ' . $s['id'] . ': suite_sha256 is not 64 lowercase hex digits');
			}
			else if(count($s['tests']) === 0)
			{
				throw self::bad('suite ' . $s['id'] . ' names no tests');
			}
			if(isset($seen[$ref]))
			{
				throw self::bad($ref . ' is listed twice');
			}
			$seen[$ref] = true;
			$suite_ids[$s['id']] = true;
			if(self::suite_sha256($s['tests']) !== $s['suite_sha256'])
			{
				throw self::bad('suite ' . $ref . ': suite_sha256 is not the hash of its tests');
			}
			foreach($s['tests'] as $e)
			{
				if($e['version'] !== null)
				{
					if(!self::good_version($e['version']) || !isset($have_test[$e['test'] . '@' . $e['version']]))
					{
						throw self::bad('suite ' . $ref . ' names ' . $e['test'] . '@' . $e['version'] . ', which is not in this index');
					}
				}
				else if(!isset($test_ids[$e['test']]))
				{
					throw self::bad('suite ' . $ref . ' names ' . $e['test'] . ', which is not in this index');
				}
			}
		}
		foreach($suite_ids as $id => $_)
		{
			if(isset($test_ids[$id]))
			{
				throw self::bad($id . ' is both a test and a suite');
			}
		}
		foreach($ix['revoked'] as $r)
		{
			if(!self::good_id($r['id']) || !self::good_version($r['version']))
			{
				throw self::bad('a revocation names ' . json_encode($r['id']) . '@' . json_encode($r['version']) . ', which is not a valid id and version');
			}
		}
	}

	// ---- the pair, the ledger and the license (6.2 steps 7 and 9, catalog spec 9.3 check 4) ----

	private static function pair_less($a, $b)
	{
		return $a[0] !== $b[0] ? $a[0] < $b[0] : $a[1] < $b[1];
	}

	// 6.2 steps 2 to 9, plus the license check, for one index.signed file against $state. $want_tier is the tier of the route it came
	// from, or '' for a bundle. $stored($tier) returns the signed file stored for a tier, or null. $parse is the function that reads the
	// index bytes after the signature has passed (a test replaces it with a counting one: nothing parses before verification).
	// Returns array('unchanged', 'same_file', 'index' (null when nothing was parsed), 'key', 'tier', 'recovery', 'signed_sha', 'next').
	// Changes nothing; the caller stores the file and then 'next'. Throws phoromatic_catalog_refusal.
	public static function evaluate($trust, $state, $want_tier, $file, $stored = null, $parse = null)
	{
		self::check_state($state);
		if($parse === null)
		{
			$parse = array(__CLASS__, 'parse_index');
		}
		$sha = self::file_sha256($file);
		// Step 2: the same file as one already accepted
		foreach($state['tiers'] as $tier => $ts)
		{
			if($ts['signed_sha256'] === $sha)
			{
				$id = self::key_id_of($file);
				if($trust->is_denied($id))
				{
					throw self::refuse(phoromatic_catalog_refusal::DENIED_KEY, 'the index is signed by key ' . $id . ', which is known to be compromised');
				}
				if($want_tier !== '' && $want_tier !== $tier)
				{
					throw self::refuse(phoromatic_catalog_refusal::BAD_TIER, 'the index is for the ' . $tier . ' tier, not ' . $want_tier);
				}
				if($ts['generation'] < $state['retired_below'])
				{
					throw self::refuse(phoromatic_catalog_refusal::RETIRED_KEY, 'the ' . $tier . ' index is signed by a key of generation ' . $ts['generation'] . ', which has been retired (below ' . $state['retired_below'] . '); import a current one');
				}
				return array('unchanged' => true, 'same_file' => true, 'index' => null, 'key' => null, 'tier' => $tier, 'recovery' => false, 'signed_sha' => $sha, 'next' => $state);
			}
		}
		// Steps 3 to 5
		list($key, $raw) = self::verify_signature($trust, $file, $state['retired_below']);
		return self::evaluate_index($trust, $state, $want_tier, $key, $raw, $sha, $stored, $parse);
	}

	// 6.2 steps 6 to 9 and the license check, once the signing key is known (the engine's evaluateIndex). The signature has already passed
	// when the server calls this; tests call it with index bytes and a key array(id, generation) to reach every rule below the
	// signature without a signing key. $parse is as for evaluate().
	public static function evaluate_index($trust, $state, $want_tier, $key, $raw, $sha, $stored = null, $parse = null)
	{
		self::check_state($state);
		if($parse === null)
		{
			$parse = array(__CLASS__, 'parse_index');
		}
		$ix = call_user_func($parse, $raw, $key['generation']);
		if($want_tier !== '' && $ix['tier'] !== $want_tier)
		{
			throw self::refuse(phoromatic_catalog_refusal::BAD_TIER, 'the index is for the ' . $ix['tier'] . ' tier, but it came from the ' . $want_tier . ' route');
		}
		$tier = $ix['tier'];
		$ts = isset($state['tiers'][$tier]) ? $state['tiers'][$tier] : null;
		$have = $ts === null ? array(0, 0) : array($ts['generation'], $ts['sequence']);
		$got = array($key['generation'], $ix['sequence']);
		// Step 7
		$floor = $trust->floor_of($tier);
		if($floor !== null && self::pair_less($got, array($floor['generation'], $floor['sequence'])))
		{
			throw self::refuse(phoromatic_catalog_refusal::FLOOR, 'the ' . $tier . ' index (generation ' . $got[0] . ', sequence ' . $got[1] . ') is below this server\'s floor (' . $floor['generation'] . ', ' . $floor['sequence'] . ')');
		}
		if(self::pair_less($got, $have))
		{
			throw self::refuse(phoromatic_catalog_refusal::ROLLBACK, 'the ' . $tier . ' index (generation ' . $got[0] . ', sequence ' . $got[1] . ') is older than the one already accepted (' . $have[0] . ', ' . $have[1] . ')');
		}
		if($got === $have)
		{
			$old = $stored === null ? null : call_user_func($stored, $tier);
			$old_index = null;
			if(is_string($old))
			{
				try
				{
					list(, $old_index) = self::split($old);
				}
				catch(phoromatic_catalog_refusal $r)
				{
					$old_index = null;
				}
			}
			if($old_index !== null)
			{
				if($old_index !== $raw)
				{
					throw self::refuse(phoromatic_catalog_refusal::SAME_PAIR_DIFFERS, 'two different ' . $tier . ' indexes have the same number (generation ' . $got[0] . ', sequence ' . $got[1] . '): the signing process or the key is broken');
				}
				// the same index under another valid signature: the stored file stays (6.2 step 7)
				return array('unchanged' => true, 'same_file' => false, 'index' => $ix, 'key' => $key, 'tier' => $tier, 'recovery' => false, 'signed_sha' => $sha, 'next' => $state);
			}
			// the stored file is missing or damaged, so there is nothing to compare with: take this one as a fresh accept (the ledger below
			// still applies) and record its hash
		}
		// Step 9: the ledger, unless this is a recovery index (5.3)
		$recovery = $key['generation'] > $have[0] && $ix['min_generation'] !== null && $ix['min_generation'] === $key['generation'];
		$revoked = array();
		foreach($ix['revoked'] as $r)
		{
			$revoked[$r['id'] . '@' . $r['version']] = true;
		}
		foreach($ix['tests'] as $t)
		{
			$ref = $t['id'] . '@' . $t['version'];
			if(isset($revoked[$ref]))
			{
				throw self::refuse(phoromatic_catalog_refusal::REVOKED_LISTED, $ref . ' is listed as a test and as revoked');
			}
		}
		if($ts !== null && !$recovery)
		{
			foreach($ix['tests'] as $t)
			{
				$ref = $t['id'] . '@' . $t['version'];
				if(isset($ts['tests'][$ref]) && $ts['tests'][$ref] !== $t['tree_sha256'])
				{
					throw self::refuse(phoromatic_catalog_refusal::LEDGER_CONFLICT, $ref . ' changed its tree: it was accepted as ' . substr($ts['tests'][$ref], 0, 12) . ' and is now ' . substr($t['tree_sha256'], 0, 12));
				}
			}
			foreach($ix['suites'] as $s)
			{
				$ref = $s['id'] . '@' . $s['version'];
				if(isset($ts['suites'][$ref]) && $ts['suites'][$ref] !== $s['suite_sha256'])
				{
					throw self::refuse(phoromatic_catalog_refusal::LEDGER_CONFLICT, 'suite ' . $ref . ' changed its content');
				}
			}
			foreach($ts['revoked'] as $r)
			{
				if(!isset($revoked[$r]))
				{
					throw self::refuse(phoromatic_catalog_refusal::REVOCATION_DROPPED, 'the index drops the revocation of ' . $r);
				}
			}
		}
		// The server's own check (catalog spec 9.3, check 4)
		self::check_license($ix);

		$next = $state;
		$nt = array('generation' => $key['generation'], 'sequence' => $ix['sequence'], 'signed_sha256' => $sha, 'tests' => array(), 'suites' => array(), 'revoked' => array());
		if($ts !== null && !$recovery)
		{
			$nt['tests'] = $ts['tests'];
			$nt['suites'] = $ts['suites'];
			$nt['revoked'] = $ts['revoked'];
		}
		foreach($ix['tests'] as $t)
		{
			$nt['tests'][$t['id'] . '@' . $t['version']] = $t['tree_sha256'];
		}
		foreach($ix['suites'] as $s)
		{
			$nt['suites'][$s['id'] . '@' . $s['version']] = $s['suite_sha256'];
		}
		if($recovery)
		{
			$nt['revoked'] = array();
		}
		foreach(array_keys($revoked) as $r)
		{
			if(!in_array($r, $nt['revoked'], true))
			{
				$nt['revoked'][] = $r;
			}
		}
		sort($nt['revoked'], SORT_STRING);
		$next['tiers'][$tier] = $nt;
		if($ix['min_generation'] !== null && $ix['min_generation'] > $next['retired_below'])
		{
			$next['retired_below'] = $ix['min_generation'];
		}
		return array('unchanged' => false, 'same_file' => false, 'index' => $ix, 'key' => $key, 'tier' => $tier, 'recovery' => $recovery, 'signed_sha' => $sha, 'next' => $next);
	}

	// A public index may contain only entries whose license_class is free and whose manifest has no machine_install and no elevated_run
	// key, whatever the value, null included (catalog spec 9.3). A key spelled in another case was already refused as bad_index.
	public static function check_license($ix)
	{
		if($ix['tier'] !== 'public')
		{
			return;
		}
		foreach($ix['tests'] as $t)
		{
			$ref = $t['id'] . '@' . $t['version'];
			if($t['license_class'] !== 'free')
			{
				throw self::refuse(phoromatic_catalog_refusal::LICENSE_TIER, 'the public catalog lists ' . $ref . ', which is ' . $t['license_class']);
			}
			if(property_exists($t['manifest'], 'machine_install') || property_exists($t['manifest'], 'elevated_run'))
			{
				throw self::refuse(phoromatic_catalog_refusal::LICENSE_TIER, 'the public catalog lists ' . $ref . ', which ' . (property_exists($t['manifest'], 'machine_install') ? 'installs software for all users' : 'runs as administrator'));
			}
		}
	}

	// ---- a bundle (7.2) ----

	// Steps 1 to 10 for one bundle's bytes: the zip's structure, then the index, then the pack members. Nothing is written. $sink, when
	// given, is called as $sink($member_name, $chunk) with each pack member's unpacked bytes, so the importer can stage them while they are
	// hashed. Returns the decision of evaluate() and 'packs' => array(sha256 => size) of what the bundle holds.
	public static function check_bundle($trust, $state, $bundle, $stored = null, $parse = null, $sink = null)
	{
		$zip = phoromatic_catalog_zip::inspect($bundle);
		$member = $zip->lookup('index.signed');
		if($member === null)
		{
			throw self::refuse(phoromatic_catalog_refusal::PACK_PATH, 'the bundle has no index.signed');
		}
		$file = $zip->read_all($member, self::MAX_INDEX_BYTES);
		$decision = self::evaluate($trust, $state, '', $file, $stored, $parse);
		// the packs are read only now, after the signature (7.2)
		$packs = array();
		foreach($zip->files() as $ent)
		{
			if($ent['name'] === 'index.signed')
			{
				continue;
			}
			$h = hash_init('sha256');
			$name = $ent['name'];
			$zip->stream($ent, function($chunk) use ($h, $sink, $name)
			{
				hash_update($h, $chunk);
				if($sink !== null)
				{
					call_user_func($sink, $name, $chunk);
				}
			});
			$sum = hash_final($h);
			if($name !== 'packs/' . $sum . '.zip')
			{
				throw self::refuse(phoromatic_catalog_refusal::BUNDLE_PACK_HASH, $name . ' doesn\'t hash to its name');
			}
			$packs[$sum] = $ent['usize'];
		}
		$ix = $decision['index'];
		if($ix === null)
		{
			// the same file as one already accepted: read it again only to check the packs
			list(, $raw) = self::split($file);
			$ix = self::parse_index($raw, PHP_INT_MAX);
		}
		self::check_bundle_packs($ix, $packs);
		$decision['packs'] = $packs;
		return $decision;
	}

	// 7.2 after the signature: every pack member is in the index, every index test has a pack member, and each pack's bytes hash to its
	// name (checked by the caller) and have the size pack_size says
	public static function check_bundle_packs($ix, $packs)
	{
		$want = array();
		foreach($ix['tests'] as $t)
		{
			$want[$t['pack_sha256']] = $t['pack_size'];
		}
		ksort($packs, SORT_STRING);
		foreach($packs as $sha => $size)
		{
			$sha = (string)$sha;
			if(!isset($want[$sha]))
			{
				throw self::refuse(phoromatic_catalog_refusal::BUNDLE_PACK_UNLISTED, 'the bundle holds a pack (' . substr($sha, 0, 12) . ') that the index doesn\'t list');
			}
			if($size !== $want[$sha])
			{
				throw self::refuse(phoromatic_catalog_refusal::BUNDLE_PACK_HASH, 'the pack ' . substr($sha, 0, 12) . ' in the bundle doesn\'t hash to its name and size');
			}
		}
		foreach($want as $sha => $_)
		{
			if(!isset($packs[$sha]))
			{
				throw self::refuse(phoromatic_catalog_refusal::BUNDLE_PACK_MISSING, 'the index lists a pack (' . substr((string)$sha, 0, 12) . ') that the bundle doesn\'t hold');
			}
		}
	}
}

?>
