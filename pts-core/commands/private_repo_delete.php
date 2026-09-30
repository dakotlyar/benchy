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

class private_repo_delete implements pts_option_interface
{
	const doc_section = 'Private Repository';
	const doc_description = 'This option removes one version of a test profile or test suite from the private repository (it is kept in the server trash directory). Usage: private-repo-delete private/NAME-VERSION [suite]';

	public static function run($r)
	{
		if(!pts_private_repo_client::require_enabled())
		{
			return false;
		}

		if(!isset($r[0]))
		{
			echo PHP_EOL . 'Usage: private-repo-delete private/NAME-VERSION [suite]' . PHP_EOL . PHP_EOL;
			return false;
		}

		$repo = pts_private_repo_client::repository_name();
		$kind = isset($r[1]) && $r[1] == 'suite' ? 'suite' : 'test';
		$id = $r[0];
		if(strpos($id, $repo . '/') === 0)
		{
			$id = substr($id, strlen($repo) + 1);
		}
		$c = strrpos($id, '-');
		if($c === false)
		{
			echo PHP_EOL . 'A version is required, e.g. ' . $repo . '/NAME-1.0.0' . PHP_EOL . PHP_EOL;
			return false;
		}

		$result = pts_private_repo_client::delete($kind, substr($id, 0, $c), substr($id, $c + 1));
		echo PHP_EOL . ($result ? 'Removed ' . $result['id'] : 'ERROR: ' . pts_private_repo_client::last_error()) . PHP_EOL . PHP_EOL;

		if($result)
		{
			pts_openbenchmarking::refresh_repository_lists(array($repo), true);
		}
	}
}

?>
