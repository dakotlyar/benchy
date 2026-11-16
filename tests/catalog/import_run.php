<?php
// The server-side catalog import (E4.4 C2), end to end through the command line: php -d memory_limit=1G tests/catalog/import_run.php
// Run by run.sh catalog in a throw-away container as an ordinary user, with the committed tree mounted read-only and no network.
//
// The tree is copied to /tmp/tree and ITS keys.json is replaced by a table of keys made fresh by this process (openssl_pkey_new, in memory only:
// no private key is ever written, committed or kept). The shipped table is untouched and the server has no switch that adds a key; this is the
// "test image replaces this one file" of design 14.1. Every scenario gets a HOME of its own, so no real ~/.benchy is ever involved.
error_reporting(E_ALL);
ini_set('display_errors', '1');
define('PTS_PATH', realpath(__DIR__ . '/../..') . '/');
require __DIR__ . '/lib.php';
require_once PTS_PATH . 'pts-core/objects/phoromatic/phoromatic_catalog_import.php';

$TREE = '/tmp/tree/';
exec('mkdir -p /tmp/tree /tmp/b && cp -a ' . escapeshellarg(PTS_PATH) . '. /tmp/tree/ 2>&1', $o, $rc);
ok($rc === 0, 'tree copy: ' . implode(' ', $o));
$ring = new test_ring(array(1, 2));
$rogue = new test_ring(array(1));

function write_keys($ring, $denied = array(), $floor = array(), $gens = null)
{
	$keys = array();
	foreach($ring->spki as $g => $s)
	{
		if($gens === null || in_array($g, $gens))
		{
			$keys[] = array('generation' => $g, 'spki' => $s);
		}
	}
	file_put_contents('/tmp/tree/pts-core/static/catalog/keys.json', json_encode(array('schema' => 'benchy-catalog-keys/1', 'keys' => $keys, 'denied_key_ids' => $denied, 'floor' => (object)$floor)));
}
write_keys($ring);

// ---- a scenario: its own HOME and its own server database ----
class scenario
{
	public $home;
	public $storage;
	
	public function __construct($init = true)
	{
		// Benchy reads the user's folder from the passwd entry and ignores $HOME, so each scenario starts by emptying the container's own ~/.benchy
		// (this container is thrown away; nothing outside it is ever touched). The scenarios run one after another and never share a database.
		$this->home = posix_getpwuid(posix_geteuid())['dir'];
		exec('rm -rf ' . escapeshellarg($this->home . '/.benchy'));
		if($init)
		{
			list($rc, $out) = $this->cli('server-init');
			ok($rc === 0 && preg_match('/^Storage: (\S+)/m', $out, $m), 'server-init: ' . $out);
			$this->storage = rtrim($m[1], '/') . '/';
		}
	}
	public function cli($cmd, $arg = null)
	{
		$c = 'cd /tmp && sh /tmp/tree/benchy ' . $cmd . ($arg !== null ? ' ' . escapeshellarg($arg) : '') . ' 2>&1 </dev/null | head -c 20000; exit ${PIPESTATUS[0]}';
		exec('bash -c ' . escapeshellarg($c), $lines, $rc);
		return array($rc, implode("\n", $lines));
	}
	public function boot($code)
	{
		$boot = "define('PTS_MODE','CLIENT');define('PTS_AUTO_LOAD_OBJECTS',true);define('PHOROMATIC_SERVER',true);\$_SERVER['argv']=array('x');include('/tmp/tree/pts-core/pts-core.php');pts_core::init();phoromatic_server::prepare_database();";
		exec('cd /tmp && php -d display_errors=1 -r ' . escapeshellarg($boot . $code) . ' 2>&1 </dev/null | head -c 20000', $lines);
		return implode("\n", $lines);
	}
	public function db($write = false)
	{
		$d = new SQLite3($this->storage . 'phoromatic.db', $write ? SQLITE3_OPEN_READWRITE : SQLITE3_OPEN_READONLY);
		$d->busyTimeout(5000);
		return $d;
	}
	public function rows($sql)
	{
		$d = $this->db();
		$r = $d->query($sql);
		$out = array();
		while($r && ($row = $r->fetchArray(SQLITE3_ASSOC)))
		{
			$out[] = $row;
		}
		$d->close();
		return $out;
	}
	public function exec($sql)
	{
		$d = $this->db(true);
		$d->exec($sql);
		$d->close();
	}
	public function one($sql)
	{
		$r = $this->rows($sql);
		return $r ? reset($r[0]) : null;
	}
	// Everything a refusal must leave as it was: the catalog tables, the files in signed/, packs/ and work/
	public function snap()
	{
		$s = array();
		foreach(array('state', 'tiers', 'ledger', 'revoked', 'entries') as $t)
		{
			$s[$t] = md5(json_encode($this->rows('SELECT * FROM phoromatic_catalog_' . $t . ' ORDER BY rowid')));
		}
		foreach(array('signed', 'packs', 'work') as $d)
		{
			$files = array();
			foreach(glob($this->storage . 'catalog/' . $d . '/*') ?: array() as $f)
			{
				$files[basename($f)] = is_file($f) ? md5_file($f) : 'dir';
			}
			ksort($files);
			$s[$d] = $files;
		}
		return $s;
	}
	public function audit($action)
	{
		return $this->rows('SELECT * FROM phoromatic_server_audit WHERE Action = \'' . $action . '\' ORDER BY AuditID');
	}
	public function last_detail($action)
	{
		$a = $this->audit($action);
		return $a ? json_decode(end($a)['Detail'], true) : null;
	}
}

