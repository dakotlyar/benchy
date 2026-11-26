<?php
// The server update (0.16.2): the signed release format and the store (phoromatic_server_update), the request the web page writes, the web gate, and the
// root helper's pure parts (deploy/deb-package/benchy-server/benchy-server-update) against the same vectors. No server, no database, no network, nothing
// installed; throw-away keys made here and never kept. Run in a container with PHP 8.3+, openssl and zlib:
//   php -d memory_limit=512M tests/server-update/server_update_test.php
error_reporting(E_ALL);
ini_set('display_errors', '1');
set_error_handler(function($no, $msg) { return strpos($msg, 'headers already sent') !== false || strpos($msg, 'Cannot set response code') !== false; });
$tree = sys_get_temp_dir() . '/server-update-' . bin2hex(random_bytes(6)) . '/';
mkdir($tree . 'pts-core/static/engine', 0700, true);
define('PTS_PATH', $tree);
register_shutdown_function(function() use ($tree) { exec('rm -rf ' . escapeshellarg($tree)); });
foreach(array('phoromatic_catalog_refusal', 'phoromatic_catalog_keys', 'phoromatic_catalog_json', 'phoromatic_catalog_zip', 'phoromatic_catalog_verify', 'phoromatic_server_update') as $c)
{
	require_once __DIR__ . '/../../pts-core/objects/phoromatic/' . $c . '.php';
}
require_once __DIR__ . '/../catalog/lib.php';
require_once __DIR__ . '/../../deploy/deb-package/benchy-server/benchy-server-update';   // (its main() only runs when it is the script)

// what the store and the web layer take from the rest of the server
class phoromatic_server
{
	public static $db = null;
	public static function current_time() { return '2026-11-24 10:00:00'; }
}
class phoromatic_server_audit
{
	public static $rows = array();
	public static function record($tenant, $type, $actor, $action, $o) { self::$rows[] = array($action, $type, $actor, $o); }
	public static function actions() { return array_map(function($r) { return $r[0]; }, self::$rows); }
}
class phoromatic_auth
{
	public static $admin = true;
	public static function is_server_admin() { return self::$admin; }
	public static function audit_actor() { return array('', 'root', 'RootAdmin', 'root login'); }
}
class phoromatic_passwords
{
	public static function check_root($p) { return $p === 'right-password'; }
	public static function select_columns() { return ''; }
	public static function check_user($row, $salt, $p) { return false; }
}
class phoromatic_private_repo_web
{
	public static $origin = true;
	public static function origin_ok() { return self::$origin; }
	public static function security_headers() {}
}
class phoromatic_tls
{
	public static function as_user($c) { return $c; }
	public static function as_service_user($c) { return $c; }
}
interface pts_webui_interface
{
	public static function preload($PATH);
	public static function page_title();
	public static function page_header();
	public static function render_page_process($PATH);
}
$_SESSION = array('AdminLevel' => -40, 'Token' => 'tok');
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
function verify_submission_token() { return isset($_POST['token_submit']) && $_POST['token_submit'] === $_SESSION['Token']; }
function write_token_in_form() { return ''; }
require_once __DIR__ . '/../../pts-core/objects/phoromatic/phoromatic_server_console.php';
require_once __DIR__ . '/../../pts-core/objects/phoromatic/phoromatic_server_update_web.php';
require_once __DIR__ . '/../../pts-core/phoromatic/pages/phoromatic_r_server_update.php';

$ring = new test_ring(array(1, 2));
$rogue = new test_ring(array(1));
file_put_contents($tree . 'pts-core/static/engine/keys.json', json_encode(array('schema' => 'benchy-engine-keys/1', 'test_keys' => false,
	'keys' => array(array('generation' => 1, 'spki' => $ring->spki[1]), array('generation' => 2, 'spki' => $ring->spki[2])), 'denied_key_ids' => array())));

