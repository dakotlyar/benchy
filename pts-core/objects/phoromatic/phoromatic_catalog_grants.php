<?php

/*
	Benchy
	phoromatic_catalog_grants.php: which catalogs each account may have (named catalogs, NC3, catalog spec 11.1): the grid on the Catalogs page and the audited change

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

// `public` is always granted and has no row. Only a server administrator changes a grant (the page checks that before it calls apply()), every change is one row of the
// server audit, and the catalog's ChangedOn moves so an agent that already said hello says it again and picks up what it now may have (or stops being told what it may not).

class phoromatic_catalog_grants
{
	// The accounts the grid lists: every account that is not purged, in name order, as array(AccountID => display name)
	public static function accounts()
	{
		$out = array();
		$result = phoromatic_server::$db->query('SELECT AccountID, GroupName, State, PurgedOn FROM phoromatic_accounts ORDER BY GroupName COLLATE NOCASE, AccountID');
		while($result && ($r = $result->fetchArray(SQLITE3_ASSOC)))
		{
			if($r['PurgedOn'] === null || $r['PurgedOn'] === '')
			{
				$out[(string)$r['AccountID']] = phoromatic_tenant_admin::display_name($r);
			}
		}
		return $out;
	}
	// Every grant there is: array(AccountID => array(catalog => true))
	public static function all()
	{
		$out = array();
		$result = phoromatic_server::$db->query('SELECT AccountID, Catalog FROM phoromatic_catalog_grants');
		while($result && ($r = $result->fetchArray(SQLITE3_ASSOC)))
		{
			$out[(string)$r['AccountID']][(string)$r['Catalog']] = true;
		}
		return $out;
	}
	// The catalogs a grant can be for: those this server holds, except public
	public static function catalogs()
	{
		return phoromatic_catalog::order_tiers(array_values(array_filter(array_keys(phoromatic_catalog::tier_rows()), function($t) { return $t !== 'public'; })));
	}

	// Applies a wanted set of grants in one transaction. $want is array(AccountID => array(catalog => true)) for the accounts and catalogs the grid showed ($accounts, $catalogs);
	// only those cells can change. Returns array(changed count, null) or array(0, why).
	public static function apply($want, $accounts, $catalogs, $actor_type, $actor, $actor_detail)
	{
		if(!phoromatic_catalog::grants_ready())
		{
			return array(0, 'The server\'s database has not been upgraded for catalog grants yet. Start the server once on this version, then try again.');
		}
		$db = phoromatic_server::$db;
		$have = self::all();
		$known_accounts = self::accounts();
		$known_catalogs = self::catalogs();
		$changes = array();
		foreach($accounts as $account)
		{
			if(!isset($known_accounts[$account]))
			{
				continue;
			}
			foreach($catalogs as $catalog)
			{
				if(!in_array($catalog, $known_catalogs, true))
				{
					continue;
				}
				$wanted = !empty($want[$account][$catalog]);
				$had = !empty($have[$account][$catalog]);
				if($wanted !== $had)
				{
					$changes[] = array($account, $catalog, $wanted);
				}
			}
		}
		if(empty($changes))
		{
			return array(0, null);
		}
		if(@$db->exec('BEGIN IMMEDIATE') === false)
		{
			return array(0, 'The database is busy (' . $db->lastErrorMsg() . '). Nothing was changed.');
		}
		$now = phoromatic_server::current_time();
		try
		{
			foreach($changes as $c)
			{
				list($account, $catalog, $wanted) = $c;
				if($wanted)
				{
					$stmt = $db->prepare('INSERT OR REPLACE INTO phoromatic_catalog_grants (AccountID, Catalog, GrantedBy, GrantedOn) VALUES (:a, :c, :by, :now)');
					$stmt->bindValue(':by', (string)$actor);
					$stmt->bindValue(':now', $now);
				}
				else
				{
					$stmt = $db->prepare('DELETE FROM phoromatic_catalog_grants WHERE AccountID = :a AND Catalog = :c');
				}
				$stmt->bindValue(':a', $account);
				$stmt->bindValue(':c', $catalog);
				if(@$stmt->execute() === false)
				{
					throw new Exception($db->lastErrorMsg());
				}
				if(!phoromatic_server_audit::record($account, $actor_type, $actor, 'catalog_grant_changed', array('actor_detail' => $actor_detail, 'subject_type' => 'catalog_grant', 'subject_id' => $catalog,
					'subject_label' => $known_accounts[$account], 'detail' => array('account' => $account, 'catalog' => $catalog, 'granted' => $wanted))))
				{
					throw new Exception('the audit row could not be written: ' . phoromatic_server_audit::last_error());
				}
			}
			// (an agent's hello is older than this change when ChangedOn is later: its next poll asks it to say hello again)
			if(@$db->exec('INSERT OR IGNORE INTO phoromatic_catalog_state (Singleton, StateVersion, RetiredBelow) VALUES (1, ' . phoromatic_catalog::STATE_VERSION . ', 0)') === false)
			{
				throw new Exception($db->lastErrorMsg());
			}
			$stmt = $db->prepare('UPDATE phoromatic_catalog_state SET ChangedOn = :now WHERE Singleton = 1');
			$stmt->bindValue(':now', $now);
			if(@$stmt->execute() === false)
			{
				throw new Exception($db->lastErrorMsg());
			}
			if(@$db->exec('COMMIT') === false)
			{
				throw new Exception('commit failed: ' . $db->lastErrorMsg());
			}
		}
		catch(Exception $e)
		{
			@$db->exec('ROLLBACK');
			phoromatic_server_audit::discard();
			return array(0, 'The changes could not be saved and nothing was changed (' . $e->getMessage() . ').');
		}
		phoromatic_server_audit::flush();
		return array(count($changes), null);
	}
}

?>