// ---- bundles ----
// $tests: list of array(id, version, pack bytes, tree sha) ; the pack is a real member named by its hash
function tst($id, $version = '1.0.0', $bytes = null, $tree = null, $over = array())
{
	$bytes = $bytes === null ? 'pack of ' . $id . $version : $bytes;
	return array($id, $version, $bytes, $tree === null ? hash('sha256', 'tree' . $id . $version) : $tree, $over);
}
function mk($name, $ring, $gen, $tier, $seq, $tests, $o = array())
{
	$entries = array();
	$members = array();
	foreach($tests as $t)
	{
		$sha = hash('sha256', $t[2]);
		$over = array('pack_sha256' => $sha, 'pack_size' => strlen($t[2])) + $t[4];
		if($tier === 'private' && !isset($over['license_class']))
		{
			$over['license_class'] = 'non-free';
		}
		$entries[] = test_entry($t[0], $t[1], $t[3], $over);
		$members[$sha] = array('name' => 'packs/' . $sha . '.zip', 'data' => $t[2], 'method' => 0);
	}
	$ix = index_json(array('tier' => $tier, 'sequence' => $seq, 'tests' => $entries, 'suites' => isset($o['suites']) ? $o['suites'] : array(), 'revoked' => isset($o['revoked']) ? $o['revoked'] : array(),
		'min_generation' => isset($o['min_generation']) ? $o['min_generation'] : null));
	$file = isset($o['file']) ? $o['file'] : $ring->sign($gen, $ix);
	foreach(isset($o['drop_member']) ? $o['drop_member'] : array() as $d)
	{
		unset($members[hash('sha256', $d)]);
	}
	$list = array_merge(array(array('name' => 'index.signed', 'data' => $file)), array_values($members), isset($o['extra']) ? $o['extra'] : array());
	$path = '/tmp/b/' . $name . '.zip';
	file_put_contents($path, catalog_zipbuild::build($list));
	return $path;
}
function has($text, $needle)
{
	return strpos($text, $needle) !== false;
}

// ---------------------------------------------------------------------------------------------------------------------------------------

echo "\nserver-catalog-check: no server storage, nothing written\n";
t('check prints one line for a good bundle, and writes nothing under HOME', function() use ($ring)
{
	$s = new scenario(false);
	$b = mk('chk-good', $ring, 1, 'public', 3, array(tst('benchy/a'), tst('benchy/b')), array('suites' => array(suite_entry('benchy/s', '1.0.0', array(array('benchy/a')))), 'revoked' => array(revoked_entry('benchy/old', '0.9.0'))));
	list($rc, $out) = $s->cli('server-catalog-check', $b);
	eq(0, $rc, $out);
	eq('public 1 3 2 1 1', trim($out), 'tier generation sequence tests suites revoked');
	ok(!file_exists($s->home . '/.benchy'), 'no ~/.benchy was made: the check needs no server storage');
});
t('check refuses a bad bundle: exit 1, the reason on stderr', function() use ($rogue)
{
	$s = new scenario(false);
	$b = mk('chk-rogue', $rogue, 1, 'public', 1, array(tst('benchy/a')));
	list($rc, $out) = $s->cli('server-catalog-check', $b);
	eq(1, $rc);
	ok(has($out, 'Refused (unknown_key)'), $out);
});

echo "\nthe first import, status, the files and the audit\n";
t('import and status are refused when there is no database (the command never makes one)', function()
{
	$s2 = new scenario(false);
	list($rc, $out) = $s2->cli('server-catalog-import', '/tmp/b/chk-good.zip');
	eq(1, $rc);
	ok(has($out, 'No Phoromatic database was found'), $out);
});
$s = new scenario();
t('status of an empty catalog', function() use ($s)
{
	list($rc, $out) = $s->cli('server-catalog-status');
	eq(0, $rc, $out);
	ok(has($out, 'No catalog has been imported.'), $out);
	eq(63, (int)$s->one('PRAGMA user_version'), 'a fresh database is at schema 63');
	eq(0, (int)$s->one('SELECT COUNT(*) FROM phoromatic_server_audit WHERE Action = \'migration\' AND Detail LIKE \'%"to":63%\''), 'a fresh database gets the tables with no migration row');
});
$P1 = mk('p1', $ring, 1, 'public', 1, array(tst('benchy/a'), tst('benchy/b')), array('suites' => array(suite_entry('benchy/s', '1.0.0', array(array('benchy/a')))), 'revoked' => array(revoked_entry('benchy/old', '0.9.0', 'was bad'))));
t('the first import makes the tier, the ledger, the entries, the files and one audit row', function() use ($s, $P1)
{
	list($rc, $out) = $s->cli('server-catalog-import', $P1);
	eq(0, $rc, $out);
	ok(has($out, 'Imported the free catalog: generation 1, sequence 1 (the first). 2 tests added, 1 withdrawn.'), $out);
	$tier = $s->rows('SELECT * FROM phoromatic_catalog_tiers')[0];
	eq(array('public', 1, 1, 'cli', 64), array($tier['Tier'], $tier['Generation'], $tier['Sequence'], $tier['ImportedVia'], strlen($tier['SignedSHA256'])));
	eq(array(2, 1, 1), array((int)$tier['TestCount'], (int)$tier['SuiteCount'], (int)$tier['RevokedCount']));
	eq(hash_file('sha256', $P1), $tier['BundleSHA256']);
	ok(strpos($tier['ImportedBy'], 'local:') === 0 && $tier['ImportedAccount'] === null, 'a local actor, no account');
	eq(array(array('Kind' => 'suite', 'Ref' => 'benchy/s@1.0.0'), array('Kind' => 'test', 'Ref' => 'benchy/a@1.0.0'), array('Kind' => 'test', 'Ref' => 'benchy/b@1.0.0')),
		array_map(function($r) { return array('Kind' => $r['Kind'], 'Ref' => $r['Ref']); }, $s->rows('SELECT Kind, Ref FROM phoromatic_catalog_ledger ORDER BY Kind, Ref')), 'the ledger');
	eq('was bad', $s->one('SELECT Reason FROM phoromatic_catalog_revoked WHERE Ref = \'benchy/old@0.9.0\''));
	eq(3, (int)$s->one('SELECT COUNT(*) FROM phoromatic_catalog_entries'), 'two tests and a suite');
	eq(0, (int)$s->one('SELECT RetiredBelow FROM phoromatic_catalog_state'));
	$signed = glob($s->storage . 'catalog/signed/*');
	eq(1, count($signed));
	eq(basename($signed[0], '.signed'), $tier['SignedSHA256'], 'named by its own hash');
	eq(2, count(glob($s->storage . 'catalog/packs/*')), 'two packs');
	foreach(array_merge($signed, glob($s->storage . 'catalog/packs/*'), array($s->storage . 'catalog/import.lock')) as $f)
	{
		eq('0600', substr(sprintf('%o', fileperms($f)), -4), $f);
	}
	foreach(array('', 'signed/', 'packs/', 'work/') as $d)
	{
		eq('0700', substr(sprintf('%o', fileperms($s->storage . 'catalog/' . $d)), -4), 'folder ' . $d);
	}
	eq(array(), glob($s->storage . 'catalog/work/*'), 'the work folder is gone');
	$d = $s->last_detail('catalog_imported');
	eq(array('cli', 1, 1, null, false, false, 2, 1, 1), array($d['via'], $d['generation'], $d['sequence'], $d['from'], $d['recovery'], $d['unchanged'], $d['tests_added'], $d['suites_added'], $d['revoked_added']));
	eq(array(0, 0), $d['retired_below']);
	$row = $s->audit('catalog_imported')[0];
	eq(array(null, 'cli', 'catalog_tier', 'public'), array($row['TenantID'], $row['ActorType'], $row['SubjectType'], $row['SubjectID']));
});
t('status after the import: served, the pair, the key, the counts', function() use ($s, $ring)
{
	list($rc, $out) = $s->cli('server-catalog-status');
	eq(0, $rc, $out);
	ok(has($out, 'Free catalog (public): served') && has($out, 'generation 1, sequence 1') && has($out, $ring->key_id(1)) && has($out, '2 tests, 1 suites, 1 withdrawn') && has($out, 'Retired key generations: none'), $out);
});

