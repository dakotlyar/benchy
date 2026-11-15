<?php

/*
	Benchy
	phoromatic_catalog_keys.php: the catalog key table (E4.4 C1, server design 2.3)

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

// The key table of catalog spec 5.2 and server design 2.3: the program tree's pts-core/static/catalog/keys.json. Nothing else can change
// it (no environment variable, setting or flag), so the constructor that takes a table in memory is for the loader and for tests, which
// build tables from public keys only. Key ids are computed from the SPKI and never stored. A table that fails any check is refused as a
// whole, and the caller then fails closed.

class phoromatic_catalog_keys
{
	const FILE = 'pts-core/static/catalog/keys.json';
	const SCHEMA = 'benchy-catalog-keys/1';
	const MAX_SEQUENCE = 9007199254740991; // 2^53 - 1

	// Private so that nothing outside this class can add a key to a table that exists (a test builds a new table; production loads one)
	private $keys = array();     // list of array('id' =>, 'generation' =>, 'spki' =>, 'pem' =>)
	private $denied = array();   // key id => true
	private $floor = array();    // tier => array('generation' =>, 'sequence' =>)

	public static function load()
	{
		$path = PTS_PATH . self::FILE;
		$text = is_file($path) ? file_get_contents($path) : false;
		if($text === false)
		{
			throw new phoromatic_catalog_refusal(phoromatic_catalog_refusal::KEY_TABLE, 'The server\'s catalog key table is damaged: ' . $path . ' cannot be read.');
		}
		return self::from_json($text);
	}

	public static function from_json($text)
	{
		$table = json_decode($text, true, 16);
		if(!is_array($table) || !isset($table['schema']) || $table['schema'] !== self::SCHEMA)
		{
			throw self::damaged('it is not a ' . self::SCHEMA . ' table');
		}
		return new self(
			isset($table['keys']) ? $table['keys'] : null,
			isset($table['denied_key_ids']) ? $table['denied_key_ids'] : null,
			isset($table['floor']) ? $table['floor'] : null
		);
	}

	// $keys: list of array('generation' => int, 'spki' => base64 string); $denied: list of ids; $floor: tier => array('generation', 'sequence')
	public function __construct($keys, $denied, $floor)
	{
		if(!is_array($keys) || !is_array($denied) || !is_array($floor))
		{
			throw self::damaged('keys, denied_key_ids and floor must all be there');
		}
		if(!extension_loaded('openssl') || !function_exists('openssl_pkey_get_details'))
		{
			throw self::damaged('the openssl extension is missing');
		}
		$gens = array();
		foreach($keys as $k)
		{
			if(!is_array($k) || !isset($k['generation']) || !is_int($k['generation']) || $k['generation'] < 1 || isset($gens[$k['generation']]))
			{
				throw self::damaged('a key generation is below 1 or used twice');
			}
			$gens[$k['generation']] = true;
			if(!isset($k['spki']) || !is_string($k['spki']) || ($der = base64_decode($k['spki'], true)) === false || $der === '')
			{
				throw self::damaged('the key of generation ' . $k['generation'] . ' is not base64');
			}
			$pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
			$res = @openssl_pkey_get_public($pem);
			$details = $res ? openssl_pkey_get_details($res) : false;
			if(!$details || $details['type'] !== OPENSSL_KEYTYPE_EC || !isset($details['ec']['curve_name']) || $details['ec']['curve_name'] !== 'prime256v1')
			{
				throw self::damaged('the key of generation ' . $k['generation'] . ' is not an ECDSA P-256 public key');
			}
			$id = substr(hash('sha256', $der), 0, 32);
			foreach($this->keys as $have)
			{
				if($have['id'] === $id)
				{
					throw self::damaged('key ' . $id . ' is in the table twice');
				}
			}
			$this->keys[] = array('id' => $id, 'generation' => $k['generation'], 'spki' => $k['spki'], 'pem' => $pem);
		}
		foreach($denied as $d)
		{
			if(!is_string($d) || preg_match('/^[0-9a-f]{32}\z/', $d) !== 1)
			{
				throw self::damaged('a denied key id is not 32 lowercase hex digits');
			}
			$this->denied[$d] = true;
		}
		foreach($floor as $tier => $pair)
		{
			if(($tier !== 'public' && $tier !== 'private') || !is_array($pair) || !isset($pair['generation'], $pair['sequence']) || !is_int($pair['generation']) || !is_int($pair['sequence'])
				|| $pair['generation'] < 1 || $pair['sequence'] < 1 || $pair['sequence'] > self::MAX_SEQUENCE)
			{
				throw self::damaged('the floor for ' . var_export($tier, true) . ' is not a public or private tier with generation >= 1 and sequence 1 to 2^53-1');
			}
			$this->floor[$tier] = array('generation' => $pair['generation'], 'sequence' => $pair['sequence']);
		}
	}

	private static function damaged($why)
	{
		return new phoromatic_catalog_refusal(phoromatic_catalog_refusal::KEY_TABLE, 'The server\'s catalog key table is damaged: ' . $why . '.');
	}

	public function all_keys()
	{
		return $this->keys;
	}

	public function denied_ids()
	{
		return array_keys($this->denied);
	}

	// array('generation' =>, 'sequence' =>) or null
	public function floor_of($tier)
	{
		return isset($this->floor[$tier]) ? $this->floor[$tier] : null;
	}

	public function lookup($key_id)
	{
		foreach($this->keys as $k)
		{
			if($k['id'] === $key_id)
			{
				return $k;
			}
		}
		return null;
	}

	public function is_denied($key_id)
	{
		return isset($this->denied[$key_id]);
	}

	// What `benchy-engine catalog keys` prints, line for line, which is the format of benchy-tests/legal/catalog-keys.txt: the keys by
	// generation, then the denied ids, then the floors by tier. The release gate compares this text, so it is the same code that
	// computes the ids the server uses.
	public function summary_text()
	{
		$keys = $this->keys;
		usort($keys, function($a, $b) { return $a['generation'] <=> $b['generation']; });
		$out = '';
		foreach($keys as $k)
		{
			$out .= 'generation ' . $k['generation'] . ' ' . $k['id'] . "\n";
		}
		$denied = array_keys($this->denied);
		sort($denied, SORT_STRING);
		foreach($denied as $d)
		{
			$out .= 'denied ' . $d . "\n";
		}
		$tiers = array_keys($this->floor);
		sort($tiers, SORT_STRING);
		foreach($tiers as $t)
		{
			$out .= 'floor ' . $t . ' ' . $this->floor[$t]['generation'] . ' ' . $this->floor[$t]['sequence'] . "\n";
		}
		return $out;
	}
}

?>
