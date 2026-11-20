<?php
// The Cygwin download-and-check helper of the Windows dependency handler, run off Windows against the stub server (json.sh).
// Usage: php cygwin_probe.php <url> <destination>; prints "ok" or "failed", then whether the file and its .part exist.
define('PTS_PATH', '/bench/');
define('PTS_MODE', 'CLIENT');
ob_start();
require '/bench/pts-core/pts-core.php';
pts_client::init();
require_once '/bench/pts-core/external-test-dependencies/dependency-handlers/microsoft_dependency_handler.php';
ob_end_clean();
$ok = microsoft_dependency_handler::fetch_checked($argv[1], $argv[2], function($u, $d) { return pts_network::download_file($u, $d); });
echo ($ok ? 'ok' : 'failed') . ' file=' . (is_file($argv[2]) ? filesize($argv[2]) : 'none') . ' part=' . (is_file($argv[2] . '.part') ? 'yes' : 'no') . "\n";
