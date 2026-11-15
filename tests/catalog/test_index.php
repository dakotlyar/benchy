<?php
// Catalog spec 13, "index" and the server-only rules (design 3.3, 14.2): every rule below the signature, through evaluate_index() with
// synthetic index bytes. The signature itself is covered by the engine fixtures in test_fixtures.php.

function idx_reason($raw, $state = null, $gen = 1, $trust = null)
{
	return reason_of(function() use ($raw, $state, $gen, $trust) { ev($raw, $state, $gen, $trust); });
}

t('a plain index is accepted and its entries become the ledger', function()
{
	$d = ev(index_json(array('tests' => array(test_entry('benchy/a', '1.0.0', sha('1')), test_entry('benchy/b', '2.0.0', sha('2'))),
		'suites' => array(suite_entry('benchy/s', '1.0.0', array(array('benchy/a'), array('benchy/b', '2.0.0', array('x' => 'y'))))), 'revoked' => array(revoked_entry('benchy/old', '0.1.0')))));
	ok(!$d['unchanged'] && !$d['recovery'], 'a plain accept');
	$t = $d['next']['tiers']['public'];
	eq(array('benchy/a@1.0.0' => sha('1'), 'benchy/b@2.0.0' => sha('2')), $t['tests']);
	eq(array('benchy/old@0.1.0'), $t['revoked']);
	eq(array(1, 1), array($t['generation'], $t['sequence']));
	eq(1, count($t['suites']));
});

t('suite_sha256 follows catalog spec 3 (independent vectors)', function()
{
	// computed outside PHP with hashlib: sha256(b"benchy/a\0\0" + b"0\0")
	eq(hash('sha256', "benchy/a\0\0" . "0\0"), phoromatic_catalog_verify::suite_sha256(array(array('test' => 'benchy/a', 'version' => null, 'options' => array()))));
	eq(hash('sha256', "benchy/a\0" . "1.0.0\0" . "2\0" . "a\0" . "1\0" . "b\0" . "2\0" . "benchy/b\0\0" . "0\0"),
		phoromatic_catalog_verify::suite_sha256(array(array('test' => 'benchy/a', 'version' => '1.0.0', 'options' => array(array('b', '2'), array('a', '1'))), array('test' => 'benchy/b', 'version' => null, 'options' => array()))));
});

t('sequence out of range: 0, -1, 2^53 and above are bad_index; 1 and 2^53-1 are accepted', function()
{
	foreach(array(0, -1, 9007199254740992) as $s)
	{
		eq('bad_index', idx_reason(index_json(array('sequence' => $s))), 'sequence ' . $s);
	}
	eq('bad_index', idx_reason(str_replace('"sequence":1,', '"sequence":18446744073709551616,', index_json())), 'a big integer');
	eq('accepted', idx_reason(index_json(array('sequence' => 1))));
	eq('accepted', idx_reason(index_json(array('sequence' => 9007199254740991))));
});

t('server-only: a float or a big integer where an integer is needed is bad_index', function()
{
	eq('bad_index', idx_reason(str_replace('"sequence":1,', '"sequence":1.0,', index_json())), 'sequence 1.0');
	eq('bad_index', idx_reason(str_replace('"sequence":1,', '"sequence":1e0,', index_json())), 'sequence 1e0');
	eq('bad_index', idx_reason(str_replace('"sequence":1,', '"sequence":"1",', index_json())), 'sequence as a string');
	eq('bad_index', idx_reason(str_replace('"min_generation":null', '"min_generation":1.0', index_json())), 'min_generation 1.0');
	eq('bad_index', idx_reason(str_replace('"min_generation":null', '"min_generation":99999999999999999999', index_json())), 'min_generation, a big integer');
	eq('bad_index', idx_reason(index_json(array('tests' => array(test_entry('benchy/a', '1.0.0', null, array('pack_size' => 1.5)))))), 'pack_size 1.5');
	eq('bad_index', idx_reason(str_replace('"pack_size":100', '"pack_size":1e2', index_json())), 'pack_size 1e2');
});

t('min_generation above the signing key\'s generation is bad_index; at it is accepted', function()
{
	eq('bad_index', idx_reason(index_json(array('min_generation' => 2)), null, 1));
	eq('bad_index', idx_reason(index_json(array('min_generation' => 0)), null, 1));
	eq('accepted', idx_reason(index_json(array('min_generation' => 1)), null, 1));
	eq('accepted', idx_reason(index_json(array('min_generation' => 2)), null, 2));
});

