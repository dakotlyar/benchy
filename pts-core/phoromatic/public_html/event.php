<?php

/*
	Phoronix Test Suite
	URLs: http://www.phoronix.com, http://www.phoronix-test-suite.com/
	Copyright (C) 2009 - 2014, Phoronix Media
	Copyright (C) 2009 - 2014, Michael Larabel

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

phoromatic_server::prepare_database();

if(!isset($_GET['type']))
{
	echo 'Missing type.';
	return;
}

switch($_GET['type'])
{
	case 'trigger':
		if(!isset($_GET['user']) || !isset($_GET['public_key']) || !isset($_GET['trigger']) || !is_string($_GET['user']) || !is_string($_GET['public_key']) || !is_string($_GET['trigger']))
		{
			echo 'Missing user, public_key, or trigger.';
			return;
		}
		// The user must be an enabled user who can schedule (level 1 to 3, a whole number) of an active account, and the key must be the public
		// key of one of THAT account's schedules. Every way of failing gives the same answer, so a stranger cannot tell a user name that exists
		// from one that does not, or a wrong key from a schedule of another account.
		$stmt = phoromatic_server::$db->prepare('SELECT AccountID FROM phoromatic_users WHERE UserName = :user_name AND typeof(AdminLevel) = \'integer\' AND AdminLevel IN (1, 2, 3)');
		$stmt->bindValue(':user_name', $_GET['user']);
		$result = $stmt->execute();
		$user_row = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;
		if(!$user_row || !phoromatic_tenant::is_active($user_row['AccountID']))
		{
			// No such user, a viewer or disabled one, or the account is suspended or removed: nothing is recorded
			echo 'Incorrect user information.';
			return;
		}
		phoromatic_tenant_context::set($user_row['AccountID']);
		$stmt = phoromatic_server::$db->prepare('SELECT ScheduleID FROM phoromatic_schedules WHERE AccountID = :account_id AND PublicKey = :public_key');
		$stmt->bindValue(':account_id', $user_row['AccountID']);
		$stmt->bindValue(':public_key', $_GET['public_key']);
		$result = $stmt->execute();
		$schedule_row = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;
		if(!$schedule_row || $_GET['public_key'] === '')
		{
			echo 'Incorrect user information.';
			return;
		}
		$sub_target = null;

		if(isset($_GET['sub_target_this_ip']))
		{
			$stmt = phoromatic_server::$db->prepare('SELECT SystemID FROM phoromatic_systems WHERE AccountID = :account_id AND LastIP = :this_ip AND DeletedOn IS NULL');
			$stmt->bindValue(':account_id', $user_row['AccountID']);
			$stmt->bindValue(':this_ip', $_SERVER['REMOTE_ADDR']);
			$result = $stmt->execute();
			$sys_row = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;
			if(!$sys_row)
			{
				// (no system means no trigger: a trigger without a target would run the schedule on every system)
				echo 'No system found associated to this IP address [' . htmlspecialchars($_SERVER['REMOTE_ADDR']) . '].';
				return;
			}
			$sub_target = $sys_row['SystemID'];
		}

		$trigger = pts_strings::sanitize($_GET['trigger']);
		$stmt = phoromatic_server::$db->prepare('INSERT INTO phoromatic_schedules_triggers (AccountID, ScheduleID, Trigger, TriggeredOn, SubTarget) VALUES (:account_id, :schedule_id, :trigger, :triggered_on, :sub_target)');
		$stmt->bindValue(':account_id',	$user_row['AccountID']);
		$stmt->bindValue(':schedule_id', $schedule_row['ScheduleID']);
		$stmt->bindValue(':trigger', $trigger);
		$stmt->bindValue(':triggered_on', phoromatic_server::current_time());
		$stmt->bindValue(':sub_target', $sub_target);
		if($stmt->execute())
		{
			echo 'Trigger ' . $trigger . ' added!';
		}
		break;

}
?>
