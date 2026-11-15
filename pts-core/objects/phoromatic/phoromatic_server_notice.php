<?php

/*
	Benchy
	phoromatic_server_notice.php: the one-time notice a server shows after it was upgraded to tenants (0.9.14): what changed since before

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

// Who sees it: a user who is an account administrator (level 1) or a server administrator, who had a user row when the server was upgraded (a
// server that never was upgraded, because it was made new, has nothing to report; a user made later never knew the old way), and who has not
// dismissed it. A dismissal is a 'notice_dismissed' row in the server audit (the audit table is append-only, so the fact cannot be lost, and it
// needs no table of its own, which keeps a rollback clean). The root login is not a user and is shown nothing.

class phoromatic_server_notice
{
	const ID = 'upgrade-0.9.14';

	protected static function upgraded_at()
	{
		// When the server was upgraded to tenants: the first 'migration' row of the server audit (new databases write none), or null
		if(!phoromatic_server::tenancy_ready())
		{
			return null;
		}
		$time = phoromatic_server::$db->querySingle('SELECT MIN(EventTime) FROM phoromatic_server_audit WHERE Action = \'migration\' AND SubjectType = \'schema\'');
		return is_string($time) && $time !== '' ? $time : null;
	}
	public static function applies()
	{
		if(!isset($_SESSION['UserID']) || !isset($_SESSION['AccountID']) || (isset($_SESSION['AdminLevel']) && $_SESSION['AdminLevel'] == -40) || empty($_SESSION['CreatedOn']))
		{
			return false;
		}
		if(($upgraded = self::upgraded_at()) === null || (string)$_SESSION['CreatedOn'] > $upgraded)
		{
			return false;
		}
		if(phoromatic_auth::level($_SESSION['AccountID']) !== 1 && !phoromatic_auth::is_server_admin())
		{
			return false;
		}
		$stmt = phoromatic_server::$db->prepare('SELECT 1 FROM phoromatic_server_audit WHERE Action = \'notice_dismissed\' AND SubjectID = :user_id AND TenantID = :account_id AND SubjectLabel = :notice');
		$stmt->bindValue(':user_id', (string)$_SESSION['UserID']);
		$stmt->bindValue(':account_id', (string)$_SESSION['AccountID']);
		$stmt->bindValue(':notice', self::ID);
		$result = $stmt->execute();
		return !($result && $result->fetchArray());
	}
	public static function dismiss()
	{
		// null on success, else why not. Dismissing needs no more than the notice applying to this user.
		if(!self::applies())
		{
			return null;
		}
		list($account, $type, $actor, $detail) = phoromatic_auth::audit_actor();
		if(@phoromatic_server::$db->exec('BEGIN IMMEDIATE') === false)
		{
			return 'The database is busy; try again.';
		}
		if(!phoromatic_server_audit::record($account, $type, $actor, 'notice_dismissed', array('actor_detail' => $detail, 'subject_type' => 'user', 'subject_id' => (string)$_SESSION['UserID'], 'subject_label' => self::ID)) ||
			@phoromatic_server::$db->exec('COMMIT') === false)
		{
			@phoromatic_server::$db->exec('ROLLBACK');
			phoromatic_server_audit::discard();
			return 'It could not be saved; try again.';
		}
		phoromatic_server_audit::flush();
		return null;
	}
	public static function render()
	{
		// The box for the top of the main page, or ''
		if(!self::applies())
		{
			return '';
		}
		$server_admin = phoromatic_auth::is_server_admin();
		$h = '<div style="border: 2px solid #888; padding: 0 1em; margin: 1em 0;"><h2>What changed in this upgrade</h2>
			<p>This server now keeps accounts completely apart from each other. Here is what is different from before.</p>
			<ul>
			<li>The public results page is gone, and one account can no longer see another account\'s results.</li>
			<li>Results feed (RSS) links changed. Open the Results Feed link in the Results menu again and update your feed reader; the old links stop working.</li>
			<li>Self-registration is closed. A server administrator creates new accounts.</li>
			<li>New systems wait for approval. Systems that enroll with an enrollment code are still approved at once.</li>
			<li>The HTTPS, OpenBenchmarking.org contact and native agent connection settings moved to the server settings, which only server administrators can open.</li>
			<li>Each account now has its own private test repository, and nobody outside the account can see what is in it.</li>
			</ul>' . ($server_admin ? '<p>As a server administrator you manage accounts on the <a href="?server_tenants">Server</a> pages: create, rename, suspend, delete and restore them, and read the server audit.</p>' : '') .
			phoromatic_server_console::form('dismiss_notice', '', 'Dismiss') . '</div>';
		return $h;
	}
}

?>
