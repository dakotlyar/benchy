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

class phoromatic_client_phone_home implements pts_option_interface
{
	const doc_section = 'Phoromatic';
	const doc_description = 'Run on the Phoromatic Server host, as the user that owns the server\'s database (e.g. sudo -u USER benchy ...). Shows or sets whether the server\'s clients may report to openbenchmarking.org. With forbidden (the default) every client keeps NoPhoneHome on, for local runs too, whatever its own configuration says, and the account setting that uploads results to OpenBenchmarking.org is hidden and ignored. With client_choice each client follows its own NoPhoneHome setting. Clients pick up a change at their next check-in (a client set to FALSE that connects for the first time may still request or send a GSID or report usage while it starts up, before that first check-in; from the first forbidding reply on it sends no GSID anywhere, also within an already running phoromatic.connect process). The same switch is on the Settings page of the web interface (server administrators), and every change is recorded in the admin audit log. Usage: phoromatic-client-phone-home [forbidden|client_choice] [--tenant ACCOUNT]. --tenant (an account ID or its name) names the account whose audit log also records the change; the policy itself is server-wide. With one active account that is the default.';

	public static function run($r)
	{
		$usage = 'phoromatic-client-phone-home [forbidden|client_choice] [--tenant ACCOUNT]';
		$tenant = pts_server_address::take_option($r, '--tenant');
		if($tenant === '' || isset($r[1]) || (isset($r[0]) && !in_array($r[0], array('forbidden', 'client_choice'), true)))
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
			// Audited like the Settings page toggle (under the server's first account); refused if the audit row cannot be written
			if(($account_id = phoromatic_tenant::cli_audit_account($tenant, $error)) === false)
			{
				echo PHP_EOL . 'ERROR: ' . $error . PHP_EOL . PHP_EOL;
				return false;
			}
			$error = phoromatic_server::set_client_phone_home_policy($r[0], $account_id, 'cli', phoromatic_private_repo_auth::local_actor());
			if($error !== null)
			{
				echo PHP_EOL . 'ERROR: ' . $error . PHP_EOL . PHP_EOL;
				return false;
			}
		}

		$policy = phoromatic_server::client_phone_home_policy();
		echo PHP_EOL . 'Client phone-home policy: ' . $policy . PHP_EOL;
		echo ($policy == 'forbidden' ? 'Clients keep NoPhoneHome on (no usage reports, no GSID, no ob_auto_compare, no automatic OpenBenchmarking.org uploads), whatever their own setting says.' : 'Each client follows its own NoPhoneHome setting (on unless set to FALSE).') . PHP_EOL;
		echo (isset($r[0]) ? 'Clients pick this up at their next check-in.' : 'Change it with: ' . $usage) . PHP_EOL . PHP_EOL;
	}
}

?>
