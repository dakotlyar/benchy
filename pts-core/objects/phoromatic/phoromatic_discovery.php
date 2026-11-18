<?php

/*
	Benchy server discovery, server side (D1, 0.10.0): the "_benchy._tcp" announcement on the local network, the network name, the announce
	setting and its audit, the state the admin sees, the DNS record that lets other VLANs find the server, and the reply of the
	unauthenticated GET /api/v2/server.

	What is safe to say to a hostile LAN is decided here and nowhere else (design 3.2): that a Benchy server exists, its network name, its
	HTTPS port, and for the "who are you" reply the version, the protocol, the certificate mode and names, and the internal CA's fingerprint
	(which ?benchy_ca already hands to anyone). Never an account name or id, a count, an e-mail address, the software stack or the WebSocket
	port. Everything a client learns this way is a hint; trust comes only from a pairing string or a join code.

	The announcing itself is a child of the server launcher (start_phoromatic_server): it reads the file written by write_state() and keeps
	avahi-publish running, restarting it when avahi-daemon restarts. This class never talks to avahi.

	This program is free software; you can redistribute it and/or modify it under the terms of the GNU General Public License as published
	by the Free Software Foundation; either version 3 of the License, or (at your option) any later version.
*/

class phoromatic_discovery
{
	const NAME_MAX_BYTES = 63;
	const INFO_PER_MINUTE = 60;
	// Join codes arrive with D2; the field is in the reply from the start so a client can already read it
	const JOIN_CODES = false;
	const CONFIG_KEY = 'PhoronixTestSuite/Options/Server/AdvertiseServiceZeroConf';
	const AVAHI_SOCKET = '/run/avahi-daemon/socket';

	// ---------- the settings (server settings file: "announce" on|off, "network_name" text or empty for the default)
	protected static function store()
	{
		phoromatic_server::init_settings_store();
	}
	public static function enabled()
	{
		// An explicit choice (the page or server-announce) wins. Until there is one, the old AdvertiseServiceZeroConf key decides (on by
		// default), so a server whose admin already turned announcing off stays off.
		self::store();
		$v = phoromatic_server::read_setting('announce');
		if($v === 'on' || $v === 'off')
		{
			return $v === 'on';
		}
		return (bool)pts_config::read_bool_config(self::CONFIG_KEY, 'TRUE');
	}
	public static function explicit()
	{
		// true when the admin has chosen (so the configuration file key is not what decides)
		self::store();
		$v = phoromatic_server::read_setting('announce');
		return $v === 'on' || $v === 'off';
	}
	public static function default_name()
	{
		$h = function_exists('gethostname') ? (string)@gethostname() : '';
		$h = trim(strpos($h, '.') !== false ? substr($h, 0, strpos($h, '.')) : $h);
		if($h === '' || self::validate_name($h) !== null)
		{
			return 'benchy';
		}
		return $h;
	}
	public static function network_name()
	{
		self::store();
		$n = phoromatic_server::read_setting('network_name');
		if(is_string($n) && trim($n) !== '' && self::validate_name($n) === null)
		{
			return trim($n);
		}
		return self::default_name();
	}
	public static function custom_name()
	{
		// the stored name, or '' while the default is in use
		self::store();
		$n = phoromatic_server::read_setting('network_name');
		return is_string($n) && trim($n) !== '' && self::validate_name($n) === null ? trim($n) : '';
	}
	public static function validate_name($name)
	{
		// null when fine, else why not. 63 bytes is the DNS label limit that the mDNS instance name has to fit; control characters would
		// break the one-line state file, the log lines and every browser that lists the name.
		$name = trim((string)$name);
		if($name === '')
		{
			return 'The network name cannot be empty.';
		}
		if(strlen($name) > self::NAME_MAX_BYTES)
		{
			return 'The network name is ' . strlen($name) . ' bytes long; the limit is ' . self::NAME_MAX_BYTES . ' bytes (a letter with an accent takes two or more).';
		}
		if(preg_match('//u', $name) !== 1)
		{
			return 'The network name is not valid UTF-8.';
		}
		if(preg_match('/[\p{Cc}\p{Zl}\p{Zp}]/u', $name) === 1)
		{
			return 'The network name cannot contain control characters or line breaks.';
		}
		return null;
	}

