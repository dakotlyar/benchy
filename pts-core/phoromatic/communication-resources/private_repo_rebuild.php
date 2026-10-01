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
// Recorded inside the repository lock before the rebuild; if the audit trail cannot take it the rebuild does not run
$attempt = function() {
	return phoromatic_private_repo_audit::record(ACCOUNT_ID, 'machine', SYSTEM_ID, 'rebuild_attempt');
};
$rebuild_finished = false;
$reply_sent = false;
// $rebuild_finished is set as soon as the final audit row is written; a later failure (the list refresh) must not add a second row
register_shutdown_function(function() use (&$rebuild_finished, &$reply_sent, &$json) {
	if($reply_sent)
	{
		return;
	}
	if($rebuild_finished)
	{
		echo json_encode($json);
		return;
	}
	$last = error_get_last();
	phoromatic_private_repo_audit::record(ACCOUNT_ID, 'machine', SYSTEM_ID, 'rebuild_failed', array('detail' => array('error' => 'aborted by a fatal error or timeout' . ($last ? ': ' . $last['message'] . ' (' . basename($last['file']) . ':' . $last['line'] . ')' : ''))));
	echo json_encode(array('phoromatic' => array('error' => 'The server stopped while rebuilding the index.')));
});
$index = phoromatic_private_repo::rebuild_index(ACCOUNT_ID, $attempt);
if(is_array($index) && !empty($index['audit_failed']))
{
	$json['phoromatic']['error'] = 'The audit log could not be written, so the index was not rebuilt.';
	phoromatic_private_repo_audit::record(ACCOUNT_ID, 'machine', SYSTEM_ID, 'rebuild_failed', array('detail' => array('error' => 'audit attempt could not be written')));
	$rebuild_finished = true;
}
else if(!is_array($index) || !isset($index['tests']))
{
	$json['phoromatic']['error'] = 'Unable to rebuild the index; check the server storage permissions.';
	phoromatic_private_repo_audit::record(ACCOUNT_ID, 'machine', SYSTEM_ID, 'rebuild_failed', array('detail' => array('error' => $json['phoromatic']['error'])));
	$rebuild_finished = true;
}
else
{
	$json['phoromatic']['rebuild'] = array('tests' => count($index['tests']), 'suites' => count($index['suites']));
	if(!empty(phoromatic_private_repo::$last_skipped))
	{
		// Archives left out because their metadata is missing or invalid
		$json['phoromatic']['rebuild']['skipped'] = phoromatic_private_repo::$last_skipped;
	}
	// Recorded before the repository list refresh
	if(!phoromatic_private_repo_audit::record(ACCOUNT_ID, 'machine', SYSTEM_ID, 'rebuild', array('detail' => $json['phoromatic']['rebuild'])))
	{
		$json['phoromatic']['warning'] = 'The rebuild succeeded but its completion could not be written to the audit log.';
	}
	$rebuild_finished = true;
	pts_openbenchmarking::refresh_repository_lists(array(phoromatic_private_repo::repository_name()), true);
}
$reply_sent = true;
echo json_encode($json);

?>
