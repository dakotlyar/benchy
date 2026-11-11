<?php

/*
	Benchy
	phoromatic_tenant_auto_approve.php: show or set the server-wide auto-approve policy

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

class phoromatic_tenant_auto_approve implements pts_option_interface
{
	const doc_section = 'Phoromatic';
	const doc_description = 'Run on the Phoromatic Server host, as the user that owns the server\'s database. Shows, or with an argument sets, whether accounts may turn on auto-approve for their own new systems. "forbidden" (the default) makes every new system wait for an administrator of its account whatever the account\'s own setting says, and switches every account\'s own setting off; "allowed" lets an account\'s administrators turn it on. The change is recorded in the audit logs. The audit row of the account named by --tenant (an account ID or its name) is where it shows up for that account; with one active account that is the default, with several and no --tenant the change is recorded for the server only. Usage: phoromatic-tenant-auto-approve [forbidden|allowed] [--tenant ACCOUNT]';

	public static function run($r)
	{
		$usage = 'phoromatic-tenant-auto-approve [forbidden|allowed] [--tenant ACCOUNT]';
		$tenant = pts_server_address::take_option($r, '--tenant');
		if(isset($r[1]) || (isset($r[0]) && !in_array($r[0], array('forbidden', 'allowed'), true)) || $tenant === '')
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
			if(($error = phoromatic_server::set_tenant_auto_approve_policy($r[0], $account_id, 'cli', phoromatic_private_repo_auth::local_actor())) !== null)
			{
				echo PHP_EOL . 'ERROR: ' . $error . PHP_EOL . PHP_EOL;
				return false;
			}
		}

		$policy = phoromatic_server::tenant_auto_approve_policy();
		echo PHP_EOL . 'Accounts may auto-approve new systems: ' . ($policy == 'allowed' ? 'allowed' : 'forbidden') . PHP_EOL;
		echo ($policy == 'forbidden' ? 'Every new system waits for approval, whatever its account\'s own setting says.' : 'An account\'s administrators may turn auto-approve on for their own account.') . PHP_EOL;
		echo (isset($r[0]) ? 'It applies to the next system that registers.' : 'Change it with: ' . $usage) . PHP_EOL . PHP_EOL;
	}
}

?>