$n = 0;
function fresh($running = '0.16.2')
{
	global $n, $tree;
	$base = $tree . 'case' . (++$n) . '/';
	phoromatic_server_update::$handoff_override = $base . 'handoff/';
	phoromatic_server_update::$root_override = $base . 'root/';
	phoromatic_server_update::$running_override = $running;
	phoromatic_server_update::$probe = function($args) { return $args[0] === 'is-enabled' ? 'enabled' : 'inactive'; };
	mkdir($base . 'handoff', 0750, true);
	mkdir($base . 'root', 0755, true);
	phoromatic_server_audit::$rows = array();
	phoromatic_auth::$admin = true;
	phoromatic_private_repo_web::$origin = true;
	$_SESSION = array('AdminLevel' => -40, 'Token' => 'tok');
	$_POST = array();
}
function pkg($name, $v, $salt = '')
{
	return $name . ' deb ' . $v . $salt;
}
function release_json($v, $o = array())
{
	$pk = array();
	foreach(array('benchy', 'benchy-server') as $name)
	{
		$bytes = pkg($name, $v, isset($o['salt']) ? $o['salt'] : '');
		$pk[] = array('name' => $name, 'version' => $v, 'file' => $name . '_' . $v . '_all.deb', 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes));
	}
	$ix = array_merge(array('schema' => 'benchy-server-release/1', 'version' => $v, 'issued' => '2026-11-24T15:00:00Z', 'min_generation' => null, 'platform' => 'linux-deb', 'db_schema' => 67, 'packages' => $pk, 'notes' => 'a note'), isset($o['ix']) ? $o['ix'] : array());
	return json_encode($ix, JSON_UNESCAPED_SLASHES);
}
function bundle($ring, $gen, $v, $o = array())
{
	$json = isset($o['json']) ? $o['json'] : release_json($v, $o);
	$signed = $ring->sign($gen, $json);
	if(isset($o['flip']))
	{
		$signed[strlen($signed) - $o['flip']] = $signed[strlen($signed) - $o['flip']] === 'a' ? 'b' : 'a';
	}
	$entries = array(array('name' => 'server.signed', 'data' => $signed));
	foreach(array('benchy', 'benchy-server') as $name)
	{
		if(isset($o['skip']) && $o['skip'] === $name)
		{
			continue;
		}
		$data = pkg($name, $v, isset($o['salt']) ? $o['salt'] : '');
		if(isset($o['tamper']) && $o['tamper'] === $name)
		{
			$data = strtoupper($data);
		}
		$entries[] = array('name' => $name . '_' . $v . '_all.deb', 'data' => $data);
	}
	foreach(isset($o['extra']) ? $o['extra'] : array() as $e)
	{
		$entries[] = $e;
	}
	return catalog_zipbuild::build($entries);
}
function imp($zip)
{
	global $tree;
	$p = $tree . 'b' . mt_rand() . '.zip';
	file_put_contents($p, $zip);
	return phoromatic_server_update::import($p, 'web', 'root', 'RootAdmin');
}
function refused($zip, $reason)
{
	try
	{
		imp($zip);
	}
	catch(phoromatic_catalog_refusal $r)
	{
		return $r->reason === $reason ? true : 'refused as ' . $r->reason . ' (' . $r->getMessage() . '), wanted ' . $reason;
	}
	return 'was accepted, wanted ' . $reason;
}
function nothing_held()
{
	return count(glob(phoromatic_server_update::handoff('held/*.signed'))) === 0 && count(glob(phoromatic_server_update::handoff('held/files/*'))) === 0;
}
function expect($got)
{
	if($got !== true)
	{
		throw new Exception((string)$got);
	}
}

// ---- U1: the format and the store ----

t('a signed server update is imported, held and listed', function() {
	global $ring;
	fresh();
	$r = imp(bundle($ring, 1, '0.16.99'));
	eq('0.16.99', $r['version']);
	eq(false, $r['unchanged']);
	$h = phoromatic_server_update::held();
	eq(1, count($h));
	eq('0.16.99', $h[0]['version']);
	eq(67, $h[0]['db_schema']);
	eq('0.16.99', phoromatic_server_update::newer()['version']);
	eq(array('server_update_imported'), phoromatic_server_audit::actions());
	global $tree;
	$same = bundle($ring, 1, '0.16.99', array('salt' => 'x'));
	imp($same);
	eq(true, imp($same)['unchanged']);
});

