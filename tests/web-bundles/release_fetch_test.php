<?php
// 0.16.4 K4: Check GitHub gets the engine, components and public catalog bundles of the release the server runs (phoromatic_release_fetch). A local fixture
// server stands in for GitHub: the real one is never contacted. No database, throw-away keys. Run in a container with PHP 8.3+, openssl, zlib and curl:
//   php -d memory_limit=512M tests/web-bundles/release_fetch_test.php
error_reporting(E_ALL);
ini_set('display_errors', '1');
$tree = sys_get_temp_dir() . '/release-fetch-' . bin2hex(random_bytes(6)) . '/';
mkdir($tree . 'pts-core/static/engine', 0700, true);
mkdir($tree . 'www', 0700, true);
define('PTS_PATH', $tree);
foreach(array('phoromatic_catalog_refusal', 'phoromatic_catalog_keys', 'phoromatic_catalog_json', 'phoromatic_catalog_zip', 'phoromatic_catalog_verify', 'phoromatic_catalog_import', 'phoromatic_catalog',
	'phoromatic_catalog_status', 'phoromatic_engine_store', 'phoromatic_component_store', 'phoromatic_store_status', 'phoromatic_server_update', 'phoromatic_release_fetch') as $c)
{
	require_once __DIR__ . '/../../pts-core/objects/phoromatic/' . $c . '.php';
}
require_once __DIR__ . '/../catalog/lib.php';
class phoromatic_server
{
	public static $path = '';
	public static $db = null;
	public static function current_time() { return '2026-11-26 10:00:00'; }
	public static function phoromatic_path($create = true) { return self::$path; }
	public static function phoromatic_path_info() { return array(self::$path, 'PhoromaticStorage', null); }
	public static function storage_source_text($s) { return 'rule ' . $s; }
}
class phoromatic_server_audit
{
	public static $rows = array();
	public static function record($tenant, $type, $actor, $action, $o) { self::$rows[] = array($action, $o['detail']['via'] ?? null); }
	public static function flush() {}
	public static function actions() { return array_map(function($r) { return $r[0]; }, self::$rows); }
}

// the fixture server: serves files under www/ and logs every path asked for
file_put_contents($tree . 'router.php', '<?php $p = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH); file_put_contents(' . var_export($tree . 'requests.log', true) . ', $p . "\n", FILE_APPEND); $f = ' . var_export($tree . 'www', true) . ' . $p; if(is_file($f)) { readfile($f); } else { http_response_code(404); }');
$port = random_int(20000, 40000);
$srv = proc_open(array('php', '-S', '127.0.0.1:' . $port, $tree . 'router.php'), array(0 => array('file', '/dev/null', 'r'), 1 => array('file', '/dev/null', 'w'), 2 => array('file', '/dev/null', 'w')), $pipes);
register_shutdown_function(function() use ($tree, $srv) { proc_terminate($srv); exec('rm -rf ' . escapeshellarg($tree)); });
for($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) { usleep(100000); }
phoromatic_server_update::$api_base = 'http://127.0.0.1:' . $port;
phoromatic_server_update::$download_base = 'http://127.0.0.1:' . $port;
phoromatic_server_update::$allow_http = true;

$fx = __DIR__ . '/../engine/fixtures/';
$n = 0;
function fresh() { global $n, $tree, $fx; phoromatic_server::$path = $tree . 'store' . (++$n) . '/'; mkdir(phoromatic_server::$path, 0700, true); phoromatic_component_store::$dir_override = phoromatic_server::$path . 'components/'; phoromatic_server_audit::$rows = array(); @unlink($tree . 'requests.log'); }
function requests() { global $tree; return is_file($tree . 'requests.log') ? file($tree . 'requests.log', FILE_IGNORE_NEW_LINES) : array(); }
// the engine and the components are signed by two different keys; both are trusted here
$spki_e = trim(file_get_contents($fx . 'go-bundle.spki'));
$spki_c = trim(file_get_contents($fx . 'go-components.spki'));
// sequences of the fixture bundles: import each into a scratch store
fresh();
file_put_contents($tree . 'pts-core/static/engine/keys.json', json_encode(array('schema' => 'benchy-engine-keys/1', 'test_keys' => false, 'keys' => array(array('generation' => 1, 'spki' => $spki_e)), 'denied_key_ids' => array())));
$seq_e = phoromatic_engine_store::import($fx . 'go-bundle.zip', 'cli', 'cli', 't')['sequence'];
file_put_contents($tree . 'pts-core/static/engine/keys.json', json_encode(array('schema' => 'benchy-engine-keys/1', 'test_keys' => false, 'keys' => array(array('generation' => 1, 'spki' => $spki_c)), 'denied_key_ids' => array())));
$seq_c = phoromatic_component_store::import($fx . 'go-components-1.zip', 'cli', 'cli', 't')['sequence'];

