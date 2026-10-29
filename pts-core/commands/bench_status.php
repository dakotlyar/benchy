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

class bench_status implements pts_option_interface
{
	const doc_section = 'Test Benches';
	const doc_description = 'Shows the test bench this system belongs to on its Phoromatic Server: its systems, the open session and its component, this system\'s hold or notice state and any outstanding task. Needs no bench token.';

	public static function run($r)
	{
		$b = pts_private_repo_client::bench_action('status');
		if($b === false)
		{
			echo PHP_EOL . 'ERROR: ' . pts_private_repo_client::last_error() . PHP_EOL . PHP_EOL;
			exit(1);
		}
		echo PHP_EOL . pts_client::cli_just_bold('Bench: ') . $b['title'] . ' (' . $b['id'] . ')' . PHP_EOL;
		if($b['session'])
		{
			$s = $b['session'];
			echo pts_client::cli_just_bold('Session: ') . $s['id'] . ' since ' . $s['started'] . ' by ' . $s['started_by'] . PHP_EOL;
			foreach($s['components'] as $c)
			{
				echo pts_client::cli_just_bold('Component under test: ') . $c['label'] . ' (' . $c['class'] . ', #' . $c['id'] . ')' . PHP_EOL;
			}
			if($s['config'] !== null)
			{
				echo pts_client::cli_just_bold('Constant parts: ') . $s['config'] . PHP_EOL;
			}
		}
		else
		{
			echo 'No open session: the bench needs a component (bench-swap) before it runs tests.' . PHP_EOL;
		}
		echo PHP_EOL . pts_client::cli_just_bold('Systems:') . PHP_EOL;
		foreach($b['members'] as $m)
		{
			echo '  ' . ($m['this'] ? '* ' : '  ') . $m['title'] . ' (' . $m['system'] . ($m['os'] !== null ? ', ' . $m['os'] : '') . '): ' . $m['state'] .
				($m['task'] ? '; task outstanding (' . $m['task']['session'] . ', issued ' . $m['task']['issued'] . ')' : '') . PHP_EOL;
			if($m['hold'])
			{
				echo '      ' . $m['hold'] . PHP_EOL;
			}
		}
		echo PHP_EOL;
	}
}

?>
