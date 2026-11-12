<?php
// pts_storage_object: the temporary file, the owner kept when root saves another user's file, stale temporaries, and no store path. Run by storage.sh as root
// in a throw-away container, in a folder of its own: php storage_object_check.php <folder>
define('PTS_CORE_VERSION', 'test');
define('BENCHY_STORAGE_TEST_HOOK', true);   // lets pts_storage_object::$swap_hook run (never defined by the product)
require '/bench/pts-core/objects/pts_storage_object.php';
$dir = rtrim($argv[1], '/') . '/';
$fails = 0;
function check($ok, $what) { global $fails; echo '  ' . ($ok ? 'PASS' : 'FAIL') . '  ' . $what . PHP_EOL; if(!$ok) $fails++; }
// the name tempnam gives a temporary file of $f for process $pid (PHP 8.4+ adds 19 random characters, not 6)
$tmpname = function($f, $pid, $suffix = 'Ab3dEf6hIj9lMn2pQrS') { return dirname($f) . '/.pts-' . substr(md5(basename($f)), 0, 8) . '.' . $pid . '.' . $suffix; };
$mode = function($f) { return substr(sprintf('%o', fileperms($f)), -4); };

// L5: no store path
chdir($dir);
$ran = false;
$r = pts_storage_object::locked(null, function() use (&$ran) { $ran = true; return 'ran'; });
check($ran && $r === 'ran', 'locked() with no store path just runs the callback');
pts_storage_object::set_in_file(null, 'a', 1); pts_storage_object::add_in_file('', 'a', 1); pts_storage_object::remove_in_file(null, 'a');
check(glob($dir . '*') === array() && !file_exists('.lock') && !file_exists('/.lock'), 'and no lock file is made anywhere, the set/add/remove calls do nothing');

// M4: 0600 whatever the umask, the umask put back, stale temporaries of dead processes removed, a live process's kept
umask(0000);
$file = $dir . 'one.pt2so';
$o = new pts_storage_object(); $o->add_object('k', 'v'); $o->save_to_file($file);
check($mode($file) === '0600', 'a new file is 0600 with umask 000 (' . $mode($file) . ')');
check(umask() === 0000, 'and the umask is back as it was');
$dead = 99999; while(posix_kill($dead, 0)) { $dead++; }
touch($tmpname($file, $dead), time() - 3600); touch($tmpname($file, 1), time() - 3600);
$fresh = $tmpname($file, $dead + 1); touch($fresh);   // a dead pid's file made a moment ago is left alone
$o->save_to_file($file);
check(!file_exists($tmpname($file, $dead)), 'a temporary file left by a process that no longer exists is removed');
check(file_exists($tmpname($file, 1)), 'one of a process that is running is left alone');
check(file_exists($fresh), 'and one made a few minutes ago is left alone even when its process is gone');
check(pts_storage_object::read_from_file($file, 'k') === 'v', 'and the file reads back');
umask(0022);

// M-C: a link planted at the old, guessable temporary name (<file>.<pid>.tmp) is never used: the temporary file is made by tempnam under a random name
$victim = $dir . 'victim.txt'; file_put_contents($victim, 'precious');
$planted = $dir . 'two.pt2so'; $o->save_to_file($planted);
symlink($victim, $planted . '.' . getmypid() . '.tmp');
$o->add_object('k9', 'v9'); $o->save_to_file($planted);
check(file_get_contents($victim) === 'precious', 'a symlink planted at the temporary name: its target is left untouched');
check(pts_storage_object::read_from_file($planted, 'k9') === 'v9' && $mode($planted) === '0600', 'the save still happened, at 0600');
$dangling = $dir . 'three.pt2so'; $o->save_to_file($dangling);
symlink($dir . 'does-not-exist-yet', $dangling . '.' . getmypid() . '.tmp');
$o->save_to_file($dangling);
check(!file_exists($dir . 'does-not-exist-yet'), 'a link to a file that does not exist is not followed either (nothing is created through it)');
// a path with glob characters does not match its neighbours
$odd = $dir . 'a[b]*.pt2so'; $o->save_to_file($odd);
touch($tmpname($dir . 'aXb.pt2so', $dead), time() - 3600);
$o->save_to_file($odd);
check(file_exists($tmpname($dir . 'aXb.pt2so', $dead)), 'the stale-file clean-up matches the name literally, not as a pattern');