	// ---------- changing them (CLI and web share this): audited first, in one transaction, no unaudited change
	public static function change($new_announce, $new_name, $account_id, $actor_type, $actor, $actor_detail, $source)
	{
		// $new_announce: 'on', 'off' or null (leave); $new_name: text, '' for the default, or null (leave). Returns null on success (also when
		// nothing differs: nothing is written then), else why nothing was changed.
		if($new_announce !== null && !in_array($new_announce, array('on', 'off'), true))
		{
			return 'Unknown value; nothing was changed.';
		}
		if($new_name !== null)
		{
			$new_name = trim($new_name);
			if($new_name !== '' && ($problem = self::validate_name($new_name)) !== null)
			{
				return $problem . ' Nothing was changed.';
			}
		}
		$before = array('announce' => self::enabled() ? 'on' : 'off', 'name' => self::network_name());
		$stored_before = array('announce' => phoromatic_server::read_setting('announce'), 'name' => phoromatic_server::read_setting('network_name'));
		$after = $before;
		if($new_announce !== null)
		{
			$after['announce'] = $new_announce;
		}
		if($new_name !== null)
		{
			$after['name'] = $new_name === '' ? self::default_name() : $new_name;
		}
		if($after === $before && !($new_announce !== null && !self::explicit()))
		{
			return null;
		}
		if(@phoromatic_server::$db->exec('BEGIN IMMEDIATE') === false)
		{
			return 'The database is busy; nothing was changed.';
		}
		if(!phoromatic_admin_audit::record($account_id, $actor_type, $actor, 'discovery_announce_change', array('actor_detail' => $actor_detail,
			'subject_type' => 'server_setting', 'subject_id' => 'discovery_announce', 'subject_label' => 'Announce this server on the local network',
			'detail' => array('source' => $source, 'before' => $before, 'after' => $after))))
		{
			$why = phoromatic_admin_audit::last_error();
			@phoromatic_server::$db->exec('ROLLBACK');
			phoromatic_server_audit::discard();
			phoromatic_admin_audit::mirror_note('discovery_announce_change rolled back, nothing changed: ' . $why);
			return 'The change could not be recorded in the audit log (' . $why . '); nothing was changed.';
		}
		$restore = function() use ($stored_before)
			{
				foreach(array('announce' => 'announce', 'name' => 'network_name') as $k => $key)
				{
					if($stored_before[$k] === false || $stored_before[$k] === null)
					{
						phoromatic_server::remove_setting($key);
					}
					else
					{
						phoromatic_server::save_setting($key, $stored_before[$k]);
					}
				}
			};
		if($new_announce !== null)
		{
			phoromatic_server::save_setting('announce', $new_announce);
		}
		if($new_name !== null)
		{
			phoromatic_server::save_setting('network_name', $new_name);
		}
		if(($new_announce !== null && phoromatic_server::read_setting('announce') !== $new_announce) || ($new_name !== null && (string)phoromatic_server::read_setting('network_name') !== $new_name))
		{
			$restore();
			@phoromatic_server::$db->exec('ROLLBACK');
			phoromatic_server_audit::discard();
			phoromatic_admin_audit::mirror_note('discovery_announce_change rolled back, nothing changed: the settings file could not be written');
			return 'The setting could not be saved (the server could not write ' . phoromatic_server::phoromatic_path(false) . 'phoromatic-settings.pt2so); nothing was changed.';
		}
		if(@phoromatic_server::$db->exec('COMMIT') === false)
		{
			$restore();
			@phoromatic_server::$db->exec('ROLLBACK');
			phoromatic_server_audit::discard();
			phoromatic_admin_audit::mirror_note('discovery_announce_change rolled back, settings restored: the audit rows could not be committed');
			return 'The change could not be recorded in the audit log; the settings were left as they were.';
		}
		phoromatic_server_audit::flush();
		self::write_state();
		return null;
	}

