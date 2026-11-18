<?php

/*
	Benchy server discovery (D2, 0.10.0). This program is free software; you can redistribute it and/or modify it under the terms of the
	GNU General Public License as published by the Free Software Foundation; either version 3 of the License, or (at your option) any later version.
*/

class enroll_code_create implements pts_option_interface
{
	const doc_section = 'Phoromatic';
	const doc_description = 'Run on the Phoromatic Server host, as the user that owns the server\'s database. Makes a code for a new native system in one account (the only active one, or with several the one named by --tenant: an account ID or its name), prints it once (only a hash is stored) and records it in the audit log. With --join it is a join code: ten characters to type on the new machine within an hour, usable once, tied to this server\'s certificate (the built-in HTTPS has to be on). Without it, the pairing string of the Systems page (good for 24 hours). Usage: enroll-code-create [LABEL] [--join] [--tenant ACCOUNT] [--system SYSTEMID]';

	public static function run($r)
	{
		$tenant = pts_server_address::take_option($r, '--tenant');
		$system = pts_server_address::take_option($r, '--system');
		$join = false;
		if(($at = array_search('--join', $r, true)) !== false)
		{
			$join = true;
			array_splice($r, $at, 1);
		}
		$label = trim(implode(' ', $r));
		if($tenant === '' || $system === '' || strpos($label, '--') !== false)
		{
			echo PHP_EOL . 'Usage: enroll-code-create [LABEL] [--join] [--tenant ACCOUNT] [--system SYSTEMID]' . PHP_EOL . PHP_EOL;
			exit(2);
		}
		if(!$join && $label === '')
		{
			echo PHP_EOL . 'Usage: enroll-code-create LABEL [--tenant ACCOUNT] [--system SYSTEMID]   (a label is needed without --join, e.g. "rig-02")' . PHP_EOL . PHP_EOL;
			exit(2);
		}
		if(phodevi::is_windows())
		{
			echo PHP_EOL . 'ERROR: The Phoromatic server isn\'t supported on Windows.' . PHP_EOL . PHP_EOL;
			exit(1);
		}
		if(($problem = phoromatic_private_repo_auth::server_command_preflight('enroll-code-create')) !== null)
		{
			echo PHP_EOL . 'ERROR: ' . $problem . PHP_EOL . PHP_EOL;
			exit(1);
		}
		phoromatic_server::prepare_database();
		if(($account_id = phoromatic_tenant::cli_account($tenant, $problem)) === false)
		{
			echo PHP_EOL . 'ERROR: ' . $problem . PHP_EOL . PHP_EOL;
			exit(1);
		}
		$made = phoromatic_agent_auth::create_code($account_id, $system, $system === null ? 'native' : null, $label, 'user', phoromatic_private_repo_auth::local_actor(), null, $join ? 'join' : 'enroll');
		if(isset($made['error']))
		{
			echo PHP_EOL . 'ERROR: ' . $made['error'] . PHP_EOL . PHP_EOL;
			exit(1);
		}
		$name = trim((string)phoromatic_server::account_id_to_group_name($account_id));
		echo PHP_EOL . ($join ? 'Join code' : 'Enrollment code') . ' EC' . $made['id'] . ' (' . $made['label'] . ') for ' . ($name !== '' ? $name : $account_id) . ', ' . ($system !== null ? 'system ' . $system : 'a new system') . '; works once, expires at ' . $made['expires'] . ' (server time):' . PHP_EOL . PHP_EOL;
		if($join)
		{
			echo '    ' . phoromatic_join_code::display($made['code']) . PHP_EOL . PHP_EOL . 'Type it on the new machine within an hour. It only works with this server.' . PHP_EOL . PHP_EOL;
		}
		else
		{
			echo '    ' . $made['code'] . PHP_EOL . PHP_EOL;
			if(phoromatic_tls::is_on())
			{
				echo 'Pairing string:' . PHP_EOL . PHP_EOL . '    ' . phoromatic_native_admin::pairing_string($made['code']) . PHP_EOL . PHP_EOL;
			}
			echo 'Copy it now; it cannot be shown again.' . PHP_EOL . PHP_EOL;
		}
	}
}

?>
