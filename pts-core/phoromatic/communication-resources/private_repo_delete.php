<?php

/*
	Benchy
	Private test profile / test suite repository (Phoromatic Server).

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

$json = array();
$kind = isset($_POST['kind']) ? $_POST['kind'] : null;
$name = isset($_POST['name']) ? $_POST['name'] : null;
$version = isset($_POST['version']) ? $_POST['version'] : null;

$result = phoromatic_private_repo::delete(ACCOUNT_ID, $kind, $name, $version);
if(isset($result['error']))
{
	$json['phoromatic']['error'] = $result['error'];
}
else
{
	$json['phoromatic']['delete'] = $result['delete'];
	pts_logger::add_to_log(SYSTEM_ID . ' - private repo delete of ' . $result['delete']['id']);
}
echo json_encode($json);

?>