echo "\nre-importing: unchanged, repair\n";
t('the same bundle again: no state change, one more audit row that says unchanged, nothing restored', function() use ($s, $P1)
{
	$before = $s->snap();
	list($rc, $out) = $s->cli('server-catalog-import', $P1);
	eq(0, $rc, $out);
	ok(has($out, 'already imported') && has($out, 'nothing changed'), $out);
	eq($before, $s->snap());
	$a = $s->audit('catalog_imported');
	eq(2, count($a));
	$d = json_decode($a[1]['Detail'], true);
	eq(array(true, array('index' => 0, 'packs' => 0)), array($d['unchanged'], $d['restored']));
});
t('the same index under another valid signature: no alarm, no state change, the stored file stays', function() use ($s, $ring)
{
	// (ECDSA signs with a fresh random number each time, so the same index signed again is another signature over the same bytes)
	$stored = glob($s->storage . 'catalog/signed/*')[0];
	$raw = file_get_contents($stored);
	list(, $index) = phoromatic_catalog_verify::split($raw);
	$other = $ring->sign(1, $index);
	ok($other !== $raw, 'a different file');
	$b = '/tmp/b/p1-resigned.zip';
	file_put_contents($b, catalog_zipbuild::build(array_merge(array(array('name' => 'index.signed', 'data' => $other)), array_map(function($f) { return array('name' => 'packs/' . basename($f), 'data' => file_get_contents($f), 'method' => 0); }, glob($s->storage . 'catalog/packs/*')))));
	$before = $s->snap();
	list($rc, $out) = $s->cli('server-catalog-import', $b);
	eq(0, $rc, $out);
	eq($before, $s->snap());
	eq($raw, file_get_contents($stored));
	eq(0, count($s->audit('catalog_import_refused')));
});
t('a lost pack is put back by importing again, and the audit row counts it', function() use ($s, $P1)
{
	$p = glob($s->storage . 'catalog/packs/*')[0];
	$bytes = file_get_contents($p);
	unlink($p);
	list(, $out) = $s->cli('server-catalog-status');
	ok(has($out, '1 test pack is missing from storage'), $out);
	list($rc, $out) = $s->cli('server-catalog-import', $P1);
	eq(0, $rc, $out);
	ok(has($out, '1 test pack that had gone missing was put back'), $out);
	eq($bytes, file_get_contents($p));
	eq(1, $s->last_detail('catalog_imported')['restored']['packs']);
	ok($s->one('SELECT ChangedOn FROM phoromatic_catalog_state') !== null, 'the repair counts as a change of what is served');
});
t('a pack of the wrong size is replaced', function() use ($s, $P1)
{
	$p = glob($s->storage . 'catalog/packs/*')[1];
	$bytes = file_get_contents($p);
	file_put_contents($p, 'short');
	list($rc, $out) = $s->cli('server-catalog-import', $P1);
	eq(0, $rc, $out);
	eq($bytes, file_get_contents($p));
});
t('a damaged or missing index file: not served, and importing the same bundle repairs it', function() use ($s, $P1)
{
	$f = glob($s->storage . 'catalog/signed/*')[0];
	$bytes = file_get_contents($f);
	file_put_contents($f, $bytes . ' ');
	list(, $out) = $s->cli('server-catalog-status');
	ok(has($out, 'NOT SERVED: the stored file is missing or damaged'), $out);
	$notice = $s->boot('echo json_encode(phoromatic_catalog::notices());');
	ok(has($notice, 'stored file is missing or damaged'), $notice);
	list($rc, $out) = $s->cli('server-catalog-import', $P1);
	eq(0, $rc, $out);
	eq($bytes, file_get_contents($f));
	eq(1, $s->last_detail('catalog_imported')['restored']['index']);
	unlink($f);
	list(, $out) = $s->cli('server-catalog-status');
	ok(has($out, 'NOT SERVED'), $out);
	$s->cli('server-catalog-import', $P1);
	eq($bytes, file_get_contents($f));
	list(, $out) = $s->cli('server-catalog-status');
	ok(has($out, 'Free catalog (public): served'), $out);
});

