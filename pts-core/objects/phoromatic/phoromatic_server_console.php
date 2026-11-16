<?php

/*
	Benchy
	phoromatic_server_console.php: what the server console pages share (?server_tenants, ?server_audit, r_server_action): the check, the menu, the forms and the messages

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

// The console is for server administrators (a role row, read from the database on each request) and the root login. Each page and the action
// handler ask phoromatic_auth::is_server_admin() themselves; the router's naming rule is a second line, not the first.

class phoromatic_server_console
{
	public static function h($text)
	{
		return htmlspecialchars((string)$text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}
	public static function allowed()
	{
		return phoromatic_auth::is_server_admin();
	}
	public static function is_root()
	{
		return isset($_SESSION['AdminLevel']) && $_SESSION['AdminLevel'] == -40;
	}
	public static function refused_page($title)
	{
		echo phoromatic_webui_header_logged_in();
		echo '<div id="pts_phoromatic_main_area"><h1>' . self::h($title) . '</h1><p>This page is for server administrators.</p></div>';
		echo phoromatic_webui_footer();
	}
	public static function flash($type, $message)
	{
		$_SESSION['ServerConsoleFlash'] = array('type' => $type === 'error' ? 'error' : 'ok', 'message' => (string)$message);
	}
	public static function take_flash()
	{
		$flash = isset($_SESSION['ServerConsoleFlash']) && is_array($_SESSION['ServerConsoleFlash']) ? $_SESSION['ServerConsoleFlash'] : null;
		unset($_SESSION['ServerConsoleFlash']);
		if($flash === null || !isset($flash['message']))
		{
			return '';
		}
		return '<p style="' . ($flash['type'] === 'error' ? 'color: red;' : '') . '"><strong>' . self::h($flash['message']) . '</strong></p>';
	}
	public static function nav($current)
	{
		$items = array('tenants' => array('?server_tenants', 'Accounts'), 'users' => array('?server_users', 'Users'));
		$items['settings'] = self::is_root() ? array('?admin_config', 'Server settings') : array('?server_settings', 'Server settings');
		$items['audit'] = array('?server_audit', 'Server audit');
		$links = array();
		foreach($items as $key => $item)
		{
			$links[] = $key === $current ? '<strong>' . $item[1] . '</strong>' : '<a href="' . $item[0] . '">' . $item[1] . '</a>';
		}
		return '<p>' . implode(' &middot; ', $links) . '</p>';
	}
	public static function form($action, $inner, $button, $extra = '')
	{
		// A form that goes to r_server_action with the CSRF token. $inner is HTML the caller has already escaped.
		return '<form action="/?r_server_action" method="post">' . write_token_in_form() . '<input type="hidden" name="action" value="' . self::h($action) . '" />' . $inner .
			'<p><input type="submit" value="' . self::h($button) . '"' . $extra . ' /></p></form>';
	}
	public static function who()
	{
		// The audit actor for tenant_admin calls: array(actor type, actor, actor detail, own account)
		list($account, $type, $actor, $detail) = phoromatic_auth::audit_actor();
		return array($type, $actor, $detail, $type === 'root' ? '' : $account);
	}
}

?>
