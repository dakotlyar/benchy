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

class upload_private_test_profile implements pts_option_interface
{
	const doc_section = 'Private Repository';
	const doc_description = 'This option uploads a test profile (e.g. local/my-test) to the private repository on the Phoromatic Server, where other approved systems on the account can install it as private/my-test. Versions are immutable; add --overwrite to replace an existing version while developing.';

	public static function run($r)
	{
		if(!pts_private_repo_client::require_enabled())
		{
			return false;
		}

		$overwrite = in_array('--overwrite', $r);
		$r = array_values(array_diff($r, array('--overwrite')));

		if(empty($r))
		{
			echo PHP_EOL . 'Usage: upload-private-test-profile local/NAME [--overwrite]' . PHP_EOL . PHP_EOL;
			return false;
		}

		foreach(pts_types::identifiers_to_test_profile_objects($r, false, true) as $test_profile)
		{
			if(pts_validation::validate_test_profile($test_profile))
			{
				pts_client::$display->generic_heading($test_profile);
				$zip_file = PTS_OPENBENCHMARKING_SCRATCH_PATH . $test_profile->get_identifier_base_name() . '-' . $test_profile->get_test_profile_version() . '.zip';
				$zip_created = pts_compression::zip_archive_create($zip_file, pts_file_io::glob($test_profile->get_resource_dir() . '*'));
				if($zip_created == false)
				{
					echo PHP_EOL . 'Failed to create zip file.' . PHP_EOL;
					return false;
				}

				$commit_description = pts_user_io::prompt_user_input('Enter a test commit description', false);

				echo PHP_EOL;
				$upload = pts_private_repo_client::upload('test', $test_profile->get_identifier_base_name(), $zip_file, $commit_description, $overwrite);
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
