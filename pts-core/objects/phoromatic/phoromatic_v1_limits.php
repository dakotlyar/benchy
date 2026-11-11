<?php

/*
	Benchy
	phoromatic_v1_limits.php: what an unauthenticated v1 client (aid + msi, no secret) may do to a server before an administrator has approved it

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

// A v1 client has no per-tenant secret: its account id and machine id are its whole identity, and approval is the only gate (plan A4).
// Two limits keep an address that does not hold either from doing damage or from probing for account ids:
//  - requests that name an account that does not exist (or was deleted) are counted per source address in phoromatic_v2_failures, the table the
//    native agent API uses; UNKNOWN_MAX of them in UNKNOWN_WINDOW seconds lock the address out of the v1 API for the rest of the window
//  - an account holds at most PENDING_MAX systems that are waiting for approval; further new systems are refused until an administrator
//    approves or deletes some. Systems that are already known are never affected.

class phoromatic_v1_limits
{
	const KIND = 'v1_unknown_account';
	const UNKNOWN_MAX = 20;
	const UNKNOWN_WINDOW = 900;
	const PENDING_MAX = 200;

	public static function wait_seconds()
	{
		// 0, or how long until this address may use the v1 API again
		if(phoromatic_server::$db == null)
		{
			return 0;
		}
		return phoromatic_agent_auth::locked_out(self::KIND, self::UNKNOWN_MAX, self::UNKNOWN_WINDOW);
	}
	public static function lockout_message($wait)
	{
		return 'Too many requests naming an unknown account from this address; try again in about ' . ceil($wait / 60) . ' minute(s).';
	}
	public static function unknown_account()
	{
		// One request named an account that does not exist. The first of a window and the one that trips the lockout are audited (and
		// capped across all addresses by the same rule the native API uses); every one is counted.
		$ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
		$now = time();
		@phoromatic_server::$db->exec('DELETE FROM phoromatic_v2_failures WHERE At < ' . ($now - 3600));
		if(!phoromatic_agent_auth::note_failure(self::KIND, $ip, $now))
		{
			return;
		}
		$stmt = @phoromatic_server::$db->prepare('SELECT COUNT(*) FROM phoromatic_v2_failures WHERE Kind = :k AND SourceIP = :ip AND At >= :since');
		if($stmt === false)
		{
			return;
		}
		$stmt->bindValue(':k', self::KIND);
		$stmt->bindValue(':ip', $ip);
		$stmt->bindValue(':since', $now - self::UNKNOWN_WINDOW);
		$result = $stmt->execute();
		$row = $result ? $result->fetchArray(SQLITE3_NUM) : false;
		$n = $row ? (int)$row[0] : 0;
		if(($n == 1 || $n == self::UNKNOWN_MAX) && phoromatic_agent_auth::audit_allowed($now))
		{
			phoromatic_admin_audit::record('', 'agent', 'unauthenticated', 'v1_account_unknown', array('detail' => array('requests_in_window' => $n, 'locked_out' => $n >= self::UNKNOWN_MAX)));
		}
	}
	public static function pending_count($account_id)
	{
		$stmt = phoromatic_server::$db->prepare('SELECT COUNT(*) FROM phoromatic_systems WHERE AccountID = :account_id AND State = 0 AND DeletedOn IS NULL');
		$stmt->bindValue(':account_id', $account_id);
		$result = $stmt->execute();
		$row = $result ? $result->fetchArray(SQLITE3_NUM) : false;
		return $row ? (int)$row[0] : 0;
	}
	public static function pending_full($account_id)
	{
		return self::pending_count($account_id) >= self::PENDING_MAX;
	}
}

?>
