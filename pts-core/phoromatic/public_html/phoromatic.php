<?php

/*
	Phoronix Test Suite
	URLs: http://www.phoronix.com, http://www.phoronix-test-suite.com/
	Copyright (C) 2009 - 2021, Phoronix Media
	Copyright (C) 2009 - 2021, Michael Larabel

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

// INIT
define('PHOROMATIC_SERVER', true);
//ini_set('memory_limit', '64M');
define('PTS_MODE', 'WEB_CLIENT');
define('PTS_AUTO_LOAD_OBJECTS', true);
error_reporting(E_ALL);

include('../../pts-core.php');
pts_core::init();

$environment_variables = array(
	'aid' => 'ACCOUNT_ID',
	'sid' => 'SYSTEM_ID',
	'bid' => 'BENCHMARK_TICKET_ID',
//	'vid' => 'VALIDATE_ID',
	'gsid' => 'GSID',
	'a' => 'ACTIVITY',
	'r' => 'REQUEST',
	'pts' => 'CLIENT_VERSION',
	'pts_core' => 'CLIENT_CORE_VERSION',
	'h' => 'CLIENT_HARDWARE',
	's' => 'CLIENT_SOFTWARE',
	'pp' => 'PHODEVI_PROPERTIES',
	'i' => 'ID',
	'o' => 'OTHER',
	'nm' => 'NETWORK_CLIENT_MAC',
	'nw' => 'NETWORK_CLIENT_WOL',
	'n' => 'HOSTNAME',
	'ti' => 'TEST_IDENTIFIER',
	'ts' => 'TRIGGER_STRING',
	'time' => 'ESTIMATED_TIME',
	'pc' => 'PERCENT_COMPLETE',
	'c' => 'COMPOSITE_XML',
	'ob' => 'OPENBENCHMARKING_ID',
	'sched' => 'SCHEDULE_ID',
	'lip' => 'LOCAL_IP',
	'l' => 'LOGS',
	'j' => 'JSON',
	'composite_xml' => 'COMPOSITE_XML',
	'composite_xml_gz' => 'COMPOSITE_XML_GZ',
	'composite_xml_hash' => 'COMPOSITE_XML_HASH',
	'system_logs_type' => 'SYSTEM_LOGS_TYPE',
	'system_logs_zip' => 'SYSTEM_LOGS_ZIP',
	'system_logs_hash' => 'SYSTEM_LOGS_HASH',
	'msi' => 'PTS_MACHINE_SELF_ID',
	'err' => 'ERROR_MSG',
	'et' => 'ELAPSED_TIME',
	);

foreach($environment_variables as $get_var => $to_var)
{
	if(isset($_REQUEST[$get_var]) && !empty($_REQUEST[$get_var]))
	{
		$$to_var = $_REQUEST[$get_var];
	}
	else
	{
		$$to_var = null;
	}
}

if($CLIENT_CORE_VERSION < 5400)
{
	// Due to major PTS 5.4 development changes, client version bump will be necessary
	$json['phoromatic']['error'] = 'You must update your Phoronix Test Suite clients for compatibility with this Phoromatic server.';
	echo json_encode($json);
	exit;
}
define('CLIENT_CORE_VERSION', $CLIENT_CORE_VERSION);

// DATABASE SETUP
phoromatic_server::prepare_database();

// Every JSON reply carries the server's phone-home policy; clients cache it and, while it forbids phoning home, keep
// NoPhoneHome on whatever their own configuration says. Anything printed before the JSON (PHP notices with display_errors
// on) is kept as it is; replies that are not JSON pass through untouched.
ob_start(function($output)
{
	$start = strpos($output, '{"phoromatic":');
	$json = $start === false ? null : json_decode(substr($output, $start), true);
	if(!is_array($json) || !isset($json['phoromatic']) || !is_array($json['phoromatic']))
	{
		return $output;
	}
	$json['phoromatic']['policy'] = array('no_phone_home' => phoromatic_server::client_phone_home_forbidden());
	return substr($output, 0, $start) . json_encode($json);
});

if($ACCOUNT_ID == null && $PTS_MACHINE_SELF_ID != null)
{
	// Try to find the account
	$stmt = phoromatic_server::$db->prepare('SELECT AccountID FROM phoromatic_systems WHERE MachineSelfID = :machine_self_id AND DeletedOn IS NULL');
	$stmt->bindValue(':machine_self_id', $PTS_MACHINE_SELF_ID);
	$result = $stmt->execute();

	if(!empty($result))
	{
		$result = $result->fetchArray();
		if($result['AccountID'] != null)
		{
			$json['phoromatic']['account_id'] = $result['AccountID'];
			echo json_encode($json);
			exit;
		}
	}

	// Try to find the account if there is an IP/MAC claim
	if(!empty($_SERVER['REMOTE_ADDR']) && !empty($NETWORK_CLIENT_MAC))
	{
		// IPAddress = :ip_address OR
		$stmt = phoromatic_server::$db->prepare('SELECT AccountID FROM phoromatic_system_association_claims WHERE NetworkMAC = :network_mac OR IPAddress = :ip_address ORDER BY CreationTime ASC LIMIT 1');
		$stmt->bindValue(':ip_address', $_SERVER['REMOTE_ADDR']);
		$stmt->bindValue(':network_mac', $NETWORK_CLIENT_MAC);
		$result = $stmt->execute();

		if(!empty($result))
		{
			$result = $result->fetchArray();
			$json['phoromatic']['account_id'] = $result['AccountID'];
			echo json_encode($json);
			exit;
		}
	}
}

if(($GSID == null && $PTS_MACHINE_SELF_ID == null) || $ACCOUNT_ID == null)
{
	$json['phoromatic']['error'] = 'Invalid Credentials';
	echo json_encode($json);
	exit;
}

// CHECK FOR VALID ACCOUNT
if(!phoromatic_server::is_phoromatic_account_path($ACCOUNT_ID))
{
	$json['phoromatic']['error'] = 'Invalid User';
	echo json_encode($json);
	exit;
}
define('ACCOUNT_ID', $ACCOUNT_ID);


// CHECK IF SYSTEM IS ALREADY CONNECTED TO THE ACCOUNT
if($PTS_MACHINE_SELF_ID != null)
{
	$stmt = phoromatic_server::$db->prepare('SELECT Title, SystemID, Groups, State, MaintenanceMode, LastCommunication FROM phoromatic_systems WHERE AccountID = :account_id AND MachineSelfID = :machine_self_id AND DeletedOn IS NULL');
	$stmt->bindValue(':account_id', ACCOUNT_ID);
	$stmt->bindValue(':machine_self_id', $PTS_MACHINE_SELF_ID);
	$result = $stmt->execute();
	$result = $result->fetchArray();
}


$mac_clash = false;
$normalised_mac = phoromatic_system_admin::normalise_mac($NETWORK_CLIENT_MAC);
if((!isset($result) || empty($result)) && $normalised_mac !== null && $PTS_MACHINE_SELF_ID != null)
{
	// Legacy rows only: a system record that has never had a MachineSelfID (very old client) is bound, right here, to the first client
	// that reports its MAC, so the bind can happen only once. A row that HAS a machine id is never taken over by MAC (see the clash
	// check below); a pending adoption row is never a legacy match; an empty or placeholder MAC never matches.
	$stmt = phoromatic_server::$db->prepare('SELECT Title, SystemID, Groups, State, MaintenanceMode, LastCommunication FROM phoromatic_systems WHERE AccountID = :account_id AND ' . phoromatic_system_admin::MAC_SQL . ' = :network_mac AND (MachineSelfID IS NULL OR MachineSelfID = \'\') AND AdoptionCandidate IS NULL AND DeletedOn IS NULL ORDER BY State DESC LIMIT 1');
	$stmt->bindValue(':account_id', ACCOUNT_ID);
	$stmt->bindValue(':network_mac', $normalised_mac);
	$result = $stmt->execute();
	$result = $result->fetchArray();

	if(!empty($result))
	{
		$stmt = phoromatic_server::$db->prepare('UPDATE phoromatic_systems SET MachineSelfID = :machine_self_id WHERE AccountID = :account_id AND SystemID = :system_id AND (MachineSelfID IS NULL OR MachineSelfID = \'\') AND DeletedOn IS NULL');
		$stmt->bindValue(':machine_self_id', $PTS_MACHINE_SELF_ID);
		$stmt->bindValue(':account_id', ACCOUNT_ID);
		$stmt->bindValue(':system_id', $result['SystemID']);
		$stmt->execute();
		if(phoromatic_server::$db->changes() != 1)
		{
			$result = false;
		}
	}
	if(empty($result))
	{
		// A different machine id presenting the MAC of an existing live system (reimage, NIC swap, or an impersonation attempt):
		// it is registered below as a NEW pending system and never inherits anything from that system
		$mac_clash = phoromatic_system_admin::mac_clash(ACCOUNT_ID, $PTS_MACHINE_SELF_ID, $NETWORK_CLIENT_MAC);
	}
}

if(empty($result) && $PTS_MACHINE_SELF_ID == null)
{
	// A client without a machine id cannot be told apart from another one and would leave a record that any client could later claim by MAC
	$json['phoromatic']['error'] = 'This client did not report a machine id; update the Phoronix Test Suite client.';
	echo json_encode($json);
	exit;
}

if(empty($result))
{
	$stmt = phoromatic_server::$db->prepare('SELECT * FROM phoromatic_account_settings WHERE AccountID = :account_id');
	$stmt->bindValue(':account_id', ACCOUNT_ID);
	$result = $stmt->execute();
	$phoromatic_account_settings = $result->fetchArray(SQLITE3_ASSOC);
	unset($phoromatic_account_settings['AccountID']);

	// Is this a machine whose system record was deleted (a tombstone keeps its old machine id / MAC)? Then it re-registers as a new system.
	$previously_deleted = null;
	if($PTS_MACHINE_SELF_ID != null || !empty($NETWORK_CLIENT_MAC))
	{
		$stmt = phoromatic_server::$db->prepare('SELECT Title, SystemID, DeletedOn FROM phoromatic_systems WHERE AccountID = :account_id AND DeletedOn IS NOT NULL AND ((DeletedMachineSelfID IS NOT NULL AND DeletedMachineSelfID = :machine_self_id) OR (DeletedNetworkMAC IS NOT NULL AND DeletedNetworkMAC <> \'\' AND DeletedNetworkMAC = :network_mac)) ORDER BY DeletedOn DESC LIMIT 1');
		$stmt->bindValue(':account_id', ACCOUNT_ID);
		$stmt->bindValue(':machine_self_id', $PTS_MACHINE_SELF_ID);
		$stmt->bindValue(':network_mac', $NETWORK_CLIENT_MAC);
		$deleted_result = $stmt->execute();
		$previously_deleted = $deleted_result ? $deleted_result->fetchArray(SQLITE3_ASSOC) : null;
	}

	// APPARENT FIRST TIME FOR THIS SYSTEM CONNECTING TO THIS ACCOUNT
	do
	{
		$system_id = pts_strings::random_characters(5, true);
		$matching_system = phoromatic_server::$db->querySingle('SELECT AccountID FROM phoromatic_systems WHERE SystemID = \'' . $system_id . '\'');
	}
	while(!empty($matching_system));
	$stmt = phoromatic_server::$db->prepare('INSERT INTO phoromatic_systems (AccountID, SystemID, Hardware, Software, SystemProperties, ClientVersion, GSID, CurrentTask, CreatedOn, LastCommunication, LastIP, LocalIP, Title, State, MachineSelfID, CoreVersion, NetworkMAC, Description, AdoptionCandidate) VALUES (:account_id, :system_id, :client_hardware, :client_software, :phodevi_properties, :client_version, :gsid, :current_task, :current_time, :current_time, :access_ip, :local_ip, :title, :preset_state, :machine_self_id, :core_version, :network_mac, :description, :adoption_candidate)');
	$stmt->bindValue(':adoption_candidate', $mac_clash ? $mac_clash['SystemID'] : null);
	$stmt->bindValue(':description', $previously_deleted ? 'Previously deleted: ' . $previously_deleted['Title'] . ' (' . $previously_deleted['SystemID'] . ') on ' . $previously_deleted['DeletedOn'] : null);
	$stmt->bindValue(':account_id', ACCOUNT_ID);
	$stmt->bindValue(':system_id', $system_id);
	$stmt->bindValue(':client_hardware', $CLIENT_HARDWARE);
	$stmt->bindValue(':client_software', $CLIENT_SOFTWARE);
	$stmt->bindValue(':phodevi_properties', $PHODEVI_PROPERTIES);
	$stmt->bindValue(':client_version', $CLIENT_VERSION);
	$stmt->bindValue(':gsid', $GSID);
	$stmt->bindValue(':access_ip', $_SERVER['REMOTE_ADDR']);
	$stmt->bindValue(':local_ip', $LOCAL_IP);
	$stmt->bindValue(':title', pts_strings::simple($HOSTNAME));
	$stmt->bindValue(':current_time', phoromatic_server::current_time());
	$stmt->bindValue(':machine_self_id', $PTS_MACHINE_SELF_ID);
	$stmt->bindValue(':core_version', $CLIENT_CORE_VERSION);
	$stmt->bindValue(':network_mac', $NETWORK_CLIENT_MAC);

	if($mac_clash)
	{
		// Same MAC as an existing system but a different machine id: never auto-approved and never inherits state; an administrator
		// chooses "Adopt as <title>" or "Register as new" on the systems page
		$stmt->bindValue(':current_task', 'Awaiting Authorization (same MAC as ' . phoromatic_system_admin::plain_title($mac_clash['Title']) . ')');
		$stmt->bindValue(':preset_state', 0);
		$new_response = 'Information Added; Waiting For Approval From Administrator.';
	}
	else if($phoromatic_account_settings['AutoApproveNewSystems'] && !$previously_deleted)
	{
		$stmt->bindValue(':current_task', 'System Added');
		$stmt->bindValue(':preset_state', 1);
		$new_response = 'System Automatically Added To Account.';
	}
	else
	{
		// A returning deleted machine always waits for approval, even when new systems are normally auto-approved
		$stmt->bindValue(':current_task', $previously_deleted ? 'Awaiting Authorization (previously deleted)' : 'Awaiting Authorization');
		$stmt->bindValue(':preset_state', 0);
		$new_response = 'Information Added; Waiting For Approval From Administrator.';
	}

	$result = $stmt->execute();

	if($mac_clash)
	{
		phoromatic_system_admin::note_adopt_refused(ACCOUNT_ID, $system_id, $PTS_MACHINE_SELF_ID, $NETWORK_CLIENT_MAC, $mac_clash);
	}

	// Email notifications
	$stmt = phoromatic_server::$db->prepare('SELECT UserName, Email FROM phoromatic_users WHERE UserID IN (SELECT UserID FROM phoromatic_user_settings WHERE AccountID = :account_id AND NotifyOnNewSystems = 1) AND AccountID = :account_id');
	$stmt->bindValue(':account_id', ACCOUNT_ID);
	$result = $stmt->execute();
	while($row = $result->fetchArray())
	{
		phoromatic_server::send_email($row['Email'], 'Phoromatic New System Added', phoromatic_server::account_id_to_group_admin_email(ACCOUNT_ID), '<p><strong>' . $row['UserName'] . ':</strong></p><p>A new system is attempting to associate with a Phoromatic account for which you\'re associated.</p><p>Title: ' . htmlspecialchars($HOSTNAME) . '<br />IP: ' . htmlspecialchars($LOCAL_IP) . '<br />System Info: ' . htmlspecialchars($CLIENT_HARDWARE) . ' ' . htmlspecialchars($CLIENT_SOFTWARE) . '</p>');
	}

	// Send response back
	$json['phoromatic']['response'] = $new_response;
	echo json_encode($json);
	exit;
}

define('SYSTEM_ID', $result['SystemID']);
define('SYSTEM_NAME', $result['Title']);
define('SYSTEM_GROUPS', $result['Groups']);
$SYSTEM_STATE = $result['State'];
define('GSID', $GSID);
define('SYSTEM_IN_MAINTENANCE_MODE', ($result['MaintenanceMode'] == 1));

if(strtotime($result['LastCommunication']) < (time() - 300))
{
	// Avoid useless updates to the database if it's close to the same info in past 2 minutes
	$stmt = phoromatic_server::$db->prepare('UPDATE phoromatic_systems SET LastIP = :access_ip, LocalIP = :local_ip, LastCommunication = :current_time, Hardware = :client_hardware, Software = :client_software, SystemProperties = :phodevi_properties, ClientVersion = :client_version, MachineSelfID = CASE WHEN MachineSelfID IS NULL OR MachineSelfID = \'\' THEN :machine_self_id ELSE MachineSelfID END, NetworkMAC = :network_mac, NetworkWakeOnLAN = :network_wol, CoreVersion = :core_version WHERE AccountID = :account_id AND SystemID = :system_id AND DeletedOn IS NULL');
	$stmt->bindValue(':account_id', $ACCOUNT_ID);
	$stmt->bindValue(':system_id', SYSTEM_ID);
	$stmt->bindValue(':client_hardware', $CLIENT_HARDWARE);
	$stmt->bindValue(':client_software', $CLIENT_SOFTWARE);
	$stmt->bindValue(':phodevi_properties', $PHODEVI_PROPERTIES);
	$stmt->bindValue(':client_version', $CLIENT_VERSION);
	$stmt->bindValue(':core_version', $CLIENT_CORE_VERSION);
	$stmt->bindValue(':access_ip', $_SERVER['REMOTE_ADDR']);
	$stmt->bindValue(':local_ip', $LOCAL_IP);
	$stmt->bindValue(':current_time', phoromatic_server::current_time());
	$stmt->bindValue(':machine_self_id', $PTS_MACHINE_SELF_ID);
	$stmt->bindValue(':network_mac', $NETWORK_CLIENT_MAC);
	$stmt->bindValue(':network_wol', $NETWORK_CLIENT_WOL);
	$stmt->execute();
}

//echo phoromatic_server::$db->lastErrorMsg();
if($SYSTEM_STATE < 1)
{
	$json['phoromatic']['response'] = 'Waiting For Approval From Administrator.';
	echo json_encode($json);
	exit;
}

define('AID', ACCOUNT_ID);
define('SID', SYSTEM_ID);

// Only a listed resource is ever included (r is never used to build a path from what the client sent)
if(is_string($REQUEST) && in_array($REQUEST, phoromatic_server::client_resources(), true))
{
	// A communication resource answers a client program that only acts on a reply starting with '{': a PHP notice in the body would
	// starve it silently. Notices go to the server log instead (the web pages keep their own error display).
	ini_set('display_errors', '0');
	ini_set('log_errors', '1');
	require('../communication-resources/' . $REQUEST . '.php');
}
else
{
	$json['phoromatic']['error'] = 'Unknown Resource: ' . (is_string($REQUEST) ? $REQUEST : '');
	echo json_encode($json);
}

//phoromatic_server::close_database();

?>