t('a lower pair is refused (rollback), also across generations', function()
{
	eq('rollback', idx_reason(index_json(array('sequence' => 4)), state_of(array('public' => tier_state(1, 5)))));
	eq('rollback', idx_reason(index_json(array('sequence' => 99)), state_of(array('public' => tier_state(2, 1))), 1));
	eq('accepted', idx_reason(index_json(array('sequence' => 6)), state_of(array('public' => tier_state(1, 5)))));
	eq('accepted', idx_reason(index_json(array('sequence' => 1)), state_of(array('public' => tier_state(1, 5))), 2), 'a higher generation wins whatever its sequence');
	eq('accepted', idx_reason(index_json(array('tier' => 'private', 'sequence' => 1)), state_of(array('public' => tier_state(1, 5)))), 'the other tier has its own pair');
});

t('a successful import advances the state and an older bundle is then refused', function()
{
	$d = ev(index_json(array('sequence' => 7)));
	eq('rollback', idx_reason(index_json(array('sequence' => 6)), $d['next']));
	$d2 = ev(index_json(array('sequence' => 8)), $d['next']);
	eq(8, $d2['next']['tiers']['public']['sequence']);
});

t('an equal pair: the same index bytes are unchanged (the stored file stays), different bytes are same_pair_differs', function()
{
	$raw = index_json(array('sequence' => 3));
	$state = state_of(array('public' => tier_state(1, 3, array('benchy/a@1.0.0' => sha('b')))));
	$d = ev($raw, $state, 1, null, stored_file($raw));
	ok($d['unchanged'] && !$d['same_file'] && $d['next'] === $state, 'unchanged, state not moved');
	eq('same_pair_differs', reason_of(function() use ($raw, $state) { ev($raw, $state, 1, null, stored_file(index_json(array('sequence' => 3, 'issued' => '2026-11-21T15:00:00Z')))); }));
	$d = ev($raw, $state, 1, null, function($t) { return null; });
	ok(!$d['unchanged'], 'with the stored file missing it is a fresh accept');
	$d = ev($raw, $state, 1, null, function($t) { return 'no newline in a damaged file'; });
	ok(!$d['unchanged'], 'and so is a damaged one');
});

t('below the floor on first contact is refused (floor), with the floor from the table', function()
{
	$trust = fixture_trust(array(), array(), array('public' => array('generation' => 1, 'sequence' => 10)));
	eq('floor', idx_reason(index_json(array('sequence' => 9)), null, 1, $trust));
	eq('accepted', idx_reason(index_json(array('sequence' => 10)), null, 1, $trust));
	eq('accepted', idx_reason(index_json(array('sequence' => 9)), null, 2, $trust), 'a higher generation clears it');
	eq('accepted', idx_reason(index_json(array('tier' => 'private', 'sequence' => 1)), null, 1, $trust), 'a public floor is not the private tier\'s');
});

t('a key below retired_below is refused for every tier, including one never held (state-level)', function()
{
	// the signature step refuses it (test_fixtures); here the stored tier of a retired generation is refused on re-import
	$good = fixture_file('index-good.signed');
	$state = state_of(array('public' => tier_state(1, 1, array(), array(), array(), hash('sha256', $good))), 2);
	eq('retired_key', reason_of(function() use ($good, $state) { phoromatic_catalog_verify::evaluate(fixture_trust(), $state, '', $good); }));
	$other_tier = state_of(array(), 2);
	eq('retired_key', reason_of(function() use ($good, $other_tier) { phoromatic_catalog_verify::evaluate(fixture_trust(), $other_tier, '', $good); }), 'a machine that never held the tier');
});

