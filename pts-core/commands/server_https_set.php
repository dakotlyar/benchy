<?php

/*
	Benchy built-in HTTPS (0.9). This program is free software; you can redistribute it and/or modify it under the terms of the
	GNU General Public License as published by the Free Software Foundation; either version 3 of the License, or (at your option) any later version.
*/

class server_https_set implements pts_option_interface
{
	const doc_section = 'Phoromatic';
	const doc_description = 'Run on the Phoromatic Server host (Linux), as the user that owns the server\'s database. Configures the built-in HTTPS (a Benchy-managed Caddy in front of the Phoromatic server; install it first with server-caddy-install). "internal" uses Caddy\'s own CA (clients pin its fingerprint with server-trust); "file" uses a certificate you provide (PEM chain + key, or a PFX); "acme" gets one from an ACME CA (needs ports 443 and 80). --http-mode says what the plain HTTP port does: serve (the client endpoints only, the web interface redirects to HTTPS; the default while clients migrate), redirect, or off. Anything that does not turn HTTPS on or off applies to the running service without restarting PHP; otherwise restart the service. Usage: server-https-set internal --names A,B [--https-port N] [--http-mode M] | file --cert CHAIN.pem --key KEY.pem [--names A,B] | file --pfx FILE --pfx-password-file FILE [--names A,B] | acme --names A --email E [--directory URL] [--ca-roots PEM] | --http-mode M';

