<?php

/*
	Phoronix Test Suite
	URLs: http://www.phoronix.com, http://www.phoronix-test-suite.com/
	Copyright (C) 2014 - 2022, Phoronix Media
	Copyright (C) 2014 - 2022, Michael Larabel

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

class phoromatic_users implements pts_webui_interface
{
	public static function page_title()
	{
		return 'Users';
	}
	public static function page_header()
	{
		return null;
	}
	public static function preload($PAGE)
	{
		return true;
	}
	public static function render_page_process($PATH)
	{
		if($_SESSION['AdminLevel'] > 3)
		{
			echo phoromatic_error_page('Unauthorized Access', 'You aren\'t an account administrator!');
			return;
		}

		// Every form on this page needs the session's token and a same-origin request (0.9.13: the origin check is new here)
		$post_ok = $_SERVER['REQUEST_METHOD'] === 'POST' && verify_submission_token() && phoromatic_private_repo_web::origin_ok();
		$rejected = '';
		if($_SERVER['REQUEST_METHOD'] === 'POST' && !$post_ok && (isset($_POST['group_name']) || isset($_POST['username']) || isset($_POST['update_user_levels'])))
		{
			$rejected = '<p style="color: red;"><strong>The form expired or was not submitted from this site; nothing was changed. Please try again.</strong></p>';
		}
		if(isset($_POST['group_name']) && $post_ok)
		{
			phoromatic_quit_if_invalid_input_found(array('group_name'));
			$stmt = phoromatic_server::$db->prepare('UPDATE phoromatic_accounts SET GroupName = :group_name WHERE AccountID = :account_id');
			$stmt->bindValue(':group_name', pts_strings::simple($_POST['group_name']));
			$stmt->bindValue(':account_id', $_SESSION['AccountID']);
			$result = $stmt->execute();
		}
		if(isset($_POST['username']) && isset($_POST['password']) && isset($_POST['confirm_password']) && isset($_POST['email']) && $post_ok)
		{
			phoromatic_quit_if_invalid_input_found(array('username', 'email'));
			// REGISTER NEW USER
			if(strlen($_POST['username']) < 4 || strpos($_POST['username'], ' ') !== false)
			{
				phoromatic_error_page('Oops!', 'Please go back and ensure the supplied username is at least four characters long and contains no spaces.');
				return false;
			}
			if(phoromatic_accounts::reserved_user_name($_POST['username']))
			{
				phoromatic_error_page('Oops!', $_POST['username'] . ' is a reserved and common username that may be used for other purposes, please make a different selection.');
				return false;
			}
			if(strlen($_POST['password']) < 6)
			{
				phoromatic_error_page('Oops!', 'Please go back and ensure the supplied password is at least six characters long.');
				return false;
			}
			if($_POST['password'] != $_POST['confirm_password'])
			{
				phoromatic_error_page('Oops!', 'Please go back and ensure the supplied password matches the password confirmation.');
				return false;
			}
			if($_POST['email'] == null || filter_var($_POST['email'], FILTER_VALIDATE_EMAIL) == false)
			{
				phoromatic_error_page('Oops!', 'Please enter a valid email address.');
				return false;
			}

			$valid_user_name_chars = '1234567890-_.abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
			for($i = 0; $i < strlen($_POST['username']); $i++)
			{
				if(strpos($valid_user_name_chars, substr($_POST['username'], $i, 1)) === false)
				{
					phoromatic_error_page('Oops!', 'Please go back and ensure a valid user-name. The character <em>' . substr($_POST['username'], $i, 1) . '</em> is not allowed.');
					return false;
				}
			}

			$matching_users = phoromatic_server::$db->querySingle('SELECT UserName FROM phoromatic_users WHERE UserName = \'' . SQLite3::escapeString($_POST['username']) . '\'');
			if(!empty($matching_users))
			{
				phoromatic_error_page('Oops!', 'The user-name is already taken.');
				return false;
			}

			// Only the levels the form offers this user: 2 for a group administrator, 3 for an administrator and up, 10 for a power user and up
			$may_create = array(2 => $_SESSION['AdminLevel'] == 1, 3 => $_SESSION['AdminLevel'] <= 2, 10 => $_SESSION['AdminLevel'] <= 3);
			if(!isset($_POST['admin_level']) || !is_string($_POST['admin_level']) || !in_array($_POST['admin_level'], array('2', '3', '10'), true) || !$may_create[(int)$_POST['admin_level']])
			{
				phoromatic_error_page('Oops!', 'Invalid administration level.');
				return false;
			}

			$stmt = phoromatic_server::$db->prepare('SELECT Salt FROM phoromatic_accounts WHERE AccountID = :account_id');
			$stmt->bindValue(':account_id', $_SESSION['AccountID']);
			$result = $stmt->execute();
			$row = $result->fetchArray();
			$account_salt = $row['Salt'];
			$user_id = pts_strings::random_characters(4, true);
			$salted_password = hash('sha256', $account_salt . $_POST['password']);


			// The audit row, the user and the user's settings row are one transaction: when the row (or its log line) cannot be written, or an
			// insert fails, nothing is created, a line in the audit log file says so, and the admin is told
			$actor_name = isset($_SESSION['UserName']) ? $_SESSION['UserName'] : '?';
			if(@phoromatic_server::$db->exec('BEGIN IMMEDIATE') === false)
			{
				phoromatic_error_page('Oops!', 'The database is busy; the user was not created. Please try again.');
				return false;
			}
			if(!phoromatic_admin_audit::record($_SESSION['AccountID'], 'user', $actor_name, 'user_created', array('actor_detail' => 'UserID ' . (isset($_SESSION['UserID']) ? $_SESSION['UserID'] : '?'),
				'subject_type' => 'user', 'subject_id' => $user_id, 'subject_label' => $_POST['username'], 'detail' => array('level' => (int)$_POST['admin_level']))))
			{
				$why = phoromatic_admin_audit::last_error();
				@phoromatic_server::$db->exec('ROLLBACK');
				phoromatic_admin_audit::mirror_note('user_created rolled back, nothing changed: ' . $why);
				phoromatic_error_page('Oops!', 'The user was not created: the change could not be recorded in the audit log (' . htmlspecialchars((string)$why) . ').');
				return false;
			}

			$stmt = phoromatic_server::$db->prepare('INSERT INTO phoromatic_users (UserID, AccountID, UserName, Email, Password, CreatedOn, LastIP, AdminLevel) VALUES (:user_id, :account_id, :user_name, :email, :password, :current_time, :last_ip, :admin_level)');
			$stmt->bindValue(':user_id', $user_id);
			$stmt->bindValue(':account_id', $_SESSION['AccountID']);
			$stmt->bindValue(':user_name', $_POST['username']);
			$stmt->bindValue(':email', $_POST['email']);
			$stmt->bindValue(':password', $salted_password);
			$stmt->bindValue(':last_ip', $_SERVER['REMOTE_ADDR']);
			$stmt->bindValue(':current_time', phoromatic_server::current_time());
			$stmt->bindValue(':admin_level', $_POST['admin_level']);
			$created = @$stmt->execute() !== false;

			if($created)
			{
				$stmt = phoromatic_server::$db->prepare('INSERT INTO phoromatic_user_settings (UserID, AccountID) VALUES (:user_id, :account_id)');
				$stmt->bindValue(':user_id', $user_id);
				$stmt->bindValue(':account_id', $_SESSION['AccountID']);
				$created = @$stmt->execute() !== false;
			}
			if(!$created || @phoromatic_server::$db->exec('COMMIT') === false)
			{
				@phoromatic_server::$db->exec('ROLLBACK');
				phoromatic_admin_audit::mirror_note('user_created rolled back, nothing changed: the user could not be written');
				phoromatic_error_page('Oops!', 'The user was not created: the database refused the change.');
				return false;
			}

			pts_logger::add_to_log($_SERVER['REMOTE_ADDR'] . ' created a new account: ' . $user_id . ' - ' . $_SESSION['AccountID']);
			phoromatic_add_activity_stream_event('users', $_POST['username'], 'added');
		}
		$role_notice = '';
		if($_SESSION['AdminLevel'] == 1 && isset($_POST['update_user_levels']) && $post_ok)
		{
			foreach(explode(',', $_POST['update_user_levels']) as $user_id)
			{
				if(isset($_POST['admin_level_' . $user_id]) && is_string($_POST['admin_level_' . $user_id]) && in_array($_POST['admin_level_' . $user_id], array('-1', '2', '3', '10'), true))
				{
					$old_level = phoromatic_server::$db->querySingle('SELECT AdminLevel FROM phoromatic_users WHERE AccountID = \'' . SQLite3::escapeString($_SESSION['AccountID']) . '\' AND UserID = \'' . SQLite3::escapeString($user_id) . '\'');
					$new_level = (int)$_POST['admin_level_' . $user_id];
					$user_name = phoromatic_server::$db->querySingle('SELECT UserName FROM phoromatic_users WHERE AccountID = \'' . SQLite3::escapeString($_SESSION['AccountID']) . '\' AND UserID = \'' . SQLite3::escapeString($user_id) . '\'');
					if($old_level === null || (int)$old_level === $new_level)
					{
						continue;   // not a user of this account, or no change: nothing to write or to record
					}
					// Same pattern as the other audited changes: the transaction opens, the change is recorded, the level is changed, and only then
					// does it commit; when any step fails everything is rolled back, a line in the audit log file says so, and the level stays as it was
					$level_label = htmlspecialchars((string)$user_name);
					if(@phoromatic_server::$db->exec('BEGIN IMMEDIATE') === false)
					{
						$role_notice .= '<p style="color: red;"><strong>The level change for ' . $level_label . ' was not made: the database is busy. Please try again.</strong></p>';
						continue;
					}
					if(!phoromatic_admin_audit::record($_SESSION['AccountID'], 'user', isset($_SESSION['UserName']) ? $_SESSION['UserName'] : '?', 'member_level_changed', array('actor_detail' => 'UserID ' . (isset($_SESSION['UserID']) ? $_SESSION['UserID'] : '?'),
						'subject_type' => 'user', 'subject_id' => $user_id, 'subject_label' => $user_name, 'detail' => array('before' => (int)$old_level, 'after' => $new_level))))
					{
						$why = phoromatic_admin_audit::last_error();
						@phoromatic_server::$db->exec('ROLLBACK');
						phoromatic_admin_audit::mirror_note('member_level_changed rolled back, nothing changed: ' . $why);
						$role_notice .= '<p style="color: red;"><strong>The level change for ' . $level_label . ' could not be recorded in the audit log; it was not made.</strong></p>';
						continue;
					}
					$stmt = phoromatic_server::$db->prepare('UPDATE phoromatic_users SET AdminLevel = :admin_level WHERE AccountID = :account_id AND UserID = :user_id');
					$stmt->bindValue(':admin_level', $new_level);
					$stmt->bindValue(':user_id', $user_id);
					$stmt->bindValue(':account_id', $_SESSION['AccountID']);
					if(@$stmt->execute() === false || @phoromatic_server::$db->exec('COMMIT') === false)
					{
						@phoromatic_server::$db->exec('ROLLBACK');
						phoromatic_admin_audit::mirror_note('member_level_changed rolled back, nothing changed: the level could not be written');
						$role_notice .= '<p style="color: red;"><strong>The level change for ' . $level_label . ' was not made: the database refused the change.</strong></p>';
						continue;
					}
					if((int)$old_level !== (int)$_POST['admin_level_' . $user_id] && phoromatic_server_roles::has($user_id))
					{
						// A tenant level does not carry the server role either way, so say what a demotion or removal here leaves in place
						$role_notice .= '<p style="color: red;"><strong>' . htmlspecialchars((string)phoromatic_server::$db->querySingle('SELECT UserName FROM phoromatic_users WHERE UserID = \'' . SQLite3::escapeString($user_id) . '\'')) . ' is still a server administrator; this page does not change that. Revoke it on the server with: server-admin-revoke USERNAME</strong></p>';
					}
				}
			}
		}

		$main = $role_notice . $rejected . '<h2>Users</h2>
			<p>People who can sign in to this account, and what each level can do:</p>
			<ul>
				<li><strong>Group Administrator (level 1):</strong> everything in the account, including changing other users\' levels. Usually the account\'s first user.</li>
				<li><strong>Administrator (level 2):</strong> like a Group Administrator, except changing levels and creating other Administrators.</li>
				<li><strong>Power User (level 3):</strong> runs the account day to day (systems, schedules, settings) and can add Viewers. Can\'t delete or adopt systems, manage the private repository, bench tokens or native enrollment codes, or set the client update script.</li>
				<li><strong>Viewer (level 10):</strong> sees everything in the account and changes only their own notifications and password.</li>
			</ul>
			<p><strong>Server administrator</strong> is a role on the server, not a level here. Changing a level on this page doesn\'t add or remove it.</p>
			<div class="pts_phoromatic_info_box_area">

				<div style="margin: 0 1%;"><form action="' . htmlspecialchars($_SERVER['REQUEST_URI'], ENT_QUOTES) . '" name="edit_user" id="edit_user" method="post">
					<ul>
						<li><h1>All Users</h1></li>';

					$stmt = phoromatic_server::$db->prepare('SELECT * FROM phoromatic_users WHERE AccountID = :account_id ORDER BY UserName ASC');
					$stmt->bindValue(':account_id', $_SESSION['AccountID']);
					$result = $stmt->execute();
					$row = $result->fetchArray();
					$user_ids = array();

					do
					{
						switch($row['AdminLevel'])
						{
							case 1:
								$level = 'Group Administrator';
								break;
							case 2:
								$level = 'Administrator';
								break;
							case 3:
								$level = 'Power User';
								break;
							case 10:
								$level = 'Viewer';
								break;
							default:
								if($row['AdminLevel'] < 1)
									$level = 'Disabled';
								else
									$level = 'Unknown';
								break;
						}

						$main .= '<a href="#"><li>' . $row['UserName'] . '<br /><table><tr><td>';

						if($row['AdminLevel'] == 1 || $_SESSION['AdminLevel'] != 1)
							$main .= '<strong>' . $level . '</strong>';
						else
						{
							$main .= '<select name="admin_level_' . $row['UserID'] . '">';

							foreach(array(-1 => 'Disabled', 2 => 'Administrator', 3 => 'Power User', 10 => 'Viewer') as $level_id => $level_string)
							{
								$main .= '<option value="' . $level_id . '"' . ($row['AdminLevel'] == $level_id ? ' selected="selected"' : null) . '>' . $level_string . '</option>';
							}

							$main .= '</select>';
							array_push($user_ids, $row['UserID']);
						}
						$main .= '</td><td>Last Login: ' . (empty($row['LastLogin']) ? 'Never' : date('j F Y H:i', strtotime($row['LastLogin']))) . '</td></tr></table></li></a>';
					}
					while($row = $result->fetchArray());


			$main .= '</ul> &nbsp; <input type="hidden" name="update_user_levels" value="' . implode(',', $user_ids) . '" />' . write_token_in_form() . ' <input name="submit" value="Update User Levels" type="submit" /></form>
				</div>
			</div>';

		$main .= '<hr /><form action="' . htmlspecialchars($_SERVER['REQUEST_URI'], ENT_QUOTES) . '" name="add_user" id="add_user" method="post" onsubmit="return validate_new_user();"><h2>Add a user</h2>' . write_token_in_form() . '
			<p>A new user signs in to this account and works with its systems, schedules and results at the level you pick. You can add users up to your own level: Group Administrators add Administrators, Power Users and Viewers; Administrators add Power Users and Viewers; Power Users add Viewers.</p>
			<h3>User</h3>
			<p><input type="text" name="username" /></p>
			<h3>Password</h3>
			<p><input type="password" name="password" /></p>
			<h3>Confirm Password</h3>
			<p><input type="password" name="confirm_password" /></p>
			<h3>Email</h3>
			<p><input type="text" name="email" /></p>
			<h3>Administration Level</h3>
			<p><select name="admin_level">';

		if($_SESSION['AdminLevel'] == 1)
			$main .= '<option value="2">Administrator</option>';

		if($_SESSION['AdminLevel'] <= 2)
			$main .= '<option value="3">Power User</option>';
		if($_SESSION['AdminLevel'] <= 3)
			$main .= '<option value="10">Viewer</option>';

		$main .= '
			</select></p>
			<p><input name="submit" value="Add user" type="submit" /></p>
			</form>';

		$group_name = phoromatic_server::account_id_to_group_name($_SESSION['AccountID']);
		$main .= '<hr /><form action="' . htmlspecialchars($_SERVER['REQUEST_URI'], ENT_QUOTES) . '" name="group_name" id="group_name" method="post"><h2>Group Name</h2>' . write_token_in_form() . '
			<p>The group name is this account\'s display name. It is shown in the title of the Settings page.</p>
			<h3>Group Name</h3>
			<p><input type="text" name="group_name" value="' . htmlspecialchars((string)$group_name, ENT_QUOTES) . '" /></p>
			<p><input name="submit" value="Update Group Name" type="submit" /></p>
			</form>';

		echo phoromatic_webui_header_logged_in();
		echo '<div id="pts_phoromatic_main_area">' . $main . '</div>';
		echo phoromatic_webui_footer();
	}
}

?>