t('correctly signed malformed JSON is bad_index', function()
{
	foreach(array('', 'not json', '[]', '"x"', '{', '{"schema":"benchy-catalog-index/1"} trailing', "{\"schema\":\"benchy-catalog-index/1\"}\xff") as $raw)
	{
		eq('bad_index', idx_reason($raw), json_encode($raw));
	}
	eq('bad_index', idx_reason(index_json(array('schema' => 'benchy-catalog-index/2'))), 'another major version');
	eq('bad_index', idx_reason(index_json(array('tests' => 'x'))), 'tests is not a list');
	eq('bad_index', idx_reason(index_json(array('tests' => array('x')))), 'a test is not an object');
	eq('bad_index', idx_reason(index_json(array('tests' => array(test_entry('benchy/a', '1.0.0', null, array('platforms' => 'linux')))))), 'platforms is a string');
	eq('bad_index', idx_reason(index_json(array('issued' => 'tomorrow'))), 'issued');
	eq('bad_index', idx_reason(index_json(array('issued' => '2026-02-30T15:00:00Z'))), 'issued, not a day');
	eq('accepted', idx_reason(index_json(array('issued' => '2026-11-20T15:00:00.5+02:00'))), 'issued with a fraction and an offset');
});

t('the tier is public or private (bad_tier); the repo is benchy; a pts/ id is bad_index', function()
{
	eq('bad_tier', idx_reason(index_json(array('tier' => 'corp'))));
	eq('bad_index', idx_reason(index_json(array('repo' => 'pts'))));
	eq('bad_index', idx_reason(index_json(array('repo' => 'Benchy'))));
	eq('bad_index', idx_reason(index_json(array('tests' => array(test_entry('pts/stream'))))));
});

t('ids: a backslash, a colon, "..", uppercase, a leading or trailing dot, over 71 bytes, are each bad_index', function()
{
	foreach(array('benchy\\a', 'benchy/a:b', 'benchy/..', 'benchy/a..b', 'benchy/A', 'benchy/Stream', 'benchy/.a', 'benchy/a.', 'benchy/a-', 'benchy/', 'benchy/a/b', 'benchy/' . str_repeat('a', 65), 'a', '', 'benchy/a b') as $id)
	{
		eq('bad_index', idx_reason(index_json(array('tests' => array(test_entry($id))))), 'test id ' . json_encode($id));
		eq('bad_index', idx_reason(index_json(array('tests' => array(test_entry('benchy/t')), 'suites' => array(suite_entry($id, '1.0.0', array(array('benchy/t'))))))), 'suite id ' . json_encode($id));
	}
	eq('accepted', idx_reason(index_json(array('tests' => array(test_entry('benchy/' . str_repeat('a', 64)))))), 'exactly 71 bytes');
	eq('accepted', idx_reason(index_json(array('tests' => array(test_entry('benchy/a.b_c-d1'))))));
});

t('versions are x.y.z with no leading zero, no suffix; so is min_engine', function()
{
	foreach(array('1.0', '1.0.0.0', '01.0.0', '1.0.0-rc1', '1.0.0+b', 'v1.0.0', '1.0.0 ', "1.0.0\n", '', '1.a.0') as $v)
	{
		eq('bad_index', idx_reason(index_json(array('tests' => array(test_entry('benchy/a', $v))))), 'version ' . json_encode($v));
		eq('bad_index', idx_reason(index_json(array('tests' => array(test_entry('benchy/a', '1.0.0', null, array('min_engine' => $v)))))), 'min_engine ' . json_encode($v));
	}
	eq('accepted', idx_reason(index_json(array('tests' => array(test_entry('benchy/a', '0.0.0'), test_entry('benchy/b', '10.20.30'))))));
});

t('entry fields: format, hashes, pack_size, platforms and license_class are checked', function()
{
	$cases = array('format' => 'zip', 'pack_sha256' => sha('A'), 'tree_sha256' => 'abc', 'pack_size' => 0, 'platforms' => array(), 'license_class' => '');
	foreach($cases as $field => $value)
	{
		eq('bad_index', idx_reason(index_json(array('tests' => array(test_entry('benchy/a', '1.0.0', null, array($field => $value)))))), $field);
	}
	eq('bad_index', idx_reason(index_json(array('tests' => array(test_entry('benchy/a', '1.0.0', null, array('pack_size' => 67108865)))))), 'pack_size over 64 MiB');
	eq('accepted', idx_reason(index_json(array('tests' => array(test_entry('benchy/a', '1.0.0', null, array('pack_size' => 67108864)))))), 'pack_size at 64 MiB');
	eq('bad_index', idx_reason(index_json(array('tests' => array(test_entry('benchy/a', '1.0.0', null, array('manifest' => 'x')))))), 'manifest is not an object');
});

