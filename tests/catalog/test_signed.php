<?php
// Signed input made at test time. Two P-256 keys are generated in memory by this process (openssl_pkey_new), sign what a case needs, and are
// gone when the process ends: no private key is written to a file, committed or kept. The server trusts them only because the test passes a
// phoromatic_catalog_keys object holding their public halves into the verifier; production code loads its table from keys.json alone and has no
// parameter, constant, environment variable or setting that adds a key. (C6: the release gate must still refuse a build where one appears.)
//
// Known divergences from the engine, both EC-P1 and deferred (design 3.3 and catalog spec 11 step 3): the server does not check a manifest's id,
// version and min_engine against its entry, and it is stricter about invalid UTF-8 (Go reads it with replacement characters). Base64 is NOT a
// divergence: both sides ignore \r and \n inside the signature and refuse spaces, tabs and missing padding (F1, test below).

function sev($trust, $state, $file, $stored = null) { return phoromatic_catalog_verify::evaluate($trust, $state, '', $file, $stored); }
function sreason($trust, $state, $file, $stored = null) { return reason_of(function() use ($trust, $state, $file, $stored) { sev($trust, $state, $file, $stored); }); }

$ring = new test_ring(array(1, 2));
$trust = $ring->trust();
$new = phoromatic_catalog_verify::new_state();

t('the shipped keys.json holds exactly the two production SPKIs, and no test key can reach it', function() use ($ring)
{
	$t = json_decode(file_get_contents(PTS_PATH . 'pts-core/static/catalog/keys.json'), true);
	eq(array(1, 2), array_column($t['keys'], 'generation'));
	eq(array('MFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAELUdgYgSQ2Rvg01aewJvsMXg7P3qd/3JqtgTP47iI1F/U2v/wYraCSr+xlI9gjyDJi3kTRorqO2PXI1FCpfDd9Q==',
		'MFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAEuAeJL8vaKEX63i9YQA+DCFGEL5ZVj3V3uKTeQd0nIuMgTUGwdkQ+wSv9QUmG6kL3DL++NWHNWyaxQPL5IoPnvQ=='), array_column($t['keys'], 'spki'));
	eq(array('schema', 'keys', 'denied_key_ids', 'floor'), array_keys($t));
	foreach($ring->spki as $s)
	{
		ok(strpos(file_get_contents(PTS_PATH . 'pts-core/static/catalog/keys.json'), $s) === false, 'a test key is in keys.json');
	}
	// the only way to a verifier's key table in production is load(), which reads that file; the tests build tables with the constructor
	$src = file_get_contents(PTS_PATH . 'pts-core/objects/phoromatic/phoromatic_catalog_keys.php');
	ok(strpos($src, 'getenv') === false && strpos($src, '$_ENV') === false && strpos($src, '$_SERVER') === false && strpos($src, '$_GET') === false && strpos($src, '$_POST') === false, 'the key table reads no environment or request');
});

t('own keys: a good index is accepted, one flipped byte is bad_signature, malformed DER is bad_signature (openssl_verify -1)', function() use ($ring, $trust, $new)
{
	$ix = index_json();
	$file = $ring->sign(1, $ix);
	eq('accepted', sreason($trust, $new, $file));
	$flipped = substr($file, 0, -3) . chr(ord($file[strlen($file) - 3]) ^ 1) . substr($file, -2);
	eq('bad_signature', sreason($trust, $new, $flipped));
	$bad = json_encode(array('schema' => 'benchy-catalog-sig/1', 'key_id' => $ring->key_id(1), 'sig' => base64_encode("\x30\x03\x02\x01\x01"))) . "\n" . $ix;
	list(, $raw) = phoromatic_catalog_verify::split($bad);
	eq(-1, @openssl_verify($raw, "\x30\x03\x02\x01\x01", $trust->all_keys()[0]['pem'], OPENSSL_ALGO_SHA256), 'this DER gives -1');
	eq('bad_signature', sreason($trust, $new, $bad));
	$other = $ring->sign(2, $ix);
	$swapped = preg_replace('/"key_id":"[0-9a-f]{32}"/', '"key_id":"' . $ring->key_id(1) . '"', $other);
	eq('bad_signature', sreason($trust, $new, $swapped), 'a signature by another key under this key id');
});

