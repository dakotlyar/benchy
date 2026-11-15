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
	if(preg_match_all('/phoromatic_catalog_verify::(?:evaluate|check_bundle)\s*\(\s*(\$\w+|phoromatic_catalog_keys::load\(\)|[^,]*)/', $src, $m))
	{
		foreach($m[1] as $arg)
		{
			$arg = trim($arg);
			if($arg === 'phoromatic_catalog_keys::load()')
			{
				continue;
			}
			// a variable is fine when this file assigns it from load() and from nothing else
			if(preg_match('/^\$(\w+)$/', $arg, $v) && preg_match('/\$' . $v[1] . '\s*=\s*phoromatic_catalog_keys::load\(\)\s*;/', $src) && preg_match_all('/\$' . $v[1] . '\s*=(?!=)/', $src) === 1)
			{
				continue;
			}
			$out[] = $file . ': the key argument ' . $arg . ' is not phoromatic_catalog_keys::load()';
		}
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
	eq(1, count(catalog_guard_violations('$t = phoromatic_catalog_keys::load(); $t = something(); phoromatic_catalog_verify::evaluate($t, $s, "", $f);')), 'a variable assigned twice');
	eq(array(), catalog_guard_violations('phoromatic_catalog_verify::evaluate(phoromatic_catalog_keys::load(), $s, "", $f);'));
	eq(array(), catalog_guard_violations('$t = phoromatic_catalog_keys::load(); phoromatic_catalog_verify::check_bundle($t, $s, $b);'));
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