t('duplicate id@version is bad_index, in tests, in suites and between them; a test and a suite may not share an id', function()
{
	eq('bad_index', idx_reason(index_json(array('tests' => array(test_entry('benchy/a'), test_entry('benchy/a'))))));
	eq('accepted', idx_reason(index_json(array('tests' => array(test_entry('benchy/a', '1.0.0'), test_entry('benchy/a', '1.1.0'))))), 'two versions of one id');
	$s = suite_entry('benchy/s', '1.0.0', array(array('benchy/a')));
	eq('bad_index', idx_reason(index_json(array('suites' => array($s, $s)))));
	eq('bad_index', idx_reason(index_json(array('suites' => array(suite_entry('benchy/a', '1.0.0', array(array('benchy/a'))))))), 'a suite and a test with one id and version');
	eq('bad_index', idx_reason(index_json(array('suites' => array(suite_entry('benchy/a', '2.0.0', array(array('benchy/a'))))))), 'a suite and a test with one id');
});

t('a suite naming a test outside the index is bad_index, including benchy/stream; a pinned version must be there too', function()
{
	eq('bad_index', idx_reason(index_json(array('suites' => array(suite_entry('benchy/s', '1.0.0', array(array('benchy/stream'))))))), 'benchy/stream, which some engines build in');
	eq('bad_index', idx_reason(index_json(array('suites' => array(suite_entry('benchy/s', '1.0.0', array(array('benchy/a'), array('benchy/zzz'))))))));
	eq('bad_index', idx_reason(index_json(array('suites' => array(suite_entry('benchy/s', '1.0.0', array(array('benchy/a', '9.9.9'))))))), 'a version that isn\'t there');
	eq('bad_index', idx_reason(index_json(array('suites' => array(suite_entry('benchy/s', '1.0.0', array(array('benchy/a', 'x'))))))), 'a pinned version that isn\'t x.y.z');
	eq('accepted', idx_reason(index_json(array('suites' => array(suite_entry('benchy/s', '1.0.0', array(array('benchy/a', '1.0.0'))))))));
	eq('bad_index', idx_reason(index_json(array('suites' => array(suite_entry('benchy/s', '1.0.0', array()))))), 'a suite with no tests');
});

t('suites: the hash, the option keys and the option values are checked', function()
{
	$s = suite_entry('benchy/s', '1.0.0', array(array('benchy/a')));
	$s['suite_sha256'] = sha('0');
	eq('bad_index', idx_reason(index_json(array('suites' => array($s)))), 'a suite_sha256 that isn\'t the hash of its tests');
	$s = suite_entry('benchy/s', '1.0.0', array(array('benchy/a')));
	$s['suite_sha256'] = 'XYZ';
	eq('bad_index', idx_reason(index_json(array('suites' => array($s)))), 'a suite_sha256 that isn\'t hex');
	$hand = function($opts) { return array('id' => 'benchy/s', 'version' => '1.0.0', 'title' => 'S', 'description' => '', 'min_engine' => '0.6.0', 'suite_sha256' => sha('0'),
		'tests' => array(array('test' => 'benchy/a', 'version' => null, 'options' => (object)$opts))); };
	eq('bad_index', idx_reason(index_json(array('suites' => array($hand(array('bad key' => 'v')))))), 'an option key with a space');
	eq('bad_index', idx_reason(index_json(array('suites' => array($hand(array('k' => "v\0w")))))), 'a NUL in a value');
	eq('bad_index', idx_reason(index_json(array('suites' => array($hand(array('k' => 5)))))), 'a value that is not a string');
	// an option map keeps its keys' case: it is a map, not a record
	eq('accepted', idx_reason(index_json(array('suites' => array(suite_entry('benchy/s', '1.0.0', array(array('benchy/a', null, array('Threads' => 'Max', 'THREADS2' => '1')))))))), 'option keys of any case');
});

t('over each limit: 1001 tests and 1001 suites are refused as limit; 1000 are accepted', function()
{
	$tests = array();
	for($i = 0; $i < 1001; $i++)
	{
		$tests[] = test_entry('benchy/t' . $i);
	}
	eq('limit', idx_reason(index_json(array('tests' => $tests))));
	eq('accepted', idx_reason(index_json(array('tests' => array_slice($tests, 0, 1000)))));
	$suites = array();
	for($i = 0; $i < 1001; $i++)
	{
		$suites[] = suite_entry('benchy/s' . $i, '1.0.0', array(array('benchy/t0')));
	}
	eq('limit', idx_reason(index_json(array('tests' => array_slice($tests, 0, 1), 'suites' => $suites))));
	eq('accepted', idx_reason(index_json(array('tests' => array_slice($tests, 0, 1), 'suites' => array_slice($suites, 0, 1000)))));
});

