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

class phoromatic_system_claim implements pts_webui_interface
{
	public static function page_title()
	{
		return 'Phoromatic Client System Claim';
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
		$main = null;

		// Claims decide which account an unconfigured client is sent to, so only an administrator of the account (level 1 or 2, read from
		// the database now) may add or remove one
		if(!phoromatic_system_admin::admin_allowed())
		{
			$main .= '<h2>System Claims</h2><p>Only the administrators of this account can claim IP and MAC addresses for it.</p>';
		}
		else
		{

			if(function_exists('ssh2_connect') && isset($_POST['ip']) && isset($_POST['port']) && isset($_POST['password']) && isset($_POST['username']) && verify_submission_token() && phoromatic_private_repo_web::origin_ok())
			{
				$connection = ssh2_connect($_POST['ip'], $_POST['port']);

				if(ssh2_auth_password($connection, $_POST['username'], $_POST['password']))
				{
					$tmp_local_file = tempnam('/tmp', 'pts-ssh');
					$tmp_remote_file = 'pts-ssh-' . rand(9999, 99999);

					file_put_contents($tmp_local_file, '#!/bin/sh
			if [ -w /var/lib/benchy/ ]
			then
				PHORO_FILE_PATH=/var/lib/benchy/
			elif [ -w $HOME/.benchy/ ]
			then
				PHORO_FILE_PATH=$HOME/.benchy/
			fi

			echo "' . phoromatic_web_socket_server_ip() . '" >> $PHORO_FILE_PATH/phoromatic-servers
			mkdir -p $PHORO_FILE_PATH/modules-data/phoromatic
			echo "' . phoromatic_web_socket_server_addr() . '" > $PHORO_FILE_PATH/modules-data/phoromatic/last-phoromatic-server
			');

					ssh2_scp_send($connection, $tmp_local_file, $tmp_remote_file);
					unlink($tmp_local_file);
					ssh2_exec($connection, 'chmod +x ' . $tmp_remote_file);
					ssh2_exec($connection, './' . $tmp_remote_file);
					ssh2_exec($connection, 'rm' . $tmp_remote_file);
				}
			}
			if((isset($_POST['ip_claim']) && !empty($_POST['ip_claim'])) && isset($_POST['ping']) && verify_submission_token() && phoromatic_private_repo_web::origin_ok())
			{
				$ip_ping = is_string($_POST['ip_claim']) && filter_var($_POST['ip_claim'], FILTER_VALIDATE_IP) !== false ? $_POST['ip_claim'] : null;
				if($ip_ping)
				{
					echo '<h3>Ping Test: ' . htmlspecialchars($ip_ping) . '</h3>';
					echo '<pre>';
					echo htmlspecialchars((string)shell_exec('ping -c 1 ' . escapeshellarg($ip_ping)));
					echo '</pre>';
				}
			}
			else if(((isset($_POST['ip_claim']) && !empty($_POST['ip_claim'])) || (isset($_POST['mac_claim']) && !empty($_POST['mac_claim']))) && verify_submission_token() && phoromatic_private_repo_web::origin_ok())
			{
				$claimed = is_string($_POST['ip_claim']) && is_string($_POST['mac_claim']) ? phoromatic_system_admin::claim($_SESSION['AccountID'], $_POST['ip_claim'], $_POST['mac_claim'], isset($_SESSION['UserName']) ? $_SESSION['UserName'] : '?', 'UserID ' . (isset($_SESSION['UserID']) ? $_SESSION['UserID'] : '?')) : array('ok' => false, 'message' => 'Enter an IP address, a MAC address or both.');
				$main .= '<p' . ($claimed['ok'] ? '' : ' style="color: red;"') . '><strong>' . htmlspecialchars($claimed['message']) . '</strong></p>';
			}
			if(isset($_POST['remove_claim']) && is_string($_POST['remove_claim']) && strpos($_POST['remove_claim'], ',') !== false && verify_submission_token() && phoromatic_private_repo_web::origin_ok())
			{
				list($ipc, $macc) = explode(',', $_POST['remove_claim'], 2);
				$stmt = phoromatic_server::$db->prepare('DELETE FROM phoromatic_system_association_claims WHERE AccountID = :account_id AND IFNULL(NetworkMAC, \'\') = :mac_address AND IFNULL(IPAddress, \'\') = :ip_address');
				$stmt->bindValue(':account_id', $_SESSION['AccountID']);
				$stmt->bindValue(':ip_address', $ipc);
				$stmt->bindValue(':mac_address', $macc);
				$result = $stmt->execute();
			}

			$main .= '<h2>Add Phoromatic Server Info Via SSH</h2>
			<p>If your Phoromatic client systems are SSH-enabled, you can specify their SSH connection information below. In doing so, the Phoromatic Server will do a one-time connection to it immediately to pre-seed the system with the Phoromatic Server account information for this account. This should allow the client systems to then find the server automatically next time the benchy is run. This command assumes Benchy is already pre-installed on the client system in your desired configuration.</p>';

			if(function_exists('ssh2_connect'))
			{
				$main .= '<h3>Phoromatic Client SSH Information:</h3>';
				$main .= '<form action="' . htmlspecialchars($_SERVER['REQUEST_URI'], ENT_QUOTES) . '" name="ssh_connect" method="post">' . write_token_in_form() . '
				<p><strong>IP Address:</strong> <input type="text" name="ip" /></p>
				<p><strong>SSH Port:</strong> <input type="text" name="port" value="22" /></p>
				<p><strong>Username:</strong> <input type="text" name="username" /></p>
				<p><strong>Password:</strong> <input type="password" name="password" /></p>
				<p><input name="submit" value="Seed Phoromatic Server Account Information" type="submit" /></p>
				</form>';
			}
			else
			{
				$main .= '<h3>PHP SSH2 Must Be Installed For This Feature</h3>';
			}
			$main .= '<hr />';
			$main .= '<h2>Add Phoromatic Server Info Via IP/MAC</h2>
			<p>If deploying a Phoromatic Server within an organization, you can attempt for automatic configuration of Phoromatic clients if you know the system\'s IP or MAC addresses. When specifying either of these fields, if a Phoromatic client attempts to connect to this Phoromatic system without being associated to an account, it will be claimed by this account as long as no other Phoromatic accounts are attempting to claim the IP/MAC. This method can be particularly useful if running the Phoromatic client as a systemd/Upstart service where it will continually poll every 90 seconds auto-detected Phoromatic Servers on the LAN via zero-conf networking. For this feature to work, the zero-conf networking (Avahi) support must be enabled and working.</p>';
			$main .= '<form action="' . htmlspecialchars($_SERVER['REQUEST_URI'], ENT_QUOTES) . '" name="auto_associate" method="post">' . write_token_in_form() . '
			<p><strong>IP Address Claim:</strong> <input type="text" name="ip_claim" /></p>
			<p><strong>MAC Address Claim:</strong> <input type="text" name="mac_claim" /></p>
			<p><input name="ping" value="Ping Test" type="submit" /> &nbsp; <input name="submit" value="Submit Claim" type="submit" /></p>
			</form>';

			$stmt = phoromatic_server::$db->prepare('SELECT * FROM phoromatic_system_association_claims WHERE AccountID = :account_id ORDER BY IPAddress ASC');
			$stmt->bindValue(':account_id', $_SESSION['AccountID']);
			$result = $stmt->execute();
			$claims = array();
			$main .= '<p style="max-height: 500px; overflow-y: auto; ">';
			while($row = $result->fetchArray())
			{
				$ip = $row['IPAddress'] != null ? htmlspecialchars($row['IPAddress']) : '<em>' . htmlspecialchars((string)pts_network::mac_to_ip($row['NetworkMAC'])) . '</em>';

				$main .= $ip . ' ' . htmlspecialchars($row['NetworkMAC']) . '<br />';
				array_push($claims, $row['IPAddress'] . ',' . $row['NetworkMAC']); // (NULL parts are '')
			}
			$main .= '</p>';

			if(!empty($claims))
			{
				$main .= '<hr /><h2>Remove Claim</h2><p>Removing a claimed IP / MAC address.</p>';
				$main .= '<p><form action="' . htmlspecialchars($_SERVER['REQUEST_URI'], ENT_QUOTES) . '" name="remove_claim" method="post"><select name="remove_claim" id="remove_claim">' . write_token_in_form();

				foreach($claims as $claim)
				{
					$main .= '<option value="' . htmlspecialchars($claim, ENT_QUOTES) . '">' . htmlspecialchars(str_replace(',', ' ', $claim)) . '</option>';
				}
				$main .= '</select> <input name="submit" value="Remove Claim" type="submit" /></form></p>';
			}

			$main .= '<hr />';
		}

		$right = null;
		echo phoromatic_webui_main($main, phoromatic_webui_right_panel_logged_in($right));
		echo phoromatic_webui_footer();
	}
}

?>
