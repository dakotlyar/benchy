<?php

/*
	Benchy
	phoromatic_r_tenant_switch.php: the account switcher's POST target (a user who belongs to more than one account moves to another)

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

// POST only, from this site, with the session's token. The membership and the account's state are read again here; nothing is trusted from the page the
// form was on. Whatever the outcome the person lands on the main page, since the ids on the page they came from belong to the account they left.

class phoromatic_r_tenant_switch implements pts_webui_interface
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
	public static function render_page_process($PATH)
	{
		ini_set('display_errors', '0');
		ob_start();
		phoromatic_private_repo_web::security_headers();
		if($_SERVER['REQUEST_METHOD'] != 'POST')
		{
			header('Allow: POST');
			http_response_code(405);
			ob_end_flush();
			echo 'POST only.';
			return;
		}
		if(!phoromatic_private_repo_web::origin_ok() || !verify_submission_token())
		{
			http_response_code(403);
			ob_end_flush();
			echo 'This request was blocked because it did not appear to come from this site. Go back, reload the page and try again.';
			return;
		}
		$error = isset($_POST['account_id']) && is_string($_POST['account_id']) ? phoromatic_auth::switch_account($_POST['account_id']) : 'There is no such account.';
		if($error !== null)
		{
			$_SESSION['TenantNotice'] = $error;
		}
		while(ob_get_level() > 0)
		{
			ob_end_clean();
		}
		header('Location: /?main');
	}
}

?>
