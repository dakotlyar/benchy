<?php

/*
	Benchy built-in HTTPS (0.9). This program is free software; you can redistribute it and/or modify it under the terms of the
	GNU General Public License as published by the Free Software Foundation; either version 3 of the License, or (at your option) any later version.
*/

class server_https_status implements pts_option_interface
{
	const doc_section = 'Phoromatic';
	const doc_description = 'Run on the Phoromatic Server host (Linux), as the user that owns the server\'s database. Shows the built-in HTTPS settings (mode, names, ports, plain-HTTP mode, PHP workers, Caddy version), the certificate the running server really presents (subject, names, issuer, expiry) and, for the internal CA, the root certificate SHA-256 fingerprint that clients give to server-trust --fingerprint. Usage: server-https-status';

	public static function run($r)
	{
		if(phoromatic_tls::cli_prelude('server-https-status') === false)
		{
			return false;
		}
		$st = phoromatic_tls::status();
		$s = $st['settings'];
		echo PHP_EOL . 'Built-in HTTPS: ' . $s['mode'] . ($s['mode'] == 'off' ? ' (plain "php -S", as before)' : '') . PHP_EOL;
		if($s['mode'] != 'off')
		{
			echo 'Names:           ' . implode(', ', $s['names']) . PHP_EOL;
			echo 'HTTPS port:      ' . $s['https_port'] . PHP_EOL;
			echo 'Plain HTTP:      ' . $s['http_mode'] . PHP_EOL;
		}
		echo 'PHP workers:     ' . phoromatic_tls::php_workers($s) . PHP_EOL;
		echo '                 ' . phoromatic_tls::workers_rule() . PHP_EOL;
		echo 'Caddy:           ' . ($s['caddy']['path'] ? $s['caddy']['version'] . ' at ' . $s['caddy']['path'] . ($s['caddy']['source'] == 'system' ? ' (system Caddy)' : '') : 'not installed (' . phoromatic_tls::as_service_user('benchy server-caddy-install') . ')') . PHP_EOL;
		echo 'Service running: ' . ($st['running'] ? 'yes' : 'no') . PHP_EOL;
		if($st['apply_failed'])
		{
			echo 'WARNING: The last certificate change was not applied; the previous configuration was restored. See ' . phoromatic_tls::tls_path(false) . 'caddy.log' . PHP_EOL;
		}
		if($s['mode'] != 'off')
		{
			if($st['live'])
			{
				$c = $st['live'];
				echo PHP_EOL . 'Certificate presented on port ' . $s['https_port'] . ':' . PHP_EOL;
				echo '  Subject:   ' . $c['subject'] . PHP_EOL;
				echo '  Names:     ' . implode(', ', $c['sans']) . PHP_EOL;
				echo '  Issuer:    ' . $c['issuer'] . PHP_EOL;
				echo '  Expires:   ' . gmdate('Y-m-d H:i', $c['not_after']) . ' UTC' . PHP_EOL;
				echo '  SHA-256:   ' . phoromatic_tls::fingerprint_display($c['sha256']) . PHP_EOL;
			}
			else
			{
				echo PHP_EOL . 'No certificate could be read from port ' . $s['https_port'] . ' (the service is not running, or has not applied this configuration yet).' . PHP_EOL;
			}
		}
		if($s['mode'] == 'internal')
		{
			$fp = $st['ca_fingerprint'];
			echo PHP_EOL . 'Built-in CA root certificate SHA-256 fingerprint (give it to clients):' . PHP_EOL . '  ' . ($fp ? phoromatic_tls::fingerprint_display($fp) : '(the CA is created when the service first starts in this mode)') . PHP_EOL;
			if($fp)
			{
				echo 'On each client: benchy server-trust https://' . phoromatic_tls::sni_name($s) . ':' . $s['https_port'] . '/ACCOUNT --fingerprint ' . phoromatic_tls::fingerprint_display($fp) . PHP_EOL;
			}
		}
		echo PHP_EOL;
		return true;
	}
}

?>
