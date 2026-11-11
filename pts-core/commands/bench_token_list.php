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
class bench_token_list implements pts_option_interface
{
	const doc_section = 'Test Benches';
	const doc_description = 'Run on the Phoromatic Server host as the database owner. Lists the bench tokens (never the tokens themselves) of all benches or of one. Usage: bench-token-list [BENCHID] [--tenant ACCOUNT]. With several accounts on the server, --tenant (an account ID or its name) says whose tokens.';

	public static function run($r)
	{
		$tenant = pts_server_address::take_option($r, '--tenant');
		$bench_id = isset($r[0]) ? ltrim($r[0], 'Bb') : null;
		if(($bench_id !== null && !ctype_digit($bench_id)) || $tenant === '' || isset($r[1]))
		{
			echo PHP_EOL . 'Usage: bench-token-list [BENCHID] [--tenant ACCOUNT]' . PHP_EOL . PHP_EOL;
			exit(2);
		}
		if(($problem = phoromatic_private_repo_auth::server_command_preflight('bench-token-list')) !== null || ($account_id = phoromatic_tenant::cli_account($tenant, $problem, true)) === false)
		{
			echo PHP_EOL . 'ERROR: ' . $problem . PHP_EOL . PHP_EOL;
			exit(1);
		}
		$rows = array();
		foreach(phoromatic_bench_token::tokens($account_id, $bench_id) as $t)
		{
			$rows[] = array('BT' . $t['TokenID'], 'B' . $t['BenchID'] . ' ' . $t['BenchTitle'], $t['Label'], $t['SystemID'] === null ? 'any system' : $t['SystemID'], $t['CreatedOn'], $t['LastUsedOn'] !== null ? $t['LastUsedOn'] . ' ' . $t['LastUsedIP'] . ' ' . $t['LastUsedSystem'] : 'never', $t['RevokedOn'] !== null ? 'revoked ' . $t['RevokedOn'] . ' (' . $t['RevokeReason'] . ')' : 'active');
		}
		echo PHP_EOL . (empty($rows) ? 'No bench tokens.' : pts_user_io::display_text_table(array_merge(array(array('Token', 'Bench', 'Label', 'Bound to', 'Created', 'Last used', 'State')), $rows))) . PHP_EOL . PHP_EOL;
	}
}

?>
