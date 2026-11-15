<?php
// A small test harness for the catalog verifier (E4.4 C1): plain PHP, no framework, no server and no database. Run by run_tests.php inside the
// private-repo test image. It never makes, reads or holds a private key: signed input is the engine's committed fixtures (signed by a key
// nobody holds), and everything below the signature is reached through phoromatic_catalog_verify::evaluate_index().

foreach(array('phoromatic_catalog_refusal', 'phoromatic_catalog_keys', 'phoromatic_catalog_json', 'phoromatic_catalog_zip', 'phoromatic_catalog_verify') as $class)
{
	require_once __DIR__ . '/../../pts-core/objects/phoromatic/' . $class . '.php';
}
require_once __DIR__ . '/zipbuild.php';
require_once __DIR__ . '/bundle_cases.php';

$GLOBALS['T_COUNT'] = array('pass' => 0, 'fail' => 0);
$GLOBALS['T_FAILED'] = array();

function t($name, $fn)
{
	try
	{
		$fn();
		$GLOBALS['T_COUNT']['pass']++;
		echo "  PASS  $name\n";
	}
	catch(Throwable $e)
	{
		$GLOBALS['T_COUNT']['fail']++;
		$GLOBALS['T_FAILED'][] = $name;
		echo "  FAIL  $name\n        | " . get_class($e) . ': ' . $e->getMessage() . "\n        | at " . basename($e->getFile()) . ':' . $e->getLine() . "\n";
	}
}

function eq($want, $got, $what = '')
{
	if($want !== $got)
	{
		throw new Exception(($what !== '' ? $what . ': ' : '') . 'expected ' . var_export($want, true) . ', got ' . var_export($got, true));
	}
}

function ok($cond, $what)
{
	if(!$cond)
	{
		throw new Exception($what);
	}
}

// The refusal reason a callable ends in, or 'accepted' when it doesn't throw one
function reason_of($fn)
{
	try
	{
		$fn();
	}
	catch(phoromatic_catalog_refusal $r)
	{
		return $r->reason;
	}
	return 'accepted';
}

function fixture_trust($extra = array(), $denied = array(), $floor = array())
{
	$spki = trim(file_get_contents(__DIR__ . '/engine-fixtures/fixture-key.spki'));
	return new phoromatic_catalog_keys(array_merge(array(array('generation' => 1, 'spki' => $spki)), $extra), $denied, $floor);
}

function fixture_file($name)
{
	return file_get_contents(__DIR__ . '/engine-fixtures/' . $name);
}

function sha($c)
{
	return str_repeat($c, 64);
}

// ---- synthetic indexes, for the rules below the signature ----

function test_entry($id, $version = '1.0.0', $tree = null, $over = array())
{
	return $over + array('id' => $id, 'version' => $version, 'format' => 'native', 'pack_sha256' => hash('sha256', $id . $version), 'pack_size' => 100,
		'tree_sha256' => $tree === null ? sha('b') : $tree, 'min_engine' => '0.6.0', 'platforms' => array('linux'), 'license_class' => 'free', 'software_license' => 'MIT',
		'manifest' => array('id' => $id, 'version' => $version, 'min_engine' => '0.6.0', 'platforms' => array('linux'), 'title' => 'T'));
}

function suite_entry($id, $version, $tests, $over = array())
{
	$list = array();
	foreach($tests as $t)
	{
		$list[] = array('test' => $t[0], 'version' => isset($t[1]) ? $t[1] : null, 'options' => (object)(isset($t[2]) ? $t[2] : array()));
	}
	$for_hash = array();
	foreach($tests as $t)
	{
		$opts = array();
		foreach((isset($t[2]) ? $t[2] : array()) as $k => $v)
		{
			$opts[] = array((string)$k, $v);
		}
		$for_hash[] = array('test' => $t[0], 'version' => isset($t[1]) ? $t[1] : null, 'options' => $opts);
	}
	return $over + array('id' => $id, 'version' => $version, 'title' => 'S', 'description' => 'd', 'min_engine' => '0.6.0',
		'suite_sha256' => phoromatic_catalog_verify::suite_sha256($for_hash), 'tests' => $list);
}

// The JSON text of an index. $over replaces top-level fields; objects the encoder would make a list of are the caller's to cast.
function index_json($over = array(), $encode_flags = 0)
{
	$ix = $over + array('schema' => 'benchy-catalog-index/1', 'repo' => 'benchy', 'tier' => 'public', 'sequence' => 1, 'issued' => '2026-11-20T15:00:00Z',
		'min_generation' => null, 'tests' => array(test_entry('benchy/a')), 'suites' => array(), 'revoked' => array());
	return json_encode($ix, JSON_UNESCAPED_SLASHES | $encode_flags);
}

function revoked_entry($id, $version, $reason = 'because')
{
	return array('id' => $id, 'version' => $version, 'reason' => $reason);
}

function tier_state($gen, $seq, $tests = array(), $suites = array(), $revoked = array(), $file_sha = null)
{
	return array('generation' => $gen, 'sequence' => $seq, 'signed_sha256' => $file_sha === null ? sha('e') : $file_sha, 'tests' => $tests, 'suites' => $suites, 'revoked' => $revoked);
}

function state_of($tiers = array(), $retired_below = 0)
{
	return array('retired_below' => $retired_below, 'tiers' => $tiers);
}

// evaluate_index() as the server calls it after a good signature, for a key of the given generation
function ev($raw, $state = null, $gen = 1, $trust = null, $stored = null, $parse = null, $want_tier = '')
{
	return phoromatic_catalog_verify::evaluate_index($trust === null ? fixture_trust() : $trust, $state === null ? phoromatic_catalog_verify::new_state() : $state,
		$want_tier, array('id' => 'k' . $gen, 'generation' => $gen), $raw, hash('sha256', $raw), $stored, $parse);
}

function stored_file($raw)
{
	return function($tier) use ($raw) { return '{"schema":"benchy-catalog-sig/1","key_id":"x","sig":"x"}' . "\n" . $raw; };
}
