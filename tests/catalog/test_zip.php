<?php
// Catalog spec 7.1 and 7.2: the raw zip reader beyond what the committed bundle fixtures cover (those are in test_fixtures.php, judged by the
// engine's verdicts), and the pack membership rules.

$fx = function($name) { return file_get_contents(__DIR__ . '/bundles/' . $name . '.zip'); };

t('Go\'s writer: bit 3 set, zeros in the local header, a descriptor with its signature: accepted (design S1)', function() use ($fx)
{
	foreach(array('go-writer-deflate', 'go-writer-store') as $name)
	{
		$bytes = $fx($name);
		eq(8, ord($bytes[6]) & 8, $name . ' sets general-purpose bit 3');
		eq("\0\0\0\0\0\0\0\0\0\0\0\0", substr($bytes, 14, 12), $name . ' leaves crc and sizes zero in the local header');
		$z = phoromatic_catalog_zip::inspect($bytes);
		eq(1, count($z->entries));
		eq(fixture_file('index-good.signed'), $z->read_all($z->lookup('index.signed'), 1 << 20), $name . ' reads back');
	}
});

t('a descriptor with or without its signature is accepted, with zeros in the local header; a stored entry too', function() use ($fx)
{
	foreach(array('ok-descriptor-sig', 'ok-descriptor-nosig', 'ok-store-descriptor', 'ok-store', 'ok-deflate', 'ok-unix-regular-file') as $name)
	{
		$z = phoromatic_catalog_zip::inspect($fx($name));
		eq(fixture_file('index-good.signed'), $z->read_all($z->lookup('index.signed'), 1 << 20), $name);
	}
});

t('a descriptor that disagrees with the central directory, or is missing, is zip_mismatch; a descriptor flag the headers disagree about too', function() use ($fx)
{
	foreach(array('descriptor-disagrees', 'descriptor-missing', 'descriptor-flag-differs') as $name)
	{
		eq('zip_mismatch', reason_of(function() use ($fx, $name) { phoromatic_catalog_zip::inspect($fx($name)); }), $name);
	}
});

t('data before the first entry, after the end record, a comment, zip64, multi-disk: zip_structure', function() use ($fx)
{
	foreach(array('data-before-first', 'data-after-end', 'zip-comment', 'zip64-locator', 'zip64-extra-central', 'zip64-extra-local', 'zip64-size-marker', 'multi-disk', 'multi-disk-entry', 'bytes-before-end-record') as $name)
	{
		eq('zip_structure', reason_of(function() use ($fx, $name) { phoromatic_catalog_zip::inspect($fx($name)); }), $name);
	}
});

t('overlapping and gapped ranges are zip_structure', function() use ($fx)
{
	foreach(array('overlap', 'gap-between', 'gap-after-last') as $name)
	{
		eq('zip_structure', reason_of(function() use ($fx, $name) { phoromatic_catalog_zip::inspect($fx($name)); }), $name);
	}
});

t('other methods and encryption are zip_method; a symlink, a setuid bit and a directory entry are refused', function() use ($fx)
{
	eq('zip_method', reason_of(function() use ($fx) { phoromatic_catalog_zip::inspect($fx('method-99')); }));
	eq('zip_method', reason_of(function() use ($fx) { phoromatic_catalog_zip::inspect($fx('encrypted')); }));
	eq('zip_entry_type', reason_of(function() use ($fx) { phoromatic_catalog_zip::inspect($fx('symlink')); }));
	eq('zip_entry_type', reason_of(function() use ($fx) { phoromatic_catalog_zip::inspect($fx('setuid')); }));
	eq('pack_path', reason_of(function() use ($fx) { phoromatic_catalog_zip::inspect($fx('directory-entry')); }), 'a bundle holds no directories');
});

t('a local header that disagrees on name, method, crc or sizes: zip_mismatch', function() use ($fx)
{
	foreach(array('local-name-differs', 'local-method-differs', 'local-crc-differs', 'local-csize-differs', 'local-usize-differs') as $name)
	{
		eq('zip_mismatch', reason_of(function() use ($fx, $name) { phoromatic_catalog_zip::inspect($fx($name)); }), $name);
	}
});

