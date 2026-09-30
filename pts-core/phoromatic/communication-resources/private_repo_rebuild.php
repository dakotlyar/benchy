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
$index = phoromatic_private_repo::rebuild_index(ACCOUNT_ID);
if(!is_array($index))
{
	$json['phoromatic']['error'] = 'Unable to rebuild the index; check the server storage permissions.';
	echo json_encode($json);
	return false;
}
$json['phoromatic']['rebuild'] = array('tests' => count($index['tests']), 'suites' => count($index['suites']));
pts_openbenchmarking::refresh_repository_lists(array(phoromatic_private_repo::repository_name()), true);
pts_logger::add_to_log(SYSTEM_ID . ' - private repo index rebuilt');
echo json_encode($json);

?>
