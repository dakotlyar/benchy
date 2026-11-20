<?php
// The server's component store (runtime component updates, R1): the Go tool's own partial bundles are accepted as the engine accepts them, and every way a
// bundle can be wrong is refused with nothing stored. No server, no database, no network; throw-away keys made here and never kept. Run:
//   php -d memory_limit=512M tests/engine/components_store_test.php
// (in a container with PHP 8.3+, openssl and zlib; see tests/engine/README for the line)
error_reporting(E_ALL);
ini_set('display_errors', '1');
$tree = sys_get_temp_dir() . '/components-store-' . bin2hex(random_bytes(6)) . '/';
mkdir($tree . 'pts-core/static/engine', 0700, true);
define('PTS_PATH', $tree);
register_shutdown_function(function() use ($tree) { exec('rm -rf ' . escapeshellarg($tree)); });
foreach(array('phoromatic_catalog_refusal', 'phoromatic_catalog_keys', 'phoromatic_catalog_json', 'phoromatic_catalog_zip', 'phoromatic_catalog_verify', 'phoromatic_catalog_import', 'phoromatic_component_store') as $c)
{
	require_once __DIR__ . '/../../pts-core/objects/phoromatic/' . $c . '.php';
}
require_once __DIR__ . '/../catalog/lib.php';

// the two things the store takes from the rest of the server
class phoromatic_server
{
	public static function current_time() { return '2026-11-24 10:00:00'; }
}
class phoromatic_server_audit
{
	public static $rows = array();
	public static function record($tenant, $type, $actor, $action, $o) { self::$rows[] = array($action, $o); }
}

$go = trim(file_get_contents(__DIR__ . '/fixtures/go-components.spki'));
$ring = new test_ring(array(2));
file_put_contents($tree . 'pts-core/static/engine/keys.json', json_encode(array('schema' => 'benchy-engine-keys/1', 'test_keys' => false,
	'keys' => array(array('generation' => 1, 'spki' => $go), array('generation' => 2, 'spki' => $ring->spki[2])), 'denied_key_ids' => array())));

$n = 0;
function fresh()
{
	global $n, $tree;
	phoromatic_component_store::$dir_override = $tree . 'store' . (++$n) . '/';
	phoromatic_server_audit::$rows = array();
}
function imp($zip_bytes)
{
	global $tree;
	$p = $tree . 'b' . mt_rand() . '.zip';
	file_put_contents($p, $zip_bytes);
	return phoromatic_component_store::import($p, 'cli', 'cli', 'tester');
}
function refused($zip_bytes, $reason)
{
	try
	{
		imp($zip_bytes);
	}
	catch(phoromatic_catalog_refusal $r)
	{
		return $r->reason === $reason ? true : 'refused as ' . $r->reason . ' (' . $r->getMessage() . '), wanted ' . $reason;
	}
	return 'was accepted, wanted ' . $reason;
}
function stored_files()
{
	$f = array();
	foreach(array('files/', 'signed/') as $d)
	{
		foreach(scandir(phoromatic_component_store::dir($d)) ?: array() as $x)
		{
			if($x[0] !== '.')
			{
				$f[] = $d . $x;
			}
		}
	}
	return $f;
}
function expect($got)
{
	if($got !== true)
	{
		throw new Exception((string)$got);
	}
}

// ---- building bundles with the faults a case asks for ----
function rel($version, $file_bytes, $name, $o = array())
{
	return array_merge(array('version' => $version, 'artifact' => array('file' => $name, 'size' => strlen($file_bytes), 'sha256' => hash('sha256', $file_bytes)), 'notes' => 'n'), $o);
}
function tr($name, $rel) { return (object)array($name => $rel); }   // (a track named 0 would encode as a list)
function index($seq, $comps, $o = array())
{
	return json_encode(array_merge(array('schema' => 'benchy-component-index/1', 'sequence' => $seq, 'issued' => '2026-11-24T15:00:00Z', 'min_generation' => null, 'platform' => 'windows-x64', 'components' => $comps), $o), JSON_UNESCAPED_SLASHES);
}
function bundle($ring, $json, $files, $o = array())
{
	$signed = $ring->sign(2, $json);
	if(isset($o['flip']))
	{
		$signed[strlen($signed) - $o['flip']] = $signed[strlen($signed) - $o['flip']] === 'a' ? 'b' : 'a';
	}
	$entries = array(array('name' => 'components.signed', 'data' => $signed));
	foreach($files as $name => $bytes)
	{
		$entries[] = array('name' => $name, 'data' => $bytes);
	}
	return catalog_zipbuild::build($entries);
}
$PHP13 = 'php zip 8.5.13';
$PHP14 = 'php zip 8.5.14';
$CLIENT = 'client zip 0.15.0';
$c_php13 = array('php' => array('tracks' => tr('8.5', rel('8.5.13', $PHP13, 'php-8.5.13.zip')), 'default_track' => '8.5'));
$c_client = array('client' => array('tracks' => tr('0', rel('0.15.0', $CLIENT, 'client-0.15.0.zip')), 'default_track' => '0'));
$both13 = array_merge($c_php13, $c_client);
$both14 = array('php' => array('tracks' => tr('8.5', rel('8.5.14', $PHP14, 'php-8.5.14.zip')), 'default_track' => '8.5')) + $c_client;

