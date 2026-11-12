<?php
// Two parts, run by storage.sh in throw-away containers, each in a folder of its own: php storage_object_check.php server|client <folder>
//   server  (as root) the Phoromatic server's settings file, phoromatic_settings_store: the atomic 0600 save, the temporary files, the lock, a file that cannot be
//           read, and root's refusal of a folder that belongs to someone else or that others can write to (root changes nothing there)
//   client  (as an ordinary user) pts_storage_object, the client's storage objects (core.pt2so): exactly as in 0.9.11, no lock file, no temporary file, no switching
define('PTS_CORE_VERSION', 'test');
define('BENCHY_STORAGE_TEST_HOOK', true);   // lets phoromatic_settings_store::$swap_hook run (never defined by the product)
require '/bench/pts-core/objects/pts_storage_object.php';
require '/bench/pts-core/objects/phoromatic/phoromatic_settings_store.php';
$part = $argv[1];
$dir = rtrim($argv[2], '/') . '/';
$fails = 0;
function check($ok, $what) { global $fails; echo '  ' . ($ok ? 'PASS' : 'FAIL') . '  ' . $what . PHP_EOL; if(!$ok) $fails++; }
// the name tempnam gives a temporary file of $f for process $pid (PHP 8.4+ adds 19 random characters, not 6)
$tmpname = function($f, $pid, $suffix = 'Ab3dEf6hIj9lMn2pQrS') { return dirname($f) . '/.pts-' . substr(md5(basename($f)), 0, 8) . '.' . $pid . '.' . $suffix; };
$mode = function($f) { return substr(sprintf('%o', fileperms($f)), -4); };
// a listing of a folder with owners, modes, sizes and times, to show nothing in it changed
$snapshot = function($d) { clearstatcache(); $o = array(); foreach(array_merge(array($d), glob(rtrim($d, '/') . '/{,.}[!.]*', GLOB_BRACE)) as $f) { $s = lstat($f); $o[$f] = array($s['uid'], $s['gid'], $s['mode'], $s['size'], $s['mtime'], $s['ino']); } return $o; };

if($part === 'client')
{
	// The client's storage objects behave as in 0.9.11: save_to_file writes the file (file_put_contents, chmod 0600), the in-file calls read, change and save
	// with no lock and no temporary file, and the class has none of the server's machinery
	chdir($dir);
	umask(0000);
	$file = $dir . 'core.pt2so';
	$o = new pts_storage_object(); $o->add_object('k', 'v'); $o->save_to_file($file);
	check($mode($file) === '0600', 'core.pt2so: a new file is 0600 with umask 000 (' . $mode($file) . ')');
	pts_storage_object::set_in_file($file, 'a', 1);
	pts_storage_object::add_in_file($file, 'total_testing_time', 2.5);
	pts_storage_object::add_in_file($file, 'total_testing_time', 2.5);
	pts_storage_object::remove_in_file($file, 'a');
	check(pts_storage_object::read_from_file($file, 'k') === 'v' && pts_storage_object::read_from_file($file, 'total_testing_time') == 5 && pts_storage_object::read_from_file($file, 'a') === false, 'set, add, remove and read work');
	check(array_map('basename', glob($dir . '{,.}[!.]*', GLOB_BRACE)) === array('core.pt2so'), 'and the folder holds the file and nothing else: no .lock file, no temporary file (' . implode(' ', array_map('basename', glob($dir . '{,.}[!.]*', GLOB_BRACE))) . ')');
	$ino = fileinode($file);
	$o->add_object('k2', 'v2'); $o->save_to_file($file); clearstatcache();
	check(fileinode($file) === $ino, 'a save writes the same file in place, as 0.9.11 did (it is not replaced by a rename)');
	check($mode($file) === '0600', 'and it stays 0600');
	pts_storage_object::set_in_file(null, 'a', 1); pts_storage_object::set_in_file($dir . 'missing.pt2so', 'a', 1);
	check(!file_exists($dir . 'missing.pt2so') && count(glob($dir . '{,.}[!.]*', GLOB_BRACE)) === 1, 'a missing file stays missing (set_in_file does nothing, as in 0.9.11)');
	foreach(array('locked', 'as_user', 'owner_to_save_as', 'save_here', 'write_private', 'remove_stale_temporaries') as $m)
	{
		if(method_exists('pts_storage_object', $m)) { check(false, 'pts_storage_object has no ' . $m . '()'); }
	}
	check(!method_exists('pts_storage_object', 'locked') && !property_exists('pts_storage_object', 'swap_hook') && !property_exists('pts_storage_object', 'switched'), 'pts_storage_object has no lock, no user switching and no test hook');
	echo $fails === 0 ? 'STORAGE OBJECT (client): PASS' . PHP_EOL : 'STORAGE OBJECT (client): FAIL (' . $fails . ')' . PHP_EOL;
	exit($fails === 0 ? 0 : 1);
}

