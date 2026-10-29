<?php

/*
	Benchy built-in HTTPS for the Phoromatic server (milestone 0.9).
	Benchy runs a pinned Caddy in front of PHP's built-in web server: Caddy terminates TLS, PHP listens on loopback only. This class is the one
	place that knows about Caddy: the settings (server JSON storage, key "https"), the Caddy binary pin and install, the generated Caddy JSON
	config, certificate inspection and import, live apply (the launcher restarts only Caddy), and the status shown by the CLI and the web UI.
	Linux only: the Phoromatic server does not run on Windows.

	This program is free software; you can redistribute it and/or modify
	it under the terms of the GNU General Public License as published by
	the Free Software Foundation; either version 3 of the License, or
	(at your option) any later version.
*/

class phoromatic_tls
{
	const DEFAULT_HTTPS_PORT = 39443;
	const DEFAULT_PHP_PORT = 39082;
	const MIN_CADDY = '2.8.0';
	const MAX_UPLOAD_BYTES = 65536;
	const MODES = array('off', 'internal', 'file', 'acme');
	const HTTP_MODES = array('serve', 'redirect', 'off');
	// What a plain-HTTP client may still ask for while http_mode is "serve" (older clients); everything else gets a 308 to HTTPS
	const CLIENT_PATHS = array('/phoromatic.php', '/server.php', '/download-cache.php', '/openbenchmarking-cache.php', '/event.php');

	public static function tls_path($create = true)
	{
		$path = phoromatic_server::phoromatic_path($create) . 'tls/';
		if($create && !is_dir($path))
		{
			@mkdir($path, 0700, true);
		}
		if($create && is_dir($path))
		{
			@chmod($path, 0700);
		}
		return $path;
	}
	public static function defaults()
	{
		return array(
			'mode' => 'off',
			'names' => array(),
			'https_port' => self::DEFAULT_HTTPS_PORT,
			'http_mode' => 'serve',
			'php_port' => 0,
			'php_workers' => 8,
			'file' => array('cert' => null, 'key' => null),
			'acme' => array('directory' => null, 'email' => null, 'ca_roots' => null),
			'caddy' => array('version' => null, 'path' => null, 'sha256' => null, 'source' => null)
			);
	}
	public static function settings()
	{
		phoromatic_server::init_settings_store();
		$stored = phoromatic_server::read_setting('https');
		$stored = is_string($stored) ? json_decode($stored, true) : false;
		$s = self::defaults();
		if(is_array($stored))
		{
			foreach($stored as $k => $v)
			{
				if(isset($s[$k]) && is_array($s[$k]) && is_array($v) && $k != 'names')
				{
					$s[$k] = array_merge($s[$k], $v);
				}
				else if(isset($s[$k]))
				{
					$s[$k] = $v;
				}
			}
		}
		return $s;
	}
	public static function save_settings($s)
	{
		phoromatic_server::init_settings_store();
		$json = json_encode($s);
		self::forget_live_cache();
		phoromatic_server::save_setting('https', $json);
		$check = phoromatic_server::read_setting('https');
		return $check === $json;
	}
	public static function is_on()
	{
		$s = self::settings();
		return $s['mode'] != 'off';
	}
	public static function php_workers($s = null)
	{
		$s = $s === null ? self::settings() : $s;
		$env = getenv('PHOROMATIC_PHP_WORKERS');
		if($env !== false && ctype_digit($env) && $env >= 1 && $env <= 256)
		{
			return (int)$env;
		}
		return isset($s['php_workers']) && is_numeric($s['php_workers']) && $s['php_workers'] >= 1 ? (int)$s['php_workers'] : 8;
	}
	public static function workers_rule()
	{
		return 'Rule of thumb: php_workers = 4 + the peak number of clients downloading tests from this server\'s cache at the same time (8 for a homelab, 16 for 50-100 systems on staggered schedules, 32 for 100+ systems that install at once; set PHOROMATIC_PHP_WORKERS to change it).';
	}

	// ---------- names
	public static function parse_names($list)
	{
		// array of names, or an error string. Hostnames or IP addresses, at least one.
		$names = array();
		foreach(is_array($list) ? $list : preg_split('/[\s,]+/', trim((string)$list), -1, PREG_SPLIT_NO_EMPTY) as $n)
		{
			$n = strtolower(trim($n));
			if(filter_var($n, FILTER_VALIDATE_IP))
			{
				$names[$n] = $n;
			}
			else if(preg_match('/^(\*\.)?([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/', $n) && strlen($n) <= 253)
			{
				$names[$n] = $n;
			}
			else
			{
				return '"' . substr(preg_replace('/[^\x20-\x7e]/', '?', $n), 0, 80) . '" is not a host name or an IP address.';
			}
		}
		return empty($names) ? 'At least one host name or IP address is needed (the names clients use to reach this server).' : array_values($names);
	}
	public static function name_covered($name, $sans)
	{
		// $sans: list of 'DNS:x' / 'IP Address:y' strings as OpenSSL prints them
		$name = strtolower($name);
		foreach($sans as $san)
		{
			if(strncasecmp($san, 'DNS:', 4) == 0)
			{
				$d = strtolower(substr($san, 4));
				if($d === $name || (strncmp($d, '*.', 2) == 0 && ($p = strpos($name, '.')) !== false && substr($name, $p) === substr($d, 1) && !filter_var($name, FILTER_VALIDATE_IP)))
				{
					return true;
				}
			}
			else if(strncasecmp($san, 'IP Address:', 11) == 0)
			{
				$ip = trim(substr($san, 11));
				if(@inet_pton($ip) !== false && @inet_pton($name) !== false && inet_pton($ip) === inet_pton($name))
				{
					return true;
				}
			}
		}
		return false;
	}
	public static function detected_names($host_header)
	{
		// For the "Turn on HTTPS" button: the host of this request plus the server's detected IP
		$names = array();
		$host = (string)$host_header;
		if(preg_match('/^\[([0-9a-f:]+)\](:\d+)?$/i', $host, $m))
		{
			$host = $m[1];
		}
		else if(($p = strrpos($host, ':')) !== false && strpos($host, ':') === $p)
		{
			$host = substr($host, 0, $p);
		}
		if($host !== '' && !in_array($host, array('0.0.0.0', 'localhost', '127.0.0.1', '::1'), true))
		{
			$names[] = $host;
		}
		if(($ip = phodevi::read_property('network', 'ip')) && filter_var($ip, FILTER_VALIDATE_IP) && !in_array($ip, $names, true))
		{
			$names[] = $ip;
		}
		if(($hn = phodevi::read_property('system', 'hostname')) && is_string($hn) && !in_array(strtolower($hn), array_map('strtolower', $names), true))
		{
			$names[] = $hn;
		}
		$parsed = self::parse_names($names);
		return is_array($parsed) ? $parsed : array('localhost');
	}

