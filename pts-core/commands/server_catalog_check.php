<?php

/*
	Benchy
	server-catalog-check: checks a signed test catalog bundle without importing it (E4.4 C2)

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

class server_catalog_check implements pts_option_interface
{
	const doc_section = 'Phoromatic';
	const doc_description = 'Runs every import check on a signed test catalog bundle against an empty server (the key table\'s floors apply) and writes nothing; it needs no server storage. Prints one line, "TIER GENERATION SEQUENCE TESTS SUITES REVOKED", and exits 1 with the reason when the bundle is refused. The release gate uses it. Usage: server-catalog-check BUNDLE.zip';

	public static function run($r)
	{
		if(!isset($r[0]) || isset($r[1]))
		{
			echo PHP_EOL . 'Usage: server-catalog-check BUNDLE.zip' . PHP_EOL . PHP_EOL;
			return false;
		}
		try
		{
			$c = phoromatic_catalog_import::check_only($r[0]);
		}
		catch(phoromatic_catalog_refusal $e)
		{
			fwrite(STDERR, 'Refused (' . $e->reason . '): ' . $e->getMessage() . PHP_EOL);
			exit(1);
		}
		echo $c['tier'] . ' ' . $c['generation'] . ' ' . $c['sequence'] . ' ' . $c['tests'] . ' ' . $c['suites'] . ' ' . $c['revoked'] . PHP_EOL;
		exit(0);
	}
}

?>
