<?php

// Unit-level checks of phoromatic_discovery (server discovery D1): the network name rules (E20), the announce setting and its audit, the DNS record
// line, the who-are-you reply body and its rate limit, the state file the launcher follows. Run by d1.sh in a throw-away container of its own
// (php /h/d1_unit.php; tree at /bench, HOME=/root, a fresh data folder), so nothing here touches a running server.
// Prints "  PASS  ..." / "  FAIL  ..." lines.

define('PTS_MODE', 'CLIENT');
define('PTS_AUTO_LOAD_OBJECTS', true);
define('PHOROMATIC_SERVER', true);
$_SERVER['argv'] = array('x');
include('/bench/pts-core/pts-core.php');
pts_core::init();
phoromatic_tls::honour_silence();
phoromatic_server::prepare_database();

$pass = 0;
$fail = 0;
function check($desc, $ok, $detail = '')
{
	global $pass, $fail;
	if($ok)
	{
		$pass++;
		echo '  PASS  ' . $desc . PHP_EOL;
	}
	else
	{
		$fail++;
		echo '  FAIL  ' . $desc . PHP_EOL;
		if($detail !== '')
		{
			echo '        | ' . substr(is_string($detail) ? $detail : json_encode($detail), 0, 500) . PHP_EOL;
		}
	}
}
function audit_rows()
{
	$d = new SQLite3(phoromatic_server::phoromatic_path(false) . 'phoromatic.db', SQLITE3_OPEN_READONLY);
	$d->busyTimeout(5000);
	$rows = array();
	$r = $d->query("SELECT ActorType, Detail FROM phoromatic_admin_audit WHERE Action = 'discovery_announce_change' ORDER BY AuditID");
	while(($row = $r->fetchArray(SQLITE3_ASSOC)))
	{
		$rows[] = $row;
	}
	$server = (int)$d->querySingle("SELECT COUNT(*) FROM phoromatic_server_audit WHERE Action = 'server_setting_changed' AND Detail LIKE '%discovery_announce_change%'");
	return array($rows, $server);
}
function keys_deep($v, $prefix = '')
{
	$out = array();
	if(is_array($v) && array_keys($v) !== range(0, count($v) - 1))
	{
		foreach($v as $k => $x)
		{
			$out[] = $prefix . $k;
			$out = array_merge($out, keys_deep($x, $prefix . $k . '.'));
		}
	}
	return $out;
}

// ---- the network name (E20)
check('name: an ordinary name is fine', phoromatic_discovery::validate_name('seinar') === null);
check('name: spaces, dots and a letter with an accent are fine', phoromatic_discovery::validate_name('Lab server 2.b') === null && phoromatic_discovery::validate_name("B\xc3\xbcro") === null);
check('name: 63 bytes is fine, 64 is not', phoromatic_discovery::validate_name(str_repeat('a', 63)) === null && is_string(phoromatic_discovery::validate_name(str_repeat('a', 64))));
check('name: the limit counts bytes, not characters (32 two-byte letters are 64 bytes)', is_string(phoromatic_discovery::validate_name(str_repeat("\xc3\xbc", 32))) && phoromatic_discovery::validate_name(str_repeat("\xc3\xbc", 31)) === null);
check('name: empty and blank are refused', is_string(phoromatic_discovery::validate_name('')) && is_string(phoromatic_discovery::validate_name('   ')));
check('name: a control character, a line break, a NUL and a Unicode line separator are refused',
	is_string(phoromatic_discovery::validate_name("a\x07b")) && is_string(phoromatic_discovery::validate_name("a\nb")) && is_string(phoromatic_discovery::validate_name("a\0b")) && is_string(phoromatic_discovery::validate_name("a\xe2\x80\xa8b")) && is_string(phoromatic_discovery::validate_name("a\x7fb")));
check('name: bytes that are not UTF-8 are refused', is_string(phoromatic_discovery::validate_name("a\xffb")));
check('name: markup is allowed as text (a client shows it as plain text)', phoromatic_discovery::validate_name('<b>x</b>') === null);
$dn = phoromatic_discovery::default_name();
check('name: the default is the short host name (never empty, no dot)', $dn !== '' && strpos($dn, '.') === false && phoromatic_discovery::validate_name($dn) === null && phoromatic_discovery::network_name() === $dn, $dn);

