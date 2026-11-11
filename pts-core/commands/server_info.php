<?php

/*
	Benchy server installer (0.9.8). This program is free software; you can redistribute it and/or modify it under the terms of the
	GNU General Public License as published by the Free Software Foundation; either version 3 of the License, or (at your option) any later version.
*/

class server_info implements pts_option_interface
{
	const doc_section = 'Phoromatic';
	const doc_description = 'Run on the Phoromatic Server host (Linux), as the user that runs the Phoromatic server. Read-only: shows which configuration file this server reads, where its storage is and whether that came from PhoromaticStorage, the database and its schema version, the HTTP port the running service used (read from the last start-up block of the server log) and where that port came from, the built-in HTTPS settings, whether registration is open and how many accounts exist. Usage: server-info';

	public static function run($r)
	{
		if(phodevi::is_windows())
		{
			echo PHP_EOL . 'ERROR: The Phoromatic server isn\'t supported on Windows.' . PHP_EOL . PHP_EOL;
			exit(1);
		}
		list($storage, $storage_source, $storage_problem) = phoromatic_server::phoromatic_path_info();
		echo PHP_EOL . 'Configuration file: ' . pts_config::get_config_file_location() . PHP_EOL;
		if(($notice = phoromatic_server_info::etc_notice()) !== null)
		{
			echo 'Note: ' . $notice . PHP_EOL;
		}
		echo 'Storage: ' . $storage . ' (' . ($storage_source == 'PhoromaticStorage' ? 'from PhoromaticStorage in the configuration file' : 'the folder under the user data directory, because PhoromaticStorage is empty') . ')' . PHP_EOL;
		if($storage_problem !== null)
		{
			echo 'PROBLEM: ' . $storage_problem . ' The server will not start until that is fixed.' . PHP_EOL;
		}

		list($port, $source) = phoromatic_server_info::http_port_setting();
		if(($logged = phoromatic_server_info::logged_startup()) !== false)
		{
			echo 'HTTP port the service used at its last start: ' . $logged['port'] . ' (' . $logged['source'] . '; from ' . phoromatic_server_info::server_log_file() . ')' . PHP_EOL;
		}
		else
		{
			echo 'HTTP port the service used at its last start: unknown (no start-up block in ' . phoromatic_server_info::server_log_file() . '; the service has not started as this user)' . PHP_EOL;
		}
		if($logged === false || $logged['port'] != $port)
		{
			echo 'HTTP port a server started by hand from this shell would use: ' . $port . ' (' . $source . '; not used by the service)' . PHP_EOL;
		}

		$db_file = $storage . 'phoromatic.db';
		if(!is_file($db_file))
		{
			echo 'Database: none at ' . $db_file . ' yet (run ' . phoromatic_tls::as_service_user('benchy server-init') . ')' . PHP_EOL . PHP_EOL;
			return;
		}
		if(($problem = phoromatic_private_repo_auth::server_command_preflight('server-info')) !== null)
		{
			echo 'Database: ' . $db_file . ' exists, but: ' . $problem . PHP_EOL . PHP_EOL;
			return;
		}
		phoromatic_server::prepare_database(true);
		echo 'Database: ' . $db_file . ', schema version ' . phoromatic_server::read_database_version() . PHP_EOL;

		$https = phoromatic_tls::settings();
		echo 'HTTPS: ' . phoromatic_server_info::https_summary($https) . ($https['mode'] != 'off' && !empty($https['names']) ? ', names ' . implode(', ', $https['names']) : '') . PHP_EOL;
		echo 'Accounts: ' . phoromatic_accounts::account_count() . PHP_EOL;
		$message = phoromatic_accounts::registration_message();
		echo 'Registration: closed (' . $message . ')' . PHP_EOL . PHP_EOL;
	}
}

?>
