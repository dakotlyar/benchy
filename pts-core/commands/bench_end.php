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

class bench_end implements pts_option_interface
{
	const doc_section = 'Test Benches';
	const doc_description = 'Ends the open session of this system\'s test bench, from the bench itself; the bench then runs no tests until a component is declared (bench-swap or the bench page). Needs a bench token (bench-login). Exit codes: 0 done, 1 refused, 2 usage.';

	public static function run($r)
	{
		if(!empty($r))
		{
			echo PHP_EOL . 'Usage: bench-end (no arguments)' . PHP_EOL . PHP_EOL;
			exit(2);
		}
		if(pts_private_repo_client::bench_token() === false)
		{
			echo PHP_EOL . 'ERROR: No bench token. Run bench-login first.' . PHP_EOL . PHP_EOL;
			exit(1);
		}
		$b = pts_private_repo_client::bench_action('end');
		if($b === false)
		{
			echo PHP_EOL . 'Not done: ' . pts_private_repo_client::last_error() . PHP_EOL . PHP_EOL;
			exit(1);
		}
		echo PHP_EOL . $b['done'] . PHP_EOL . PHP_EOL;
	}
}

?>
