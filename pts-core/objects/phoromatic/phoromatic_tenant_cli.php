<?php

/*
	Benchy
	phoromatic_tenant_cli.php: the server-tenant-* commands, which run the same changes as the server console on the server host

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

class phoromatic_tenant_cli
{
	const USAGE = array(
		'list' => 'server-tenant-list [--all]',
		'rename' => 'server-tenant-rename ACCOUNT --name NEW-NAME',
		'suspend' => 'server-tenant-suspend ACCOUNT [--reason TEXT] [--even-if-running]',
		'resume' => 'server-tenant-resume ACCOUNT',
		'delete' => 'server-tenant-delete ACCOUNT --confirm-name NAME [--reason TEXT]',
		'restore' => 'server-tenant-restore ACCOUNT',
		'purge' => 'server-tenant-purge ACCOUNT --confirm-name NAME --confirm-id ACCOUNT-ID');

	protected static function fail($message, $code = 1)
	{
		echo PHP_EOL . 'ERROR: ' . $message . PHP_EOL . PHP_EOL;
		exit($code);
	}
	public static function find($value, &$error)
	{
		// The account an ACCOUNT argument names (its account ID, or its name when only one account has it), deleted accounts included; false with $error set
		$error = null;
		$hits = array();
		foreach(phoromatic_tenant_admin::listing() as $row)
		{
			if(strcasecmp($row['AccountID'], (string)$value) === 0)
			{
				return $row;
			}
			if(strcasecmp(phoromatic_tenant_admin::display_name($row), (string)$value) === 0)
			{
				$hits[] = $row;
			}
		}
		if(count($hits) === 1)
		{
			return $hits[0];
		}
		$error = count($hits) > 1 ? 'More than one account is called ' . phoromatic_audit_core::clean($value, 64) . '; use its account ID (server-tenant-list shows them).' : 'There is no account ' . phoromatic_audit_core::clean($value, 64) . ' (server-tenant-list shows them).';
		return false;
	}
	public static function run($verb, $r)
	{
		$usage = self::USAGE[$verb];
		$name = pts_server_address::take_option($r, '--name');
		$reason = pts_server_address::take_option($r, '--reason');
		$confirm_name = pts_server_address::take_option($r, '--confirm-name');
		$confirm_id = pts_server_address::take_option($r, '--confirm-id');
		$all = in_array('--all', $r, true);
		$running = in_array('--even-if-running', $r, true);
		$r = array_values(array_diff($r, array('--all', '--even-if-running')));
		$wants_account = $verb !== 'list';
		if(($wants_account && (count($r) !== 1 || $r[0] === '')) || (!$wants_account && !empty($r)) || $name === '' || $confirm_name === '' || $confirm_id === '' ||
			($verb === 'rename' && $name === null) || ($all && $verb !== 'list') || ($running && $verb !== 'suspend') || ($name !== null && $verb !== 'rename') || ($reason !== null && !in_array($verb, array('suspend', 'delete'), true)) ||
			($confirm_name !== null && !in_array($verb, array('delete', 'purge'), true)) || ($confirm_id !== null && $verb !== 'purge'))
		{
			echo PHP_EOL . 'Usage: ' . $usage . PHP_EOL . PHP_EOL;
			exit(2);
		}
		if(($problem = phoromatic_private_repo_auth::server_command_preflight($usage)) !== null)
		{
			self::fail($problem);
		}
		phoromatic_server::prepare_database();
		if(!phoromatic_server::tenancy_ready())
		{
			self::fail(phoromatic_server_roles::UNAVAILABLE);
		}
		if($verb === 'list')
		{
			echo PHP_EOL;
			foreach(phoromatic_tenant_admin::listing() as $row)
			{
				if(!$all && $row['State'] === 'deleted')
				{
					continue;
				}
				echo '  ' . $row['AccountID'] . '  ' . str_pad($row['PurgedOn'] !== null ? 'purged' : $row['State'], 10) . phoromatic_audit_core::clean(phoromatic_tenant_admin::display_name($row), 64) . PHP_EOL;
			}
			echo PHP_EOL;
			return;
		}
		if(($row = self::find($r[0], $error)) === false)
		{
			self::fail($error);
		}
		$who = array('cli', phoromatic_private_repo_auth::local_actor(), null, '');
		$leftover = null;
		if($verb === 'rename')
		{
			$error = phoromatic_tenant_admin::rename($row['AccountID'], $name, $who);
		}
		else if($verb === 'purge')
		{
			$error = phoromatic_tenant_admin::purge($row['AccountID'], $who, $confirm_name, $confirm_id, $leftover);
		}
		else
		{
			$error = phoromatic_tenant_admin::change_state($verb, $row['AccountID'], $who, $reason, $confirm_name, $running);
		}
		if($error !== null)
		{
			self::fail($error);
		}
		echo PHP_EOL . 'Done: account ' . $row['AccountID'] . ' ' . ($verb === 'rename' ? 'renamed' : ($verb === 'purge' ? 'purged' : phoromatic_tenant_admin::PAST[$verb])) . '. The change is in the server audit log.' . PHP_EOL;
		if($leftover !== null)
		{
			echo 'The account\'s folder could not be removed completely and was left as ' . $leftover . '; remove it by hand.' . PHP_EOL;
		}
		echo PHP_EOL;
	}
}

?>
