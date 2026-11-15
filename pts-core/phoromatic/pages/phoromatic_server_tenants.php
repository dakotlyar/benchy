<?php

/*
	Benchy
	phoromatic_server_tenants.php: ?server_tenants, the server console's accounts: the list, creating one, and an account's page with its lifecycle buttons
	(?server_tenants/new, ?server_tenants/<account>, ?server_tenants/<account>/suspend|delete|purge for the confirm pages). Pages only show: every
	change is a POST to r_server_action. For server administrators at any account level and the root login.

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

class phoromatic_server_tenants implements pts_webui_interface
{
	public static function page_title()
	{
		return 'Accounts';
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
			// Nothing below runs for anyone else
			phoromatic_server_console::refused_page('Accounts');
			return;
		}
		$main = phoromatic_server_console::nav('tenants') . phoromatic_server_console::take_flash();
		$first = isset($PATH[0]) ? $PATH[0] : '';
		if(!phoromatic_server::tenancy_ready())
		{
			$main .= '<p>' . self::h(phoromatic_server_roles::UNAVAILABLE) . '</p>';
		}
		else if($first === 'new')
		{
			$main .= self::create_form();
		}
		else if($first === '' || $first === 'show')
		{
			$main .= self::listing(isset($PATH[1]) ? $PATH[1] : '');
		}
		else
		{
			$main .= self::detail($first, isset($PATH[1]) ? $PATH[1] : '');
		}
		echo phoromatic_webui_header_logged_in();
		echo '<div id="pts_phoromatic_main_area">' . $main . '</div>';
		echo phoromatic_webui_footer();
	}
	protected static function state_label($row)
	{
		return $row['PurgedOn'] !== null ? 'purged' : $row['State'];
	}
	protected static function listing($filter)
	{
		$filters = array('' => array('Active and suspended', null), 'active' => array('Active', 'active'), 'suspended' => array('Suspended', 'suspended'), 'deleted' => array('Deleted', 'deleted'), 'purged' => array('Purged', 'purged'), 'all' => array('All', 'all'));
		$filter = isset($filters[$filter]) ? $filter : '';
		$rows = phoromatic_tenant_admin::listing(in_array($filter, array('active', 'suspended', 'deleted', 'purged'), true) ? $filter : null);
		$links = array();
		foreach($filters as $key => $f)
		{
			$links[] = $key === $filter ? '<strong>' . $f[0] . '</strong>' : '<a href="?server_tenants/show/' . $key . '">' . $f[0] . '</a>';
		}
		$h = '<h1>Accounts</h1><p>' . implode(' &middot; ', $links) . ' &nbsp; <a href="?server_tenants/new">Create an account</a></p>';
		$h .= '<div style="overflow-x: auto;"><table><tr><th>Name</th><th>Account ID</th><th>State</th><th>Users</th><th>Systems</th><th>Created</th></tr>';
		$shown = 0;
		foreach($rows as $row)
		{
			if(($filter === '' && $row['State'] === 'deleted') || ($filter === 'deleted' && $row['PurgedOn'] !== null))
			{
				continue;
			}
			$c = phoromatic_tenant_admin::counts($row['AccountID']);
			$h .= '<tr><td><a href="?server_tenants/' . self::h(rawurlencode($row['AccountID'])) . '">' . self::h(phoromatic_tenant_admin::display_name($row)) . '</a></td><td>' . self::h($row['AccountID']) . '</td><td>' . self::h(self::state_label($row)) .
				'</td><td>' . (int)$c['users'] . '</td><td>' . (int)$c['systems'] . '</td><td>' . self::h(phoromatic_server::user_friendly_timedate($row['CreatedOn'])) . '</td></tr>';
			$shown++;
		}
		$h .= '</table></div>';
		if($shown === 0)
		{
			$h .= '<p>No accounts to show here.</p>';
		}
		return $h;
	}
	protected static function create_form()
	{
		return '<h1>Create an account</h1>
			<p>An account is a separate workspace with its own systems, schedules, results and private test repository. Its first user is its administrator.</p>' .
			phoromatic_server_console::form('tenant_create', '
			<p><label>Account name<br /><input type="text" name="tenant_name" maxlength="' . phoromatic_tenant_admin::NAME_MAX . '" /></label></p>
			<p><label>First user name<br /><input type="text" name="register_username" /></label></p>
			<p><label>Password<br /><input type="password" name="register_password" autocomplete="new-password" /></label></p>
			<p><label>Password again<br /><input type="password" name="register_password_confirm" autocomplete="new-password" /></label></p>
			<p><label>Email address<br /><input type="text" name="register_email" /></label></p>
			<p><label>Account ID (optional, six letters or digits)<br /><input type="text" name="seed_accountid" maxlength="6" /></label><br />Only to bring back an account whose ID clients already use.</p>', 'Create account') .
			'<p><a href="?server_tenants">Back to the accounts</a></p>';
	}
	protected static function detail($account_id, $action)
	{
		$row = phoromatic_tenant_admin::get($account_id);
		if(!$row)
		{
			return '<h1>Accounts</h1><p>There is no such account.</p><p><a href="?server_tenants">Back to the accounts</a></p>';
		}
		$aid = $row['AccountID'];
		$name = phoromatic_tenant_admin::display_name($row);
		$state = self::state_label($row);
		$counts = phoromatic_tenant_admin::counts($aid);
		$back = '<p><a href="?server_tenants/' . self::h(rawurlencode($aid)) . '">Back to ' . self::h($name) . '</a></p>';
		$hidden = '<input type="hidden" name="account_id" value="' . self::h($aid) . '" />';
		if($action === 'suspend' && $state === 'active')
		{
			$running = phoromatic_tenant_admin::running_systems($aid);
			return '<h1>Suspend ' . self::h($name) . '</h1><p>While an account is suspended its users cannot sign in, its systems and agents are turned away, and its results feed, triggers and schedules do nothing. Nothing is deleted, and resuming puts everything back.</p>' .
				phoromatic_server_console::form('tenant_suspend', $hidden . ($running > 0 ? '<p><label><input type="checkbox" name="confirm_running" value="1" /> ' . (int)$running . ' system(s) are running a task now. A run that finishes while the account is suspended cannot upload its result; I understand.</label></p>' : '') .
				'<p><label>Reason (optional)<br /><input type="text" name="reason" maxlength="' . phoromatic_tenant_admin::REASON_MAX . '" /></label></p>', 'Suspend account') . $back;
		}
		if($action === 'delete' && $state === 'suspended')
		{
			return '<h1>Delete ' . self::h($name) . '</h1><p>This account has ' . (int)$counts['users'] . ' user(s), ' . (int)$counts['systems'] . ' system(s), ' . (int)$counts['schedules'] . ' schedule(s) and ' . (int)$counts['results'] . ' result(s). Its users will not be able to sign in. ' .
				'Deleting keeps all the data on disk, and you can restore the account later.</p>' .
				phoromatic_server_console::form('tenant_delete', $hidden . '<p><label>Type the account name to confirm: <strong>' . self::h($name) . '</strong><br /><input type="text" name="confirm_name" autocomplete="off" /></label></p>
				<p><label>Reason (optional)<br /><input type="text" name="reason" maxlength="' . phoromatic_tenant_admin::REASON_MAX . '" /></label></p>', 'Delete account') . $back;
		}
		if($action === 'purge' && $state === 'deleted')
		{
			return '<h1>Purge ' . self::h($name) . '</h1><p><strong>This destroys the account\'s data for good.</strong> Its ' . (int)$counts['systems'] . ' system(s), ' . (int)$counts['schedules'] . ' schedule(s), ' . (int)$counts['results'] . ' result(s), its private repository and every other row and file it owns are removed, and there is no way back. ' .
				'Its users stay on the server but have no account. The audit keeps the record that it was purged, and the account ID is never used again.</p>' .
				phoromatic_server_console::form('tenant_purge', $hidden . '<p><label>Type the account name: <strong>' . self::h($name) . '</strong><br /><input type="text" name="confirm_name" autocomplete="off" /></label></p>
				<p><label>Type the account ID: <strong>' . self::h($aid) . '</strong><br /><input type="text" name="confirm_id" autocomplete="off" /></label></p>', 'Purge account permanently') . $back;
		}
		$h = '<h1>' . self::h($name) . '</h1><p><strong>State:</strong> ' . self::h($state) . '<br /><strong>Account ID:</strong> ' . self::h($aid) . '<br /><strong>Created:</strong> ' . self::h(phoromatic_server::user_friendly_timedate($row['CreatedOn'])) .
			($row['CreatedBy'] ? ' by ' . self::h($row['CreatedBy']) : '') . '<br /><strong>Users:</strong> ' . (int)$counts['users'] . ' &middot; <strong>Systems:</strong> ' . (int)$counts['systems'] . ' &middot; <strong>Schedules:</strong> ' . (int)$counts['schedules'] . ' &middot; <strong>Results:</strong> ' . (int)$counts['results'] . '</p>';
		if($row['StateChangedOn'] && $state !== 'active')
		{
			$h .= '<p>Last changed ' . self::h(phoromatic_server::user_friendly_timedate($row['StateChangedOn'])) . ' by ' . self::h($row['StateChangedBy']) . ($row['StateReason'] ? ': ' . self::h($row['StateReason']) : '') . '</p>';
		}
		if($state !== 'purged')
		{
			$h .= '<h2>Rename</h2>' . phoromatic_server_console::form('tenant_rename', $hidden . '<p><input type="text" name="tenant_name" value="' . self::h(phoromatic_tenant_admin::display_name($row)) . '" maxlength="' . phoromatic_tenant_admin::NAME_MAX . '" /></p>', 'Rename');
		}
		$h .= '<h2>Lifecycle</h2>';
		if($state === 'active')
		{
			$h .= '<p><a href="?server_tenants/' . self::h(rawurlencode($aid)) . '/suspend">Suspend this account</a></p>';
		}
		else if($state === 'suspended')
		{
			$h .= phoromatic_server_console::form('tenant_resume', $hidden, 'Resume account') . '<p><a href="?server_tenants/' . self::h(rawurlencode($aid)) . '/delete">Delete this account</a></p>';
		}
		else if($state === 'deleted')
		{
			$h .= phoromatic_server_console::form('tenant_restore', $hidden, 'Restore account (it comes back suspended)') . '<p><a href="?server_tenants/' . self::h(rawurlencode($aid)) . '/purge">Purge this account permanently</a></p>';
		}
		else
		{
			$h .= '<p>This account was purged on ' . self::h(phoromatic_server::user_friendly_timedate($row['PurgedOn'])) . '. Nothing is left to change.</p>';
		}
		if($state !== 'purged' && $state !== 'deleted')
		{
			$h .= self::members_section($row, $state, $hidden);
		}
		return $h . '<p><a href="?server_tenants">Back to the accounts</a></p>';
	}
	protected static function level_options($selected, $with_off = false)
	{
		$o = '';
		foreach(array('1' => 'Group Administrator', '2' => 'Administrator', '3' => 'Power User', '10' => 'Viewer') + ($with_off ? array('off' => 'Turned off') : array()) as $value => $label)
		{
			$o .= '<option value="' . $value . '"' . ((string)$selected === (string)$value ? ' selected="selected"' : '') . '>' . $label . '</option>';
		}
		return $o;
	}
	protected static function members_section($row, $state, $hidden)
	{
		// Who belongs to the account. A server administrator sees names and levels here, not the account's systems or results: to see those they join it (below).
		$aid = $row['AccountID'];
		$h = '<h2>Members</h2>';
		if(!phoromatic_memberships::ready())
		{
			return $h . '<p>' . self::h(phoromatic_server_roles::UNAVAILABLE) . '</p>';
		}
		$mine = false;
		$h .= '<div style="overflow-x: auto;"><table><tr><th>User</th><th>Level</th><th>Last sign-in</th><th></th></tr>';
		foreach(phoromatic_memberships::members($aid) as $m)
		{
			$uid = '<input type="hidden" name="user_id" value="' . self::h($m['UserID']) . '" />';
			$mine = $mine || (isset($_SESSION['UserID']) && (string)$_SESSION['UserID'] === (string)$m['UserID']);
			$h .= '<tr><td>' . self::h($m['UserName']) . ($m['ServerAdmin'] ? ' <em>(server administrator)</em>' : '') . ($m['IsHome'] ? '' : ' <em>(from another account)</em>') . ($m['DisabledOn'] !== null ? ' <em>(turned off on the server)</em>' : '') . '</td><td>' .
				phoromatic_server_console::form('member_level', $hidden . $uid . '<select name="level">' . self::level_options($m['AdminLevel'] > 0 ? $m['AdminLevel'] : 'off', true) . '</select>', 'Change') . '</td><td>' .
				self::h(empty($m['LastLogin']) ? 'Never' : phoromatic_server::user_friendly_timedate($m['LastLogin'])) . '</td><td>' . phoromatic_server_console::form('member_remove', $hidden . $uid, 'Remove from account') . '</td></tr>';
		}
		$h .= '</table></div>';
		$h .= '<h3>Add an existing user</h3><p>The user keeps their own sign-in and works in this account at the level you pick. An account\'s own administrators cannot add users from other accounts.</p>' .
			phoromatic_server_console::form('member_add', $hidden . '<p><label>User name <input type="text" name="user_name" /></label> <select name="level">' . self::level_options('10') . '</select></p>', 'Add to account');
		if(!phoromatic_server_console::is_root() && $state === 'active')
		{
			$h .= '<h3>See this account\'s data</h3>';
			if($mine)
			{
				$h .= '<p>You are a member of this account.</p>' . phoromatic_server_console::form('leave_tenant', $hidden, 'Leave this account');
			}
			else
			{
				$h .= '<p>To look at this account\'s systems and results you join it as a member. It is recorded in the server audit and in the account\'s own audit, and its administrators see you in their list of users. You can leave again from this page.</p>' .
					phoromatic_server_console::form('join_tenant', $hidden . '<p><label>Join as <select name="level">' . self::level_options('10') . '</select></label></p>', 'Join this account');
			}
		}
		return $h;
	}
}

?>
