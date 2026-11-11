<?php

/*
	Benchy
	Repository tokens: the credential the CLI needs, on top of an approved machine, to upload to, delete from or rebuild the
	private test profile / test suite repository.

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

// A token is "bpr_" + 43 base64url characters (256 bits). Only its SHA-256 is stored; the raw value is shown once, at creation.
// Creation and revocation write their audit row in the same transaction and fail closed: no audit row, no change.

class phoromatic_private_repo_auth
{
	const PREFIX = 'bpr_';
	const MAX_LABEL = 64;
	const MAX_FAILURES = 10;
	const FAILURE_WINDOW = 300;
	const MSG_REQUIRED = 'A repository token is required to upload to, delete from or rebuild the private repository. Get one from the Phoromatic server administrator (on the server: benchy private-repo-token-create LABEL), then run: private-repo-login';
	const MISSING_AUDIT_WINDOW = 3600;
	const MSG_REJECTED = 'The repository token was not accepted (wrong or revoked). Run private-repo-login with a current token.';

	protected static $current = null;

	public static function current_label()
	{
		return self::$current != null ? self::$current['Label'] : null;
	}
	public static function valid_token_format($token)
	{
		return is_string($token) && preg_match('/^bpr_[A-Za-z0-9_-]{43}\z/', $token) === 1;
	}
	public static function local_actor()
	{
		// Who ran a server-side token command (shell access to the server host is the authority there)
		$user = function_exists('posix_geteuid') && function_exists('posix_getpwuid') && ($pw = posix_getpwuid(posix_geteuid())) ? $pw['name'] : get_current_user();
		return 'local:' . $user;
	}
	protected static function db()
	{
		if(phoromatic_server::$db == null)
		{
			phoromatic_server::prepare_database();
		}
		return phoromatic_server::$db;
	}
	public static function create($account_id, $label, $actor_type, $actor, $actor_detail = null)
	{
		// array('token' => raw token, 'id' => TokenID, 'label' => ...) or array('error' => ...). Fails closed: no change without
		// an audit row. The mirror log gets an "(attempt)" line before the commit and a confirmation or rollback line after it.
		$label = trim(preg_replace('/[^ -~]/', '', (string)$label));
		if($label === '' || strlen($label) > self::MAX_LABEL)
		{
			return array('error' => 'A label of 1 to ' . self::MAX_LABEL . ' printable characters is required (e.g. the machine or person using the token).');
		}
		$db = self::db();
		$token = self::PREFIX . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
		$began = false;
		$id = null;

		try
		{
			// Without the write lock the statements below would run in autocommit and a rollback could not undo them
			if(@$db->exec('BEGIN IMMEDIATE') === false)
			{
				return array('error' => 'The token could not be created: the database is busy (' . $db->lastErrorMsg() . '). Try again.');
			}
			$began = true;
			$stmt = @$db->prepare('INSERT INTO phoromatic_private_repo_tokens (AccountID, Label, TokenHash, CreatedOn, CreatedBy) VALUES (:account_id, :label, :hash, :created_on, :created_by)');
			if($stmt == false)
			{
				throw new Exception('database error');
			}
			$stmt->bindValue(':account_id', $account_id);
			$stmt->bindValue(':label', $label);
			$stmt->bindValue(':hash', hash('sha256', $token));
			$stmt->bindValue(':created_on', phoromatic_server::current_time());
			$stmt->bindValue(':created_by', $actor);
			if(@$stmt->execute() === false)
			{
				throw new Exception('database error');
			}
			$id = $db->lastInsertRowID();
			if(!phoromatic_private_repo_audit::record($account_id, $actor_type, $actor, 'token_created', array('actor_detail' => $actor_detail, 'mirror_label' => 'attempt', 'detail' => array('token_id' => $id, 'label' => $label))))
			{
				throw new Exception('the audit log could not be written (' . phoromatic_private_repo_audit::last_error() . ')');
			}
			if(@$db->exec('COMMIT') === false)
			{
				throw new Exception('the commit failed (' . $db->lastErrorMsg() . ')');
			}
		}
		catch(Throwable $e)
		{
			if($began)
			{
				@$db->exec('ROLLBACK');
				phoromatic_private_repo_audit::mirror_note('token_created ROLLED BACK (no token exists): ' . $e->getMessage());
			}
			return array('error' => 'The token could not be created: ' . $e->getMessage() . '.');
		}

		phoromatic_private_repo_audit::mirror_note('token_created committed: token ' . $id . ' (' . $label . ')');
		return array('token' => $token, 'id' => $id, 'label' => $label);
	}
	public static function revoke($account_id, $token_id, $actor_type, $actor, $actor_detail = null)
	{
		// array('revoked' => TokenID) or array('error' => ...); same fail-closed scheme as create()
		$db = self::db();
		$token_id = (int)$token_id;
		$began = false;

		try
		{
			if(@$db->exec('BEGIN IMMEDIATE') === false)
			{
				return array('error' => 'The token was not revoked: the database is busy (' . $db->lastErrorMsg() . '). Try again.');
			}
			$began = true;
			$stmt = @$db->prepare('UPDATE phoromatic_private_repo_tokens SET RevokedOn = :now WHERE AccountID = :account_id AND TokenID = :id AND RevokedOn IS NULL');
			if($stmt == false)
			{
				throw new Exception('database error');
			}
			$stmt->bindValue(':now', phoromatic_server::current_time());
			$stmt->bindValue(':account_id', $account_id);
			$stmt->bindValue(':id', $token_id, SQLITE3_INTEGER);
			if(@$stmt->execute() === false)
			{
				throw new Exception('database error');
			}
			if($db->changes() != 1)
			{
				@$db->exec('ROLLBACK');
				return array('error' => 'No active token with that ID.');
			}
			if(!phoromatic_private_repo_audit::record($account_id, $actor_type, $actor, 'token_revoked', array('actor_detail' => $actor_detail, 'mirror_label' => 'attempt', 'detail' => array('token_id' => $token_id))))
			{
				throw new Exception('the audit log could not be written (' . phoromatic_private_repo_audit::last_error() . ')');
			}
			if(@$db->exec('COMMIT') === false)
			{
				throw new Exception('the commit failed (' . $db->lastErrorMsg() . ')');
			}
		}
		catch(Throwable $e)
		{
			if($began)
			{
				@$db->exec('ROLLBACK');
				phoromatic_private_repo_audit::mirror_note('token_revoked ROLLED BACK (token ' . $token_id . ' is still active): ' . $e->getMessage());
			}
			return array('error' => 'The token was not revoked: ' . $e->getMessage() . '.');
		}

		phoromatic_private_repo_audit::mirror_note('token_revoked committed: token ' . $token_id);
		return array('revoked' => $token_id);
	}
	public static function storage_folder_of_other_user()
	{
		// True when the storage folder exists and belongs to another user: that is the case the "Run: sudo -u OWNER ..." advice below is for
		// (the folder is fine, this is the wrong user), not a storage setting that needs fixing
		$info = phoromatic_server::phoromatic_path_info();
		return $info[1] !== 'default-conflict' && is_dir($info[0]) && function_exists('posix_geteuid') && fileowner(rtrim($info[0], '/')) !== posix_geteuid();
	}
	public static function server_command_preflight($command)
	{
		// The token commands run on the server host and share its database and audit log with the service. Run as anyone but the
		// service user they could create files the service can then not use (which would make every upload fail closed) or a stray
		// database elsewhere. So refuse (null if fine, else the message) instead of chowning from here.
		if(phoromatic_server::storage_problem() !== null && !self::storage_folder_of_other_user())
		{
			phoromatic_server::storage_error_exit();   // (names the setting and the folder, exits 78: not "no database was found")
		}
		$path = phoromatic_server::phoromatic_path(false);
		$db_file = $path . 'phoromatic.db';
		if(!is_file($db_file))
		{
			return 'No Phoromatic database was found at ' . $db_file . '. Run this as the user that runs the Phoromatic server, e.g. sudo -u SERVICEUSER benchy ' . $command . ' (a different user has a different storage location; this command never creates a database).';
		}
		if(!function_exists('posix_geteuid') || !function_exists('posix_getpwuid'))
		{
			return null;
		}
		$owner = fileowner($db_file);
		$name = ($pw = posix_getpwuid($owner)) ? $pw['name'] : (string)$owner;
		if(posix_geteuid() !== $owner)
		{
			return 'The Phoromatic database ' . $db_file . ' belongs to ' . $name . ', but this command is running as a different user. Run: sudo -u ' . $name . (($pw && !empty($pw['dir'])) ? ' env HOME=' . (preg_match('#^[A-Za-z0-9_./-]+$#', $pw['dir']) ? $pw['dir'] : escapeshellarg($pw['dir'])) : '') . ' benchy ' . $command;
		}
		foreach(array($db_file . '-wal', $db_file . '-shm', phoromatic_private_repo_audit::mirror_path()) as $file)
		{
			if(is_file($file) && fileowner($file) !== $owner)
			{
				return $file . ' is not owned by ' . $name . ' (a previous command run as another user probably created it), so the server could not use it. Fix it first: sudo chown ' . $name . ' ' . $file;
			}
		}
		return null;
	}
	public static function list_tokens($account_id)
	{
		$stmt = self::db()->prepare('SELECT TokenID, Label, CreatedOn, CreatedBy, LastUsedOn, LastUsedIP, RevokedOn FROM phoromatic_private_repo_tokens WHERE AccountID = :account_id ORDER BY TokenID ASC');
		$stmt->bindValue(':account_id', $account_id);
		$result = $stmt->execute();
		$rows = array();
		while($result && $row = $result->fetchArray(SQLITE3_ASSOC))
		{
			$rows[] = $row;
		}
		return $rows;
	}
	public static function verify($account_id, $token)
	{
		// The matching active token row, or false
		if(!self::valid_token_format($token))
		{
			return false;
		}
		$hash = hash('sha256', $token);
		$stmt = self::db()->prepare('SELECT * FROM phoromatic_private_repo_tokens WHERE AccountID = :account_id AND TokenHash = :hash AND RevokedOn IS NULL');
		$stmt->bindValue(':account_id', $account_id);
		$stmt->bindValue(':hash', $hash);
		$result = $stmt->execute();
		$row = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;
		// The lookup is by the hash of the presented token, so no secret-dependent comparison happens in PHP
		return $row ? $row : false;
	}
	public static function authorize_cli_request()
	{
		// For the upload/delete/rebuild endpoints, after the machine has been found approved (phoromatic.php does that first):
		// the POST field rt must be an active token of this account. Prints the JSON error and returns false otherwise.
		$ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
		$fail = function($message) {
			echo json_encode(array('phoromatic' => array('error' => $message)));
			return false;
		};
		$token = isset($_POST['rt']) && is_string($_POST['rt']) ? $_POST['rt'] : '';

		if($token === '')
		{
			// An old client, or no private-repo-login yet: tell them what to do. Not counted towards the lockout.
			// Recorded at most once per machine and hour so a misconfigured script cannot grow the audit table and log without bound.
			if(!phoromatic_private_repo_audit::has_recent_failure(SYSTEM_ID, 'missing', self::MISSING_AUDIT_WINDOW))
			{
				phoromatic_private_repo_audit::record(ACCOUNT_ID, 'machine', SYSTEM_ID, 'cli_auth_failed', array('detail' => array('reason' => 'missing', 'resource' => isset($_POST['r']) ? $_POST['r'] : null, 'note' => 'further missing-token attempts from this machine are not recorded for an hour')));
			}
			return $fail(self::MSG_REQUIRED);
		}
		if(($wait = phoromatic_private_repo_audit::lockout_seconds_remaining($ip, self::MAX_FAILURES, self::FAILURE_WINDOW, 'invalid')) > 0)
		{
			// Locked out (every token from this address, valid or not, so the message gives nothing away): no new row, the limiter
			// exists to stop log spam
			return $fail('Too many failed repository token attempts from this address; try again in about ' . ceil($wait / 60) . ' minute(s).');
		}
		if(($row = self::verify(ACCOUNT_ID, $token)) === false)
		{
			phoromatic_private_repo_audit::record(ACCOUNT_ID, 'machine', SYSTEM_ID, 'cli_auth_failed', array('detail' => array('reason' => 'invalid', 'resource' => isset($_POST['r']) ? $_POST['r'] : null)));
			return $fail(self::MSG_REJECTED);
		}

		self::$current = $row;
		$stmt = @self::db()->prepare('UPDATE phoromatic_private_repo_tokens SET LastUsedOn = :now, LastUsedIP = :ip WHERE TokenID = :id');
		if($stmt)
		{
			$stmt->bindValue(':now', phoromatic_server::current_time());
			$stmt->bindValue(':ip', $ip);
			$stmt->bindValue(':id', $row['TokenID'], SQLITE3_INTEGER);
			@$stmt->execute();
		}
		return true;
	}
}

?>
