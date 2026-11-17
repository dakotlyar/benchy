<?php

// Unit-level checks of the one-certificate-for-every-name code (phoromatic_tls, internal mode, 0.10). Run by https_names.sh in a throw-away
// container (php /h/https_names_unit.php) where the tree is at /bench and /opt/caddy/caddy.tar.gz holds the pinned Caddy. No server runs.
// Prints "  PASS  ..." / "  FAIL  ..." lines.

define('PTS_MODE', 'CLIENT');
define('PTS_AUTO_LOAD_OBJECTS', true);
define('PHOROMATIC_SERVER', true);
$_SERVER['argv'] = array('x');
include('/bench/pts-core/pts-core.php');
pts_core::init();
phoromatic_tls::honour_silence();

function check($desc, $ok, $detail = '')
{
	echo ($ok ? '  PASS  ' : '  FAIL  ') . $desc . PHP_EOL;
	if(!$ok && $detail !== '')
	{
		echo '        | ' . substr(is_string($detail) ? $detail : json_encode($detail), 0, 500) . PHP_EOL;
	}
}

shell_exec('mkdir -p /tmp/caddybin && tar xzf /opt/caddy/caddy.tar.gz -C /tmp/caddybin caddy');
$s = phoromatic_tls::defaults();
$s['mode'] = 'internal';
$s['names'] = array('192.168.15.10', '10.10.1.185', 'Server.Example.com', '*.lab.example', 'fe80::1');
$s['caddy'] = array('version' => '2.11.7', 'path' => '/tmp/caddybin/caddy', 'sha256' => null, 'source' => 'system');
$tls = phoromatic_tls::tls_path();
$dir = $tls . 'caddy-data/pki/authorities/local/';

check('no CA and no leaf to begin with', !is_file($dir . 'root.crt') && !is_file(phoromatic_tls::leaf_cert_file()));
check('a missing leaf is reported as needed', phoromatic_tls::leaf_needs_issue($s) === 'there is none yet');
list($changed, $error) = phoromatic_tls::ensure_internal_leaf($s);
check('ensure makes Caddy\'s CA (through caddy validate) and the leaf, with no error', $changed === true && $error === null, $error);
check('the root is Caddy\'s own (the name Benchy <name> CA is kept)', is_file($dir . 'root.crt') && strpos(file_get_contents($dir . 'root.crt'), 'BEGIN CERTIFICATE') !== false);
$root_fp = phoromatic_tls::root_ca_fingerprint();
$info = phoromatic_tls::cert_info(file_get_contents(phoromatic_tls::leaf_cert_file()));
$have = phoromatic_tls::names_from_cert($info);
$ok = true;
foreach($s['names'] as $n)
{
	$ok = $ok && phoromatic_tls::name_covered($n, $info['sans']);
}
check('the leaf carries every configured name (DNS, wildcard and both IP families)', $ok && count($have) == 5, $info['sans']);
check('the leaf is valid for 30 days from now and is not a CA', $info['not_after'] - time() > 29 * 86400 && $info['not_after'] - time() <= 30 * 86400 && !$info['is_ca'] && $info['not_before'] <= time() + 5, $info);
check('the leaf verifies against the root (openssl_x509_verify)', phoromatic_tls::leaf_needs_issue($s) === null);
$out = shell_exec('openssl verify -CAfile ' . escapeshellarg($dir . 'root.crt') . ' -verify_ip 10.10.1.185 -x509_strict -purpose sslserver ' . escapeshellarg(phoromatic_tls::leaf_cert_file()) . ' 2>&1');
check('the openssl command line also accepts it for the second address, for TLS server use, strictly', strpos($out, 'OK') !== false, $out);
$ext = shell_exec('openssl x509 -in ' . escapeshellarg(phoromatic_tls::leaf_cert_file()) . ' -noout -ext basicConstraints,keyUsage,extendedKeyUsage 2>&1');
check('basicConstraints CA:FALSE, digitalSignature, serverAuth', strpos($ext, 'CA:FALSE') !== false && strpos($ext, 'Digital Signature') !== false && strpos($ext, 'TLS Web Server Authentication') !== false, $ext);
$mode = fileperms(phoromatic_tls::leaf_key_file()) & 0777;
check('the leaf key is 0600 and so is the certificate, no temp files left', $mode == 0600 && (fileperms(phoromatic_tls::leaf_cert_file()) & 0777) == 0600 && !glob($tls . '*.new') && !is_file($tls . 'internal-leaf.cnf') && !is_file($tls . 'ca-bootstrap.json'));
check('the private key is not in the certificate file and the key file is not a certificate', strpos(file_get_contents(phoromatic_tls::leaf_cert_file()), 'PRIVATE') === false && strpos(file_get_contents(phoromatic_tls::leaf_key_file()), 'CERTIFICATE') === false);

