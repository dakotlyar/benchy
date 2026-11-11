<?php

/*
	Benchy
	Server-wide phone-home policy for the clients of a Phoromatic Server.

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

class phoromatic_v2_allow_http implements pts_option_interface
{
	const doc_section = 'Phoromatic';
	const doc_description = 'Run on the Phoromatic Server host, as the user that owns the server\'s database (e.g. sudo -u USER benchy ...). Shows or sets whether the native agent API (/api/v2) answers over plain HTTP. It is off by default: the API refuses plain HTTP with 426 https_required, because agents send a bearer token. Turn it on only for a test rig; every change is recorded in the admin audit log. Usage: phoromatic-v2-allow-http [on|off] [--tenant ACCOUNT]. --tenant (an account ID or its name) names the account whose audit log also records the change; the setting itself is server-wide. With one active account that is the default.';

	public static function run($r)
	{
		$usage = 'phoromatic-v2-allow-http [on|off] [--tenant ACCOUNT]';
		$tenant = pts_server_address::take_option($r, '--tenant');
		if($tenant === '' || isset($r[1]) || (isset($r[0]) && !in_array($r[0], array('on', 'off'), true)))
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

		if(isset($r[0]))
		{
			if(($account_id = phoromatic_tenant::cli_audit_account($tenant, $error)) === false)
			{
				echo PHP_EOL . 'ERROR: ' . $error . PHP_EOL . PHP_EOL;
				return false;
			}
			$error = phoromatic_server::set_v2_allow_http($r[0], $account_id, 'cli', phoromatic_private_repo_auth::local_actor());
			if($error !== null)
			{
				echo PHP_EOL . 'ERROR: ' . $error . PHP_EOL . PHP_EOL;
				return false;
			}
		}

		echo PHP_EOL . 'Native agent API over plain HTTP: ' . (phoromatic_api_v2::allow_http() ? 'on' : 'off') . PHP_EOL;
		if(phoromatic_api_v2::allow_http())
		{
			echo 'WARNING: agents send their token over plain HTTP. Use this for test rigs only.' . PHP_EOL;
		}
		echo (isset($r[0]) ? 'It takes effect at the next request.' : 'Change it with: ' . $usage) . PHP_EOL . PHP_EOL;
	}
}

?>