t('names equal under case folding, a name that is not one of the two forms: pack_path (a missing index.signed belongs to the bundle check, in test_fixtures)', function() use ($fx)
{
	foreach(array('duplicate-name', 'bad-member-name', 'uppercase-pack-name') as $name)
	{
		eq('pack_path', reason_of(function() use ($fx, $name) { phoromatic_catalog_zip::inspect($fx($name)); }), $name);
	}
	$idx = array('name' => 'index.signed', 'data' => 'x');
	eq('pack_path', reason_of(function() use ($idx) { phoromatic_catalog_zip::inspect(catalog_zipbuild::build(array($idx, array('name' => 'Index.Signed', 'data' => 'y')))); }), 'a second name that differs only in case');
	eq('pack_path', reason_of(function() use ($idx) { phoromatic_catalog_zip::inspect(catalog_zipbuild::build(array($idx, array('name' => '../index.signed', 'data' => 'y')))); }), 'a path that climbs');
	eq('pack_path', reason_of(function() use ($idx) { phoromatic_catalog_zip::inspect(catalog_zipbuild::build(array($idx, array('name' => 'packs\\' . str_repeat('a', 64) . '.zip', 'data' => 'y')))); }), 'a backslash');
});

t('a member that decompresses to a size other than declared, a bad crc and truncated data: pack_size', function() use ($fx)
{
	foreach(array('usize-larger', 'usize-smaller', 'crc-wrong', 'truncated-deflate') as $name)
	{
		$z = phoromatic_catalog_zip::inspect($fx($name));
		eq('pack_size', reason_of(function() use ($z) { $z->read_all($z->lookup('index.signed'), 1 << 20); }), $name);
	}
});

t('limits: a bundle over 128 MiB, 1003 members, an index.signed over 16 MiB plus 4 KiB, a ratio over 100: pack_limit', function() use ($fx)
{
	eq('pack_limit', reason_of(function() { phoromatic_catalog_zip::inspect(str_repeat('x', phoromatic_catalog_zip::MAX_BYTES + 1)); }), 'one byte over 128 MiB');
	$members = array();
	for($i = 0; $i < 1003; $i++)
	{
		$members[] = array('name' => 'packs/' . str_pad(dechex($i), 64, '0', STR_PAD_LEFT) . '.zip', 'data' => 'x', 'method' => 0);
	}
	eq('pack_limit', reason_of(function() use ($members) { phoromatic_catalog_zip::inspect(catalog_zipbuild::build($members)); }), '1003 members');
	eq('zip_structure', reason_of(function() use ($members) { phoromatic_catalog_zip::inspect(catalog_zipbuild::build(array_slice($members, 0, 1002), array('prefix' => 'J'))); }), '1002 members is within the limit (and fails on the prefix instead)');
	eq('pack_limit', reason_of(function() use ($fx) { phoromatic_catalog_zip::inspect($fx('index-too-large')); }), 'index.signed declared over its limit');
	$big = function($n) { return array('name' => 'packs/' . str_repeat($n, 64) . '.zip', 'data' => '', 'raw' => '', 'central' => array('usize' => 60 * 1024 * 1024, 'csize' => 0)); };
	eq('pack_limit', reason_of(function() use ($big) { phoromatic_catalog_zip::inspect(catalog_zipbuild::build(array($big('a'), $big('b')))); }), 'two members declaring 60 MiB each from a tiny zip');
});

t('streaming: the bytes come out whole, in chunks, with their crc and size checked; a deflate bomb is stopped at the declared size', function()
{
	$data = '';
	for($i = 0; $i < 60000; $i++)
	{
		$data .= md5((string)$i); // about 1.9 MB that doesn't compress past the 100x ratio limit
	}
	$name = 'packs/' . hash('sha256', $data) . '.zip';
	foreach(array(0, 8) as $method)
	{
		$z = phoromatic_catalog_zip::inspect(catalog_zipbuild::build(array(array('name' => 'index.signed', 'data' => 'i'), array('name' => $name, 'data' => $data, 'method' => $method))));
		$got = '';
		$chunks = 0;
		$z->stream($z->lookup($name), function($c) use (&$got, &$chunks) { $got .= $c; $chunks++; });
		ok($got === $data, 'method ' . $method . ' reads back whole');
		ok($chunks > 1, 'method ' . $method . ' arrives in chunks');
	}
	// a deflate stream that expands to 50 MB, declared as 1000 bytes
	$bomb = gzdeflate(str_repeat('0', 50 * 1024 * 1024), 9);
	$z = phoromatic_catalog_zip::inspect(catalog_zipbuild::build(array(array('name' => 'index.signed', 'data' => 'i', 'raw' => $bomb, 'central' => array('usize' => 1000, 'crc' => 0), 'local' => array('usize' => 1000, 'crc' => 0)))));
	$seen = 0;
	eq('pack_size', reason_of(function() use ($z, &$seen) { $z->stream($z->lookup('index.signed'), function($c) use (&$seen) { $seen += strlen($c); }); }));
	ok($seen === 0, 'nothing past the declared size reached the sink (' . $seen . ')');
});

