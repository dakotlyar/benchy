<?php

/*
	Benchy
	phoromatic_json_schema.php: the small part of JSON Schema (2020-12) the protocol v2 schemas use, enough to check an agent's request
	bodies against the pinned schemas in static/protocol-v2/ and to test the server's replies against the same files.

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

// Supported: $ref (to "#", "#/$defs/x" and "<file>#/$defs/x" within the protocol-v2 directory), type (one or a list), enum, const,
// required, properties, additionalProperties (a schema), propertyNames, items, minItems, maxItems, minimum, maxLength, maxProperties,
// pattern, allOf, oneOf. Anything else in a schema is ignored (description, title, $id, ...), so a schema that needs more has to be
// checked by hand. Data is what json_decode($text, true) gives: an empty array can be an empty object or an empty list, and is
// accepted as either.

class phoromatic_json_schema
{
	protected static $documents = array();

	public static function directory()
	{
		return PTS_CORE_PATH . 'static/protocol-v2/';
	}
	protected static function document($file)
	{
		if(!isset(self::$documents[$file]))
		{
			$path = self::directory() . basename($file);
			$text = is_file($path) ? file_get_contents($path) : false;
			self::$documents[$file] = $text === false ? null : json_decode($text, true);
		}
		return self::$documents[$file];
	}
	// $reference is like "v2.schema.json#/$defs/poll_request" or "result-v1.schema.json#/$defs/upload". Returns a list of problems (empty = valid).
	public static function validate($data, $reference, $max_errors = 5)
	{
		$errors = array();
		$parts = explode('#', $reference, 2);
		$root = self::document($parts[0]);
		$schema = self::resolve($root, isset($parts[1]) ? $parts[1] : '');
		if($root === null || $schema === null)
		{
			return array('schema ' . $reference . ' not found');
		}
		self::check($data, $schema, $parts[0], '', $errors, $max_errors);
		return $errors;
	}
	protected static function resolve($root, $pointer)
	{
		$node = $root;
		foreach(array_filter(explode('/', $pointer), 'strlen') as $key)
		{
			if(!is_array($node) || !array_key_exists($key, $node))
			{
				return null;
			}
			$node = $node[$key];
		}
		return $node;
	}
	protected static function is_list($v)
	{
		return is_array($v) && ($v === array() || array_keys($v) === range(0, count($v) - 1));
	}
	protected static function type_ok($data, $type)
	{
		switch($type)
		{
			case 'string': return is_string($data);
			case 'boolean': return is_bool($data);
			case 'null': return $data === null;
			case 'integer': return is_int($data) || (is_float($data) && is_finite($data) && floor($data) == $data);
			case 'number': return is_int($data) || (is_float($data) && is_finite($data));
			case 'array': return is_array($data) && self::is_list($data);
			case 'object': return is_array($data) && ($data === array() || !self::is_list($data));
		}
		return false;
	}
	protected static function check($data, $schema, $file, $path, &$errors, $max)
	{
		if(count($errors) >= $max || !is_array($schema))
		{
			return;
		}
		$fail = function($why) use (&$errors, $path, $max) {
			if(count($errors) < $max)
			{
				$errors[] = ($path === '' ? '(body)' : $path) . ': ' . $why;
			}
		};
		if(isset($schema['$ref']))
		{
			$ref = $schema['$ref'];
			$parts = explode('#', $ref, 2);
			$ref_file = $parts[0] === '' ? $file : $parts[0];
			$root = self::document($ref_file);
			$target = $root === null ? null : self::resolve($root, isset($parts[1]) ? $parts[1] : '');
			if($target === null)
			{
				$fail('unresolved reference ' . $ref);
				return;
			}
			self::check($data, $target, $ref_file, $path, $errors, $max);
		}
		if(isset($schema['allOf']))
		{
			foreach($schema['allOf'] as $sub)
			{
				self::check($data, $sub, $file, $path, $errors, $max);
			}
		}
		if(isset($schema['oneOf']))
		{
			$matches = 0;
			foreach($schema['oneOf'] as $sub)
			{
				$sub_errors = array();
				self::check($data, $sub, $file, $path, $sub_errors, 1);
				if(empty($sub_errors))
				{
					$matches++;
				}
			}
			if($matches != 1)
			{
				$fail('matches ' . $matches . ' of the allowed shapes, not exactly one');
			}
		}
		if(isset($schema['type']))
		{
			$ok = false;
			foreach((array)$schema['type'] as $t)
			{
				$ok = $ok || self::type_ok($data, $t);
			}
			if(!$ok)
			{
				$fail('must be ' . implode(' or ', (array)$schema['type']));
				return;
			}
		}
		if(array_key_exists('const', $schema) && $data !== $schema['const'] && !(is_numeric($data) && is_numeric($schema['const']) && $data == $schema['const']))
		{
			$fail('must be ' . json_encode($schema['const']));
		}
		if(isset($schema['enum']) && !in_array($data, $schema['enum'], true))
		{
			$fail('is not one of ' . json_encode($schema['enum']));
		}
		if(is_string($data))
		{
			if(isset($schema['maxLength']) && mb_strlen($data, 'UTF-8') > $schema['maxLength'])
			{
				$fail('is longer than ' . $schema['maxLength']);
			}
			if(isset($schema['pattern']) && preg_match('~' . str_replace('~', '\\~', $schema['pattern']) . '~u', $data) !== 1)
			{
				$fail('does not match ' . $schema['pattern']);
			}
		}
		if((is_int($data) || is_float($data)) && isset($schema['minimum']) && $data < $schema['minimum'])
		{
			$fail('is below ' . $schema['minimum']);
		}
		if(is_array($data) && self::is_list($data) && $data !== array())
		{
			if(isset($schema['minItems']) && count($data) < $schema['minItems'])
			{
				$fail('needs at least ' . $schema['minItems'] . ' items');
			}
			if(isset($schema['maxItems']) && count($data) > $schema['maxItems'])
			{
				$fail('has more than ' . $schema['maxItems'] . ' items');
			}
			if(isset($schema['items']))
			{
				foreach($data as $i => $item)
				{
					self::check($item, $schema['items'], $file, $path . '[' . $i . ']', $errors, $max);
				}
			}
		}
		else if(is_array($data) && $data === array())
		{
			if(isset($schema['minItems']) && $schema['minItems'] > 0 && isset($schema['type']) && in_array('array', (array)$schema['type'], true) && !in_array('object', (array)$schema['type'], true))
			{
				$fail('needs at least ' . $schema['minItems'] . ' items');
			}
		}
		if(is_array($data) && !self::is_list($data))
		{
			if(isset($schema['required']))
			{
				foreach($schema['required'] as $key)
				{
					if(!array_key_exists($key, $data))
					{
						$fail('lacks ' . $key);
					}
				}
			}
			if(isset($schema['maxProperties']) && count($data) > $schema['maxProperties'])
			{
				$fail('has more than ' . $schema['maxProperties'] . ' properties');
			}
			if(isset($schema['propertyNames']['pattern']))
			{
				foreach(array_keys($data) as $name)
				{
					if(preg_match('~' . str_replace('~', '\\~', $schema['propertyNames']['pattern']) . '~u', (string)$name) !== 1)
					{
						$fail('property name ' . json_encode((string)$name) . ' does not match ' . $schema['propertyNames']['pattern']);
					}
				}
			}
			foreach($data as $name => $value)
			{
				if(isset($schema['properties'][$name]))
				{
					self::check($value, $schema['properties'][$name], $file, $path . '.' . $name, $errors, $max);
				}
				else if(isset($schema['additionalProperties']) && is_array($schema['additionalProperties']))
				{
					self::check($value, $schema['additionalProperties'], $file, $path . '.' . $name, $errors, $max);
				}
			}
		}
	}
}

?>