	// ---------- what the launcher follows
	public static function state_file()
	{
		return phoromatic_tls::tls_path() . 'announce';
	}
	public static function runtime_state_file()
	{
		// written by the launcher: "up" while avahi-publish is running, "down" otherwise
		return phoromatic_tls::tls_path(false) . 'announce.state';
	}
	public static function write_state()
	{
		// Four lines for the launcher: on|off, the network name, the HTTPS port, and on|off for the older _http/_https announcement (which follows the setting alone). Anything but "on" means nothing is announced; announcing
		// happens only while the built-in HTTPS is on, and only for its port (nothing is announced for plain HTTP).
		$s = phoromatic_tls::settings();
		$on = self::enabled() && $s['mode'] != 'off';
		$text = ($on ? 'on' : 'off') . "\n" . self::network_name() . "\n" . (int)$s['https_port'] . "\n" . (self::enabled() ? 'on' : 'off') . "\n";
		$file = self::state_file();
		$tmp = $file . '.new';
		if(@file_put_contents($tmp, $text) === false)
		{
			return false;
		}
		@chmod($tmp, 0600);
		return @rename($tmp, $file);
	}
	public static function avahi_daemon_present()
	{
		return file_exists(self::AVAHI_SOCKET);
	}
	public static function avahi_publish_path()
	{
		$dirs = array_merge(explode(':', (string)getenv('PATH')), array('/usr/bin', '/bin', '/usr/local/bin'));
		foreach($dirs as $d)
		{
			if($d !== '' && is_file($d . '/avahi-publish') && is_executable($d . '/avahi-publish'))
			{
				return $d . '/avahi-publish';
			}
		}
		return false;
	}
	public static function state()
	{
		// array(code, text): what the Server settings page and server-announce status say. code: announced, off, https_off, not_running,
		// no_daemon, no_publish, starting.
		$s = phoromatic_tls::settings();
		$name = self::network_name();
		if(!self::enabled())
		{
			return array('off', 'Not announced: announcing is turned off.');
		}
		if($s['mode'] == 'off')
		{
			return array('https_off', 'Not announced: the built-in HTTPS is off. Only the HTTPS port is announced, never plain HTTP.');
		}
		if(!phoromatic_tls::service_running())
		{
			return array('not_running', 'Not announced: the server is not running here. It announces when it starts.');
		}
		$f = self::runtime_state_file();
		$up = is_file($f) && trim((string)@file_get_contents($f)) === 'up';
		if($up)
		{
			return array('announced', 'Announced as ' . $name . ' on port ' . (int)$s['https_port'] . '.');
		}
		if(!self::avahi_daemon_present())
		{
			return array('no_daemon', 'Not announced: avahi-daemon isn\'t running on this host (in a container, it has to run inside the container, and the container needs host networking or macvlan).');
		}
		if(self::avahi_publish_path() === false)
		{
			return array('no_publish', 'Not announced: avahi-publish isn\'t installed on this host (on Debian and Ubuntu it is in the avahi-utils package).');
		}
		return array('starting', 'Not announced yet. The server tries again every few seconds; if this stays, look in the server log.');
	}

