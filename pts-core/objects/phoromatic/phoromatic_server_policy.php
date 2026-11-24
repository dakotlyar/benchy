<?php

/*
	Benchy
	phoromatic_server_policy.php: the server-wide sections of ?server_settings (server administrators) and ?admin_config (root): the auto-approve and phone-home policy forms, the shared download cache link and the commands that are set on the server only. (Named ...policy because phoromatic_server_settings is the page.)

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

class phoromatic_server_policy
{
	public static function process_post()
	{
		// HTML for the form's message line. A change needs a server administrator (read from the database now), POST, the CSRF token and a
		// same-origin request; it is applied before the page is drawn, so the account settings below already follow it.
		if(!phoromatic_auth::is_server_admin() || $_SERVER['REQUEST_METHOD'] != 'POST')
		{
			return '';
		}
		if(isset($_POST['tenant_auto_approve']))
		{
			if(!is_string($_POST['tenant_auto_approve']) || !phoromatic_private_repo_web::origin_ok() || !verify_submission_token())
			{
				return '<p style="color: red;"><strong>The form expired or was not submitted from this site; the auto-approve policy was not changed. Please try again.</strong></p>';
			}
			list($actor_account, $actor_type, $actor, $actor_detail) = phoromatic_auth::audit_actor();
			if(($error = phoromatic_server::set_tenant_auto_approve_policy($_POST['tenant_auto_approve'], $actor_account, $actor_type, $actor, $actor_detail)) !== null)
			{
				return '<p style="color: red;"><strong>' . htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</strong></p>';
			}
			phoromatic_add_activity_stream_event('settings', null, 'modified');
			return '<p><strong>Saved. The new auto-approve policy applies to the next system that registers.</strong></p>';
		}
		if(phoromatic_discovery::is_post())
		{
			return phoromatic_discovery::process_post();
		}
		if(!isset($_POST['client_phone_home']))
		{
			return '';
		}
		if(!is_string($_POST['client_phone_home']) || !phoromatic_private_repo_web::origin_ok() || !verify_submission_token())
		{
			return '<p style="color: red;"><strong>The form expired or was not submitted from this site; the phone-home policy was not changed. Please try again.</strong></p>';
		}
		list($actor_account, $actor_type, $actor, $actor_detail) = phoromatic_auth::audit_actor();
		if(($error = phoromatic_server::set_client_phone_home_policy($_POST['client_phone_home'], $actor_account, $actor_type, $actor, $actor_detail)) !== null)
		{
			return '<p style="color: red;"><strong>' . htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</strong></p>';
		}
		phoromatic_add_activity_stream_event('settings', null, 'modified');
		return '<p><strong>Saved. Clients pick up the new policy at their next check-in.</strong></p>';
	}
	public static function render_all($page, $policy_message, $https_message)
	{
		// The six server-wide sections, in this order, for ?server_settings and (above its root-only part) ?admin_config. Each renderer keeps the
		// checks of its own form; none of them needs the page to have checked the role first, and a user who is no server administrator gets ''.
		if(!phoromatic_auth::is_server_admin())
		{
			return '';
		}
		// The line a POST left goes under the form that was posted: the announce form's, or else the two policy forms'
		$discovery_posted = phoromatic_discovery::is_post();
		return self::render($page, $discovery_posted ? '' : $policy_message) . phoromatic_tls_web::render_section($page, $https_message) . phoromatic_discovery::render_section($page, $discovery_posted ? $policy_message : '') . phoromatic_native_admin::settings_section($page) . self::render_cache_link() . self::render_server_only() . phoromatic_server_update_web::render_section($page);
	}
	public static function render($page, $message)
	{
		if(!phoromatic_auth::is_server_admin())
		{
			return '';
		}
		$forbidden = phoromatic_server::client_phone_home_forbidden();
		$auto_forbidden = phoromatic_server::tenant_auto_approve_forbidden();
		// The line a POST left goes under the form that was posted
		$message_auto = isset($_POST['tenant_auto_approve']) ? $message : '';
		$message = isset($_POST['tenant_auto_approve']) ? '' : $message;
		$action = htmlspecialchars($page, ENT_QUOTES);
		return '<form name="tenant_auto_approve_form" id="tenant_auto_approve_form" action="?' . $action . '" method="post">' . write_token_in_form() . '
<hr /><h2>New systems, all accounts</h2>' . $message_auto . '
				<p><label><input type="radio" name="tenant_auto_approve" value="forbidden"' . ($auto_forbidden ? ' checked="checked"' : '') . ' /> Every new system waits for approval (default)</label><br />
				<label><input type="radio" name="tenant_auto_approve" value="allowed"' . ($auto_forbidden ? '' : ' checked="checked"') . ' /> Each account decides</label></p>
				<p>Choosing &quot;waits for approval&quot; also switches off every account\'s own auto-approve. Systems enrolled with a code are always approved.</p>
				<p><input type="submit" value="Save" /></p>
				</form>
				<form name="client_phone_home_form" id="client_phone_home_form" action="?' . $action . '" method="post">' . write_token_in_form() . '
<hr /><h2>Contact with OpenBenchmarking.org</h2>' . $message . '
				<p><label><input type="radio" name="client_phone_home" value="forbidden"' . ($forbidden ? ' checked="checked"' : '') . ' /> Clients never contact OpenBenchmarking.org (default)</label><br />
				<label><input type="radio" name="client_phone_home" value="client_choice"' . ($forbidden ? '' : ' checked="checked"') . ' /> Each client decides</label></p>
				<p>Covers usage reports, OpenBenchmarking system IDs, automatic comparisons and automatic uploads, for local runs too. With &quot;each client decides&quot; a client follows its own NoPhoneHome setting, which is on unless set to FALSE. Clients pick up a change at their next check-in.</p>
				<p><input type="submit" value="Save" /></p>
				</form>';
	}
	public static function render_cache_link()
	{
		return '<hr /><h2>Shared download cache</h2><p>Add files on the <a href="?caches">Download cache</a> page.</p>';
	}
	public static function render_server_only()
	{
		return '<hr /><h2>Set on the server only</h2><p>Run these on the server as the service user.</p>
<p>Accounts: <code>benchy server-account-create --user NAME</code><br />
Welcome message: <code>benchy server-registration closed --message TEXT</code><br />
Server administrators: <code>benchy server-admin-list</code>, <code>server-admin-grant</code>, <code>server-admin-revoke</code><br />
Server audit: entries go to <code>phoromatic-server-audit.log</code></p>';
	}
}

?>
