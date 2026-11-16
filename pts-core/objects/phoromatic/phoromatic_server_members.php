<?php

/*
	Benchy
	phoromatic_server_members.php: what a server administrator does to users and memberships in the server console (add, change, remove, join, leave, turn a user off, reset a password)

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

// The callers (r_server_action) have checked phoromatic_auth::is_server_admin() and the CSRF token. Every change here is one transaction with its row in the
// server audit, and a change to an account's members is also a row in that account's own audit (phoromatic_admin_audit), so its administrators see what the
// operator did to their account. No audit row, no change. $who is array(actor type, actor, actor detail, own account), as phoromatic_server_console::who() makes it.
// Each function returns null on success, else why nothing was changed.

class phoromatic_server_members
{
	const PASSWORD_MIN = 6;

	protected static function fail($why)
	{
		@phoromatic_server::$db->exec('ROLLBACK');
		phoromatic_server_audit::discard();
		return 'The change could not be made (' . $why . '); nothing was changed.';
	}
	protected static function stop($text)
	{
		@phoromatic_server::$db->exec('ROLLBACK');
		return $text;
	}
	protected static function begin()
	{
		if(!phoromatic_memberships::ready())
		{
			return phoromatic_server_roles::UNAVAILABLE;
		}
		return @phoromatic_server::$db->exec('BEGIN IMMEDIATE') !== false ? null : 'The database is busy; nothing was changed.';
	}
	protected static function finish()
	{
		if(@phoromatic_server::$db->exec('COMMIT') === false)
		{
			return self::fail(phoromatic_server::$db->lastErrorMsg());
		}
		phoromatic_server_audit::flush();
		return null;
	}
	protected static function level_ok($level)
	{
		return is_string($level) && in_array($level, array('1', '2', '3', '10'), true);
	}
	protected static function account($account_id)
	{
		// A working account (not deleted, not purged) or null
		$row = phoromatic_tenant_admin::get($account_id);
		return $row && $row['PurgedOn'] === null && $row['State'] !== phoromatic_tenant::DELETED ? $row : null;
	}
	protected static function record_both($who, $action, $account, $user, $detail)
	{
		// The server audit row, and the account's own
		$fields = array('actor_detail' => $who[2], 'subject_type' => 'user', 'subject_id' => $user['UserID'], 'subject_label' => $user['UserName'], 'detail' => $detail);
		return phoromatic_server_audit::record($account['AccountID'], $who[0], $who[1], $action, $fields)
			&& phoromatic_admin_audit::record($account['AccountID'], $who[0], $who[1], $action, $fields);
	}
	protected static function record_user($who, $action, $user)
	{
		// A change to the user themselves (turned off or on, a new password): the server audit row (under their own account), and one in the audit of every
		// account they belong to, so each account's administrators see what the operator did to a member. No password is ever written.
		$fields = array('actor_detail' => $who[2], 'subject_type' => 'user', 'subject_id' => $user['UserID'], 'subject_label' => $user['UserName']);
		$ok = phoromatic_server_audit::record($user['AccountID'], $who[0], $who[1], $action, $fields);
		foreach(phoromatic_memberships::for_user($user['UserID']) as $m)
		{
			$ok = $ok && phoromatic_admin_audit::record($m['AccountID'], $who[0], $who[1], $action, $fields);
		}
		return $ok;
	}
	protected static function written($ok)
	{
		return $ok ? null : self::fail(phoromatic_server_audit::last_error() ?: phoromatic_admin_audit::last_error() ?: phoromatic_server::$db->lastErrorMsg());
	}
	public static function add($account_id, $user_name, $level, $who)
	{
		// An existing user becomes a member of an account, at one of the four levels
		if(!self::level_ok($level))
		{
			return 'Choose a level for the new member; nothing was changed.';
		}
		if(($error = self::begin()) !== null)
		{
			return $error;
		}
		$account = self::account($account_id);
		$user = is_string($user_name) ? phoromatic_memberships::find_user($user_name) : false;
		if(!$account)
		{
			return self::stop('There is no such account (or it is deleted); nothing was changed.');
		}
		if(!$user)
		{
			return self::stop('There is no user named ' . phoromatic_audit_core::clean($user_name, 40) . '; nothing was changed.');
		}
		foreach(phoromatic_memberships::members($account['AccountID']) as $m)
		{
			if($m['UserID'] === $user['UserID'])
			{
				return self::stop($user['UserName'] . ' is already a member of this account (change their level instead); nothing was changed.');
			}
		}
		if(!phoromatic_memberships::add($user['UserID'], $account['AccountID'], (int)$level, $who[1]))
		{
			return self::fail(phoromatic_server::$db->lastErrorMsg());
		}
		if(($error = self::written(self::record_both($who, 'member_added', $account, $user, array('level' => (int)$level)))) !== null)
		{
			return $error;
		}
		return self::finish();
	}
	public static function set_level($account_id, $user_id, $level, $who)
	{
		// A member's level in one account; 'off' turns them off in it (they stay a member)
		if(!self::level_ok($level) && $level !== 'off')
		{
			return 'Choose a level; nothing was changed.';
		}
		if(($error = self::begin()) !== null)
		{
			return $error;
		}
		$account = self::account($account_id);
		$user = is_string($user_id) ? phoromatic_memberships::find_user_by_id($user_id) : false;
		$old = null;
		foreach($account ? phoromatic_memberships::members($account['AccountID']) : array() as $m)
		{
			if($user && $m['UserID'] === $user['UserID'])
			{
				$old = (int)$m['AdminLevel'];
			}
		}
		if(!$account || !$user || $old === null)
		{
			return self::stop('That user is not a member of this account; nothing was changed.');
		}
		$new = $level === 'off' ? -1 : (int)$level;
		if($new === $old)
		{
			return self::stop($user['UserName'] . ' already has that level; nothing was changed.');
		}
		if($new !== 1 && phoromatic_memberships::is_last_admin($user['UserID'], $account['AccountID']))
		{
			return self::stop(phoromatic_memberships::last_admin_message($user['UserName'], $account['AccountID']));
		}
		if(!phoromatic_memberships::set_level($user['UserID'], $account['AccountID'], $new))
		{
			return self::fail(phoromatic_server::$db->lastErrorMsg());
		}
		if(($error = self::written(self::record_both($who, 'member_level_changed', $account, $user, array('before' => $old, 'after' => $new)))) !== null)
		{
			return $error;
		}
		return self::finish();
	}
	public static function remove($account_id, $user_id, $who)
	{
		if(($error = self::begin()) !== null)
		{
			return $error;
		}
		$account = self::account($account_id);
		$user = is_string($user_id) ? phoromatic_memberships::find_user_by_id($user_id) : false;
		$old = null;
		foreach($account ? phoromatic_memberships::members($account['AccountID']) : array() as $m)
		{
			if($user && $m['UserID'] === $user['UserID'])
			{
				$old = (int)$m['AdminLevel'];
			}
		}
		if(!$account || !$user || $old === null)
		{
			return self::stop('That user is not a member of this account; nothing was changed.');
		}
		if(phoromatic_memberships::is_last_admin($user['UserID'], $account['AccountID']))
		{
			return self::stop(phoromatic_memberships::last_admin_message($user['UserName'], $account['AccountID']));
		}
		if(!phoromatic_memberships::remove($user['UserID'], $account['AccountID']))
		{
			return self::fail(phoromatic_server::$db->lastErrorMsg());
		}
		if(($error = self::written(self::record_both($who, 'member_removed', $account, $user, array('level' => $old)))) !== null)
		{
			return $error;
		}
		return self::finish();
	}
	public static function join($account_id, $level, $who)
	{
		// The signed-in server administrator makes themselves a member of an account to see its data (the console shows only counts). Written to the
		// server audit and to the account's own audit, and they show on the account's Users page as a server administrator. The root login has no user
		// row, so it cannot join (it has the data pages of the admin tools).
		if(!self::level_ok($level))
		{
			return 'Choose the level to join at; nothing was changed.';
		}
		$user = isset($_SESSION['UserID']) && $who[0] === 'user' ? phoromatic_memberships::find_user_by_id($_SESSION['UserID']) : false;
		if(!$user)
		{
			return 'Only a signed-in server administrator who is a user can join an account.';
		}
		if(($error = self::begin()) !== null)
		{
			return $error;
		}
		$account = self::account($account_id);
		if(!$account || $account['State'] !== phoromatic_tenant::ACTIVE)
		{
			return self::stop('Only an active account can be joined; nothing was changed.');
		}
		foreach(phoromatic_memberships::members($account['AccountID']) as $m)
		{
			if($m['UserID'] === $user['UserID'])
			{
				return self::stop('You are already a member of this account; nothing was changed.');
			}
		}
		if(!phoromatic_memberships::add($user['UserID'], $account['AccountID'], (int)$level, $who[1]))
		{
			return self::fail(phoromatic_server::$db->lastErrorMsg());
		}
		if(($error = self::written(self::record_both($who, 'server_admin_joined_tenant', $account, $user, array('level' => (int)$level)))) !== null)
		{
			return $error;
		}
		return self::finish();
	}
	public static function leave($account_id, $who)
	{
		$user = isset($_SESSION['UserID']) && $who[0] === 'user' ? phoromatic_memberships::find_user_by_id($_SESSION['UserID']) : false;
		if(!$user)
		{
			return 'Only a signed-in server administrator who is a user can leave an account.';
		}
		if(($error = self::begin()) !== null)
		{
			return $error;
		}
		// (every membership counts for leaving one, a turned-off one included; only a working one needs another working account to stay in)
		$account = phoromatic_tenant_admin::get($account_id);
		$all = phoromatic_memberships::for_user($user['UserID']);
		$here = null;
		$usable = 0;
		foreach($all as $m)
		{
			$usable += $m['Usable'] ? 1 : 0;
			if($account && $m['AccountID'] === $account['AccountID'])
			{
				$here = $m;
			}
		}
		if($here === null || $account['PurgedOn'] !== null)
		{
			return self::stop('You are not a member of this account; nothing was changed.');
		}
		if($here['Usable'] && $usable < 2)
		{
			return self::stop('This is your only working account; you cannot leave it.');
		}
		if(phoromatic_memberships::is_last_admin($user['UserID'], $account['AccountID']))
		{
			return self::stop(phoromatic_memberships::last_admin_message($user['UserName'], $account['AccountID']));
		}
		if(!phoromatic_memberships::remove($user['UserID'], $account['AccountID']))
		{
			return self::fail(phoromatic_server::$db->lastErrorMsg());
		}
		if(($error = self::written(self::record_both($who, 'server_admin_left_tenant', $account, $user, array('level' => (int)$here['Level'])))) !== null)
		{
			return $error;
		}
		return self::finish();
	}
	public static function set_disabled($user_id, $disable, $who)
	{
		// Turns a user off (or on) on the whole server: they cannot sign in and a session they have ends at its next click. Memberships stay.
		if(($error = self::begin()) !== null)
		{
			return $error;
		}
		$user = is_string($user_id) ? phoromatic_memberships::find_user_by_id($user_id) : false;
		if(!$user)
		{
			return self::stop('There is no such user; nothing was changed.');
		}
		if($disable && isset($_SESSION['UserID']) && (string)$_SESSION['UserID'] === (string)$user['UserID'] && $who[0] === 'user')
		{
			return self::stop('You cannot turn yourself off; nothing was changed.');
		}
		if($disable && ($stuck = phoromatic_memberships::accounts_where_last_admin($user['UserID'])))
		{
			return self::stop(phoromatic_memberships::last_admin_message($user['UserName'], $stuck[0]));
		}
		if(($user['DisabledOn'] !== null) === (bool)$disable)
		{
			return self::stop($user['UserName'] . ($disable ? ' is already turned off' : ' is not turned off') . '; nothing was changed.');
		}
		$stmt = phoromatic_server::$db->prepare('UPDATE phoromatic_users SET DisabledOn = :on WHERE UserID = :user_id');
		$stmt->bindValue(':on', $disable ? phoromatic_server::current_time() : null);
		$stmt->bindValue(':user_id', $user['UserID']);
		if(@$stmt->execute() === false || !phoromatic_memberships::sync_legacy($user['UserID']))
		{
			return self::fail(phoromatic_server::$db->lastErrorMsg());
		}
		if(($error = self::written(self::record_user($who, $disable ? 'user_disabled' : 'user_enabled', $user))) !== null)
		{
			return $error;
		}
		return self::finish();
	}
	public static function reset_password($user_id, $password, $who)
	{
		// The new password is salted with the user's home account's salt, as every password is until the hash changes (the password page does the same)
		if(!is_string($password) || strlen($password) < self::PASSWORD_MIN)
		{
			return 'The password must be at least ' . self::PASSWORD_MIN . ' characters long; nothing was changed.';
		}
		if(($error = self::begin()) !== null)
		{
			return $error;
		}
		$user = is_string($user_id) ? phoromatic_memberships::find_user_by_id($user_id) : false;
		$salt = null;
		if($user)
		{
			$stmt = phoromatic_server::$db->prepare('SELECT Salt FROM phoromatic_accounts WHERE AccountID = :account_id');
			$stmt->bindValue(':account_id', $user['AccountID']);
			$result = $stmt->execute();
			$row = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;
			$salt = $row ? $row['Salt'] : null;
		}
		if(!$user || empty($salt))
		{
			return self::stop('There is no such user; nothing was changed.');
		}
		$stmt = phoromatic_server::$db->prepare('UPDATE phoromatic_users SET Password = :password WHERE UserID = :user_id');
		$legacy = phoromatic_passwords::legacy_user($salt, $password);
		$stmt->bindValue(':password', $legacy);
		$stmt->bindValue(':user_id', $user['UserID']);
		if(@$stmt->execute() === false || !phoromatic_passwords::store($user['UserID'], $legacy, $password))
		{
			return self::fail(phoromatic_server::$db->lastErrorMsg());
		}
		if(($error = self::written(self::record_user($who, 'user_password_reset', $user))) !== null)
		{
			return $error;
		}
		return self::finish();
	}
	public static function listing()
	{
		// Every user for the console's Users page: UserID, UserName, Email, AccountID (home), DisabledOn, LastLogin, ServerAdmin
		$rows = array();
		$result = phoromatic_server::$db->query('SELECT u.UserID, u.UserName, u.Email, u.AccountID, u.LastLogin, ' . (phoromatic_memberships::ready() ? 'u.DisabledOn' : 'NULL AS DisabledOn') .
			', (SELECT COUNT(*) FROM phoromatic_server_roles r WHERE r.UserID = u.UserID) AS ServerAdmin FROM phoromatic_users u ORDER BY u.UserName ASC');
		while($result && ($row = $result->fetchArray(SQLITE3_ASSOC)))
		{
			$rows[] = $row;
		}
		return $rows;
	}
}

?>