// Root saves into a folder that belongs to uid 33: the whole save is done as 33 and nothing is chowned
$u = $dir . 'u33/'; mkdir($u); chown($u, 33); chgrp($u, 33);
$groups_before = posix_getgroups();
$fresh33 = $u . 'fresh.pt2so';
$o->save_to_file($fresh33);
clearstatcache();
check(is_file($fresh33) && fileowner($fresh33) === 33 && filegroup($fresh33) === 33 && $mode($fresh33) === '0600', 'root saving a new file into uid 33\'s folder: made by 33, owned 33:33, 0600 (' . fileowner($fresh33) . ':' . filegroup($fresh33) . ' ' . $mode($fresh33) . ')');
check(posix_geteuid() === 0 && posix_getegid() === 0 && posix_getgroups() === $groups_before, 'and root\'s user, group and supplementary groups are back afterwards');
check(count(glob($u . '.pts-*')) === 0, 'and no temporary file is left');

// M1: the temporary file is swapped for a link between the write and the rename (the folder owner racing root): refused, nothing the owner could not already reach is touched
$secret = $dir . 'shadow.txt'; file_put_contents($secret, 'root only'); chmod($secret, 0640);
$before = array(fileowner($secret), filegroup($secret), $mode($secret), file_get_contents($secret));
$swapped = $u . 'swapped.pt2so'; $o->save_to_file($swapped);
foreach(array('symbolic link' => function($tmp) use ($secret) { unlink($tmp); symlink($secret, $tmp); },
              'hard link' => function($tmp) use ($secret) { unlink($tmp); if(!@link($secret, $tmp)) { $GLOBALS['hardlink_refused'] = true; symlink($secret, $tmp); } }) as $what => $hook)
{
	pts_storage_object::$swap_hook = $hook;
	$threw = false;
	try { $o->add_object('swap', 1); $o->save_to_file($swapped); } catch(Exception $e) { $threw = true; }
	pts_storage_object::$swap_hook = null;
	clearstatcache();
	if($what === 'hard link' && !empty($GLOBALS['hardlink_refused'])) { echo '  NOTE  the kernel refused the hard link (protected_hardlinks): this round tried a symbolic link instead' . PHP_EOL; }
	check($threw, 'a temporary file swapped for a ' . $what . ' before the rename: the save fails with an error');
	check(array(fileowner($secret), filegroup($secret), $mode($secret), file_get_contents($secret)) === $before, 'and the target keeps its owner, mode and content (nothing is chowned)');
	check(pts_storage_object::read_from_file($swapped, 'swap') === false && fileowner($swapped) === 33, 'the old file is kept as it was, with its owner');
	check(array_filter(glob($u . '.pts-*'), 'is_link') === array() && count(glob($u . '.pts-*')) === 0, 'and nothing is left in the folder');
}
$o->remove_object('swap');

// F1: while root works as uid 33 none of root's groups (gid 0) are kept, and the user is 33
check(in_array(0, $groups_before, true), 'precondition: root has gid 0 among its groups (' . implode(',', $groups_before) . ')');
$seen = null;
pts_storage_object::$swap_hook = function($tmp) use (&$seen) { $seen = array(posix_geteuid(), posix_getegid(), posix_getgroups()); };
$o->save_to_file($u . 'groups.pt2so');
pts_storage_object::$swap_hook = null;
check($seen !== null && $seen[0] === 33 && $seen[1] === 33 && !in_array(0, $seen[2], true), 'while switched: euid 33, egid 33 and gid 0 is not among the groups (' . ($seen ? implode(',', $seen[2]) : '-') . ')');
check(posix_getgroups() === $groups_before, 'and root\'s groups are back afterwards');

// F2: a root-owned file inside uid 33's folder, a link planted as its lock: the folder's owner decides, so root works as 33 and the target is untouched
$rootfile = $u . 'rootowned.pt2so'; file_put_contents($rootfile, 'x'); chown($rootfile, 0); chgrp($rootfile, 0);
symlink($secret, $rootfile . '.lock');
$threw = false;
try { pts_storage_object::set_in_file($rootfile, 'kr', 'vr'); } catch(Exception $e) { $threw = true; }
clearstatcache();
check(array(fileowner($secret), filegroup($secret), $mode($secret), file_get_contents($secret)) === $before, 'a root-owned file in uid 33\'s folder with a lock link planted: the link\'s target is untouched');
unlink($rootfile . '.lock');

