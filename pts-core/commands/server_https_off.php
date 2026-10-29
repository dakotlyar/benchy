<?php

/*
	Benchy built-in HTTPS (0.9). This program is free software; you can redistribute it and/or modify it under the terms of the
	GNU General Public License as published by the Free Software Foundation; either version 3 of the License, or (at your option) any later version.
*/

class server_https_off implements pts_option_interface
{
	const doc_section = 'Phoromatic';
	const doc_description = 'Run on the Phoromatic Server host (Linux), as the user that owns the server\'s database. Turns the built-in HTTPS off: the next start of the service is plain "php -S" on the HTTP port again, exactly as before HTTPS was set up. Clients that were switched to https:// do not fall back by themselves; re-point them with phoromatic.connect HOST:PORT/ACCOUNT. The change is recorded in the audit log. Usage: server-https-off';

	public static function run($r)
	{
		if(($account_id = phoromatic_tls::cli_prelude('server-https-off')) === false)
		{
			return false;
		}
		$before = phoromatic_tls::settings();
		if($before['mode'] == 'off')
		{
			echo PHP_EOL . 'The built-in HTTPS is already off.' . PHP_EOL . PHP_EOL;
			return true;
		}
		$after = $before;
		$after['mode'] = 'off';
		$error = phoromatic_tls::commit_change($account_id, 'cli', phoromatic_private_repo_auth::local_actor(), null, $after, array(array('https_mode_change',
			array('source' => 'cli', 'from' => $before['mode'], 'to' => 'off', 'names' => $before['names']))));
		if($error !== null)
		{
			echo PHP_EOL . 'ERROR: ' . $error . PHP_EOL . PHP_EOL;
			return false;
		}
		echo PHP_EOL . 'HTTPS is off. ' . phoromatic_tls::report_apply($before, $after) . PHP_EOL . PHP_EOL;
		return true;
	}
}

?>
