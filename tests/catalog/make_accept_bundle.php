<?php
// Makes a bundle that should be accepted, signed by a key that exists only in this process's memory, with real packs. Writes the bundle and the
// key's PUBLIC half (base64 SPKI) to the two paths given, and prints what the PHP verifier says about it. The private half is never written.
// Used by engine_accept_parity.sh. Usage: php make_accept_bundle.php <bundle.zip> <public.spki>
define('PTS_PATH', realpath(__DIR__ . '/../..') . '/');
require __DIR__ . '/lib.php';
$ring = new test_ring(array(1));
$packs = array('benchy/one' => str_repeat("pack one\n", 700), 'benchy/two' => 'second pack');
$tests = array();
$members = array();
foreach($packs as $id => $bytes)
{
	$sha = hash('sha256', $bytes);
	$tests[] = test_entry($id, '1.0.0', hash('sha256', 'tree' . $id), array('pack_sha256' => $sha, 'pack_size' => strlen($bytes)));
	$members[] = array('name' => 'packs/' . $sha . '.zip', 'data' => $bytes, 'method' => 0);
}
$bundle = catalog_zipbuild::build(array_merge(array(array('name' => 'index.signed', 'data' => $ring->sign(1, index_json(array('tests' => $tests))))), $members));
file_put_contents($argv[1], $bundle);
file_put_contents($argv[2], $ring->spki[1]);
$d = phoromatic_catalog_verify::check_bundle($ring->trust(), phoromatic_catalog_verify::new_state(), $bundle);
echo 'php: accepted ' . $d['tier'] . ' ' . $d['next']['tiers'][$d['tier']]['generation'] . ' ' . $d['next']['tiers'][$d['tier']]['sequence'] . ' with ' . count($d['packs']) . " packs\n";
