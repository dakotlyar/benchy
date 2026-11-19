<?php
// Bundles for the catalog-suites tests (Benchy 0.10.0, CS0-CS4), signed by two keys that exist only in this process's memory (nothing private is written):
//   php make_cs_bundles.php <outdir>   writes keys.json, and
//     pub1.zip  public, sequence 1: benchy/zstd 1.0.0, benchy/redis 1.0.0, benchy/lz4 1.0.0 (all Linux)
//     priv1.zip private, sequence 1: benchy/winbench 1.0.0 (Windows), benchy/novab 1.0.0 (Windows, [machine_install]), benchy/passm 1.0.0 (Windows, [elevated_run] and [machine_install])
//     pub2.zip  public, sequence 2: pub1 plus benchy/zstd 1.1.0 (its level menu has lost "8")
//     pub3.zip  public, sequence 3: pub2 without benchy/zstd 1.0.0 (not revoked, just no longer listed)
//     pub4.zip  public, sequence 4: pub3 with benchy/redis 1.0.0 withdrawn ("bad build")
define('PTS_PATH', realpath(__DIR__ . '/../..') . '/');
require __DIR__ . '/lib.php';
$ring = new test_ring(array(1, 2));
$out = rtrim($argv[1], '/') . '/';
$keys = array();
foreach($ring->spki as $g => $s)
{
	$keys[] = array('generation' => $g, 'spki' => $s);
}
file_put_contents($out . 'keys.json', json_encode(array('schema' => 'benchy-catalog-keys/1', 'keys' => $keys, 'denied_key_ids' => array(), 'floor' => (object)array())));
function choice($key, $label, $def, $choices)
{
	$list = array();
	foreach($choices as $c)
	{
		$list[] = array('key' => $c, 'label' => $c);
	}
	return array('key' => $key, 'label' => $label, 'kind' => 'choice', 'default' => $def, 'choices' => $list);
}
function entry_of($tier, $id, $version, $title, $platforms, $options = array(), $manifest = array())
{
	$bytes = 'pack of ' . $id . ' ' . $version;
	$sha = hash('sha256', $bytes);
	$e = test_entry($id, $version, hash('sha256', 'tree' . $id . $version), array('pack_sha256' => $sha, 'pack_size' => strlen($bytes), 'license_class' => $tier === 'public' ? 'free' : 'non-free', 'platforms' => $platforms));
	$e['manifest'] = array_merge($e['manifest'], array('title' => $title, 'description' => $title . ' for the suite tests.', 'platforms' => $platforms, 'options' => $options), $manifest);
	return array($e, array('name' => 'packs/' . $sha . '.zip', 'data' => $bytes, 'method' => 0));
}
function bundle($ring, $name, $tier, $seq, $items, $revoked = array())
{
	$entries = array();
	$members = array();
	foreach($items as $i)
	{
		$entries[] = $i[0];
		$members[] = $i[1];
	}
	$index = index_json(array('tier' => $tier, 'sequence' => $seq, 'tests' => $entries, 'revoked' => $revoked));
	file_put_contents($GLOBALS['out'] . $name . '.zip', catalog_zipbuild::build(array_merge(array(array('name' => 'index.signed', 'data' => $ring->sign(1, $index))), $members)));
}
$L = array('linux');
$W = array('windows');
$zstd10 = entry_of('public', 'benchy/zstd', '1.0.0', 'Zstd Compression', $L, array(choice('level', 'Compression Level', '3', array('3', '8', '19'))), array('category' => 'Processor', 'tags' => array('compression', 'multi-thread'), 'estimated_run_s' => 120));
$zstd11 = entry_of('public', 'benchy/zstd', '1.1.0', 'Zstd Compression', $L, array(choice('level', 'Compression Level', '3', array('3', '19'))), array('min_engine' => '0.6.0', 'category' => 'cpu', 'tags' => array('compression', 'multi-thread'), 'estimated_run_s' => 120));
$redis = entry_of('public', 'benchy/redis', '1.0.0', 'Redis', $L, array(choice('test', 'Test', 'set', array('set', 'get')), choice('connections', 'Parallel Connections', '50', array('50', '500'))), array('category' => 'cpu', 'tags' => array('database', 'real-app'), 'estimated_run_s' => 400));
$lz4 = entry_of('public', 'benchy/lz4', '1.0.0', 'LZ4 Compression', $L, array(), array('tags' => array('compression', '<img src=x onerror=alert(1)>', 'compression', 'Upper', 7, 'single-thread')));
bundle($ring, 'pub1', 'public', 1, array($zstd10, $redis, $lz4));
bundle($ring, 'priv1', 'private', 1, array(
	entry_of('private', 'benchy/winbench', '1.0.0', 'Win Bench', $W, array(choice('test', 'CPU Test', 'nT', array('nT', '1T'))), array('category' => 'graphics', 'tags' => array('gaming'), 'estimated_run_s' => 1500)),
	entry_of('private', 'benchy/novab', '1.0.0', 'Novabench', $W, array(), array('machine_install' => array('product' => 'Novabench'))),
	entry_of('private', 'benchy/passm', '1.0.0', 'PassMark PerformanceTest', $W, array(), array('elevated_run' => array('reason' => 'x'), 'machine_install' => array('product' => 'PassMark PerformanceTest')))));
bundle($ring, 'pub2', 'public', 2, array($zstd10, $zstd11, $redis, $lz4));
bundle($ring, 'pub3', 'public', 3, array($zstd11, $redis, $lz4));
bundle($ring, 'pub4', 'public', 4, array($zstd11, $lz4), array(revoked_entry('benchy/redis', '1.0.0', 'bad build')));