	public static function run($r)
	{
		$usage = 'server-https-set internal --names A,B [--https-port N] [--http-mode serve|redirect|off] | file --cert CHAIN.pem --key KEY.pem [--names A,B] | file --pfx FILE --pfx-password-file FILE | acme --names A --email E [--directory URL] [--ca-roots PEM] | --http-mode M';
		$opts = array();
		foreach(array('--names', '--https-port', '--http-mode', '--cert', '--key', '--pfx', '--pfx-password-file', '--email', '--directory', '--ca-roots') as $o)
		{
			$opts[$o] = in_array($o, array('--cert', '--key', '--pfx', '--pfx-password-file', '--ca-roots'), true) ? pts_server_address::take_path_option($r, $o) : pts_server_address::take_option($r, $o);
		}
		$mode = isset($r[0]) ? array_shift($r) : null;
		if(!empty($r) || ($mode !== null && !in_array($mode, array('internal', 'file', 'acme'), true)) || ($mode === null && $opts['--http-mode'] === null))
		{
			echo PHP_EOL . 'Usage: ' . $usage . PHP_EOL . PHP_EOL;
			return false;
		}
		if(($account_id = phoromatic_tls::cli_prelude('server-https-set')) === false)
		{
			return false;
		}
		$before = phoromatic_tls::settings();
		$after = $before;
		$cert_pem = $key_pem = null;
		$audits = array();
		$warnings = array();

		if($opts['--http-mode'] !== null)
		{
			if(!in_array($opts['--http-mode'], phoromatic_tls::HTTP_MODES, true))
			{
				echo PHP_EOL . 'ERROR: --http-mode must be serve, redirect or off.' . PHP_EOL . PHP_EOL;
				return false;
			}
			$after['http_mode'] = $opts['--http-mode'];
		}
		if($opts['--https-port'] !== null)
		{
			if(!ctype_digit($opts['--https-port']) || $opts['--https-port'] < 2 || $opts['--https-port'] > 65535)
			{
				echo PHP_EOL . 'ERROR: --https-port must be a port number.' . PHP_EOL . PHP_EOL;
				return false;
			}
			$after['https_port'] = (int)$opts['--https-port'];
		}
		if($opts['--names'] !== null)
		{
			$names = phoromatic_tls::parse_names($opts['--names']);
			if(!is_array($names))
			{
				echo PHP_EOL . 'ERROR: ' . $names . PHP_EOL . PHP_EOL;
				return false;
			}
			$after['names'] = $names;
		}

		if($mode == 'internal')
		{
			if(empty($after['names']))
			{
				echo PHP_EOL . 'ERROR: --names is required (the host names and IP addresses clients use to reach this server).' . PHP_EOL . PHP_EOL;
				return false;
			}
			$after['mode'] = 'internal';
		}
		else if($mode == 'file')
		{
			// Names default to the certificate's own, not to what an earlier mode had
			$after['names'] = $opts['--names'] !== null ? $after['names'] : array();
			if($opts['--pfx'] !== null)
			{
				$pfx = is_file($opts['--pfx']) ? file_get_contents($opts['--pfx']) : false;
				$pw = $opts['--pfx-password-file'] !== null && is_file($opts['--pfx-password-file']) ? rtrim(file_get_contents($opts['--pfx-password-file']), "\r\n") : false;
				if($pfx === false || $pw === false)
				{
					echo PHP_EOL . 'ERROR: --pfx and --pfx-password-file must both be readable files.' . PHP_EOL . PHP_EOL;
					return false;
				}
				$result = phoromatic_tls::import_pfx($pfx, $pw, $after['names'] ? $after['names'] : null);
				$source_note = 'pfx';
			}
			else
			{
				$cert = $opts['--cert'] !== null && is_file($opts['--cert']) ? file_get_contents($opts['--cert']) : false;
				$key = $opts['--key'] !== null && is_file($opts['--key']) ? file_get_contents($opts['--key']) : false;
				if($cert === false || $key === false)
				{
					echo PHP_EOL . 'ERROR: --cert and --key (or --pfx and --pfx-password-file) must be readable files.' . PHP_EOL . PHP_EOL;
					return false;
				}
				$result = phoromatic_tls::import_pem($cert, $key, $after['names'] ? $after['names'] : null);
				$source_note = 'pem';
			}
			list($error, $warnings, $info, $cert_pem, $key_pem) = $result;
			if($error !== null)
			{
				echo PHP_EOL . 'ERROR: ' . $error . ' Nothing was changed.' . PHP_EOL . PHP_EOL;
				return false;
			}
			if(empty($after['names']))
			{
				$after['names'] = phoromatic_tls::names_from_cert($info);
				if(empty($after['names']))
				{
					echo PHP_EOL . 'ERROR: The certificate has no subject alternative names; give --names.' . PHP_EOL . PHP_EOL;
					return false;
				}
			}
			$tls = phoromatic_tls::tls_path();
			$after['mode'] = 'file';
			$after['file'] = array('cert' => $tls . 'cert.pem', 'key' => $tls . 'key.pem');
			$audits[] = array('https_cert_upload', phoromatic_tls::audit_details_for_cert($info, 'cli', $warnings));
		}
		else if($mode == 'acme')
		{
			if(empty($after['names']) || $opts['--email'] === null || !filter_var($opts['--email'], FILTER_VALIDATE_EMAIL))
			{
				echo PHP_EOL . 'ERROR: acme needs --names and a valid --email.' . PHP_EOL . PHP_EOL;
				return false;
			}
			foreach($after['names'] as $n)
			{
				if(filter_var($n, FILTER_VALIDATE_IP))
				{
					echo PHP_EOL . 'ERROR: An ACME certificate is for host names, not the IP address ' . $n . '.' . PHP_EOL . PHP_EOL;
					return false;
				}
			}
			if($opts['--ca-roots'] !== null && !is_file($opts['--ca-roots']))
			{
				echo PHP_EOL . 'ERROR: --ca-roots ' . $opts['--ca-roots'] . ' is not a file.' . PHP_EOL . PHP_EOL;
				return false;
			}
			if($opts['--directory'] !== null && !preg_match('#^https://[^\s]+$#', $opts['--directory']))
			{
				echo PHP_EOL . 'ERROR: --directory must be an https:// URL.' . PHP_EOL . PHP_EOL;
				return false;
			}
			if($after['https_port'] != 443)
			{
				echo PHP_EOL . 'ERROR: ACME validates on the standard ports: this needs --https-port 443 and the HTTP port 80 (PHOROMATIC_HTTP_PORT=80), which also needs CAP_NET_BIND_SERVICE for the service.' . PHP_EOL . PHP_EOL;
				return false;
			}
			$after['mode'] = 'acme';
			$after['acme'] = array('directory' => $opts['--directory'] !== null ? $opts['--directory'] : 'https://acme-v02.api.letsencrypt.org/directory', 'email' => $opts['--email'],
				'ca_roots' => $opts['--ca-roots'] !== null ? realpath($opts['--ca-roots']) : null);
		}
		else if($before['mode'] == 'acme' && $after['https_port'] != 443)
		{
			echo PHP_EOL . 'ERROR: ACME needs --https-port 443.' . PHP_EOL . PHP_EOL;
			return false;
		}

		if($after['mode'] != 'off' && ($after['caddy']['path'] === null) && phoromatic_tls::caddy_check($after) !== null)
		{
			$warnings[] = 'No Caddy is installed yet; run benchy server-caddy-install before restarting the service.';
		}
		if(phoromatic_tls::describe($before) != phoromatic_tls::describe($after) || $before['acme'] != $after['acme'])
		{
			$audits[] = array('https_mode_change', array('source' => 'cli', 'from' => $before['mode'], 'to' => $after['mode'], 'names' => $after['names'], 'https_port' => $after['https_port'], 'http_mode' => $after['http_mode']));
		}
		if(empty($audits))
		{
			echo PHP_EOL . 'Nothing to change.' . PHP_EOL . PHP_EOL;
			return true;
		}
		$error = phoromatic_tls::commit_change($account_id, 'cli', phoromatic_private_repo_auth::local_actor(), null, $after, $audits, $cert_pem, $key_pem);
		if($error !== null)
		{
			echo PHP_EOL . 'ERROR: ' . $error . PHP_EOL . PHP_EOL;
			return false;
		}
		echo PHP_EOL . 'Saved: HTTPS mode ' . $after['mode'] . ', names ' . implode(', ', $after['names']) . ', HTTPS port ' . $after['https_port'] . ', plain HTTP: ' . $after['http_mode'] . '.' . PHP_EOL;
		foreach($warnings as $w)
		{
			echo 'WARNING: ' . $w . PHP_EOL;
		}
		echo phoromatic_tls::report_apply($before, $after) . PHP_EOL . PHP_EOL;
		return true;
	}
}

?>