// ---- the setting, and the old key it replaces
// d1.sh has set AdvertiseServiceZeroConf=FALSE in this container's configuration before this file runs (the default, on, is checked against the
// real server); the configuration is read once per process
check('setting: an admin who set AdvertiseServiceZeroConf to FALSE stays off until they choose', phoromatic_discovery::enabled() === false && phoromatic_discovery::explicit() === false);
check('state: off says so', phoromatic_discovery::state()[0] == 'off');
check('change: a bad value is refused', is_string(phoromatic_discovery::change('maybe', null, '', 'cli', 'qc', null, 'cli')));
check('change: a bad name is refused with nothing written and no audit row', is_string(phoromatic_discovery::change(null, str_repeat('x', 64), '', 'cli', 'qc', null, 'cli')) && count(audit_rows()[0]) == 0 && phoromatic_discovery::custom_name() === '');
check('change: a name with a line break is refused', is_string(phoromatic_discovery::change(null, "a\nb", '', 'cli', 'qc', null, 'cli')) && count(audit_rows()[0]) == 0);
check('change: on succeeds, replaces the old key, and is audited once (here and in the server audit)', phoromatic_discovery::change('on', null, '', 'cli', 'qc', 'detail', 'cli') === null && phoromatic_discovery::enabled() === true && phoromatic_discovery::explicit() === true && count(audit_rows()[0]) == 1 && audit_rows()[1] == 1);
list($rows) = audit_rows();
$detail = json_decode($rows[0]['Detail'], true);
check('audit: the row says what it was and what it is, and where the change came from', $detail['source'] == 'cli' && $detail['before']['announce'] == 'off' && $detail['after']['announce'] == 'on' && $detail['after']['name'] == $dn, $detail);
check('change: the same value again writes nothing and no audit row', phoromatic_discovery::change('on', null, '', 'cli', 'qc', null, 'cli') === null && count(audit_rows()[0]) == 1);
check('change: off, then a name', phoromatic_discovery::change('off', 'Rack 4 \'lab\'', '', 'web', 'qc', null, 'web') === null && phoromatic_discovery::enabled() === false && phoromatic_discovery::network_name() === 'Rack 4 \'lab\'' && count(audit_rows()[0]) == 2);
check('change: an empty name goes back to the host name', phoromatic_discovery::change(null, '', '', 'cli', 'qc', null, 'cli') === null && phoromatic_discovery::network_name() === $dn && phoromatic_discovery::custom_name() === '' && count(audit_rows()[0]) == 3);
phoromatic_discovery::change('on', null, '', 'cli', 'qc', null, 'cli');

// ---- the DNS record
$s = phoromatic_tls::defaults();
check('srv: with the built-in HTTPS off there is no record', phoromatic_discovery::srv_line($s) === null);
$s['mode'] = 'internal';
$s['names'] = array('seinar.asmartass.home', '192.168.100.50');
check('srv: the record is filled in from the first dotted host name and the HTTPS port', phoromatic_discovery::srv_line($s) === 'local-data: "_benchy._tcp.asmartass.home. 3600 IN SRV 0 0 39443 seinar.asmartass.home."', phoromatic_discovery::srv_line($s));
$s['names'] = array('192.168.100.50', 'seinar.asmartass.home');
check('srv: an address ahead of the name is skipped', phoromatic_discovery::srv_host($s) === 'seinar.asmartass.home');
$s['names'] = array('*.asmartass.home', 'seinar.asmartass.home');
check('srv: a wildcard is skipped', phoromatic_discovery::srv_host($s) === 'seinar.asmartass.home');
$s['names'] = array('seinar', '10.0.0.5');
$s['https_port'] = 4443;
check('srv: with no dotted name the search domain is left for the admin, and the port is the HTTPS port', strpos(phoromatic_discovery::srv_line($s), 'YOUR-SEARCH-DOMAIN. 3600 IN SRV 0 0 4443 SERVER-NAME.YOUR-SEARCH-DOMAIN.') !== false, phoromatic_discovery::srv_line($s));
$lines = phoromatic_discovery::status_lines($s);
check('status lines: the announcing line, the record and the note for the placeholders', count($lines) >= 4 && strpos(implode("\n", $lines), 'Announcing:') !== false && strpos(implode("\n", $lines), 'YOUR-SEARCH-DOMAIN') !== false);

