<?php

/*
	Benchy
	Native suites (Benchy 0.10.0, catalog suites CS0-CS3): a suite of native tests (catalog tests, built-in and repository tests), kept in two account tables, and the one
	seam a schedule reads its native rows through (expanded_rows). Design: ideas/testing/benchy-catalog-suites.md.

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

// A suite belongs to one account: every read and write below is bound to AccountID, including the resolution of `native:<SuiteID>` at issue, so a SuiteID from
// another account resolves to nothing. A suite holds either catalog/native tests or (a different kind, in a different place) Phoronix profiles, never both.
// The server works the offer out again on every save; the page's own checks are for the person using it.

class phoromatic_native_suites
{
	const SCHEMA_STEP = 65;   // every "is the schema new enough" check uses this, so renumbering the step is one line
	const MAX_MEMBERS = 50;
	const PLATFORMS = array('linux', 'windows', 'macos');
	const NAMES = array('linux' => 'Linux', 'windows' => 'Windows', 'macos' => 'macOS');

	public static function schema_sql()
	{
		return array(
			'CREATE TABLE IF NOT EXISTS phoromatic_native_suites (AccountID TEXT NOT NULL, SuiteID INTEGER NOT NULL, Title TEXT NOT NULL, Description TEXT, Revision INTEGER NOT NULL DEFAULT 1, CreatedOn TEXT NOT NULL, CreatedBy TEXT NOT NULL, ChangedOn TEXT, ChangedBy TEXT, ' .
				'ConfirmedRevision INTEGER, ConfirmedOn TEXT, ConfirmedBy TEXT, ConfirmAuditID INTEGER, ConfirmedJSON TEXT, PRIMARY KEY (AccountID, SuiteID))',
			'CREATE UNIQUE INDEX IF NOT EXISTS phoromatic_native_suites_title ON phoromatic_native_suites (AccountID, Title COLLATE NOCASE)',
			'CREATE TABLE IF NOT EXISTS phoromatic_native_suite_members (AccountID TEXT NOT NULL, SuiteID INTEGER NOT NULL, Position INTEGER NOT NULL, Ref TEXT NOT NULL, Version TEXT, OptionsJSON TEXT NOT NULL DEFAULT \'{}\', UNIQUE (AccountID, SuiteID, Ref, OptionsJSON))');
	}
	public static function ready()
	{
		return phoromatic_server::$db != null && phoromatic_server::read_database_version() >= self::SCHEMA_STEP;
	}

	// ---- small helpers ----

	protected static function rows($sql, $binds = array())
	{
		if(($stmt = @phoromatic_server::$db->prepare($sql)) === false)
		{
			return array();
		}
		foreach($binds as $k => $v)
		{
			$stmt->bindValue($k, $v);
		}
		$result = @$stmt->execute();
		$rows = array();
		while($result && $row = $result->fetchArray(SQLITE3_ASSOC))
		{
			$rows[] = $row;
		}
		return $rows;
	}
	protected static function clean($text, $max = 200)
	{
		return phoromatic_catalog_sched::clean($text, $max);
	}
	// The platform name the catalog uses (linux, windows, macos) for the OS name a system reported at its last hello, or null when it isn't known. The engine runs on three platforms only.
	public static function os_platform($os_name)
	{
		$os = strtolower(trim((string)$os_name));
		if($os === '')
		{
			return null;
		}
		if(strpos($os, 'windows') !== false)
		{
			return 'windows';
		}
		if(strpos($os, 'mac') !== false || strpos($os, 'darwin') !== false || strpos($os, 'os x') !== false)
		{
			return 'macos';
		}
		return 'linux';
	}
	// The platform of a systems row, from its last hello (SystemProperties.benchy_system_info.os.name), or null
	public static function system_platform($sys)
	{
		$props = is_array($sys) && isset($sys['SystemProperties']) ? json_decode((string)$sys['SystemProperties'], true) : null;
		return is_array($props) && isset($props['benchy_system_info']['os']['name']) && is_string($props['benchy_system_info']['os']['name']) ? self::os_platform($props['benchy_system_info']['os']['name']) : null;
	}
	public static function platform_text($platforms)
	{
		$names = array();
		foreach($platforms as $p)
		{
			$names[] = isset(self::NAMES[$p]) ? self::NAMES[$p] : self::clean($p, 20);
		}
		return implode(', ', $names);
	}
	public static function lower_platforms($json)
	{
		return self::lower_list($json);
	}
	protected static function lower_list($json)
	{
		$list = is_array($json) ? $json : json_decode((string)$json, true);
		$out = array();
		foreach(is_array($list) ? $list : array() as $p)
		{
			if(is_string($p) && $p !== '')
			{
				$out[] = strtolower($p);
			}
		}
		return array_values(array_unique($out));
	}
	// A sentence for a test the builder can't take (section 8), or null. $install is the product a machine-wide install is for.
	public static function block_text($title, $install, $elevated)
	{
		if($install === null && !$elevated)
		{
			return null;
		}
		$what = array();
		if($install !== null)
		{
			$what[] = 'installs ' . $install . ' for all users';
		}
		if($elevated)
		{
			$what[] = 'runs as administrator';
		}
		return $title . ' ' . implode(' and ', $what) . ', and suites that need administrator rights come in a later release. Add it to a schedule on its own instead.';
	}

	// ---- the offer ----

	// What a suite of this account may be built from: array(catalog (ref => array(ref, title, tier, label, description, versions (version => array(platforms, options, install, elevated, min_engine)), order (newest first), block)),
	// reported (ref => array(ref, title, description, options, systems (names), platforms (list; every platform when none of the reporters has a known OS), block)), has_catalog)
	public static function offer($account_id)
	{
		$out = array('catalog' => array(), 'reported' => array(), 'has_catalog' => false);
		$held = array();
		if(phoromatic_catalog::ready())
		{
			$tiers = phoromatic_catalog::tiers_for_account($account_id);
			$out['has_catalog'] = !empty($tiers);
			foreach($tiers as $tier)
			{
				foreach(phoromatic_catalog::entries($tier, 'test') as $e)
				{
					$id = $e['ID'];
					$held[$id] = true;
					if(!phoromatic_catalog_sched::valid_version($e['Version']) || phoromatic_catalog_sched::withdrawn($id, $e['Version']) !== null)
					{
						continue;
					}
					$json = json_decode((string)$e['EntryJSON'], true);
					$m = is_array($json) && isset($json['manifest']) && is_array($json['manifest']) ? $json['manifest'] : array();
					$install = $e['MachineInstall'] !== null && $e['MachineInstall'] !== '' ? self::clean($e['MachineInstall'], 80) : null;
					if(!isset($out['catalog'][$id]))
					{
						$out['catalog'][$id] = array('ref' => $id, 'title' => self::clean($e['Title'], 120), 'tier' => $e['Tier'], 'description' => '', 'versions' => array(), 'order' => array(), 'block' => null);
					}
					$out['catalog'][$id]['versions'][$e['Version']] = array('platforms' => self::lower_list($e['Platforms']), 'options' => isset($m['options']) && is_array($m['options']) ? $m['options'] : array(),
						'install' => $install, 'elevated' => (int)$e['ElevatedRun'] == 1, 'min_engine' => self::clean($e['MinEngine'], 40), 'description' => isset($m['description']) && is_string($m['description']) ? self::clean($m['description'], 400) : '') +
						phoromatic_native_taxonomy::facts(isset($m['category']) ? $m['category'] : null, isset($m['tags']) ? $m['tags'] : null, isset($m['estimated_run_s']) ? $m['estimated_run_s'] : null);
				}
			}
			foreach($out['catalog'] as $id => &$c)
			{
				$order = array_keys($c['versions']);
				usort($order, function($a, $b) { return phoromatic_catalog_sched::compare($b, $a); });
				$c['order'] = $order;
				$newest = $c['versions'][$order[0]];
				$c['description'] = $newest['description'];
				$c['cat'] = $newest['cat'];
				$c['tags'] = $newest['tags'];
				$c['len'] = $newest['len'];
				$c['block'] = phoromatic_catalog_sched::is_admin_ref($account_id, $id) ? self::block_text($c['title'], $newest['install'], $newest['elevated']) : null;
				if($c['block'] === null && phoromatic_catalog_sched::is_admin_ref($account_id, $id))
				{
					// an older version declared it, or a system reports it: the same gate (section 8)
					$c['block'] = $c['title'] . ' needs administrator rights, and suites that need administrator rights come in a later release. Add it to a schedule on its own instead.';
				}
			}
			unset($c);
			uasort($out['catalog'], function($a, $b) { return strcasecmp($a['title'], $b['title']); });
			// a catalog test whose every version is withdrawn is not offered, and is not "reported by your systems" either
			foreach(array_keys($out['catalog']) as $id)
			{
				if(empty($out['catalog'][$id]['versions']))
				{
					unset($out['catalog'][$id]);
				}
			}
		}
		// the tests the account's systems report that no served tier holds, with the OS of each reporter
		$systems = array();
		foreach(self::rows('SELECT SystemID, Title, SystemProperties FROM phoromatic_systems WHERE AccountID = :a AND DeletedOn IS NULL', array(':a' => $account_id)) as $s)
		{
			$systems[$s['SystemID']] = $s;
		}
		foreach(phoromatic_native_admin::catalogue($account_id) as $ref => $t)
		{
			if(isset($held[$ref]))
			{
				continue;
			}
			$platforms = array();
			$unknown = false;
			foreach(array_keys($t['systems']) as $sid)
			{
				$p = isset($systems[$sid]) ? self::system_platform($systems[$sid]) : null;
				if($p === null)
				{
					$unknown = true;
				}
				else
				{
					$platforms[$p] = true;
				}
			}
			// "If none of those systems has a known OS, the test counts as every platform"; an unknown one beside a known one adds nothing
			$platforms = empty($platforms) ? self::PLATFORMS : array_values(array_intersect(self::PLATFORMS, array_keys($platforms)));
			$admin = phoromatic_catalog_sched::is_admin_ref($account_id, $ref);
			$title = self::clean($t['title'], 120);
			$facts = phoromatic_native_taxonomy::facts($t['category'], $t['tags'], $t['estimated_run_s']);
			$out['reported'][$ref] = array('ref' => $ref, 'title' => $title, 'description' => isset($t['description']) ? self::clean($t['description'], 400) : '', 'options' => $t['options'],
				'systems' => array_map(function($x) { return self::clean($x, 60); }, array_values($t['systems'])), 'platforms' => $platforms,
				'cat' => $facts['cat'], 'tags' => $facts['tags'], 'len' => $facts['len'],
				'block' => $admin ? $title . ' needs administrator rights, and suites that need administrator rights come in a later release. Add it to a schedule on its own instead.' : null);
		}
		uasort($out['reported'], function($a, $b) { return strcasecmp($a['title'], $b['title']); });
		return $out;
	}
	// The platforms of a member of a suite (section 4): array of platform names. $version null is "not pinned". null when the ref is in neither list.
	public static function member_platforms($offer, $ref, $version)
	{
		if(isset($offer['catalog'][$ref]))
		{
			$c = $offer['catalog'][$ref];
			$v = $version !== null ? $version : $c['order'][0];
			return isset($c['versions'][$v]) ? $c['versions'][$v]['platforms'] : null;
		}
		return isset($offer['reported'][$ref]) ? $offer['reported'][$ref]['platforms'] : null;
	}
	// The options a member can have, from the manifest at its version (a catalog test) or the report
	public static function member_options($offer, $ref, $version)
	{
		if(isset($offer['catalog'][$ref]))
		{
			$c = $offer['catalog'][$ref];
			$v = $version !== null ? $version : $c['order'][0];
			return isset($c['versions'][$v]) ? $c['versions'][$v]['options'] : null;
		}
		return isset($offer['reported'][$ref]) ? $offer['reported'][$ref]['options'] : null;
	}
	public static function title_of($offer, $ref)
	{
		return isset($offer['catalog'][$ref]) ? $offer['catalog'][$ref]['title'] : (isset($offer['reported'][$ref]) ? $offer['reported'][$ref]['title'] : self::clean($ref, 80));
	}
	// The suite's platforms: the intersection over its members' platforms (a list of member arrays with ref and version)
	public static function suite_platforms($offer, $members)
	{
		$set = null;
		foreach($members as $m)
		{
			$p = self::member_platforms($offer, $m['ref'], $m['version']);
			if($p === null)
			{
				continue;
			}
			$set = $set === null ? $p : array_values(array_intersect($set, $p));
		}
		return $set === null ? self::PLATFORMS : $set;
	}

	// ---- reading suites ----

	public static function get($account_id, $suite_id)
	{
		if(!self::ready() || !is_int($suite_id) && !ctype_digit((string)$suite_id))
		{
			return null;
		}
		$r = self::rows('SELECT * FROM phoromatic_native_suites WHERE AccountID = :a AND SuiteID = :s', array(':a' => $account_id, ':s' => (int)$suite_id));
		if(empty($r))
		{
			return null;
		}
		$r[0]['members'] = self::members($account_id, (int)$suite_id);
		return $r[0];
	}
	public static function members($account_id, $suite_id)
	{
		return self::rows('SELECT Position, Ref, Version, OptionsJSON FROM phoromatic_native_suite_members WHERE AccountID = :a AND SuiteID = :s ORDER BY Position', array(':a' => $account_id, ':s' => (int)$suite_id));
	}
	public static function all($account_id)
	{
		if(!self::ready())
		{
			return array();
		}
		return self::rows('SELECT s.*, (SELECT COUNT(*) FROM phoromatic_native_suite_members m WHERE m.AccountID = s.AccountID AND m.SuiteID = s.SuiteID) AS MemberCount FROM phoromatic_native_suites s WHERE s.AccountID = :a ORDER BY s.Title COLLATE NOCASE', array(':a' => $account_id));
	}
	// The titles of the account's schedules that hold the suite: array(ScheduleID => Title)
	public static function used_by($account_id, $suite_id)
	{
		$out = array();
		if(!self::ready())
		{
			return $out;
		}
		foreach(self::rows('SELECT s.ScheduleID, s.Title FROM phoromatic_schedules s WHERE s.AccountID = :a AND EXISTS (SELECT 1 FROM phoromatic_schedules_native_tests n WHERE n.AccountID = s.AccountID AND n.ScheduleID = s.ScheduleID AND n.Kind = \'suite\' AND n.Ref = :r) ORDER BY s.Title',
			array(':a' => $account_id, ':r' => 'native:' . (int)$suite_id)) as $r)
		{
			$out[(int)$r['ScheduleID']] = $r['Title'];
		}
		return $out;
	}
	// The rows a job for the schedule would carry (section 5): the loose rows in Position order, each suite row replaced by its members in order. Every row: Ref, Version, OptionsJSON,
	// Suite (null, or array(id, title, revision)). A row with the same Ref, Version and OptionsJSON as an earlier one is dropped (the job carries it once). $override is array(suite id =>
	// member rows) to expand a suite as it would be after an edit that isn't saved yet.
	public static function expanded_rows($account_id, $schedule_id, $override = array())
	{
		$have_kind = self::ready();
		$out = array();
		$seen = array();
		foreach(self::rows('SELECT Ref, Version, OptionsJSON' . ($have_kind ? ', Kind' : '') . ' FROM phoromatic_schedules_native_tests WHERE AccountID = :a AND ScheduleID = :s ORDER BY Position, Ref', array(':a' => $account_id, ':s' => (int)$schedule_id)) as $r)
		{
			$suite = null;
			$list = array($r);
			if($have_kind && isset($r['Kind']) && $r['Kind'] === 'suite')
			{
				$list = array();
				if(preg_match('/^native:([0-9]{1,9})\z/', $r['Ref'], $m) === 1 && ($s = self::get($account_id, (int)$m[1])) !== null)
				{
					$suite = array('id' => (int)$s['SuiteID'], 'title' => $s['Title'], 'revision' => (int)$s['Revision']);
					$list = isset($override[(int)$s['SuiteID']]) ? $override[(int)$s['SuiteID']] : $s['members'];
				}
			}
			foreach($list as $row)
			{
				$key = $row['Ref'] . "\0" . ($row['Version'] === null ? '' : $row['Version']) . "\0" . $row['OptionsJSON'];
				if(isset($seen[$key]))
				{
					continue;
				}
				$seen[$key] = true;
				$out[] = array('Ref' => $row['Ref'], 'Version' => $row['Version'], 'OptionsJSON' => $row['OptionsJSON'], 'Suite' => $suite);
			}
		}
		return $out;
	}
	// Whether two expanded rows are the same test with the same options at different versions (not allowed in one schedule): the first such pair as array(row, row), or null
	public static function version_conflict($rows)
	{
		$by = array();
		foreach($rows as $r)
		{
			$k = $r['Ref'] . "\0" . $r['OptionsJSON'];
			if(isset($by[$k]) && $by[$k]['Version'] !== $r['Version'])
			{
				return array($by[$k], $r);
			}
			if(!isset($by[$k]))
			{
				$by[$k] = $r;
			}
		}
		return null;
	}
	public static function conflict_text($offer, $pair)
	{
		list($a, $b) = $pair;
		$options = json_decode($a['OptionsJSON'], true);
		$with = empty($options) ? '' : ' with ' . self::options_text($offer, $a['Ref'], $a['Version'], $a['OptionsJSON']);
		return self::title_of($offer, $a['Ref']) . $with . ' is already in this schedule at ' . ($a['Version'] !== null ? $a['Version'] : 'not pinned') . ($a['Suite'] !== null ? ' (suite ' . self::clean($a['Suite']['title'], 60) . ')' : '') . ', and this would add ' .
			($b['Version'] !== null ? $b['Version'] : 'not pinned') . '. Use one version.';
	}
	// "level 3" for the options of a member, in the manifest's labels where they are known
	public static function options_text($offer, $ref, $version, $json)
	{
		$options = json_decode($json, true);
		$list = array();
		$declared = self::member_options($offer, $ref, $version);
		foreach(is_array($options) ? $options : array() as $k => $v)
		{
			$label = $k;
			$shown = $v;
			foreach(is_array($declared) ? $declared : array() as $o)
			{
				if(is_array($o) && isset($o['key']) && $o['key'] === $k)
				{
					$label = isset($o['label']) && is_string($o['label']) ? $o['label'] : $k;
					foreach(isset($o['choices']) && is_array($o['choices']) ? $o['choices'] : array() as $c)
					{
						if(isset($c['key']) && (string)$c['key'] === (string)$v && isset($c['label']) && is_string($c['label']))
						{
							$shown = $c['label'];
						}
					}
				}
			}
			$list[] = self::clean($label, 60) . ' ' . self::clean($shown, 60);
		}
		return implode(', ', $list);
	}

	// ---- checking and saving ----

	// The members a form posted, as array(ref, version or null, options (posted key => value)): m[i][ref], m[i][version] ('' is not pinned), m[i][opt][key]. Strings only; the rest is the server's check.
	public static function posted_members($posted)
	{
		$out = array();
		if(!is_array($posted))
		{
			return $out;
		}
		foreach($posted as $m)
		{
			if(!is_array($m) || !isset($m['ref']) || !is_string($m['ref']))
			{
				continue;
			}
			$opts = array();
			foreach(isset($m['opt']) && is_array($m['opt']) ? $m['opt'] : array() as $k => $v)
			{
				if(is_string($v) || is_int($v))
				{
					$opts[(string)$k] = (string)$v;
				}
			}
			$out[] = array('ref' => $m['ref'], 'version' => isset($m['version']) && is_string($m['version']) && $m['version'] !== '' ? $m['version'] : null, 'options' => $opts);
		}
		return $out;
	}
	// Checks a draft the way section 4 says, against an offer worked out now. array(error or null, rows (Ref, Version, OptionsJSON, Position), platforms)
	public static function check($account_id, $suite_id, $title, $members, $offer = null)
	{
		$offer = $offer === null ? self::offer($account_id) : $offer;
		$rows = array();
		if(count($members) < 1)
		{
			return array('Add at least one test.', array(), array());
		}
		if(count($members) > self::MAX_MEMBERS)
		{
			return array('A suite holds at most ' . self::MAX_MEMBERS . ' tests.', array(), array());
		}
		$title = trim((string)$title);
		if($title === '' || strlen($title) > 100 || preg_match('/[\x00-\x1f\x7f]/', $title))
		{
			return array($title === '' ? 'Give the suite a title.' : 'The title is too long (100 characters at most) or has control characters.', array(), array());
		}
		foreach(self::rows('SELECT SuiteID FROM phoromatic_native_suites WHERE AccountID = :a AND Title = :t COLLATE NOCASE', array(':a' => $account_id, ':t' => $title)) as $same)
		{
			if($suite_id === null || (int)$same['SuiteID'] !== (int)$suite_id)
			{
				return array('A suite with that title exists.', array(), array());
			}
		}
		$seen = array();
		$pinned = array();
		foreach($members as $i => $m)
		{
			$ref = $m['ref'];
			$label = self::clean($ref, 80);
			// 1. a native ref of the offer: a pts/ or local/ ref (or anything else) never gets in
			if(strpos($ref, 'pts/') === 0 || strpos($ref, 'local/') === 0)
			{
				return array($label . ' is a Phoronix profile. A native suite takes native tests only; Phoronix profiles go in a Phoronix suite.', array(), array());
			}
			if(!isset($offer['catalog'][$ref]) && !isset($offer['reported'][$ref]))
			{
				return array($label . ' is not a test this server\'s catalog or your systems offer.', array(), array());
			}
			$block = isset($offer['catalog'][$ref]) ? $offer['catalog'][$ref]['block'] : $offer['reported'][$ref]['block'];
			// 2. no administrator test (section 8), by the same call the page used
			if($block !== null || phoromatic_catalog_sched::is_admin_ref($account_id, $ref))
			{
				return array($block !== null ? $block : self::title_of($offer, $ref) . ' needs administrator rights, and suites that need administrator rights come in a later release.', array(), array());
			}
			// 3. the pinned version is in the offer, and the options are valid for it
			$version = $m['version'];
			if(isset($offer['catalog'][$ref]))
			{
				if($version !== null && !isset($offer['catalog'][$ref]['versions'][$version]))
				{
					return array(self::title_of($offer, $ref) . ' ' . self::clean($version, 40) . ' is not offered by this server\'s catalog (withdrawn, or no longer listed). Pick another version.', array(), array());
				}
			}
			else if($version !== null)
			{
				return array(self::title_of($offer, $ref) . ' is reported by your systems, not held by the catalog, so it can\'t be pinned to a version.', array(), array());
			}
			$declared = self::member_options($offer, $ref, $version);
			$options = phoromatic_native_admin::checked_options(array('options' => $declared), $m['options']);
			if(is_string($options))
			{
				return array(self::title_of($offer, $ref) . ': ' . $options, array(), array());
			}
			foreach(array_keys($m['options']) as $k)
			{
				$known = false;
				foreach($declared as $o)
				{
					$known = $known || (is_array($o) && isset($o['key']) && $o['key'] === (string)$k);
				}
				if(!$known)
				{
					return array(self::title_of($offer, $ref) . ' has no option called ' . self::clean($k, 60) . ' at ' . ($version !== null ? $version : 'its newest version') . '.', array(), array());
				}
			}
			$json = json_encode((object)$options, JSON_UNESCAPED_SLASHES);
			// 5. no two members with the same ref and options, whatever their versions
			$key = $ref . "\0" . $json;
			if(isset($seen[$key]))
			{
				return array(self::title_of($offer, $ref) . ' is in this suite twice with the same options (tests ' . $seen[$key] . ' and ' . ($i + 1) . '). A suite holds one version of each test setting, so change an option or remove one.', array(), array());
			}
			$seen[$key] = $i + 1;
			$rows[] = array('Position' => $i, 'Ref' => $ref, 'Version' => $version, 'OptionsJSON' => $json);
			$pinned[] = array('ref' => $ref, 'version' => $version);
		}
		// 4. one platform for the whole suite
		$platforms = self::suite_platforms($offer, $pinned);
		if(empty($platforms))
		{
			$names = array();
			foreach($pinned as $m)
			{
				$names[self::title_of($offer, $m['ref'])] = true;
			}
			return array('These tests have no platform in common (' . implode(', ', array_slice(array_keys($names), 0, 3)) . (count($names) > 3 ? ' and others' : '') . '), so the suite would run nowhere. A suite runs on one machine at a time; build a separate suite for each platform.', array(), array());
		}
		return array(null, $rows, $platforms);
	}
	protected static function member_text($offer, $r)
	{
		return $r['Ref'] . ($r['Version'] !== null ? '@' . $r['Version'] : '') . ' ' . $r['OptionsJSON'];
	}
	// The save of a native suite: null and the suite id on success, else why nothing was changed. $suite_id null makes a new suite. $actor is array(name, detail).
	public static function save($account_id, $suite_id, $title, $description, $members, $actor, &$new_id = null)
	{
		if(!self::ready())
		{
			return 'This server has not been upgraded for native suites yet (start it once on this version); nothing was saved.';
		}
		$offer = self::offer($account_id);
		list($why, $rows, $platforms) = self::check($account_id, $suite_id, $title, $members, $offer);
		if($why !== null)
		{
			return $why;
		}
		$title = trim((string)$title);
		$description = self::clean((string)$description, 500);
		$db = phoromatic_server::$db;
		if(@$db->exec('BEGIN IMMEDIATE') === false)
		{
			return 'The database is busy; nothing was saved.';
		}
		$now = phoromatic_server::current_time();
		$before = null;
		if($suite_id === null)
		{
			$n = self::rows('SELECT COALESCE(MAX(SuiteID), 0) + 1 AS n FROM phoromatic_native_suites WHERE AccountID = :a', array(':a' => $account_id));
			$suite_id = (int)$n[0]['n'];
			$stmt = $db->prepare('INSERT INTO phoromatic_native_suites (AccountID, SuiteID, Title, Description, Revision, CreatedOn, CreatedBy) VALUES (:a, :s, :t, :d, 1, :now, :by)');
			$revision = 1;
		}
		else
		{
			$before = self::get($account_id, (int)$suite_id);
			if($before === null)
			{
				@$db->exec('ROLLBACK');
				return 'No such suite; nothing was saved.';
			}
			$same = $before['Title'] === $title && (string)$before['Description'] === $description && count($before['members']) == count($rows);
			foreach($before['members'] as $i => $m)
			{
				$same = $same && $m['Ref'] === $rows[$i]['Ref'] && $m['Version'] === $rows[$i]['Version'] && $m['OptionsJSON'] === $rows[$i]['OptionsJSON'];
			}
			if($same)
			{
				@$db->exec('ROLLBACK');
				return 'Nothing changed; the suite was not saved again.';
			}
			// an edit that would give a schedule that holds the suite two versions of one test setting is refused, naming the schedule
			foreach(self::used_by($account_id, (int)$suite_id) as $sid => $stitle)
			{
				$override = array((int)$suite_id => $rows);
				if(($pair = self::version_conflict(self::expanded_rows($account_id, $sid, $override))) !== null)
				{
					@$db->exec('ROLLBACK');
					return 'Schedule "' . self::clean($stitle, 80) . '": ' . self::conflict_text($offer, $pair);
				}
			}
			$revision = (int)$before['Revision'] + 1;
			$stmt = $db->prepare('UPDATE phoromatic_native_suites SET Title = :t, Description = :d, Revision = :rev, ChangedOn = :now, ChangedBy = :by WHERE AccountID = :a AND SuiteID = :s');
			$stmt->bindValue(':rev', $revision);
			$del = $db->prepare('DELETE FROM phoromatic_native_suite_members WHERE AccountID = :a AND SuiteID = :s');
			$del->bindValue(':a', $account_id);
			$del->bindValue(':s', (int)$suite_id);
			if(@$del->execute() === false)
			{
				@$db->exec('ROLLBACK');
				return 'The suite could not be saved.';
			}
		}
		foreach(array(':a' => $account_id, ':s' => (int)$suite_id, ':t' => $title, ':d' => $description, ':now' => $now, ':by' => $actor[0]) as $k => $v)
		{
			$stmt->bindValue($k, $v);
		}
		$ok = @$stmt->execute() !== false;
		$ins = $db->prepare('INSERT INTO phoromatic_native_suite_members (AccountID, SuiteID, Position, Ref, Version, OptionsJSON) VALUES (:a, :s, :p, :r, :v, :o)');
		foreach($rows as $i => $r)
		{
			foreach(array(':a' => $account_id, ':s' => (int)$suite_id, ':p' => $i, ':r' => $r['Ref'], ':v' => $r['Version'], ':o' => $r['OptionsJSON']) as $k => $v)
			{
				$ins->bindValue($k, $v);
			}
			$ok = $ok && @$ins->execute() !== false;
		}
		$after = array_map(function($r) use ($offer) { return self::member_text($offer, $r); }, $rows);
		$audit = $before === null ?
			array('native_suite_created', array('suite' => (int)$suite_id, 'title' => $title, 'revision' => 1, 'members' => $after)) :
			array('native_suite_changed', array('suite' => (int)$suite_id, 'title' => $title, 'revision_from' => (int)$before['Revision'], 'revision_to' => $revision,
				'members_before' => array_map(function($r) use ($offer) { return self::member_text($offer, $r); }, $before['members']), 'members_after' => $after));
		$ok = $ok && phoromatic_admin_audit::record($account_id, 'user', $actor[0], $audit[0], array('actor_detail' => $actor[1], 'subject_type' => 'native_suite', 'subject_id' => (string)(int)$suite_id, 'subject_label' => $title, 'detail' => $audit[1]));
		if(!$ok || @$db->exec('COMMIT') === false)
		{
			@$db->exec('ROLLBACK');
			return 'The suite could not be saved (a suite with that title may exist, or the audit record could not be written); nothing was changed.';
		}
		$new_id = (int)$suite_id;
		return null;
	}
	// Deletes a suite no schedule uses: null on success, else why not
	public static function delete($account_id, $suite_id, $actor)
	{
		$s = self::get($account_id, (int)$suite_id);
		if($s === null)
		{
			return 'No such suite.';
		}
		$used = self::used_by($account_id, (int)$suite_id);
		if(!empty($used))
		{
			return 'Used by ' . implode(', ', array_map(function($t) { return self::clean($t, 60); }, array_values($used))) . '. Remove it there first.';
		}
		$db = phoromatic_server::$db;
		if(@$db->exec('BEGIN IMMEDIATE') === false)
		{
			return 'The database is busy; nothing was deleted.';
		}
		$ok = true;
		foreach(array('phoromatic_native_suite_members', 'phoromatic_native_suites') as $table)
		{
			$d = $db->prepare('DELETE FROM ' . $table . ' WHERE AccountID = :a AND SuiteID = :s');
			$d->bindValue(':a', $account_id);
			$d->bindValue(':s', (int)$suite_id);
			$ok = $ok && @$d->execute() !== false;
		}
		$ok = $ok && phoromatic_admin_audit::record($account_id, 'user', $actor[0], 'native_suite_deleted', array('actor_detail' => $actor[1], 'subject_type' => 'native_suite', 'subject_id' => (string)(int)$suite_id, 'subject_label' => $s['Title'],
			'detail' => array('suite' => (int)$suite_id, 'title' => $s['Title'], 'revision' => (int)$s['Revision'], 'members' => array_map(function($r) { return self::member_text(array(), $r); }, $s['members']))));
		if(!$ok || @$db->exec('COMMIT') === false)
		{
			@$db->exec('ROLLBACK');
			return 'The suite could not be deleted; nothing was changed.';
		}
		return null;
	}

	// ---- what a member says about its pin (section 6) ----

	// The state of a member's pin: array(state ('ok', 'superseded', 'withdrawn', 'dropped', 'unpinned', 'other'), newest, why). 'superseded' when a newer version is in the offer.
	public static function pin_state($account_id, $offer, $r)
	{
		if($r['Version'] !== null && ($why = phoromatic_catalog_sched::withdrawn($r['Ref'], $r['Version'])) !== null)
		{
			return array('state' => 'withdrawn', 'newest' => isset($offer['catalog'][$r['Ref']]) ? $offer['catalog'][$r['Ref']]['order'][0] : null, 'why' => $why);
		}
		if(!isset($offer['catalog'][$r['Ref']]))
		{
			return array('state' => $r['Version'] === null ? 'other' : 'dropped', 'newest' => null, 'why' => null);
		}
		$newest = $offer['catalog'][$r['Ref']]['order'][0];
		if($r['Version'] === null)
		{
			return array('state' => 'unpinned', 'newest' => $newest, 'why' => null);
		}
		if(!isset($offer['catalog'][$r['Ref']]['versions'][$r['Version']]))
		{
			return array('state' => 'dropped', 'newest' => $newest, 'why' => null);
		}
		return array('state' => phoromatic_catalog_sched::compare($newest, $r['Version']) > 0 ? 'superseded' : 'ok', 'newest' => $newest, 'why' => null);
	}
	// Moves members of a saved suite to the newest version of their tests (section 6, CS3): $only is a position (one member) or null (every member that can move). array(error or null, moved, refused (list of text)).
	// A member whose options the new manifest doesn't offer, or that would leave the suite without a platform, stays where it is and is listed.
	public static function update($account_id, $suite_id, $only, $actor)
	{
		$s = self::get($account_id, (int)$suite_id);
		if($s === null)
		{
			return array('No such suite.', 0, array());
		}
		$offer = self::offer($account_id);
		$members = array();
		$moved = 0;
		$refused = array();
		foreach($s['members'] as $i => $m)
		{
			$members[] = array('ref' => $m['Ref'], 'version' => $m['Version'], 'options' => json_decode($m['OptionsJSON'], true) ?: array());
		}
		foreach($members as $i => $m)
		{
			if($only !== null && $i !== (int)$only)
			{
				continue;
			}
			$st = self::pin_state($account_id, $offer, $s['members'][$i]);
			if($m['version'] === null || $st['state'] !== 'superseded' || $st['newest'] === null)
			{
				continue;
			}
			$try = $members;
			$try[$i]['version'] = $st['newest'];
			$bad = null;
			// the stored values are checked against the new manifest; a key that is gone is found below
			$t = phoromatic_native_admin::checked_options(array('options' => self::member_options($offer, $m['ref'], $st['newest'])), $m['options']);
			if(is_string($t))
			{
				$bad = $t;
			}
			else
			{
				$declared = array();
				foreach(self::member_options($offer, $m['ref'], $st['newest']) as $o)
				{
					$declared[] = is_array($o) && isset($o['key']) ? (string)$o['key'] : '';
				}
				foreach(array_keys($m['options']) as $k)
				{
					if(!in_array((string)$k, $declared, true))
					{
						$bad = 'the option ' . self::clean($k, 60) . ' is not offered by ' . $st['newest'];
					}
				}
			}
			if($bad === null)
			{
				list($why) = self::check($account_id, (int)$suite_id, $s['Title'], $try, $offer);
				$bad = $why;
			}
			if($bad !== null)
			{
				$refused[] = self::title_of($offer, $m['ref']) . ' ' . $st['newest'] . ' stays where it is because ' . $bad;
				continue;
			}
			$members = $try;
			$moved++;
		}
		if($moved == 0)
		{
			return array(null, 0, $refused);
		}
		$posted = array();
		foreach($members as $m)
		{
			$posted[] = $m;
		}
		$e = self::save($account_id, (int)$suite_id, $s['Title'], (string)$s['Description'], $posted, $actor);
		return array($e, $e === null ? $moved : 0, $refused);
	}
}

?>
