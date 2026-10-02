<?php

/*
	Benchy
	Deleting and restoring systems as tombstones.

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

// A deleted system is NOT removed from phoromatic_systems: the row stays as a tombstone (State -1 and DeletedOn set) so the SystemID is
// never issued again and results keep resolving to the old title. Existing queries that filter State >= 0 / > 0 already exclude it.
// The delete is one database transaction that starts with the audit row, so an audit failure leaves everything unchanged.
// Callers check authorisation (AdminLevel, POST, CSRF token) before calling.

class phoromatic_system_admin
{
	public static function valid_system_id($system_id)
	{
		return is_string($system_id) && preg_match('/\A[A-Za-z0-9]{1,32}\z/', $system_id) === 1;
	}
	public static function tombstone($account_id, $system_id)
	{
		// The tombstone row of this account, or false
		$stmt = phoromatic_server::$db->prepare('SELECT * FROM phoromatic_systems WHERE AccountID = :account_id AND SystemID = :system_id AND DeletedOn IS NOT NULL LIMIT 1');
		$stmt->bindValue(':account_id', $account_id);
		$stmt->bindValue(':system_id', (string)$system_id);
		$result = $stmt->execute();
		return $result ? $result->fetchArray(SQLITE3_ASSOC) : false;
	}
	public static function deleted_systems($account_id)
	{
		$stmt = phoromatic_server::$db->prepare('SELECT SystemID, Title, DeletedOn, DeletedBy FROM phoromatic_systems WHERE AccountID = :account_id AND DeletedOn IS NOT NULL ORDER BY DeletedOn DESC');
		$stmt->bindValue(':account_id', $account_id);
		$result = $stmt->execute();
		$rows = array();
		while($result && $row = $result->fetchArray(SQLITE3_ASSOC))
		{
			$rows[] = $row;
		}
		return $rows;
	}
	protected static function lists_containing($table, $id_column, $account_id, $system_id)
	{
		// Schedules / tickets of the account whose RunTargetSystems comma list names the system: array(id => new list)
		$found = array();
		$stmt = phoromatic_server::$db->prepare('SELECT ' . $id_column . ' AS ObjID, RunTargetSystems FROM ' . $table . ' WHERE AccountID = :account_id AND RunTargetSystems LIKE :pattern');
		$stmt->bindValue(':account_id', $account_id);
		$stmt->bindValue(':pattern', '%' . $system_id . '%');
		$result = $stmt->execute();
		while($result && $row = $result->fetchArray(SQLITE3_ASSOC))
		{
			$ids = explode(',', (string)$row['RunTargetSystems']);
			if(in_array($system_id, $ids, true))
			{
				$found[$row['ObjID']] = implode(',', array_values(array_diff($ids, array($system_id))));
			}
		}
		return $found;
	}
	public static function impact($account_id, $system_id)
	{
		// What a delete will change, for the confirmation page and the audit row
		$stmt = phoromatic_server::$db->prepare('SELECT COUNT(*) FROM phoromatic_results WHERE AccountID = :account_id AND SystemID = :system_id');
		$stmt->bindValue(':account_id', $account_id);
		$stmt->bindValue(':system_id', $system_id);
		$result = $stmt->execute();
		$row = $result ? $result->fetchArray(SQLITE3_NUM) : false;
		return array(
			'results_kept' => $row ? (int)$row[0] : 0,
			'schedules' => array_keys(self::lists_containing('phoromatic_schedules', 'ScheduleID', $account_id, $system_id)),
			'tickets' => array_keys(self::lists_containing('phoromatic_benchmark_tickets', 'TicketID', $account_id, $system_id)));
	}
	protected static function begin()
	{
		return @phoromatic_server::$db->exec('BEGIN IMMEDIATE') !== false;
	}
	protected static function rollback($why)
	{
		@phoromatic_server::$db->exec('ROLLBACK');
		phoromatic_admin_audit::mirror_note('rolled back, nothing changed: ' . $why);
	}
	public static function delete($account_id, $system_id, $actor_name, $actor_detail = null)
	{
		// array('ok' => bool, 'message' => string, 'warning' => string|null)
		if(!self::valid_system_id($system_id))
		{
			return array('ok' => false, 'message' => 'System not found or already deleted.');
		}
		if(!self::begin())
		{
			return array('ok' => false, 'message' => 'The system could not be deleted because the database is busy; nothing was changed.');
		}
		$stmt = phoromatic_server::$db->prepare('SELECT * FROM phoromatic_systems WHERE AccountID = :account_id AND SystemID = :system_id AND DeletedOn IS NULL');
		$stmt->bindValue(':account_id', $account_id);
		$stmt->bindValue(':system_id', $system_id);
		$result = $stmt->execute();
		$row = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;
		if(!$row)
		{
			@phoromatic_server::$db->exec('ROLLBACK');
			return array('ok' => false, 'message' => 'System not found or already deleted.');
		}
		if((int)$row['State'] != -1)
		{
			@phoromatic_server::$db->exec('ROLLBACK');
			return array('ok' => false, 'message' => 'Only a disabled system can be deleted. Disable it first.');
		}

		$schedules = self::lists_containing('phoromatic_schedules', 'ScheduleID', $account_id, $system_id);
		$tickets = self::lists_containing('phoromatic_benchmark_tickets', 'TicketID', $account_id, $system_id);
		$impact = self::impact($account_id, $system_id);
		$audit = phoromatic_admin_audit::record($account_id, 'user', $actor_name, 'system_delete', array(
			'actor_detail' => $actor_detail, 'subject_type' => 'system', 'subject_id' => $system_id, 'subject_label' => $row['Title'],
			'detail' => array('results_kept' => $impact['results_kept'], 'schedules_updated' => array_keys($schedules), 'tickets_updated' => array_keys($tickets),
				'groups_cleared' => (string)$row['Groups'], 'deleted_machine_self_id' => $row['MachineSelfID'], 'deleted_network_mac' => $row['NetworkMAC'])));
		if(!$audit)
		{
			self::rollback('audit failed: ' . phoromatic_admin_audit::last_error());
			return array('ok' => false, 'message' => 'The system could not be deleted because the audit record could not be written (' . phoromatic_admin_audit::last_error() . '); nothing was changed.');
		}

		$ok = true;
		$stmt = phoromatic_server::$db->prepare('UPDATE phoromatic_systems SET DeletedOn = :now, DeletedBy = :by, DeletedMachineSelfID = MachineSelfID, DeletedNetworkMAC = NetworkMAC, MachineSelfID = NULL, NetworkMAC = NULL, LastIP = NULL, LocalIP = NULL, SystemProperties = NULL, Groups = NULL, CurrentTask = \'Deleted\', CurrentProcessSchedule = NULL, CurrentProcessTicket = 0 WHERE AccountID = :account_id AND SystemID = :system_id AND State = -1 AND DeletedOn IS NULL');
		$stmt->bindValue(':now', phoromatic_server::current_time());
		$stmt->bindValue(':by', (string)$actor_name);
		$stmt->bindValue(':account_id', $account_id);
		$stmt->bindValue(':system_id', $system_id);
		$ok = @$stmt->execute() !== false && phoromatic_server::$db->changes() == 1;
		foreach(array('phoromatic_schedules' => array('ScheduleID', $schedules), 'phoromatic_benchmark_tickets' => array('TicketID', $tickets)) as $table => $spec)
		{
			foreach($ok ? $spec[1] : array() as $id => $new_list)
			{
				$stmt = phoromatic_server::$db->prepare('UPDATE ' . $table . ' SET RunTargetSystems = :list WHERE AccountID = :account_id AND ' . $spec[0] . ' = :id');
				$stmt->bindValue(':list', $new_list);
				$stmt->bindValue(':account_id', $account_id);
				$stmt->bindValue(':id', $id);
				$ok = $ok && @$stmt->execute() !== false;
			}
		}
		foreach($ok ? array('phoromatic_system_client_errors', 'phoromatic_system_warnings') : array() as $table)
		{
			$stmt = phoromatic_server::$db->prepare('DELETE FROM ' . $table . ' WHERE AccountID = :account_id AND SystemID = :system_id');
			$stmt->bindValue(':account_id', $account_id);
			$stmt->bindValue(':system_id', $system_id);
			$ok = $ok && @$stmt->execute() !== false;
		}
		if(!$ok || @phoromatic_server::$db->exec('COMMIT') === false)
		{
			self::rollback('delete step failed');
			return array('ok' => false, 'message' => 'The system could not be deleted because of a database error; nothing was changed.');
		}

		// Files after the commit: a failure leaves an inert directory that nothing reads for a tombstone
		$warning = null;
		$dir = phoromatic_server::phoromatic_account_system_path($account_id, $system_id);
		if(is_dir($dir))
		{
			$real = realpath($dir);
			$base = realpath(phoromatic_server::phoromatic_account_system_path($account_id));
			if($real === false || $base === false || strpos($real, $base . DIRECTORY_SEPARATOR) !== 0 || basename($real) !== $system_id)
			{
				$purged = false;
			}
			else
			{
				pts_file_io::delete($real . '/', null, true);
				clearstatcache();
				$purged = !is_dir($real);
			}
			if(!$purged)
			{
				phoromatic_admin_audit::record($account_id, 'user', $actor_name, 'system_delete_cleanup_failed', array('actor_detail' => $actor_detail,
					'subject_type' => 'system', 'subject_id' => $system_id, 'subject_label' => $row['Title'], 'detail' => array('path' => $dir)));
				$warning = 'The system was deleted, but its sensor and log files could not be removed from the server (' . htmlspecialchars($dir) . '). They are unused and can be removed by hand.';
			}
		}
		return array('ok' => true, 'message' => 'System deleted.', 'warning' => $warning);
	}
	public static function restore($account_id, $system_id, $actor_name, $actor_detail = null)
	{
		// Brings a tombstone back as a Disabled system, with its old identity unless a live system has taken that machine id or MAC
		if(!self::valid_system_id($system_id))
		{
			return array('ok' => false, 'message' => 'System not found or not deleted.');
		}
		if(!self::begin())
		{
			return array('ok' => false, 'message' => 'The system could not be restored because the database is busy; nothing was changed.');
		}
		$stmt = phoromatic_server::$db->prepare('SELECT * FROM phoromatic_systems WHERE AccountID = :account_id AND SystemID = :system_id AND DeletedOn IS NOT NULL');
		$stmt->bindValue(':account_id', $account_id);
		$stmt->bindValue(':system_id', $system_id);
		$result = $stmt->execute();
		$row = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;
		if(!$row)
		{
			@phoromatic_server::$db->exec('ROLLBACK');
			return array('ok' => false, 'message' => 'System not found or not deleted.');
		}
		foreach(array('MachineSelfID' => $row['DeletedMachineSelfID'], 'NetworkMAC' => $row['DeletedNetworkMAC']) as $column => $value)
		{
			if($value === null || $value === '')
			{
				continue;
			}
			$stmt = phoromatic_server::$db->prepare('SELECT SystemID FROM phoromatic_systems WHERE AccountID = :account_id AND ' . $column . ' = :value AND DeletedOn IS NULL LIMIT 1');
			$stmt->bindValue(':account_id', $account_id);
			$stmt->bindValue(':value', $value);
			$result = $stmt->execute();
			if($result && ($other = $result->fetchArray(SQLITE3_ASSOC)))
			{
				@phoromatic_server::$db->exec('ROLLBACK');
				return array('ok' => false, 'message' => 'This system cannot be restored: its machine identity is now used by system ' . htmlspecialchars($other['SystemID']) . '. Delete that system first if the old one should come back.');
			}
		}
		if(!phoromatic_admin_audit::record($account_id, 'user', $actor_name, 'system_restore', array('actor_detail' => $actor_detail, 'subject_type' => 'system',
			'subject_id' => $system_id, 'subject_label' => $row['Title'], 'detail' => array('deleted_on' => $row['DeletedOn'], 'deleted_by' => $row['DeletedBy'], 'note' => 'group and schedule membership and sensor data are not restored'))))
		{
			self::rollback('audit failed: ' . phoromatic_admin_audit::last_error());
			return array('ok' => false, 'message' => 'The system could not be restored because the audit record could not be written (' . phoromatic_admin_audit::last_error() . '); nothing was changed.');
		}
		$stmt = phoromatic_server::$db->prepare('UPDATE phoromatic_systems SET MachineSelfID = DeletedMachineSelfID, NetworkMAC = DeletedNetworkMAC, DeletedMachineSelfID = NULL, DeletedNetworkMAC = NULL, DeletedOn = NULL, DeletedBy = NULL, State = -1, CurrentTask = \'Awaiting Task\' WHERE AccountID = :account_id AND SystemID = :system_id AND DeletedOn IS NOT NULL');
		$stmt->bindValue(':account_id', $account_id);
		$stmt->bindValue(':system_id', $system_id);
		if(@$stmt->execute() === false || phoromatic_server::$db->changes() != 1 || @phoromatic_server::$db->exec('COMMIT') === false)
		{
			self::rollback('restore step failed');
			return array('ok' => false, 'message' => 'The system could not be restored because of a database error; nothing was changed.');
		}
		return array('ok' => true, 'message' => 'System restored. It is Disabled; enable it from its edit page when ready.');
	}
}

?>