function release($version, $assets, $files = array())
{
	global $tree, $fx;
	$dir = $tree . 'www/dakotlyar/benchy/releases/download/v' . $version . '/';
	@mkdir($dir, 0700, true);
	@mkdir($tree . 'www/repos/dakotlyar/benchy/releases/tags', 0700, true);
	$list = array();
	foreach($assets as $name => $src)
	{
		copy($src, $dir . $name);
		$list[] = array('name' => $name, 'browser_download_url' => 'http://127.0.0.1:1/never-used/' . $name);
	}
	file_put_contents($tree . 'www/repos/dakotlyar/benchy/releases/tags/v' . $version, json_encode(array('tag_name' => 'v' . $version, 'assets' => $list)));
}
function run_fetch($v = '0.16.4') { return phoromatic_release_fetch::bundles($v, 'root', 'RootAdmin', 'root login', null); }
function find($lines, $word) { foreach($lines as $l) { if(strpos($l, $word) !== false) { return $l; } } return ''; }

$eng = 'benchy-engine-bundle-' . $seq_e . '.zip';
$cmp = 'benchy-components-bundle-' . $seq_c . '.zip';
$cat = 'benchy-catalog-public-1-1.zip';

t('the release the server runs: engine and components imported, the catalog refused (no database here) while the others still import', function() {
	global $fx, $eng, $cmp, $cat, $spki_e, $spki_c, $tree;
	fresh();
	file_put_contents($tree . 'pts-core/static/engine/keys.json', json_encode(array('schema' => 'benchy-engine-keys/1', 'test_keys' => false, 'keys' => array(array('generation' => 1, 'spki' => $spki_e), array('generation' => 2, 'spki' => $spki_c)), 'denied_key_ids' => array())));
	release('0.16.4', array($eng => $fx . 'go-bundle.zip', $cmp => $fx . 'go-components-1.zip', $cat => $fx . 'go-bundle.zip'));
	$l = run_fetch();
	ok(strpos(find($l, 'Engine'), 'Imported engine') !== false, implode(' | ', $l));
	ok(strpos(find($l, 'Components'), 'Imported') !== false, implode(' | ', $l));
	ok(strpos(find($l, 'Public catalog'), '! ') === 0, implode(' | ', $l));
	ok(in_array(array('engine_imported', 'github'), array_map(function($r) { return array($r[0], $r[1]); }, phoromatic_server_audit::$rows), true), 'no engine_imported row via github: ' . json_encode(phoromatic_server_audit::$rows));
	ok(!in_array('/never-used/' . $eng, requests(), true), 'browser_download_url was used');
	eq('/repos/dakotlyar/benchy/releases/tags/v0.16.4', requests()[0]);
});

t('the same again: engine and components say already offered, nothing is imported twice', function() {
	global $eng, $cmp;
	$l = run_fetch();
	ok(strpos(find($l, 'Engine'), 'already offered') !== false && strpos(find($l, 'Engine'), '! ') !== 0, implode(' | ', $l));
	ok(strpos(find($l, 'Components'), 'already offered') !== false, implode(' | ', $l));
});

t('a held sequence at or above the name is not downloaded at all', function() {
	global $fx, $eng, $cmp;
	fresh();
	release('0.16.4', array($eng => $fx . 'go-bundle.zip'));
	run_fetch();
	@unlink($GLOBALS['tree'] . 'requests.log');
	$l = run_fetch();
	ok(strpos(find($l, 'Engine'), 'already offered') !== false, implode(' | ', $l));
	$r = implode(' ', requests());
	ok(strpos($r, '/download/') === false, 'asked for ' . $r);
});

t('an asset missing from the release says so, the rest carry on', function() {
	global $fx, $eng;
	fresh();
	release('0.16.4', array($eng => $fx . 'go-bundle.zip'));
	$l = run_fetch();
	ok(strpos(find($l, 'Components'), 'not on the 0.16.4 release') !== false, implode(' | ', $l));
	ok(strpos(find($l, 'Public catalog'), 'not on the 0.16.4 release') !== false, implode(' | ', $l));
	ok(strpos(find($l, 'Engine'), 'Imported engine') !== false, implode(' | ', $l));
});