t('over each limit: index.signed over 16 MiB plus 4 KiB, a signature line over 4096 bytes', function()
{
	$line = '{"schema":"benchy-catalog-sig/1","key_id":"' . str_repeat('0', 32) . '","sig":"' . str_repeat('A', 100) . '"}';
	eq('limit', reason_of(function() use ($line) { phoromatic_catalog_verify::split($line . "\n" . str_repeat('x', phoromatic_catalog_verify::MAX_INDEX_BYTES)); }), 'a file over the limit');
	eq('accepted', reason_of(function() use ($line) { phoromatic_catalog_verify::split($line . "\n" . str_repeat('x', phoromatic_catalog_verify::MAX_INDEX_BYTES - strlen($line) - 1)); }), 'a file at the limit');
	eq('limit', reason_of(function() { phoromatic_catalog_verify::split(str_repeat('x', 4097) . "\nrest"); }), 'a signature line of 4097 bytes');
	eq('accepted', reason_of(function() { phoromatic_catalog_verify::split(str_repeat('x', 4096) . "\nrest"); }), 'a signature line of 4096 bytes');
});

t('the signature line: no newline, not JSON, another schema, a number for the key id, a key spelled in another case are bad_envelope', function()
{
	$f = function($line) { return reason_of(function() use ($line) { phoromatic_catalog_verify::evaluate(fixture_trust(), phoromatic_catalog_verify::new_state(), '', $line . "\n{}"); }); };
	eq('bad_envelope', $f('not json'));
	eq('bad_envelope', $f('{"schema":"benchy-catalog-sig/2","key_id":"x","sig":"x"}'));
	eq('bad_envelope', $f('{"schema":"benchy-catalog-sig/1","key_id":5,"sig":"x"}'));
	eq('bad_envelope', $f('{"Schema":"benchy-catalog-sig/1","key_id":"x","sig":"x"}'));
	eq('bad_envelope', $f('{"schema":"benchy-catalog-sig/1","key_id":"x","SIG":"x"}'));
	eq('bad_envelope', $f('{"schema":"benchy-catalog-sig/1","key_id":"x","sig":"x","ſig":"y"}'), 'a non-ASCII key');
	eq('unknown_key', $f('{"schema":"benchy-catalog-sig/1","key_id":"x","sig":"x"}'), 'a key id that is not hex is just unknown');
	eq('bad_signature', $f('{"schema":"benchy-catalog-sig/1","key_id":"d98e6e86a8584efd075cc0fc8155f980","sig":"not base64!!"}'));
	eq('bad_signature', $f('{"schema":"benchy-catalog-sig/1","key_id":"d98e6e86a8584efd075cc0fc8155f980","sig":""}'));
	eq('bad_signature', $f('{"schema":"benchy-catalog-sig/1","key_id":"d98e6e86a8584efd075cc0fc8155f980"}'));
});

t('a listed version that is revoked is refused (revoked_listed); a revocation of something not listed is fine', function()
{
	eq('revoked_listed', idx_reason(index_json(array('revoked' => array(revoked_entry('benchy/a', '1.0.0'))))));
	eq('accepted', idx_reason(index_json(array('revoked' => array(revoked_entry('benchy/a', '0.9.0'))))));
	eq('accepted', idx_reason(index_json(array('revoked' => array(revoked_entry('benchy/never', '1.0.0'))))));
	eq('bad_index', idx_reason(index_json(array('revoked' => array(revoked_entry('Benchy/a', '1.0.0'))))), 'a revocation with a bad id');
	eq('bad_index', idx_reason(index_json(array('revoked' => array(revoked_entry('benchy/a', '1.0'))))), 'a revocation with a bad version');
});

