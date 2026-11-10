<?php

/*
	Benchy
	phoromatic_audit_core.php: what the append-only audit tables share: bounded printable text, JSON detail, the mirror log line

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

// phoromatic_admin_audit (per account) and phoromatic_server_audit (server wide) both build their rows and log lines with these.

class phoromatic_audit_core
{
	const MAX_FIELD = 128;
	const MAX_DETAIL_STRING = 1000;

	public static function clean($value, $max = self::MAX_FIELD)
	{
		// Printable ASCII only and bounded, for both the stored value and the log line
		if($value === null)
		{
			return null;
		}
		$value = preg_replace('/[^\x20-\x7e]/', '?', is_scalar($value) ? (string)$value : '');
		return strlen($value) > $max ? substr($value, 0, $max) . '...' : $value;
	}
	public static function clean_detail($value)
	{
		if(is_array($value))
		{
			return array_map(array(__CLASS__, 'clean_detail'), $value);
		}
		return is_string($value) ? self::clean($value, self::MAX_DETAIL_STRING) : $value;
	}
	public static function encode_detail($detail)
	{
		$json = json_encode(self::clean_detail($detail), JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
		return $json === false ? '{"error":"detail could not be encoded"}' : $json;
	}
	public static function mirror($file, $line)
	{
		if(!is_file($file))
		{
			if(@file_put_contents($file, '') === false)
			{
				return false;
			}
			@chmod($file, 0640);
		}
		return @file_put_contents($file, '[' . date('Y-m-d\TH:i:sO') . '] ' . $line . PHP_EOL, FILE_APPEND | LOCK_EX) !== false;
	}
}

?>
