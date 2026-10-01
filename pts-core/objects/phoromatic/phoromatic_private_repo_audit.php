<?php

/*
	Benchy
	Append-only audit trail for the private test profile / test suite repository.

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

// Rows live in phoromatic.db (table phoromatic_private_repo_audit, created by a prepare_database() migration step).
// Append-only: this class only ever INSERTs and SELECTs, and the table has triggers that refuse UPDATE/DELETE.
// Every event is also mirrored to a dedicated log file next to the pts_logger logs so a line exists outside the database.

class phoromatic_private_repo_audit
{
	// Mutations are recorded in two steps so that an audit failure can stop them: "<x>_attempt" is written (fail closed)
	// inside the repository lock BEFORE anything is changed, then "<x>" (done) or "<x>_failed" follows. Rows are never edited.
	const ACTIONS = array('upload_staged', 'upload_rejected', 'upload_attempt', 'upload_committed', 'upload_failed', 'delete_attempt', 'delete', 'delete_failed', 'rebuild_attempt', 'rebuild', 'rebuild_failed', 'token_created', 'token_revoked', 'cli_auth_failed');
	const COLUMNS = array('AuditID', 'AccountID', 'EventTime', 'ActorType', 'Actor', 'ActorDetail', 'SourceIP', 'Action', 'Kind', 'Name', 'Version', 'Sha256', 'Sha1', 'PrevSha256', 'Detail');
	const MAX_FIELD = 128;
	const MAX_DETAIL_STRING = 1000;

	protected static $last_error = null;

	public static function last_error()
	{
		return self::$last_error;
	}
	protected static function clean($value, $max = self::MAX_FIELD)
	{
		// Printable ASCII only and bounded, for both the stored value and the log line
		if($value === null)
		{
			return null;
		}
		$value = preg_replace('/[^\x20-\x7e]/', '?', is_scalar($value) ? (string)$value : '');
		return strlen($value) > $max ? substr($value, 0, $max) . '...' : $value;
	}
	protected static function clean_detail($value)
	{
		if(is_array($value))
		{
			return array_map(array(__CLASS__, 'clean_detail'), $value);
		}
		return is_string($value) ? self::clean($value, self::MAX_DETAIL_STRING) : $value;
	}
	public static function record($account_id, $actor_type, $actor, $action, $fields = array())
	{
		// $fields may carry: actor_detail, source_ip, kind, name, version, sha256, sha1, prev_sha256, detail (array, stored as JSON).
		// True only if BOTH the database row and the mirror log line were written; last_error() says what failed otherwise.
		// Never emits PHP warnings (callers print JSON).
		self::$last_error = null;
		try
		{
			if(!isset($fields['actor_detail']) && phoromatic_private_repo_auth::current_label() !== null)
			{
				// CLI operations done with a repository token carry the token's label
				$fields['actor_detail'] = phoromatic_private_repo_auth::current_label();
			}
			$f = array();
			foreach(array('actor_detail', 'source_ip', 'kind', 'name', 'version', 'sha256', 'sha1', 'prev_sha256') as $k)
			{
				$f[$k] = isset($fields[$k]) ? self::clean($fields[$k]) : null;
			}
			if($f['source_ip'] === null && isset($_SERVER['REMOTE_ADDR']))
			{
				$f['source_ip'] = self::clean($_SERVER['REMOTE_ADDR']);
			}
			$actor_type = self::clean($actor_type);
			$actor = self::clean($actor);
			$detail = null;
			if(isset($fields['detail']))
			{
				$detail = json_encode(self::clean_detail($fields['detail']), JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
				if($detail === false)
				{
					$detail = '{"error":"detail could not be encoded"}';
				}
			}

			$line = 'private repo audit: ' . $action . (isset($fields['mirror_label']) ? ' (' . self::clean($fields['mirror_label']) . ')' : '') . ' by ' . $actor_type . ' ' . $actor . ($f['source_ip'] ? ' from ' . $f['source_ip'] : '') .
				($f['kind'] !== null ? ' ' . $f['kind'] . ' ' . $f['name'] . ($f['version'] !== null ? '-' . $f['version'] : '') : '') .
				($f['sha256'] !== null ? ' sha256=' . $f['sha256'] : '') . ($detail !== null ? ' ' . $detail : '');
			// Mirror first, so a line exists even when the database write fails
			$mirrored = self::mirror($line);

			$stored = false;
			if(!in_array($action, self::ACTIONS, true))
			{
				self::$last_error = 'unknown audit action';
			}
			else if(!self::database_ready())
			{
				self::$last_error = 'audit database unavailable';
			}
			else if(($stmt = @phoromatic_server::$db->prepare('INSERT INTO phoromatic_private_repo_audit (AccountID, EventTime, ActorType, Actor, ActorDetail, SourceIP, Action, Kind, Name, Version, Sha256, Sha1, PrevSha256, Detail) VALUES (:account_id, :event_time, :actor_type, :actor, :actor_detail, :source_ip, :action, :kind, :name, :version, :sha256, :sha1, :prev_sha256, :detail)')) === false)
			{
				self::$last_error = 'audit table unavailable';
			}
			else
			{
				$stmt->bindValue(':account_id', self::clean($account_id));
				$stmt->bindValue(':event_time', phoromatic_server::current_time());
				$stmt->bindValue(':actor_type', $actor_type);
				$stmt->bindValue(':actor', $actor);
				$stmt->bindValue(':action', $action);
				foreach($f as $k => $v)
				{
					$stmt->bindValue(':' . $k, $v);
				}
				$stmt->bindValue(':detail', $detail);
				$stored = @$stmt->execute() !== false;
				if(!$stored)
				{
					self::$last_error = 'audit row could not be written';
				}
			}
			if(!$mirrored && self::$last_error === null)
			{
				self::$last_error = 'audit log file could not be written';
			}
			return $stored && $mirrored;
		}
		catch(Throwable $e)
		{
			self::$last_error = 'audit error: ' . $e->getMessage();
			return false;
		}
	}
	public static function mirror_path()
	{
		return pts_logger::default_log_file_path() . 'phoromatic-private-repo-audit.log';
	}
	public static function mirror_note($text)
	{
		// A follow-up line in the mirror log (e.g. "committed" after an "(attempt)" line); never throws
		try
		{
			return self::mirror('private repo audit: ' . self::clean($text, 300));
		}
		catch(Throwable $e)
		{
			return false;
		}
	}
	protected static function mirror($line)
	{
		$file = self::mirror_path();
		if(!is_file($file))
		{
			if(@file_put_contents($file, '') === false)
			{
				return false;
			}
			@chmod($file, 0640);
		}
		return @file_put_contents($file, '[' . date('Y-m-d\TH:i:sO') . '] ' . $line . PHP_EOL, FILE_APPEND | LOCK_EX) !== false;
	}
	protected static function database_ready()
	{
		if(phoromatic_server::$db == null)
		{
			phoromatic_server::prepare_database();
		}
		return phoromatic_server::$db != null;
	}
	public static function recent($account_id, $limit = 50, $offset = 0, $action = null)
	{
		// Newest first
		if(!self::database_ready())
		{
			return array();
		}
		$stmt = phoromatic_server::$db->prepare('SELECT * FROM phoromatic_private_repo_audit WHERE AccountID = :account_id' . ($action != null ? ' AND Action = :action' : '') . ' ORDER BY AuditID DESC LIMIT :limit OFFSET :offset');
		$stmt->bindValue(':account_id', $account_id);
		if($action != null)
		{
			$stmt->bindValue(':action', $action);
		}
		$stmt->bindValue(':limit', max(1, (int)$limit), SQLITE3_INTEGER);
		$stmt->bindValue(':offset', max(0, (int)$offset), SQLITE3_INTEGER);
		$result = $stmt->execute();
		$rows = array();
		while($result && $row = $result->fetchArray(SQLITE3_ASSOC))
		{
			$rows[] = $row;
		}
		return $rows;
	}
	public static function export_csv($account_id, $out = null)
	{
		// Oldest first, streamed row by row into $out (a stream; defaults to a returned string)
		$return_string = $out === null;
		$fh = $return_string ? fopen('php://temp', 'r+') : $out;
		fputcsv($fh, self::COLUMNS);
		if(self::database_ready())
		{
			$stmt = phoromatic_server::$db->prepare('SELECT * FROM phoromatic_private_repo_audit WHERE AccountID = :account_id ORDER BY AuditID ASC');
			$stmt->bindValue(':account_id', $account_id);
			$result = $stmt->execute();
			while($result && $row = $result->fetchArray(SQLITE3_ASSOC))
			{
				$line = array();
				foreach(self::COLUMNS as $c)
				{
					$v = (string)$row[$c];
					// Neutralise spreadsheet formula injection
					$line[] = $v !== '' && strpos("=+-@\t\r", $v[0]) !== false ? "'" . $v : $v;
				}
				fputcsv($fh, $line);
			}
		}
		if(!$return_string)
		{
			return true;
		}
		rewind($fh);
		$csv = stream_get_contents($fh);
		fclose($fh);
		return $csv;
	}
	public static function lockout_seconds_remaining($source_ip, $max_failures, $window_seconds, $reason)
	{
		// 0 if fewer than $max_failures failures of that reason in the window; else how long until the oldest of the
		// counted ones leaves the window
		if(!self::database_ready())
		{
			return 0;
		}
		$stmt = phoromatic_server::$db->prepare('SELECT EventTime FROM phoromatic_private_repo_audit WHERE Action = \'cli_auth_failed\' AND SourceIP = :ip AND EventTime >= :since AND Detail LIKE :reason ORDER BY AuditID DESC LIMIT :max');
		$stmt->bindValue(':ip', $source_ip);
		$stmt->bindValue(':since', date('Y-m-d H:i:s', time() - (int)$window_seconds));
		$stmt->bindValue(':reason', '%"reason":"' . preg_replace('/[^a-z_]/', '', $reason) . '"%');
		$stmt->bindValue(':max', (int)$max_failures, SQLITE3_INTEGER);
		$result = $stmt->execute();
		$times = array();
		while($result && $row = $result->fetchArray(SQLITE3_ASSOC))
		{
			$times[] = strtotime($row['EventTime']);
		}
		return count($times) >= $max_failures ? max(1, min($times) + (int)$window_seconds - time()) : 0;
	}
	public static function has_recent_failure($actor, $reason, $window_seconds)
	{
		// Is there already a cli_auth_failed row for this actor and reason inside the window? (used to record "missing" once per hour)
		if(!self::database_ready())
		{
			return false;
		}
		$stmt = phoromatic_server::$db->prepare('SELECT COUNT(*) FROM phoromatic_private_repo_audit WHERE Action = \'cli_auth_failed\' AND Actor = :actor AND EventTime >= :since AND Detail LIKE :reason');
		$stmt->bindValue(':actor', self::clean($actor));
		$stmt->bindValue(':since', date('Y-m-d H:i:s', time() - (int)$window_seconds));
		$stmt->bindValue(':reason', '%"reason":"' . preg_replace('/[^a-z_]/', '', $reason) . '"%');
		$result = $stmt->execute();
		$row = $result ? $result->fetchArray(SQLITE3_NUM) : false;
		return $row && (int)$row[0] > 0;
	}
	public static function recent_failures_for_ip($source_ip, $window_seconds = 300, $reason = null)
	{
		// $reason limits the count to failures recorded with that detail reason (e.g. only wrong tokens, not missing ones)
		if(!self::database_ready())
		{
			return 0;
		}
		$stmt = phoromatic_server::$db->prepare('SELECT COUNT(*) FROM phoromatic_private_repo_audit WHERE Action = \'cli_auth_failed\' AND SourceIP = :ip AND EventTime >= :since' . ($reason !== null ? ' AND Detail LIKE :reason' : ''));
		if($reason !== null)
		{
			$stmt->bindValue(':reason', '%"reason":"' . preg_replace('/[^a-z_]/', '', $reason) . '"%');
		}
		$stmt->bindValue(':ip', $source_ip);
		$stmt->bindValue(':since', date('Y-m-d H:i:s', time() - (int)$window_seconds));
		$result = $stmt->execute();
		$row = $result ? $result->fetchArray(SQLITE3_NUM) : false;
		return $row ? (int)$row[0] : 0;
	}
}

?>