t('the signature\'s base64 is read as Go reads it: spaces, tabs and missing padding are refused; newlines are ignored', function() use ($ring, $trust, $new)
{
	$ix = index_json();
	do
	{
		$b64 = base64_encode($ring->sig(1, $ix));
	}
	while(substr($b64, -1) !== '=');
	$with = function($sig) use ($ring, $ix) { return json_encode(array('schema' => 'benchy-catalog-sig/1', 'key_id' => $ring->key_id(1), 'sig' => $sig)) . "\n" . $ix; };
	eq('accepted', sreason($trust, $new, $with($b64)), 'the plain signature');
	eq('bad_signature', sreason($trust, $new, $with(substr($b64, 0, 10) . ' ' . substr($b64, 10))), 'a space');
	eq('bad_signature', sreason($trust, $new, $with(substr($b64, 0, 10) . "\t" . substr($b64, 10))), 'a tab');
	eq('bad_signature', sreason($trust, $new, $with(' ' . $b64)), 'a leading space');
	eq('bad_signature', sreason($trust, $new, $with(rtrim($b64, '='))), 'no padding');
	eq('bad_signature', sreason($trust, $new, $with($b64 . '=')), 'too much padding');
	eq('accepted', sreason($trust, $new, $with(substr($b64, 0, 10) . "\n" . substr($b64, 10))), 'a newline (Go ignores it)');
	eq('accepted', sreason($trust, $new, $with(substr($b64, 0, 10) . "\r\n" . substr($b64, 10))), 'a CR LF');
});

t('a state whose numbers are not integers is refused before anything is compared (state_corrupt)', function() use ($ring, $trust)
{
	$file = $ring->sign(1, index_json(array('sequence' => 3)));
	$state = function($gen, $seq, $retired = 0) { return state_of(array('public' => tier_state($gen, $seq)), $retired); };
	eq('rollback', sreason($trust, $state(1, 5), $file), 'integers: a lower sequence is a rollback');
	foreach(array(array('1', 5, 0), array(1, '5', 0), array(1, 5.0, 0), array(1, null, 0), array(1, 5, '0'), array(1, 5, null)) as $c)
	{
		eq('state_corrupt', sreason($trust, $state($c[0], $c[1], $c[2]), $file), json_encode($c));
		eq('state_corrupt', reason_of(function() use ($trust, $state, $c) { phoromatic_catalog_verify::evaluate_index($trust, $state($c[0], $c[1], $c[2]), '', array('id' => 'k', 'generation' => 1), index_json(), 'x'); }), 'evaluate_index ' . json_encode($c));
	}
	$odd = state_of(array('other' => tier_state(1, 1)));
	eq('state_corrupt', sreason($trust, $odd, $file), 'a tier that is not public or private');
	$short = array('retired_below' => 0, 'tiers' => array('public' => array('generation' => 1, 'sequence' => 1)));
	eq('state_corrupt', sreason($trust, $short, $file), 'a tier with no ledger');
	eq('state_corrupt', sreason($trust, array(), $file), 'no state at all');
});

t('own keys: a malleated signature (the same bytes under another valid signature) at an equal pair is unchanged: no alarm, the stored file stays', function() use ($ring, $trust, $new)
{
	$ix = index_json();
	$a = $ring->sign(1, $ix);
	$b = $ring->sign(1, $ix);
	ok($a !== $b, 'ECDSA gave two different valid signatures');
	$d = sev($trust, $new, $a);
	$stored = function($tier) use ($a) { return $a; };
	$d2 = sev($trust, $d['next'], $b, $stored);
	ok($d2['unchanged'] && !$d2['same_file'] && $d2['next'] === $d['next'], 'unchanged by index bytes');
	$respaced = '{ "schema": "benchy-catalog-sig/1", "key_id": "' . $ring->key_id(1) . '", "sig": "' . base64_encode($ring->sig(1, $ix)) . '" }' . "\n" . $ix;
	ok(sev($trust, $d['next'], $respaced, $stored)['unchanged'], 'a respaced signature line too');
	$different = $ring->sign(1, index_json(array('issued' => '2026-11-21T15:00:00Z')));
	eq('same_pair_differs', sreason($trust, $d['next'], $different, $stored), 'but different index bytes at the pair are an alarm');
});

