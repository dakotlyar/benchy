<?php

// Unit-level checks of the HTTPS classes (phoromatic_tls, pts_server_address, pts_server_tls helpers) that need no running service.
// Run by tests/private-repo/https.sh inside the server container (php /h/https_unit.php), where the tree is at /bench, the pinned Caddy is
// installed and /share/fx holds the test certificates. Prints "  PASS  ..." / "  FAIL  ..." lines.

define('PTS_MODE', 'CLIENT');
define('PTS_AUTO_LOAD_OBJECTS', true);
define('PHOROMATIC_SERVER', true);
$_SERVER['argv'] = array('x');
include('/bench/pts-core/pts-core.php');
pts_core::init();
phoromatic_tls::honour_silence();

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

// server strings
$a = pts_server_address::parse('https://seinar:39443/ABC123');
check('server string: https://host:port/AID', $a == array('scheme' => 'https', 'host' => 'seinar', 'port' => 39443, 'account' => 'ABC123'), $a);
$a = pts_server_address::parse('10.0.0.5:8080/XYZ');
check('server string: the legacy ip:port/AID means http', $a['scheme'] == 'http' && $a['host'] == '10.0.0.5' && $a['port'] == 8080);
check('server string: http:// is accepted too', pts_server_address::parse('http://h:1234/A')['scheme'] == 'http');
check('server string: the canonical form of http is the legacy form, of https the URL form', pts_server_address::to_string(pts_server_address::parse('http://h:1234/A')) == 'h:1234/A' && pts_server_address::to_string(pts_server_address::parse('https://h:1234/A')) == 'https://h:1234/A');
foreach(array('', 'host', 'host:80', 'host:80/', 'host:99999/AID', 'ftp://h:80/A', 'https://h/A', 'h:80/A B', 'https://h:80/A/../B') as $bad)
{
	check('server string: "' . $bad . '" is refused', pts_server_address::parse($bad) === false);
}
$args = array('x', '--fingerprint', 'AB', '--replace', 'y');
check('take_option removes an option and its value', pts_server_address::take_option($args, '--fingerprint') === 'AB' && $args == array('x', '--replace', 'y'));
$args = array('--ca-file');
check('take_option: an option without a value is the empty string', pts_server_address::take_option($args, '--ca-file') === '' && $args == array());

// names
check('names: hostnames and IPs are accepted', phoromatic_tls::parse_names('seinar, seinar.lan,192.168.100.118,fe80::1') == array('seinar', 'seinar.lan', '192.168.100.118', 'fe80::1'));
check('names: a space or punctuation is refused', is_string(phoromatic_tls::parse_names('a b!')) && is_string(phoromatic_tls::parse_names('')));
check('names: a certificate SAN covers its name, a wildcard covers one label, an IP SAN covers the IP', phoromatic_tls::name_covered('seinar', array('DNS:seinar')) && phoromatic_tls::name_covered('a.lan', array('DNS:*.lan')) && !phoromatic_tls::name_covered('a.b.lan', array('DNS:*.lan')) && phoromatic_tls::name_covered('10.0.0.1', array('IP Address:10.0.0.1')) && !phoromatic_tls::name_covered('10.0.0.2', array('IP Address:10.0.0.1')));
check('fingerprint: colons, spaces and case are ignored; a short one is refused', phoromatic_tls::fingerprint_normalise('AA:BB ' . str_repeat('cc', 30)) === 'aabb' . str_repeat('cc', 30) && phoromatic_tls::fingerprint_normalise('AB:CD') === false);