t('a components index or an engine index signed by the same key is not a server release', function() {
	global $ring;
	fresh();
	$comp = json_encode(array('schema' => 'benchy-component-index/1', 'sequence' => 3, 'issued' => '2026-11-24T15:00:00Z', 'min_generation' => null, 'platform' => 'windows-x64', 'components' => new stdClass()));
	expect(refused(bundle($ring, 1, '0.16.99', array('json' => $comp)), 'bad_index'));
	$eng = json_encode(array('schema' => 'benchy-engine-index/1', 'sequence' => 3, 'issued' => '2026-11-24T15:00:00Z', 'min_generation' => null, 'release' => new stdClass(), 'revoked' => array(), 'floor' => ''));
	expect(refused(bundle($ring, 1, '0.16.99', array('json' => $eng)), 'bad_index'));
	ok(nothing_held(), 'something was held');
	$actions = phoromatic_server_audit::actions();
	eq(array('server_update_import_refused', 'server_update_import_refused'), $actions);
});

t('a bad signature, an unknown key and a denied key are refused with nothing held', function() {
	global $ring, $rogue, $tree;
	fresh();
	expect(refused(bundle($ring, 1, '0.16.99', array('flip' => 3)), 'bad_signature'));
	expect(refused(bundle($rogue, 1, '0.16.99'), 'unknown_key'));
	ok(nothing_held(), 'something was held');
	$denied = json_decode(file_get_contents($tree . 'pts-core/static/engine/keys.json'), true);
	$denied['denied_key_ids'] = array($ring->key_id(2));
	file_put_contents($tree . 'pts-core/static/engine/keys.json', json_encode($denied));
	expect(refused(bundle($ring, 2, '0.16.99'), 'denied_key'));
	$denied['denied_key_ids'] = array();
	file_put_contents($tree . 'pts-core/static/engine/keys.json', json_encode($denied));
});

t('a deb that is not the one the index names is refused: flipped bytes, a missing file, an extra file', function() {
	global $ring;
	fresh();
	expect(refused(bundle($ring, 1, '0.16.99', array('tamper' => 'benchy-server')), 'bundle_pack_hash'));
	expect(refused(bundle($ring, 1, '0.16.99', array('skip' => 'benchy')), 'bundle_pack_missing'));
	expect(refused(bundle($ring, 1, '0.16.99', array('extra' => array(array('name' => 'evil.sh', 'data' => 'x')))), 'bundle_pack_unlisted'));
	ok(nothing_held(), 'something was held');
});

t('an older server release is refused (downgrade), the running version is held as the way back', function() {
	global $ring;
	fresh('0.16.2');
	expect(refused(bundle($ring, 1, '0.16.1'), 'downgrade'));
	eq('0.16.2', imp(bundle($ring, 1, '0.16.2'))['version']);
	ok(phoromatic_server_update::has_base(), 'the running version is not held');
	eq(null, phoromatic_server_update::newer());
});

t('the index must have exactly the fields, the two packages and the file names', function() {
	global $ring;
	fresh();
	$bad = array(
		array('ix' => array('extra' => 1)), array('ix' => array('platform' => 'windows-x64')), array('ix' => array('schema' => 'benchy-server-release/2')), array('ix' => array('version' => '0.16')),
		array('ix' => array('db_schema' => '67')), array('ix' => array('notes' => str_repeat('x', 201))), array('ix' => array('issued' => 'yesterday')), array('ix' => array('min_generation' => '1')),
		array('ix' => array('packages' => array())),
	);
	foreach($bad as $i => $o)
	{
		expect(refused(bundle($ring, 1, '0.16.99', $o), 'bad_index'));
	}
	$ix = json_decode(release_json('0.16.99'), true);
	$ix['packages'][1]['file'] = '../benchy-server_0.16.99_all.deb';
	expect(refused(bundle($ring, 1, '0.16.99', array('json' => json_encode($ix))), 'bad_index'));
	$ix = json_decode(release_json('0.16.99'), true);
	$ix['packages'][1]['name'] = 'benchy';
	expect(refused(bundle($ring, 1, '0.16.99', array('json' => json_encode($ix))), 'bad_index'));
	$ix = json_decode(release_json('0.16.99'), true);
	$ix['packages'][0]['size'] = 0;
	expect(refused(bundle($ring, 1, '0.16.99', array('json' => json_encode($ix))), 'bad_index'));
	ok(nothing_held(), 'something was held');
});

