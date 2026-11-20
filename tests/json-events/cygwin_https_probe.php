<?php
// pts_network::download_https_verified, run off Windows (json.sh): plain http is refused before any connection, and an https server
// whose certificate the system store doesn't trust (the stub's self-signed one) fails and leaves no file. Usage: cygwin_https_probe.php <https url> <destination>
define('PTS_PATH', '/bench/');
define('PTS_MODE', 'CLIENT');
ob_start();
require '/bench/pts-core/pts-core.php';
pts_client::init();
ob_end_clean();
$http = pts_network::download_https_verified('http://127.0.0.1:1/x', $argv[2] . '.http');
$self = pts_network::download_https_verified($argv[1], $argv[2]);
echo 'http=' . ($http ? 'ok' : 'refused') . ' selfsigned=' . ($self ? 'ok' : 'refused') . ' files=' . (is_file($argv[2]) || is_file($argv[2] . '.http') ? 'yes' : 'no') . "\n";
