<?php
// Bundles for the schedule tests (E4.4 C4), signed by two keys that exist only in this process's memory (nothing private is written):
//   php make_sched_bundles.php <outdir>   writes keys.json, and
//     pub1.zip  public, sequence 1: benchy/pin 1.0.0, 1.1.0, 1.2.0 and 1.3.0 (that one needs engine 0.9.0)
//     priv1.zip private, sequence 1: benchy/adm 1.0.0 and 1.1.0 (both [elevated_run]), benchy/inst 1.0.0 ([machine_install] of "Acme Suite")
//     pub2.zip  public, sequence 2: benchy/pin without 1.2.0, and 1.2.0 withdrawn ("security fix")
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
function entry_of($tier, $id, $version, $title, $over = array(), $manifest = array())
{
	$bytes = 'pack of ' . $id . ' ' . $version;
	$sha = hash('sha256', $bytes);
	$e = test_entry($id, $version, hash('sha256', 'tree' . $id . $version), $over + array('pack_sha256' => $sha, 'pack_size' => strlen($bytes), 'license_class' => $tier === 'public' ? 'free' : 'non-free'));
	$e['manifest'] = array_merge($e['manifest'], array('title' => $title), $manifest);
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
$pin = array(entry_of('public', 'benchy/pin', '1.0.0', 'Pin Bench'), entry_of('public', 'benchy/pin', '1.1.0', 'Pin Bench'), entry_of('public', 'benchy/pin', '1.2.0', 'Pin Bench'),
	entry_of('public', 'benchy/pin', '1.3.0', 'Pin Bench', array('min_engine' => '0.9.0')));
bundle($ring, 'pub1', 'public', 1, $pin);
bundle($ring, 'priv1', 'private', 1, array(
	entry_of('private', 'benchy/adm', '1.0.0', 'Admin Bench', array(), array('elevated_run' => array('reason' => 'x'))),
	entry_of('private', 'benchy/adm', '1.1.0', 'Admin Bench', array(), array('elevated_run' => array('reason' => 'x'))),
	entry_of('private', 'benchy/inst', '1.0.0', 'Installer Bench', array(), array('machine_install' => array('product' => 'Acme Suite')))));
bundle($ring, 'pub2', 'public', 2, array($pin[0], $pin[1], $pin[3]), array(revoked_entry('benchy/pin', '1.2.0', 'security fix')));
