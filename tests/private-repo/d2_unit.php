<?php

// Unit-level checks of join codes (server discovery D2, Benchy 0.10.0): the code (alphabet, normalizing, check characters, the vectors of the protocol
// README), the anchor per TLS mode, making a code (kind, hash only, an hour, audit), enrolling with one (the right kind, the right account, once, expiry,
// the lockout, the server-wide cap and its pause), the schema step 64 over a database at 63, and the Systems page box. Run by d2.sh in a throw-away
// container of its own (php /h/d2_unit.php; tree at /bench, HOME=/root, a fresh data folder; it makes two accounts, UNITAA and UNITBB), so
// nothing here touches a running server. Prints "  PASS  ..." / "  FAIL  ..." lines.

define('PTS_MODE', 'CLIENT');
define('PTS_AUTO_LOAD_OBJECTS', true);
define('PHOROMATIC_SERVER', true);
$_SERVER['argv'] = array('x');
include('/bench/pts-core/pts-core.php');
pts_core::init();
phoromatic_tls::honour_silence();
phoromatic_server::prepare_database();

// the two accounts the checks work in (the command line would refuse to run as root here): the same call server-account-create makes
foreach(array(array('unita', 'a@example.com', 'UNITAA', null), array('unitb', 'b@example.com', 'UNITBB', 'Unit B')) as $acc)
{
	if(($why = phoromatic_accounts::create($acc[0], 'unitpass1', 'unitpass1', $acc[1], $acc[2], null, $acc[3])) !== null)
	{
		echo '  FAIL  could not make the account ' . $acc[2] . ': ' . $why . PHP_EOL;
	}
}
// (the Systems page code needs the web helper that makes the CSRF field; its own checks are in d2_web.py page)
if(!function_exists('write_token_in_form'))
{
	function write_token_in_form()
	{
		return '<input type="hidden" name="token_submit" value="x" />';
	}
}

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
function q($sql)
{
	return phoromatic_server::$db->querySingle($sql);
}
function rows($sql)
{
	$out = array();
	$r = phoromatic_server::$db->query($sql);
	while($r && ($row = $r->fetchArray(SQLITE3_ASSOC)))
	{
		$out[] = $row;
	}
	return $out;
}
function enroll_body($field, $code, $seed = 'a')
{
	$b = json_decode(file_get_contents('/bench/pts-core/static/protocol-v2/examples/enroll_request.json'), true);
	unset($b['code']);
	$b[$field] = $code;
	$b['machine']['machine_id'] = str_repeat($seed, 64);
	return $b;
}
function enroll_as($ip, $field, $code, $seed = 'a')
{
	$_SERVER['REMOTE_ADDR'] = $ip;
	return phoromatic_agent_auth::enroll(enroll_body($field, $code, $seed));
}
function reason($r)
{
	return isset($r['error']) ? $r['error'][0] . ' ' . $r['error'][1] : (string)$r['status'];
}
function fresh_counters()
{
	phoromatic_server::$db->exec('DELETE FROM phoromatic_v2_failures');
}
function make_join($account, $label = 'unit')
{
	$m = phoromatic_agent_auth::create_code($account, null, 'native', $label, 'user', 'qc admin', null, 'join');
	return $m;
}

