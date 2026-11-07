<?php

/*
	Benchy Phoromatic accounts (0.9.8). This program is free software; you can redistribute it and/or modify it under the terms of the
	GNU General Public License as published by the Free Software Foundation; either version 3 of the License, or (at your option) any later version.
*/

class phoromatic_accounts
{
	const DEFAULT_CLOSED_MESSAGE = 'Account creation is closed on this server. Ask its administrator for an account.';

	public static function create($register_username, $register_password, $register_password_confirm, $register_email, $seed_accountid = null, $remote_addr = null)
	{
		// Registers a user (and, unless the server adds new users to one account, a new account for it). Returns null on success or the
		// reason it was refused as a message (the web form shows it on an error page, the command line prints it). No web context is needed:
		// without a $remote_addr (the command line) nothing is logged to the server log and no welcome mail is sent.
		if(strlen($register_username) < 4 || strpos($register_username, ' ') !== false)
		{
			return 'Please go back and ensure the supplied username is at least four characters long and contains no spaces.';
		}
		if(in_array(strtolower($register_username), array('admin', 'administrator', 'rootadmin')))
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

		$matching_users = phoromatic_server::$db->querySingle('SELECT UserName FROM phoromatic_users WHERE UserName = \'' . SQLite3::escapeString($register_username) . '\'');
		if(!empty($matching_users))
		{
			return 'The user-name is already taken.';
		}

		if(phoromatic_server::read_setting('add_new_users_to_account') != null)
		{
			$account_id = phoromatic_server::read_setting('add_new_users_to_account');
			$is_new_account = false;
		}
		else
		{
			$id_tries = 0;
			do
			{
				if($id_tries == 0 && $seed_accountid != null && isset($seed_accountid[5]))
				{
					$account_id = strtoupper(substr($seed_accountid, 0, 6));
				}
				else
				{
					$account_id = pts_strings::random_characters(6, true);
				}
				$matching_accounts = phoromatic_server::$db->querySingle('SELECT AccountID FROM phoromatic_accounts WHERE AccountID = \'' . $account_id . '\'');
				$id_tries++;
			}
			while(!empty($matching_accounts));
			$is_new_account = true;
		}

		$user_id = pts_strings::random_characters(4, true);
		$db = phoromatic_server::$db;
		$new_account_dir = null;

		// All the inserts or none: a failure part-way leaves no account without a user (or the other way round)
		if(!$db->exec('BEGIN IMMEDIATE'))
		{
			return 'The account could not be created: the database is busy or not writable (' . $db->lastErrorMsg() . ').';
		}
		$failed = null;
		if($is_new_account)
		{
			if($remote_addr !== null)
			{
				pts_logger::add_to_log($remote_addr . ' created a new account: ' . $user_id . ' - ' . $account_id);
			}
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
		}
		else
		{
			if($remote_addr !== null)
			{
				pts_logger::add_to_log($remote_addr . ' being added to an account: ' . $user_id . ' - ' . $account_id);
			}
			$account_salt = $db->querySingle('SELECT Salt FROM phoromatic_accounts WHERE AccountID = \'' . $account_id . '\'');
			if(empty($account_salt))
			{
				$failed = 'the account new users are added to (add_new_users_to_account) does not exist';
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
				$stmt->bindValue(':admin_level', ($is_new_account ? 1 : 10));
				$failed = $stmt->execute() ? null : $db->lastErrorMsg();
			}
			else
			{
				$failed = $db->lastErrorMsg();
			}
		}
		if($failed === null)
		{
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
			if($new_account_dir !== null && is_dir($new_account_dir))
			{
				@rmdir($new_account_dir);
			}
			return 'The account could not be created (' . $failed . '). Nothing was saved.';
		}

		if($remote_addr !== null)
		{
			phoromatic_server::send_email($register_email, 'Phoromatic Account Registration', (($e = phoromatic_server::read_setting('admin_support_email')) != null ? $e : 'no-reply@phoromatic.com'), '<p><strong>' . $register_username . '</strong>:</p><p>Your Phoromatic account has been created and is now active.</p>');
		}
		return null;
	}
	public static function account_count()
	{
		return (int)phoromatic_server::$db->querySingle('SELECT COUNT(*) FROM phoromatic_accounts');
	}
	public static function registration_message()
	{
		// The text shown instead of the registration form, or null when anyone can register
		$message = phoromatic_server::read_setting('account_creation_alt');
		return $message == null ? null : $message;
	}
}

?>
