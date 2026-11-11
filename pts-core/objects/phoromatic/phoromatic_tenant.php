<?php

/*
	Benchy
	phoromatic_tenant.php: whether a tenant (an account) exists, and whether it may be used right now

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

// A tenant is a phoromatic_accounts row. Its State column is added by schema 59 with the values active (the default), suspended and
// deleted; phoromatic_agent_auth::account_state() reads the same column and the same words, so do not rename them.

class phoromatic_tenant
{
	const ACTIVE = 'active';
	const SUSPENDED = 'suspended';
	const DELETED = 'deleted';

	public static function state($account_id)
	{
		// 'active', 'suspended' or 'deleted'; null when there is no such account (or the id could not be one). A State this code does
		// not know counts as suspended: unknown never means usable. Replaces is_dir(accounts/<id>) as the test that an account exists.
		if(($account_id = phoromatic_tenant_path::segment($account_id)) === false || $account_id[0] === '.' || phoromatic_server::$db == null)
		{
			return null;
		}
		$stmt = @phoromatic_server::$db->prepare('SELECT State FROM phoromatic_accounts WHERE AccountID = :account_id');
		if($stmt === false)
		{
			// A database that is not yet at schema 59 has no State: every account it has is active
			$stmt = phoromatic_server::$db->prepare('SELECT \'active\' AS State FROM phoromatic_accounts WHERE AccountID = :account_id');
		}
		$stmt->bindValue(':account_id', $account_id);
		$result = $stmt->execute();
		$row = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;
		if(!$row)
		{
			return null;
		}
		return $row['State'] === null ? self::ACTIVE : (in_array($row['State'], array(self::ACTIVE, self::SUSPENDED, self::DELETED), true) ? $row['State'] : self::SUSPENDED);
	}
	public static function is_active($account_id)
	{
		return self::state($account_id) === self::ACTIVE;
	}
	public static function active_clause($column = 'AccountID')
	{
		// SQL for a background loop that walks every account's rows: only the accounts that are active (suspended and deleted ones are skipped).
		// Before schema 59 there is no State and every account is active.
		if(!phoromatic_server::tenancy_ready())
		{
			return '1 = 1';
		}
		return $column . ' IN (SELECT AccountID FROM phoromatic_accounts WHERE State IS NULL OR State = \'active\')';
	}
	// ----- the account a server command works on (--tenant)

	protected static function listing()
	{
		// Every account that is not deleted, oldest first: array of array(AccountID, GroupName, State)
		if(phoromatic_server::$db == null)
		{
			return array();
		}
		$result = phoromatic_server::$db->query('SELECT AccountID, GroupName, ' . (phoromatic_server::tenancy_ready() ? 'State' : '\'active\' AS State') . ' FROM phoromatic_accounts ORDER BY CreatedOn ASC, rowid ASC');
		$rows = array();
		while($result && ($row = $result->fetchArray(SQLITE3_ASSOC)))
		{
			$state = $row['State'] === null ? self::ACTIVE : $row['State'];
			if($state !== self::DELETED)
			{
				$rows[] = array($row['AccountID'], (string)$row['GroupName'], in_array($state, array(self::ACTIVE, self::SUSPENDED), true) ? $state : self::SUSPENDED);
			}
		}
		return $rows;
	}
	protected static function describe($rows)
	{
		$lines = array();
		foreach($rows as $row)
		{
			$lines[] = '  ' . $row[0] . '  ' . phoromatic_audit_core::clean($row[1], 64) . ($row[2] === self::ACTIVE ? '' : '  (' . $row[2] . ')');
		}
		return implode(PHP_EOL, $lines);
	}
	public static function cli_account($value, &$error)
	{
		// The account a server command (private-repo-token-create, bench-token-list, ...) works on: the one named by --tenant (an AccountID or
		// the account's name, which must be active), or, when --tenant is left out, the only active account. With several there is no default:
		// the command used to take the oldest one without saying so, which put a token in the wrong tenant. false, with $error set, when
		// there is no single answer.
		$error = null;
		if(phoromatic_server::$db == null)
		{
			phoromatic_server::prepare_database();
		}
		$rows = self::listing();
		if($value !== null)
		{
			$hits = array();
			foreach($rows as $row)
			{
				if(strcasecmp($row[0], $value) === 0)
				{
					$hits = array($row);
					break;
				}
				if($row[1] !== '' && strcasecmp($row[1], $value) === 0)
				{
					$hits[] = $row;
				}
			}
			if(count($hits) > 1)
			{
				$error = 'More than one account is named "' . phoromatic_audit_core::clean($value, 64) . '". Use its account ID instead:' . PHP_EOL . self::describe($hits);
				return false;
			}
			if(empty($hits))
			{
				$error = 'No such account: ' . phoromatic_audit_core::clean($value, 64) . '. The accounts on this server are:' . PHP_EOL . self::describe($rows);
				return false;
			}
			if($hits[0][2] !== self::ACTIVE)
			{
				$error = 'Account ' . $hits[0][0] . ' (' . phoromatic_audit_core::clean($hits[0][1], 64) . ') is ' . $hits[0][2] . '; resume it before working on it.';
				return false;
			}
			return $hits[0][0];
		}
		$active = array_values(array_filter($rows, function($row) { return $row[2] === phoromatic_tenant::ACTIVE; }));
		if(count($active) === 1)
		{
			return $active[0][0];
		}
		if(empty($active))
		{
			$error = 'No active Phoromatic account exists on this server (or its database is not readable by this user).';
			return false;
		}
		$error = 'This server has ' . count($active) . ' active accounts, so say which one with --tenant ACCOUNT (an account ID or the account\'s name):' . PHP_EOL . self::describe($active);
		return false;
	}
	public static function cli_audit_account($value, &$error)
	{
		// For a server-wide setting changed from the command line, which belongs to no account: the account whose audit log also gets the row.
		// --tenant names it; without it the only active account, else none (the server audit has the row either way).
		$error = null;
		if($value !== null)
		{
			return self::cli_account($value, $error);
		}
		if(phoromatic_server::$db == null)
		{
			phoromatic_server::prepare_database();
		}
		$active = array_values(array_filter(self::listing(), function($row) { return $row[2] === phoromatic_tenant::ACTIVE; }));
		return count($active) === 1 ? $active[0][0] : '';
	}
}

?>
