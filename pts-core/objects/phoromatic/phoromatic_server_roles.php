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
	public static function insert($user_id, $granted_by)
	{
		// Inside the caller's transaction; no audit row (the caller writes it). True if the row exists afterwards.
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
		$rows = array();
		$result = @phoromatic_server::$db->query('SELECT r.UserID, u.UserName, u.AccountID, r.GrantedOn, r.GrantedBy FROM phoromatic_server_roles r LEFT JOIN phoromatic_users u ON u.UserID = r.UserID ORDER BY r.GrantedOn, r.UserID');
		while($result && $row = $result->fetchArray(SQLITE3_ASSOC))
		{
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
		$stmt = phoromatic_server::$db->prepare('SELECT UserID, UserName, AccountID, AdminLevel FROM phoromatic_users WHERE UserName = :user_name');
		$stmt->bindValue(':user_name', (string)$user_name);
		$result = $stmt->execute();
		$user = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;
		if(!$user)
		{
			return 'There is no user named ' . phoromatic_audit_core::clean($user_name, 40) . '; nothing was changed.';
		}
		if($grant && !in_array($user['AdminLevel'], array(1, 2, 3, 10)) )
		{
			return $user['UserName'] . ' is disabled; nothing was changed.';
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
			return 'The change could not be made (' . $why . '); nothing was changed.';
		}
		return null;
	}
}

?>
