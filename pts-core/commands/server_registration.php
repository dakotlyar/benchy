<?php

/*
	Benchy server installer (0.9.8). This program is free software; you can redistribute it and/or modify it under the terms of the
	GNU General Public License as published by the Free Software Foundation; either version 3 of the License, or (at your option) any later version.
*/

class server_registration implements pts_option_interface
{
	const doc_section = 'Phoromatic';
	const doc_description = 'Run on the Phoromatic Server host (Linux), as the user that owns the server\'s database. Opens or closes account registration on the welcome page. "closed" replaces the registration form with a message (the default message, or --message TEXT); it is the same setting as the Account Creation String on the Admin Config page. With no argument it shows the current state. Usage: server-registration [open | closed [--message TEXT]]';

	public static function run($r)
	{
		$message = pts_server_address::take_option($r, '--message');
		$state = isset($r[0]) ? array_shift($r) : null;
		if(!empty($r) || ($state !== null && !in_array($state, array('open', 'closed'), true)) || ($message !== null && $state !== 'closed') || $message === '')
		{
			echo PHP_EOL . 'Usage: server-registration [open | closed [--message TEXT]]' . PHP_EOL . PHP_EOL;
			exit(2);
		}
		if(phoromatic_tls::cli_prelude('server-registration') === false)
		{
			exit(1);
		}
		if($state === 'open')
		{
			phoromatic_server::save_setting('account_creation_alt', '');
		}
		else if($state === 'closed')
		{
			phoromatic_server::save_setting('account_creation_alt', $message !== null ? $message : phoromatic_accounts::DEFAULT_CLOSED_MESSAGE);
		}
		$now = phoromatic_accounts::registration_message();
		echo PHP_EOL . 'Registration is ' . ($now === null ? 'open: anyone who can reach the server can create an account.' : 'closed. The welcome page says: ' . $now) . PHP_EOL . PHP_EOL;
	}
}

?>
