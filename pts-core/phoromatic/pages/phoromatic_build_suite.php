<?php

/*
	Phoronix Test Suite
	URLs: http://www.phoronix.com, http://www.phoronix-test-suite.com/
	Copyright (C) 2015 - 2022, Phoronix Media
	Copyright (C) 2015 - 2022, Michael Larabel

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

// One page builds both kinds of suite (Benchy 0.10.0, catalog suites CS1): an engine switch at the top, locked once the draft holds a test. The Phoronix half keeps its fields and its
// handler (the form posts suite_title, suite_version, test_add[] ... and the same suite-definition.xml is written); the native half posts to its own handler below, which takes native refs only.

class phoromatic_build_suite implements pts_webui_interface
{
	public static function page_title()
	{
		return 'Build Custom Test Suite';
	}
	public static function page_header()
	{
		return null;
	}
	public static function preload($PAGE)
	{
		return true;
	}
	// JSON for a script element: no text from an index or a report can close it
	public static function json($data)
	{
		return json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
	}
	// The native engine's offer in the shape the page's script reads
	protected static function native_items($account_id, $offer)
	{
		$items = array();
		foreach($offer['catalog'] as $ref => $c)
		{
			$versions = array();
			foreach($c['versions'] as $v => $info)
			{
				$versions[$v] = array('plat' => $info['platforms'], 'opts' => self::options($info['options']), 'install' => $info['install'], 'elevated' => $info['elevated'], 'min' => $info['min_engine']);
			}
			$labels = phoromatic_catalog_sched::labels($account_id, $ref, $c['order'][0]);
			$newest = $c['versions'][$c['order'][0]];
			$items[] = array('ref' => $ref, 'title' => $c['title'], 'src' => $c['tier'] === 'private' ? 'nonfree' : 'free', 'label' => $labels['known'] ? $labels['tier'] : '', 'desc' => $c['description'], 'ver' => $c['order'][0], 'order' => $c['order'],
				'versions' => $versions, 'plat' => $newest['platforms'], 'install' => $newest['install'], 'elevated' => $newest['elevated'], 'block' => $c['block'], 'opts' => self::options($newest['options']),
				'cat' => $c['cat'], 'tags' => $c['tags'], 'len' => $c['len']);
		}
		foreach($offer['reported'] as $ref => $t)
		{
			$names = array_slice($t['systems'], 0, 3);
			$items[] = array('ref' => $ref, 'title' => $t['title'], 'src' => 'builtin', 'label' => '', 'desc' => $t['description'], 'ver' => null, 'order' => array(), 'versions' => new stdClass(), 'plat' => $t['platforms'], 'install' => null, 'elevated' => false,
				'block' => $t['block'], 'opts' => self::options($t['options']), 'cat' => $t['cat'], 'tags' => $t['tags'], 'len' => $t['len'],
				'reported' => implode(', ', $names) . (count($t['systems']) > 3 ? ' and ' . (count($t['systems']) - 3) . ' more' : ''));
		}
		return $items;
	}
	// An option list the page can draw: choice (a menu) and number or text (a field)
	protected static function options($list)
	{
		$out = array();
		foreach(is_array($list) ? $list : array() as $o)
		{
			if(!is_array($o) || !isset($o['key']) || !is_string($o['key']) || preg_match('/^[A-Za-z0-9_.-]{1,64}\z/', $o['key']) !== 1)
			{
				continue;
			}
			$choices = array();
			foreach(isset($o['choices']) && is_array($o['choices']) ? $o['choices'] : array() as $c)
			{
				if(is_array($c) && isset($c['key']))
				{
					$choices[] = array((string)$c['key'], phoromatic_catalog_sched::clean(isset($c['label']) ? $c['label'] : $c['key'], 120));
				}
			}
			$out[] = array('key' => $o['key'], 'label' => phoromatic_catalog_sched::clean(isset($o['label']) ? $o['label'] : $o['key'], 80), 'kind' => isset($o['kind']) && $o['kind'] === 'choice' ? 'choice' : 'text',
				'def' => isset($o['default']) && (is_string($o['default']) || is_int($o['default'])) ? (string)$o['default'] : '', 'choices' => $choices);
		}
		return $out;
	}
	// The Phoronix engine's offer: the server's local/ profiles ("Private repository") and OpenBenchmarking.org, with a title and description only where the index cache already has them
	protected static function pts_items()
	{
		$meta = array();
		foreach(pts_openbenchmarking::linked_repositories() as $repo)
		{
			$index = pts_openbenchmarking::read_repository_index($repo);
			foreach(isset($index['tests']) && is_array($index['tests']) ? $index['tests'] : array() as $id => $t)
			{
				$meta[$repo . '/' . $id] = array(isset($t['title']) && is_string($t['title']) ? $t['title'] : null, isset($t['description']) && is_string($t['description']) ? $t['description'] : '');
			}
		}
		$items = array();
		$dc = pts_client::download_cache_path();
		$dc_exists = is_file($dc . 'pts-download-cache.json');
		$cache_json = $dc_exists ? json_decode(file_get_contents($dc . 'pts-download-cache.json'), true) : null;
		$tests = array();
		foreach(pts_tests::local_tests() as $t)
		{
			$tests[$t] = 'private';
		}
		foreach(pts_openbenchmarking::available_tests(false, isset($_COOKIE['list_show_all_test_versions']) && $_COOKIE['list_show_all_test_versions']) as $t)
		{
			$tests[$t] = 'ob';
		}
		foreach($tests as $test => $src)
		{
			if(phoromatic_server::read_setting('show_local_tests_only'))
			{
				$cache_checked = false;
				if($dc_exists && $cache_json && isset($cache_json['phoronix-test-suite']['cached-tests']))
				{
					$cache_checked = true;
					if(!in_array($test, $cache_json['phoronix-test-suite']['cached-tests']))
					{
						continue;
					}
				}
				if(!$cache_checked && pts_test_install_request::test_files_available_on_local_system($test) == false)
				{
					continue;
				}
			}
			$base = preg_match('/^(.+)-[0-9][0-9.]*\z/', $test, $m) === 1 ? $m[1] : $test;
			$title = isset($meta[$base]) && $meta[$base][0] !== null ? phoromatic_catalog_sched::clean($meta[$base][0], 120) : $test;
			$items[] = array('ref' => (string)$test, 'title' => $title, 'src' => $src, 'desc' => isset($meta[$base]) ? phoromatic_catalog_sched::clean($meta[$base][1], 300) : '');
		}
		return $items;
	}
	protected static function native_save($account_id)
	{
		// array(message html or null, error text or null, draft or null)
		if(PHOROMATIC_USER_IS_VIEWER || !phoromatic_private_repo_web::origin_ok() || !verify_submission_token())
		{
			return array(null, 'Nothing was saved: the form could not be verified (it may have been open too long, or came from another site). Build the suite again from this page.', null);
		}
		$members = phoromatic_native_suites::posted_members(isset($_POST['m']) ? $_POST['m'] : null);
		$suite_id = isset($_POST['ns_id']) && is_string($_POST['ns_id']) && ctype_digit($_POST['ns_id']) && strlen($_POST['ns_id']) <= 9 ? (int)$_POST['ns_id'] : null;
		$title = isset($_POST['ns_title']) && is_string($_POST['ns_title']) ? $_POST['ns_title'] : '';
		$description = isset($_POST['ns_description']) && is_string($_POST['ns_description']) ? $_POST['ns_description'] : '';
		$draft = array('id' => $suite_id, 'title' => $title, 'description' => $description, 'members' => array_map(function($m) { return array('ref' => $m['ref'], 'version' => $m['version'], 'opts' => (object)$m['options']); }, $members));
		if($suite_id !== null && phoromatic_native_suites::get($account_id, $suite_id) === null)
		{
			return array(null, 'No such suite; nothing was saved.', $draft);
		}
		$new_id = null;
		$e = phoromatic_native_suites::save($account_id, $suite_id, $title, $description, $members, phoromatic_native_admin::actor_of(), $new_id);
		if($e !== null)
		{
			return array(null, $e, $draft);
		}
		return array('Saved &ldquo;' . htmlspecialchars(trim($title)) . '&rdquo; with ' . count($members) . ' test' . (count($members) == 1 ? '' : 's') . '. Add it to a schedule from the schedule\'s Native Tests box.', null, null);
	}
	public static function render_page_process($PATH)
	{
		$account_id = $_SESSION['AccountID'];
		$native_message = null;
		$native_error = null;
		$draft = null;
		if(isset($_POST['native_suite_save']))
		{
			list($native_message, $native_error, $draft) = self::native_save($account_id);
			if($native_message !== null)
			{
				phoromatic_add_activity_stream_event('suite', 'native', 'added');
			}
		}
		else if(isset($_POST['native_suite_update']) && !PHOROMATIC_USER_IS_VIEWER)
		{
			if(!phoromatic_private_repo_web::origin_ok() || !verify_submission_token())
			{
				$native_error = 'Nothing was changed: the form could not be verified. Open the suite again from Local Suites.';
			}
			else
			{
				$only = isset($_POST['ns_member']) && is_string($_POST['ns_member']) && ctype_digit($_POST['ns_member']) ? (int)$_POST['ns_member'] : null;
				list($e, $moved, $refused) = phoromatic_native_suites::update($account_id, isset($_POST['ns_id']) && is_string($_POST['ns_id']) && ctype_digit($_POST['ns_id']) ? (int)$_POST['ns_id'] : 0, $only, phoromatic_native_admin::actor_of());
				$native_error = $e;
				$native_message = $e === null ? ($moved > 0 ? 'Moved ' . $moved . ' test' . ($moved == 1 ? '' : 's') . ' to the newest version.' : 'Nothing could be moved.') : null;
				if(!empty($refused))
				{
					$native_message = ($native_message !== null ? $native_message . ' ' : '') . 'These stayed where they were: ' . htmlspecialchars(implode('; ', $refused));
				}
			}
		}
		else if(isset($_POST['suite_title']) && (PHOROMATIC_USER_IS_VIEWER || !phoromatic_private_repo_web::origin_ok() || !verify_submission_token()))
		{
			echo '<h2>Nothing was saved: the form could not be verified (it may have been open too long, or came from another site). Build the suite again from this page.</h2>';
		}
		else if(isset($_POST['suite_title']))
		{
			phoromatic_quit_if_invalid_input_found(array('suite_title', 'test_add', 'suite_version', 'suite_description'));
			$proceed = true;

			if(strlen($_POST['suite_title']) < 3 || pts_strings::keep_in_string($_POST['suite_title'], pts_strings::CHAR_LETTER | pts_strings::CHAR_NUMERIC | pts_strings::CHAR_DASH) != $_POST['suite_title'])
			{
				echo '<h2>Suite title must be at least three characters and contain just alpha-numeric characters and dashes allowed.</h2>';
				$proceed = false;
			}
			if(!isset($_POST['suite_version']) || empty($_POST['suite_version']) || !pts_strings::is_version($_POST['suite_version']))
			{
				echo '<h2>Suite version must be valid numeric version format X.Y.Z.</h2>';
				$proceed = false;
			}

			$tests = array();

			foreach($_POST['test_add'] as $i => $test_identifier)
			{
				$test_prefix = $_POST['test_prefix'][$i];
				$args = array();
				$args_name = array();

				foreach($_POST as $i => $v)
				{
					if(strpos($i, $test_prefix) !== false && substr($i, -9) != '_selected')
					{
						phoromatic_quit_if_invalid_input_found(array($i, $i . '_selected'));
						if(strpos($v, '||') !== false)
						{
							$opts = explode('||', $v);
							$a = array();
							$d = array();
							foreach($opts as $opt)
							{
								$t = explode('::', $opt);
								$a[] = $t[1];
								$d[] = $t[0];
							}
							$args[] = $a;
							$args_name[] = $d;
						}
						else
						{
							$args[] = array($v);
							$args_name[] = array($_POST[$i . '_selected']);
						}
					}
				}

				$test_args = array();
				$test_args_description = array();
				pts_test_run_options::compute_all_combinations($test_args, null, $args, 0);
				pts_test_run_options::compute_all_combinations($test_args_description, null, $args_name, 0, ' - ');

				foreach(array_keys($test_args) as $i)
				{
					$tests[] = array('test' => $test_identifier, 'description' => $test_args_description[$i], 'args' => $test_args[$i]);
				}
			}

			if(count($tests) < 1)
			{
				echo '<h2>You must add at least one test to the suite.</h2>';
				$proceed = false;
			}

			if($proceed)
			{
				$new_suite = new pts_test_suite();
				$version_bump = 0;

			//	do
			//	{
					//$suite_version = '1.' . $version_bump . '.0';
					$suite_version = $_POST['suite_version'];
					$suite_id = $new_suite->clean_save_name_string($_POST['suite_title']) . '-' . $suite_version;
					if(($suite_folder = phoromatic_tenant_path::segment($suite_id, 255)) === false)
					{
						echo '<h2>The suite title and version cannot be used as a folder name.</h2>';
						return false;
					}
					$suite_dir = phoromatic_server::phoromatic_account_suite_path($_SESSION['AccountID'], $suite_folder);
			//		$version_bump++;
			//	}
			//	while(is_dir($suite_dir));
				pts_file_io::mkdir($suite_dir);
				$save_to = $suite_dir . '/suite-definition.xml';

				$new_suite->set_title($_POST['suite_title']);
				$new_suite->set_version($suite_version); // $suite_version
				$new_suite->set_maintainer($_SESSION['UserName']);
				$new_suite->set_suite_type('System');
				$new_suite->set_description($_POST['suite_description']);

				foreach($tests as $m)
				{
					$new_suite->add_to_suite($m['test'], $m['args'], $m['description']);
				}

				$new_suite->save_xml(null, $save_to);
				echo '<h2>Saved As ' . $suite_id . '</h2>';
				phoromatic_add_activity_stream_event('suite', $suite_id, 'added');
			}
		}
		echo phoromatic_webui_header_logged_in();

		if(PHOROMATIC_USER_IS_VIEWER)
		{
			echo '<div id="pts_phoromatic_main_area"><h1>Local Suites</h1><p>Find already created local test suites by your account/group via the <a href="/?local_suites">local suites</a> page.</p></div>';
			echo phoromatic_webui_footer();
			return;
		}

		// Which suite is open: ?build_suite/native/<SuiteID> is a native suite, ?build_suite/<folder> a Phoronix suite (a folder name always ends in -<version>, so it is never "native")
		$kind = 'native';
		$native_suite = null;
		$pts_file = null;
		if(isset($PATH[0]) && $PATH[0] === 'native' && isset($PATH[1]) && ctype_digit($PATH[1]) && strlen($PATH[1]) <= 9)
		{
			$native_suite = phoromatic_native_suites::get($account_id, (int)$PATH[1]);
		}
		else if(isset($PATH[0]) && $PATH[0] !== 'native' && ($suite_folder = phoromatic_tenant_path::uri_segment($PATH[0], 255)) !== false)
		{
			$f = phoromatic_server::phoromatic_account_suite_path($_SESSION['AccountID'], $suite_folder) . '/suite-definition.xml';
			if(is_file($f))
			{
				$pts_file = $f;
				$kind = 'pts';
			}
		}
		$suite = new pts_test_suite($pts_file);
		$native_ready = phoromatic_native_suites::ready();
		if(!$native_ready)
		{
			$kind = 'pts';
		}

		$offer = $native_ready ? phoromatic_native_suites::offer($account_id) : array('catalog' => array(), 'reported' => array(), 'has_catalog' => false);
		$data = array('kind' => $kind, 'nativeReady' => $native_ready, 'hasCatalog' => $offer['has_catalog'], 'native' => self::native_items($account_id, $offer), 'pts' => self::pts_items(), 'taxonomy' => phoromatic_native_taxonomy::lists(), 'editing' => $native_suite !== null || $pts_file !== null,
			'draft' => null, 'error' => $native_error, 'saved' => $draft === null ? null : $native_message, 'updates' => array(), 'revision' => null, 'usedBy' => array());
		if($draft !== null)
		{
			$data['draft'] = $draft;
			$data['kind'] = 'native';
		}
		else if($native_suite !== null)
		{
			$members = array();
			foreach($native_suite['members'] as $i => $m)
			{
				$members[] = array('ref' => $m['Ref'], 'version' => $m['Version'], 'opts' => (object)(json_decode($m['OptionsJSON'], true) ?: array()));
				$st = phoromatic_native_suites::pin_state($account_id, $offer, $m);
				if(in_array($st['state'], array('superseded', 'withdrawn', 'dropped'), true))
				{
					$data['updates'][] = array('pos' => $i, 'state' => $st['state'], 'newest' => $st['newest'], 'why' => $st['why'] === null ? null : phoromatic_catalog_sched::clean($st['why']), 'ref' => $m['Ref'], 'version' => $m['Version']);
				}
			}
			$data['draft'] = array('id' => (int)$native_suite['SuiteID'], 'title' => $native_suite['Title'], 'description' => (string)$native_suite['Description'], 'members' => $members);
			$data['revision'] = (int)$native_suite['Revision'];
			$data['usedBy'] = array_values(phoromatic_native_suites::used_by($account_id, (int)$native_suite['SuiteID']));
		}
		$data['catalogText'] = 'This server has no signed catalog yet. A server administrator imports it on the Test catalog page.';
		$data['updateToken'] = isset($_SESSION['Token']) && is_string($_SESSION['Token']) ? $_SESSION['Token'] : '';
		$data['action'] = $_SERVER['REQUEST_URI'];

		$main = '<div class="bs-crumbs"><a href="/?testing">Testing</a> &rsaquo; <a href="/?local_suites">Local Suites</a> &rsaquo; Build Suite</div>';
		$main .= '<h1>Build Suite</h1><p class="bs-intro">A suite is a set of tests with their settings, saved under one name so a schedule can run them together. Find tests below, add them, then set each one\'s version and options under <strong>This suite</strong>.</p>';
		if($native_message !== null && $draft === null)
		{
			$main .= '<p class="bs-callout bs-ok" role="status">' . $native_message . '</p>';
		}
		$main .= self::markup($suite, $native_ready);
		$main .= '<script type="application/json" id="bs-data">' . self::json($data) . '</script>';
		$main .= '<script type="text/javascript">' . self::script() . '</script>';
		echo '<div id="pts_phoromatic_main_area">' . $main . '</div>';
		echo phoromatic_webui_footer();
	}
	protected static function markup($suite, $native_ready)
	{
		$h = function($t) { return htmlspecialchars((string)$t, ENT_QUOTES); };
		$pts_script = '';
		foreach($suite->get_contained_test_result_objects() as $obj)
		{
			$pts_script .= 'phoromatic_ajax_append_element("r_add_test_build_suite_details/&tp=' . rawurlencode($obj->test_profile->get_identifier()) . '&tpa=' . rawurlencode($obj->get_arguments_description()) . '", "test_details");' . PHP_EOL;
		}
		$html = self::style() . '<div id="bs">';
		$html .= '<div class="bs-kind" role="group" aria-labelledby="bs-kind-label"><h3 id="bs-kind-label" class="bs-sr">Engine</h3><div class="bs-seg">' .
			'<input type="radio" name="bs_kind" id="bs-kind-native" value="native"' . ($native_ready ? '' : ' disabled="disabled"') . ' /><label for="bs-kind-native">Native engine</label>' .
			'<input type="radio" name="bs_kind" id="bs-kind-pts" value="pts" /><label for="bs-kind-pts">Phoronix engine</label></div>' .
			'<p class="bs-kind-text" id="bs-kind-text"></p><p class="bs-kind-lock" id="bs-kind-lock" hidden="hidden"></p></div>';
		$html .= '<div class="bs-layout"><section aria-labelledby="bs-find-h"><h2 id="bs-find-h" class="bs-sr">Find tests</h2>' .
			'<div class="bs-searchbar"><label for="bs-q" class="bs-sr">Search tests</label><input type="search" id="bs-q" placeholder="Search by name, ref, platform or what it measures" autocomplete="off" /></div>' .
			'<a class="bs-jump" id="bs-jump" href="#bs-suite-h">This suite</a><div class="bs-chips" id="bs-chips" role="group" aria-label="Sources to search"></div><div class="bs-facets" id="bs-facets" aria-label="Filters"></div><div id="bs-cross" class="bs-cross" hidden="hidden"></div><div id="bs-results" aria-live="polite"></div></section>';
		$html .= '<aside class="bs-suite" aria-labelledby="bs-suite-h"><div class="bs-suite-head"><h2 id="bs-suite-h">This suite</h2><span class="bs-tag" id="bs-suite-kind"></span></div>';
		// the native half: a form of its own, filled by the script when it is sent
		$html .= '<div id="bs-pane-native"><form action="' . $h($_SERVER['REQUEST_URI']) . '" method="post" id="bs-native-form">' . write_token_in_form() . '<input type="hidden" name="native_suite_save" value="1" /><div id="bs-native-fields"></div>' .
			'<div class="bs-fieldset"><div><label for="bs-title">Title</label><input type="text" id="bs-title" maxlength="100" /></div><div><label for="bs-desc">Description (optional)</label><textarea id="bs-desc" rows="2" maxlength="500"></textarea></div></div>' .
			'<p class="bs-runs-on" id="bs-runs-on"></p><div id="bs-callouts"></div><div class="bs-members" id="bs-members"></div><div class="bs-suite-empty" id="bs-suite-empty" hidden="hidden">No tests yet. Use <strong>Add</strong> beside a test to put it here.</div>' .
			'<div id="bs-save-msg"></div><div class="bs-save-row"><span class="bs-why" id="bs-why"></span><input type="submit" class="bs-btn bs-big" id="bs-save" value="Save suite" /></div></form><div id="bs-updates"></div></div>';
		// the Phoronix half: today's form and fields, unchanged
		$html .= '<div id="bs-pane-pts"><form action="' . $h($_SERVER['REQUEST_URI']) . '" name="build_suite" id="build_suite" method="post">' . write_token_in_form() . '
			<div class="bs-fieldset"><div><label for="suite_title">Title</label><input type="text" name="suite_title" id="suite_title" value="' . $h($suite->get_title()) . '" /></div>
			<div><label for="suite_version">Suite version</label><input type="text" name="suite_version" id="suite_version" value="' . $h($suite->get_version() == null ? '1.0.0' : $suite->get_version()) . '" /></div>
			<div><label for="suite_description">Description</label><textarea name="suite_description" id="suite_description" cols="60" rows="2">' . $h($suite->get_description()) . '</textarea></div></div>
			<p class="bs-runs-on" id="bs-runs-on-pts"></p>
			<div id="test_details"></div>
			<div class="bs-suite-empty" id="bs-pts-empty">No tests yet. Use <strong>Add</strong> beside a test to put it here.</div>
			<p class="bs-small">' . pts_web_embed::cookie_checkbox_option_helper('list_show_all_test_versions', 'Show all available test profile versions.') . '</p>
			<div class="bs-save-row"><input name="submit" value="' . ($suite->get_title() != null ? 'Edit' : 'Create') . ' Suite" type="submit" class="bs-btn bs-big" /></div></form>
			<script type="text/javascript">' . $pts_script . '</script></div>';
		$html .= '</aside></div></div>';
		return $html;
	}
	protected static function style()
	{
		return <<<'CSS'
<style>
#bs { --bs-warn-bg: #fef2f2; --bs-warn-border: #fca5a5; --bs-note-bg: #fff7ed; --bs-note-border: #fdba74; --bs-info-bg: #eff6ff; --bs-info-border: #bfdbfe; --bs-ok-bg: #f0fdf4; --bs-ok-border: #86efac; }
@media (prefers-color-scheme: dark) { :root:not([data-theme="light"]) #bs { --bs-warn-bg: #2a1618; --bs-warn-border: #7f1d1d; --bs-note-bg: #2a1d12; --bs-note-border: #9a3412; --bs-info-bg: #142033; --bs-info-border: #1e3a8a; --bs-ok-bg: #102117; --bs-ok-border: #166534; } }
:root[data-theme="dark"] #bs { --bs-warn-bg: #2a1618; --bs-warn-border: #7f1d1d; --bs-note-bg: #2a1d12; --bs-note-border: #9a3412; --bs-info-bg: #142033; --bs-info-border: #1e3a8a; --bs-ok-bg: #102117; --bs-ok-border: #166534; }
.bs-crumbs { font-size: .84em; color: var(--ph-text-muted); margin-bottom: 6px; }
.bs-intro { max-width: 78ch; color: var(--ph-text-muted); margin: 0 0 16px; }
.bs-sr { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }
.bs-callout { font-size: .9em; padding: 8px 10px; border-radius: 6px; margin: 0 0 10px; border: 1px solid #fdba74; background: #fff7ed; color: #1f2430; }
.bs-ok { border-color: #86efac; background: #f0fdf4; }
#bs .bs-callout { border-color: var(--bs-note-border); background: var(--bs-note-bg); color: var(--ph-text); }
#bs .bs-callout.bs-ok { border-color: var(--bs-ok-border); background: var(--bs-ok-bg); }
#bs .bs-callout.bs-err { border-color: var(--bs-warn-border); background: var(--bs-warn-bg); }
#bs .bs-kind { display: flex; flex-wrap: wrap; gap: 10px 16px; align-items: center; padding: 12px 14px; background: var(--ph-bg-subtle); border: 1px solid var(--ph-border); border-radius: 6px; margin-bottom: 18px; }
#bs .bs-seg { display: inline-flex; border: 1px solid var(--ph-border-strong); border-radius: 999px; padding: 3px; background: var(--ph-bg-elevated); flex-wrap: wrap; }
#bs .bs-seg input { position: absolute; opacity: 0; width: 1px; height: 1px; }
#bs .bs-seg label { padding: 6px 14px; border-radius: 999px; cursor: pointer; font-weight: 600; font-size: .9em; user-select: none; }
#bs .bs-seg input:checked + label { background: var(--ph-accent); color: var(--ph-accent-contrast); }
#bs .bs-seg input:disabled + label { cursor: not-allowed; color: var(--ph-text-muted); opacity: .6; }
#bs .bs-seg input:focus-visible + label { outline: 3px solid var(--ph-input-focus-ring); outline-offset: 1px; }
#bs .bs-kind-text { flex: 1 1 280px; font-size: .9em; color: var(--ph-text-muted); margin: 0; }
#bs .bs-kind-lock { flex-basis: 100%; font-size: .86em; color: var(--ph-heading-soft); margin: 0; }
#bs .bs-jump { display: none; margin: 0 0 10px; font-size: .9em; font-weight: 600; }
@media (max-width: 980px) { #bs .bs-jump { display: inline-block; } }
#bs .bs-layout { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 430px); gap: 22px; align-items: start; }
@media (max-width: 980px) { #bs .bs-layout { grid-template-columns: minmax(0, 1fr); } }
#bs .bs-searchbar { margin-bottom: 10px; }
#bs input[type=search] { width: 100%; box-sizing: border-box; }
#bs .bs-chips { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 14px; }
#bs .bs-chip { font: inherit; font-size: .85em; font-weight: 600; padding: 4px 11px; border-radius: 999px; cursor: pointer; border: 1px solid var(--ph-border-strong); background: var(--ph-bg-elevated); color: var(--ph-text-muted); }
#bs .bs-chip[aria-pressed="true"] { background: var(--ph-bg-subtle); color: var(--ph-text); border-color: var(--ph-link); }
#bs .bs-chip .bs-n { font-weight: 400; margin-left: 4px; }
#bs .bs-facets { margin-bottom: 14px; }
#bs .bs-facet { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; margin-bottom: 6px; }
#bs .bs-facet-name { font-size: .8em; font-weight: 600; color: var(--ph-text-muted); min-width: 6.5em; }
#bs .bs-btn { font: inherit; font-size: .88em; font-weight: 600; padding: 6px 14px; border-radius: 6px; border: 0; cursor: pointer; background: var(--ph-accent); color: var(--ph-accent-contrast); }
#bs .bs-btn:disabled { cursor: not-allowed; opacity: .45; }
#bs .bs-btn.bs-ghost { background: transparent; color: var(--ph-text); border: 1px solid var(--ph-border-strong); font-weight: 500; }
#bs .bs-btn.bs-link { background: transparent; color: var(--ph-link); padding: 0; font-weight: 600; }
#bs .bs-big { font-size: .95em; padding: 8px 20px; }
#bs .bs-group { margin-bottom: 18px; }
#bs .bs-group-head { display: flex; flex-wrap: wrap; gap: 2px 10px; align-items: baseline; padding-bottom: 6px; border-bottom: 1px solid var(--ph-border); }
#bs .bs-group-head h2 { font-size: 1.02em; margin: 0; clear: none; }
#bs .bs-muted { color: var(--ph-text-muted); font-size: .86em; }
#bs .bs-row { display: flex; flex-wrap: wrap; gap: 6px 14px; align-items: flex-start; padding: 10px 6px; border-bottom: 1px solid var(--ph-border); }
#bs .bs-row-main { flex: 1 1 300px; min-width: 0; }
#bs .bs-row-title { font-weight: 600; display: flex; flex-wrap: wrap; gap: 2px 8px; align-items: baseline; }
#bs .bs-row-desc { font-size: .86em; color: var(--ph-text-muted); margin: 2px 0 0; }
#bs .bs-tags { display: flex; flex-wrap: wrap; gap: 4px; margin-top: 6px; }
#bs .bs-tag { font-size: .76em; font-weight: 600; padding: 1px 8px; border-radius: 999px; border: 1px solid var(--ph-border-strong); color: var(--ph-text-muted); white-space: nowrap; }
#bs .bs-tag.bs-install { border-color: var(--bs-note-border); color: var(--ph-heading-soft); background: var(--bs-note-bg); }
#bs .bs-tag.bs-admin { border-color: var(--bs-warn-border); color: var(--ph-warn); background: var(--bs-warn-bg); }
#bs .bs-row-act { display: flex; align-items: center; gap: 8px; flex: 0 0 auto; margin-left: auto; }
#bs .bs-insuite { font-size: .8em; color: var(--ph-accent); font-weight: 600; white-space: nowrap; }
#bs .bs-reason, #bs .bs-refuse { flex-basis: 100%; font-size: .86em; padding: 8px 10px; border-radius: 6px; margin: 0; border: 1px solid var(--bs-note-border); background: var(--bs-note-bg); }
#bs .bs-refuse { border-color: var(--bs-warn-border); background: var(--bs-warn-bg); }
#bs .bs-empty { padding: 24px 14px; text-align: center; border: 1px dashed var(--ph-border-strong); border-radius: 6px; color: var(--ph-text-muted); }
#bs .bs-empty p { margin: 0 0 8px; }
#bs .bs-cross { font-size: .88em; padding: 8px 12px; border-radius: 6px; background: var(--bs-info-bg); border: 1px solid var(--bs-info-border); margin-bottom: 14px; }
#bs .bs-suite { border: 1px solid var(--ph-border-strong); border-radius: 10px; background: var(--ph-bg-elevated); padding: 16px; }
#bs .bs-suite-head { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 6px; align-items: baseline; margin-bottom: 10px; }
#bs .bs-suite-head h2 { margin: 0; }
#bs .bs-fieldset { display: grid; gap: 10px; margin-bottom: 14px; }
#bs .bs-fieldset label { display: block; font-size: .78em; font-weight: 600; text-transform: uppercase; letter-spacing: .03em; color: var(--ph-text-muted); margin-bottom: 3px; }
#bs .bs-fieldset input, #bs .bs-fieldset textarea { width: 100%; box-sizing: border-box; }
#bs .bs-runs-on { font-size: .88em; margin: 0 0 8px; }
#bs .bs-members { margin: 0 0 12px; border-top: 1px solid var(--ph-border); }
#bs .bs-member { padding: 10px 0; border-bottom: 1px solid var(--ph-border); }
#bs .bs-m-top { display: flex; gap: 8px; align-items: flex-start; }
#bs .bs-m-num { font-size: .78em; font-weight: 700; color: var(--ph-text-muted); min-width: 1.4em; padding-top: 2px; }
#bs .bs-m-name { flex: 1 1 auto; min-width: 0; }
#bs .bs-m-name strong { display: block; }
#bs .bs-m-btns { display: flex; gap: 4px; flex: 0 0 auto; }
#bs .bs-m-btns button { font: inherit; font-size: .8em; padding: 3px 8px; border-radius: 6px; border: 1px solid var(--ph-border-strong); background: transparent; color: var(--ph-text); cursor: pointer; }
#bs .bs-m-btns button:disabled { opacity: .35; cursor: not-allowed; }
#bs .bs-m-cfg { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 8px 10px; margin: 8px 0 0 calc(1.4em + 8px); }
#bs .bs-m-cfg label { display: block; font-size: .76em; font-weight: 600; color: var(--ph-text-muted); margin-bottom: 2px; }
#bs .bs-m-cfg select, #bs .bs-m-cfg input { width: 100%; box-sizing: border-box; }
#bs .bs-m-note, #bs .bs-m-err { margin: 6px 0 0 calc(1.4em + 8px); font-size: .82em; color: var(--ph-text-muted); }
#bs .bs-m-err { color: var(--ph-warn); font-weight: 600; }
#bs .bs-suite-empty { padding: 18px 8px; text-align: center; color: var(--ph-text-muted); font-size: .9em; border-top: 1px solid var(--ph-border); margin-bottom: 12px; }
#bs .bs-save-row { display: flex; flex-wrap: wrap; gap: 8px 12px; align-items: center; justify-content: flex-end; }
#bs .bs-why { flex: 1 1 160px; font-size: .84em; color: var(--ph-text-muted); }
#bs .bs-small { font-size: .86em; }
</style>
CSS;
	}
	protected static function script()
	{
		return <<<'JS'
(function () {
  "use strict";
  var D = JSON.parse(document.getElementById("bs-data").textContent);
  var $ = function (id) { return document.getElementById(id); };
  function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]; }); }
  var PNAME = { linux: "Linux", windows: "Windows", macos: "macOS" };
  function pname(p) { return PNAME[p] || p; }
  function pnames(a) { return a.map(pname); }
  function joinNames(a) { return a.length <= 1 ? (a[0] || "") : a.slice(0, -1).join(", ") + " and " + a[a.length - 1]; }
  function union(items) { var u = []; items.forEach(function (t) { t.plat.forEach(function (p) { if (u.indexOf(p) === -1) u.push(p); }); }); return u; }

  var NI = {}; D.native.forEach(function (t) { NI[t.ref] = t; });
  var PI = {}; D.pts.forEach(function (t) { PI[t.ref] = t; });

  var KIND_TEXT = {
    native: "Runs on systems with the Benchy native agent. Tests come from the signed catalog and from what your systems report. Add the suite to a schedule to run it; benchmark tickets don't run native suites yet.",
    pts: "Runs on systems with the Phoronix Test Suite engine, as suites always have. Tests come from your private repository and OpenBenchmarking.org."
  };
  function sources(kind) {
    if (kind === "native") {
      var out = [];
      [["free", "Free tests", false], ["nonfree", "Non-free tests", true]].forEach(function (g) {
        var items = D.native.filter(function (t) { return t.src === g[0]; });
        if (items.length) out.push({ id: g[0], chip: g[1], head: g[1], sub: "Signed catalog. " + joinNames(pnames(union(items))) + (g[2] ? "; you bring the license." : ".") });
      });
      if (D.native.some(function (t) { return t.src === "builtin"; })) out.push({ id: "builtin", chip: "Reported by your systems", head: "Other native tests", sub: "Built into the agent and reported by your systems. Not in this server's catalog, so each system runs its own version." });
      return out;
    }
    var o = [];
    if (D.pts.some(function (t) { return t.src === "private"; })) o.push({ id: "private", chip: "Private repository", head: "Private repository", sub: "Test profiles in this server's local folder." });
    o.push({ id: "ob", chip: "OpenBenchmarking", head: "OpenBenchmarking.org", sub: "Phoronix test profiles." });
    return o;
  }
  function items(kind) { return kind === "native" ? D.native : D.pts; }

  /* ---------- state ---------- */
  var S = { kind: D.kind === "pts" ? "pts" : "native", q: "", on: { free: true, nonfree: true, builtin: true, "private": true, ob: true }, members: [], refusal: null, id: null, uid: 0, f: { tag: {}, plat: {}, len: {}, admin: false } };
  var TAX = D.taxonomy || { cats: [], tagLabels: {} };
  var LENS = [["short", "Short", "up to 5 min"], ["medium", "Medium", "5 to 20 min"], ["long", "Long", "over 20 min"]];
  function tlabel(k) { return Object.prototype.hasOwnProperty.call(TAX.tagLabels, k) ? TAX.tagLabels[k] : k; }
  function clabel(k) { for (var i = 0; i < TAX.cats.length; i++) if (TAX.cats[i][0] === k) return TAX.cats[i][1]; return "Other"; }
  function picked(m) { return Object.keys(m).filter(function (k) { return m[k]; }); }
  // chips of one facet combine with OR, facets with AND; a test with no estimate matches no length chip (categories.md 6, 7)
  function facetOk(t) {
    if (S.kind !== "native") return true;
    var f = S.f, a = picked(f.tag), b = picked(f.plat), c = picked(f.len);
    if (a.length && !(t.tags || []).some(function (x) { return f.tag[x]; })) return false;
    if (b.length && !(t.plat || []).some(function (x) { return f.plat[x]; })) return false;
    if (c.length && !f.len[t.len]) return false;
    if (f.admin && !(t.install || t.elevated)) return false;
    return true;
  }
  function facetsOn() { var f = S.f; return picked(f.tag).length + picked(f.plat).length + picked(f.len).length > 0 || f.admin; }
  function defaults(opts) {
    var o = {};
    opts.forEach(function (x) { o[x.key] = x.def !== "" ? x.def : (x.kind === "choice" && x.choices.length ? x.choices[0][0] : ""); });
    return o;
  }
  function optsFor(t, version) {
    if (t.src === "builtin") return t.opts;
    var v = version || t.ver;
    return t.versions[v] ? t.versions[v].opts : t.opts;
  }
  function newMember(ref, version, opts) { return { id: ++S.uid, ref: ref, version: version, opts: opts, err: null }; }
  function addMember(t) { S.members.push(newMember(t.ref, t.ver, defaults(optsFor(t, t.ver)))); }
  if (D.draft && S.kind === "native") {
    S.id = D.draft.id;
    D.draft.members.forEach(function (m) {
      var o = {}; for (var k in m.opts) { if (Object.prototype.hasOwnProperty.call(m.opts, k)) o[k] = String(m.opts[k]); }
      S.members.push(newMember(m.ref, m.version, o));
    });
  }
  $("bs-title").value = D.draft ? D.draft.title : "";
  $("bs-desc").value = D.draft ? D.draft.description : "";

  function memPlat(m) {
    var t = NI[m.ref];
    if (!t) return ["linux", "windows", "macos"];
    if (t.src === "builtin") return t.plat;
    var v = m.version || t.ver;
    return t.versions[v] ? t.versions[v].plat : t.plat;
  }
  function suitePlat(members) {
    var set = null;
    members.forEach(function (m) { var p = memPlat(m); set = set === null ? p.slice() : set.filter(function (x) { return p.indexOf(x) !== -1; }); });
    return set === null ? ["linux", "windows", "macos"] : set;
  }
  function tryPlat(candidate, members, t) {
    // the suite's platforms with the candidate member set; "" when it works, else the refusal text
    var pl = suitePlat(members.concat([candidate]));
    if (pl.length) return "";
    var names = []; members.forEach(function (m) { var n = NI[m.ref] ? NI[m.ref].title : m.ref; if (names.indexOf(n) === -1) names.push(n); });
    var cur = suitePlat(members);
    return "<b>" + esc(t.title) + " can't " + (candidate.joining ? "join this suite" : "use that version") + ".</b> It runs on " + esc(joinNames(pnames(memPlat(candidate)))) + " only, and " + esc(joinNames(names.slice(0, 3))) + (names.length > 3 ? " and others" : "") +
      " run on " + esc(joinNames(pnames(cur))) + " only. A suite runs on one machine at a time, so it would run nowhere. Build a separate " + esc(pname(memPlat(candidate)[0])) + " suite for it; a schedule can hold both.";
  }

  /* ---------- search ---------- */
  function hay(t) {
    return [t.title, t.ref, t.desc || "", (t.plat || []).map(pname).join(" "), t.label || "", t.install ? "installs for all users " + t.install : "", t.elevated ? "runs as administrator" : "",
      t.src === "nonfree" ? "non-free nonfree" : "", t.src === "free" ? "free" : "", t.src === "builtin" ? "reported by your systems" : "", t.src === "private" ? "private repository" : "", t.src === "ob" ? "openbenchmarking" : "", t.cat ? clabel(t.cat) : "", (t.tags || []).map(tlabel).join(" ")].join(" ").toLowerCase();
  }
  function matches(t, q) {
    var words = q.toLowerCase().split(/\s+/).filter(Boolean);
    if (!words.length) return true;
    var h = hay(t);
    return words.every(function (w) { return h.indexOf(w) !== -1; });
  }
  function ptsCount() { return document.querySelectorAll("#test_details input[name='test_add[]']").length; }
  function locked() { return S.kind === "native" ? S.members.length > 0 : ptsCount() > 0; }
  function countIn(ref) { return S.members.filter(function (m) { return m.ref === ref; }).length; }

  /* ---------- rendering ---------- */
  function renderKind() {
    $("bs-kind-native").checked = S.kind === "native";
    $("bs-kind-pts").checked = S.kind === "pts";
    $("bs-kind-text").textContent = KIND_TEXT[S.kind];
    var lk = locked();
    if (D.nativeReady) { $("bs-kind-native").disabled = lk && S.kind !== "native"; }
    $("bs-kind-pts").disabled = lk && S.kind !== "pts";
    var lock = $("bs-kind-lock");
    lock.hidden = !lk;
    if (lk) lock.textContent = S.kind === "native"
      ? "This suite has native tests, so it stays a native suite. Each engine runs only its own tests, so a suite can't hold both. Save this one, then start a new suite for Phoronix profiles."
      : "This suite has Phoronix profiles, so it stays a Phoronix suite. Each engine runs only its own tests, so a suite can't hold both. Save this one, then start a new suite for native tests.";
    var n = S.kind === "native" ? S.members.length : ptsCount();
    $("bs-jump").textContent = "Go to this suite (" + n + " test" + (n === 1 ? "" : "s") + ")";
    $("bs-suite-kind").textContent = S.kind === "native" ? "Native suite" : "Phoronix suite";
    $("bs-pane-native").hidden = S.kind !== "native";
    $("bs-pane-pts").hidden = S.kind !== "pts";
  }
  function renderChips() {
    $("bs-chips").innerHTML = sources(S.kind).map(function (s) {
      var n = items(S.kind).filter(function (t) { return t.src === s.id && matches(t, S.q) && facetOk(t); }).length;
      return '<button type="button" class="bs-chip" data-src="' + s.id + '" aria-pressed="' + (S.on[s.id] ? "true" : "false") + '">' + esc(s.chip) + '<span class="bs-n">' + n + "</span></button>";
    }).join("");
  }
  function renderFacets() {
    var box = $("bs-facets");
    if (S.kind !== "native") { box.innerHTML = ""; return; }
    var all = D.native, tags = [], plats = [], lens = {}, admin = false;
    all.forEach(function (t) {
      (t.tags || []).forEach(function (x) { if (tags.indexOf(x) === -1) tags.push(x); });
      (t.plat || []).forEach(function (x) { if (plats.indexOf(x) === -1) plats.push(x); });
      if (t.len) lens[t.len] = true;
      if (t.install || t.elevated) admin = true;
    });
    tags.sort(function (a, b) { return tlabel(a).localeCompare(tlabel(b)); });
    function chip(kind, key, text, on, hint) {
      return '<button type="button" class="bs-chip" data-f="' + kind + '" data-k="' + esc(key) + '" aria-pressed="' + (on ? "true" : "false") + '"' + (hint ? ' title="' + esc(hint) + '"' : "") + ">" + esc(text) + "</button>";
    }
    var rows = [];
    if (tags.length) rows.push(["Tags", tags.map(function (x) { return chip("tag", x, tlabel(x), S.f.tag[x]); })]);
    if (plats.length > 1) rows.push(["Platform", plats.map(function (x) { return chip("plat", x, pname(x), S.f.plat[x]); })]);
    var ls = LENS.filter(function (l) { return lens[l[0]]; });
    if (ls.length) rows.push(["Run length", ls.map(function (l) { return chip("len", l[0], l[1], S.f.len[l[0]], l[2]); })]);
    if (admin) rows.push(["Rights", [chip("admin", "1", "Needs administrator", S.f.admin)]]);
    var html = rows.map(function (r) { return '<div class="bs-facet" role="group" aria-label="' + esc(r[0]) + '"><span class="bs-facet-name">' + esc(r[0]) + "</span>" + r[1].join("") + "</div>"; }).join("");
    if (rows.length && facetsOn()) html += '<p><button type="button" class="bs-btn bs-link" id="bs-clear-f">Clear filters</button></p>';
    box.innerHTML = html;
  }
  function rowHtml(t) {
    var tags = [];
    if (S.kind === "native") {
      var pl = t.plat || [];
      tags.push('<span class="bs-tag">' + esc(joinNames(pnames(pl))) + (pl.length === 1 ? " only" : "") + "</span>");
      if (t.ver) tags.push('<span class="bs-tag">' + esc(t.ver) + "</span>");
      (t.tags || []).forEach(function (x) { tags.push('<span class="bs-tag">' + esc(tlabel(x)) + "</span>"); });
      if (t.len) tags.push('<span class="bs-tag">' + esc(LENS.filter(function (l) { return l[0] === t.len; })[0][1]) + " run</span>");
      if (t.reported) tags.push('<span class="bs-tag">Reported by ' + esc(t.reported) + "</span>");
      if (t.install) tags.push('<span class="bs-tag bs-install">Installs ' + esc(t.install) + " for all users</span>");
      if (t.elevated) tags.push('<span class="bs-tag bs-admin">Runs as administrator</span>');
    }
    var n = S.kind === "native" ? countIn(t.ref) : 0;
    var id = "bs-add-" + t.ref.replace(/[^a-z0-9]+/gi, "-");
    var act = '<div class="bs-row-act">' + (n ? '<span class="bs-insuite">In suite' + (n > 1 ? " &times;" + n : "") + "</span>" : "") +
      '<button type="button" class="bs-btn" id="' + id + '" data-add="' + esc(t.ref) + '"' + (t.block ? ' disabled="disabled" aria-describedby="' + id + '-why"' : "") + ">" + (n ? "Add again" : "Add") + "</button></div>";
    var extra = "";
    if (t.block) extra += '<p class="bs-reason" id="' + id + '-why"><b>Can\'t go in a suite yet.</b> ' + esc(t.block) + "</p>";
    if (S.refusal && S.refusal.ref === t.ref) extra += '<p class="bs-refuse" role="alert">' + S.refusal.html + "</p>";
    return '<div class="bs-row"><div class="bs-row-main"><div class="bs-row-title">' + esc(t.title) + " <code>" + esc(t.ref) + '</code></div>' + (t.desc ? '<p class="bs-row-desc">' + esc(t.desc) + "</p>" : "") +
      '<div class="bs-tags">' + tags.join("") + "</div></div>" + act + extra + "</div>";
  }
  function renderResults() {
    var html = "", shown = 0, srcs = sources(S.kind);
    if (S.kind === "native" && !D.hasCatalog) html += '<p class="bs-callout">' + esc(D.catalogText) + "</p>";
    if (S.kind === "native") {
      // by category in the fixed order, then Other; rows by title inside a group (categories.md 7)
      var live = D.native.filter(function (t) { return S.on[t.src] && matches(t, S.q) && facetOk(t); });
      var order = TAX.cats.map(function (c) { return c[0]; }).concat(["other"]);
      order.forEach(function (k) {
        var rows = live.filter(function (t) { return (t.cat || "other") === k; });
        if (!rows.length) return;
        rows.sort(function (a, b) { return a.title.toLowerCase() < b.title.toLowerCase() ? -1 : (a.title.toLowerCase() > b.title.toLowerCase() ? 1 : 0); });
        shown += rows.length;
        html += '<section class="bs-group"><div class="bs-group-head"><h2>' + esc(clabel(k)) + ' <span class="bs-muted">(' + rows.length + ')</span></h2></div>' + rows.map(rowHtml).join("") + "</section>";
      });
    } else srcs.forEach(function (s) {
      if (!S.on[s.id]) return;
      var rows = items(S.kind).filter(function (t) { return t.src === s.id && matches(t, S.q); });
      if (!rows.length) return;
      shown += rows.length;
      html += '<section class="bs-group"><div class="bs-group-head"><h2>' + esc(s.head) + ' <span class="bs-muted">(' + rows.length + ')</span></h2><span class="bs-muted">' + esc(s.sub) + '</span></div>' + rows.map(rowHtml).join("") + "</section>";
    });
    if (!shown) {
      var off = srcs.filter(function (s) { return !S.on[s.id]; }).map(function (s) { return { s: s, n: items(S.kind).filter(function (t) { return t.src === s.id && matches(t, S.q) && facetOk(t); }).length }; }).filter(function (x) { return x.n; });
      var anyOn = srcs.some(function (s) { return S.on[s.id]; });
      html += '<div class="bs-empty">';
      if (!srcs.length) html += "<p><strong>Nothing to add.</strong></p><p>There are no tests to offer here yet.</p>";
      else if (!anyOn) html += "<p><strong>Every source is turned off.</strong></p><p>Turn on a source above to see its tests.</p>";
      else if (S.kind === "native" && facetsOn() && !off.length) html += "<p><strong>No tests match the filters</strong>" + (S.q ? " and &ldquo;" + esc(S.q) + "&rdquo;" : "") + ' in the sources you picked.</p><p><button type="button" class="bs-btn bs-ghost" id="bs-clear-f2">Clear filters</button></p>';
      else {
        html += "<p><strong>No tests match &ldquo;" + esc(S.q) + "&rdquo;</strong> in the sources you picked.</p>";
        if (off.length) html += "<p>" + off.map(function (x) { return x.n + " in " + esc(x.s.chip); }).join(", ") + ", which " + (off.length > 1 ? "are" : "is") + ' turned off. <button type="button" class="bs-btn bs-link" id="bs-all-src">Search every source</button></p>';
        else if (S.q) html += "<p>Try a shorter word, a test ref such as <code>benchy/x265</code>, or a platform such as <em>Windows</em>.</p>";
        if (S.q) html += '<p><button type="button" class="bs-btn bs-ghost" id="bs-clear-q">Clear the search</button></p>';
      }
      html += "</div>";
    }
    $("bs-results").innerHTML = html;
    var otherKind = S.kind === "native" ? "pts" : "native";
    var other = S.q.trim() ? items(otherKind).filter(function (t) { return matches(t, S.q); }) : [];
    var cross = $("bs-cross");
    cross.hidden = !other.length;
    if (other.length) {
      var names = other.slice(0, 3).map(function (t) { return "<code>" + esc(t.ref) + "</code>"; }).join(", ") + (other.length > 3 ? " and " + (other.length - 3) + " more" : "");
      var noun = otherKind === "native" ? "native test" : "Phoronix profile";
      cross.innerHTML = other.length + " " + noun + (other.length > 1 ? "s" : "") + " also match" + (other.length > 1 ? "" : "es") + ": " + names + ". " +
        (locked() || (otherKind === "native" && !D.nativeReady) ? "They go in a separate suite, because a suite runs on one engine." : '<button type="button" class="bs-btn bs-link" id="bs-switch">Build a ' + (otherKind === "native" ? "native" : "Phoronix") + " suite instead</button>");
    }
  }
  function memberErrors() {
    var errs = {}, seen = {};
    S.members.forEach(function (m, i) {
      var k = m.ref + "|" + JSON.stringify(m.opts, Object.keys(m.opts).sort());
      if (seen[k]) errs[m.id] = "Already in this suite with the same options (test " + seen[k] + "). A suite holds one version of each test setting, so change an option or remove one.";
      else seen[k] = i + 1;
    });
    return errs;
  }
  function stateOf(m) {
    for (var i = 0; i < D.updates.length; i++) if (D.updates[i].ref === m.ref && D.updates[i].version === m.version) return D.updates[i];
    return null;
  }
  function optControl(m, x, p) {
    var cur = m.opts[x.key] !== undefined ? m.opts[x.key] : "";
    if (x.kind === "choice" && x.choices.length) {
      return '<div><label for="' + p + "-" + esc(x.key) + '">' + esc(x.label) + '</label><select id="' + p + "-" + esc(x.key) + '" data-m="' + m.id + '" data-k="' + esc(x.key) + '">' +
        x.choices.map(function (c) { return '<option value="' + esc(c[0]) + '"' + (cur === c[0] ? " selected" : "") + ">" + esc(c[1]) + "</option>"; }).join("") + "</select></div>";
    }
    return '<div><label for="' + p + "-" + esc(x.key) + '">' + esc(x.label) + '</label><input type="text" maxlength="200" id="' + p + "-" + esc(x.key) + '" data-m="' + m.id + '" data-k="' + esc(x.key) + '" value="' + esc(cur) + '" /></div>';
  }
  function renderSuite() {
    if (S.kind !== "native") { renderPts(); return; }
    var errs = memberErrors(), box = $("bs-members");
    $("bs-suite-empty").hidden = S.members.length > 0;
    box.innerHTML = S.members.map(function (m, i) {
      var t = NI[m.ref] || { ref: m.ref, title: m.ref, src: "builtin", opts: [], versions: {}, order: [], ver: null, plat: [] }, p = "bs-m" + m.id, cfg = "", notes = [];
      if (t.src !== "builtin") {
        var vs = t.order.map(function (v, j) { return '<option value="' + esc(v) + '"' + (m.version === v ? " selected" : "") + ">" + esc(v) + (j === 0 ? " (newest, pinned)" : "") + (t.versions[v].min && t.versions[v].min !== "" ? "" : "") + "</option>"; }).join("");
        var gone = m.version && !t.versions[m.version] ? '<option value="' + esc(m.version) + '" selected disabled>' + esc(m.version) + " (not offered any more)</option>" : "";
        cfg += '<div><label for="' + p + '-ver">Version</label><select id="' + p + '-ver" data-m="' + m.id + '" data-k="__ver">' + gone + vs + '<option value=""' + (m.version ? "" : " selected") + ">Not pinned (each system runs its own newest)</option></select></div>";
        if (!m.version) notes.push("Not pinned: each system runs the newest version it has (now " + esc(t.ver) + " in the catalog). A new catalog release changes what this measures.");
        var st = stateOf(m);
        if (st && st.state === "withdrawn") notes.push('<span class="bs-m-err">' + esc(m.version) + " was withdrawn" + (st.why ? ": " + esc(st.why) : "") + ". Pick another version.</span>");
        else if (st && st.state === "dropped") notes.push('<span class="bs-m-err">' + esc(m.version) + " is no longer in this server's catalog. Pick another version.</span>");
        else if (m.version && t.versions[m.version] && t.ver !== m.version) notes.push(esc(t.ver) + " is available (this one stays at " + esc(m.version) + " until you update it).");
        if (m.version && t.versions[m.version] && t.versions[m.version].min && t.versions[m.version].min !== "0.6.0") notes.push("Needs engine " + esc(t.versions[m.version].min) + " or later; a system on an older engine holds the schedule.");
      } else cfg += '<div><span class="bs-muted">Version: each system runs its own</span></div>';
      var o = optsFor(t, m.version);
      o.forEach(function (x) { cfg += optControl(m, x, p); });
      if (!o.length) cfg += '<div><span class="bs-muted">No options</span></div>';
      var e = errs[m.id] || m.err;
      return '<div class="bs-member"><div class="bs-m-top"><span class="bs-m-num">' + (i + 1) + '</span><div class="bs-m-name"><strong>' + esc(t.title) + "</strong><code>" + esc(t.ref) + '</code></div><div class="bs-m-btns">' +
        '<button type="button" id="' + p + '-up" data-up="' + m.id + '" aria-label="Move ' + esc(t.title) + ' up"' + (i === 0 ? " disabled" : "") + ">Up</button>" +
        '<button type="button" id="' + p + '-rm" data-rm="' + m.id + '" aria-label="Remove ' + esc(t.title) + '">Remove</button></div></div><div class="bs-m-cfg">' + cfg + "</div>" +
        notes.map(function (n) { return '<p class="bs-m-note">' + n + "</p>"; }).join("") + (e ? '<p class="bs-m-err" role="alert">' + (errs[m.id] ? esc(e) : e) + "</p>" : "") + "</div>";
    }).join("");
    var ro = $("bs-runs-on");
    if (!S.members.length) ro.innerHTML = "";
    else ro.innerHTML = '<span class="bs-muted">Runs on:</span> <b>' + esc(joinNames(pnames(suitePlat(S.members)))) + '</b> <span class="bs-muted">systems with the native agent - ' + S.members.length + " test" + (S.members.length > 1 ? "s" : "") + "</span>";
    var why = "", title = $("bs-title").value.trim();
    if (!S.members.length) why = "Add at least one test.";
    else if (Object.keys(errs).length) why = "Fix the duplicate test first.";
    else if (!title) why = "Give the suite a title.";
    else if (!suitePlat(S.members).length) why = "These tests have no platform in common.";
    $("bs-save").disabled = !!why;
    $("bs-why").textContent = why;
    var html = "";
    if (D.error) html += '<p class="bs-callout bs-err" role="alert">' + esc(D.error) + "</p>";
    if (D.saved) html += '<p class="bs-callout bs-ok" role="status">' + D.saved + "</p>";
    if (D.revision) html += '<p class="bs-muted">Revision ' + D.revision + (D.usedBy.length ? ". Used by " + esc(D.usedBy.join(", ")) + "; a saved change applies to all of them." : ".") + "</p>";
    $("bs-save-msg").innerHTML = html;
    renderUpdates();
  }
  function renderUpdates() {
    var el = $("bs-updates");
    if (!D.updates.length || S.id === null) { el.innerHTML = ""; return; }
    var parts = ['<h3>Updates</h3><p class="bs-muted">Save your changes first; these act on the saved suite.</p>'], any = 0;
    D.updates.forEach(function (u) {
      var t = NI[u.ref], title = t ? t.title : u.ref;
      if (u.state === "superseded") {
        any++;
        parts.push('<form method="post" action="' + esc(D.action) + '" class="bs-upd"><input type="hidden" name="token_submit" value="' + esc(D.updateToken) + '" /><input type="hidden" name="native_suite_update" value="1" /><input type="hidden" name="ns_id" value="' + S.id + '" /><input type="hidden" name="ns_member" value="' + u.pos + '" />' +
          esc(title) + " " + esc(u.version) + ": " + esc(u.newest) + ' is available. <input type="submit" class="bs-btn" value="Update to ' + esc(u.newest) + '" /></form>');
      } else parts.push("<p class=\"bs-m-err\">" + esc(title) + " " + esc(u.version) + (u.state === "withdrawn" ? " was withdrawn" + (u.why ? ": " + esc(u.why) : "") : " is no longer in this server's catalog") + ". Pick another version.</p>");
    });
    if (any > 1) parts.push('<form method="post" action="' + esc(D.action) + '"><input type="hidden" name="token_submit" value="' + esc(D.updateToken) + '" /><input type="hidden" name="native_suite_update" value="1" /><input type="hidden" name="ns_id" value="' + S.id + '" /><input type="submit" class="bs-btn bs-ghost" value="Update all" /></form>');
    el.innerHTML = parts.join("");
  }
  function renderPts() {
    var n = ptsCount();
    $("bs-pts-empty").hidden = n > 0;
    $("bs-runs-on-pts").innerHTML = n ? '<span class="bs-muted">Runs on:</span> <b>systems with the Phoronix engine</b>' : "";
  }
  function render() { renderKind(); renderChips(); renderFacets(); renderResults(); renderSuite(); }

  /* ---------- actions ---------- */
  function tryAdd(ref) {
    if (S.kind === "pts") {
      phoromatic_ajax_append_element("r_add_test_build_suite_details/&tp=" + encodeURIComponent(ref), "test_details");
      return;
    }
    var t = NI[ref];
    D.saved = null; D.error = null;
    if (!t || t.block) return;
    var cand = newMember(t.ref, t.ver, defaults(optsFor(t, t.ver))); cand.joining = true; S.uid--;
    if (S.members.length) {
      var bad = tryPlat(cand, S.members, t);
      if (bad) { S.refusal = { ref: ref, html: bad }; render(); return; }
    }
    S.refusal = null;
    addMember(t);
    render();
  }
  function setKind(k) {
    if (k === S.kind) return;
    if (locked() || (k === "native" && !D.nativeReady)) { renderKind(); return; }
    S.kind = k; S.refusal = null; D.saved = null;
    render();
  }
  document.addEventListener("click", function (e) {
    var b = e.target.closest ? e.target.closest("#bs button") : null;
    if (!b) return;
    if (b.dataset.add) { tryAdd(b.dataset.add); return; }
    if (b.dataset.src) { S.on[b.dataset.src] = !S.on[b.dataset.src]; renderChips(); renderResults(); return; }
    if (b.dataset.f) {
      if (b.dataset.f === "admin") S.f.admin = !S.f.admin; else S.f[b.dataset.f][b.dataset.k] = !S.f[b.dataset.f][b.dataset.k];
      renderChips(); renderFacets(); renderResults(); return;
    }
    if (b.id === "bs-clear-f" || b.id === "bs-clear-f2") { S.f = { tag: {}, plat: {}, len: {}, admin: false }; renderChips(); renderFacets(); renderResults(); return; }
    if (b.dataset.rm) {
      var i = S.members.findIndex(function (m) { return m.id === +b.dataset.rm; });
      S.members.splice(i, 1); S.refusal = null; D.saved = null; render();
      var next = S.members[Math.min(i, S.members.length - 1)], f = $(next ? "bs-m" + next.id + "-rm" : "bs-q"); if (f) f.focus();
      return;
    }
    if (b.dataset.up) {
      var j = S.members.findIndex(function (m) { return m.id === +b.dataset.up; });
      if (j > 0) { var x = S.members[j]; S.members[j] = S.members[j - 1]; S.members[j - 1] = x; }
      render(); var u = $("bs-m" + b.dataset.up + "-up"); if (u && !u.disabled) u.focus(); else { var r = $("bs-m" + b.dataset.up + "-rm"); if (r) r.focus(); }
      return;
    }
    if (b.id === "bs-clear-q") { S.q = ""; $("bs-q").value = ""; render(); $("bs-q").focus(); return; }
    if (b.id === "bs-all-src") { sources(S.kind).forEach(function (s) { S.on[s.id] = true; }); render(); return; }
    if (b.id === "bs-switch") { setKind(S.kind === "native" ? "pts" : "native"); return; }
  });
  document.addEventListener("change", function (e) {
    var el = e.target;
    if (el.name === "bs_kind") { setKind(el.value); return; }
    if (el.dataset && el.dataset.m) {
      var m = S.members.filter(function (x) { return x.id === +el.dataset.m; })[0], t = NI[m.ref];
      D.saved = null; D.error = null; m.err = null;
      if (el.dataset.k === "__ver") {
        var nv = el.value === "" ? null : el.value;
        var cand = { id: m.id, ref: m.ref, version: nv, opts: m.opts };
        var others = S.members.filter(function (x) { return x !== m; });
        var bad = others.length ? tryPlat(cand, others, t) : "";
        if (bad) m.err = bad;
        else {
          var o = optsFor(t, nv), kept = {}, d = defaults(o), reset = [];
          o.forEach(function (x) {
            var ok = m.opts[x.key] !== undefined && (x.kind !== "choice" || x.choices.some(function (c) { return c[0] === m.opts[x.key]; }));
            kept[x.key] = ok ? m.opts[x.key] : d[x.key]; if (!ok) reset.push(x.label);
          });
          m.version = nv; m.opts = kept;
          if (reset.length) m.err = null;
        }
      } else m.opts[el.dataset.k] = el.value;
      var id = el.id; renderSuite(); var again = $(id); if (again) again.focus();
    }
  });
  document.addEventListener("input", function (e) {
    var el = e.target;
    if (el.id === "bs-q") { S.q = el.value; S.refusal = null; renderChips(); renderResults(); return; }
    if (el.id === "bs-title") { D.saved = null; D.error = null; renderSuite(); return; }
    if (el.dataset && el.dataset.m && el.tagName === "INPUT") {
      var m = S.members.filter(function (x) { return x.id === +el.dataset.m; })[0];
      m.opts[el.dataset.k] = el.value; D.saved = null; renderSuiteKeepFocus(el.id);
    }
  });
  function renderSuiteKeepFocus(id) {
    var el = $(id), pos = el ? el.selectionStart : 0;
    renderSuite(); var again = $(id); if (again) { again.focus(); try { again.setSelectionRange(pos, pos); } catch (x) { } }
  }
  $("bs-native-form").addEventListener("submit", function (e) {
    if ($("bs-save").disabled) { e.preventDefault(); return; }
    var box = $("bs-native-fields"); box.innerHTML = "";
    function hid(name, value) { var i = document.createElement("input"); i.type = "hidden"; i.name = name; i.value = value; box.appendChild(i); }
    if (S.id !== null) hid("ns_id", String(S.id));
    hid("ns_title", $("bs-title").value.trim());
    hid("ns_description", $("bs-desc").value);
    S.members.forEach(function (m, i) {
      hid("m[" + i + "][ref]", m.ref);
      hid("m[" + i + "][version]", m.version || "");
      Object.keys(m.opts).forEach(function (k) { hid("m[" + i + "][opt][" + k + "]", m.opts[k]); });
    });
  });
  var pd = $("test_details");
  if (window.MutationObserver) new MutationObserver(function () { renderKind(); renderChips(); renderResults(); renderPts(); }).observe(pd, { childList: true, subtree: true });
  $("bs-pane-pts").addEventListener("submit", function (e) {
    var t = $("suite_title").value;
    if (!ptsCount() || !/^[A-Za-z0-9-]{3,}$/.test(t)) {
      e.preventDefault();
      alert(!ptsCount() ? "You must add at least one test to the suite." : "Suite title must be at least three characters and contain just letters, numbers and dashes.");
    }
  });
  render();
})();
JS;
	}
}

?>
