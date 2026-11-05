<?php

/*
	Benchy built-in HTTPS (0.9). This program is free software; you can redistribute it and/or modify it under the terms of the
	GNU General Public License as published by the Free Software Foundation; either version 3 of the License, or (at your option) any later version.
*/

class server_caddy_install implements pts_option_interface
{
	const doc_section = 'Phoromatic';
	const doc_description = 'Run on the Phoromatic Server host (Linux), as the user that owns the server\'s database. Installs the Caddy web server that the built-in HTTPS puts in front of the Phoromatic server: downloads the release pinned by this Benchy (checking its size and sha256) into the server\'s storage and records it. With --archive FILE the pinned tar.gz is taken from a file instead of the network; with --use PATH an existing Caddy (2.8 or newer, e.g. the distribution\'s) is recorded instead. Usage: server-caddy-install [--archive FILE | --use PATH]';

	public static function run($r)
	{
		$usage = 'server-caddy-install [--archive FILE | --use PATH]';
		$archive = pts_server_address::take_path_option($r, '--archive');
		$use = pts_server_address::take_path_option($r, '--use');
		if(!empty($r) || ($archive !== null && $use !== null) || $archive === '' || $use === '')
		{
			echo PHP_EOL . 'Usage: ' . $usage . PHP_EOL . PHP_EOL;
			return false;
		}
		if(phoromatic_tls::cli_prelude($usage) === false)
		{
			return false;
		}
		list($ok, $message) = phoromatic_tls::caddy_install($archive, $use);
		echo PHP_EOL . ($ok ? $message : 'ERROR: ' . $message) . PHP_EOL . PHP_EOL;
		if($ok)
		{
			echo 'Next: benchy server-https-set internal --names HOST,IP   (then restart the service)' . PHP_EOL . PHP_EOL;
		}
		return $ok;
	}
}

?>
