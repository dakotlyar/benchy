<?php

/*
	Benchy
	server-component-import: imports a signed runtime-component bundle into the server (runtime component updates, R1)

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

class server_component_import implements pts_option_interface
{
	const doc_section = 'Phoromatic';
	const doc_description = 'Run on the Phoromatic Server host, as the user that owns the server\'s database (e.g. sudo -u USER benchy ...). Imports a signed runtime-component bundle (benchy-components-bundle-N.zip, up to 128 MiB): components.signed and only the files this server does not hold yet. The signature and every file are checked before anything is stored, and the result goes to the server audit log. Paired machines are offered the components when they next check in. Usage: server-component-import BUNDLE.zip';

	public static function run($r)
	{
		$usage = 'server-component-import BUNDLE.zip';
		if(!isset($r[0]) || isset($r[1]))
		{
			echo PHP_EOL . 'Usage: ' . $usage . PHP_EOL . PHP_EOL;
			exit(2);
		}
		if(($problem = phoromatic_private_repo_auth::server_command_preflight($usage)) !== null)
		{
			echo PHP_EOL . 'ERROR: ' . $problem . PHP_EOL . PHP_EOL;
			exit(1);
		}
		phoromatic_server::prepare_database();
		try
		{
			$out = phoromatic_component_store::import($r[0], 'cli', 'cli', phoromatic_private_repo_auth::local_actor());
		}
		catch(phoromatic_catalog_refusal $e)
		{
			echo PHP_EOL . 'Refused (' . $e->reason . '): ' . $e->getMessage() . PHP_EOL . 'Nothing was changed.' . PHP_EOL . PHP_EOL;
			exit(1);
		}
		echo PHP_EOL . phoromatic_component_store::import_text($out) . PHP_EOL . PHP_EOL;
	}
}

?>
