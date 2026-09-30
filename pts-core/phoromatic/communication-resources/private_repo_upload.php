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
$name = isset($_POST['name']) && is_string($_POST['name']) ? $_POST['name'] : null;
foreach(array('zip', 'zip_sha1', 'commit_description') as $field)
{
	if(isset($_POST[$field]) && !is_string($_POST[$field]))
	{
		$json['phoromatic']['error'] = 'Malformed request.';
		echo json_encode($json);
		return false;
	}
}

if(empty($_POST['zip']) && !empty($_SERVER['CONTENT_LENGTH']))
{
	$json['phoromatic']['error'] = 'The upload was empty or exceeded the server post_max_size.';
	echo json_encode($json);
	return false;
}

$result = phoromatic_private_repo::upload(ACCOUNT_ID, SYSTEM_ID, $kind, $name,
	isset($_POST['zip']) ? $_POST['zip'] : '',
	isset($_POST['zip_sha1']) ? $_POST['zip_sha1'] : '',
	isset($_POST['commit_description']) ? substr($_POST['commit_description'], 0, 1000) : '',
	!empty($_POST['overwrite']));

if(isset($result['error']))
{
	$json['phoromatic']['error'] = $result['error'];
	pts_logger::add_to_log(SYSTEM_ID . ' - private repo upload of ' . $kind . ' ' . $name . ' rejected: ' . $result['error']);
}
else
{
	$json['phoromatic']['upload'] = $result['upload'];
	phoromatic_private_repo::sync_server_cache($kind, $name, substr($result['upload']['id'], strrpos($result['upload']['id'], '-') + 1));
	pts_logger::add_to_log(SYSTEM_ID . ' - private repo upload of ' . $result['upload']['id']);
}
echo json_encode($json);

?>
