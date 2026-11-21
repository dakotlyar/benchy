<?php

/*
	Benchy
	phoromatic_catalog_status.php: the words of the catalog commands (E4.4 C2, server design 8.2)

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

class phoromatic_catalog_status
{
	const NAMES = array('public' => 'free', 'private' => 'non-free');
	const WHY = array('none' => 'not imported', 'denied_key' => 'NOT SERVED: the signing key is no longer trusted', 'retired_key' => 'NOT SERVED: the signing key is retired',
		'file_missing' => 'NOT SERVED: the stored file is missing or damaged (import the same bundle again to repair it)', 'key_table' => 'NOT SERVED: the server\'s key table is damaged');

	// "free" and "non-free" for the two the catalog tools have always had; another catalog is called by its signed title, or its name
	public static function name($tier, $rows = null)
	{
		return isset(self::NAMES[$tier]) ? self::NAMES[$tier] : phoromatic_catalog::label($tier, $rows);
	}
	public static function import_text($out)
	{
		$name = self::name($out['tier']);
		$pair = 'generation ' . $out['generation'] . ', sequence ' . $out['sequence'];
		if($out['unchanged'])
		{
			$fixed = $out['restored']['index'] + $out['restored']['packs'];
			return 'The ' . $name . ' catalog is already imported (' . $pair . '); nothing changed.' . ($fixed > 0 ? ' ' . ($out['restored']['index'] ? 'The index file' . ($out['restored']['packs'] ? ' and ' : '') : '') . ($out['restored']['packs'] ? $out['restored']['packs'] . ' test pack' . ($out['restored']['packs'] == 1 ? '' : 's') : '') . ' that had gone missing ' . ($fixed == 1 ? 'was' : 'were') . ' put back.' : '');
		}
		return 'Imported the ' . $name . ' catalog: ' . $pair . ($out['from'] === null ? ' (the first)' : ' (was generation ' . $out['from'][0] . ', sequence ' . $out['from'][1] . ')') . '. ' .
			$out['tests_added'] . ' test' . ($out['tests_added'] == 1 ? '' : 's') . ' added, ' . $out['revoked_added'] . ' withdrawn.' . ($out['recovery'] ? ' This was a recovery release: the earlier ledger and withdrawals were replaced.' : '') .
			' Agents pick it up when they next check in.' . (isset(self::NAMES[$out['tier']]) ? '' : ' An account sees this catalog only after you turn it on for that account on the Catalogs page.');
	}
	public static function text()
	{
		if(!phoromatic_catalog::ready())
		{
			return 'The catalog is not set up on this server yet: its database is at schema ' . phoromatic_server::read_database_version() . ' and the catalog needs ' . phoromatic_catalog::SCHEMA_STEP . '. Start the server once on this version.';
		}
		$lines = array();
		$state = phoromatic_catalog::state_row();
		$rows = phoromatic_catalog::tier_rows();
		try
		{
			$trust = phoromatic_catalog_keys::load();
			$keys = null;
		}
		catch(phoromatic_catalog_refusal $e)
		{
			$trust = null;
			$keys = $e->getMessage();
		}
		if($keys !== null)
		{
			$lines[] = $keys . ' Imports are refused; nothing is served.';
		}
		if(empty($rows))
		{
			$lines[] = 'No catalog has been imported.';
		}
		foreach($rows as $tier => $r)
		{
			$s = phoromatic_catalog::tier_status($tier, $trust, $rows, (int)$state['RetiredBelow']);
			$lines[] = ucfirst(self::name($tier, $rows)) . ' catalog (' . $tier . '): ' . ($s['served'] ? 'served' : self::WHY[$s['why']]);
			$lines[] = '  release: generation ' . $r['Generation'] . ', sequence ' . $r['Sequence'] . '; signed by key ' . $r['KeyID'] . '; issued ' . $r['Issued'];
			$lines[] = '  holds: ' . $r['TestCount'] . ' tests, ' . $r['SuiteCount'] . ' suites, ' . $r['RevokedCount'] . ' withdrawn';
			$lines[] = '  imported ' . $r['ImportedOn'] . ' by ' . $r['ImportedBy'] . ' (' . $r['ImportedVia'] . ')';
		}
		$lines[] = 'Retired key generations: ' . ((int)$state['RetiredBelow'] > 1 ? 'below ' . (int)$state['RetiredBelow'] : 'none');
		if(!empty($state['PackagedOn']))
		{
			$lines[] = 'The bundle that came with this version: ' . $state['PackagedOutcome'] . ' (' . $state['PackagedOn'] . ')';
		}
		if(($n = phoromatic_catalog::missing_packs()) > 0)
		{
			$lines[] = $n . ' test pack' . ($n == 1 ? ' is' : 's are') . ' missing from storage. Import the same bundle again to put ' . ($n == 1 ? 'it' : 'them') . ' back.';
		}
		return implode(PHP_EOL, $lines);
	}
}

?>