echo "\nrefusals leave nothing behind\n";
function refused($s, $label, $path, $reason)
{
	t($label . ' (' . $reason . ')', function() use ($s, $path, $reason)
	{
		$before = $s->snap();
		$n = count($s->audit('catalog_import_refused'));
		list($rc, $out) = $s->cli('server-catalog-import', $path);
		eq(1, $rc, $out);
		ok(has($out, 'Refused (' . $reason . ')') && has($out, 'Nothing was changed.'), $out);
		eq($before, $s->snap(), 'nothing was written');
		$a = $s->audit('catalog_import_refused');
		eq($n + 1, count($a), 'one new refusal row');
		$d = json_decode(end($a)['Detail'], true);
		eq(array('cli', $reason, hash_file('sha256', $path)), array($d['via'], $d['reason'], $d['bundle_sha256']));
		ok(strlen($d['message']) <= 303, 'message is bounded');
	});
}
$flip = function($b) { return substr($b, 0, -3) . chr(ord($b[strlen($b) - 3]) ^ 1) . substr($b, -2); };
refused($s, 'a signature with one byte flipped', mk('r-flip', $ring, 1, 'public', 2, array(tst('benchy/a')), array('file' => $flip($ring->sign(1, index_json(array('sequence' => 2)))))), 'bad_signature');
refused($s, 'a key that is not in the table', mk('r-rogue', $rogue, 1, 'public', 2, array(tst('benchy/a'))), 'unknown_key');
refused($s, 'the same pair with a different index', mk('r-lower', $ring, 1, 'public', 1, array(tst('benchy/a'), tst('benchy/c')), array()), 'same_pair_differs');
$keep = array('revoked' => array(revoked_entry('benchy/old', '0.9.0', 'was bad')));   // (the revocation check comes before the license check, so these keep the one the server holds)
refused($s, 'a license failure in a public index: a non-free test', mk('r-lic', $ring, 1, 'public', 2, array(tst('benchy/a'), tst('benchy/n', '1.0.0', null, null, array('license_class' => 'non-free'))), $keep), 'license_tier');
refused($s, 'a machine_install entry in a public index', mk('r-mi', $ring, 1, 'public', 2, array(tst('benchy/a'), tst('benchy/m', '1.0.0', null, null, array('manifest' => array('id' => 'benchy/m', 'machine_install' => array('product' => 'x'))))), $keep), 'license_tier');
refused($s, 'a test whose tree changed (ledger)', mk('r-ledger', $ring, 1, 'public', 2, array(tst('benchy/a', '1.0.0', null, hash('sha256', 'another tree')), tst('benchy/b'))), 'ledger_conflict');
refused($s, 'a dropped revocation', mk('r-drop', $ring, 1, 'public', 2, array(tst('benchy/a'), tst('benchy/b'))), 'revocation_dropped');
refused($s, 'a listed version that is also revoked', mk('r-revl', $ring, 1, 'public', 2, array(tst('benchy/a'), tst('benchy/b')), array('revoked' => array(revoked_entry('benchy/old', '0.9.0'), revoked_entry('benchy/a', '1.0.0')))), 'revoked_listed');
refused($s, 'a pack that does not hash to its name', mk('r-hash', $ring, 1, 'public', 2, array(tst('benchy/a'), tst('benchy/b'), tst('benchy/c')), array('revoked' => array(revoked_entry('benchy/old', '0.9.0')), 'drop_member' => array('pack of benchy/c1.0.0'),
	'extra' => array(array('name' => 'packs/' . hash('sha256', 'pack of benchy/c1.0.0') . '.zip', 'data' => 'not that pack', 'method' => 0)))), 'bundle_pack_hash');
refused($s, 'a pack the index does not list', mk('r-unl', $ring, 1, 'public', 2, array(tst('benchy/a'), tst('benchy/b')), array('revoked' => array(revoked_entry('benchy/old', '0.9.0')), 'extra' => array(array('name' => 'packs/' . hash('sha256', 'extra') . '.zip', 'data' => 'extra', 'method' => 0)))), 'bundle_pack_unlisted');
refused($s, 'an index test with no pack', mk('r-miss', $ring, 1, 'public', 2, array(tst('benchy/a'), tst('benchy/b'), tst('benchy/c')), array('revoked' => array(revoked_entry('benchy/old', '0.9.0')), 'drop_member' => array('pack of benchy/c1.0.0'))), 'bundle_pack_missing');
file_put_contents('/tmp/b/garbage.zip', 'this is not a zip');
refused($s, 'a file that is not a zip', '/tmp/b/garbage.zip', 'zip_structure');
t('a bundle over 128 MiB is refused before it is read', function() use ($s)
{
	$f = fopen('/tmp/b/huge.zip', 'wb');
	ftruncate($f, 134217728 + 1);
	fclose($f);
	$before = $s->snap();
	list($rc, $out) = $s->cli('server-catalog-import', '/tmp/b/huge.zip');
	eq(1, $rc);
	ok(has($out, 'Refused (limit)'), $out);
	eq($before, $s->snap());
	unlink('/tmp/b/huge.zip');
});
t('a bundle that is not there', function() use ($s)
{
	list($rc, $out) = $s->cli('server-catalog-import', '/tmp/b/nothing.zip');
	eq(1, $rc);
	ok(has($out, 'Refused (bundle_unreadable)'), $out);
});

