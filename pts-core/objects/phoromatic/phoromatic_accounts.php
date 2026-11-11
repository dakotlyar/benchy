<?php

/*
	Benchy Phoromatic accounts (0.9.8). This program is free software; you can redistribute it and/or modify it under the terms of the
	GNU General Public License as published by the Free Software Foundation; either version 3 of the License, or (at your option) any later version.
*/

class phoromatic_accounts
{
	const DEFAULT_CLOSED_MESSAGE = 'Account creation is closed on this server. Ask its administrator for an account.';

	public static function reserved_user_name($name)
	{
		// Names with a meaning of their own, and the names of the user table's columns (so nothing that reads a name as a column can mistake one)
		return in_array(strtolower((string)$name), array('admin', 'administrator', 'rootadmin', 'userid', 'accountid', 'username', 'email', 'password', 'adminlevel', 'createdon', 'lastip', 'lastlogin'), true);
	}
	public static function create($register_username, $register_password, $register_password_confirm, $register_email, $seed_accountid = null, $remote_addr = null)
	{
		// Registers a user and a new account for it (always a new account: a user is never added to someone else's). Returns null on success or the
		// reason it was refused as a message (the web form shows it on an error page, the command line prints it). No web context is needed:
		// without a $remote_addr (the command line) nothing is logged to the server log and no welcome mail is sent.
		if(strlen($register_username) < 4 || strpos($register_username, ' ') !== false)
		{
			return 'Please go back and ensure the supplied username is at least four characters long and contains no spaces.';
		}
		if(self::reserved_user_name($register_username))
		{
			return $register_username . ' is a reserved and common username that may be used for other purposes, please make a different selection.';
		}
		if(strlen($register_password) < 6)
		{
			return 'Please go back and ensure the supplied password is at least six characters long.';
		}
		if($register_password != $register_password_confirm)
		{
			return 'Please go back and ensure the supplied password matches the password confirmation.';
		}
		if($register_email == null || filter_var($register_email, FILTER_VALIDATE_EMAIL) == false)
		{
			return 'Please enter a valid email address.';
		}

		$valid_user_name_chars = '1234567890-_.abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
		for($i = 0; $i < strlen($register_username); $i++)
		{
			if(strpos($valid_user_name_chars, substr($register_username, $i, 1)) === false)
			{
				return 'Please go back and ensure a valid user-name. The character <em>' . substr($register_username, $i, 1) . '</em> is not allowed.';
			}
		}

		$db = phoromatic_server::$db;
		$new_account_dir = null;
		if(!phoromatic_server::tenancy_ready())
		{
			// The database upgrade is waiting (see the server log): an account made now would miss its role and its state
			return phoromatic_server_roles::UNAVAILABLE;
		}

		// All the checks and inserts in one transaction: nothing can be taken between the check and the insert, and a failure part-way
		// leaves no account without a user (or the other way round)
		if(@$db->exec('BEGIN IMMEDIATE') === false)
		{
			return self::failure('the database is busy or not writable (' . $db->lastErrorMsg() . ')', $remote_addr);
		}
		$matching_users = $db->querySingle('SELECT UserName FROM phoromatic_users WHERE UserName = \'' . SQLite3::escapeString($register_username) . '\'');
		if(!empty($matching_users))
		{
			$db->exec('ROLLBACK');
			return 'The user-name is already taken.';
		}

		$id_tries = 0;
		do
		{
			// The seed becomes the account's folder name and goes into SQL: six letters and digits, else a random id is used
			if($id_tries == 0 && is_string($seed_accountid) && preg_match('/^[A-Za-z0-9]{6}/', $seed_accountid) === 1)
			{
				$account_id = strtoupper(substr($seed_accountid, 0, 6));
			}
			else
			{
				$account_id = pts_strings::random_characters(6, true);
			}
			$matching_accounts = $db->querySingle('SELECT AccountID FROM phoromatic_accounts WHERE AccountID = \'' . $account_id . '\'');
			$id_tries++;
		}
		while(!empty($matching_accounts));

		$user_id = pts_strings::random_characters(4, true);
		$failed = null;
		// The first account on a server is its owner's: the level-1 user made with it is also a server administrator (what schema 59 gives
		// a server's only account). Counted before the insert, inside this transaction.
		$first_account = (int)$db->querySingle('SELECT COUNT(*) FROM phoromatic_accounts WHERE State IS NULL OR State <> \'deleted\'') === 0;
		$account_salt = pts_strings::random_characters(12, true);
		$stmt = $db->prepare('INSERT INTO phoromatic_accounts (AccountID, ValidateID, CreatedOn, Salt) VALUES (:account_id, :validate_id, :current_time, :salt)');
		if($stmt)
		{
			$stmt->bindValue(':account_id', $account_id);
			$stmt->bindValue(':validate_id', pts_strings::random_characters(4, true));
			$stmt->bindValue(':salt', $account_salt);
			$stmt->bindValue(':current_time', phoromatic_server::current_time());
			$failed = $stmt->execute() ? null : $db->lastErrorMsg();
		}
		else
		{
			$failed = $db->lastErrorMsg();
		}

		if($failed === null)
		{
			$stmt = $db->prepare('INSERT INTO phoromatic_account_settings (AccountID) VALUES (:account_id)');
			if($stmt)
			{
				$stmt->bindValue(':account_id', $account_id);
				$failed = $stmt->execute() ? null : $db->lastErrorMsg();
			}
			else
			{
				$failed = $db->lastErrorMsg();
			}
		}
		if($failed === null)
		{
			$stmt = $db->prepare('INSERT INTO phoromatic_user_settings (UserID, AccountID) VALUES (:user_id, :account_id)');
			if($stmt)
			{
				$stmt->bindValue(':user_id', $user_id);
				$stmt->bindValue(':account_id', $account_id);
				$failed = $stmt->execute() ? null : $db->lastErrorMsg();
			}
			else
			{
				$failed = $db->lastErrorMsg();
			}
		}

		if($failed === null)
		{
			$salted_password = hash('sha256', $account_salt . $register_password);
			$stmt = $db->prepare('INSERT INTO phoromatic_users (UserID, AccountID, UserName, Email, Password, CreatedOn, LastIP, AdminLevel) VALUES (:user_id, :account_id, :user_name, :email, :password, :current_time, :last_ip, :admin_level)');
			if($stmt)
			{
				$stmt->bindValue(':user_id', $user_id);
				$stmt->bindValue(':account_id', $account_id);
				$stmt->bindValue(':user_name', $register_username);
				$stmt->bindValue(':email', $register_email);
				$stmt->bindValue(':password', $salted_password);
				$stmt->bindValue(':last_ip', $remote_addr !== null ? $remote_addr : '127.0.0.1');
				$stmt->bindValue(':current_time', phoromatic_server::current_time());
				$stmt->bindValue(':admin_level', 1);
				$failed = $stmt->execute() ? null : $db->lastErrorMsg();
			}
			else
			{
				$failed = $db->lastErrorMsg();
			}
		}
		if($failed === null && $first_account)
		{
			if(!phoromatic_server_roles::insert($user_id, 'first-account') || !phoromatic_server_audit::record(null, $remote_addr !== null ? 'user' : 'cli', $register_username, 'server_role_granted', array('subject_type' => 'user', 'subject_id' => $user_id,
				'subject_label' => $register_username, 'detail' => array('role' => 'admin', 'rule' => 'the first account on the server'))))
			{
				$failed = 'the server administrator role could not be recorded (' . (phoromatic_server_audit::last_error() ?: $db->lastErrorMsg()) . ')';
			}
		}
		if($failed === null)
		{
			phoromatic_tenant_context::allow($account_id);
			$account_dir = phoromatic_server::phoromatic_account_path($account_id);
			if(!is_dir($account_dir))
			{
				pts_file_io::mkdir($account_dir);
				$new_account_dir = $account_dir;
				if(!is_dir($account_dir))
				{
					$failed = 'the folder ' . $account_dir . ' could not be created';
				}
			}
		}
		if($failed === null && !$db->exec('COMMIT'))
		{
			$failed = $db->lastErrorMsg();
		}
		if($failed !== null)
		{
			$db->exec('ROLLBACK');
			phoromatic_server_audit::discard();
			if($new_account_dir !== null && is_dir($new_account_dir))
			{
				@rmdir($new_account_dir);
			}
			return self::failure($failed, $remote_addr);
		}

		phoromatic_server_audit::flush();
		if($remote_addr !== null)
		{
			pts_logger::add_to_log($remote_addr . ' created a new account: ' . $user_id . ' - ' . $account_id);
		}

		if($remote_addr !== null)
		{
			phoromatic_server::send_email($register_email, 'Phoromatic Account Registration', (($e = phoromatic_server::read_setting('admin_support_email')) != null ? $e : 'no-reply@phoromatic.com'), '<p><strong>' . $register_username . '</strong>:</p><p>Your Phoromatic account has been created and is now active.</p>');
		}
		return null;
	}
	protected static function failure($detail, $remote_addr)
	{
		// The web form gets a plain message; the reason (database messages, paths) goes to the server log. The command line prints it.
		if($remote_addr !== null)
		{
			pts_logger::add_to_log($remote_addr . ' account creation failed: ' . $detail);
			return 'The account could not be created. Nothing was saved. The reason is in the server log.';
		}
		return 'The account could not be created (' . $detail . '). Nothing was saved.';
	}
	public static function account_count()
	{
		return (int)phoromatic_server::$db->querySingle('SELECT COUNT(*) FROM phoromatic_accounts');
	}
	public static function registration_message()
	{
		// The text shown on the welcome page where the registration form used to be. There is no registration form any more (0.9.12): accounts
		// are made by the server's administrator, so this is always a message. It is the text an administrator set (server-registration closed
		// --message, or the Account Creation String on the Admin Config page) or the default; the empty text that "open" used to store counts
		// as the default.
		$message = phoromatic_server::read_setting('account_creation_alt');
		if(!is_string($message) || trim($message) === '')
		{
			return self::DEFAULT_CLOSED_MESSAGE;
		}
		return $message;
	}
	public static function first_account_hint()
	{
		// For the welcome page of a server that has no account yet and is closed
		return 'This server has no accounts yet. On the server, as the user that owns its database, create the first one with: benchy server-account-create --user NAME --email ADDRESS (it asks for the password). Further accounts are made the same way, or by the root administrator on the Admin page.';
	}
}

?>
