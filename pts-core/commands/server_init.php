<?php

/*
	Benchy server installer (0.9.8). This program is free software; you can redistribute it and/or modify it under the terms of the
	GNU General Public License as published by the Free Software Foundation; either version 3 of the License, or (at your option) any later version.
*/

class server_init implements pts_option_interface
{
	const doc_section = 'Phoromatic';
	const doc_description = 'Run on the Phoromatic Server host (Linux), as the user that will run the Phoromatic server (never as root). Creates the server\'s storage folder and its database, with every schema update, the same as the first start of the server does, and prints the path and the schema version. The other server commands refuse to run until the database exists. Running it again changes nothing. Usage: server-init';

	public static function run($r)
	{
		if(phodevi::is_windows())
		{
			echo PHP_EOL . 'ERROR: The Phoromatic server isn\'t supported on Windows.' . PHP_EOL . PHP_EOL;
			exit(1);
		}
		if(!function_exists('posix_geteuid'))
		{
			echo PHP_EOL . 'ERROR: PHP\'s POSIX support is needed to check which user this runs as.' . PHP_EOL . PHP_EOL;
			exit(1);
		}
		if(posix_geteuid() === 0)
		{
			echo PHP_EOL . 'ERROR: server-init must not run as root; the database would belong to root and the server could not use it. Run it as the user that runs the server, e.g. sudo -u phoromatic env HOME=/var/lib/phoromatic benchy server-init' . PHP_EOL . PHP_EOL;
			exit(1);
		}
		if(!extension_loaded('sqlite3'))
		{
			echo PHP_EOL . 'ERROR: PHP SQLite3 support is needed (the php-sqlite3 package on Debian and Ubuntu).' . PHP_EOL . PHP_EOL;
			exit(1);
		}

		list($path, $source) = phoromatic_server::phoromatic_path_info();
		if(is_dir($path) && fileowner($path) !== posix_geteuid())
		{
			$owner = ($pw = posix_getpwuid(fileowner($path))) ? $pw['name'] : (string)fileowner($path);
			echo PHP_EOL . 'ERROR: The storage folder ' . $path . ' belongs to ' . $owner . ', not to this user. Nothing was changed.' . PHP_EOL . PHP_EOL;
			exit(1);
		}
		$existed = is_file($path . 'phoromatic.db');
		if($existed && ($problem = phoromatic_private_repo_auth::server_command_preflight('server-init')) !== null)
		{
			echo PHP_EOL . 'ERROR: ' . $problem . PHP_EOL . PHP_EOL;
			exit(1);
		}

		phoromatic_server::prepare_database();
		$version = phoromatic_server::read_database_version();
		echo PHP_EOL . 'Storage: ' . $path . ' (' . ($source == 'PhoromaticStorage' ? 'from PhoromaticStorage in ' . pts_config::get_config_file_location() : 'the fallback under the user data directory; PhoromaticStorage is empty or not usable') . ')' . PHP_EOL;
		echo 'Database: ' . $path . 'phoromatic.db, schema version ' . $version . PHP_EOL;
		echo ($existed ? 'The database was already there; nothing was created.' : 'The database was created.') . PHP_EOL . PHP_EOL;
	}
}

?>
