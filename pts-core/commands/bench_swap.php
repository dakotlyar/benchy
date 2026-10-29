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

class bench_swap implements pts_option_interface
{
	const doc_section = 'Test Benches';
	const doc_description = 'Declares a component swap on this system\'s test bench, from the bench itself: ends the open session and starts a new one. Usage: bench-swap --component <ID or label> | --new <class>:"<label>" [--config "<constant parts>"] [--notes "<text>"]. Needs a bench token (bench-login). Refused while a task is outstanding on any system of the bench. Exit codes: 0 done, 1 refused, 2 usage.';

	public static function run($r)
	{
		$args = array();
		$names = array('--component' => 'component', '--new' => 'new', '--config' => 'config', '--notes' => 'notes');
		for($i = 0; $i < count($r); $i++)
		{
			$a = $r[$i];
			$v = null;
			if(($eq = strpos($a, '=')) !== false && substr($a, 0, 2) == '--')
			{
				$v = substr($a, $eq + 1);
				$a = substr($a, 0, $eq);
			}
			if(!isset($names[$a]) || isset($args[$names[$a]]))
			{
				return self::usage(isset($names[$a]) ? $a . ' given twice (one component per session in 0.9).' : 'Unknown argument: ' . $r[$i]);
			}
			if($v === null)
			{
				if(!isset($r[$i + 1]))
				{
					return self::usage($a . ' needs a value.');
				}
				$v = $r[++$i];
			}
			$args[$names[$a]] = $v;
		}
		if(isset($args['component']) == isset($args['new']))
		{
			return self::usage('Give either --component or --new.');
		}
		if(isset($args['new']) && strpos($args['new'], ':') === false)
		{
			return self::usage('--new takes CLASS:LABEL, e.g. --new gpu:"RX 9070 XT sample 2".');
		}
		if(pts_private_repo_client::bench_token() === false)
		{
			echo PHP_EOL . 'ERROR: No bench token. Run bench-login first (the token comes from the bench page or bench-token-create on the server).' . PHP_EOL . PHP_EOL;
			exit(1);
		}
		$b = pts_private_repo_client::bench_action('swap', $args);
		if($b === false)
		{
			echo PHP_EOL . 'Not done: ' . pts_private_repo_client::last_error() . PHP_EOL . PHP_EOL;
			exit(1);
		}
		echo PHP_EOL . $b['done'] . PHP_EOL . PHP_EOL;
	}
	protected static function usage($why)
	{
		echo PHP_EOL . $why . PHP_EOL . 'Usage: bench-swap --component <ID or label> | --new <class>:"<label>" [--config "<text>"] [--notes "<text>"]' . PHP_EOL . PHP_EOL;
		exit(2);
	}
}

?>