// ---- server part (root, in a root-owned 0755 folder)
chmod($dir, 0755);
chdir($dir);
$S = 'phoromatic_settings_store';

// no store path
$ran = false;
$r = $S::locked(null, function() use (&$ran) { $ran = true; return 'ran'; });
check($ran && $r === 'ran', 'locked() with no store path just runs the callback');
$S::set_in_file(null, 'a', 1); $S::remove_in_file('', 'a');
check(glob($dir . '{,.}[!.]*', GLOB_BRACE) === array() && !file_exists('.lock') && !file_exists('/.lock'), 'and no lock file is made anywhere, the set/remove calls do nothing');

// 0600 whatever the umask, the umask put back, stale temporaries of dead processes removed, a live process's kept
umask(0000);
$file = $dir . 'one.pt2so';
$o = new pts_storage_object(); $o->add_object('k', 'v'); $S::save($o, $file);
check($mode($file) === '0600', 'a new file is 0600 with umask 000 (' . $mode($file) . ')');
check(umask() === 0000, 'and the umask is back as it was');
check(pts_storage_object::read_from_file($file, 'k') === 'v', 'the pts_storage_object class (and so an older release) reads what the server store wrote');
$dead = 99999; while(posix_kill($dead, 0)) { $dead++; }
touch($tmpname($file, $dead), time() - 3600); touch($tmpname($file, 1), time() - 3600);
$fresh = $tmpname($file, $dead + 1); touch($fresh);   // a dead pid's file made a moment ago is left alone
touch($tmpname($file, $dead, 'Ab3dE'), time() - 3600);   // (fewer than 6 random characters: not one of ours)
$S::save($o, $file);
check(!file_exists($tmpname($file, $dead)), 'a temporary file left by a process that no longer exists is removed');
check(file_exists($tmpname($file, 1)), 'one of a process that is running is left alone');
check(file_exists($fresh), 'and one made a few minutes ago is left alone even when its process is gone');
check(file_exists($tmpname($file, $dead, 'Ab3dE')), 'and a name with fewer than 6 random characters is not taken for a temporary file');
check($S::read_from_file($file, 'k') === 'v', 'and the file reads back');
umask(0022);

// a link planted at the old, guessable temporary name (<file>.<pid>.tmp) is never used: the temporary file is made by tempnam under a random name
$victim = $dir . 'victim.txt'; file_put_contents($victim, 'precious');
$planted = $dir . 'two.pt2so'; $S::save($o, $planted);
symlink($victim, $planted . '.' . getmypid() . '.tmp');
$o->add_object('k9', 'v9'); $S::save($o, $planted);
check(file_get_contents($victim) === 'precious', 'a symlink planted at the temporary name: its target is left untouched');
check($S::read_from_file($planted, 'k9') === 'v9' && $mode($planted) === '0600', 'the save still happened, at 0600');
$dangling = $dir . 'three.pt2so'; $S::save($o, $dangling);
symlink($dir . 'does-not-exist-yet', $dangling . '.' . getmypid() . '.tmp');
$S::save($o, $dangling);
check(!file_exists($dir . 'does-not-exist-yet'), 'a link to a file that does not exist is not followed either (nothing is created through it)');
$odd = $dir . 'a[b]*.pt2so'; $S::save($o, $odd);
touch($tmpname($dir . 'aXb.pt2so', $dead), time() - 3600);
$S::save($o, $odd);
check(file_exists($tmpname($dir . 'aXb.pt2so', $dead)), 'the stale-file clean-up matches the name literally, not as a pattern');

// the temporary file is swapped for a link between the write and the rename: refused, the target untouched, the old file kept, nothing left behind
$secret = $dir . 'shadow.txt'; file_put_contents($secret, 'root only'); chmod($secret, 0640);
$before = array(fileowner($secret), filegroup($secret), $mode($secret), file_get_contents($secret));
$swapped = $dir . 'swapped.pt2so'; $S::save($o, $swapped);
foreach(array('symbolic link' => function($tmp) use ($secret) { unlink($tmp); symlink($secret, $tmp); },
              'hard link' => function($tmp) use ($secret) { unlink($tmp); if(!@link($secret, $tmp)) { $GLOBALS['hardlink_refused'] = true; symlink($secret, $tmp); } }) as $what => $hook)
{
	$S::$swap_hook = $hook;
	$threw = false;
	try { $o->add_object('swap', 1); $S::save($o, $swapped); } catch(Exception $e) { $threw = true; }
	$S::$swap_hook = null;
	clearstatcache();
	if($what === 'hard link' && !empty($GLOBALS['hardlink_refused'])) { echo '  NOTE  the kernel refused the hard link (protected_hardlinks): this round tried a symbolic link instead' . PHP_EOL; }
	check($threw, 'a temporary file swapped for a ' . $what . ' before the rename: the save fails with an error');
	check(array(fileowner($secret), filegroup($secret), $mode($secret), file_get_contents($secret)) === $before, 'and the target keeps its owner, mode and content');
	check($S::read_from_file($swapped, 'swap') === false, 'the old file is kept as it was');
	check(glob($dir . '.pts-*' . substr(md5('swapped.pt2so'), 0, 8) . '*') === array(), 'and nothing is left in the folder');
}
$o->remove_object('swap');

