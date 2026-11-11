<?php

/*
	Benchy
	Private test profile / test suite repository (Phoromatic Server hosted).

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

class private_repo_token_create implements pts_option_interface
{
	const doc_section = 'Private Repository';
	const doc_description = 'Run on the Phoromatic Server host, as the user that owns the server\'s database (e.g. sudo -u USER benchy ...; the command refuses to run as anyone else so it cannot leave files the server cannot use). Creates a repository token for one account (the only active one, or with several the one named by --tenant: an account ID or its name), prints it once (only a hash is stored) and records the creation in the audit log. Hand it to the person or machine that needs to upload to, delete from or rebuild the private repository, who then runs private-repo-login. Usage: private-repo-token-create LABEL [--tenant ACCOUNT]';

	public static function run($r)
	{
		$tenant = pts_server_address::take_option($r, '--tenant');
		if($tenant === '' || !isset($r[0]) || trim(implode(' ', $r)) === '')
		{
			echo PHP_EOL . 'Usage: private-repo-token-create LABEL [--tenant ACCOUNT]   (LABEL: e.g. the machine or person who will use it)' . PHP_EOL . PHP_EOL;
			return false;
		}
		if(($problem = phoromatic_private_repo_auth::server_command_preflight('private-repo-token-create LABEL')) !== null)
		{
			echo PHP_EOL . 'ERROR: ' . $problem . PHP_EOL . PHP_EOL;
			return false;
		}
		if(($account_id = phoromatic_tenant::cli_account($tenant, $error)) === false)
		{
			echo PHP_EOL . 'ERROR: ' . $error . PHP_EOL . PHP_EOL;
			return false;
		}

		$result = phoromatic_private_repo_auth::create($account_id, implode(' ', $r), 'user', phoromatic_private_repo_auth::local_actor());
		if(isset($result['error']))
		{
			echo PHP_EOL . 'ERROR: ' . $result['error'] . PHP_EOL . PHP_EOL;
			return false;
		}

		echo PHP_EOL . 'Token #' . $result['id'] . ' (' . $result['label'] . ') for account ' . $account_id . ':' . PHP_EOL . PHP_EOL . '    ' . $result['token'] . PHP_EOL . PHP_EOL;
		echo 'Copy it now; it cannot be shown again. On the machine that will use it run: benchy private-repo-login' . PHP_EOL . PHP_EOL;
	}
}

?>
