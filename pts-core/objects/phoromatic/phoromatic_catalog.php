<?php

/*
	Benchy
	phoromatic_catalog.php: what the server keeps of the signed test catalog (E4.4 C2, server design 2 and 4.1): the tables, the files, and what is served

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

// The catalog belongs to the server, not to an account: its tables have no AccountID and its files sit under <storage>/catalog/, outside
// accounts/, so a tenant purge cannot reach them. The tables hold exactly what the engine keeps in state.json (the pair, the ledger and the
// revocations, split by tier) plus the entries of each served index for the pages and the picker. phoromatic_catalog_import writes them.

class phoromatic_catalog
{
	const SCHEMA_STEP = 63;   // every "is the schema new enough" check uses this, so renumbering the step is one line
	const STATE_VERSION = 1;
	const TIERS = array('public', 'private');

	public static function schema_sql()
	{
		return array(
			'CREATE TABLE IF NOT EXISTS phoromatic_catalog_state (Singleton INTEGER PRIMARY KEY CHECK (Singleton = 1), StateVersion INTEGER NOT NULL, RetiredBelow INTEGER NOT NULL DEFAULT 0, ChangedOn TEXT, PackagedSHA256 TEXT, PackagedOutcome TEXT, PackagedOn TEXT)',
			'CREATE TABLE IF NOT EXISTS phoromatic_catalog_tiers (Tier TEXT PRIMARY KEY CHECK (Tier IN (\'public\', \'private\')), Generation INTEGER NOT NULL, Sequence INTEGER NOT NULL, SignedSHA256 TEXT NOT NULL, KeyID TEXT NOT NULL, Issued TEXT NOT NULL, MinGeneration INTEGER, TestCount INTEGER NOT NULL, SuiteCount INTEGER NOT NULL, RevokedCount INTEGER NOT NULL, BundleSHA256 TEXT NOT NULL, ImportedOn TEXT NOT NULL, ImportedBy TEXT NOT NULL, ImportedVia TEXT NOT NULL CHECK (ImportedVia IN (\'cli\', \'web\', \'package\')), ImportedAccount TEXT)',
			'CREATE TABLE IF NOT EXISTS phoromatic_catalog_ledger (Tier TEXT NOT NULL, Kind TEXT NOT NULL CHECK (Kind IN (\'test\', \'suite\')), Ref TEXT NOT NULL, Hash TEXT NOT NULL, PRIMARY KEY (Tier, Kind, Ref))',
			'CREATE TABLE IF NOT EXISTS phoromatic_catalog_revoked (Tier TEXT NOT NULL, Ref TEXT NOT NULL, Reason TEXT NOT NULL, PRIMARY KEY (Tier, Ref))',
			'CREATE TABLE IF NOT EXISTS phoromatic_catalog_entries (Tier TEXT NOT NULL, Kind TEXT NOT NULL CHECK (Kind IN (\'test\', \'suite\')), ID TEXT NOT NULL, Version TEXT NOT NULL, PackSHA256 TEXT, PackSize INTEGER, MinEngine TEXT NOT NULL, Platforms TEXT, LicenseClass TEXT, Title TEXT NOT NULL, MachineInstall TEXT, ElevatedRun INTEGER NOT NULL DEFAULT 0, EntryJSON TEXT NOT NULL, PRIMARY KEY (Tier, Kind, ID, Version))',
			'CREATE INDEX IF NOT EXISTS phoromatic_catalog_entries_pack ON phoromatic_catalog_entries (PackSHA256)');
	}
	public static function ready()
	{
		return phoromatic_server::$db != null && phoromatic_server::read_database_version() >= self::SCHEMA_STEP;
	}

	// ---- the files ----

	public static function dir($sub = '')
	{
		return phoromatic_server::phoromatic_path(false) . 'catalog/' . $sub;
	}
	public static function signed_path($sha)
	{
		return self::dir('signed/' . $sha . '.signed');
	}
	public static function pack_path($sha)
	{
		return self::dir('packs/' . $sha . '.zip');
	}
	public static function ensure_dirs()
	{
		foreach(array('', 'signed/', 'packs/', 'work/') as $sub)
		{
			$d = self::dir($sub);
			if(!is_dir($d) && !@mkdir($d, 0700) && !is_dir($d))
			{
				throw new phoromatic_catalog_refusal(phoromatic_catalog_refusal::STORAGE, 'The catalog folder ' . $d . ' could not be made.');
			}
			@chmod($d, 0700);
		}
	}

	// ---- the tables ----

	// Every row of a query, or false when it failed (a caller that deletes files on what it reads must tell "none" from "could not read")
	protected static function rows($sql, $binds = array())
	{
		if(!self::ready() || ($stmt = @phoromatic_server::$db->prepare($sql)) === false)
		{
			return false;
		}
		foreach($binds as $k => $v)
		{
			$stmt->bindValue($k, $v);
		}
		$result = @$stmt->execute();
		if($result === false)
		{
			return false;
		}
		$rows = array();
		while($row = $result->fetchArray(SQLITE3_ASSOC))
		{
			$rows[] = $row;
		}
		return $rows;
	}
	public static function tier_rows()
	{
		$rows = self::rows('SELECT * FROM phoromatic_catalog_tiers ORDER BY Tier');
		$out = array();
		foreach($rows ?: array() as $r)
		{
			$out[$r['Tier']] = $r;
		}
		return $out;
	}
	public static function state_row()
	{
		$rows = self::rows('SELECT * FROM phoromatic_catalog_state WHERE Singleton = 1');
		return $rows ? $rows[0] : array('StateVersion' => self::STATE_VERSION, 'RetiredBelow' => 0, 'ChangedOn' => null, 'PackagedSHA256' => null, 'PackagedOutcome' => null, 'PackagedOn' => null);
	}
	// The engine's state.json as the verifier takes it (phoromatic_catalog_verify's header says its shape), read from the tables. An integer
	// column that came back as anything else is the verifier's to refuse (state_corrupt), not this function's to fix.
	// A SELECT that fails is a refusal here, never "no rows": the rollback, ledger and revocation checks must not fail open on a table they could not read.
	protected static function strict_rows($sql)
	{
		$rows = self::rows($sql);
		if($rows === false)
		{
			throw new phoromatic_catalog_refusal(phoromatic_catalog_refusal::STATE_CORRUPT, 'the stored catalog state could not be read (' . substr($sql, 0, 60) . ')');
		}
		return $rows;
	}
	public static function state()
	{
		$state = phoromatic_catalog_verify::new_state();
		foreach(array('phoromatic_catalog_state', 'phoromatic_catalog_tiers', 'phoromatic_catalog_ledger', 'phoromatic_catalog_revoked') as $t)
		{
			self::strict_rows('SELECT 1 FROM ' . $t . ' LIMIT 1');
		}
		$state['retired_below'] = (int)self::state_row()['RetiredBelow'];
		foreach(self::strict_rows('SELECT * FROM phoromatic_catalog_tiers ORDER BY Tier') as $r)
		{
			$tier = $r['Tier'];
			$state['tiers'][$tier] = array('generation' => $r['Generation'], 'sequence' => $r['Sequence'], 'signed_sha256' => $r['SignedSHA256'], 'tests' => array(), 'suites' => array(), 'revoked' => array());
		}
		foreach(self::strict_rows('SELECT Tier, Kind, Ref, Hash FROM phoromatic_catalog_ledger') as $r)
		{
			if(isset($state['tiers'][$r['Tier']]))
			{
				$state['tiers'][$r['Tier']][$r['Kind'] === 'suite' ? 'suites' : 'tests'][$r['Ref']] = $r['Hash'];
			}
		}
		foreach(self::strict_rows('SELECT Tier, Ref FROM phoromatic_catalog_revoked ORDER BY Ref') as $r)
		{
			if(isset($state['tiers'][$r['Tier']]))
			{
				$state['tiers'][$r['Tier']]['revoked'][] = $r['Ref'];
			}
		}
		return $state;
	}
	public static function entries($tier, $kind = null)
	{
		return self::rows('SELECT * FROM phoromatic_catalog_entries WHERE Tier = :tier' . ($kind !== null ? ' AND Kind = :kind' : '') . ' ORDER BY Kind, ID, Version', $kind !== null ? array(':tier' => $tier, ':kind' => $kind) : array(':tier' => $tier)) ?: array();
	}
	public static function revoked($tier)
	{
		return self::rows('SELECT Ref, Reason FROM phoromatic_catalog_revoked WHERE Tier = :tier ORDER BY Ref', array(':tier' => $tier)) ?: array();
	}

	// ---- what is stored and what is served ----

	// The stored index.signed of a tier, or null when the file is missing or isn't the file the row names (a damaged file is no file)
	public static function stored_signed($tier)
	{
		$rows = self::tier_rows();
		if(!isset($rows[$tier]) || !preg_match('/^[0-9a-f]{64}$/', $rows[$tier]['SignedSHA256']) || !is_file($path = self::signed_path($rows[$tier]['SignedSHA256'])) || ($bytes = @file_get_contents($path)) === false)
		{
			return null;
		}
		return hash('sha256', $bytes) === $rows[$tier]['SignedSHA256'] ? $bytes : null;
	}
	// A stored pack file is whole enough when it is there at the size the index gave (the engine hashes every byte it takes)
	public static function pack_present($sha, $size)
	{
		return is_file($path = self::pack_path($sha)) && filesize($path) === (int)$size;
	}
	// array('served' => bool, 'why' => string|null) for one tier (server design 4.1): the row exists, its key isn't denied, its generation
	// isn't retired, and its stored file is there. The floor is not applied again, and the key need not be in the table: a planned rotation
	// that drops a key leaves a stored tier served.
	public static function tier_status($tier, $trust = null, $rows = null, $retired_below = null)
	{
		$rows = $rows === null ? self::tier_rows() : $rows;
		if(!isset($rows[$tier]))
		{
			return array('served' => false, 'why' => 'none');
		}
		$r = $rows[$tier];
		if($trust === null)
		{
			try
			{
				$trust = phoromatic_catalog_keys::load();
			}
			catch(phoromatic_catalog_refusal $e)
			{
				return array('served' => false, 'why' => 'key_table');
			}
		}
		$retired_below = $retired_below === null ? (int)self::state_row()['RetiredBelow'] : $retired_below;
		if($trust->is_denied($r['KeyID']))
		{
			return array('served' => false, 'why' => 'denied_key');
		}
		if($r['Generation'] < $retired_below)
		{
			return array('served' => false, 'why' => 'retired_key');
		}
		if(self::stored_signed($tier) === null)
		{
			return array('served' => false, 'why' => 'file_missing');
		}
		return array('served' => true, 'why' => null);
	}
	public static function served_tiers()
	{
		$out = array();
		foreach(array_keys(self::tier_rows()) as $tier)
		{
			if(self::tier_status($tier)['served'])
			{
				$out[] = $tier;
			}
		}
		return $out;
	}
	// How many packs the entries name that are missing or the wrong size on disk
	public static function missing_packs()
	{
		$n = 0;
		foreach(self::rows('SELECT DISTINCT PackSHA256, PackSize FROM phoromatic_catalog_entries WHERE PackSHA256 IS NOT NULL') ?: array() as $r)
		{
			$n += self::pack_present($r['PackSHA256'], $r['PackSize']) ? 0 : 1;
		}
		return $n;
	}

	// ---- notices (red lines on the server settings page, and on the catalog page when it exists) ----

	public static function notices()
	{
		$out = array();
		if(!self::ready())
		{
			return $out;
		}
		$state = self::state_row();
		if(!empty($state['PackagedOutcome']) && preg_match('/^refused \(\w+\): (.*)$/s', $state['PackagedOutcome'], $m))
		{
			$out[] = 'The catalog bundle that came with this version of Benchy was not imported: ' . rtrim($m[1], '.') . '. The catalog you had is unchanged.';
		}
		$labels = array('public' => 'free', 'private' => 'non-free');
		foreach(self::tier_rows() as $tier => $r)
		{
			$s = self::tier_status($tier, null, null, (int)$state['RetiredBelow']);
			if($s['served'])
			{
				continue;
			}
			$name = 'The ' . $labels[$tier] . ' catalog';
			$out[] = array('denied_key' => $name . ' was signed with a key that is no longer trusted. Import a newer release.',
				'retired_key' => $name . ' was signed with a retired key (generation ' . (int)$r['Generation'] . '). Import a newer release.',
				'file_missing' => $name . '\'s stored file is missing or damaged. Import the same bundle again to repair it.',
				'key_table' => 'The server\'s catalog key table is damaged, so the stored catalog is not served.')[$s['why']];
		}
		if(($n = self::missing_packs()) > 0)
		{
			$out[] = $n . ' test pack' . ($n == 1 ? ' is' : 's are') . ' missing from storage. Import the same bundle again to put ' . ($n == 1 ? 'it' : 'them') . ' back.';
		}
		return array_values(array_unique($out));
	}
	public static function notices_html()
	{
		$h = '';
		foreach(self::notices() as $n)
		{
			$h .= '<p style="color: #b00020; font-weight: bold;">' . htmlspecialchars($n, ENT_QUOTES) . '</p>';
		}
		return $h;
	}
}

?>
