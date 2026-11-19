<?php

/*
	Phoronix Test Suite
	URLs: http://www.phoronix.com, http://www.phoronix-test-suite.com/
	Copyright (C) 2015 - 2026, Phoronix Media
	Copyright (C) 2015 - 2026, Michael Larabel

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

// Test categories and tags (engine spec/categories.md, Benchy 0.12.0): the fixed lists, the legacy alias table, the tag grammar and the run-length classes.
// The engine checks a manifest against the same lists; this side only reads what a catalog entry or an agent's report carries, and never trusts it.

class phoromatic_native_taxonomy
{
	// The categories in the order every page shows them (spec 1); anything else groups as Other
	const OTHER = 'other';
	public static $categories = array('cpu' => 'CPU', 'memory' => 'Memory', 'storage' => 'Storage', 'graphics' => 'Graphics', 'network' => 'Network', 'system' => 'Whole system', 'application' => 'Applications');
	public static $tags = array('compression' => 'Compression', 'encoding' => 'Video encoding', 'rendering' => 'Rendering', 'compilation' => 'Compiling', 'crypto' => 'Cryptography', 'database' => 'Database', 'chess' => 'Chess',
		'synthetic' => 'Synthetic', 'real-app' => 'Real application', 'productivity' => 'Productivity', 'single-thread' => 'Single-thread', 'multi-thread' => 'Multi-thread', 'gaming' => 'Gaming', 'mining' => 'Mining');
	// PTS and old values (spec 3.1), by lower-cased name
	protected static $legacy = array('processor' => 'cpu', 'memory' => 'memory', 'disk' => 'storage', 'graphics' => 'graphics', 'network' => 'network', 'system' => 'system', 'application' => 'application');
	const MAX_TAGS = 6;
	const SHORT_MAX_S = 300;
	const MEDIUM_MAX_S = 1200;

	// The key a category value stands for: a key as it is, a PTS name through the alias table (any case), else 'other'
	public static function category_key($raw)
	{
		if(!is_string($raw))
		{
			return self::OTHER;
		}
		if(isset(self::$categories[$raw]))
		{
			return $raw;
		}
		$low = strtolower($raw);
		return isset(self::$legacy[$low]) ? self::$legacy[$low] : self::OTHER;
	}
	public static function category_label($key)
	{
		return isset(self::$categories[$key]) ? self::$categories[$key] : 'Other';
	}
	// What a manifest or a report's tag list comes to on this side: the well-formed strings (lower-case words joined by single hyphens, at most 32 bytes), first seen, at most six, in order.
	// The page escapes all text as well; this is the second line behind the engine's own check.
	public static function clean_tags($raw)
	{
		$out = array();
		foreach(is_array($raw) ? $raw : array() as $t)
		{
			if(is_string($t) && strlen($t) <= 32 && preg_match('/^[a-z0-9]+(-[a-z0-9]+)*\z/', $t) === 1 && !in_array($t, $out, true))
			{
				$out[] = $t;
				if(count($out) == self::MAX_TAGS)
				{
					break;
				}
			}
		}
		return $out;
	}
	// A tag's label: the list's word, or the key itself for a well-formed tag this release doesn't know
	public static function tag_label($key)
	{
		return isset(self::$tags[$key]) ? self::$tags[$key] : $key;
	}
	// Short up to 300 s, Medium to 1200 s, Long above; '' when unknown (spec 6)
	public static function length_class($seconds)
	{
		if(!(is_int($seconds) || is_float($seconds)) || !is_finite($seconds) || $seconds <= 0)
		{
			return '';
		}
		return $seconds <= self::SHORT_MAX_S ? 'short' : ($seconds <= self::MEDIUM_MAX_S ? 'medium' : 'long');
	}
	// The same facts for a manifest or a TestJSON object: array(cat, tags, len)
	public static function facts($category, $tags, $estimated_s)
	{
		return array('cat' => self::category_key($category), 'tags' => self::clean_tags($tags), 'len' => self::length_class($estimated_s));
	}
	// For the page's script: the lists and their labels
	public static function lists()
	{
		$cats = array();
		foreach(self::$categories as $k => $l)
		{
			$cats[] = array($k, $l);
		}
		return array('cats' => $cats, 'tagLabels' => self::$tags);
	}
	// "CPU, Compression, Multi-thread" for a row of text
	public static function tag_text($tags)
	{
		return implode(', ', array_map(array('phoromatic_native_taxonomy', 'tag_label'), $tags));
	}
}

?>
