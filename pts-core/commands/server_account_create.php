<?php

/*
	Benchy server installer (0.9.8). This program is free software; you can redistribute it and/or modify it under the terms of the
	GNU General Public License as published by the Free Software Foundation; either version 3 of the License, or (at your option) any later version.
*/

class server_account_create implements pts_option_interface
{
	const doc_section = 'Phoromatic';
	const doc_description = 'Run on the Phoromatic Server host (Linux), as the user that owns the server\'s database. Creates a Phoromatic account with its first administrator user, with the same checks as the registration form (and works while registration is closed). The password is asked for twice without echo on a terminal; otherwise it is one line read from standard input (an empty or closed input is an error), so it never appears in the process list. No email is sent. Usage: server-account-create --user NAME --email ADDRESS';

	public static function run($r)
	{
		$user = pts_server_address::take_option($r, '--user');
		$email = pts_server_address::take_option($r, '--email');
		if(!empty($r) || $user === null || $user === '' || $email === null || $email === '')
		{
			echo PHP_EOL . 'Usage: server-account-create --user NAME --email ADDRESS   (the password is read from the terminal or one line of standard input)' . PHP_EOL . PHP_EOL;
			exit(2);
		}
		if(phoromatic_tls::cli_prelude('server-account-create') === false)
		{
			exit(1);
		}

		if(function_exists('posix_isatty') && posix_isatty(STDIN))
		{
			$password = pts_user_io::prompt_user_input('Password for ' . $user, false, true);
			$confirm = pts_user_io::prompt_user_input('Password again', false, true);
			echo PHP_EOL;
		}
		else
		{
			$line = fgets(STDIN);
			if($line === false || ($password = rtrim($line, "\r\n")) === '')
			{
				echo PHP_EOL . 'ERROR: No password was given. Pipe one line to standard input, or run this on a terminal. No account was created.' . PHP_EOL . PHP_EOL;
				exit(1);
			}
			$confirm = $password;
		}

		if(($error = phoromatic_accounts::create($user, $password, $confirm, $email)) !== null)
		{
			echo PHP_EOL . 'ERROR: ' . trim(strip_tags($error)) . ' No account was created.' . PHP_EOL . PHP_EOL;
			exit(1);
		}
		$account_id = phoromatic_server::$db->querySingle('SELECT AccountID FROM phoromatic_users WHERE UserName = \'' . SQLite3::escapeString($user) . '\'');
		echo PHP_EOL . 'Account created: user ' . $user . ' in account ' . $account_id . '. It can log in at the server\'s address now.' . PHP_EOL . PHP_EOL;
	}
}

?>
