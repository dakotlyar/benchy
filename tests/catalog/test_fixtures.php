<?php
// Parity with the engine: the committed index fixtures (internal/catindex/testdata/fixtures) and the bundle fixtures, each judged the way the
// engine judges them. expected.json is the engine's own; bundles/engine-verdicts.json was recorded by running a catalogtest engine build on
// every bundle (engine_verdicts.sh).

$expected = json_decode(file_get_contents(__DIR__ . '/engine-fixtures/expected.json'), true);

foreach($expected as $case)
{
	t('engine fixture ' . $case['file'] . ': ' . ($case['accept'] ? 'accepted' : 'refused ' . $case['reason']), function() use ($case)
	{
		$file = fixture_file($case['file']);
		$got = reason_of(function() use ($file) { phoromatic_catalog_verify::evaluate(fixture_trust(), phoromatic_catalog_verify::new_state(), '', $file); });
		eq($case['accept'] ? 'accepted' : $case['reason'], $got);
	});
}

t('the malleated and respaced fixtures are the good index under another signature: unchanged at an equal pair, the stored file stays', function()
{
	$good = fixture_file('index-good.signed');
	$d = phoromatic_catalog_verify::evaluate(fixture_trust(), phoromatic_catalog_verify::new_state(), '', $good);
	ok(!$d['unchanged'], 'first contact is a change');
	$state = $d['next'];
	eq(array(1, 1), array($state['tiers']['public']['generation'], $state['tiers']['public']['sequence']));
	$stored = function($tier) use ($good) { return $good; };
	foreach(array('index-malleated.signed', 'index-respaced.signed') as $name)
	{
		$f = fixture_file($name);
		ok($f !== $good, $name . ' is a different file');
		$d2 = phoromatic_catalog_verify::evaluate(fixture_trust(), $state, '', $f, $stored);
		ok($d2['unchanged'] && !$d2['same_file'], $name . ': unchanged, by the index bytes');
		eq($state, $d2['next'], $name . ': the state does not move');
	}
	$d3 = phoromatic_catalog_verify::evaluate(fixture_trust(), $state, '', $good, $stored);
	ok($d3['unchanged'] && $d3['same_file'], 'the very same file is unchanged by hash');
	ok($d3['index'] === null, 'nothing is parsed for the very same file');
});

t('the bad-DER fixture is the case where openssl_verify returns -1, and it is refused as bad_signature, not accepted', function()
{
	list(, $index) = phoromatic_catalog_verify::split(fixture_file('index-bad-der.signed'));
	$e = json_decode(strtok(fixture_file('index-bad-der.signed'), "\n"), true);
	$key = fixture_trust()->all_keys()[0];
	eq(-1, @openssl_verify($index, base64_decode($e['sig']), $key['pem'], OPENSSL_ALGO_SHA256), 'this PHP gives -1 on it');
	eq('bad_signature', reason_of(function() { phoromatic_catalog_verify::evaluate(fixture_trust(), phoromatic_catalog_verify::new_state(), '', fixture_file('index-bad-der.signed')); }));
});

t('bytes are never parsed before the signature passes (a counting parser): flipped, bad DER, unknown key, denied key, retired key, no signature line', function()
{
	$calls = 0;
	$count = function($raw, $gen) use (&$calls) { $calls++; return phoromatic_catalog_verify::parse_index($raw, $gen); };
	$run = function($file, $trust = null, $state = null) use ($count)
	{
		return reason_of(function() use ($file, $trust, $state, $count)
		{
			phoromatic_catalog_verify::evaluate($trust === null ? fixture_trust() : $trust, $state === null ? phoromatic_catalog_verify::new_state() : $state, '', $file, null, $count);
		});
	};
	eq('bad_signature', $run(fixture_file('index-flipped.signed')));
	eq('bad_signature', $run(fixture_file('index-bad-der.signed')));
	eq('unknown_key', $run(fixture_file('index-unknown-key.signed')));
	eq('denied_key', $run(fixture_file('index-good.signed'), fixture_trust(array(), array('d98e6e86a8584efd075cc0fc8155f980'))));
	eq('retired_key', $run(fixture_file('index-good.signed'), null, state_of(array(), 2)));
	eq('bad_envelope', $run("no newline at all"));
	eq(0, $calls, 'the parser was called');
	eq('accepted', $run(fixture_file('index-good.signed')));
	eq(1, $calls, 'and is called once, after a good signature');
});

