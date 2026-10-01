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

$commit_description = isset($_POST['commit_description']) && is_string($_POST['commit_description']) ? substr($_POST['commit_description'], 0, 1000) : '';
$audit_fields = function($audit, $detail = array()) use ($kind, $name, $commit_description) {
	return array('kind' => $kind, 'name' => $name,
		'version' => isset($audit['version']) ? $audit['version'] : null,
		'sha256' => isset($audit['sha256']) ? $audit['sha256'] : null,
		'sha1' => isset($audit['sha1']) ? $audit['sha1'] : null,
		'prev_sha256' => isset($audit['prev_sha256']) ? $audit['prev_sha256'] : null,
		'detail' => array_merge(array('commit_description' => $commit_description, 'source' => 'cli'), $detail));
};
// Written inside the repository lock before anything is stored: if the audit trail cannot take it, nothing is stored
$attempt = function($audit) use ($audit_fields) {
	return phoromatic_private_repo_audit::record(ACCOUNT_ID, 'machine', SYSTEM_ID, 'upload_attempt', $audit_fields($audit, array('warnings' => $audit['warnings'], 'replaces' => isset($audit['prev_sha256']))));
};

$result = phoromatic_private_repo::upload(ACCOUNT_ID, SYSTEM_ID, $kind, $name,
	isset($_POST['zip']) ? $_POST['zip'] : '',
	isset($_POST['zip_sha1']) ? $_POST['zip_sha1'] : '',
	$commit_description,
	!empty($_POST['overwrite']),
	$attempt);

$audit = isset($result['audit']) ? $result['audit'] : array();
if(isset($result['error']))
{
	$json['phoromatic']['error'] = $result['error'];
	if(!empty($result['stored']))
	{
		// In the store despite the error (e.g. the index rebuild failed): the record must say it is there
		phoromatic_private_repo_audit::record(ACCOUNT_ID, 'machine', SYSTEM_ID, 'upload_committed', $audit_fields($audit, array('partial' => true, 'error' => $result['error'])));
	}
	else
	{
		$action = !empty($result['attempted']) ? 'upload_failed' : 'upload_rejected';
		phoromatic_private_repo_audit::record(ACCOUNT_ID, 'machine', SYSTEM_ID, $action, $audit_fields($audit, array('error' => $result['error'], 'trash' => isset($audit['trash']) ? $audit['trash'] : null)));
	}
}
else
{
	$json['phoromatic']['upload'] = $result['upload'];
	// Recorded before the cache sync so a sync problem cannot leave an unrecorded upload
	if(!phoromatic_private_repo_audit::record(ACCOUNT_ID, 'machine', SYSTEM_ID, 'upload_committed', $audit_fields($audit, array('warnings' => $audit['warnings'], 'entries' => $audit['entries'], 'replaced' => $result['upload']['replaced'], 'trash' => isset($audit['trash']) ? $audit['trash'] : null))))
	{
		$json['phoromatic']['upload']['warnings'][] = 'The upload succeeded but its completion could not be written to the audit log.';
	}
	phoromatic_private_repo::sync_server_cache($kind, $name, $audit['version']);
}
echo json_encode($json);

?>