// F3 + F4: under the switch there is no write in place (the folder cannot take a temporary file, the file itself is writable), and an unopenable lock file is an error
$ro = $dir . 'ro33/'; mkdir($ro); chown($ro, 33); chgrp($ro, 33);
$inplace = $ro . 'inplace.pt2so'; $o->save_to_file($inplace);
touch($inplace . '.lock'); chown($inplace . '.lock', 33); chgrp($inplace . '.lock', 33); chmod($inplace . '.lock', 0600);
$o->add_object('inplace', 'new');
$content_before = file_get_contents($inplace);
chmod($ro, 0555);
$threw = false;
try { pts_storage_object::set_in_file($inplace, 'ki', 'vi'); } catch(Exception $e) { $threw = true; }
clearstatcache();
check($threw && file_get_contents($inplace) === $content_before, 'F3: a save under the switch that cannot make its temporary file throws and does not write in place');
$stale = $ro . 'stale.pt2so'; chmod($ro, 0755); $o->save_to_file($stale); file_put_contents($stale . '.lock', ''); chown($stale . '.lock', 0); chgrp($stale . '.lock', 0); chmod($stale . '.lock', 0600);
$threw = false; $msg = '';
try { pts_storage_object::set_in_file($stale, 'ks', 'vs'); } catch(Exception $e) { $threw = true; $msg = $e->getMessage(); }
check($threw && strpos($msg, 'stale.pt2so.lock') !== false && pts_storage_object::read_from_file($stale, 'ks') === false, 'F4: a root-owned lock file the folder owner cannot open: a clear error naming it, nothing saved (' . $msg . ')');

// M2: a storage folder reached through a link, with // in it, or relative still saves by rename (atomic), never in place
mkdir($dir . 'real'); symlink($dir . 'real', $dir . 'viaLink');
$renames = 0;
pts_storage_object::$swap_hook = function($tmp) use (&$renames) { $renames++; };
foreach(array($dir . 'viaLink/s.pt2so', $dir . 'real//s2.pt2so', 'real/../real/s3.pt2so') as $n => $where)
{
	$o->save_to_file($where);
	check(is_file($where) && pts_storage_object::read_from_file($where, 'k') === 'v' && $renames === $n + 1, 'saved by rename in ' . $where);
}
pts_storage_object::$swap_hook = null;
// a destination that is itself a link is never written through
$link = $dir . 'real/lnk.pt2so'; symlink($victim, $link);
$o->save_to_file($link);
check(file_get_contents($victim) === 'precious' && !is_link($link), 'a destination that is a link: the link is replaced, its target is not written');

// L3: root saving a file that belongs to another user
$theirs = $u . 'theirs.pt2so';
$o->save_to_file($theirs); chown($theirs, 33); chgrp($theirs, 33); chmod($theirs, 0600);
$o->add_object('k2', 'v2'); $o->save_to_file($theirs);
clearstatcache();
check(fileowner($theirs) === 33 && filegroup($theirs) === 33, 'root saving a file of uid 33 leaves it owned by uid 33, group 33 (' . fileowner($theirs) . ':' . filegroup($theirs) . ')');
check($mode($theirs) === '0600', 'and at 0600');
check(pts_storage_object::read_from_file($theirs, 'k2') === 'v2', 'with the new content');
pts_storage_object::set_in_file($theirs, 'k3', 'v3');
clearstatcache();
check(fileowner($theirs) === 33 && pts_storage_object::read_from_file($theirs, 'k3') === 'v3', 'set_in_file keeps the owner too');
// A link planted as <file>.lock in uid 33's folder, pointing at a root-only file: root saves; the target keeps its owner, mode and content
$lockfile = $u . 'locked.pt2so';
$o->save_to_file($lockfile);
symlink($secret, $lockfile . '.lock');
$threw = false;
try { pts_storage_object::set_in_file($lockfile, 'kl', 'vl'); } catch(Exception $e) { $threw = true; }
clearstatcache();
check(array(fileowner($secret), filegroup($secret), $mode($secret), file_get_contents($secret)) === $before, 'a link planted as <file>.lock: root saving there leaves its target\'s owner, mode and content alone');
check($threw && pts_storage_object::read_from_file($lockfile, 'kl') === false, 'and the save is refused with an error (the owner of the folder cannot open that lock), nothing written');
check(posix_geteuid() === 0 && posix_getegid() === 0, 'root is back afterwards');
// the lock is re-entrant and nests with the user switch; a plain lock file made there belongs to 33
unlink($lockfile . '.lock');
$nested = pts_storage_object::locked($lockfile, function() use ($lockfile) { return pts_storage_object::locked($lockfile, function() use ($lockfile) { pts_storage_object::set_in_file($lockfile, 'kn', 'vn'); return 'inner'; }); });
clearstatcache();
check($nested === 'inner' && pts_storage_object::read_from_file($lockfile, 'kn') === 'vn', 'a lock held again inside itself (and a save inside it) works with the user switch');
check(!file_exists($lockfile . '.lock') || (fileowner($lockfile . '.lock') === 33 && $mode($lockfile . '.lock') === '0600'), 'and the lock file it made belongs to 33 at 0600');
check(posix_geteuid() === 0 && posix_getegid() === 0, 'root is back afterwards (nested)');

echo $fails === 0 ? 'STORAGE OBJECT: PASS' . PHP_EOL : 'STORAGE OBJECT: FAIL (' . $fails . ')' . PHP_EOL;
exit($fails === 0 ? 0 : 1);
