<?php

/*
	Benchy
	phoromatic_auth.php: who the logged-in web user is allowed to be, read from the database on each call (not from the session)

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

class phoromatic_auth
{
	// Failed web log-ins (users and the root login alike) are audit rows; ten from one address within five minutes lock that address out
	const LOGIN_MAX_FAILURES = 10;
	const LOGIN_FAILURE_WINDOW = 300;

	public static function login_wait_seconds()
	{
		// 0, or how long until this address may try to log in again
		$ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
		return phoromatic_auth_lockout::seconds_remaining('phoromatic_admin_audit', 'web_login_failed', $ip, array('invalid'), self::LOGIN_MAX_FAILURES, self::LOGIN_FAILURE_WINDOW);
	}
	public static function login_failed($user_name)
	{
		phoromatic_admin_audit::record('', 'user', (string)$user_name, 'web_login_failed', array('detail' => array('reason' => 'invalid')));
	}
	public static function level($account_id)
	{
		// The session user's AdminLevel in this account (1 and up), or 0: not logged in, another account, not a member of it, disabled (in it or on the
		// server), or no such user. Read from the database on every call. Only the account the session is in counts.
		if(!isset($_SESSION['UserID']) || !isset($_SESSION['AccountID']) || $account_id === null || $account_id === '' || (string)$_SESSION['AccountID'] !== (string)$account_id)
		{
			return 0;
		}
		return phoromatic_memberships::level((string)$_SESSION['UserID'], (string)$account_id);
	}
	public static function is_server_admin()
	{
		// Settings and stores that every account on the server shares: the HTTPS certificate, the phone-home policy, the agent API
		// settings and the download cache. The root login always, otherwise a user with a row in phoromatic_server_roles who is still an
		// enabled user of the account the session is in. Rows come from schema 59 (a server's only account), the first account made on an
		// empty server, and server-admin-grant.
		if(isset($_SESSION['AdminLevel']) && $_SESSION['AdminLevel'] == -40)
		{
			return true;
		}
		if(!isset($_SESSION['UserID']) || !isset($_SESSION['AccountID']) || self::level($_SESSION['AccountID']) === 0)
		{
			return false;
		}
		return phoromatic_server_roles::has($_SESSION['UserID']);
	}
	public static function audit_actor()
	{
		// Who the audit rows of a web change name: array(account, actor type, actor, actor detail). The root login belongs to no account (its
		// session says account 0, which is no account's row) and is recorded as the root actor, not as a user; a user is recorded with the
		// account the session is in.
		if(isset($_SESSION['AdminLevel']) && $_SESSION['AdminLevel'] == -40)
		{
			return array('', 'root', 'RootAdmin', 'root login');
		}
		return array(isset($_SESSION['AccountID']) ? (string)$_SESSION['AccountID'] : '', 'user', isset($_SESSION['UserName']) ? (string)$_SESSION['UserName'] : '?',
			'UserID ' . (isset($_SESSION['UserID']) ? $_SESSION['UserID'] : '?'));
	}
	public static function audit_actor_fields()
	{
		// array(actor type, actor, actor detail, who to name as the creator) of the same actor
		list($account, $type, $actor, $detail) = self::audit_actor();
		return array($type, $actor, $detail, $actor);
	}
	public static function refresh_session()
	{
		// Run on every web request, before anything reads the session's level. A user whose user row is gone or disabled on the server is logged out. A
		// user whose membership in the account the session is in is gone, disabled or in a tenant that is not active is moved to their next working
		// account (with a note saying so; that request ends in a redirect to the main page and does nothing), or logged out when they have none; anyone else gets the level the database holds now (a demotion applies at
		// once). The root login is not a user row and is left alone. True when the session was ended.
		if(!isset($_SESSION['UserID']) || !isset($_SESSION['AccountID']) || (isset($_SESSION['AdminLevel']) && $_SESSION['AdminLevel'] == -40))
		{
			return false;
		}
		$user = phoromatic_memberships::find_user_by_id((string)$_SESSION['UserID']);
		if(!$user || $user['DisabledOn'] !== null)
		{
			return self::end_session();
		}
		$level = phoromatic_tenant::state($_SESSION['AccountID']) === phoromatic_tenant::ACTIVE ? self::level($_SESSION['AccountID']) : 0;
		if($level === 0)
		{
			$lost = phoromatic_server::account_id_to_group_name($_SESSION['AccountID']);
			foreach(phoromatic_memberships::for_user($_SESSION['UserID']) as $m)
			{
				if($m['Usable'])
				{
					$_SESSION['AccountID'] = $m['AccountID'];
					$_SESSION['AdminLevel'] = (int)$m['Level'];
					$_SESSION['TenantNotice'] = ($lost !== null && trim((string)$lost) !== '' ? $lost : 'The account you were in') . ' is no longer available to you. You are now in ' . $m['Name'] . '.';
					self::remember_account($m['AccountID']);
					// The request that found out is not carried on in the other account: whatever it was (a POST made on a page of the account they lost
					// above all) is dropped, the session id changes, and the person lands on the main page of the account they are in now
					session_regenerate_id(true);
					session_write_close();
					header('Location: /?main');
					exit;
				}
			}
			return self::end_session();
		}
		$_SESSION['AdminLevel'] = $level;
		return false;
	}
	protected static function end_session()
	{
		$_SESSION = array();
		session_regenerate_id(true);
		return true;
	}
	protected static function remember_account($account_id)
	{
		// The account to open at the next log-in, and when the membership was last used. Not an error when it cannot be written (a read-only database).
		if(!phoromatic_memberships::ready() || !isset($_SESSION['UserID']))
		{
			return;
		}
		$stmt = @phoromatic_server::$db->prepare('UPDATE phoromatic_users SET LastAccountID = :account_id WHERE UserID = :user_id');
		if($stmt)
		{
			$stmt->bindValue(':account_id', (string)$account_id);
			$stmt->bindValue(':user_id', (string)$_SESSION['UserID']);
			@$stmt->execute();
		}
		$stmt = @phoromatic_server::$db->prepare('UPDATE phoromatic_memberships SET LastUsedOn = :now WHERE UserID = :user_id AND AccountID = :account_id');
		if($stmt)
		{
			$stmt->bindValue(':now', phoromatic_server::current_time());
			$stmt->bindValue(':user_id', (string)$_SESSION['UserID']);
			$stmt->bindValue(':account_id', (string)$account_id);
			@$stmt->execute();
		}
	}
	public static function resolve_session($user)
	{
		// The step after a right password: which account the log-in opens and what is refused. $user is the users row (UserID, UserName, AccountID,
		// CreatedOn). Returns array(account id, level, note) for the account to open, or array(null, title, text) when the user may not sign in. The
		// choice: the account they were last in, else their own (home) account, else the first by name, each only if it works (active, level 1 to 10).
		$found = phoromatic_memberships::find_user_by_id($user['UserID']);
		if($found && $found['DisabledOn'] !== null)
		{
			return array(null, 'Disabled Account', 'The log-in is not possible as this account has been disabled.');
		}
		$all = phoromatic_memberships::for_user($user['UserID']);
		$usable = array_values(array_filter($all, function($m) { return $m['Usable']; }));
		if(empty($usable))
		{
			// The password was right, so saying why the log-in is refused tells a stranger nothing
			$suspended = array_filter($all, function($m) { return $m['State'] === phoromatic_tenant::SUSPENDED && phoromatic_memberships::valid_level($m['Level']); });
			if(!empty($suspended))
			{
				return array(null, 'Account Suspended', 'This Phoromatic account is suspended. Ask the administrator of this server.');
			}
			return array(null, 'Disabled Account', empty($all) ? 'The log-in is not possible: this user is not a member of any account.' : 'The log-in is not possible as this account has been disabled.');
		}
		$chosen = null;
		if(phoromatic_memberships::ready() && !empty($found))
		{
			$stmt = phoromatic_server::$db->prepare('SELECT LastAccountID FROM phoromatic_users WHERE UserID = :user_id');
			$stmt->bindValue(':user_id', (string)$user['UserID']);
			$result = $stmt->execute();
			$row = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;
			foreach($usable as $m)
			{
				if($row && $row['LastAccountID'] === $m['AccountID'])
				{
					$chosen = $m;
				}
			}
		}
		if($chosen === null)
		{
			foreach($usable as $m)
			{
				if($m['Home'])
				{
					$chosen = $m;
				}
			}
		}
		if($chosen === null)
		{
			$chosen = $usable[0];
		}
		return array($chosen['AccountID'], (int)$chosen['Level'], null);
	}
	public static function open_session($user, $account_id, $level, $salt)
	{
		// Starts the session in the account resolve_session() chose (a new session id first, so an id seen before the log-in is worthless)
		session_regenerate_id();
		$_SESSION['UserID'] = $user['UserID'];
		$_SESSION['UserName'] = $user['UserName'];
		$_SESSION['AccountID'] = $account_id;
		$_SESSION['AdminLevel'] = $level;
		$_SESSION['CreatedOn'] = $user['CreatedOn'];
		$_SESSION['Token'] = sha1($salt . (function_exists('random_bytes') ? bin2hex(random_bytes(32)) : rand()) . PTS_CORE_VERSION . time());
		$_SESSION['CoreVersionOnSignOn'] = PTS_CORE_VERSION;
		self::remember_account($account_id);
	}
	public static function switch_account($account_id)
	{
		// The account switcher: re-reads the membership and the account's state, then moves the session there. A new session id (the CSRF token is kept).
		// null on success, else why not. Nothing carries across: the next page is the account's main page.
		$account_id = phoromatic_tenant_path::segment($account_id);
		if($account_id === false || !isset($_SESSION['UserID']))
		{
			return 'There is no such account.';
		}
		foreach(phoromatic_memberships::for_user($_SESSION['UserID']) as $m)
		{
			if($m['AccountID'] === $account_id)
			{
				if(!$m['Usable'])
				{
					return $m['State'] === phoromatic_tenant::ACTIVE ? 'You cannot use ' . $m['Name'] . ' (your access to it is turned off).' : $m['Name'] . ' is not available (it is ' . $m['State'] . ').';
				}
				$_SESSION['AccountID'] = $account_id;
				$_SESSION['AdminLevel'] = (int)$m['Level'];
				self::remember_account($account_id);
				session_regenerate_id(true);
				return null;
			}
		}
		return 'There is no such account.';
	}
}

?>
