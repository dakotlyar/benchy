<?php

/*
	Phoronix Test Suite
	URLs: https://github.com/dakotlyar/benchy
	Copyright (C) 2008 - 2018, Phoronix Media
	Copyright (C) 2008 - 2018, Michael Larabel

	This program is free software; you can redistribute it and/or modify
	it under the terms of the GNU General Public License as published by
	the Free Software Foundation; either version 3 of the License, or
	(at your option) any later version.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
	GNU General Public License for more details.

	You should have received a copy of the GNU General Public License
	along with this program. If not, see <http://www.gnu.org/licenses/>.
*/

// Usage: php deploy/deb-package/build-package-deb.php [--output DIR]
// Builds benchy_<version>_all.deb and benchy-server_<version>_all.deb into DIR (default: the current directory), so their versions cannot
// drift apart. Every step is checked; the first failure stops the build.

umask(022);

function build_fail($message)
{
	fwrite(STDERR, PHP_EOL . 'ERROR: ' . $message . PHP_EOL . PHP_EOL);
	exit(1);
}
function build_run($command)
{
	// Runs a shell command and returns its output; a non-zero exit stops the build and shows what the command said.
	$output = array();
	$exit_code = 0;
	exec($command . ' 2>&1', $output, $exit_code);
	if($exit_code != 0)
	{
		build_fail('Command failed (exit ' . $exit_code . '): ' . $command . PHP_EOL . implode(PHP_EOL, array_slice($output, -20)));
	}
	return implode(PHP_EOL, $output);
}

if(!is_executable("benchy") || !is_dir("pts-core/"))
{
	build_fail("You must run this script from the root directory of the benchy/ folder!\nExample: php deploy/deb-package/build-package-deb.php");
}
foreach(array('/usr/bin/dpkg-deb', '/usr/bin/fakeroot') as $needed)
{
	if(!is_executable($needed))
	{
		build_fail(basename($needed) . ' must be present on the system to generate the Benchy Debian package (apt install dpkg-dev fakeroot).');
	}
}

$output_dir = getcwd();
$args = array_slice($argv, 1);
if(count($args) == 2 && $args[0] == '--output')
{
	$output_dir = $args[1];
}
else if(!empty($args))
{
	build_fail('Usage: php deploy/deb-package/build-package-deb.php [--output DIR]');
}
if(!is_dir($output_dir) || !is_writable($output_dir))
{
	build_fail($output_dir . ' is not a writable directory.');
}
$output_dir = realpath($output_dir);

@require("pts-core/pts-core.php");

if(!defined("PTS_VERSION"))
{
	build_fail("The Benchy version wasn't found!");
}

// An unpredictable private work directory; the package root sits inside it (dpkg-deb wants the root itself to be 0755)
$work_dir = tempnam(sys_get_temp_dir(), 'benchy-deb-');
if($work_dir === false || !unlink($work_dir) || !mkdir($work_dir, 0700))
{
	build_fail('Could not create a temporary work directory.');
}
register_shutdown_function(function() use ($work_dir) { if(is_dir($work_dir)) { exec('rm -rf ' . escapeshellarg($work_dir)); } });
$build_root = $work_dir . '/root';
if(!mkdir($build_root . '/DEBIAN', 0755, true) || !mkdir($build_root . '/usr', 0755, true))
{
	build_fail('Could not create the build directory ' . $build_root);
}

// DESTDIR keeps install-sh working purely inside the build root: it installs for the real /usr prefix (so the launcher points at
// /usr/share/benchy) and, with DESTDIR set, never touches the build user's own MIME database.
build_run('DESTDIR=' . escapeshellarg($build_root) . ' ./install-sh /usr');

// A trailing alpha/beta/milestone suffix (0.10a1, 0.10b2, 0.10m1) has to sort before the release, which dpkg does with a tilde
$pts_version = preg_replace('/([0-9])([abm][0-9]*)$/', '$1~$2', PTS_VERSION);

// What is declared here, and why, is written up in deploy/deployments.md.
$depends = array(
	'php-cli',          // the interpreter (the distribution's current php8.x)
	'php-xml',          // dom + simplexml: test and result XML
	'php-zip',          // ZipArchive: result and log archives
	'php-curl',         // downloading test files
	'php-mbstring',     // the Phoromatic JSON handling
	'build-essential',  // gcc, g++ and make: most tests compile on install
	'pkgconf | pkg-config', // not in build-essential; autotools tests such as pts/sysbench stop without it
	'tar', 'gzip', 'bzip2', 'xz-utils', 'unzip', // unpacking the test downloads
	'ca-certificates',  // https downloads
	'procps'            // ps, used by the system probing
);
$recommends = array(
	'php-sqlite3',      // the Phoromatic server's database
	'php-bz2',          // bzcompress: Ubuntu's php-cli lacks it, and every command prints "MISSING Bzip2" without it
	'php-gd',           // PNG/PDF graph export (SVG graphs need nothing); pulls the X11 client libraries, no display server
	'pciutils',         // lspci, for the hardware list
	'iproute2'          // ip, for the network probing
);

