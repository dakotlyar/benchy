<?php

/*
	Phoronix Test Suite
	URLs: http://www.phoronix.com, http://www.phoronix-test-suite.com/
	Copyright (C) 2008 - 2022, Phoronix Media
	Copyright (C) 2008 - 2022, Michael Larabel

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

class phoromatic_settings implements pts_webui_interface
{
	public static function page_title()
	{
		return 'Settings';
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
			echo phoromatic_webui_header_logged_in();

			// The server-wide forms moved to ?server_settings in 0.9.13. For one release this page still takes their posts (an open tab, a
			// script), through the same handlers with the same checks, and says where the forms are now. A server administrator only (each
			// handler re-checks the role); the account forms below follow the policy that was just set.
			$moved_message = phoromatic_server_policy::process_post() . phoromatic_tls_web::process_post() . phoromatic_native_admin::settings_post();
			if($moved_message != '' && phoromatic_auth::is_server_admin())
			{
				$moved_message .= '<p>These settings are now on the <a href="?server_settings">Server settings</a> page.</p>';
			}

			// The user, account and environment-variable forms: a change needs the CSRF token and a same-origin request. Without them the
			// submitted fields are dropped before anything reads them, so nothing is saved.
			$form_rejected = '';
			if($_SERVER['REQUEST_METHOD'] == 'POST' && (isset($_POST['user_settings_update']) || isset($_POST['account_settings_update']) || isset($_POST['env_var_update'])) && (!phoromatic_private_repo_web::origin_ok() || !verify_submission_token()))
			{
				unset($_POST['user_settings_update'], $_POST['account_settings_update'], $_POST['env_var_update']);
				$form_rejected = '<p><strong>The form expired or was not submitted from this site; nothing was changed. Please try again.</strong></p>';
			}

			$h = function($text) { return htmlspecialchars((string)$text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); };
			$can_change = !PHOROMATIC_USER_IS_VIEWER;
			// An account-level change that decides who joins or what every client runs follows the pattern of the other audited settings: the
			// transaction opens, the audit row is written, the change is made, and only then does it commit. When the row (or its log line)
			// cannot be written, or the change itself fails, everything is rolled back, a line in the audit log file says so, and the page
			// shows a red message. $audit_begin returns true when the transaction is open and the row is written; the caller then ends it with
			// $audit_commit (true when the change stands) or $audit_fail (rolls back).
			$audit_notices = '';
			$audit_begin = function($action, $subject_id, $label, $detail) use (&$audit_notices)
			{
				if(@phoromatic_server::$db->exec('BEGIN IMMEDIATE') === false)
				{
					$audit_notices .= '<p style="color: red;"><strong>' . htmlspecialchars($label . ' was not changed: the database is busy. Please try again.', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</strong></p>';
					return false;
				}
				if(phoromatic_admin_audit::record($_SESSION['AccountID'], 'user', isset($_SESSION['UserName']) ? $_SESSION['UserName'] : '?', $action, array('actor_detail' => 'UserID ' . (isset($_SESSION['UserID']) ? $_SESSION['UserID'] : '?'),
					'subject_type' => 'account_setting', 'subject_id' => $subject_id, 'subject_label' => $label, 'detail' => $detail)))
				{
					return true;
				}
				$why = phoromatic_admin_audit::last_error();
				@phoromatic_server::$db->exec('ROLLBACK');
				phoromatic_admin_audit::mirror_note($action . ' rolled back, nothing changed: ' . $why);
				$audit_notices .= '<p style="color: red;"><strong>' . htmlspecialchars($label . ' could not be recorded in the audit log (' . $why . '); that change was not made.', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</strong></p>';
				return false;
			};
			$audit_fail = function($action, $label, $why) use (&$audit_notices)
			{
				@phoromatic_server::$db->exec('ROLLBACK');
				phoromatic_admin_audit::mirror_note($action . ' rolled back, nothing changed: ' . $why);
				$audit_notices .= '<p style="color: red;"><strong>' . htmlspecialchars($label . ' was not changed: ' . $why . '.', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</strong></p>';
				return false;
			};
			$audit_commit = function($action, $label) use (&$audit_fail)
			{
				return @phoromatic_server::$db->exec('COMMIT') !== false || $audit_fail($action, $label, 'the audit record could not be committed');
			};

			$stmt = phoromatic_server::$db->prepare('SELECT GroupName FROM phoromatic_accounts WHERE AccountID = :account_id');
			$stmt->bindValue(':account_id', $_SESSION['AccountID']);
			$account_row = $stmt->execute()->fetchArray();
			$account_name = $account_row && trim((string)$account_row['GroupName']) != '' ? $account_row['GroupName'] : 'this account';

			$main = '<h1>Settings: ' . $h($account_name) . '</h1>' . $moved_message . $form_rejected . '<!--AUDIT_NOTICES-->
				<p>Your own notifications come first, then the options that apply to everyone in this account. Server-wide options live on a separate page for server administrators.</p>';

			// ---- Your notifications (this user's own row, every level)
			$stmt = phoromatic_server::$db->prepare('SELECT * FROM phoromatic_user_settings WHERE AccountID = :account_id AND UserID = :user_id');
			$stmt->bindValue(':account_id', $_SESSION['AccountID']);
			$stmt->bindValue(':user_id', $_SESSION['UserID']);
			$row = $stmt->execute()->fetchArray();

			$user_settings = array(
				'NotifyOnResultUploads' => array('Email me when a result is uploaded', ''),
				'NotifyOnWarnings' => array('Email me when a system reports a warning or error', ''),
				'NotifyOnNewSystems' => array('Email me when a new system asks to join', ''),
				'NotifyOnHungSystems' => array('Email me when a system seems stuck', 'No check-in while it should be running.')
				);
			$main .= '<hr /><h2 id="notifications">Your notifications</h2><p><em>Just for you.</em></p>';
			if(!phoromatic_server::mail_available())
			{
				$main .= '<p><strong>This server can\'t send email yet, so these have no effect until its administrator sets up mail.</strong></p>';
			}
			$main .= '<form name="system_form" id="user_settings_form" action="?settings" method="post">' . write_token_in_form() . '<p>';
			foreach($user_settings as $key => $setting)
			{
				if(isset($_POST['user_settings_update']))
				{
					$row[$key] = isset($_POST[$key]) && $_POST[$key] == 'yes' ? 1 : 0;

					$stmt = phoromatic_server::$db->prepare('UPDATE phoromatic_user_settings SET ' . $key . ' = :val WHERE AccountID = :account_id AND UserID = :user_id');
					$stmt->bindValue(':account_id', $_SESSION['AccountID']);
					$stmt->bindValue(':user_id', $_SESSION['UserID']);
					$stmt->bindValue(':val', $row[$key]);
					$stmt->execute();
				}

				$main .= '<label><input type="checkbox" name="' . $key . '" ' . (isset($row[$key]) && $row[$key] == 1 ? 'checked="checked" ' : '') . 'value="yes" /> ' . $setting[0] . '</label>' . ($setting[1] != '' ? '<br /><small>&nbsp;&nbsp;&nbsp;&nbsp;' . $setting[1] . '</small>' : '') . '<br />';
			}
			$main .= '</p><p><input type="hidden" value="1" name="user_settings_update" /><input type="submit" value="Save notifications" /></p></form>';

			// ---- This account (one form, one Save: unticked boxes post nothing, so the groups must never be separate forms)
			$stmt = phoromatic_server::$db->prepare('SELECT * FROM phoromatic_account_settings WHERE AccountID = :account_id');
			$stmt->bindValue(':account_id', $_SESSION['AccountID']);
			$row = $stmt->execute()->fetchArray();
			$auto_locked = phoromatic_server::tenant_auto_approve_forbidden();
			$ob_locked = phoromatic_server::client_phone_home_forbidden();
			$pts_only = ' <em>(PTS clients only)</em>';

			// array(key, label, help, tag, sub-option)
			$account_groups = array(
				'New systems' => array(
					array('AutoApproveNewSystems', 'Approve new systems automatically', 'When on, any machine that checks in with this account\'s ID joins without waiting. Systems enrolled with a code are always approved. Otherwise each new system waits on the Systems page until someone approves it.', '', false)
					),
				'Running tests' => array(
					array('RunInstallCommand', 'Install tests before each scheduled run', '', '', false),
					array('ForceInstallTests', 'Reinstall from scratch each time', 'Only applies when the option above is on. Slower; use it when installs may be stale.', '', true),
					array('PreSeedTestInstalls', 'Pre-install common tests while idle', '', $pts_only, false)
					),
				'Results and logs' => array(
					array('ArchiveResultsLocally', 'Keep a copy of each result on the system', 'Off: the system deletes its copy once the server has it.', '', false),
					array('ProgressiveResultUploads', 'Send results after each test', 'You can watch a run\'s results as they arrive instead of waiting for the end.', '', false),
					array('UploadSystemLogs', 'Send system logs with each result', '', $pts_only, false),
					array('UploadInstallLogs', 'Send install logs with each result', '', '', false),
					array('UploadRunLogs', 'Send test run logs with each result', '', '', false),
					array('UploadResultsToOpenBenchmarking', 'Also upload results to OpenBenchmarking.org (public)', 'Results also go to OpenBenchmarking.org, where anyone can see them.', '', false)
					),
				'Power and network' => array(
					array('PowerOffWhenDone', 'Power systems off when the day\'s tests are done', '', $pts_only, false),
					array('NetworkPowerUpWhenNeeded', 'Wake systems over the network before a scheduled run', 'Needs Wake-on-LAN on the system.', '', true),
					array('PowerOnSystemDaily', 'Wake idle systems once a day', 'Keeps their details and network address fresh; they power off again if the power-off option above is on.', '', true),
					array('LimitNetworkCommunication', 'Reduce network traffic (large fleets)', 'Systems check in less often and stop live status updates. Use it only if the server is overloaded.', '', false)
					)
				);

			$env_vars_show = !empty($row['GlobalEnvironmentVariables']) ? pts_env::phoromatic_only(pts_strings::parse_value_string_vars($row['GlobalEnvironmentVariables'])) : array();
			$settings_updated = false;
			if($can_change)
			{
				// Same columns, same rules as before: a locked option is not read from the form (its stored value stays), nothing is written for
				// a viewer, and one activity row says "settings modified"
				foreach($account_groups as $section_settings)
				{
					foreach($section_settings as $opt)
					{
						$key = $opt[0];
						if(($key == 'AutoApproveNewSystems' && $auto_locked) || ($key == 'UploadResultsToOpenBenchmarking' && $ob_locked))
						{
							continue;
						}
						if(isset($_POST['account_settings_update']))
						{
							$new_value = isset($_POST[$key]) && $_POST[$key] == 'yes' ? 1 : 0;
							$audited = $key == 'AutoApproveNewSystems' && (int)(isset($row[$key]) ? $row[$key] : 0) !== $new_value;
							if($audited && !$audit_begin('account_setting_set', $key, 'Approve new systems automatically', array('before' => (int)(isset($row[$key]) ? $row[$key] : 0), 'after' => $new_value)))
							{
								continue;
							}

							$stmt = phoromatic_server::$db->prepare('UPDATE phoromatic_account_settings SET ' . $key . ' = :val WHERE AccountID = :account_id');
							$stmt->bindValue(':account_id', $_SESSION['AccountID']);
							$stmt->bindValue(':val', $new_value);
							$written = @$stmt->execute() !== false;
							if($audited && (!$written ? !$audit_fail('account_setting_set', 'Approve new systems automatically', 'the setting could not be written') : !$audit_commit('account_setting_set', 'Approve new systems automatically')))
							{
								continue;
							}
							$row[$key] = $new_value;

							if($settings_updated == false)
							{
								phoromatic_add_activity_stream_event('settings', null, 'modified');
								$settings_updated = true;
							}
						}
					}
				}

				// A value with a ';' would start another variable in the stored string (a level 3 user could add one that is not offered here), so it
				// is refused as a whole, not cut
				$env_rejected = isset($_POST['env_var_update']) ? pts_env::rejected_posted_options('phoromatic') : array();
				if(!empty($env_rejected))
				{
					$audit_notices .= '<p style="color: red;"><strong>Test options for every run were not changed: a value cannot contain a semicolon (' . $h(implode(', ', $env_rejected)) . ').</strong></p>';
				}
				if(isset($_POST['env_var_update']) && empty($env_rejected))
				{
					$env_before = $env_vars_show;
					$env_vars_show = array();
					$env_vars = array();
					foreach(pts_env::get_posted_options('phoromatic') as $ei => $ev)
					{
						array_push($env_vars, $ei . '=' . $ev);
						$env_vars_show[$ei] = $ev;
					}
					$env_vars = implode(';', $env_vars);
					// Which variables changed (names only: the values can be paths and the like)
					$env_changed = array();
					foreach(array_unique(array_merge(array_keys($env_before), array_keys($env_vars_show))) as $ei)
					{
						if((isset($env_before[$ei]) ? $env_before[$ei] : null) !== (isset($env_vars_show[$ei]) ? $env_vars_show[$ei] : null))
						{
							$env_changed[] = $ei;
						}
					}
					$env_open = false;
					if(!empty($env_changed))
					{
						if($audit_begin('account_env_set', 'GlobalEnvironmentVariables', 'Test options for every run', array('changed' => $env_changed, 'set_before' => count($env_before), 'set_after' => count($env_vars_show))))
						{
							$env_open = true;
						}
						else
						{
							$env_vars_show = $env_before;
							$env_vars = null;
						}
					}
					if($env_vars !== null)
					{
						$stmt = phoromatic_server::$db->prepare('UPDATE phoromatic_account_settings SET GlobalEnvironmentVariables = :val WHERE AccountID = :account_id');
						$stmt->bindValue(':account_id', $_SESSION['AccountID']);
						$stmt->bindValue(':val', $env_vars);
						$written = @$stmt->execute() !== false;
						if($env_open && (!$written ? !$audit_fail('account_env_set', 'Test options for every run', 'the options could not be written') : !$audit_commit('account_env_set', 'Test options for every run')))
						{
							$env_vars_show = $env_before;
						}
					}
				}
			}

			$env_total = count(pts_env::read_possible_vars('phoromatic'));
			$env_summary = 'Test options for every run (advanced, ' . $env_total . ' options, ' . count($env_vars_show) . ' set)';

			$main .= '<hr /><h2 id="account">This account</h2><p><em>' . ($can_change ? 'Applies to everyone in ' . $h($account_name) . '.' : 'Read-only. Applies to everyone in ' . $h($account_name) . '.') . '</em></p>';
			if(!$can_change)
			{
				$main .= '<p>Only Group Administrators, Administrators and Power Users can change these.</p>';
			}
			else
			{
				$main .= '<form name="system_form" id="account_settings_form" action="?settings" method="post">' . write_token_in_form();
			}
			foreach($account_groups as $group => $options)
			{
				$main .= '<h3>' . $group . '</h3>';
				foreach($options as $opt)
				{
					list($key, $label, $help, $tag, $sub) = $opt;
					$locked = ($key == 'AutoApproveNewSystems' && $auto_locked) || ($key == 'UploadResultsToOpenBenchmarking' && $ob_locked);
					$on = !$locked && isset($row[$key]) && $row[$key] == 1;
					if($locked)
					{
						$help = $key == 'AutoApproveNewSystems' ? 'Off. This server requires approval for every new system (server setting). Systems enrolled with a code are approved by the code.' :
							'Off. This server keeps clients from contacting OpenBenchmarking.org (server setting). Your saved choice is kept for if that changes.';
					}
					$indent = $sub ? 'margin-left: 2em; ' : '';
					if($can_change)
					{
						$main .= '<p style="' . $indent . 'margin-top: 0.4em; margin-bottom: 0.4em;"><label><input type="checkbox" name="' . $key . '" ' . ($on ? 'checked="checked" ' : '') . ($locked ? 'disabled="disabled" ' : '') . 'value="yes" /> ' . $label . '</label>' . $tag . ($locked ? ' <strong>(Locked by server)</strong>' : '') . ($help != '' ? '<br /><small>' . $help . '</small>' : '') . '</p>';
					}
					else
					{
						$main .= '<p style="' . $indent . 'margin-top: 0.4em; margin-bottom: 0.4em;">' . $label . ': <strong>' . ($on ? 'On' : 'Off') . '</strong>' . ($locked ? ' (server setting)' : '') . '</p>';
					}
				}
				if($group == 'Running tests')
				{
					if($can_change)
					{
						$main .= '<details><summary>' . $env_summary . '</summary><p>Applied to every schedule and benchmark ticket in this account; a schedule can override them. Needs clients running Phoronix Test Suite 10.8 or newer, which Benchy\'s own clients are.</p>' . pts_env::get_html_options('phoromatic', $env_vars_show) . '<input type="hidden" value="1" name="env_var_update" /></details>';
					}
					else
					{
						$set = array();
						foreach($env_vars_show as $ek => $ev)
						{
							// (the stored value was escaped when it was saved, so it must not be escaped twice)
							$set[] = '<code>' . $h($ek) . '</code> = ' . htmlspecialchars((string)$ev, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false);
						}
						$main .= '<p>Test options for every run: <strong>' . count($env_vars_show) . ' set</strong>' . (!empty($set) ? '<br />' . implode('<br />', $set) : '') . '</p>';
					}
				}
			}
			if($can_change)
			{
				$main .= '<p><input type="hidden" value="1" name="account_settings_update" /><input type="submit" value="Save account settings" /></p></form>';
			}

			// ---- Client update script: shell that every client of the account runs, so only AdminLevel 1-2 (read from the database now, not
			// from the session) may see or change it, and a save needs POST + the CSRF token + same-origin
			$main .= '<hr /><h2 id="script">Client update script</h2>';
			if(phoromatic_system_admin::admin_allowed())
			{
				$update_script_path = phoromatic_server::phoromatic_account_path($_SESSION['AccountID']) . 'client-update-script.sh';
				$update_script_message = '';
				if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['client_update_script']))
				{
					if(!is_string($_POST['client_update_script']) || !phoromatic_private_repo_web::origin_ok() || !verify_submission_token())
					{
						$update_script_message = '<p style="color: red;"><strong>The form expired or was not submitted from this site; the client update script was not changed. Please try again.</strong></p>';
					}
					else
					{
						$script_new = str_replace("\r\n", PHP_EOL, $_POST['client_update_script']);
						$script_old = is_file($update_script_path) ? (string)file_get_contents($update_script_path) : null;
						if($script_old === $script_new)
						{
							phoromatic_add_activity_stream_event('settings', null, 'modified');   // nothing to write or to record in the audit
						}
						else if($audit_begin('client_script_set', 'client-update-script.sh', 'Client update script', array('before_sha256' => $script_old === null ? null : hash('sha256', $script_old), 'after_sha256' => hash('sha256', $script_new), 'bytes' => strlen($script_new))))
						{
							// === false: an emptied script writes 0 bytes, which is a successful save
							if(file_put_contents($update_script_path, $script_new) === false)
							{
								$audit_fail('client_script_set', 'The client update script', 'the server could not write the file, so the saved script is unchanged');
							}
							else if(@phoromatic_server::$db->exec('COMMIT') === false)
							{
								// the audit row did not commit, so put the file back as it was, and say so only if that worked
								@phoromatic_server::$db->exec('ROLLBACK');
								$restored = $script_old === null ? (@unlink($update_script_path) || !is_file($update_script_path)) : @file_put_contents($update_script_path, $script_old) !== false;
								if($restored)
								{
									phoromatic_admin_audit::mirror_note('client_script_set rolled back, nothing changed: the audit record could not be committed');
									$audit_notices .= '<p style="color: red;"><strong>The client update script was not changed: the audit record could not be committed.</strong></p>';
								}
								else
								{
									phoromatic_admin_audit::mirror_note('client_script_set: the audit row was rolled back but the previous script could not be put back; the file on disk is the NEW script');
									$audit_notices .= '<p style="color: red;"><strong>The client update script is now the new text: the audit record could not be committed and the previous script could not be put back. Check it before the next client run.</strong></p>';
								}
							}
							else
							{
								phoromatic_add_activity_stream_event('settings', null, 'modified');
							}
						}
					}
				}

				if(!is_file($update_script_path))
				{
					$script_contents = pts_file_io::file_get_contents(PTS_CORE_STATIC_PATH . 'sample-pts-client-update-script.sh');
				}
				else
				{
					$script_contents = pts_file_io::file_get_contents($update_script_path);
				}

				$main .= '<form name="update_client_script_form" id="update_client_script_form" action="?settings" method="post">' . write_token_in_form() . '<p><em>Group Administrators and Administrators.</em></p>' . $update_script_message . '<p>Runs on every PTS client of this account after a test, when idle and before shutdown. If it updates Benchy it should reboot. It doesn\'t update the server. Native agents don\'t use it.</p>
				<p><textarea style="width: 80%; height: 400px;" name="client_update_script" id="client_update_script">' . $h($script_contents) . '</textarea></p>
				<p><input type="submit" value="Save script" /></p>
				</form>';
			}
			else
			{
				$main .= '<p>A Group Administrator or Administrator can set a script that keeps this account\'s clients updated.</p>';
			}

			// ---- Security and access: only the links this role can use
			$links = array('Your password' => '<a href="?password">Change it</a>');
			if(($feed_query = phoromatic_feed::query($_SESSION['UserID'], $_SESSION['AccountID'], $_SESSION['CreatedOn'])) !== '')
			{
				$links['Your results feed (RSS)'] = '<a href="/rss.php?' . $feed_query . '">Open the feed link</a> (personal to you; anyone with the link can read the feed)';
			}
			if(isset($_SESSION['AdminLevel']) && $_SESSION['AdminLevel'] >= 1 && $_SESSION['AdminLevel'] <= 2)
			{
				$links['Private repository tokens'] = '<a href="?private_repository">Private repository</a>';
			}
			if(isset($_SESSION['AdminLevel']) && $_SESSION['AdminLevel'] >= 1 && $_SESSION['AdminLevel'] <= 3)
			{
				$links['Bench tokens'] = '<a href="?benches">Benches</a>' . ($_SESSION['AdminLevel'] == 3 ? ' (view)' : '');
			}
			if(isset($_SESSION['AdminLevel']) && $_SESSION['AdminLevel'] >= 1 && $_SESSION['AdminLevel'] <= 2)
			{
				$links['Native enrollment codes'] = '<a href="?systems">Systems</a>';
			}
			$links['Download cache'] = '<a href="?caches">Caches</a>';
			$links['Recent account activity'] = '<a href="?account_activity">Account activity</a>';
			$main .= '<hr /><h2 id="security">Security and access</h2><p>';
			foreach($links as $name => $link)
			{
				$main .= $name . ' .......... ' . $link . '<br />';
			}
			$main .= '</p>';

			if(phoromatic_auth::is_server_admin())
			{
				$main .= '<hr /><h2>Server-wide settings</h2><p><em>You\'re a server administrator.</em></p>
				<p>HTTPS, OpenBenchmarking.org contact, the auto-approve policy and native agent connections affect every account on this server. <a href="?server_settings">Open Server settings</a></p>';
			}

			echo '<div id="pts_phoromatic_main_area">' . str_replace('<!--AUDIT_NOTICES-->', $audit_notices, $main) . '</div>';
			echo phoromatic_webui_footer();
	}
}

?>
