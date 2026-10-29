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

class bench_login implements pts_option_interface
{
	const doc_section = 'Test Benches';
	const doc_description = 'Saves the bench token that bench-swap and bench-end need. The token is read from standard input (hidden when typed at a terminal), never from the command line, and saved to ~/.benchy/bench-token with owner-only permissions. BENCHY_BENCH_TOKEN in the environment takes precedence. Bench tokens (bbt_...) are made on the bench page or with bench-token-create on the server; a repository token (bpr_...) is a different thing.';

	public static function run($r)
	{
		if(!empty($r))
		{
			echo PHP_EOL . 'bench-login takes no arguments: the token is read from standard input, never from the command line. Nothing was saved.' . PHP_EOL . PHP_EOL;
			exit(2);
		}
		$token = pts_private_repo_client::read_secret('Bench token: ');
		if($token === false || $token === '')
		{
			echo PHP_EOL . 'No input received. Nothing was saved.' . PHP_EOL . PHP_EOL;
			exit(1);
		}
		if(phoromatic_private_repo_auth::valid_token_format($token))
		{
			echo PHP_EOL . 'That is a repository token (bpr_...), for private-repo-login. Bench actions need a bench token (bbt_...). Nothing was saved.' . PHP_EOL . PHP_EOL;
			exit(1);
		}
		if(!phoromatic_bench_token::valid_token_format($token))
		{
			echo PHP_EOL . 'That does not look like a bench token (they start with bbt_ and are 47 characters long). Nothing was saved.' . PHP_EOL . PHP_EOL;
			exit(1);
		}
		if(!pts_private_repo_client::save_token($token, pts_private_repo_client::bench_token_file()))
		{
			echo PHP_EOL . 'ERROR: Unable to save the token to ' . pts_private_repo_client::bench_token_file() . PHP_EOL . PHP_EOL;
			exit(1);
		}
		echo PHP_EOL . 'Bench token saved to ' . pts_private_repo_client::bench_token_file() . ' (checked by the server at the next bench-swap or bench-end).' . PHP_EOL . PHP_EOL;
	}
}

?>
