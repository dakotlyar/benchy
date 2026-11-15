<?php

/*
	Benchy
	phoromatic_r_server_action.php: the one place the server console changes anything (r_server_action). POST only, same origin, the CSRF token, and a server
	administrator read from the database now; then the answer is a redirect with a message, so a reload does not repeat the change. Reachable by GET by no one.
	dismiss_notice is the exception to the role: it is for the user the upgrade notice was shown to.

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

class phoromatic_r_server_action implements pts_webui_interface
{
	const ACTIONS = array('tenant_create', 'tenant_rename', 'tenant_suspend', 'tenant_resume', 'tenant_delete', 'tenant_restore', 'tenant_purge', 'dismiss_notice');

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
	protected static function field($name)
	{
		return isset($_POST[$name]) && is_string($_POST[$name]) ? $_POST[$name] : null;
	}
	protected static function stop($code, $text)
	{
		while(ob_get_level() > 0)
		{
			ob_end_clean();
		}
		http_response_code($code);
		echo $text;
	}
	protected static function done($type, $message, $to)
	{
		phoromatic_server_console::flash($type, $message);
		while(ob_get_level() > 0)
		{
			ob_end_clean();
		}
		header('Location: /?' . $to);
	}
	public static function render_page_process($PATH)
	{
		ini_set('display_errors', '0');
		ob_start();
		phoromatic_private_repo_web::security_headers();
		if($_SERVER['REQUEST_METHOD'] != 'POST')
		{
			header('Allow: POST');
			self::stop(405, 'POST only.');
			return;
		}
		$action = self::field('action');
		if($action === null || !in_array($action, self::ACTIONS, true))
		{
			self::stop(400, 'Unknown action.');
			return;
		}
		if($action !== 'dismiss_notice' && !phoromatic_server_console::allowed())
		{
			self::stop(403, 'You do not have access to this action.');
			return;
		}
		if(!phoromatic_private_repo_web::origin_ok())
		{
			self::stop(403, 'This request was blocked because it did not appear to come from this site. Go back, reload the page and try again.');
			return;
		}
		if(!verify_submission_token())
		{
			self::done('error', 'The form expired or was not submitted from this site; nothing was changed. Please try again.', $action === 'dismiss_notice' ? 'main' : 'server_tenants');
			return;
		}
		if($action === 'dismiss_notice')
		{
			$error = phoromatic_server_notice::dismiss();
			self::done($error === null ? 'ok' : 'error', $error === null ? 'Dismissed.' : $error, 'main');
			return;
		}
		$who = phoromatic_server_console::who();
		$aid = self::field('account_id');
		$detail = $aid === null ? 'server_tenants' : 'server_tenants/' . rawurlencode($aid);
		if($action === 'tenant_create')
		{
			$name = self::field('tenant_name');
			$user = self::field('register_username');
			$error = $name === null || $user === null || self::field('register_password') === null || self::field('register_password_confirm') === null || self::field('register_email') === null
				? 'Fill in every field; nothing was created.'
				: phoromatic_accounts::create($user, self::field('register_password'), self::field('register_password_confirm'), self::field('register_email'), self::field('seed_accountid'), $_SERVER['REMOTE_ADDR'], $name);
			if($error !== null)
			{
				self::done('error', trim(html_entity_decode(strip_tags($error), ENT_QUOTES, 'UTF-8')), 'server_tenants/new');
				return;
			}
			$stmt = phoromatic_server::$db->prepare('SELECT AccountID FROM phoromatic_users WHERE UserName = :user_name');
			$stmt->bindValue(':user_name', $user);
			$result = $stmt->execute();
			$row = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;
			self::done('ok', 'The account was created.', $row ? 'server_tenants/' . rawurlencode($row['AccountID']) : 'server_tenants');
			return;
		}
		if($aid === null)
		{
			self::done('error', 'No account was named; nothing was changed.', 'server_tenants');
			return;
		}
		$ok = 'Done.';
		$error = null;
		if($action === 'tenant_rename')
		{
			$error = phoromatic_tenant_admin::rename($aid, self::field('tenant_name'), $who);
			$ok = 'The account was renamed.';
		}
		else if($action === 'tenant_purge')
		{
			$error = phoromatic_tenant_admin::purge($aid, $who, self::field('confirm_name'), self::field('confirm_id'), $leftover);
			$ok = $leftover === null ? 'The account was purged.' : 'The account was purged, but its folder could not be removed completely and was left on the server as ' . implode(', ', array_map('basename', explode(', ', $leftover))) . '. The audit has a note about it.';
		}
		else
		{
			$verb = substr($action, strlen('tenant_'));
			$error = phoromatic_tenant_admin::change_state($verb, $aid, $who, self::field('reason'), self::field('confirm_name'), self::field('confirm_running') === '1');
			$ok = 'The account was ' . phoromatic_tenant_admin::PAST[$verb] . '.';
		}
		if($error !== null)
		{
			// (back to the page the person was on: a refusal on a confirm page shows there)
			$back = in_array($action, array('tenant_suspend', 'tenant_delete', 'tenant_purge'), true) ? $detail . '/' . substr($action, strlen('tenant_')) : $detail;
			self::done('error', $error, $back);
			return;
		}
		self::done('ok', $ok, $detail);
	}
}

?>
