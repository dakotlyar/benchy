<?php

/*
	Benchy PHP smoke test
	URLs: http://www.phoronix.com, http://www.phoronix-test-suite.com/

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

// Run by install.ps1 and the updater as: php.exe -n -c <php.ini> php-smoke.php
// Contract: exit 0 and print one JSON line {"php_version": "...", "extensions": [...]}, extension names in lower case.
// The caller checks the version and the required extensions; this script loads nothing from pts-core, so it works
// against a staged PHP before any Benchy code is in place.

echo json_encode(array(
	'php_version' => PHP_VERSION,
	'extensions' => array_values(array_map('strtolower', get_loaded_extensions())),
	)) . PHP_EOL;
exit(0);
