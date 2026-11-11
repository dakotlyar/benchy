<?php

/*
	Benchy Phoromatic server: which settings are in force (0.9.8). This program is free software; you can redistribute it and/or modify it
	under the terms of the GNU General Public License as published by the Free Software Foundation; either version 3 of the License, or
	(at your option) any later version.
*/

class phoromatic_server_info
{
	const LOG_MARKER = 'Server settings in effect:';

	public static function http_port_setting()
	{
		// array(port, source): the HTTP port the server is told to use. PHOROMATIC_HTTP_PORT (the service unit sets it) wins over
		// RemoteAccessPort in the configuration file; with neither the port is 'RANDOM' (the server then picks a free one)
		$override = getenv('PHOROMATIC_HTTP_PORT');
		if($override && is_numeric($override) && $override > 1)
		{
			return array($override, 'PHOROMATIC_HTTP_PORT');
		}
		$configured = pts_config::read_user_config('PhoronixTestSuite/Options/Server/RemoteAccessPort', 'RANDOM');
		if($configured == 'RANDOM')
		{
			return array('RANDOM', 'RemoteAccessPort is RANDOM or not set, and PHOROMATIC_HTTP_PORT is not set');
		}
		return array($configured, 'RemoteAccessPort in ' . pts_config::get_config_file_location());
	}
	public static function etc_notice()
	{
		// /etc/benchy.xml is read by root only (a server that runs as its own user reads $HOME/.benchy/user-config.xml); say so when
		// it exists but is not the file in use, because a change made there then has no effect
		$etc = pts_config::SYSTEM_CONFIG_FILE;
		if(is_file($etc) && pts_config::get_config_file_location() != $etc)
		{
			return $etc . ' exists but this server does not read it; its settings are in ' . pts_config::get_config_file_location();
		}
		return null;
	}
	public static function https_summary($s)
	{
		return $s['mode'] == 'off' ? 'off (plain HTTP only)' : $s['mode'] . ' on port ' . $s['https_port'] . ', plain HTTP: ' . $s['http_mode'];
	}
	public static function startup_lines($http_port, $port_source, $https)
	{
		// The block the server prints and logs at every start
		list($storage, $storage_source, $storage_problem) = phoromatic_server::phoromatic_path_info();
		$lines = array(self::LOG_MARKER);
		$lines[] = '  Configuration file: ' . pts_config::get_config_file_location();
		$lines[] = '  Storage: ' . $storage . ' (' . ($storage_source == 'PhoromaticStorage' ? 'from PhoromaticStorage in the configuration file' : 'the folder under the user data directory, because PhoromaticStorage is empty') . ')';
		$lines[] = '  HTTP port: ' . $http_port . ' (' . $port_source . ')';
		$lines[] = '  HTTPS: ' . self::https_summary($https);
		if(($notice = self::etc_notice()) !== null)
		{
			$lines[] = '  Note: ' . $notice;
		}
		return $lines;
	}
	public static function server_log_file()
	{
		return pts_logger::default_log_file_path() . 'phoromatic.log';
	}
	public static function logged_startup()
	{
		// The last startup block in the server log (the log is cleared at every start), as array('port' => , 'source' => ), or false.
		// The command line cannot see the environment of the service unit, so this is how it learns the port the service really used.
		$file = self::server_log_file();
		if(!is_readable($file))
		{
			return false;
		}
		$log = file_get_contents($file);
		if(!is_string($log) || ($at = strrpos($log, self::LOG_MARKER)) === false)
		{
			return false;
		}
		if(preg_match('/HTTP port: (\S+) \(([^)]*)\)/', substr($log, $at), $m) !== 1)
		{
			return false;
		}
		return array('port' => $m[1], 'source' => $m[2]);
	}
}

?>