// ---- the alphabet and what a person types
$A = 'name:seinar.example.net';
$FP = 'b0d6e1c2f3a4958677685746352413020f1e2d3c4b5a69788796a5b4c3d2e1f0';
check('vectors of the protocol README, internal anchor', phoromatic_join_code::check_chars('K7Q29X', $FP) === 'JS23' && phoromatic_join_code::check_chars('000000', $FP) === 'SDC8' && phoromatic_join_code::check_chars('ZZZZZZ', $FP) === 'VNC9');
check('vectors of the protocol README, name anchor', phoromatic_join_code::check_chars('K7Q29X', $A) === 'A1FH' && phoromatic_join_code::check_chars('000000', $A) === 'XD5D' && phoromatic_join_code::check_chars('ZZZZZZ', $A) === '1MEA');
check('normalize: case, hyphens and spaces are ignored', phoromatic_join_code::normalize(' k7q-29x a1fh ') === 'K7Q29XA1FH' && phoromatic_join_code::normalize("K7Q-29X-A1FH\n") === 'K7Q29XA1FH');
check('normalize: I and L read as 1, O as 0', phoromatic_join_code::normalize('K7Q29XAIFH') === 'K7Q29XA1FH' && phoromatic_join_code::normalize('k7q29xalfh') === 'K7Q29XA1FH' && phoromatic_join_code::normalize('0O0000XD5D') === '0000' . '00XD5D');
check('normalize: U, a wrong length, other characters and non-text are refused', phoromatic_join_code::normalize('K7Q29XA1FU') === false && phoromatic_join_code::normalize('K7Q29XA1F') === false && phoromatic_join_code::normalize('K7Q29XA1FHH') === false && phoromatic_join_code::normalize('K7Q29XA1F!') === false && phoromatic_join_code::normalize(array('K7Q29XA1FH')) === false && phoromatic_join_code::normalize('') === false);
check('display: three, three and four', phoromatic_join_code::display('K7Q29XA1FH') === 'K7Q-29X-A1FH');
check('verifies: the right code for its anchor', phoromatic_join_code::verifies('K7Q29XA1FH', $A) && phoromatic_join_code::verifies('K7Q29XJS23', $FP));
check('verifies: the same code against another anchor is refused (a rogue server)', !phoromatic_join_code::verifies('K7Q29XA1FH', 'name:rogue.example.net') && !phoromatic_join_code::verifies('K7Q29XJS23', strrev($FP)) && !phoromatic_join_code::verifies('K7Q29XA1FH', $FP));
$typos = 0;
$alphabet = phoromatic_join_code::ALPHABET;
for($i = 0; $i < 10; $i++)
{
	$code = 'K7Q29XA1FH';
	$code[$i] = $alphabet[(strpos($alphabet, $code[$i]) + 1) % 32];
	$typos += phoromatic_join_code::verifies($code, $A) ? 1 : 0;
}
check('verifies: a one-character typo anywhere in the code is caught', $typos === 0, $typos);
$ok = true;
$seen = array();
for($i = 0; $i < 300; $i++)
{
	$c = phoromatic_join_code::make($A);
	$ok = $ok && preg_match('/^[0-9A-HJKMNP-TV-Z]{10}$/', $c) === 1 && phoromatic_join_code::verifies($c, $A) && phoromatic_join_code::normalize(phoromatic_join_code::display($c)) === $c;
	$seen[$c] = 1;
}
check('make: 300 codes, all ten canonical characters, all verify for their anchor and survive display and normalizing, no repeats', $ok && count($seen) === 300);
check('hash: the SHA-256 of the ten characters', phoromatic_join_code::hash('K7Q29XA1FH') === hash('sha256', 'K7Q29XA1FH'));

