<?php

/*
	Benchy
	server-engine-keys: the key table of the signed engine bundle, in the engine's `engine keys` format (engine self-update, U1)

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

class server_engine_keys implements pts_option_interface
{
	const doc_section = 'Phoromatic';
	const doc_description = 'Prints the key table of the server\'s signed engine bundle in the format of `benchy-engine engine keys`: one "generation N KEYID" line per key, "denied KEYID" lines, and a TEST KEYS line while the development keys are in. The release gate compares this text with the production list. It needs no server storage. Usage: server-engine-keys';

	public static function run($r)
	{
		if(isset($r[0]))
		{
			echo PHP_EOL . 'Usage: server-engine-keys' . PHP_EOL . PHP_EOL;
			return false;
		}
		try
		{
			$text = phoromatic_engine_store::keys_text();
		}
		catch(phoromatic_catalog_refusal $e)
		{
			fwrite(STDERR, $e->getMessage() . PHP_EOL);
			exit(1);
		}
		echo $text;
		exit(0); // the release gate compares this text byte for byte, so the framework's closing blank line is left out
	}
}

?>
