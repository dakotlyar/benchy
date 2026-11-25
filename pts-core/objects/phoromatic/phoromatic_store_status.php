<?php

/*
	Benchy
	phoromatic_store_status.php: what each store holds, what it offers, and why not, in words (0.16.3)

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

// One table of words for the "why" codes that the stores' current() functions give, used by the import commands, the web pages and the status panel.

class phoromatic_store_status
{
	const WHY = array(
		'none' => 'No {what} has been imported yet.',
		'file_missing' => 'The stored {what} is missing a file or does not match its record, so nothing is offered. Import the bundle again.',
		'state_corrupt' => 'The stored {what} record is damaged, so nothing is offered. Import the bundle again.',
		'denied_key' => 'The stored {what}\'s key is no longer trusted, so nothing is offered.',
		'retired_key' => 'The stored {what}\'s key is retired, so nothing is offered.',
		'key_table' => 'The key table is damaged, so nothing is offered.');

	public static function why_text($why, $what)
	{
		return isset(self::WHY[$why]) ? str_replace('{what}', $what, self::WHY[$why]) : 'The stored ' . $what . ' does not verify (' . $why . '), so nothing is offered.';
	}
	protected static function h($t)
	{
		return htmlspecialchars((string)$t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}
	// One line for a store: what is held, whether it is offered to machines, and if not, why. $class is the store class (engine or component).
	public static function line($label, $class, $what)
	{
		try
		{
			$state = $class::state();
		}
		catch(phoromatic_catalog_refusal $e)
		{
			$state = false;
		}
		$c = $class::current($why);
		$held = $state === null ? 'nothing held' : ($state === false ? 'the stored record is damaged' : 'held: ' . (isset($state['version']) ? $state['version'] . ', ' : '') . 'sequence ' . (int)$state['sequence']);
		if($c !== null)
		{
			return '<li><strong>' . self::h($label) . '</strong>: ' . self::h($held) . '; offered to machines.</li>';
		}
		return '<li><strong>' . self::h($label) . '</strong>: ' . self::h($held) . '; ' . ($why === 'none' ? 'not offered (nothing imported).' : '<span style="color: #b00020;">NOT offered to machines. ' . self::h(self::why_text($why, $what)) . '</span>') . '</li>';
	}
	// The block on the Test catalog page: the engine and component lines and the folder this server reads its bundles from
	public static function lines_html()
	{
		$info = phoromatic_server::phoromatic_path_info();
		return '<hr /><h2 id="bundle-status">Engine and component bundles</h2><ul>' . self::line('Engine', 'phoromatic_engine_store', 'engine') . self::line('Components', 'phoromatic_component_store', 'component bundle') . '</ul>
<p>This server reads its stored bundles from <code>' . self::h(phoromatic_engine_store::dir()) . '</code> and <code>' . self::h(phoromatic_component_store::dir()) . '</code> (storage ' . self::h($info[0]) . ', ' . self::h(phoromatic_server::storage_source_text($info[1])) . '). A bundle imported into any other folder is not offered.</p>';
	}
	// The refusal an import throws when what it just stored isn't what the server would offer. $dir is where it stored.
	public static function not_served($what, $dir, $why)
	{
		return new phoromatic_catalog_refusal(phoromatic_catalog_refusal::NOT_SERVED, 'Stored in ' . $dir . ', but it is not offered: ' . self::why_text($why, $what));
	}
}

?>
