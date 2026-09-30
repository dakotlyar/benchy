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

class upload_private_test_suite implements pts_option_interface
{
	const doc_section = 'Private Repository';
	const doc_description = 'This option uploads a test suite (e.g. local/my-suite) to the private repository on the Phoromatic Server, where other approved systems on the account can install it as private/my-suite. Versions are immutable; add --overwrite to replace an existing version while developing.';

	public static function argument_checks()
	{
		return array(
		new pts_argument_check(0, array('pts_test_suite', 'is_suite'), null)
		);
	}
	public static function run($r)
	{
		$overwrite = in_array('--overwrite', $r);

		if(($test_suite = pts_types::identifier_to_object($r[0])) != false)
		{
			pts_client::$display->generic_heading($r[0]);

			if(pts_validation::validate_test_suite($test_suite) && $test_suite->get_file_location() != null)
			{
				$zip_file = PTS_OPENBENCHMARKING_SCRATCH_PATH . $test_suite->get_identifier(false) . '-' . $test_suite->get_version() . '.zip';
				$zip_created = pts_compression::zip_archive_create($zip_file, $test_suite->get_file_location());

				if($zip_created == false)
				{
					echo PHP_EOL . 'Failed to create zip file.' . PHP_EOL;
					return false;
				}

				$commit_description = pts_user_io::prompt_user_input('Enter a suite commit description', false);

				echo PHP_EOL;
				$upload = pts_private_repo_client::upload('suite', $test_suite->get_identifier_base_name(), $zip_file, $commit_description, $overwrite);
				unlink($zip_file);

				if($upload == false)
				{
					echo 'ERROR: ' . pts_private_repo_client::last_error() . PHP_EOL . PHP_EOL;
					return false;
				}

				foreach($upload['warnings'] as $warning)
				{
					echo 'WARNING: ' . $warning . PHP_EOL;
				}
				echo 'Uploaded ' . $upload['id'] . PHP_EOL;
				echo 'Command: benchy benchmark ' . $upload['id'] . PHP_EOL . PHP_EOL;
				pts_openbenchmarking::refresh_repository_lists(array(pts_private_repo_client::repository_name()), true);
			}
		}
	}
}

?>