// ---- the anchor, per TLS mode
$s = phoromatic_tls::defaults();
check('anchor: HTTPS off is refused, and the reason says so', ($a = phoromatic_join_code::anchor($s)) && isset($a['error']) && strpos($a['error'], 'built-in HTTPS') !== false, $a);
$s['mode'] = 'internal';
$s['names'] = array('Seinar.Example.NET', '192.0.2.9');
phoromatic_tls::save_settings($s);
check('anchor: internal mode with no CA yet is refused', isset(phoromatic_join_code::anchor()['error']), phoromatic_join_code::anchor());
$ca = phoromatic_tls::root_ca_path();
@mkdir(dirname($ca), 0700, true);
exec('openssl req -x509 -newkey rsa:2048 -nodes -subj /CN=qc-ca -days 2 -keyout /tmp/qc-ca.key -out ' . escapeshellarg($ca) . ' 2>/dev/null');
$want = strtolower(str_replace(':', '', trim(preg_replace('/^.*=/', '', (string)shell_exec('openssl x509 -in ' . escapeshellarg($ca) . ' -noout -fingerprint -sha256')))));
check('anchor: internal mode is the root CA fingerprint, lower-case hex, what the server reports as ca_sha256', phoromatic_join_code::anchor() === array('anchor' => $want) && phoromatic_discovery::info()['tls']['ca_sha256'] === $want, phoromatic_join_code::anchor());
foreach(array('file', 'acme') as $mode)
{
	$s['mode'] = $mode;
	$s['names'] = array('seinar.example.net', '192.0.2.9');
	phoromatic_tls::save_settings($s);
	check('anchor: ' . $mode . ' mode is "name:" and the first name', phoromatic_join_code::anchor() === array('anchor' => 'name:seinar.example.net'), phoromatic_join_code::anchor());
}
$s['names'] = array('Seinar.Example.NET');
phoromatic_tls::save_settings($s);
check('anchor: the name is lower case', phoromatic_join_code::anchor() === array('anchor' => 'name:seinar.example.net'));
$s['names'] = array('*.example.net', 'seinar.example.net');
phoromatic_tls::save_settings($s);
check('anchor: a wildcard first is refused with a way out', ($a = phoromatic_join_code::anchor()) && isset($a['error']) && strpos($a['error'], 'wildcard') !== false, $a);
$s['names'] = array();
phoromatic_tls::save_settings($s);
check('anchor: no name at all is refused', isset(phoromatic_join_code::anchor()['error']));
$s['mode'] = 'off';
$s['names'] = array('seinar.example.net');
phoromatic_tls::save_settings($s);
check('info: join_codes is false while the built-in HTTPS is off', phoromatic_discovery::info()['join_codes'] === false);
$s['mode'] = 'internal';
phoromatic_tls::save_settings($s);
check('info: join_codes is true while it is on', phoromatic_discovery::info()['join_codes'] === true);

// ---- making a code
$A_ID = 'UNITAA';
$B_ID = 'UNITBB';
phoromatic_tenant_context::set($A_ID);
check('the database is at schema 68 and the table has a Kind column that defaults to enroll', (int)q('PRAGMA user_version') === 68 && in_array('Kind', phoromatic_server::table_columns('phoromatic_enrol_codes')) && phoromatic_join_code::ready());
$s['mode'] = 'off';
phoromatic_tls::save_settings($s);
$m = make_join($A_ID);
check('create: with the built-in HTTPS off no join code is made and nothing is written', isset($m['error']) && strpos($m['error'], 'built-in HTTPS') !== false && (int)q('SELECT COUNT(*) FROM phoromatic_enrol_codes') === 0, $m);
$s['mode'] = 'internal';
phoromatic_tls::save_settings($s);
$old = phoromatic_agent_auth::create_code($A_ID, null, 'native', 'ordinary', 'user', 'qc admin');
check('create: an ordinary code is still a ben_ code, Kind enroll, good for 24 hours', isset($old['code']) && phoromatic_agent_auth::valid_code_format($old['code']) && q("SELECT Kind FROM phoromatic_enrol_codes WHERE CodeID = " . (int)$old['id']) === 'enroll' && abs(strtotime($old['expires']) - time() - 86400) < 10, $old);
$m = make_join($A_ID, 'bench pc');
check('create: a join code is ten canonical characters that verify against this server\'s anchor', isset($m['code']) && preg_match('/^[0-9A-HJKMNP-TV-Z]{10}$/', $m['code']) === 1 && phoromatic_join_code::verifies($m['code'], $want), $m);
$row = rows('SELECT * FROM phoromatic_enrol_codes WHERE CodeID = ' . (int)$m['id'])[0];
check('create: stored as Kind join, the SHA-256 of the code and never the code, one use, an hour, the account, native policy', $row['Kind'] === 'join' && $row['CodeHash'] === hash('sha256', $m['code']) && strpos(json_encode($row), $m['code']) === false && (int)$row['MaxUses'] === 1 && abs(strtotime($row['ExpiresOn']) - time() - 3600) < 10 && $row['AccountID'] === $A_ID && $row['SetPolicy'] === 'native' && $row['Label'] === 'bench pc', $row);
$audit = rows("SELECT Detail, ActorType FROM phoromatic_admin_audit WHERE Action = 'agent_code_create' AND SubjectID = 'EC" . (int)$m['id'] . "'");
check('create: one audit row, naming the kind, and not the code', count($audit) === 1 && strpos($audit[0]['Detail'], '"kind":"join"') !== false && strpos(json_encode($audit), $m['code']) === false, $audit);
$m2 = make_join($A_ID, '');
check('create: no label is "join code"', isset($m2['code']) && $m2['label'] === 'join code', $m2);
check('create: a label with markup is refused as before', isset(make_join($A_ID, '<b>x</b>')['error']));
check('create: a system that does not exist is refused', isset(phoromatic_agent_auth::create_code($A_ID, 'NOSUCH', 'native', 'x', 'user', 'qc admin', null, 'join')['error']));
phoromatic_server::$db->exec("INSERT INTO phoromatic_enrol_codes (CodeID, AccountID, CodeHash, Label, ExpiresOn, CreatedOn, CreatedBy) VALUES (9000, '" . $A_ID . "', 'x9000', 'by a release before 64', '2099-01-01 00:00:00', 'now', 'old')");
check('a code written without Kind (a release before 0.10.0, or this one before the step) is an ordinary code', q('SELECT Kind FROM phoromatic_enrol_codes WHERE CodeID = 9000') === 'enroll');
phoromatic_server::$db->exec('DELETE FROM phoromatic_enrol_codes WHERE CodeID = 9000');

