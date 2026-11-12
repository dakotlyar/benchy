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

class private_repo_token_list implements pts_option_interface
{
	const doc_section = 'Private Repository';
	const doc_description = 'Run on the Phoromatic Server host, as the user that owns the server\'s database (e.g. sudo -u USER benchy ...; the command refuses to run as anyone else so it cannot leave files the server cannot use). Lists the repository tokens of the server\'s account (ID, label, who created it and when, last use, revocation). The token values are never stored, so they cannot be listed.';

	public static function run($r)
	{
		$tenant = pts_server_address::take_option($r, '--tenant');
		if($tenant === '' || !empty($r))
		{
			echo PHP_EOL . 'Usage: private-repo-token-list [--tenant ACCOUNT] (--tenant, an account ID or its name, is needed when the server has more than one active account)' . PHP_EOL . PHP_EOL;
			return false;
		}
		if(($problem = phoromatic_private_repo_auth::server_command_preflight('private-repo-token-list')) !== null)
		{
			echo PHP_EOL . 'ERROR: ' . $problem . PHP_EOL . PHP_EOL;
			exit(1);
		}
		if(($account_id = phoromatic_tenant::cli_account($tenant, $error, true)) === false)
		{
			echo PHP_EOL . 'ERROR: ' . $error . PHP_EOL . PHP_EOL;
			return false;
		}

		$tokens = phoromatic_private_repo_auth::list_tokens($account_id);
		echo PHP_EOL . 'Repository tokens for account ' . $account_id . ':' . PHP_EOL . PHP_EOL;
		if(empty($tokens))
		{
			echo '    (none; create one with private-repo-token-create LABEL)' . PHP_EOL;
		}
		foreach($tokens as $t)
		{
			echo '    #' . $t['TokenID'] . '  ' . ($t['RevokedOn'] ? 'REVOKED ' . $t['RevokedOn'] : 'active') . '  "' . $t['Label'] . '"  created ' . $t['CreatedOn'] . ' by ' . $t['CreatedBy'] .
				'  last used ' . ($t['LastUsedOn'] ? $t['LastUsedOn'] . ' from ' . $t['LastUsedIP'] : 'never') . PHP_EOL;
		}
		echo PHP_EOL;
	}
}

?>