// nothing due: no new certificate
$serial = $info['sha256'];
list($changed, $error) = phoromatic_tls::ensure_internal_leaf($s);
check('a second ensure with nothing due changes nothing', $changed === false && $error === null && phoromatic_tls::cert_info(file_get_contents(phoromatic_tls::leaf_cert_file()))['sha256'] === $serial);

// the reasons
check('20 days later it is not due, 21 days later it is (10 days before the end)', phoromatic_tls::leaf_needs_issue($s, time() + 19 * 86400) === null && phoromatic_tls::leaf_needs_issue($s, time() + 21 * 86400) === 'it is due for renewal');
$s2 = $s;
$s2['names'][] = '172.16.0.9';
check('another name is a reason to make it again (and Server.Example.com in a different case is not)', phoromatic_tls::leaf_needs_issue($s2) === 'the names changed');
$s3 = $s;
$s3['names'] = array('192.168.15.10', '10.10.1.185', 'server.example.com', '*.lab.example', 'fe80::1');
check('a different case of a name is no reason', phoromatic_tls::leaf_needs_issue($s3) === null);
$s4 = $s;
array_pop($s4['names']);
check('a removed name is a reason too (it must not stay on the certificate)', phoromatic_tls::leaf_needs_issue($s4) === 'the names changed');
list($changed, $error) = phoromatic_tls::ensure_internal_leaf($s, time() + 22 * 86400);
check('ensure renews a due leaf: a different certificate, the same names, the same root', $changed === true && $error === null && phoromatic_tls::cert_info(file_get_contents(phoromatic_tls::leaf_cert_file()))['sha256'] !== $serial && phoromatic_tls::root_ca_fingerprint() === $root_fp);
check('the old pair is kept as .prev (0600) for the launcher\'s restore', is_file(phoromatic_tls::leaf_cert_file() . '.prev') && (fileperms(phoromatic_tls::leaf_key_file() . '.prev') & 0777) == 0600);
// a damaged pair
$good = file_get_contents(phoromatic_tls::leaf_key_file());
$other = openssl_pkey_new(array('private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'));
openssl_pkey_export($other, $other_pem);
file_put_contents(phoromatic_tls::leaf_key_file(), $other_pem);
check('a key that does not match the certificate (an interrupted write) is made again', phoromatic_tls::leaf_needs_issue($s) === 'its key does not match it');
list($changed, $error) = phoromatic_tls::ensure_internal_leaf($s);
check('...and ensure repairs it', $changed === true && $error === null && phoromatic_tls::leaf_needs_issue($s) === null);
file_put_contents(phoromatic_tls::leaf_cert_file(), "not a certificate\n");
check('an unreadable certificate file is made again', phoromatic_tls::leaf_needs_issue($s) === 'it cannot be read');
list($changed, $error) = phoromatic_tls::ensure_internal_leaf($s);
check('...and ensure repairs that too', $changed === true && $error === null);
// a leaf from another CA (the CA was replaced)
$old_root = file_get_contents($dir . 'root.crt');
$fake = openssl_pkey_new(array('private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'));
$csr = openssl_csr_new(array('commonName' => 'Other Root'), $fake, array('digest_alg' => 'sha256'));
$x = openssl_csr_sign($csr, null, $fake, 30, array('digest_alg' => 'sha256'));
openssl_x509_export($x, $other_root);
file_put_contents($dir . 'root.crt', $other_root);
check('a leaf not signed by the current root is made again', phoromatic_tls::leaf_needs_issue($s) === 'it was not signed by the current root');
file_put_contents($dir . 'root.crt', $old_root);
check('the root fingerprint did not change through all of that', phoromatic_tls::root_ca_fingerprint() === $root_fp);


