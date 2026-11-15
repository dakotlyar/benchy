<?php

/*
	Benchy
	phoromatic_passwords.php: how a Phoromatic password is stored and checked (schema 62, tenancy T6).

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

// Two hashes are kept for every password in 0.9.14, so that a rollback to 0.9.13 stays clean:
//   legacy  phoromatic_users.Password: sha256(account_salt . pw) for a user, sha256('PTS' . pw) for the root admin. The release before this one
//           reads only this, and its own password changes write only this.
//   modern  PasswordHash: password_hash() (PHP's default, bcrypt) of the password, with PasswordLegacyFp beside it: a fingerprint of the
//           legacy value as it was when the modern hash was written.
// A login trusts the modern hash only while the legacy value still has that fingerprint. When the release before this one has changed the
// password, the legacy value has moved on, the fingerprint no longer matches, the modern hash is stale and is not consulted: the legacy
// hash decides, and a success writes a fresh modern hash. So a password changed on 0.9.13 after a rollback wins over the one before it.
// Every place that sets a password writes the legacy hash as it always did and then calls store() (or store_root()) in the same transaction.
//
// Dropping the legacy hash is a decision for 0.9.15 and is not built: nothing here writes '!' to Password. A release that does so has to make
// this class trust a modern hash whose legacy value is '!' (it would never match its fingerprint).
//
// bcrypt reads only the first 72 bytes of its input and refuses a NUL byte, so it is given base64(sha256(password)) (44 characters), which
// keeps every byte of a long password significant. The legacy comparison is hash_equals() (constant-time); password_verify() is too.
class phoromatic_passwords
{
	private static $dummy = null;

	public static function ready()
	{
		return phoromatic_server::passwords_ready();
	}
	public static function legacy_user($salt, $password)
	{
		return hash('sha256', $salt . $password);
	}
	public static function legacy_root($password)
	{
		return hash('sha256', 'PTS' . $password);
	}
	protected static function prepare($password)
	{
		return base64_encode(hash('sha256', (string)$password, true));
	}
	public static function modern($password)
	{
		return password_hash(self::prepare($password), PASSWORD_DEFAULT);
	}
	public static function fingerprint($legacy)
	{
		return hash('sha256', 'legacy-fingerprint:' . $legacy);
	}
	public static function waste_time()
	{
		// A check against a hash of a password nobody has, made at the cost password_hash() uses today: a user name that is not there and a password
		// that is wrong for a user with no modern hash yet cost as much as a wrong password for one who has (a request that fails pays for two hashes)
		if(self::$dummy === null)
		{
			self::$dummy = password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT);
		}
		password_verify('x', self::$dummy);
		return false;
	}
	public static function check($stored_legacy, $stored_modern, $stored_fingerprint, $candidate_legacy, $password, $persist = null)
	{
		// True when $password is the password. $candidate_legacy is the legacy hash of $password ('' when it cannot be made: no salt).
		// $stored_modern/$stored_fingerprint are null when there is nowhere to keep them (the schema 62 step is waiting); $persist(modern, fingerprint)
		// writes a new modern hash and is called after a legacy success and when password_needs_rehash() says the cost has changed.
		$stored_legacy = (string)$stored_legacy;
		if(is_string($stored_modern) && $stored_modern !== '' && is_string($stored_fingerprint) && hash_equals(self::fingerprint($stored_legacy), $stored_fingerprint))
		{
			if(!password_verify(self::prepare($password), $stored_modern))
			{
				return false;
			}
			if($persist !== null && password_needs_rehash($stored_modern, PASSWORD_DEFAULT))
			{
				$persist(self::modern($password), $stored_fingerprint);
			}
			return true;
		}
		if($candidate_legacy === '' || !hash_equals($stored_legacy, $candidate_legacy))
		{
			return self::waste_time();
		}
		if($persist !== null)
		{
			$persist(self::modern($password), self::fingerprint($stored_legacy));
		}
		return true;
	}
	public static function check_user($row, $salt, $password)
	{
		// $row: UserID and Password, and PasswordHash and PasswordLegacyFp when the schema has them (phoromatic_users). $salt: the home account's.
		$ready = self::ready();
		$legacy = (string)$row['Password'];
		$user_id = (string)$row['UserID'];
		return self::check($legacy, $ready ? ($row['PasswordHash'] ?? null) : null, $ready ? ($row['PasswordLegacyFp'] ?? null) : null,
			empty($salt) ? '' : self::legacy_user($salt, $password), $password, !$ready ? null : function($modern, $fingerprint) use ($user_id, $legacy)
		{
			// (only while the legacy value is the one that was checked: a password changed in the meantime is not overwritten)
			$stmt = phoromatic_server::$db->prepare('UPDATE phoromatic_users SET PasswordHash = :hash, PasswordLegacyFp = :fp WHERE UserID = :user_id AND Password = :legacy');
			$stmt->bindValue(':hash', $modern);
			$stmt->bindValue(':fp', $fingerprint);
			$stmt->bindValue(':user_id', $user_id);
			$stmt->bindValue(':legacy', $legacy);
			@$stmt->execute();
		});
	}
	public static function store($user_id, $legacy, $password)
	{
		// The modern hash of a password that has just been set, for the user whose legacy hash is $legacy. True when written (or when the schema
		// has no place for it yet: the legacy hash alone is then the password, as before). Call it inside the transaction that wrote the legacy hash.
		if(!self::ready())
		{
			return true;
		}
		$stmt = phoromatic_server::$db->prepare('UPDATE phoromatic_users SET PasswordHash = :hash, PasswordLegacyFp = :fp WHERE UserID = :user_id');
		$stmt->bindValue(':hash', self::modern($password));
		$stmt->bindValue(':fp', self::fingerprint($legacy));
		$stmt->bindValue(':user_id', (string)$user_id);
		return @$stmt->execute() !== false;
	}
	public static function check_root($password)
	{
		// The root admin's password, kept in the server's settings file: root_admin_pw (legacy), root_admin_pw_hash and root_admin_pw_fp
		$legacy = phoromatic_server::read_setting('root_admin_pw');
		return self::check($legacy, phoromatic_server::read_setting('root_admin_pw_hash'), phoromatic_server::read_setting('root_admin_pw_fp'),
			self::legacy_root($password), $password, function($modern, $fingerprint)
		{
			// (the hash first and its fingerprint last: a stop in between leaves a hash that no fingerprint vouches for, and the legacy hash decides)
			phoromatic_server::save_setting('root_admin_pw_hash', $modern);
			phoromatic_server::save_setting('root_admin_pw_fp', $fingerprint);
		});
	}
	public static function store_root($password)
	{
		$legacy = self::legacy_root($password);
		phoromatic_server::settings_locked(function() use ($legacy, $password)
		{
			phoromatic_server::save_setting('root_admin_pw', $legacy);
			phoromatic_server::save_setting('root_admin_pw_hash', self::modern($password));
			phoromatic_server::save_setting('root_admin_pw_fp', self::fingerprint($legacy));
		});
	}
}

?>
