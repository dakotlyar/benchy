<?php

/*
	Benchy server installer (0.9.8). This program is free software; you can redistribute it and/or modify it under the terms of the
	GNU General Public License as published by the Free Software Foundation; either version 3 of the License, or (at your option) any later version.
*/

class server_registration implements pts_option_interface
{
	const doc_section = 'Phoromatic';
	const doc_description = 'Run on the Phoromatic Server host (Linux), as the user that owns the server\'s database. Shows or sets the text the welcome page gives where a registration form used to be. Since 0.9.12 nobody can create an account from the welcome page: accounts are made by the server\'s administrator with server-account-create, or by the root login on the Admin page. "closed" (the same as never having run this) sets that text to the default or to --message TEXT; it is the same setting as the Account Creation String on the Admin Config page. "open" is refused: open registration no longer exists, and a server that had it open is closed when it is upgraded. With no argument it shows the current text. Usage: server-registration [closed [--message TEXT]]';

	public static function run($r)
	{
		$message = pts_server_address::take_option($r, '--message');
		$state = isset($r[0]) ? array_shift($r) : null;
		if(!empty($r) || ($state !== null && !in_array($state, array('open', 'closed'), true)) || ($message !== null && $state !== 'closed') || $message === '')
		{
			echo PHP_EOL . 'Usage: server-registration [closed [--message TEXT]]' . PHP_EOL . PHP_EOL;
			exit(2);
		}
		if($state === 'open')
		{
			echo PHP_EOL . 'ERROR: Open registration was removed in 0.9.12: nobody can create an account from the welcome page any more, so there is nothing to open. Make an account on this server with: benchy server-account-create --user NAME --email ADDRESS' . PHP_EOL . PHP_EOL;
			exit(1);
		}
		if(phoromatic_tls::cli_prelude('server-registration') === false)
		{
			exit(1);
		}
		if($state === 'closed')
		{
			phoromatic_server::save_setting('account_creation_alt', $message !== null ? $message : phoromatic_accounts::DEFAULT_CLOSED_MESSAGE);
		}
		echo PHP_EOL . 'Registration is closed. The welcome page says: ' . phoromatic_accounts::registration_message() . PHP_EOL . PHP_EOL;
	}
}

?>