// a name that was never through parse_names (carried over from a file-mode certificate) must not reach the openssl config
$inj = $s;
$inj['names'] = array('192.168.15.10', "evil.example\nbasicConstraints = critical,CA:TRUE");
$before = file_get_contents(phoromatic_tls::leaf_cert_file());
$err = phoromatic_tls::issue_internal_leaf($inj);
check('a name with a newline in it is refused (no config injection), and the leaf on disk is untouched', is_string($err) && strpos($err, 'cannot go into a certificate') !== false && file_get_contents(phoromatic_tls::leaf_cert_file()) === $before, $err);
list($changed, $err) = phoromatic_tls::ensure_internal_leaf($inj);
check('...ensure returns that as the problem (the caller falls back and says why)', $changed === false && is_string($err));
check('the leaf has serverAuth only', strpos(shell_exec('openssl x509 -in ' . escapeshellarg(phoromatic_tls::leaf_cert_file()) . ' -noout -ext extendedKeyUsage 2>&1'), 'Client Authentication') === false);

// restore after a failed reload: a good .prev comes back, a stale or wrong-names one does not
phoromatic_tls::ensure_internal_leaf($s, time() + 22 * 86400);   // makes a new current leaf; the one before it is now .prev
$prev_fp = phoromatic_tls::cert_info(file_get_contents(phoromatic_tls::leaf_cert_file() . '.prev'))['sha256'];
$cur_fp = phoromatic_tls::cert_info(file_get_contents(phoromatic_tls::leaf_cert_file()))['sha256'];
list($changed, $err) = phoromatic_tls::restore_leaf_prev($s);
check('restore puts a still-good .prev back', $err === null && $changed === true && phoromatic_tls::cert_info(file_get_contents(phoromatic_tls::leaf_cert_file()))['sha256'] === $prev_fp && $prev_fp !== $cur_fp);
$s5 = $s;
$s5['names'][] = '172.16.0.77';
list($changed, $err) = phoromatic_tls::restore_leaf_prev($s5);
$now_info = phoromatic_tls::cert_info(file_get_contents(phoromatic_tls::leaf_cert_file()));
check('restore does not put back a .prev for other names: it makes a new leaf for the current ones', $err === null && phoromatic_tls::name_covered('172.16.0.77', $now_info['sans']));
copy(phoromatic_tls::leaf_cert_file(), phoromatic_tls::leaf_cert_file() . '.prev');
copy(phoromatic_tls::leaf_key_file(), phoromatic_tls::leaf_key_file() . '.prev');
$prev_fp = phoromatic_tls::cert_info(file_get_contents(phoromatic_tls::leaf_cert_file() . '.prev'))['sha256'];
list($changed, $err) = phoromatic_tls::restore_leaf_prev($s5, time() + 29.5 * 86400);
check('restore does not put back a .prev with under a day left: it makes a new leaf', $err === null && $changed === true && phoromatic_tls::cert_info(file_get_contents(phoromatic_tls::leaf_cert_file()))['sha256'] !== $prev_fp);
$cj = $tls . 'unit-uses.json';
file_put_contents($cj, '{"load_files":[{"certificate":"' . phoromatic_tls::leaf_cert_file() . '"}]}');
$cj2 = $tls . 'unit-automate.json';
file_put_contents($cj2, '{"automate":["a"]}');
check('a config that loads the leaf is told from one that automates per name', phoromatic_tls::caddy_json_uses_leaf($cj) && !phoromatic_tls::caddy_json_uses_leaf($cj2) && !phoromatic_tls::caddy_json_uses_leaf($tls . 'nope.json'));
// the banner stays quiet in the fallback
phoromatic_tls::note_leaf_result('test problem');
check('a fallback problem is kept and logged', phoromatic_tls::leaf_problem() === 'test problem' && is_file($tls . 'internal-leaf.log') && strpos(file_get_contents($tls . 'internal-leaf.log'), 'test problem') !== false);
phoromatic_tls::note_leaf_result(null);
check('...and cleared again when the leaf works', phoromatic_tls::leaf_problem() === null);

