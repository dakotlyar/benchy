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
class bench_token_create implements pts_option_interface
{
	const doc_section = 'Test Benches';
	const doc_description = 'Run on the Phoromatic Server host, as the user that owns the server\'s database. Creates a bench token for one test bench, optionally bound to one of its systems, prints it once (only a hash is stored) and records the creation in the audit log. On the bench, run bench-login and paste it. Usage: bench-token-create BENCHID LABEL [--system SYSTEMID]';

	public static function run($r)
	{
		$system = null;
		if(($at = array_search('--system', $r, true)) !== false)
		{
			$system = isset($r[$at + 1]) ? $r[$at + 1] : '';
			array_splice($r, $at, 2);
		}
		$bench_id = isset($r[0]) ? ltrim($r[0], 'Bb') : '';
		$label = trim(implode(' ', array_slice($r, 1)));
		if(!ctype_digit($bench_id) || $label === '' || $system === '')
		{
			echo PHP_EOL . 'Usage: bench-token-create BENCHID LABEL [--system SYSTEMID]   (BENCHID as on the bench page, e.g. 3 or B3)' . PHP_EOL . PHP_EOL;
			exit(2);
		}
		if(($problem = phoromatic_private_repo_auth::server_command_preflight('bench-token-create BENCHID LABEL')) !== null || ($account_id = phoromatic_private_repo::server_account_id()) == false)
		{
			echo PHP_EOL . 'ERROR: ' . ($problem !== null ? $problem : 'No Phoromatic account exists on this server (or its database is not readable by this user).') . PHP_EOL . PHP_EOL;
			exit(1);
		}
		$result = phoromatic_bench_token::create($account_id, $bench_id, $label, $system, 'user', phoromatic_private_repo_auth::local_actor());
		if(isset($result['error']))
		{
			echo PHP_EOL . 'ERROR: ' . $result['error'] . PHP_EOL . PHP_EOL;
			exit(1);
		}
		echo PHP_EOL . 'Bench token BT' . $result['id'] . ' (' . $result['label'] . ') for ' . $result['bench']['Title'] . ' (B' . (int)$result['bench']['BenchID'] . '), ' . ($result['system'] === null ? 'any system of the bench' : 'system ' . $result['system'] . ' only') . ':' . PHP_EOL . PHP_EOL . '    ' . $result['token'] . PHP_EOL . PHP_EOL;
		echo 'Copy it now; it cannot be shown again. On the bench run: benchy bench-login' . PHP_EOL . PHP_EOL;
	}
}

?>
