<?php

/*
	Benchy
	phoromatic_release_fetch.php: Check GitHub gets the engine, components and public catalog bundles of the release the server runs (0.16.4)

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

// The URL is built from the fixed repository and a file name that matched an exact pattern; browser_download_url from GitHub's JSON is never used.
// Each store is independent: one refusing never stops the others. Lines starting with "! " are refusals (the page shows them red).

class phoromatic_release_fetch
{
	const PATTERNS = array(
		'engine' => '/^benchy-engine-bundle-([0-9]{1,6})\.zip\z/',
		'components' => '/^benchy-components-bundle-([0-9]{1,6})\.zip\z/',
		'catalog' => '/^benchy-catalog-public-([0-9]{1,6})-([0-9]{1,6})\.zip\z/');
	const LABELS = array('engine' => 'Engine', 'components' => 'Components', 'catalog' => 'Public catalog');

	// $running is the version this server runs (bundles of another release are never taken: they would not match this server)
	public static function bundles($running, $actor_type, $actor, $actor_detail = null, $account = null)
	{
		$repo = phoromatic_server_update::REPO;
		try
		{
			$json = json_decode(phoromatic_server_update::curl(phoromatic_server_update::$api_base . '/repos/' . $repo . '/releases/tags/v' . $running, 1048576), true, 16);
		}
		catch(phoromatic_catalog_refusal $r)
		{
			return array('! The ' . $running . ' release could not be read from GitHub: ' . $r->getMessage());
		}
		if(!is_array($json) || !isset($json['assets']) || !is_array($json['assets']))
		{
			return array('! The ' . $running . ' release could not be read: GitHub\'s answer has no list of files.');
		}
		$assets = $json['assets'];
		$lines = array();
		foreach(self::PATTERNS as $store => $pattern)
		{
			$label = self::LABELS[$store];
			$found = array();
			foreach($assets as $a)
			{
				if(is_array($a) && isset($a['name']) && is_string($a['name']) && preg_match($pattern, $a['name'], $m) === 1)
				{
					$found[$a['name']] = array_map('intval', array_slice($m, 1));
				}
			}
			if(count($found) === 0)
			{
				$lines[] = $label . ': not on the ' . $running . ' release. Upload it on the Test catalog page.';
				continue;
			}
			if(count($found) > 1)
			{
				$lines[] = '! ' . $label . ': the ' . $running . ' release has more than one file for it, so none was taken.';
				continue;
			}
			$name = key($found);
			try
			{
				if(!self::newer($store, current($found)))
				{
					$lines[] = $label . ': ' . $name . ' is already offered.';
					continue;
				}
				$lines[] = $label . ': ' . self::fetch($store, $running, $name, $actor_type, $actor, $actor_detail, $account);
			}
			catch(phoromatic_catalog_refusal $r)
			{
				if($r->reason === phoromatic_catalog_refusal::ROLLBACK)
				{
					$lines[] = $label . ': ' . $name . ' is already offered.';
				}
				else
				{
					$lines[] = '! ' . $label . ': ' . $name . ' was not imported (' . $r->reason . '): ' . $r->getMessage();
				}
			}
		}
		$lines[] = 'The gaming catalog is never on GitHub. Upload it on the Test catalog page.';
		return $lines;
	}
	// True when the number(s) in the file name are above what the store holds (or it holds nothing)
	protected static function newer($store, $nums)
	{
		if($store === 'catalog')
		{
			$rows = phoromatic_catalog::tier_rows();
			return !isset($rows['public']) || array((int)$rows['public']['Generation'], (int)$rows['public']['Sequence']) < $nums;
		}
		$s = $store === 'engine' ? phoromatic_engine_store::state() : phoromatic_component_store::state();
		return $s === null || $s['sequence'] < $nums[0];
	}
	protected static function fetch($store, $running, $name, $actor_type, $actor, $actor_detail, $account)
	{
		$tmp = tempnam(sys_get_temp_dir(), 'benchy-rf-');
		try
		{
			$max = $store === 'engine' ? phoromatic_engine_store::MAX_BUNDLE : ($store === 'components' ? phoromatic_component_store::MAX_BUNDLE : phoromatic_catalog_import::MAX_BUNDLE);
			phoromatic_server_update::curl(phoromatic_server_update::$download_base . '/' . phoromatic_server_update::REPO . '/releases/download/v' . $running . '/' . $name, $max, $tmp);
			if($store === 'engine')
			{
				$o = phoromatic_engine_store::import($tmp, 'github', $actor_type, $actor, $actor_detail, $account);
				return $o['status'] === 'unchanged' ? $name . ' is already offered.' : phoromatic_engine_store::import_text($o);
			}
			if($store === 'components')
			{
				$o = phoromatic_component_store::import($tmp, 'github', $actor_type, $actor, $actor_detail, $account);
				return $o['status'] === 'unchanged' ? $name . ' is already offered.' : phoromatic_component_store::import_text($o);
			}
			// (the catalog table only accepts cli, web and package as the way in, so the catalog is recorded as web)
			$o = phoromatic_catalog_import::run($tmp, 'web', $actor_type, $actor, $actor_detail . ', from GitHub', $account, true);
			return $o['unchanged'] ? $name . ' is already offered.' : phoromatic_catalog_status::import_text($o);
		}
		finally
		{
			@unlink($tmp);
		}
	}
}

?>
