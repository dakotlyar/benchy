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

class phoromatic_welcome implements pts_webui_interface
{
	public static function page_title()
	{
		return 'Welcome';
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
		// There is no public registration form: accounts are made by the server's administrator (benchy server-account-create on the server,
		// or the root login). Whatever is posted to ?register is ignored and the page below is the ordinary log-in page.
		$account_creation_string = phoromatic_accounts::registration_message();

		if(isset($_POST['username']) && isset($_POST['password']) && (!is_string($_POST['username']) || !is_string($_POST['password'])))
		{
			phoromatic_error_page('Invalid Information', 'The user-name or password did not match our records.');
			return false;
		}
		else if(isset($_POST['username']) && isset($_POST['password']) && ($login_wait = phoromatic_auth::login_wait_seconds()) > 0)
		{
			pts_logger::add_to_log($_SERVER['REMOTE_ADDR'] . ' is locked out of log-in after repeated failures');
			phoromatic_error_page('Too Many Failed Log-Ins', 'There have been too many failed log-in attempts from this address. Try again in about ' . ceil($login_wait / 60) . ' minute(s).');
			return false;
		}
		else if(isset($_POST['username']) && isset($_POST['password']) && strtolower($_POST['username']) == 'rootadmin')
		{
			phoromatic_quit_if_invalid_input_found(array('username'));
			$admin_pw = phoromatic_server::read_setting('root_admin_pw');
			if(empty($admin_pw))
			{
				echo phoromatic_webui_header(array('Action Required'), '');
				$box = '<h1>Root Admin Password Not Set</h1>
				<p>The root admin password has not yet been set for this system. It can be set by running on the system: <strong>benchy phoromatic.set-root-admin-password</strong>.</p>';
				echo phoromatic_webui_box($box);
				echo phoromatic_webui_footer();
				return false;
			}
			else if(!phoromatic_passwords::check_root($_POST['password']))
			{
				phoromatic_auth::login_failed('RootAdmin');
				pts_logger::add_to_log($_SERVER['REMOTE_ADDR'] . ' failed a root log-in attempt');
				phoromatic_error_page('Invalid Information', 'The user-name or password did not match our records.');
				return false;
			}
			else
			{
				session_regenerate_id();
				$_SESSION['UserID'] = 0;
				$_SESSION['UserName'] = 'RootAdmin';
				$_SESSION['AccountID'] = 0;
				$_SESSION['AdminLevel'] = -40;
				// The root session needs a CSRF token too, or no POST form on the admin pages (system / schedule / ticket removal) could ever verify
				$_SESSION['Token'] = sha1(bin2hex(random_bytes(32)) . PTS_CORE_VERSION . time());
				$_SESSION['CreatedOn'] = null;
				$_SESSION['CoreVersionOnSignOn'] = PTS_CORE_VERSION;
				session_write_close();
				header('Location: /?admin');
			}
		}
		else if(isset($_POST['username']) && isset($_POST['password']))
		{
			phoromatic_quit_if_invalid_input_found(array('username'));
			$matching_user = phoromatic_server::$db->querySingle('SELECT UserName, Password, AccountID, UserID, CreatedOn' . phoromatic_passwords::select_columns() . ' FROM phoromatic_users WHERE UserName = \'' . SQLite3::escapeString($_POST['username']) . '\'', true);
			if(!empty($matching_user))
			{
				$user_id = $matching_user['UserID'];
				$user = $matching_user['UserName'];
				$account_id = $matching_user['AccountID'];

				if($user == $_POST['username'])
				{
					$account_salt = phoromatic_server::$db->querySingle('SELECT Salt FROM phoromatic_accounts WHERE AccountID = \'' . SQLite3::escapeString($account_id) . '\'');
				}
				else
				{
					$account_salt = null;
				}

				// The password is checked before anything else is said about the account, so a wrong guess learns nothing about it
				// (the salt is the user's home account's, wherever the account they open turns out to be)
				if(phoromatic_passwords::check_user($matching_user, $account_salt, $_POST['password']))
				{
					list($open_account, $open_level, $note) = phoromatic_auth::resolve_session($matching_user);
					if($open_account === null)
					{
						pts_logger::add_to_log($_SERVER['REMOTE_ADDR'] . ' attempted to log-in to a ' . ($open_level === 'Account Suspended' ? 'suspended' : 'disabled or removed') . ' account: ' . $_POST['username']);
						phoromatic_error_page($open_level, $note);
						return false;
					}
					phoromatic_auth::open_session($matching_user, $open_account, $open_level, $account_salt);
					$account_id = $open_account;
					$stmt = phoromatic_server::$db->prepare('UPDATE phoromatic_users SET LastIP = :last_ip, LastLogin = :last_login WHERE UserID = :user_id');
					$stmt->bindValue(':last_ip', $_SERVER['REMOTE_ADDR']);
					$stmt->bindValue(':last_login', phoromatic_server::current_time());
					$stmt->bindValue(':user_id', (string)$user_id);
					$stmt->execute();
					session_write_close();

					phoromatic_tenant_context::set($account_id);
					pts_file_io::mkdir(phoromatic_server::phoromatic_account_path($account_id));
					pts_file_io::mkdir(phoromatic_server::phoromatic_account_result_path($account_id));
					pts_file_io::mkdir(phoromatic_server::phoromatic_account_system_path($account_id));
					pts_file_io::mkdir(phoromatic_server::phoromatic_account_suite_path($account_id));

					echo phoromatic_webui_header(array('Welcome, ' . $user), '');
					$box = '<h1>Log-In Successful</h1>
					<p><strong>' . $user . '</strong>, we are now redirecting you to your account portal. If you are not redirected within a few seconds, please <a href="?main">click here</a>.<script type="text/javascript">window.location.href = "?main";</script></p>';
					echo phoromatic_webui_box($box);
					echo phoromatic_webui_footer();
					pts_logger::add_to_log($_SERVER['REMOTE_ADDR'] . ' successfully logged in as user: ' . $user);
				}
				else
				{
					phoromatic_auth::login_failed($_POST['username']);
					pts_logger::add_to_log($_SERVER['REMOTE_ADDR'] . ' failed a log-in attempt as: ' . $_POST['username']);
					phoromatic_error_page('Invalid Information', 'The user-name or password did not match our records.');
					return false;
				}
			}
			else
			{
				phoromatic_passwords::waste_time();
				phoromatic_auth::login_failed($_POST['username']);
				pts_logger::add_to_log($_SERVER['REMOTE_ADDR'] . ' failed a log-in attempt as: ' . $_POST['username']);
				phoromatic_error_page('Invalid Information', 'The user-name or password did not match our records.');
				return false;
			}
		}
		else
		{
			echo phoromatic_webui_header(array(), '');

			$box = '<h1>Welcome</h1>
			<p>You must log in to your Phoromatic account to use this service. Accounts are made by the server\'s administrator.</p>
			<p>Phoromatic is the remote management and test orchestration system for <a href="https://github.com/dakotlyar/benchy">Benchy</a>. Phoromatic allows the automatic scheduling of tests, remote installation of new tests, and the management of multiple test systems over a network (LAN/WAN) all through an intuitive, easy-to-use web interface. Tests can be scheduled to automatically run on a routine basis across multiple test systems. The test results are then available from this centralized location along with other features for interacting with Benchy systems.</p>
			<p>Phoromatic makes it very easy to provide for automated scheduling of tests on multiple systems, is extremely extensible, allows various remote testing possibilities, makes it very trivial to manage multiple systems, and centralizes result management within an organization.</p>
			<p id="features_link"><a onclick="javascript:document.getElementById(\'features\').style.display = \'block\'; document.getElementById(\'features_link\').style.display = \'none\';">Learn more about Phoromatic</a>.</p>
			<div style="display: none;" id="features">

<h1>Phoromatic Benefits</h1>
<h3>Automated Scheduling</h3>
<p>Whether it be every evening at 6:00PM, once every Thursday at 10:00AM or somewhere in between, Phoromatic can schedule tests to be run at user-defined intervals. The testing schedules can be updated through Phoromatic web interface. After the test(s) have run, the results will be immediately uploaded to Phoromatic. Phoromatic also allows external &quot;triggers&quot; that can be initiated via a URL if wanting to automatically initiate testing when a Git commit occurs or some other external event.</p>
<h3>Extensible</h3>
<p>Any test profile or test suite that is compliant with Benchy specifications will work with Phoromatic. Phoromatic is able to leverage the hundreds of test profiles and dozens of test suites currently in Benchy via <a href="http://openbenchmarking.org/">OpenBenchmarking.org</a>, along with any custom/proprietary test profiles you or your company utilize.</p>
<h3>Remote Testing</h3>
<p>Once the test system is setup, all testing and management of that system can be done remotely. There is no need to excute Benchy commands locally using the GUI or command line version, but instead nearly all of the same features are accessible from the Phoromatic interface. The Phoromatic Server can also control waking systems via WoL when tests are issued, shutting down systems when idling, and other management tasks.</p>
<h3>Multi-System Support</h3>
<p>A single Phoromatic account is able to manage multiple test systems running Benchy. Phoromatic supports grouping together test systems, tagging, and other features to support effectively managing many test systems. From the Phoromatic interface, installed system hardware and software from a given system can also be viewed. Systems can be spread across a private LAN or spread across several locations via the Internet.</p>
<h3>Turn-Key Deployment</h3>
<p>No additional software needs to be installed to support Phoromatic; all that is needed is Benchy and its dependencies. New test systems can easily be synced with a given Phoromatic account by running a single command from the Benchy client.</p>
<p>The Phoromatic Server can be quickly and easily deployed with the only new presented dependency compared to Benchy clients is on PHP SQLite support. With modern versions of PHP-CLI, the Phoromatic Server is completely self-hosting for its web service.</p>
<p>Benchy client systems with Avahi / zero-conf networking support can automatically find Phoromatic Servers on their LAN for connecting to accounts, obtaining download caches, and utilizing other Phoromatic functionality.</p>
<h3>Result Management</h3>
<p>Test results are automatically uploaded to the Phoromatic account and remain private unless you opt to upload them to OpenBenchmarking.org for public reference. From the Phoromatic interface, results from multiple test systems can easily be compared and multiple results from the same systems can be used to track performance over time. There are also options to look at the statistical significance of the results and other features to efficienctly and effectively analyze the system performance.</p>
<h3>Multi-User Support</h3>
<p>If deploying a Phoromatic Server within an organization, there is also support for allowing multiple user accounts to be associated with the same data and other systems.</p>
<h3>Local Caching</h3>
<p>The Phoromatic Server automatically allows for caching of Benchy files and OpenBenchmarking.org test profile/suite caches. This allows for Benchy to be more easily deployed within organizations where the systems otherwise do not have Internet access for obtaining the necessary support files.</p>
<h3>Dashboard</h3>
<p>The Phoromatic dashboard allows viewing the state of all connected systems in one concise view from seeing their test state to hardware/software details, estimated time to completion, and other system information.</p>
<h3>E-Mail Notifications</h3>
<p>The Phoromatic Server is able to send out notifications of new results being available, systems that appear hung, immediate alerts of system errors, and other important information so that it can be dealt with in a timely manner (assuming your Phoromatic server has a working mail server).</p>
<h1>Getting Started</h1>
<p>Getting started can be as easy as <strong>benchy start-phoromatic-server</strong> to deploy a Phoromatic Server with HTTP access for the UI. Setting up client systems can be as simple as <strong>benchy phoromatic.connect</strong> and there are systemd files available for easy access to. <strong>More details can be found via the <a href="https://github.com/phoronix-test-suite/phoronix-test-suite/blob/master/documentation/phoronix-test-suite.md#phoronix-test-suite-phoromatic"><strong>Benchy documentation</strong></a>.</strong></p>
<p>The Benchy source-code is <a href="https://github.com/dakotlyar/benchy">hosted via GitHub</a>.</p>
			</div>
			<hr />
			<h1>Log-In</h1>
			<form name="login_form" id="login_form" action="?login" method="post" onsubmit="return phoromatic_login(this);">
			<ul class="r_form_wrapper">
				<li class="label_input_wrapper">
					<label for="u_username">Username</label>
					<input type="text" name="username" id="u_username" required/>
				</li>
				<li class="label_input_wrapper">
					<label for="u_password">Password</label>
					<input type="password" name="password" id="u_password" required/>
				</li>
				<li class="label_input_wrapper">
					<input type="submit" value="Submit" />
				</li>
				</ul>
			</form>
			<hr />
			<h1>Register</h1>';

			$box .= '<p>' . htmlspecialchars($account_creation_string) . '</p>';
			if(phoromatic_accounts::account_count() == 0)
			{
				$box .= '<p>' . htmlspecialchars(phoromatic_accounts::first_account_hint()) . '</p>';
			}
			$box .= '<hr />';

			echo phoromatic_webui_box($box);
			echo phoromatic_webui_footer();
		}
	}
}

?>
