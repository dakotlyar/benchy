<?php

/*
	Benchy built-in HTTPS (0.9): the web side of the certificate management (section "HTTPS certificate" of ?settings for account
	administrators of level 1, and of ?admin_config for the root login). Everything that changes state is a POST with the CSRF token and a
	same-origin check, and the administrator level is re-read from the database. The private key is only accepted over HTTPS or from
	loopback (an SSH tunnel); over plain HTTP the page offers "Turn on HTTPS with the built-in CA" instead, which sends no secret.
	Every change is audited first (rolled back if the audit row cannot be written) and never records key material or the PFX password.

	This program is free software; you can redistribute it and/or modify it under the terms of the GNU General Public License as published
	by the Free Software Foundation; either version 3 of the License, or (at your option) any later version.
*/

class phoromatic_tls_web
{
	const STAGE_TTL = 600;

	public static function allowed()
	{
		// The root login, or an account administrator of level 1 (read from the database now, not from what the session remembers)
		if(isset($_SESSION['AdminLevel']) && $_SESSION['AdminLevel'] == -40)
		{
			return true;
		}
		if(!isset($_SESSION['UserID']) || !isset($_SESSION['AccountID']) || !isset($_SESSION['AdminLevel']) || $_SESSION['AdminLevel'] != 1)
		{
			return false;
		}
		$stmt = phoromatic_server::$db->prepare('SELECT AdminLevel FROM phoromatic_users WHERE UserID = :user_id AND AccountID = :account_id');
		$stmt->bindValue(':user_id', (string)$_SESSION['UserID']);
		$stmt->bindValue(':account_id', $_SESSION['AccountID']);
		$result = $stmt->execute();
		$row = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;
		return $row && $row['AdminLevel'] == 1;
	}
	public static function channel_secure()
	{
		// HTTPS (only the secret-checked prepend can set it) or a loopback address (REMOTE_ADDR cannot be forged by a remote client)
		if(!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on')
		{
			return true;
		}
		return isset($_SERVER['REMOTE_ADDR']) && in_array($_SERVER['REMOTE_ADDR'], array('127.0.0.1', '::1', '::ffff:127.0.0.1'), true);
	}
	protected static function actor()
	{
		return array(isset($_SESSION['AccountID']) && $_SESSION['AccountID'] ? $_SESSION['AccountID'] : (string)phoromatic_private_repo::server_account_id(),
			'user', isset($_SESSION['UserName']) ? $_SESSION['UserName'] : '?', 'UserID ' . (isset($_SESSION['UserID']) ? $_SESSION['UserID'] : '?'));
	}
	protected static function h($text)
	{
		return htmlspecialchars((string)$text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}
	protected static function error($text)
	{
		return '<p style="color: red;"><strong>' . self::h($text) . '</strong></p>';
	}
	protected static function staged_dir()
	{
		$d = phoromatic_tls::tls_path() . 'staged/';
		if(!is_dir($d))
		{
			@mkdir($d, 0700, true);
		}
		return $d;
	}
	public static function sweep_staged($all = false)
	{
		// Unconfirmed staged certificates (they hold a private key): older than STAGE_TTL, or all of them (server start)
		foreach((array)glob(self::staged_dir() . '*.json') as $f)
		{
			if($all || filemtime($f) < time() - self::STAGE_TTL)
			{
				@unlink($f);
			}
		}
	}
	protected static function read_upload($field, &$error)
	{
		// The file's content, or null when none was sent (error set when it was refused). The temporary file is removed either way.
		if(!isset($_FILES[$field]) || !is_array($_FILES[$field]) || !isset($_FILES[$field]['error']) || is_array($_FILES[$field]['error']) || $_FILES[$field]['error'] == UPLOAD_ERR_NO_FILE)
		{
			return null;
		}
		$f = $_FILES[$field];
		try
		{
			if($f['error'] == UPLOAD_ERR_INI_SIZE || $f['error'] == UPLOAD_ERR_FORM_SIZE || (isset($f['size']) && $f['size'] > phoromatic_tls::MAX_UPLOAD_BYTES))
			{
				$error = 'A file is larger than ' . (phoromatic_tls::MAX_UPLOAD_BYTES / 1024) . ' KiB, which no certificate or key needs to be.';
				return null;
			}
			if($f['error'] != UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name']))
			{
				$error = 'The file upload failed.';
				return null;
			}
			$data = file_get_contents($f['tmp_name']);
			if($data === false || strlen($data) > phoromatic_tls::MAX_UPLOAD_BYTES)
			{
				$error = 'A file could not be read, or is larger than ' . (phoromatic_tls::MAX_UPLOAD_BYTES / 1024) . ' KiB.';
				return null;
			}
			return $data;
		}
		finally
		{
			if(isset($f['tmp_name']) && is_string($f['tmp_name']) && $f['tmp_name'] !== '')
			{
				@unlink($f['tmp_name']);
			}
		}
	}
	public static function process_post()
	{
		// HTML for the section's message line ('' when this request is none of its business)
		if($_SERVER['REQUEST_METHOD'] != 'POST' || !isset($_POST['https_action']) || !is_string($_POST['https_action']))
		{
			return '';
		}
		if(!self::allowed())
		{
			return self::error('Only an administrator of level 1 can change the HTTPS settings; nothing was changed.');
		}
		$action = $_POST['https_action'];
		if(!in_array($action, array('bootstrap', 'stage', 'confirm', 'cancel'), true))
		{
			return self::error('Unknown action; nothing was changed.');
		}
		if(!phoromatic_private_repo_web::origin_ok() || !verify_submission_token())
		{
			return self::error('The form expired or was not submitted from this site; nothing was changed. Please try again.');
		}
		self::sweep_staged();
		list($account_id, $actor_type, $actor, $actor_detail) = self::actor();
		$before = phoromatic_tls::settings();

		if($action == 'cancel')
		{
			self::drop_stage();
			return '<p>Cancelled; nothing was changed.</p>';
		}
		if($action == 'bootstrap')
		{
			// No secret travels in this request, so it is safe over plain HTTP. Settings only: PHP has to move to loopback, which needs a restart.
			if($before['mode'] != 'off')
			{
				return self::error('HTTPS is already on.');
			}
			$after = $before;
			$after['mode'] = 'internal';
			$after['names'] = phoromatic_tls::detected_names(isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '');
			$error = phoromatic_tls::commit_change($account_id, $actor_type, $actor, $actor_detail, $after, array(array('https_mode_change',
				array('source' => 'web', 'from' => 'off', 'to' => 'internal', 'names' => $after['names'], 'https_port' => $after['https_port'], 'http_mode' => $after['http_mode']))));
			if($error !== null)
			{
				return self::error($error);
			}
			$note = phoromatic_tls::caddy_check($after) !== null ? ' Caddy is not installed yet: on the server run <strong>benchy server-caddy-install</strong> first.' : '';
			return '<p><strong>Saved: HTTPS with the built-in CA for ' . self::h(implode(', ', $after['names'])) . '.</strong> Restart the Benchy server service to apply it (systemctl restart phoromatic-server), then open this page at https://' . self::h($after['names'][0]) . ':' . (int)$after['https_port'] . ' and accept the built-in CA once (its fingerprint is shown here and by server-https-status).' . $note . '</p>';
		}

		// The upload steps take a private key: only over HTTPS or from localhost, checked here as well as on the page
		if(!self::channel_secure())
		{
			return self::error('Refused: a certificate and key are only accepted over HTTPS, or from localhost, so the private key is not sent in plain text.');
		}
		if($action == 'stage')
		{
			return self::stage_upload($before);
		}
		return self::confirm_upload($before, $account_id, $actor_type, $actor, $actor_detail);
	}
	protected static function drop_stage()
	{
		if(!empty($_SESSION['HttpsStaged']) && is_string($_SESSION['HttpsStaged']) && preg_match('/^[0-9a-f]{32}$/', $_SESSION['HttpsStaged']))
		{
			@unlink(self::staged_dir() . $_SESSION['HttpsStaged'] . '.json');
		}
		unset($_SESSION['HttpsStaged']);
	}
	protected static function stage_upload($before)
	{
		$error = null;
		$cert = self::read_upload('cert_file', $error);
		$key = $error === null ? self::read_upload('key_file', $error) : null;
		$pfx = $error === null ? self::read_upload('pfx_file', $error) : null;
		if($error !== null)
		{
			return self::error($error . ' Nothing was changed.');
		}
		$names = null;
		if(isset($_POST['names']) && is_string($_POST['names']) && trim($_POST['names']) !== '')
		{
			$names = phoromatic_tls::parse_names($_POST['names']);
			if(!is_array($names))
			{
				return self::error($names . ' Nothing was changed.');
			}
		}
		if($pfx !== null)
		{
			$password = isset($_POST['pfx_password']) && is_string($_POST['pfx_password']) ? $_POST['pfx_password'] : '';
			$result = phoromatic_tls::import_pfx($pfx, $password, $names);
			unset($password);
		}
		else if($cert !== null && $key !== null)
		{
			$result = phoromatic_tls::import_pem($cert, $key, $names);
		}
		else
		{
			return self::error('Choose a certificate file and a key file, or a PFX file. Nothing was changed.');
		}
		list($err, $warnings, $info, $cert_pem, $key_pem) = $result;
		if($err !== null)
		{
			return self::error($err . ' Nothing was changed.');
		}
		$final_names = $names !== null ? $names : phoromatic_tls::names_from_cert($info);
		if(empty($final_names))
		{
			return self::error('The certificate has no subject alternative names; enter the names clients use. Nothing was changed.');
		}
		self::drop_stage();
		$id = bin2hex(random_bytes(16));
		$file = self::staged_dir() . $id . '.json';
		$old = umask(0077);
		$ok = file_put_contents($file, json_encode(array('cert' => $cert_pem, 'key' => $key_pem, 'names' => $final_names, 'info' => $info, 'warnings' => $warnings, 'source' => $pfx !== null ? 'pfx' : 'pem'))) !== false;
		umask($old);
		if(!$ok)
		{
			return self::error('The certificate could not be staged on the server. Nothing was changed.');
		}
		@chmod($file, 0600);
		$_SESSION['HttpsStaged'] = $id;
		$h = '<div style="border: 1px solid #888; padding: 8px;"><p><strong>Check this certificate, then confirm.</strong></p><table>' .
			'<tr><td>Subject:</td><td>' . self::h($info['subject']) . '</td></tr>' .
			'<tr><td>Issuer:</td><td>' . self::h($info['issuer']) . '</td></tr>' .
			'<tr><td>Names:</td><td>' . self::h(implode(', ', $info['sans'])) . '</td></tr>' .
			'<tr><td>Serves:</td><td>' . self::h(implode(', ', $final_names)) . '</td></tr>' .
			'<tr><td>Expires:</td><td>' . self::h(gmdate('Y-m-d H:i', $info['not_after'])) . ' UTC</td></tr>' .
			'<tr><td>SHA-256:</td><td><code>' . self::h(phoromatic_tls::fingerprint_display($info['sha256'])) . '</code></td></tr></table>';
		foreach($warnings as $w)
		{
			$h .= '<p style="color: #b60;"><strong>Warning:</strong> ' . self::h($w) . '</p>';
		}
		$h .= '<form action="?' . self::h(defined('PAGE_REQUEST') ? PAGE_REQUEST : 'settings') . '" method="post">' . write_token_in_form() . '<input type="hidden" name="https_action" value="confirm" /><input type="submit" value="Use this certificate" /></form>' .
			'<form action="?' . self::h(defined('PAGE_REQUEST') ? PAGE_REQUEST : 'settings') . '" method="post">' . write_token_in_form() . '<input type="hidden" name="https_action" value="cancel" /><input type="submit" value="Cancel" /></form></div>';
		return $h;
	}
	protected static function confirm_upload($before, $account_id, $actor_type, $actor, $actor_detail)
	{
		$id = isset($_SESSION['HttpsStaged']) && is_string($_SESSION['HttpsStaged']) && preg_match('/^[0-9a-f]{32}$/', $_SESSION['HttpsStaged']) ? $_SESSION['HttpsStaged'] : null;
		$file = $id ? self::staged_dir() . $id . '.json' : null;
		if($file === null || !is_file($file) || filemtime($file) < time() - self::STAGE_TTL)
		{
			self::drop_stage();
			return self::error('The staged certificate expired (or there is none). Upload it again.');
		}
		$staged = json_decode(file_get_contents($file), true);
		self::drop_stage();
		if(!is_array($staged) || !isset($staged['cert']) || !isset($staged['key']))
		{
			return self::error('The staged certificate could not be read. Upload it again.');
		}
		// Validated again now: it may have expired while it waited
		list($err, $warnings, $info, $cert_pem, $key_pem) = phoromatic_tls::validate_certificate($staged['cert'], $staged['key'], $staged['names']);
		if($err !== null)
		{
			return self::error($err . ' Nothing was changed.');
		}
		$tls = phoromatic_tls::tls_path();
		$after = $before;
		$after['mode'] = 'file';
		$after['names'] = $staged['names'];
		$after['file'] = array('cert' => $tls . 'cert.pem', 'key' => $tls . 'key.pem');
		$audits = array(array('https_cert_upload', phoromatic_tls::audit_details_for_cert($info, 'web', $warnings)));
		if(phoromatic_tls::describe($before) != phoromatic_tls::describe($after))
		{
			$audits[] = array('https_mode_change', array('source' => 'web', 'from' => $before['mode'], 'to' => 'file', 'names' => $after['names'], 'https_port' => $after['https_port'], 'http_mode' => $after['http_mode']));
		}
		$error = phoromatic_tls::commit_change($account_id, $actor_type, $actor, $actor_detail, $after, $audits, $cert_pem, $key_pem);
		if($error !== null)
		{
			return self::error($error);
		}
		return '<p><strong>Certificate saved.</strong> ' . self::h(phoromatic_tls::report_apply($before, $after)) . '</p>';
	}
	public static function reconcile_failed_apply()
	{
		// The launcher put the previous Caddy configuration back (and wrote the marker); the settings go back to match what is serving
		$tls = phoromatic_tls::tls_path(false);
		if(is_file(phoromatic_tls::apply_failed_file()) && is_file($tls . 'settings.prev'))
		{
			$prev = json_decode(file_get_contents($tls . 'settings.prev'), true);
			if(is_array($prev) && isset($prev['mode']))
			{
				phoromatic_tls::save_settings(array_merge(phoromatic_tls::defaults(), $prev));
			}
			@rename($tls . 'settings.prev', $tls . 'settings.prev.used');
		}
	}

	public static function render_section($page, $message = '')
	{
		// $page: the ?page this form posts back to (settings or admin_config); $message: what process_post() returned
		if(!self::allowed())
		{
			return '';
		}
		self::reconcile_failed_apply();
		self::sweep_staged();
		$s = phoromatic_tls::settings();
		$secure = self::channel_secure();
		$h = '<hr /><h2>HTTPS Certificate</h2>' . $message;
		$h .= '<p>Mode: <strong>' . self::h($s['mode'] == 'off' ? 'off (plain HTTP)' : $s['mode']) . '</strong>';
		if($s['mode'] != 'off')
		{
			$h .= ' &middot; names: ' . self::h(implode(', ', $s['names'])) . ' &middot; HTTPS port ' . (int)$s['https_port'] . ' &middot; plain HTTP: ' . self::h($s['http_mode']);
		}
		$h .= '</p>';
		if(is_file(phoromatic_tls::apply_failed_file()))
		{
			$h .= self::error('The new certificate was not applied: the previous configuration is still serving. See ' . phoromatic_tls::tls_path(false) . 'caddy.log on the server.');
		}
		if($s['mode'] != 'off')
		{
			$live = phoromatic_tls::cached_live_certificate();
			if(is_array($live))
			{
				$h .= '<p>Serving: ' . self::h($live['subject']) . ' (' . self::h(implode(', ', $live['sans'])) . '), issued by ' . self::h($live['issuer']) . ', expires ' . self::h(gmdate('Y-m-d', $live['not_after'])) . ' UTC<br />SHA-256 <code>' . self::h(phoromatic_tls::fingerprint_display($live['sha256'])) . '</code></p>';
			}
			if($s['mode'] == 'internal' && ($fp = phoromatic_tls::root_ca_fingerprint()))
			{
				$h .= '<p>Built-in CA root fingerprint (SHA-256), to check in the browser and to give clients: <code>' . self::h(phoromatic_tls::fingerprint_display($fp)) . '</code><br />The root certificate: <a href="/server.php?benchy_ca">server.php?benchy_ca</a></p>';
			}
		}
		$action = '?' . self::h($page);
		if($s['mode'] == 'off')
		{
			$h .= '<form action="' . $action . '" method="post">' . write_token_in_form() . '<input type="hidden" name="https_action" value="bootstrap" /><p>This server is on plain HTTP. <input type="submit" value="Turn on HTTPS with the built-in CA" /> Saves the setting only (nothing secret is sent); restart the service to apply it.</p></form>';
		}
		if($secure)
		{
			$h .= '<form action="' . $action . '" method="post" enctype="multipart/form-data">' . write_token_in_form() . '<input type="hidden" name="https_action" value="stage" />' .
				'<p>Upload a certificate: either a PEM certificate chain and a PEM private key (no passphrase), or a PFX file and its password. Each file at most 64 KiB.</p>' .
				'<p>Certificate chain (PEM): <input type="file" name="cert_file" /><br />Private key (PEM): <input type="file" name="key_file" /></p>' .
				'<p>or PFX file: <input type="file" name="pfx_file" /> password: <input type="password" name="pfx_password" autocomplete="off" /></p>' .
				'<p>Names to serve (optional, comma separated; default: the certificate\'s names): <input type="text" name="names" size="40" /></p>' .
				'<p><input type="submit" value="Check the certificate" /></p></form>';
		}
		else
		{
			$target = $s['mode'] != 'off' && !empty($s['names']) ? 'https://' . self::h(phoromatic_tls::sni_name($s)) . ':' . (int)$s['https_port'] : 'https://HOST:' . (int)$s['https_port'];
			$h .= '<p style="color: #b60;"><strong>Upload needs HTTPS so the private key isn\'t sent in plain text.</strong> Turn on HTTPS with the built-in CA first (button above), restart the service, open this page at ' . $target . ', then upload.</p>' .
				'<form><fieldset disabled="disabled"><p>Certificate chain (PEM): <input type="file" /> Private key (PEM): <input type="file" /> <input type="submit" value="Check the certificate" /></p></fieldset></form>';
		}
		return $h;
	}
	public static function status_box()
	{
		// Read-only, for ?admin_config (root): the same facts as server-https-status
		$s = phoromatic_tls::settings();
		$h = '<hr /><h1>HTTPS</h1><p>Mode: <strong>' . self::h($s['mode']) . '</strong>';
		if($s['mode'] != 'off')
		{
			$h .= '; names: ' . self::h(implode(', ', $s['names'])) . '; HTTPS port ' . (int)$s['https_port'] . '; plain HTTP: ' . self::h($s['http_mode']);
		}
		$h .= '; PHP workers: ' . (int)phoromatic_tls::php_workers($s) . '; Caddy: ' . self::h($s['caddy']['path'] ? $s['caddy']['version'] : 'not installed') . '.</p>';
		if($s['mode'] != 'off' && is_array($live = phoromatic_tls::cached_live_certificate()))
		{
			$h .= '<p>Certificate: ' . self::h($live['subject']) . ', issued by ' . self::h($live['issuer']) . ', expires ' . self::h(gmdate('Y-m-d', $live['not_after'])) . ' UTC.</p>';
		}
		if($s['mode'] == 'internal' && ($fp = phoromatic_tls::root_ca_fingerprint()))
		{
			$h .= '<p>Built-in CA root SHA-256: <code>' . self::h(phoromatic_tls::fingerprint_display($fp)) . '</code></p>';
		}
		$h .= '<p>Change it on the server with <code>benchy server-https-set</code>, <code>server-https-off</code> and <code>server-https-status</code>; ' . self::h(phoromatic_tls::workers_rule()) . '</p>';
		return $h;
	}
	public static function banner()
	{
		// For level-1 users on every page (see phoromatic_webui_header_logged_in)
		if(!isset($_SESSION['AdminLevel']) || $_SESSION['AdminLevel'] != 1)
		{
			return '';
		}
		$text = phoromatic_tls::expiry_banner();
		return $text === null ? '' : '<div style="background: #fed; border: 1px solid #b60; color: #630; padding: 6px 12px; margin: 4px;"><strong>HTTPS:</strong> ' . self::h($text) . '</div>';
	}
}

?>
