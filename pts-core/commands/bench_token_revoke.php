<?php

/*
	Benchy
	Hardware test benches: the bench's own command line (Benchy 0.9).

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
class bench_token_revoke implements pts_option_interface
{
	const doc_section = 'Test Benches';
	const doc_description = 'Run on the Phoromatic Server host as the database owner. Revokes a bench token (audited). Usage: bench-token-revoke TOKENID [--tenant ACCOUNT] (TOKENID as bench-token-list shows it, e.g. 4 or BT4; with several accounts on the server --tenant, an account ID or its name, says whose)';

	public static function run($r)
	{
		$tenant = pts_server_address::take_option($r, '--tenant');
		$id = isset($r[0]) ? preg_replace('/\A(BT|bt)/', '', $r[0]) : '';
		if(!ctype_digit($id) || $tenant === '' || isset($r[1]))
		{
			echo PHP_EOL . 'Usage: bench-token-revoke TOKENID [--tenant ACCOUNT]' . PHP_EOL . PHP_EOL;
			exit(2);
		}
		if(($problem = phoromatic_private_repo_auth::server_command_preflight('bench-token-revoke TOKENID')) !== null || ($account_id = phoromatic_tenant::cli_account($tenant, $problem, true)) === false)
		{
			echo PHP_EOL . 'ERROR: ' . $problem . PHP_EOL . PHP_EOL;
			exit(1);
		}
		$result = phoromatic_bench_token::revoke($account_id, $id, 'user', phoromatic_private_repo_auth::local_actor());
		echo PHP_EOL . (isset($result['error']) ? 'ERROR: ' . $result['error'] : 'Bench token BT' . $result['revoked'] . ' revoked.') . PHP_EOL . PHP_EOL;
		if(isset($result['error']))
		{
			exit(1);
		}
	}
}

?>
