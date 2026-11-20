<?php
// Makes the engine bundles of the engine-update server tests, signed by throw-away keys made here (in memory; no private key is written, committed or kept):
//   php tests/engine/make_bundles.php OUTDIR
// Writes keys.json (the table of those keys, for the test copy of the tree) and the bundles. Every archive is a few bytes of text: the server checks names, sizes
// and hashes, never what is inside, so nothing here needs a real engine build.
error_reporting(E_ALL);
ini_set('display_errors', '1');
define('PTS_PATH', realpath(__DIR__ . '/../..') . '/');
require_once __DIR__ . '/../catalog/lib.php';

$out = rtrim($argv[1], '/') . '/';
$ring = new test_ring(array(1, 2));
$rogue = new test_ring(array(1));

function archive_name($version, $plat)
{
	return 'benchy-engine-' . $version . '-' . $plat . ($plat === 'windows-x64' ? '.zip' : '.tar.gz');
}
// the index JSON as the engine's signing tool writes it
function eng_index_json($version, $seq, $plats, $o = array())
{
	$arts = array();
	foreach($plats as $p)
	{
		$bytes = 'engine archive ' . $version . ' ' . $p . (isset($o['salt']) ? $o['salt'] : '');
		$arts[$p] = array('file' => archive_name($version, $p), 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes),
			'exe' => array('name' => $p === 'windows-x64' ? 'benchy-engine.exe' : 'benchy-engine', 'size' => 1000, 'sha256' => hash('sha256', 'exe ' . $version . $p)));
	}
	$ix = array('schema' => 'benchy-engine-index/1', 'sequence' => $seq, 'issued' => '2026-11-20T15:00:00Z', 'min_generation' => isset($o['min_generation']) ? $o['min_generation'] : null,
		'release' => array('version' => $version, 'contract_protocol' => 1, 'agent_protocol' => 2, 'artifacts' => $arts, 'notes' => isset($o['notes']) ? $o['notes'] : 'Plain notes for an admin.'),
		'revoked' => isset($o['revoked']) ? $o['revoked'] : array(), 'floor' => isset($o['floor']) ? $o['floor'] : '');
	$j = json_encode($ix, JSON_UNESCAPED_SLASHES);
	return isset($o['raw']) ? $o['raw']($j) : $j;
}
// the zip: engine.signed and the archives the index names, with the faults a case asks for
function bundle($name, $ring, $gen, $version, $seq, $plats, $o = array())
{
	global $out;
	$j = eng_index_json($version, $seq, $plats, $o);
	$file = $ring->sign($gen, $j);
	if(isset($o['flip']))
	{
		$file[strlen($file) - $o['flip']] = $file[strlen($file) - $o['flip']] === 'a' ? 'b' : 'a';
	}
	$entries = array(array('name' => 'engine.signed', 'data' => $file));
	foreach(isset($o['archive_plats']) ? $o['archive_plats'] : $plats as $p)
	{
		$data = 'engine archive ' . $version . ' ' . $p . (isset($o['salt']) ? $o['salt'] : '');
		if(isset($o['bad_archive']) && $o['bad_archive'] === $p)
		{
			$data = strtoupper($data);   // the same length, other bytes
		}
		if(isset($o['skip_archive']) && $o['skip_archive'] === $p)
		{
			continue;
		}
		$entries[] = array('name' => archive_name($version, $p), 'data' => $data);
	}
	foreach(isset($o['extra']) ? $o['extra'] : array() as $e)
	{
		$entries[] = $e;
	}
	file_put_contents($out . $name . '.zip', catalog_zipbuild::build($entries));
}

$keys = array();
foreach($ring->spki as $g => $s)
{
	$keys[] = array('generation' => $g, 'spki' => $s);
}
file_put_contents($out . 'keys.json', json_encode(array('schema' => 'benchy-engine-keys/1', 'test_keys' => false, 'keys' => $keys, 'denied_key_ids' => array())));
$P = array('windows-x64', 'linux-x64');
bundle('a', $ring, 1, '0.11.0', 1, $P);                                                                 // the first release
bundle('b', $ring, 1, '0.11.1', 2, $P, array('revoked' => array(array('version' => '0.10.0', 'reason' => 'reads the wrong Edge build')), 'floor' => '0.9.0'));
bundle('a-other', $ring, 1, '0.11.0', 1, $P, array('notes' => 'other notes'));                          // the same pair as a, other bytes
bundle('rogue', $rogue, 1, '0.11.2', 9, $P);                                                            // signed by a key the table doesn't hold
bundle('flipped', $ring, 1, '0.11.2', 9, $P, array('flip' => 40));                                      // a byte of the signed index changed after signing
bundle('bad-archive', $ring, 1, '0.11.2', 9, $P, array('bad_archive' => 'linux-x64'));
bundle('missing', $ring, 1, '0.11.2', 9, $P, array('skip_archive' => 'linux-x64'));
bundle('extra', $ring, 1, '0.11.2', 9, $P, array('archive_plats' => array('windows-x64', 'linux-x64', 'linux-arm64')));
bundle('badname', $ring, 1, '0.11.2', 9, $P, array('extra' => array(array('name' => '../evil.zip', 'data' => 'x'))));
bundle('casefold', $ring, 1, '0.11.2', 9, $P, array('raw' => function($j) { return preg_replace('/"sequence"/', '"Sequence"', $j, 1); }));
bundle('bad-version', $ring, 1, '0.11', 9, array());
bundle('gen2', $ring, 2, '0.12.0', 3, $P, array('min_generation' => 2));                                // the standby takes over and retires generation 1
bundle('gen1-late', $ring, 1, '0.12.1', 4, $P);                                                         // generation 1 after that: refused
bundle('min-too-high', $ring, 1, '0.12.1', 10, $P, array('min_generation' => 2));                       // min_generation above its own key
// ---- the component bundles (runtime component updates, R1): components.signed and the files it names
function comp_bundle($name, $ring, $gen, $seq)
{
	global $out;
	$php = 'php zip 8.5.13';
	$client = 'client zip 0.15.0';
	$rel = function($v, $bytes, $file) { return array('version' => $v, 'artifact' => array('file' => $file, 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes)), 'notes' => 'n'); };
	$ix = array('schema' => 'benchy-component-index/1', 'sequence' => $seq, 'issued' => '2026-11-24T15:00:00Z', 'min_generation' => null, 'platform' => 'windows-x64', 'components' => array(
		'php' => array('tracks' => (object)array('8.5' => $rel('8.5.13', $php, 'php-8.5.13.zip')), 'default_track' => '8.5'),
		'client' => array('tracks' => (object)array('0' => $rel('0.15.0', $client, 'client-0.15.0.zip')), 'default_track' => '0')));
	$entries = array(array('name' => 'components.signed', 'data' => $ring->sign($gen, json_encode($ix, JSON_UNESCAPED_SLASHES))), array('name' => 'php-8.5.13.zip', 'data' => $php), array('name' => 'client-0.15.0.zip', 'data' => $client));
	file_put_contents($out . $name . '.zip', catalog_zipbuild::build($entries));
}
comp_bundle('c1', $ring, 1, 1);
comp_bundle('c1-rogue', $rogue, 1, 9);
echo "ok\n";
