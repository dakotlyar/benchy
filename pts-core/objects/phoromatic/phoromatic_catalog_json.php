<?php

/*
	Benchy
	phoromatic_catalog_json.php: strict JSON for the signed catalog (E4.4 C1, server design 3.3)

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

// Reading the signature line and the index the way the engine does (server design 3.3). Go's encoding/json matches struct keys
// case-insensitively and PHP's json_decode doesn't, so a signed index with "Min_Generation" would mean one thing to every engine and
// another to the server. This refuses, as bad_index (bad_envelope for the signature line):
//   - an object read as a record with a key that equals one of its known keys under ASCII case folding but isn't spelled exactly, or two
//     keys equal under case folding;
//   - a key in a record with any byte outside ASCII (Go folds with Unicode simple folding, so U+017F and U+212A match s and k);
//   - input that isn't valid UTF-8, or nests deeper than 64.
// Integers are checked by the typed readers in phoromatic_catalog_verify (a big integer comes back as a string, and 1.0 as a float).
// A suite test's "options" is a map, not a record, so its keys can have any case.

class phoromatic_catalog_json
{
	const DEPTH = 64;

	// The records, with their known keys and the keys that hold more records: 'obj' is one record, 'list' a list of them
	private static $records = array(
		'sig' => array(array('schema', 'key_id', 'sig'), array()),
		'root' => array(array('schema', 'repo', 'tier', 'sequence', 'issued', 'min_generation', 'tests', 'suites', 'revoked'),
			array('tests' => array('list', 'test'), 'suites' => array('list', 'suite'), 'revoked' => array('list', 'revoked'))),
		'test' => array(array('id', 'version', 'format', 'pack_sha256', 'pack_size', 'tree_sha256', 'min_engine', 'platforms', 'license_class', 'software_license', 'manifest'),
			array('manifest' => array('obj', 'manifest'))),
		'suite' => array(array('id', 'version', 'title', 'description', 'min_engine', 'suite_sha256', 'tests'), array('tests' => array('list', 'suite_test'))),
		'suite_test' => array(array('test', 'version', 'options'), array()),
		'revoked' => array(array('id', 'version', 'reason'), array()),
		'manifest' => array(array('id', 'version', 'title', 'min_engine', 'platforms', 'description', 'category', 'license', 'status', 'disk_size_mb', 'estimated_run_s', 'dependencies', 'timeout_s', 'trials', 'metrics', 'parse', 'options', 'lineage', 'machine_install', 'elevated_run'),
			array('trials' => array('obj', 'trials'), 'metrics' => array('list', 'metric'), 'parse' => array('list', 'parse'), 'options' => array('list', 'option'),
				'lineage' => array('list', 'lineage'), 'machine_install' => array('obj', 'machine_install'), 'elevated_run' => array('obj', 'elevated_run'))),
		'trials' => array(array('count'), array()),
		'metric' => array(array('name', 'unit', 'direction'), array()),
		'parse' => array(array('metric', 'regex', 'divide_by'), array()),
		'option' => array(array('key', 'label', 'kind', 'help', 'default', 'choices'), array('choices' => array('list', 'choice'))),
		'choice' => array(array('key', 'label', 'help'), array()),
		'lineage' => array(array('ref', 'version', 'parity'), array()),
		'machine_install' => array(array('product', 'message', 'file', 'kind', 'detect', 'args', 'signer'), array()),
		'elevated_run' => array(array('message'), array()),
	);

	// Decodes $text (objects come back as stdClass, so {} and [] stay different) and checks every record under $record. Throws a refusal
	// with $reason.
	public static function decode($text, $record, $reason)
	{
		// (plain PCRE, no mbstring function: the verifier also runs where mbstring is missing and where pts_strings is not loaded)
		if(preg_match('//u', $text) !== 1)
		{
			throw new phoromatic_catalog_refusal($reason, 'the JSON is not valid UTF-8');
		}
		try
		{
			$v = json_decode($text, false, self::DEPTH, JSON_BIGINT_AS_STRING | JSON_THROW_ON_ERROR);
		}
		catch(JsonException $e)
		{
			throw new phoromatic_catalog_refusal($reason, 'the JSON is not valid: ' . $e->getMessage());
		}
		if(!is_object($v))
		{
			throw new phoromatic_catalog_refusal($reason, 'the JSON is not an object');
		}
		self::check($v, $record, $reason);
		return $v;
	}

	private static function check($obj, $record, $reason)
	{
		list($known, $children) = self::$records[$record];
		$folded = array();
		foreach(array_keys(get_object_vars($obj)) as $key)
		{
			$key = (string)$key;
			if(preg_match('/[^\x00-\x7f]/', $key) === 1)
			{
				throw new phoromatic_catalog_refusal($reason, 'a key holds a character outside ASCII, which the engine would fold to an ASCII key');
			}
			$low = strtolower($key);
			if(isset($folded[$low]))
			{
				throw new phoromatic_catalog_refusal($reason, 'two keys are the same under case folding: ' . $folded[$low] . ' and ' . $key);
			}
			$folded[$low] = $key;
			if($key !== $low || !in_array($key, $known, true))
			{
				// a key that is a known one under case folding but isn't spelled exactly
				foreach($known as $k)
				{
					if($k !== $key && $k === $low)
					{
						throw new phoromatic_catalog_refusal($reason, 'the key ' . $key . ' is spelled in another case than ' . $k . ', which the engine would read as the same key');
					}
				}
			}
		}
		foreach($children as $key => $how)
		{
			if(!property_exists($obj, $key))
			{
				continue;
			}
			$v = $obj->$key;
			if($how[0] === 'obj' && is_object($v))
			{
				self::check($v, $how[1], $reason);
			}
			else if($how[0] === 'list' && is_array($v))
			{
				foreach($v as $item)
				{
					if(is_object($item))
					{
						self::check($item, $how[1], $reason);
					}
				}
			}
		}
	}
}

?>
