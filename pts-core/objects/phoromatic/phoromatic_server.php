<?php

/*
	Phoronix Test Suite
	URLs: http://www.phoronix.com, http://www.phoronix-test-suite.com/
	Copyright (C) 2014 - 2021, Phoronix Media
	Copyright (C) 2014 - 2021, Michael Larabel

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

class phoromatic_server
{
	public static $db = null;
	private static $json_storage = null;
	private static $request_backup = null;   // the backup file name made in this request before the first schema step, reused by the later ones
	private static $tenancy_ready = false;
	const MIGRATE_RETRY_SECONDS = 300;
	const DEFAULT_STORAGE_SETTING = '~/.benchy/phoromatic/';   // what user-config-defaults.xml ships for PhoromaticStorage: not a folder anyone chose

	public static function current_time()
	{
		return date('Y-m-d H:i:s');
	}
	public static function read_database_version()
	{
		$result = self::$db->query('PRAGMA user_version');

		if($result == false)
			return -1;

		$result = $result->fetchArray();
		return isset($result['user_version']) && is_numeric($result['user_version']) ? $result['user_version'] : 0;
	}
	public static function phoromatic_path_info()
	{
		// The storage folder in use, where it came from and what is wrong with it: array(path, source, problem). The source is
		// 'PhoromaticStorage' (the configuration file names a folder) or 'fallback' (the setting is empty, or still the value the file ships with,
		// ~/.benchy/phoromatic/, which nobody chose: the folder under the user data directory is used). A folder the administrator did set is never
		// replaced by the fallback: if this user cannot write it, problem says so (it is null otherwise) and the server does not start, instead of
		// quietly serving an empty database from somewhere else.
		// (an empty setting is read before the path helper sees it, which would turn nothing into "/")
		$configured = trim((string)pts_config::read_user_config('PhoronixTestSuite/Options/Server/PhoromaticStorage', ''));
		$PHOROMATIC_PATH = ($configured === '' || rtrim($configured, '/') === rtrim(self::DEFAULT_STORAGE_SETTING, '/')) ? '' : pts_config::read_path_config('PhoronixTestSuite/Options/Server/PhoromaticStorage', '');

		if(empty($PHOROMATIC_PATH))
		{
			return array(PTS_USER_PATH . 'phoromatic/', 'fallback', null);
		}
		$problem = null;
		if(is_dir($PHOROMATIC_PATH))
		{
			if(!is_writable($PHOROMATIC_PATH))
			{
				$problem = 'the folder cannot be written by this user';
			}
		}
		else if(file_exists(rtrim($PHOROMATIC_PATH, '/')))
		{
			$problem = 'it exists and is not a folder';
		}
		else
		{
			// Not there yet: it can be made if the nearest folder that exists above it can be written
			$above = dirname(rtrim($PHOROMATIC_PATH, '/'));
			while($above !== '/' && $above !== '.' && !is_dir($above))
			{
				$above = dirname($above);
			}
			if(!is_dir($above) || !is_writable($above))
			{
				$problem = 'the folder does not exist and ' . $above . ' cannot be written by this user, so it cannot be made';
			}
		}
		if($problem !== null)
		{
			$me = function_exists('posix_geteuid') && function_exists('posix_getpwuid') && ($pw = posix_getpwuid(posix_geteuid())) ? $pw['name'] : 'this user';
			$problem = 'PhoromaticStorage is set to ' . $PHOROMATIC_PATH . ' but ' . str_replace('this user', $me, $problem) . '.';
		}
		return array($PHOROMATIC_PATH, 'PhoromaticStorage', $problem);
	}
	public static function storage_problem()
	{
		// null when the storage folder is fine, else a sentence for the person starting the server
		$info = self::phoromatic_path_info();
		return $info[2];
	}
	public static function storage_error_exit($context = null)
	{
		// The server (web request, event server, command) cannot start with the storage folder it was told to use: say why and stop with a
		// non-zero status, rather than falling back to another folder and looking empty
		$problem = self::storage_problem();
		$text = 'ERROR: ' . $problem . ' The Phoromatic Server was not started and nothing was changed. Fix the owner or the mount of that folder (benchy-server-setup does this on a packaged server), or correct PhoromaticStorage in ' . pts_config::get_config_file_location() . '. The server does not fall back to another folder when this setting is set.';
		if(PHP_SAPI !== 'cli')
		{
			// A web client gets no path, user name or setting: the detail goes to the server's log, and a person at a terminal gets it below
			error_log('Benchy: ' . $text);
			if(!headers_sent())
			{
				http_response_code(503);
				header('Content-Type: text/plain; charset=utf-8');
			}
			echo 'The Phoromatic Server is not available: its storage cannot be used. The details are in the server log.' . PHP_EOL;
			exit(78);
		}
		echo PHP_EOL . $text . PHP_EOL . PHP_EOL;
		exit(78);
	}
	public static function phoromatic_path($create = true)
	{
		list($PHOROMATIC_PATH, $source, $problem) = self::phoromatic_path_info();

		if($create && $problem === null)
		{
			pts_file_io::mkdir($PHOROMATIC_PATH);
		}
		return $PHOROMATIC_PATH;
	}
	public static function find_download_cache()
	{
		static $dc_file = false;

		if($dc_file == false)
		{
			if(($dc = pts_client::download_cache_path()) && is_file($dc . 'pts-download-cache.json'))
			{
				$dc_file = $dc . 'pts-download-cache.json';
			}
			else if(is_file(PTS_DOWNLOAD_CACHE_PATH . 'pts-download-cache.json'))
			{
				$dc_file = PTS_DOWNLOAD_CACHE_PATH . 'pts-download-cache.json';
			}
			else if(is_file('/var/cache/benchy/download-cache/pts-download-cache.json'))
			{
				$dc_file = '/var/cache/benchy/download-cache/pts-download-cache.json';
			}
			else if(is_file(PTS_SHARE_PATH . 'download-cache/pts-download-cache.json'))
			{
				$dc_file = PTS_SHARE_PATH . 'download-cache/pts-download-cache.json';
			}
		}

		return $dc_file;
	}
	public static function download_cache_items()
	{
		$items = array();

		if(($dc = phoromatic_server::find_download_cache()))
		{
			$cache_json = file_get_contents($dc);
			$cache_json = json_decode($cache_json, true);

			if($cache_json && isset($cache_json['phoronix-test-suite']['download-cache']))
			{
				foreach($cache_json['phoronix-test-suite']['download-cache'] as $file_name => $info)
				{
					$items[$file_name] = $info;
				}
			}

			// looking for files added but not in the JSON cache, a.k.a. files uploaded but not make-download-cache run since
			foreach(pts_file_io::glob(dirname($dc) . '/*') as $file_in_dir)
			{
				$f = basename($file_in_dir);
				if($f == 'pts-download-cache.json')
				{
					continue;
				}
				if(!isset($items[$f]))
				{
					$items[$f] = array(
					'file_name' => $f,
					'file_size' => filesize($file_in_dir),
					'associated_tests' => array(),
					'md5' => md5_file($file_in_dir),
					'sha256' => hash_file('sha256', $file_in_dir),
					);
				}
			}
			ksort($items);
		}
		return $items;
	}
	public static function phoromatic_account_path($account_id, $folder = null)
	{
		// $folder: the caller reads only that folder of the account (private-repo), which a request may be allowed without the rest
		phoromatic_tenant_context::require_account($account_id, $folder);
		return self::phoromatic_path() . 'accounts/' . $account_id . '/';
	}
	public static function phoromatic_account_result_path($account_id, $result_id = null)
	{
		return self::phoromatic_account_path($account_id) . 'results/' . ($result_id != null ? $result_id . '/' : null);
	}
	public static function phoromatic_account_suite_path($account_id, $suite_id = null)
	{
		return self::phoromatic_account_path($account_id) . 'suites/' . ($suite_id != null ? $suite_id . '/' : null);
	}
	public static function find_suite_file($account_id, $suite_name)
	{
		$suite_file = false;
		// The name reaches this from the web (a schedule's suite_add, a ticket's stored suite): one segment for an account suite,
		// repository/name for an official one, nothing that can leave those folders
		if(($suite_name = phoromatic_tenant_path::suite_name($suite_name)) === false)
		{
			return false;
		}
		$xml_path = strpos($suite_name, '/') === false ? phoromatic_server::phoromatic_account_suite_path($account_id, $suite_name) . 'suite-definition.xml' : null;
		if($xml_path !== null && is_file($xml_path))
		{
			// Local account-created suite
			$suite_file = $xml_path;
		}
		else
		{
			if(is_file(PTS_TEST_SUITE_PATH . $suite_name . '/suite-definition.xml'))
			{
				// is a PTS suite
				$suite_file = PTS_TEST_SUITE_PATH . $suite_name . '/suite-definition.xml';
			}
		}

		return $suite_file;
	}
	public static function phoromatic_account_system_path($account_id, $system_id = null)
	{
		return self::phoromatic_account_path($account_id) . 'systems/' . ($system_id != null ? $system_id . '/' : null);
	}
	public static function phoromatic_account_stress_log_path($account_id, $ticket_id = null)
	{
		return self::phoromatic_account_path($account_id) . 'stress-logs/' . ($ticket_id != null ? $ticket_id . '/' : null);
	}
	public static function init_settings_store()
	{
		// The settings file path, without opening the database (the HTTPS commands and the server launcher only need the settings)
		if(self::$json_storage == null)
		{
			if(self::storage_problem() !== null)
			{
				self::storage_error_exit();
			}
			self::$json_storage = self::phoromatic_path() . 'phoromatic-settings.pt2so';
			if(!is_file(self::$json_storage))
			{
				$pt2so = new pts_storage_object();
				$pt2so->save_to_file(self::$json_storage);
			}
		}
	}
	public static function read_setting($setting)
	{
		return pts_storage_object::read_from_file(self::$json_storage, $setting);
	}
	public static function settings_locked($callback)
	{
		// $callback() with the settings file locked (read, decide and write in one go); the lock is not the schema step's migrate.lock, so a
		// page that needs a setting never waits for a database copy
		self::init_settings_store();
		return pts_storage_object::locked(self::$json_storage, $callback);
	}
	public static function ensure_feed_secret()
	{
		// The feed key secret (see phoromatic_feed), made if there is none: 256 random bits, never made twice by two requests
		return self::settings_locked(function()
		{
			$secret = self::read_setting('feed_secret');
			if(!is_string($secret) || strlen($secret) < 64)
			{
				self::save_setting('feed_secret', bin2hex(random_bytes(32)));
				$secret = self::read_setting('feed_secret');
			}
			return is_string($secret) && strlen($secret) >= 64 ? $secret : false;
		});
	}
	public static function save_setting($setting, $value)
	{
		return pts_storage_object::set_in_file(self::$json_storage, $setting, $value);
	}
	public static function client_resources()
	{
		// The requests (r=) phoromatic.php serves to clients, one per file in phoromatic/communication-resources/.
		// A new resource file must be added here too, or it is refused as unknown.
		return array('clone_result', 'error_report', 'list_results', 'ping', 'private_repo_delete', 'private_repo_fetch',
			'private_repo_index', 'bench_action', 'private_repo_rebuild', 'private_repo_upload', 'result_log_upload', 'result_upload', 'start',
			'stress_log_upload', 'test_install_manifest', 'tick', 'update_system_status', 'user_context_log');
	}
	public static function client_phone_home_policy()
	{
		// Server-wide: 'forbidden' (the default, also when unset or unrecognised) or 'client_choice'. Set with the
		// phoromatic-client-phone-home command.
		return self::read_setting('client_phone_home') === 'client_choice' ? 'client_choice' : 'forbidden';
	}
	public static function client_phone_home_forbidden()
	{
		return self::client_phone_home_policy() !== 'client_choice';
	}
	public static function set_client_phone_home_policy($policy, $account_id, $actor_type, $actor, $actor_detail = null)
	{
		// null on success, else why nothing was changed. The setting lives in the settings file, not the database, so the audit
		// row is written first inside a database transaction and the change is refused if it cannot be: no unaudited change.
		// A failed save rolls the audit row back; a failed commit puts the old value back.
		if(!in_array($policy, array('forbidden', 'client_choice'), true))
		{
			return 'Unknown policy; nothing was changed.';
		}
		$before = self::client_phone_home_policy();
		if(@self::$db->exec('BEGIN IMMEDIATE') === false)
		{
			return 'The database is busy; nothing was changed.';
		}
		if(!phoromatic_admin_audit::record($account_id, $actor_type, $actor, 'client_phone_home_set', array('actor_detail' => $actor_detail,
			'subject_type' => 'server_setting', 'subject_id' => 'client_phone_home', 'subject_label' => 'Clients may phone home',
			'detail' => array('before' => $before, 'after' => $policy))))
		{
			$why = phoromatic_admin_audit::last_error();
			@self::$db->exec('ROLLBACK');
			phoromatic_admin_audit::mirror_note('client_phone_home_set rolled back, nothing changed: ' . $why);
			return 'The change could not be recorded in the audit log (' . $why . '); nothing was changed.';
		}
		self::save_setting('client_phone_home', $policy);
		if(self::client_phone_home_policy() !== $policy)
		{
			@self::$db->exec('ROLLBACK');
			phoromatic_admin_audit::mirror_note('client_phone_home_set rolled back, nothing changed: the settings file could not be written');
			return 'The setting could not be saved (the server could not write ' . self::phoromatic_path(false) . 'phoromatic-settings.pt2so); nothing was changed.';
		}
		if(@self::$db->exec('COMMIT') === false)
		{
			self::save_setting('client_phone_home', $before);
			@self::$db->exec('ROLLBACK');
			phoromatic_admin_audit::mirror_note('client_phone_home_set rolled back, setting restored to ' . $before . ': the audit row could not be committed');
			return 'The change could not be recorded in the audit log; the setting was left at ' . $before . '.';
		}
		return null;
	}
	public static function tenant_auto_approve_policy()
	{
		// Server-wide: 'forbidden' (the default, also when unset or unrecognised) or 'allowed'. While forbidden every new v1 system waits for
		// an administrator whatever its account's own setting says (native enrollment codes, which an administrator makes, still approve).
		return self::read_setting('tenant_auto_approve') === 'allowed' ? 'allowed' : 'forbidden';
	}
	public static function tenant_auto_approve_forbidden()
	{
		return self::tenant_auto_approve_policy() !== 'allowed';
	}
	public static function set_tenant_auto_approve_policy($policy, $account_id, $actor_type, $actor, $actor_detail = null)
	{
		// null on success, else why nothing was changed. Same shape as set_client_phone_home_policy(): the audit row first, inside a
		// transaction, and no change without it. Switching to forbidden also writes 0 to every account's own setting in the same
		// transaction, so the checkbox is off again if the policy is ever allowed later (and stays off after a rollback to an older release).
		if(!in_array($policy, array('forbidden', 'allowed'), true))
		{
			return 'Unknown policy; nothing was changed.';
		}
		$before = self::tenant_auto_approve_policy();
		if(@self::$db->exec('BEGIN IMMEDIATE') === false)
		{
			return 'The database is busy; nothing was changed.';
		}
		$zeroed = 0;
		if($policy === 'forbidden')
		{
			$zeroed = (int)self::$db->querySingle('SELECT COUNT(*) FROM phoromatic_account_settings WHERE AutoApproveNewSystems IS NOT NULL AND AutoApproveNewSystems <> 0');
			if(!self::$db->exec('UPDATE phoromatic_account_settings SET AutoApproveNewSystems = 0 WHERE AutoApproveNewSystems IS NOT NULL AND AutoApproveNewSystems <> 0'))
			{
				@self::$db->exec('ROLLBACK');
				return 'The accounts\' own settings could not be changed; nothing was changed.';
			}
		}
		if(!phoromatic_admin_audit::record($account_id, $actor_type, $actor, 'tenant_auto_approve_set', array('actor_detail' => $actor_detail,
			'subject_type' => 'server_setting', 'subject_id' => 'tenant_auto_approve', 'subject_label' => 'Accounts may auto-approve new systems',
			'detail' => array('before' => $before, 'after' => $policy, 'accounts_switched_off' => $zeroed))))
		{
			$why = phoromatic_admin_audit::last_error();
			@self::$db->exec('ROLLBACK');
			phoromatic_server_audit::discard();
			phoromatic_admin_audit::mirror_note('tenant_auto_approve_set rolled back, nothing changed: ' . $why);
			return 'The change could not be recorded in the audit log (' . $why . '); nothing was changed.';
		}
		self::save_setting('tenant_auto_approve', $policy);
		if(self::tenant_auto_approve_policy() !== $policy)
		{
			@self::$db->exec('ROLLBACK');
			phoromatic_server_audit::discard();
			phoromatic_admin_audit::mirror_note('tenant_auto_approve_set rolled back, nothing changed: the settings file could not be written');
			return 'The setting could not be saved (the server could not write ' . self::phoromatic_path(false) . 'phoromatic-settings.pt2so); nothing was changed.';
		}
		if(@self::$db->exec('COMMIT') === false)
		{
			self::save_setting('tenant_auto_approve', $before);
			@self::$db->exec('ROLLBACK');
			phoromatic_server_audit::discard();
			phoromatic_admin_audit::mirror_note('tenant_auto_approve_set rolled back, setting restored to ' . $before . ': the audit row could not be committed');
			return 'The change could not be recorded in the audit log; the setting was left at ' . $before . '.';
		}
		phoromatic_server_audit::flush();
		return null;
	}
	// The mirror list as it would be stored: array(urls), or a string saying what is wrong with it. Nothing is written.
	public static function check_v2_mirrors($urls)
	{
		$clean = array();
		foreach((array)$urls as $url)
		{
			$url = trim((string)$url);
			if($url === '')
			{
				continue;
			}
			if(strlen($url) > 300 || preg_match('#^https://[A-Za-z0-9.-]+(:[0-9]{1,5})?(/[A-Za-z0-9._~/%+-]*)?\z#', $url) !== 1)
			{
				return 'Not an https:// address on a host name: ' . substr(preg_replace('/[^\x20-\x7e]/', '?', $url), 0, 80) . '; nothing was changed.';
			}
			$clean[] = $url;
		}
		if(count($clean) > 20)
		{
			return 'At most 20 mirrors; nothing was changed.';
		}
		return $clean;
	}
	public static function set_v2_mirrors($urls, $account_id, $actor_type, $actor, $actor_detail = null)
	{
		// null on success, else why nothing was changed: the list of download mirrors the server offers to native agents (https only; an
		// agent drops any that is not on the server's own host, and the API only offers those). Audited first, like the other settings.
		$clean = self::check_v2_mirrors($urls);
		if(is_string($clean))
		{
			return $clean;
		}
		$before = self::read_setting('v2_mirrors');
		if(@self::$db->exec('BEGIN IMMEDIATE') === false)
		{
			return 'The database is busy; nothing was changed.';
		}
		if(!phoromatic_admin_audit::record($account_id, $actor_type, $actor, 'v2_mirrors_set', array('actor_detail' => $actor_detail, 'subject_type' => 'server_setting', 'subject_id' => 'v2_mirrors',
			'subject_label' => 'Download mirrors offered to native agents', 'detail' => array('before' => is_array($before) ? $before : array(), 'after' => $clean))))
		{
			$why = phoromatic_admin_audit::last_error();
			@self::$db->exec('ROLLBACK');
			return 'The change could not be recorded in the audit log (' . $why . '); nothing was changed.';
		}
		self::save_setting('v2_mirrors', $clean);
		if(self::read_setting('v2_mirrors') != $clean)
		{
			@self::$db->exec('ROLLBACK');
			return 'The setting could not be saved; nothing was changed.';
		}
		if(@self::$db->exec('COMMIT') === false)
		{
			self::save_setting('v2_mirrors', is_array($before) ? $before : array());
			@self::$db->exec('ROLLBACK');
			return 'The change could not be recorded in the audit log; the setting was left as it was.';
		}
		return null;
	}
	public static function set_v2_settings($http, $urls, $account_id, $actor_type, $actor, $actor_detail = null)
	{
		// The settings page's save: the plain-HTTP switch ($http 'on'/'off', or null to leave it) and the mirror list ($urls, or null) in ONE
		// transaction, so a save that fails halfway leaves both as they were. null on success, else why nothing was changed. The settings live
		// in a file, not in the database, so what the transaction cannot undo is put back by hand.
		$clean = null;
		if($urls !== null && is_string($clean = self::check_v2_mirrors($urls)))
		{
			return $clean;
		}
		if($http !== null && !in_array($http, array('on', 'off'), true))
		{
			return 'Unknown value; nothing was changed.';
		}
		$before_http = self::read_setting('v2_allow_http') === 'on' ? 'on' : 'off';
		$before_mirrors = self::read_setting('v2_mirrors');
		$before_mirrors = is_array($before_mirrors) ? $before_mirrors : array();
		$http_changes = $http !== null && $http !== $before_http;
		$mirrors_change = $clean !== null && $clean !== $before_mirrors;
		if(!$http_changes && !$mirrors_change)
		{
			return null;
		}
		if(@self::$db->exec('BEGIN IMMEDIATE') === false)
		{
			return 'The database is busy; nothing was changed.';
		}
		$restore = function() use ($before_http, $before_mirrors) {
			self::save_setting('v2_allow_http', $before_http);
			self::save_setting('v2_mirrors', $before_mirrors);
			@self::$db->exec('ROLLBACK');
		};
		if($http_changes && !phoromatic_admin_audit::record($account_id, $actor_type, $actor, 'v2_allow_http_set', array('actor_detail' => $actor_detail, 'subject_type' => 'server_setting', 'subject_id' => 'v2_allow_http',
			'subject_label' => 'Native agent API over plain HTTP', 'detail' => array('before' => $before_http, 'after' => $http))))
		{
			$why = phoromatic_admin_audit::last_error();
			@self::$db->exec('ROLLBACK');
			return 'The change could not be recorded in the audit log (' . $why . '); nothing was changed.';
		}
		if($mirrors_change && !phoromatic_admin_audit::record($account_id, $actor_type, $actor, 'v2_mirrors_set', array('actor_detail' => $actor_detail, 'subject_type' => 'server_setting', 'subject_id' => 'v2_mirrors',
			'subject_label' => 'Download mirrors offered to native agents', 'detail' => array('before' => $before_mirrors, 'after' => $clean))))
		{
			$why = phoromatic_admin_audit::last_error();
			@self::$db->exec('ROLLBACK');
			return 'The change could not be recorded in the audit log (' . $why . '); nothing was changed.';
		}
		if($http_changes)
		{
			self::save_setting('v2_allow_http', $http);
			if((self::read_setting('v2_allow_http') === 'on' ? 'on' : 'off') !== $http)
			{
				$restore();
				return 'The setting could not be saved; nothing was changed.';
			}
		}
		if($mirrors_change)
		{
			self::save_setting('v2_mirrors', $clean);
			if(self::read_setting('v2_mirrors') != $clean)
			{
				$restore();
				return 'The setting could not be saved; nothing was changed.';
			}
		}
		if(@self::$db->exec('COMMIT') === false)
		{
			$restore();
			return 'The change could not be recorded in the audit log; the settings were left as they were.';
		}
		return null;
	}
	public static function set_v2_allow_http($value, $account_id, $actor_type, $actor, $actor_detail = null)
	{
		// null on success, else why nothing was changed. Same discipline as the phone-home policy: the audit row first, in a transaction.
		if(!in_array($value, array('on', 'off'), true))
		{
			return 'Unknown value; nothing was changed.';
		}
		$before = self::read_setting('v2_allow_http') === 'on' ? 'on' : 'off';
		if(@self::$db->exec('BEGIN IMMEDIATE') === false)
		{
			return 'The database is busy; nothing was changed.';
		}
		if(!phoromatic_admin_audit::record($account_id, $actor_type, $actor, 'v2_allow_http_set', array('actor_detail' => $actor_detail, 'subject_type' => 'server_setting', 'subject_id' => 'v2_allow_http',
			'subject_label' => 'Native agent API over plain HTTP', 'detail' => array('before' => $before, 'after' => $value))))
		{
			$why = phoromatic_admin_audit::last_error();
			@self::$db->exec('ROLLBACK');
			return 'The change could not be recorded in the audit log (' . $why . '); nothing was changed.';
		}
		self::save_setting('v2_allow_http', $value);
		if((self::read_setting('v2_allow_http') === 'on' ? 'on' : 'off') !== $value)
		{
			@self::$db->exec('ROLLBACK');
			return 'The setting could not be saved; nothing was changed.';
		}
		if(@self::$db->exec('COMMIT') === false)
		{
			self::save_setting('v2_allow_http', $before);
			@self::$db->exec('ROLLBACK');
			return 'The change could not be recorded in the audit log; the setting was left at ' . $before . '.';
		}
		return null;
	}
	public static function close_database()
	{
		if(self::$db != null)
		{
			self::$db->close();
			self::$db = null;
		}
	}
	protected static function migrate_log($text)
	{
		error_log('Benchy database migration: ' . $text);
		pts_logger::add_to_log('Database migration: ' . $text);
	}
	protected static function table_columns($table)
	{
		$columns = array();
		$result = self::$db->query('PRAGMA table_info(' . $table . ')');
		while($result && $row = $result->fetchArray(SQLITE3_ASSOC))
		{
			$columns[] = $row['name'];
		}
		return $columns;
	}
	protected static function backup_before_migration($to_version)
	{
		// A copy of the database made by VACUUM INTO (a consistent snapshot, whatever the other processes are doing), at 0600, in
		// <storage>/backups/. Every run of the step on a database that already holds data makes a fresh one: it is written under a name
		// ending in .part, checked (it opens, passes quick_check and is at the schema it was taken at) and only then renamed, so a copy cut
		// short by a kill or a full disk is never taken for a backup. Only the newest earlier copy is kept beside it, so a step that keeps
		// failing after a good backup cannot fill the disk. The file name, or null (the reason is in the log).
		$dir = self::phoromatic_path() . 'backups/';
		$old_umask = umask(0077);
		$final = null;
		$part = null;
		$ok = false;
		try
		{
			$ok = is_dir($dir) || @mkdir($dir, 0700, true);
			if($ok)
			{
				foreach((array)glob($dir . '*.part') as $left_over)
				{
					@unlink($left_over);
				}
				$earlier = (array)glob($dir . 'phoromatic-pre-' . (int)$to_version . '-*.db');
				sort($earlier);
				foreach(array_slice($earlier, 0, max(0, count($earlier) - 1)) as $old)
				{
					@unlink($old);
				}
				$base = $dir . 'phoromatic-pre-' . (int)$to_version . '-' . date('Ymd-His');
				$final = $base . '.db';
				for($n = 2; file_exists($final); $n++)
				{
					$final = $base . '-' . $n . '.db';
				}
				$part = $final . '.part';
				$ok = @touch($part) && @chmod($part, 0600);
			}
			if(!$ok)
			{
				self::migrate_log('the backup folder ' . $dir . ' or a file in it could not be created');
			}
			else if(!@self::$db->exec('VACUUM INTO \'' . SQLite3::escapeString($part) . '\''))
			{
				self::migrate_log('the backup ' . basename($final) . ' could not be made (' . self::$db->lastErrorMsg() . ')');
				$ok = false;
			}
			else
			{
				$ok = false;
				$copy = new SQLite3($part, SQLITE3_OPEN_READONLY);
				$ok = (int)$copy->querySingle('PRAGMA user_version') === (int)self::read_database_version() && $copy->querySingle('PRAGMA quick_check') === 'ok';
				$copy->close();
				$ok = $ok && @rename($part, $final);
				if(!$ok)
				{
					self::migrate_log('the backup ' . basename($final) . ' did not check out, so it was not kept');
				}
			}
		}
		catch(Throwable $e)
		{
			$ok = false;
			self::migrate_log('the backup could not be made or checked (' . $e->getMessage() . ')');
		}
		umask($old_umask);
		if($ok)
		{
			return basename($final);
		}
		if($part !== null)
		{
			@unlink($part);
		}
		return null;
	}
	protected static function migrate_deferred($why, $to = 59)
	{
		// The step did not run. Say why once, and do not try again for MIGRATE_RETRY_SECONDS: every request would otherwise take the lock and
		// copy the database again. Until it has run the pages work at the old schema (tenancy_ready() is false).
		self::migrate_log('schema ' . (int)$to . ' was not applied: ' . $why . '; trying again in ' . (int)(self::MIGRATE_RETRY_SECONDS / 60) . ' minutes');
		$files = self::migrate_retry_files();
		if((is_link($files[0]) || file_exists($files[0])) && !self::migrate_retry_valid($files[0]))
		{
			@unlink($files[0]); // a link, another user's file or a date in the future: replaced, never followed or kept
		}
		if(!(is_dir(dirname($files[0])) && is_writable(dirname($files[0])) && @touch($files[0])) && !is_link($files[1]))
		{
			// The storage is the thing that is wrong (read-only, full): the marker goes to the temporary folder, never through a link there
			if(!is_file($files[1]) || self::migrate_retry_valid($files[1]))
			{
				@touch($files[1]);
			}
		}
	}
	protected static function migrate_retry_files()
	{
		return array(self::phoromatic_path() . 'migrate.retry', sys_get_temp_dir() . '/benchy-migrate-retry-' . md5(self::phoromatic_path()));
	}
	protected static function migrate_retry_valid($file)
	{
		// A marker counts only if it is a plain file of the user the server runs as (the process owner: getmyuid() is the owner of the script file) whose time is not in the future: in the shared temporary folder anyone could
		// otherwise make one with a date far ahead and keep the upgrade from ever running
		clearstatcache(true, $file);
		return !is_link($file) && is_file($file) && fileowner($file) === (function_exists('posix_geteuid') ? posix_geteuid() : getmyuid()) && filemtime($file) <= time();
	}
	protected static function migrate_retry_active()
	{
		foreach(self::migrate_retry_files() as $file)
		{
			if(self::migrate_retry_valid($file) && filemtime($file) > time() - self::MIGRATE_RETRY_SECONDS)
			{
				return true;
			}
		}
		return false;
	}
	protected static function migrate_retry_clear()
	{
		$files = self::migrate_retry_files();
		if(file_exists($files[0]) || is_link($files[0]))
		{
			@unlink($files[0]);
		}
		if(self::migrate_retry_valid($files[1]))
		{
			@unlink($files[1]);
		}
	}
	public static function tenancy_ready()
	{
		// True once the database is at schema 59: phoromatic_accounts.State and the server role and audit tables exist. Code that reads them
		// asks first, so a server whose upgrade is waiting (no room for the backup, a busy database) still serves.
		return self::$tenancy_ready;
	}
	protected static function migrate_step($to, $fresh_database, $body, $undo = null)
	{
		// One schema step that must run exactly once while several web workers and the event server open the database at once. Takes
		// <storage>/migrate.lock without waiting long (another process doing the step is not waited for: this request goes on at the old
		// schema), looks at user_version again, makes the backup (not for a database created a moment ago), then runs $body($backup) and sets
		// user_version in ONE transaction. $body returns false when anything failed; $undo (if given) puts back what $body changed outside
		// the database. If the backup or any part fails the step does not run and is not tried again for five minutes.
		if(self::migrate_retry_active())
		{
			return;
		}
		$lock = @fopen(self::phoromatic_path() . 'migrate.lock', 'c');
		if($lock === false)
		{
			self::migrate_deferred('the lock file ' . self::phoromatic_path() . 'migrate.lock could not be opened', $to);
			return;
		}
		for($tries = 0; $tries < 100 && !($locked = flock($lock, LOCK_EX | LOCK_NB)); $tries++)
		{
			usleep(100000);
		}
		if(empty($locked))
		{
			fclose($lock);
			return;
		}
		@chmod(self::phoromatic_path() . 'migrate.lock', 0600);
		try
		{
			if(self::read_database_version() >= $to)
			{
				return;
			}
			$backup = null;
			if(!$fresh_database)
			{
				// One copy per request however many steps it takes (58 to 60): the one made before the first step is the state before them all
				if(self::$request_backup === null)
				{
					self::$request_backup = self::backup_before_migration($to);
				}
				if(($backup = self::$request_backup) === null)
				{
					self::migrate_deferred('the backup could not be made', $to);
					return;
				}
			}
			if(@self::$db->exec('BEGIN IMMEDIATE') === false)
			{
				self::migrate_deferred('the database is busy (' . self::$db->lastErrorMsg() . ')', $to);
				return;
			}
			$ok = $body($backup);
			$ok = $ok && self::$db->exec('PRAGMA user_version = ' . (int)$to);
			if($ok && @self::$db->exec('COMMIT'))
			{
				self::migrate_retry_clear();
				phoromatic_server_audit::flush();
				return;
			}
			$why = self::$db->lastErrorMsg() . ' ' . phoromatic_server_audit::last_error();
			@self::$db->exec('ROLLBACK');
			phoromatic_server_audit::discard();
			if($undo !== null)
			{
				$undo();
			}
			self::migrate_deferred('nothing was changed (' . trim($why) . ')', $to);
		}
		finally
		{
			flock($lock, LOCK_UN);
			fclose($lock);
		}
	}
	protected static function migrate_tenancy_schema($fresh_database)
	{
		// Schema 58 to 59: tenant lifecycle columns on phoromatic_accounts, the server role table and the server audit table
		self::migrate_step(59, $fresh_database, function($backup) use ($fresh_database)
		{
			$ok = true;
			$columns = self::table_columns('phoromatic_accounts');
			foreach(array('State' => 'TEXT DEFAULT \'active\'', 'StateChangedOn' => 'TEXT', 'StateChangedBy' => 'TEXT', 'StateReason' => 'TEXT', 'DeletedOn' => 'TEXT', 'PurgedOn' => 'TEXT', 'CreatedBy' => 'TEXT') as $column => $type)
			{
				if(!in_array($column, $columns))
				{
					$ok = $ok && self::$db->exec('ALTER TABLE phoromatic_accounts ADD COLUMN ' . $column . ' ' . $type);
				}
			}
			// Names: the oldest account without one becomes Default, the others Account <AID>
			$named = array();
			$oldest = self::$db->querySingle('SELECT AccountID FROM phoromatic_accounts ORDER BY CreatedOn ASC, rowid ASC LIMIT 1');
			$unnamed = self::$db->query('SELECT AccountID FROM phoromatic_accounts WHERE GroupName IS NULL OR TRIM(GroupName) = \'\' ORDER BY CreatedOn ASC, rowid ASC');
			$unnamed_ids = array();
			while($unnamed && ($row = $unnamed->fetchArray(SQLITE3_ASSOC)))
			{
				$unnamed_ids[] = $row['AccountID'];
			}
			foreach($unnamed_ids as $account_id)
			{
				$stmt = self::$db->prepare('UPDATE phoromatic_accounts SET GroupName = :name WHERE AccountID = :account_id');
				$stmt->bindValue(':name', $account_id === $oldest ? 'Default' : 'Account ' . $account_id);
				$stmt->bindValue(':account_id', $account_id);
				$ok = $ok && $stmt->execute() !== false;
				$named[$account_id] = $account_id === $oldest ? 'Default' : 'Account ' . $account_id;
			}
			$ok = $ok && self::$db->exec('CREATE TABLE IF NOT EXISTS phoromatic_server_roles (UserID TEXT PRIMARY KEY, Role TEXT NOT NULL, Source TEXT NOT NULL DEFAULT \'local\', SourceRef TEXT, GrantedOn TEXT NOT NULL, GrantedBy TEXT NOT NULL)');
			$ok = $ok && self::$db->exec('CREATE TABLE IF NOT EXISTS phoromatic_server_audit (AuditID INTEGER PRIMARY KEY AUTOINCREMENT, EventTime TEXT NOT NULL, ActorType TEXT NOT NULL, Actor TEXT NOT NULL, ActorDetail TEXT, SourceIP TEXT, Action TEXT NOT NULL, TenantID TEXT, SubjectType TEXT, SubjectID TEXT, SubjectLabel TEXT, Detail TEXT)');
			$ok = $ok && self::$db->exec('CREATE INDEX IF NOT EXISTS phoromatic_server_audit_time ON phoromatic_server_audit (EventTime DESC)');
			$ok = $ok && self::$db->exec('CREATE INDEX IF NOT EXISTS phoromatic_server_audit_tenant ON phoromatic_server_audit (TenantID, EventTime DESC)');
			$ok = $ok && self::$db->exec('CREATE TRIGGER IF NOT EXISTS phoromatic_server_audit_no_update BEFORE UPDATE ON phoromatic_server_audit BEGIN SELECT RAISE(ABORT, \'audit rows are append-only\'); END');
			$ok = $ok && self::$db->exec('CREATE TRIGGER IF NOT EXISTS phoromatic_server_audit_no_delete BEFORE DELETE ON phoromatic_server_audit BEGIN SELECT RAISE(ABORT, \'audit rows are append-only\'); END');
			$ok = $ok && self::$db->exec('CREATE TRIGGER IF NOT EXISTS phoromatic_server_audit_no_replace BEFORE INSERT ON phoromatic_server_audit WHEN NEW.AuditID IS NOT NULL AND EXISTS (SELECT 1 FROM phoromatic_server_audit WHERE AuditID = NEW.AuditID) BEGIN SELECT RAISE(ABORT, \'audit rows are append-only\'); END');

			self::$tenancy_ready = $ok;     // (the role and audit code below checks it; set false again if the step is rolled back)
			// The role: a server with exactly one account keeps what its level-1 users could do before; with more, nobody gets it
			$granted = array();
			if($ok && (int)self::$db->querySingle('SELECT COUNT(*) FROM phoromatic_accounts WHERE State IS NULL OR State <> \'deleted\'') === 1)
			{
				$users = self::$db->query('SELECT u.UserID, u.UserName FROM phoromatic_users u WHERE u.AdminLevel = 1 AND u.AccountID IN (SELECT AccountID FROM phoromatic_accounts WHERE State IS NULL OR State <> \'deleted\') AND NOT EXISTS (SELECT 1 FROM phoromatic_server_roles r WHERE r.UserID = u.UserID) ORDER BY u.UserID');
				$new_roles = array();
				while($users && ($row = $users->fetchArray(SQLITE3_ASSOC)))
				{
					$new_roles[] = $row;
				}
				foreach($new_roles as $row)
				{
					$ok = $ok && phoromatic_server_roles::insert($row['UserID'], 'migration') && phoromatic_server_audit::record(null, 'migration', 'migration', 'server_role_granted', array('subject_type' => 'user', 'subject_id' => $row['UserID'],
						'subject_label' => $row['UserName'], 'detail' => array('role' => 'admin', 'rule' => 'the only account on the server')));
					$granted[] = $row['UserName'];
				}
			}
			// (a database created a moment ago was not migrated from anything: no row)
			$ok = $ok && ($fresh_database || phoromatic_server_audit::record(null, 'migration', 'migration', 'migration', array('subject_type' => 'schema', 'subject_id' => '59', 'detail' => array('from' => 58, 'to' => 59,
				'backup' => $backup, 'accounts_named' => $named, 'server_admins_granted' => $granted))));
			return $ok;
		}, function()
		{
			self::$tenancy_ready = false;
		});
	}
	public static function remove_setting($setting)
	{
		return pts_storage_object::remove_in_file(self::$json_storage, $setting);
	}
	protected static function migrate_policy_schema($fresh_database)
	{
		// Schema 59 to 60 (tenancy T2): the settings that let one account see another's results or let systems in unasked only ever move
		// toward closed here, and stay closed if the server is rolled back, because the version before this one honours the same words.
		//   per account: LetOtherGroupsViewResults, LetPublicViewResults, AutoApproveNewSystems -> 0
		//   server settings (the settings file): force_result_sharing and add_new_users_to_account removed, allow_test_profile_creation 0,
		//   account_creation_alt made the closed message when it was the empty text (registration open), tenant_auto_approve forbidden
		// Every value it replaces goes into ONE `migration` row of the server audit. A database created a moment ago has nothing to close.
		$before = array();
		$undo = function() use (&$before)
		{
			// The audit row and the version went back with the transaction: the settings file gets its old values back so the step runs
			// again from the same place (the closed values are never what was there before, so this cannot reopen anything)
			foreach($before as $key => $value)
			{
				if($value === false)
				{
					self::remove_setting($key);
				}
				else
				{
					self::save_setting($key, $value);
				}
			}
		};
		self::migrate_step(60, $fresh_database, function($backup) use ($fresh_database, &$before)
		{
			$ok = true;
			$audit = array('from' => 59, 'to' => 60, 'backup' => $backup);
			foreach(array('LetOtherGroupsViewResults' => 'sharing_with_other_accounts', 'LetPublicViewResults' => 'public_results', 'AutoApproveNewSystems' => 'auto_approve') as $column => $label)
			{
				$accounts = array();
				$result = self::$db->query('SELECT AccountID FROM phoromatic_account_settings WHERE ' . $column . ' IS NOT NULL AND ' . $column . ' <> 0 ORDER BY AccountID');
				while($result && ($row = $result->fetchArray(SQLITE3_ASSOC)))
				{
					$accounts[] = $row['AccountID'];
				}
				$ok = $ok && $result !== false && self::$db->exec('UPDATE phoromatic_account_settings SET ' . $column . ' = 0 WHERE ' . $column . ' IS NOT NULL AND ' . $column . ' <> 0');
				$audit['accounts_with_' . $label] = count($accounts) . ($accounts ? ': ' . implode(',', $accounts) : '');
			}
			// The values as they were before this step touched the settings file. A crash between the settings write and the COMMIT leaves the
			// closed values in the file with the version still 59, so the first run's values are kept in migrate-60.before (under migrate.lock)
			// and the run that follows uses them, not what it finds in the file. Removed once the step has committed.
			$stash = self::phoromatic_path() . 'migrate-60.before';
			$keys = array('force_result_sharing', 'add_new_users_to_account', 'allow_test_profile_creation', 'account_creation_alt', 'tenant_auto_approve');
			$settings_before = is_file($stash) ? json_decode((string)file_get_contents($stash), true) : null;
			if(!is_array($settings_before) || array_keys($settings_before) !== $keys)
			{
				$settings_before = array();
				foreach($keys as $key)
				{
					$settings_before[$key] = self::read_setting($key);
				}
				$old_umask = umask(0077);
				$ok = $ok && @file_put_contents($stash, json_encode($settings_before)) !== false;
				umask($old_umask);
			}
			$closed = array();
			if(!empty($settings_before['force_result_sharing']))
			{
				$closed['force_result_sharing'] = false;
			}
			if(!empty($settings_before['add_new_users_to_account']))
			{
				$closed['add_new_users_to_account'] = false;
			}
			if(!empty($settings_before['allow_test_profile_creation']))
			{
				$closed['allow_test_profile_creation'] = 0;
			}
			if(!is_string($settings_before['account_creation_alt']) || $settings_before['account_creation_alt'] === '')
			{
				// (unset counts: the version before this one treats a missing text as open registration, as it does the empty one)
				$closed['account_creation_alt'] = phoromatic_accounts::DEFAULT_CLOSED_MESSAGE;
			}
			if($settings_before['tenant_auto_approve'] !== 'forbidden')
			{
				$closed['tenant_auto_approve'] = 'forbidden';
			}
			foreach($closed as $key => $value)
			{
				$before[$key] = $settings_before[$key];
				if($value === false)
				{
					self::remove_setting($key);
				}
				else
				{
					self::save_setting($key, $value);
				}
				$ok = $ok && (self::read_setting($key) === $value || ($value === false && self::read_setting($key) === false));
			}
			// The secret for feed links is made here, under the settings lock, so no page has to make it (and wait) the first time
			$ok = $ok && phoromatic_server::ensure_feed_secret() !== false;
			if(!$fresh_database)
			{
				$audit['settings_before'] = array_map(function($v) { return $v === false ? '(not set)' : $v; }, $settings_before);
				$audit['settings_closed'] = array_keys($closed);
				$ok = $ok && phoromatic_server_audit::record(null, 'migration', 'migration', 'migration', array('subject_type' => 'schema', 'subject_id' => '60', 'detail' => $audit));
			}
			return $ok;
		}, $undo);
		if(self::read_database_version() >= 60)
		{
			@unlink(self::phoromatic_path() . 'migrate-60.before');
		}
	}
	public static function prepare_database($read_only = false)
	{
		if(!$read_only && self::storage_problem() !== null)
		{
			self::storage_error_exit();
		}
		self::$json_storage = self::phoromatic_path() . 'phoromatic-settings.pt2so';
		if(!is_file(self::$json_storage))
		{
			$pt2so = new pts_storage_object();
			$pt2so->save_to_file(self::$json_storage);
		}

		$db_file = self::phoromatic_path() . 'phoromatic.db';
		$db_flags = SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE;

		if($read_only && is_file($db_file))
		{
			$db_flags = SQLITE3_OPEN_READONLY;
		}
		else if(is_file($db_file) && !is_writable($db_file))
		{
			echo 'The database file is not writable!' . PHP_EOL . 'DB: ' . $db_file . PHP_EOL;
		}
		else if(!is_writable(dirname($db_file)))
		{
			echo 'The database directory is not writable!' . PHP_EOL . 'DB: ' . dirname($db_file) . PHP_EOL;
		}

		self::$db = new SQLite3($db_file, $db_flags);
		self::$db->busyTimeout(10000);

		if($read_only && is_file($db_file))
		{
			self::$tenancy_ready = self::read_database_version() >= 59;
			return true;
		}

		$start_version = self::read_database_version();
		switch($start_version)
		{
			case 0:
				// Account Database
				self::$db->exec('CREATE TABLE phoromatic_accounts (AccountID TEXT PRIMARY KEY, ValidateID TEXT NOT NULL, CreatedOn TEXT NOT NULL, Salt TEXT NOT NULL)');
				self::$db->exec('CREATE TABLE phoromatic_account_settings (AccountID TEXT PRIMARY KEY, ArchiveResultsLocally INTEGER, UploadSystemLogs INTEGER DEFAULT 1, RunInstallCommand INTEGER DEFAULT 1, ForceInstallTests INTEGER, SystemSensorMonitoring INTEGER)');
				self::$db->exec('CREATE TABLE phoromatic_users (UserID TEXT PRIMARY KEY, AccountID TEXT NOT NULL, UserName TEXT UNIQUE, Email TEXT, Password TEXT NOT NULL, CreatedOn TEXT NOT NULL, LastLogin TEXT, LastIP TEXT)');
				self::$db->exec('CREATE TABLE phoromatic_schedules (AccountID TEXT, ScheduleID INTEGER, Title TEXT, Description TEXT, State INTEGER, ActiveOn TEXT, RunAt TEXT, SetContextPreInstall TEXT, SetContextPostInstall TEXT, SetContextPreRun TEXT, SetContextPostRun TEXT, LastModifiedBy TEXT, LastModifiedOn TEXT, PublicKey TEXT, UNIQUE(AccountID, ScheduleID) ON CONFLICT IGNORE)');
				//self::$db->exec('CREATE TABLE phoromatic_schedules_systems (AccountID TEXT UNIQUE, ScheduleID INTEGER UNIQUE, SystemID TEXT UNIQUE)');
				self::$db->exec('CREATE TABLE phoromatic_schedules_tests (AccountID TEXT, ScheduleID INTEGER, TestProfile TEXT, TestArguments TEXT, TestDescription TEXT, UNIQUE(AccountID, ScheduleID, TestProfile, TestArguments) ON CONFLICT REPLACE)');
				self::$db->exec('CREATE TABLE phoromatic_schedules_triggers (AccountID TEXT, ScheduleID INTEGER, Trigger TEXT, TriggerTarget TEXT, TriggeredOn TEXT, UNIQUE(AccountID, ScheduleID, Trigger) ON CONFLICT IGNORE)');
				self::$db->exec('CREATE TABLE phoromatic_user_settings (AccountID TEXT, UserID TEXT, NotifyOnResultUploads INTEGER, NotifyOnWarnings INTEGER, NotifyOnNewSystems INTEGER, UNIQUE(AccountID, UserID) ON CONFLICT IGNORE)');
				self::$db->exec('CREATE TABLE phoromatic_systems (AccountID TEXT, SystemID TEXT, Title TEXT, Description TEXT, Groups TEXT, Hardware TEXT, Software TEXT, ClientVersion TEXT, GSID TEXT, CurrentTask TEXT, EstimatedTimeForTask TEXT, CreatedOn TEXT, LastCommunication TEXT, LastIP TEXT, State INTEGER, LocalIP TEXT, NetworkMAC TEXT, Flags TEXT, UNIQUE(AccountID, SystemID) ON CONFLICT IGNORE)');
				self::$db->exec('CREATE TABLE phoromatic_system_warnings (AccountID TEXT, SystemID TEXT, Warning TEXT, WarningTime TEXT)');
				self::$db->exec('CREATE TABLE phoromatic_results (AccountID TEXT, UploadID INTEGER, ScheduleID INTEGER, Trigger TEXT, UploadTime TEXT, Title TEXT, OpenBenchmarkingID TEXT, SystemID TEXT, UNIQUE(AccountID, UploadID) ON CONFLICT IGNORE)');
				self::$db->exec('CREATE TABLE phoromatic_groups (AccountID TEXT, GroupName TEXT, Description TEXT, UNIQUE(AccountID, GroupName) ON CONFLICT IGNORE)');

				self::$db->exec('PRAGMA user_version = 1');
			case 1:
				// phoromatic_results changes for schema mostly from OB
				// Changes made 20 September / post 5.4-M1
				self::$db->exec('ALTER TABLE phoromatic_results ADD COLUMN Description TEXT');
				self::$db->exec('ALTER TABLE phoromatic_results ADD COLUMN SystemCount INTEGER');
				self::$db->exec('ALTER TABLE phoromatic_results ADD COLUMN ResultCount INTEGER');
				self::$db->exec('ALTER TABLE phoromatic_results ADD COLUMN DisplayStatus INTEGER DEFAULT 1');
				self::$db->exec('ALTER TABLE phoromatic_results ADD COLUMN TimesViewed INTEGER DEFAULT 0');
				self::$db->exec('ALTER TABLE phoromatic_results ADD COLUMN XmlUploadHash TEXT');
				self::$db->exec('ALTER TABLE phoromatic_results ADD COLUMN ComparisonHash TEXT');
				// Add phoromatic_results_results as test_results_results equivalent from OB
				self::$db->exec('CREATE TABLE phoromatic_results_results (AccountID TEXT, UploadID INTEGER, AbstractID INTEGER, TestProfile TEXT, ComparisonHash TEXT, UNIQUE(AccountID, UploadID, AbstractID) ON CONFLICT IGNORE)');
				self::$db->exec('CREATE TABLE phoromatic_results_systems (AccountID TEXT, UploadID INTEGER, SystemIdentifier TEXT, Hardware TEXT, Software TEXT, UNIQUE(AccountID, UploadID, SystemIdentifier) ON CONFLICT IGNORE)');
				self::$db->exec('PRAGMA user_version = 2');
			case 2:
				// Change made 4 October to introduce machine self ID as a new identifier for local systems without Internet not having OpenBenchmarking.org GSID, etc
				self::$db->exec('ALTER TABLE phoromatic_systems ADD COLUMN MachineSelfID TEXT');
				self::$db->exec('PRAGMA user_version = 3');
			case 3:
				// Change made 8 October for targeting the SystemID / GroupNames of systems to test in schedules
				self::$db->exec('ALTER TABLE phoromatic_schedules ADD COLUMN RunTargetSystems TEXT');
				self::$db->exec('ALTER TABLE phoromatic_schedules ADD COLUMN RunTargetGroups TEXT');
				self::$db->exec('PRAGMA user_version = 4');
			case 4:
				// Change made 11 October for administrative level
				self::$db->exec('ALTER TABLE phoromatic_users ADD COLUMN AdminLevel INTEGER DEFAULT 1');
				self::$db->exec('PRAGMA user_version = 5');
			case 5:
				self::$db->exec('CREATE TABLE phoromatic_activity_stream (AccountID TEXT, ActivityTime TEXT, ActivityCreator TEXT, ActivityCreatorType TEXT, ActivityEvent TEXT, ActivityEventID TEXT, ActivityEventType TEXT)');
				self::$db->exec('PRAGMA user_version = 6');
			case 6:
				self::$db->exec('CREATE TABLE phoromatic_system_client_errors (AccountID TEXT, SystemID TEXT, UploadTime TEXT, ScheduleID INTEGER, TriggerID TEXT, ErrorMessage TEXT, TestIdentifier TEXT, TestArguments TEXT)');
				self::$db->exec('PRAGMA user_version = 7');
			case 7:
				// Change made 11 October for administrative level
				self::$db->exec('ALTER TABLE phoromatic_account_settings ADD COLUMN UploadResultsToOpenBenchmarking INTEGER DEFAULT 0');
				self::$db->exec('PRAGMA user_version = 8');
			case 8:
				// Change made 24 November 2014 Wake On LAN info for client systems
				self::$db->exec('ALTER TABLE phoromatic_systems ADD COLUMN NetworkWakeOnLAN TEXT');
				self::$db->exec('PRAGMA user_version = 9');
			case 9:
				// Change made 24 November 2014 for new user/account settings
				self::$db->exec('ALTER TABLE phoromatic_user_settings ADD COLUMN NotifyOnHungSystems INTEGER DEFAULT 0');
				self::$db->exec('ALTER TABLE phoromatic_account_settings ADD COLUMN PowerOffWhenDone INTEGER DEFAULT 0');
				self::$db->exec('ALTER TABLE phoromatic_account_settings ADD COLUMN NetworkPowerUpWhenNeeded INTEGER DEFAULT 0');
				self::$db->exec('PRAGMA user_version = 10');
			case 10:
				// Change made 25 November for user context logging
				self::$db->exec('CREATE TABLE phoromatic_system_context_logs (AccountID TEXT, SystemID TEXT, UploadTime TEXT, ScheduleID INTEGER, TriggerID TEXT, UserContextStep TEXT, UserContextLog TEXT)');
				self::$db->exec('PRAGMA user_version = 11');
			case 11:
				// Change made 27 November for time elapsed during benchmarking
				self::$db->exec('ALTER TABLE phoromatic_results ADD COLUMN ElapsedTime INTEGER DEFAULT 0');
				self::$db->exec('PRAGMA user_version = 12');
			case 12:
				// Change made 27 November for IP/MAC address claiming to accounts
				self::$db->exec('CREATE TABLE phoromatic_system_association_claims (AccountID TEXT, IPAddress TEXT, NetworkMAC TEXT, CreationTime TEXT, UNIQUE(IPAddress, NetworkMAC) ON CONFLICT IGNORE)');
				self::$db->exec('PRAGMA user_version = 13');
			case 13:
				// Change made 30 November for percent complete
				self::$db->exec('ALTER TABLE phoromatic_systems ADD COLUMN TaskPercentComplete INTEGER DEFAULT 0');
				self::$db->exec('PRAGMA user_version = 14');
			case 14:
				// Change made 1 December for more reporting features
				self::$db->exec('ALTER TABLE phoromatic_systems ADD COLUMN CurrentProcessSchedule INTEGER');
				self::$db->exec('ALTER TABLE phoromatic_systems ADD COLUMN TimeToNextCommunication INTEGER DEFAULT 0');
				self::$db->exec('PRAGMA user_version = 15');
			case 15:
				// Change made 1 December for maintenance mode
				self::$db->exec('ALTER TABLE phoromatic_systems ADD COLUMN MaintenanceMode INTEGER DEFAULT 0');
				self::$db->exec('PRAGMA user_version = 16');
			case 16:
				// Change made 31 January for group name
				self::$db->exec('ALTER TABLE phoromatic_accounts ADD COLUMN GroupName TEXT');
				self::$db->exec('PRAGMA user_version = 17');
			case 17:
				// Change made 31 January for Phoromatic Public Result ID
				self::$db->exec('ALTER TABLE phoromatic_results ADD COLUMN PPRID TEXT');
				self::$db->exec('PRAGMA user_version = 18');
			case 18:
				// Change made 31 January for Phoromatic Public Result ID
				self::rebuild_pprid_entries();
				self::$db->exec('CREATE UNIQUE INDEX IF NOT EXISTS public_result_id ON phoromatic_results (PPRID)');
				self::$db->exec('PRAGMA user_version = 19');
			case 19:
				// Change made 31 January
				self::$db->exec('ALTER TABLE phoromatic_account_settings ADD COLUMN LetOtherGroupsViewResults INTEGER DEFAULT 0');
				self::$db->exec('PRAGMA user_version = 20');
			case 20:
				// Change made 4 February
				self::$db->exec('ALTER TABLE phoromatic_account_settings ADD COLUMN PreSeedTestInstalls INTEGER DEFAULT 0');
				self::$db->exec('PRAGMA user_version = 21');
			case 21:
				// Change made 8 February
				self::$db->exec('CREATE TABLE phoromatic_benchmark_tickets (AccountID TEXT, TicketID INTEGER, TicketIssueTime TEXT, Title TEXT, ResultIdentifier TEXT, SuiteToRun TEXT, Description TEXT, State INTEGER DEFAULT 1, LastModifiedBy TEXT, LastModifiedOn TEXT, RunTargetSystems TEXT, RunTargetGroups TEXT, UNIQUE(AccountID, TicketID) ON CONFLICT IGNORE)');
				self::$db->exec('PRAGMA user_version = 22');
			case 22:
				// Change made 8 February
				self::$db->exec('ALTER TABLE phoromatic_results ADD COLUMN BenchmarkTicketID INTEGER');
				self::$db->exec('PRAGMA user_version = 23');
			case 23:
				// Change made 24 February
				self::$db->exec('ALTER TABLE phoromatic_systems ADD COLUMN SystemVariables TEXT');
				self::$db->exec('PRAGMA user_version = 24');
			case 24:
				// Change made 24 February
				self::$db->exec('ALTER TABLE phoromatic_benchmark_tickets ADD COLUMN EnvironmentVariables TEXT');
				self::$db->exec('PRAGMA user_version = 25');
			case 25:
				// Change made 10 March
				self::$db->exec('CREATE TABLE phoromatic_annotations (AccountID TEXT, Type TEXT, ID TEXT, SecondaryID TEXT, AnnotatedTime TEXT, AnnotatedBy TEXT, Annotation TEXT)');
				self::$db->exec('PRAGMA user_version = 26');
			case 26:
				// Change made 26 March
				self::$db->exec('ALTER TABLE phoromatic_systems ADD COLUMN BlockPowerOffs INTEGER DEFAULT 0');
				self::$db->exec('PRAGMA user_version = 27');
			case 27:
				// Change made 27 March
				self::$db->exec('ALTER TABLE phoromatic_account_settings ADD COLUMN PowerOnSystemDaily INTEGER DEFAULT 0');
				self::$db->exec('PRAGMA user_version = 28');
			case 28:
				// Change made 13 April
				self::$db->exec('ALTER TABLE phoromatic_account_settings ADD COLUMN LetPublicViewResults INTEGER DEFAULT 0');
				self::$db->exec('PRAGMA user_version = 29');
			case 29:
				// Change made 12 May
				self::$db->exec('ALTER TABLE phoromatic_systems ADD COLUMN TickThreadEvent TEXT');
				self::$db->exec('PRAGMA user_version = 30');
			case 30:
				// Change made 3 June
				self::$db->exec('ALTER TABLE phoromatic_systems ADD COLUMN CoreVersion INTEGER');
				self::$db->exec('PRAGMA user_version = 31');
			case 31:
				// Change made 5 June
				self::$db->exec('CREATE TABLE phoromatic_schedules_trigger_skips (AccountID TEXT, ScheduleID INTEGER, Trigger TEXT, UNIQUE(AccountID, ScheduleID, Trigger) ON CONFLICT IGNORE)');
				self::$db->exec('PRAGMA user_version = 32');
			case 32:
				// Change made 10 June
				self::$db->exec('ALTER TABLE phoromatic_schedules_triggers ADD COLUMN SubTarget TEXT');
				self::$db->exec('PRAGMA user_version = 33');
			case 33:
				// Change made 4 October
				self::$db->exec('ALTER TABLE phoromatic_account_settings ADD COLUMN AutoApproveNewSystems INTEGER DEFAULT 0');
				self::$db->exec('PRAGMA user_version = 34');
			case 34:
				// Change made 7 January 2016
				self::$db->exec('ALTER TABLE phoromatic_account_settings ADD COLUMN LimitNetworkCommunication INTEGER DEFAULT 0');
				self::$db->exec('PRAGMA user_version = 35');
			case 35:
				// Change made 15 April 2016
				self::$db->exec('ALTER TABLE phoromatic_systems ADD COLUMN CurrentProcessTicket INTEGER DEFAULT 0');
				self::$db->exec('PRAGMA user_version = 36');
			case 36:
				// Change made 30 May 2017 for introducing run priority
				self::$db->exec('ALTER TABLE phoromatic_schedules ADD COLUMN RunPriority INTEGER DEFAULT 100');
				self::$db->exec('PRAGMA user_version = 37');
			case 37:
				// Previously these were called on each load but no reason to redundantly do so...
				self::$db->exec('PRAGMA journal_mode = WAL');
				self::$db->exec('PRAGMA synchronous = NORMAL');
				self::$db->exec('PRAGMA user_version = 38');
			case 38:
			case 39:
				// Change made 15 May 2021
				self::$db->exec('ALTER TABLE phoromatic_systems ADD COLUMN SystemProperties TEXT');
				self::$db->exec('PRAGMA user_version = 40');
			case 40:
				// Change made 6 July 2021
				self::$db->exec('ALTER TABLE phoromatic_account_settings ADD COLUMN AllowAnyDataForLogFiles INTEGER DEFAULT 0');
				self::$db->exec('PRAGMA user_version = 41');
			case 41:
				// Change made November 2021
				self::$db->exec('ALTER TABLE phoromatic_account_settings ADD COLUMN UploadInstallLogs INTEGER DEFAULT 0');
				self::$db->exec('ALTER TABLE phoromatic_account_settings ADD COLUMN UploadRunLogs INTEGER DEFAULT 0');
				self::$db->exec('PRAGMA user_version = 42');
			case 42:
				// Change made November 2021
				self::$db->exec('ALTER TABLE phoromatic_schedules ADD COLUMN EnvironmentVariables TEXT');
				self::$db->exec('PRAGMA user_version = 43');
			case 43:
				// Change made December 2021
				self::$db->exec('ALTER TABLE phoromatic_account_settings ADD COLUMN GlobalEnvironmentVariables TEXT');
				self::$db->exec('PRAGMA user_version = 44');
			case 44:
				// Change made December 2021
				self::$db->exec('ALTER TABLE phoromatic_account_settings ADD COLUMN ProgressiveResultUploads INTEGER DEFAULT 0');
				self::$db->exec('ALTER TABLE phoromatic_results ADD COLUMN InProgress INTEGER DEFAULT 0');
				self::$db->exec('PRAGMA user_version = 45');
			case 45:
				// Change made 30 September 2026: append-only audit trail for the private test repository
				self::$db->exec('CREATE TABLE IF NOT EXISTS phoromatic_private_repo_audit (AuditID INTEGER PRIMARY KEY AUTOINCREMENT, AccountID TEXT NOT NULL, EventTime TEXT NOT NULL, ActorType TEXT NOT NULL, Actor TEXT NOT NULL, ActorDetail TEXT, SourceIP TEXT, Action TEXT NOT NULL, Kind TEXT, Name TEXT, Version TEXT, Sha256 TEXT, Sha1 TEXT, PrevSha256 TEXT, Detail TEXT)');
				self::$db->exec('CREATE INDEX IF NOT EXISTS phoromatic_private_repo_audit_account_time ON phoromatic_private_repo_audit (AccountID, EventTime DESC)');
				self::$db->exec('CREATE INDEX IF NOT EXISTS phoromatic_private_repo_audit_failures ON phoromatic_private_repo_audit (Action, SourceIP, EventTime)');
				self::$db->exec('CREATE TRIGGER IF NOT EXISTS phoromatic_private_repo_audit_no_update BEFORE UPDATE ON phoromatic_private_repo_audit BEGIN SELECT RAISE(ABORT, \'audit rows are append-only\'); END');
				self::$db->exec('CREATE TRIGGER IF NOT EXISTS phoromatic_private_repo_audit_no_delete BEFORE DELETE ON phoromatic_private_repo_audit BEGIN SELECT RAISE(ABORT, \'audit rows are append-only\'); END');
				self::$db->exec('PRAGMA user_version = 46');
			case 46:
				// Change made 1 October 2026: repository tokens for the CLI upload/delete/rebuild commands (only a hash is stored)
				self::$db->exec('CREATE TABLE IF NOT EXISTS phoromatic_private_repo_tokens (TokenID INTEGER PRIMARY KEY AUTOINCREMENT, AccountID TEXT NOT NULL, Label TEXT NOT NULL, TokenHash TEXT NOT NULL UNIQUE, CreatedOn TEXT NOT NULL, CreatedBy TEXT NOT NULL, LastUsedOn TEXT, LastUsedIP TEXT, RevokedOn TEXT)');
				self::$db->exec('CREATE INDEX IF NOT EXISTS phoromatic_private_repo_tokens_account ON phoromatic_private_repo_tokens (AccountID)');
				self::$db->exec('PRAGMA user_version = 47');
			case 47:
				// Change made 1 October 2026: the audit page filters by name
				self::$db->exec('CREATE INDEX IF NOT EXISTS phoromatic_private_repo_audit_name ON phoromatic_private_repo_audit (AccountID, Name, AuditID)');
				self::$db->exec('PRAGMA user_version = 48');
			case 48:
				// Change made 2 October 2026: deleted systems become tombstones (State stays -1, DeletedOn set) so a SystemID is never reused
				// (only adds a column that is missing, so a half-applied or repeated step cannot fail on "duplicate column")
				$existing_columns = array();
				$column_result = self::$db->query('PRAGMA table_info(phoromatic_systems)');
				while($column_result && $column_row = $column_result->fetchArray(SQLITE3_ASSOC))
				{
					$existing_columns[] = $column_row['name'];
				}
				foreach(array('DeletedOn', 'DeletedBy', 'DeletedMachineSelfID', 'DeletedNetworkMAC') as $new_column)
				{
					if(!in_array($new_column, $existing_columns))
					{
						self::$db->exec('ALTER TABLE phoromatic_systems ADD COLUMN ' . $new_column . ' TEXT');
					}
				}
				self::$db->exec('CREATE INDEX IF NOT EXISTS phoromatic_systems_msi ON phoromatic_systems (MachineSelfID)');
				self::$db->exec('CREATE INDEX IF NOT EXISTS phoromatic_systems_mac ON phoromatic_systems (NetworkMAC)');
				self::$db->exec('PRAGMA user_version = 49');
			case 49:
				// Change made 2 October 2026: append-only audit trail for system (and later bench) lifecycle events
				self::$db->exec('CREATE TABLE IF NOT EXISTS phoromatic_admin_audit (AuditID INTEGER PRIMARY KEY AUTOINCREMENT, AccountID TEXT NOT NULL, EventTime TEXT NOT NULL, ActorType TEXT NOT NULL, Actor TEXT NOT NULL, ActorDetail TEXT, SourceIP TEXT, Action TEXT NOT NULL, SubjectType TEXT, SubjectID TEXT, SubjectLabel TEXT, Detail TEXT)');
				self::$db->exec('CREATE INDEX IF NOT EXISTS phoromatic_admin_audit_account_time ON phoromatic_admin_audit (AccountID, EventTime DESC)');
				self::$db->exec('CREATE TRIGGER IF NOT EXISTS phoromatic_admin_audit_no_update BEFORE UPDATE ON phoromatic_admin_audit BEGIN SELECT RAISE(ABORT, \'audit rows are append-only\'); END');
				self::$db->exec('CREATE TRIGGER IF NOT EXISTS phoromatic_admin_audit_no_delete BEFORE DELETE ON phoromatic_admin_audit BEGIN SELECT RAISE(ABORT, \'audit rows are append-only\'); END');
				self::$db->exec('PRAGMA user_version = 50');
			case 50:
				// Change made 2 October 2026: INSERT OR REPLACE with an existing AuditID replaces a row without firing the DELETE trigger,
				// so refuse an insert that reuses an AuditID (both audit tables)
				foreach(array('phoromatic_private_repo_audit', 'phoromatic_admin_audit') as $audit_table)
				{
					self::$db->exec('CREATE TRIGGER IF NOT EXISTS ' . $audit_table . '_no_replace BEFORE INSERT ON ' . $audit_table . ' WHEN NEW.AuditID IS NOT NULL AND EXISTS (SELECT 1 FROM ' . $audit_table . ' WHERE AuditID = NEW.AuditID) BEGIN SELECT RAISE(ABORT, \'audit rows are append-only\'); END');
				}
				self::$db->exec('PRAGMA user_version = 51');
			case 51:
				// Change made 2 October 2026: a client that reports the MAC of an existing system but a different machine id is no longer adopted
				// automatically; it registers as a new pending row whose AdoptionCandidate names the existing system (an administrator decides)
				// (repeatable: only adds what is missing)
				$existing_columns = array();
				$column_result = self::$db->query('PRAGMA table_info(phoromatic_systems)');
				while($column_result && $column_row = $column_result->fetchArray(SQLITE3_ASSOC))
				{
					$existing_columns[] = $column_row['name'];
				}
				if(!in_array('AdoptionCandidate', $existing_columns))
				{
					self::$db->exec('ALTER TABLE phoromatic_systems ADD COLUMN AdoptionCandidate TEXT');
				}
				self::$db->exec('CREATE INDEX IF NOT EXISTS phoromatic_systems_msi ON phoromatic_systems (MachineSelfID)');
				self::$db->exec('CREATE INDEX IF NOT EXISTS phoromatic_systems_mac ON phoromatic_systems (NetworkMAC)');
				self::$db->exec('PRAGMA user_version = 52');
			case 52:
				// Change made 27 October 2026 (Benchy 0.8): hardware test benches. A bench is its own entity owning its system(s); one
				// component under test per session; a drift baseline per (session, system). Additive and repeatable: tables and indexes
				// IF NOT EXISTS, and only the columns that are missing are added. case 53 (0.9) drops phoromatic_systems_one_per_bench (dual-boot).
				self::$db->exec('CREATE TABLE IF NOT EXISTS phoromatic_benches (BenchID INTEGER PRIMARY KEY AUTOINCREMENT, AccountID TEXT NOT NULL, Title TEXT NOT NULL, Notes TEXT, CreatedOn TEXT NOT NULL, CreatedBy TEXT NOT NULL, RetiredOn TEXT, RetiredBy TEXT)');
				self::$db->exec('CREATE INDEX IF NOT EXISTS phoromatic_benches_account ON phoromatic_benches (AccountID, RetiredOn)');
				self::$db->exec('CREATE TABLE IF NOT EXISTS phoromatic_components (ComponentID INTEGER PRIMARY KEY AUTOINCREMENT, AccountID TEXT NOT NULL, Class TEXT NOT NULL, Label TEXT NOT NULL, Vendor TEXT, Model TEXT, Serial TEXT, Revision TEXT, Notes TEXT, PhotoURL TEXT, DetectedJSON TEXT, CreatedOn TEXT NOT NULL, CreatedBy TEXT NOT NULL, RetiredOn TEXT, RetiredBy TEXT)');
				self::$db->exec('CREATE INDEX IF NOT EXISTS phoromatic_components_account ON phoromatic_components (AccountID, Class, RetiredOn)');
				self::$db->exec('CREATE UNIQUE INDEX IF NOT EXISTS phoromatic_components_live_label ON phoromatic_components (AccountID, lower(Label)) WHERE RetiredOn IS NULL');
				self::$db->exec('CREATE TABLE IF NOT EXISTS phoromatic_bench_sessions (SessionID INTEGER PRIMARY KEY AUTOINCREMENT, AccountID TEXT NOT NULL, BenchID INTEGER NOT NULL, ComponentID INTEGER NOT NULL, Config TEXT, StartedOn TEXT NOT NULL, StartedBy TEXT NOT NULL, EndedOn TEXT, EndedBy TEXT, EndReason TEXT, Notes TEXT)');
				self::$db->exec('CREATE UNIQUE INDEX IF NOT EXISTS phoromatic_bench_sessions_open ON phoromatic_bench_sessions (AccountID, BenchID) WHERE EndedOn IS NULL');
				self::$db->exec('CREATE INDEX IF NOT EXISTS phoromatic_bench_sessions_bench ON phoromatic_bench_sessions (AccountID, BenchID, StartedOn)');
				self::$db->exec('CREATE INDEX IF NOT EXISTS phoromatic_bench_sessions_component ON phoromatic_bench_sessions (AccountID, ComponentID)');
				self::$db->exec('CREATE UNIQUE INDEX IF NOT EXISTS phoromatic_bench_sessions_component_open ON phoromatic_bench_sessions (AccountID, ComponentID) WHERE EndedOn IS NULL');
				self::$db->exec('CREATE TABLE IF NOT EXISTS phoromatic_bench_baselines (AccountID TEXT NOT NULL, SessionID INTEGER NOT NULL, SystemID TEXT NOT NULL, StartHardware TEXT, StartJSON TEXT, BaselineJSON TEXT, BaselineOn TEXT, AwaitingFromJSON TEXT, UNIQUE(AccountID, SessionID, SystemID))');
				foreach(array('phoromatic_systems' => array('BenchID' => 'INTEGER', 'BenchTaskSession' => 'INTEGER', 'BenchTaskIssuedOn' => 'TEXT', 'BenchDrift' => 'TEXT'),
					'phoromatic_results' => array('BenchSessionID' => 'INTEGER', 'BenchSessionSource' => 'TEXT', 'BenchDeviation' => 'TEXT')) as $table => $new_columns)
				{
					$existing_columns = array();
					$column_result = self::$db->query('PRAGMA table_info(' . $table . ')');
					while($column_result && $column_row = $column_result->fetchArray(SQLITE3_ASSOC))
					{
						$existing_columns[] = $column_row['name'];
					}
					foreach($new_columns as $new_column => $type)
					{
						if(!in_array($new_column, $existing_columns))
						{
							self::$db->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $new_column . ' ' . $type);
						}
					}
				}
				self::$db->exec('CREATE UNIQUE INDEX IF NOT EXISTS phoromatic_systems_one_per_bench ON phoromatic_systems (AccountID, BenchID) WHERE BenchID IS NOT NULL AND DeletedOn IS NULL');
				self::$db->exec('CREATE INDEX IF NOT EXISTS phoromatic_results_bench_session ON phoromatic_results (AccountID, BenchSessionID)');
				self::$db->exec('PRAGMA user_version = 53');
			case 53:
				// Change made 28 October 2026 (Benchy 0.9): multi-system benches (dual-boot, one system per OS install). Only the one-live-
				// system-per-bench index goes; every bench rule of 0.8 already covers all members. Repeatable; 0.8 code runs on the result.
				self::$db->exec('DROP INDEX IF EXISTS phoromatic_systems_one_per_bench');
				self::$db->exec('PRAGMA user_version = 54');
			case 54:
				// Change made 28 October 2026 (Benchy 0.9, 2C): bench tokens (bbt_, their own type: one bench, optionally one member
				// system; only the SHA-256 is stored) and "rerun ticket on this component" requests. Additive and repeatable.
				self::$db->exec('CREATE TABLE IF NOT EXISTS phoromatic_bench_tokens (TokenID INTEGER PRIMARY KEY AUTOINCREMENT, AccountID TEXT NOT NULL, BenchID INTEGER NOT NULL, SystemID TEXT, Label TEXT NOT NULL, TokenHash TEXT NOT NULL UNIQUE, CreatedOn TEXT NOT NULL, CreatedBy TEXT NOT NULL, LastUsedOn TEXT, LastUsedIP TEXT, LastUsedSystem TEXT, RevokedOn TEXT, RevokedBy TEXT, RevokeReason TEXT)');
				self::$db->exec('CREATE INDEX IF NOT EXISTS phoromatic_bench_tokens_bench ON phoromatic_bench_tokens (AccountID, BenchID, RevokedOn)');
				self::$db->exec('CREATE TABLE IF NOT EXISTS phoromatic_bench_ticket_reruns (AccountID TEXT NOT NULL, TicketID INTEGER NOT NULL, BenchID INTEGER NOT NULL, SessionID INTEGER NOT NULL, RequestedOn TEXT NOT NULL, RequestedBy TEXT NOT NULL, UNIQUE(AccountID, TicketID, SessionID))');
				self::$db->exec('PRAGMA user_version = 55');
			case 55:
				// Change made 29 October 2026 (Benchy 0.9, built-in HTTPS): which scheme a system last checked in over, so the systems list can
				// show who is still on plain HTTP before http_mode goes to redirect. Additive; repeatable (the column is checked first).
				$have_last_scheme = false;
				$cols = self::$db->query('PRAGMA table_info(phoromatic_systems)');
				while($cols && ($col = $cols->fetchArray(SQLITE3_ASSOC)))
				{
					if($col['name'] == 'LastScheme')
					{
						$have_last_scheme = true;
					}
				}
				if(!$have_last_scheme)
				{
					self::$db->exec('ALTER TABLE phoromatic_systems ADD COLUMN LastScheme TEXT');
				}
				self::$db->exec('PRAGMA user_version = 56');
			case 56:
				// Change made 30 October 2026 (Benchy 0.9.1, multi-component sessions): every component of a session (the primary, position 0,
				// is still phoromatic_bench_sessions.ComponentID, so 0.9 code keeps working and shows the primary), with an open flag so a part
				// is in one open session only; the classes a member is waiting to see swapped. Additive and repeatable; the backfill ignores rows
				// it already made.
				self::$db->exec('CREATE TABLE IF NOT EXISTS phoromatic_bench_session_components (AccountID TEXT NOT NULL, SessionID INTEGER NOT NULL, ComponentID INTEGER NOT NULL, Position INTEGER NOT NULL, OpenFlag INTEGER, UNIQUE(AccountID, SessionID, ComponentID), UNIQUE(AccountID, SessionID, Position))');
				self::$db->exec('CREATE UNIQUE INDEX IF NOT EXISTS phoromatic_bench_session_components_open ON phoromatic_bench_session_components (AccountID, ComponentID) WHERE OpenFlag = 1');
				self::$db->exec('INSERT OR IGNORE INTO phoromatic_bench_session_components (AccountID, SessionID, ComponentID, Position, OpenFlag) SELECT AccountID, SessionID, ComponentID, 0, CASE WHEN EndedOn IS NULL THEN 1 END FROM phoromatic_bench_sessions');
				$have_awaiting_classes = false;
				$cols = self::$db->query('PRAGMA table_info(phoromatic_bench_baselines)');
				while($cols && ($col = $cols->fetchArray(SQLITE3_ASSOC)))
				{
					if($col['name'] == 'AwaitingClasses')
					{
						$have_awaiting_classes = true;
					}
				}
				if(!$have_awaiting_classes)
				{
					self::$db->exec('ALTER TABLE phoromatic_bench_baselines ADD COLUMN AwaitingClasses TEXT');
				}
				self::$db->exec('PRAGMA user_version = 57');
			case 57:
				// Change made 2 November 2026 (Benchy 0.9.4, native agents, protocol v2): enrollment codes (ben_) and system agent tokens
				// (bsa_), both stored as SHA-256 only; the jobs handed to native agents; the native test rows of a schedule (kept apart from
				// phoromatic_schedules_tests so a PHP client can never be handed a benchy/ ref); each native system's catalogue of runnable
				// tests; and the system's engine policy (NULL is the PTS engine). Additive and repeatable: IF NOT EXISTS, and the column is
				// added only when it is missing.
				self::$db->exec('CREATE TABLE IF NOT EXISTS phoromatic_enrol_codes (CodeID INTEGER PRIMARY KEY AUTOINCREMENT, AccountID TEXT NOT NULL, CodeHash TEXT NOT NULL UNIQUE, SystemID TEXT, SetPolicy TEXT, Label TEXT NOT NULL, MaxUses INTEGER NOT NULL DEFAULT 1, Uses INTEGER NOT NULL DEFAULT 0, ExpiresOn TEXT NOT NULL, CreatedOn TEXT NOT NULL, CreatedBy TEXT NOT NULL, RevokedOn TEXT, RevokedBy TEXT)');
				self::$db->exec('CREATE TABLE IF NOT EXISTS phoromatic_system_tokens (TokenID INTEGER PRIMARY KEY AUTOINCREMENT, AccountID TEXT NOT NULL, SystemID TEXT NOT NULL, TokenHash TEXT NOT NULL UNIQUE, EnrolCodeID INTEGER, MachineID TEXT, CreatedOn TEXT NOT NULL, LastUsedOn TEXT, LastUsedIP TEXT, RevokedOn TEXT, RevokedBy TEXT, RevokeReason TEXT)');
				self::$db->exec('CREATE INDEX IF NOT EXISTS phoromatic_system_tokens_system ON phoromatic_system_tokens (AccountID, SystemID, RevokedOn)');
				self::$db->exec('CREATE TABLE IF NOT EXISTS phoromatic_v2_failures (Kind TEXT NOT NULL, SourceIP TEXT NOT NULL, At INTEGER NOT NULL)');
				self::$db->exec('CREATE INDEX IF NOT EXISTS phoromatic_v2_failures_ip ON phoromatic_v2_failures (Kind, SourceIP, At)');
				self::$db->exec('CREATE INDEX IF NOT EXISTS phoromatic_v2_failures_at ON phoromatic_v2_failures (At)');
				self::$db->exec('CREATE INDEX IF NOT EXISTS phoromatic_admin_audit_action_ip ON phoromatic_admin_audit (Action, SourceIP, EventTime)');
				self::$db->exec('CREATE TABLE IF NOT EXISTS phoromatic_v2_jobs (JobID INTEGER PRIMARY KEY AUTOINCREMENT, AccountID TEXT NOT NULL, SystemID TEXT NOT NULL, Kind TEXT NOT NULL, ScheduleID INTEGER, TicketID INTEGER, Trigger TEXT, Title TEXT, PayloadJSON TEXT NOT NULL, State TEXT NOT NULL, EndStatus TEXT, EndReason TEXT, RunID TEXT, RunEnded INTEGER, UploadID INTEGER, BenchSessionID INTEGER, IssuedOn TEXT NOT NULL, LastContactOn TEXT, EndedOn TEXT)');
				self::$db->exec('CREATE INDEX IF NOT EXISTS phoromatic_v2_jobs_system ON phoromatic_v2_jobs (AccountID, SystemID, State)');
				self::$db->exec('CREATE UNIQUE INDEX IF NOT EXISTS phoromatic_v2_jobs_run ON phoromatic_v2_jobs (AccountID, SystemID, RunID) WHERE RunID IS NOT NULL');
				self::$db->exec('CREATE TABLE IF NOT EXISTS phoromatic_schedules_native_tests (AccountID TEXT NOT NULL, ScheduleID INTEGER NOT NULL, Position INTEGER NOT NULL, Ref TEXT NOT NULL, OptionsJSON TEXT NOT NULL DEFAULT \'{}\', Description TEXT, AddedOn TEXT NOT NULL, AddedBy TEXT NOT NULL, UNIQUE(AccountID, ScheduleID, Ref, OptionsJSON))');
				self::$db->exec('CREATE TABLE IF NOT EXISTS phoromatic_system_catalogue (AccountID TEXT NOT NULL, SystemID TEXT NOT NULL, Ref TEXT NOT NULL, Version TEXT NOT NULL, TestJSON TEXT NOT NULL, ReportedOn TEXT NOT NULL, PRIMARY KEY (AccountID, SystemID, Ref))');
				$have_engine_policy = false;
				$cols = self::$db->query('PRAGMA table_info(phoromatic_systems)');
				while($cols && ($col = $cols->fetchArray(SQLITE3_ASSOC)))
				{
					if($col['name'] == 'EnginePolicy')
					{
						$have_engine_policy = true;
					}
				}
				if(!$have_engine_policy)
				{
					self::$db->exec('ALTER TABLE phoromatic_systems ADD COLUMN EnginePolicy TEXT');
				}
				self::$db->exec('PRAGMA user_version = 58');
			case 58:
				// Change made 10 November 2026 (Benchy 0.9.11, tenancy T1): see migrate_tenancy_schema(). Unlike the steps above it takes a lock,
				// backs the database up first and commits in one transaction, because several web workers and the event server open the
				// database at once and a once-only grant or audit row must not be made twice.
				self::migrate_tenancy_schema($start_version === 0);
			case 59:
				// Change made 11 November 2026 (Benchy 0.9.12, tenancy T2): see migrate_policy_schema(). Needs schema 59 (the server audit), so it
				// waits when that step did not run.
				if(self::read_database_version() >= 59)
				{
					self::$tenancy_ready = true;   // (a database that arrived at 59: the step writes its audit row through the server audit)
					self::migrate_policy_schema($start_version === 0);
				}
		}
		if(!$read_only && self::read_database_version() >= 58 && !self::$db->querySingle('SELECT 1 FROM sqlite_master WHERE type = \'table\' AND name = \'phoromatic_v2_failures\''))
		{
			// A development database that reached version 58 before this table was part of it. The lockouts need it, so it is made
			// when it is missing, whatever the version says; the lookup above keeps this to one read on every other request.
			self::$db->exec('CREATE TABLE IF NOT EXISTS phoromatic_v2_failures (Kind TEXT NOT NULL, SourceIP TEXT NOT NULL, At INTEGER NOT NULL)');
			self::$db->exec('CREATE INDEX IF NOT EXISTS phoromatic_v2_failures_ip ON phoromatic_v2_failures (Kind, SourceIP, At)');
			self::$db->exec('CREATE INDEX IF NOT EXISTS phoromatic_v2_failures_at ON phoromatic_v2_failures (At)');
		}
		if(!$read_only && self::read_database_version() >= 57)
		{
			// A 0.9.0 server run after the 0.9.1 migration (a rollback and re-upgrade) leaves the session component rows out of step: a
			// session it ended keeps OpenFlag = 1 (a part would stay "under test" for good), and one it started has no row. Mend both
			// whenever the database is opened for writing (every web request and CLI command that calls prepare_database(), not only at
			// start-up), in one pass; the cheap existence checks keep the write (and its lock) for the case that needs it.
			// The flags go first, so that a part freed by a session 0.9.0 ended can be taken by the session it started next.
			$stale = self::$db->querySingle('SELECT 1 FROM phoromatic_bench_session_components j WHERE j.OpenFlag = 1 AND EXISTS (SELECT 1 FROM phoromatic_bench_sessions s WHERE s.AccountID = j.AccountID AND s.SessionID = j.SessionID AND s.EndedOn IS NOT NULL) LIMIT 1');
			if($stale)
			{
				// A part may also be in the row of another open session that has no flag (a double booking, see below). When the stale flag
				// goes, that row takes it (the oldest open one, as when a session ends), or the part would look free while a session has it.
				// One transaction: either the flags move on together or nothing changes, and the next request tries again.
				if(@self::$db->exec('BEGIN IMMEDIATE'))
				{
					$ok = true;
					$freed = array();
					$stale_rows = self::$db->query('SELECT DISTINCT j.AccountID, j.ComponentID FROM phoromatic_bench_session_components j WHERE j.OpenFlag = 1 AND EXISTS (SELECT 1 FROM phoromatic_bench_sessions s WHERE s.AccountID = j.AccountID AND s.SessionID = j.SessionID AND s.EndedOn IS NOT NULL)');
					while($stale_rows && ($f = $stale_rows->fetchArray(SQLITE3_ASSOC)))
					{
						$freed[] = $f;
					}
					$ok = $stale_rows !== false;
					$ok = $ok && self::$db->exec('UPDATE phoromatic_bench_session_components SET OpenFlag = NULL WHERE OpenFlag = 1 AND EXISTS (SELECT 1 FROM phoromatic_bench_sessions s WHERE s.AccountID = phoromatic_bench_session_components.AccountID AND s.SessionID = phoromatic_bench_session_components.SessionID AND s.EndedOn IS NOT NULL)');
					foreach($freed as $f)
					{
						$ok = $ok && phoromatic_bench::flag_oldest_open_row($f['AccountID'], (int)$f['ComponentID']);
					}
					self::$db->exec($ok ? 'COMMIT' : 'ROLLBACK');
				}
			}
			$orphans = self::$db->query('SELECT s.AccountID, s.SessionID, s.BenchID, s.ComponentID, s.EndedOn FROM phoromatic_bench_sessions s WHERE NOT EXISTS (SELECT 1 FROM phoromatic_bench_session_components j WHERE j.AccountID = s.AccountID AND j.SessionID = s.SessionID) ORDER BY s.SessionID');
			$orphan_rows = array();
			while($orphans && ($o = $orphans->fetchArray(SQLITE3_ASSOC)))
			{
				$orphan_rows[] = $o;
			}
			foreach($orphan_rows as $o)
			{
				// The primary of a session 0.9.0 started. Its part may already be an extra part of a live 0.9.1 session (0.9.0 only
				// looks at the primary): the row is still written, so the session's results stay in the class filter and nothing is
				// retried on the next start, but without the open flag (that one belongs to the live row), and the clash is audited.
				$holder = $o['EndedOn'] === null ? self::$db->querySingle('SELECT SessionID FROM phoromatic_bench_session_components WHERE AccountID = \'' . SQLite3::escapeString($o['AccountID']) . '\' AND ComponentID = ' . (int)$o['ComponentID'] . ' AND OpenFlag = 1 LIMIT 1') : null;
				self::$db->exec('INSERT OR IGNORE INTO phoromatic_bench_session_components (AccountID, SessionID, ComponentID, Position, OpenFlag) VALUES (\'' . SQLite3::escapeString($o['AccountID']) . '\', ' . (int)$o['SessionID'] . ', ' . (int)$o['ComponentID'] . ', 0, ' . ($o['EndedOn'] === null && !$holder ? '1' : 'NULL') . ')');
				// Audit only when this request wrote the row: two requests can both see the same orphan, and the second one's INSERT
				// OR IGNORE changes nothing
				if($holder && self::$db->changes() == 1)
				{
					phoromatic_admin_audit::record($o['AccountID'], 'system', 'server', 'bench_part_double_booked', array('subject_type' => 'bench', 'subject_id' => $o['BenchID'],
						'detail' => array('session' => 'S' . (int)$o['SessionID'], 'component_id' => (int)$o['ComponentID'], 'also_in_open_session' => 'S' . (int)$holder,
						'note' => 'A Benchy 0.9.0 server made this part the primary of session S' . (int)$o['SessionID'] . ' while the 0.9.1 session S' . (int)$holder . ' still had it; both are open. End S' . (int)$o['SessionID'] . ' (the one the 0.9.0 server started), or both.')));
				}
			}
		}
		self::$tenancy_ready = self::read_database_version() >= 59;
		chmod($db_file, 0600);
		if(!defined('PHOROMATIC_DB_INIT'))
		{
			define('PHOROMATIC_DB_INIT', true);
		}
	}
	public static function generate_result_export_dump($account_id)
	{
		phoromatic_tenant_context::allow($account_id); // the operator names the account
		ini_set('memory_limit', '4G');
		pts_file_io::mkdir(self::phoromatic_path() . 'result-export/');
		$export_path = self::phoromatic_path() . 'result-export/' . $account_id . '/';
		pts_file_io::mkdir($export_path);

		$stmt = phoromatic_server::$db->prepare('SELECT * FROM phoromatic_schedules WHERE AccountID = :account_id AND State = 1 AND (SELECT COUNT(*) FROM phoromatic_schedules_tests WHERE AccountID = :account_id AND ScheduleID = phoromatic_schedules.ScheduleID) > 0 AND (SELECT COUNT(*) FROM phoromatic_results WHERE AccountID = :account_id AND ScheduleID = phoromatic_schedules.ScheduleID) > 4 ORDER BY Title ASC');
		$stmt->bindValue(':account_id', $account_id);
		$result = $stmt->execute();

		$exported_result_index = array('phoromatic' => array());
		$error_index = array('phoromatic' => array());
		while($result && $row = $result->fetchArray())
		{
			$id = str_replace(' ', '-', strtolower($row['Title']));
			$triggers = array();
			$first_time = time();
			$latest_time = 0;
			$stmt2 = phoromatic_server::$db->prepare('SELECT * FROM phoromatic_results WHERE AccountID = :account_id AND ScheduleID = :schedule_id ORDER BY UploadTime DESC');
			$stmt2->bindValue(':account_id', $row['AccountID']);
			$stmt2->bindValue(':schedule_id', $row['ScheduleID']);
			$result2 = $stmt2->execute();
			pts_file_io::mkdir($export_path);

			while($result2 && $row2 = $result2->fetchArray())
			{
				$composite_xml = phoromatic_server::phoromatic_account_result_path($row2['AccountID'], $row2['UploadID']) . 'composite.xml';
				if(is_file($composite_xml))
				{
					// Bench results get " - S<session>" so two sessions on the same day (same trigger) do not overwrite each other
					$system_dir = phoromatic_server::system_id_to_name($row2['SystemID'], $row2['AccountID']) . (!empty($row2['BenchSessionID']) ? ' - S' . (int)$row2['BenchSessionID'] : '');
					pts_file_io::mkdir($export_path . $id . '/' . $row2['Trigger']);
					pts_file_io::mkdir($export_path . $id . '/' . $row2['Trigger'] . '/' . $system_dir);
					copy($composite_xml, $export_path . $id . '/' . $row2['Trigger'] . '/' . $system_dir . '/composite.xml');
				}
				pts_arrays::unique_push($triggers, $row2['Trigger']);

				$utime = strtotime($row2['UploadTime']);
				$first_time = min($first_time, $utime);
				$latest_time = max($latest_time, $utime);
			}

			$exported_result_index['phoromatic'][$id] = array(
				'title' => $row['Title'],
				'id' => $id,
				'description' => $row['Description'],
				'triggers' => $triggers,
				'first_result_time' => $first_time,
				'last_result_time' => $latest_time
				);

			$stmt2 = phoromatic_server::$db->prepare('SELECT * FROM phoromatic_system_client_errors WHERE AccountID = :account_id AND ScheduleID = :schedule_id ORDER BY UploadTime DESC');
			$stmt2->bindValue(':account_id', $row['AccountID']);
			$stmt2->bindValue(':schedule_id', $row['ScheduleID']);
			$result2 = $stmt2->execute();
			$error_index['phoromatic'][$id] = array();
			while($result2 && $row2 = $result2->fetchArray())
			{
				// Only show last month of errors for now
				if(strtotime('-1 month') > strtotime($row2['UploadTime']))
					continue;

				$error_index['phoromatic'][$id][] = array(
					'system' => phoromatic_server::system_id_to_name($row2['SystemID'], $row2['AccountID']),
					'trigger' => $row2['TriggerID'],
					'test' => $row2['TestIdentifier'],
					'test_description' => $row2['TestArguments'],
					'error' => $row2['ErrorMessage'],
					'error_time' => strtotime($row2['UploadTime']),
					);
			}

		}
		$exported_result_index = json_encode($exported_result_index, JSON_PRETTY_PRINT);
		$error_index = json_encode($error_index, JSON_PRETTY_PRINT);
		file_put_contents($export_path . '/export-index.json', $exported_result_index);
		file_put_contents($export_path . '/export-test-errors.json', $error_index);
	}
	public static function mail_available()
	{
		// PHP's mail() hands the message to sendmail_path (a command line: "sendmail -t -i", "/usr/sbin/sendmail -t", "msmtp -t"); with no such
		// program (a server that was never set up to send mail) every call only logs a failure, so the message is skipped instead. The program
		// is the first word: a path, or a name looked for in PATH. On Windows mail() uses the SMTP settings of php.ini, not a program.
		static $available = null;
		if($available === null)
		{
			if(stripos(PHP_OS, 'WIN') === 0)
			{
				return $available = true;
			}
			$command = trim((string)ini_get('sendmail_path'));
			$program = $command === '' ? '' : strtok($command, " \t");
			$available = false;
			if($program !== '' && $program !== false)
			{
				$candidates = strpos($program, '/') !== false ? array($program) : array_map(function($dir) use ($program) { return rtrim($dir, '/') . '/' . $program; }, explode(PATH_SEPARATOR, (string)getenv('PATH')));
				foreach($candidates as $candidate)
				{
					if(is_file($candidate) && is_executable($candidate))
					{
						$available = true;
						break;
					}
				}
			}
		}
		return $available;
	}
	public static function send_email($to, $subject, $from, $body)
	{
		if(!self::mail_available())
		{
			return;
		}
		$msg = '<html><body>' . $body . '
		<hr />
		<p><img src="http://www.phoronix-test-suite.com/web/pts-logo-60.png" /></p>
		<h6><em>The <a href="http://www.phoronix-test-suite.com/">Phoronix Test Suite</a>, <a href="http://www.phoromatic.com/">Phoromatic</a>, and <a href="http://openbenchmarking.org/">OpenBenchmarking.org</a> are products of <a href="http://www.phoronix-media.com/">Phoronix Media</a>.<br />The Phoronix Test Suite is open-source under terms of the GNU GPL. Commercial support, custom engineering, and other services are available by contacting Phoronix Media.<br />&copy; ' . date('Y') . ' Phoronix Media.</em></h6>
		</body></html>';
		$headers = "MIME-Version: 1.0\r\n";
		$headers .= "Content-type:text/html;charset=UTF-8\r\n";
		$headers .= "From: Phoromatic - Phoronix Test Suite <no-reply@phoromatic.com>\r\n";
		$headers .= "Reply-To: " . $from . "\r\n";
		mail($to, $subject, $msg, $headers);
	}
	protected static function rebuild_pprid_entries()
	{
		$stmt = phoromatic_server::$db->prepare('SELECT * FROM phoromatic_results ORDER BY UploadTime ASC');
		$result = $stmt ? $stmt->execute() : false;

		while($result && ($row = $result->fetchArray()))
		{
			$stmt = phoromatic_server::$db->prepare('UPDATE phoromatic_results SET PPRID = :pprid WHERE AccountID = :account_id AND UploadID = :upload_id');
			$stmt->bindValue(':account_id', $row['AccountID']);
			$stmt->bindValue(':upload_id', $row['UploadID']);
			$stmt->bindValue(':pprid', phoromatic_server::compute_pprid($row['AccountID'], $row['SystemID'], $row['UploadTime'], $row['XmlUploadHash']));
			$stmt->execute();
		}
	}
	public static function compute_pprid($account_id, $system_id, $upload_time, $xml_upload_hash)
	{
		return base_convert(sha1($account_id . ' ' . $system_id . ' ' . $xml_upload_hash . ' ' . $upload_time), 16, 36);
	}
	public static function system_id_to_name($system_id, $aid = false)
	{
		static $system_names;

		if(!isset($system_names[$system_id]) || empty($system_names[$system_id]))
		{
			$stmt = phoromatic_server::$db->prepare('SELECT Title FROM phoromatic_systems WHERE AccountID = :account_id AND SystemID = :system_id');
			$stmt->bindValue(':account_id', ($aid ? $aid : $_SESSION['AccountID']));
			$stmt->bindValue(':system_id', $system_id);
			$result = $stmt->execute();
			$row = $result ? $result->fetchArray() : false;
			$system_names[$system_id] = isset($row['Title']) ? $row['Title'] : false;
		}

		return $system_names[$system_id];
	}
	public static function system_id_variables($system_id, $aid = false)
	{
		$stmt = phoromatic_server::$db->prepare('SELECT SystemVariables FROM phoromatic_systems WHERE AccountID = :account_id AND SystemID = :system_id');
		$stmt->bindValue(':account_id', ($aid ? $aid : $_SESSION['AccountID']));
		$stmt->bindValue(':system_id', $system_id);
		$result = $stmt->execute();
		$row = $result ? $result->fetchArray() : false;
		return isset($row['SystemVariables']) ? $row['SystemVariables'] : null;
	}
	public static function schedule_id_to_name($schedule_id, $aid = false)
	{
		static $schedule_names;

		if(!isset($schedule_names[$schedule_id]) || empty($schedule_names[$schedule_id]))
		{
			$stmt = phoromatic_server::$db->prepare('SELECT Title FROM phoromatic_schedules WHERE AccountID = :account_id AND ScheduleID = :schedule_id');
			$stmt->bindValue(':account_id', ($aid ? $aid : $_SESSION['AccountID']));
			$stmt->bindValue(':schedule_id', $schedule_id);
			$result = $stmt->execute();
			$row = $result ? $result->fetchArray() : false;
			$schedule_names[$schedule_id] = isset($row['Title']) ? $row['Title'] : false;
		}

		return $schedule_names[$schedule_id];
	}
	public static function ticket_id_to_name($ticket_id, $aid = false)
	{
		static $ticket_names;

		if(!isset($ticket_names[$ticket_id]) || empty($ticket_names[$ticket_id]))
		{
			$stmt = phoromatic_server::$db->prepare('SELECT Title FROM phoromatic_benchmark_tickets WHERE AccountID = :account_id AND TicketID = :ticket_id');
			$stmt->bindValue(':account_id', ($aid ? $aid : $_SESSION['AccountID']));
			$stmt->bindValue(':ticket_id', $ticket_id);
			$result = $stmt->execute();
			$row = $result ? $result->fetchArray() : false;
			$ticket_names[$ticket_id] = isset($row['Title']) ? $row['Title'] : false;
		}

		return $ticket_names[$ticket_id];
	}
	public static function account_id_to_group_admin_email($account_id)
	{
		$stmt = phoromatic_server::$db->prepare('SELECT Email FROM phoromatic_users WHERE AccountID = :account_id AND AdminLevel = 1 ORDER BY CreatedOn ASC LIMIT 1');
		$stmt->bindValue(':account_id', $account_id);
		$result = $stmt->execute();
		$row = $result ? $result->fetchArray() : false;
		return isset($row['Email']) ? $row['Email'] : false;
	}
	public static function account_id_to_group_name($account_id)
	{
		static $group_names;

		if(!isset($group_names[$account_id]) || empty($group_names[$account_id]))
		{
			$stmt = phoromatic_server::$db->prepare('SELECT GroupName FROM phoromatic_accounts WHERE AccountID = :account_id');
			$stmt->bindValue(':account_id', $account_id);
			$result = $stmt->execute();
			$row = $result ? $result->fetchArray() : false;
			$group_names[$account_id] = isset($row['GroupName']) ? $row['GroupName'] : null;
		}

		return $group_names[$account_id];
	}
	public static function account_created_on($account_id)
	{
		static $created_dates;

		if(!isset($created_dates[$account_id]) || empty($created_dates[$account_id]))
		{
			$stmt = phoromatic_server::$db->prepare('SELECT CreatedOn FROM phoromatic_accounts WHERE AccountID = :account_id');
			$stmt->bindValue(':account_id', $account_id);
			$result = $stmt->execute();
			$row = $result ? $result->fetchArray() : false;
			$created_dates[$account_id] = isset($row['CreatedOn']) ? $row['CreatedOn'] : null;
		}

		return $created_dates[$account_id];
	}
	public static function recently_active_systems($account_id)
	{
		$systems = array();
		$stmt = phoromatic_server::$db->prepare('SELECT * FROM phoromatic_systems WHERE AccountID = :account_id AND State >= 0 ORDER BY LastCommunication DESC');
		$stmt->bindValue(':account_id', $account_id);
		$result = $stmt->execute();

		while($result && $row = $result->fetchArray())
		{
			if(strtotime($row['LastCommunication']) < (time() - 21600))
				break;
			if(stripos((string)$row['CurrentTask'], 'shutdown') !== false || stripos((string)$row['CurrentTask'], 'exit') !== false)
				continue;

			$systems[] = $row;
		}

		return $systems;
	}
	public static function check_for_benchmark_ticket_result_match($benchmark_id, $account_id, $system_id, $ticket_issue_time)
	{
		// On a bench with an open session a ticket is done once per session (each component runs it), as for schedules
		$bench = self::bench_state_for_system($account_id, $system_id);
		$stmt = phoromatic_server::$db->prepare('SELECT UploadID FROM phoromatic_results WHERE AccountID = :account_id AND SystemID = :system_id AND BenchmarkTicketID = :benchmark_id AND UploadTime > :ticket_issue_time AND InProgress = 0' . ($bench && $bench['session'] !== null ? ' AND BenchSessionID = :bench_session' : ''));
		if($bench && $bench['session'] !== null)
		{
			$stmt->bindValue(':bench_session', $bench['session']);
		}
		$stmt->bindValue(':account_id', $account_id);
		$stmt->bindValue(':system_id', $system_id);
		$stmt->bindValue(':benchmark_id', $benchmark_id);
		$stmt->bindValue(':ticket_issue_time', $ticket_issue_time);
		$result = $stmt->execute();

		if($result && $result->fetchArray() != false)
		{
			return true;
		}

		return false;
	}
	protected static $bench_cache = array();
	public static function bench_cache_reset()
	{
		self::$bench_cache = array();
	}
	public static function bench_state_for_system($account_id, $system_id)
	{
		// false for an ordinary system, else array('session' => open SessionID|null, 'drift' => BenchDrift JSON|null); cached per request
		// (phoromatic_bench resets the cache after every change it commits)
		$key = $account_id . '/' . $system_id;
		if(!isset(self::$bench_cache[$key]))
		{
			$stmt = phoromatic_server::$db->prepare('SELECT y.BenchID, y.BenchDrift, (SELECT s.SessionID FROM phoromatic_bench_sessions s WHERE s.AccountID = y.AccountID AND s.BenchID = y.BenchID AND s.EndedOn IS NULL) AS OpenSession FROM phoromatic_systems y WHERE y.AccountID = :account_id AND y.SystemID = :system_id LIMIT 1');
			$stmt->bindValue(':account_id', $account_id);
			$stmt->bindValue(':system_id', $system_id);
			$result = $stmt->execute();
			$row = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;
			self::$bench_cache[$key] = !$row || $row['BenchID'] === null ? false : array('session' => $row['OpenSession'] !== null ? (int)$row['OpenSession'] : null, 'drift' => $row['BenchDrift']);
		}
		return self::$bench_cache[$key];
	}
	public static function check_for_triggered_result_match($schedule_id, $trigger_id, $account_id, $system_id, $native = false)
	{
		// On a bench with an open session, "already ran" means already ran IN THAT SESSION: a daily schedule runs once per component per day
		$bench = self::bench_state_for_system($account_id, $system_id);
		$stmt = phoromatic_server::$db->prepare('SELECT UploadID FROM phoromatic_results WHERE AccountID = :account_id AND ScheduleID = :schedule_id AND Trigger = :trigger AND SystemID = :system_id AND InProgress = 0' . ($bench && $bench['session'] !== null ? ' AND BenchSessionID = :bench_session' : '') . ($native ? " AND UploadID NOT IN (SELECT UploadID FROM phoromatic_v2_jobs WHERE AccountID = :account_id AND UploadID IS NOT NULL AND State = 'ended' AND EndStatus = 'cancelled' AND EndReason IN ('agent_stopped', 'agent_restarted'))" : ''));
		// (a native result uploaded by a job that the agent itself stopped is partial: it does not use the trigger up, T10.6)
		$stmt->bindValue(':account_id', $account_id);
		$stmt->bindValue(':system_id', $system_id);
		$stmt->bindValue(':schedule_id', $schedule_id);
		$stmt->bindValue(':trigger', $trigger_id);
		if($bench && $bench['session'] !== null)
		{
			$stmt->bindValue(':bench_session', $bench['session']);
		}
		$result = $stmt->execute();

		if($result && $result->fetchArray() != false)
		{
			return true;
		}

		if($native && phoromatic_api_v2::trigger_consumed($account_id, $system_id, $schedule_id, $trigger_id))
		{
			return true;
		}

		if(!$native && (!defined('CLIENT_CORE_VERSION') || CLIENT_CORE_VERSION < 10601))
		{
			/*
			Traditionally to avoid a test trigger/schedule getting caught in a possible endless loop if all contained test(s) failed
			where there would then be no results to upload so at the end of the run would just re-query Phoromatic Server and start over,
			the below check would compare the error count associated with that system and schedule/trigger exceeded the count of tests to run.
			The check works "okay" but if rebooting mid-test and then repeating any prior failed tests, etc, could be among the scenarios leading to higher
			error count and thus abandoning that test trigger even if not all the tests were yet attempted. Plus some test runs could generate multiple errors per test.

			With PTS 10.6 the pieces are in place to allow run errors to be embedded into the XML result file with null results and work gracefully throughout the system.
			So even if all the test runs fail / error out, there will still be a valid result file and thus still uploading the result file to the Phoromatic Server.

			Thus this check with newer versions isn't necessary since the result file check will be accurate and always be uploaded if all tests fail,
			plus being more convenient for showing errors within the result page, etc.
			*/

			// See if the system attempted to run the trigger/schedule combination but reported an error during the process....
			$stmt = phoromatic_server::$db->prepare('SELECT COUNT(ErrorMessage) AS ErrorCount FROM phoromatic_system_client_errors WHERE AccountID = :account_id AND SystemID = :system_id AND ScheduleID = :schedule_id AND TriggerID = :trigger');
			$stmt->bindValue(':account_id', $account_id);
			$stmt->bindValue(':system_id', $system_id);
			$stmt->bindValue(':schedule_id', $schedule_id);
			$stmt->bindValue(':trigger', $trigger_id);
			$result = $stmt->execute();

			if($result != false && ($row = $result->fetchArray()) != false)
			{
				$error_count = $row['ErrorCount'];
				$stmt = phoromatic_server::$db->prepare('SELECT COUNT(*) AS TestCount FROM phoromatic_schedules_tests WHERE AccountID = :account_id AND ScheduleID = :schedule_id');
				$stmt->bindValue(':account_id', $account_id);
				$stmt->bindValue(':schedule_id', $schedule_id);
				$result = $stmt->execute();
				$row = $result ? $result->fetchArray() : null;

				// See if error count was greater than test count, meaning all of the tests might have failed
				if($error_count >= $row['TestCount'])
				{
					return true;
				}
			}
		}

		$stmt = phoromatic_server::$db->prepare('SELECT ScheduleID FROM phoromatic_schedules_trigger_skips WHERE AccountID = :account_id AND ScheduleID = :schedule_id AND Trigger = :trigger');
		$stmt->bindValue(':account_id', $account_id);
		$stmt->bindValue(':schedule_id', $schedule_id);
		$stmt->bindValue(':trigger', $trigger_id);
		$result = $stmt->execute();

		if($result && $result->fetchArray() != false)
		{
			return true;
		}

		return false;
	}
	public static function user_friendly_timedate($time)
	{
		return date('j F H:i', strtotime($time));
	}
	public static function get_system_details($account_id, $system_id)
	{
		$stmt = phoromatic_server::$db->prepare('SELECT * FROM phoromatic_systems WHERE AccountID = :account_id AND SystemID = :system_id LIMIT 1');
		$stmt->bindValue(':account_id', $account_id);
		$stmt->bindValue(':system_id', $system_id);
		$result = $stmt->execute();
		$row = $result ? $result->fetchArray() : null;

		return $row;
	}
	public static function systems_associated_with_schedule($account_id, $schedule_id)
	{
		$system_ids = array();
		$stmt = phoromatic_server::$db->prepare('SELECT RunTargetSystems, RunTargetGroups FROM phoromatic_schedules WHERE AccountID = :account_id AND ScheduleID = :schedule_id LIMIT 1');
		$stmt->bindValue(':account_id', $account_id);
		$stmt->bindValue(':schedule_id', $schedule_id);
		$result = $stmt->execute();

		if($result && $row = $result->fetchArray())
		{
			foreach(explode(',', (string)$row['RunTargetSystems']) as $sys)
			{
				if(empty($sys))
					continue;

				$system_ids[] = $sys;
			}

			foreach(explode(',', (string)$row['RunTargetGroups']) as $group)
			{
				if(empty($group))
					continue;

				$stmt = phoromatic_server::$db->prepare('SELECT SystemID FROM phoromatic_systems WHERE AccountID = :account_id AND Groups LIKE :sgroup AND State > 0 ORDER BY Title ASC');
				$stmt->bindValue(':account_id', $account_id);
				$stmt->bindValue(':sgroup', '%#' . $group . '#%');
				$result = $stmt->execute();

				while($result && $row = $result->fetchArray())
				{
					$system_ids[] = $row['SystemID'];
				}
			}
		}

		return array_unique($system_ids);
	}
	public static function schedules_that_run_on_system($account_id, $system_id)
	{
		$schedules = array();
		$stmt = phoromatic_server::$db->prepare('SELECT * FROM phoromatic_schedules WHERE AccountID = :account_id AND State = 1 ORDER BY TITLE ASC');
		$stmt->bindValue(':account_id', $account_id);
		$result = $stmt->execute();

		while($result && $row = $result->fetchArray())
		{
			// Make sure this test schedule is supposed to work on given system
			if(!in_array($system_id, explode(',', (string)$row['RunTargetSystems'])))
			{
				$stmt = phoromatic_server::$db->prepare('SELECT Groups FROM phoromatic_systems WHERE AccountID = :account_id AND SystemID = :system_id LIMIT 1');
				$stmt->bindValue(':account_id', $account_id);
				$stmt->bindValue(':system_id', $system_id);
				$sys_result = $stmt->execute();
				$sys_row = $sys_result ? $sys_result->fetchArray() : null;

				if(!isset($sys_row['Groups']) || empty($sys_row['Groups']))
				{
					continue;
				}

				$matches_to_group = false;
				foreach(explode(',', (string)$row['RunTargetGroups']) as $group)
				{
					if(stripos((string)$sys_row['Groups'], '#' . $group . '#') !== false)
					{
						$matches_to_group = true;
						break;
					}
				}

				if($matches_to_group == false)
					continue;
			}

			$schedules[] = $row;
		}

		return $schedules;
	}
	public static function system_has_outstanding_jobs($account_id, $system_id, $time_offset = 0, $include_low_priority_work = true)
	{
		// A bench with no open session, or one holding tests (awaiting a swap, or the class under test changed), has nothing to run:
		// it is then not woken up nor reported as down
		$bench = self::bench_state_for_system($account_id, $system_id);
		if($bench && ($bench['session'] === null || phoromatic_bench::holds(phoromatic_bench::drift_state($bench['drift']))))
		{
			return false;
		}

		$stmt = phoromatic_server::$db->prepare('SELECT Groups FROM phoromatic_systems WHERE AccountID = :account_id AND SystemID = :system_id LIMIT 1');
		$stmt->bindValue(':account_id', $account_id);
		$stmt->bindValue(':system_id', $system_id);
		$sys_result = $stmt->execute();
		$sys_row = $sys_result ? $sys_result->fetchArray() : null;


		// See if there's an open schedule to run for system
		$schedule_row = self::system_check_for_open_schedule_run($account_id, $system_id, $time_offset, $sys_row, $include_low_priority_work);
		if($schedule_row != false)
		{
			return $schedule_row;
		}

		// See if there's an open benchmark ticket for system
		$ticket_row = self::system_check_for_open_benchmark_ticket($account_id, $system_id, $sys_row);
		if($ticket_row != false)
		{
			return $ticket_row;
		}

		return false;
	}
	public static function system_check_for_open_schedule_run($account_id, $system_id, $time_offset = 0, &$sys_row = null, $include_low_priority_work = true, $format = 'pts')
	{
		// $format is the kind of test rows a schedule must have to count: 'pts' (phoromatic_schedules_tests) or 'native' (phoromatic_schedules_native_tests,
		// for a system on the native engine, whose used-up triggers also come from its v2 jobs)
		$tests_table = $format === 'native' ? 'phoromatic_schedules_native_tests' : 'phoromatic_schedules_tests';
		$native = $format === 'native';
		if($include_low_priority_work)
		{
			$stmt = phoromatic_server::$db->prepare('SELECT * FROM phoromatic_schedules WHERE AccountID = :account_id AND State = 1 AND (SELECT COUNT(*) FROM ' . $tests_table . ' WHERE AccountID = :account_id AND ScheduleID = phoromatic_schedules.ScheduleID) > 0 ORDER BY RunPriority DESC');
		}
		else
		{
			// Only include higher priority work
			$stmt = phoromatic_server::$db->prepare('SELECT * FROM phoromatic_schedules WHERE AccountID = :account_id AND State = 1 AND RunPriority >= 100 AND (SELECT COUNT(*) FROM ' . $tests_table . ' WHERE AccountID = :account_id AND ScheduleID = phoromatic_schedules.ScheduleID) > 0 ORDER BY RunPriority DESC');
		}

		$stmt->bindValue(':account_id', $account_id);
		$result = $stmt->execute();
		$day_of_week_int = date('N') - 1;

		while($result && $row = $result->fetchArray())
		{
			// Make sure this test schedule is supposed to work on given system
			if(!in_array($system_id, explode(',', (string)$row['RunTargetSystems'])))
			{
				$matches_to_group = false;
				foreach(explode(',', (string)$row['RunTargetGroups']) as $group)
				{
					if(stripos((string)$sys_row['Groups'], '#' . $group . '#') !== false)
					{
						$matches_to_group = true;
						break;
					}
				}

				if($matches_to_group == false)
					continue;
			}

			// See if test is a time-based schedule due to run today and now or past the time scheduled to run
			if(strpos((string)$row['ActiveOn'], strval($day_of_week_int)) !== false)
			{
				if($row['RunAt'] <= date('H.i', (time() + $time_offset)))
				{
					$trigger_id = date('Y-m-d');
					if(!phoromatic_server::check_for_triggered_result_match($row['ScheduleID'], $trigger_id, $account_id, $system_id, $native))
					{
						return $row;
					}
				}
			}

			// See if custom trigger...
			$stmt = phoromatic_server::$db->prepare('SELECT * FROM phoromatic_schedules_triggers WHERE AccountID = :account_id AND ScheduleID = :schedule_id ORDER BY TriggeredOn DESC');
			$stmt->bindValue(':account_id', $account_id);
			$stmt->bindValue(':schedule_id', $row['ScheduleID']);
			$trigger_result = $stmt->execute();
			while($trigger_result && $trigger_row = $trigger_result->fetchArray())
			{
				// See if any sub-targeting is happening
				if($trigger_row['SubTarget'] !== null)
				{
					$sub_targets = explode(',', $trigger_row['SubTarget']);
					if(!empty($sub_targets) && !in_array($system_id, $sub_targets))
					{
						// This system isn't part of the sub-targeted trigger
						continue;
					}
				}

				if(substr($trigger_row['TriggeredOn'], 0, 10) == date('Y-m-d') || substr($trigger_row['TriggeredOn'], 0, 10) == date('Y-m-d', (time() - 60 * 60 * 24)))
				{
					if(!phoromatic_server::check_for_triggered_result_match($row['ScheduleID'], $trigger_row['Trigger'], $account_id, $system_id, $native))
					{
						$row['Trigger'] = $trigger_row['Trigger'];
						return $row;
					}
				}
			}
		}

		return false;
	}
	public static function system_check_for_open_benchmark_ticket($account_id, $system_id, &$sys_row)
	{
		// A bench member also gets older tickets that a level 1-2 user asked to run again on its open session (2C, "Run again on the
		// current component"), for 24 hours after the request; whether it already ran is then decided per session as always
		$bench = !empty($sys_row['BenchID']) ? self::bench_state_for_system($account_id, $system_id) : false;
		$rerun = $bench && $bench['session'] !== null;
		$stmt = phoromatic_server::$db->prepare('SELECT * FROM phoromatic_benchmark_tickets WHERE AccountID = :account_id AND State = 1 AND TicketIssueTime < :current_time AND (TicketIssueTime > :yesterday' . ($rerun ? ' OR TicketID IN (SELECT TicketID FROM phoromatic_bench_ticket_reruns WHERE AccountID = :account_id AND BenchID = :rerun_bench AND SessionID = :rerun_session AND RequestedOn >= :rerun_since)' : '') . ') ORDER BY TicketIssueTime ASC');
		//echo phoromatic_server::$db->lastErrorMsg();
		if($rerun)
		{
			$stmt->bindValue(':rerun_bench', (int)$sys_row['BenchID']);
			$stmt->bindValue(':rerun_session', (int)$bench['session']);
			$stmt->bindValue(':rerun_since', date('Y-m-d H:i:s', time() - 86400));
		}
		$stmt->bindValue(':account_id', $account_id);
		$stmt->bindValue(':current_time', time());
		$stmt->bindValue(':yesterday', (time() - (60 * 60 * 24)));
		$result = $stmt->execute();

		while($result && $row = $result->fetchArray())
		{
			// Make sure this test schedule is supposed to work on given system
			if(!in_array($system_id, explode(',', (string)$row['RunTargetSystems'])))
			{
				$matches_to_group = false;
				foreach(explode(',', (string)$row['RunTargetGroups']) as $group)
				{
					if(stripos((string)$sys_row['Groups'], '#' . $group . '#') !== false)
					{
						$matches_to_group = true;
						break;
					}
				}

				if($matches_to_group == false)
					continue;
			}

			if(!phoromatic_server::check_for_benchmark_ticket_result_match($row['TicketID'], $account_id, $system_id, $row['TicketIssueTime']))
			{
				if(strpos((string)$row['EnvironmentVariables'], 'PTS_CONCURRENT_TEST_RUNS') !== false && is_file(phoromatic_server::phoromatic_account_stress_log_path($account_id, $row['TicketID']) . $system_id . '.log') && filemtime(phoromatic_server::phoromatic_account_stress_log_path($account_id, $row['TicketID']) . $system_id . '.log') > $row['TicketIssueTime'])
				{
					// Stress log uploaded
					continue;
				}

				return $row;
			}
		}

		return false;
	}
	public static function time_to_next_scheduled_job($account_id, $system_id)
	{
		$stmt = phoromatic_server::$db->prepare('SELECT * FROM phoromatic_schedules WHERE AccountID = :account_id AND State = 1 AND (SELECT COUNT(*) FROM phoromatic_schedules_tests WHERE AccountID = :account_id AND ScheduleID = phoromatic_schedules.ScheduleID) > 0');
		$stmt->bindValue(':account_id', $account_id);
		$result = $stmt->execute();
		$scheduled_times = array();

		while($result && $row = $result->fetchArray())
		{
			if(empty($row['RunAt']))
			{
				continue;
			}

			// Make sure this test schedule is supposed to work on given system
			if(!in_array($system_id, explode(',', (string)$row['RunTargetSystems'])))
			{
				$stmt = phoromatic_server::$db->prepare('SELECT Groups FROM phoromatic_systems WHERE AccountID = :account_id AND SystemID = :system_id LIMIT 1');
				$stmt->bindValue(':account_id', $account_id);
				$stmt->bindValue(':system_id', $system_id);
				$sys_result = $stmt->execute();
				$sys_row = $sys_result ? $sys_result->fetchArray() : null;

				$matches_to_group = false;
				foreach(explode(',', (string)$row['RunTargetGroups']) as $group)
				{
					if(stripos((string)$sys_row['Groups'], '#' . $group . '#') !== false)
					{
						$matches_to_group = true;
						break;
					}
				}

				if($matches_to_group == false)
					continue;
			}

			list($hour, $minute) = explode('.', (string)$row['RunAt']);
			if(!is_numeric($hour) || !is_numeric($minute))
			{
				continue;
			}

			foreach(explode(',', (string)$row['ActiveOn']) as $active_day)
			{
				// A schedule with no days ticked has ActiveOn '' (a one-off or paused schedule): explode() gives one empty
				// string, and '' * 1440 is a TypeError that used to turn the whole Systems page into a 500
				if(!is_numeric($active_day))
				{
					continue;
				}

				$scheduled_times[] = (($active_day * 1440) + ($hour * 60) + $minute );
			}
		}

		sort($scheduled_times);

		$now_time = ((date('N') - 1) * 1440) + (date('G') * 60) + date('i');
		foreach($scheduled_times as $i => $time_to_next_job)
		{
			if($now_time > $time_to_next_job)
				unset($scheduled_times[$i]);
		}

		if(!empty($scheduled_times))
			return array_shift($scheduled_times) - $now_time;

		return false;
	}
	public static function estimated_time_remaining_diff($estimated_minutes, $last_comm)
	{
		if($estimated_minutes > 0)
		{
			$estimated_completion = strtotime($last_comm) + ($estimated_minutes * 60);

			if(time() < $estimated_completion)
			{
				return ceil(($estimated_completion - time()) / 60);
			}
		}

		return 0;
	}
	public static function systems_appearing_down($account_id = null)
	{
		if(isset($_SESSION['AccountID']))
			$account_id = $_SESSION['AccountID'];

		$systems = array();
		$stmt = phoromatic_server::$db->prepare('SELECT SystemID, Title, LastCommunication, CurrentTask FROM phoromatic_systems WHERE AccountID = :account_id AND State >= 0 ORDER BY LastCommunication DESC');
		$stmt->bindValue(':account_id', $account_id);
		$result = $stmt->execute();
		while($result && $row = $result->fetchArray())
		{
			if(phoromatic_server::system_check_if_down($_SESSION['AccountID'], $row['SystemID'], $row['LastCommunication'], $row['CurrentTask']))
			{
				$systems[] = $row['SystemID'];
			}
		}

		return $systems;
	}
	public static function schedules_today($account_id)
	{
		$schedules = array();
		$show_day_of_week = date('N') - 1;
		$stmt = phoromatic_server::$db->prepare('SELECT * FROM phoromatic_schedules WHERE AccountID = :account_id AND State = 1 AND (SELECT COUNT(*) FROM phoromatic_schedules_tests WHERE AccountID = :account_id AND ScheduleID = phoromatic_schedules.ScheduleID) > 0 AND ActiveOn LIKE :active_day ORDER BY RunAt ASC');
		$stmt->bindValue(':account_id', $account_id);
		$stmt->bindValue(':active_day', '%' . $show_day_of_week . '%');
		$result = $stmt->execute();

		while($result && $row = $result->fetchArray())
		{
			$schedules[] = $row;
		}

		return $schedules;
	}
	public static function schedules_total($account_id)
	{
		$schedules = array();
		$show_day_of_week = date('N') - 1;
		$stmt = phoromatic_server::$db->prepare('SELECT * FROM phoromatic_schedules WHERE AccountID = :account_id AND State = 1 AND (SELECT COUNT(*) FROM phoromatic_schedules_tests WHERE AccountID = :account_id AND ScheduleID = phoromatic_schedules.ScheduleID) > 0 ORDER BY RunAt ASC');
		$stmt->bindValue(':account_id', $account_id);
		$result = $stmt->execute();

		while($result && $row = $result->fetchArray())
		{
			$schedules[] = $row;
		}

		return $schedules;
	}
	public static function benchmark_tickets_today($account_id)
	{
		$tickets = array();
		$stmt = phoromatic_server::$db->prepare('SELECT * FROM phoromatic_benchmark_tickets WHERE AccountID = :account_id AND State >= 0 AND TicketIssueTime > :time_cutoff ORDER BY TicketIssueTime DESC');
		$stmt->bindValue(':account_id', $account_id);
		$stmt->bindValue(':time_cutoff', (time() - (60 * 60 * 24 * 14)));
		$result = $stmt->execute();

		while($result && $row = $result->fetchArray())
		{
			$tickets[] = $row;
		}

		return $tickets;
	}
	public static function systems_idling($account_id)
	{
		$systems = array();
		$stmt = phoromatic_server::$db->prepare('SELECT SystemID FROM phoromatic_systems WHERE AccountID = :account_id AND State >= 0 AND CurrentTask LIKE \'%Idling%\' ORDER BY LastCommunication DESC');
		$stmt->bindValue(':account_id', $account_id);
		$result = $stmt->execute();
		while($result && $row = $result->fetchArray())
		{
			$systems[] = $row;
		}

		return $systems;
	}
	public static function systems_shutdown($account_id)
	{
		$systems = array();
		$stmt = phoromatic_server::$db->prepare('SELECT SystemID FROM phoromatic_systems WHERE AccountID = :account_id AND State >= 0 AND CurrentTask LIKE \'%Shutdown%\' ORDER BY LastCommunication DESC');
		$stmt->bindValue(':account_id', $account_id);
		$result = $stmt->execute();
		while($result && $row = $result->fetchArray())
		{
			$systems[] = $row;
		}

		return $systems;
	}
	public static function systems_running_tests($account_id)
	{
		$systems = array();
		$stmt = phoromatic_server::$db->prepare('SELECT SystemID FROM phoromatic_systems WHERE AccountID = :account_id AND State >= 0 AND (CurrentTask LIKE \'%Running%\' OR CurrentTask LIKE \'%Installing%\' OR CurrentTask LIKE \'%Benchmark%\') ORDER BY LastCommunication DESC');
		$stmt->bindValue(':account_id', $account_id);
		$result = $stmt->execute();
		while($result && $row = $result->fetchArray())
		{
			$systems[] = $row;
		}

		return $systems;
	}
	public static function systems_total($account_id)
	{
		$systems = array();
		$stmt = phoromatic_server::$db->prepare('SELECT SystemID FROM phoromatic_systems WHERE AccountID = :account_id AND State >= 0 ORDER BY LastCommunication DESC');
		$stmt->bindValue(':account_id', $account_id);
		$result = $stmt->execute();
		while($result && $row = $result->fetchArray())
		{
			$systems[] = $row;
		}

		return $systems;
	}
	public static function test_result_count_for_test_profile($account_id, $test_profile)
	{
		$stmt = phoromatic_server::$db->prepare('SELECT COUNT(*) As TotalCount FROM phoromatic_results_results WHERE AccountID = :account_id AND TestProfile LIKE :tp');
		$stmt->bindValue(':account_id', $account_id);
		$stmt->bindValue(':tp', $test_profile . '%');
		$result = $stmt->execute();
		if($result && $row = $result->fetchArray())
		{
			return $row['TotalCount'];
		}

		return 0;
	}
	public static function test_result_count_for_test_profiles($account_id)
	{
		$stmt = phoromatic_server::$db->prepare('SELECT COUNT(*) As Count, TestProfile FROM phoromatic_results_results WHERE AccountID = :account_id GROUP BY TestProfile ORDER BY TestProfile ASC');
		$stmt->bindValue(':account_id', $account_id);
		$result = $stmt->execute();
		$tests = array();
		while($result && $row = $result->fetchArray())
		{
			$tests[$row['TestProfile']] = $row['Count'];
		}

		return $tests;
	}
	public static function test_results($account_id, $time_limit = false)
	{
		$results = array();
		$stmt = phoromatic_server::$db->prepare('SELECT * FROM phoromatic_results WHERE AccountID = :account_id ORDER BY UploadTime DESC');
		$stmt->bindValue(':account_id', $account_id);
		$result = $stmt->execute();
		while($result && $row = $result->fetchArray())
		{
			if($time_limit != false && strtotime($row['UploadTime']) < $time_limit)
			{
				break;
			}

			$results[] = $row;
		}

		return $results;
	}
	public static function test_results_total($account_id)
	{
		$stmt = phoromatic_server::$db->prepare('SELECT COUNT(*) As ResultCount FROM phoromatic_results WHERE AccountID = :account_id');
		$stmt->bindValue(':account_id', $account_id);
		$result = $stmt->execute();
		if($result && $row = $result->fetchArray())
		{
			return $row['ResultCount'];
		}

		return 0;
	}
	public static function test_results_benchmark_count($account_id)
	{
		$results = array();
		$stmt = phoromatic_server::$db->prepare('SELECT COUNT(*) AS BenchmarkCount FROM phoromatic_results_results WHERE AccountID = :account_id');
		$stmt->bindValue(':account_id', $account_id);
		$result = $stmt->execute();
		if($result && $row = $result->fetchArray())
		{
			return $row['BenchmarkCount'];
		}

		return 0;
	}
	public static function system_check_if_down($account_id, $system_id, $last_communication, $current_task)
	{
		$last_comm = strtotime($last_communication);
		return ((phoromatic_server::system_has_outstanding_jobs($account_id, $system_id, -600) && (($last_comm < (time() - 5400) && stripos($current_task, 'Running') === false) || $last_comm < (time() - 7200) || ($last_comm < (time() - 600) && stripos($current_task, 'Shutdown') !== false))) || ($last_comm < (time() -7200) && (stripos($current_task, 'running') !== false ||  stripos($current_task, 'setting') !== false))) || $current_task == 'Unknown';
	}
	public static function estimated_time_remaining_string($estimated_minutes, $last_comm, $append = 'Remaining')
	{
		$remaining = phoromatic_server::estimated_time_remaining_diff($estimated_minutes, $last_comm);
		return $remaining > 0 ? '~' . pts_strings::plural_handler($remaining, 'Minute') . ' ' . $append : null;
	}
}

if(phoromatic_server::storage_problem() === null && !is_dir(phoromatic_server::phoromatic_path() . 'accounts'))
{
	mkdir(phoromatic_server::phoromatic_path() . 'accounts');
}

?>
