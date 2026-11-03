<?php

/*
	Benchy
	api/v2/index.php: the front controller of the native agent API (protocol v2). Every /api/v2/... request lands here: the PHP built-in
	server runs the nearest index.php for a path that is no file, and a web server in front only has to pass the path on. The route is read
	from REQUEST_URI, never PATH_INFO. All the logic is in objects/phoromatic/phoromatic_api_v2.php.

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

// nothing PHP says may end up in a reply body: the agent expects JSON
ini_set('display_errors', '0');

define('PHOROMATIC_SERVER', true);
define('PTS_MODE', 'WEB_CLIENT');
define('PTS_AUTO_LOAD_OBJECTS', true);
error_reporting(E_ALL);

include(dirname(__FILE__) . '/../../../../pts-core.php');
pts_core::init();
phoromatic_api_v2::run();

?>
