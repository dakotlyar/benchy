<?php

/*
	Benchy
	router.php: the router script of the PHP built-in web server (php -S ... -t public_html router.php), E4.4 C3.

	The built-in server answers 404 for a path whose last part has an extension and is no file, without ever looking for an index.php, but
	the signed catalog's route, GET /api/v2/catalog/{tier}/index.signed, is such a path and the agent's contract fixes it. This sends the
	catalog routes to the API front controller; every other request is returned to the server untouched (return false), so pages, files,
	the other API routes and everything else behave exactly as they did without a router.

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

$benchy_path = (string)parse_url(isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/', PHP_URL_PATH);
if(strpos($benchy_path, '/api/v2/catalog/') === 0)
{
	// php -S doesn't run auto_prepend_file for a request the router handles itself (it does for the script it hands back with return false), so the
	// HTTPS-mode proxy guard is run here; it does nothing without the proxy secret, and nothing the second time (it is idempotent)
	require_once(dirname(__FILE__) . '/proxy_prepend.php');
	include(dirname(__FILE__) . '/public_html/api/v2/index.php');
	return true;
}
return false;

?>
