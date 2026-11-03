<?php

/*
	Benchy
	Append-only audit trail for administrative events on systems (and later benches).

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

// Rows live in phoromatic.db (table phoromatic_admin_audit, created by a prepare_database() migration step), next to the data they
// describe, so a system delete can write its audit row in the SAME transaction as the change. Append-only: this class only INSERTs and
// SELECTs and the table has triggers that refuse UPDATE/DELETE. Every event is also mirrored to a log file next to the pts_logger logs.
// Modelled on phoromatic_private_repo_audit (whose action whitelist is deliberately not widened).

class phoromatic_admin_audit
{
	const ACTIONS = array('system_delete', 'system_delete_cleanup_failed', 'system_restore', 'system_adopt', 'system_register_new', 'system_adopt_refused', 'client_phone_home_set', 'https_cert_upload', 'https_mode_change',
		'bench_create', 'bench_retire', 'bench_demote', 'bench_member_add', 'bench_token_create', 'bench_token_revoke', 'bench_cli_auth_failed', 'result_reassign', 'ticket_rerun_request', 'component_create', 'component_edit', 'component_retire', 'session_start', 'session_end',
		'bench_drift_ack', 'bench_drift_detected', 'bench_drift_cleared', 'bench_constant_changed', 'bench_constant_restored', 'bench_part_double_booked',
		'agent_code_create', 'agent_code_revoke', 'agent_enrolled', 'agent_enrol_failed', 'agent_auth_failed', 'agent_failures_capped', 'agent_token_revoke', 'agent_machine_mismatch', 'v2_allow_http_set', 'agent_policy_set', 'v2_mirrors_set', 'native_test_add', 'native_test_remove');
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
		// $fields may carry: actor_detail, source_ip, subject_type, subject_id, subject_label, detail (array, stored as JSON).
		// True only if BOTH the database row and the mirror log line were written; last_error() says what failed otherwise.
		// Safe to call inside an open transaction (it only INSERTs); the caller rolls back when this returns false.
		self::$last_error = null;
		try
		{
			$f = array();
			foreach(array('actor_detail', 'source_ip', 'subject_type', 'subject_id', 'subject_label') as $k)
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

			$line = 'admin audit: ' . $action . ' by ' . $actor_type . ' ' . $actor . ($f['source_ip'] ? ' from ' . $f['source_ip'] : '') .
				($f['subject_type'] !== null ? ' ' . $f['subject_type'] . ' ' . $f['subject_id'] . ' "' . $f['subject_label'] . '"' : '') . ($detail !== null ? ' ' . $detail : '');
			// Mirror first, so a line exists even when the database write (or the surrounding transaction) fails
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
			else if(($stmt = @phoromatic_server::$db->prepare('INSERT INTO phoromatic_admin_audit (AccountID, EventTime, ActorType, Actor, ActorDetail, SourceIP, Action, SubjectType, SubjectID, SubjectLabel, Detail) VALUES (:account_id, :event_time, :actor_type, :actor, :actor_detail, :source_ip, :action, :subject_type, :subject_id, :subject_label, :detail)')) === false)
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
		return pts_logger::default_log_file_path() . 'phoromatic-admin-audit.log';
	}
	public static function mirror_note($text)
	{
		// A follow-up line in the mirror log (e.g. "rolled back"); never throws
		try
		{
			return self::mirror('admin audit: ' . self::clean($text, 300));
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
	public static function recent($account_id, $limit = 50)
	{
		// Newest first
		if(!self::database_ready())
		{
			return array();
		}
		$stmt = phoromatic_server::$db->prepare('SELECT * FROM phoromatic_admin_audit WHERE AccountID = :account_id ORDER BY AuditID DESC LIMIT :limit');
		$stmt->bindValue(':account_id', $account_id);
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
