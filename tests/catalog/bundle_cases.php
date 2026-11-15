<?php
// The cases of the bundle fixtures: name => zip bytes. See make_bundles.php. Each case is one fault in an otherwise good bundle.
function catalog_bundle_cases()
{
	$good = file_get_contents(__DIR__ . '/engine-fixtures/index-good.signed');
	$idx = array('name' => 'index.signed', 'data' => $good);
	$packdata = 'not a real pack, only bytes that hash to their own name';
	$packsha = hash('sha256', $packdata);
	$pack = array('name' => 'packs/' . $packsha . '.zip', 'data' => $packdata, 'method' => 0);
	$B = 'catalog_zipbuild::build';
	$unix = function($mode) { return array('made_by' => (3 << 8) | 20, 'attrs' => $mode << 16); };
	$c = array();

	// well-formed: the structure passes and the engine goes on to the packs (the index lists a pack the bundle lacks)
	$c['ok-deflate'] = $B(array($idx));
	$c['ok-store'] = $B(array($idx + array('method' => 0)));
	$c['ok-descriptor-sig'] = $B(array($idx + array('flags' => 8, 'desc' => 'sig')));
	$c['ok-descriptor-nosig'] = $B(array($idx + array('flags' => 8, 'desc' => 'nosig')));
	$c['ok-store-descriptor'] = $B(array($idx + array('method' => 0, 'flags' => 8, 'desc' => 'sig')));
	$c['ok-unix-regular-file'] = $B(array($idx + array('central' => $unix(0100644))));

	// membership, after the signature
	$c['pack-unlisted'] = $B(array($idx, $pack));
	$c['pack-name-hash-differs'] = $B(array($idx, array('name' => 'packs/' . str_repeat('b', 64) . '.zip', 'data' => $packdata, 'method' => 0)));

	// 7.1 records
	$c['data-before-first'] = $B(array($idx), array('prefix' => 'JUNK'));
	$c['data-after-end'] = $B(array($idx), array('suffix' => 'JUNK'));
	$c['zip-comment'] = $B(array($idx), array('comment' => 'hello'));
	$c['zip64-locator'] = $B(array($idx), array('zip64_locator' => true));
	$c['zip64-extra-central'] = $B(array($idx + array('central' => array('extra' => pack('vv', 1, 0)))));
	$c['zip64-extra-local'] = $B(array($idx + array('local' => array('extra' => pack('vv', 1, 0)))));
	$c['zip64-size-marker'] = $B(array($idx + array('central' => array('csize' => 0xFFFFFFFF))));
	$c['multi-disk'] = $B(array($idx), array('eocd' => array('disk' => 1)));
	$c['multi-disk-entry'] = $B(array($idx + array('central' => array('disk' => 1))));
	$c['local-name-differs'] = $B(array($idx + array('local' => array('name' => 'Index.signed'))));
	$c['local-method-differs'] = $B(array($idx + array('local' => array('method' => 0))));
	$c['local-crc-differs'] = $B(array($idx + array('local' => array('crc' => 12345))));
	$c['local-csize-differs'] = $B(array($idx + array('local' => array('csize' => 5))));
	$c['local-usize-differs'] = $B(array($idx + array('local' => array('usize' => 5))));
	$c['descriptor-flag-differs'] = $B(array($idx + array('local' => array('flags' => 8))));
	$c['descriptor-disagrees'] = $B(array($idx + array('flags' => 8, 'desc' => 'sig', 'desc_override' => pack('VVV', 1, 2, 3))));
	$c['descriptor-missing'] = $B(array($idx + array('flags' => 8)));
	$c['overlap'] = $B(array($idx + array('method' => 0, 'central' => array('csize' => strlen($good) + 10, 'usize' => strlen($good) + 10), 'local' => array('csize' => strlen($good) + 10, 'usize' => strlen($good) + 10)), array('name' => 'packs/' . str_repeat('c', 64) . '.zip', 'data' => 'x', 'method' => 0)));
	$c['gap-between'] = $B(array($idx, array('name' => 'packs/' . str_repeat('c', 64) . '.zip', 'data' => 'x', 'method' => 0, 'gap_before' => 'GAP')));
	$c['gap-after-last'] = $B(array($idx + array('gap_after' => 'GAP')));
	$c['bytes-before-end-record'] = $B(array($idx), array('before_eocd' => 'JUNK'));
	$c['method-99'] = $B(array($idx + array('central' => array('method' => 99), 'local' => array('method' => 99))));
	$c['encrypted'] = $B(array($idx + array('flags' => 1)));
	$c['symlink'] = $B(array($idx + array('central' => $unix(0120777))));
	$c['setuid'] = $B(array($idx + array('central' => $unix(04755 | 0100000))));
	$c['directory-entry'] = $B(array($idx, array('name' => 'packs/', 'data' => '', 'method' => 0, 'central' => $unix(040755))));
	$c['duplicate-name'] = $B(array($idx, $idx));
	$c['bad-member-name'] = $B(array($idx, array('name' => 'extra.txt', 'data' => 'x', 'method' => 0)));
	$c['uppercase-pack-name'] = $B(array($idx, array('name' => 'packs/' . str_repeat('A', 64) . '.zip', 'data' => 'x', 'method' => 0)));
	$c['no-index-signed'] = $B(array($pack));
	$c['index-too-large'] = $B(array($idx + array('central' => array('usize' => 16 * 1024 * 1024 + 4097))));
	$c['usize-larger'] = $B(array($idx + array('central' => array('usize' => strlen($good) + 10), 'local' => array('usize' => strlen($good) + 10))));
	$c['usize-smaller'] = $B(array($idx + array('central' => array('usize' => strlen($good) - 10), 'local' => array('usize' => strlen($good) - 10))));
	$c['crc-wrong'] = $B(array($idx + array('central' => array('crc' => 1), 'local' => array('crc' => 1))));
	$c['truncated-deflate'] = $B(array($idx + array('raw' => substr(gzdeflate($good, 9), 0, 40), 'central' => array('usize' => strlen($good)))));
	return $c;
}