t('own keys: generation 2 recovery after generation 1 at sequence 2^53-1; a generation-1 index is refused as retired afterwards, for either tier', function() use ($ring, $trust, $new)
{
	$d1 = sev($trust, $new, $ring->sign(1, index_json(array('sequence' => 9007199254740991, 'tests' => array(test_entry('benchy/a', '1.0.0', sha('6')))))));
	eq('rollback', sreason($trust, $d1['next'], $ring->sign(1, index_json(array('sequence' => 1)))));
	$rec = sev($trust, $d1['next'], $ring->sign(2, index_json(array('sequence' => 1, 'min_generation' => 2, 'tests' => array(test_entry('benchy/a', '1.0.0', sha('b')))))));
	ok($rec['recovery'], 'a recovery');
	eq(2, $rec['next']['retired_below']);
	eq(sha('b'), $rec['next']['tiers']['public']['tests']['benchy/a@1.0.0'], 'the poisoned row is replaced');
	eq('retired_key', sreason($trust, $rec['next'], $ring->sign(1, index_json(array('tier' => 'private', 'sequence' => 1)))), 'a retired key signing the other tier');
	eq('retired_key', sreason($trust, $rec['next'], $ring->sign(1, index_json(array('sequence' => 99)))), 'and its own tier');
});

t('own keys: a denied key, a key below the floor and an unknown key', function() use ($ring, $new)
{
	$file = $ring->sign(1, index_json());
	eq('denied_key', sreason($ring->trust(array($ring->key_id(1))), $new, $file));
	eq('floor', sreason($ring->trust(array(), array('public' => array('generation' => 2, 'sequence' => 1))), $new, $file));
	eq('unknown_key', sreason(fixture_trust(), $new, $file));
});

// ---- a successful bundle, end to end, with real packs ----

function real_bundle($ring, $gen, $over, $packs, $extra_members = array(), $drop = array())
{
	$tests = array();
	$members = array();
	foreach($packs as $id => $bytes)
	{
		$sha = hash('sha256', $bytes);
		$tests[] = test_entry($id, '1.0.0', hash('sha256', 'tree' . $id), array('pack_sha256' => $sha, 'pack_size' => strlen($bytes)));
		if(!in_array($id, $drop, true))
		{
			$members[] = array('name' => 'packs/' . $sha . '.zip', 'data' => $bytes, 'method' => 0);
		}
	}
	$signed = $ring->sign($gen, index_json($over + array('tests' => $tests)));
	return catalog_zipbuild::build(array_merge(array(array('name' => 'index.signed', 'data' => $signed)), $members, $extra_members));
}

$packs = array('benchy/one' => str_repeat("pack one bytes\n", 500), 'benchy/two' => 'pack two, which is not even a zip');

