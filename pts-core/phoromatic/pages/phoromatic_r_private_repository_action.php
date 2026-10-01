<?php

/*
	Benchy
	Phoromatic web UI: POST handler for the private repository page (delete, rebuild, token create/revoke); redirects after every POST.

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

// An r_ page renders without the HTML shell, which is what makes header redirects possible. Every request: POST only, same-origin
// when the browser says, CSRF token, then the database-backed AdminLevel 1-2 guard. Nothing here is reachable by GET.

class phoromatic_r_private_repository_action implements pts_webui_interface
{
	public static function page_title()
	{
		return '';
	}
	public static function page_header()
	{
		return null;
	}
	public static function preload($PAGE)
	{
		phoromatic_private_repo_web::security_headers();
		return true;
	}
	protected static $finished = false;
	protected static $recorded = false;
	protected static function done($type, $message, $to = '')
	{
		self::$finished = true;
		phoromatic_private_repo_web::flash($type, $message);
		while(ob_get_level() > 0)
		{
			ob_end_clean();
		}
		header('Location: /?private_repository' . $to);
	}
	public static function render_page_process($PATH)
	{
		ini_set('display_errors', '0');
		ob_start();
		phoromatic_private_repo_web::security_headers();

		if($_SERVER['REQUEST_METHOD'] != 'POST')
		{
			ob_end_clean();
			http_response_code(405);
			header('Allow: POST');
			echo 'POST only.';
			return;
		}
		$guard = phoromatic_private_repo_web::guard();
		if(isset($guard['error']))
		{
			ob_end_clean();
			http_response_code(403);
			echo 'You do not have access to this action.';
			return;
		}
		if(empty($_POST) && empty($_FILES) && !empty($_SERVER['CONTENT_LENGTH']) && isset($_SERVER['CONTENT_TYPE']) && preg_match('#^(multipart/form-data|application/x-www-form-urlencoded)#i', $_SERVER['CONTENT_TYPE']) && phoromatic_private_repo_web::ini_bytes(ini_get('post_max_size')) > 0 && (int)$_SERVER['CONTENT_LENGTH'] > phoromatic_private_repo_web::ini_bytes(ini_get('post_max_size')))
		{
			// PHP discards everything of a request over post_max_size. Say so before the token check, which would otherwise
			// report a misleading "form expired".
			self::done('error', 'That upload is larger than this server accepts (post_max_size is ' . ini_get('post_max_size') . '). Zips are limited to ' . (phoromatic_private_repo::MAX_ZIP_BYTES / 1048576) . ' MB; put large files in downloads.xml instead of bundling them.', '/upload');
			return;
		}
		if(!phoromatic_private_repo_web::origin_ok())
		{
			ob_end_clean();
			http_response_code(403);
			echo 'This request was blocked because it did not appear to come from this site. Go back, reload the page and try again.';
			return;
		}
		if(!verify_submission_token())
		{
			self::done('error', 'The form expired or was not submitted from this site; nothing was changed. Please try again.');
			return;
		}

		$user = $guard['user'];
		$account_id = $user['AccountID'];
		$who = phoromatic_private_repo_web::actor_fields($user);
		$action = isset($_POST['action']) && is_string($_POST['action']) ? $_POST['action'] : '';

		// A fatal error or timeout still ends in a redirect with a message instead of a blank page
		register_shutdown_function(function() use ($action) {
			if(self::$finished)
			{
				return;
			}
			$last = error_get_last();
			$message = $action == 'token_create'
				? 'The server stopped while creating the token. If it was created it could not be shown: revoke it on this page and create a new one.'
				: ($action == 'stage' || $action == 'cancel'
					? 'The server stopped while handling the upload; check the Upload tab for what was kept.'
				: ($action == 'confirm'
					? 'The server stopped while confirming the upload' . (self::$recorded ? ' after it had been stored and recorded in the audit log (check the repository list).' : '; check the list and the audit log to see whether it was stored.')
				: ($action == 'delete' || $action == 'rebuild'
					? 'The server stopped while handling this action' . (self::$recorded ? ' after it had been completed and recorded in the audit log (the repository list may need a moment to catch up).' : '; check the audit log for what happened.')
					: 'The server stopped while handling this action; check the Tokens list for what happened.')));
			phoromatic_private_repo_web::flash('error', $message . phoromatic_private_repo_web::error_summary($last));
			while(ob_get_level() > 0)
			{
				ob_end_clean();
			}
			header('Location: /?private_repository' . ($action == 'token_create' || $action == 'token_revoke' ? '/tokens' : (in_array($action, array('stage', 'confirm', 'cancel')) ? '/upload' : '')));
		});

		if(in_array($action, array('delete', 'rebuild', 'stage', 'confirm')) && phoromatic_private_repo::repository_name() == false)
		{
			self::done('error', 'The private repository is disabled on this server; nothing was changed.');
			return;
		}

		switch($action)
		{
			case 'delete':
				self::delete($account_id, $who);
				break;
			case 'rebuild':
				self::rebuild($account_id, $who);
				break;
			case 'stage':
				self::stage($account_id, $user, $who);
				break;
			case 'confirm':
				self::confirm($account_id, $user, $who);
				break;
			case 'cancel':
				self::cancel($account_id, $user, $who);
				break;
			case 'token_create':
				$result = phoromatic_private_repo_auth::create($account_id, isset($_POST['label']) && is_string($_POST['label']) ? $_POST['label'] : '', $who['actor_type'], $who['actor'], $who['actor_detail']);
				if(isset($result['error']))
				{
					self::done('error', $result['error'], '/tokens');
				}
				else
				{
					phoromatic_private_repo_web::stash_new_token($result['token'], $result['id'], $result['label']);
					self::done('ok', 'Token created.', '/tokens');
				}
				break;
			case 'token_revoke':
				$id = isset($_POST['token_id']) && is_string($_POST['token_id']) && ctype_digit($_POST['token_id']) ? (int)$_POST['token_id'] : 0;
				$result = phoromatic_private_repo_auth::revoke($account_id, $id, $who['actor_type'], $who['actor'], $who['actor_detail']);
				self::done(isset($result['error']) ? 'error' : 'ok', isset($result['error']) ? $result['error'] : 'Token #' . $id . ' revoked.', '/tokens');
				break;
			default:
				self::done('error', 'Unknown action; nothing was changed.');
				break;
		}
	}
	protected static function upload_error_text($code)
	{
		switch($code)
		{
			case UPLOAD_ERR_INI_SIZE:
			case UPLOAD_ERR_FORM_SIZE:
				return 'The file is larger than this server accepts (upload_max_filesize is ' . ini_get('upload_max_filesize') . ').';
			case UPLOAD_ERR_PARTIAL:
				return 'The upload was interrupted; please try again.';
			case UPLOAD_ERR_NO_FILE:
				return 'Choose a zip file to upload.';
			default:
				return 'The server could not receive the file (error ' . (int)$code . ').';
		}
	}
	protected static function stage_fields($meta, $facts, $detail = array())
	{
		return array('kind' => isset($meta['kind']) ? $meta['kind'] : null, 'name' => isset($meta['name']) ? $meta['name'] : null,
			'version' => isset($facts['version']) ? $facts['version'] : (isset($meta['version']) ? $meta['version'] : null),
			'sha256' => isset($facts['sha256']) ? $facts['sha256'] : null, 'sha1' => isset($facts['sha1']) ? $facts['sha1'] : null,
			'prev_sha256' => isset($facts['prev_sha256']) ? $facts['prev_sha256'] : null,
			'detail' => array_merge(array('source' => 'web'), isset($facts['original_sha256']) ? array('original_sha256' => $facts['original_sha256'], 'rewritten_sha256' => isset($facts['sha256']) ? $facts['sha256'] : null) : array(), $detail));
	}
	protected static function stage($account_id, $user, $who)
	{
		$f = isset($_FILES['zip']) && is_array($_FILES['zip']) ? $_FILES['zip'] : null;
		if($f == null || !isset($f['error']) || is_array($f['error']) || !isset($f['tmp_name']) || !is_string($f['tmp_name']))
		{
			self::done('error', 'Choose a zip file to upload.', '/upload');
			return;
		}
		if($f['error'] !== UPLOAD_ERR_OK)
		{
			self::audit($account_id, $who, 'upload_rejected', array('detail' => array('source' => 'web', 'error' => self::upload_error_text($f['error']))));
			self::done('error', self::upload_error_text($f['error']), '/upload');
			return;
		}
		if(!is_uploaded_file($f['tmp_name']))
		{
			self::done('error', 'The upload was not received properly; please try again.', '/upload');
			return;
		}
		$name = isset($_POST['name']) && is_string($_POST['name']) ? strtolower(trim($_POST['name'])) : '';
		$description = isset($_POST['commit_description']) && is_string($_POST['commit_description']) ? trim($_POST['commit_description']) : '';
		$original = isset($f['name']) && is_string($f['name']) ? $f['name'] : 'upload.zip';

		phoromatic_private_repo_stage::sweep($account_id, phoromatic_private_repo_web::expired_audit($account_id));
		$staged = function($meta, $facts) use ($account_id, $who) {
			return self::audit($account_id, $who, 'upload_staged', self::stage_fields($meta, $facts, array('stage_id' => $meta['id'], 'original_name' => $meta['original_name'], 'size' => $meta['size'],
				'overwrites_existing' => $meta['existing'] !== null, 'warnings' => $facts['warnings'], 'files' => count($meta['preview']['files']), 'expires' => date('Y-m-d H:i:s', $meta['expires']))));
		};
		$result = phoromatic_private_repo_stage::stage_upload($account_id, $user, $f['tmp_name'], $original, $name, $description, isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : null, $staged);
		if(isset($result['error']))
		{
			self::audit($account_id, $who, 'upload_rejected', self::stage_fields(array('name' => $name !== '' ? $name : null), isset($result['audit']) ? $result['audit'] : array(), array('error' => $result['error'], 'original_name' => $original)));
			self::done('error', $result['error'], '/upload');
			return;
		}
		self::done('ok', 'Staged. Read the scripts and downloads below, then confirm or cancel.', '/review&id=' . $result['stage']['id']);
	}
	protected static function posted_stage_id()
	{
		return isset($_POST['stage_id']) && is_string($_POST['stage_id']) ? $_POST['stage_id'] : '';
	}
	protected static function confirm($account_id, $user, $who)
	{
		$id = self::posted_stage_id();
		$meta = phoromatic_private_repo_stage::get_stage($account_id, $id, $user['UserID']);
		if(isset($meta['error']))
		{
			self::done('error', $meta['error'], '/upload');
			return;
		}
		$review = '/review&id=' . $id;
		if(!isset($_POST['reviewed']) || $_POST['reviewed'] !== '1')
		{
			self::done('error', 'Tick the box to confirm that you have read the scripts; nothing was stored.', $review);
			return;
		}
		$overwrite = isset($_POST['overwrite']) && $_POST['overwrite'] === '1';
		$accepted = isset($_POST['accept_unreviewable']) && $_POST['accept_unreviewable'] === '1';
		$posted_hash = isset($_POST['sha256']) && is_string($_POST['sha256']) ? $_POST['sha256'] : '';
		$description = $meta['commit_description'];
		$unreviewable = isset($meta['preview']['unreviewable']) ? $meta['preview']['unreviewable'] : array();
		$fields = function($facts, $detail = array()) use ($meta, $id, $description, $overwrite, $accepted, $unreviewable) {
			return self::stage_fields($meta, $facts, array_merge(array('stage_id' => $id, 'commit_description' => $description, 'overwrite' => $overwrite,
				'accepted_unreviewable_files' => $accepted ? array_map(function($u) { return $u['path']; }, array_slice($unreviewable, 0, 50)) : array()), $detail));
		};
		// Written inside the repository lock before anything is stored: no audit row, no upload. Under the same lock the version
		// being replaced must still be the one that was reviewed.
		$attempt = function($facts) use ($account_id, $who, $fields, $meta) {
			$reviewed_prev = $meta['existing'] !== null ? $meta['existing']['sha256'] : null;
			if((isset($facts['prev_sha256']) ? $facts['prev_sha256'] : null) !== $reviewed_prev)
			{
				return 'Version ' . $meta['version'] . ' changed while you were confirming (it no longer matches what you reviewed), so nothing was stored. Please upload again to review the current state.';
			}
			return self::audit($account_id, $who, 'upload_attempt', $fields($facts, array('warnings' => $facts['warnings'])));
		};
		$result = phoromatic_private_repo_stage::commit($account_id, $meta, $posted_hash, $overwrite, $accepted, $who['actor'], $attempt);
		$audit = isset($result['audit']) ? $result['audit'] : array('sha256' => $meta['sha256'], 'sha1' => $meta['sha1'], 'version' => $meta['version']);

		if(isset($result['error']))
		{
			if(!empty($result['stored']))
			{
				self::audit($account_id, $who, 'upload_committed', $fields($audit, array('partial' => true, 'error' => $result['error'])));
				self::$recorded = true;
				phoromatic_private_repo_stage::discard($account_id, $id);
				self::done('error', $result['error'], '');
				return;
			}
			if(!empty($result['claim_lost']))
			{
				// Someone else (another tab, the sweep) holds this stage: they record what happens to it, we record nothing
				self::done('error', $result['error'], '/upload');
				return;
			}
			self::audit($account_id, $who, !empty($result['attempted']) ? 'upload_failed' : 'upload_rejected', $fields($audit, array('error' => $result['error'])));
			self::done('error', $result['error'], !empty($result['restored']) ? $review : '/upload');
			return;
		}

		// Recorded before the cache sync, so a problem there cannot leave an unrecorded upload
		$recorded = self::audit($account_id, $who, 'upload_committed', $fields($audit, array('warnings' => $audit['warnings'], 'entries' => $audit['entries'], 'replaced' => $result['upload']['replaced'],
			'trash' => isset($audit['trash']) ? $audit['trash'] : null)));
		self::$recorded = true;
		phoromatic_private_repo_stage::discard($account_id, $id);
		phoromatic_private_repo::sync_server_cache($meta['kind'], $meta['name'], $meta['version']);
		$message = 'Added ' . $result['upload']['id'] . ($result['upload']['replaced'] ? ' (replaced the previous upload of that version)' : '') . '.';
		foreach($result['upload']['warnings'] as $w)
		{
			$message .= ' Warning: ' . $w;
		}
		self::done($recorded ? 'ok' : 'error', $message . ($recorded ? '' : ' Its completion could not be written to the audit log.'));
	}
	protected static function cancel($account_id, $user, $who)
	{
		$id = self::posted_stage_id();
		$meta = phoromatic_private_repo_stage::get_stage($account_id, $id, $user['UserID']);
		if(isset($meta['error']))
		{
			self::done('error', $meta['error'], '/upload');
			return;
		}
		// The stage is claimed first (an atomic rename), so a confirm running at the same moment cannot also be audited as cancelled.
		// The audit row is written while holding the claim; if it cannot be written the stage is handed back untouched.
		$result = phoromatic_private_repo_stage::cancel($account_id, $meta, function() use ($account_id, $who, $meta, $id) {
			return self::audit($account_id, $who, 'upload_cancelled', self::stage_fields($meta, $meta, array('stage_id' => $id)));
		});
		if(isset($result['error']))
		{
			self::done('error', $result['error'], !empty($result['claim_lost']) ? '/upload' : '/review&id=' . $id);
			return;
		}
		self::done('ok', 'Upload cancelled and removed from the server.', '/upload');
	}
	protected static function audit($account_id, $who, $action, $fields)
	{
		return phoromatic_private_repo_audit::record($account_id, $who['actor_type'], $who['actor'], $action, array_merge($fields, array('actor_detail' => $who['actor_detail'])));
	}
	protected static function delete($account_id, $who)
	{
		$kind = isset($_POST['kind']) && is_string($_POST['kind']) ? $_POST['kind'] : '';
		$name = isset($_POST['name']) && is_string($_POST['name']) ? $_POST['name'] : '';
		$version = isset($_POST['version']) && is_string($_POST['version']) ? $_POST['version'] : '';
		$fields = function($audit, $detail = array()) use ($kind, $name, $version) {
			return array('kind' => $kind, 'name' => $name, 'version' => $version, 'sha256' => isset($audit['sha256']) ? $audit['sha256'] : null, 'sha1' => isset($audit['sha1']) ? $audit['sha1'] : null,
				'detail' => array_merge(array('source' => 'web'), $detail));
		};
		// Written inside the repository lock before anything changes; if it cannot be written nothing is deleted
		$attempt = function($audit) use ($account_id, $who, $fields) {
			return self::audit($account_id, $who, 'delete_attempt', $fields($audit));
		};
		$result = phoromatic_private_repo::delete($account_id, $kind, $name, $version, $attempt);
		$audit = isset($result['audit']) ? $result['audit'] : array();

		if(isset($result['error']))
		{
			self::audit($account_id, $who, !empty($result['done']) ? 'delete' : 'delete_failed', $fields($audit, array('error' => $result['error'], 'partial' => !empty($result['done']), 'trash' => isset($audit['trash']) ? $audit['trash'] : null)));
			self::done('error', $result['error']);
			return;
		}
		$recorded = self::audit($account_id, $who, 'delete', $fields($audit, array('trash' => isset($audit['trash']) ? $audit['trash'] : null)));
		self::$recorded = true;
		phoromatic_private_repo::sync_server_cache($kind, $name, $version);
		self::done($recorded ? 'ok' : 'error', 'Deleted ' . $kind . ' ' . $name . '-' . $version . ' (moved to the server trash).' . ($recorded ? '' : ' Its completion could not be written to the audit log.'));
	}
	protected static function rebuild($account_id, $who)
	{
		$attempt = function() use ($account_id, $who) {
			return self::audit($account_id, $who, 'rebuild_attempt', array('detail' => array('source' => 'web')));
		};
		$index = phoromatic_private_repo::rebuild_index($account_id, $attempt);
		if(is_array($index) && !empty($index['audit_failed']))
		{
			self::audit($account_id, $who, 'rebuild_failed', array('detail' => array('source' => 'web', 'error' => 'audit attempt could not be written')));
			self::done('error', 'The audit log could not be written, so the index was not rebuilt.');
			return;
		}
		if(!is_array($index) || !isset($index['tests']))
		{
			self::audit($account_id, $who, 'rebuild_failed', array('detail' => array('source' => 'web', 'error' => 'index could not be rebuilt')));
			self::done('error', 'Unable to rebuild the index; check the server storage permissions.');
			return;
		}
		$detail = array('source' => 'web', 'tests' => count($index['tests']), 'suites' => count($index['suites']));
		if(!empty(phoromatic_private_repo::$last_skipped))
		{
			$detail['skipped'] = phoromatic_private_repo::$last_skipped;
		}
		$recorded = self::audit($account_id, $who, 'rebuild', array('detail' => $detail));
		self::$recorded = true;
		pts_openbenchmarking::refresh_repository_lists(array(phoromatic_private_repo::repository_name()), true);
		$message = 'Index rebuilt: ' . $detail['tests'] . ' tests, ' . $detail['suites'] . ' suites.';
		if(isset($detail['skipped']))
		{
			$message .= ' Not listed because their metadata is missing or invalid: ' . implode(', ', $detail['skipped']) . ' (delete them or upload again with overwrite).';
		}
		self::done($recorded ? ($detail && isset($detail['skipped']) ? 'error' : 'ok') : 'error', $message . ($recorded ? '' : ' Its completion could not be written to the audit log.'));
	}
}

?>
