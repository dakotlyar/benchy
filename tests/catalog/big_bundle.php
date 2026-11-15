<?php
// A bundle of about 126 MiB (two packs of 63 MiB, the biggest a bundle can hold) through the whole verifier, under the memory_limit the import
// raises itself to (512M). Key in memory only. Prints the peak. Run: php -d memory_limit=512M tests/catalog/big_bundle.php
error_reporting(E_ALL);
define('PTS_PATH', realpath(__DIR__ . '/../..') . '/');
require __DIR__ . '/lib.php';
$ring = new test_ring(array(1));
$packs = array();
$members = array();
$tests = array();
foreach(array('a', 'b') as $i => $c)
{
	$bytes = '';
	for($n = 0; $n < 63 * 16; $n++)
	{
		$bytes .= str_repeat(chr(ord($c) + ($n % 7)), 65536) ; // 64 KiB blocks, so the whole is 63 MiB
	}
	$bytes = substr($bytes, 0, 63 * 1048576);
	$sha = hash('sha256', $bytes);
	$tests[] = test_entry('benchy/big' . $c, '1.0.0', hash('sha256', 'tree' . $c), array('pack_sha256' => $sha, 'pack_size' => strlen($bytes)));
	$members[] = array('name' => 'packs/' . $sha . '.zip', 'data' => $bytes, 'method' => 0);
	unset($bytes);
}
$bundle = catalog_zipbuild::build(array_merge(array(array('name' => 'index.signed', 'data' => $ring->sign(1, index_json(array('tests' => $tests))))), $members));
unset($members);
echo 'bundle ' . strlen($bundle) . " bytes\n";
$bytes_seen = 0;
$d = phoromatic_catalog_verify::check_bundle($ring->trust(), phoromatic_catalog_verify::new_state(), $bundle, null, null, function($n, $c) use (&$bytes_seen) { $bytes_seen += strlen($c); });
if($bytes_seen !== 126 * 1048576 || count($d['packs']) !== 2)
{
	echo "FAIL: saw $bytes_seen bytes, " . count($d['packs']) . " packs\n";
	exit(1);
}
echo 'ok, peak ' . round(memory_get_peak_usage(true) / 1048576) . " MiB\n";
