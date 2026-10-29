<?php

/*
	Benchy
	phoromatic_auth_lockout.php: the per-IP failure lockout shared by repository tokens and bench tokens

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

// Failures are the audit rows themselves (each token type has its own audit table and action, so its own counter): no extra state.

class phoromatic_auth_lockout
{
	public static function seconds_remaining($table, $action, $source_ip, $reasons, $max_failures, $window_seconds)
	{
		// 0 if fewer than $max_failures rows of $action with one of $reasons from this address in the window; else how long until the
		// oldest of the counted ones leaves the window
		if(phoromatic_server::$db == null || !in_array($table, array('phoromatic_private_repo_audit', 'phoromatic_admin_audit'), true))
		{
			return 0;
		}
		$like = array();
		$params = array(':ip' => $source_ip, ':since' => date('Y-m-d H:i:s', time() - (int)$window_seconds), ':action' => $action);
		foreach(array_values((array)$reasons) as $i => $reason)
		{
			$like[] = 'Detail LIKE :r' . $i;
			$params[':r' . $i] = '%"reason":"' . preg_replace('/[^a-z_]/', '', $reason) . '"%';
		}
		$stmt = @phoromatic_server::$db->prepare('SELECT EventTime FROM ' . $table . ' WHERE Action = :action AND SourceIP = :ip AND EventTime >= :since AND (' . implode(' OR ', $like) . ') ORDER BY AuditID DESC LIMIT ' . (int)$max_failures);
		if($stmt === false)
		{
			return 0;
		}
		foreach($params as $k => $v)
		{
			$stmt->bindValue($k, $v);
		}
		$result = $stmt->execute();
		$times = array();
		while($result && $row = $result->fetchArray(SQLITE3_ASSOC))
		{
			$times[] = strtotime($row['EventTime']);
		}
		return count($times) >= $max_failures ? max(1, min($times) + (int)$window_seconds - time()) : 0;
	}
}

?>
