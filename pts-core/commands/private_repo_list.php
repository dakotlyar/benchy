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

class private_repo_list implements pts_option_interface
{
	const doc_section = 'Private Repository';
	const doc_description = 'This option lists the test profiles and test suites in the cached private repository index, with their versions.';

	public static function run($r)
	{
		if(!pts_private_repo_client::require_enabled())
		{
			return false;
		}

		$repo = pts_private_repo_client::repository_name();
		pts_openbenchmarking::refresh_repository_lists(array($repo));
		$index = pts_openbenchmarking::read_repository_index($repo);

		if(!is_array($index))
		{
			echo PHP_EOL . 'No private repository index is available. Run private-repo-setup first.' . PHP_EOL . PHP_EOL;
			return false;
		}

		if(empty($index['tests']) && empty($index['suites']))
		{
			echo PHP_EOL . 'The private repository is empty.' . PHP_EOL . PHP_EOL;
			return true;
		}

		foreach(array('tests' => 'Test Profiles', 'suites' => 'Test Suites') as $section => $heading)
		{
			if(!empty($index[$section]))
			{
				echo PHP_EOL . pts_client::cli_just_bold($heading) . PHP_EOL;
				ksort($index[$section]);
				foreach($index[$section] as $name => $entry)
				{
					echo sprintf('%-40s %-20s %s', $repo . '/' . $name, implode(', ', $entry['versions']), $entry['title']) . PHP_EOL;
				}
			}
		}
		echo PHP_EOL;
	}
}

?>
