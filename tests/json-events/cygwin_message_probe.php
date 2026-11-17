<?php
// The message of a Cygwin that is not ready after its setup ran (pts_external_dependencies::cygwin_failure_message), run off Windows (json.sh).
// Prints one line per case: all three files gone, one gone, two gone.
define('PTS_PATH', '/bench/');
define('PTS_MODE', 'CLIENT');
ob_start();
require '/bench/pts-core/pts-core.php';
pts_client::init();
ob_end_clean();
echo 'all: ' . pts_external_dependencies::cygwin_failure_message(array('bash.exe' => 'bash', 'unzip.exe' => 'unzip', 'which.exe' => 'which')) . "\n";
echo 'one: ' . pts_external_dependencies::cygwin_failure_message(array('which.exe' => 'which')) . "\n";
echo 'two: ' . pts_external_dependencies::cygwin_failure_message(array('unzip.exe' => 'unzip', 'which.exe' => 'which')) . "\n";