t('the Go tool\'s first bundle is accepted, served and listed', function() {
	fresh();
	$o = imp(file_get_contents(__DIR__ . '/fixtures/go-components-1.zip'));
	eq('imported', $o['status']);
	eq(array('client 0.15.0', 'php 8.5.13-1'), $o['changed']);
	$c = phoromatic_component_store::current($why);
	ok($c !== null, 'current() says ' . $why);
	eq(array('vcredist' => '14.44'), $c['components']['php']['tracks']['8.5']['requires']);
	$rows = phoromatic_component_store::rows();
	eq(array('php', 'client'), array_map(function($r) { return $r['name']; }, $rows));
	eq('8.5.13-1', $rows[0]['tracks']['8.5']['version']);
	eq(3, count(stored_files()));
	foreach($c['files'] as $sha => $size)
	{
		eq($size, phoromatic_component_store::served_size($sha));
	}
	eq(null, phoromatic_component_store::served_size(str_repeat('0', 64)));
});

t('the Go tool\'s second, partial bundle moves PHP and keeps the client already held', function() {
	fresh();
	imp(file_get_contents(__DIR__ . '/fixtures/go-components-1.zip'));
	$o = imp(file_get_contents(__DIR__ . '/fixtures/go-components-2.zip'));
	eq(array('php 8.5.14'), $o['changed']);
	$c = phoromatic_component_store::current($why);
	ok($c !== null, 'current() says ' . $why);
	eq('8.5.14', $c['components']['php']['tracks']['8.5']['version']);
	eq('0.15.0', $c['components']['client']['tracks']['0']['version']);
	eq(array('8.5.13-1'), $c['floors']['php']['revoked']);
	eq(2, $c['sequence']);
	eq(3, count(stored_files()), 'the old PHP file was collected');
});

t('the partial bundle alone, on a server that never held the client, is refused as missing', function() {
	fresh();
	expect(refused(file_get_contents(__DIR__ . '/fixtures/go-components-2.zip'), 'bundle_pack_missing'));
	eq(array(), stored_files());
});

t('the same bundle again changes nothing; an older one is a rollback', function() {
	fresh();
	imp(file_get_contents(__DIR__ . '/fixtures/go-components-1.zip'));
	imp(file_get_contents(__DIR__ . '/fixtures/go-components-2.zip'));
	eq('unchanged', imp(file_get_contents(__DIR__ . '/fixtures/go-components-2.zip'))['status']);
	expect(refused(file_get_contents(__DIR__ . '/fixtures/go-components-1.zip'), 'rollback'));
});

t('a tampered signature and a key the table does not hold are refused before the index is read', function() use ($ring, $both13, $PHP13, $CLIENT) {
	fresh();
	expect(refused(bundle($ring, index(1, $both13), array('php-8.5.13.zip' => $PHP13, 'client-0.15.0.zip' => $CLIENT), array('flip' => 40)), 'bad_signature'));
	$rogue = new test_ring(array(2));
	expect(refused(bundle($rogue, index(1, $both13), array('php-8.5.13.zip' => $PHP13, 'client-0.15.0.zip' => $CLIENT)), 'unknown_key'));
	eq(array(), stored_files());
});

t('an engine index signed by the same key is not a component index', function() use ($ring, $PHP13) {
	fresh();
	$j = json_encode(array('schema' => 'benchy-engine-index/1', 'sequence' => 1, 'issued' => '2026-11-24T15:00:00Z', 'release' => array('version' => '0.12.0', 'contract_protocol' => 1, 'agent_protocol' => 2, 'artifacts' => array('linux-x64' => array())), 'revoked' => array()));
	expect(refused(bundle($ring, $j, array()), 'bad_index'));
});

