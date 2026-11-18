<?php

/*
	Phoronix Test Suite
	URLs: http://www.phoronix.com, http://www.phoronix-test-suite.com/
	Copyright (C) 2015 - 2016, Phoronix Media
	Copyright (C) 2015 - 2016, Michael Larabel

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

class phoromatic_local_suites implements pts_webui_interface
{
	public static function page_title()
	{
		return 'Local Test Suite';
	}
	public static function page_header()
	{
		return null;
	}
	public static function preload($PAGE)
	{
		return true;
	}
	public static function render_page_process($PATH)
	{
		$suite_dir = phoromatic_server::phoromatic_account_suite_path($_SESSION['AccountID']);
		$main = '<h1>Local Suites</h1><p>These are test suites created by you or another account within your group. Suites are an easy collection of test profiles. New suites can be trivially made via the <a href="/?build_suite">build suite</a> page.</p>';

		if(!PHOROMATIC_USER_IS_VIEWER && isset($PATH[0]) && $PATH[0] == 'delete' && phoromatic_post_allowed())
		{
			foreach(explode(',', isset($PATH[1]) ? $PATH[1] : '') as $raw_id)
			{
				// each name becomes a folder name: decoded, then checked
				if(($id = phoromatic_tenant_path::uri_segment($raw_id, 255)) !== false && is_file($suite_dir . $id . '/suite-definition.xml'))
				{
					$main .= '<p><strong>Deleting:</strong> ' . htmlspecialchars($id) . '</p>';
					unlink($suite_dir . $id . '/suite-definition.xml');
					pts_file_io::delete($suite_dir . $id, null, true);
				}
			}
		}

		$suite_count = 0;
		if(!PHOROMATIC_USER_IS_VIEWER && isset($PATH[0]) && $PATH[0] == 'delete-native' && isset($PATH[1]) && ctype_digit($PATH[1]) && phoromatic_post_allowed())
		{
			$e = phoromatic_native_suites::delete($_SESSION['AccountID'], (int)$PATH[1], phoromatic_native_admin::actor_of());
			$main .= $e === null ? '<p><strong>Deleted native suite ' . (int)$PATH[1] . '.</strong></p>' : '<p style="color: red;"><strong>' . htmlspecialchars($e) . '</strong></p>';
		}
		// The native suites (catalog tests, built-in and repository tests), kept in the database rather than as files; they run on systems with the native engine
		if(phoromatic_native_suites::ready())
		{
			$offer = phoromatic_native_suites::offer($_SESSION['AccountID']);
			foreach(phoromatic_native_suites::all($_SESSION['AccountID']) as $ns)
			{
				$suite_count++;
				$members = phoromatic_native_suites::members($_SESSION['AccountID'], (int)$ns['SuiteID']);
				$used = phoromatic_native_suites::used_by($_SESSION['AccountID'], (int)$ns['SuiteID']);
				$main .= '<a name="native-' . (int)$ns['SuiteID'] . '"></a><h1>' . pts_strings::sanitize($ns['Title']) . ' [native suite ' . (int)$ns['SuiteID'] . ']</h1>';
				$main .= '<p><strong>Engine:</strong> Native &middot; revision ' . (int)$ns['Revision'] . ' &middot; ' . count($members) . ' test' . (count($members) == 1 ? '' : 's') . ' &middot; ' .
					htmlspecialchars(phoromatic_native_suites::platform_text(phoromatic_native_suites::suite_platforms($offer, array_map(function($x) { return array('ref' => $x['Ref'], 'version' => $x['Version']); }, $members)))) . '</p>';
				if(trim((string)$ns['Description']) !== '')
				{
					$main .= '<p><em>' . pts_strings::sanitize($ns['Description']) . '</em></p>';
				}
				$main .= '<p>' . (empty($used) ? 'Not used by any schedule.' : 'Used by ' . htmlspecialchars(implode(', ', array_values($used))) . '.') . '</p>';
				if(!PHOROMATIC_USER_IS_VIEWER)
				{
					$main .= '<p><a href="?build_suite/native/' . (int)$ns['SuiteID'] . '">Edit Suite</a> - ' . phoromatic_post_button('?local_suites/delete-native/' . (int)$ns['SuiteID'], 'Delete Suite') . '</p>';
				}
				$main .= '<div style="max-height: 400px; width: 80%; overflow-y: scroll;">';
				foreach($members as $m)
				{
					$labels = phoromatic_catalog::ready() ? phoromatic_catalog_sched::labels($_SESSION['AccountID'], $m['Ref'], $m['Version']) : array('known' => false);
					$opts = phoromatic_native_suites::options_text($offer, $m['Ref'], $m['Version'], $m['OptionsJSON']);
					$main .= '<h3>' . htmlspecialchars(phoromatic_native_suites::title_of($offer, $m['Ref'])) . ' [' . htmlspecialchars($m['Ref']) . ']</h3><p>' . ($m['Version'] !== null ? 'Pinned to ' . htmlspecialchars($m['Version']) : 'Not pinned') .
						($labels['known'] ? ' - ' . htmlspecialchars($labels['tier']) : '') . ($opts !== '' ? ' - ' . htmlspecialchars($opts) : '') . '</p>';
				}
				$main .= '</div><hr />';
			}
		}
		foreach(pts_file_io::glob($suite_dir . '*/suite-definition.xml') as $xml_path)
		{
			$suite_count++;
			$id = basename(dirname($xml_path));
			$test_suite = new pts_test_suite($xml_path);

			$main .= '<a name="' . $id . '"></a><h1>' . pts_strings::sanitize($test_suite->get_title()) . ' [' . $id . ']</h1>';
			$main .= '<p><strong>Engine:</strong> Phoronix</p><p><strong>' . pts_strings::sanitize($test_suite->get_maintainer()) . '</strong></p>';
			$main .= '<p><em>' . pts_strings::sanitize($test_suite->get_description()) . '</em></p>';
			if(!PHOROMATIC_USER_IS_VIEWER)
			{
				$main .= '<p><a href="?build_suite/' . $id . '">Edit Suite</a> - ' . phoromatic_post_button('?local_suites/delete/' . $id, 'Delete Suite') . '</p>';
			}
			$main .= '<div style="max-height: 400px; width: 80%; overflow-y: scroll;">';
			$test_suite->sort_contained_tests();
			foreach($test_suite->get_contained_test_result_objects() as $tro)
			{
				$main .= '<h3>' . $tro->test_profile->get_title() . ' [' . $tro->test_profile->get_identifier() . ']</h3>';
				$main .= '<p>' . $tro->get_arguments_description() . '</p>';
			}
			$main .= '</div>';
			$main .= '<hr />';
		}

		if($suite_count == 0)
			$main .= '<h1>No Test Suites Found</h1>';

		echo phoromatic_webui_header_logged_in();
		echo '<div id="pts_phoromatic_main_area">' . $main . '</div>';
		echo phoromatic_webui_footer();
	}
}

?>
