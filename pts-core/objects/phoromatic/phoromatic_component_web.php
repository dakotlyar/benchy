<?php

/*
	Benchy
	phoromatic_component_web.php: the runtime-component rows of the Updates section of the Test catalog page (runtime component updates, R1)

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

// Server administrators and the root login only, like the engine section above it. The upload goes through phoromatic_component_store::import(),
// which checks everything before it stores anything. The rows come from phoromatic_component_store::rows(), so the page shows only what verifies now.

class phoromatic_component_web
{
	public static $message = '';

	protected static function h($text)
	{
		return htmlspecialchars((string)$text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}
	protected static function note($text, $bad = false)
	{
		return '<p' . ($bad ? ' style="color: #b00020;"' : '') . '><strong>' . self::h($text) . '</strong></p>';
	}

	public static function process_post()
	{
		if(!phoromatic_auth::is_server_admin() || $_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['components_import']))
		{
			return '';
		}
		if(!phoromatic_private_repo_web::origin_ok() || !verify_submission_token())
		{
			return self::$message = self::note('The form expired or was not submitted from this site; nothing was changed. Please try again.', true);
		}
		$f = isset($_FILES['components_bundle']) && is_array($_FILES['components_bundle']) ? $_FILES['components_bundle'] : null;
		if($f === null || !isset($f['error'], $f['tmp_name']) || is_array($f['error']) || !is_string($f['tmp_name']) || $f['error'] === UPLOAD_ERR_NO_FILE)
		{
			return self::$message = self::note('Choose a component bundle (a .zip file) to import.', true);
		}
		if($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE)
		{
			return self::$message = self::note('That file is larger than this page accepts (' . ini_get('upload_max_filesize') . '). Nothing was changed. Use the command on this page, on the server.', true);
		}
		if($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name']))
		{
			return self::$message = self::note('The file did not arrive whole. Nothing was changed. Please try again.', true);
		}
		list($account, $type, $actor, $detail) = phoromatic_auth::audit_actor();
		$detail = $type === 'root' ? $detail : $detail . ', account ' . $account;
		try
		{
			return self::$message = self::note(phoromatic_component_store::import_text(phoromatic_component_store::import($f['tmp_name'], 'web', $type, $actor, $detail, $type === 'root' ? null : $account)));
		}
		catch(phoromatic_catalog_refusal $e)
		{
			return self::$message = self::note('Refused (' . $e->reason . '): ' . $e->getMessage() . ' Nothing was changed.', true);
		}
	}

	public static function render_section($page)
	{
		if(!phoromatic_auth::is_server_admin())
		{
			return '';
		}
		$out = '<hr /><h2 id="component-updates">Runtime component updates</h2>
<p>The signed PHP, Benchy tree, desktop client, Cygwin set and VC++ runtime this server offers to the Windows machines paired with it. Each machine checks the publisher\'s signature itself, so this server can\'t change a component. Nothing is installed while a run is going.</p>' . self::$message;
		$c = phoromatic_component_store::current($why);
		if($c === null)
		{
			$words = array('none' => 'No components have been imported yet.', 'file_missing' => 'The stored components are missing a file or do not match their record, so nothing is offered. Import the bundle again.', 'state_corrupt' => 'The stored component record is damaged, so nothing is offered. Import the bundle again.',
				'denied_key' => 'The stored components\' key is no longer trusted, so nothing is offered.', 'retired_key' => 'The stored components\' key is retired, so nothing is offered.', 'key_table' => 'The key table is damaged, so nothing is offered.');
			$out .= self::note(isset($words[$why]) ? $words[$why] : 'The stored components do not verify (' . $why . '), so nothing is offered.', $why !== 'none');
		}
		else
		{
			$rows = array();
			foreach(phoromatic_component_store::rows() as $r)
			{
				foreach($r['tracks'] as $tn => $t)
				{
					$req = array();
					foreach($t['requires'] as $k => $v)
					{
						$req[] = self::h($k . ' ' . $v);
					}
					$rows[] = '<tr><td>' . self::h($r['name']) . '</td><td>' . self::h($tn === '' ? '' : $tn . ($tn === $r['default_track'] && count($r['tracks']) > 1 ? ' (default)' : '')) . '</td><td>' . self::h($t['version']) . '</td><td>Offered</td><td>' .
						round($t['size'] / 1048576, 1) . ' MB</td><td>' . implode(', ', $req) . '</td><td>' . self::h(substr($t['notes'], 0, 200)) . '</td></tr>';
				}
			}
			$out .= '<div style="overflow-x: auto;"><table><tr><th>Component</th><th>Track</th><th>Version</th><th>Status</th><th>Size</th><th>Needs</th><th>Notes</th></tr>' . implode('', $rows) . '</table></div><p>Signed by ' .
				phoromatic_catalog_web::signed_by($c['generation'], $c['retired_below']) . ', sequence ' . (int)$c['sequence'] . ', issued ' . self::h(substr($c['issued'], 0, 10)) . ', imported ' . self::h(substr((string)$c['imported_on'], 0, 16)) . '.</p>';
			$withdrawn = array();
			foreach(phoromatic_component_store::rows() as $r)
			{
				foreach($r['revoked'] as $v)
				{
					$withdrawn[] = self::h($r['name'] . ' ' . $v);
				}
			}
			if(!empty($withdrawn))
			{
				$out .= '<p>Withdrawn: ' . implode(', ', $withdrawn) . '</p>';
			}
		}
		$out .= '<form action="?' . self::h($page) . '" method="post" enctype="multipart/form-data">' . write_token_in_form() . '<input type="hidden" name="components_import" value="1" />
<p><strong>Import a component bundle</strong> <input type="file" name="components_bundle" accept=".zip" /> <input type="submit" value="Import" /></p>
<p>Bigger than this page accepts (' . self::h(ini_get('post_max_size')) . ')? On the server, run: <code>' . self::h(phoromatic_tls::as_service_user('benchy server-component-import /path/to/benchy-components-bundle.zip')) . '</code></p></form>';
		$events = array();
		foreach(phoromatic_server_audit::component_rows(10) as $row)
		{
			$events[] = '<tr><td>' . self::h($row['EventTime']) . '</td><td>' . self::h(self::event_text($row)) . '</td></tr>';
		}
		return $out . (empty($events) ? '' : '<h3>Recent component events</h3><div style="overflow-x: auto;"><table>' . implode('', $events) . '</table></div>');
	}

	public static function event_text($row)
	{
		$d = json_decode((string)$row['Detail'], true);
		$d = is_array($d) ? $d : array();
		$who = $row['ActorType'] === 'root' ? 'the root login' : (string)$row['Actor'];
		$who .= isset($d['via']) ? ' (' . $d['via'] . ')' : '';
		if($row['Action'] === 'components_import_refused')
		{
			return $who . ' tried to import a component bundle and was refused (' . (isset($d['reason']) ? $d['reason'] : 'unknown') . '): ' . (isset($d['message']) ? $d['message'] : '');
		}
		return $who . ' imported components' . (!empty($d['unchanged']) ? ' again' : '') . ' (' . (isset($d['changed']) && is_array($d['changed']) && $d['changed'] ? implode(', ', $d['changed']) : 'nothing new') . '), key generation ' . (isset($d['generation']) ? (int)$d['generation'] : '?') . ' sequence ' . (isset($d['sequence']) ? (int)$d['sequence'] : '?');
	}
}

?>
