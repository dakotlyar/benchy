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

class server_admin_list implements pts_option_interface
{
	const doc_section = 'Phoromatic';
	const doc_description = 'Run on the Phoromatic Server host, as the user that owns the server\'s database (e.g. sudo -u USER benchy ...). Lists the users who are server administrators. Usage: server-admin-list';

	public static function run($r)
	{
		$usage = 'server-admin-list';
		if(isset($r[0]))
		{
			echo PHP_EOL . 'Usage: ' . $usage . PHP_EOL . PHP_EOL;
			return false;
		}
		if(($problem = phoromatic_private_repo_auth::server_command_preflight($usage)) !== null)
		{
			echo PHP_EOL . 'ERROR: ' . $problem . PHP_EOL . PHP_EOL;
			return false;
		}
		phoromatic_server::prepare_database();
		if(!phoromatic_server::tenancy_ready())
		{
			echo PHP_EOL . 'ERROR: ' . phoromatic_server_roles::UNAVAILABLE . PHP_EOL . PHP_EOL;
			return false;
		}
		$rows = phoromatic_server_roles::listing();
		echo PHP_EOL;
		if(empty($rows))
		{
			echo 'No user is a server administrator. The root login can always do what a server administrator can. Grant the role with: server-admin-grant USERNAME' . PHP_EOL . PHP_EOL;
			return;
		}
		echo 'Server administrators:' . PHP_EOL;
		foreach($rows as $row)
		{
			echo '  ' . ($row['UserName'] !== null ? $row['UserName'] . ' (account ' . $row['AccountID'] . ')' : 'user ' . $row['UserID'] . ' (no longer exists)') . ', since ' . $row['GrantedOn'] . ', granted by ' . $row['GrantedBy'] . PHP_EOL;
		}
		echo PHP_EOL;
	}
}

?>
