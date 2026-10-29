<?php

/*
	Benchy
	phoromatic_bench_token.php: bench tokens, the credential for declaring a swap from the bench itself (Benchy 0.9, 2C)

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

// A bench token is "bbt_" + 43 base64url characters (256 bits); only its SHA-256 is stored, the raw value is shown once. It is its own
// type (phoromatic_bench_tokens), unrelated to repository tokens (bpr_): it belongs to one bench and optionally to one member system,
// and acts only on the bench of the machine that presents it (found from the machine id, never from a parameter). Tokens are revoked
// automatically when their bench retires, when their bound system leaves the bench or is deleted; nothing ever revives one. Every
// change writes its phoromatic_admin_audit row first, inside the caller's or its own transaction; no row, no change.

class phoromatic_bench_token
{
	const PREFIX = 'bbt_';
	const MAX_FAILURES = 10;
	const FAILURE_WINDOW = 300;
	const MISSING_AUDIT_WINDOW = 3600;
	const LOCKOUT_REASONS = array('invalid', 'wrong_bench', 'wrong_system', 'repo_token');
	const MSG_REQUIRED = 'A bench token is required to change the bench from the command line. Create one on the bench page (or on the server: benchy bench-token-create BENCHID LABEL), then run: bench-login';
	const MSG_REJECTED = 'The bench token was not accepted (wrong, revoked, or for another bench or system). Run bench-login with a current token.';
	const MSG_REPO = 'That is a repository token. Bench actions need a bench token (bbt_...): see bench-login.';

	public static function valid_token_format($token)
	{
		return is_string($token) && preg_match('/^bbt_[A-Za-z0-9_-]{43}\z/', $token) === 1;
	}
	protected static function one($sql, $params)
	{
		$stmt = phoromatic_server::$db->prepare($sql);
		if($stmt === false)
		{
			return false;
		}
		foreach($params as $k => $v)
		{
			$stmt->bindValue($k, $v);
		}
		$result = $stmt->execute();
		return $result ? $result->fetchArray(SQLITE3_ASSOC) : false;
	}
	public static function tokens($account_id, $bench_id = null)
	{
		$stmt = phoromatic_server::$db->prepare('SELECT t.*, b.Title AS BenchTitle FROM phoromatic_bench_tokens t LEFT JOIN phoromatic_benches b ON b.AccountID = t.AccountID AND b.BenchID = t.BenchID WHERE t.AccountID = :a' . ($bench_id !== null ? ' AND t.BenchID = :b' : '') . ' ORDER BY t.RevokedOn IS NOT NULL, t.TokenID ASC');
		$stmt->bindValue(':a', $account_id);
		if($bench_id !== null)
		{
			$stmt->bindValue(':b', (int)$bench_id);
		}
		$result = $stmt->execute();
		$rows = array();
		while($result && $row = $result->fetchArray(SQLITE3_ASSOC))
		{
			$rows[] = $row;
		}
		return $rows;
	}
	protected static function audit($account_id, $actor_type, $actor, $actor_detail, $action, $bench, $detail)
	{
		return phoromatic_admin_audit::record($account_id, $actor_type, $actor, $action, array('actor_detail' => $actor_detail, 'subject_type' => 'bench',
			'subject_id' => $bench['BenchID'], 'subject_label' => $bench['Title'], 'detail' => $detail));
	}
	public static function create($account_id, $bench_id, $label, $system_id, $actor_type, $actor, $actor_detail = null)
	{
		// array('token' => raw, 'id' => TokenID, 'label' => ..., 'system' => ...) or array('error' => text)
		$label = phoromatic_bench::clean_text($label, 64);
		if($label === null)
		{
			return array('error' => 'A label of 1 to 64 printable characters is required (e.g. "rig-02 linux shell").');
		}
		$system_id = $system_id === null || $system_id === '' ? null : (string)$system_id;
		if($system_id !== null && !phoromatic_system_admin::valid_system_id($system_id))
		{
			return array('error' => 'System not found.');
		}
		$db = phoromatic_server::$db;
		if(@$db->exec('BEGIN IMMEDIATE') === false)
		{
			return array('error' => 'The database is busy; no token was created.');
		}
		$bench = phoromatic_bench::bench($account_id, $bench_id);
		$fail = function($message) use ($db) {
			@$db->exec('ROLLBACK');
			return array('error' => $message);
		};
		if(!$bench || $bench['RetiredOn'] !== null)
		{
			return $fail('Bench not found or retired.');
		}
		if($system_id !== null && !self::one('SELECT SystemID FROM phoromatic_systems WHERE AccountID = :a AND SystemID = :y AND BenchID = :b AND DeletedOn IS NULL', array(':a' => $account_id, ':y' => $system_id, ':b' => (int)$bench['BenchID'])))
		{
			return $fail('That system is not a member of this bench.');
		}
		$token = self::PREFIX . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
		$seq = (int)$db->querySingle('SELECT seq FROM sqlite_sequence WHERE name = \'phoromatic_bench_tokens\'');
		$id = max($seq, (int)$db->querySingle('SELECT MAX(TokenID) FROM phoromatic_bench_tokens')) + 1;
		if(!self::audit($account_id, $actor_type, $actor, $actor_detail, 'bench_token_create', $bench, array('token' => 'BT' . $id, 'label' => $label, 'system' => $system_id, 'binding' => $system_id === null ? 'any member' : 'system ' . $system_id)))
		{
			return $fail('No token was created: the audit record could not be written (' . phoromatic_admin_audit::last_error() . ').');
		}
		$stmt = $db->prepare('INSERT INTO phoromatic_bench_tokens (TokenID, AccountID, BenchID, SystemID, Label, TokenHash, CreatedOn, CreatedBy) VALUES (:id, :a, :b, :y, :l, :h, :now, :by)');
		foreach(array(':id' => $id, ':a' => $account_id, ':b' => (int)$bench['BenchID'], ':y' => $system_id, ':l' => $label, ':h' => hash('sha256', $token), ':now' => phoromatic_server::current_time(), ':by' => (string)$actor) as $k => $v)
		{
			$stmt->bindValue($k, $v);
		}
		if(@$stmt->execute() === false || @$db->exec('COMMIT') === false)
		{
			return $fail('No token was created because of a database error.');
		}
		return array('token' => $token, 'id' => $id, 'label' => $label, 'system' => $system_id, 'bench' => $bench);
	}
	public static function revoke($account_id, $token_id, $actor_type, $actor, $actor_detail = null, $bench_id = null)
	{
		// A manual revoke (web: $bench_id is the page's bench). array('revoked' => TokenID) or array('error' => text)
		$db = phoromatic_server::$db;
		if(@$db->exec('BEGIN IMMEDIATE') === false)
		{
			return array('error' => 'The database is busy; nothing was changed.');
		}
		$t = self::one('SELECT * FROM phoromatic_bench_tokens WHERE AccountID = :a AND TokenID = :t AND RevokedOn IS NULL' . ($bench_id !== null ? ' AND BenchID = :b' : ''), array_merge(array(':a' => $account_id, ':t' => (int)$token_id), $bench_id !== null ? array(':b' => (int)$bench_id) : array()));
		if(!$t)
		{
			@$db->exec('ROLLBACK');
			return array('error' => 'No active bench token with that ID.');
		}
		if(!self::revoke_rows_in_tx($account_id, array($t), 'manual', $actor_type, $actor, $actor_detail) || @$db->exec('COMMIT') === false)
		{
			@$db->exec('ROLLBACK');
			return array('error' => 'Nothing was changed: the audit record could not be written (' . phoromatic_admin_audit::last_error() . ').');
		}
		return array('revoked' => (int)$t['TokenID']);
	}
	public static function revoke_for_in_tx($account_id, $bench_id, $system_id, $reason, $actor_type, $actor, $actor_detail = null)
	{
		// Automatic revocation inside the caller's transaction: every live token of the bench ($system_id null), or the ones bound to
		// that system. false when an audit row or the update failed (the caller rolls back).
		$params = array(':a' => $account_id, ':b' => (int)$bench_id);
		if($system_id !== null)
		{
			$params[':y'] = (string)$system_id;
		}
		$stmt = phoromatic_server::$db->prepare('SELECT * FROM phoromatic_bench_tokens WHERE AccountID = :a AND BenchID = :b AND RevokedOn IS NULL' . ($system_id !== null ? ' AND SystemID = :y' : ''));
		if($stmt === false)
		{
			return true; // the table does not exist (a database before 0.9): nothing to revoke
		}
		foreach($params as $k => $v)
		{
			$stmt->bindValue($k, $v);
		}
		$result = $stmt->execute();
		$rows = array();
		while($result && $row = $result->fetchArray(SQLITE3_ASSOC))
		{
			$rows[] = $row;
		}
		return self::revoke_rows_in_tx($account_id, $rows, $reason, $actor_type, $actor, $actor_detail);
	}
	protected static function revoke_rows_in_tx($account_id, $rows, $reason, $actor_type, $actor, $actor_detail)
	{
		foreach($rows as $t)
		{
			$bench = phoromatic_bench::bench($account_id, $t['BenchID']);
			$bench = $bench ? $bench : array('BenchID' => $t['BenchID'], 'Title' => null);
			if(!self::audit($account_id, $actor_type, $actor, $actor_detail, 'bench_token_revoke', $bench, array('token' => 'BT' . $t['TokenID'], 'label' => $t['Label'], 'system' => $t['SystemID'], 'reason' => $reason)))
			{
				return false;
			}
			$stmt = phoromatic_server::$db->prepare('UPDATE phoromatic_bench_tokens SET RevokedOn = :now, RevokedBy = :by, RevokeReason = :reason WHERE AccountID = :a AND TokenID = :t AND RevokedOn IS NULL');
			foreach(array(':now' => phoromatic_server::current_time(), ':by' => (string)$actor, ':reason' => $reason, ':a' => $account_id, ':t' => (int)$t['TokenID']) as $k => $v)
			{
				$stmt->bindValue($k, $v);
			}
			if(@$stmt->execute() === false)
			{
				return false;
			}
		}
		return true;
	}
	protected static function auth_failed($reason, $bench_id)
	{
		// bench_cli_auth_failed, machine actor; "missing" at most once per machine and hour
		if($reason == 'missing')
		{
			$row = self::one('SELECT COUNT(*) AS n FROM phoromatic_admin_audit WHERE Action = \'bench_cli_auth_failed\' AND Actor = :y AND EventTime >= :since AND Detail LIKE \'%"reason":"missing"%\'', array(':y' => SYSTEM_ID, ':since' => date('Y-m-d H:i:s', time() - self::MISSING_AUDIT_WINDOW)));
			if($row && (int)$row['n'] > 0)
			{
				return;
			}
		}
		phoromatic_admin_audit::record(ACCOUNT_ID, 'machine', SYSTEM_ID, 'bench_cli_auth_failed', array('subject_type' => 'bench', 'subject_id' => $bench_id, 'detail' => array('reason' => $reason, 'system' => SYSTEM_ID)));
	}
	public static function authorize_bench_request($bench_id)
	{
		// For bench_action (after phoromatic.php found the machine approved, and the resource found its bench): the POST field bt must
		// be a live token of this bench, and of this system when it is bound to one. The token row, or array('error' => text).
		$ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
		$token = isset($_POST['bt']) && is_string($_POST['bt']) ? trim($_POST['bt']) : '';
		if($token === '')
		{
			self::auth_failed('missing', $bench_id);
			return array('error' => self::MSG_REQUIRED);
		}
		if(($wait = phoromatic_auth_lockout::seconds_remaining('phoromatic_admin_audit', 'bench_cli_auth_failed', $ip, self::LOCKOUT_REASONS, self::MAX_FAILURES, self::FAILURE_WINDOW)) > 0)
		{
			return array('error' => 'Too many failed bench token attempts from this address; try again in about ' . ceil($wait / 60) . ' minute(s).');
		}
		if(phoromatic_private_repo_auth::valid_token_format($token))
		{
			self::auth_failed('repo_token', $bench_id);
			return array('error' => self::MSG_REPO);
		}
		$t = self::valid_token_format($token) ? self::one('SELECT * FROM phoromatic_bench_tokens WHERE AccountID = :a AND TokenHash = :h AND RevokedOn IS NULL', array(':a' => ACCOUNT_ID, ':h' => hash('sha256', $token))) : false;
		if(!$t)
		{
			self::auth_failed('invalid', $bench_id);
			return array('error' => self::MSG_REJECTED);
		}
		if((int)$t['BenchID'] !== (int)$bench_id)
		{
			self::auth_failed('wrong_bench', $bench_id);
			return array('error' => self::MSG_REJECTED);
		}
		if($t['SystemID'] !== null && $t['SystemID'] !== SYSTEM_ID)
		{
			self::auth_failed('wrong_system', $bench_id);
			return array('error' => self::MSG_REJECTED);
		}
		$stmt = phoromatic_server::$db->prepare('UPDATE phoromatic_bench_tokens SET LastUsedOn = :now, LastUsedIP = :ip, LastUsedSystem = :y WHERE TokenID = :t');
		foreach(array(':now' => phoromatic_server::current_time(), ':ip' => $ip, ':y' => SYSTEM_ID, ':t' => (int)$t['TokenID']) as $k => $v)
		{
			$stmt->bindValue($k, $v);
		}
		@$stmt->execute();
		return $t;
	}
}

?>
