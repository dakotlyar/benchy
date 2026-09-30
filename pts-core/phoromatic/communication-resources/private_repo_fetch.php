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
$kind = isset($_POST['kind']) && is_string($_POST['kind']) ? $_POST['kind'] : null;
$id = isset($_POST['id']) && is_string($_POST['id']) ? $_POST['id'] : null;

$package = phoromatic_private_repo::read_package(ACCOUNT_ID, $kind, $id);
if($package == false)
{
	$json['phoromatic']['error'] = 'No such ' . $kind . ' in the private repository.';
}
else
{
	$json['phoromatic']['sha1'] = $package[0];
	$json['phoromatic']['zip'] = base64_encode($package[1]);
	pts_logger::add_to_log(SYSTEM_ID . ' - private repo fetch of ' . $kind . ' ' . $id);
}
echo json_encode($json);

?>