	// ---------- the DNS record for other VLANs
	public static function srv_host($s)
	{
		// the first name that is a dotted host name (not an address, not a wildcard), or null
		foreach($s['names'] as $n)
		{
			if(strpos($n, '.') !== false && strncmp($n, '*.', 2) != 0 && !filter_var($n, FILTER_VALIDATE_IP))
			{
				return $n;
			}
		}
		return null;
	}
	public static function srv_line($s)
	{
		// The record for Unbound's custom options (pfSense and OPNsense), filled in from the server's own first host name and HTTPS port;
		// null while the built-in HTTPS is off (nothing to point at). Without a dotted host name the search domain cannot be guessed, so the
		// two places it goes are left for the admin to fill in.
		if($s['mode'] == 'off')
		{
			return null;
		}
		$host = self::srv_host($s);
		if($host !== null)
		{
			$domain = substr($host, strpos($host, '.') + 1);
			return 'local-data: "_benchy._tcp.' . $domain . '. 3600 IN SRV 0 0 ' . (int)$s['https_port'] . ' ' . $host . '."';
		}
		return 'local-data: "_benchy._tcp.YOUR-SEARCH-DOMAIN. 3600 IN SRV 0 0 ' . (int)$s['https_port'] . ' SERVER-NAME.YOUR-SEARCH-DOMAIN."';
	}
	public static function status_lines($s)
	{
		// What server-https-status prints (one call from that command): the announcing, and the DNS record. Lines without a trailing newline.
		$lines = array();
		$st = self::state();
		$lines[] = '';
		$lines[] = 'Announcing:      ' . $st[1];
		if(($srv = self::srv_line($s)) !== null)
		{
			$lines[] = 'DNS record so that machines on other VLANs can find this server (Unbound custom option, or the same record in your DNS server):';
			$lines[] = '  ' . $srv;
			if(self::srv_host($s) === null)
			{
				$lines[] = '  (None of this server\'s names is a dotted host name, so the two YOUR-SEARCH-DOMAIN parts are yours to fill in.)';
			}
		}
		return $lines;
	}

