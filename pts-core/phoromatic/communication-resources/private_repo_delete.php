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
// The reply is JSON; a PHP warning or notice printed in front of it would corrupt it
ini_set('display_errors', '0');
$kind = isset($_POST['kind']) && is_string($_POST['kind']) ? $_POST['kind'] : null;
$name = isset($_POST['name']) && is_string($_POST['name']) ? $_POST['name'] : null;
$version = isset($_POST['version']) && is_string($_POST['version']) ? $_POST['version'] : null;

$fields = function($audit, $detail = array()) use ($kind, $name, $version) {
	return array('kind' => $kind, 'name' => $name, 'version' => $version,
		'sha256' => isset($audit['sha256']) ? $audit['sha256'] : null, 'sha1' => isset($audit['sha1']) ? $audit['sha1'] : null, 'detail' => $detail);
};
$attempt = function($audit) use ($fields) {
	return phoromatic_private_repo_audit::record(ACCOUNT_ID, 'machine', SYSTEM_ID, 'delete_attempt', $fields($audit));
};
$delete_finished = false;
register_shutdown_function(function() use (&$delete_finished, $kind, $name, $version) {
	if($delete_finished)
	{
		return;
	}
	$last = error_get_last();
	$moved = phoromatic_private_repo::valid_kind($kind) && phoromatic_private_repo::valid_name($name) && phoromatic_private_repo::valid_version($version) && !phoromatic_private_repo::version_exists(ACCOUNT_ID, $kind, $name, $version);
	phoromatic_private_repo_audit::record(ACCOUNT_ID, 'machine', SYSTEM_ID, $moved ? 'delete' : 'delete_failed', array('kind' => $kind, 'name' => $name, 'version' => $version,
		'detail' => array('error' => 'aborted by a fatal error or timeout' . ($last ? ': ' . $last['message'] . ' (' . basename($last['file']) . ':' . $last['line'] . ')' : ''), 'partial' => $moved)));
	echo json_encode(array('phoromatic' => array('error' => 'The server stopped while handling the delete.')));
});
$result = phoromatic_private_repo::delete(ACCOUNT_ID, $kind, $name, $version, $attempt);
$audit = isset($result['audit']) ? $result['audit'] : array();
if(isset($result['error']))
{
	$json['phoromatic']['error'] = $result['error'];
	// A version that was already moved to the trash is a (partial) delete; anything else changed nothing
	phoromatic_private_repo_audit::record(ACCOUNT_ID, 'machine', SYSTEM_ID, !empty($result['done']) ? 'delete' : 'delete_failed', $fields($audit, array('error' => $result['error'], 'partial' => !empty($result['done']), 'trash' => isset($audit['trash']) ? $audit['trash'] : null)));
}
else
{
	$json['phoromatic']['delete'] = $result['delete'];
	if(!phoromatic_private_repo_audit::record(ACCOUNT_ID, 'machine', SYSTEM_ID, 'delete', $fields($audit, array('trash' => $audit['trash']))))
	{
		$json['phoromatic']['warning'] = 'The delete succeeded but its completion could not be written to the audit log.';
	}
	phoromatic_private_repo::sync_server_cache($kind, $name, $version);
}
$delete_finished = true;
echo json_encode($json);

?>
