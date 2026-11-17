<?php

// D0 (server discovery, 0.9.15): what the code decides, without a running service. Run by d0.sh in a container, one process per scenario
// because the configuration is read once per process: php /h/d0_unit.php <scenario>. HOME holds ~/.benchy/user-config.xml for the scenario.
// Prints "  PASS  ..." / "  FAIL  ..." lines.

define('PTS_MODE', 'CLIENT');
define('PTS_AUTO_LOAD_OBJECTS', true);
$_SERVER['argv'] = array('x');
include('/bench/pts-core/pts-core.php');
pts_core::init();

$pass = 0;
$fail = 0;
function check($desc, $ok, $detail = '')
{
	global $pass, $fail;
	if($ok)
	{
		$pass++;
		echo '  PASS  ' . $desc . PHP_EOL;
	}
	else
	{
		$fail++;
		echo '  FAIL  ' . $desc . PHP_EOL;
		if($detail !== '')
		{
			echo '        | ' . substr(is_string($detail) ? $detail : json_encode($detail), 0, 500) . PHP_EOL;
		}
	}
}

$scenario = isset($argv[1]) ? $argv[1] : '';
switch($scenario)
{
	case 'relay':
		// the expectation arrives in D0_EXPECT (yes/no); the config under HOME says why
		$expect = getenv('D0_EXPECT') == 'yes';
		$allowed = pts_openbenchmarking::server_relay_publish_allowed();
		check(getenv('D0_WHY') . ': the server ' . ($expect ? 'may' : 'may not') . ' publish to the relay', $allowed === $expect, var_export($allowed, true));
		break;
	case 'servers':
		// avahi-browse (a stub on PATH) announces a server on 127.0.0.1:D0_PORT; D0_CONFIGURED says whether the config names it too
		$port = getenv('D0_PORT');
		$configured = getenv('D0_CONFIGURED') == 'yes';
		$servers = pts_client::available_phoromatic_servers();
		check('the announced server was found (so it can still serve the download cache)', isset($servers['127.0.0.1']) && $servers['127.0.0.1']['http_port'] == $port, $servers);
		check('...and is marked ' . ($configured ? 'configured' : 'unconfigured'), isset($servers['127.0.0.1']) && !empty($servers['127.0.0.1']['configured']) === $configured, $servers);
		foreach(array('index' => array('index', 'pts'), 'test' => array('test', 'pts', 'x-1.0.0'), 'suite' => array('suite', 'pts', 'y-1.0.0'), 'is_test' => array('is_test', null, 'x')) as $name => $a)
		{
			$got = pts_openbenchmarking::phoromatic_server_ob_cache_request($a[0], $a[1], isset($a[2]) ? $a[2] : null);
			check('metadata request "' . $name . '" ' . ($configured ? 'is answered by the configured server' : 'is not sent to the unconfigured server'), $configured ? $got === 'ROGUE-METADATA' : $got === null, $got);
		}
		break;
	case 'servers2':
		// two servers announced on the same address (ports D0_PORT and D0_PORT2); the config names only D0_PORT. The administrator's entry must be the
		// one kept for that address, not whichever the network announced first
		$servers = pts_client::available_phoromatic_servers();
		check('same address announced on two ports: the kept entry is the configured port', isset($servers['127.0.0.1']) && $servers['127.0.0.1']['http_port'] == getenv('D0_PORT') && !empty($servers['127.0.0.1']['configured']), $servers);
		$got = pts_openbenchmarking::phoromatic_server_ob_cache_request('index', 'pts');
		check('...so its metadata answers', $got === 'ROGUE-METADATA', $got);
		break;
	case 'dlcache':
		// A hashless file offered by the server's download cache: taken only from a configured server. A hashed file from a discovered one is
		// offered, and must still match its hash whatever NO_FILE_HASH_CHECKS says.
		$configured = getenv('D0_CONFIGURED') == 'yes';
		$good = 'good file contents';
		$test_files = array(
			new pts_test_file_download('http://example.invalid/nohash.bin', 'nohash.bin', 0, null, null),
			new pts_test_file_download('http://example.invalid/hashed.bin', 'hashed.bin', 0, md5($good), hash('sha256', $good)));
		$req = new pts_test_install_request('local/d0-dl');
		$req->test_files = $test_files;
		$local = array(); $remote = array(); $remote_files = array();
		$caches = pts_test_install_manager::phoromatic_download_server_caches();
		check('the announced server\'s download cache listing was read', count($caches) == 1, $caches);
		$req->scan_download_caches($local, $remote, $remote_files, $caches);
		check('hashless file ' . ($configured ? 'is taken from the configured server' : 'is NOT taken from the discovered server'), $test_files[0]->get_download_location_type() === ($configured ? 'REMOTE_DOWNLOAD_CACHE' : null), $test_files[0]->get_download_location_type());
		check('hashed file whose hash the listing confirms is offered by the server', $test_files[1]->get_download_location_type() === 'REMOTE_DOWNLOAD_CACHE', $test_files[1]->get_download_location_type());
		$tmp = tempnam(sys_get_temp_dir(), 'd0');
		file_put_contents($tmp, 'tampered');
		putenv('NO_FILE_HASH_CHECKS=1');
		$bad_passes = $test_files[1]->check_file_hash($tmp);
		check('a tampered file ' . ($configured ? 'passes only because NO_FILE_HASH_CHECKS is set (configured source, as before)' : 'fails the hash check even with NO_FILE_HASH_CHECKS set'), $bad_passes === $configured, var_export($bad_passes, true));
		file_put_contents($tmp, $good);
		check('the real file passes', $test_files[1]->check_file_hash($tmp) === true);
		unlink($tmp);
		break;
	case 'relaycheck':
		// NoPhoneHome on: the relay check must not even try (the sink is the only thing that could see it); the answer is an empty list
		$r = pts_openbenchmarking::possible_phoromatic_servers();
		check('possible_phoromatic_servers() returns nothing under NoPhoneHome', $r === array(), $r);
		break;
}

echo 'RESULT ' . $pass . ' ' . $fail . PHP_EOL;
exit($fail > 0 ? 1 : 0);
