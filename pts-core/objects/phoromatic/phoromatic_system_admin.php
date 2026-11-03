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
	// SQL expression that reads a stored NetworkMAC the way normalise_mac() writes it (lowercase, no spaces, '-' as ':')
	const MAC_SQL = "lower(replace(replace(replace(NetworkMAC, '-', ':'), ' ', ''), char(9), ''))";
	const REFUSED_AUDIT_CAP_PER_HOUR = 100;
	const ACTIVE_WINDOW = 600;

	public static function normalise_mac($mac)
	{
		// null for a missing or placeholder MAC (empty, all zeros, all ff), which never identifies a machine
		$m = str_replace('-', ':', strtolower(preg_replace('/\s+/', '', (string)$mac)));
		$digits = str_replace(':', '', $m);
		if($digits === '' || preg_match('/\A(0+|f+)\z/', $digits))
		{
			return null;
		}
		return $m;
	}
	public static function valid_system_id($system_id)
	{
		return is_string($system_id) && preg_match('/\A[A-Za-z0-9]{1,32}\z/', $system_id) === 1;
	}
	public static function live_system_ids($account_id, $system_ids)
	{
		// The given ids that are existing, not deleted systems of the account (for run_on_systems[] posted by forms)
		$keep = array();
		foreach((array)$system_ids as $system_id)
		{
			if(!self::valid_system_id($system_id))
			{
				continue;
			}
			$stmt = phoromatic_server::$db->prepare('SELECT 1 FROM phoromatic_systems WHERE AccountID = :account_id AND SystemID = :system_id AND DeletedOn IS NULL');
			$stmt->bindValue(':account_id', $account_id);
			$stmt->bindValue(':system_id', $system_id);
			$result = $stmt->execute();
			if($result && $result->fetchArray(SQLITE3_NUM))
			{
				$keep[] = $system_id;
			}
		}
		return $keep;
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
				'groups_cleared' => (string)$row['Groups'], 'deleted_machine_self_id' => $row['MachineSelfID'], 'deleted_network_mac' => $row['NetworkMAC'], 'bench' => $row['BenchID'] !== null ? 'B' . $row['BenchID'] : null)));
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
		if($ok)
		{
			// The system's native agent token stops working with it
			$ok = phoromatic_agent_auth::revoke_in_tx($account_id, $system_id, 'system_deleted', 'user', $actor_name, $actor_detail) !== false;
		}
		if($ok && $row['BenchID'] !== null)
		{
			// A bench's last live system: its open session ends and the bench retires (history and labels stay)
			$ok = phoromatic_bench::member_deleted_in_tx($account_id, $row, $actor_name, $actor_detail);
		}
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
	public static function admin_allowed()
	{
		// AdminLevel 1 or 2, read from the database now (not from what the session remembers), so a demoted user loses the right at once
		if(PHOROMATIC_USER_IS_VIEWER || !isset($_SESSION['UserID']) || !isset($_SESSION['AccountID']))
		{
			return false;
		}
		$stmt = phoromatic_server::$db->prepare('SELECT AdminLevel FROM phoromatic_users WHERE UserID = :user_id AND AccountID = :account_id');
		$stmt->bindValue(':user_id', (string)$_SESSION['UserID']);
		$stmt->bindValue(':account_id', $_SESSION['AccountID']);
		$result = $stmt->execute();
		$row = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;
		return $row && $row['AdminLevel'] >= 1 && $row['AdminLevel'] <= 2;
	}
	public static function plain_title($title)
	{
		// A system title for a status line that other pages print without escaping: no markup characters, bounded
		return substr(preg_replace('/[^A-Za-z0-9 ._()#@+-]/', '', (string)$title), 0, 60);
	}
	public static function mac_clash($account_id, $machine_self_id, $network_mac)
	{
		// The live system of the account that already has this MAC under a DIFFERENT machine id (and is not itself a pending adoption
		// row), or false. An empty or placeholder MAC never matches.
		$normalised = self::normalise_mac($network_mac);
		if($normalised === null)
		{
			return false;
		}
		$stmt = phoromatic_server::$db->prepare('SELECT SystemID, Title FROM phoromatic_systems WHERE AccountID = :account_id AND ' . self::MAC_SQL . ' = :network_mac AND MachineSelfID IS NOT NULL AND MachineSelfID <> \'\' AND MachineSelfID <> :machine_self_id AND AdoptionCandidate IS NULL AND DeletedOn IS NULL ORDER BY State DESC, LastCommunication DESC LIMIT 1');
		$stmt->bindValue(':account_id', $account_id);
		$stmt->bindValue(':network_mac', $normalised);
		$stmt->bindValue(':machine_self_id', (string)$machine_self_id);
		$result = $stmt->execute();
		return $result ? $result->fetchArray(SQLITE3_ASSOC) : false;
	}
	public static function adoption_buttons($pending_id, $pending_mac, $candidate_title, $candidate_last_communication)
	{
		// The Adopt / Register as new forms for a pending row that looks like an existing system (AdminLevel 1-2 only)
		if(!self::admin_allowed())
		{
			return '';
		}
		$h_id = htmlspecialchars($pending_id);
		$html = '';
		if($candidate_title !== null && self::normalise_mac($pending_mac) !== null)
		{
			$active = strtotime($candidate_last_communication) > time() - self::ACTIVE_WINDOW;
			$html .= '<form action="?systems/' . $h_id . '" method="post" style="display: inline;">' . write_token_in_form() . '<input type="hidden" name="adopt_system" value="1" />' .
				($active ? '<label><input type="checkbox" name="confirm_active" value="1" /> ' . htmlspecialchars($candidate_title) . ' checked in within the last 10 minutes and may still be running; adopt anyway</label> ' : '') .
				'<input type="submit" value="Adopt as ' . htmlspecialchars($candidate_title) . '" onclick="return confirm(\'The new client takes over the existing system record, with its state, groups and results. Continue?\');" /></form> ';
		}
		$html .= '<form action="?systems/' . $h_id . '" method="post" style="display: inline;">' . write_token_in_form() . '<input type="hidden" name="register_new_system" value="1" /><input type="submit" value="Register as new" onclick="return confirm(\'Keep this as a separate system? It is then approved like any other new system.\');" /></form>';
		return $html;
	}
	protected static function short_hash($value)
	{
		return substr(sha1((string)$value), 0, 12);
	}
	public static function note_adopt_refused($account_id, $new_system_id, $machine_self_id, $network_mac, $candidate)
	{
		// Written by the check-in path when a client with an unknown machine id presents an existing system's MAC. One row per
		// (machine id, MAC) per hour so a flapping client cannot flood the log. The new row stays pending whatever happens here, so
		// a failure to audit is logged but does not block the (safe) registration. The machine id itself is not stored (hash only).
		try
		{
			$key = substr(preg_replace('/[^\x20-\x7e]/', '?', 'msi ' . self::short_hash($machine_self_id) . ' mac ' . strtolower((string)$network_mac)), 0, 100);
			$stmt = @phoromatic_server::$db->prepare('SELECT 1 FROM phoromatic_admin_audit WHERE AccountID = :account_id AND Action = \'system_adopt_refused\' AND ActorDetail = :key AND EventTime >= :since LIMIT 1');
			if($stmt === false)
			{
				return false;
			}
			$stmt->bindValue(':account_id', $account_id);
			$stmt->bindValue(':key', $key);
			$stmt->bindValue(':since', date('Y-m-d H:i:s', time() - 3600));
			$result = $stmt->execute();
			if($result && $result->fetchArray(SQLITE3_NUM))
			{
				return false;
			}
			// and a ceiling per account, so a client minting machine ids cannot flood the audit table and log
			$stmt = @phoromatic_server::$db->prepare('SELECT COUNT(*) FROM phoromatic_admin_audit WHERE AccountID = :account_id AND Action = \'system_adopt_refused\' AND EventTime >= :since');
			if($stmt === false)
			{
				return false;
			}
			$stmt->bindValue(':account_id', $account_id);
			$stmt->bindValue(':since', date('Y-m-d H:i:s', time() - 3600));
			$result = $stmt->execute();
			$count = $result ? $result->fetchArray(SQLITE3_NUM) : false;
			if(!$count || $count[0] >= self::REFUSED_AUDIT_CAP_PER_HOUR)
			{
				return false;
			}
			return phoromatic_admin_audit::record($account_id, 'machine', $new_system_id, 'system_adopt_refused', array('actor_detail' => $key, 'subject_type' => 'system',
				'subject_id' => $candidate['SystemID'], 'subject_label' => $candidate['Title'],
				'detail' => array('new_pending_system_id' => $new_system_id, 'network_mac' => $network_mac, 'machine_self_id_hash' => self::short_hash($machine_self_id),
					'note' => 'unknown machine id presented the MAC of an existing system; registered as a new pending system, nothing inherited')));
		}
		catch(Throwable $e)
		{
			return false;
		}
	}
	protected static function pending_pair($account_id, $pending_id)
	{
		// array(pending row, candidate row) as they are right now (inside the caller's transaction), or a string saying what is wrong
		if(!self::valid_system_id($pending_id))
		{
			return 'System not found.';
		}
		$stmt = phoromatic_server::$db->prepare('SELECT * FROM phoromatic_systems WHERE AccountID = :account_id AND SystemID = :system_id AND DeletedOn IS NULL');
		$stmt->bindValue(':account_id', $account_id);
		$stmt->bindValue(':system_id', $pending_id);
		$result = $stmt->execute();
		$pending = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;
		if(!$pending || !in_array((int)$pending['State'], array(0, -1), true) || $pending['AdoptionCandidate'] === null || $pending['AdoptionCandidate'] === '')
		{
			return 'This system is not waiting for an adoption decision (it may already have been handled).';
		}
		$stmt = phoromatic_server::$db->prepare('SELECT * FROM phoromatic_systems WHERE AccountID = :account_id AND SystemID = :system_id AND DeletedOn IS NULL AND AdoptionCandidate IS NULL');
		$stmt->bindValue(':account_id', $account_id);
		$stmt->bindValue(':system_id', $pending['AdoptionCandidate']);
		$result = $stmt->execute();
		$candidate = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;
		return array($pending, $candidate);
	}
	public static function adopt($account_id, $pending_id, $actor_name, $actor_detail = null, $acknowledge_active = false)
	{
		// The new client of a pending row takes over the existing system's record (its State, Groups, title, results stay), and the
		// pending row is removed. array('ok' => bool, 'message' => string)
		if(!self::begin())
		{
			return array('ok' => false, 'message' => 'The system could not be adopted because the database is busy; nothing was changed.');
		}
		$pair = self::pending_pair($account_id, $pending_id);
		if(is_string($pair))
		{
			@phoromatic_server::$db->exec('ROLLBACK');
			return array('ok' => false, 'message' => $pair);
		}
		list($pending, $existing) = $pair;
		if(!$existing)
		{
			@phoromatic_server::$db->exec('ROLLBACK');
			return array('ok' => false, 'message' => 'The system it looked like no longer exists (deleted or changed). Use "Register as new" instead.');
		}
		if($pending['MachineSelfID'] === null || $pending['MachineSelfID'] === '')
		{
			@phoromatic_server::$db->exec('ROLLBACK');
			return array('ok' => false, 'message' => 'This client reports no machine id, so it cannot take over a system. Use "Register as new" instead.');
		}
		$pending_mac = self::normalise_mac($pending['NetworkMAC']);
		if($pending_mac === null || $pending_mac !== self::normalise_mac($existing['NetworkMAC']))
		{
			@phoromatic_server::$db->exec('ROLLBACK');
			return array('ok' => false, 'message' => 'The two systems no longer report the same MAC address, so this cannot be adopted. Use "Register as new" instead.');
		}
		$stmt = phoromatic_server::$db->prepare('SELECT SystemID FROM phoromatic_systems WHERE MachineSelfID = :msi AND SystemID <> :pending AND DeletedOn IS NULL LIMIT 1');
		$stmt->bindValue(':msi', $pending['MachineSelfID']);
		$stmt->bindValue(':pending', $pending['SystemID']);
		$result = $stmt->execute();
		if($result && $result->fetchArray(SQLITE3_NUM))
		{
			@phoromatic_server::$db->exec('ROLLBACK');
			return array('ok' => false, 'message' => 'This client\'s machine id is already used by another system, so it cannot be adopted. Use "Register as new" instead.');
		}
		if(!$acknowledge_active && strtotime($existing['LastCommunication']) > time() - self::ACTIVE_WINDOW)
		{
			@phoromatic_server::$db->exec('ROLLBACK');
			return array('ok' => false, 'message' => htmlspecialchars($existing['Title']) . ' checked in within the last 10 minutes and may still be running its client. Tick the confirmation box next to the button to adopt anyway; nothing was changed.');
		}
		$detail = array('pending_system_id' => $pending['SystemID'], 'network_mac' => $pending['NetworkMAC'], 'old_network_mac' => $existing['NetworkMAC'],
			'old_machine_self_id_hash' => self::short_hash($existing['MachineSelfID']), 'new_machine_self_id_hash' => self::short_hash($pending['MachineSelfID']),
			'state_kept' => (int)$existing['State'], 'groups_kept' => (string)$existing['Groups']);
		if(isset($existing['BenchTaskSession']))
		{
			// A bench's outstanding task snapshot is cleared below: the old install it was issued to now registers as a pending clash
			// with no access, so its result can never arrive, and the new install gets the task again if it is still due
			$detail['bench_task_session_cleared'] = (int)$existing['BenchTaskSession'];
		}
		if(!phoromatic_admin_audit::record($account_id, 'user', $actor_name, 'system_adopt', array('actor_detail' => $actor_detail, 'subject_type' => 'system',
			'subject_id' => $existing['SystemID'], 'subject_label' => $existing['Title'], 'detail' => $detail)))
		{
			self::rollback('audit failed: ' . phoromatic_admin_audit::last_error());
			return array('ok' => false, 'message' => 'The system could not be adopted because the audit record could not be written (' . htmlspecialchars(phoromatic_admin_audit::last_error()) . '); nothing was changed.');
		}
		$stmt = phoromatic_server::$db->prepare('UPDATE phoromatic_systems SET MachineSelfID = :msi, NetworkMAC = :mac, GSID = :gsid, Hardware = :hw, Software = :sw, SystemProperties = :props, ClientVersion = :cv, CoreVersion = :corev, LastIP = :lastip, LocalIP = :localip, NetworkWakeOnLAN = :wol, LastCommunication = :lastcomm, CurrentTask = \'Awaiting Task\', CurrentProcessSchedule = NULL, CurrentProcessTicket = 0, TickThreadEvent = NULL, BenchTaskSession = NULL, BenchTaskIssuedOn = NULL WHERE AccountID = :account_id AND SystemID = :system_id AND DeletedOn IS NULL AND AdoptionCandidate IS NULL');
		foreach(array(':msi' => 'MachineSelfID', ':mac' => 'NetworkMAC', ':gsid' => 'GSID', ':hw' => 'Hardware', ':sw' => 'Software', ':props' => 'SystemProperties', ':cv' => 'ClientVersion',
			':corev' => 'CoreVersion', ':lastip' => 'LastIP', ':localip' => 'LocalIP', ':wol' => 'NetworkWakeOnLAN', ':lastcomm' => 'LastCommunication') as $param => $column)
		{
			$stmt->bindValue($param, $pending[$column]);
		}
		$stmt->bindValue(':account_id', $account_id);
		$stmt->bindValue(':system_id', $existing['SystemID']);
		$ok = @$stmt->execute() !== false && phoromatic_server::$db->changes() == 1;
		if($ok)
		{
			// The pending row is kept as a tombstone with no identity, so its SystemID is never issued again (audit rows name it)
			$stmt = phoromatic_server::$db->prepare('UPDATE phoromatic_systems SET DeletedOn = :now, DeletedBy = :by, State = -1, MachineSelfID = NULL, NetworkMAC = NULL, DeletedMachineSelfID = NULL, DeletedNetworkMAC = NULL, AdoptionCandidate = NULL, LastIP = NULL, LocalIP = NULL, SystemProperties = NULL, Groups = NULL, CurrentTask = :task WHERE AccountID = :account_id AND SystemID = :system_id AND State IN (0, -1) AND AdoptionCandidate = :candidate AND DeletedOn IS NULL');
			$stmt->bindValue(':now', phoromatic_server::current_time());
			$stmt->bindValue(':by', (string)$actor_name);
			$stmt->bindValue(':task', 'Adopted into ' . $existing['SystemID']);
			$stmt->bindValue(':account_id', $account_id);
			$stmt->bindValue(':system_id', $pending['SystemID']);
			$stmt->bindValue(':candidate', $existing['SystemID']);
			$ok = @$stmt->execute() !== false && phoromatic_server::$db->changes() == 1;
		}
		if(!$ok || @phoromatic_server::$db->exec('COMMIT') === false)
		{
			self::rollback('adopt step failed');
			return array('ok' => false, 'message' => 'The system could not be adopted because of a database error; nothing was changed.');
		}
		return array('ok' => true, 'message' => 'The new client now uses the existing system record ' . htmlspecialchars($existing['Title']) . ' (' . htmlspecialchars($existing['SystemID']) . '); its state, groups and results are unchanged.');
	}
	public static function register_new($account_id, $pending_id, $actor_name, $actor_detail = null)
	{
		// The pending row stays a separate system; only the "looks like <system>" mark is cleared, so it is approved like any new system
		if(!self::begin())
		{
			return array('ok' => false, 'message' => 'The system could not be updated because the database is busy; nothing was changed.');
		}
		$pair = self::pending_pair($account_id, $pending_id);
		if(is_string($pair))
		{
			@phoromatic_server::$db->exec('ROLLBACK');
			return array('ok' => false, 'message' => $pair);
		}
		list($pending, $existing) = $pair;
		if(!phoromatic_admin_audit::record($account_id, 'user', $actor_name, 'system_register_new', array('actor_detail' => $actor_detail, 'subject_type' => 'system',
			'subject_id' => $pending['SystemID'], 'subject_label' => $pending['Title'],
			'detail' => array('was_candidate' => $pending['AdoptionCandidate'], 'network_mac' => $pending['NetworkMAC']))))
		{
			self::rollback('audit failed: ' . phoromatic_admin_audit::last_error());
			return array('ok' => false, 'message' => 'The system could not be updated because the audit record could not be written (' . htmlspecialchars(phoromatic_admin_audit::last_error()) . '); nothing was changed.');
		}
		$stmt = phoromatic_server::$db->prepare('UPDATE phoromatic_systems SET AdoptionCandidate = NULL, CurrentTask = CASE WHEN State = 0 THEN \'Awaiting Authorization\' ELSE CurrentTask END WHERE AccountID = :account_id AND SystemID = :system_id AND State IN (0, -1) AND AdoptionCandidate = :candidate AND DeletedOn IS NULL');
		$stmt->bindValue(':account_id', $account_id);
		$stmt->bindValue(':system_id', $pending['SystemID']);
		$stmt->bindValue(':candidate', $pending['AdoptionCandidate']);
		if(@$stmt->execute() === false || phoromatic_server::$db->changes() != 1 || @phoromatic_server::$db->exec('COMMIT') === false)
		{
			self::rollback('register as new step failed');
			return array('ok' => false, 'message' => 'The system could not be updated because of a database error; nothing was changed.');
		}
		return array('ok' => true, 'message' => htmlspecialchars($pending['Title']) . ' is now a separate pending system. Approve it from its edit page when ready.');
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
		// A restored system does not rejoin a bench that retired when it was deleted (restoring never reopens a session)
		$stmt = phoromatic_server::$db->prepare('UPDATE phoromatic_systems SET MachineSelfID = DeletedMachineSelfID, NetworkMAC = DeletedNetworkMAC, DeletedMachineSelfID = NULL, DeletedNetworkMAC = NULL, DeletedOn = NULL, DeletedBy = NULL, State = -1, CurrentTask = \'Awaiting Task\', BenchID = CASE WHEN EXISTS (SELECT 1 FROM phoromatic_benches b WHERE b.AccountID = phoromatic_systems.AccountID AND b.BenchID = phoromatic_systems.BenchID AND b.RetiredOn IS NULL) THEN BenchID ELSE NULL END WHERE AccountID = :account_id AND SystemID = :system_id AND DeletedOn IS NOT NULL');
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
