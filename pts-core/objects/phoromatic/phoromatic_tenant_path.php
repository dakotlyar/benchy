<?php

/*
	Benchy
	phoromatic_tenant_path.php: the one check for a request-derived value that becomes a file or folder name

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

// Every account folder name, ticket, upload, system and suite name that came from a request goes through segment() before it reaches
// phoromatic_server::phoromatic_account_*_path() or find_suite_file(). tests/tenancy/lint_paths.py fails the run on any call that does not.

class phoromatic_tenant_path
{
	public static function segment($value)
	{
		// The value as one safe path segment, or false. Compare with === false: "0" is a valid segment. Pass the DECODED value;
		// a segment taken from the raw request URI goes through uri_segment() instead, which decodes it first.
		if(is_int($value))
		{
			$value = (string)$value;
		}
		if(!is_string($value) || preg_match('/^[A-Za-z0-9._-]{1,128}\z/', $value) !== 1 || $value === '.' || $value === '..')
		{
			return false;
		}
		return $value;
	}
	public static function uri_segment($raw)
	{
		// For a piece of the request URI as PHP leaves it (not decoded): decode once, then check, so %2e%2e%2f is seen as ../
		// A second layer of encoding stays in the value, where the check rejects the percent sign.
		return is_string($raw) ? self::segment(rawurldecode($raw)) : false;
	}
	public static function suite_name($value)
	{
		// A suite name as find_suite_file() takes it: a suite of the account (one segment) or an official suite (repository/name,
		// two segments), else false
		if(!is_string($value))
		{
			return false;
		}
		$parts = explode('/', $value);
		if(count($parts) > 2)
		{
			return false;
		}
		foreach($parts as $part)
		{
			if(self::segment($part) === false)
			{
				return false;
			}
		}
		return $value;
	}
}

?>
