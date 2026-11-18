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
// Since schema 67 (DROP_STEP, the last step of 0.10.0) there is no legacy hash any more. A password is in one of three states:
//   both hashes   before the step (and while it waits): as above, exactly the 0.9.15 behavior. Drop mode (phoromatic_server::passwords_dropped()) keys on
//                 the schema, so a step that is waiting for its backup never drops anything.
//   modern        Password = '!', PasswordHash = password_hash(prepare(password)), PasswordLegacyFp NULL, PasswordScheme NULL.
//   wrapped       an account that had no trusted modern hash when the step ran (nobody had signed in since 0.9.14): Password = '!',
//                 PasswordScheme = 'legacy-wrapped', PasswordHash = password_hash(prepare(legacy value)). The check recomputes the legacy value from the
//                 typed password (a sha256 more than a modern check, the same bcrypt) and verifies the bcrypt over it; a success replaces it with a modern hash.
// Root is the same, in the settings file: root_admin_pw ('!' once dropped, never removed: "not set" must keep meaning not set), root_admin_pw_hash,
// root_admin_pw_scheme; root_admin_pw_fp is removed. sweep() does the conversion (at the step, and again on any open that finds a legacy value, which is what a
// rollback to 0.9.15 and a re-upgrade leaves behind). The legacy check path stays for those rows and goes at 1.0 (design decision, 2026-11-17).
// PasswordLegacyFp is not dropped as a column (0.9.15 names it in its sign-in SELECT, so a rollback gets a clean refusal), it is NULL everywhere.
// Every place that sets a password gets its column values from columns_for() (and store_root()): nothing outside this class calls legacy_user() or
// legacy_root() (tests/tenancy/lint_passwords.py holds that).
//
// bcrypt reads only the first 72 bytes of its input and refuses a NUL byte, so it is given base64(sha256(password)) (44 characters), which
// keeps every byte of a long password significant. The legacy comparison is hash_equals() (constant-time); password_verify() is too.
class phoromatic_passwords
{
	const DROP_STEP = 67;   // the schema step that removes the legacy hash; every "is the schema new enough" check uses this
	const WRAPPED = 'legacy-wrapped';