t('files: a hash that does not match, one the index does not name, one missing, an unsafe name', function() use ($ring, $both13, $PHP13, $CLIENT) {
	fresh();
	expect(refused(bundle($ring, index(1, $both13), array('php-8.5.13.zip' => strtoupper($PHP13), 'client-0.15.0.zip' => $CLIENT)), 'bundle_pack_hash'));
	expect(refused(bundle($ring, index(1, $both13), array('php-8.5.13.zip' => $PHP13, 'client-0.15.0.zip' => $CLIENT, 'extra.zip' => 'x')), 'bundle_pack_unlisted'));
	expect(refused(bundle($ring, index(1, $both13), array('php-8.5.13.zip' => $PHP13)), 'bundle_pack_missing'));
	expect(refused(bundle($ring, index(1, $both13), array('php-8.5.13.zip' => $PHP13, 'client-0.15.0.zip' => $CLIENT, '../evil.zip' => 'x')), 'pack_path'));
	expect(refused(bundle($ring, index(1, $both13), array('php-8.5.13.zip' => $PHP13, 'client-0.15.0.zip' => $CLIENT, 'sub/evil.zip' => 'x')), 'pack_path'));
	eq(array(), stored_files());
});

t('the index: a path in a file name, a bad version, both tracks and a flat release, a floor above the release, case-folded keys', function() use ($ring, $PHP13) {
	fresh();
	foreach(array(
		array('../php.zip', 'bad_index'), array('a/b.zip', 'bad_index'), array('php.zip ', 'bad_index'),
	) as $c)
	{
		$comps = array('php' => array('tracks' => tr('8.5', rel('8.5.13', $PHP13, $c[0])), 'default_track' => '8.5'));
		expect(refused(bundle($ring, index(1, $comps), array()), $c[1]));
	}
	$bad = array(
		array('php' => array('tracks' => tr('8.5', rel('8', $PHP13, 'p.zip')), 'default_track' => '8.5')),
		array('php' => array('tracks' => tr('8.5', rel('8.5.13', $PHP13, 'p.zip')), 'default_track' => '9.9')),
		array('php' => array('tracks' => tr('8.5', rel('8.5.13', $PHP13, 'p.zip')), 'default_track' => '8.5', 'floor' => '8.5.14')),
		array('php' => array('tracks' => tr('8.5', rel('8.5.13', $PHP13, 'p.zip')), 'default_track' => '8.5', 'revoked' => array(array('version' => '8.5.13', 'reason' => 'x')))),
		array('php' => array('tracks' => tr('8.5', rel('8.5.13', $PHP13, 'p.zip')), 'default_track' => '8.5') + rel('8.5.13', $PHP13, 'q.zip')),
		array('php' => array('tracks' => tr('../x', rel('8.5.13', $PHP13, 'p.zip')), 'default_track' => '../x')),
	);
	foreach($bad as $i => $comps)
	{
		expect(refused(bundle($ring, index(1, $comps), array('p.zip' => $PHP13)), 'bad_index') === true ? true : 'case ' . $i . ' ' . refused(bundle($ring, index(1, $comps), array('p.zip' => $PHP13)), 'bad_index'));
	}
	expect(refused(bundle($ring, preg_replace('/"sequence"/', '"Sequence"', index(1, array('php' => array('tracks' => tr('8.5', rel('8.5.13', $PHP13, 'p.zip')), 'default_track' => '8.5')), array()), 1), array('p.zip' => $PHP13)), 'bad_index'));
	expect(refused(bundle($ring, preg_replace('/"artifact"/', '"Artifact"', index(1, array('php' => array('tracks' => tr('8.5', rel('8.5.13', $PHP13, 'p.zip')), 'default_track' => '8.5'))), 1), array('p.zip' => $PHP13)), 'bad_index'));
	eq(array(), stored_files(), 'something was stored');
});

t('forward only: nothing dropped, no version down, no version replaced, revocations and floors kept', function() use ($ring, $both13, $both14, $c_php13, $c_client, $PHP13, $PHP14, $CLIENT) {
	fresh();
	$f13 = array('php-8.5.13.zip' => $PHP13, 'client-0.15.0.zip' => $CLIENT);
	imp(bundle($ring, index(1, $both13), $f13));
	expect(refused(bundle($ring, index(2, $c_php13), array()), 'bad_index'));                                                              // the client is dropped
	$down = array('php' => array('tracks' => tr('8.5', rel('8.5.12', 'php zip 8.5.12', 'php-8.5.12.zip')), 'default_track' => '8.5')) + $c_client;
	expect(refused(bundle($ring, index(2, $down), array('php-8.5.12.zip' => 'php zip 8.5.12')), 'bad_index'));                             // 8.5.12 after 8.5.13
	$swap = array('php' => array('tracks' => tr('8.5', rel('8.5.13', 'other bytes!', 'php-8.5.13b.zip')), 'default_track' => '8.5')) + $c_client;
	expect(refused(bundle($ring, index(2, $swap), array('php-8.5.13b.zip' => 'other bytes!')), 'bad_index'));                              // 8.5.13 with other bytes
	$f14 = array('php-8.5.14.zip' => $PHP14);
	$with_rev = $both14;
	$with_rev['php']['revoked'] = array(array('version' => '8.5.12-1', 'reason' => 'CVE'));
	$with_rev['php']['floor'] = '8.5.12';
	imp(bundle($ring, index(2, $with_rev), $f14));
	expect(refused(bundle($ring, index(3, $both14), array()), 'revocation_dropped'));                                                       // the revocation is gone
	$low = $with_rev;
	$low['php']['floor'] = '8.5.10';
	expect(refused(bundle($ring, index(3, $low), array()), 'floor'));                                                                       // the floor falls
	$c = phoromatic_component_store::current($why);
	ok($c !== null && $c['sequence'] === 2, 'the stored index stayed at 2 (' . $why . ')');
});

