<?php

/*
	Phoronix Test Suite
	URLs: http://www.phoronix.com, http://www.phoronix-test-suite.com/
	Copyright (C) 2015, Phoronix Media
	Copyright (C) 2015, Michael Larabel

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

// The client's own account only (results are not shared between accounts)
$stmt = phoromatic_server::$db->prepare('SELECT Title, SystemID, PPRID, UploadTime, AccountID FROM phoromatic_results WHERE AccountID = :account_id ORDER BY UploadTime DESC LIMIT 30');
$stmt->bindValue(':account_id', ACCOUNT_ID);
$result = $stmt->execute();
$results = array();
while($row = $result->fetchArray())
{
	$results[$row['PPRID']] = array(
		'Title' => $row['Title'],
		'SystemName' => phoromatic_server::system_id_to_name($row['SystemID'], ACCOUNT_ID),
		'UploadTime' => $row['UploadTime'],
		'GroupName' => phoromatic_server::account_id_to_group_name(ACCOUNT_ID),
		);
}

$json['phoromatic']['results'] = $results;
$json['phoromatic']['response'] = 'results';
echo json_encode($json);
?>
