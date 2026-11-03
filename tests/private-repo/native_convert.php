<?php
// Harness helper (run inside the server container by test_native_result.py): converts a native result fixture with
// phoromatic_native_result and prints what the rest of Phoromatic then sees, as one JSON object.
//   php native_convert.php <fixture.json> [<options json>]    convert and parse back
//   php native_convert.php --isref <id>...                    is each one an engine test ref
//   php native_convert.php --scratch                          the OpenBenchmarking scratch directory
define('PHOROMATIC_SERVER', true);
define('PTS_MODE', 'WEB_CLIENT');
define('PTS_AUTO_LOAD_OBJECTS', true);
include('/bench/pts-core/pts-core.php');
pts_core::init();

if($argv[1] == '--scratch')
{
	echo PTS_OPENBENCHMARKING_SCRATCH_PATH;
	exit;
}
if($argv[1] == '--isref')
{
	echo json_encode(array_map(array('phoromatic_native_result', 'is_native_ref'), array_slice($argv, 2)));
	exit;
}
$upload = json_decode(file_get_contents($argv[1]), true);
$options = isset($argv[2]) ? json_decode($argv[2], true) : array();
if(isset($options['_fail_entry']))
{
	// a copy of the run in which one metric failed, to see that its siblings keep their hashes
	$i = $options['_fail_entry'];
	$upload['runs'][0]['tests'][$i]['status'] = 'failed';
	$upload['runs'][0]['tests'][$i]['value'] = null;
	$upload['runs'][0]['tests'][$i]['trials'] = array();
	$upload['runs'][0]['tests'][$i]['error'] = array('code' => 'run_failed', 'message' => 'qc made it fail');
	unset($options['_fail_entry']);
}
if(isset($options['_engine_version']))
{
	$upload['engine']['version'] = $options['_engine_version'];
	unset($options['_engine_version']);
}
if(isset($options['_no_title']))
{
	$upload['title'] = '';
	unset($options['_no_title']);
}
if(isset($options['_inf_value']))
{
	$upload['runs'][0]['tests'][0]['value'] = INF;
	unset($options['_inf_value']);
}
if(isset($options['_nan_trial']))
{
	$upload['runs'][0]['tests'][0]['trials'][0] = NAN;
	unset($options['_nan_trial']);
}
$r = phoromatic_native_result::convert($upload, $options);
$out = array('ok' => $r['ok'], 'error' => $r['error'], 'xml' => $r['xml'], 'valid' => null, 'tests' => array());
if($r['ok'])
{
	$f = new pts_result_file($r['xml']);
	$out['valid'] = $f->validate();
	foreach($f->get_result_objects() as $o)
	{
		$out['tests'][] = array($o->test_profile->get_identifier(), $o->get_comparison_hash(true, false), $o->get_arguments(), $o->get_arguments_description(), $o->test_profile->get_title());
	}
}
echo json_encode($out);