echo "\nupdating: the ledger grows, entries are replaced, files no tier names are collected\n";
t('sequence 2 adds a test and a withdrawal, stops listing one; the ledger keeps what it ever accepted', function() use ($s, $ring)
{
	$p2 = mk('p2', $ring, 1, 'public', 2, array(tst('benchy/a'), tst('benchy/c')), array('revoked' => array(revoked_entry('benchy/old', '0.9.0', 'was bad'), revoked_entry('benchy/b', '1.0.0', 'bad too'))));
	list($rc, $out) = $s->cli('server-catalog-import', $p2);
	eq(0, $rc, $out);
	ok(has($out, 'Imported the free catalog: generation 1, sequence 2 (was generation 1, sequence 1). 1 test added, 1 withdrawn.'), $out);
	eq(array('benchy/a@1.0.0', 'benchy/b@1.0.0', 'benchy/c@1.0.0'), array_column($s->rows('SELECT Ref FROM phoromatic_catalog_ledger WHERE Kind = \'test\' ORDER BY Ref'), 'Ref'), 'b stays in the ledger');
	eq(array('benchy/a', 'benchy/c'), array_column($s->rows('SELECT ID FROM phoromatic_catalog_entries WHERE Kind = \'test\' ORDER BY ID'), 'ID'), 'the entries are what the new index lists');
	eq(array('benchy/b@1.0.0', 'benchy/old@0.9.0'), array_column($s->rows('SELECT Ref FROM phoromatic_catalog_revoked ORDER BY Ref'), 'Ref'));
	eq(2, count(glob($s->storage . 'catalog/packs/*')), 'the pack nobody names any more is collected');
	eq(1, count(glob($s->storage . 'catalog/signed/*')), 'and so is the old index');
	eq(0, (int)$s->one('SELECT COUNT(*) FROM phoromatic_catalog_entries WHERE Kind = \'suite\''), 'the suite is gone from the entries');
});
refused($s, 'the first bundle again, now below the pair the server holds', '/tmp/b/p1.zip', 'rollback');
t('a bundle named for the other tier but holding a private index is stored as private; the tiers never mix', function() use ($s, $ring)
{
	$b = mk('benchy-catalog-public-1-9', $ring, 1, 'private', 1, array(tst('benchy/p', '11.0.0', null, null, array('manifest' => array('id' => 'benchy/p', 'machine_install' => array('product' => 'Thing 11'), 'elevated_run' => array('reason' => 'x'), 'title' => 'The P')))));
	list($rc, $out) = $s->cli('server-catalog-import', $b);
	eq(0, $rc, $out);
	ok(has($out, 'non-free catalog'), $out);
	eq(array('private', 'public'), array_column($s->rows('SELECT Tier FROM phoromatic_catalog_tiers ORDER BY Tier'), 'Tier'));
	$e = $s->rows('SELECT * FROM phoromatic_catalog_entries WHERE Tier = \'private\'')[0];
	eq(array('Thing 11', 1, 'The P', 'non-free'), array($e['MachineInstall'], (int)$e['ElevatedRun'], $e['Title'], $e['LicenseClass']));
	eq(2, count($s->rows('SELECT DISTINCT Tier FROM phoromatic_catalog_entries')));
	eq(3, count(glob($s->storage . 'catalog/packs/*')), 'its pack sits with the others');
});
t('work folders older than an hour are collected by the next import, newer ones are left alone', function() use ($s, $ring)
{
	foreach(array('old', 'new') as $n)
	{
		mkdir($s->storage . 'catalog/work/' . $n, 0700);
		file_put_contents($s->storage . 'catalog/work/' . $n . '/x.part', 'x');
	}
	touch($s->storage . 'catalog/work/old', time() - 7200);
	$s->cli('server-catalog-import', '/tmp/b/p2.zip');
	eq(array('new'), array_map('basename', glob($s->storage . 'catalog/work/*')));
	exec('rm -rf ' . escapeshellarg($s->storage . 'catalog/work/new'));
});

echo "\ncrash and corruption (the server's own)\n";
t('a failure after the files are written and before COMMIT: the tables are unchanged, the new files are collected, the next import works', function() use ($s, $ring)
{
	$p3 = mk('p3', $ring, 1, 'public', 3, array(tst('benchy/a'), tst('benchy/c'), tst('benchy/d')), array('revoked' => array(revoked_entry('benchy/old', '0.9.0', 'was bad'), revoked_entry('benchy/b', '1.0.0', 'bad too'))));
	$s->exec('CREATE TRIGGER boom BEFORE INSERT ON phoromatic_catalog_entries BEGIN SELECT RAISE(ABORT, \'injected\'); END');
	$before = $s->snap();
	$audit = count($s->audit('catalog_imported'));
	list($rc, $out) = $s->cli('server-catalog-import', $p3);
	eq(1, $rc, $out);
	ok(has($out, 'Refused (state_write)'), $out);
	eq($before, $s->snap(), 'tables and files as they were: the new index and pack were collected');
	eq($audit, count($s->audit('catalog_imported')), 'no row for an import that did not happen');
	eq(1, count(array_filter($s->audit('catalog_import_refused'), function($r) { return strpos($r['Detail'], 'state_write') !== false; })), 'and one refusal row says why');
	$s->exec('DROP TRIGGER boom');
	list($rc, $out) = $s->cli('server-catalog-import', $p3);
	eq(0, $rc, $out);
	eq(3, (int)$s->one('SELECT Sequence FROM phoromatic_catalog_tiers WHERE Tier = \'public\''));
});
t('files with no rows at all count as first contact', function() use ($s)
{
	foreach(array('state', 'tiers', 'ledger', 'revoked', 'entries') as $t)
	{
		$s->exec('DELETE FROM phoromatic_catalog_' . $t);
	}
	list($rc, $out) = $s->cli('server-catalog-import', '/tmp/b/p3.zip');
	eq(0, $rc, $out);
	ok(has($out, 'sequence 3 (the first)'), $out);
	eq(array(), array_filter(glob($s->storage . 'catalog/signed/*'), function($f) { return substr($f, -7) !== '.signed'; }));
});
t('a second import while one is running is refused as busy, and the first is not disturbed', function() use ($s)
{
	$lock = fopen($s->storage . 'catalog/import.lock', 'c');
	ok(flock($lock, LOCK_EX | LOCK_NB), 'the test holds the lock');
	$before = $s->snap();
	list($rc, $out) = $s->cli('server-catalog-import', '/tmp/b/p3.zip');
	eq(1, $rc, $out);
	ok(has($out, 'Refused (busy): Another catalog import is running.'), $out);
	eq($before, $s->snap());
	flock($lock, LOCK_UN);
	fclose($lock);
});