t('same generation and sequence with other bytes is refused; the standby key retires the first', function() use ($ring, $both13, $PHP13, $CLIENT) {
	fresh();
	$f13 = array('php-8.5.13.zip' => $PHP13, 'client-0.15.0.zip' => $CLIENT);
	imp(bundle($ring, index(1, $both13), $f13));
	expect(refused(bundle($ring, index(1, $both13, array('issued' => '2026-11-25T15:00:00Z')), $f13), 'same_pair_differs'));
	eq('imported', imp(bundle($ring, index(2, $both13, array('min_generation' => 2)), $f13))['status']);
	$c = phoromatic_component_store::current($why);
	eq(2, $c['retired_below']);
});

t('a file that goes missing after an import stops everything being offered, and a partial bundle cannot cover for it', function() use ($ring, $both13, $both14, $PHP13, $PHP14, $CLIENT) {
	fresh();
	imp(bundle($ring, index(1, $both13), array('php-8.5.13.zip' => $PHP13, 'client-0.15.0.zip' => $CLIENT)));
	$c = phoromatic_component_store::current();
	foreach($c['files'] as $sha => $size)
	{
		if($size === strlen($CLIENT))
		{
			unlink(phoromatic_component_store::file_path($sha));
		}
	}
	eq(null, phoromatic_component_store::current($why));
	eq('file_missing', $why);
	expect(refused(bundle($ring, index(2, $both14), array('php-8.5.14.zip' => $PHP14)), 'bundle_pack_missing'));
	eq('imported', imp(bundle($ring, index(2, $both14), array('php-8.5.14.zip' => $PHP14, 'client-0.15.0.zip' => $CLIENT)))['status']);
	ok(phoromatic_component_store::current($why) !== null, 'current() says ' . $why);
});

t('a corrupt state file fails closed', function() use ($ring, $both13, $PHP13, $CLIENT) {
	fresh();
	imp(bundle($ring, index(1, $both13), array('php-8.5.13.zip' => $PHP13, 'client-0.15.0.zip' => $CLIENT)));
	file_put_contents(phoromatic_component_store::dir('state.json'), '{garbage');
	eq(null, phoromatic_component_store::current($why));
	eq('state_corrupt', $why);
});

t('every refusal leaves an audit row and every import one too', function() use ($ring, $both13, $PHP13, $CLIENT) {
	fresh();
	refused('not a zip', 'zip_structure');
	imp(bundle($ring, index(1, $both13), array('php-8.5.13.zip' => $PHP13, 'client-0.15.0.zip' => $CLIENT)));
	eq(array('components_import_refused', 'components_imported'), array_map(function($r) { return $r[0]; }, phoromatic_server_audit::$rows));
});

t('versions compare as the engine compares them', function() {
	eq(-1, phoromatic_component_store::compare('8.5.12', '8.5.12-1'));
	eq(1, phoromatic_component_store::compare('8.5.13', '8.5.12-9'));
	eq(0, phoromatic_component_store::compare('14.44', '14.44.0.0'));
	eq(-1, phoromatic_component_store::compare('2026.11.24-1', '2026.11.24-2'));
	foreach(array('8', '8.5.x', '1.2.3.4.5', '08.5', '8.5.12-', '8.5.12-1-2', '') as $v)
	{
		ok(!phoromatic_component_store::good_version($v), $v . ' counted as a version');
	}
});

echo "\n" . $GLOBALS['T_COUNT']['pass'] . ' passed, ' . $GLOBALS['T_COUNT']['fail'] . " failed\n";
exit($GLOBALS['T_COUNT']['fail'] === 0 ? 0 : 1);
