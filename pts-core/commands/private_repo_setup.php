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

class private_repo_setup implements pts_option_interface
{
	const doc_section = 'Private Repository';
	const doc_description = 'This option saves the Phoromatic Server (IP:PORT/ACCOUNT) that hosts the private test profile and test suite repository. With no argument, the server this system last connected to via the Phoromatic client is used. This system must be approved by the Phoromatic account to read from or write to the repository.';

	public static function run($r)
	{
		if(!pts_private_repo_client::require_enabled())
		{
			return false;
		}

		if(isset($r[0]))
		{
			if(pts_private_repo_client::parse_connection($r[0]) == false)
			{
				echo PHP_EOL . 'Expected a server of the form IP:PORT/ACCOUNT, e.g. 192.168.1.2:5555/I0SSJY' . PHP_EOL . PHP_EOL;
				return false;
			}
			pts_private_repo_client::save_connection($r[0]);
		}
		else if(pts_private_repo_client::connection() == false)
		{
			echo PHP_EOL . 'No server given and this system has not connected to a Phoromatic Server. Usage: private-repo-setup IP:PORT/ACCOUNT' . PHP_EOL . PHP_EOL;
			return false;
		}

		$connection = pts_private_repo_client::connection();
		echo PHP_EOL . 'Private repository "' . pts_private_repo_client::repository_name() . '" server: ' . $connection['address'] . ':' . $connection['port'] . '/' . $connection['account_id'] . PHP_EOL;
		pts_openbenchmarking::refresh_repository_lists(array(pts_private_repo_client::repository_name()), true);
		echo 'Run private-repo-list to see what it contains.' . PHP_EOL . PHP_EOL;
	}
}

?>