// certificate validation
$leaf = file_get_contents('/share/fx/leaf.crt');
$chain = file_get_contents('/share/fx/leaf-chain.crt');
$key = file_get_contents('/share/fx/leaf.key');
list($err, $warn, $info) = phoromatic_tls::import_pem($leaf, $key, array('hsrv'));
check('import_pem: a good leaf is accepted; no intermediate is a warning', $err === null && count($warn) == 1 && strpos($warn[0], 'intermediate') !== false, array($err, $warn));
list($err, $warn, $info) = phoromatic_tls::import_pem($chain, $key, array('hsrv', 'not-covered.example'));
check('import_pem: the chain has no intermediate warning; an uncovered name is a warning', $err === null && count($warn) == 1 && strpos($warn[0], 'not-covered.example') !== false, array($err, $warn));
check('import_pem: the info has a 64-digit fingerprint, the SANs and a not_after in the future', strlen($info['sha256']) == 64 && count($info['sans']) == 3 && $info['not_after'] > time());
list($err) = phoromatic_tls::import_pem($leaf, file_get_contents('/share/fx/other.key'));
check('import_pem: a key from another pair is refused', $err !== null && strpos($err, 'does not belong') !== false, $err);
list($err) = phoromatic_tls::import_pem($leaf, 'not a key');
check('import_pem: garbage instead of a key is refused', $err !== null && strpos($err, 'private key could not be read') !== false, $err);
list($err) = phoromatic_tls::import_pem('nothing', $key);
check('import_pem: no certificate in the file is refused', $err !== null && strpos($err, 'No PEM certificate') !== false, $err);
list($err) = phoromatic_tls::import_pem(file_get_contents('/share/fx/expired.crt'), file_get_contents('/share/fx/expired.key'));
check('import_pem: an expired certificate is refused', $err !== null && strpos($err, 'expired') !== false, $err);
list($err) = phoromatic_tls::import_pem(file_get_contents('/share/fx/future.crt'), file_get_contents('/share/fx/future.key'));
check('import_pem: a not-yet-valid certificate is refused', $err !== null && strpos($err, 'not valid until') !== false, $err);
list($err, $warn, $info) = phoromatic_tls::import_pfx(file_get_contents('/share/fx/leaf2.pfx'), 'pfxpass');
check('import_pfx: the right password gives the certificate', $err === null && $info !== null && strlen($info['sha256']) == 64, $err);
list($err) = phoromatic_tls::import_pfx(file_get_contents('/share/fx/leaf2.pfx'), 'nope');
check('import_pfx: the wrong password is refused', $err !== null && strpos($err, 'PFX could not be read') !== false, $err);
$ca = phoromatic_tls::cert_info(file_get_contents('/share/fx/ca.crt'));
check('cert_info: the test CA is a CA, the leaf is not', $ca['is_ca'] === true && phoromatic_tls::cert_info($leaf)['is_ca'] === false);

// pts_server_tls
check('pts_server_tls::verify_failure recognises OpenSSL verification messages, not connection errors', pts_server_tls::verify_failure('fopen(): SSL operation failed with code 1. OpenSSL Error messages: error:0A000086:SSL routines::certificate verify failed') && !pts_server_tls::verify_failure('fopen(): Failed to open stream: Connection refused'));
$tmp = tempnam(sys_get_temp_dir(), 'ca');
file_put_contents($tmp, 'garbage');
check('a CA file that is not PEM is an error', pts_server_tls::ca_file_error($tmp) !== null && pts_server_tls::ca_file_error('/share/fx/ca.crt') === null && pts_server_tls::ca_file_error('/nonexistent') !== null);
unlink($tmp);
check('a URL that is not a known Benchy server gets no attempts (old behaviour)', pts_server_tls::attempts('https://openbenchmarking.org/x') === false && pts_server_tls::attempts('http://h:80/x') === false);
pts_server_tls::register('known.example', 39443);
check('a registered https Benchy server gets the system store when there is no CA file', pts_server_tls::attempts('https://known.example:39443/x') == array('system'));
putenv('BENCHY_SERVER_CAFILE=/share/fx/ca.crt');
check('with a CA file: the CA file first, then the system store', pts_server_tls::attempts('https://known.example:39443/x') == array('cafile', 'system'));
pts_server_tls::remember('https://known.example:39443/x', 'cafile');
check('the attempt that worked is remembered for that host:port', pts_server_tls::attempts('https://known.example:39443/y') == array('cafile'));
pts_server_tls::forget('https://known.example:39443/x');
putenv('BENCHY_SERVER_CAFILE');
$params = array();
pts_server_tls::apply_stream($params, 'https://known.example:39443/x', 'system');
check('apply_stream verifies the peer and the name, and refuses self-signed', $params['ssl']['verify_peer'] === true && $params['ssl']['verify_peer_name'] === true && $params['ssl']['allow_self_signed'] === false && $params['ssl']['peer_name'] == 'known.example' && !isset($params['ssl']['cafile']));

