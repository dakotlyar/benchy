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

class bench_logout implements pts_option_interface
{
	const doc_section = 'Test Benches';
	const doc_description = 'Deletes the bench token saved by bench-login (~/.benchy/bench-token). BENCHY_BENCH_TOKEN, if set, is not affected.';

	public static function run($r)
	{
		$file = pts_private_repo_client::bench_token_file();
		if(is_file($file) && !unlink($file))
		{
			echo PHP_EOL . 'ERROR: Unable to delete ' . $file . PHP_EOL . PHP_EOL;
			exit(1);
		}
		echo PHP_EOL . 'Bench token removed.' . (getenv('BENCHY_BENCH_TOKEN') ? ' (BENCHY_BENCH_TOKEN is still set in this environment.)' : '') . PHP_EOL . PHP_EOL;
	}
}

?>