t('a denied key refuses even the file that is already accepted; a dropped or raised-floor table does not', function()
{
	$good = fixture_file('index-good.signed');
	$state = state_of(array('public' => tier_state(1, 1, array(), array(), array(), hash('sha256', $good))));
	eq('denied_key', reason_of(function() use ($good, $state) { phoromatic_catalog_verify::evaluate(fixture_trust(array(), array('d98e6e86a8584efd075cc0fc8155f980')), $state, '', $good); }));
	// a planned rotation: the key is no longer in the table, and a floor above the stored pair was compiled in. The stored index stays accepted.
	$other = new phoromatic_catalog_keys(array(array('generation' => 2, 'spki' => OTHER_P256_SPKI)), array(), array('public' => array('generation' => 2, 'sequence' => 50)));
	$d = phoromatic_catalog_verify::evaluate($other, $state, '', $good);
	ok($d['unchanged'] && $d['same_file'], 'the stored index is unchanged');
});

t('a floor from the table refuses a first contact below it (floor), and a stored higher pair refuses it as rollback', function()
{
	$good = fixture_file('index-good.signed');
	$floor = fixture_trust(array(), array(), array('public' => array('generation' => 1, 'sequence' => 2)));
	eq('floor', reason_of(function() use ($good, $floor) { phoromatic_catalog_verify::evaluate($floor, phoromatic_catalog_verify::new_state(), '', $good); }));
	// the private tier has no floor, and a public floor doesn't touch it
	$state = state_of(array('public' => tier_state(1, 5)));
	eq('rollback', reason_of(function() use ($good, $state) { phoromatic_catalog_verify::evaluate(fixture_trust(), $state, '', $good); }));
	$state = state_of(array('public' => tier_state(2, 1)));
	eq('rollback', reason_of(function() use ($good, $state) { phoromatic_catalog_verify::evaluate(fixture_trust(), $state, '', $good); }), 'a lower generation is below whatever the sequence');
});

t('an equal pair with different index bytes is refused (same_pair_differs); with the stored file missing it is a fresh accept', function()
{
	$good = fixture_file('index-good.signed');
	$state = state_of(array('public' => tier_state(1, 1, array('benchy/fixture@1.0.0' => sha('b')))));
	$other = function($tier) { return "{\"schema\":\"benchy-catalog-sig/1\",\"key_id\":\"x\",\"sig\":\"x\"}\n{\"different\":true}"; };
	eq('same_pair_differs', reason_of(function() use ($good, $state, $other) { phoromatic_catalog_verify::evaluate(fixture_trust(), $state, '', $good, $other); }));
	$d = phoromatic_catalog_verify::evaluate(fixture_trust(), $state, '', $good, function($tier) { return null; });
	ok(!$d['unchanged'], 'a lost stored file is taken as a fresh accept');
	eq(hash('sha256', $good), $d['next']['tiers']['public']['signed_sha256'], 'and its hash is recorded');
});

t('the fixture index is accepted by the ledger a state of the same tests, and refused when that test changed its tree', function()
{
	$good = fixture_file('index-good.signed');
	$state = state_of(array('public' => tier_state(0, 0, array('benchy/fixture@1.0.0' => sha('c')))));
	eq('ledger_conflict', reason_of(function() use ($good, $state) { phoromatic_catalog_verify::evaluate(fixture_trust(), $state, '', $good); }));
});

$verdicts = json_decode(file_get_contents(__DIR__ . '/bundles/engine-verdicts.json'), true);
$trust_for_bundles = function() { return fixture_trust(); };

foreach($verdicts as $name => $engine)
{
	t("bundle fixture $name: the engine says $engine", function() use ($name, $engine)
	{
		$bytes = file_get_contents(__DIR__ . '/bundles/' . $name . '.zip');
		$got = reason_of(function() use ($bytes) { phoromatic_catalog_verify::check_bundle(fixture_trust(), phoromatic_catalog_verify::new_state(), $bytes); });
		eq($engine, $got);
	});
}

t('the bundle fixtures are exactly what make_bundles.php builds (no hand edits)', function()
{
	$cases = catalog_bundle_cases();
	foreach($cases as $name => $bytes)
	{
		ok(is_file(__DIR__ . '/bundles/' . $name . '.zip'), $name . ' is not committed');
		ok(file_get_contents(__DIR__ . '/bundles/' . $name . '.zip') === $bytes, $name . ' differs from the builder');
	}
	$files = glob(__DIR__ . '/bundles/*.zip');
	foreach($files as $f)
	{
		$n = basename($f, '.zip');
		ok(isset($cases[$n]) || strpos($n, 'go-writer-') === 0, $n . ' is committed but not built');
	}
	foreach(json_decode(file_get_contents(__DIR__ . '/bundles/engine-verdicts.json'), true) as $n => $v)
	{
		ok(is_file(__DIR__ . '/bundles/' . $n . '.zip'), 'a verdict for a missing bundle ' . $n);
	}
	eq(count($files), count(json_decode(file_get_contents(__DIR__ . '/bundles/engine-verdicts.json'), true)), 'every bundle has an engine verdict');
});
