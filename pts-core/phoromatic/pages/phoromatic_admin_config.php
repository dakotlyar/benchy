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

class phoromatic_admin_config implements pts_webui_interface
{
	public static function page_title()
	{
		return 'Phoromatic Server Configuration';
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
		$https_message = phoromatic_tls_web::process_post();
		$phone_home_message = phoromatic_server_policy::process_post();

		// Storage, download cache, proxy and ports are set on the server (benchy-server-setup and user-config-set own them): the forms that
		// changed them from here are gone. A post from a stale tab changes nothing and says what to run instead. (The live copy of the data
		// folder could lose writes made after the copy, the ports form saved values its own check had refused, and the proxy check fetched
		// phoronix-test-suite.com.)
		$set_on_server = array(
			'new_phoromatic_path' => 'the storage folder: sudo benchy-server-setup --storage-path DIR --names NAMES (NAMES: the same host names and addresses as now, comma-separated). Copy the folder there first, with the service stopped. Setup does not move data.',
			'new_dc_path' => 'the download cache folder: sudo -u phoromatic env HOME=/var/lib/phoromatic benchy user-config-set CacheDirectory=DIR (as the service user; on a .deb server that is phoromatic)',
			'new_proxy_address' => 'the network proxy: sudo -u phoromatic env HOME=/var/lib/phoromatic benchy user-config-set ProxyAddress=ADDRESS ProxyPort=PORT',
			'new_http_port' => 'the ports: the HTTPS port with benchy-server-setup --https-port N; the plain HTTP port is PHOROMATIC_HTTP_PORT in the systemd drop-in /etc/systemd/system/phoromatic-server.service.d/ (a file there overrides the package unit)'
			);
		foreach($set_on_server as $field => $command)
		{
			if(isset($_POST[$field]))
			{
				$main .= '<h2 style="color: red;">Nothing was changed. This is set on the server now, not here. To change ' . htmlspecialchars($command, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h2>';
			}
		}
		if(isset($_POST['account_creation_alt']))
		{
			// (an empty text would read as open registration to the releases before this one: empty means the default closed message)
			$creation_text = trim((string)$_POST['account_creation_alt']);
			phoromatic_server::save_setting('account_creation_alt', $creation_text === '' ? phoromatic_accounts::DEFAULT_CLOSED_MESSAGE : $creation_text);
		}
		if(isset($_POST['main_page_message']))
		{
			phoromatic_server::save_setting('main_page_message', $_POST['main_page_message']);
		}
		if(isset($_POST['show_local_tests_only']))
		{
			phoromatic_server::save_setting('show_local_tests_only', $_POST['show_local_tests_only']);
		}
		if(isset($_POST['new_admin_support_email']))
		{
			phoromatic_server::save_setting('admin_support_email', $_POST['new_admin_support_email']);
		}
		if(isset($_POST['rebuild_results_db']) && $_POST['rebuild_results_db'] === '1')
		{
			foreach(pts_file_io::glob(phoromatic_server::phoromatic_path() . 'accounts/*/results/*/composite.xml') as $composite_xml)
			{
				$account_id = basename(dirname(dirname(dirname($composite_xml))));
				$upload_id = basename(dirname($composite_xml));

				$result_file = new pts_result_file($composite_xml);

				// Validate the XML
				$relative_id = 0;
				foreach($result_file->get_result_objects() as $result_object)
				{
					$relative_id++;
					$stmt = phoromatic_server::$db->prepare('INSERT INTO phoromatic_results_results (AccountID, UploadID, AbstractID, TestProfile, ComparisonHash) VALUES (:account_id, :upload_id, :abstract_id, :test_profile, :comparison_hash)');
					$stmt->bindValue(':account_id', $account_id);
					$stmt->bindValue(':upload_id', $upload_id);
					$stmt->bindValue(':abstract_id', $relative_id);
					$stmt->bindValue(':test_profile', $result_object->test_profile->get_identifier());
					$stmt->bindValue(':comparison_hash', $result_object->get_comparison_hash(true, false));
					$result = $stmt->execute();
				}

				if($relative_id > 0)
				{
					foreach($result_file->get_systems() as $s)
					{
						$stmt = phoromatic_server::$db->prepare('INSERT INTO phoromatic_results_systems (AccountID, UploadID, SystemIdentifier, Hardware, Software) VALUES (:account_id, :upload_id, :system_identifier, :hardware, :software)');
						$stmt->bindValue(':account_id', $account_id);
						$stmt->bindValue(':upload_id', $upload_id);
						$stmt->bindValue(':system_identifier', $s->get_identifier());
						$stmt->bindValue(':hardware', $s->get_hardware());
						$stmt->bindValue(':software', $s->get_software());
						$result = $stmt->execute();
					}
				}
			}
		}

		$self = htmlspecialchars($_SERVER['REQUEST_URI'], ENT_QUOTES);
		$main .= '<h1>Server configuration (root)</h1>
<p>The first sections are the same as the Server settings page that server administrators see. Below them are the settings only root has, then the ones that are set on the server.</p>';
		$main .= phoromatic_server_policy::render_all('admin_config', $phone_home_message, $https_message);
		$main .= phoromatic_catalog_web::render_section('admin_config', phoromatic_catalog_web::process_post());

		$main .= '<hr /><h2>Root only</h2>';
		$main .= '<form action="' . $self . '" name="account_creation_text" method="post">' . write_token_in_form() . '';
		$main .= '<p><strong>Welcome-page message</strong><br />Shown on the welcome page where a sign-up form would be. There is no sign-up; accounts are made on the server with <em>benchy server-account-create</em>, or by the root login on the <a href="?admin">Admin</a> page.<br /><textarea name="account_creation_alt" cols="50" rows="4">' . htmlspecialchars(phoromatic_accounts::registration_message(), ENT_QUOTES | ENT_SUBSTITUTE) . '</textarea></p>';
		$main .= '<p><input name="submit" value="Save" type="submit" /></p>';
		$main .= '</form>';

		$main .= '<form action="' . $self . '" name="main_page_message" method="post">' . write_token_in_form() . '';
		$main .= '<p><strong>Main-page message (HTML)</strong><br />Shown on the main page of every account after log-in.<br /><textarea name="main_page_message" cols="50" rows="4">' . htmlspecialchars((string)phoromatic_server::read_setting('main_page_message'), ENT_QUOTES | ENT_SUBSTITUTE) . '</textarea></p>';
		$main .= '<p><input name="submit" value="Save" type="submit" /></p>';
		$main .= '</form>';

		$main .= '<form action="' . $self . '" name="show_local_tests_only" method="post">' . write_token_in_form() . '';
		$main .= '<p><input type="hidden" name="show_local_tests_only" value="0" /><label><input type="checkbox" name="show_local_tests_only" value="1"' . (phoromatic_server::read_setting('show_local_tests_only') ? ' checked="checked"' : '') . ' /> Only show tests whose files are in the download cache</label><br />Test lists on the Tests, Build Suite and Schedules pages then leave out tests with files that are missing from this server\'s cache. Useful where the test systems have no Internet access.</p>';
		$main .= '<p><input name="submit" value="Save" type="submit" /></p>';
		$main .= '</form>';

		$main .= '<form action="' . $self . '" name="support_email" method="post">' . write_token_in_form() . '';
		$main .= '<p><strong>Reply-to address for server emails</strong><br />Shown as the reply-to address of emails the server sends, and as a support address if a user has a problem.<br /><input type="text" name="new_admin_support_email" value="' . htmlspecialchars((string)phoromatic_server::read_setting('admin_support_email'), ENT_QUOTES | ENT_SUBSTITUTE) . '" /></p>';
		$main .= '<p><input name="submit" value="Save" type="submit" /></p>';
		$main .= '</form>';

		$main .= '<form action="' . $self . '" name="rebuild_results_db" method="post">' . write_token_in_form() . '';
		$main .= '<p><strong>Rebuild the result index</strong><br />Re-reads every stored result. Use it after restoring files by hand. Safe to repeat; may take minutes.</p>';
		$main .= '<p><input type="hidden" name="rebuild_results_db" value="1" /><input name="submit" value="Rebuild now" type="submit" /></p>';
		$main .= '</form>';

		$main .= '<hr /><h2>Managed by benchy-server-setup (read-only)</h2>';
		$tls = phoromatic_tls::settings();
		list($http_port, $http_source) = phoromatic_server_info::http_port_setting();
		// The package's drop-in sets PHOROMATIC_HTTP_PORT for the service; this page is served by that service, so it normally sees it too
		$env_port = getenv('PHOROMATIC_HTTP_PORT');
		if($http_source == 'PHOROMATIC_HTTP_PORT')
		{
			$http_note = 'set by PHOROMATIC_HTTP_PORT in the systemd drop-in';
		}
		else if($env_port !== false && $env_port !== '')
		{
			$http_note = 'PHOROMATIC_HTTP_PORT is set to ' . htmlspecialchars($env_port, ENT_QUOTES | ENT_SUBSTITUTE) . ', which is not a valid port, so it is ignored; ' . ($http_port === 'RANDOM' ? 'no port is configured' : 'the port comes from ' . htmlspecialchars($http_source, ENT_QUOTES | ENT_SUBSTITUTE));
		}
		else
		{
			$http_note = $http_port === 'RANDOM' ? 'this page cannot see PHOROMATIC_HTTP_PORT and the configuration file has no port: on a .deb server the port is set in the systemd drop-in, see <code>systemctl cat phoromatic-server</code>' : 'from ' . htmlspecialchars($http_source, ENT_QUOTES | ENT_SUBSTITUTE);
		}
		$main .= '<p>Storage: <code>' . htmlspecialchars((string)phoromatic_server::phoromatic_path(), ENT_QUOTES | ENT_SUBSTITUTE) . '</code> (<code>sudo benchy-server-setup --storage-path DIR --names ' . htmlspecialchars(!empty($tls['names']) ? implode(',', (array)$tls['names']) : 'NAMES', ENT_QUOTES | ENT_SUBSTITUTE) . '</code>; give the same names as now. Copy the folder there first, with the service stopped. Setup does not move data)<br />';
		$main .= 'Download cache: <code>' . htmlspecialchars((string)pts_client::download_cache_path(), ENT_QUOTES | ENT_SUBSTITUTE) . '</code> (<code>sudo -u phoromatic env HOME=/var/lib/phoromatic benchy user-config-set CacheDirectory=DIR</code>)<br />';
		$main .= 'Ports: HTTPS ' . (int)$tls['https_port'] . ' (mode ' . htmlspecialchars($tls['mode'], ENT_QUOTES | ENT_SUBSTITUTE) . ', <code>benchy-server-setup --https-port N</code>), plain HTTP ' . htmlspecialchars((string)$http_port, ENT_QUOTES | ENT_SUBSTITUTE) . ' (' . $http_note . '; HTTP mode ' . htmlspecialchars($tls['http_mode'], ENT_QUOTES | ENT_SUBSTITUTE) . '), PHP ' . ((int)$tls['php_port'] > 0 ? (int)$tls['php_port'] : 'not set') . ' (loopback only)<br />';
		$proxy = trim((string)pts_config::read_user_config('PhoronixTestSuite/Options/Networking/ProxyAddress'));
		$main .= 'Proxy: ' . ($proxy === '' ? 'none' : htmlspecialchars($proxy . ':' . pts_config::read_user_config('PhoronixTestSuite/Options/Networking/ProxyPort'), ENT_QUOTES | ENT_SUBSTITUTE)) . ' (<code>sudo -u phoromatic env HOME=/var/lib/phoromatic benchy user-config-set ProxyAddress=ADDRESS ProxyPort=PORT</code>)</p>';
		$main .= '<p>Change these on the server, then restart the service. The admin guide has the details.</p>';

		echo phoromatic_webui_header_logged_in();
		echo phoromatic_webui_main($main, phoromatic_webui_right_panel_logged_in());
		echo phoromatic_webui_footer();
	}
}

?>