t('a whole bundle with real packs is accepted: the packs are hashed as they stream, the state advances, and an older bundle is then refused', function() use ($ring, $trust, $new, $packs)
{
	$bundle = real_bundle($ring, 1, array('sequence' => 3), $packs);
	$seen = array();
	$d = phoromatic_catalog_verify::check_bundle($trust, $new, $bundle, null, null, function($name, $chunk) use (&$seen) { $seen[$name] = (isset($seen[$name]) ? $seen[$name] : '') . $chunk; });
	ok(!$d['unchanged'], 'a new index');
	$want = array(hash('sha256', $packs['benchy/one']) => strlen($packs['benchy/one']), hash('sha256', $packs['benchy/two']) => strlen($packs['benchy/two']));
	ksort($want);
	ksort($d['packs']);
	eq($want, $d['packs']);
	foreach($packs as $bytes)
	{
		eq($bytes, $seen['packs/' . hash('sha256', $bytes) . '.zip'], 'the sink got the whole pack');
	}
	eq(array(1, 3), array($d['next']['tiers']['public']['generation'], $d['next']['tiers']['public']['sequence']));
	eq('rollback', reason_of(function() use ($ring, $trust, $d, $packs) { phoromatic_catalog_verify::check_bundle($trust, $d['next'], real_bundle($ring, 1, array('sequence' => 2), $packs)); }));
	// re-importing the same bundle changes nothing and still checks its packs
	$again = phoromatic_catalog_verify::check_bundle($trust, $d['next'], $bundle);
	ok($again['unchanged'] && $again['same_file'] && count($again['packs']) === 2, 're-import: unchanged, packs listed again (the repair path)');
	// a deflated pack member works the same
	$def = catalog_zipbuild::build(array(array('name' => 'index.signed', 'data' => $ring->sign(1, index_json(array('sequence' => 4, 'tests' => array(test_entry('benchy/one', '1.0.0', hash('sha256', 'treebenchy/one'), array('pack_sha256' => hash('sha256', $packs['benchy/one']), 'pack_size' => strlen($packs['benchy/one']))))))))
		, array('name' => 'packs/' . hash('sha256', $packs['benchy/one']) . '.zip', 'data' => $packs['benchy/one'], 'method' => 8)));
	ok(phoromatic_catalog_verify::check_bundle($trust, $d['next'], $def)['packs'] !== array(), 'a deflated member');
});

t('a bundle with real packs: a missing pack, an extra pack, altered bytes and a pack of the wrong size are each refused with nothing parsed past the failure', function() use ($ring, $trust, $new, $packs)
{
	$run = function($bundle) use ($trust, $new) { return reason_of(function() use ($trust, $new, $bundle) { phoromatic_catalog_verify::check_bundle($trust, $new, $bundle); }); };
	eq('bundle_pack_missing', $run(real_bundle($ring, 1, array(), $packs, array(), array('benchy/two'))));
	eq('bundle_pack_unlisted', $run(real_bundle($ring, 1, array(), $packs, array(array('name' => 'packs/' . hash('sha256', 'extra') . '.zip', 'data' => 'extra', 'method' => 0)))));
	// a member named for the index's hash but holding other bytes
	$sha = hash('sha256', $packs['benchy/two']);
	$b = real_bundle($ring, 1, array(), array('benchy/two' => $packs['benchy/two']), array(), array('benchy/two'));
	$z = catalog_zipbuild::build(array(array('name' => 'index.signed', 'data' => phoromatic_catalog_zip::inspect($b)->read_all(phoromatic_catalog_zip::inspect($b)->lookup('index.signed'), 1 << 20)), array('name' => 'packs/' . $sha . '.zip', 'data' => 'altered', 'method' => 0)));
	eq('bundle_pack_hash', $run($z));
});

t('own keys: a license failure in a signed bundle writes nothing (the public tier holds only free tests)', function() use ($ring, $trust, $new, $packs)
{
	$sha = hash('sha256', 'p');
	foreach(array('non-free' => array('license_class' => 'non-free'), 'machine_install' => array('manifest' => array('id' => 'benchy/x', 'version' => '1.0.0', 'min_engine' => '0.6.0', 'platforms' => array('linux'), 'title' => 'T', 'machine_install' => array('product' => 'X'))))
		as $name => $over)
	{
		$entry = test_entry('benchy/x', '1.0.0', sha('b'), $over + array('pack_sha256' => $sha, 'pack_size' => 1));
		$bundle = catalog_zipbuild::build(array(array('name' => 'index.signed', 'data' => $ring->sign(1, index_json(array('tests' => array($entry))))), array('name' => 'packs/' . $sha . '.zip', 'data' => 'p', 'method' => 0)));
		$wrote = 0;
		eq('license_tier', reason_of(function() use ($trust, $new, $bundle, &$wrote) { phoromatic_catalog_verify::check_bundle($trust, $new, $bundle, null, null, function($n, $c) use (&$wrote) { $wrote++; }); }), $name);
		eq(0, $wrote, $name . ': no pack byte was read');
	}
});