t('two files for one store: that store is skipped and says so', function() {
	global $fx, $seq_e;
	fresh();
	release('0.16.4', array('benchy-engine-bundle-' . $seq_e . '.zip' => $fx . 'go-bundle.zip', 'benchy-engine-bundle-' . ($seq_e + 1) . '.zip' => $fx . 'go-bundle.zip'));
	$l = run_fetch();
	ok(strpos(find($l, 'Engine'), '! ') === 0 && strpos(find($l, 'Engine'), 'more than one') !== false, implode(' | ', $l));
	ok(!array_filter(requests(), function($p) { return strpos($p, '/download/') !== false; }), 'a file was fetched');
});

t('a name that does not match the exact pattern is never fetched', function() {
	global $fx, $seq_e;
	fresh();
	release('0.16.4', array('benchy-engine-bundle-' . $seq_e . '.zip.evil' => $fx . 'go-bundle.zip', 'benchy-engine-bundle-x.zip' => $fx . 'go-bundle.zip', 'xbenchy-engine-bundle-' . $seq_e . '.zip' => $fx . 'go-bundle.zip', 'benchy-engine-bundle-' . $seq_e . ".zip\n" => $fx . 'go-bundle.zip'));
	$l = run_fetch();
	ok(strpos(find($l, 'Engine'), 'not on the 0.16.4 release') !== false, implode(' | ', $l));
	ok(!array_filter(requests(), function($p) { return strpos($p, '/download/') !== false; }), 'a file was fetched');
});

t('only the running version\'s release is read: a newer release is never touched', function() {
	global $fx, $eng, $cmp;
	fresh();
	release('0.16.4', array($eng => $fx . 'go-bundle.zip'));
	release('0.16.5', array('benchy-engine-bundle-99.zip' => $fx . 'go-bundle.zip'));
	run_fetch();
	ok(count(requests()) >= 1, 'no request was made');
	foreach(requests() as $p) { ok(strpos($p, '0.16.5') === false, 'asked for ' . $p); }
});

t('GitHub has no such release: one red line, nothing imported', function() {
	fresh();
	$l = run_fetch('0.99.0');
	eq(1, count($l));
	ok(strpos($l[0], '! ') === 0 && strpos($l[0], '0.99.0') !== false, $l[0]);
});

t('a file GitHub serves that the import refuses shows red with the import\'s text, and the next store still runs', function() {
	global $fx, $eng, $cmp;
	fresh();
	release('0.16.4', array($eng => $fx . 'go-components-1.zip', $cmp => $fx . 'go-components-1.zip'));
	$l = run_fetch();
	ok(strpos(find($l, 'Engine'), '! ') === 0, implode(' | ', $l));
	ok(find($l, 'Components') !== '', 'components line missing');
});

t('assets that is not a list: one red line, not three "not on the release" lines', function() {
	global $tree;
	fresh();
	@mkdir($tree . 'www/repos/dakotlyar/benchy/releases/tags', 0700, true);
	file_put_contents($tree . 'www/repos/dakotlyar/benchy/releases/tags/v0.16.6', json_encode(array('assets' => 'x')));
	$l = run_fetch('0.16.6');
	eq(1, count($l));
	ok(strpos($l[0], '! ') === 0, $l[0]);
});

t('the gaming catalog line is there', function() {
	global $fx, $eng;
	fresh();
	release('0.16.4', array($eng => $fx . 'go-bundle.zip'));
	ok(strpos(implode(' | ', run_fetch()), 'gaming catalog is never on GitHub') !== false, 'missing');
});

t('a GitHub fetch only imports the public tier (public_only guard), a package one is unaffected', function() {
	$src = file_get_contents(__DIR__ . '/../../pts-core/objects/phoromatic/phoromatic_release_fetch.php');
	ok(preg_match('/catalog_import::run\(.*, true\)/', $src) === 1, 'run() is not given public_only');
	$src = file_get_contents(__DIR__ . '/../../pts-core/objects/phoromatic/phoromatic_catalog_import.php');
	ok(strpos($src, "\$public_only && \$tier !== 'public'") !== false, 'no guard');
});

echo 'FAILURES ' . $GLOBALS['T_COUNT']['fail'] . ' of ' . ($GLOBALS['T_COUNT']['fail'] + $GLOBALS['T_COUNT']['pass']) . "\n";
exit($GLOBALS['T_COUNT']['fail'] ? 1 : 0);
