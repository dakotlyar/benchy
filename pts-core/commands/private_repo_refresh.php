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

class private_repo_refresh implements pts_option_interface
{
	const doc_section = 'Private Repository';
	const doc_description = 'This option forces a refresh of the private repository index from the Phoromatic Server.';

	public static function run($r)
	{
		if(!pts_private_repo_client::require_enabled())
		{
			return false;
		}

		$repo = pts_private_repo_client::repository_name();
		pts_openbenchmarking::refresh_repository_lists(array($repo), true);
		$index = pts_openbenchmarking::read_repository_index($repo, false);
		if(pts_openbenchmarking::$private_refresh_error != null)
		{
			echo PHP_EOL . 'Refresh failed: ' . pts_openbenchmarking::$private_refresh_error . ($index ? ' The cached index was kept.' : null) . PHP_EOL . PHP_EOL;
			return false;
		}
		echo PHP_EOL . ($index ? 'The private repository index is up to date.' : 'The private repository has no index yet.') . PHP_EOL . PHP_EOL;
	}
}

?>