t('ledger conflict: a test whose tree changed, and a suite whose content changed, are refused; a new version is fine', function()
{
	$state = state_of(array('public' => tier_state(1, 1, array('benchy/a@1.0.0' => sha('b')), array('benchy/s@1.0.0' => sha('9')))));
	eq('ledger_conflict', idx_reason(index_json(array('sequence' => 2, 'tests' => array(test_entry('benchy/a', '1.0.0', sha('c'))))), $state));
	eq('accepted', idx_reason(index_json(array('sequence' => 2, 'tests' => array(test_entry('benchy/a', '1.0.0', sha('b'))))), $state), 'the same tree');
	eq('accepted', idx_reason(index_json(array('sequence' => 2, 'tests' => array(test_entry('benchy/a', '1.1.0', sha('c'))))), $state), 'a new version of the id');
	eq('ledger_conflict', idx_reason(index_json(array('sequence' => 2, 'tests' => array(test_entry('benchy/a')), 'suites' => array(suite_entry('benchy/s', '1.0.0', array(array('benchy/a')))))), $state), 'a suite with another hash');
	$state2 = state_of(array('public' => tier_state(1, 1, array(), array('benchy/s@1.0.0' => suite_entry('benchy/s', '1.0.0', array(array('benchy/a')))['suite_sha256']))));
	eq('accepted', idx_reason(index_json(array('sequence' => 2, 'suites' => array(suite_entry('benchy/s', '1.0.0', array(array('benchy/a')))))), $state2), 'the same suite');
	$state3 = state_of(array('private' => tier_state(1, 1, array('benchy/a@1.0.0' => sha('b')))));
	eq('accepted', idx_reason(index_json(array('sequence' => 1, 'tests' => array(test_entry('benchy/a', '1.0.0', sha('c'))))), $state3), 'the ledger is per tier');
});

t('a dropped revocation is refused (revocation_dropped); keeping it or adding more is accepted', function()
{
	$state = state_of(array('public' => tier_state(1, 1, array(), array(), array('benchy/old@0.1.0'))));
	eq('revocation_dropped', idx_reason(index_json(array('sequence' => 2)), $state));
	eq('revocation_dropped', idx_reason(index_json(array('sequence' => 2, 'revoked' => array(revoked_entry('benchy/other', '1.0.0')))), $state));
	$d = ev(index_json(array('sequence' => 2, 'revoked' => array(revoked_entry('benchy/old', '0.1.0'), revoked_entry('benchy/new', '2.0.0')))), $state);
	eq(array('benchy/new@2.0.0', 'benchy/old@0.1.0'), $d['next']['tiers']['public']['revoked']);
});

t('the ledger and the revocations only grow across a routine rotation (the key generation rises, min_generation null)', function()
{
	$state = state_of(array('public' => tier_state(1, 9, array('benchy/a@1.0.0' => sha('b')), array(), array('benchy/old@0.1.0'))));
	eq('ledger_conflict', idx_reason(index_json(array('sequence' => 1, 'tests' => array(test_entry('benchy/a', '1.0.0', sha('c'))), 'revoked' => array(revoked_entry('benchy/old', '0.1.0')))), $state, 2));
	eq('revocation_dropped', idx_reason(index_json(array('sequence' => 1)), $state, 2));
	$d = ev(index_json(array('sequence' => 1, 'tests' => array(test_entry('benchy/a', '1.0.0', sha('b')), test_entry('benchy/n', '1.0.0', sha('d'))), 'revoked' => array(revoked_entry('benchy/old', '0.1.0')))), $state, 2);
	ok(!$d['recovery'], 'not a recovery');
	eq(array(2, 1), array($d['next']['tiers']['public']['generation'], $d['next']['tiers']['public']['sequence']));
	eq(2, count($d['next']['tiers']['public']['tests']));
});

t('the wanted tier of a route must match the index (bad_tier)', function()
{
	eq('bad_tier', reason_of(function() { ev(index_json(array('tier' => 'private')), null, 1, null, null, null, 'public'); }));
	eq('accepted', reason_of(function() { ev(index_json(array('tier' => 'public')), null, 1, null, null, null, 'public'); }));
});

// ---- the server-only strictness of design 3.3 ----

