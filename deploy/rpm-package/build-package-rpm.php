<?php

/*
	Phoronix Test Suite
	URLs: http://www.phoronix.com, http://www.phoronix-test-suite.com/
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

if(!is_file("benchy") || !is_dir("pts-core/"))
{
       echo "\nYou must run this script from the root directory of the benchy/ folder!\n";
       echo "Example: php5 deploy/rpm-package/build-package-rpm.php\n";
       exit(0);
}
if(!is_executable('/usr/bin/rpmbuild'))
{
	echo PHP_EOL . "rpmbuild must be present on the system. Try: yum install rpm-build." . PHP_EOL . PHP_EOL;
	exit;
}

@require("pts-core/pts-core.php");

if(!defined("PTS_VERSION"))
{
       echo "\nERROR: The Benchy version wasn't found!\n";
       exit(0);
}

$rpm_v = PTS_VERSION . '.' . date('YmdHi');

shell_exec("rm -rf /tmp/pts-rpm-builder/");
shell_exec("mkdir -p /tmp/pts-rpm-builder/{BUILD,RPMS,S{OURCE,PEC,RPM}S,benchy-" . $rpm_v . "}");
shell_exec("cp -R ./ /tmp/pts-rpm-builder/benchy-" . $rpm_v . "/");
shell_exec("tar --exclude=.git -C /tmp/pts-rpm-builder/ -cjvf /tmp/pts-rpm-builder/SOURCES/benchy-" . $rpm_v . ".tar.bz2 benchy-" . $rpm_v . "/");

$spec_file = "Summary: A Comprehensive Linux Benchmarking System\n";
$spec_file .= "Name: benchy\n";
$spec_file .= "Version: " . $rpm_v . "\n";
$spec_file .= "Release: 1\n";
$spec_file .= "License: GPL\n";
$spec_file .= "Group: Utilities\n";
$spec_file .= "URL: http://www.phoronix-test-suite.com/\n";
$spec_file .= "Source: benchy-" . $rpm_v . ".tar.bz2\n";
$spec_file .= "Packager: Dave Kotlyar <davekotlyar@gmail.com>\n";
$spec_file .= "Requires: php-cli, php-xml\n";
$spec_file .= "BuildArch: noarch\n";
$spec_file .= "BuildRoot: %{_tmppath}/%{name}-%{version}-root\n";
$spec_file .= "%description\n";
$spec_file .= @file_get_contents("pts-core/static/short-description.txt") . "\n";
$spec_file .= "%prep\n";
$spec_file .= "%setup -q\n";
$spec_file .= "%build\n";
$spec_file .= "%install\n";
$spec_file .= "rm -rf %{buildroot}\n";
$spec_file .= "./install-sh %{buildroot}/usr\n";
$spec_file .= "sed -i 's|%buildroot||g' %buildroot%_bindir/benchy\n";
$spec_file .= "%clean\n";
$spec_file .= "rm -rf %{buildroot}\n";
$spec_file .= "%files\n";
$spec_file .= "%{_bindir}/benchy\n";
$spec_file .= "%{_datadir}/benchy/*\n";
$spec_file .= "%{_datadir}/applications/*\n";
$spec_file .= "%{_datadir}/icons/hicolor/*\n";
$spec_file .= "%{_datadir}/metainfo/com.benchy.benchy.metainfo.xml\n";
$spec_file .= "%{_datadir}/doc/*\n";
$spec_file .= "%{_mandir}/man1/%{name}.1*\n";
$spec_file .= "%config(noreplace) %{_sysconfdir}/bash_completion.d\n";
$spec_file .= "%config(noreplace) %{_sysconfdir}/../usr/lib/systemd/system/*\n";
//$spec_file .= "%config(noreplace) %{_sysconfdir}/init/*\n";
$spec_file .= "%changelog\n";
$spec_file .= "* " . date('D M d Y') . " Dave Kotlyar <davekotlyar@gmail.com>\n";
$spec_file .= "- Initial release.";

file_put_contents("/tmp/pts-rpm-builder/SPECS/pts.spec", $spec_file);
shell_exec("mv -f " . getenv('HOME') . "/.rpmmacros /tmp/pts-rpm-builder 2>&1");
file_put_contents(getenv('HOME') ."/.rpmmacros", "%_topdir /tmp/pts-rpm-builder");
shell_exec("rpmbuild -ba --verbose /tmp/pts-rpm-builder/SPECS/pts.spec");
shell_exec("cp /tmp/pts-rpm-builder/RPMS/noarch/benchy-" . $rpm_v . "-1.noarch.rpm ./");
shell_exec("rm -f " . getenv('HOME') . "/.rpmmacros");
shell_exec("mv -f /tmp/pts-rpm-builder/.rpmmacros " . getenv('HOME') . ' 2>&1');
shell_exec("rm -rf /tmp/pts-rpm-builder");

?>
