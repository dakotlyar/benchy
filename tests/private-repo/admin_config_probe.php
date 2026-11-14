<?php
// Renders ?admin_config as root in this process (so the test can set the environment the web worker would have, e.g. PHOROMATIC_HTTP_PORT
// from the service's systemd drop-in) and prints the "Managed by benchy-server-setup" block as text. Run inside the QC server container.
define('PTS_MODE', 'WEB_CLIENT'); define('PTS_AUTO_LOAD_OBJECTS', true); define('PHOROMATIC_SERVER', true); $_SERVER['argv'] = array('x');
$_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['REQUEST_URI'] = '/?admin_config'; $_SERVER['HTTP_HOST'] = 'x'; $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
include('/bench/pts-core/pts-core.php'); pts_core::init();
include('/bench/pts-core/phoromatic/phoromatic_functions.php');
interface pts_webui_interface { public static function preload($PATH); public static function page_title(); public static function page_header(); public static function render_page_process($PATH); }
phoromatic_server::prepare_database();
$_SESSION = array('AdminLevel' => -40, 'AccountID' => 'x', 'UserName' => 'r', 'Token' => 't');
define('PAGE_REQUEST', 'admin_config');
include('/bench/pts-core/phoromatic/pages/phoromatic_admin_config.php');
ob_start(); phoromatic_admin_config::render_page_process(array()); $out = ob_get_clean();
preg_match('/Managed by benchy-server-setup.*?<\/p>/s', $out, $m);
echo 'MANAGED: ' . strip_tags(isset($m[0]) ? $m[0] : 'NOT FOUND') . "\n";