// a storage folder reached through a link, with // in it, or relative still saves by rename (atomic), never in place
mkdir($dir . 'real'); symlink($dir . 'real', $dir . 'viaLink');
$renames = 0;
$S::$swap_hook = function($tmp) use (&$renames) { $renames++; };
foreach(array($dir . 'viaLink/s.pt2so', $dir . 'real//s2.pt2so', 'real/../real/s3.pt2so') as $n => $where)
{
	$S::save($o, $where);
	check(is_file($where) && $S::read_from_file($where, 'k') === 'v' && $renames === $n + 1, 'saved by rename in ' . $where);
}
$S::$swap_hook = null;
$link = $dir . 'real/lnk.pt2so'; symlink($victim, $link);
$S::save($o, $link);
check(file_get_contents($victim) === 'precious' && !is_link($link), 'a destination that is a link: the link is replaced, its target is not written');

// set, remove, and a lock held again inside itself
$lf = $dir . 'locked.pt2so'; $S::save($o, $lf);
$S::set_in_file($lf, 'kn', 'vn');
$nested = $S::locked($lf, function() use ($S, $lf) { return $S::locked($lf, function() use ($S, $lf) { $S::set_in_file($lf, 'km', 'vm'); return 'inner'; }); });
check($nested === 'inner' && $S::read_from_file($lf, 'kn') === 'vn' && $S::read_from_file($lf, 'km') === 'vm', 'set_in_file works, and a lock held again inside itself (with a save inside it) works');
check(is_file($lf . '.lock') && $mode($lf . '.lock') === '0600', 'the lock file is <file>.lock at 0600');
$S::remove_in_file($lf, 'kn');
check($S::read_from_file($lf, 'kn') === false && $S::read_from_file($lf, 'km') === 'vm', 'remove_in_file removes just that setting');

// a file that is there and cannot be read is an error, never an empty store; a missing one is just not there yet
$bad = $dir . 'bad.pt2so'; file_put_contents($bad, 'this is not a settings file');
foreach(array('read_from_file' => function() use ($S, $bad) { return $S::read_from_file($bad, 'x'); },
              'set_in_file' => function() use ($S, $bad) { return $S::set_in_file($bad, 'x', 1); },
              'remove_in_file' => function() use ($S, $bad) { return $S::remove_in_file($bad, 'x'); }) as $what => $do)
{
	$threw = false; $msg = '';
	try { $do(); } catch(Exception $e) { $threw = true; $msg = $e->getMessage(); }
	check($threw && strpos($msg, 'bad.pt2so') !== false && file_get_contents($bad) === 'this is not a settings file', $what . ' on a damaged file throws, names it, and leaves it alone');
}
check($S::read_from_file($dir . 'not-there.pt2so', 'x') === false, 'a file that is not there reads as not set (the first start)');
$S::set_in_file($dir . 'not-there.pt2so', 'x', 1);
check(!file_exists($dir . 'not-there.pt2so'), 'and set_in_file does not make it (the first start makes it under the lock)');
$dangle = $dir . 'dangling.pt2so'; symlink($dir . 'nowhere', $dangle);
$threw = false; try { $S::read_from_file($dangle, 'x'); } catch(Exception $e) { $threw = true; }
check($threw, 'a link to nothing is a file that is there and cannot be read: it throws');
$unreadable = $dir . 'noread.pt2so'; $S::save($o, $unreadable); chmod($unreadable, 0000);
// (root reads everything: drop to nobody for this one)
if(posix_geteuid() === 0 && @posix_seteuid(65534))
{
	$threw = false; try { $S::read_from_file($unreadable, 'k'); } catch(Exception $e) { $threw = true; }
	posix_seteuid(0);
	check($threw, 'a file this user may not read throws');
}

