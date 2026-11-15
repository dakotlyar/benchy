<?php
// Writes the bundle fixtures of tests/catalog/bundles/. Every bundle holds the engine's signed fixture index (engine-fixtures/index-good.signed),
// so the signature is good and what differs is the zip around it. They hold no private key and need none. Deterministic; the test run builds
// them again and compares bytes. Usage: php make_bundles.php [outdir]
require __DIR__ . '/zipbuild.php';
require __DIR__ . '/bundle_cases.php';
$out = isset($argv[1]) ? $argv[1] : __DIR__ . '/bundles';
foreach(catalog_bundle_cases() as $name => $bytes)
{
	file_put_contents($out . '/' . $name . '.zip', $bytes);
}
echo count(catalog_bundle_cases()) . " bundles written to $out\n";
