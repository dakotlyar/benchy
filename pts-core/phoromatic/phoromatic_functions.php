<?php

/*
	Phoronix Test Suite
	URLs: http://www.phoronix.com, http://www.phoronix-test-suite.com/
	Copyright (C) 2013 - 2022, Phoronix Media
	Copyright (C) 2013 - 2022, Michael Larabel

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

define('PHOROMATIC_SERVER_WEB_INTERFACE', true);
define('PAGE_LOAD_START_TIME', microtime(true));

function phoromatic_quit_if_invalid_input_found($input_keys = null)
{
	if(empty($input_keys))
	{
		// Check them all if not being selective about what keys to check
		$input_keys = array_keys($_REQUEST);
	}
	// backup as to sanitization and stripping elsewhere, safeguard namely check for things like < for fields that shouldn't have it
	// plus a few simple backups as safeguards for words that really have no legit relevance within Phoromatic...

	foreach(pts_strings::safety_strings_to_reject() as $invalid_string)
	{
		foreach($input_keys as $key)
		{
			if(isset($_GET[$key]) && !empty($_GET[$key]))
			{
				foreach(pts_arrays::to_array($_GET[$key]) as $val_to_check)
				{
					if(stripos($val_to_check, $invalid_string) !== false)
					{
						echo '<strong>Exited due to invalid input ( ' . $invalid_string . ') attempted:</strong> ' . htmlspecialchars($val_to_check);
						exit;
					}
				}
			}
			if(isset($_POST[$key]) && !empty($_POST[$key]))
			{
				foreach(pts_arrays::to_array($_POST[$key]) as $val_to_check)
				{
					if(stripos($val_to_check, $invalid_string) !== false)
					{
						echo '<strong>Exited due to invalid input ( ' . $invalid_string . ') attempted:</strong> ' . htmlspecialchars($val_to_check);
						exit;
					}
				}
			}
		}
	}
}
function phoromatic_init_web_page_setup()
{
	if(session_save_path() == null || !is_writable(session_save_path()))
	{
		// This is needed since on at least EL6 by default there is no session_save_path set
		if(is_writable('/var/lib/php') && is_dir('/var/lib/php'))
		{
			session_save_path('/var/lib/php');
		}
		else if(is_writable('/var/lib/php5') && is_dir('/var/lib/php5'))
		{
			session_save_path('/var/lib/php5');
		}
		else if(is_writable('/tmp'))
		{
			session_save_path('/tmp');
		}
		else if(is_writable('.'))
		{
			session_save_path('.');
		}
	}

	define('PHOROMATIC_SERVER', true);
	if(defined('PTS_IS_DEV_BUILD') && PTS_IS_DEV_BUILD)
	{
		error_reporting(E_ALL);
	}
	// Lax keeps the session cookie off cross-site POSTs (CSRF defence in depth) while ordinary links into the UI still work
	session_set_cookie_params(array_merge(session_get_cookie_params(), array('samesite' => 'Lax', 'httponly' => true, 'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'))));
	session_start();

	define('PTS_MODE', 'WEB_CLIENT');
	define('PTS_AUTO_LOAD_OBJECTS', true);
	define('PHOROMATIC_USER_IS_VIEWER', !isset($_SESSION['AdminLevel']) || $_SESSION['AdminLevel'] >= 10 || $_SESSION['AdminLevel'] < 1 ? true : false);

	include('../../pts-core.php');
	pts_core::init();
	// The web interface never runs pts_client::init(), so seed bundled OpenBenchmarking indexes missing from the scratch path here
	pts_openbenchmarking::seed_bundled_indexes();

	if(isset($_SERVER['REQUEST_URI']) && !empty($_SERVER['REQUEST_URI']))
	{
		pts_strings::exit_if_contains_unsafe_data($_SERVER['REQUEST_URI']);
	}
}
function phoromatic_webui_header($left_items, $right = null)
{
	$ret = PHP_EOL . '<div id="pts_phoromatic_top_header">
	<ul>
	<li><a href="?"><img style="vertical-align: middle;" class="img_logo_pg" src="images/phoromatic_logo.svg" /></a>';

	if(isset($_SESSION['AdminLevel']) &&$_SESSION['AdminLevel'] > 0 && isset($_SESSION['AccountID']) && !empty($_SESSION['AccountID']))
	{
		$ret .= '<ul id="pts_phoromatic_info">';
		$ret .= '<li><a class="ph_date" href="#">' . date('H:i T - j F') . '</a></li>';
		$group_name = phoromatic_server::account_id_to_group_name($_SESSION['AccountID']);
		if($group_name != null)
		{
			$ret .= '<li><a href="#">' . htmlspecialchars($group_name) . '</a></li>';
		}
		$ret .= '</ul>';
	}
	$ret .= '</li>';

	//$ret .= '<ul>';
	foreach($left_items as $i => $item)
	{
		if(is_array($item))
		{
			$ret .= '<li>' . $i;

			if(!empty($item))
			{
				$ret .= '<ul>';
				foreach($item as $sub_item)
				{
					$ret .= '<li>' . $sub_item . '</li>';
				}
				$ret .= '</ul>';
			}
			$ret .= '</li>' . PHP_EOL;
		}
		else
		{
			$ret .= '<li>' . $item . '</li>' . PHP_EOL;
		}
	}
	$ret .= '<li><div id="phoromatic_result_selected_info_box"></div> <a href="#" onclick="javascript:phoromatic_generate_comparison(\'?result/\');"><div id="phoromatic_result_compare_info_box">Compare</div></a> <a href="#" onclick="javascript:phoromatic_delete_results(\'?results/delete/\', \'' . htmlspecialchars(isset($_SESSION['Token']) && is_string($_SESSION['Token']) ? $_SESSION['Token'] : '', ENT_QUOTES) . '\'); return false;"><div id="phoromatic_result_delete_box">Delete</div></a></li>';
	$ret .= '</ul>';

	// Light/dark mode toggle -- purely visual/client-side (see phoromatic.js),
	// shown on every page since they all render through this function.
	$theme_toggle = '<button type="button" id="phoromatic_theme_toggle" class="phoromatic_theme_toggle" onclick="phoromatic_toggle_theme();" aria-pressed="false">Dark Mode</button>';

	$ret .= '<div id="pts_phoromatic_top_header_right">' . $right . $theme_toggle . '</div>';

	$ret .=' </div>';

	return $ret;
}
function phoromatic_get_posted_var($name, $default_value = null)
{
	if(isset($_POST[$name]))
	{
		phoromatic_quit_if_invalid_input_found(array($name));
	}

	return isset($_POST[$name]) ? $_POST[$name] : null;
}
function phoromatic_webui_main($main, $right = null)
{
	return '<div id="pts_phoromatic_main">' . ($right != null ? '<div id="pts_phoromatic_menu_right">' . $right . '</div>' : null) . '<div id="pts_phoromatic_main_area">' . $main . '</div><div style="clear: both;"></div></div>';
}
function phoromatic_webui_box(&$box)
{
	return '<div id="pts_phoromatic_main_box"><div id="pts_phoromatic_main_box_inside">' . $box . '</div></div>';
}
function phoromatic_results_for_schedule($schedule_id, $limit_results = false)
{
	switch($limit_results)
	{
		case 'TODAY':
			$stmt = phoromatic_server::$db->prepare('SELECT COUNT(UploadID) As UploadCount FROM phoromatic_results WHERE AccountID = :account_id AND ScheduleID = :schedule_id AND UploadTime LIKE :today_date');
			$stmt->bindValue(':today_date', date('Y-m-d') . '%');
			break;
		default:
			$stmt = phoromatic_server::$db->prepare('SELECT COUNT(UploadID) As UploadCount FROM phoromatic_results WHERE AccountID = :account_id AND ScheduleID = :schedule_id');
			break;
	}

	$stmt->bindValue(':account_id', $_SESSION['AccountID']);
	$stmt->bindValue(':schedule_id', $schedule_id);
	$test_result_result = $stmt->execute();
	$row = $test_result_result->fetchArray();

	return empty($row) ? 0 : $row['UploadCount'];
}
function phoromatic_schedule_activeon_string($active_on, $active_at = null)
{
	if(!empty($active_on))
	{
		$active_days = explode(',', $active_on);
		$week = array('M', 'T', 'W', 'TH', 'F', 'S', 'SU');
		foreach($active_days as $i => &$day)
		{
			if(!isset($week[$day]))
			{
				unset($active_days[$i]);
			}
			else
			{
				$day = $week[$day];
			}
		}
		return implode(' ', $active_days) . (!empty($active_at) ? ' @ ' . str_replace('.', ':', $active_at) : null );
	}
}
function phoromatic_webui_footer()
{
	return '<div id="pts_phoromatic_bottom_footer">
	<div style="float: left; padding: 5px;"><svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" version="1.1" viewbox="0 0 76 41" width="76" height="41" preserveAspectRatio="xMinYMin meet">
  <path d="m74 22v9m-5-16v16m-5-28v28m-23-2h12.5c2.485281 0 4.5-2.014719 4.5-4.5s-2.014719-4.5-4.5-4.5h-8c-2.485281 0-4.5-2.014719-4.5-4.5s2.014719-4.5 4.5-4.5h12.5m-21 5h-11m11 13h-2c-4.970563 0-9-4.029437-9-9v-20m-24 40v-20c0-4.970563 4.0294373-9 9-9 4.970563 0 9 4.029437 9 9s-4.029437 9-9 9h-9" stroke="#696969" stroke-width="4" fill="none" />
</svg> &nbsp;</div>
<p style="margin: 6px 15px;"><strong>' . date('H:i T - j F Y') . '</strong>' . (PTS_IS_DEV_BUILD ? ' &nbsp; [' . round(microtime(true) - PAGE_LOAD_START_TIME, 2) . 's Page Load Time]' : null) . '<br />Copyright &copy; 2008 - ' . date('Y') . ' by <a href="http://www.phoronix-media.com/">Phoronix Media</a>. All rights reserved.<br />
All trademarks used are properties of their respective owners.<br />' . pts_core::program_title() . ' (PHP ' . PHP_VERSION . ')</p></div> <script type="text/javascript"> phoromatic_checkbox_toggle_result_comparison(\'\'); </script>';
}
function phoromatic_add_activity_stream_event($activity_event, $activity_event_id, $activity_event_type)
{
	$stmt = phoromatic_server::$db->prepare('INSERT INTO phoromatic_activity_stream (AccountID, ActivityTime, ActivityCreator, ActivityCreatorType, ActivityEvent, ActivityEventID, ActivityEventType) VALUES (:account_id, :activity_time, :activity_creator, :activity_creator_type, :activity_event, :activity_event_id, :activity_event_type)');
	$stmt->bindValue(':account_id', $_SESSION['AccountID']);
	$stmt->bindValue(':activity_time', phoromatic_server::current_time());
	$stmt->bindValue(':activity_creator', $_SESSION['UserName']);
	$stmt->bindValue(':activity_creator_type', 'USER');
	$stmt->bindValue(':activity_event', $activity_event);
	$stmt->bindValue(':activity_event_id', $activity_event_id);
	$stmt->bindValue(':activity_event_type', $activity_event_type);
	return $stmt->execute();
}
function phoromatic_tracker_page_relevant()
{
	$stmt = phoromatic_server::$db->prepare('SELECT RunTargetSystems, RunTargetGroups, (SELECT COUNT(*) FROM phoromatic_results WHERE AccountID = phoromatic_schedules.AccountID AND ScheduleID = phoromatic_schedules.ScheduleID) AS UploadedResultCount FROM phoromatic_schedules WHERE AccountID = :account_id AND State >= 1 ORDER BY Title ASC');
	$stmt->bindValue(':account_id', $_SESSION['AccountID']);
	$result = $stmt->execute();
	$row = $result->fetchArray();

	if($row)
	{
		do
		{
			if(is_numeric($row['RunTargetSystems']) && $row['UploadedResultCount'] > (((int)$row['RunTargetSystems'] + (int)$row['RunTargetGroups'] + 1) * 7))
			{
				return true;
			}
		}
		while($row = $result->fetchArray());
	}

	return false;
}
function phoromatic_webui_header_logged_in()
{
	$html_links = array();
	$pages = array();
	if($_SESSION['AdminLevel'] == -40)
	{
		$pages = array('Admin', 'Admin_Config', 'Admin_Data', 'Logout');
	}
	else if($_SESSION['AdminLevel'] > 0)
	{
		$sub_main_menu = array();
		$sub_tests_menu = array();
		$sub_systems_menu = array();
		$sub_testing_menu = array();
		$sub_results_menu = array();

		if(phoromatic_account_system_count() > 0)
		{
			$sub_systems_menu[] = 'Dashboard';
			$sub_systems_menu[] = 'Maintenance Table';
			$sub_systems_menu[] = 'Component Table';
			$sub_systems_menu[] = 'Benches';
		}

		//$sub_main_menu[] = '<a href="?tests">Test Profiles</a>';
		if(isset($_SESSION['AdminLevel']) && $_SESSION['AdminLevel'] < 4)
		{
			$sub_main_menu[] = 'Users';
		}

		array_push($sub_main_menu, 'Settings', '<a href="?account_activity">Account Activity</a>', 'Logout');
		$sub_testing_menu[] = '<a href="?schedules">Test Schedules</a>';

		if(isset($_SESSION['AdminLevel']) && $_SESSION['AdminLevel'] >= 1 && $_SESSION['AdminLevel'] <= 2)
		{
			// The page re-checks the level against the database on every request; this only decides whether to show the link
			$sub_tests_menu[] = '<a href="?private_repository">Private Repository</a>';
		}

		if(!PHOROMATIC_USER_IS_VIEWER)
		{
			if(phoromatic_server::read_setting('allow_test_profile_creation') == 1)
			{
				array_push($sub_tests_menu, '<a href="?create_test">Create New Test Profile</a>');
			}
			array_push($sub_tests_menu, '<a href="?build_suite">Build Test Suite</a>');
			array_push($sub_testing_menu, '<a href="?sched">Create A Schedule</a>', '<a href="?benchmark">Run A Benchmark</a>');
		}

		if(phoromatic_tracker_page_relevant())
		{
			$sub_results_menu[] = 'Tracker';
		}
		$sub_results_menu[] = '<a href="/rss.php?user=' . $_SESSION['UserID'] . '&amp;v=' . sha1($_SESSION['CreatedOn']) . '">Results Feed <img src="images/rss.svg" width="16" height="16" /></a>';

		$pages = array('Main' => $sub_main_menu, 'Systems' => $sub_systems_menu, 'Tests' => $sub_tests_menu, '<a href="/?testing">Testing</a>' => $sub_testing_menu, 'Results' => $sub_results_menu, '<form action="/?search" method="post" id="search"><input type="search" name="search" id="seach_input" size="16" /> <input type="submit" name="sa" value="Search" /><div class="search_expander"></div></form>');
	}

	foreach($pages as $title => $page)
	{
		if(is_array($page) || empty($page))
		{
			$menu_row = array();
			foreach($page as $sub_page)
			{
				$menu_row[] = menu_item_to_html($sub_page);
			}
			$html_links[menu_item_to_html($title)] = $menu_row;
		}
		else
		{
			$html_links[] = menu_item_to_html($page);
		}
	}

	return phoromatic_webui_header($html_links, null) . phoromatic_tls_web::banner();
}
function menu_item_to_html($page)
{
	if(strpos($page, '</') !== false)
		return $page;

	$page_link = strtolower($page);
	if(($x = strpos($page_link, '<br />')) !== false)
	{
		$page_link = trim(substr($page_link, $x + 6));
	}
	$page_link = str_replace(' ', '_', $page_link);

	if(strtolower($page) == PAGE_REQUEST)
	{
		return '<a href="?' . $page_link . '"><u>' . str_replace('_', ' ', $page) . '</u></a>';
	}
	else
	{
		return '<a href="?' . $page_link . '">' . str_replace('_', ' ', $page) . '</a>';
	}
}
function phoromatic_webui_right_panel_logged_in($add = null)
{
	$right = null;
	if($_SESSION['AdminLevel'] == -40)
	{
		$right .= '<h3>Phoromatic Server</h3><hr /><p><strong>' . date('H:i T - j F Y') . '</p>';
	}
	else if($_SESSION['AdminLevel'] > 0)
	{
		//$right .= '<a href="#" onclick="javascript:phoromatic_generate_comparison(\'?result/\');"><div id="phoromatic_result_compare_info_box"></div></a> <a href="#" onclick="javascript:phoromatic_delete_results(\'?results/delete/\'); return false;"><div id="phoromatic_result_delete_box">Delete Selected Results</div></a>';
		if(($bad_systems = phoromatic_server::systems_appearing_down()) != false)
		{
			$right .= '<ul><li><span class="alert">Systems Needing Attention</span></li>';
			foreach($bad_systems as $system)
			{
				$right .= '<li><a href="?systems/' . $system . '">' . htmlspecialchars(phoromatic_server::system_id_to_name($system)) . '</a></li>';
			}
			$right .= '</ul><hr />';
		}

		$right .= $add;

		if($add == null)
		{
			$recently_active_systems = phoromatic_server::recently_active_systems($_SESSION['AccountID']);
			if(!empty($recently_active_systems))
			{
				$right .= '<ul><li>Recently Active Systems</li>';

				foreach($recently_active_systems as &$row)
				{
					$right .= '<li><a href="?systems/' . htmlspecialchars($row['SystemID']) . '">' . htmlspecialchars($row['Title']) . '</a></li>';
				}

				$right .= '</ul><hr />';
			}

			$right .= '
				<ul>
					<li>Today\'s Scheduled Events</li>';

				$stmt = phoromatic_server::$db->prepare('SELECT Title, ScheduleID, RunAt FROM phoromatic_schedules WHERE AccountID = :account_id AND State >= 1  AND ActiveOn LIKE :active_on ORDER BY RunAt,Title ASC');
				$stmt->bindValue(':account_id', $_SESSION['AccountID']);
				$stmt->bindValue(':active_on', '%' . (date('N') - 1) . '%');
				$result = $stmt->execute();
				$row = $result->fetchArray();

				if($row == false)
				{
					$right .= '</ul><p style="text-align: left; margin: 6px 10px;">No Events Found</p>';
				}
				else
				{
					do
					{
						$right .= '<li>' . $row['RunAt'] . ' <a href="?schedules/' . $row['ScheduleID'] . '">' . $row['Title'] . '</a></li>';
					}
					while($row = $result->fetchArray());
					$right .= '</ul>';
				}
		}

		$system_count = phoromatic_account_system_count();
		$schedule_count = phoromatic_account_schedule_count();
		$stmt = phoromatic_server::$db->prepare('SELECT COUNT(UploadID) AS ResultCount FROM phoromatic_results WHERE AccountID = :account_id');
		$stmt->bindValue(':account_id', $_SESSION['AccountID']);
		$result = $stmt->execute();
		$row = $result->fetchArray();
		$result_count = $row['ResultCount'];
		$stmt = phoromatic_server::$db->prepare('SELECT COUNT(ActivityTime) AS ActivityCount FROM phoromatic_activity_stream WHERE AccountID = :account_id AND ActivityTime LIKE :today_date');
		$stmt->bindValue(':account_id', $_SESSION['AccountID']);
		$stmt->bindValue(':today_date', date('Y-m-d') . '%');
		$result = $stmt->execute();
		$row = $result->fetchArray();
		$activity_count = $row['ActivityCount'];

		$group_name = phoromatic_server::account_id_to_group_name($_SESSION['AccountID']);
		if($group_name != null)
		{
			$group_name = '<strong>' . htmlspecialchars($group_name) . '</strong><br />';
		}

		$right .= '<hr /><p><strong>' . date('H:i T - j F Y') . '</strong><br />' . $group_name . '<a href="?systems">' . $system_count . ' System' . ($system_count == 1 ? '' : 's') . '</a><br /><a href="?schedules">' . $schedule_count . ' Schedule' . ($schedule_count == 1 ? '' : 's') . '</a><br /><a href="?results">' . $result_count . ' Result' . ($result_count == 1 ? '' : 's') . '</a>';
		$right .= ' <a href="/rss.php?user=' . $_SESSION['UserID'] . '&amp;v=' . sha1($_SESSION['CreatedOn']) . '"><img src="images/rss.svg" width="16" height="16" /></a>';
		$right .= '<br /><a href="?account_activity">' . $activity_count . ' Activity Events Today</a></p>';
	}

	return $right;
}
function phoromatic_account_schedule_count()
{
	static $schedule_count = 0;

	if($schedule_count == 0)
	{
		$stmt = phoromatic_server::$db->prepare('SELECT COUNT(Title) AS ScheduleCount FROM phoromatic_schedules WHERE AccountID = :account_id AND State >= 1');
		$stmt->bindValue(':account_id', $_SESSION['AccountID']);
		$result = $stmt->execute();
		$row = $result->fetchArray();
		$schedule_count = $row['ScheduleCount'];
	}
	return $schedule_count;
}
function phoromatic_account_system_count()
{
	static $sys_count = 0;

	if($sys_count == 0)
	{
		$stmt = phoromatic_server::$db->prepare('SELECT COUNT(Title) AS SystemCount FROM phoromatic_systems WHERE AccountID = :account_id AND State >= 0');
		$stmt->bindValue(':account_id', $_SESSION['AccountID']);
		$result = $stmt->execute();
		$row = $result->fetchArray();
		$sys_count = $row['SystemCount'];
	}
	return $sys_count;
}
function phoromatic_web_socket_server_ip()
{
	$server_ip = $_SERVER['HTTP_HOST'];
	if(($x = strpos($server_ip, ':')) !== false)
	{
		$server_ip = substr($server_ip, 0, $x);
	}

	if($server_ip == 'localhost' || $server_ip == '0.0.0.0')
	{
		$local_ip = phodevi::read_property('network', 'ip');
		if($local_ip)
		{
			$server_ip = $local_ip;
		}
	}
	// getenv('PTS_WEBSOCKET_PORT')
	return $server_ip . ':' . $_SERVER['SERVER_PORT'];
}
function phoromatic_web_socket_server_addr()
{
	// With the built-in HTTPS on, the string clients are given is the https:// one (https://host:39443/ACCOUNT)
	if(phoromatic_tls::is_on())
	{
		return phoromatic_tls::public_base() . '/' . $_SESSION['AccountID'];
	}
	return phoromatic_web_socket_server_ip() . '/' . $_SESSION['AccountID'];
}
function phoromatic_error_page($title, $description)
{
	echo phoromatic_webui_header(array(''), '');
	$box = '<h1>' . $title . '</h1>
		<h2>' . $description . '</h2>';
	echo phoromatic_webui_box($box);
	echo phoromatic_webui_footer();
}
function phoromatic_systems_needing_attention()
{
	$main = null;
	$stmt = phoromatic_server::$db->prepare('SELECT Title, SystemID, State, LastIP, LocalIP, LastCommunication, NetworkMAC, AdoptionCandidate, (SELECT c.LastCommunication FROM phoromatic_systems c WHERE c.AccountID = phoromatic_systems.AccountID AND c.SystemID = phoromatic_systems.AdoptionCandidate AND c.DeletedOn IS NULL) AS CandidateLastCommunication, (SELECT c.Title FROM phoromatic_systems c WHERE c.AccountID = phoromatic_systems.AccountID AND c.SystemID = phoromatic_systems.AdoptionCandidate AND c.DeletedOn IS NULL) AS CandidateTitle FROM phoromatic_systems WHERE AccountID = :account_id AND State = 0 AND DeletedOn IS NULL ORDER BY LastCommunication DESC');
	$stmt->bindValue(':account_id', $_SESSION['AccountID']);
	$result = $stmt->execute();
	if($row = $result->fetchArray())
	{
		$main .= '<div class="pts_phoromatic_info_box_area"><div style="float: left; width: 100%;"><ul><li><h1>Systems Needing Attention</h1></li><li class="light" style="font-weight: normal;">The following systems have attempted to sync with this Phoromatic account but have not been validated. When clicking on them you are able to approve or disable them from your account along with editing the system information.</li>';

		do
		{
			$ip = $row['LocalIP'];
			if($row['LastIP'] != $row['LocalIP'])
			{
				$ip .= ' / ' . $row['LastIP'];
			}

			if($row['AdoptionCandidate'] !== null && $row['AdoptionCandidate'] !== '')
			{
				// Same MAC address as an existing system but a different machine id: an administrator (level 1-2) decides
				$h_id = htmlspecialchars($row['SystemID']);
				$main .= '<li><a href="?systems/' . $h_id . '/edit">' . htmlspecialchars($row['Title']) . '</a><br /><em><strong>IP:</strong> ' . htmlspecialchars($ip) . ' <strong>Last Communication:</strong> ' . htmlspecialchars($row['LastCommunication']) . '</em><br /><strong>Reports the same MAC address as ' . ($row['CandidateTitle'] !== null ? htmlspecialchars($row['CandidateTitle']) . ' (' . htmlspecialchars($row['AdoptionCandidate']) . ')' : 'a system that no longer exists') . '.</strong> Is this the same machine reinstalled, or a different one?';
				$main .= phoromatic_system_admin::adoption_buttons($row['SystemID'], $row['NetworkMAC'], $row['CandidateTitle'], $row['CandidateLastCommunication']);
				$main .= '</li>';
				continue;
			}

			$main .= '<a href="?systems/' . $row['SystemID'] . '/edit"><li>' . htmlspecialchars($row['Title']) . '<br /><em><strong>IP:</strong> ' . htmlspecialchars($ip) . ' <strong>Last Communication:</strong> ' . $row['LastCommunication'] . '</em></li></a>';
		}
		while($row = $result->fetchArray());

		$main .= '</ul></div></div>';
	}

	// Benches on hold (no component, awaiting a swap, the class under test changed) or with a constant-part notice
	$bench_items = '';
	foreach(phoromatic_bench::attention_items($_SESSION['AccountID']) as $item)
	{
		$b = $item['bench'];
		$link = '?benches/' . (int)$b['BenchID'];
		$name = phoromatic_bench::h($b['Title']);
		$sys = $item['system'] ? phoromatic_bench::h($item['system']['Title']) : '';
		switch($item['kind'])
		{
			case 'no_session':
				$bench_items .= '<li><a href="' . $link . '">' . $name . '</a><br /><strong>Needs a component:</strong> no open session. Tests on hold.</li>';
				break;
			case 'awaiting':
				$bench_items .= '<li><a href="' . $link . '">' . $name . '</a><br /><strong>Awaiting swap</strong> on ' . $sys . ' (S' . (int)$item['session']['SessionID'] . ', ' . phoromatic_bench::h($item['session']['Label']) . '). Tests on hold.</li>';
				break;
			case 'drift':
				$f = $item['drift']['fields'][0];
				foreach($item['drift']['fields'] as $field)
				{
					$f = !empty($field['holds']) ? $field : $f;
				}
				$bench_items .= '<li><a href="' . $link . '/ack/' . phoromatic_bench::h($item['system']['SystemID']) . '">' . $name . '</a><br /><strong>' . phoromatic_bench::h(phoromatic_bench::class_name($f['key'])) . ' changed:</strong> ' . phoromatic_bench::h($f['baseline']) . ' &rarr; ' . phoromatic_bench::h($f['detected']) . ' on ' . $sys . '. Tests on hold.</li>';
				break;
			case 'notice':
				$parts = array();
				foreach(phoromatic_bench::notice_fields($item['drift']) as $f)
				{
					$parts[] = phoromatic_bench::h(phoromatic_bench::class_name($f['key']));
				}
				$bench_items .= '<li><a href="' . $link . '">' . $name . '</a><br /><strong>Constant part changed:</strong> ' . implode(', ', $parts) . ' on ' . $sys . '. Tests keep running; results marked.</li>';
				break;
		}
	}
	if($bench_items != '')
	{
		$main .= '<div class="pts_phoromatic_info_box_area bench_attention"><div style="float: left; width: 100%;"><ul><li><h1>Benches Needing Attention</h1></li>' . $bench_items . '</ul></div></div>';
	}

	return $main;
}
function phoromatic_oldest_result_for_schedule($schedule_id)
{
	static $old_time;

	if(!isset($old_time[$schedule_id]))
	{
		$stmt = phoromatic_server::$db->prepare('SELECT UploadTime FROM phoromatic_results WHERE AccountID = :account_id AND ScheduleID = :schedule_id ORDER BY UploadTime ASC LIMIT 1');
		$stmt->bindValue(':account_id', $_SESSION['AccountID']);
		$stmt->bindValue(':schedule_id', $schedule_id);
		$result = $stmt->execute();
		$row = $result->fetchArray();
		$old_time[$schedule_id] = $row['UploadTime'];
	}

	return $old_time[$schedule_id];
}
function phoromatic_task_html($current_task)
{
	// CurrentTask is stored already HTML-escaped (pts_strings::sanitize), so keep existing entities instead of escaping them twice;
	// anything stored raw still gets escaped
	return htmlspecialchars((string)$current_task, ENT_QUOTES, 'UTF-8', false);
}
function write_token_in_form()
{
	return '<input type="hidden" name="token_submit" value="' . (isset($_SESSION['Token']) && is_string($_SESSION['Token']) ? $_SESSION['Token'] : '') . '" />';
}
function append_token_to_url($prefix = '/')
{
	return $prefix . '&token_submit=' . (isset($_SESSION['Token']) && is_string($_SESSION['Token']) ? $_SESSION['Token'] : '');
}
function verify_submission_token()
{
	return isset($_SESSION['Token']) && is_string($_SESSION['Token']) && $_SESSION['Token'] !== '' && isset($_REQUEST['token_submit']) && is_string($_REQUEST['token_submit']) && hash_equals($_SESSION['Token'], $_REQUEST['token_submit']);
}
function phoromatic_post_rejected()
{
	// A POST that carries fields but fails the same-origin check or the CSRF token (for pages whose forms all carry write_token_in_form())
	return $_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST) && (!phoromatic_private_repo_web::origin_ok() || !verify_submission_token());
}
function create_new_phoromatic_account($register_username, $register_password, $register_password_confirm, $register_email, $seed_accountid = null)
{
	// REGISTER NEW USER (the checks and the inserts are in phoromatic_accounts::create(), which the command line uses too)
	$error = phoromatic_accounts::create($register_username, $register_password, $register_password_confirm, $register_email, $seed_accountid, $_SERVER['REMOTE_ADDR']);
	if($error !== null)
	{
		phoromatic_error_page('Oops!', $error);
		return false;
	}
	return true;
}

?>
