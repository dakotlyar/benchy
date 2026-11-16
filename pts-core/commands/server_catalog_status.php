<?php

/*
	Benchy
	server-catalog-status: what the server holds of the signed test catalog (E4.4 C2)

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

class server_catalog_status implements pts_option_interface
{
	const doc_section = 'Phoromatic';
	const doc_description = 'Run on the Phoromatic Server host, as the user that owns the server\'s database. Shows each catalog tier the server holds (its release pair, signing key, issue date, counts, and whether it is served or why not), the retired key generations, and the last attempt to import the bundle that came with this version of Benchy. Changes nothing. Usage: server-catalog-status';

	public static function run($r)
	{
		if(isset($r[0]))
		{
			echo PHP_EOL . 'Usage: server-catalog-status' . PHP_EOL . PHP_EOL;
			return false;
		}
		if(($problem = phoromatic_private_repo_auth::server_command_preflight('server-catalog-status')) !== null)
		{
			echo PHP_EOL . 'ERROR: ' . $problem . PHP_EOL . PHP_EOL;
			exit(1);
		}
		phoromatic_server::prepare_database(true);
		echo PHP_EOL . phoromatic_catalog_status::text() . PHP_EOL . PHP_EOL;
	}
}

?>