t('server-only: a key spelled in another case is bad_index (root, test, suite, revoked, manifest)', function()
{
	foreach(array('"min_generation"' => '"Min_Generation"', '"tier"' => '"Tier"', '"sequence"' => '"SEQUENCE"', '"tests"' => '"Tests"') as $from => $to)
	{
		eq('bad_index', idx_reason(str_replace($from, $to, index_json())), $to);
	}
	eq('bad_index', idx_reason(str_replace('"pack_size"', '"Pack_Size"', index_json())), 'a test key');
	eq('bad_index', idx_reason(str_replace('"license_class"', '"License_Class"', index_json())), 'license_class');
	eq('bad_index', idx_reason(str_replace('"manifest":{', '"Manifest":{', index_json())), 'manifest');
	$s = index_json(array('tests' => array(test_entry('benchy/a')), 'suites' => array(suite_entry('benchy/s', '1.0.0', array(array('benchy/a'))))));
	eq('bad_index', idx_reason(str_replace('"suite_sha256"', '"Suite_SHA256"', $s)), 'a suite key');
	eq('bad_index', idx_reason(str_replace('"options"', '"Options"', $s)), 'a suite test key');
	eq('bad_index', idx_reason(str_replace('"reason"', '"REASON"', index_json(array('revoked' => array(revoked_entry('benchy/o', '0.1.0')))))), 'a revoked key');
});

t('server-only: two keys equal under case folding are bad_index, even an unknown one', function()
{
	eq('bad_index', idx_reason(str_replace('"sequence":1,', '"sequence":1,"Sequence":2,', index_json())));
	eq('bad_index', idx_reason(str_replace('"sequence":1,', '"sequence":1,"extra":1,"EXTRA":2,', index_json())));
	eq('accepted', idx_reason(str_replace('"sequence":1,', '"sequence":1,"extra":1,"other":2,', index_json())), 'unknown keys are ignored');
});

t('server-only: a key with a character outside ASCII is bad_index (U+017F "ſequence", the Kelvin sign U+212A)', function()
{
	eq('bad_index', idx_reason(str_replace('"sequence":1,', "\"\u{017F}equence\":9,\"sequence\":1,", index_json())), 'long s');
	eq('bad_index', idx_reason(str_replace('"sequence":1,', "\"\u{017F}equence\":1,", index_json())), 'long s alone, as the only sequence');
	eq('bad_index', idx_reason(str_replace('"tier"', "\"mi\u{212A}\"", index_json())), 'a Kelvin sign key');
	eq('bad_index', idx_reason(str_replace('"license_class"', "\"\u{212A}ind\"", index_json())), 'in a test entry');
	eq('accepted', idx_reason(index_json(array('tests' => array(test_entry('benchy/a', '1.0.0', null, array('software_license' => "M\u{00C9}T"))))) ), 'a value may hold any valid UTF-8');
});

t('server-only: a manifest key spelled in another case, and one under machine_install, is bad_index', function()
{
	foreach(array('Machine_Install', 'MACHINE_INSTALL', 'Elevated_Run', 'elevated_RUN') as $key)
	{
		$m = array('id' => 'benchy/a', 'version' => '1.0.0', 'min_engine' => '0.6.0', 'platforms' => array('linux'), 'title' => 'T');
		$m[$key] = array('product' => 'x');
		eq('bad_index', idx_reason(index_json(array('tests' => array(test_entry('benchy/a', '1.0.0', null, array('manifest' => $m)))))), $key);
	}
	$m = array('id' => 'benchy/a', 'version' => '1.0.0', 'min_engine' => '0.6.0', 'platforms' => array('linux'), 'title' => 'T', 'machine_install' => array('Product' => 'x'));
	eq('bad_index', idx_reason(index_json(array('tests' => array(test_entry('benchy/a', '1.0.0', null, array('manifest' => $m)))))), 'a nested record key');
	$m = array('id' => 'benchy/a', 'version' => '1.0.0', 'min_engine' => '0.6.0', 'platforms' => array('linux'), 'options' => array(array('key' => 'k', 'KIND' => 'text')));
	eq('bad_index', idx_reason(index_json(array('tests' => array(test_entry('benchy/a', '1.0.0', null, array('manifest' => $m)))))), 'a manifest option key');
});

t('the JSON must be valid UTF-8 and nest no deeper than 64', function()
{
	eq('bad_index', idx_reason(str_replace('"title":"T"', "\"title\":\"\xc3\x28\"", index_json())), 'invalid UTF-8');
	$deep = str_repeat('[', 70) . str_repeat(']', 70);
	eq('bad_index', idx_reason(str_replace('"title":"T"', '"title":"T","deep":' . $deep, index_json())), 'nesting over 64');
	$ok = str_repeat('[', 30) . str_repeat(']', 30);
	eq('accepted', idx_reason(str_replace('"title":"T"', '"title":"T","deep":' . $ok, index_json())), 'nesting of 30');
});