// ---- root against a folder that is not its own: refused, and root changes NOTHING in it
$run = function($f) use ($S, $o) { $S::set_in_file($f, 'kz', 'vz'); };
$cases = array();
$other = $dir . 'u33/'; mkdir($other); chown($other, 33); chgrp($other, 33);
$cases['a folder owned by uid 33 (0755)'] = array($other, 'belongs to', 33);
$gw = $dir . 'gw/'; mkdir($gw); chmod($gw, 0775);
$cases['a root-owned folder that is group-writable (0775)'] = array($gw, 'writable by its group or by others', 0);
$ow = $dir . 'ow/'; mkdir($ow); chmod($ow, 0757);
$cases['a root-owned folder that is world-writable (0757)'] = array($ow, 'writable by its group or by others', 0);
$lockplant = $dir . 'shadow2.txt'; file_put_contents($lockplant, 'root only too'); chmod($lockplant, 0640);
foreach($cases as $what => list($folder, $words, $uid))
{
	$f = $folder . 'phoromatic-settings.pt2so';
	$o->save_to_file($f);   // (put there the way the folder's owner would have: not through the server store, which is what is being tested)
	chown($f, $uid); chgrp($f, $uid); chmod($f, 0600);
	symlink($lockplant, $f . '.lock');   // a lock link planted by whoever controls the folder, pointing at a root-only file
	$beforefs = $snapshot($folder); $content = file_get_contents($f);
	$lbefore = array(fileowner($lockplant), filegroup($lockplant), $mode($lockplant), file_get_contents($lockplant));
	foreach(array('set_in_file' => function() use ($S, $f) { $S::set_in_file($f, 'kz', 'vz'); },
	              'remove_in_file' => function() use ($S, $f) { $S::remove_in_file($f, 'k'); },
	              'locked' => function() use ($S, $f) { $S::locked($f, function() { return 'ran'; }); },
	              'save' => function() use ($S, $f, $o) { $S::save($o, $f); }) as $call => $do)
	{
		$threw = false; $msg = '';
		try { $do(); } catch(Exception $e) { $threw = true; $msg = $e->getMessage(); }
		clearstatcache();
		check($threw && strpos($msg, $words) !== false, 'root, ' . $what . ': ' . $call . ' is refused with an error that says why');
	}
	check($snapshot($folder) === $beforefs && file_get_contents($f) === $content, 'root, ' . $what . ': nothing in the folder changed (owners, modes, sizes, times, inodes, content)');
	check(array(fileowner($lockplant), filegroup($lockplant), $mode($lockplant), file_get_contents($lockplant)) === $lbefore, 'and the root-only file the planted lock link points at keeps its owner, mode and content');
	check(posix_geteuid() === 0 && posix_getegid() === 0, 'and root is still root');
	check($S::read_from_file($f, 'k') === 'v', 'a plain read of the file is still allowed');
	$m = $S::root_refusal($folder);
	if($uid === 33) { check(strpos($m, 'sudo -u ' . (posix_getpwuid(33)['name']) . ' benchy') !== false, 'the message says to run the server commands as the owner (sudo -u <owner>)'); }
	else { check(strpos($m, 'chmod go-w') !== false, 'the message says how to fix the mode'); }
}
// root against a root-owned 0755 folder works (that is $dir itself, above); a folder that is not there yet is not refused
check($S::root_refusal($dir) === null && $S::root_refusal($dir . 'not-made-yet/') === null, 'root_refusal: a root-owned 0755 folder, and a folder not made yet, are fine');
$rok = $dir . 'rok/'; mkdir($rok); chmod($rok, 0755);
$S::set_in_file($rok . 'x.pt2so', 'a', 1);
$o->save_to_file($rok . 'x.pt2so');
$S::set_in_file($rok . 'x.pt2so', 'kz', 'vz');
check($S::read_from_file($rok . 'x.pt2so', 'kz') === 'vz' && $mode($rok . 'x.pt2so') === '0600' && fileowner($rok . 'x.pt2so') === 0, 'root in a root-owned 0755 folder: the change is saved, 0600, owned by root');
check(array_map('basename', glob($rok . '{,.}[!.]*', GLOB_BRACE)) === array('x.pt2so', 'x.pt2so.lock'), 'with only the file and its lock file in the folder (' . implode(' ', array_map('basename', glob($rok . '{,.}[!.]*', GLOB_BRACE))) . ')');
// a non-root user is never refused by the root rule
if(@posix_seteuid(65534)) { check($S::root_refusal($other) === null, 'the root rule is only for root: another user is not refused by it'); posix_seteuid(0); }

echo $fails === 0 ? 'STORAGE OBJECT (server): PASS' . PHP_EOL : 'STORAGE OBJECT (server): FAIL (' . $fails . ')' . PHP_EOL;
exit($fails === 0 ? 0 : 1);
