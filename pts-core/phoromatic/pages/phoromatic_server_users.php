<?php

/*
	Benchy
	phoromatic_server_users.php: every user on the server, for server administrators (turn a user off or on, set a new password)

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

// Names, home accounts, the accounts each user belongs to and their state: no data of any account. Memberships are changed on an account's own page in the
// console. Setting a password here is what the root page's "Override User Password" form used to do (that form is gone from ?admin).

class phoromatic_server_users implements pts_webui_interface
{
	public static function page_title()
	{
		return 'Users';
	}
	public static function page_header()
	{
		return null;
	}
	public static function preload($PAGE)
	{
		return true;
	}
	protected static function h($text)
	{
		return phoromatic_server_console::h($text);
	}
	public static function render_page_process($PATH)
	{
		if(!phoromatic_server_console::allowed())
		{
			phoromatic_server_console::refused_page('Users');
			return;
		}
		$main = phoromatic_server_console::nav('users') . phoromatic_server_console::take_flash();
		if(!phoromatic_memberships::ready())
		{
			$main .= '<p>' . self::h(phoromatic_server_roles::UNAVAILABLE) . '</p>';
		}
		else
		{
			$main .= '<h1>Users</h1><p>Everyone who can sign in to this server. Turning a user off stops them signing in to any account, and ends a session they have at its next click. Add a user to an account, or change what they can do there, on the account\'s page under Accounts.</p>';
			$main .= '<div style="overflow-x: auto;"><table><tr><th>User</th><th>Own account</th><th>Belongs to</th><th>Last sign-in</th><th>State</th><th>Set a new password</th></tr>';
			foreach(phoromatic_server_members::listing() as $u)
			{
				$accounts = array();
				foreach(phoromatic_memberships::for_user($u['UserID']) as $m)
				{
					$accounts[] = self::h($m['Name']) . ' (' . self::h(phoromatic_memberships::level_name($m['Level'])) . ($m['State'] === 'active' ? '' : ', ' . self::h($m['State'])) . ')';
				}
				$hidden = '<input type="hidden" name="user_id" value="' . self::h($u['UserID']) . '" />';
				$own = phoromatic_tenant_admin::get($u['AccountID']);
				$main .= '<tr><td>' . self::h($u['UserName']) . ($u['ServerAdmin'] ? ' <em>(server administrator)</em>' : '') . '</td><td>' . self::h($own ? phoromatic_tenant_admin::display_name($own) : $u['AccountID']) . '</td><td>' . (empty($accounts) ? 'No account' : implode('<br />', $accounts)) . '</td><td>' .
					self::h(empty($u['LastLogin']) ? 'Never' : phoromatic_server::user_friendly_timedate($u['LastLogin'])) . '</td><td>' .
					($u['DisabledOn'] !== null ? 'Turned off<br />' . phoromatic_server_console::form('user_enable', $hidden, 'Turn on') : 'On<br />' . phoromatic_server_console::form('user_disable', $hidden, 'Turn off')) . '</td><td>' .
					phoromatic_server_console::form('user_password_reset', $hidden . '<input type="password" name="new_password" autocomplete="new-password" placeholder="New password" /><br /><input type="password" name="new_password_again" autocomplete="new-password" placeholder="Again" />', 'Set password') . '</td></tr>';
			}
			$main .= '</table></div>';
		}
		echo phoromatic_webui_header_logged_in();
		echo '<div id="pts_phoromatic_main_area">' . $main . '</div>';
		echo phoromatic_webui_footer();
	}
}

?>
