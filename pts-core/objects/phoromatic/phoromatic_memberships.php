<?php

/*
	Benchy
	phoromatic_memberships.php: which accounts a user belongs to and at what level (schema 61), and who gets an account's mail

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

// A membership is a user's access to one account: phoromatic_memberships(UserID, AccountID, AdminLevel). Level 1, 2, 3 or 10 works; anything below 1
// is "disabled in this account". phoromatic_users.AccountID stays the user's home account (the password salt hangs off it) and
// phoromatic_users.AdminLevel is kept in step with the home membership on every write here, so the release before this one (which reads only that
// column) still sees current roles. A server-wide disable (phoromatic_users.DisabledOn) shows there as a negative level too.
//
// Until the database is at schema 61 (an upgrade that is waiting) every read here falls back to the home account of each user, which is what it was
// before; writes made by this class then touch only the legacy column, and the schema 61 step copies them into memberships when it runs.

class phoromatic_memberships
{
	const LEVELS = array(1, 2, 3, 10);

	public static function ready()
	{
		return phoromatic_server::memberships_ready();
	}
	public static function valid_level($level)
	{
		return is_int($level) && in_array($level, self::LEVELS, true);
	}
	public static function level($user_id, $account_id)
	{
		// The user's level in the account, 1 and up, or 0: not a member, disabled in the account, disabled on the server, or no such user
		if($user_id === null || $user_id === '' || $account_id === null || $account_id === '')
		{
			return 0;
		}
		if(self::ready())
		{
			$stmt = phoromatic_server::$db->prepare('SELECT m.AdminLevel FROM phoromatic_memberships m JOIN phoromatic_users u ON u.UserID = m.UserID WHERE m.UserID = :user_id AND m.AccountID = :account_id AND u.DisabledOn IS NULL');
		}
		else
		{
			$stmt = phoromatic_server::$db->prepare('SELECT AdminLevel FROM phoromatic_users WHERE UserID = :user_id AND AccountID = :account_id');
		}
		$stmt->bindValue(':user_id', (string)$user_id);
		$stmt->bindValue(':account_id', (string)$account_id);
		$result = $stmt->execute();
		$row = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;
		// Exactly 1, 2, 3 or 10 as a whole number: anything else a database holds (1.5, '1x') is no level at all
		return $row && self::valid_level($row['AdminLevel']) ? $row['AdminLevel'] : 0;
	}
	public static function for_user($user_id)
	{
		// Every membership of the user, by account name: array of array(AccountID, Name, State, Level (the stored number), Usable (works now: a level of 1 to
		// 10 in an active account), Home). Purged accounts have no memberships (the purge removes them).
		$rows = array();
		if(self::ready())
		{
			$stmt = phoromatic_server::$db->prepare('SELECT m.AccountID, m.AdminLevel, a.GroupName, a.State, u.AccountID AS Home FROM phoromatic_memberships m JOIN phoromatic_accounts a ON a.AccountID = m.AccountID JOIN phoromatic_users u ON u.UserID = m.UserID WHERE m.UserID = :user_id');
		}
		else
		{
			$stmt = phoromatic_server::$db->prepare('SELECT u.AccountID, u.AdminLevel, a.GroupName, ' . (phoromatic_server::tenancy_ready() ? 'a.State' : '\'active\' AS State') . ', u.AccountID AS Home FROM phoromatic_users u JOIN phoromatic_accounts a ON a.AccountID = u.AccountID WHERE u.UserID = :user_id');
		}
		$stmt->bindValue(':user_id', (string)$user_id);
		$result = $stmt->execute();
		while($result && ($row = $result->fetchArray(SQLITE3_ASSOC)))
		{
			$state = $row['State'] === null ? phoromatic_tenant::ACTIVE : (in_array($row['State'], array(phoromatic_tenant::ACTIVE, phoromatic_tenant::SUSPENDED, phoromatic_tenant::DELETED), true) ? $row['State'] : phoromatic_tenant::SUSPENDED);
			$rows[] = array('AccountID' => $row['AccountID'], 'Name' => phoromatic_tenant_admin::display_name($row), 'State' => $state, 'Level' => $row['AdminLevel'],
				'Usable' => self::valid_level($row['AdminLevel']) && $state === phoromatic_tenant::ACTIVE, 'Home' => $row['Home'] === $row['AccountID']);
		}
		usort($rows, function($a, $b) { return strcasecmp($a['Name'], $b['Name']) ?: strcmp($a['AccountID'], $b['AccountID']); });
		return $rows;
	}
	public static function level_name($level)
	{
		switch($level)
		{
			case 1:
				return 'Group Administrator';
			case 2:
				return 'Administrator';
			case 3:
				return 'Power User';
			case 10:
				return 'Viewer';
		}
		return is_numeric($level) && $level < 1 ? 'Disabled' : 'Unknown';
	}
	public static function members($account_id)
	{
		// The members of the account for its Users page, by name: UserID, UserName, Email, LastLogin, AdminLevel (this account's), Home (the user's own
		// account), Source, ServerAdmin (a row in the server role table), DisabledOn
		$rows = array();
		if(self::ready())
		{
			$stmt = phoromatic_server::$db->prepare('SELECT u.UserID, u.UserName, u.Email, m.LastUsedOn AS LastUsed, m.AdminLevel, m.Source, u.DisabledOn, (u.AccountID = m.AccountID) AS IsHome, (SELECT COUNT(*) FROM phoromatic_server_roles r WHERE r.UserID = u.UserID) AS ServerAdmin FROM phoromatic_memberships m JOIN phoromatic_users u ON u.UserID = m.UserID WHERE m.AccountID = :account_id ORDER BY u.UserName ASC');
		}
		else
		{
			$stmt = phoromatic_server::$db->prepare('SELECT u.UserID, u.UserName, u.Email, u.LastLogin AS LastUsed, u.AdminLevel, \'local\' AS Source, NULL AS DisabledOn, 1 AS IsHome, 0 AS ServerAdmin FROM phoromatic_users u WHERE u.AccountID = :account_id ORDER BY u.UserName ASC');
		}
		$stmt->bindValue(':account_id', (string)$account_id);
		$result = $stmt->execute();
		while($result && ($row = $result->fetchArray(SQLITE3_ASSOC)))
		{
			$rows[] = $row;
		}
		return $rows;
	}
	public static function member_count($account_id)
	{
		return phoromatic_tenant_admin::count_rows(self::ready() ? 'phoromatic_memberships' : 'phoromatic_users', $account_id);
	}
	public static function sync_legacy($user_id)
	{
		// The release before schema 61 reads only phoromatic_users.AdminLevel: it is the home membership's level (negative for a user disabled on the server,
		// and -1 for a user with no home membership at all, so no rollback gives anybody a way in that the membership table took away). Inside the caller's
		// transaction. True if it was written.
		if(!self::ready())
		{
			return true;
		}
		$user = self::find_user_by_id($user_id);
		if(!$user)
		{
			return true;
		}
		$stmt = phoromatic_server::$db->prepare('SELECT AdminLevel FROM phoromatic_memberships WHERE UserID = :user_id AND AccountID = :account_id');
		$stmt->bindValue(':user_id', (string)$user_id);
		$stmt->bindValue(':account_id', (string)$user['AccountID']);
		$result = $stmt->execute();
		$row = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;
		$level = $row && is_int($row['AdminLevel']) ? $row['AdminLevel'] : 0;
		$legacy = $level === 0 ? -1 : ($user['DisabledOn'] !== null ? -abs($level) : $level);
		$stmt = phoromatic_server::$db->prepare('UPDATE phoromatic_users SET AdminLevel = :level WHERE UserID = :user_id');
		$stmt->bindValue(':level', $legacy, SQLITE3_INTEGER);
		$stmt->bindValue(':user_id', (string)$user_id);
		return @$stmt->execute() !== false;
	}
	public static function add($user_id, $account_id, $level, $created_by)
	{
		// Makes the user a member (or changes the level of one that is) and gives them their notification settings row for the account. Inside the caller's
		// transaction, with no audit row (the caller writes it). True if it is written. Refused while the schema 61 step is waiting.
		if(!self::ready())
		{
			return false;
		}
		$stmt = phoromatic_server::$db->prepare('INSERT INTO phoromatic_memberships (UserID, AccountID, AdminLevel, Source, CreatedOn, CreatedBy) VALUES (:user_id, :account_id, :level, \'local\', :now, :created_by) ON CONFLICT (UserID, AccountID) DO UPDATE SET AdminLevel = excluded.AdminLevel');
		$stmt->bindValue(':user_id', (string)$user_id);
		$stmt->bindValue(':account_id', (string)$account_id);
		$stmt->bindValue(':level', (int)$level, SQLITE3_INTEGER);
		$stmt->bindValue(':now', phoromatic_server::current_time());
		$stmt->bindValue(':created_by', phoromatic_audit_core::clean($created_by));
		if(@$stmt->execute() === false)
		{
			return false;
		}
		$stmt = phoromatic_server::$db->prepare('INSERT OR IGNORE INTO phoromatic_user_settings (UserID, AccountID) VALUES (:user_id, :account_id)');
		$stmt->bindValue(':user_id', (string)$user_id);
		$stmt->bindValue(':account_id', (string)$account_id);
		return @$stmt->execute() !== false && self::sync_legacy($user_id);
	}
	public static function set_level($user_id, $account_id, $level)
	{
		// A level change of an existing member (negative: disabled in this account). Before schema 61 it is the legacy column of a home user.
		if(!self::ready())
		{
			$stmt = phoromatic_server::$db->prepare('UPDATE phoromatic_users SET AdminLevel = :level WHERE UserID = :user_id AND AccountID = :account_id');
		}
		else
		{
			$stmt = phoromatic_server::$db->prepare('UPDATE phoromatic_memberships SET AdminLevel = :level WHERE UserID = :user_id AND AccountID = :account_id');
		}
		$stmt->bindValue(':level', (int)$level, SQLITE3_INTEGER);
		$stmt->bindValue(':user_id', (string)$user_id);
		$stmt->bindValue(':account_id', (string)$account_id);
		return @$stmt->execute() !== false && phoromatic_server::$db->changes() === 1 && self::sync_legacy($user_id);
	}
	public static function remove($user_id, $account_id)
	{
		// Ends the membership (the user stays). The user's notification settings for the account go with it, and nothing in the account (systems, results,
		// schedules) names the user, so nothing else changes.
		if(!self::ready())
		{
			return false;
		}
		$stmt = phoromatic_server::$db->prepare('DELETE FROM phoromatic_memberships WHERE UserID = :user_id AND AccountID = :account_id');
		$stmt->bindValue(':user_id', (string)$user_id);
		$stmt->bindValue(':account_id', (string)$account_id);
		if(@$stmt->execute() === false || phoromatic_server::$db->changes() !== 1)
		{
			return false;
		}
		$stmt = phoromatic_server::$db->prepare('DELETE FROM phoromatic_user_settings WHERE UserID = :user_id AND AccountID = :account_id');
		$stmt->bindValue(':user_id', (string)$user_id);
		$stmt->bindValue(':account_id', (string)$account_id);
		return @$stmt->execute() !== false && self::sync_legacy($user_id);
	}
	public static function is_last_admin($user_id, $account_id)
	{
		// True when the user is a working Group Administrator (level 1, not turned off on the server) of the account and nobody else is: taking that away would
		// leave the account with nobody who can manage it
		if(self::level($user_id, $account_id) !== 1)
		{
			return false;
		}
		$stmt = phoromatic_server::$db->prepare('SELECT COUNT(*) AS n FROM phoromatic_memberships m JOIN phoromatic_users u ON u.UserID = m.UserID WHERE m.AccountID = :account_id AND m.AdminLevel = 1 AND u.DisabledOn IS NULL AND m.UserID <> :user_id');
		$stmt->bindValue(':account_id', (string)$account_id);
		$stmt->bindValue(':user_id', (string)$user_id);
		$result = $stmt->execute();
		$row = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;
		return !$row || (int)$row['n'] === 0;
	}
	public static function last_admin_message($user_name, $account_id)
	{
		$row = phoromatic_tenant_admin::get($account_id);
		return $user_name . ' is the last Group Administrator of ' . ($row ? phoromatic_tenant_admin::display_name($row) : 'this account') . '; make someone else a Group Administrator first. Nothing was changed.';
	}
	public static function accounts_where_last_admin($user_id)
	{
		// The accounts (not deleted) the user is the only working Group Administrator of: array of AccountID
		$ids = array();
		foreach(self::for_user($user_id) as $m)
		{
			if($m['State'] !== phoromatic_tenant::DELETED && self::is_last_admin($user_id, $m['AccountID']))
			{
				$ids[] = $m['AccountID'];
			}
		}
		return $ids;
	}
	public static function reconcile_legacy()
	{
		// After a rollback to the release before schema 61 and the upgrade again (the version stays 61, so the step does not run): that release changes only
		// phoromatic_users.AdminLevel (a disable, a new user, a level change). Where the legacy level is not what sync_legacy() would write for the home
		// membership, the legacy level wins, because nothing but that release can make the two differ: the membership takes the level (a new user gets
		// a home membership, a user the old release turned on loses DisabledOn). One indexed read on a server where they agree; the repair is one transaction
		// with an audit row per user. Levels that are not whole numbers are left alone.
		if(!self::ready() || !phoromatic_server::tenancy_ready())
		{
			return;
		}
		$find = 'SELECT u.UserID, u.UserName, u.AccountID, u.AdminLevel AS Legacy, u.DisabledOn, m.AdminLevel AS Level FROM phoromatic_users u JOIN phoromatic_accounts a ON a.AccountID = u.AccountID AND a.PurgedOn IS NULL LEFT JOIN phoromatic_memberships m ON m.UserID = u.UserID AND m.AccountID = u.AccountID WHERE typeof(u.AdminLevel) = \'integer\' AND u.AdminLevel <> (CASE WHEN m.AdminLevel IS NULL OR m.AdminLevel = 0 THEN -1 WHEN u.DisabledOn IS NOT NULL THEN -ABS(m.AdminLevel) ELSE m.AdminLevel END)';
		$probe = phoromatic_server::$db->querySingle($find . ' LIMIT 1', true);
		if(empty($probe) || @phoromatic_server::$db->exec('BEGIN IMMEDIATE') === false)
		{
			return;
		}
		$rows = array();
		$result = phoromatic_server::$db->query($find);
		while($result && ($row = $result->fetchArray(SQLITE3_ASSOC)))
		{
			$rows[] = $row;
		}
		$ok = true;
		foreach($rows as $row)
		{
			$legacy = (int)$row['Legacy'];
			$new = $legacy;
			if($row['DisabledOn'] !== null && $legacy < 0)
			{
				// (turned off on the server and the old release changed the level: the membership keeps the level it was changed to, the turn-off stays)
				$new = self::valid_level(abs($legacy)) ? abs($legacy) : (int)$row['Level'];
			}
			$changed = $row['Level'] === null || (int)$row['Level'] !== $new || ($legacy > 0 && $row['DisabledOn'] !== null);
			if(!$changed)
			{
				// (the membership already says it: only the legacy number is put in its usual form, as a level of 0 is written -1)
				$ok = $ok && self::sync_legacy($row['UserID']);
				continue;
			}
			$stmt = phoromatic_server::$db->prepare('INSERT INTO phoromatic_memberships (UserID, AccountID, AdminLevel, Source, CreatedOn, CreatedBy) VALUES (:user_id, :account_id, :level, \'local\', :now, \'reconcile\') ON CONFLICT (UserID, AccountID) DO UPDATE SET AdminLevel = excluded.AdminLevel');
			$stmt->bindValue(':user_id', $row['UserID']);
			$stmt->bindValue(':account_id', $row['AccountID']);
			$stmt->bindValue(':level', $new, SQLITE3_INTEGER);
			$stmt->bindValue(':now', phoromatic_server::current_time());
			$ok = $ok && @$stmt->execute() !== false;
			if($legacy > 0)
			{
				// the old release turned the user on (or never turned them off): a server-wide turn-off is lifted with it
				$ok = $ok && @phoromatic_server::$db->exec('UPDATE phoromatic_users SET DisabledOn = NULL WHERE UserID = \'' . SQLite3::escapeString($row['UserID']) . '\'') !== false;
			}
			$ok = $ok && @phoromatic_server::$db->exec('INSERT OR IGNORE INTO phoromatic_user_settings (UserID, AccountID) VALUES (\'' . SQLite3::escapeString($row['UserID']) . '\', \'' . SQLite3::escapeString($row['AccountID']) . '\')') !== false;
			$ok = $ok && self::sync_legacy($row['UserID']);
			$ok = $ok && phoromatic_server_audit::record($row['AccountID'], 'system', 'reconcile', $row['Level'] === null ? 'member_added' : 'member_level_changed', array('subject_type' => 'user', 'subject_id' => $row['UserID'],
				'subject_label' => $row['UserName'], 'detail' => array('before' => $row['Level'] === null ? null : (int)$row['Level'], 'after' => $new, 'why' => 'the release before schema 61 changed this user; its level is kept')));
		}
		if(!$ok || @phoromatic_server::$db->exec('COMMIT') === false)
		{
			@phoromatic_server::$db->exec('ROLLBACK');
			phoromatic_server_audit::discard();
			return;
		}
		phoromatic_server_audit::flush();
	}
	public static function find_user($user_name)
	{
		// array(UserID, UserName, AccountID (home), DisabledOn) or false
		$stmt = phoromatic_server::$db->prepare('SELECT UserID, UserName, AccountID, ' . (self::ready() ? 'DisabledOn' : 'NULL AS DisabledOn') . ' FROM phoromatic_users WHERE UserName = :user_name');
		$stmt->bindValue(':user_name', (string)$user_name);
		$result = $stmt->execute();
		return $result ? $result->fetchArray(SQLITE3_ASSOC) : false;
	}
	public static function find_user_by_id($user_id)
	{
		$stmt = phoromatic_server::$db->prepare('SELECT UserID, UserName, AccountID, ' . (self::ready() ? 'DisabledOn' : 'NULL AS DisabledOn') . ' FROM phoromatic_users WHERE UserID = :user_id');
		$stmt->bindValue(':user_id', (string)$user_id);
		$result = $stmt->execute();
		return $result ? $result->fetchArray(SQLITE3_ASSOC) : false;
	}
	public static function accounts_for_name($user_name, $levels)
	{
		// The accounts a user (by name) works in at one of the levels, not disabled on the server: array of AccountID. $levels are whole numbers from the caller.
		$list = implode(', ', array_map('intval', $levels));
		if(self::ready())
		{
			$stmt = phoromatic_server::$db->prepare('SELECT m.AccountID FROM phoromatic_memberships m JOIN phoromatic_users u ON u.UserID = m.UserID WHERE u.UserName = :user_name AND u.DisabledOn IS NULL AND m.AdminLevel IN (' . $list . ') ORDER BY m.AccountID');
		}
		else
		{
			$stmt = phoromatic_server::$db->prepare('SELECT AccountID FROM phoromatic_users WHERE UserName = :user_name AND typeof(AdminLevel) = \'integer\' AND AdminLevel IN (' . $list . ') ORDER BY AccountID');
		}
		$stmt->bindValue(':user_name', (string)$user_name);
		$result = $stmt->execute();
		$ids = array();
		while($result && ($row = $result->fetchArray(SQLITE3_ASSOC)))
		{
			$ids[] = $row['AccountID'];
		}
		return $ids;
	}
	public static function notify_recipients($account_id, $setting_column)
	{
		// The people an account's mail goes to: members of the account (at a working level, not disabled on the server) who switched $setting_column on in
		// their settings for this account, wherever their home account is. $setting_column is one of this class's own names, never from a request.
		// array of array(UserName, Email)
		if(!in_array($setting_column, array('NotifyOnNewSystems', 'NotifyOnWarnings', 'NotifyOnHungSystems', 'NotifyOnResultUploads'), true))
		{
			return array();
		}
		if(self::ready())
		{
			$stmt = phoromatic_server::$db->prepare('SELECT u.UserName, u.Email FROM phoromatic_users u JOIN phoromatic_memberships m ON m.UserID = u.UserID WHERE m.AccountID = :account_id AND m.AdminLevel IN (1, 2, 3, 10) AND u.DisabledOn IS NULL AND u.UserID IN (SELECT UserID FROM phoromatic_user_settings WHERE AccountID = :account_id AND ' . $setting_column . ' = 1) ORDER BY u.UserName');
		}
		else
		{
			$stmt = phoromatic_server::$db->prepare('SELECT UserName, Email FROM phoromatic_users WHERE UserID IN (SELECT UserID FROM phoromatic_user_settings WHERE AccountID = :account_id AND ' . $setting_column . ' = 1) AND AccountID = :account_id');
		}
		$stmt->bindValue(':account_id', (string)$account_id);
		$result = $stmt->execute();
		$rows = array();
		while($result && ($row = $result->fetchArray(SQLITE3_ASSOC)))
		{
			$rows[] = $row;
		}
		return $rows;
	}
	public static function group_admin_email($account_id)
	{
		// The first (oldest) Group Administrator's address of the account, or false
		if(self::ready())
		{
			$stmt = phoromatic_server::$db->prepare('SELECT u.Email FROM phoromatic_memberships m JOIN phoromatic_users u ON u.UserID = m.UserID WHERE m.AccountID = :account_id AND m.AdminLevel = 1 AND u.DisabledOn IS NULL ORDER BY m.CreatedOn ASC, u.UserName ASC LIMIT 1');
		}
		else
		{
			$stmt = phoromatic_server::$db->prepare('SELECT Email FROM phoromatic_users WHERE AccountID = :account_id AND AdminLevel = 1 ORDER BY CreatedOn ASC LIMIT 1');
		}
		$stmt->bindValue(':account_id', (string)$account_id);
		$result = $stmt->execute();
		$row = $result ? $result->fetchArray() : false;
		return isset($row['Email']) ? $row['Email'] : false;
	}
}

?>
