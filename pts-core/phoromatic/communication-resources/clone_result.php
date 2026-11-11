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

// A result of the client's own account, handed over as the result file itself (base64 of the composite.xml). It used to be the path of
// the file on the server, which a client could only use on the server's own machine and which showed where the server keeps results.
$pprid = phoromatic_tenant_path::segment($ID, 64);
$stmt = phoromatic_server::$db->prepare('SELECT AccountID, UploadID FROM phoromatic_results WHERE AccountID = :account_id AND PPRID = :pprid LIMIT 1');
$stmt->bindValue(':account_id', ACCOUNT_ID);
$stmt->bindValue(':pprid', $pprid === false ? '' : $pprid);
$result = $stmt->execute();

if($result && ($row = $result->fetchArray()))
{
	$composite_xml = phoromatic_server::phoromatic_account_result_path($row['AccountID'], $row['UploadID']) . 'composite.xml';
	if(is_file($composite_xml) && ($contents = file_get_contents($composite_xml)) !== false)
	{
		$json['phoromatic']['result']['composite_xml'] = base64_encode($contents);
		echo json_encode($json);
		return;
	}
}

$json['phoromatic']['error'] = 'No such result in this account.';
echo json_encode($json);

?>
