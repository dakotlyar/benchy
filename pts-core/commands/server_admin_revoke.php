<?php

/*
	Benchy
	Server administrators of a Phoromatic Server: who has the role.

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

class server_admin_revoke implements pts_option_interface
{
	const doc_section = 'Phoromatic';
	const doc_description = 'Run on the Phoromatic Server host, as the user that owns the server\'s database (e.g. sudo -u USER benchy ...). Takes the server administrator role away from a user. The change is recorded in the server audit log. Usage: server-admin-revoke USERNAME';

	public static function run($r)
	{
		$usage = 'server-admin-revoke USERNAME';
		if(!isset($r[0]) || isset($r[1]))
		{
			echo PHP_EOL . 'Usage: ' . $usage . PHP_EOL . PHP_EOL;
			return false;
		}
		if(($problem = phoromatic_private_repo_auth::server_command_preflight($usage)) !== null)
		{
			echo PHP_EOL . 'ERROR: ' . $problem . PHP_EOL . PHP_EOL;
			exit(1);
		}
		phoromatic_server::prepare_database();
		$error = phoromatic_server_roles::revoke($r[0], 'cli', phoromatic_private_repo_auth::local_actor());
		if($error !== null)
		{
			echo PHP_EOL . 'ERROR: ' . $error . PHP_EOL . PHP_EOL;
			return false;
		}
		echo PHP_EOL . 'Done. The change is in the server audit log.' . PHP_EOL . PHP_EOL;
	}
}

?>