// ---- enrolling with one
fresh_counters();
$jc = make_join($A_ID, 'first');
$r = enroll_as('192.0.2.50', 'join_code', $jc['code']);
check('enroll: a join code gives a token and a system in the account that made it, on the native engine', $r['status'] === 201 && phoromatic_agent_auth::valid_token_format($r['body']['token']) && $r['body']['account']['id'] === $A_ID && $r['body']['policy'] === 'native', $r);
$sys = rows("SELECT AccountID, EnginePolicy, State FROM phoromatic_systems WHERE SystemID = '" . $r['body']['system']['id'] . "'");
check('enroll: the system is in that account only, approved, native', count($sys) === 1 && $sys[0]['AccountID'] === $A_ID && (int)$sys[0]['State'] === 1 && $sys[0]['EnginePolicy'] === 'native', $sys);
$ae = rows("SELECT Detail FROM phoromatic_admin_audit WHERE Action = 'agent_enrolled' ORDER BY AuditID DESC LIMIT 1");
check('enroll: the audit row says it was a join code', strpos($ae[0]['Detail'], '"code_kind":"join"') !== false && strpos($ae[0]['Detail'], $jc['code']) === false, $ae);
check('enroll: the second use is code_used', reason(enroll_as('192.0.2.50', 'join_code', $jc['code'], 'b')) === '401 code_used');
$jb = make_join($B_ID, 'for b');
$r = enroll_as('192.0.2.50', 'join_code', $jb['code'], 'c');
$sysb = $r['status'] === 201 ? rows("SELECT AccountID FROM phoromatic_systems WHERE SystemID = '" . $r['body']['system']['id'] . "'") : array();
check('enroll: a code made inside account B puts the machine in B, and the client chose nothing', $r['status'] === 201 && $r['body']['account']['id'] === $B_ID && count($sysb) === 1 && $sysb[0]['AccountID'] === $B_ID, $r);
$je = make_join($A_ID, 'old');
phoromatic_server::$db->exec("UPDATE phoromatic_enrol_codes SET ExpiresOn = '2020-01-01 00:00:00' WHERE CodeID = " . (int)$je['id']);
check('enroll: an expired join code is code_expired', reason(enroll_as('192.0.2.50', 'join_code', $je['code'])) === '401 code_expired');
$jr = make_join($A_ID, 'revoked');
phoromatic_agent_auth::revoke_code($A_ID, $jr['id'], 'user', 'qc admin');
check('enroll: a revoked join code is code_invalid', reason(enroll_as('192.0.2.50', 'join_code', $jr['code'])) === '401 code_invalid');
check('enroll: a code that was never made is code_invalid', reason(enroll_as('192.0.2.50', 'join_code', '2222222222')) === '401 code_invalid');
// the two kinds never stand in for each other
$jx = make_join($A_ID, 'cross');
$ox = phoromatic_agent_auth::create_code($A_ID, null, 'native', 'cross ordinary', 'user', 'qc admin');
check('enroll: a join code sent as an ordinary code is code_invalid', reason(enroll_as('192.0.2.51', 'code', $jx['code'])) === '401 code_invalid');
check('enroll: an ordinary code sent as a join code is code_invalid', reason(enroll_as('192.0.2.51', 'join_code', $ox['code'])) === '401 code_invalid');
check('enroll: neither wrong try used the codes up', (int)q('SELECT Uses FROM phoromatic_enrol_codes WHERE CodeID = ' . (int)$jx['id']) === 0 && (int)q('SELECT Uses FROM phoromatic_enrol_codes WHERE CodeID = ' . (int)$ox['id']) === 0);
check('enroll: and both still work in their own field', enroll_as('192.0.2.52', 'join_code', $jx['code'], 'd')['status'] === 201 && enroll_as('192.0.2.52', 'code', $ox['code'], 'e')['status'] === 201);
$ju = make_join($A_ID, 'suspended');
phoromatic_server::$db->exec("UPDATE phoromatic_accounts SET State = 'suspended' WHERE AccountID = '" . $A_ID . "'");
$r = enroll_as('192.0.2.53', 'join_code', $ju['code']);
phoromatic_server::$db->exec("UPDATE phoromatic_accounts SET State = 'active' WHERE AccountID = '" . $A_ID . "'");
check('enroll: a suspended account refuses its join code, as it does any code (and the code is not used up)', reason($r) === '403 tenant_suspended' && (int)q('SELECT Uses FROM phoromatic_enrol_codes WHERE CodeID = ' . (int)$ju['id']) === 0, $r);