echo "\nrecovery, rotation and retired keys\n";
t('a generation-2 recovery index is accepted after a generation-1 index at sequence 2^53-1, replaces a poisoned ledger, and clears a thief\'s revocation', function() use ($ring)
{
	$s = new scenario();
	$max = 9007199254740991;
	list($rc, $out) = $s->cli('server-catalog-import', mk('rc1', $ring, 1, 'public', $max, array(tst('benchy/a'), tst('benchy/poison', '1.0.0', null, hash('sha256', 'poisoned tree'))), array('revoked' => array(revoked_entry('benchy/good', '1.0.0', 'a thief')))));
	eq(0, $rc, $out);
	// the same ref with its real tree is refused by the ledger, as any key of generation 1 is bound by it
	list($rc, $out) = $s->cli('server-catalog-import', mk('rc-bad', $ring, 1, 'public', $max, array(tst('benchy/a'), tst('benchy/poison')), array('revoked' => array(revoked_entry('benchy/good', '1.0.0', 'a thief')))));
	eq(1, $rc);
	$rec = mk('rc2', $ring, 2, 'public', 1, array(tst('benchy/a'), tst('benchy/poison')), array('min_generation' => 2));
	list($rc, $out) = $s->cli('server-catalog-import', $rec);
	eq(0, $rc, $out);
	ok(has($out, 'recovery release'), $out);
	eq(hash('sha256', 'treebenchy/poison1.0.0'), $s->one('SELECT Hash FROM phoromatic_catalog_ledger WHERE Ref = \'benchy/poison@1.0.0\''), 'the poisoned row is replaced');
	eq(0, (int)$s->one('SELECT COUNT(*) FROM phoromatic_catalog_revoked'), 'the thief\'s revocation is cleared');
	eq(array(2, 1), array((int)$s->one('SELECT Generation FROM phoromatic_catalog_tiers'), (int)$s->one('SELECT Sequence FROM phoromatic_catalog_tiers')));
	eq(2, (int)$s->one('SELECT RetiredBelow FROM phoromatic_catalog_state'));
	$d = $s->last_detail('catalog_imported');
	eq(array(true, array(0, 2)), array($d['recovery'], $d['retired_below']));
});
t('a retired key is refused for the other tier, including one the server has never held; a stored tier of a retired generation is not served', function() use ($ring)
{
	$s = new scenario();
	$s->cli('server-catalog-import', mk('rt-priv', $ring, 1, 'private', 1, array(tst('benchy/p'))));
	$s->cli('server-catalog-import', mk('rt-pub', $ring, 1, 'public', 1, array(tst('benchy/a'))));
	list($rc, $out) = $s->cli('server-catalog-import', mk('rt-rec', $ring, 2, 'public', 1, array(tst('benchy/a')), array('min_generation' => 2)));
	eq(0, $rc, $out);
	list(, $out) = $s->cli('server-catalog-status');
	ok(has($out, 'Free catalog (public): served') && has($out, 'Non-free catalog (private): NOT SERVED: the signing key is retired') && has($out, 'Retired key generations: below 2'), $out);
	list($rc, $out) = $s->cli('server-catalog-import', mk('rt-priv2', $ring, 1, 'private', 2, array(tst('benchy/p'), tst('benchy/q'))));
	eq(1, $rc);
	ok(has($out, 'Refused (retired_key)'), $out);
	$s2 = new scenario();
	$s2->cli('server-catalog-import', '/tmp/b/rt-rec.zip');
	list($rc, $out) = $s2->cli('server-catalog-import', mk('rt-never', $ring, 1, 'private', 1, array(tst('benchy/p'))));
	eq(1, $rc);
	ok(has($out, 'Refused (retired_key)'), $out . ' (a tier the server has never held)');
});
t('a planned rotation: a generation-2 index without min_generation keeps the ledger and revocations, and leaves the other tier served', function() use ($ring)
{
	$s = new scenario();
	$s->cli('server-catalog-import', mk('ro-priv', $ring, 1, 'private', 1, array(tst('benchy/p'))));
	$s->cli('server-catalog-import', mk('ro-pub1', $ring, 1, 'public', 1, array(tst('benchy/a')), array('revoked' => array(revoked_entry('benchy/old', '0.9.0')))));
	list($rc, $out) = $s->cli('server-catalog-import', mk('ro-pub2', $ring, 2, 'public', 1, array(tst('benchy/a'), tst('benchy/b')), array('revoked' => array(revoked_entry('benchy/old', '0.9.0')))));
	eq(0, $rc, $out);
	eq(false, $s->last_detail('catalog_imported')['recovery']);
	eq(0, (int)$s->one('SELECT RetiredBelow FROM phoromatic_catalog_state'));
	list(, $out) = $s->cli('server-catalog-status');
	ok(has($out, 'Non-free catalog (private): served') && has($out, 'Free catalog (public): served'), $out);
	list($rc, $out) = $s->cli('server-catalog-import', mk('ro-bad', $ring, 2, 'public', 2, array(tst('benchy/a'), tst('benchy/b')), array()));
	eq(1, $rc);
	ok(has($out, 'revocation_dropped'), 'a generation-2 key is still bound by the ledger and the revocations: ' . $out);
});

