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
// Every event is also mirrored to the pts_logger log so a line exists outside the database.

class phoromatic_private_repo_audit
{
	const ACTIONS = array('upload_staged', 'upload_committed', 'upload_rejected', 'delete', 'rebuild', 'token_created', 'token_revoked', 'cli_auth_failed');
	const COLUMNS = array('AuditID', 'AccountID', 'EventTime', 'ActorType', 'Actor', 'ActorDetail', 'SourceIP', 'Action', 'Kind', 'Name', 'Version', 'Sha256', 'Sha1', 'PrevSha256', 'Detail');

	public static function record($account_id, $actor_type, $actor, $action, $fields = array())
	{
		// $fields may carry: actor_detail, source_ip, kind, name, version, sha256, sha1, prev_sha256, detail (array, stored as JSON).
		// Returns true if the row was written.
		$source_ip = isset($fields['source_ip']) ? $fields['source_ip'] : (isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : null);
		$detail = isset($fields['detail']) ? (is_string($fields['detail']) ? $fields['detail'] : json_encode($fields['detail'])) : null;

		// Mirror first, so the log line exists even when the database write fails. This uses its own non-flushing logger:
		// the shared pts_logger::add_to_log() file (phoromatic.log) is truncated at the start of every request.
		self::mirror_logger()->log('private repo audit: ' . $action . ' by ' . $actor_type . ' ' . $actor . ($source_ip ? ' from ' . $source_ip : '') .
			(isset($fields['kind']) ? ' ' . $fields['kind'] . ' ' . (isset($fields['name']) ? $fields['name'] : '') . (isset($fields['version']) ? '-' . $fields['version'] : '') : '') .
			(isset($fields['sha256']) ? ' sha256=' . $fields['sha256'] : '') . ($detail != null ? ' ' . preg_replace('/[\r\n]+/', ' ', $detail) : ''));

		if(!in_array($action, self::ACTIONS, true) || !self::database_ready())
		{
			return false;
		}

		$stmt = phoromatic_server::$db->prepare('INSERT INTO phoromatic_private_repo_audit (AccountID, EventTime, ActorType, Actor, ActorDetail, SourceIP, Action, Kind, Name, Version, Sha256, Sha1, PrevSha256, Detail) VALUES (:account_id, :event_time, :actor_type, :actor, :actor_detail, :source_ip, :action, :kind, :name, :version, :sha256, :sha1, :prev_sha256, :detail)');
		if($stmt == false)
		{
			return false;
		}
		$stmt->bindValue(':account_id', $account_id);
		$stmt->bindValue(':event_time', phoromatic_server::current_time());
		$stmt->bindValue(':actor_type', $actor_type);
		$stmt->bindValue(':actor', $actor);
		$stmt->bindValue(':actor_detail', isset($fields['actor_detail']) ? $fields['actor_detail'] : null);
		$stmt->bindValue(':source_ip', $source_ip);
		$stmt->bindValue(':action', $action);
		foreach(array('kind', 'name', 'version', 'sha256', 'sha1', 'prev_sha256') as $f)
		{
			$stmt->bindValue(':' . $f, isset($fields[$f]) ? $fields[$f] : null);
		}
		$stmt->bindValue(':detail', $detail);

		return $stmt->execute() !== false;
	}
	protected static function mirror_logger()
	{
		static $logger = null;
		if($logger == null)
		{
			$logger = new pts_logger(null, 'phoromatic-private-repo-audit.log', false);
		}
		return $logger;
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
	public static function export_csv($account_id)
	{
		$fh = fopen('php://temp', 'r+');
		fputcsv($fh, self::COLUMNS);
		foreach(array_reverse(self::recent($account_id, PHP_INT_MAX)) as $row)
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
		rewind($fh);
		$csv = stream_get_contents($fh);
		fclose($fh);
		return $csv;
	}
	public static function recent_failures_for_ip($source_ip, $window_seconds = 300)
	{
		if(!self::database_ready())
		{
			return 0;
		}
		$stmt = phoromatic_server::$db->prepare('SELECT COUNT(*) FROM phoromatic_private_repo_audit WHERE Action = \'cli_auth_failed\' AND SourceIP = :ip AND EventTime >= :since');
		$stmt->bindValue(':ip', $source_ip);
		$stmt->bindValue(':since', date('Y-m-d H:i:s', time() - (int)$window_seconds));
		$result = $stmt->execute();
		$row = $result ? $result->fetchArray(SQLITE3_NUM) : false;
		return $row ? (int)$row[0] : 0;
	}
}

?>
