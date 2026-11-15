<?php
// Runs the catalog verifier tests: php -d memory_limit=1G tests/catalog/run_tests.php   (run.sh catalog does this in a throwaway container)
error_reporting(E_ALL);
ini_set('display_errors', '1');
define('PTS_PATH', realpath(__DIR__ . '/../..') . '/');
require __DIR__ . '/lib.php';
foreach(array('test_keys', 'test_fixtures', 'test_index', 'test_recovery', 'test_zip', 'test_signed', 'test_guard') as $file)
{
	echo "\n$file\n";
	require __DIR__ . '/' . $file . '.php';
}
echo "\n" . $GLOBALS['T_COUNT']['pass'] . ' passed, ' . $GLOBALS['T_COUNT']['fail'] . " failed\n";
if($GLOBALS['T_COUNT']['fail'] > 0)
{
	echo "FAILED: " . implode('; ', $GLOBALS['T_FAILED']) . "\n";
	exit(1);
}
