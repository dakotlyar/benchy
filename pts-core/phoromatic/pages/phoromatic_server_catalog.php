<?php

/*
	Benchy
	phoromatic_server_catalog.php: ?server_catalog, the signed test catalog for server administrators at any account level (E4.4 C5, server design 8.1). The
	role is checked here and again by the upload's own handler, never inferred from the level. The root login reaches the same section on ?admin_config.

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

class phoromatic_server_catalog implements pts_webui_interface
{
	public static function page_title()
	{
		return 'Test catalog';
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
			// Nothing below runs for anyone else, so a forged upload changes nothing
			phoromatic_server_console::refused_page('Test catalog');
			return;
		}
		// An import is applied before the page is drawn, so the tables below already show it
		$message = phoromatic_catalog_web::process_post();
		$main = phoromatic_server_console::nav('catalog') . '<h1>Test catalog</h1>' . phoromatic_catalog_web::render_section('server_catalog', $message);
		echo phoromatic_webui_header_logged_in();
		echo '<div id="pts_phoromatic_main_area">' . $main . '</div>';
		echo phoromatic_webui_footer();
	}
}

?>