echo "\nstored indexes against a changed key table\n";
t('a table without the key, or with a raised floor, leaves a stored tier served; denying the key stops it', function() use ($ring)
{
	$s = new scenario();
	$s->cli('server-catalog-import', mk('st-pub', $ring, 1, 'public', 4, array(tst('benchy/a'))));
	try
	{
	write_keys($ring, array(), array(), array(2));
	list(, $out) = $s->cli('server-catalog-status');
	ok(has($out, 'Free catalog (public): served'), 'without the generation-1 key: ' . $out);
	write_keys($ring, array(), array('public' => array('generation' => 1, 'sequence' => 99)));
	list(, $out) = $s->cli('server-catalog-status');
	ok(has($out, 'Free catalog (public): served'), 'with a raised floor: ' . $out);
	list($rc, $out) = $s->cli('server-catalog-import', mk('st-floor', $ring, 1, 'public', 5, array(tst('benchy/a'))));
	eq(1, $rc);
	ok(has($out, 'Refused (floor)'), $out);
	write_keys($ring, array($ring->key_id(1)));
	list(, $out) = $s->cli('server-catalog-status');
	ok(has($out, 'NOT SERVED: the signing key is no longer trusted'), $out);
	$notice = $s->boot('echo json_encode(phoromatic_catalog::notices());');
	ok(has($notice, 'no longer trusted'), $notice);
	list($rc, $out) = $s->cli('server-catalog-import', '/tmp/b/st-pub.zip');
	eq(1, $rc);
	ok(has($out, 'Refused (denied_key)'), 'the same file from a denied key: ' . $out);
	}
	finally
	{
		write_keys($ring);
	}
});
t('a damaged key table: imports are refused, the stored catalog is still reported, and nothing is written', function() use ($ring)
{
	$s = new scenario();
	$s->cli('server-catalog-import', mk('kt-pub', $ring, 1, 'public', 1, array(tst('benchy/a'))));
	$good = file_get_contents('/tmp/tree/pts-core/static/catalog/keys.json');
	file_put_contents('/tmp/tree/pts-core/static/catalog/keys.json', '{"schema":"nope"}');
	try
	{
	$before = $s->snap();
	list($rc, $out) = $s->cli('server-catalog-import', '/tmp/b/kt-pub.zip');
	eq(1, $rc);
	ok(has($out, 'Refused (key_table)') && has($out, 'catalog key table is damaged'), $out);
	eq($before, $s->snap());
	list(, $out) = $s->cli('server-catalog-status');
	ok(has($out, 'key table is damaged'), $out);
	}
	finally
	{
		file_put_contents('/tmp/tree/pts-core/static/catalog/keys.json', $good);
	}
});

echo "\na table that cannot be read is a refusal, not an empty table\n";
t('an unreadable ledger, revocation or tier table refuses the import (state_corrupt) instead of skipping the rollback and ledger checks', function() use ($ring)
{
	foreach(array('revoked', 'ledger', 'tiers', 'state') as $tbl)
	{
		$s = new scenario();
		$s->cli('server-catalog-import', '/tmp/b/p2.zip');
		$before_files = array_merge(glob($s->storage . 'catalog/signed/*'), glob($s->storage . 'catalog/packs/*'));
		$s->exec('ALTER TABLE phoromatic_catalog_' . $tbl . ' RENAME TO hidden_' . $tbl);
		// the older bundle: with the tables read as empty it would be accepted as a first contact
		list($rc, $out) = $s->cli('server-catalog-import', '/tmp/b/p1.zip');
		eq(1, $rc, $tbl . ': ' . $out);
		ok(has($out, 'Refused (state_corrupt)'), $tbl . ': ' . $out);
		$s->exec('ALTER TABLE hidden_' . $tbl . ' RENAME TO phoromatic_catalog_' . $tbl);
		eq(2, (int)$s->one('SELECT Sequence FROM phoromatic_catalog_tiers'), $tbl . ': the tier is still sequence 2');
		eq($before_files, array_merge(glob($s->storage . 'catalog/signed/*'), glob($s->storage . 'catalog/packs/*')), $tbl . ': no file was written');
	}
});

echo "\nthe web path's arguments, and the precondition words\n";
t('run() with via web records the user, the account and the web way', function() use ($ring)
{
	$s = new scenario();
	$out = $s->boot('try { $r = phoromatic_catalog_import::run(\'/tmp/b/p1.zip\', \'web\', \'user\', \'alice\', \'account A1\', \'A1\'); echo $r[\'status\']; } catch(phoromatic_catalog_refusal $e) { echo $e->reason . \': \' . $e->getMessage(); }');
	eq('imported', trim($out));
	$tier = $s->rows('SELECT * FROM phoromatic_catalog_tiers')[0];
	eq(array('web', 'alice', 'A1'), array($tier['ImportedVia'], $tier['ImportedBy'], $tier['ImportedAccount']));
	$row = $s->audit('catalog_imported')[0];
	eq(array('user', 'alice', 'account A1'), array($row['ActorType'], $row['Actor'], $row['ActorDetail']));
});
t('openssl, the curve or zlib missing: each is named, and none is the bundle\'s fault', function()
{
	ok(has(phoromatic_catalog_import::capability_problem(false, false, true), 'openssl extension'), 'openssl');
	ok(has(phoromatic_catalog_import::capability_problem(true, false, true), 'P-256'), 'curve');
	ok(has(phoromatic_catalog_import::capability_problem(true, true, false), 'zlib'), 'zlib');
	eq(null, phoromatic_catalog_import::capability_problem(true, true, true));
	foreach(array('precondition', 'busy', 'state_write', 'storage', 'bundle_unreadable', 'key_table', 'state_corrupt') as $r)
	{
		ok(phoromatic_catalog_refusal::transient($r), $r . ' is transient');
	}
	foreach(array('bad_signature', 'license_tier', 'rollback', 'bundle_pack_hash', 'limit') as $r)
	{
		ok(!phoromatic_catalog_refusal::transient($r), $r . ' is final');
	}
});

