<?php

/*
	Benchy 0.9: the one place that knows the format of a Phoromatic server string.
	https://host:port/ACCOUNT, http://host:port/ACCOUNT, or the legacy host:port/ACCOUNT (= http). This program is free software; you can
	redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation;
	either version 3 of the License, or (at your option) any later version.
*/

class pts_server_address
{
	public static function parse($string)
	{
		// array(scheme, host, port, account) or false
		if(!is_string($string) || !preg_match('#^(?:(https?)://)?([^/:\s]+):([0-9]{1,5})/([A-Za-z0-9]+)$#', trim($string), $m) || $m[3] < 1 || $m[3] > 65535)
		{
			return false;
		}
		return array('scheme' => $m[1] === '' ? 'http' : strtolower($m[1]), 'host' => $m[2], 'port' => (int)$m[3], 'account' => $m[4]);
	}
	public static function to_string($a)
	{
		// The canonical form: the legacy form for http, so files written by 0.9 still read in 0.8
		return ($a['scheme'] == 'https' ? 'https://' : '') . $a['host'] . ':' . $a['port'] . '/' . $a['account'];
	}
	public static function base_url($scheme, $host, $port)
	{
		return ($scheme == 'https' ? 'https' : 'http') . '://' . $host . ':' . $port;
	}
	public static function take_option(&$args, $name)
	{
		// Removes "--name VALUE" from $args and returns VALUE; null if absent, '' if it has no value
		if(($at = array_search($name, $args, true)) === false)
		{
			return null;
		}
		$value = isset($args[$at + 1]) && strncmp($args[$at + 1], '--', 2) != 0 ? $args[$at + 1] : '';
		array_splice($args, $at, $value === '' ? 1 : 2);
		return $value;
	}
	public static function caller_path($path)
	{
		// The launcher changes into the install directory before PHP starts, so a relative file name given on the command line has to be read
		// against the directory the user was in (BENCHY_CALLER_DIR, set by the launcher). An absolute name, an empty one and a run without the
		// variable (PHP started by hand) are left as they are.
		$dir = getenv('BENCHY_CALLER_DIR');
		if($path === null || $path === '' || $dir === false || $dir === '' || preg_match('#^([/\\\\]|[A-Za-z]:)#', $path))
		{
			return $path;
		}
		return rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $path;
	}
	public static function take_path_option(&$args, $name)
	{
		// take_option() for an option whose value is a file name
		return self::caller_path(self::take_option($args, $name));
	}
}

?>
