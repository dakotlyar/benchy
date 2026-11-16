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
	case 'relaycheck':
		// NoPhoneHome on: the relay check must not even try (the sink is the only thing that could see it); the answer is an empty list
		$r = pts_openbenchmarking::possible_phoromatic_servers();
		check('possible_phoromatic_servers() returns nothing under NoPhoneHome', $r === array(), $r);
		break;
}

echo 'RESULT ' . $pass . ' ' . $fail . PHP_EOL;
exit($fail > 0 ? 1 : 0);
