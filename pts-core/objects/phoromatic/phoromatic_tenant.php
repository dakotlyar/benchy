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
		// SQL for a background loop that walks every account's rows: only the accounts that are active (suspended and deleted ones are skipped)
		return $column . ' IN (SELECT AccountID FROM phoromatic_accounts WHERE State IS NULL OR State = \'active\')';
	}
}

?>
