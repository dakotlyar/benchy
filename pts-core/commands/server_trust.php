<?php

/*
	Benchy 0.9: take a Benchy server's CA and switch this client to its https:// address. This program is free software; you can
	redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation;
	either version 3 of the License, or (at your option) any later version.
*/

class server_trust implements pts_option_interface
{
	const doc_section = 'Phoromatic';
	const doc_description = 'Run on a client (Linux or Windows). Makes this client trust the HTTPS certificate of a Benchy (Phoromatic) server and switches its stored server addresses (the Phoromatic client\'s and the private repository\'s) to https://. With --fingerprint SHA256 (read it off server-https-status on the server) the server\'s built-in CA is fetched and kept only if its fingerprint is exactly that. With --ca-file PEM a CA certificate you were given is used (with --fingerprint too, it must match). With neither, the certificate must already verify with what the client trusts (the system store on Linux; on Windows only the CA file, so there use --fingerprint or --ca-file), and nothing is saved. The CA is stored in the user path as server-ca.pem (BENCHY_SERVER_CAFILE overrides it). A certificate error always stops the client; it never falls back to HTTP. Usage: server-trust https://HOST:PORT/ACCOUNT [--fingerprint SHA256] [--ca-file PEM] [--replace]';

	public static function run($r)
	{
		$usage = 'server-trust https://HOST:PORT/ACCOUNT [--fingerprint SHA256] [--ca-file PEM] [--replace]';
		phoromatic_tls::honour_silence();
		$fingerprint_arg = pts_server_address::take_option($r, '--fingerprint');
		$ca_file_arg = pts_server_address::take_option($r, '--ca-file');
		$replace = false;
		if(($at = array_search('--replace', $r, true)) !== false)
		{
			$replace = true;
			array_splice($r, $at, 1);
		}
		$server = isset($r[0]) && count($r) == 1 ? pts_server_address::parse($r[0]) : false;
		if($server === false || $server['scheme'] != 'https' || $fingerprint_arg === '' || $ca_file_arg === '')
		{
			echo PHP_EOL . 'Usage: ' . $usage . PHP_EOL . '       (the address must start with https://)' . PHP_EOL . PHP_EOL;
			return false;
		}
		$expected = null;
		if($fingerprint_arg !== null && ($expected = phoromatic_tls::fingerprint_normalise($fingerprint_arg)) === false)
		{
			echo PHP_EOL . 'ERROR: --fingerprint must be a SHA-256 fingerprint (64 hex digits, colons allowed).' . PHP_EOL . PHP_EOL;
			return false;
		}
		pts_server_tls::register($server['host'], $server['port']);
		$base = pts_server_address::base_url('https', $server['host'], $server['port']);
		$info_url = $base . '/server.php?phoromatic_info';
		$ca_pem = null;

		if($ca_file_arg !== null)
		{
			$ca_pem = is_file($ca_file_arg) && is_readable($ca_file_arg) ? file_get_contents($ca_file_arg) : false;
			$certs = $ca_pem === false ? array() : phoromatic_tls::split_pem_certs($ca_pem);
			if(empty($certs))
			{
				echo PHP_EOL . 'ERROR: ' . $ca_file_arg . ' holds no PEM certificate. Nothing was changed.' . PHP_EOL . PHP_EOL;
				return false;
			}
			$found = array();
			$has_ca = false;
			foreach($certs as $c)
			{
				$i = phoromatic_tls::cert_info($c);
				if($i === false)
				{
					echo PHP_EOL . 'ERROR: ' . $ca_file_arg . ' holds a certificate that cannot be read. Nothing was changed.' . PHP_EOL . PHP_EOL;
					return false;
				}
				$found[] = $i['sha256'];
				$has_ca = $has_ca || $i['is_ca'];
				echo PHP_EOL . 'CA file certificate: ' . $i['subject'] . PHP_EOL . '  SHA-256: ' . phoromatic_tls::fingerprint_display($i['sha256']);
			}
			echo PHP_EOL;
			if(!$has_ca)
			{
				echo PHP_EOL . 'ERROR: The file holds no CA certificate (basicConstraints CA:TRUE). Nothing was changed.' . PHP_EOL . PHP_EOL;
				return false;
			}
			if($expected !== null && !in_array($expected, $found, true))
			{
				echo PHP_EOL . 'ERROR: None of the certificates in the file has the SHA-256 fingerprint you gave. Nothing was changed.' . PHP_EOL . PHP_EOL;
				return false;
			}
		}
		else if($expected !== null)
		{
			// Trust on first use, pinned by the out-of-band fingerprint: verification is off for THIS ONE request only
			$ctx = stream_context_create(array('ssl' => array('verify_peer' => false, 'verify_peer_name' => false, 'peer_name' => $server['host']), 'http' => array('timeout' => 15, 'user_agent' => pts_network::user_agent())));
			$ca_pem = @file_get_contents($base . '/server.php?benchy_ca', false, $ctx);
			$certs = $ca_pem === false ? array() : phoromatic_tls::split_pem_certs($ca_pem);
			if(count($certs) != 1 || ($i = phoromatic_tls::cert_info($certs[0])) === false)
			{
				echo PHP_EOL . 'ERROR: ' . $server['host'] . ':' . $server['port'] . ' did not return a CA certificate (it must be a server with the built-in CA, "internal" mode; for another CA use --ca-file). Nothing was changed.' . PHP_EOL . PHP_EOL;
				return false;
			}
			echo PHP_EOL . 'CA offered by the server: ' . $i['subject'] . PHP_EOL . '  SHA-256: ' . phoromatic_tls::fingerprint_display($i['sha256']) . PHP_EOL;
			if(!hash_equals($expected, $i['sha256']))
			{
				echo PHP_EOL . 'ERROR: The fingerprint does not match the one you gave (' . phoromatic_tls::fingerprint_display($expected) . '). Someone may be in the way. Nothing was saved.' . PHP_EOL . PHP_EOL;
				return false;
			}
			$ca_pem = $certs[0] . PHP_EOL;
		}
		else
		{
			// No CA given: the server's certificate must already verify with what this client trusts
			$response = pts_network::http_get_contents($info_url, false, false, false, false, 10);
			if(self::is_phoromatic_info($response))
			{
				echo PHP_EOL . $server['host'] . ':' . $server['port'] . ' already verifies with what this client trusts (' . pts_server_tls::trust_text() . '); no new CA file is needed.' . PHP_EOL;
				return self::switch_addresses($server);
			}
			echo PHP_EOL . 'The certificate of ' . $server['host'] . ':' . $server['port'] . ' does not verify against ' . pts_server_tls::trust_text() . ' (or the server did not answer).' . PHP_EOL;
			echo 'Nothing was saved. Run: benchy server-trust ' . $r[0] . ' --fingerprint SHA256   (the fingerprint is shown by server-https-status on the server)' . PHP_EOL;
			echo 'or:  benchy server-trust ' . $r[0] . ' --ca-file ca.pem' . PHP_EOL . PHP_EOL;
			return false;
		}

		// With the CA in hand, a verified request has to work and be a Phoromatic answer, or nothing is saved
		$tmp = tempnam(sys_get_temp_dir(), 'benchyca');
		file_put_contents($tmp, $ca_pem);
		$ctx = stream_context_create(array('ssl' => array('verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false, 'cafile' => $tmp, 'peer_name' => $server['host']), 'http' => array('timeout' => 15, 'user_agent' => pts_network::user_agent())));
		$response = @file_get_contents($info_url, false, $ctx);
		@unlink($tmp);
		if(!self::is_phoromatic_info($response))
		{
			echo PHP_EOL . 'ERROR: With that CA the certificate of ' . $server['host'] . ':' . $server['port'] . ' does not verify (is ' . $server['host'] . ' one of the names the certificate covers?), or the server is not a Benchy server. Nothing was saved.' . PHP_EOL . PHP_EOL;
			return false;
		}
		echo 'The server\'s certificate verifies with this CA.' . PHP_EOL;

		pts_file_io::mkdir(PTS_USER_PATH);
		$target = PTS_USER_PATH . 'server-ca.pem';
		if(is_file($target) && trim(file_get_contents($target)) !== trim($ca_pem))
		{
			$confirmed = $replace;
			if(!$confirmed && function_exists('stream_isatty') && @stream_isatty(STDIN))
			{
				$confirmed = pts_user_io::prompt_bool_input('A different CA is already trusted (' . $target . '). Replace it', false, 'SERVER_TRUST_REPLACE');
			}
			if(!$confirmed)
			{
				echo PHP_EOL . 'A different CA is already stored in ' . $target . '. Nothing was changed; run again with --replace to replace it.' . PHP_EOL . PHP_EOL;
				return false;
			}
		}
		$old = umask(0077);
		$written = file_put_contents($target, $ca_pem);
		umask($old);
		if($written === false)
		{
			echo PHP_EOL . 'ERROR: Could not write ' . $target . '.' . PHP_EOL . PHP_EOL;
			return false;
		}
		@chmod($target, 0600);
		echo 'Saved the CA to ' . $target . PHP_EOL;
		if(getenv('BENCHY_SERVER_CAFILE'))
		{
			echo 'Note: BENCHY_SERVER_CAFILE is set (' . getenv('BENCHY_SERVER_CAFILE') . ') and wins over this file.' . PHP_EOL;
		}
		return self::switch_addresses($server);
	}
	protected static function is_phoromatic_info($response)
	{
		return is_string($response) && ($j = json_decode($response, true)) && is_array($j) && isset($j['pts']);
	}
	protected static function switch_addresses($server)
	{
		// The stored server strings that point at the same host (any port) and account become the https:// string
		$files = array(PTS_USER_PATH . 'modules-data/phoromatic/last-phoromatic-server' => 'the Phoromatic client', pts_private_repo_client::connection_file() => 'the private repository');
		$changed = 0;
		foreach($files as $file => $what)
		{
			if(!is_file($file) || ($a = pts_server_address::parse(file_get_contents($file))) === false || strcasecmp($a['host'], $server['host']) != 0 || $a['account'] != $server['account'])
			{
				continue;
			}
			$new = pts_server_address::to_string($server);
			if(pts_server_address::to_string($a) !== $new)
			{
				file_put_contents($file, $new);
				echo 'Switched ' . $what . ' server: ' . pts_server_address::to_string($a) . ' -> ' . $new . PHP_EOL;
				$changed++;
			}
		}
		if($changed == 0)
		{
			echo 'No stored server address needed changing. Connect with: benchy phoromatic.connect ' . pts_server_address::to_string($server) . PHP_EOL;
		}
		echo PHP_EOL;
		return true;
	}
}

?>