// the Caddy config
$leaf = array(phoromatic_tls::leaf_cert_file(), phoromatic_tls::leaf_key_file());
$c = phoromatic_tls::caddy_config($s, 'secret', 39082, 39080, null, $leaf);
check('config with a leaf: load_files, no automate, no issuer (Caddy makes no certificates of its own)', isset($c['apps']['tls']['certificates']['load_files'][0]) && !isset($c['apps']['tls']['certificates']['automate']) && $c['apps']['tls']['automation']['policies'][0]['issuers'] === array());
check('...with the leaf\'s file names and the first non-wildcard name as default_sni', $c['apps']['tls']['certificates']['load_files'][0]['certificate'] === $leaf[0] && $c['apps']['tls']['certificates']['load_files'][0]['key'] === $leaf[1] && $c['apps']['http']['servers']['benchy_https']['tls_connection_policies'][0]['default_sni'] === '192.168.15.10');
check('...the pki block is still there with install_trust false', $c['apps']['pki']['certificate_authorities']['local']['install_trust'] === false && $c['apps']['pki']['certificate_authorities']['local']['name'] === phoromatic_tls::ca_name($s));
$c = phoromatic_tls::caddy_config($s, 'secret', 39082, 39080);
check('config without a leaf is today\'s: Caddy automates a certificate per name', $c['apps']['tls']['certificates']['automate'] === $s['names'] && $c['apps']['tls']['automation']['policies'][0]['issuers'][0]['module'] === 'internal');
$sf = $s;
$sf['mode'] = 'file';
$sf['file'] = array('cert' => '/x/c.pem', 'key' => '/x/k.pem');
$c = phoromatic_tls::caddy_config($sf, 'secret', 39082, 39080, null, $leaf);
check('file mode ignores a leaf', isset($c['apps']['tls']['certificates']['load_files']) && $c['apps']['tls']['certificates']['load_files'][0]['certificate'] === '/x/c.pem');
// write_config makes the leaf, validates with the real Caddy
@unlink($tls . 'internal-leaf.pem');
$cfg = $tls . 'unit-caddy.json';
check('write_config in internal mode makes the leaf and writes a config', phoromatic_tls::write_config($s, 'secret', 39082, 39080, $cfg) && is_file(phoromatic_tls::leaf_cert_file()) && strpos(file_get_contents($cfg), 'load_files') !== false);
check('...and the real Caddy accepts that config (caddy validate)', phoromatic_tls::validate_config($s, $cfg) === null);
check('...with mode 0600 and no problem noted', (fileperms($cfg) & 0777) == 0600 && phoromatic_tls::leaf_problem() === null);
// the fallback
$bad = $s;
$bad['caddy']['path'] = '/nonexistent/caddy';
@unlink($tls . 'internal-leaf.pem');
shell_exec('rm -rf ' . escapeshellarg($tls . 'caddy-data/pki'));
check('write_config falls back to per-name certificates when the CA cannot be made, and notes why', phoromatic_tls::write_config($bad, 'secret', 39082, 39080, $cfg) && strpos(file_get_contents($cfg), 'automate') !== false && phoromatic_tls::leaf_problem() !== null);
echo '        (the reason kept for server-https-status: ' . substr((string)phoromatic_tls::leaf_problem(), 0, 120) . ')' . PHP_EOL;
// the probes
check('the no-SNI handshake helper gives false for a port nothing listens on', phoromatic_tls::handshake_certificate('127.0.0.1', 1, null, 1) === false);
