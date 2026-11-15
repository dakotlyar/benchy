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

class phoromatic_admin implements pts_webui_interface
{
	public static function page_title()
	{
		return 'Phoromatic Root Administrator';
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
		if($_SESSION['AdminLevel'] != -40)
		{
			header('Location: /?main');
		}
		$main = null;
		if(phoromatic_post_rejected())
		{
			// a change needs the CSRF token and a same-origin request; without them nothing below sees the submitted fields
			$_POST = array();
			$main = '<h2 style="color: red;">The form expired or was not submitted from this site; nothing was changed. Please try again.</h2>';
		}
		if(isset($_POST['email_all_subject']) && isset($_POST['email_all_message']) && !empty($_POST['email_all_message']))
		{
			phoromatic_quit_if_invalid_input_found(array('email_all_subject', 'email_all_message'));
			$stmt = phoromatic_server::$db->prepare('SELECT * FROM phoromatic_users ORDER BY UserName ASC');
			$result = $stmt->execute();

			while($row = $result->fetchArray())
			{
				$user = $row['UserName'];
				$email = $row['email'];
				phoromatic_server::send_email($email, $_POST['email_all_subject'], $_POST['email_all_reply_to'], $_POST['email_all_message']);
			}
			echo '<h2>Emails sent to all Phoromatic users.</h2>';
		}
		$main .= '<h1>Phoromatic Server Administration</h1>';

		$main .= '<hr /><h2>Server Information</h2>';
		$main .= '<p><strong>HTTP Server Port:</strong> ' . getenv('PTS_WEB_PORT') . '<br /><strong>WebSocket Server Port:</strong> ' . getenv('PTS_WEBSOCKET_PORT') . '<br /><strong>Phoromatic Server Path:</strong> ' . phoromatic_server::phoromatic_path() . '<br /><strong>Configuration File:</strong>: ' . pts_config::get_config_file_location() . '</p>';

		$main .= '<hr /><h2>Statistics</h2>';
		$stmt = phoromatic_server::$db->prepare('SELECT COUNT(Title) AS SystemCount FROM phoromatic_systems WHERE State >= 0');
		$result = $stmt->execute();
		$row = $result->fetchArray();
		$stats['Total System Count'] = $row['SystemCount'];
		$stmt = phoromatic_server::$db->prepare('SELECT COUNT(Title) AS ScheduleCount FROM phoromatic_schedules WHERE State >= 1');
		$result = $stmt->execute();
		$row = $result->fetchArray();
		$stats['Total Schedule Count'] = $row['ScheduleCount'];
		$stmt = phoromatic_server::$db->prepare('SELECT COUNT(UploadID) AS ResultCount FROM phoromatic_results');
		$result = $stmt->execute();
		$row = $result->fetchArray();
		$stats['Total Result Count'] = $row['ResultCount'];
		$stmt = phoromatic_server::$db->prepare('SELECT COUNT(ActivityTime) AS ActivityCount FROM phoromatic_activity_stream');
		$stmt->bindValue(':today_date', date('Y-m-d') . '%');
		$result = $stmt->execute();
		$row = $result->fetchArray();
		$stats['Total Activity Count'] = $row['ActivityCount'];

		$main .= '<p>';
		foreach($stats as $what => $c)
			$main .= '<strong>' . $what . ':</strong> ' . $c . '<br />';


		$main .= '<hr /><h2>Account Topology</h2>';
		$stmt = phoromatic_server::$db->prepare('SELECT * FROM phoromatic_users ORDER BY AccountID,AdminLevel ASC');
		$result = $stmt->execute();

		$plevel = -1;
		$user_list = array();
		while($row = $result->fetchArray())
		{
			switch($row['AdminLevel'])
			{
				case 1:
					$level = 'Group Administrator';
					$offset = null;
					break;
				case 2:
					$level = 'Administrator';
					$offset = str_repeat('-', 10);
					break;
				case 3:
					$level = 'Power User';
					$offset = str_repeat('-', 20);
					break;
				case 10:
					$level = 'Viewer';
					$offset = str_repeat('-', 30);
					break;
				default:
					if($row['AdminLevel'] < 1)
						$level = 'Disabled';
					else
						$level = 'Unknown';

					$offset = null;
					break;
			}

			if($row['AdminLevel'] == 1)
			{
				if($plevel != -1)
					$main .= '</p>';
				$main .= '<p>';
			}

			$main .= $offset . ' <strong>' . htmlspecialchars((string)$row['UserName']) . '</strong> (<em>' . $level . '</em>) <strong>Created On:</strong> ' . phoromatic_server::user_friendly_timedate($row['CreatedOn']) . ' <strong>Last Log-In:</strong> ' . ($row['LastLogin'] != null ? phoromatic_server::user_friendly_timedate($row['LastLogin']) : 'N/A') . ($row['AdminLevel'] == 1 ? ' [<strong>ACCOUNT ID:</strong> ' . htmlspecialchars((string)$row['AccountID']) . ']' : null) . '<br />';
			$plevel = $row['AdminLevel'];
			$user_list[$row['UserName']] = $row['AdminLevel'];
		}
		if($plevel != -1)
			$main .= '</p>';

		$main .= '<hr /><h2>Users</h2><p>Turning a user off and setting a new password are on the <a href="?server_users">Server</a> pages (Users), where a server administrator can do them too, and each is recorded in the server audit.</p>';

		$main .= '<hr /><h2>Accounts</h2><p>Accounts are created, renamed, suspended, deleted and purged on the <a href="?server_tenants">Server</a> pages.</p>';

		//
		$server_log_file = getenv('PTS_PHOROMATIC_LOG_LOCATION');
		$server_log = is_string($server_log_file) && is_file($server_log_file) ? explode(PHP_EOL, file_get_contents($server_log_file)) : array();
		foreach($server_log as $i => $line_item)
		{
			if(strpos($line_item, '[200]') !== false || strpos($line_item, '[302]') !== false)
			{
				unset($server_log[$i]);
			}
		}
		$server_log = implode(PHP_EOL, $server_log);

		$main .= '<hr /><h2>Phoromatic Server Log</h2>';
		$main .= '<p><textarea style="width: 80%; height: 400px;">' . htmlspecialchars($server_log, ENT_QUOTES | ENT_SUBSTITUTE) . '</textarea></p>';

		$main .= '<hr /><h2>Email All Users</h2>';
		$main .= '<form name="email_all" id="email_all" action="?admin" method="post">' . write_token_in_form() . '
		<h3>Reply-To Email Address:</h3>
		<p><input type="text" name="email_all_reply_to" /></p>
		<h3>Subject:</h3>
		<p><input type="text" name="email_all_subject" /></p>
		<h3>Message:</h3>
		<p> <textarea rows="4" cols="50" name="email_all_message"></textarea></p>
		<p><input type="submit" value="Send Email" /></p>
		</form>';

		echo phoromatic_webui_header_logged_in();
		echo phoromatic_webui_main($main, phoromatic_webui_right_panel_logged_in());
		echo phoromatic_webui_footer();
	}
}

?>