t('read_all refuses a member over the caller\'s limit (pack_limit)', function()
{
	$z = phoromatic_catalog_zip::inspect(catalog_zipbuild::build(array(array('name' => 'index.signed', 'data' => str_repeat('a', 2000)))));
	eq('pack_limit', reason_of(function() use ($z) { $z->read_all($z->lookup('index.signed'), 1999); }));
	eq(2000, strlen($z->read_all($z->lookup('index.signed'), 2000)));
});

t('not a zip at all, and too short: zip_structure', function()
{
	eq('zip_structure', reason_of(function() { phoromatic_catalog_zip::inspect(''); }));
	eq('zip_structure', reason_of(function() { phoromatic_catalog_zip::inspect(str_repeat('x', 100)); }));
});

// ---- 7.2 after the signature: membership ----

function ix_with_packs($packs)
{
	$tests = array();
	foreach($packs as $i => $p)
	{
		$tests[] = array('pack_sha256' => $p[0], 'pack_size' => $p[1], 'id' => 'benchy/t' . $i, 'version' => '1.0.0');
	}
	return array('tests' => $tests);
}

t('membership: every pack in the index, every index test with a pack, sizes equal', function()
{
	$a = sha('a');
	$b = sha('b');
	$check = function($packs, $held) { return reason_of(function() use ($packs, $held) { phoromatic_catalog_verify::check_bundle_packs(ix_with_packs($packs), $held); }); };
	eq('accepted', $check(array(array($a, 10), array($b, 20)), array($a => 10, $b => 20)));
	eq('accepted', $check(array(array($a, 10), array($a, 10)), array($a => 10)), 'two tests sharing one pack');
	eq('accepted', $check(array(), array()), 'an index with no tests and no packs');
	eq('bundle_pack_unlisted', $check(array(array($a, 10)), array($a => 10, $b => 20)), 'an extra pack');
	eq('bundle_pack_missing', $check(array(array($a, 10), array($b, 20)), array($a => 10)), 'an index entry with no pack');
	eq('bundle_pack_hash', $check(array(array($a, 10)), array($a => 11)), 'a pack of another size');
});

t('membership: the member name must be the hash of its bytes (bundle_pack_hash)', function()
{
	$data = 'some pack bytes';
	$idx = array('name' => 'index.signed', 'data' => fixture_file('index-good.signed'));
	$good = catalog_zipbuild::build(array($idx, array('name' => 'packs/' . hash('sha256', $data) . '.zip', 'data' => $data, 'method' => 0)));
	$seen = array();
	// the index lists a pack that is not this one, so it ends in unlisted, but only after the name check passed
	eq('bundle_pack_unlisted', reason_of(function() use ($good, &$seen) { phoromatic_catalog_verify::check_bundle(fixture_trust(), phoromatic_catalog_verify::new_state(), $good, null, null, function($n, $c) use (&$seen) { $seen[$n] = true; }); }));
	eq(1, count($seen), 'the sink saw the pack member and not index.signed');
	$bad = catalog_zipbuild::build(array($idx, array('name' => 'packs/' . sha('c') . '.zip', 'data' => $data, 'method' => 0)));
	eq('bundle_pack_hash', reason_of(function() use ($bad) { phoromatic_catalog_verify::check_bundle(fixture_trust(), phoromatic_catalog_verify::new_state(), $bad); }));
});

t('a bundle whose index is refused is refused before any pack is read', function()
{
	$read = 0;
	$flipped = catalog_zipbuild::build(array(array('name' => 'index.signed', 'data' => fixture_file('index-flipped.signed')), array('name' => 'packs/' . hash('sha256', 'p') . '.zip', 'data' => 'p', 'method' => 0)));
	eq('bad_signature', reason_of(function() use ($flipped, &$read) { phoromatic_catalog_verify::check_bundle(fixture_trust(), phoromatic_catalog_verify::new_state(), $flipped, null, null, function($n, $c) use (&$read) { $read++; }); }));
	eq(0, $read, 'no pack byte was read');
});

t('re-importing the very same file re-checks the packs against the index parsed again (unchanged by hash)', function()
{
	$good = fixture_file('index-good.signed');
	$state = state_of(array('public' => tier_state(1, 1, array('benchy/fixture@1.0.0' => sha('b')), array(), array(), hash('sha256', $good))));
	$bundle = catalog_zipbuild::build(array(array('name' => 'index.signed', 'data' => $good)));
	$err = null;
	try
	{
		phoromatic_catalog_verify::check_bundle(fixture_trust(), $state, $bundle);
	}
	catch(phoromatic_catalog_refusal $r)
	{
		$err = $r->reason;
	}
	eq('bundle_pack_missing', $err, 'the unchanged index still has its packs checked');
});
