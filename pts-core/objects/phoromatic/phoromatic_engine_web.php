<?php

/*
	Benchy
	phoromatic_engine_web.php: the "Engine updates" section of the Test catalog page (engine self-update, U1)

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

// Server administrators and the root login only, like the catalog: engines are server-level, every account sees the same state. The upload goes
// through phoromatic_engine_store::import(), which checks everything before it stores anything.

class phoromatic_engine_web
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

	// The text of an engine upload POST (and kept for render_section), or ''
	public static function process_post()
	{
		if(!phoromatic_auth::is_server_admin() || $_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['engine_import']))
		{
			return '';
		}
		if(!phoromatic_private_repo_web::origin_ok() || !verify_submission_token())
		{
			return self::$message = self::note('The form expired or was not submitted from this site; nothing was changed. Please try again.', true);
		}
		$f = isset($_FILES['engine_bundle']) && is_array($_FILES['engine_bundle']) ? $_FILES['engine_bundle'] : null;
		if($f === null || !isset($f['error'], $f['tmp_name']) || is_array($f['error']) || !is_string($f['tmp_name']) || $f['error'] === UPLOAD_ERR_NO_FILE)
		{
			return self::$message = self::note('Choose an engine bundle (a .zip file) to import.', true);
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
			return self::$message = self::note(phoromatic_engine_store::import_text(phoromatic_engine_store::import($f['tmp_name'], 'web', $type, $actor, $detail, $type === 'root' ? null : $account)));
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
		$out = '<hr /><h2 id="engine-updates">Engine updates</h2>
<p>The signed Benchy engine this server offers to the machines paired with it. Each machine checks the publisher\'s signature itself, so this server can\'t change an engine. Machines are offered it on their Engines page; nothing is installed while a run is going.</p>' . self::$message;
		$c = phoromatic_engine_store::current($why);
		if($c === null)
		{
			$words = array('none' => 'No engine has been imported yet.', 'file_missing' => 'The stored engine is missing a file or does not match its record, so nothing is offered. Import the bundle again.', 'state_corrupt' => 'The stored engine record is damaged, so nothing is offered. Import the bundle again.',
				'denied_key' => 'The stored engine\'s key is no longer trusted, so nothing is offered.', 'retired_key' => 'The stored engine\'s key is retired, so nothing is offered.', 'key_table' => 'The engine key table is damaged, so nothing is offered.');
			$out .= self::note(isset($words[$why]) ? $words[$why] : 'The stored engine does not verify (' . $why . '), so nothing is offered.', $why !== 'none');
		}
		else
		{
			$out .= '<div style="overflow-x: auto;"><table><tr><th>Engine</th><th>Status</th><th>Platforms</th><th>Signed by</th><th>Issued</th><th>Imported</th></tr><tr><td>' . self::h($c['version']) . '</td><td>Offered</td><td>' .
				self::h(implode(', ', array_keys($c['artifacts']))) . '</td><td>' . phoromatic_catalog_web::signed_by($c['generation'], $c['retired_below']) . ', sequence ' . (int)$c['sequence'] . '</td><td>' . self::h(substr($c['issued'], 0, 10)) .
				'</td><td>' . self::h(substr((string)$c['imported_on'], 0, 16)) . '</td></tr></table></div>';
			if($c['notes'] !== '')
			{
				$out .= '<p>' . nl2br(self::h($c['notes'])) . '</p>';
			}
			if(!empty($c['revoked']))
			{
				$r = array();
				foreach($c['revoked'] as $v)
				{
					$r[] = self::h($v['version']) . ': ' . self::h($v['reason']);
				}
				$out .= '<p>Withdrawn: ' . implode('; ', $r) . '</p>';
			}
		}
		$out .= '<form action="?' . self::h($page) . '" method="post" enctype="multipart/form-data">' . write_token_in_form() . '<input type="hidden" name="engine_import" value="1" />
<p><strong>Import an engine bundle</strong> <input type="file" name="engine_bundle" accept=".zip" /> <input type="submit" value="Import" /></p>
<p>Bigger than this page accepts (' . self::h(ini_get('post_max_size')) . ')? On the server, run: <code>' . self::h(phoromatic_tls::as_service_user('benchy server-engine-import /path/to/benchy-engine-bundle.zip')) . '</code></p></form>';
		$events = array();
		foreach(phoromatic_server_audit::engine_rows(10) as $row)
		{
			$events[] = '<tr><td>' . self::h($row['EventTime']) . '</td><td>' . self::h(self::event_text($row)) . '</td></tr>';
		}
		return $out . (empty($events) ? '' : '<h3>Recent engine events</h3><div style="overflow-x: auto;"><table>' . implode('', $events) . '</table></div>');
	}

	public static function event_text($row)
	{
		$d = json_decode((string)$row['Detail'], true);
		$d = is_array($d) ? $d : array();
		$who = $row['ActorType'] === 'root' ? 'the root login' : (string)$row['Actor'];
		$who .= isset($d['via']) ? ' (' . $d['via'] . ')' : '';
		if($row['Action'] === 'engine_import_refused')
		{
			return $who . ' tried to import an engine bundle and was refused (' . (isset($d['reason']) ? $d['reason'] : 'unknown') . '): ' . (isset($d['message']) ? $d['message'] : '');
		}
		return $who . ' imported engine ' . (isset($d['version']) ? $d['version'] : '?') . (!empty($d['unchanged']) ? ' again' : '') . ', key generation ' . (isset($d['generation']) ? (int)$d['generation'] : '?') . ' sequence ' . (isset($d['sequence']) ? (int)$d['sequence'] : '?');
	}
}

?>