	// ---------- the Caddy binary
	public static function platform_key()
	{
		$m = php_uname('m');
		return in_array($m, array('aarch64', 'arm64'), true) ? 'linux-arm64' : ($m == 'x86_64' || $m == 'amd64' ? 'linux-x64' : null);
	}
	public static function caddy_pin()
	{
		$file = PTS_CORE_STATIC_PATH . 'update/runtime-pins.json';
		$pins = is_file($file) ? json_decode(file_get_contents($file), true) : null;
		$key = self::platform_key();
		if($key && is_array($pins) && isset($pins[$key]['caddy']['artifact']['sha256']) && isset($pins[$key]['caddy']['version']))
		{
			return $pins[$key]['caddy'];
		}
		return false;
	}
	public static function sha256_file($file)
	{
		return is_file($file) ? hash_file('sha256', $file) : false;
	}
	public static function run_caddy($caddy, $args, $timeout = 30)
	{
		// array(exit code, output). Caddy's own config/autosave directory is kept inside <tls>, so no home directory is needed.
		$cmd = escapeshellarg($caddy);
		foreach($args as $a)
		{
			$cmd .= ' ' . escapeshellarg($a);
		}
		$config_home = self::tls_path() . 'caddy-config';
		if(!is_dir($config_home))
		{
			mkdir($config_home, 0700, true);
		}
		$p = @proc_open($cmd . ' 2>&1', array(1 => array('pipe', 'w')), $pipes, null, array('XDG_CONFIG_HOME' => $config_home, 'XDG_DATA_HOME' => self::tls_path() . 'caddy-data', 'PATH' => getenv('PATH')));
		if(!is_resource($p))
		{
			return array(127, 'could not run ' . $caddy);
		}
		stream_set_blocking($pipes[1], false);
		$out = '';
		$end = time() + $timeout;
		while(time() < $end)
		{
			$chunk = stream_get_contents($pipes[1]);
			$out .= $chunk;
			$st = proc_get_status($p);
			if(!$st['running'])
			{
				$out .= stream_get_contents($pipes[1]);
				break;
			}
			usleep(50000);
		}
		$st = proc_get_status($p);
		if($st['running'])
		{
			proc_terminate($p, 9);
			$out .= ' (timed out)';
			$code = 124;
		}
		else
		{
			$code = $st['exitcode'];
		}
		fclose($pipes[1]);
		$closed = proc_close($p);
		return array($code === -1 ? $closed : $code, trim($out));
	}
	public static function caddy_version($caddy)
	{
		list($code, $out) = self::run_caddy($caddy, array('version'), 10);
		return $code == 0 && preg_match('/v?(\d+\.\d+\.\d+)/', $out, $m) ? $m[1] : false;
	}
	public static function caddy_check($s)
	{
		// null if the recorded Caddy is usable, else why not. Run before every start: a damaged binary stops the server, it does
		// not fall back to plain HTTP.
		$c = $s['caddy'];
		if(empty($c['path']) || !is_file($c['path']) || !is_executable($c['path']))
		{
			return 'No Caddy is installed for the built-in HTTPS. Run: benchy server-caddy-install';
		}
		if($c['source'] === 'pin')
		{
			$have = self::sha256_file($c['path']);
			if(empty($c['sha256']) || $have === false || !hash_equals($c['sha256'], $have))
			{
				return 'The Caddy binary ' . $c['path'] . ' does not match the sha256 recorded when it was installed (it was changed or damaged). Run: benchy server-caddy-install';
			}
		}
		$v = self::caddy_version($c['path']);
		if($v === false)
		{
			return 'The Caddy binary ' . $c['path'] . ' does not run (benchy server-caddy-install installs the pinned one).';
		}
		if(version_compare($v, self::MIN_CADDY, '<'))
		{
			return 'Caddy ' . $v . ' is too old; ' . self::MIN_CADDY . ' or newer is needed.';
		}
		return null;
	}
	public static function caddy_install($archive = null, $use = null, $progress = null)
	{
		// array(true, message) or array(false, error). Records the binary in the settings.
		if(phodevi::is_windows())
		{
			return array(false, 'The Phoromatic server isn\'t supported on Windows.');
		}
		$s = self::settings();
		if($use !== null)
		{
			if(!is_file($use) || !is_executable($use))
			{
				return array(false, $use . ' is not an executable file.');
			}
			$v = self::caddy_version($use);
			if($v === false || version_compare($v, self::MIN_CADDY, '<'))
			{
				return array(false, $use . ' ' . ($v === false ? 'does not run as Caddy' : 'is Caddy ' . $v . '; ' . self::MIN_CADDY . ' or newer is needed') . '.');
			}
			$s['caddy'] = array('version' => $v, 'path' => realpath($use), 'sha256' => null, 'source' => 'system');
			self::save_settings($s);
			return array(true, 'Using Caddy ' . $v . ' at ' . $s['caddy']['path']);
		}
		$pin = self::caddy_pin();
		if($pin === false)
		{
			return array(false, 'This Benchy pins no Caddy for ' . php_uname('m') . '. Use server-caddy-install --use /path/to/caddy (2.8 or newer).');
		}
		$tls = self::tls_path();
		$work = $tls . 'download.tmp';
		@unlink($work);
		if($archive !== null)
		{
			if(!is_file($archive) || !@copy($archive, $work))
			{
				return array(false, 'Cannot read ' . $archive . '.');
			}
		}
		else
		{
			$got = false;
			foreach($pin['upstream_urls'] as $url)
			{
				$ctx = stream_context_create(array('ssl' => array('verify_peer' => true, 'verify_peer_name' => true), 'http' => array('timeout' => 60, 'follow_location' => 1, 'max_redirects' => 5, 'user_agent' => 'Benchy')));
				$in = @fopen($url, 'rb', false, $ctx);
				if(!$in)
				{
					continue;
				}
				$out = fopen($work, 'wb');
				$total = 0;
				while(!feof($in) && $total <= $pin['artifact']['size'])
				{
					$chunk = fread($in, 65536);
					if($chunk === false)
					{
						break;
					}
					$total += strlen($chunk);
					fwrite($out, $chunk);
				}
				fclose($in);
				fclose($out);
				if($total == $pin['artifact']['size'])
				{
					$got = true;
					break;
				}
			}
			if(!$got)
			{
				@unlink($work);
				return array(false, 'Could not download ' . $pin['artifact']['file'] . ' (' . $pin['upstream_urls'][0] . '). Download it yourself and run: benchy server-caddy-install --archive <file>');
			}
		}
		if(filesize($work) != $pin['artifact']['size'] || !hash_equals($pin['artifact']['sha256'], (string)self::sha256_file($work)))
		{
			@unlink($work);
			return array(false, 'The Caddy archive does not match the pinned size and sha256 (' . $pin['artifact']['sha256'] . '); nothing was installed.');
		}
		$bin_dir = $tls . 'bin/';
		@mkdir($bin_dir, 0700, true);
		$unpack = $tls . 'unpack.tmp/';
		pts_file_io::delete($unpack, null, true);
		@mkdir($unpack, 0700, true);
		$p = proc_open(array('tar', '-xzf', $work, '-C', $unpack, 'caddy'), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
		$tar_out = is_resource($p) ? stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]) : 'tar missing';
		if(is_resource($p))
		{
			proc_close($p);
		}
		@unlink($work);
		if(!is_file($unpack . 'caddy'))
		{
			pts_file_io::delete($unpack, null, true);
			return array(false, 'The archive did not contain a caddy binary. ' . trim($tar_out));
		}
		$dest = $bin_dir . 'caddy-' . $pin['version'];
		@unlink($dest);
		rename($unpack . 'caddy', $dest);
		chmod($dest, 0755);
		pts_file_io::delete($unpack, null, true);
		$v = self::caddy_version($dest);
		if($v !== $pin['version'])
		{
			@unlink($dest);
			return array(false, 'The unpacked Caddy reports ' . ($v === false ? 'nothing' : $v) . ', not the pinned ' . $pin['version'] . '; removed.');
		}
		$s['caddy'] = array('version' => $v, 'path' => $dest, 'sha256' => self::sha256_file($dest), 'source' => 'pin');
		self::save_settings($s);
		return array(true, 'Installed Caddy ' . $v . ' at ' . $dest);
	}

	// ---------- certificates
	public static function cert_info($pem)
	{
		// array of subject, issuer, sans (list), not_before, not_after (unix), sha256 (hex, DER), is_ca; or false
		$x = @openssl_x509_read($pem);
		if(!$x)
		{
			return false;
		}
		$p = @openssl_x509_parse($x);
		if(!is_array($p))
		{
			return false;
		}
		$sans = array();
		if(!empty($p['extensions']['subjectAltName']))
		{
			foreach(explode(',', $p['extensions']['subjectAltName']) as $san)
			{
				$sans[] = trim($san);
			}
		}
		return array(
			'subject' => isset($p['name']) ? $p['name'] : '',
			'issuer' => self::dn_string(isset($p['issuer']) ? $p['issuer'] : array()),
			'self_signed' => isset($p['name']) && $p['name'] === self::dn_string_slash(isset($p['issuer']) ? $p['issuer'] : array()),
			'sans' => $sans,
			'not_before' => $p['validFrom_time_t'],
			'not_after' => $p['validTo_time_t'],
			'sha256' => strtolower((string)openssl_x509_fingerprint($x, 'sha256')),
			'is_ca' => !empty($p['extensions']['basicConstraints']) && stripos($p['extensions']['basicConstraints'], 'CA:TRUE') !== false
			);
	}
	protected static function dn_string($dn)
	{
		$parts = array();
		foreach($dn as $k => $v)
		{
			$parts[] = $k . '=' . (is_array($v) ? implode('+', $v) : $v);
		}
		return implode(', ', $parts);
	}
	protected static function dn_string_slash($dn)
	{
		$s = '';
		foreach($dn as $k => $v)
		{
			$s .= '/' . $k . '=' . (is_array($v) ? implode('+', $v) : $v);
		}
		return $s;
	}
	public static function split_pem_certs($pem)
	{
		preg_match_all('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', (string)$pem, $m);
		return $m[0];
	}
	public static function fingerprint_normalise($fp)
	{
		$fp = strtolower(str_replace(array(':', ' ', "\t"), '', trim((string)$fp)));
		return preg_match('/^[0-9a-f]{64}$/', $fp) ? $fp : false;
	}
	public static function fingerprint_display($hex)
	{
		return strtoupper(implode(':', str_split($hex, 2)));
	}
	public static function validate_certificate($cert_pem, $key_pem, $names = null)
	{
		// The one validation both the CLI and the web form use. Returns array(error|null, warnings[], info|null, cert_pem, key_pem).
		// $cert_pem: leaf first, then intermediates. $key_pem: an unencrypted PEM private key.
		$certs = self::split_pem_certs($cert_pem);
		if(empty($certs))
		{
			return array('No PEM certificate was found in the certificate file.', array(), null, null, null);
		}
		$info = self::cert_info($certs[0]);
		if($info === false)
		{
			return array('The certificate could not be read.', array(), null, null, null);
		}
		$key = @openssl_pkey_get_private($key_pem);
		if(!$key)
		{
			return array('The private key could not be read (it must be a PEM key without a passphrase).', array(), null, null, null);
		}
		if(!@openssl_x509_check_private_key($certs[0], $key))
		{
			return array('The private key does not belong to the certificate.', array(), null, null, null);
		}
		$now = time();
		if($info['not_after'] < $now)
		{
			return array('The certificate expired on ' . gmdate('Y-m-d H:i', $info['not_after']) . ' UTC.', array(), null, null, null);
		}
		if($info['not_before'] > $now)
		{
			return array('The certificate is not valid until ' . gmdate('Y-m-d H:i', $info['not_before']) . ' UTC.', array(), null, null, null);
		}
		$warnings = array();
		if(count($certs) < 2 && !$info['self_signed'])
		{
			$warnings[] = 'The file holds no intermediate certificates; clients that do not already know the issuing CA\'s intermediate may fail to verify it.';
		}
		if($info['not_after'] < $now + 30 * 86400)
		{
			$warnings[] = 'The certificate expires in less than 30 days (' . gmdate('Y-m-d', $info['not_after']) . ').';
		}
		if($names)
		{
			$missing = array();
			foreach($names as $n)
			{
				if(!self::name_covered($n, $info['sans']))
				{
					$missing[] = $n;
				}
			}
			if(!empty($missing))
			{
				$warnings[] = 'The certificate does not cover: ' . implode(', ', $missing) . '.';
			}
		}
		$key_out = '';
		openssl_pkey_export($key, $key_out);
		return array(null, $warnings, $info, implode("\n", $certs) . "\n", $key_out);
	}
	public static function import_pem($cert_pem, $key_pem, $names = null)
	{
		return self::validate_certificate($cert_pem, $key_pem, $names);
	}
	public static function import_pfx($pfx_data, $password, $names = null)
	{
		$bag = array();
		if(!@openssl_pkcs12_read($pfx_data, $bag, (string)$password) || empty($bag['cert']) || empty($bag['pkey']))
		{
			return array('The PFX could not be read (wrong password, or it holds no certificate and key).', array(), null, null, null);
		}
		$chain = $bag['cert'];
		if(!empty($bag['extracerts']))
		{
			$chain .= "\n" . (is_array($bag['extracerts']) ? implode("\n", $bag['extracerts']) : $bag['extracerts']);
		}
		return self::validate_certificate($chain, $bag['pkey'], $names);
	}
	public static function store_certificate($cert_pem, $key_pem)
	{
		// <tls>/cert.pem and key.pem, 0600, written through a temp name so a failed write leaves the old pair
		$tls = self::tls_path();
		foreach(array('cert.pem' => $cert_pem, 'key.pem' => $key_pem) as $name => $data)
		{
			$tmp = $tls . $name . '.new';
			@unlink($tmp);
			$old = umask(0077);
			$ok = file_put_contents($tmp, $data) === strlen($data);
			umask($old);
			if(!$ok)
			{
				@unlink($tmp);
				return false;
			}
			chmod($tmp, 0600);
		}
		$had_cert = is_file($tls . 'cert.pem');
		if($had_cert)
		{
			@copy($tls . 'cert.pem', $tls . 'cert.pem.prev');
			@copy($tls . 'key.pem', $tls . 'key.pem.prev');
			@chmod($tls . 'cert.pem.prev', 0600);
			@chmod($tls . 'key.pem.prev', 0600);
		}
		return rename($tls . 'cert.pem.new', $tls . 'cert.pem') && rename($tls . 'key.pem.new', $tls . 'key.pem');
	}
	public static function root_ca_path()
	{
		return self::tls_path(false) . 'caddy-data/pki/authorities/local/root.crt';
	}
	public static function root_ca_pem()
	{
		$f = self::root_ca_path();
		return is_file($f) ? file_get_contents($f) : false;
	}
	public static function root_ca_fingerprint()
	{
		$pem = self::root_ca_pem();
		$info = $pem ? self::cert_info($pem) : false;
		return $info ? $info['sha256'] : false;
	}
	public static function sni_name($s)
	{
		foreach($s['names'] as $n)
		{
			if(strncmp($n, '*.', 2) != 0)
			{
				return $n;
			}
		}
		return $s['names'][0];
	}
	public static function names_from_cert($info)
	{
		$names = array();
		foreach($info['sans'] as $san)
		{
			if(strncasecmp($san, 'DNS:', 4) == 0)
			{
				$names[] = substr($san, 4);
			}
			else if(strncasecmp($san, 'IP Address:', 11) == 0)
			{
				$names[] = trim(substr($san, 11));
			}
		}
		return $names;
	}
	public static function live_certificate($s = null)
	{
		// The certificate Caddy really serves, through a handshake to loopback with SNI = first name (one code path for every mode).
		// array(cert info) or false.
		$s = $s === null ? self::settings() : $s;
		if($s['mode'] == 'off' || empty($s['names']))
		{
			return false;
		}
		$ctx = stream_context_create(array('ssl' => array('verify_peer' => false, 'verify_peer_name' => false, 'capture_peer_cert' => true, 'peer_name' => self::sni_name($s), 'SNI_enabled' => true)));
		$fp = @stream_socket_client('ssl://127.0.0.1:' . (int)$s['https_port'], $errno, $errstr, 5, STREAM_CLIENT_CONNECT, $ctx);
		if(!$fp)
		{
			return false;
		}
		$params = stream_context_get_params($fp);
		fclose($fp);
		return isset($params['options']['ssl']['peer_certificate']) ? self::cert_info($params['options']['ssl']['peer_certificate']) : false;
	}

	// ---------- the Caddy config
	public static function post_max_bytes()
	{
		$v = trim((string)ini_get('post_max_size'));
		$n = (float)$v;
		switch(strtoupper(substr($v, -1)))
		{
			case 'G': $n *= 1024;
			case 'M': $n *= 1024;
			case 'K': $n *= 1024;
		}
		return (int)$n;
	}
	public static function caddy_config($s, $secret, $php_port, $http_port, $post_max = null)
	{
		// The Caddy JSON config (never a Caddyfile, so there are no escaping rules). The admin API is disabled: nothing can reconfigure
		// Caddy at run time. X-Benchy-* request headers are always SET, which overwrites anything a client sent.
		$tls = self::tls_path();
		$post_max = $post_max === null ? self::post_max_bytes() : $post_max;
		$https_port = (int)$s['https_port'];
		$names = $s['names'];
		$proxy = function($proto, $port) use ($secret, $php_port)
			{
				return array('handler' => 'reverse_proxy', 'upstreams' => array(array('dial' => '127.0.0.1:' . $php_port)),
					// The underscore spellings are removed as well: PHP folds X_Benchy_* into the same variables (proxy_prepend.php refuses them too)
					'headers' => array('request' => array('delete' => array('X_Benchy_Proxy', 'X_Benchy_Client_Ip', 'X_Benchy_Proto', 'X_Benchy_Port', 'X_benchy_*', 'X.Benchy.Client.Ip', 'X.Benchy.Proto', 'X.Benchy.Port', 'X.Benchy.Proxy', 'X.benchy.*'), 'set' => array(
						'X-Benchy-Proxy' => array($secret),
						'X-Benchy-Client-Ip' => array('{http.request.remote.host}'),
						'X-Benchy-Proto' => array($proto),
						'X-Benchy-Port' => array((string)$port)))));
			};
		$body_limit = array('handler' => 'request_body', 'max_size' => $post_max + 1048576);
		$sni = $names[0];
		foreach($names as $n)
		{
			if(strncmp($n, '*.', 2) != 0)
			{
				$sni = $n;
				break;
			}
		}
		$servers = array('benchy_https' => array(
			'listen' => array(':' . $https_port),
			'automatic_https' => array('disable_redirects' => true),
			'logs' => (object)array(),
			'tls_connection_policies' => array(array('default_sni' => $sni)),
			'routes' => array(array('handle' => array($body_limit, $proxy('https', $https_port))))));

		$to_https = array('handler' => 'static_response', 'status_code' => 308, 'headers' => array('Location' => array('https://{http.request.host}:' . $https_port . '{http.request.uri}')));
		$refuse = array('handler' => 'static_response', 'status_code' => 403, 'headers' => array('Content-Type' => array('application/json')),
			'body' => json_encode(array('phoromatic' => array('error' => 'This server requires HTTPS. Reconnect with: benchy server-trust https://{http.request.host}:' . $https_port . '/<account>')), JSON_UNESCAPED_SLASHES));
		if($s['http_mode'] == 'serve')
		{
			$servers['benchy_http'] = array('listen' => array(':' . $http_port), 'automatic_https' => array('disable' => true), 'logs' => (object)array(), 'routes' => array(
				array('match' => array(array('path' => self::CLIENT_PATHS)), 'handle' => array($body_limit, $proxy('http', $http_port)), 'terminal' => true),
				array('handle' => array($to_https))));
		}
		else if($s['http_mode'] == 'redirect')
		{
			$servers['benchy_http'] = array('listen' => array(':' . $http_port), 'automatic_https' => array('disable' => true), 'logs' => (object)array(), 'routes' => array(
				array('match' => array(array('method' => array('GET', 'HEAD'))), 'handle' => array($to_https), 'terminal' => true),
				array('handle' => array($refuse))));
		}

		$tls_app = array();
		$policy_base = array('subjects' => $names);
		switch($s['mode'])
		{
			case 'internal':
				$tls_app['certificates'] = array('automate' => $names);
				$tls_app['automation'] = array('policies' => array($policy_base + array('issuers' => array(array('module' => 'internal')), 'disable_ocsp_stapling' => true)));
				break;
			case 'file':
				$tls_app['certificates'] = array('load_files' => array(array('certificate' => $s['file']['cert'], 'key' => $s['file']['key'])));
				// No issuers: Caddy must not try to obtain a certificate for these names itself
				$tls_app['automation'] = array('policies' => array(array('subjects' => $names, 'issuers' => array(), 'disable_ocsp_stapling' => true)));
				break;
			case 'acme':
				$issuer = array('module' => 'acme', 'ca' => $s['acme']['directory'], 'email' => $s['acme']['email']);
				if(!empty($s['acme']['ca_roots']))
				{
					$issuer['trusted_roots_pem_files'] = array($s['acme']['ca_roots']);
				}
				$tls_app['certificates'] = array('automate' => $names);
				$tls_app['automation'] = array('policies' => array($policy_base + array('issuers' => array($issuer))));
				break;
		}
		$config = array(
			'admin' => array('disabled' => true),
			'storage' => array('module' => 'file_system', 'root' => $tls . 'caddy-data'),
			'logging' => array('logs' => array('default' => array('level' => 'INFO',
				'writer' => array('output' => 'file', 'filename' => $tls . 'caddy.log', 'roll_size_mb' => 10, 'roll_keep' => 3)))),
			'apps' => array(
				'http' => array('http_port' => (int)$http_port, 'https_port' => $https_port, 'servers' => $servers),
				'tls' => $tls_app,
				// install_trust false is load-bearing: by default Caddy tries to add its root to the host trust store (sudo prompt, or a failure)
				'pki' => array('certificate_authorities' => array('local' => array('name' => 'Benchy ' . $sni . ' CA', 'install_trust' => false)))));
		return $config;
	}
	public static function write_config($s, $secret, $php_port, $http_port, $file = null)
	{
		$file = $file === null ? self::tls_path() . 'caddy.json' : $file;
		$tmp = $file . '.tmp';
		$old = umask(0077);
		$ok = file_put_contents($tmp, json_encode(self::caddy_config($s, $secret, $php_port, $http_port), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)) !== false;
		umask($old);
		if(!$ok)
		{
			return false;
		}
		chmod($tmp, 0600);
		return rename($tmp, $file);
	}
	public static function validate_config($s, $file)
	{
		// null if "caddy validate" accepts the file, else its message
		$caddy = $s['caddy']['path'];
		list($code, $out) = self::run_caddy($caddy, array('validate', '--config', $file), 60);
		return $code == 0 ? null : 'caddy validate failed: ' . substr($out, -400);
	}
	public static function port_in_use($port)
	{
		$fp = @fsockopen('127.0.0.1', (int)$port, $errno, $errstr, 1);
		if($fp)
		{
			fclose($fp);
			return true;
		}
		$srv = @stream_socket_server('tcp://0.0.0.0:' . (int)$port, $e, $es);
		if($srv)
		{
			fclose($srv);
			return false;
		}
		return true;
	}
	public static function start_checks($s, $http_port, &$php_port)
	{
		// null if the server may start in HTTPS mode, else one line saying why not (it never falls back to plain HTTP)
		if(phodevi::is_windows())
		{
			return 'The Phoromatic server isn\'t supported on Windows.';
		}
		if(!in_array($s['mode'], self::MODES, true) || !in_array($s['http_mode'], self::HTTP_MODES, true))
		{
			return 'The HTTPS settings are damaged (mode ' . $s['mode'] . '). Run benchy server-https-status, or benchy server-https-off.';
		}
		if(empty($s['names']))
		{
			return 'The HTTPS settings name no host. Run: benchy server-https-set ' . $s['mode'] . ' --names a,b';
		}
		if(($problem = self::caddy_check($s)) !== null)
		{
			return $problem;
		}
		if($s['mode'] == 'file')
		{
			$cert = is_file($s['file']['cert']) ? file_get_contents($s['file']['cert']) : '';
			$key = is_file($s['file']['key']) ? file_get_contents($s['file']['key']) : '';
			list($error) = self::validate_certificate($cert, $key);
			if($error !== null)
			{
				return 'The HTTPS certificate cannot be used: ' . $error;
			}
		}
		if($s['mode'] == 'acme' && ((int)$s['https_port'] != 443 || (int)$http_port != 80))
		{
			return 'ACME needs this server on ports 443 (HTTPS) and 80 (HTTP).';
		}
		if(self::port_in_use($s['https_port']))
		{
			return 'The HTTPS port ' . $s['https_port'] . ' is already in use.';
		}
		$php_port = (int)$s['php_port'] > 1 ? (int)$s['php_port'] : self::DEFAULT_PHP_PORT;
		if(self::port_in_use($php_port))
		{
			if((int)$s['php_port'] > 1)
			{
				return 'The PHP port ' . $php_port . ' is already in use.';
			}
			$php_port = pts_network::find_available_port(39100, 39999);
		}
		return null;
	}
	public static function new_secret()
	{
		return bin2hex(random_bytes(32));
	}

	// ---------- audited changes (CLI and web form share this)
	public static function commit_change($account_id, $actor_type, $actor, $actor_detail, $new_settings, $audits, $cert_pem = null, $key_pem = null)
	{
		// null on success, else why nothing was changed. The audit rows are written first inside a database transaction and the change
		// is refused if they cannot be: no unaudited change. $audits: list of array(action, detail array). Never records key material.
		$before = self::settings();
		if(@phoromatic_server::$db->exec('BEGIN IMMEDIATE') === false)
		{
			return 'The database is busy; nothing was changed.';
		}
		foreach($audits as $a)
		{
			if(!phoromatic_admin_audit::record($account_id, $actor_type, $actor, $a[0], array('actor_detail' => $actor_detail,
				'subject_type' => 'server_setting', 'subject_id' => 'https', 'subject_label' => 'HTTPS', 'detail' => $a[1])))
			{
				$why = phoromatic_admin_audit::last_error();
				@phoromatic_server::$db->exec('ROLLBACK');
				phoromatic_admin_audit::mirror_note($a[0] . ' rolled back, nothing changed: ' . $why);
				return 'The change could not be recorded in the audit log (' . $why . '); nothing was changed.';
			}
		}
		$tls = self::tls_path();
		// What the launcher's restore (and the next page view) puts back if the new configuration does not come up
		@file_put_contents($tls . 'settings.prev', json_encode($before));
		@chmod($tls . 'settings.prev', 0600);
		$had_pair = is_file($tls . 'cert.pem') && is_file($tls . 'key.pem');
		if($cert_pem !== null && !self::store_certificate($cert_pem, $key_pem))
		{
			@phoromatic_server::$db->exec('ROLLBACK');
			phoromatic_admin_audit::mirror_note('https change rolled back, nothing changed: the certificate files could not be written');
			return 'The certificate could not be stored (the server could not write ' . $tls . '); nothing was changed.';
		}
		$restore = function() use ($cert_pem, $had_pair, $tls, $before)
			{
				if($cert_pem !== null)
				{
					if($had_pair && is_file($tls . 'cert.pem.prev'))
					{
						@copy($tls . 'cert.pem.prev', $tls . 'cert.pem');
						@copy($tls . 'key.pem.prev', $tls . 'key.pem');
					}
					else
					{
						@unlink($tls . 'cert.pem');
						@unlink($tls . 'key.pem');
					}
				}
				self::save_settings($before);
			};
		if(!self::save_settings($new_settings))
		{
			$restore();
			@phoromatic_server::$db->exec('ROLLBACK');
			phoromatic_admin_audit::mirror_note('https change rolled back, nothing changed: the settings file could not be written');
			return 'The setting could not be saved (the server could not write the settings file); nothing was changed.';
		}
		if(@phoromatic_server::$db->exec('COMMIT') === false)
		{
			$restore();
			@phoromatic_server::$db->exec('ROLLBACK');
			phoromatic_admin_audit::mirror_note('https change rolled back, settings restored: the audit rows could not be committed');
			return 'The change could not be recorded in the audit log; the settings were left as they were.';
		}
		return null;
	}
	public static function audit_details_for_cert($info, $source, $warnings = array())
	{
		return array('source' => $source, 'subject' => $info['subject'], 'issuer' => $info['issuer'], 'sans' => $info['sans'], 'not_after' => gmdate('Y-m-d H:i:s', $info['not_after']) . ' UTC', 'sha256' => $info['sha256'], 'warnings' => $warnings);
	}
	public static function describe($s)
	{
		return array('mode' => $s['mode'], 'names' => $s['names'], 'https_port' => $s['https_port'], 'http_mode' => $s['http_mode']);
	}

	// ---------- shared by the server-host commands
	public static function honour_silence()
	{
		// The client's error handler (pts_client::code_error_handler) prints notices even for statements written with @. These commands
		// use @ on calls that may fail in normal use (a missing file, a bad certificate), so the @ operator is honoured again here.
		$previous = set_error_handler(function($number, $text, $file, $line) use (&$previous)
			{
				if(!(error_reporting() & $number))
				{
					return true;
				}
				return $previous ? call_user_func($previous, $number, $text, $file, $line) : false;
			});
	}
	public static function cli_prelude($usage)
	{
		self::honour_silence();
		// The account id the audit rows go under, or false after printing why the command cannot run (Windows, wrong user, no database)
		if(phodevi::is_windows())
		{
			echo PHP_EOL . 'ERROR: The Phoromatic server isn\'t supported on Windows.' . PHP_EOL . PHP_EOL;
			return false;
		}
		if(($problem = phoromatic_private_repo_auth::server_command_preflight($usage)) !== null)
		{
			echo PHP_EOL . 'ERROR: ' . $problem . PHP_EOL . PHP_EOL;
			return false;
		}
		phoromatic_server::prepare_database();
		$account_id = phoromatic_private_repo::server_account_id();
		return $account_id ? $account_id : '';
	}
	public static function report_apply($before, $after)
	{
		// What to tell the admin after a committed change: applied live, or restart needed
		if($before['mode'] == 'off' || $after['mode'] == 'off')
		{
			return 'Restart the Benchy server service to apply this change (systemctl restart phoromatic-server).';
		}
		list($applied, $message) = self::apply_live($after);
		return $applied ? $message : ($message . (strpos($message, 'not running') !== false ? '' : ' Restart the Benchy server service to apply it.'));
	}

	// ---------- running service: runtime.json and live apply
	public static function runtime_file()
	{
		return self::tls_path() . 'runtime.json';
	}
	public static function read_runtime()
	{
		$f = self::runtime_file();
		$r = is_file($f) ? json_decode(file_get_contents($f), true) : null;
		return is_array($r) ? $r : false;
	}
	public static function service_running()
	{
		$r = self::read_runtime();
		return $r && !empty($r['launcher_pid']) && is_dir('/proc/' . (int)$r['launcher_pid']);
	}
	public static function apply_failed_file()
	{
		return self::tls_path() . 'apply-failed';
	}
	public static function apply_live($s)
	{
		// array(applied (bool), message). The running Caddy picks the new config up through the flag the launcher watches; PHP keeps
		// running. Anything that changes the PHP side (off <-> on) needs a restart instead.
		if(!self::service_running())
		{
			return array(false, 'The service is not running; the change applies when it starts.');
		}
		$r = self::read_runtime();
		if($s['mode'] == 'off' || !isset($r['php_port']) || !isset($r['secret']) || empty($r['https']) || empty($r['supervised']))
		{
			return array(false, 'Restart the Benchy server service to apply this change.');
		}
		$tls = self::tls_path();
		$new = $tls . 'caddy.json.new';
		if(!self::write_config($s, $r['secret'], $r['php_port'], $r['http_port'], $new))
		{
			return array(false, 'The Caddy configuration could not be written.');
		}
		if(($problem = self::validate_config($s, $new)) !== null)
		{
			@unlink($new);
			return array(false, $problem);
		}
		@unlink(self::apply_failed_file());
		self::forget_live_cache();
		if(is_file($tls . 'caddy.json'))
		{
			@copy($tls . 'caddy.json', $tls . 'caddy.json.prev');
			@chmod($tls . 'caddy.json.prev', 0600);
		}
		rename($new, $tls . 'caddy.json');
		file_put_contents($tls . 'reload-caddy', (string)time());
		return array(true, 'Applying now: Caddy restarts on its own within a few seconds; PHP keeps running.');
	}
	public static function wait_applied($timeout = 20)
	{
		// true once the launcher has consumed the reload flag (and the apply-failed marker tells whether it worked)
		$end = time() + $timeout;
		while(time() < $end)
		{
			if(!is_file(self::tls_path() . 'reload-caddy'))
			{
				sleep(3);
				return !is_file(self::apply_failed_file());
			}
			sleep(1);
		}
		return false;
	}

	// ---------- status
	public static function status()
	{
		$s = self::settings();
		$live = $s['mode'] != 'off' ? self::live_certificate($s) : false;
		return array('settings' => $s, 'live' => $live, 'ca_fingerprint' => $s['mode'] == 'internal' ? self::root_ca_fingerprint() : false,
			'running' => self::service_running(), 'apply_failed' => is_file(self::apply_failed_file()));
	}
	public static function forget_live_cache()
	{
		// The 10-minute cache of the live handshake must not outlive a mode change or an apply
		$f = self::tls_path(false) . 'live-cert.cache';
		if(is_file($f))
		{
			unlink($f);
		}
	}
	public static function cached_live_certificate()
	{
		// For web pages: the handshake result, cached 10 minutes
		$f = self::tls_path() . 'live-cert.cache';
		if(is_file($f) && filemtime($f) > time() - 600)
		{
			$c = json_decode(file_get_contents($f), true);
			if(is_array($c))
			{
				return $c['info'];
			}
		}
		$info = self::live_certificate();
		if(is_file(self::tls_path() . 'reload-caddy'))
		{
			// An apply is pending: this handshake is still the old Caddy's, so it must not be cached
			return $info;
		}
		if($info === false)
		{
			// A failed handshake (Caddy down or restarting, e.g. on the launcher's rollback path) says nothing about the certificate: do not hide the banner for 10 minutes
			return $info;
		}
		@file_put_contents($f, json_encode(array('info' => $info)));
		@chmod($f, 0600);
		return $info;
	}
	public static function expiry_banner()
	{
		// null, or the warning for level-1 users: file mode < 21 days, internal/acme < 7 days (Caddy renews well before that)
		$s = self::settings();
		if($s['mode'] == 'off')
		{
			return null;
		}
		$c = self::cached_live_certificate();
		if(!is_array($c))
		{
			return null;
		}
		$left = $c['not_after'] - time();
		// Caddy's internal CA issues short-lived leaf certificates (12 hours) and renews them at two thirds of that, so for "internal" only an
		// expired certificate means renewal has failed; ACME certificates are renewed about 30 days before they end
		$limit = $s['mode'] == 'file' ? 21 * 86400 : ($s['mode'] == 'internal' ? 0 : 7 * 86400);
		if($left >= $limit)
		{
			return null;
		}
		return $left < 0 ? 'The HTTPS certificate expired on ' . gmdate('Y-m-d', $c['not_after']) . ' UTC. Clients can no longer connect securely.' :
			'The HTTPS certificate expires on ' . gmdate('Y-m-d', $c['not_after']) . ' UTC.' . ($s['mode'] == 'file' ? ' Upload a new one on the Settings page or with benchy server-https-set file.' : ' Caddy should have renewed it already; see ' . self::tls_path(false) . 'caddy.log.');
	}

	// ---------- the public URL
	public static function public_base($host_header = null)
	{
		// https://host:https_port when HTTPS is on, else http://host:port as it was. Host is HTTP_HOST without its port.
		$host = $host_header === null ? (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost') : $host_header;
		if(preg_match('/^(\[[0-9a-f:]+\])(:\d+)?$/i', $host, $m))
		{
			$name = $m[1];
			$port = isset($m[2]) ? substr($m[2], 1) : null;
		}
		else if(($p = strrpos($host, ':')) !== false)
		{
			$name = substr($host, 0, $p);
			$port = substr($host, $p + 1);
		}
		else
		{
			$name = $host;
			$port = null;
		}
		if(isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' && !empty($_SERVER['SERVER_PORT']))
		{
			return 'https://' . $name . ':' . (int)$_SERVER['SERVER_PORT'];
		}
		$s = self::settings();
		if($s['mode'] != 'off')
		{
			return 'https://' . $name . ':' . (int)$s['https_port'];
		}
		return 'http://' . $name . ($port ? ':' . $port : '');
	}
}

?>
