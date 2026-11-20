<?php
// A bundle the engine's own signing tool made (benchy-catalog engine prepare/finish, built with the engupdtest tag, signed by a throw-away key whose private half was
// deleted): this server's reader, verifier and index parser must accept it exactly as the engine does. Run: php tests/engine/go_fixture.php (no server, no network)
error_reporting(E_ALL);
ini_set('display_errors', '1');
$tree = sys_get_temp_dir() . '/engine-gofix-' . getmypid() . '/';
mkdir($tree . 'pts-core/static/engine', 0700, true);
file_put_contents($tree . 'pts-core/static/engine/keys.json', json_encode(array('schema' => 'benchy-engine-keys/1', 'test_keys' => false, 'keys' => array(array('generation' => 1, 'spki' => trim(file_get_contents(__DIR__ . '/fixtures/go-bundle.spki')))), 'denied_key_ids' => array())));
define('PTS_PATH', $tree);
foreach(array('phoromatic_catalog_refusal', 'phoromatic_catalog_keys', 'phoromatic_catalog_json', 'phoromatic_catalog_zip', 'phoromatic_catalog_verify', 'phoromatic_engine_store') as $c)
{
	require_once __DIR__ . '/../../pts-core/objects/phoromatic/' . $c . '.php';
}
$bad = array();
$zip = phoromatic_catalog_zip::inspect(file_get_contents(__DIR__ . '/fixtures/go-bundle.zip'), true);
$file = $zip->read_all($zip->lookup('engine.signed'), 65536);
list($key, $raw) = phoromatic_catalog_verify::engine_signature($file, 0);
$ix = phoromatic_engine_store::parse_index($raw);
$names = array_map(function($e) { return $e['name']; }, $zip->files());
$ok = $ix['version'] === '0.11.0' && $ix['sequence'] === 1 && $key['generation'] === 1 && array_keys($ix['artifacts']) === array('windows-x64', 'linux-x64') || array_keys($ix['artifacts']) === array('linux-x64', 'windows-x64');
foreach($ix['artifacts'] as $a)
{
	$ok = $ok && in_array($a['file'], $names, true) && hash('sha256', $zip->read_all($zip->lookup($a['file']), 1 << 20)) === $a['sha256'];
}
$flipped = $file;
$flipped[strlen($flipped) - 20] = $flipped[strlen($flipped) - 20] === 'a' ? 'b' : 'a';
try
{
	phoromatic_catalog_verify::engine_signature($flipped, 0);
	$ok = false;
}
catch(phoromatic_catalog_refusal $r)
{
	$ok = $ok && $r->reason === 'bad_signature';
}
echo $ok ? "PASS  the engine's own bundle: zip, signature, index and archive hashes all accepted; a flipped byte refused\n" : "FAIL  the engine's own bundle\n";
exit($ok ? 0 : 1);
