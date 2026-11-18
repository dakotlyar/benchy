<?php

/*
	Benchy
	phoromatic_catalog_sched.php: what the signed catalog means for a schedule (E4.4 C4, server design 6): version pins and the choice of version, the labels,
	the per-system status, and the rules that hold a schedule on a system (a pin an engine can't run, a withdrawn or too new version, a test that needs
	administrator rights). It only reads; the schedule page and the agent API act on what it says.

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

// A hold never fails and never uses up a trigger: the schedule is skipped for that system, so it runs as soon as the reason is gone (6.4, 6.6).
// Everything printed is escaped by the callers; the texts here carry what an index or an agent said only through clean().

class phoromatic_catalog_sched
{
	const PIN_ENGINE = '0.6.0';       // the first engine that can run a chosen version (catalog_v1)
	const ADMIN_ENGINE = '0.7.0';     // the first engine that reports what it needs to run administrator tests
	const HOLD_SECONDS = 21600;       // a deferral holds a schedule on a system for 6 hours at most (6.6 rule C)
	const VERSION_PATTERN = '/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\z/';
	const KINDS_APPROVAL = array('approval_declined', 'uac_declined');
	const KINDS_SETUP = array('not_set_up', 'install_needs_setup');

	// The ready_for_approval schedule id of the poll being answered (the agent carries it on every poll while the person's ready file lives, so nothing is stored)
	public static $ready_schedule = null;
	// The first reason a schedule was skipped in the selection being run: array(text, detail), or null
	public static $first_hold = null;

	// ---- small helpers ----

	// Text from an index or an agent, for a line a person reads: control characters out, 200 characters at most (callers escape it for HTML)
	public static function clean($text, $max = 200)
	{
		$text = preg_replace('/[\x00-\x1f\x7f]+/u', ' ', (string)$text);
		$text = $text === null ? '' : trim($text);
		return strlen($text) > $max ? substr($text, 0, $max - 1) . '...' : $text;
	}
	public static function valid_version($v)
	{
		return is_string($v) && preg_match(self::VERSION_PATTERN, $v) === 1;
	}
	// -1, 0 or 1 comparing x.y.z field by field as numbers (digit strings, so a huge field can't overflow); a version that doesn't start with x.y.z is null
	public static function compare($a, $b)
	{
		if(preg_match('/^([0-9]+)\.([0-9]+)\.([0-9]+)/', (string)$a, $x) !== 1 || preg_match('/^([0-9]+)\.([0-9]+)\.([0-9]+)/', (string)$b, $y) !== 1)
		{
			return null;
		}
		for($i = 1; $i <= 3; $i++)
		{
			$p = ltrim($x[$i], '0');
			$q = ltrim($y[$i], '0');
			if(strlen($p) != strlen($q))
			{
				return strlen($p) < strlen($q) ? -1 : 1;
			}
			if(($c = strcmp($p, $q)) != 0)
			{
				return $c < 0 ? -1 : 1;
			}
		}
		return 0;
	}
	// The engine's version from a system's ClientVersion ("benchy-native 0.6.0"), as x.y.z, or null when it isn't known
	public static function engine_version($sys)
	{
		return isset($sys['ClientVersion']) && preg_match('/^benchy-native ([0-9]+\.[0-9]+\.[0-9]+)/', (string)$sys['ClientVersion'], $m) === 1 ? $m[1] : null;
	}
	public static function engine_text($sys)
	{
		$v = self::engine_version($sys);
		return $v === null ? 'of unknown version' : $v;
	}
	public static function engine_older($sys, $than)
	{
		$v = self::engine_version($sys);
		return $v !== null && self::compare($v, $than) < 0;
	}
	public static function has_word($sys, $word)
	{
		return phoromatic_native_admin::has_capability($sys, $word);
	}
	public static function level_one()
	{
		return phoromatic_auth::level(isset($_SESSION['AccountID']) ? $_SESSION['AccountID'] : null) === 1;
	}

	// ---- what the catalog says about a test ----

	// The entries row of id@version in the tiers this account is served, or null
	public static function entry($account_id, $ref, $version)
	{
		foreach(phoromatic_catalog::entries_of_id(phoromatic_catalog::tiers_for_account($account_id), $ref) as $r)
		{
			if($r['Version'] === $version)
			{
				return $r;
			}
		}
		return null;
	}
	// Why id@version was withdrawn (in any tier: a revocation crosses tiers), or null
	public static function withdrawn($ref, $version)
	{
		$why = phoromatic_catalog::revoked_reason($ref . '@' . $version);
		return $why === null ? null : self::clean($why);
	}
	// The versions of a test the picker may pin: the entries of the served tiers less every withdrawn one, newest first
	public static function catalog_versions($account_id, $ref)
	{
		$out = array();
		foreach(phoromatic_catalog::entries_of_id(phoromatic_catalog::tiers_for_account($account_id), $ref) as $r)
		{
			if(self::valid_version($r['Version']) && self::withdrawn($ref, $r['Version']) === null)
			{
				$out[$r['Version']] = true;
			}
		}
		$out = array_keys($out);
		usort($out, function($a, $b) { return self::compare($b, $a); });
		return $out;
	}
	// Whether a test needs administrator rights at any version the account's tiers hold, or a system of the account reports it does (a bundle this server doesn't hold)
	public static function is_admin_ref($account_id, $ref)
	{
		foreach(phoromatic_catalog::entries_of_id(phoromatic_catalog::tiers_for_account($account_id), $ref) as $r)
		{
			if((int)$r['ElevatedRun'] == 1 || ($r['MachineInstall'] !== null && $r['MachineInstall'] !== ''))
			{
				return true;
			}
		}
		foreach(phoromatic_native_admin::rows_of('SELECT TestJSON FROM phoromatic_system_catalogue WHERE AccountID = :a AND Ref = :r LIMIT 50', array(':a' => $account_id, ':r' => $ref)) as $row)
		{
			$t = json_decode($row['TestJSON'], true);
			if(is_array($t) && isset($t['admin']) && is_array($t['admin']) && (!empty($t['admin']['elevated_run']) || !empty($t['admin']['machine_install'])))
			{
				return true;
			}
		}
		return false;
	}
	// Whether a schedule holds a test that needs administrator rights (6.6, "administrator schedule"); the refs of those tests
	public static function admin_tests($account_id, $schedule_id)
	{
		$out = array();
		// over the expanded rows (a suite's members count as the schedule's own tests), so a suite can't run an administrator test even if a later release changed what a member declares
		foreach(phoromatic_native_suites::expanded_rows($account_id, (int)$schedule_id) as $r)
		{
			if(!in_array($r['Ref'], $out, true) && self::is_admin_ref($account_id, $r['Ref']))
			{
				$out[] = $r['Ref'];
			}
		}
		return $out;
	}

	// The level-1 rule for a change to a schedule that runs tests needing administrator rights (6.6 S3): array(allowed, tests). Not an administrator schedule: allowed.
	public static function gate($account_id, $schedule_id)
	{
		$tests = phoromatic_catalog::ready() ? self::admin_tests($account_id, $schedule_id) : array();
		return array('allowed' => empty($tests) || self::level_one(), 'tests' => $tests);
	}

	// ---- what a system reported ----

	// ref => array(version, admin (array or null), title) for the refs asked, from the system's last hello
	public static function reported($account_id, $system_id, $refs)
	{
		$out = array();
		foreach(phoromatic_native_admin::rows_of('SELECT Ref, Version, TestJSON FROM phoromatic_system_catalogue WHERE AccountID = :a AND SystemID = :y', array(':a' => $account_id, ':y' => $system_id)) as $r)
		{
			if(!in_array($r['Ref'], $refs, true))
			{
				continue;
			}
			$t = json_decode($r['TestJSON'], true);
			$out[$r['Ref']] = array('version' => $r['Version'], 'admin' => is_array($t) && isset($t['admin']) && is_array($t['admin']) ? $t['admin'] : null, 'title' => is_array($t) && isset($t['title']) && is_string($t['title']) ? self::clean($t['title']) : $r['Ref']);
		}
		return $out;
	}
	// What a row of a schedule needs on a system: array(ref, version (the one the job would send), pinned, elevated, install, installed, accepted (list), title) or null
	// when nothing is known about the test on that system and it isn't pinned. The version is the row's pin, or what the system itself reported.
	protected static function need($account_id, $row, $report)
	{
		$version = $row['Version'] !== null ? $row['Version'] : ($report !== null ? $report['version'] : null);
		$entry = $version === null ? null : self::entry($account_id, $row['Ref'], $version);
		$admin = $report !== null && is_array($report['admin']) ? $report['admin'] : array();
		$same = $version !== null && $report !== null && $report['version'] === $version;
		if($entry === null && !$same)
		{
			// Nothing describes the version the job will send (not reported, or pinned to one the server doesn't hold while the system reports another): a test
			// that needs administrator rights at some version is treated as needing them, so an engine that can't run them holds instead of failing the job
			return self::is_admin_ref($account_id, $row['Ref']) ? array('ref' => $row['Ref'], 'version' => $version, 'pinned' => $row['Version'] !== null, 'elevated' => true, 'install' => false, 'installed' => null,
				'accepted' => array(), 'title' => $report !== null ? $report['title'] : $row['Ref'], 'known' => false) : null;
		}
		// the server's own row for this version first; the agent's report describes only the version it reported
		if($entry !== null)
		{
			$elevated = (int)$entry['ElevatedRun'] == 1;
			$install = $entry['MachineInstall'] !== null && $entry['MachineInstall'] !== '';
			$title = self::clean($entry['Title']);
		}
		else
		{
			$elevated = $same && !empty($admin['elevated_run']);
			$install = $same && !empty($admin['machine_install']);
			$title = $report !== null ? $report['title'] : $row['Ref'];
		}
		return array('ref' => $row['Ref'], 'version' => $version, 'pinned' => $row['Version'] !== null, 'elevated' => $elevated, 'install' => $install,
			// `installed` describes only the reported version; for another version it is unknown (6.6 rule A)
			'installed' => $same && array_key_exists('installed', $admin) ? $admin['installed'] : null,
			'accepted' => isset($admin['accepted_versions']) && is_array($admin['accepted_versions']) ? $admin['accepted_versions'] : array(), 'title' => $title, 'known' => true);
	}

	// ---- the fix a person sees (6.6 S4) ----

	public static function fix_text($sys)
	{
		$props = isset($sys['SystemProperties']) ? json_decode((string)$sys['SystemProperties'], true) : null;
		$reason = is_array($props) && isset($props['benchy_agent']['not_set_up_reason']) && is_string($props['benchy_agent']['not_set_up_reason']) ? $props['benchy_agent']['not_set_up_reason'] : null;
		$os = is_array($props) && isset($props['benchy_system_info']['os']['name']) && is_string($props['benchy_system_info']['os']['name']) ? $props['benchy_system_info']['os']['name'] : '';
		if($os !== '' && stripos($os, 'windows') === false)
		{
			return 'Tests that need administrator rights run on Windows machines only in this release, so this schedule stays held on this system.';
		}
		$texts = array(
			'elevated_without_policy' => 'This system\'s agent already runs as administrator, which happens when User Account Control is off or the built-in Administrator account is used. Allow scheduled administrator tests once, as administrator: `benchy-engine agent setup-elevated`.',
			'binary_not_protected' => 'The unattended agent isn\'t running from the protected copy. Start it from the task that setup made, or run `benchy-engine agent setup-elevated` again.',
			'home_not_protected' => 'The unattended agent\'s folder isn\'t protected. Run `benchy-engine agent setup-elevated` again.',
			'policy_unreadable' => 'The policy file on this system can\'t be read safely, so it refuses administrator tests. An administrator should fix or remove `Benchy Policy\\policy.toml`.');
		if($reason !== null && isset($texts[$reason]))
		{
			return $texts[$reason];
		}
		return 'Start the server agent from the Benchy tray (Server..., Start when I log in), and each run asks you first. Or, for a machine nobody sits at, once as administrator: `benchy-engine agent setup-elevated`.';
	}
	public static function engine_fix()
	{
		return 'Update the engine on this system to ' . self::ADMIN_ENGINE . ' or later. It can then run tests that need administrator rights.';
	}
	public static function accept_fix($tests)
	{
		$ids = array();
		foreach($tests as $t)
		{
			$ids[] = $t['ref'] . '@' . $t['version'];
		}
		return 'Review and accept it at the machine: the tray offers this, or run `benchy-engine machine-accept ' . implode(' / ', array_slice($ids, 0, 3)) . '`.';
	}

	// ---- the per-system status of a test that needs administrator rights, for the schedule page (6.3) ----

	public static function target_status($sys, $need)
	{
		if(self::engine_older($sys, self::ADMIN_ENGINE))
		{
			return 'update the engine to ' . self::ADMIN_ENGINE . ' or later';
		}
		$set_up = self::has_word($sys, 'elevation_unattended') || self::has_word($sys, 'elevation_desktop');
		$needs_admin = $need['elevated'] || ($need['install'] && $need['installed'] === false);
		if($needs_admin && !$set_up)
		{
			return 'not set up yet, see its page';
		}
		if(!in_array($need['version'], $need['accepted'], true))
		{
			return 'a person at the machine has to accept it once (the tray offers this, or `benchy-engine machine-accept`)';
		}
		if($need['elevated'])
		{
			return self::has_word($sys, 'elevation_unattended') ? 'runs without asking' : 'someone at the desk approves each run';
		}
		return 'ready';
	}

	// ---- the rules that hold a schedule on a system (6.4, 6.6) ----

	protected static function held($rule, $text, $detail = null)
	{
		return array('rule' => $rule, 'text' => $text, 'detail' => $detail);
	}
	// null when the schedule may be issued to this system now, else array(rule, text, detail). $schedule is the schedules row, $sys the systems row.
	public static function hold($account_id, $sys, $schedule)
	{
		if(!phoromatic_catalog::ready())
		{
			return null;
		}
		$rows = phoromatic_native_suites::expanded_rows($account_id, (int)$schedule['ScheduleID']);
		if(empty($rows))
		{
			return null;
		}
		$name = 'Schedule "' . self::clean($schedule['Title'], 80) . '"';
		$pinned = array_values(array_filter($rows, function($r) { return $r['Version'] !== null; }));
		// 1. a pin an engine without catalog_v1 can't run
		if(!empty($pinned) && !self::has_word($sys, 'catalog_v1'))
		{
			return self::held(1, $name . ' pins test versions, and this system\'s engine (' . self::engine_text($sys) . ') can\'t run a chosen version. Update it to engine ' . self::PIN_ENGINE . ' or later.');
		}
		$platform = phoromatic_native_suites::system_platform($sys);
		foreach($pinned as $r)
		{
			// a member of a suite is named with its suite
			$who = self::clean($r['Ref']) . ' ' . $r['Version'] . ($r['Suite'] !== null ? ' (in suite ' . self::clean($r['Suite']['title'], 60) . ')' : '');
			// 2. a pinned version that was withdrawn
			if(($why = self::withdrawn($r['Ref'], $r['Version'])) !== null)
			{
				return self::held(2, $who . ' was withdrawn: ' . $why . '. Pick another version.');
			}
			$e = self::entry($account_id, $r['Ref'], $r['Version']);
			// 2b. a pinned version a served tier no longer lists although it still lists the test (not withdrawn: the catalog dropped it); the agent would fail the job not_found. A pin on a test the catalog doesn't hold at all (only systems report it) is not held, as before
			if($e === null && !empty(phoromatic_catalog::entries_of_id(phoromatic_catalog::tiers_for_account($account_id), $r['Ref'])))
			{
				return self::held('2b', $who . ' is no longer in this server\'s catalog. Pick another version.');
			}
			// 3. a pinned version that needs a newer engine than this system's
			$engine = self::engine_version($sys);
			if($e !== null && $engine !== null && self::compare($e['MinEngine'], $engine) > 0)
			{
				return self::held(3, $who . ' needs engine ' . self::clean($e['MinEngine'], 40) . ' or later, and this system runs ' . $engine . '. Update it, or pick an older version.');
			}
		}
		// 5. a member whose platforms leave out this system's OS (an unknown OS passes): a pinned catalog test by the platforms of that version, an unpinned one by its newest version's, a built-in by its reporters'
		if($platform !== null)
		{
			$offer = phoromatic_native_suites::offer($account_id);
			foreach($rows as $r)
			{
				$platforms = phoromatic_native_suites::member_platforms($offer, $r['Ref'], $r['Version']);
				if(empty($platforms) || in_array($platform, $platforms, true))
				{
					continue;
				}
				return self::held(5, ($r['Suite'] !== null ? 'Suite ' . self::clean($r['Suite']['title'], 60) : self::clean($r['Ref']) . ($r['Version'] !== null ? ' ' . $r['Version'] : '')) . ' runs on ' . phoromatic_native_suites::platform_text($platforms) . ' only, and this system runs ' . phoromatic_native_suites::platform_text(array($platform)) . '.');
			}
		}
		// 4. tests that need administrator rights (6.6)
		$reports = self::reported($account_id, $sys['SystemID'], array_column($rows, 'Ref'));
		$needs = array();
		foreach($rows as $r)
		{
			if(($n = self::need($account_id, $r, isset($reports[$r['Ref']]) ? $reports[$r['Ref']] : null)) !== null && ($n['elevated'] || $n['install']))
			{
				$needs[] = $n;
			}
		}
		if(empty($needs))
		{
			return null;
		}
		// rule 0: an engine that reports nothing about this can't be told apart from one that isn't set up, so it comes first
		if(self::engine_older($sys, self::ADMIN_ENGINE))
		{
			return self::held(0, $name . ' has a test that needs administrator rights. ' . self::engine_fix());
		}
		$set_up = self::has_word($sys, 'elevation_unattended') || self::has_word($sys, 'elevation_desktop');
		$unaccepted = array();
		foreach($needs as $n)
		{
			if(!$set_up && ($n['elevated'] || ($n['install'] && $n['installed'] === false)))
			{
				// A. not set up
				return self::held('A', $name . ' is waiting: this system can\'t run tests that need administrator rights yet. ' . self::fix_text($sys));
			}
			if($n['known'] && !in_array($n['version'], $n['accepted'], true))
			{
				$unaccepted[] = $n;
			}
		}
		// B. not accepted
		if(!empty($unaccepted))
		{
			return self::held('B', $name . ' is waiting: someone at this machine has to accept ' . self::clean($unaccepted[0]['title'], 80) . ' ' . $unaccepted[0]['version'] . ' once' . (count($unaccepted) > 1 ? ' (and ' . (count($unaccepted) - 1) . ' more)' : '') . '. ' . self::accept_fix($unaccepted),
				self::accept_detail($unaccepted));
		}
		// C. the schedule's newest job on this system ended deferred
		return self::deferred_hold($account_id, $sys, $schedule, $name, $needs, $set_up);
	}
	protected static function accept_detail($unaccepted)
	{
		$tests = array();
		foreach($unaccepted as $n)
		{
			$tests[] = array('ref' => $n['ref'], 'version' => $n['version']);
		}
		return array('kind' => 'acceptance_needed', 'tests' => $tests, 'fix' => self::accept_fix($unaccepted));
	}
	protected static function deferred_hold($account_id, $sys, $schedule, $name, $needs, $set_up)
	{
		$job = phoromatic_native_admin::rows_of('SELECT State, EndStatus, DeferredKind, EndedOn FROM phoromatic_v2_jobs WHERE AccountID = :a AND SystemID = :y AND ScheduleID = :s ORDER BY JobID DESC LIMIT 1',
			array(':a' => $account_id, ':y' => $sys['SystemID'], ':s' => (int)$schedule['ScheduleID']));
		if(empty($job) || $job[0]['State'] !== 'ended' || $job[0]['EndStatus'] !== 'deferred' || (string)$job[0]['DeferredKind'] === '')
		{
			return null;
		}
		$kind = $job[0]['DeferredKind'];
		$ended = strtotime((string)$job[0]['EndedOn']);
		if($ended === false || time() >= $ended + self::HOLD_SECONDS)
		{
			return null;
		}
		$props = json_decode((string)$sys['SystemProperties'], true);
		$hello = is_array($props) && isset($props['benchy_agent']['hello_on']) ? strtotime((string)$props['benchy_agent']['hello_on']) : false;
		$hello_after = $hello !== false && $hello > $ended;
		$left = (int)ceil(($ended + self::HOLD_SECONDS - time()) / 3600);
		if(in_array($kind, self::KINDS_APPROVAL, true))
		{
			// someone clicked "Approve scheduled run" for this schedule at the machine
			if(self::$ready_schedule !== null && (int)self::$ready_schedule === (int)$schedule['ScheduleID'])
			{
				return null;
			}
			return self::held('C', $name . ' is waiting: nobody approved its last run at this machine' . ($kind === 'uac_declined' ? ' (the Windows prompt was declined)' : '') . '. Approve it from the tray (Approve scheduled run), or it is offered again in about ' . $left . ' hour' . ($left == 1 ? '' : 's') . '.');
		}
		if(in_array($kind, self::KINDS_SETUP, true))
		{
			if($hello_after && $set_up)
			{
				return null;
			}
			return self::held('C', $name . ' is waiting: the agent put its last run off because this system isn\'t set up for it. ' . self::fix_text($sys));
		}
		if($kind === 'acceptance_needed')
		{
			$unaccepted = array();
			foreach($needs as $n)
			{
				if(!in_array($n['version'], $n['accepted'], true))
				{
					$unaccepted[] = $n;
				}
			}
			if($hello_after && empty($unaccepted))
			{
				return null;
			}
			if(empty($unaccepted))
			{
				$unaccepted = $needs;
			}
			return self::held('C', $name . ' is waiting: the agent put its last run off because nobody at this machine has accepted it. ' . self::accept_fix($unaccepted), self::accept_detail($unaccepted));
		}
		// suite_changed (C7), the old meaning with no kind, and a word this server doesn't know: nothing is held
		return null;
	}

	// "lab-1: runs without asking" lines for a test that needs administrator rights, one per native target; empty for a test that doesn't. $pin is the row's version (null: each
	// system's own newest, as it reported it)
	public static function status_lines($account_id, $targets, $ref, $pin)
	{
		$out = array();
		foreach($targets as $id => $t)
		{
			$rep = self::reported($account_id, $id, array($ref));
			$n = self::need($account_id, array('Ref' => $ref, 'Version' => $pin), isset($rep[$ref]) ? $rep[$ref] : null);
			if($n !== null && ($n['elevated'] || $n['install']))
			{
				$out[] = phoromatic_system_admin::plain_title($t['Title']) . ': ' . self::target_status($t, $n);
			}
		}
		return $out;
	}

	// ---- the picker (6.2) ----

	// The native systems a schedule runs on, with what the picker needs of each
	public static function targets($account_id, $schedule_id)
	{
		$out = array();
		foreach(phoromatic_server::systems_associated_with_schedule($account_id, (int)$schedule_id) as $id)
		{
			$r = phoromatic_native_admin::rows_of('SELECT SystemID, Title, EnginePolicy, ClientVersion, AgentCapabilities, SystemProperties FROM phoromatic_systems WHERE AccountID = :a AND SystemID = :y AND DeletedOn IS NULL AND EnginePolicy = \'native\'', array(':a' => $account_id, ':y' => $id));
			if(!empty($r))
			{
				$out[$id] = $r[0];
			}
		}
		return $out;
	}
	// What the picker offers for one test of a schedule (6.2): array(v1, reported (sysid => version or null), versions (the catalog's), choices, default, say)
	public static function pick($account_id, $targets, $ref)
	{
		$names = array();
		$reported = array();
		$v1 = !empty($targets);
		foreach($targets as $id => $t)
		{
			$names[$id] = phoromatic_system_admin::plain_title($t['Title']);
			$rep = self::reported($account_id, $id, array($ref));
			$reported[$id] = isset($rep[$ref]) ? $rep[$ref]['version'] : null;
			$v1 = $v1 && self::has_word($t, 'catalog_v1');
		}
		$versions = self::catalog_versions($account_id, $ref);
		$out = array('v1' => $v1, 'reported' => $reported, 'versions' => $versions, 'choices' => array(), 'default' => null, 'say' => '');
		if(empty($targets))
		{
			// No system to ask yet (a schedule with no native systems): every target has catalog_v1, vacuously, so a test the catalog holds is offered pinned to its newest version (8.4)
			if(!empty($versions))
			{
				$out['v1'] = true;
				$out['choices'] = $versions;
				$out['default'] = $versions[0];
				$out['say'] = 'Pinned to ' . $versions[0] . ', the newest in this server\'s catalog.';
			}
			return $out;
		}
		if(!$v1)
		{
			$old = array();
			foreach($targets as $id => $t)
			{
				if(!self::has_word($t, 'catalog_v1'))
				{
					$old[] = $names[$id] . ' runs engine ' . self::engine_version($t);
				}
			}
			$out['say'] = 'Not pinned: ' . implode(', ', array_map(array('phoromatic_catalog_sched', 'clean'), array_slice($old, 0, 5))) . ', which can\'t run a chosen version. Each system runs its own newest version.';
			return $out;
		}
		$set = array_unique(array_values($reported), SORT_REGULAR);
		if(count($set) == 1 && $set[0] === null && !empty($versions))
		{
			// every system has catalog_v1 and none has reported the test: it is the catalog's, so the newest version is pinned (CS4)
			$out['choices'] = $versions;
			$out['default'] = $versions[0];
			$out['say'] = 'Pinned to ' . $versions[0] . ', the newest in this server\'s catalog (no system has reported the test yet).';
			return $out;
		}
		if(count($set) == 1 && $set[0] !== null && self::valid_version($set[0]))
		{
			$out['choices'] = array_values(array_unique(array_merge(array($set[0]), $versions)));
			if(self::withdrawn($ref, $set[0]) === null)
			{
				$out['default'] = $set[0];
			}
			$out['say'] = 'Pinned to ' . $set[0] . ', the version every system has now.';
			return $out;
		}
		$parts = array();
		foreach($reported as $id => $v)
		{
			$parts[] = $names[$id] . ' ' . ($v === null ? 'has not reported it' : $v);
		}
		$out['choices'] = $versions;
		$out['say'] = 'Each system runs its own newest version: ' . implode(', ', array_map(array('phoromatic_catalog_sched', 'clean'), array_slice($parts, 0, 5))) . '. Pick a version to make them match.';
		return $out;
	}
	// The label lines of 6.3 for a test at a version: array of text (tier, install, administrator), from the server's own entry
	public static function labels($account_id, $ref, $version)
	{
		$e = $version === null ? null : self::entry($account_id, $ref, $version);
		if($e === null)
		{
			return array('known' => false, 'tier' => null, 'install' => null, 'elevated' => false);
		}
		return array('known' => true, 'tier' => $e['Tier'] === 'private' ? 'Non-free test' : 'Free test', 'install' => $e['MachineInstall'] !== null && $e['MachineInstall'] !== '' ? self::clean($e['MachineInstall'], 80) : null, 'elevated' => (int)$e['ElevatedRun'] == 1);
	}
	// The detail of a test for the audit: array(elevated_run, machine_install) when the entry declares either, else null
	public static function admin_detail($account_id, $ref, $version)
	{
		$e = $version === null ? null : self::entry($account_id, $ref, $version);
		if($e === null || ((int)$e['ElevatedRun'] != 1 && ($e['MachineInstall'] === null || $e['MachineInstall'] === '')))
		{
			return null;
		}
		return array('elevated_run' => (int)$e['ElevatedRun'] == 1, 'machine_install' => $e['MachineInstall'] !== null && $e['MachineInstall'] !== '');
	}

	// The trigger URL (event.php, no session): may the named user trigger this schedule? Administrator schedules only for a level-1 member of the account (6.6 S3);
	// array(allowed, tests)
	public static function trigger_gate($account_id, $schedule_id, $user_name)
	{
		$tests = phoromatic_catalog::ready() ? self::admin_tests($account_id, $schedule_id) : array();
		return array('allowed' => empty($tests) || in_array($account_id, phoromatic_memberships::accounts_for_name($user_name, array(1)), true), 'tests' => $tests);
	}

	// ---- group edits (6.6 S3) ----

	// May the session user move a system's groups from $before to $after? array(allowed, schedules): the enabled administrator schedules the change newly aims at the system.
	// Level 1 may (and each is audited); level 2 may not when there are any. Anything else is as it was.
	public static function regroup($account_id, $system_id, $before, $after)
	{
		if(!phoromatic_catalog::ready())
		{
			return array('allowed' => true, 'schedules' => array());
		}
		$reached = self::schedules_reached_by_groups($account_id, $system_id, $before, $after);
		return array('allowed' => empty($reached) || self::level_one(), 'schedules' => $reached);
	}
	// Audits a level-1 group change that aims a system at administrator schedules; false when a row could not be written (the caller then leaves the groups alone)
	public static function regroup_audit($account_id, $system_id, $before, $after, $reached)
	{
		foreach($reached as $schedule_id => $title)
		{
			if(!phoromatic_native_admin::admin_change_audit($account_id, $schedule_id, 'groups', 'system ' . $system_id . ' groups ' . (string)$before, 'system ' . $system_id . ' groups ' . (string)$after, self::admin_tests($account_id, $schedule_id)))
			{
				return false;
			}
		}
		return true;
	}

	// The enabled administrator schedules a system would newly be aimed at if its groups went from $before to $after ('#a##b#' strings): array(ScheduleID => Title).
	// A schedule that already names the system itself isn't re-aimed by a group.
	public static function schedules_reached_by_groups($account_id, $system_id, $before, $after)
	{
		// Group names are matched without regard to case everywhere the scheduler matches them (stripos, LIKE), so "LABS" is "labs" here too
		$added = array();
		foreach(preg_split('/#+/', (string)$after, -1, PREG_SPLIT_NO_EMPTY) as $g)
		{
			if(stripos((string)$before, '#' . $g . '#') === false)
			{
				$added[] = strtolower($g);
			}
		}
		$out = array();
		if(empty($added))
		{
			return $out;
		}
		foreach(phoromatic_native_admin::rows_of('SELECT ScheduleID, Title, RunTargetSystems, RunTargetGroups FROM phoromatic_schedules WHERE AccountID = :a AND State = 1', array(':a' => $account_id)) as $s)
		{
			$already = in_array($system_id, explode(',', (string)$s['RunTargetSystems']), true);
			$groups = array_map('strtolower', explode(',', (string)$s['RunTargetGroups']));
			$before_hit = false;
			foreach(preg_split('/#+/', (string)$before, -1, PREG_SPLIT_NO_EMPTY) as $g)
			{
				$before_hit = $before_hit || in_array(strtolower($g), $groups, true);
			}
			if($already || $before_hit || count(array_intersect($added, $groups)) == 0)
			{
				continue;
			}
			if(!empty(self::admin_tests($account_id, $s['ScheduleID'])))
			{
				$out[(int)$s['ScheduleID']] = $s['Title'];
			}
		}
		return $out;
	}
}

?>
