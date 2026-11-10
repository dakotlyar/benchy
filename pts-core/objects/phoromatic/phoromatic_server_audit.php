<?php

/*
	Benchy
	phoromatic_server_audit.php: the append-only audit trail of server-wide events (settings, server roles, migrations), kept apart from any account's

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

// Rows live in phoromatic.db (table phoromatic_server_audit, created by prepare_database() at schema 59). TenantID is the AccountID an
// event concerns, or NULL for the server itself. Append-only like phoromatic_admin_audit: the table refuses UPDATE and DELETE and an
// insert that reuses an AuditID. Only a server administrator ever reads it; a tenant never sees these rows.

class phoromatic_server_audit
{
	const ACTIONS = array('server_role_granted', 'server_role_revoked', 'server_setting_changed', 'migration');
	const ACTOR_TYPES = array('user', 'root', 'cli', 'migration', 'system');

	protected static $last_error = null;
	protected static $pending = array();
	protected static $shutdown_registered = false;

	public static function last_error()
	{
		return self::$last_error;
	}
	public static function mirror_path()
	{
		return pts_logger::default_log_file_path() . 'phoromatic-server-audit.log';
	}
	public static function record($tenant_id, $actor_type, $actor, $action, $fields = array())
	{
		// $fields may carry: actor_detail, source_ip, subject_type, subject_id, subject_label, detail (array, stored as JSON). True only if
		// both the database row and the mirror log line were written; last_error() says what failed. Safe inside an open transaction.
		self::$last_error = null;
		try
		{
			$f = array();
			foreach(array('actor_detail', 'source_ip', 'subject_type', 'subject_id', 'subject_label') as $k)
			{
				$f[$k] = isset($fields[$k]) ? phoromatic_audit_core::clean($fields[$k]) : null;
			}
			if($f['source_ip'] === null && isset($_SERVER['REMOTE_ADDR']))
			{
				$f['source_ip'] = phoromatic_audit_core::clean($_SERVER['REMOTE_ADDR']);
			}
			$actor_type = in_array($actor_type, self::ACTOR_TYPES, true) ? $actor_type : 'system';
			$actor = phoromatic_audit_core::clean($actor);
			$detail = isset($fields['detail']) ? phoromatic_audit_core::encode_detail($fields['detail']) : null;
			$line = 'server audit: ' . $action . ' by ' . $actor_type . ' ' . $actor . ($f['source_ip'] ? ' from ' . $f['source_ip'] : '') . ($tenant_id !== null && $tenant_id !== '' ? ' tenant ' . phoromatic_audit_core::clean($tenant_id) : '') .
				($f['subject_type'] !== null ? ' ' . $f['subject_type'] . ' ' . $f['subject_id'] . ' "' . $f['subject_label'] . '"' : '') . ($detail !== null ? ' ' . $detail : '');
			$stored = false;
			if(!phoromatic_server::tenancy_ready())
			{
				self::$last_error = 'the database upgrade that creates the server audit has not run';
			}
			else if(!in_array($action, self::ACTIONS, true))
			{
				self::$last_error = 'unknown audit action';
			}
			else if(self::database() == null)
			{
				self::$last_error = 'audit database unavailable';
			}
			else if(($stmt = @phoromatic_server::$db->prepare('INSERT INTO phoromatic_server_audit (EventTime, ActorType, Actor, ActorDetail, SourceIP, Action, TenantID, SubjectType, SubjectID, SubjectLabel, Detail) VALUES (:event_time, :actor_type, :actor, :actor_detail, :source_ip, :action, :tenant_id, :subject_type, :subject_id, :subject_label, :detail)')) === false)
			{
				self::$last_error = 'server audit table unavailable';
			}
			else
			{
				$stmt->bindValue(':event_time', phoromatic_server::current_time());
				$stmt->bindValue(':actor_type', $actor_type);
				$stmt->bindValue(':actor', $actor);
				$stmt->bindValue(':action', $action);
				$stmt->bindValue(':tenant_id', $tenant_id === null || $tenant_id === '' ? null : phoromatic_audit_core::clean($tenant_id));
				foreach($f as $k => $v)
				{
					$stmt->bindValue(':' . $k, $v);
				}
				$stmt->bindValue(':detail', $detail);
				$stored = @$stmt->execute() !== false;
				if(!$stored)
				{
					self::$last_error = 'server audit row could not be written';
				}
				else
				{
					// The line goes to the log only once the row is committed (flush()): a change that is rolled back leaves no line behind
					self::$pending[(int)phoromatic_server::$db->lastInsertRowID()] = $line;
					if(!self::$shutdown_registered)
					{
						self::$shutdown_registered = true;
						register_shutdown_function(array(__CLASS__, 'flush'));
					}
				}
			}
			return $stored;
		}
		catch(Throwable $e)
		{
			self::$last_error = 'server audit error: ' . $e->getMessage();
			return false;
		}
	}
	public static function flush()
	{
		// Writes the log lines of the rows that are in the database for everybody (a second connection sees only what is committed) and
		// forgets the rest. Called after a commit, and at the end of the request for whatever is left.
		if(empty(self::$pending))
		{
			return true;
		}
		$ok = true;
		$committed = array();
		try
		{
			$other = new SQLite3(phoromatic_server::phoromatic_path(false) . 'phoromatic.db', SQLITE3_OPEN_READONLY);
			$other->busyTimeout(5000);
			foreach(array_keys(self::$pending) as $id)
			{
				if($other->querySingle('SELECT 1 FROM phoromatic_server_audit WHERE AuditID = ' . (int)$id))
				{
					$committed[] = $id;
				}
			}
			$other->close();
		}
		catch(Throwable $e)
		{
			$committed = array();
		}
		foreach($committed as $id)
		{
			$ok = phoromatic_audit_core::mirror(self::mirror_path(), self::$pending[$id]) && $ok;
		}
		self::$pending = array();
		return $ok;
	}
	protected static function database()
	{
		if(phoromatic_server::$db == null)
		{
			phoromatic_server::prepare_database();
		}
		return phoromatic_server::$db;
	}
	public static function recent($limit = 50)
	{
		// Newest first; for a server administrator's page or command, never for a tenant
		if(phoromatic_server::$db == null)
		{
			return array();
		}
		$stmt = phoromatic_server::$db->prepare('SELECT * FROM phoromatic_server_audit ORDER BY AuditID DESC LIMIT :limit');
		$stmt->bindValue(':limit', max(1, (int)$limit), SQLITE3_INTEGER);
		$result = $stmt->execute();
		$rows = array();
		while($result && $row = $result->fetchArray(SQLITE3_ASSOC))
		{
			$rows[] = $row;
		}
		return $rows;
	}
}

?>