$control_file = "Package: benchy\n";
$control_file .= "Version: " . $pts_version . "\n";
$control_file .= "Section: utils\n";
$control_file .= "Installed-Size: " . trim(build_run('cd ' . escapeshellarg($build_root) . ' && du -s --exclude=DEBIAN . | cut -f 1')) . "\n";
$control_file .= "Priority: optional\n";
$control_file .= "Architecture: all\n";
$control_file .= "Depends: " . implode(', ', $depends) . "\n";
$control_file .= "Recommends: " . implode(', ', $recommends) . "\n";
$control_file .= "Maintainer: Dave Kotlyar <245733243+dakotlyar@users.noreply.github.com>\n";
$control_file .= "Description: An Automated, Open-Source Testing Framework\n " . @str_replace("\n", " ", trim(file_get_contents('pts-core/static/short-description.txt'))) . "\n";
$control_file .= "Homepage: https://github.com/dakotlyar/benchy\n";
if(file_put_contents($build_root . '/DEBIAN/control', $control_file) === false)
{
	build_fail('Could not write the control file.');
}

$deb_file = $output_dir . '/benchy_' . $pts_version . '_all.deb';
@unlink($deb_file);
build_run('fakeroot dpkg-deb --build ' . escapeshellarg($build_root) . ' ' . escapeshellarg($deb_file));
if(!is_file($deb_file) || filesize($deb_file) == 0)
{
	build_fail('dpkg-deb reported success but ' . $deb_file . ' is missing.');
}
echo PHP_EOL . 'Built ' . $deb_file . PHP_EOL;

// ---------- benchy-server: the service user, its folders, the systemd drop-in and the setup script (deploy/deb-package/benchy-server/)
$server_src = 'deploy/deb-package/benchy-server/';
foreach(array('postinst', 'prerm', 'postrm', '10-benchy-server.conf', 'benchy-server-setup') as $server_file)
{
	if(!is_file($server_src . $server_file))
	{
		build_fail('Missing ' . $server_src . $server_file . ' (the benchy-server package source).');
	}
}
$server_root = $work_dir . '/server-root';
if(!mkdir($server_root . '/DEBIAN', 0755, true) || !mkdir($server_root . '/usr/sbin', 0755, true) || !mkdir($server_root . '/usr/lib/systemd/system/phoromatic-server.service.d', 0755, true))
{
	build_fail('Could not create the build directory ' . $server_root);
}
foreach(array('postinst', 'prerm', 'postrm') as $script)
{
	build_run('install -m 0755 ' . escapeshellarg($server_src . $script) . ' ' . escapeshellarg($server_root . '/DEBIAN/' . $script));
}
build_run('install -m 0755 ' . escapeshellarg($server_src . 'benchy-server-setup') . ' ' . escapeshellarg($server_root . '/usr/sbin/benchy-server-setup'));
build_run('install -m 0644 ' . escapeshellarg($server_src . '10-benchy-server.conf') . ' ' . escapeshellarg($server_root . '/usr/lib/systemd/system/phoromatic-server.service.d/10-benchy-server.conf'));

// The server needs php-sqlite3 (benchy only recommends it); the same version of benchy keeps the code and the setup script in step
$server_depends = array('benchy (= ' . $pts_version . ')', 'php-sqlite3', 'adduser', 'systemd', 'init-system-helpers', 'iproute2', 'curl');
$server_control = "Package: benchy-server\n";
$server_control .= "Version: " . $pts_version . "\n";
$server_control .= "Section: utils\n";
$server_control .= "Installed-Size: " . trim(build_run('cd ' . escapeshellarg($server_root) . ' && du -s --exclude=DEBIAN . | cut -f 1')) . "\n";
$server_control .= "Priority: optional\n";
$server_control .= "Architecture: all\n";
$server_control .= "Depends: " . implode(', ', $server_depends) . "\n";
$server_control .= "Maintainer: Dave Kotlyar <245733243+dakotlyar@users.noreply.github.com>\n";
$server_control .= "Description: Sets up a Benchy Phoromatic server\n Adds the phoromatic service user, its folders and the systemd settings for the Phoromatic server, and the benchy-server-setup command that finishes the job (HTTPS, the first account, enabling the service). Nothing is enabled or started by installing the package.\n";
$server_control .= "Homepage: https://github.com/dakotlyar/benchy\n";
if(file_put_contents($server_root . '/DEBIAN/control', $server_control) === false)
{
	build_fail('Could not write the benchy-server control file.');
}
$server_deb_file = $output_dir . '/benchy-server_' . $pts_version . '_all.deb';
@unlink($server_deb_file);
build_run('fakeroot dpkg-deb --build ' . escapeshellarg($server_root) . ' ' . escapeshellarg($server_deb_file));
if(!is_file($server_deb_file) || filesize($server_deb_file) == 0)
{
	build_fail('dpkg-deb reported success but ' . $server_deb_file . ' is missing.');
}
echo 'Built ' . $server_deb_file . PHP_EOL . PHP_EOL;

?>
