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
	const doc_description = 'Run on the Phoromatic Server host, as the user that runs the server. Revokes a repository token immediately and records it in the audit log. Usage: private-repo-token-revoke TOKEN_ID (see private-repo-token-list)';

	public static function run($r)
	{
		if(!isset($r[0]) || !ctype_digit($r[0]))
		{
			echo PHP_EOL . 'Usage: private-repo-token-revoke TOKEN_ID   (IDs are shown by private-repo-token-list)' . PHP_EOL . PHP_EOL;
			return false;
		}
		if(($account_id = phoromatic_private_repo::server_account_id()) == false)
		{
			echo PHP_EOL . 'ERROR: No Phoromatic account exists on this server (or its database is not readable by this user).' . PHP_EOL . PHP_EOL;
			return false;
		}

		$result = phoromatic_private_repo_auth::revoke($account_id, $r[0], 'user', phoromatic_private_repo_auth::local_actor());
		echo PHP_EOL . (isset($result['error']) ? 'ERROR: ' . $result['error'] : 'Token #' . $result['revoked'] . ' revoked.') . PHP_EOL . PHP_EOL;
		return !isset($result['error']);
	}
}

?>
