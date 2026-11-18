<?php

/*
	Benchy
	phoromatic_server_settings.php: ?server_settings, the settings that apply to every account on the server (0.9.13). For server administrators
	at any account level: the role is checked here and again by every form's own handler, never inferred from the level. The root login
	reaches the same sections on ?admin_config (the router sends it away from a page without "admin" in its name).

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

class phoromatic_server_settings implements pts_webui_interface
{
	public static function page_title()
	{
		return 'Server settings';
	}
	public static function page_header()
	{
		return null;
	}
	public static function preload($PAGE)
	{
		return true;
	}
	public static function render_page_process($PATH)
	{
		if(!phoromatic_auth::is_server_admin())
		{
			// Nothing below runs for anyone else, so a forged POST changes nothing
			echo phoromatic_webui_header_logged_in();
			echo '<div id="pts_phoromatic_main_area"><h1>Server settings</h1><p>Server settings are for server administrators.</p></div>';
			echo phoromatic_webui_footer();
			return;
		}

		// A policy change is applied before the page is drawn, so the forms below already follow it
		$policy_message = phoromatic_server_policy::process_post();
		$https_message = phoromatic_tls_web::process_post();

		$main = '<h1>Server settings</h1>
			<p>These apply to every account on this server. Changes go to the server audit (<code>phoromatic-server-audit.log</code>). Account options are on the <a href="?settings">Settings</a> page.</p>' .
			'<p><a href="?server_catalog">Test catalog</a>: the signed tests every account shares.</p>' . (count(phoromatic_catalog::notices()) > 0 ? '<p style="color: #b00020; font-weight: bold;">The test catalog needs attention. See the <a href="?server_catalog">Test catalog</a> page.</p>' : '') .
			(phoromatic_passwords::dropped() && ($old_copies = phoromatic_passwords::old_copies()) > 0 ? '<p style="color: #b00020; font-weight: bold;">' . $old_copies . ' older database cop' . ($old_copies == 1 ? 'y' : 'ies') .
				' in <code>storage/backups/</code> still ' . ($old_copies == 1 ? 'holds' : 'hold') . ' the old password hashes. Delete ' . ($old_copies == 1 ? 'it' : 'them') . ' once you are sure you will not go back to 0.9.15 or older.</p>' : '') .
			phoromatic_server_policy::render_all('server_settings', $policy_message, $https_message);

		echo phoromatic_webui_header_logged_in();
		echo '<div id="pts_phoromatic_main_area">' . $main . '</div>';
		echo phoromatic_webui_footer();
	}
}

?>
