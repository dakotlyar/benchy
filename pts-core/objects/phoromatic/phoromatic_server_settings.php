<?php

/*
	Benchy
	phoromatic_server_settings.php: the phone-home policy form, shared by the Settings page (the single-account server's level-1 user) and ?admin_config (root)

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

class phoromatic_server_settings
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
			if(($error = phoromatic_server::set_tenant_auto_approve_policy($_POST['tenant_auto_approve'], $_SESSION['AccountID'], 'user',
				(isset($_SESSION['UserName']) ? $_SESSION['UserName'] : '?'), 'UserID ' . (isset($_SESSION['UserID']) ? $_SESSION['UserID'] : '?'))) !== null)
			{
				return '<p style="color: red;"><strong>' . htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</strong></p>';
			}
			phoromatic_add_activity_stream_event('settings', null, 'modified');
			return '<p><strong>Saved. The new auto-approve policy applies to the next system that registers.</strong></p>';
		}
		if(!isset($_POST['client_phone_home']))
		{
			return '';
		}
		if(!is_string($_POST['client_phone_home']) || !phoromatic_private_repo_web::origin_ok() || !verify_submission_token())
		{
			return '<p style="color: red;"><strong>The form expired or was not submitted from this site; the phone-home policy was not changed. Please try again.</strong></p>';
		}
		if(($error = phoromatic_server::set_client_phone_home_policy($_POST['client_phone_home'], $_SESSION['AccountID'], 'user',
			(isset($_SESSION['UserName']) ? $_SESSION['UserName'] : '?'), 'UserID ' . (isset($_SESSION['UserID']) ? $_SESSION['UserID'] : '?'))) !== null)
		{
			return '<p style="color: red;"><strong>' . htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</strong></p>';
		}
		phoromatic_add_activity_stream_event('settings', null, 'modified');
		return '<p><strong>Saved. Clients pick up the new policy at their next check-in.</strong></p>';
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
		return '<form name="tenant_auto_approve_form" id="tenant_auto_approve_form" action="?' . htmlspecialchars($page, ENT_QUOTES) . '" method="post">' . write_token_in_form() . '
<hr /><h2>Accounts May Auto-Approve New Systems</h2>' . $message_auto . '<p>This applies to every account on this Phoromatic Server. With <em>No</em> (the default) every new system that registers waits for an administrator of its account to approve it, whatever the account\'s own setting says, and switching to it turns every account\'s own setting off. With <em>Yes</em> an account\'s administrators may turn auto-approve on for their own account. Native agents enrolled with a code an administrator made are approved either way.</p>
				<p><label><input type="radio" name="tenant_auto_approve" value="forbidden"' . ($auto_forbidden ? ' checked="checked"' : '') . ' /> No (forbidden)</label><br />
				<label><input type="radio" name="tenant_auto_approve" value="allowed"' . ($auto_forbidden ? '' : ' checked="checked"') . ' /> Yes (allowed)</label></p>
				<p><input type="submit" value="Save Auto-Approve Policy" /></p>
				</form>
				<form name="client_phone_home_form" id="client_phone_home_form" action="?' . htmlspecialchars($page, ENT_QUOTES) . '" method="post">' . write_token_in_form() . '
<hr /><h2>Clients May Phone Home</h2>' . $message . '<p>This applies to every client of this Phoromatic Server, in all accounts. With <em>No</em> (the default) clients keep NoPhoneHome on whatever their own configuration says, for local runs too: no anonymous usage reports, no OpenBenchmarking.org system ID, no ob_auto_compare and no automatic result uploads to OpenBenchmarking.org (the account setting for that is hidden). With <em>Client\'s choice</em> each client follows its own NoPhoneHome setting, which is on unless set to FALSE. Clients pick up a change at their next check-in.</p>
				<p><label><input type="radio" name="client_phone_home" value="forbidden"' . ($forbidden ? ' checked="checked"' : '') . ' /> No (forbidden)</label><br />
				<label><input type="radio" name="client_phone_home" value="client_choice"' . ($forbidden ? '' : ' checked="checked"') . ' /> Client\'s choice</label></p>
				<p><input type="submit" value="Save Phone-Home Policy" /></p>
				</form>';
	}
}

?>
