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
	const MSG_REJECTED = 'The repository token was not accepted (wrong, revoked, or too many failed attempts from this address). Run private-repo-login with a current token.';

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
	public static function create($account_id, $label, $actor_type, $actor)
	{
		// array('token' => raw token, 'id' => TokenID, 'label' => ...) or array('error' => ...)
		$label = trim(preg_replace('/[^ -~]/', '', (string)$label));
		if($label === '' || strlen($label) > self::MAX_LABEL)
		{
			return array('error' => 'A label of 1 to ' . self::MAX_LABEL . ' printable characters is required (e.g. the machine or person using the token).');
		}
		$db = self::db();
		$token = self::PREFIX . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

		$db->exec('BEGIN IMMEDIATE');
		$stmt = @$db->prepare('INSERT INTO phoromatic_private_repo_tokens (AccountID, Label, TokenHash, CreatedOn, CreatedBy) VALUES (:account_id, :label, :hash, :created_on, :created_by)');
		$ok = $stmt != false;
		if($ok)
		{
			$stmt->bindValue(':account_id', $account_id);
			$stmt->bindValue(':label', $label);
			$stmt->bindValue(':hash', hash('sha256', $token));
			$stmt->bindValue(':created_on', phoromatic_server::current_time());
			$stmt->bindValue(':created_by', $actor);
			$ok = @$stmt->execute() !== false;
		}
		$id = $ok ? $db->lastInsertRowID() : null;
		if(!$ok || !phoromatic_private_repo_audit::record($account_id, $actor_type, $actor, 'token_created', array('detail' => array('token_id' => $id, 'label' => $label))))
		{
			$db->exec('ROLLBACK');
			return array('error' => 'The token could not be created: ' . ($ok ? 'the audit log could not be written (' . phoromatic_private_repo_audit::last_error() . ').' : 'database error.'));
		}
		$db->exec('COMMIT');
		return array('token' => $token, 'id' => $id, 'label' => $label);
	}
	public static function revoke($account_id, $token_id, $actor_type, $actor)
	{
		// array('revoked' => TokenID) or array('error' => ...)
		$db = self::db();
		$db->exec('BEGIN IMMEDIATE');
		$stmt = $db->prepare('UPDATE phoromatic_private_repo_tokens SET RevokedOn = :now WHERE AccountID = :account_id AND TokenID = :id AND RevokedOn IS NULL');
		$stmt->bindValue(':now', phoromatic_server::current_time());
		$stmt->bindValue(':account_id', $account_id);
		$stmt->bindValue(':id', (int)$token_id, SQLITE3_INTEGER);
		$done = @$stmt->execute() !== false && $db->changes() == 1;
		if(!$done)
		{
			$db->exec('ROLLBACK');
			return array('error' => 'No active token with that ID.');
		}
		if(!phoromatic_private_repo_audit::record($account_id, $actor_type, $actor, 'token_revoked', array('detail' => array('token_id' => (int)$token_id))))
		{
			$db->exec('ROLLBACK');
			return array('error' => 'The token was not revoked: the audit log could not be written (' . phoromatic_private_repo_audit::last_error() . ').');
		}
		$db->exec('COMMIT');
		return array('revoked' => (int)$token_id);
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
		return $row && hash_equals($row['TokenHash'], $hash) ? $row : false;
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
			phoromatic_private_repo_audit::record(ACCOUNT_ID, 'machine', SYSTEM_ID, 'cli_auth_failed', array('detail' => array('reason' => 'missing', 'resource' => isset($_POST['r']) ? $_POST['r'] : null)));
			return $fail(self::MSG_REQUIRED);
		}
		if(phoromatic_private_repo_audit::recent_failures_for_ip($ip, self::FAILURE_WINDOW, 'invalid') >= self::MAX_FAILURES)
		{
			// Locked out: same message as a wrong token, no new row (the limiter exists to stop log spam)
			return $fail(self::MSG_REJECTED);
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
