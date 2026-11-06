<?php

/*
	Phoronix Test Suite
	URLs: https://github.com/dakotlyar/benchy
	Copyright (C) 2008 - 2017, Phoronix Media
	Copyright (C) 2008, Andrew Schofield

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

// Usage: php deploy/rpm-package/build-package-rpm.php [--output DIR]
// Builds benchy-<version>-1.noarch.rpm into DIR (default: the current directory). Every step is checked; the first failure stops the build.
// NOTE: the Requires list below is declared from the code and the Fedora/AlmaLinux 9 package names; the rpm has not yet been install-tested.

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

if(!is_file("benchy") || !is_dir("pts-core/"))
{
	build_fail("You must run this script from the root directory of the benchy/ folder!\nExample: php deploy/rpm-package/build-package-rpm.php");
}
if(!is_executable('/usr/bin/rpmbuild'))
{
	build_fail('rpmbuild must be present on the system. Try: dnf install rpm-build.');
}

$output_dir = getcwd();
$args = array_slice($argv, 1);
if(count($args) == 2 && $args[0] == '--output')
{
	$output_dir = $args[1];
}
else if(!empty($args))
{
	build_fail('Usage: php deploy/rpm-package/build-package-rpm.php [--output DIR]');
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

// A trailing alpha/beta/milestone suffix (0.10a1, 0.10b2, 0.10m1) has to sort before the release, which RPM does with a tilde.
$rpm_v = preg_replace('/([0-9])([abm][0-9]*)$/', '$1~$2', PTS_VERSION);
$rpm_release = '1';
$source_dir = getcwd();

// An unpredictable private work directory; it also serves as HOME for rpmbuild (see below)
$build_root = tempnam(sys_get_temp_dir(), 'benchy-rpm-');
if($build_root === false || !unlink($build_root) || !mkdir($build_root, 0700))
{
	build_fail('Could not create a temporary work directory.');
}
register_shutdown_function(function() use ($build_root) { if(is_dir($build_root)) { exec('rm -rf ' . escapeshellarg($build_root)); } });
foreach(array('BUILD', 'RPMS', 'SOURCES', 'SPECS', 'SRPMS', 'home', 'benchy-' . $rpm_v) as $sub_dir)
{
	if(!mkdir($build_root . '/' . $sub_dir, 0755, true))
	{
		build_fail('Could not create ' . $build_root . '/' . $sub_dir);
	}
}
$tree = $build_root . '/benchy-' . $rpm_v;
build_run('bash -o pipefail -c ' . escapeshellarg('tar -C "$1" --exclude=.git --exclude="*.rpm" --exclude="*.deb" -cf - . | tar -C "$2" -xf -') . ' copy ' . escapeshellarg($source_dir) . ' ' . escapeshellarg($tree));
build_run('tar -C ' . escapeshellarg($build_root) . ' -cjf ' . escapeshellarg($build_root . '/SOURCES/benchy-' . $rpm_v . '.tar.bz2') . ' benchy-' . $rpm_v);

// What is declared here, and why, is written up in deploy/deployments.md. Package names are the Fedora / Enterprise Linux ones.
$requires = array(
	'php-cli',                   // the interpreter; php-common comes with it (openssl, curl, zlib, bz2, sockets, readline)
	'php-xml',                   // dom + simplexml: test and result XML
	'(php-pecl-zip or php-zip)', // ZipArchive: result and log archives (Fedora/EL call it php-pecl-zip, Remi php-zip)
	'php-mbstring',              // the Phoromatic JSON handling
	'php-process',               // pcntl + posix
	'gcc', 'gcc-c++', 'make',    // most tests compile on install
	'pkgconfig',                 // autotools tests such as pts/sysbench stop without it
	'tar', 'gzip', 'bzip2', 'xz', 'unzip', // unpacking the test downloads
	'ca-certificates',           // https downloads
	'procps-ng',                 // ps, used by the system probing
	'which'                      // the launcher looks for php with it
);
$recommends = array(
	'php-pdo',                   // sqlite3 for the Phoromatic server
	'php-gd',                    // PNG/PDF graph export (SVG graphs need nothing)
	'pciutils',                  // lspci, for the hardware list
	'iproute'                    // ip, for the network probing
);

$spec_file = "Summary: A Comprehensive Linux Benchmarking System\n";
$spec_file .= "Name: benchy\n";
$spec_file .= "Version: " . $rpm_v . "\n";
$spec_file .= "Release: " . $rpm_release . "\n";
$spec_file .= "License: GPL-3.0-or-later\n";
$spec_file .= "URL: https://github.com/dakotlyar/benchy\n";
$spec_file .= "Source0: benchy-" . $rpm_v . ".tar.bz2\n";
$spec_file .= "Packager: Dave Kotlyar <245733243+dakotlyar@users.noreply.github.com>\n";
foreach($requires as $r)
{
	$spec_file .= "Requires: " . $r . "\n";
}
foreach($recommends as $r)
{
	$spec_file .= "Recommends: " . $r . "\n";
}
$spec_file .= "BuildArch: noarch\n";
$spec_file .= "%description\n";
$spec_file .= trim(@file_get_contents("pts-core/static/short-description.txt")) . "\n";
$spec_file .= "%prep\n";
$spec_file .= "%setup -q\n";
$spec_file .= "%build\n";
$spec_file .= "%install\n";
$spec_file .= "rm -rf %{buildroot}\n";
$spec_file .= "DESTDIR=%{buildroot} ./install-sh %{_prefix}\n";
$spec_file .= "%files\n";
$spec_file .= "%{_bindir}/benchy\n";
$spec_file .= "%{_datadir}/benchy\n";
$spec_file .= "%{_datadir}/applications/*\n";
$spec_file .= "%{_datadir}/icons/hicolor/*\n";
$spec_file .= "%{_datadir}/metainfo/com.benchy.benchy.metainfo.xml\n";
$spec_file .= "%{_datadir}/mime/packages/*\n";
$spec_file .= "%{_datadir}/doc/benchy\n";
$spec_file .= "%{_mandir}/man1/%{name}.1*\n";
$spec_file .= "%config(noreplace) %{_sysconfdir}/bash_completion.d/benchy\n";
// The units are installed but never enabled or started: there is no %post, so a headless install stays quiet until the admin runs systemctl enable.
$spec_file .= "%{_prefix}/lib/systemd/system/*.service\n";
$spec_file .= "%changelog\n";
$spec_file .= "* " . date('D M d Y') . " Dave Kotlyar <245733243+dakotlyar@users.noreply.github.com> - " . $rpm_v . "-" . $rpm_release . "\n";
$spec_file .= "- Packaged from the Benchy " . PTS_VERSION . " source.\n";

$spec_path = $build_root . '/SPECS/benchy.spec';
if(file_put_contents($spec_path, $spec_file) === false)
{
	build_fail('Could not write the spec file.');
}

// HOME points at an empty directory inside the work directory, so rpm never reads the user's ~/.rpmmacros (or ~/.rpmrc); _topdir puts the build there too
build_run('HOME=' . escapeshellarg($build_root . '/home') . ' rpmbuild -bb --define ' . escapeshellarg('_topdir ' . $build_root) . ' ' . escapeshellarg($spec_path));

$built = glob($build_root . '/RPMS/noarch/benchy-' . $rpm_v . '-' . $rpm_release . '*.noarch.rpm');
if(count($built) != 1)
{
	build_fail('rpmbuild reported success but ' . $build_root . '/RPMS/noarch has no benchy rpm.');
}
$rpm_file = $output_dir . '/' . basename($built[0]);
if(!copy($built[0], $rpm_file) || filesize($rpm_file) == 0)
{
	build_fail('Could not copy the rpm to ' . $rpm_file);
}
echo PHP_EOL . 'Built ' . $rpm_file . PHP_EOL . PHP_EOL;

?>
