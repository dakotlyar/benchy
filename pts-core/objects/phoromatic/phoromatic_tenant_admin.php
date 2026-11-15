<?php

/*
	Benchy
	phoromatic_tenant_admin.php: the life of a tenant (an account) as a server administrator manages it: rename, suspend, resume, delete, restore, purge

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

// One place for what the console pages and the server-tenant-* commands do, so both refuse the same things. Nothing here checks who is asking:
// the callers do (the pages and r_server_action ask phoromatic_auth::is_server_admin() on the request, the commands run on the server host).
// Every change is one transaction with its row in the server audit (phoromatic_server_audit): no audit row, no change. Creating an account is
// phoromatic_accounts::create().
//
//   active --suspend--> suspended --resume--> active
//   suspended --delete--> deleted (a tombstone: the row and every file stay) --restore--> suspended
//   deleted --purge--> deleted with PurgedOn (rows and files gone, the account row and the audit kept; the id is never handed out again)
//
// $who is array(actor type, actor, actor detail, own account): the audit actor, and the account the actor is signed in to ('' for root and the
// command line), which a user may not suspend or delete from under their own session.

class phoromatic_tenant_admin
{
	const PAST = array('suspend' => 'suspended', 'resume' => 'resumed', 'delete' => 'deleted', 'restore' => 'restored');
	const NAME_MAX = 64;
	const REASON_MAX = 200;
	const TRANSITIONS = array(
		'suspend' => array('active', 'suspended', 'tenant_suspended'),
		'resume' => array('suspended', 'active', 'tenant_resumed'),
		'delete' => array('suspended', 'deleted', 'tenant_deleted'),
		'restore' => array('deleted', 'suspended', 'tenant_restored'));
	// Tables that keep an account's AccountID after a purge: the account row itself, its users (never deleted by a purge), and the audit tables
	const PURGE_KEEPS = array('phoromatic_accounts', 'phoromatic_users', 'phoromatic_admin_audit', 'phoromatic_private_repo_audit', 'phoromatic_server_audit');

	public static function normalize_name($name)
	{
		return trim(preg_replace('/\s+/u', ' ', (string)$name));
	}
	public static function name_problem($name, $except_account = null)
	{
		// null when the name can be given to an account, else why not. Letters, digits, spaces and punctuation of any language: no control
		// characters. Two accounts never share a name (compared without regard to case, deleted accounts included).
		if(!is_string($name) || preg_match('//u', $name) !== 1)
		{
			return 'The name is not valid text.';
		}
		$name = self::normalize_name($name);
		if($name === '')
		{
			return 'Give the account a name.';
		}
		if(preg_match('/\p{C}/u', $name) === 1)
		{
			return 'The name cannot contain control characters.';
		}
		if(mb_strlen($name, 'UTF-8') > self::NAME_MAX)
		{
			return 'The name can be at most ' . self::NAME_MAX . ' characters.';
		}
		$result = phoromatic_server::$db->query('SELECT AccountID, GroupName FROM phoromatic_accounts');
		while($result && ($row = $result->fetchArray(SQLITE3_ASSOC)))
		{
			if($row['GroupName'] !== null && ($except_account === null || $row['AccountID'] !== $except_account) && mb_strtolower(self::normalize_name($row['GroupName']), 'UTF-8') === mb_strtolower($name, 'UTF-8'))
			{
				return 'Another account is already called ' . $name . ' (names are compared without regard to case, and deleted accounts keep theirs).';
			}
		}
		return null;
	}
	public static function display_name($row)
	{
		return isset($row['GroupName']) && trim((string)$row['GroupName']) !== '' ? (string)$row['GroupName'] : 'Account ' . $row['AccountID'];
	}
	public static function get($account_id)
	{
		// The account's row (State is never null here), or false
		if(phoromatic_tenant_path::segment($account_id) === false || !phoromatic_server::tenancy_ready())
		{
			return false;
		}
		$stmt = phoromatic_server::$db->prepare('SELECT * FROM phoromatic_accounts WHERE AccountID = :account_id');
		$stmt->bindValue(':account_id', (string)$account_id);
		$result = $stmt->execute();
		$row = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;
		if(!$row)
		{
			return false;
		}
		$row['State'] = $row['State'] === null ? phoromatic_tenant::ACTIVE : $row['State'];
		return $row;
	}
	public static function listing($state = null)
	{
		// Every account (oldest first) with its counts, for the console; $state limits it to one state, 'purged' to the purged ones
		$rows = array();
		if(!phoromatic_server::tenancy_ready())
		{
			return $rows;
		}
		$result = phoromatic_server::$db->query('SELECT * FROM phoromatic_accounts ORDER BY CreatedOn ASC, rowid ASC');
		while($result && ($row = $result->fetchArray(SQLITE3_ASSOC)))
		{
			$row['State'] = $row['State'] === null ? phoromatic_tenant::ACTIVE : $row['State'];
			if($state === null || ($state === 'purged' ? $row['PurgedOn'] !== null : $row['State'] === $state))
			{
				$rows[] = $row;
			}
		}
		return $rows;
	}
	public static function count_rows($table, $account_id)
	{
		// (a name from this class's own list, never from a request)
		$stmt = @phoromatic_server::$db->prepare('SELECT COUNT(*) AS n FROM ' . $table . ' WHERE AccountID = :account_id');
		if($stmt === false)
		{
			return 0;
		}
		$stmt->bindValue(':account_id', (string)$account_id);
		$result = $stmt->execute();
		$row = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;
		return $row ? (int)$row['n'] : 0;
	}
	public static function counts($account_id)
	{
		return array('users' => self::count_rows('phoromatic_users', $account_id), 'systems' => self::count_rows('phoromatic_systems', $account_id),
			'schedules' => self::count_rows('phoromatic_schedules', $account_id), 'results' => self::count_rows('phoromatic_results', $account_id));
	}
	public static function running_systems($account_id)
	{
		// How many systems of the account are in the middle of a task now (for the suspend page)
		return count(phoromatic_server::systems_running_tests($account_id));
	}
	protected static function clean_reason($reason)
	{
		return $reason === null ? null : phoromatic_audit_core::clean(trim((string)$reason), self::REASON_MAX);
	}
	protected static function begin()
	{
		return phoromatic_server::tenancy_ready() ? @phoromatic_server::$db->exec('BEGIN IMMEDIATE') !== false : false;
	}
	protected static function fail($why)
	{
		@phoromatic_server::$db->exec('ROLLBACK');
		phoromatic_server_audit::discard();
		return 'The change could not be made (' . $why . '); nothing was changed.';
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
	public static function rename($account_id, $name, $who)
	{
		// null on success, else why nothing was changed
		if(!is_string($name))
		{
			return 'Give the account a name.';
		}
		if(!phoromatic_server::tenancy_ready())
		{
			return phoromatic_server_roles::UNAVAILABLE;
		}
		if(!self::begin())
		{
			return 'The database is busy; nothing was changed.';
		}
		$row = self::get($account_id);
		if(!$row)
		{
			@phoromatic_server::$db->exec('ROLLBACK');
			return 'There is no such account.';
		}
		if($row['PurgedOn'] !== null)
		{
			@phoromatic_server::$db->exec('ROLLBACK');
			return 'This account was purged; nothing is left to rename.';
		}
		if(($problem = self::name_problem($name, $row['AccountID'])) !== null)
		{
			@phoromatic_server::$db->exec('ROLLBACK');
			return $problem;
		}
		$new = self::normalize_name($name);
		$stmt = phoromatic_server::$db->prepare('UPDATE phoromatic_accounts SET GroupName = :name WHERE AccountID = :account_id');
		$stmt->bindValue(':name', $new);
		$stmt->bindValue(':account_id', $row['AccountID']);
		if(@$stmt->execute() === false || !phoromatic_server_audit::record($row['AccountID'], $who[0], $who[1], 'tenant_renamed', array('actor_detail' => $who[2], 'subject_type' => 'tenant',
			'subject_id' => $row['AccountID'], 'subject_label' => $new, 'detail' => array('before' => $row['GroupName'], 'after' => $new))))
		{
			return self::fail(phoromatic_server_audit::last_error() ?: phoromatic_server::$db->lastErrorMsg());
		}
		return self::finish();
	}
	public static function change_state($action, $account_id, $who, $reason = null, $confirm_name = null, $confirm_running = false)
	{
		// suspend, resume, delete or restore. null on success, else why nothing was changed. Delete needs the account's name typed ($confirm_name).
		if(!isset(self::TRANSITIONS[$action]))
		{
			return 'Unknown action; nothing was changed.';
		}
		list($from, $to, $audit_action) = self::TRANSITIONS[$action];
		if(!phoromatic_server::tenancy_ready())
		{
			return phoromatic_server_roles::UNAVAILABLE;
		}
		if(!self::begin())
		{
			return 'The database is busy; nothing was changed.';
		}
		$row = self::get($account_id);
		$stop = null;
		if(!$row)
		{
			$stop = 'There is no such account.';
		}
		else if($row['PurgedOn'] !== null)
		{
			$stop = 'This account was purged; nothing is left to ' . $action . '.';
		}
		else if($row['State'] !== $from)
		{
			$stop = 'The account is ' . $row['State'] . ', and only an account that is ' . $from . ' can be ' . self::PAST[$action] . '; nothing was changed.';
		}
		else if(in_array($action, array('suspend', 'delete'), true) && $who[3] !== '' && (string)$who[3] === (string)$row['AccountID'])
		{
			$stop = 'You are signed in to this account, and suspending it would end your own session. Ask another server administrator, or use the server-tenant commands on the server.';
		}
		else if($action === 'delete' && (!is_string($confirm_name) || self::normalize_name($confirm_name) !== self::normalize_name(self::display_name($row))))
		{
			$stop = 'Type the account\'s name exactly to delete it; nothing was changed.';
		}
		else if($action === 'suspend' && !$confirm_running && self::running_systems($row['AccountID']) > 0)
		{
			$stop = self::running_systems($row['AccountID']) . ' system(s) of this account are running a task now. A run that finishes while the account is suspended cannot upload its result (the system keeps its local copy). Tick the box to suspend anyway; nothing was changed.';
		}
		if($stop !== null)
		{
			@phoromatic_server::$db->exec('ROLLBACK');
			return $stop;
		}
		$now = phoromatic_server::current_time();
		$reason = self::clean_reason($reason);
		$stmt = phoromatic_server::$db->prepare('UPDATE phoromatic_accounts SET State = :to, StateChangedOn = :now, StateChangedBy = :by, StateReason = :reason, DeletedOn = :deleted_on WHERE AccountID = :account_id AND State = :from AND PurgedOn IS NULL');
		$stmt->bindValue(':to', $to);
		$stmt->bindValue(':now', $now);
		$stmt->bindValue(':by', phoromatic_audit_core::clean($who[1]));
		$stmt->bindValue(':reason', $reason);
		$stmt->bindValue(':deleted_on', $action === 'delete' ? $now : ($action === 'restore' ? null : $row['DeletedOn']));
		$stmt->bindValue(':account_id', $row['AccountID']);
		$stmt->bindValue(':from', $from);
		if(@$stmt->execute() === false || phoromatic_server::$db->changes() !== 1 || !phoromatic_server_audit::record($row['AccountID'], $who[0], $who[1], $audit_action, array('actor_detail' => $who[2],
			'subject_type' => 'tenant', 'subject_id' => $row['AccountID'], 'subject_label' => self::display_name($row), 'detail' => array('from' => $from, 'to' => $to, 'reason' => $reason))))
		{
			return self::fail(phoromatic_server_audit::last_error() ?: phoromatic_server::$db->lastErrorMsg());
		}
		return self::finish();
	}
	public static function purge($account_id, $who, $confirm_name, $confirm_id, &$leftover = null)
	{
		// Removes a deleted account's rows and files for good. Only from deleted, only with the name and the account id typed exactly. null on
		// success, else why nothing was changed. $leftover is set to the folder that could not be removed when the rows are gone but the files are
		// not (the account is purged either way; the audit says so).
		$leftover = null;
		if(!phoromatic_server::tenancy_ready())
		{
			return phoromatic_server_roles::UNAVAILABLE;
		}
		if(!self::begin())
		{
			return 'The database is busy; nothing was changed.';
		}
		$row = self::get($account_id);
		$stop = null;
		if(!$row)
		{
			$stop = 'There is no such account.';
		}
		else if($row['PurgedOn'] !== null)
		{
			$stop = 'This account was already purged.';
		}
		else if($row['State'] !== phoromatic_tenant::DELETED)
		{
			$stop = 'Only a deleted account can be purged. This one is ' . $row['State'] . '; nothing was changed.';
		}
		else if(!is_string($confirm_name) || !is_string($confirm_id) || self::normalize_name($confirm_name) !== self::normalize_name(self::display_name($row)) || $confirm_id !== $row['AccountID'])
		{
			$stop = 'Type the account\'s name and its account ID exactly to purge it; nothing was changed.';
		}
		if($stop !== null)
		{
			@phoromatic_server::$db->exec('ROLLBACK');
			return $stop;
		}
		$aid = $row['AccountID'];
		$removed = array();
		$tables = phoromatic_server::$db->query('SELECT name FROM sqlite_master WHERE type = \'table\' AND name LIKE \'phoromatic\\_%\' ESCAPE \'\\\' ORDER BY name');
		$names = array();
		while($tables && ($t = $tables->fetchArray(SQLITE3_ASSOC)))
		{
			$names[] = $t['name'];
		}
		foreach($names as $table)
		{
			if(in_array($table, self::PURGE_KEEPS, true) || !in_array('AccountID', phoromatic_server::table_columns($table), true))
			{
				continue;
			}
			$stmt = phoromatic_server::$db->prepare('DELETE FROM ' . $table . ' WHERE AccountID = :account_id');
			$stmt->bindValue(':account_id', $aid);
			if(@$stmt->execute() === false)
			{
				return self::fail($table . ': ' . phoromatic_server::$db->lastErrorMsg());
			}
			if(($n = phoromatic_server::$db->changes()) > 0)
			{
				$removed[$table] = $n;
			}
		}
		$now = phoromatic_server::current_time();
		$stmt = phoromatic_server::$db->prepare('UPDATE phoromatic_accounts SET PurgedOn = :now, StateChangedOn = :now, StateChangedBy = :by WHERE AccountID = :account_id AND State = \'deleted\' AND PurgedOn IS NULL');
		$stmt->bindValue(':now', $now);
		$stmt->bindValue(':by', phoromatic_audit_core::clean($who[1]));
		$stmt->bindValue(':account_id', $aid);
		if(@$stmt->execute() === false || phoromatic_server::$db->changes() !== 1 || !phoromatic_server_audit::record($aid, $who[0], $who[1], 'tenant_purged', array('actor_detail' => $who[2],
			'subject_type' => 'tenant', 'subject_id' => $aid, 'subject_label' => self::display_name($row), 'detail' => array('rows_removed' => $removed))))
		{
			return self::fail(phoromatic_server_audit::last_error() ?: phoromatic_server::$db->lastErrorMsg());
		}
		if(($error = self::finish()) !== null)
		{
			return $error;
		}
		// The files, after the commit. A folder that cannot be removed is left where it is, named, and the audit says so.
		$left = self::remove_files($aid);
		if(!empty($left))
		{
			$leftover = implode(', ', $left);
			phoromatic_server_audit::record($aid, $who[0], $who[1], 'tenant_purge_cleanup_failed', array('actor_detail' => $who[2], 'subject_type' => 'tenant', 'subject_id' => $aid,
				'subject_label' => self::display_name($row), 'detail' => array('left' => array_map('basename', $left))));
			phoromatic_server_audit::flush();
		}
		return null;
	}
	// ----- the files of a purged account: everything under the storage folder that is named for its account id

	protected static function storage_root()
	{
		return rtrim((string)phoromatic_server::phoromatic_path(false), '/') . '/';
	}
	protected static function remove_one($path)
	{
		// True when nothing is left of $path. A link is removed, and the folder it points at is removed too (with a link inside that tree removed, never followed)
		// - but only when that folder is not the storage folder, one of its parents, or anything else inside it: a link that points there is left alone and reported.
		if(is_link($path))
		{
			$target = realpath($path);
			if($target !== false)
			{
				$root = realpath(self::storage_root());
				$t = rtrim($target, '/') . '/';
				if($root === false || $t === '/' || strpos(rtrim($root, '/') . '/', $t) === 0 || strpos($t, rtrim($root, '/') . '/') === 0)
				{
					return false;
				}
				phoromatic_private_repo::remove_tree($target);
			}
			@unlink($path);
			return !is_link($path) && ($target === false || !file_exists($target));
		}
		if(!file_exists($path))
		{
			return true;
		}
		phoromatic_private_repo::remove_tree($path);
		return !file_exists($path);
	}
	public static function folders_of($account_id)
	{
		// What exists on disk for the account: its folder, a folder a purge set aside, and its result export
		$base = self::storage_root();
		$found = array();
		foreach(array_merge(array($base . 'accounts/' . $account_id, $base . 'result-export/' . $account_id), (array)glob($base . 'accounts/.purged-' . $account_id . '-*', GLOB_NOSORT)) as $p)
		{
			if(is_link($p) || file_exists($p))
			{
				$found[] = $p;
			}
		}
		return $found;
	}
	public static function remove_files($account_id)
	{
		// Removes every folder folders_of() finds; returns the ones that are still there. The account folder is moved aside first (one step, so
		// nothing reads half of it) when it is a real folder.
		$left = array();
		if(phoromatic_tenant_path::segment($account_id) === false || $account_id[0] === '.')
		{
			return $left;
		}
		$base = self::storage_root();
		$dir = $base . 'accounts/' . $account_id;
		if(is_dir($dir) && !is_link($dir))
		{
			@rename($dir, $base . 'accounts/.purged-' . $account_id . '-' . date('YmdHis'));
		}
		foreach(self::folders_of($account_id) as $path)
		{
			if(!self::remove_one($path))
			{
				$left[] = $path;
			}
		}
		return $left;
	}
	public static function sweep_purged()
	{
		// The event server's hourly pass: the folders of purged accounts that are still on disk (a purge that stopped between its commit and the
		// removal, or one that could not remove a folder) are removed, and each cleanup and each failure is a row in the server audit. A failure
		// that was already recorded for the account in the last day is not recorded again every hour.
		if(!phoromatic_server::tenancy_ready())
		{
			return 0;
		}
		$ids = array();
		$result = phoromatic_server::$db->query('SELECT AccountID FROM phoromatic_accounts WHERE PurgedOn IS NOT NULL');
		while($result && ($row = $result->fetchArray(SQLITE3_ASSOC)))
		{
			$ids[$row['AccountID']] = true;
		}
		foreach((array)glob(self::storage_root() . 'accounts/.purged-*', GLOB_NOSORT) as $p)
		{
			if(preg_match('/^\.purged-([A-Za-z0-9]+)-\d{14}$/', basename($p), $m))
			{
				$ids[$m[1]] = true;
			}
		}
		$done = 0;
		foreach(array_keys($ids) as $aid)
		{
			$aid = (string)$aid;
			if(empty(self::folders_of($aid)))
			{
				continue;
			}
			$left = self::remove_files($aid);
			$done++;
			if(empty($left))
			{
				phoromatic_server_audit::record($aid, 'system', 'event-server', 'tenant_purge_cleaned', array('subject_type' => 'tenant', 'subject_id' => $aid));
			}
			else
			{
				$stmt = phoromatic_server::$db->prepare('SELECT 1 FROM phoromatic_server_audit WHERE Action = \'tenant_purge_cleanup_failed\' AND TenantID = :aid AND EventTime > :since');
				$stmt->bindValue(':aid', $aid);
				$stmt->bindValue(':since', date('Y-m-d H:i:s', time() - 86400));
				$r = $stmt->execute();
				if(!($r && $r->fetchArray()))
				{
					phoromatic_server_audit::record($aid, 'system', 'event-server', 'tenant_purge_cleanup_failed', array('subject_type' => 'tenant', 'subject_id' => $aid, 'detail' => array('left' => array_map('basename', $left))));
				}
			}
			phoromatic_server_audit::flush();
		}
		return $done;
	}
}

?>
