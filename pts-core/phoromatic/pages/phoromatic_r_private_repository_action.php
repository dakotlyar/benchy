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
				: ($action == 'delete' || $action == 'rebuild'
					? 'The server stopped while handling this action' . (self::$recorded ? ' after it had been completed and recorded in the audit log (the repository list may need a moment to catch up).' : '; check the audit log for what happened.')
					: 'The server stopped while handling this action; check the Tokens list for what happened.');
			phoromatic_private_repo_web::flash('error', $message . ($last ? ' (' . basename($last['file']) . ': ' . $last['message'] . ')' : ''));
			while(ob_get_level() > 0)
			{
				ob_end_clean();
			}
			header('Location: /?private_repository' . ($action == 'token_create' || $action == 'token_revoke' ? '/tokens' : ''));
		});

		if(in_array($action, array('delete', 'rebuild')) && phoromatic_private_repo::repository_name() == false)
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