check('a verification failure is remembered so that retry loops stop', pts_server_tls::verification_failed() === false);
ob_start();
pts_server_tls::report_failure('https://known.example:39443/x', 'certificate verify failed');
$reported = ob_get_clean();
check('...report_failure marks it, and the message says what is trusted', pts_server_tls::verification_failed() === true && strpos((string)pts_server_tls::last_failure(), 'trusted: ') !== false, pts_server_tls::last_failure());
pts_server_tls::remember('https://known.example:39443/x', 'system');
check('...a verified request clears it again', pts_server_tls::verification_failed() === false);

// the generated Caddy configuration validates for every mode and plain-HTTP mode
$s = phoromatic_tls::settings();
$caddy = $s['caddy']['path'];
check('the recorded Caddy passes caddy_check', phoromatic_tls::caddy_check($s) === null, phoromatic_tls::caddy_check($s));
$tmpdir = sys_get_temp_dir() . '/https-unit-' . getmypid();
mkdir($tmpdir);
$valid = true;
$detail = array();
foreach(array('internal', 'file', 'acme') as $mode)
{
	foreach(array('serve', 'redirect', 'off') as $http_mode)
	{
		$t = phoromatic_tls::defaults();
		$t['mode'] = $mode;
		$t['names'] = array('hsrv', '127.0.0.1');
		$t['http_mode'] = $http_mode;
		$t['file'] = array('cert' => '/share/fx/leaf-chain.crt', 'key' => '/share/fx/leaf.key');
		$t['acme'] = array('directory' => 'https://ca.example/dir', 'email' => 'a@b.co', 'ca_roots' => null);
		$t['caddy'] = $s['caddy'];
		$file = $tmpdir . '/' . $mode . '-' . $http_mode . '.json';
		phoromatic_tls::write_config($t, 'secret', 39082, 39080, $file);
		// caddy validate provisions the modules, which creates the CA in the configured storage: keep that out of the server's own
		$c = json_decode(file_get_contents($file));   // objects, not arrays: an empty JSON object must stay one
		$c->storage->root = $tmpdir . '/storage';
		$c->logging->logs->default->writer->filename = $tmpdir . '/caddy.log';
		file_put_contents($file, json_encode($c));
		$problem = phoromatic_tls::validate_config($t, $file);
		if($problem !== null)
		{
			$valid = false;
			$detail[] = $mode . '/' . $http_mode . ': ' . substr($problem, -450);
		}
	}
}
check('caddy validate accepts the generated config for internal/file/acme x serve/redirect/off', $valid, $detail);
$config = json_decode(file_get_contents($tmpdir . '/internal-serve.json'), true);
check('the config disables the admin API, never trusts the host store and sets the proxy headers', $config['admin']['disabled'] === true && $config['apps']['pki']['certificate_authorities']['local']['install_trust'] === false
	&& $config['apps']['http']['servers']['benchy_https']['routes'][0]['handle'][1]['headers']['request']['set']['X-Benchy-Proxy'] == array('secret')
	&& $config['apps']['http']['servers']['benchy_https']['routes'][0]['handle'][1]['upstreams'][0]['dial'] == '127.0.0.1:39082');
