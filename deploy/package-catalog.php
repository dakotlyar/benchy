<?php

/*
	Benchy
	Shared by the deb and rpm builders: the signed public test catalog bundle a server package ships (E4.4, server design 3.6 and 10)

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

// The package puts the file at <prefix>/share/benchy/catalog/<file>, where the event server looks for it at start (PTS_PATH . 'catalog/').
// Returns array('file' => name, 'path' => path of the checked file), or null when the tree pins no catalog bundle and none was given.
// The file must be the one pinned in pts-core/static/update/runtime-pins.json (catalog.public: file, size, sha256), which
// deploy/release.sh catalog-bundle has already checked against the server's own signature check. Every mismatch stops the build.
function package_catalog_bundle($given)
{
	$pins = @json_decode(@file_get_contents('pts-core/static/update/runtime-pins.json'), true);
	$pin = is_array($pins) && isset($pins['catalog']['public']) ? $pins['catalog']['public'] : null;
	if($pin === null)
	{
		if($given !== null)
		{
			build_fail('--catalog-bundle was given, but runtime-pins.json pins no catalog bundle (catalog.public).');
		}
		return null;
	}
	if($given === null)
	{
		build_fail('runtime-pins.json pins a catalog bundle (' . (isset($pin['file']) ? $pin['file'] : '?') . '). Get it with deploy/release.sh catalog-bundle <version> and pass it with --catalog-bundle <path>.');
	}
	if(!isset($pin['file'], $pin['size'], $pin['sha256']) || !is_string($pin['file']) || !preg_match('/^benchy-catalog-public-[1-9][0-9]*-[1-9][0-9]*\.zip$/', $pin['file']) || !preg_match('/^[0-9a-f]{64}$/', (string)$pin['sha256']))
	{
		build_fail('runtime-pins.json: catalog.public needs a file named benchy-catalog-public-<generation>-<sequence>.zip, a size and a sha256.');
	}
	// The package must ship exactly the tree the release gate checked: HEAD is the release tag and nothing is changed or untracked
	$head = array(); $tagged = array(); $dirty = array(); $rc = array(0, 0, 0);
	exec('git rev-parse HEAD 2>&1', $head, $rc[0]);
	exec('git rev-parse --verify -q ' . escapeshellarg('refs/tags/v' . PTS_VERSION . '^{commit}') . ' 2>&1', $tagged, $rc[1]);
	exec('git status --porcelain 2>&1', $dirty, $rc[2]);
	if($rc[0] != 0 || $rc[1] != 0 || $rc[2] != 0 || $head !== $tagged)
	{
		build_fail('This tree pins a catalog bundle, so it must be built from a git checkout of exactly the tag v' . PTS_VERSION . ' (HEAD is not that tag, or this is not a git checkout).');
	}
	if(!empty($dirty))
	{
		build_fail('This tree pins a catalog bundle, so it must be clean: git status shows changes (' . trim($dirty[0]) . '). Package exactly what release.sh check passed.');
	}
	if(!is_file($given) || !is_readable($given))
	{
		build_fail('--catalog-bundle ' . $given . ': no such file.');
	}
	if(filesize($given) !== $pin['size'] || hash_file('sha256', $given) !== $pin['sha256'])
	{
		build_fail('--catalog-bundle ' . $given . ' is not the pinned ' . $pin['file'] . ' (size or sha256 differs from runtime-pins.json).');
	}
	return array('file' => $pin['file'], 'path' => realpath($given));
}

// Parses [--output DIR] [--catalog-bundle FILE]; returns array(output dir or null, bundle path or null)
function package_args($argv, $usage)
{
	$out = null;
	$bundle = null;
	$args = array_slice($argv, 1);
	for($i = 0; $i < count($args); $i += 2)
	{
		if(!isset($args[$i + 1]))
		{
			build_fail($usage);
		}
		if($args[$i] === '--output' && $out === null)
		{
			$out = $args[$i + 1];
		}
		else if($args[$i] === '--catalog-bundle' && $bundle === null)
		{
			$bundle = $args[$i + 1];
		}
		else
		{
			build_fail($usage);
		}
	}
	return array($out, $bundle);
}

?>
