<?php

/*
	Benchy
	phoromatic_server_roles.php: who is a server administrator (the phoromatic_server_roles table), granted and revoked with an audit row

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

// A server administrator may change what every account shares (HTTPS, phone-home, the agent API, the download cache). The root login is
// one without a row here. The only role is 'admin'. Rows are made by schema 59 (the only account's level-1 users), by the first account
// created on an empty server, and by root on the command line (server-admin-grant, server-admin-revoke).

class phoromatic_server_roles
{
	const UNAVAILABLE = 'The server is waiting to finish a database upgrade (the reason is in the server log), so this cannot be done yet; nothing was changed.';

	public static function insert($user_id, $granted_by)
	{
		// Inside the caller's transaction; no audit row (the caller writes it). True if the row exists afterwards.
		if(!phoromatic_server::tenancy_ready())
		{
			return false;
		}
		$stmt = phoromatic_server::$db->prepare('INSERT OR IGNORE INTO phoromatic_server_roles (UserID, Role, Source, GrantedOn, GrantedBy) VALUES (:user_id, \'admin\', \'local\', :now, :granted_by)');
		$stmt->bindValue(':user_id', $user_id);
		$stmt->bindValue(':now', phoromatic_server::current_time());
		$stmt->bindValue(':granted_by', $granted_by);
		return $stmt->execute() !== false;
	}
	public static function has($user_id)
	{
		$stmt = @phoromatic_server::$db->prepare('SELECT 1 FROM phoromatic_server_roles WHERE UserID = :user_id AND Role = \'admin\'');
		if($stmt === false)
		{
			return false;
		}
		$stmt->bindValue(':user_id', (string)$user_id);
		$result = $stmt->execute();
		return $result && $result->fetchArray() !== false;
	}
	public static function listing()
	{
		// Status: 'active' (the user can sign in to at least one active account), 'disabled' (turned off on the server) or 'no account' (a member of no active
		// account, so the role has nobody to act as; a purge or a removal can leave one)
		$rows = array();
		$ready = phoromatic_memberships::ready();
		$result = @phoromatic_server::$db->query('SELECT r.UserID, u.UserName, u.AccountID, r.GrantedOn, r.GrantedBy' . ($ready ? ', u.DisabledOn, (SELECT COUNT(*) FROM phoromatic_memberships m JOIN phoromatic_accounts a ON a.AccountID = m.AccountID WHERE m.UserID = r.UserID AND m.AdminLevel IN (1, 2, 3, 10) AND (a.State IS NULL OR a.State = \'active\')) AS ActiveAccounts' : ', NULL AS DisabledOn, NULL AS ActiveAccounts') . ' FROM phoromatic_server_roles r LEFT JOIN phoromatic_users u ON u.UserID = r.UserID ORDER BY r.GrantedOn, r.UserID');
		while($result && $row = $result->fetchArray(SQLITE3_ASSOC))
		{
			$row['Status'] = $row['UserName'] === null ? 'no such user' : ($row['DisabledOn'] !== null ? 'disabled' : ($ready && (int)$row['ActiveAccounts'] === 0 ? 'no account' : 'active'));
			$rows[] = $row;
		}
		return $rows;
	}
	public static function grant($user_name, $actor_type, $actor, $actor_detail = null)
	{
		// null on success, else why nothing was changed. The role row and its audit row are one transaction.
		return self::change($user_name, true, $actor_type, $actor, $actor_detail);
	}
	public static function revoke($user_name, $actor_type, $actor, $actor_detail = null)
	{
		return self::change($user_name, false, $actor_type, $actor, $actor_detail);
	}
	protected static function change($user_name, $grant, $actor_type, $actor, $actor_detail)
	{
		if(!phoromatic_server::tenancy_ready())
		{
			return self::UNAVAILABLE;
		}
		$stmt = phoromatic_server::$db->prepare('SELECT UserID, UserName, AccountID FROM phoromatic_users WHERE UserName = :user_name');
		$stmt->bindValue(':user_name', (string)$user_name);
		$result = $stmt->execute();
		$user = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;
		if(!$user)
		{
			return 'There is no user named ' . phoromatic_audit_core::clean($user_name, 40) . '; nothing was changed.';
		}
		if($grant)
		{
			$usable = array_filter(phoromatic_memberships::for_user($user['UserID']), function($m) { return $m['Usable']; });
			$found = phoromatic_memberships::find_user_by_id($user['UserID']);
			if(empty($usable) || ($found && $found['DisabledOn'] !== null))
			{
				return $user['UserName'] . ' is disabled or belongs to no working account; nothing was changed.';
			}
		}
		if($grant === self::has($user['UserID']))
		{
			return $user['UserName'] . ($grant ? ' is already a server administrator' : ' is not a server administrator') . '; nothing was changed.';
		}
		if(@phoromatic_server::$db->exec('BEGIN IMMEDIATE') === false)
		{
			return 'The database is busy; nothing was changed.';
		}
		$stmt = $grant ? null : phoromatic_server::$db->prepare('DELETE FROM phoromatic_server_roles WHERE UserID = :user_id');
		if($stmt !== null)
		{
			$stmt->bindValue(':user_id', $user['UserID']);
		}
		$ok = $grant ? self::insert($user['UserID'], $actor) : $stmt->execute() !== false;
		$ok = $ok && phoromatic_server_audit::record($user['AccountID'], $actor_type, $actor, $grant ? 'server_role_granted' : 'server_role_revoked', array('actor_detail' => $actor_detail,
			'subject_type' => 'user', 'subject_id' => $user['UserID'], 'subject_label' => $user['UserName'], 'detail' => array('role' => 'admin')));
		if(!$ok || @phoromatic_server::$db->exec('COMMIT') === false)
		{
			$why = phoromatic_server_audit::last_error() ?: phoromatic_server::$db->lastErrorMsg();
			@phoromatic_server::$db->exec('ROLLBACK');
			phoromatic_server_audit::discard();
			return 'The change could not be made (' . $why . '); nothing was changed.';
		}
		phoromatic_server_audit::flush();
		return null;
	}
}

?>
