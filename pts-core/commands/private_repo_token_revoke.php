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

class private_repo_token_revoke implements pts_option_interface
{
	const doc_section = 'Private Repository';
	const doc_description = 'Run on the Phoromatic Server host, as the user that owns the server\'s database (e.g. sudo -u USER benchy ...; the command refuses to run as anyone else so it cannot leave files the server cannot use). Revokes a repository token immediately and records it in the audit log. Usage: private-repo-token-revoke TOKEN_ID [--tenant ACCOUNT] (see private-repo-token-list; --tenant, an account ID or its name, is needed when the server has more than one active account)';

	public static function run($r)
	{
		$tenant = pts_server_address::take_option($r, '--tenant');
		if($tenant === '' || !isset($r[0]) || !ctype_digit($r[0]) || isset($r[1]))
		{
			echo PHP_EOL . 'Usage: private-repo-token-revoke TOKEN_ID [--tenant ACCOUNT]   (IDs are shown by private-repo-token-list)' . PHP_EOL . PHP_EOL;
			return false;
		}
		if(($problem = phoromatic_private_repo_auth::server_command_preflight('private-repo-token-revoke TOKEN_ID')) !== null)
		{
			echo PHP_EOL . 'ERROR: ' . $problem . PHP_EOL . PHP_EOL;
			exit(1);
		}
		if(($account_id = phoromatic_tenant::cli_account($tenant, $error, true)) === false)
		{
			echo PHP_EOL . 'ERROR: ' . $error . PHP_EOL . PHP_EOL;
			return false;
		}

		$result = phoromatic_private_repo_auth::revoke($account_id, $r[0], 'user', phoromatic_private_repo_auth::local_actor());
		echo PHP_EOL . (isset($result['error']) ? 'ERROR: ' . $result['error'] : 'Token #' . $result['revoked'] . ' revoked.') . PHP_EOL . PHP_EOL;
		return !isset($result['error']);
	}
}

?>