// ---- the lockout of one address
fresh_counters();
$jl = make_join($A_ID, 'lockout');
for($i = 0; $i < 10; $i++)
{
	enroll_as('192.0.2.60', 'join_code', '3333333333');
}
$r = enroll_as('192.0.2.60', 'join_code', $jl['code']);
check('lockout: ten wrong join codes lock the address out, even for the right code (429 with Retry-After)', reason($r) === '429 rate_limited' && isset($r['error'][3]['Retry-After']) && $r['error'][3]['Retry-After'] > 0, $r);
check('lockout: another address is not locked', enroll_as('192.0.2.61', 'join_code', $jl['code'], 'f')['status'] === 201);
$jl2 = make_join($A_ID, 'lockout 2');
fresh_counters();
for($i = 0; $i < 5; $i++)
{
	enroll_as('192.0.2.62', 'join_code', '3333333333');
	enroll_as('192.0.2.62', 'code', 'ben_' . str_repeat('Y', 43));
}
check('lockout: wrong join codes and wrong ordinary codes count together (one address, one budget)', reason(enroll_as('192.0.2.62', 'join_code', $jl2['code'])) === '429 rate_limited');

// ---- the cap across all addresses, and the pause
fresh_counters();
$now = time();
$s0 = phoromatic_server::$db->prepare('INSERT INTO phoromatic_v2_failures (Kind, SourceIP, At) VALUES (\'join\', :ip, :at)');
for($i = 1; $i <= 97; $i++)
{
	$s0->bindValue(':ip', '198.51.100.' . ($i % 250 + 1) . '/' . $i);
	$s0->bindValue(':at', $now - 30);
	$s0->execute();
	$s0->reset();
}
$jp = make_join($A_ID, 'paused');
$op = phoromatic_agent_auth::create_code($A_ID, null, 'native', 'paused ordinary', 'user', 'qc admin');
enroll_as('203.0.113.1', 'join_code', '4444444444');
enroll_as('203.0.113.2', 'join_code', '4444444444');
check('cap: 99 failed join codes in ten minutes (97 + 2) do not switch join codes off', phoromatic_join_code::paused() === 0 && (int)q("SELECT COUNT(*) FROM phoromatic_v2_failures WHERE Kind = 'joinpause'") === 0);
enroll_as('203.0.113.3', 'join_code', '4444444444');
$wait = phoromatic_join_code::paused();
check('cap: the hundredth does, for about ten minutes', $wait > 590 && $wait <= 600, $wait);
$before = (int)q("SELECT COUNT(*) FROM phoromatic_v2_failures WHERE Kind = 'join'");
$r = enroll_as('203.0.113.9', 'join_code', $jp['code']);
check('cap: while paused the right join code is refused too (429, rate_limited, Retry-After), and the message says what still works', reason($r) === '429 rate_limited' && $r['error'][3]['Retry-After'] > 0 && strpos($r['error'][2], 'pairing string') !== false, $r);
check('cap: the refused attempts are not counted again, and the code is not used up', (int)q("SELECT COUNT(*) FROM phoromatic_v2_failures WHERE Kind = 'join'") === $before && (int)q('SELECT Uses FROM phoromatic_enrol_codes WHERE CodeID = ' . (int)$jp['id']) === 0);
$r = enroll_as('203.0.113.9', 'code', $op['code'], 'g');
check('cap: an ordinary code (a pairing string) still enrolls during the pause', $r['status'] === 201, $r);
check('cap: the pause is audited once, with no code in it', (int)q("SELECT COUNT(*) FROM phoromatic_admin_audit WHERE Action = 'join_codes_paused'") === 1 && strpos((string)q("SELECT Detail FROM phoromatic_admin_audit WHERE Action = 'join_codes_paused'"), '4444444444') === false);
enroll_as('203.0.113.4', 'join_code', '4444444444');
check('cap: more refused attempts during the pause add no second pause or audit row', (int)q("SELECT COUNT(*) FROM phoromatic_v2_failures WHERE Kind = 'joinpause'") === 1 && (int)q("SELECT COUNT(*) FROM phoromatic_admin_audit WHERE Action = 'join_codes_paused'") === 1);
phoromatic_server::$db->exec("UPDATE phoromatic_v2_failures SET At = At - 601 WHERE Kind IN ('joinpause', 'join')");
check('cap: ten minutes later join codes are on again', phoromatic_join_code::paused() === 0);
$r = enroll_as('203.0.113.9', 'join_code', $jp['code'], 'h');
check('cap: and the same code now enrolls', $r['status'] === 201, $r);

