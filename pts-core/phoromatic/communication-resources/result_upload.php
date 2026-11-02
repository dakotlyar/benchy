<?php

/*
	Phoronix Test Suite
	URLs: http://www.phoronix.com, http://www.phoronix-test-suite.com/
	Copyright (C) 2009 - 2015, Phoronix Media
	Copyright (C) 2009 - 2015, Michael Larabel

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
//error_reporting(E_ALL | E_NOTICE | E_STRICT);

$json = array();

if($COMPOSITE_XML != null)
{
	$composite_xml = base64_decode($_POST['composite_xml']);
}
else if($COMPOSITE_XML_GZ != null && function_exists('gzinflate'))
{
	$composite_xml = gzinflate(base64_decode($_POST['composite_xml_gz']));
}
else
{
	$composite_xml = null;
}

if($composite_xml == null || sha1($composite_xml) != $COMPOSITE_XML_HASH)
{
	$json['phoromatic']['error'] = 'XML Hash Mismatch';
	echo json_encode($json);
	return false;
}

// Everything past the transport (schema check, bench attribution, rows, mails) lives in phoromatic_result_ingest, which the native
// agent's result endpoint calls as well
$progressive_upload = isset($_POST['progressive_upload']) ? $_POST['progressive_upload'] : false;
$system_logs = ($SYSTEM_LOGS_ZIP != null && $SYSTEM_LOGS_HASH != null) ? array($SYSTEM_LOGS_ZIP, $SYSTEM_LOGS_HASH) : null;
$stored = phoromatic_result_ingest::store(ACCOUNT_ID, SYSTEM_ID, $composite_xml, $SCHEDULE_ID, $BENCHMARK_TICKET_ID, $TRIGGER_STRING, $ELAPSED_TIME, $progressive_upload, $system_logs);

if(!$stored['ok'])
{
	$json['phoromatic']['error'] = $stored['error'];
	echo json_encode($json);
	return false;
}

$json['phoromatic']['upload_id'] = $stored['upload_id'];
$json['phoromatic']['response'] = 'Result Upload: ' . $stored['upload_id'];
echo json_encode($json);
if($progressive_upload != 1)
{
	phoromatic_result_ingest::notify(ACCOUNT_ID, SYSTEM_ID, $stored['upload_id'], $stored['title']);
}
return true;

?>
