<?php
// pts_storage_object: the temporary file, the owner kept when root saves another user's file, stale temporaries, and no store path. Run by storage.sh as root
// in a throw-away container, in a folder of its own: php storage_object_check.php <folder>
define('PTS_CORE_VERSION', 'test');
require '/bench/pts-core/objects/pts_storage_object.php';
$dir = rtrim($argv[1], '/') . '/';
$fails = 0;
function check($ok, $what) { global $fails; echo '  ' . ($ok ? 'PASS' : 'FAIL') . '  ' . $what . PHP_EOL; if(!$ok) $fails++; }
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
touch($file . '.' . $dead . '.tmp'); touch($file . '.1.tmp');
$o->save_to_file($file);
check(!file_exists($file . '.' . $dead . '.tmp'), 'a *.tmp left by a process that no longer exists is removed');
check(file_exists($file . '.1.tmp'), 'one of a process that is running is left alone');
check(pts_storage_object::read_from_file($file, 'k') === 'v', 'and the file reads back');
umask(0022);

// L3: root saving a file that belongs to another user
$theirs = $dir . 'theirs.pt2so';
$o->save_to_file($theirs); chown($theirs, 33); chgrp($theirs, 33); chmod($theirs, 0600);
$o->add_object('k2', 'v2'); $o->save_to_file($theirs);
clearstatcache();
check(fileowner($theirs) === 33 && filegroup($theirs) === 33, 'root saving a file of uid 33 leaves it owned by uid 33, group 33 (' . fileowner($theirs) . ':' . filegroup($theirs) . ')');
check($mode($theirs) === '0600', 'and at 0600');
check(pts_storage_object::read_from_file($theirs, 'k2') === 'v2', 'with the new content');
pts_storage_object::set_in_file($theirs, 'k3', 'v3');
clearstatcache();
check(fileowner($theirs) === 33 && pts_storage_object::read_from_file($theirs, 'k3') === 'v3', 'set_in_file keeps the owner too');
echo $fails === 0 ? 'STORAGE OBJECT: PASS' . PHP_EOL : 'STORAGE OBJECT: FAIL (' . $fails . ')' . PHP_EOL;
exit($fails === 0 ? 0 : 1);