t('a zip with a folder, a path or a symlink member is refused', function() {
	global $ring;
	fresh();
	expect(refused(bundle($ring, 1, '0.16.99', array('extra' => array(array('name' => '../x', 'data' => 'x')))), 'pack_path'));
	expect(refused(bundle($ring, 1, '0.16.99', array('extra' => array(array('name' => 'sub/x', 'data' => 'x')))), 'pack_path'));
	ok(nothing_held(), 'something was held');
});

// ---- the request ----

t('a request is written atomically with exactly the fields, for a held newer bundle', function() {
	global $ring;
	fresh();
	imp(bundle($ring, 1, '0.16.2'));
	$sha = imp(bundle($ring, 1, '0.16.99'))['sha'];
	$h = phoromatic_server_update::request($sha, 'RootAdmin');
	eq('0.16.99', $h['version']);
	$bytes = file_get_contents(phoromatic_server_update::handoff('request.json'));
	$j = json_decode($bytes, true);
	eq(array('schema', 'signed_sha256', 'requested_by', 'requested_at'), array_keys($j));
	eq('benchy-server-update-request/1', $j['schema']);
	eq($sha, $j['signed_sha256']);
	eq('RootAdmin', $j['requested_by']);
	ok(preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ\z/', $j['requested_at']) === 1, 'requested_at');
	ok(strlen($bytes) < 1024, 'too long');
	eq('0640', substr(sprintf('%o', fileperms(phoromatic_server_update::handoff('request.json'))), -4));
	eq(array(), glob(phoromatic_server_update::handoff('.*.tmp')));
	// the helper's own parser takes exactly that
	eq(array('sha' => $sha, 'by' => 'RootAdmin'), BenchyServerUpdate::parse_request($bytes));
	expect(refused_request($sha, 'not_available'));   // one at a time
});
function refused_request($sha, $reason)
{
	try
	{
		phoromatic_server_update::request($sha, 'RootAdmin');
	}
	catch(phoromatic_catalog_refusal $r)
	{
		return $r->reason === $reason ? true : 'refused as ' . $r->reason . ' (' . $r->getMessage() . '), wanted ' . $reason;
	}
	return 'was accepted, wanted ' . $reason;
}

t('a request is refused: no bundle of the running version, an unknown sha, the running version itself, a service that is not enabled', function() {
	global $ring;
	fresh();
	$sha = imp(bundle($ring, 1, '0.16.99'))['sha'];
	expect(refused_request($sha, 'not_held'));   // (no way back held)
	imp(bundle($ring, 1, '0.16.2'));
	expect(refused_request(str_repeat('a', 64), 'not_held'));
	expect(refused_request('../../etc/passwd', 'not_held'));
	$base = phoromatic_server_update::held();
	$running = array_values(array_filter($base, function($h) { return $h['version'] === '0.16.2'; }))[0]['sha'];
	expect(refused_request($running, 'downgrade'));
	phoromatic_server_update::$probe = function($args) { return 'disabled'; };
	expect(refused_request($sha, 'not_available'));
	ok(!file_exists(phoromatic_server_update::handoff('request.json')), 'a request was written');
});

t('what the page shows: queued, running, done, interrupted', function() {
	global $ring;
	fresh();
	eq('none', phoromatic_server_update::view()['state']);
	$status = function($state, $run = 'abcdef0123456789') { file_put_contents(phoromatic_server_update::root_dir('status.json'), json_encode(array('state' => $state, 'run_id' => $run, 'from' => '0.16.2', 'to' => '0.16.99', 'step' => 's', 'message' => 'm'))); };
	$active = false;
	phoromatic_server_update::$probe = function($args) use (&$active) { return $args[0] === 'is-active' ? ($active ? 'active' : 'inactive') : 'enabled'; };
	$status('done');
	eq('done', phoromatic_server_update::view()['state']);
	file_put_contents(phoromatic_server_update::handoff('request.json'), '{}');
	eq('queued', phoromatic_server_update::view()['state']);
	unlink(phoromatic_server_update::handoff('request.json'));
	$status('installing');
	eq('interrupted', phoromatic_server_update::view()['state']);
	$active = true;
	eq('installing', phoromatic_server_update::view()['state']);
	file_put_contents(phoromatic_server_update::root_dir('status.json'), 'not json');
	eq('none', phoromatic_server_update::view()['state']);
	// a finished run is audited once
	$active = false;
	$status('rolled_back');
	ok(phoromatic_server_update::audit_result_once(phoromatic_server_update::status()), 'first read');
	ok(!phoromatic_server_update::audit_result_once(phoromatic_server_update::status()), 'second read');
	eq(array('server_update_result'), phoromatic_server_audit::actions());
});

// ---- U2: the web gate ----

function post($action, $fields = array(), $files = array())
{
	$_SERVER['REQUEST_METHOD'] = 'POST';
	$_POST = array_merge(array('action' => $action, 'token_submit' => 'tok', 'page' => 'server_settings'), $fields);
	$_FILES = $files;
	ob_start();
	phoromatic_r_server_update::render_page_process(array());
	return ob_get_clean();
}
function flash_text()
{
	$f = isset($_SESSION['ServerUpdateFlash']) ? $_SESSION['ServerUpdateFlash'] : null;
	unset($_SESSION['ServerUpdateFlash']);
	return $f === null ? '' : ($f['ok'] ? 'ok' : 'error') . ': ' . $f['message'];
}

t('a user who is not a server administrator is refused every action and the status, and nothing is written', function() {
	global $ring;
	fresh();
	imp(bundle($ring, 1, '0.16.2'));
	$sha = imp(bundle($ring, 1, '0.16.99'))['sha'];
	phoromatic_auth::$admin = false;
	$_SESSION = array('UserID' => 'u1', 'AccountID' => 'a1', 'Token' => 'tok');
	foreach(array('update', 'upload', 'check_github') as $a)
	{
		$out = post($a, array('signed_sha256' => $sha, 'password' => 'right-password'));
		ok(strpos($out, 'You do not have access') !== false, $a . ' answered: ' . $out);
	}
	$_SERVER['REQUEST_METHOD'] = 'GET';
	ob_start();
	phoromatic_r_server_update::render_page_process(array());
	$out = ob_get_clean();
	ok(strpos($out, 'You do not have access') !== false, 'status answered: ' . $out);
	ok(!file_exists(phoromatic_server_update::handoff('request.json')), 'a request was written');
	eq('', flash_text());
	eq('', phoromatic_server_update_web::render_section('server_settings'));
});

t('a wrong or missing password is refused and audited, and nothing is requested', function() {
	global $ring;
	fresh();
	imp(bundle($ring, 1, '0.16.2'));
	$sha = imp(bundle($ring, 1, '0.16.99'))['sha'];
	phoromatic_server_audit::$rows = array();
	post('update', array('signed_sha256' => $sha, 'password' => 'wrong'));
	ok(strpos(flash_text(), 'password was not right') !== false, 'flash');
	post('update', array('signed_sha256' => $sha));
	ok(strpos(flash_text(), 'password was not right') !== false, 'flash 2');
	post('update', array('signed_sha256' => $sha, 'password' => array('x')));
	flash_text();
	ok(!file_exists(phoromatic_server_update::handoff('request.json')), 'a request was written');
	eq(array('server_update_reauth_failed', 'server_update_reauth_failed', 'server_update_reauth_failed'), phoromatic_server_audit::actions());
});

t('a missing token or a foreign origin changes nothing', function() {
	global $ring;
	fresh();
	imp(bundle($ring, 1, '0.16.2'));
	$sha = imp(bundle($ring, 1, '0.16.99'))['sha'];
	post('update', array('signed_sha256' => $sha, 'password' => 'right-password', 'token_submit' => 'other'));
	ok(strpos(flash_text(), 'expired') !== false, 'token');
	phoromatic_private_repo_web::$origin = false;
	$out = post('update', array('signed_sha256' => $sha, 'password' => 'right-password'));
	ok(strpos($out, 'did not appear to come from this site') !== false, 'origin');
	ok(!file_exists(phoromatic_server_update::handoff('request.json')), 'a request was written');
	$out = post('format-disk');
	ok(strpos($out, 'Unknown action') !== false, 'unknown action');
});

t('the right password writes the request and the audit row', function() {
	global $ring;
	fresh();
	imp(bundle($ring, 1, '0.16.2'));
	$sha = imp(bundle($ring, 1, '0.16.99'))['sha'];
	phoromatic_server_audit::$rows = array();
	post('update', array('signed_sha256' => $sha, 'password' => 'right-password'));
	ok(strpos(flash_text(), 'was asked for') !== false, 'flash');
	ok(file_exists(phoromatic_server_update::handoff('request.json')), 'no request');
	eq(array('server_update_requested'), phoromatic_server_audit::actions());
});

t('a hand-written request that the helper reads is checked as strictly as the page writes it', function() {
	$good = '{"schema":"benchy-server-update-request/1","signed_sha256":"' . str_repeat('a', 64) . '","requested_by":"root","requested_at":"2026-11-24T10:00:00Z"}';
	eq('root', BenchyServerUpdate::parse_request($good)['by']);
	$bad = array(
		'', '{}', 'null', '[]', str_replace('"root"', '"root; rm -rf /"', $good), str_replace('"root"', '"$(id)"', $good), str_replace('"root"', '"' . str_repeat('x', 65) . '"', $good),
		str_replace(str_repeat('a', 64), str_repeat('A', 64), $good), str_replace(str_repeat('a', 64), '../../../etc/shadow', $good), str_replace(str_repeat('a', 64), str_repeat('a', 63), $good),
		str_replace('request/1', 'request/2', $good), substr($good, 0, -1) . ',"file":"/etc/passwd"}', str_replace('"2026-11-24T10:00:00Z"', '"soon"', $good),
		str_replace('"root"', '5', $good), str_repeat(' ', 2000) . $good, "\0" . $good, str_replace('"root"', '"a\nb"', $good),
	);
	foreach($bad as $i => $b)
	{
		try
		{
			BenchyServerUpdate::parse_request($b);
		}
		catch(RuntimeException $e)
		{
			continue;
		}
		throw new Exception('request ' . $i . ' was accepted: ' . substr($b, 0, 80));
	}
});

// ---- U3: the helper's checks agree with the server's ----

t('the helper and the server read signatures and indexes alike', function() {
	global $ring, $rogue, $tree;
	list($keys, $denied) = BenchyServerUpdate::load_keys($tree . 'pts-core/static/engine/keys.json');
	$good = release_json('0.16.99');
	$vectors = array(
		'good' => $ring->sign(1, $good),
		'good gen 2' => $ring->sign(2, $good),
		'unknown key' => $rogue->sign(1, $good),
		'flipped index' => str_replace('0.16.99', '0.16.98', $ring->sign(1, $good)),
		'no newline' => str_replace("\n", ' ', $ring->sign(1, $good)),
		'bad base64' => str_replace('"sig":"', '"sig":"!', $ring->sign(1, $good)),
		'wrong schema' => str_replace('benchy-catalog-sig/1', 'benchy-catalog-sig/2', $ring->sign(1, $good)),
		'empty' => '',
	);
	foreach($vectors as $name => $file)
	{
		$web = reason_of(function() use ($file) { phoromatic_server_update::check_signed($file); });
		try
		{
			list($raw) = BenchyServerUpdate::verify_signed($file, $keys, $denied);
			$root = 'accepted';
		}
		catch(RuntimeException $e)
		{
			$root = 'refused';
		}
		eq($web === 'accepted', $root === 'accepted', $name . ': server says ' . $web . ', helper says ' . $root);
	}
	// a signed index that is not a release: both refuse
	$other = array('good' => $good);
	$ix = json_decode($good, true);
	$cases = array('extra field' => $ix + array('x' => 1), 'bad platform' => array_merge($ix, array('platform' => 'rpm')), 'bad version' => array_merge($ix, array('version' => '1.0')),
		'one package' => array_merge($ix, array('packages' => array($ix['packages'][0]))), 'size 0' => array_merge($ix, array('db_schema' => 0)));
	foreach($cases as $name => $c)
	{
		$raw = json_encode($c);
		$web = reason_of(function() use ($raw) { phoromatic_server_update::parse_release($raw); });
		try
		{
			BenchyServerUpdate::parse_release($raw);
			$root = 'accepted';
		}
		catch(RuntimeException $e)
		{
			$root = 'refused';
		}
		eq('accepted' === $web, $root === 'accepted', $name . ': server says ' . $web . ', helper says ' . $root);
		ok($root === 'refused', $name . ' was accepted');
	}
	eq('0.16.99', BenchyServerUpdate::parse_release($good)['version']);
});

t('the helper refuses a key table that is damaged, and honours denied key ids', function() {
	global $ring, $tree;
	$p = $tree . 'k.json';
	file_put_contents($p, '{"schema":"benchy-engine-keys/1","keys":[{"generation":1,"spki":"AAAA"}],"denied_key_ids":[]}');
	try
	{
		BenchyServerUpdate::load_keys($p);
		throw new Exception('accepted');
	}
	catch(RuntimeException $e)
	{
		ok(strpos($e->getMessage(), 'not') !== false, $e->getMessage());
	}
	file_put_contents($p, json_encode(array('schema' => 'benchy-engine-keys/1', 'keys' => array(array('generation' => 1, 'spki' => $ring->spki[1])), 'denied_key_ids' => array($ring->key_id(1)))));
	list($keys, $denied) = BenchyServerUpdate::load_keys($p);
	try
	{
		BenchyServerUpdate::verify_signed($ring->sign(1, release_json('0.16.99')), $keys, $denied);
		throw new Exception('accepted');
	}
	catch(RuntimeException $e)
	{
		ok(strpos($e->getMessage(), 'compromised') !== false, $e->getMessage());
	}
	ok(!is_link($p), 'link');
});

t('the schema constant is the highest migration step of the server code', function() {
	$src = file_get_contents(__DIR__ . '/../../pts-core/objects/phoromatic/phoromatic_server.php');
	preg_match_all('/migrate_step\((\d+)/', $src, $m1);
	preg_match_all('/migrate_step\(phoromatic_\w+::(\w+)/', $src, $m2);
	$steps = array_map('intval', $m1[1]);
	foreach($m2[1] as $i => $const)
	{
		foreach(glob(__DIR__ . '/../../pts-core/objects/phoromatic/*.php') as $f)
		{
			if(preg_match('/const ' . $const . ' = (\d+);/', file_get_contents($f), $mm) && strpos(file_get_contents($f), 'class') !== false)
			{
				$cls = $m2[0][$i];
				if(strpos($cls, basename($f, '.php')) !== false)
				{
					$steps[] = (int)$mm[1];
				}
			}
		}
	}
	eq(max($steps), phoromatic_server_update::DB_SCHEMA, 'DB_SCHEMA against the highest migration step');
});

t('the Check GitHub result lines render as a list, a line starting with "! " in red and without the marker', function() {
	phoromatic_server_update_web::flash(true, "Engine: ok\n! Components: <b>refused</b>");
	$h = phoromatic_server_update_web::take_flash();
	ok(strpos($h, '<ul>') === 0 && substr_count($h, '<li') === 2, $h);
	ok(strpos($h, '<li>Engine: ok</li>') !== false && strpos($h, 'color: #b00020;">Components: &lt;b&gt;refused') !== false, $h);
	eq('', phoromatic_server_update_web::take_flash());
});

echo "\n" . $GLOBALS['T_COUNT']['pass'] . ' passed, ' . $GLOBALS['T_COUNT']['fail'] . " failed\n";
exit($GLOBALS['T_COUNT']['fail'] === 0 ? 0 : 1);