	public static function ready()
	{
		return phoromatic_server::passwords_ready();
	}
	public static function dropped()
	{
		return phoromatic_server::passwords_dropped();
	}
	public static function select_columns()
	{
		// the columns a sign-in or a password change reads besides UserID/Password, for the schema the database is at
		return self::ready() ? ', PasswordHash, PasswordLegacyFp' . (self::dropped() ? ', PasswordScheme' : '') : '';
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
		// One password_verify() against a constant hash of a password nobody has, at the cost password_hash() uses (10 before PHP 8.4, 12 from it): a user
		// name that is not there, and a wrong password for a user with no modern hash yet, cost what a wrong password for one who has costs
		password_verify('x', PHP_VERSION_ID >= 80400 ? '$2y$12$Pj/4s1dcVSUNNXOeuEOz2uRtArCwjdiZf8.Z9t8IqLtemZAYOvAdi' : '$2y$10$rONm.LcM3tBLyOk0hRR9.OXZEM3f28cWQqaiDXmWVnzYSArjaNtpK');
		return false;
	}
	public static function check($stored_legacy, $stored_modern, $stored_fingerprint, $candidate_legacy, $password, $persist = null, $always_persist = false)
	{
		// True when $password is the password. $candidate_legacy is the legacy hash of $password ('' when it cannot be made: no salt).
		// $stored_modern/$stored_fingerprint are null when there is nowhere to keep them (the schema 62 step is waiting); $persist(modern, fingerprint)
		// writes a new modern hash and is called after a legacy success and when password_needs_rehash() says the cost has changed (or after any success, with $always_persist).
		$stored_legacy = (string)$stored_legacy;
		if(is_string($stored_modern) && $stored_modern !== '' && is_string($stored_fingerprint) && hash_equals(self::fingerprint($stored_legacy), $stored_fingerprint))
		{
			if(!password_verify(self::prepare($password), $stored_modern))
			{
				return false;
			}
			if($persist !== null && ($always_persist || password_needs_rehash($stored_modern, PASSWORD_DEFAULT)))
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
	protected static function wrap($legacy)
	{
		// a bcrypt of the legacy value, for an account that has no modern hash of its own yet
		return password_hash(self::prepare($legacy), PASSWORD_DEFAULT);
	}
	protected static function check_modern($hash, $scheme, $candidate_legacy, $password, $persist)
	{
		// The password of a row whose legacy value is gone ('!'). $persist(new_hash) is called after a success that should change what is stored (a wrapped hash
		// becomes a plain one; a plain one whose cost has changed is made again).
		if(!is_string($hash) || $hash === '')
		{
			// '!' with no hash should not exist (the sweep always writes one); fail closed
			return self::waste_time();
		}
		if($scheme === null || $scheme === false || $scheme === '')   // (a setting that is not there reads as false)
		{
			if(!password_verify(self::prepare($password), $hash))
			{
				return false;
			}
			if(password_needs_rehash($hash, PASSWORD_DEFAULT))
			{
				$persist(self::modern($password));
			}
			return true;
		}
		if($scheme === self::WRAPPED && $candidate_legacy !== '')
		{
			if(!password_verify(self::prepare($candidate_legacy), $hash))
			{
				return false;
			}
			$persist(self::modern($password));
			return true;
		}
		return self::waste_time();   // an empty salt (no legacy value to recompute), or a scheme this release does not know
	}
	public static function check_user($row, $salt, $password)
	{
		// $row: UserID and Password, and PasswordHash, PasswordLegacyFp (and PasswordScheme once dropped) when the schema has them (select_columns()). $salt: the home account's.
		$ready = self::ready();
		$legacy = (string)$row['Password'];
		$user_id = (string)$row['UserID'];
		$candidate = empty($salt) ? '' : self::legacy_user($salt, $password);
		if($ready && self::dropped())
		{
			if($legacy === '!')
			{
				return self::check_modern($row['PasswordHash'] ?? null, $row['PasswordScheme'] ?? null, $candidate, $password, function($modern) use ($user_id, $row)
				{
					// (only while the hash is the one that was checked)
					$stmt = phoromatic_server::$db->prepare('UPDATE phoromatic_users SET PasswordHash = :hash, PasswordScheme = NULL WHERE UserID = :user_id AND Password = \'!\' AND PasswordHash = :old');
					$stmt->bindValue(':hash', $modern);
					$stmt->bindValue(':user_id', $user_id);
					$stmt->bindValue(':old', (string)$row['PasswordHash']);
					@$stmt->execute();
				});
			}
			// A real legacy value in drop mode: a row the sweep has not reached (or 0.9.15 wrote after a rollback). 0.9.15's check; a success makes it plain modern
			return self::check($legacy, $row['PasswordHash'] ?? null, $row['PasswordLegacyFp'] ?? null, $candidate, $password, function($modern, $fingerprint) use ($user_id, $legacy)
			{
				$stmt = phoromatic_server::$db->prepare('UPDATE phoromatic_users SET Password = \'!\', PasswordHash = :hash, PasswordLegacyFp = NULL, PasswordScheme = NULL WHERE UserID = :user_id AND Password = :legacy');
				$stmt->bindValue(':hash', $modern);
				$stmt->bindValue(':user_id', $user_id);
				$stmt->bindValue(':legacy', $legacy);
				@$stmt->execute();
			}, true);
		}
		return self::check($legacy, $ready ? ($row['PasswordHash'] ?? null) : null, $ready ? ($row['PasswordLegacyFp'] ?? null) : null,
			$candidate, $password, !$ready ? null : function($modern, $fingerprint) use ($user_id, $legacy)
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
	public static function columns_for($salt, $password)
	{
		// The column values of phoromatic_users for a password that is being set, for the schema the database is at: Password, PasswordHash, PasswordLegacyFp,
		// PasswordScheme (null where the column is not there yet). Every writer takes its values from here and then calls store().
		if(self::ready() && self::dropped())
		{
			return array('Password' => '!', 'PasswordHash' => self::modern($password), 'PasswordLegacyFp' => null, 'PasswordScheme' => null);
		}
		$legacy = self::legacy_user($salt, $password);
		if(self::ready())
		{
			return array('Password' => $legacy, 'PasswordHash' => self::modern($password), 'PasswordLegacyFp' => self::fingerprint($legacy), 'PasswordScheme' => null);
		}
		return array('Password' => $legacy, 'PasswordHash' => null, 'PasswordLegacyFp' => null, 'PasswordScheme' => null);
	}
	public static function store($user_id, $columns)
	{
		// The hash columns of columns_for() for a user whose Password column has just been written from the same array. True when written (or when the schema
		// has no place for them yet: the legacy hash alone is then the password, as before). Call it inside the transaction that wrote Password.
		if(!self::ready())
		{
			return true;
		}
		$dropped = self::dropped();
		$stmt = phoromatic_server::$db->prepare('UPDATE phoromatic_users SET PasswordHash = :hash, PasswordLegacyFp = :fp' . ($dropped ? ', PasswordScheme = :scheme' : '') . ' WHERE UserID = :user_id');
		$stmt->bindValue(':hash', $columns['PasswordHash']);
		$stmt->bindValue(':fp', $columns['PasswordLegacyFp']);
		if($dropped)
		{
			$stmt->bindValue(':scheme', $columns['PasswordScheme']);
		}
		$stmt->bindValue(':user_id', (string)$user_id);
		return @$stmt->execute() !== false;
	}
	public static function check_root($password)
	{
		// The root admin's password, kept in the server's settings file: root_admin_pw (legacy; '!' once dropped), root_admin_pw_hash, root_admin_pw_fp and root_admin_pw_scheme
		$legacy = phoromatic_server::read_setting('root_admin_pw');
		if(self::ready() && self::dropped())
		{
			$hash = phoromatic_server::read_setting('root_admin_pw_hash');
			if($legacy === '!')
			{
				return self::check_modern($hash, phoromatic_server::read_setting('root_admin_pw_scheme'), self::legacy_root($password), $password, function($modern) use ($hash)
				{
					self::root_replace_hash($hash, $modern);
				});
			}
			return self::check($legacy, $hash, phoromatic_server::read_setting('root_admin_pw_fp'), self::legacy_root($password), $password, function($modern, $fingerprint) use ($legacy)
			{
				// (under the settings lock and only while the legacy value is the one that was checked: a sweep or a new password in between wins; best effort, like every persist)
				try
				{
					phoromatic_server::settings_locked(function() use ($legacy, $modern)
					{
						if(phoromatic_server::read_setting('root_admin_pw') === $legacy)
						{
							self::root_write_plain($modern);
						}
					});
				}
				catch(Exception $e)
				{
				}
			}, true);
		}
		return self::check($legacy, phoromatic_server::read_setting('root_admin_pw_hash'), phoromatic_server::read_setting('root_admin_pw_fp'),
			self::legacy_root($password), $password, function($modern, $fingerprint)
		{
			// (the hash first and its fingerprint last: a stop in between leaves a hash that no fingerprint vouches for, and the legacy hash decides)
			// (best effort: a settings file that cannot be saved must not fail a correct log-in; the next one tries again)
			try
			{
				phoromatic_server::save_setting('root_admin_pw_hash', $modern);
				phoromatic_server::save_setting('root_admin_pw_fp', $fingerprint);
			}
			catch(Exception $e)
			{
			}
		});
	}
	protected static function root_replace_hash($old_hash, $new_hash)
	{
		// A wrapped (or out of date) root hash becomes a plain one. Best effort, like every persist: a failure leaves the old, still valid, hash.
		// (The two writes cannot be one; a stop between them leaves a hash and a scheme that disagree, which fails closed and is mended by set-root-admin-password. EC-P1.)
		try
		{
			phoromatic_server::settings_locked(function() use ($old_hash, $new_hash)
			{
				if(phoromatic_server::read_setting('root_admin_pw_hash') === $old_hash)
				{
					phoromatic_server::save_setting('root_admin_pw_hash', $new_hash);
					phoromatic_server::remove_setting('root_admin_pw_scheme');
				}
			});
		}
		catch(Exception $e)
		{
		}
	}
	protected static function root_write_plain($modern)
	{
		// Root's settings in the dropped state with a plain modern hash. The hash first, the legacy value ('!') after it, the fingerprint last: a stop part way
		// leaves a state the legacy-value check above still verifies (the old legacy value is real until the second write).
		phoromatic_server::save_setting('root_admin_pw_hash', $modern);
		phoromatic_server::remove_setting('root_admin_pw_scheme');
		phoromatic_server::save_setting('root_admin_pw', '!');
		phoromatic_server::remove_setting('root_admin_pw_fp');
	}
	public static function store_root($password)
	{
		if(self::ready() && self::dropped())
		{
			$modern = self::modern($password);
			phoromatic_server::settings_locked(function() use ($modern)
			{
				self::root_write_plain($modern);
			});
			return;
		}
		$legacy = self::legacy_root($password);
		phoromatic_server::settings_locked(function() use ($legacy, $password)
		{
			phoromatic_server::save_setting('root_admin_pw', $legacy);
			phoromatic_server::save_setting('root_admin_pw_hash', self::modern($password));
			phoromatic_server::save_setting('root_admin_pw_fp', self::fingerprint($legacy));
		});
	}
	public static function old_copies()
	{
		// How many database copies in <storage>/backups/ were taken at or before the drop (phoromatic-pre-<N>-*.db, N up to DROP_STEP): they hold every legacy hash.
		// Worked out from the file names; nothing is ever deleted here.
		$count = 0;
		foreach((array)glob(phoromatic_server::phoromatic_path(false) . 'backups/phoromatic-pre-*.db') as $file)
		{
			if(preg_match('/^phoromatic-pre-(\d+)-/', basename($file), $m) && (int)$m[1] <= self::DROP_STEP)
			{
				$count++;
			}
		}
		return $count;
	}
	public static function needs_sweep()
	{
		// True when a legacy value is still there: a user row whose Password is not '!', or a root_admin_pw that is a real value. One cheap read each.
		if(phoromatic_server::$db->querySingle('SELECT 1 FROM phoromatic_users WHERE Password != \'!\' LIMIT 1'))
		{
			return true;
		}
		try
		{
			$root = phoromatic_server::read_setting('root_admin_pw');
			$fp = phoromatic_server::read_setting('root_admin_pw_fp');
			return (is_string($root) && $root !== '' && $root !== '!') || (is_string($fp) && $fp !== '');   // (a fingerprint left by a stop between root's writes)
		}
		catch(Exception $e)
		{
			return false;
		}
	}
	public static function sweep_users()
	{
		// Every user row with a legacy value loses it: a trusted modern hash (its fingerprint matches the legacy value) is kept, anything else (no modern hash, or a
		// stale one because the password was changed on an older release) is wrapped. The caller holds a transaction. array(kept, wrapped), or false.
		$rows = array();
		$result = phoromatic_server::$db->query('SELECT UserID, Password, PasswordHash, PasswordLegacyFp FROM phoromatic_users WHERE Password != \'!\'');
		if($result === false)
		{
			return false;
		}
		while($row = $result->fetchArray(SQLITE3_ASSOC))
		{
			$rows[] = $row;
		}
		$kept = $wrapped = 0;
		foreach($rows as $row)
		{
			$legacy = (string)$row['Password'];
			$trusted = is_string($row['PasswordHash']) && $row['PasswordHash'] !== '' && is_string($row['PasswordLegacyFp']) && hash_equals(self::fingerprint($legacy), $row['PasswordLegacyFp']);
			// (a sign-in or password change that lands in between wins: the UPDATE only applies while Password is still the value that was read)
			$stmt = phoromatic_server::$db->prepare('UPDATE phoromatic_users SET Password = \'!\', PasswordHash = :hash, PasswordLegacyFp = NULL, PasswordScheme = :scheme WHERE UserID = :user_id AND Password = :legacy');
			$stmt->bindValue(':hash', $trusted ? $row['PasswordHash'] : self::wrap($legacy));
			$stmt->bindValue(':scheme', $trusted ? null : self::WRAPPED);
			$stmt->bindValue(':user_id', (string)$row['UserID']);
			$stmt->bindValue(':legacy', $legacy);
			if(@$stmt->execute() === false)
			{
				return false;
			}
			if(phoromatic_server::$db->changes() > 0)
			{
				$trusted ? $kept++ : $wrapped++;
			}
		}
		return array($kept, $wrapped);
	}
	public static function sweep_root()
	{
		// root_admin_pw: 'kept', 'wrapped', or null (nothing to do: never set, or already dropped). Under the settings lock; written in the order that every stop part
		// way leaves verifiable (see root_write_plain): hash and scheme first, then '!', then the fingerprint goes.
		return phoromatic_server::settings_locked(function()
		{
			$legacy = phoromatic_server::read_setting('root_admin_pw');
			if($legacy === '!')
			{
				$left = phoromatic_server::read_setting('root_admin_pw_fp');
				if(is_string($left) && $left !== '')
				{
					phoromatic_server::remove_setting('root_admin_pw_fp');   // (a stop between '!' and this removal)
					return 'cleaned';
				}
				return null;
			}
			if(!is_string($legacy) || $legacy === '')
			{
				return null;
			}
			$hash = phoromatic_server::read_setting('root_admin_pw_hash');
			$fp = phoromatic_server::read_setting('root_admin_pw_fp');
			$trusted = is_string($hash) && $hash !== '' && is_string($fp) && hash_equals(self::fingerprint($legacy), $fp);
			if($trusted)
			{
				phoromatic_server::remove_setting('root_admin_pw_scheme');
			}
			else
			{
				phoromatic_server::save_setting('root_admin_pw_hash', self::wrap($legacy));
				phoromatic_server::save_setting('root_admin_pw_scheme', self::WRAPPED);
			}
			phoromatic_server::save_setting('root_admin_pw', '!');
			phoromatic_server::remove_setting('root_admin_pw_fp');
			return $trusted ? 'kept' : 'wrapped';
		});
	}
}

?>