// ---- the Systems page box
$_SESSION['AccountID'] = $A_ID;
$_SERVER['REQUEST_METHOD'] = 'GET';
fresh_counters();
$html = phoromatic_native_admin::list_section(true);
check('page: with the built-in HTTPS on the box offers "Make a join code" next to the pairing string button', strpos($html, 'name="native_join"') !== false && strpos($html, 'value="Enroll a new native system"') !== false);
check('page: and says what each is good for (an hour, a day)', strpos($html, 'an hour') !== false && strpos($html, '24 hours') !== false);
check('page: a viewer or anyone who is not an administrator gets no box', phoromatic_native_admin::list_section(false) === '');
$open = phoromatic_agent_auth::create_code($A_ID, null, 'native', 'listed join', 'user', 'qc admin', null, 'join');
$html = phoromatic_native_admin::list_section(true);
check('page: the open codes table has a Kind column, and shows the join code as one', strpos($html, '<th>Kind</th>') !== false && strpos($html, 'listed join') !== false && strpos($html, 'Join code') !== false);
check('page: the table never shows a code', strpos($html, $open['code']) === false && strpos($html, phoromatic_join_code::display($open['code'])) === false);
phoromatic_server::$db->exec("INSERT INTO phoromatic_v2_failures (Kind, SourceIP, At) VALUES ('joinpause', '*', " . time() . ")");
$html = phoromatic_native_admin::list_section(true);
check('page: while join codes are paused the box says so, and that a pairing string works', strpos($html, 'switched off') !== false && strpos($html, 'pairing string') !== false);
fresh_counters();
$box = phoromatic_native_admin::join_box($open);
check('page: the join code box shows the code in groups, says an hour and once, and escapes everything', strpos($box, phoromatic_join_code::display($open['code'])) !== false && strpos($box, 'within an hour') !== false && strpos($box, 'works once') !== false);
$s['mode'] = 'off';
phoromatic_tls::save_settings($s);
$html = phoromatic_native_admin::list_section(true);
check('page: with the built-in HTTPS off there is no join code button, and the box says why', strpos($html, 'native_join') === false && strpos($html, 'need the server\'s built-in HTTPS') !== false);
$s['mode'] = 'internal';
phoromatic_tls::save_settings($s);

