<?php
// 0.16.3: an import checks that the store offers what it just stored (not_served), and the status line says so in words. No server, no database, no network;
// throw-away keys. Run in a container with PHP 8.3+, openssl and zlib:
//   php -d memory_limit=512M tests/web-bundles/served_check_test.php
error_reporting(E_ALL);
ini_set('display_errors', '1');
$tree = sys_get_temp_dir() . '/web-bundles-' . bin2hex(random_bytes(6)) . '/';
mkdir($tree . 'pts-core/static/engine', 0700, true);
define('PTS_PATH', $tree);
register_shutdown_function(function() use ($tree) { exec('rm -rf ' . escapeshellarg($tree)); });
foreach(array('phoromatic_catalog_refusal', 'phoromatic_catalog_keys', 'phoromatic_catalog_json', 'phoromatic_catalog_zip', 'phoromatic_catalog_verify', 'phoromatic_catalog_import',
	'phoromatic_engine_store', 'phoromatic_component_store', 'phoromatic_store_status') as $c)
{
	require_once __DIR__ . '/../../pts-core/objects/phoromatic/' . $c . '.php';
}
require_once __DIR__ . '/../catalog/lib.php';
class phoromatic_server
{
	public static $path = '';
	public static function current_time() { return '2026-11-25 10:00:00'; }
	public static function phoromatic_path($create = true) { return self::$path; }
	public static function phoromatic_path_info() { return array(self::$path, 'PhoromaticStorage', null); }
	public static function storage_source_text($s) { return 'rule ' . $s; }
}
class phoromatic_server_audit
{
	public static function record($tenant, $type, $actor, $action, $o) {}
}
function keys($spki) { global $tree; file_put_contents($tree . 'pts-core/static/engine/keys.json', json_encode(array('schema' => 'benchy-engine-keys/1', 'test_keys' => false, 'keys' => array(array('generation' => 1, 'spki' => $spki)), 'denied_key_ids' => array()))); }
$n = 0;
function fresh() { global $n, $tree; phoromatic_server::$path = $tree . 'store' . (++$n) . '/'; mkdir(phoromatic_server::$path, 0700, true); phoromatic_component_store::$dir_override = phoromatic_server::$path . 'components/'; }
function refusal_of($fn) { try { $fn(); } catch(phoromatic_catalog_refusal $r) { return $r; } return null; }
function damage($class)
{
	// the state names a file the index does not, and it is missing: re-importing the same bundle puts back only what the index names
	$p = $class::dir('state.json');
	$s = json_decode(file_get_contents($p), true);
	$s['files'][str_repeat('a', 64)] = 10;
	file_put_contents($p, json_encode($s));
}

t('engine: an import is served, a repeat over a state that is not offered ends not_served with the folder and the words, and the status line says NOT offered', function() {
	fresh();
	keys(trim(file_get_contents(__DIR__ . '/../engine/fixtures/go-bundle.spki')));
	$b = __DIR__ . '/../engine/fixtures/go-bundle.zip';
	eq('imported', phoromatic_engine_store::import($b, 'cli', 'cli', 't')['status']);
	ok(strpos(phoromatic_store_status::line('Engine', 'phoromatic_engine_store', 'engine'), 'offered to machines.') !== false, 'offered line');
	damage('phoromatic_engine_store');
	$r = refusal_of(function() use ($b) { phoromatic_engine_store::import($b, 'cli', 'cli', 't'); });
	ok($r !== null, 'was not refused');
	eq('not_served', $r->reason);
	ok(strpos($r->getMessage(), phoromatic_engine_store::dir()) !== false && strpos($r->getMessage(), 'missing a file') !== false, $r->getMessage());
	$l = phoromatic_store_status::line('Engine', 'phoromatic_engine_store', 'engine');
	ok(strpos($l, 'NOT offered') !== false && strpos($l, 'missing a file') !== false, $l);
});

t('components: the same', function() {
	fresh();
	keys(trim(file_get_contents(__DIR__ . '/../engine/fixtures/go-components.spki')));
	$b = __DIR__ . '/../engine/fixtures/go-components-1.zip';
	eq('imported', phoromatic_component_store::import($b, 'cli', 'cli', 't')['status']);
	damage('phoromatic_component_store');
	$r = refusal_of(function() use ($b) { phoromatic_component_store::import($b, 'cli', 'cli', 't'); });
	ok($r !== null && $r->reason === 'not_served', 'got ' . ($r ? $r->reason : 'accepted'));
});

t('nothing imported: the line says so and is not red', function() {
	fresh();
	$l = phoromatic_store_status::line('Engine', 'phoromatic_engine_store', 'engine');
	ok(strpos($l, 'nothing imported') !== false && strpos($l, 'NOT') === false, $l);
});

echo 'FAILURES ' . $GLOBALS['T_COUNT']['fail'] . ' of ' . ($GLOBALS['T_COUNT']['fail'] + $GLOBALS['T_COUNT']['pass']) . "\n";
exit($GLOBALS['T_COUNT']['fail'] ? 1 : 0);