// ---- the state file the launcher follows
$s = phoromatic_tls::defaults();
$s['mode'] = 'internal';
$s['names'] = array('seinar.asmartass.home');
phoromatic_tls::save_settings($s);
check('state file: on, the network name, the HTTPS port and the older announcement, one per line', phoromatic_discovery::write_state() && file_get_contents(phoromatic_discovery::state_file()) === "on\n" . $dn . "\n39443\non\n", file_get_contents(phoromatic_discovery::state_file()));
phoromatic_discovery::change('off', null, '', 'cli', 'qc', null, 'cli');
check('state file: off once announcing is off', strpos(file_get_contents(phoromatic_discovery::state_file()), "off\n") === 0);
phoromatic_discovery::change('on', 'Z', '', 'cli', 'qc', null, 'cli');
$s['mode'] = 'off';
phoromatic_tls::save_settings($s);
phoromatic_discovery::write_state();
check('state file: off while the built-in HTTPS is off, whatever the setting', strpos(file_get_contents(phoromatic_discovery::state_file()), "off\n") === 0 && phoromatic_discovery::state()[0] == 'https_off');
$s['mode'] = 'internal';
phoromatic_tls::save_settings($s);
check('state: HTTPS on and announcing on, but no service running here', phoromatic_discovery::state()[0] == 'not_running');

// ---- the reply
$info = phoromatic_discovery::info();
check('info: the exact fields, in HTTPS-internal mode without a CA yet', array_keys($info) == array('product', 'name', 'version', 'protocol', 'tls', 'join_codes') && array_keys($info['tls']) == array('mode', 'names'), array_keys($info));
check('info: product, name, version, protocol and the names the admin set', $info['product'] === 'benchy' && $info['name'] === 'Z' && $info['version'] === PTS_VERSION && $info['protocol'] === array(2) && $info['tls']['names'] === array('seinar.asmartass.home') && $info['tls']['mode'] === 'internal');
check('info: join codes are offered while the built-in HTTPS is on (D2)', $info['join_codes'] === true);
$ca = phoromatic_tls::root_ca_path();
@mkdir(dirname($ca), 0700, true);
exec('openssl req -x509 -newkey rsa:2048 -nodes -subj /CN=qc-ca -days 2 -keyout /tmp/qc-ca.key -out ' . escapeshellarg($ca) . ' 2>/dev/null');
$want = strtolower(str_replace(':', '', trim(preg_replace('/^.*=/', '', (string)shell_exec('openssl x509 -in ' . escapeshellarg($ca) . ' -noout -fingerprint -sha256')))));
$info = phoromatic_discovery::info();
check('info: in internal mode the root CA fingerprint is there, lower-case hex', isset($info['tls']['ca_sha256']) && $info['tls']['ca_sha256'] === $want && preg_match('/^[0-9a-f]{64}$/', $want) === 1, $info);
$s['mode'] = 'file';
phoromatic_tls::save_settings($s);
check('info: in file mode there is no CA fingerprint', !isset(phoromatic_discovery::info()['tls']['ca_sha256']) && phoromatic_discovery::info()['tls']['mode'] === 'file');
$s['mode'] = 'internal';
phoromatic_tls::save_settings($s);
// nothing about accounts or counts, however many there are
$text = json_encode(phoromatic_discovery::info());
check('info: the reply carries none of the words an account, user, system or count would bring', !preg_match('/account|user|system|count|email|ws_port|websocket|php|SERVER_SOFTWARE/i', $text), $text);
check('info: every key is one the design allows (3.2, 3.3)', array_diff(keys_deep(phoromatic_discovery::info()), array('product', 'name', 'version', 'protocol', 'tls', 'join_codes', 'tls.mode', 'tls.names', 'tls.ca_sha256')) == array());

// ---- the rate limit
$limited = false;
for($i = 1; $i <= 60; $i++)
{
	$limited = $limited || phoromatic_discovery::rate_limited('192.0.2.7');
}
check('rate limit: 60 requests in a minute from one address are answered', $limited === false);
check('rate limit: the 61st is not', phoromatic_discovery::rate_limited('192.0.2.7') === true);
check('rate limit: another address is not affected', phoromatic_discovery::rate_limited('192.0.2.8') === false);
$f = phoromatic_tls::tls_path() . 'info-rate/' . substr(hash('sha256', '192.0.2.7'), 0, 32);
file_put_contents($f, (time() - 61) . ' 500');
check('rate limit: a minute later the count starts again', phoromatic_discovery::rate_limited('192.0.2.7') === false);
check('rate limit: the files are only counters (an address hash, a time and a number)', preg_match('/^\d+ \d+$/', file_get_contents($f)) === 1);

echo 'RESULT d1_unit pass=' . $pass . ' fail=' . $fail . PHP_EOL;
exit($fail ? 1 : 0);

?>