echo "\nthe bundle that comes with the package\n";
function packaged_run($s)
{
	return trim($s->boot('phoromatic_catalog_import::packaged(); echo "done";'));
}
t('the packaged bundle is imported at the first pass, once, and never again for the same file', function() use ($ring)
{
	$s = new scenario();
	mkdir('/tmp/tree/catalog');
	copy('/tmp/b/p1.zip', '/tmp/tree/catalog/benchy-catalog-public-1-1.zip');
	eq('done', packaged_run($s));
	$state = $s->rows('SELECT * FROM phoromatic_catalog_state')[0];
	eq(array(hash_file('sha256', '/tmp/b/p1.zip'), 'imported'), array($state['PackagedSHA256'], $state['PackagedOutcome']));
	$via = $s->rows('SELECT ImportedVia, ImportedBy FROM phoromatic_catalog_tiers')[0];
	eq(array('package', 'package'), array($via['ImportedVia'], $via['ImportedBy']));
	$row = $s->audit('catalog_imported')[0];
	eq(array('system', 'package'), array($row['ActorType'], $row['Actor']));
	$before = $s->snap();
	$n = count($s->audit('catalog_imported'));
	eq('done', packaged_run($s));
	eq($before, $s->snap());
	eq($n, count($s->audit('catalog_imported')), 'the next start does nothing, and says nothing');
});
t('a packaged bundle below what the server holds is recorded as older: no alarm, no refusal row, no notice', function() use ($ring)
{
	$s = new scenario();
	$s->cli('server-catalog-import', '/tmp/b/p2.zip');
	copy('/tmp/b/p1.zip', '/tmp/tree/catalog/benchy-catalog-public-1-1.zip');
	eq('done', packaged_run($s));
	eq('older', $s->one('SELECT PackagedOutcome FROM phoromatic_catalog_state'));
	eq(0, count($s->audit('catalog_import_refused')));
	eq('', $s->boot('echo phoromatic_catalog::notices_html();'));
	eq(2, (int)$s->one('SELECT Sequence FROM phoromatic_catalog_tiers'));
});
t('a packaged bundle refused for its content: the catalog is intact, the start goes on, it is audited once and not again, and the notice says why', function() use ($ring)
{
	$s = new scenario();
	$s->cli('server-catalog-import', '/tmp/b/p1.zip');
	$bad = mk('pk-lic', $ring, 1, 'public', 2, array(tst('benchy/a'), tst('benchy/n', '1.0.0', null, null, array('license_class' => 'non-free'))), array('revoked' => array(revoked_entry('benchy/old', '0.9.0', 'was bad'))));
	copy($bad, '/tmp/tree/catalog/benchy-catalog-public-1-1.zip');
	$before = $s->snap();
	eq('done', packaged_run($s));
	$after = $s->snap();
	unset($before['state'], $after['state']);   // (the state row now says what happened to the packaged bundle; everything else is as it was)
	eq($before, $after);
	$o = $s->one('SELECT PackagedOutcome FROM phoromatic_catalog_state');
	ok(strpos($o, 'refused (license_tier)') === 0, $o);
	eq(1, count($s->audit('catalog_import_refused')));
	eq('done', packaged_run($s));
	eq(1, count($s->audit('catalog_import_refused')), 'the same bundle is not audited again');
	$h = $s->boot('echo phoromatic_catalog::notices_html();');
	ok(has($h, 'The catalog bundle that came with this version of Benchy was not imported') && has($h, 'The catalog you had is unchanged.') && has($h, 'license_tier') === false && has($h, 'non-free'), $h);
	// a new package, a different file, is tried again
	copy('/tmp/b/p2.zip', '/tmp/tree/catalog/benchy-catalog-public-1-1.zip');
	eq('done', packaged_run($s));
	eq('imported', $s->one('SELECT PackagedOutcome FROM phoromatic_catalog_state'));
	eq('', $s->boot('echo phoromatic_catalog::notices_html();'), 'and the notice goes');
});
t('a refusal that is not about the bundle (another import holds the lock) is not recorded, so the next start tries again', function() use ($ring)
{
	$s = new scenario();
	copy('/tmp/b/p1.zip', '/tmp/tree/catalog/benchy-catalog-public-1-1.zip');
	mkdir($s->storage . 'catalog', 0700, true);
	$lock = fopen($s->storage . 'catalog/import.lock', 'c');
	flock($lock, LOCK_EX | LOCK_NB);
	eq('done', packaged_run($s));
	flock($lock, LOCK_UN);
	fclose($lock);
	eq(null, $s->one('SELECT PackagedSHA256 FROM phoromatic_catalog_state'), 'no outcome recorded');
	eq(0, count($s->audit('catalog_imported')));
	eq('done', packaged_run($s));
	eq('imported', $s->one('SELECT PackagedOutcome FROM phoromatic_catalog_state'));
});
t('two bundle files in the package folder: none is imported, and the outcome says so', function()
{
	$s = new scenario();
	copy('/tmp/b/p2.zip', '/tmp/tree/catalog/benchy-catalog-public-1-2.zip');
	eq('done', packaged_run($s));
	eq(0, count($s->rows('SELECT * FROM phoromatic_catalog_tiers')));
	ok(strpos($s->one('SELECT PackagedOutcome FROM phoromatic_catalog_state'), 'refused (several_bundles)') === 0, 'the outcome says why');
	unlink('/tmp/tree/catalog/benchy-catalog-public-1-2.zip');
	unlink('/tmp/tree/catalog/benchy-catalog-public-1-1.zip');
});
t('a packaged bundle that holds the private tier is refused as final, and nothing is written', function() use ($ring)
{
	$s = new scenario();
	copy(mk('pk-priv', $ring, 1, 'private', 1, array(tst('benchy/p'))), '/tmp/tree/catalog/benchy-catalog-public-1-1.zip');
	eq('done', packaged_run($s));
	unlink('/tmp/tree/catalog/benchy-catalog-public-1-1.zip');
	eq(0, count($s->rows('SELECT * FROM phoromatic_catalog_tiers')) + count($s->rows('SELECT * FROM phoromatic_catalog_entries')) + count($s->rows('SELECT * FROM phoromatic_catalog_ledger')));
	eq(array(), array_merge(glob($s->storage . 'catalog/signed/*'), glob($s->storage . 'catalog/packs/*'), glob($s->storage . 'catalog/work/*')));
	ok(strpos($s->one('SELECT PackagedOutcome FROM phoromatic_catalog_state'), 'refused (package_tier)') === 0, 'a final outcome, recorded');
	eq(1, count($s->audit('catalog_import_refused')));
	// the same bundle by hand is fine: only the packaged path is public-only
	list($rc, $out) = $s->cli('server-catalog-import', '/tmp/b/pk-priv.zip');
	eq(0, $rc, $out);
});
t('no bundle in the package (a source install): nothing happens', function()
{
	$s = new scenario();
	eq('done', packaged_run($s));
	eq(0, count($s->rows('SELECT * FROM phoromatic_catalog_state')));
	eq(array(), $s->audit('catalog_imported'));
});

echo "\n" . $GLOBALS['T_COUNT']['pass'] . ' passed, ' . $GLOBALS['T_COUNT']['fail'] . " failed\n";
if($GLOBALS['T_COUNT']['fail'] > 0)
{
	echo 'FAILED: ' . implode('; ', $GLOBALS['T_FAILED']) . "\n";
	exit(1);
}