// ---- the license check (catalog spec 9.3, check 4) ----

function manifest_with($extra, $class = 'free')
{
	$m = array('id' => 'benchy/a', 'version' => '1.0.0', 'min_engine' => '0.6.0', 'platforms' => array('linux'), 'title' => 'T') + $extra;
	return index_json(array('tests' => array(test_entry('benchy/a', '1.0.0', null, array('manifest' => $m, 'license_class' => $class)))));
}

t('a public index may hold only free tests: non-free, unstated, machine_install and elevated_run are license_tier, one each', function()
{
	eq('license_tier', idx_reason(manifest_with(array(), 'non-free')), 'non-free');
	eq('license_tier', idx_reason(manifest_with(array(), 'unstated')), 'unstated');
	eq('license_tier', idx_reason(manifest_with(array(), 'Free')), 'a license class that is not exactly free');
	eq('license_tier', idx_reason(manifest_with(array('machine_install' => array('product' => 'X', 'message' => 'm', 'file' => 'f', 'kind' => 'exe', 'detect' => array('x'))))), 'machine_install');
	eq('license_tier', idx_reason(manifest_with(array('elevated_run' => array('message' => 'm')))), 'elevated_run');
	eq('license_tier', idx_reason(manifest_with(array('machine_install' => null))), 'machine_install: null still counts');
	eq('license_tier', idx_reason(manifest_with(array('elevated_run' => false))), 'elevated_run: false still counts');
	eq('license_tier', idx_reason(manifest_with(array('machine_install' => array()))), 'machine_install: an empty list still counts');
	eq('accepted', idx_reason(manifest_with(array())), 'a free test with neither');
});

t('the private tier may hold non-free, machine_install and elevated_run tests', function()
{
	$m = array('id' => 'benchy/a', 'version' => '1.0.0', 'min_engine' => '0.6.0', 'platforms' => array('linux'), 'title' => 'T', 'machine_install' => array('product' => 'X'), 'elevated_run' => array('message' => 'm'));
	eq('accepted', idx_reason(index_json(array('tier' => 'private', 'tests' => array(test_entry('benchy/a', '1.0.0', null, array('manifest' => $m, 'license_class' => 'non-free')))))));
});

t('a license failure writes nothing: no next state, and the refusal comes after the ledger checks', function()
{
	$state = state_of(array('public' => tier_state(1, 1, array('benchy/a@1.0.0' => sha('b')))));
	$before = $state;
	eq('license_tier', idx_reason(index_json(array('sequence' => 2, 'tests' => array(test_entry('benchy/a', '1.0.0', sha('b'), array('license_class' => 'non-free'))))), $state));
	eq($before, $state, 'the state passed in');
	eq('ledger_conflict', idx_reason(index_json(array('sequence' => 2, 'tests' => array(test_entry('benchy/a', '1.0.0', sha('c'), array('license_class' => 'non-free'))))), $state), 'the ledger is checked first');
});

// ---- forward compatibility ----

t('an entry with a future min_engine and an unknown option kind is accepted (the server does not validate manifests), and a suite naming it too', function()
{
	$m = array('id' => 'benchy/future', 'version' => '3.0.0', 'min_engine' => '9.0.0', 'platforms' => array('linux'), 'title' => 'T', 'surprise' => array('x' => 1),
		'options' => array(array('key' => 'k', 'label' => 'K', 'kind' => 'hologram', 'default' => '1')));
	$d = ev(index_json(array('tests' => array(test_entry('benchy/future', '3.0.0', null, array('manifest' => $m, 'min_engine' => '9.0.0'))),
		'suites' => array(suite_entry('benchy/fs', '1.0.0', array(array('benchy/future'), array('benchy/future', '3.0.0')), array('min_engine' => '9.0.0'))))));
	eq(array('benchy/future@3.0.0' => sha('b')), $d['next']['tiers']['public']['tests']);
	eq(1, count($d['next']['tiers']['public']['suites']));
});

t('unknown keys at every level are ignored', function()
{
	$raw = str_replace(array('"repo":"benchy",', '"format":"native",'), array('"repo":"benchy","future":1,', '"format":"native","tomorrow":[1],'), index_json());
	eq('accepted', idx_reason($raw));
});
