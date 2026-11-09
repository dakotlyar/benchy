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
		// The session user's AdminLevel in this account (1 and up), or 0: not logged in, another account, disabled, or no such user
		if(!isset($_SESSION['UserID']) || !isset($_SESSION['AccountID']) || $account_id === null || $account_id === '' || (string)$_SESSION['AccountID'] !== (string)$account_id)
		{
			return 0;
		}
		$stmt = phoromatic_server::$db->prepare('SELECT AdminLevel FROM phoromatic_users WHERE UserID = :user_id AND AccountID = :account_id');
		$stmt->bindValue(':user_id', (string)$_SESSION['UserID']);
		$stmt->bindValue(':account_id', (string)$account_id);
		$result = $stmt->execute();
		$row = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;
		return $row && $row['AdminLevel'] >= 1 ? (int)$row['AdminLevel'] : 0;
	}
	public static function is_server_admin()
	{
		// Settings and stores that every account on the server shares: the HTTPS certificate, the phone-home policy, the agent API
		// settings and the download cache. The root login always. Until server roles exist (a later step), an account administrator
		// (level 1) too, but only while the server has exactly one account, so a single-account server works as before and the
		// permission ends the moment a second account exists.
		if(isset($_SESSION['AdminLevel']) && $_SESSION['AdminLevel'] == -40)
		{
			return true;
		}
		if(!isset($_SESSION['AccountID']) || self::level($_SESSION['AccountID']) !== 1)
		{
			return false;
		}
		return (int)phoromatic_server::$db->querySingle('SELECT COUNT(*) FROM phoromatic_accounts') === 1;
	}
}

?>
