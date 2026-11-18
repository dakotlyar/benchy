<?php

/*
	Benchy server discovery (D1, 0.10.0). This program is free software; you can redistribute it and/or modify it under the terms of the
	GNU General Public License as published by the Free Software Foundation; either version 3 of the License, or (at your option) any later version.
*/

class server_announce implements pts_option_interface
{
	const doc_section = 'Phoromatic';
	const doc_description = 'Run on the Phoromatic Server host (Linux), as the user that owns the server\'s database. Controls whether the server announces itself on the local network (as _benchy._tcp, with the built-in HTTPS port only) and under what network name; the same setting as the Server settings page. "status" (the default) shows whether it is announced, the name, and the DNS record that lets machines on other VLANs find the server. A running server follows a change within a few seconds. Changes are recorded in the audit log. Usage: server-announce [status|on|off|name TEXT|name default] (the service itself also calls server-announce refresh after a live change of the HTTPS port)';

	public static function run($r)
	{
		if(isset($r[0]) && $r[0] == 'refresh')
		{
			// What the running service calls after a live change of the HTTPS port: rebuild the file it follows from the settings. No database,
			// no output, and nothing audited (no setting changed).
			phoromatic_tls::honour_silence();
			return phoromatic_discovery::write_state();
		}
		if(($account_id = phoromatic_tls::cli_prelude('server-announce')) === false)
		{
			return false;
		}
		$what = isset($r[0]) ? strtolower($r[0]) : 'status';
		if($what == 'status' && count($r) <= 1)
		{
			self::status();
			return true;
		}
		$announce = null;
		$name = null;
		if(($what == 'on' || $what == 'off') && count($r) == 1)
		{
			$announce = $what;
		}
		else if($what == 'name' && count($r) >= 2)
		{
			$text = trim(implode(' ', array_slice($r, 1)));
			$name = $text == 'default' && count($r) == 2 ? '' : $text;
		}
		else
		{
			echo PHP_EOL . 'Usage: benchy server-announce [status|on|off|name TEXT|name default]' . PHP_EOL . PHP_EOL;
			return false;
		}
		if(($error = phoromatic_discovery::change($announce, $name, $account_id, 'cli', phoromatic_private_repo_auth::local_actor(), null, 'cli')) !== null)
		{
			echo PHP_EOL . 'ERROR: ' . $error . PHP_EOL . PHP_EOL;
			return false;
		}
		echo PHP_EOL . ($announce !== null ? 'Announcing is ' . $announce . '.' : 'The network name is ' . phoromatic_discovery::network_name() . '.') . ' ';
		echo (phoromatic_tls::service_running() ? 'The running server follows within a few seconds.' : 'The server is not running; this applies when it starts.') . '' . PHP_EOL . PHP_EOL;
		return true;
	}
	protected static function status()
	{
		$s = phoromatic_tls::settings();
		$st = phoromatic_discovery::state();
		echo PHP_EOL . 'Announce on the local network: ' . (phoromatic_discovery::enabled() ? 'on' : 'off') . (phoromatic_discovery::explicit() ? '' : ' (not chosen yet: following AdvertiseServiceZeroConf in the configuration file, on by default)') . PHP_EOL;
		echo 'Network name:                  ' . phoromatic_discovery::network_name() . (phoromatic_discovery::custom_name() === '' ? ' (the host name)' : '') . PHP_EOL;
		echo 'State:                         ' . $st[1] . PHP_EOL;
		if(($srv = phoromatic_discovery::srv_line($s)) !== null)
		{
			echo PHP_EOL . 'DNS record so that machines on other VLANs can find this server (Unbound custom option, or the same record in your DNS server):' . PHP_EOL . '  ' . $srv . PHP_EOL;
			if(phoromatic_discovery::srv_host($s) === null)
			{
				echo '  (None of this server\'s names is a dotted host name, so the two YOUR-SEARCH-DOMAIN parts are yours to fill in.)' . PHP_EOL;
			}
		}
		echo PHP_EOL;
	}
}

?>
