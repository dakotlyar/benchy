<?php
// Makes the bundles and the key table the E4.4 rollback check needs, signed by two keys that exist only in this process's memory (nothing private is written):
//   php make_rollback_bundles.php <outdir>   writes keys.json (the public halves), a.zip (public, sequence 1), b.zip (private, sequence 1), c.zip (public, sequence 2)
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
function bundle($ring, $name, $tier, $seq, $ids)
{
	$entries = array();
	$members = array();
	foreach($ids as $id)
	{
		$bytes = 'pack of ' . $id;
		$sha = hash('sha256', $bytes);
		$entries[] = test_entry($id, '1.0.0', hash('sha256', 'tree' . $id), array('pack_sha256' => $sha, 'pack_size' => strlen($bytes), 'license_class' => $tier === 'public' ? 'free' : 'non-free'));
		$members[] = array('name' => 'packs/' . $sha . '.zip', 'data' => $bytes, 'method' => 0);
	}
	$index = index_json(array('tier' => $tier, 'sequence' => $seq, 'tests' => $entries));
	file_put_contents($GLOBALS['out'] . $name . '.zip', catalog_zipbuild::build(array_merge(array(array('name' => 'index.signed', 'data' => $ring->sign(1, $index))), $members)));
}
bundle($ring, 'a', 'public', 1, array('benchy/one', 'benchy/two'));
bundle($ring, 'b', 'private', 1, array('benchy/paid'));
bundle($ring, 'c', 'public', 2, array('benchy/one', 'benchy/two', 'benchy/three'));
