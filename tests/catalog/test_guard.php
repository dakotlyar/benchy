<?php
// F4: production code reads its key table from keys.json and nowhere else. A test builds a table with `new phoromatic_catalog_keys(...)` and
// hands it to the verifier; this guard fails the build if anything under pts-core/ (outside the catalog classes) does the same, or calls the
// verifier with a table that didn't come from phoromatic_catalog_keys::load(). C6's release gate should run the same scan.

function catalog_guard_violations($src, $file = '')
{
	$out = array();
	foreach(array('new phoromatic_catalog_keys' => '/new\s+phoromatic_catalog_keys\b/', 'phoromatic_catalog_keys::from_json(' => '/phoromatic_catalog_keys::from_json\s*\(/', 'evaluate_index(' => '/\bevaluate_index\s*\(/') as $what => $re)
	{
		if(preg_match($re, $src))
		{
			$out[] = $file . ': ' . $what;
		}
	}
	// the key argument of evaluate() and check_bundle() is the literal load() call, nothing else (not a variable: a helper elsewhere in the
	// file may have assigned that one from load() while this function was handed another)
	if(preg_match_all('/phoromatic_catalog_verify::(?:evaluate|check_bundle)\s*\(\s*(phoromatic_catalog_keys::load\(\)|[^,]*)/', $src, $m))
	{
		foreach($m[1] as $arg)
		{
			if(trim($arg) !== 'phoromatic_catalog_keys::load()')
			{
				$out[] = $file . ': the key argument ' . trim($arg) . ' is not the literal phoromatic_catalog_keys::load()';
			}
		}
	}
	// verify_signature is private; naming it, or reaching the verifier through a string callable, is refused too
	if(preg_match('/phoromatic_catalog_verify::verify_signature\b/', $src))
	{
		$out[] = $file . ': verify_signature';
	}
	if(preg_match('/([\'"])\\\\?\s*phoromatic_catalog_(?:verify|keys)\b[^\'"]*\1/', $src))
	{
		$out[] = $file . ': the verifier or the key table named in a string (a string callable or a dynamic class)';
	}
	return $out;
}

t('the guard sees what it must: a constructed table, from_json, evaluate_index, and a key argument that is not load()', function()
{
	eq(1, count(catalog_guard_violations('$t = new phoromatic_catalog_keys($a, $b, $c);')));
	eq(1, count(catalog_guard_violations('$t = phoromatic_catalog_keys::from_json($x);')));
	eq(1, count(catalog_guard_violations('phoromatic_catalog_verify::evaluate_index($t, $s, "", $k, $r, $h);')));
	eq(1, count(catalog_guard_violations('phoromatic_catalog_verify::evaluate($other, $s, "", $f);')));
	eq(1, count(catalog_guard_violations('phoromatic_catalog_verify::check_bundle(make_trust(), $s, $b);')));
	eq(array(), catalog_guard_violations('phoromatic_catalog_verify::evaluate(phoromatic_catalog_keys::load(), $s, "", $f);'));
	eq(array(), catalog_guard_violations('phoromatic_catalog_verify::check_bundle(phoromatic_catalog_keys::load(), $s, $b);'));
	// the bypasses AP-5 found
	$bypass = array(
		'a table passed in while a helper elsewhere calls load()' => 'class imp { static function trust() { $t = phoromatic_catalog_keys::load(); return $t; } static function run($b, $t) { return phoromatic_catalog_verify::check_bundle($t, $s, $b); } }',
		'a variable from load() is not enough, even when assigned once' => '$t = phoromatic_catalog_keys::load(); phoromatic_catalog_verify::check_bundle($t, $s, $b);',
		'?: override' => '$t = phoromatic_catalog_keys::load(); phoromatic_catalog_verify::evaluate($t ?: $other, $s, "", $f);',
		'list() reassign' => '$t = phoromatic_catalog_keys::load(); list($t) = array($other); phoromatic_catalog_verify::evaluate($t, $s, "", $f);',
		'foreach reassign' => '$t = phoromatic_catalog_keys::load(); foreach($tables as $t) phoromatic_catalog_verify::evaluate($t, $s, "", $f);',
		'verify_signature with another table' => 'phoromatic_catalog_verify::verify_signature($other, $file, 0);',
		'a string callable' => 'call_user_func(array("phoromatic_catalog_verify", "evaluate"), $other, $s, "", $f);',
		'a string callable, one string' => 'call_user_func("phoromatic_catalog_verify::evaluate", $other, $s, "", $f);',
	);
	foreach($bypass as $what => $code)
	{
		ok(count(catalog_guard_violations($code)) > 0, 'missed: ' . $what);
	}
	// not covered, on purpose (EC-P1): a class name built at run time (new $c) and unserialize
	eq('Error', reason_of_error(function() { phoromatic_catalog_verify::verify_signature(fixture_trust(), '', 0); }), 'verify_signature is private');
});

t('nothing under pts-core/ outside the catalog classes builds a key table or passes the verifier another one', function()
{
	$own = array('phoromatic_catalog_keys.php', 'phoromatic_catalog_verify.php', 'phoromatic_catalog_zip.php', 'phoromatic_catalog_json.php', 'phoromatic_catalog_refusal.php');
	$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(PTS_PATH . 'pts-core', FilesystemIterator::SKIP_DOTS));
	$seen = 0;
	$bad = array();
	foreach($it as $f)
	{
		if(substr($f->getFilename(), -4) !== '.php' || in_array($f->getFilename(), $own, true))
		{
			continue;
		}
		$seen++;
		$bad = array_merge($bad, catalog_guard_violations(file_get_contents($f->getPathname()), substr($f->getPathname(), strlen(PTS_PATH))));
	}
	ok($seen > 100, 'the scan saw the tree (' . $seen . ' files)');
	eq(array(), $bad);
});

t('the key table\'s lists are private: nothing outside the class can add a key', function()
{
	$t = fixture_trust();
	foreach(array('keys', 'denied', 'floor') as $prop)
	{
		eq('Error', reason_of_error(function() use ($t, $prop) { $t->$prop = array(); }), $prop);
	}
});

function reason_of_error($fn)
{
	try
	{
		$fn();
	}
	catch(Error $e)
	{
		return 'Error';
	}
	return 'none';
}
