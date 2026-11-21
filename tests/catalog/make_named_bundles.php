<?php
// Bundles for the named catalog tests (NC3), signed by two keys that exist only in this process's memory (nothing private is written):
//   php make_named_bundles.php <outdir>   writes keys.json, and
//     pub1.zip    public, sequence 1: benchy/one, benchy/two (free)
//     priv1.zip   private, sequence 1: benchy/paid, benchy/pshared (non-free)
//     game1.zip   gaming, sequence 1, title "Games": benchy/game (free), benchy/gshared (non-free)
//     extras1.zip extras, sequence 1, no title: benchy/extra
// benchy/pshared (private) and benchy/gshared (gaming) name the same pack file, so a pack shared by two catalogs is served to a tenant that has either one.
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
function item($id, $license, $pack_of = null)
{
	$bytes = 'pack of ' . ($pack_of === null ? $id : $pack_of);
	$sha = hash('sha256', $bytes);
	$e = test_entry($id, '1.0.0', hash('sha256', 'tree' . $id), array('pack_sha256' => $sha, 'pack_size' => strlen($bytes), 'license_class' => $license, 'platforms' => array('linux', 'windows')));
	$e['manifest']['title'] = ucfirst(substr($id, 6)) . ' Bench';
	$e['manifest']['platforms'] = array('linux', 'windows');
	return array($e, array('name' => 'packs/' . $sha . '.zip', 'data' => $bytes, 'method' => 0));
}
function bundle($ring, $name, $tier, $seq, $items, $title = null)
{
	$entries = array();
	$members = array();
	foreach($items as $i)
	{
		$entries[] = $i[0];
		$members[$i[1]['name']] = $i[1];
	}
	$over = array('tier' => $tier, 'sequence' => $seq, 'tests' => $entries);
	if($title !== null)
	{
		$over['title'] = $title;
	}
	file_put_contents($GLOBALS['out'] . $name . '.zip', catalog_zipbuild::build(array_merge(array(array('name' => 'index.signed', 'data' => $ring->sign(1, index_json($over)))), array_values($members))));
}
bundle($ring, 'pub1', 'public', 1, array(item('benchy/one', 'free'), item('benchy/two', 'free')));
bundle($ring, 'priv1', 'private', 1, array(item('benchy/paid', 'non-free'), item('benchy/pshared', 'non-free', 'shared')));
bundle($ring, 'game1', 'gaming', 1, array(item('benchy/game', 'free'), item('benchy/gshared', 'non-free', 'shared')), 'Games');
bundle($ring, 'extras1', 'extras', 1, array(item('benchy/extra', 'non-free')));
