<?php

/*
	Benchy
	phoromatic_agent_auth.php: enrollment codes (ben_), system agent tokens (bsa_) and the checks behind /api/v2 (protocol v2, Benchy 0.9.4)

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

// Both kinds are "ben_"/"bsa_" + 43 base64url characters (256 random bits). Only the SHA-256 is stored; the raw value is shown once.
// A code belongs to an account, optionally to one existing system, is single use and good for 24 hours; a code an administrator makes
// is the approval, so an enrolled system is approved. A system has at most one live token: enrolling revokes the previous one. Every
// change writes its phoromatic_admin_audit row first, inside the same transaction; no row, no change. Failed enrollments and failed
// token checks are counted per source address (phoromatic_v2_failures); only the first of a window and the one that locks the address out are audited.

class phoromatic_agent_auth
{
	const CODE_PREFIX = 'ben_';
	const TOKEN_PREFIX = 'bsa_';
	const CODE_TTL = 86400;
	const ENROLL_MAX_FAILURES = 10;
	const ENROLL_WINDOW = 900;
	const AUTH_MAX_FAILURES = 20;
	const AUTH_WINDOW = 900;
	const TOUCH_INTERVAL = 60;
	const AUDIT_CAP = 30;
	const AUDIT_CAP_WINDOW = 60;

	protected static $account_has_state = null;

	public static function valid_code_format($code)
	{
		return is_string($code) && preg_match('/^ben_[A-Za-z0-9_-]{43}\z/', $code) === 1;
	}
	public static function valid_token_format($token)
	{
		return is_string($token) && preg_match('/^bsa_[A-Za-z0-9_-]{43}\z/', $token) === 1;
	}
	protected static function random_secret($prefix)
	{
		return $prefix . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
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
	protected static function ip()
	{
		return isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
	}
	protected static function text($value, $max)
	{
		// Printable text only and bounded: it ends up on pages and in log lines
		$value = is_scalar($value) ? preg_replace('/[^\x20-\x7e]/u', '', (string)$value) : '';
		return trim(substr($value, 0, $max));
	}

	// ----- codes

	public static function codes($account_id)
	{
		$stmt = phoromatic_server::$db->prepare('SELECT * FROM phoromatic_enrol_codes WHERE AccountID = :a ORDER BY CodeID DESC LIMIT 100');
		$stmt->bindValue(':a', $account_id);
		$result = $stmt->execute();
		$rows = array();
		while($result && $row = $result->fetchArray(SQLITE3_ASSOC))
		{
			$rows[] = $row;
		}
		return $rows;
	}
	public static function create_code($account_id, $system_id, $set_policy, $label, $actor_type, $actor, $actor_detail = null)
	{
		// array('code' => raw, 'id' => CodeID, 'expires' => text) or array('error' => text)
		$label = phoromatic_bench::clean_label($label);
		if($label === false)
		{
			return array('error' => 'A label of 1 to 64 printable characters without markup characters is required (e.g. "rig-02").');
		}
		$system_id = $system_id === null || $system_id === '' ? null : (string)$system_id;
		if($set_policy !== null && $set_policy !== 'native')
		{
			return array('error' => 'The only policy a code can set is native.');
		}
		$db = phoromatic_server::$db;
		if(@$db->exec('BEGIN IMMEDIATE') === false)
		{
			return array('error' => 'The database is busy; no code was created.');
		}
		$fail = function($message) use ($db) {
			@$db->exec('ROLLBACK');
			return array('error' => $message);
		};
		if($system_id !== null && (!phoromatic_system_admin::valid_system_id($system_id) || !self::one('SELECT SystemID FROM phoromatic_systems WHERE AccountID = :a AND SystemID = :y AND DeletedOn IS NULL', array(':a' => $account_id, ':y' => $system_id))))
		{
			return $fail('System not found.');
		}
		$code = self::random_secret(self::CODE_PREFIX);
		$seq = (int)$db->querySingle('SELECT seq FROM sqlite_sequence WHERE name = \'phoromatic_enrol_codes\'');
		$id = max($seq, (int)$db->querySingle('SELECT MAX(CodeID) FROM phoromatic_enrol_codes')) + 1;
		$expires = date('Y-m-d H:i:s', time() + self::CODE_TTL);
		if(!phoromatic_admin_audit::record($account_id, $actor_type, $actor, 'agent_code_create', array('actor_detail' => $actor_detail, 'subject_type' => 'enroll_code', 'subject_id' => 'EC' . $id,
			'subject_label' => $label, 'detail' => array('system' => $system_id, 'set_policy' => $set_policy, 'expires' => $expires))))
		{
			return $fail('No code was created: the audit record could not be written (' . phoromatic_admin_audit::last_error() . ').');
		}
		$stmt = $db->prepare('INSERT INTO phoromatic_enrol_codes (CodeID, AccountID, CodeHash, SystemID, SetPolicy, Label, MaxUses, Uses, ExpiresOn, CreatedOn, CreatedBy) VALUES (:id, :a, :h, :y, :p, :l, 1, 0, :exp, :now, :by)');
		foreach(array(':id' => $id, ':a' => $account_id, ':h' => hash('sha256', $code), ':y' => $system_id, ':p' => $set_policy, ':l' => $label, ':exp' => $expires, ':now' => phoromatic_server::current_time(), ':by' => (string)$actor) as $k => $v)
		{
			$stmt->bindValue($k, $v);
		}
		if(@$stmt->execute() === false || @$db->exec('COMMIT') === false)
		{
			return $fail('No code was created because of a database error.');
		}
		return array('code' => $code, 'id' => $id, 'expires' => $expires, 'label' => $label);
	}
	public static function revoke_code($account_id, $code_id, $actor_type, $actor, $actor_detail = null)
	{
		$db = phoromatic_server::$db;
		if(@$db->exec('BEGIN IMMEDIATE') === false)
		{
			return array('error' => 'The database is busy; nothing was changed.');
		}
		$row = self::one('SELECT * FROM phoromatic_enrol_codes WHERE AccountID = :a AND CodeID = :c AND RevokedOn IS NULL', array(':a' => $account_id, ':c' => (int)$code_id));
		if(!$row)
		{
			@$db->exec('ROLLBACK');
			return array('error' => 'No unrevoked code with that ID.');
		}
		$stmt = $db->prepare('UPDATE phoromatic_enrol_codes SET RevokedOn = :now, RevokedBy = :by WHERE AccountID = :a AND CodeID = :c AND RevokedOn IS NULL');
		foreach(array(':now' => phoromatic_server::current_time(), ':by' => (string)$actor, ':a' => $account_id, ':c' => (int)$code_id) as $k => $v)
		{
			$stmt->bindValue($k, $v);
		}
		if(!phoromatic_admin_audit::record($account_id, $actor_type, $actor, 'agent_code_revoke', array('actor_detail' => $actor_detail, 'subject_type' => 'enroll_code', 'subject_id' => 'EC' . $row['CodeID'], 'subject_label' => $row['Label']))
			|| @$stmt->execute() === false || @$db->exec('COMMIT') === false)
		{
			@$db->exec('ROLLBACK');
			return array('error' => 'Nothing was changed: the audit record or the update could not be written.');
		}
		return array('revoked' => (int)$row['CodeID']);
	}

	// ----- tokens

	public static function live_token($account_id, $system_id)
	{
		return self::one('SELECT * FROM phoromatic_system_tokens WHERE AccountID = :a AND SystemID = :y AND RevokedOn IS NULL ORDER BY TokenID DESC LIMIT 1', array(':a' => $account_id, ':y' => $system_id));
	}
	public static function revoke_system_token($account_id, $system_id, $reason, $actor_type, $actor, $actor_detail = null)
	{
		// A manual revoke (the Systems page). array('revoked' => n) or array('error' => text)
		$db = phoromatic_server::$db;
		if(@$db->exec('BEGIN IMMEDIATE') === false)
		{
			return array('error' => 'The database is busy; nothing was changed.');
		}
		$n = self::revoke_in_tx($account_id, $system_id, $reason, $actor_type, $actor, $actor_detail);
		if($n === false || @$db->exec('COMMIT') === false)
		{
			@$db->exec('ROLLBACK');
			return array('error' => 'Nothing was changed: the audit record could not be written.');
		}
		return $n > 0 ? array('revoked' => $n) : array('error' => 'That system has no live agent token.');
	}
	public static function revoke_in_tx($account_id, $system_id, $reason, $actor_type, $actor, $actor_detail = null)
	{
		// Inside the caller's transaction: revokes the live token(s) of the system, one audit row each. The number revoked, or false when an
		// audit row or the update failed (the caller rolls back). A database from before 0.9.4 has no table: nothing to revoke.
		$stmt = phoromatic_server::$db->prepare('SELECT TokenID FROM phoromatic_system_tokens WHERE AccountID = :a AND SystemID = :y AND RevokedOn IS NULL');
		if($stmt === false)
		{
			return 0;
		}
		$stmt->bindValue(':a', $account_id);
		$stmt->bindValue(':y', $system_id);
		$result = $stmt->execute();
		$ids = array();
		while($result && $row = $result->fetchArray(SQLITE3_ASSOC))
		{
			$ids[] = (int)$row['TokenID'];
		}
		foreach($ids as $id)
		{
			if(!phoromatic_admin_audit::record($account_id, $actor_type, $actor, 'agent_token_revoke', array('actor_detail' => $actor_detail, 'subject_type' => 'system', 'subject_id' => $system_id,
				'detail' => array('token' => 'AT' . $id, 'reason' => $reason))))
			{
				return false;
			}
			$u = phoromatic_server::$db->prepare('UPDATE phoromatic_system_tokens SET RevokedOn = :now, RevokedBy = :by, RevokeReason = :reason WHERE TokenID = :t AND RevokedOn IS NULL');
			foreach(array(':now' => phoromatic_server::current_time(), ':by' => (string)$actor, ':reason' => $reason, ':t' => $id) as $k => $v)
			{
				$u->bindValue($k, $v);
			}
			if(@$u->execute() === false)
			{
				return false;
			}
		}
		return count($ids);
	}

	// ----- failures and the lockout

	// Failed enrollments and failed token checks are counted per source address in their own small table (phoromatic_v2_failures, rows
	// older than an hour are purged as new ones come in), so the check costs an index lookup however much has been tried, and an attacker
	// cannot grow the audit table: only the first failure of a window and the one that trips the lockout are audited (and mirrored).
	public static function failure($kind, $account_id, $reason, $max, $window)
	{
		$db = phoromatic_server::$db;
		$ip = self::ip();
		$now = time();
		@$db->exec('DELETE FROM phoromatic_v2_failures WHERE At < ' . ($now - 3600));
		if(!self::note_failure($kind, $ip, $now))
		{
			// Nothing could be counted (no table, a full disk). locked_out() refuses while it cannot read the counter, so the address is
			// held back anyway; there is nothing more to do here than not crash on the way to the 401.
			return;
		}
		$n = self::one('SELECT COUNT(*) AS n FROM phoromatic_v2_failures WHERE Kind = :k AND SourceIP = :ip AND At >= :since', array(':k' => $kind, ':ip' => $ip, ':since' => $now - (int)$window));
		$n = $n ? (int)$n['n'] : 0;
		if(($n == 1 || $n == $max) && self::audit_allowed($now))
		{
			phoromatic_admin_audit::record($account_id === null ? '' : $account_id, 'agent', 'unauthenticated', $kind === 'enroll' ? 'agent_enroll_failed' : 'agent_auth_failed',
				array('detail' => array('reason' => $reason, 'failures_in_window' => $n, 'locked_out' => $n >= $max)));
		}
	}
	protected static function note_failure($kind, $ip, $at)
	{
		$stmt = @phoromatic_server::$db->prepare('INSERT INTO phoromatic_v2_failures (Kind, SourceIP, At) VALUES (:k, :ip, :at)');
		if($stmt === false)
		{
			return false;
		}
		$stmt->bindValue(':k', $kind);
		$stmt->bindValue(':ip', $ip);
		$stmt->bindValue(':at', $at);
		return @$stmt->execute() !== false;
	}
	// The per-address rule above keeps one address from filling the audit table; addresses are free to anyone with a few thousand of
	// them, so the audited failures are also capped across all addresses, per minute. Each audited failure leaves an 'audited' marker in
	// the counter table (source '*', which no real address is), each one over the cap a 'capped' marker, and the first over the cap in a
	// minute gets one summary row in the audit trail. The failures themselves are counted either way.
	public static function audit_allowed($now)
	{
		$row = self::one('SELECT COUNT(*) AS n FROM phoromatic_v2_failures WHERE Kind = \'audited\' AND SourceIP = \'*\' AND At >= :since', array(':since' => $now - self::AUDIT_CAP_WINDOW));
		if($row && (int)$row['n'] < self::AUDIT_CAP)
		{
			self::note_failure('audited', '*', $now);
			return true;
		}
		$capped = self::one('SELECT COUNT(*) AS n FROM phoromatic_v2_failures WHERE Kind = \'capped\' AND SourceIP = \'*\' AND At >= :since', array(':since' => $now - self::AUDIT_CAP_WINDOW));
		self::note_failure('capped', '*', $now);
		if($capped && (int)$capped['n'] == 0)
		{
			phoromatic_admin_audit::record('', 'agent', 'unauthenticated', 'agent_failures_capped', array('detail' => array('cap' => self::AUDIT_CAP, 'window_s' => self::AUDIT_CAP_WINDOW,
				'note' => 'Failed enrollments and token checks above the cap are counted but not audited one by one until the window passes.')));
		}
		return false;
	}
	// Set by locked_out() when it refused only because the failure counter could not be read: the address did nothing wrong, the server did
	protected static $counter_broken = false;
	// The 429 message for a wait locked_out() returned
	public static function lockout_message($what, $wait)
	{
		if(self::$counter_broken)
		{
			return 'The server cannot check its record of failed ' . $what . ' just now (a server problem, not anything this address did), so requests are held back for a minute; try again in about ' . ceil($wait / 60) . ' minute(s).';
		}
		return 'Too many failed ' . $what . ' from this address; try again in about ' . ceil($wait / 60) . ' minute(s).';
	}
	public static function locked_out($kind, $max, $window)
	{
		// Seconds until this address may try again, 0 when it may
		self::$counter_broken = false;
		$stmt = @phoromatic_server::$db->prepare('SELECT At FROM phoromatic_v2_failures WHERE Kind = :k AND SourceIP = :ip AND At >= :since ORDER BY At DESC LIMIT ' . (int)$max);
		if($stmt === false)
		{
			// Fails closed: with no counter to read, a minute's wait is better than no lockout at all
			self::$counter_broken = true;
			return 60;
		}
		$stmt->bindValue(':k', $kind);
		$stmt->bindValue(':ip', self::ip());
		$stmt->bindValue(':since', time() - (int)$window);
		$result = $stmt->execute();
		if($result === false)
		{
			self::$counter_broken = true;
			return 60;
		}
		$times = array();
		while($result && $row = $result->fetchArray(SQLITE3_ASSOC))
		{
			$times[] = (int)$row['At'];
		}
		return count($times) >= $max ? max(1, min($times) + (int)$window - time()) : 0;
	}

	// ----- enrollment

	// $body is an enroll_request that has already passed its schema. array('status' => 201, 'body' => ...) or array('error' => array(status, code, message)).
	public static function enroll($body)
	{
		if(($wait = self::locked_out('enroll', self::ENROLL_MAX_FAILURES, self::ENROLL_WINDOW)) > 0)
		{
			return array('error' => array(429, 'rate_limited', self::lockout_message('enrollments', $wait), array('Retry-After' => $wait)));
		}
		$code = $body['code'];
		$db = phoromatic_server::$db;
		if(@$db->exec('BEGIN IMMEDIATE') === false)
		{
			return array('error' => array(503, 'server_error', 'The server is busy; try again.'));
		}
		$refuse = function($status, $code_name, $message, $account_id = null) use ($db) {
			@$db->exec('ROLLBACK');
			self::failure('enroll', $account_id, $code_name, self::ENROLL_MAX_FAILURES, self::ENROLL_WINDOW);
			return array('error' => array($status, $code_name, $message));
		};
		$row = self::one('SELECT * FROM phoromatic_enrol_codes WHERE CodeHash = :h', array(':h' => hash('sha256', $code)));
		if(!$row || $row['RevokedOn'] !== null)
		{
			return $refuse(401, 'code_invalid', 'That enrollment code is not valid.', $row ? $row['AccountID'] : null);
		}
		if($row['ExpiresOn'] < phoromatic_server::current_time())
		{
			return $refuse(401, 'code_expired', 'That enrollment code has expired. Make a new one on the Systems page.', $row['AccountID']);
		}
		if((int)$row['Uses'] >= (int)$row['MaxUses'])
		{
			return $refuse(401, 'code_used', 'That enrollment code has already been used.', $row['AccountID']);
		}
		$account_id = $row['AccountID'];
		if(self::account_state($account_id) === 'suspended')
		{
			@$db->exec('ROLLBACK');
			return array('error' => array(403, 'tenant_suspended', 'This account is suspended.'));
		}
		if(self::account_state($account_id) === 'deleted')
		{
			return $refuse(401, 'code_invalid', 'That enrollment code is not valid.', $account_id);
		}
		$use = $db->prepare('UPDATE phoromatic_enrol_codes SET Uses = Uses + 1 WHERE CodeID = :c AND Uses < MaxUses AND RevokedOn IS NULL');
		$use->bindValue(':c', (int)$row['CodeID']);
		if(@$use->execute() === false || $db->changes() != 1)
		{
			return $refuse(401, 'code_used', 'That enrollment code has already been used.', $account_id);
		}

		$machine = $body['machine'];
		$info = $body['system_info'];
		$hardware = self::text($info['summary']['hardware'], 2000);
		$software = self::text($info['summary']['software'], 2000);
		$properties = json_encode(array('benchy_system_info' => $info));
		$version = 'benchy-native ' . self::text($body['engine']['version'], 40);
		$now = phoromatic_server::current_time();
		$scheme = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';
		$system_id = $row['SystemID'];
		$created = false;
		if($system_id !== null)
		{
			$system = self::one('SELECT * FROM phoromatic_systems WHERE AccountID = :a AND SystemID = :y AND DeletedOn IS NULL', array(':a' => $account_id, ':y' => $system_id));
			if(!$system)
			{
				return $refuse(401, 'code_invalid', 'The system this code was made for no longer exists.', $account_id);
			}
			$u = $db->prepare('UPDATE phoromatic_systems SET Hardware = :hw, Software = :sw, SystemProperties = :pp, ClientVersion = :cv, LastCommunication = :now, LastIP = :ip, LastScheme = :scheme' .
				($row['SetPolicy'] === 'native' ? ", EnginePolicy = 'native'" : '') . ((int)$system['State'] == 0 ? ', State = 1' : '') . ' WHERE AccountID = :a AND SystemID = :y');
		}
		else
		{
			do
			{
				$system_id = pts_strings::random_characters(5, true);
				$taken = $db->querySingle('SELECT AccountID FROM phoromatic_systems WHERE SystemID = \'' . $system_id . '\'');
			}
			while(!empty($taken));
			$created = true;
			$title = pts_strings::simple(self::text($machine['hostname'], 64));
			$u = $db->prepare('INSERT INTO phoromatic_systems (AccountID, SystemID, Hardware, Software, SystemProperties, ClientVersion, CurrentTask, CreatedOn, LastCommunication, LastIP, LastScheme, Title, State, EnginePolicy) VALUES (:a, :y, :hw, :sw, :pp, :cv, \'Enrolled, waiting for the agent\', :now, :now, :ip, :scheme, :title, 1, \'native\')');
			$u->bindValue(':title', $title === '' || $title === null ? 'native agent' : $title);
		}
		foreach(array(':a' => $account_id, ':y' => $system_id, ':hw' => $hardware, ':sw' => $software, ':pp' => $properties, ':cv' => $version, ':now' => $now, ':ip' => self::ip(), ':scheme' => $scheme) as $k => $v)
		{
			$u->bindValue($k, $v);
		}
		if(@$u->execute() === false)
		{
			@$db->exec('ROLLBACK');
			return array('error' => array(500, 'server_error', 'The system could not be saved.'));
		}
		// Every live token of the system goes (there should be one; if a bug ever left more, none survives), one audit row each
		$revoked = self::revoke_in_tx($account_id, $system_id, 're-enrolled', 'agent', 'enroll');
		if($revoked === false)
		{
			@$db->exec('ROLLBACK');
			return array('error' => array(500, 'server_error', 'The old token could not be revoked.'));
		}
		$token = self::random_secret(self::TOKEN_PREFIX);
		$seq = (int)$db->querySingle('SELECT seq FROM sqlite_sequence WHERE name = \'phoromatic_system_tokens\'');
		$token_id = max($seq, (int)$db->querySingle('SELECT MAX(TokenID) FROM phoromatic_system_tokens')) + 1;
		$ins = $db->prepare('INSERT INTO phoromatic_system_tokens (TokenID, AccountID, SystemID, TokenHash, EnrolCodeID, MachineID, CreatedOn) VALUES (:id, :a, :y, :h, :c, :m, :now)');
		foreach(array(':id' => $token_id, ':a' => $account_id, ':y' => $system_id, ':h' => hash('sha256', $token), ':c' => (int)$row['CodeID'], ':m' => $machine['machine_id'], ':now' => $now) as $k => $v)
		{
			$ins->bindValue($k, $v);
		}
		if(@$ins->execute() === false
			|| !phoromatic_admin_audit::record($account_id, 'agent', 'enroll', 'agent_enrolled', array('subject_type' => 'system', 'subject_id' => $system_id,
				'detail' => array('token' => 'AT' . $token_id, 'code' => 'EC' . $row['CodeID'], 'code_label' => $row['Label'], 'new_system' => $created, 'policy_set' => $row['SetPolicy'],
					'revoked_tokens' => $revoked, 'hostname' => self::text($machine['hostname'], 64), 'os' => self::text($machine['os'], 16), 'arch' => self::text($machine['arch'], 16),
					'engine' => self::text($body['engine']['version'], 40)))))
		{
			@$db->exec('ROLLBACK');
			return array('error' => array(500, 'server_error', 'The enrollment could not be recorded (' . phoromatic_admin_audit::last_error() . ').'));
		}
		if(@$db->exec('COMMIT') === false)
		{
			@$db->exec('ROLLBACK');
			return array('error' => array(500, 'server_error', 'The enrollment could not be saved.'));
		}
		$system = self::one('SELECT Title, EnginePolicy FROM phoromatic_systems WHERE AccountID = :a AND SystemID = :y', array(':a' => $account_id, ':y' => $system_id));
		return array('status' => 201, 'body' => array('token' => $token, 'system' => array('id' => $system_id, 'title' => (string)$system['Title']),
			'account' => array('id' => $account_id, 'name' => self::account_name($account_id)), 'policy' => $system['EnginePolicy'] === 'native' ? 'native' : 'pts'));
	}

	// ----- every other call

	// The group name of the account, or its id when it has none (a fresh account's group name is empty), so the agent never prints a blank name
	protected static function account_name($account_id)
	{
		$name = trim((string)phoromatic_server::account_id_to_group_name($account_id));
		return $name !== '' ? $name : (string)$account_id;
	}
	public static function account_state($account_id)
	{
		// Tenancy adds phoromatic_accounts.State later; until then (and on a database without it) every account is active
		if(self::$account_has_state === null)
		{
			self::$account_has_state = false;
			$cols = phoromatic_server::$db->query('PRAGMA table_info(phoromatic_accounts)');
			while($cols && ($col = $cols->fetchArray(SQLITE3_ASSOC)))
			{
				if($col['name'] == 'State')
				{
					self::$account_has_state = true;
				}
			}
		}
		if(!self::$account_has_state)
		{
			return 'active';
		}
		$row = self::one('SELECT State FROM phoromatic_accounts WHERE AccountID = :a', array(':a' => $account_id));
		return $row && $row['State'] !== null ? (string)$row['State'] : 'active';
	}
	protected static function bearer()
	{
		$header = null;
		foreach(array('HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION') as $key)
		{
			if(!empty($_SERVER[$key]) && is_string($_SERVER[$key]))
			{
				$header = $_SERVER[$key];
				break;
			}
		}
		if($header === null && function_exists('getallheaders'))
		{
			foreach(getallheaders() as $name => $value)
			{
				if(strtolower($name) === 'authorization' && is_string($value))
				{
					$header = $value;
				}
			}
		}
		return $header !== null && preg_match('/^Bearer ([^\s]+)\z/i', trim($header), $m) === 1 ? $m[1] : null;
	}
	// array('token' => row, 'system' => row) or array('error' => array(status, code, message[, headers])). The system row is the live one;
	// whether it is approved is the caller's question (hello is answered for an unapproved system, nothing else is).
	public static function authenticate()
	{
		if(($wait = self::locked_out('auth', self::AUTH_MAX_FAILURES, self::AUTH_WINDOW)) > 0)
		{
			return array('error' => array(429, 'rate_limited', self::lockout_message('requests', $wait), array('Retry-After' => $wait)));
		}
		$token = self::bearer();
		$row = self::valid_token_format($token) ? self::one('SELECT * FROM phoromatic_system_tokens WHERE TokenHash = :h', array(':h' => hash('sha256', $token))) : false;
		if(!$row)
		{
			self::failure('auth', null, 'invalid', self::AUTH_MAX_FAILURES, self::AUTH_WINDOW);
			return array('error' => array(401, 'unauthenticated', 'A valid agent token is required.'));
		}
		if($row['RevokedOn'] !== null)
		{
			return array('error' => array(401, 'token_revoked', 'This token was revoked. Enroll the machine again.'));
		}
		$system = self::one('SELECT * FROM phoromatic_systems WHERE AccountID = :a AND SystemID = :y', array(':a' => $row['AccountID'], ':y' => $row['SystemID']));
		if(!$system || $system['DeletedOn'] !== null)
		{
			return array('error' => array(401, 'token_revoked', 'This system no longer exists. Enroll the machine again.'));
		}
		$state = self::account_state($row['AccountID']);
		if($state === 'suspended')
		{
			return array('error' => array(403, 'tenant_suspended', 'This account is suspended.'));
		}
		if($state === 'deleted')
		{
			return array('error' => array(401, 'unauthenticated', 'A valid agent token is required.'));
		}
		if($row['LastUsedOn'] === null || strtotime($row['LastUsedOn']) < time() - self::TOUCH_INTERVAL || $row['LastUsedIP'] !== self::ip())
		{
			$stmt = phoromatic_server::$db->prepare('UPDATE phoromatic_system_tokens SET LastUsedOn = :now, LastUsedIP = :ip WHERE TokenID = :t');
			foreach(array(':now' => phoromatic_server::current_time(), ':ip' => self::ip(), ':t' => (int)$row['TokenID']) as $k => $v)
			{
				$stmt->bindValue($k, $v);
			}
			@$stmt->execute();
		}
		return array('token' => $row, 'system' => $system);
	}
}

?>
