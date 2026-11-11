<?php

/*
	Phoronix Test Suite
	URLs: http://www.phoronix.com, http://www.phoronix-test-suite.com/
	Copyright (C) 2018, Phoronix Media
	Copyright (C) 2018, Michael Larabel

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

class phoromatic_create_test implements pts_webui_interface
{
	// The test profile wizard wrote into the profile store every account on the server shares (test-profiles/local/), so one account could
	// change what every other account lists and installs. It is gone: tests an account needs of its own go in its private repository.
	public static function page_title()
	{
		return 'Create Test Profile';
	}
	public static function page_header()
	{
		return null;
	}
	public static function preload($PATH)
	{
		return true;
	}
	public static function render_page_process($PATH)
	{
		http_response_code(404);
		echo phoromatic_webui_header_logged_in();
		echo phoromatic_webui_main('<h1>Not available</h1><p>Test profiles are no longer created on the server. Add the tests an account needs of its own to its <a href="?private_repository">private repository</a>.</p>', phoromatic_webui_right_panel_logged_in(null));
		echo phoromatic_webui_footer();
	}
}

?>