// ---- the schema step over a database at 63
phoromatic_server::$db->exec('ALTER TABLE phoromatic_enrol_codes DROP COLUMN Kind');
phoromatic_server::$db->exec('PRAGMA user_version = 63');
$ordinary_before = (int)q('SELECT COUNT(*) FROM phoromatic_enrol_codes');
check('migration setup: a database at 63 has no Kind column and join codes are not ready', !in_array('Kind', phoromatic_server::table_columns('phoromatic_enrol_codes')) && !phoromatic_join_code::ready());
$m = make_join($A_ID);
check('migration setup: no join code is made there, with a clear reason', isset($m['error']) && strpos($m['error'], 'schema 64') !== false, $m);
$o = phoromatic_agent_auth::create_code($A_ID, null, 'native', 'before the step', 'user', 'qc admin');
check('migration setup: an ordinary code can still be made (no Kind needed)', isset($o['code']), $o);
$audit_before = (int)q("SELECT COUNT(*) FROM phoromatic_server_audit WHERE Action = 'migration'");
phoromatic_server::prepare_database();
check('migration: the next start is at 68 (64 to 67 on the way) with the column, defaulting existing codes to enroll', (int)q('PRAGMA user_version') === 68 && in_array('Kind', phoromatic_server::table_columns('phoromatic_enrol_codes')) && (int)q("SELECT COUNT(*) FROM phoromatic_enrol_codes WHERE Kind = 'enroll'") === $ordinary_before + 1 && phoromatic_join_code::ready());
$mig = rows("SELECT Detail FROM phoromatic_server_audit WHERE Action = 'migration' AND Detail LIKE '%\"from\":63%' ORDER BY AuditID DESC LIMIT 1");
check('migration: one audit row for each step (64 to 68), the first from 63 to 64, with the backup named', (int)q("SELECT COUNT(*) FROM phoromatic_server_audit WHERE Action = 'migration'") === $audit_before + 5 && strpos($mig[0]['Detail'], '"to":64') !== false && strpos($mig[0]['Detail'], '"from":63') !== false && strpos($mig[0]['Detail'], 'backup') !== false, $mig);
phoromatic_server::prepare_database();
check('migration: running it again changes nothing', (int)q('PRAGMA user_version') === 68 && (int)q("SELECT COUNT(*) FROM phoromatic_server_audit WHERE Action = 'migration'") === $audit_before + 5);
phoromatic_server::$db->exec('PRAGMA user_version = 63');
phoromatic_server::prepare_database();
check('migration: the step repeated over a column that is already there adds no second one and does not fail', (int)q('PRAGMA user_version') === 68 && count(array_keys(phoromatic_server::table_columns('phoromatic_enrol_codes'), 'Kind')) === 1);
$m = make_join($A_ID, 'after');
check('migration: join codes work after the step', isset($m['code']) && enroll_as('192.0.2.70', 'join_code', $m['code'], 'i')['status'] === 201);

echo 'RESULT d2_unit pass=' . $pass . ' fail=' . $fail . PHP_EOL;
exit($fail ? 1 : 0);

?>
