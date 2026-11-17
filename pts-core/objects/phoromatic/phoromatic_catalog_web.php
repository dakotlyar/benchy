<?php

/*
	Benchy
	phoromatic_catalog_web.php: the signed test catalog on the web (E4.4 C5, server design 8.1 and 3.5): the "Test catalog" page of server
	administrators (?server_catalog) and the same section on ?admin_config for the root login. The upload goes through
	phoromatic_catalog_import::run() like the command line does (via "web"); the page only reads what the C2 accessors hold.

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

class phoromatic_catalog_web
{
	const NAMES = array('public' => 'Free', 'private' => 'Non-free');
	const EVENTS = 20;

	protected static function h($text)
	{
		return htmlspecialchars((string)$text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}
	protected static function note($text, $bad = false)
	{
		return '<p' . ($bad ? ' style="color: #b00020;"' : '') . '><strong>' . self::h($text) . '</strong></p>';
	}

	// ---- the upload ----

	// The text of a POST to this page, or ''. Everything the import itself refuses ends up in the audit (via web); the problems before it (no file, a file
	// PHP dropped) never reached the verifier and say so here.
	public static function process_post()
	{
		if(!phoromatic_auth::is_server_admin() || $_SERVER['REQUEST_METHOD'] !== 'POST')
		{
			return '';
		}
		$limit = phoromatic_private_repo_web::ini_bytes(ini_get('post_max_size'));
		if(empty($_POST) && empty($_FILES) && !empty($_SERVER['CONTENT_LENGTH']) && isset($_SERVER['CONTENT_TYPE']) && stripos($_SERVER['CONTENT_TYPE'], 'multipart/form-data') === 0 && $limit > 0 && (int)$_SERVER['CONTENT_LENGTH'] > $limit)
		{
			// PHP throws away a request over post_max_size, token included
			return self::note('That file is larger than this page accepts (' . ini_get('post_max_size') . '). Nothing was changed. Use the command on this page, on the server.', true);
		}
		if(!isset($_POST['catalog_import']))
		{
			return '';
		}
		if(!phoromatic_private_repo_web::origin_ok() || !verify_submission_token())
		{
			return self::note('The form expired or was not submitted from this site; nothing was changed. Please try again.', true);
		}
		$f = isset($_FILES['catalog_bundle']) && is_array($_FILES['catalog_bundle']) ? $_FILES['catalog_bundle'] : null;
		if($f === null || !isset($f['error'], $f['tmp_name']) || is_array($f['error']) || !is_string($f['tmp_name']) || $f['error'] === UPLOAD_ERR_NO_FILE)
		{
			return self::note('Choose a catalog bundle (a .zip file) to import.', true);
		}
		if($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE)
		{
			return self::note('That file is larger than this page accepts (' . ini_get('upload_max_filesize') . '). Nothing was changed. Use the command on this page, on the server.', true);
		}
		if($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name']))
		{
			return self::note('The file did not arrive whole. Nothing was changed. Please try again.', true);
		}
		list($account, $type, $actor, $detail) = phoromatic_auth::audit_actor();
		try
		{
			return self::note(phoromatic_catalog_status::import_text(phoromatic_catalog_import::run($f['tmp_name'], 'web', $type, $actor, $detail, $type === 'root' ? null : $account)));
		}
		catch(phoromatic_catalog_refusal $e)
		{
			return self::note('Refused (' . $e->reason . '): ' . $e->getMessage() . ' Nothing was changed.', true);
		}
	}

	// ---- the section ----

	// $page is the ?page the form posts back to (server_catalog, or admin_config for the root login); $message is what process_post() returned
	public static function render_section($page, $message = '')
	{
		if(!phoromatic_auth::is_server_admin())
		{
			return '';
		}
		$out = '<hr /><h2 id="test-catalog">Test catalog</h2>
<p>These signed tests are shared by every account on this server. Agents fetch them from here and check the publisher\'s signature themselves, so this server can\'t change them.</p>';
		if(!phoromatic_catalog::ready())
		{
			return $out . self::note('The catalog is not set up on this server yet. Restart the server once on this version.', true);
		}
		$out .= phoromatic_catalog::notices_html() . $message;
		$state = phoromatic_catalog::state_row();
		$rows = phoromatic_catalog::tier_rows();
		$current = 0;
		try
		{
			$trust = phoromatic_catalog_keys::load();
			foreach($trust->all_keys() as $k)
			{
				$current = max($current, (int)$k['generation']);
			}
		}
		catch(phoromatic_catalog_refusal $e)
		{
			$trust = null;
		}
		$out .= '<div style="overflow-x: auto;"><table><tr><th>Tier</th><th>Status</th><th>Release</th><th>Signed by</th><th>Issued</th><th>Tests</th><th>Suites</th><th>Withdrawn</th></tr>';
		foreach(self::NAMES as $tier => $name)
		{
			if(!isset($rows[$tier]))
			{
				$out .= '<tr><td>' . $name . '</td><td>Not imported</td><td colspan="6"></td></tr>';
				continue;
			}
			$r = $rows[$tier];
			$s = phoromatic_catalog::tier_status($tier, $trust, $rows, (int)$state['RetiredBelow']);
			$why = array('denied_key' => 'Not served: its key is no longer trusted', 'retired_key' => 'Not served: its key is retired', 'file_missing' => 'Not served: its file is missing or damaged', 'key_table' => 'Not served: the key table is damaged');
			$out .= '<tr><td>' . $name . '</td><td>' . ($s['served'] ? 'Served' : '<span style="color: #b00020;">' . self::h($why[$s['why']]) . '</span>') . '</td><td>generation ' . (int)$r['Generation'] . ', ' . (int)$r['Sequence'] .
				'</td><td>' . ((int)$r['Generation'] === $current ? 'current key' : 'an older key') . ' (generation ' . (int)$r['Generation'] . ')</td><td>' . self::h(substr($r['Issued'], 0, 10)) . '</td><td>' . (int)$r['TestCount'] . '</td><td>' . (int)$r['SuiteCount'] . '</td><td>' . (int)$r['RevokedCount'] . '</td></tr>';
		}
		$out .= '</table></div><p>Retired keys: ' . ((int)$state['RetiredBelow'] > 1 ? 'generations below ' . (int)$state['RetiredBelow'] : 'none') . '</p>';

		$limit = ini_get('post_max_size');
		$out .= '<form action="?' . self::h($page) . '" method="post" enctype="multipart/form-data">' . write_token_in_form() . '<input type="hidden" name="catalog_import" value="1" />
<p><strong>Import a catalog bundle</strong> <input type="file" name="catalog_bundle" accept=".zip" /> <input type="submit" value="Import" /></p>
<p>Bigger than this page accepts (' . self::h($limit) . ')? On the server, run: <code>sudo -u ' . self::h(self::service_user()) . ' benchy server-catalog-import /path/to/bundle.zip</code></p></form>';

		foreach(self::NAMES as $tier => $name)
		{
			$out .= '<h3>' . $name . ' tests</h3>';
			$entries = isset($rows[$tier]) ? phoromatic_catalog::entries($tier, 'test') : array();
			if(empty($entries))
			{
				$out .= '<p>None.</p>';
				continue;
			}
			$out .= '<div style="overflow-x: auto;"><table><tr><th>Test</th><th>Version</th><th>Platforms</th><th>Notes</th></tr>';
			foreach($entries as $e)
			{
				$platforms = json_decode((string)$e['Platforms'], true);
				$notes = array();
				if($e['MachineInstall'] !== null && $e['MachineInstall'] !== '')
				{
					$notes[] = 'Installs ' . phoromatic_catalog_sched::clean($e['MachineInstall'], 80) . ' for all users';
				}
				if((int)$e['ElevatedRun'] == 1)
				{
					$notes[] = 'Runs as administrator (runs on systems set up for administrator tests; see each system\'s page)';
				}
				$out .= '<tr><td>' . self::h(phoromatic_catalog_sched::clean($e['ID'])) . '</td><td>' . self::h(phoromatic_catalog_sched::clean($e['Version'], 40)) . '</td><td>' .
					self::h(is_array($platforms) ? implode(', ', array_map(function($p) { return phoromatic_catalog_sched::clean($p, 40); }, $platforms)) : '') . '</td><td>' . self::h(implode('; ', $notes)) . '</td></tr>';
			}
			$out .= '</table></div>';
		}
		$suites = array();
		foreach(array_keys(self::NAMES) as $tier)
		{
			foreach(isset($rows[$tier]) ? phoromatic_catalog::entries($tier, 'suite') : array() as $e)
			{
				$suites[] = self::h(phoromatic_catalog_sched::clean($e['ID'])) . ' ' . self::h(phoromatic_catalog_sched::clean($e['Version'], 40)) . ' (' . strtolower(self::NAMES[$tier]) . ')';
			}
		}
		if(!empty($suites))
		{
			$out .= '<h3>Suites</h3><p>' . implode('<br />', $suites) . '</p>';
		}
		$withdrawn = array();
		foreach(self::NAMES as $tier => $name)
		{
			foreach(isset($rows[$tier]) ? phoromatic_catalog::revoked($tier) : array() as $v)
			{
				$withdrawn[] = self::h(str_replace('@', ' ', phoromatic_catalog_sched::clean($v['Ref']))) . ' (' . strtolower($name) . '): ' . self::h(phoromatic_catalog_sched::clean($v['Reason']));
			}
		}
		$out .= '<h3>Withdrawn</h3>' . (empty($withdrawn) ? '<p>None.</p>' : '<p>' . implode('<br />', $withdrawn) . '</p>');
		$out .= '<h3>Recent catalog events</h3>';
		$events = array();
		foreach(phoromatic_server_audit::catalog_rows(self::EVENTS) as $row)
		{
			$events[] = '<tr><td>' . self::h($row['EventTime']) . '</td><td>' . self::h(self::event_text($row)) . '</td></tr>';
		}
		return $out . (empty($events) ? '<p>None yet.</p>' : '<div style="overflow-x: auto;"><table>' . implode('', $events) . '</table></div>') . '<p>Everything is in the <a href="?server_audit">server audit</a>.</p>';
	}
	public static function service_user()
	{
		$u = function_exists('posix_geteuid') && function_exists('posix_getpwuid') ? posix_getpwuid(posix_geteuid()) : false;
		return is_array($u) && !empty($u['name']) && preg_match('/^[a-z_][a-z0-9_-]*$/i', $u['name']) ? $u['name'] : 'phoromatic';
	}
	// One audit row in words
	public static function event_text($row)
	{
		$d = json_decode((string)$row['Detail'], true);
		$d = is_array($d) ? $d : array();
		$via = isset($d['via']) ? (string)$d['via'] : '';
		$who = $row['ActorType'] === 'root' ? 'the root login' : ($row['ActorType'] === 'system' ? ($via === 'package' ? 'the bundle that came with Benchy' : 'the server') : (string)$row['Actor']);
		$who .= $via !== '' && $via !== 'package' ? ' (' . $via . ')' : '';
		$tier = isset(self::NAMES[$row['SubjectID']]) ? strtolower(self::NAMES[$row['SubjectID']]) : '';
		if($row['Action'] === 'catalog_import_refused')
		{
			return $who . ' tried to import ' . ($tier !== '' ? 'the ' . $tier . ' catalog' : 'a bundle') . ' and was refused (' . (isset($d['reason']) ? $d['reason'] : 'unknown') . '): ' . (isset($d['message']) ? $d['message'] : '');
		}
		$pair = 'generation ' . (isset($d['generation']) ? (int)$d['generation'] : '?') . ' sequence ' . (isset($d['sequence']) ? (int)$d['sequence'] : '?');
		if(!empty($d['unchanged']))
		{
			$fixed = (isset($d['restored']['index']) ? (int)$d['restored']['index'] : 0) + (isset($d['restored']['packs']) ? (int)$d['restored']['packs'] : 0);
			return $who . ' imported the ' . $tier . ' catalog again, ' . $pair . ': ' . ($fixed > 0 ? 'missing files were put back' : 'nothing changed');
		}
		$from = isset($d['from']) && is_array($d['from']) && count($d['from']) == 2 ? ' (was ' . (int)$d['from'][0] . ' ' . (int)$d['from'][1] . ')' : '';
		$n = isset($d['tests_added']) ? (int)$d['tests_added'] : 0;
		$w = isset($d['revoked_added']) ? (int)$d['revoked_added'] : 0;
		return $who . ' imported the ' . $tier . ' catalog, ' . $pair . $from . ': ' . $n . ' test' . ($n == 1 ? '' : 's') . ' added, ' . $w . ' withdrawn';
	}
}

?>
