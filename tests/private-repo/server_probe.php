<?php

// Run by server_deb.sh inside a benchy-server container as the service user (php server_probe.php <tree>): what start_phoromatic_server
// would treat as a supervised start, and whether the server would send mail. Prints "supervised=true|false" and "mail=true|false".

define('PTS_MODE', 'CLIENT');
define('PTS_AUTO_LOAD_OBJECTS', true);
define('PHOROMATIC_SERVER', true);
$_SERVER['argv'] = array('x');
$tree = rtrim($argv[1], '/');
include($tree . '/pts-core/pts-core.php');
pts_core::init();
include($tree . '/pts-core/commands/start_phoromatic_server.php');
$method = new ReflectionMethod('start_phoromatic_server', 'is_supervised');
$method->setAccessible(true);
echo 'supervised=' . ($method->invoke(null) ? 'true' : 'false') . PHP_EOL;
echo 'mail=' . (phoromatic_server::mail_available() ? 'true' : 'false') . PHP_EOL;
