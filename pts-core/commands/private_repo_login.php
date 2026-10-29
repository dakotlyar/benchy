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

class private_repo_login implements pts_option_interface
{
	const doc_section = 'Private Repository';
	const doc_description = 'This option saves the repository token that upload-private-test-profile, upload-private-test-suite, private-repo-delete and private-repo-rebuild need on top of an approved system. The token is read from standard input (hidden when typed at a terminal), never from the command line, and saved to ~/.benchy/private-repo-token with owner-only permissions. The BENCHY_PRIVATE_REPO_TOKEN environment variable takes precedence over the saved file. Tokens are created on the Phoromatic Server with private-repo-token-create.';

	public static function run($r)
	{
		if(!pts_private_repo_client::require_enabled())
		{
			return false;
		}

		$token = self::read_token();
		if($token === false)
		{
			echo PHP_EOL . 'No input received. Nothing was saved.' . PHP_EOL . PHP_EOL;
			return false;
		}

		if(!phoromatic_private_repo_auth::valid_token_format($token))
		{
			echo PHP_EOL . 'That does not look like a repository token (they start with bpr_ and are 47 characters long). Nothing was saved.' . PHP_EOL . PHP_EOL;
			return false;
		}
		if(!pts_private_repo_client::save_token($token))
		{
			echo PHP_EOL . 'ERROR: Unable to save the token to ' . pts_private_repo_client::token_file() . PHP_EOL . PHP_EOL;
			return false;
		}

		echo PHP_EOL . 'Token saved to ' . pts_private_repo_client::token_file() . ' (not yet verified: the server checks it on the next upload, delete or rebuild).' . PHP_EOL . PHP_EOL;
	}
	protected static function read_token()
	{
		return pts_private_repo_client::read_secret('Repository token: ');
	}
}

?>
