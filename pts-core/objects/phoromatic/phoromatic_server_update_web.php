<?php

/*
	Benchy
	phoromatic_server_update_web.php: the "Server updates" section of ?server_settings and ?admin_config, and its actions (0.16.2, U2; the endpoint is phoromatic_r_server_update)

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

// Server administrators and the root login only. This process never installs anything and never gets root: pressing Update writes a request file that the
// root helper (benchy-server-update) checks again from scratch. Every form carries the CSRF token and goes through origin_ok(); Update also needs the
// signed-in person's password entered again.

class phoromatic_server_update_web
{
	const PAGES = array('server_settings', 'admin_config');
	const BUSY_MINUTES = 15;

	protected static function h($text)
	{
		return htmlspecialchars((string)$text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}

	// The person's password, checked again for this one action. The root login has its own.
	public static function reauth($password)
	{
		if(!is_string($password) || $password === '' || strlen($password) > 1024)
		{
			return false;
		}
		if(phoromatic_server_console::is_root())
		{
			return phoromatic_passwords::check_root($password) ? true : false;
		}
		if(!isset($_SESSION['UserID']) || phoromatic_server::$db == null)
		{
			return false;
		}
		$stmt = phoromatic_server::$db->prepare('SELECT UserID, Password, AccountID' . phoromatic_passwords::select_columns() . ' FROM phoromatic_users WHERE UserID = :user_id');
		$stmt->bindValue(':user_id', (string)$_SESSION['UserID']);
		$result = $stmt->execute();
		$row = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;
		if(empty($row))
		{
			return false;
		}
		$stmt = phoromatic_server::$db->prepare('SELECT Salt FROM phoromatic_accounts WHERE AccountID = :account_id');
		$stmt->bindValue(':account_id', (string)$row['AccountID']);
		$result = $stmt->execute();
		$salt = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;
		return phoromatic_passwords::check_user($row, $salt ? $salt['Salt'] : null, $password) ? true : false;
	}

	public static function flash($ok, $message)
	{
		$_SESSION['ServerUpdateFlash'] = array('ok' => (bool)$ok, 'message' => (string)$message);
	}
	public static function take_flash()
	{
		$f = isset($_SESSION['ServerUpdateFlash']) && is_array($_SESSION['ServerUpdateFlash']) ? $_SESSION['ServerUpdateFlash'] : null;
		unset($_SESSION['ServerUpdateFlash']);
		return $f === null ? '' : '<p' . ($f['ok'] ? '' : ' style="color: #b00020;"') . '><strong>' . self::h($f['message']) . '</strong></p>';
	}

	// Systems that are running a test and have checked in within the last BUSY_MINUTES minutes: array of titles
	public static function busy_systems()
	{
		$out = array();
		if(phoromatic_server::$db == null)
		{
			return $out;
		}
		$result = @phoromatic_server::$db->query('SELECT Title, CurrentTask, LastCommunication FROM phoromatic_systems WHERE State >= 0');
		while($result && ($row = $result->fetchArray(SQLITE3_ASSOC)))
		{
			$when = strtotime((string)$row['LastCommunication']);
			if(stripos((string)$row['CurrentTask'], 'running') !== false && stripos((string)$row['CurrentTask'], 'idling') === false && $when !== false && $when > time() - self::BUSY_MINUTES * 60)
			{
				$out[] = (string)$row['Title'];
			}
		}
		return $out;
	}

	// The three POSTs and the status read. $action: upload, check_github, update. Returns array(ok bool, message)
	public static function act($action)
	{
		list($account, $type, $actor, $detail) = phoromatic_auth::audit_actor();
		$detail = $type === 'root' ? $detail : $detail . ', account ' . $account;
		try
		{
			if($action === 'upload')
			{
				$f = isset($_FILES['server_update_bundle']) && is_array($_FILES['server_update_bundle']) ? $_FILES['server_update_bundle'] : null;
				if($f === null || !isset($f['error'], $f['tmp_name']) || is_array($f['error']) || !is_string($f['tmp_name']) || $f['error'] === UPLOAD_ERR_NO_FILE)
				{
					return array(false, 'Choose a server update bundle (a .zip file) to upload.');
				}
				if($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE)
				{
					return array(false, 'That file is larger than this page accepts (' . ini_get('upload_max_filesize') . '). Nothing was changed. Use the command on this page, on the server.');
				}
				if($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name']))
				{
					return array(false, 'The file did not arrive whole. Nothing was changed. Please try again.');
				}
				return array(true, phoromatic_server_update::import_text(phoromatic_server_update::import($f['tmp_name'], 'web', $type, $actor, $detail)));
			}
			if($action === 'check_github')
			{
				return array(true, implode(' ', phoromatic_server_update::check_github($type, $actor, $detail)));
			}
			if($action === 'update')
			{
				$sha = isset($_POST['signed_sha256']) && is_string($_POST['signed_sha256']) ? $_POST['signed_sha256'] : '';
				$fields = array('actor_detail' => $detail, 'subject_type' => 'server_update', 'subject_id' => $sha);
				if(!self::reauth(isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : ''))
				{
					@phoromatic_server_audit::record(null, $type, $actor, 'server_update_reauth_failed', $fields);
					return array(false, 'The password was not right. Nothing was changed.');
				}
				$busy = self::busy_systems();
				if(!empty($busy) && empty($_POST['busy_ack']))
				{
					return array(false, 'Machines are running tests (' . implode(', ', array_slice($busy, 0, 5)) . (count($busy) > 5 ? ', and ' . (count($busy) - 5) . ' more' : '') . '). Tick the box to update anyway. Nothing was changed.');
				}
				$from = phoromatic_server_update::running_version();
				try
				{
					$h = phoromatic_server_update::request($sha, $actor . ($type === 'root' ? '' : '@' . $account));
				}
				catch(phoromatic_catalog_refusal $r)
				{
					@phoromatic_server_audit::record(null, $type, $actor, 'server_update_requested', $fields + array('detail' => array('result' => 'refused', 'reason' => $r->reason, 'message' => substr($r->getMessage(), 0, 300))));
					throw $r;
				}
				@phoromatic_server_audit::record(null, $type, $actor, 'server_update_requested', $fields + array('detail' => array('result' => 'requested', 'from' => $from, 'to' => $h['version'], 'busy_systems' => count($busy), 'busy_ack' => !empty($_POST['busy_ack']))));
				return array(true, 'The update to ' . $h['version'] . ' was asked for. The server will restart; this page follows it.');
			}
		}
		catch(phoromatic_catalog_refusal $r)
		{
			return array(false, 'Refused (' . $r->reason . '): ' . $r->getMessage() . ' Nothing was changed.');
		}
		return array(false, 'Unknown action.');
	}

	public static function render_section($page)
	{
		if(!phoromatic_auth::is_server_admin())
		{
			return '';
		}
		$running = phoromatic_server_update::running_version();
		$out = '<hr /><h2 id="server-update">Server updates</h2>
<p>This server runs version <strong>' . self::h($running) . '</strong>. A server update is a signed bundle with the new Benchy and benchy-server packages. The server checks the signature against its own key table and the update service then installs it as root, takes a copy of the database first, and puts everything back by itself if the new version does not start.</p>' . self::take_flash();
		$a = phoromatic_server_update::availability();
		if(!$a['ok'])
		{
			return $out . '<p>This server cannot be updated from this page. ' . self::h($a['why']) . '</p><p>Update it the way it was installed. On a server installed from the packages that is: <code>sudo apt install ./benchy_X.Y.Z_all.deb ./benchy-server_X.Y.Z_all.deb</code>, with X.Y.Z the new version.</p>';
		}
		$token = write_token_in_form();
		$p = self::h($page);
		if(!phoromatic_server_update::view()['running'])
		{
			phoromatic_server_update::prune();   // (bundles older than the running server are no use: the update service keeps its own archive)
		}
		$held = phoromatic_server_update::held();
		$rows = array();
		foreach($held as $h)
		{
			$rows[] = '<tr><td>' . self::h($h['version']) . '</td><td>' . self::h(substr($h['issued'], 0, 10)) . '</td><td>' . self::h($h['notes']) . '</td><td>' . (phoromatic_server_update::compare($h['version'], $running) > 0 ? 'Newer' : ($h['version'] === $running ? 'Running version (the way back)' : 'Older')) . '</td></tr>';
		}
		$out .= empty($rows) ? '<p>No server update is held yet.</p>' : '<div style="overflow-x: auto;"><table><tr><th>Version</th><th>Issued</th><th>Notes</th><th></th></tr>' . implode('', $rows) . '</table></div>';
		$view = phoromatic_server_update::view();
		$busy_now = $view['running'];
		$out .= '<form action="/?r_server_update" method="post" enctype="multipart/form-data">' . $token . '<input type="hidden" name="page" value="' . $p . '" />
<p><button type="submit" name="action" value="check_github"' . ($busy_now ? ' disabled' : '') . '>Check GitHub</button> (only when pressed; this server sends nothing else to GitHub)
 or upload a bundle <input type="file" name="server_update_bundle" accept=".zip" /> <button type="submit" name="action" value="upload"' . ($busy_now ? ' disabled' : '') . '>Upload</button></p>
<p>Bigger than this page accepts (' . self::h(ini_get('post_max_size')) . ')? On the server, run: <code>' . self::h(phoromatic_tls::as_service_user('benchy server-update-import /path/to/benchy-server-update-X.Y.Z.zip')) . '</code></p></form>';
		$newer = phoromatic_server_update::newer();
		if($newer !== null && !phoromatic_server_update::has_base())
		{
			$out .= '<p style="color: #b00020;"><strong>The bundle for the running version ' . self::h($running) . ' is not held, so there is nothing to go back to if the update fails. Press Check GitHub, or upload benchy-server-update-' . self::h($running) . '.zip, first.</strong></p>';
		}
		else if($newer !== null)
		{
			$busy = self::busy_systems();
			$out .= '<form action="/?r_server_update" method="post">' . $token . '<input type="hidden" name="page" value="' . $p . '" /><input type="hidden" name="action" value="update" /><input type="hidden" name="signed_sha256" value="' . self::h($newer['sha']) . '" />
<p><strong>Update to ' . self::h($newer['version']) . '</strong>. The server restarts and is unavailable for a few minutes. Enter your password to confirm: <input type="password" name="password" autocomplete="current-password" required /></p>' .
				(empty($busy) ? '' : '<p style="color: #b00020;"><strong>Running tests now: ' . self::h(implode(', ', array_slice($busy, 0, 10))) . '.</strong> <label><input type="checkbox" name="busy_ack" value="1" /> Update anyway; their results upload when the server is back.</label></p>') .
				'<p><button type="submit"' . ($busy_now ? ' disabled' : '') . '>Update server</button></p></form>';
		}
		$out .= '<div id="server-update-status" style="margin-top: 1em;"></div>';
		$st = $view['status'];
		phoromatic_server_update::audit_result_once($st);
		return $out . '<script type="text/javascript">' . self::script() . '</script>' . self::noscript($view);
	}

	protected static function noscript($view)
	{
		return '<noscript><p>' . self::h(phoromatic_server_update::state_text($view['state'], $view['status'])) . '</p></noscript>';
	}

	protected static function script()
	{
		return <<<'JS'
(function() {
  var box = document.getElementById("server-update-status");
  var lost = 0, last = "", active = false, since = Date.now();
  function show(lines) {
    box.textContent = "";
    lines.forEach(function(l, i) {
      var p = document.createElement(i === 0 ? "p" : "div");
      p.textContent = l;
      if (i > 0) { p.style.fontFamily = "monospace"; p.style.fontSize = "0.85em"; } else { p.style.fontWeight = "bold"; }
      box.appendChild(p);
    });
  }
  function poll() {
    fetch("/?r_server_update", {credentials: "same-origin", headers: {"Accept": "application/json"}}).then(function(r) {
      return r.text();
    }).then(function(t) {
      var j;
      try { j = JSON.parse(t); } catch (e) { show(["Signed out. Sign in again to see the result of the update."]); return; }
      lost = 0;
      var head = j.text || "";
      if (j.step) { head += " (" + j.step + ")"; }
      if (j.message) { head += " " + j.message; }
      var lines = [head || "No update has run."].concat(j.log || []);
      show(j.state === "none" ? [] : lines);
      var live = j.running || ["preflight", "backup", "installing", "starting", "checking", "rolling_back", "queued"].indexOf(j.state) >= 0;
      if (live) { active = true; }
      if (active && !live) { active = false; last = j.state; if (last === "done") { setTimeout(function() { location.reload(); }, 1500); return; } }
      if (live || Date.now() - since < 8000) { setTimeout(poll, 3000); }
    }).catch(function() {
      lost++;
      if (lost * 3 > 600) { show(["The server did not come back within 10 minutes. Look at it on the server host."]); return; }
      show(["The server is restarting. This page keeps trying."]);
      active = true;
      setTimeout(poll, 3000);
    });
  }
  poll();
})();
JS;
	}
}

?>
