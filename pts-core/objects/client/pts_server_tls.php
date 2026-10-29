<?php

/*
	Benchy 0.9: how the client trusts a Benchy (Phoromatic) server's certificate. This program is free software; you can redistribute
	it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation; either
	version 3 of the License, or (at your option) any later version.

	Every request to an https:// Benchy server this client knows is verified. Trust is an ordered list of attempts: (1) the CA file, if
	there is one (BENCHY_SERVER_CAFILE, else <PTS_USER_PATH>/server-ca.pem); (2) the system store (OpenSSL's default paths on Linux, the
	Windows store on Windows). PHP cannot merge the two, so a request that fails certificate VERIFICATION on attempt 1 is retried once
	with attempt 2; a connection error is not retried. There is no fallback to HTTP and no switch to ignore a bad certificate.
	URLs that are not Benchy servers (test downloads from upstream mirrors) keep their old behaviour.
*/

class pts_server_tls
{
	private static $registered = array();
	private static $worked = array();
	private static $last_failure = null;

	public static function key($host, $port)
	{
		return strtolower($host) . ':' . (int)$port;
	}
	public static function register($host, $port)
	{
		self::$registered[self::key($host, $port)] = true;
	}
	public static function known($host, $port)
	{
		if(isset(self::$registered[self::key($host, $port)]))
		{
			return true;
		}
		// The servers this client is set up for (the Phoromatic client's last server, the private repository's server)
		$files = array(PTS_USER_PATH . 'modules-data/phoromatic/last-phoromatic-server', pts_private_repo_client::connection_file());
		foreach($files as $file)
		{
			if(is_file($file) && ($a = pts_server_address::parse(file_get_contents($file))) && self::key($a['host'], $a['port']) === self::key($host, $port))
			{
				return true;
			}
		}
		return false;
	}
	public static function benchy_url($url)
	{
		// array(host, port) when this is an https:// URL of a Benchy server this client knows, else false
		if(!is_string($url) || strncasecmp($url, 'https://', 8) != 0 || !($p = parse_url($url)) || empty($p['host']))
		{
			return false;
		}
		$port = isset($p['port']) ? $p['port'] : 443;
		return self::known($p['host'], $port) ? array($p['host'], $port) : false;
	}
	public static function ca_file_path()
	{
		$env = getenv('BENCHY_SERVER_CAFILE');
		if($env !== false && $env !== '')
		{
			return $env;
		}
		return is_file(PTS_USER_PATH . 'server-ca.pem') ? PTS_USER_PATH . 'server-ca.pem' : null;
	}
	public static function ca_file_error($file)
	{
		// null if the file parses as one or more PEM certificates, else why not (an error at use, never a silent skip)
		if(!is_file($file) || !is_readable($file))
		{
			return 'The CA file ' . $file . ' cannot be read.';
		}
		$certs = phoromatic_tls::split_pem_certs(file_get_contents($file));
		if(empty($certs))
		{
			return 'The CA file ' . $file . ' holds no PEM certificate.';
		}
		foreach($certs as $c)
		{
			if(!@openssl_x509_read($c))
			{
				return 'The CA file ' . $file . ' holds a certificate that cannot be parsed.';
			}
		}
		return null;
	}
	public static function attempts($url)
	{
		// array of attempts (each 'cafile' or 'system') in order, or false for a URL that is not a Benchy server
		if(($hp = self::benchy_url($url)) === false)
		{
			return false;
		}
		$k = self::key($hp[0], $hp[1]);
		$have_file = self::ca_file_path() !== null;
		if(isset(self::$worked[$k]) && (self::$worked[$k] != 'cafile' || $have_file))
		{
			return array(self::$worked[$k]);
		}
		return $have_file ? array('cafile', 'system') : array('system');
	}
	public static function remember($url, $attempt)
	{
		self::$last_failure = null;
		if(($hp = self::benchy_url($url)) !== false)
		{
			self::$worked[self::key($hp[0], $hp[1])] = $attempt;
		}
	}
	public static function forget($url)
	{
		if(($hp = self::benchy_url($url)) !== false)
		{
			unset(self::$worked[self::key($hp[0], $hp[1])]);
		}
	}
	public static function apply_stream(&$parameters, $url, $attempt)
	{
		// $parameters: the stream_context_create() options array
		$hp = parse_url($url);
		$parameters['ssl']['verify_peer'] = true;
		$parameters['ssl']['verify_peer_name'] = true;
		$parameters['ssl']['allow_self_signed'] = false;
		$parameters['ssl']['peer_name'] = $hp['host'];
		$parameters['ssl']['SNI_enabled'] = true;
		if($attempt == 'cafile')
		{
			$parameters['ssl']['cafile'] = self::ca_file_path();
		}
	}
	public static function apply_curl($ch, $url, $attempt)
	{
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
		if($attempt == 'cafile')
		{
			curl_setopt($ch, CURLOPT_CAINFO, self::ca_file_path());
		}
		else
		{
			// The system store: on Windows the native one, elsewhere curl's default paths (the caller does not set the bundled directory)
			if(defined('CURLOPT_SSL_OPTIONS') && defined('CURLSSLOPT_NATIVE_CA') && phodevi::is_windows())
			{
				curl_setopt($ch, CURLOPT_SSL_OPTIONS, CURLSSLOPT_NATIVE_CA);
			}
		}
	}
	public static function verify_failure($message)
	{
		// Did the last error say the certificate (not the connection) was the problem?
		return is_string($message) && (stripos($message, 'certificate verify failed') !== false || stripos($message, 'SSL operation failed') !== false
			|| stripos($message, 'did not match expected') !== false || stripos($message, 'unable to get local issuer') !== false || stripos($message, 'self-signed') !== false);
	}
	public static function report_failure($url, $reason)
	{
		// The request fails, with the host, OpenSSL's reason and the fix. Logged at most once an hour per host.
		if(($hp = self::benchy_url($url)) === false)
		{
			return;
		}
		$reason = preg_match('/(certificate verify failed|did not match expected [^ ]+|unable to get local issuer certificate|self[- ]signed certificate[a-z ]*|certificate has expired)/i', (string)$reason, $m) ? $m[1] : substr(preg_replace('/\s+/', ' ', trim((string)$reason)), 0, 120);
		$message = 'Could not verify the certificate of the Benchy server ' . $hp[0] . ':' . $hp[1] . ($reason !== '' ? ' (' . $reason . ')' : '') .
			' (trusted: ' . self::trust_text() . '). Nothing was sent. Run: benchy server-trust https://' . $hp[0] . ':' . $hp[1] . '/ACCOUNT --fingerprint SHA256 (the fingerprint is shown by server-https-status on the server).';
		self::$last_failure = $message;
		$k = self::key($hp[0], $hp[1]);
		static $last_log = array();
		if(!isset($last_log[$k]) || time() - $last_log[$k] >= 3600)
		{
			$last_log[$k] = time();
			pts_client::$pts_logger && pts_client::$pts_logger->log($message);
			if(class_exists('pts_json_output', false) && pts_json_output::is_active())
			{
				pts_json_output::error('network', $message, false, array('tls' => 'verify_failed', 'host' => $hp[0], 'port' => (int)$hp[1]));
			}
			else
			{
				fwrite(STDERR, PHP_EOL . $message . PHP_EOL);
			}
		}
	}
	public static function verification_failed()
	{
		// A certificate that does not verify will not verify 20 seconds later: callers with retry loops stop at once
		return self::$last_failure !== null;
	}
	public static function trust_text()
	{
		// What the client trusts, in words: Windows PHP streams do not consult the Windows certificate store (checked on Windows with
		// PHP 8.5.12), so there trust is the CA file only (server-trust), whatever else php.ini may add
		return phodevi::is_windows() ? 'the CA file saved by server-trust' : 'the CA file or the system store';
	}
	public static function last_failure()
	{
		return self::$last_failure;
	}
}

?>