	// ---------- GET /api/v2/server
	public static function info()
	{
		// The reply body (without the api/server envelope). Settings only: no database, no accounts, no counts.
		$s = phoromatic_tls::settings();
		$tls = array('mode' => $s['mode'], 'names' => array_values($s['names']));
		if($s['mode'] == 'internal' && ($fp = phoromatic_tls::root_ca_fingerprint()))
		{
			$tls['ca_sha256'] = $fp;
		}
		return array('product' => 'benchy', 'name' => self::network_name(), 'version' => PTS_VERSION, 'protocol' => array(2), 'tls' => $tls,
			'join_codes' => self::JOIN_CODES && $s['mode'] != 'off');
	}
	public static function rate_limited($ip)
	{
		// true once one address has asked more than INFO_PER_MINUTE times in a minute. A small file per address in the server's own folder, so
		// an unauthenticated endpoint writes nothing to the database; any problem with the file means "not limited" (it is a guard against
		// a flood, not a gate).
		$dir = phoromatic_tls::tls_path() . 'info-rate/';
		if(!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir))
		{
			return false;
		}
		$now = time();
		if(random_int(1, 50) === 1)
		{
			foreach((array)glob($dir . '*') as $old)
			{
				if(is_file($old) && filemtime($old) < $now - 120)
				{
					@unlink($old);
				}
			}
		}
		$h = @fopen($dir . substr(hash('sha256', (string)$ip), 0, 32), 'c+');
		if($h === false)
		{
			return false;
		}
		$limited = false;
		if(flock($h, LOCK_EX))
		{
			$parts = explode(' ', trim((string)stream_get_contents($h)));
			$start = isset($parts[0]) && ctype_digit($parts[0]) ? (int)$parts[0] : 0;
			$count = isset($parts[1]) && ctype_digit($parts[1]) ? (int)$parts[1] : 0;
			if($now - $start >= 60)
			{
				$start = $now;
				$count = 0;
			}
			$count++;
			ftruncate($h, 0);
			rewind($h);
			fwrite($h, $start . ' ' . $count);
			$limited = $count > self::INFO_PER_MINUTE;
			flock($h, LOCK_UN);
		}
		fclose($h);
		return $limited;
	}

	// ---------- the Server settings page (a section under HTTPS)
	protected static function h($text)
	{
		return htmlspecialchars((string)$text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}
	public static function is_post()
	{
		return isset($_POST['discovery_form']);
	}
	public static function process_post()
	{
		// HTML for the section's message line; '' when this form was not posted. Server administrators only, POST, the CSRF token and a
		// same-origin request, like every other server-wide setting.
		if(!self::is_post() || !phoromatic_auth::is_server_admin() || $_SERVER['REQUEST_METHOD'] != 'POST')
		{
			return '';
		}
		if(!is_string($_POST['discovery_form']) || !phoromatic_private_repo_web::origin_ok() || !verify_submission_token())
		{
			return '<p style="color: red;"><strong>The form expired or was not submitted from this site; the announce setting was not changed. Please try again.</strong></p>';
		}
		$announce = isset($_POST['discovery_announce']) && $_POST['discovery_announce'] === 'on' ? 'on' : (isset($_POST['discovery_announce']) && $_POST['discovery_announce'] === 'off' ? 'off' : null);
		$name = isset($_POST['discovery_name']) && is_string($_POST['discovery_name']) ? trim($_POST['discovery_name']) : null;
		if($announce === null)
		{
			return '<p style="color: red;"><strong>Choose on or off; nothing was changed.</strong></p>';
		}
		// A name equal to the one in use is no change; an empty box means "use the host name"
		if($name !== null && $name === self::network_name() && self::custom_name() === '')
		{
			$name = null;
		}
		list($actor_account, $actor_type, $actor, $actor_detail) = phoromatic_auth::audit_actor();
		if(($error = self::change($announce, $name, $actor_account, $actor_type, $actor, $actor_detail, 'web')) !== null)
		{
			return '<p style="color: red;"><strong>' . self::h($error) . '</strong></p>';
		}
		phoromatic_add_activity_stream_event('settings', null, 'modified');
		return '<p><strong>Saved.</strong> A running server follows within a few seconds, the older <code>_http._tcp</code> and <code>_https._tcp</code> announcement included.</p>';
	}
	public static function render_section($page, $message = '')
	{
		if(!phoromatic_auth::is_server_admin())
		{
			return '';
		}
		$s = phoromatic_tls::settings();
		$st = self::state();
		$srv = self::srv_line($s);
		$cfg_note = !self::explicit() && !self::enabled() ? '<p>It is off because <code>AdvertiseServiceZeroConf</code> is FALSE in the server\'s configuration file. Saving this form replaces that.</p>' : '';
		$h = '<form name="discovery_form" id="discovery_form" action="?' . self::h($page) . '" method="post">' . write_token_in_form() . '<input type="hidden" name="discovery_form" value="1" />' .
			'<hr /><h2>Finding this server on the network</h2>' . $message .
			'<p><label><input type="radio" name="discovery_announce" value="on"' . (self::enabled() ? ' checked="checked"' : '') . ' /> Announce this server on the local network (default)</label><br />' .
			'<label><input type="radio" name="discovery_announce" value="off"' . (self::enabled() ? '' : ' checked="checked"') . ' /> Do not announce it</label></p>' . $cfg_note .
			'<p>Network name: <input type="text" name="discovery_name" size="30" maxlength="' . self::NAME_MAX_BYTES . '" value="' . self::h(self::network_name()) . '" /> (what machines show for this server; up to ' . self::NAME_MAX_BYTES . ' bytes; clear the box to use the host name)</p>' .
			'<p id="discovery_state"><strong>' . self::h($st[1]) . '</strong></p>' .
			'<p>The announcement says only that a Benchy server is here, its network name and its HTTPS port. Machines on other VLANs do not see it; they need the DNS record below, or a typed address.</p>';
		if($srv !== null)
		{
			$h .= '<p>DNS record for other VLANs, to add to the resolver those machines use (Unbound custom options on pfSense and OPNsense):<br /><code id="discovery_srv">' . self::h($srv) . '</code></p>';
			if(self::srv_host($s) === null)
			{
				$h .= '<p>None of this server\'s names is a dotted host name, so the two <code>YOUR-SEARCH-DOMAIN</code> parts are yours to fill in.</p>';
			}
		}
		return $h . '<p><input type="submit" value="Save" /></p></form>';
	}
}

?>