check('the config deletes the underscore spellings of the proxy headers', in_array('X_Benchy_Client_Ip', $config['apps']['http']['servers']['benchy_https']['routes'][0]['handle'][1]['headers']['request']['delete'], true));
check('the config limits request bodies to post_max_size + 1 MiB', $config['apps']['http']['servers']['benchy_https']['routes'][0]['handle'][0]['max_size'] == phoromatic_tls::post_max_bytes() + 1048576);
check('the config file is 0600', (fileperms($tmpdir . '/internal-serve.json') & 0777) == 0600);
check('the plain HTTP server of serve mode proxies only the client endpoints, with X-Benchy-Proto http', $config['apps']['http']['servers']['benchy_http']['routes'][0]['match'][0]['path'] == phoromatic_tls::CLIENT_PATHS
	&& $config['apps']['http']['servers']['benchy_http']['routes'][0]['handle'][1]['headers']['request']['set']['X-Benchy-Proto'] == array('http'));
exec('rm -rf ' . escapeshellarg($tmpdir));

// the pairing host against the configured names
$hs = phoromatic_tls::defaults();
$hs['mode'] = 'internal';
$hs['names'] = array('192.168.1.5', 'box.lan', '*.lab.test');
check('pairing host: a configured IP, name and wildcard match are covered', phoromatic_tls::host_problem('192.168.1.5:39443', $hs) === null && phoromatic_tls::host_problem('BOX.lan', $hs) === null && phoromatic_tls::host_problem('a.lab.test:1', $hs) === null);
$p = phoromatic_tls::host_problem('seinar:39443', $hs);
check('pairing host: an uncovered name gets the command that adds it, keeping the old names', $p && $p['host'] == 'seinar' && $p['command'] == "benchy server-https-set internal --names '192.168.1.5,box.lan,*.lab.test,seinar'", $p);
check('pairing host: a bracketed IPv6 address is read without its brackets and port', phoromatic_tls::request_host('[fe80::1]:39443') === 'fe80::1' && phoromatic_tls::request_host('Seinar') === 'seinar');
check('pairing host: a made-up Host header is no host, and raises no warning', phoromatic_tls::request_host('a b<script>') === false && phoromatic_tls::host_problem('a b<script>', $hs) === null);
$hs['mode'] = 'file';
$p = phoromatic_tls::host_problem('seinar', $hs);
check('pairing host: in file mode there is no command (only a new certificate helps)', $p && $p['command'] === null, $p);
$hs['mode'] = 'acme'; $hs['acme']['email'] = 'a@b.co';
$p = phoromatic_tls::host_problem('seinar', $hs);
check('pairing host: in acme mode the command carries the email', $p && strpos($p['command'], 'benchy server-https-set acme --names ') === 0 && substr($p['command'], -strlen(" --email 'a@b.co'")) === " --email 'a@b.co'", $p);
$hs['acme']['ca_roots'] = '/etc/my roots.pem'; $hs['acme']['directory'] = 'https://ca.test/dir';
$p = phoromatic_tls::host_problem('seinar', $hs);
check('pairing host: the acme command keeps --ca-roots and the directory, every value quoted', $p && $p['command'] == "benchy server-https-set acme --names '192.168.1.5,box.lan,*.lab.test,seinar' --email 'a@b.co' --directory 'https://ca.test/dir' --ca-roots '/etc/my roots.pem'", $p);
// the command the pairing page shows runs as the server's user (a different user would change a different set of files)
$me = function_exists('posix_getpwuid') ? posix_getpwuid(posix_geteuid()) : false;
$wrapped = phoromatic_tls::as_service_user('benchy x');
check('pairing host: the shown command is run as the service user (sudo -u <user> ...), or as is for root', $me && $me['name'] !== '' && (posix_geteuid() === 0 ? $wrapped === 'benchy x' : $wrapped === 'sudo -u ' . $me['name'] . ' benchy x'), $wrapped);
$hs['mode'] = 'off';
check('pairing host: with HTTPS off there is nothing to check', phoromatic_tls::host_problem('seinar', $hs) === null);

echo 'RESULT unit pass=' . $pass . ' fail=' . $fail . PHP_EOL;
exit($fail ? 1 : 0);
