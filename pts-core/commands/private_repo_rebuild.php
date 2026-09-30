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

class private_repo_rebuild implements pts_option_interface
{
	const doc_section = 'Private Repository';
	const doc_description = 'This option asks the Phoromatic Server to regenerate the private repository index from the stored packages.';

	public static function run($r)
	{
		if(!pts_private_repo_client::require_enabled())
		{
			return false;
		}

		$result = pts_private_repo_client::rebuild();
		echo PHP_EOL . ($result ? 'Index rebuilt: ' . $result['tests'] . ' tests, ' . $result['suites'] . ' suites.' : 'ERROR: ' . pts_private_repo_client::last_error()) . PHP_EOL . PHP_EOL;

		if($result)
		{
			pts_openbenchmarking::refresh_repository_lists(array(pts_private_repo_client::repository_name()), true);
		}
	}
}

?>
