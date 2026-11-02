<?php

/*
	Benchy
	Hardware test benches: benches, the component inventory, sessions (one component under test at a time) and the drift guard.

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

// A bench (phoromatic_benches) owns its systems through phoromatic_systems.BenchID: from 0.9 several, one per OS install of a
// dual-boot bench (add_member()), sharing the bench's sessions; 0.8 allowed one. A bench has at most one open session, and a session
// tests one component. Results are filed under the session that was open when the task was ISSUED (phoromatic_systems.BenchTaskSession,
// a snapshot cleared when the client next asks for work). Drift baselines are kept per (session, system).
//
// Every administrative change is one BEGIN IMMEDIATE transaction that writes its phoromatic_admin_audit row(s) first; any failure rolls
// everything back. Callers check POST, the CSRF token, the Origin and phoromatic_system_admin::admin_allowed() (levels 1-2) first.
// Messages returned in array('ok', 'message') are already HTML-escaped.

class phoromatic_bench
{
	const CLASSES = array('cpu', 'gpu', 'memory', 'storage', 'motherboard', 'psu', 'cooler', 'other');
	const DETECTABLE = array('cpu', 'gpu', 'motherboard', 'memory');
	const FORCE_SILENCE = 7200;
	const MACHINE_AUDIT_CAP_PER_HOUR = 100;
	const MAX_COMPONENTS = 4;
	const MAX_LABEL = 64;
	const MAX_REASSIGN = 500;
	// The audit ActorType of the changes made through this class: 'user' (web), 'bench_token' (bench_action, the bench's own CLI)
	public static $actor_type = 'user';
	// Merged into the Detail of every audit row this class writes (bench_action puts the presenting system there)
	public static $audit_extra = array();

	public static function h($value)
	{
		return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}
	public static function class_name($class)
	{
		$names = array('cpu' => 'CPU', 'gpu' => 'GPU', 'memory' => 'Memory', 'storage' => 'Storage', 'motherboard' => 'Motherboard', 'psu' => 'PSU', 'cooler' => 'Cooler', 'other' => 'Other');
		return isset($names[$class]) ? $names[$class] : $class;
	}

	// ---------------------------------------------------------------- input rules

	public static function clean_label($label)
	{
		// What graphs and the viewer show: printable ASCII, 1-64 characters, no markup or quote characters. false if not acceptable.
		$label = trim(preg_replace('/\s+/', ' ', (string)$label));
		if(!preg_match('/\A[\x20-\x7e]{1,' . self::MAX_LABEL . '}\z/', $label) || strpbrk($label, '<>"\'&\\') !== false)
		{
			return false;
		}
		return $label;
	}
	public static function clean_text($value, $max = 128, $multiline = false)
	{
		// Optional free text: printable ASCII (plus newlines when multiline), bounded; null when empty
		$value = str_replace("\r", '', (string)$value);
		$value = preg_replace($multiline ? '/[^\x20-\x7e\n]/' : '/[^\x20-\x7e]/', '', $value);
		$value = trim($value);
		if(strlen($value) > $max)
		{
			$value = substr($value, 0, $max);
		}
		return $value === '' ? null : $value;
	}
	public static function clean_photo_url($url)
	{
		// An http(s) link or null; false if something else was given
		$url = trim((string)$url);
		if($url === '')
		{
			return null;
		}
		if(strlen($url) > 500 || !preg_match('#\Ahttps?://[^\s<>"\']+\z#i', $url) || filter_var($url, FILTER_VALIDATE_URL) === false)
		{
			return false;
		}
		return $url;
	}
	public static function env_value($label)
	{
		// A label reduced to the system-variable character set (no '=' or ';', which the client splits on)
		return trim(preg_replace('/[^A-Za-z0-9 _,.\/+-]/', '', (string)$label));
	}

	// ---------------------------------------------------------------- reads

	protected static function one($sql, $params)
	{
		$stmt = phoromatic_server::$db->prepare($sql);
		if($stmt === false)
		{
			return false;
		}
		foreach($params as $k => $v)
		{
			$stmt->bindValue($k, $v);
		}
		$result = $stmt->execute();
		return $result ? $result->fetchArray(SQLITE3_ASSOC) : false;
	}
	protected static function all($sql, $params)
	{
		$stmt = phoromatic_server::$db->prepare($sql);
		$rows = array();
		if($stmt === false)
		{
			return $rows;
		}
		foreach($params as $k => $v)
		{
			$stmt->bindValue($k, $v);
		}
		$result = $stmt->execute();
		while($result && $row = $result->fetchArray(SQLITE3_ASSOC))
		{
			$rows[] = $row;
		}
		return $rows;
	}
	public static function session_components($account_id, $session_id)
	{
		// The components of a session in position order (the primary first): rows of phoromatic_components plus Position
		return self::all('SELECT c.*, j.Position FROM phoromatic_bench_session_components j JOIN phoromatic_components c ON c.AccountID = j.AccountID AND c.ComponentID = j.ComponentID WHERE j.AccountID = :a AND j.SessionID = :s ORDER BY j.Position ASC', array(':a' => $account_id, ':s' => (int)$session_id));
	}
	public static function with_components($row)
	{
		// A session row (SELECT s.*, c.Label, c.Class of the primary) with its whole component set. Label becomes the labels joined with
		// " + " (what graphs and pages show), Class stays the primary's class, Classes lists the distinct classes in position order and
		// Components the rows. A one-component session reads exactly as in 0.9.
		if(!is_array($row))
		{
			return $row;
		}
		$components = self::session_components($row['AccountID'], $row['SessionID']);
		if(empty($components))
		{
			$components = array(array('ComponentID' => $row['ComponentID'], 'Class' => $row['Class'], 'Label' => $row['Label'], 'Position' => 0));
		}
		$row['PrimaryLabel'] = $row['Label'];
		$row['Label'] = implode(' + ', array_map(function($c) { return $c['Label']; }, $components));
		$row['Classes'] = array_values(array_unique(array_map(function($c) { return $c['Class']; }, $components)));
		$row['Components'] = $components;
		return $row;
	}
	public static function values_text($summary, $classes)
	{
		// The detected values of the given classes joined by " + " (a one-class session: the value itself); null when none is known
		$values = array();
		foreach($classes as $k)
		{
			$v = pts_hardware_summary::display_value($summary, $k);
			if($v !== null)
			{
				$values[] = $v;
			}
		}
		return empty($values) ? null : implode(' + ', $values);
	}
	public static function class_names_text($classes)
	{
		return implode(' + ', array_map(function($c) { return self::class_name($c); }, $classes));
	}
	public static function bench($account_id, $bench_id)
	{
		if(!is_numeric($bench_id) || (int)$bench_id < 1)
		{
			return false;
		}
		return self::one('SELECT * FROM phoromatic_benches WHERE AccountID = :a AND BenchID = :b', array(':a' => $account_id, ':b' => (int)$bench_id));
	}
	public static function benches($account_id, $include_retired = false)
	{
		return self::all('SELECT * FROM phoromatic_benches WHERE AccountID = :a' . ($include_retired ? '' : ' AND RetiredOn IS NULL') . ' ORDER BY RetiredOn IS NOT NULL, Title COLLATE NOCASE ASC', array(':a' => $account_id));
	}
	public static function members($account_id, $bench_id)
	{
		// The bench's live (not deleted) systems
		return self::all('SELECT * FROM phoromatic_systems WHERE AccountID = :a AND BenchID = :b AND DeletedOn IS NULL ORDER BY Title ASC', array(':a' => $account_id, ':b' => (int)$bench_id));
	}
	public static function open_session($account_id, $bench_id)
	{
		return self::with_components(self::one('SELECT s.*, c.Label, c.Class FROM phoromatic_bench_sessions s JOIN phoromatic_components c ON c.AccountID = s.AccountID AND c.ComponentID = s.ComponentID WHERE s.AccountID = :a AND s.BenchID = :b AND s.EndedOn IS NULL', array(':a' => $account_id, ':b' => (int)$bench_id)));
	}
	public static function session($account_id, $session_id)
	{
		return self::with_components(self::one('SELECT s.*, c.Label, c.Class FROM phoromatic_bench_sessions s JOIN phoromatic_components c ON c.AccountID = s.AccountID AND c.ComponentID = s.ComponentID WHERE s.AccountID = :a AND s.SessionID = :s', array(':a' => $account_id, ':s' => (int)$session_id)));
	}
	public static function sessions($account_id, $bench_id)
	{
		// Newest first, with their component and result counts (task / ad-hoc)
		$rows = self::all('SELECT s.*, c.Label, c.Class, (SELECT COUNT(*) FROM phoromatic_results r WHERE r.AccountID = s.AccountID AND r.BenchSessionID = s.SessionID AND IFNULL(r.BenchSessionSource, \'task\') <> \'upload\') AS TaskResults, (SELECT COUNT(*) FROM phoromatic_results r WHERE r.AccountID = s.AccountID AND r.BenchSessionID = s.SessionID AND r.BenchSessionSource = \'upload\') AS AdhocResults FROM phoromatic_bench_sessions s JOIN phoromatic_components c ON c.AccountID = s.AccountID AND c.ComponentID = s.ComponentID WHERE s.AccountID = :a AND s.BenchID = :b ORDER BY s.SessionID DESC', array(':a' => $account_id, ':b' => (int)$bench_id));
		foreach($rows as $k => $row)
		{
			$rows[$k] = self::with_components($row);
		}
		return $rows;
	}
	public static function component($account_id, $component_id)
	{
		if(!is_numeric($component_id) || (int)$component_id < 1)
		{
			return false;
		}
		return self::one('SELECT * FROM phoromatic_components WHERE AccountID = :a AND ComponentID = :c', array(':a' => $account_id, ':c' => (int)$component_id));
	}
	public static function components($account_id)
	{
		// All components with where they are now, how many sessions used them and how many results they have
		return self::all('SELECT c.*, (SELECT COUNT(*) FROM phoromatic_bench_session_components j WHERE j.AccountID = c.AccountID AND j.ComponentID = c.ComponentID) AS SessionCount, (SELECT COUNT(*) FROM phoromatic_results r JOIN phoromatic_bench_session_components j ON j.AccountID = r.AccountID AND j.SessionID = r.BenchSessionID WHERE r.AccountID = c.AccountID AND j.ComponentID = c.ComponentID) AS ResultCount, o.SessionID AS OpenSessionID, o.BenchID AS OpenBenchID, b.Title AS OpenBenchTitle, (SELECT MAX(j.SessionID) FROM phoromatic_bench_session_components j WHERE j.AccountID = c.AccountID AND j.ComponentID = c.ComponentID) AS LastSessionID FROM phoromatic_components c LEFT JOIN phoromatic_bench_session_components oj ON oj.AccountID = c.AccountID AND oj.ComponentID = c.ComponentID AND oj.OpenFlag = 1 LEFT JOIN phoromatic_bench_sessions o ON o.AccountID = oj.AccountID AND o.SessionID = oj.SessionID LEFT JOIN phoromatic_benches b ON b.AccountID = o.AccountID AND b.BenchID = o.BenchID WHERE c.AccountID = :a ORDER BY c.RetiredOn IS NOT NULL, o.SessionID IS NULL, c.Class, c.Label COLLATE NOCASE', array(':a' => $account_id));
	}
	public static function in_stock_components($account_id, $bench_id = 0)
	{
		// Parts a session may use: not retired, in no open session. With $bench_id also this bench's own open-session parts, tagged
		// Carried = 1 (a swap that keeps one part and changes another has to be able to pick the part it keeps).
		return self::all('SELECT c.*, EXISTS (SELECT 1 FROM phoromatic_bench_session_components j JOIN phoromatic_bench_sessions s ON s.AccountID = j.AccountID AND s.SessionID = j.SessionID WHERE j.AccountID = c.AccountID AND j.ComponentID = c.ComponentID AND j.OpenFlag = 1 AND s.BenchID = :b) AS Carried FROM phoromatic_components c WHERE c.AccountID = :a AND c.RetiredOn IS NULL AND NOT EXISTS (SELECT 1 FROM phoromatic_bench_session_components j JOIN phoromatic_bench_sessions s ON s.AccountID = j.AccountID AND s.SessionID = j.SessionID WHERE j.AccountID = c.AccountID AND j.ComponentID = c.ComponentID AND j.OpenFlag = 1 AND s.BenchID <> :b) ORDER BY c.Class, c.Label COLLATE NOCASE', array(':a' => $account_id, ':b' => (int)$bench_id));
	}
	public static function component_result_count($account_id, $component_id)
	{
		$row = self::one('SELECT COUNT(*) AS n FROM phoromatic_results r JOIN phoromatic_bench_session_components s ON s.AccountID = r.AccountID AND s.SessionID = r.BenchSessionID WHERE r.AccountID = :a AND s.ComponentID = :c', array(':a' => $account_id, ':c' => (int)$component_id));
		return $row ? (int)$row['n'] : 0;
	}
	public static function baseline($account_id, $session_id, $system_id)
	{
		return self::one('SELECT * FROM phoromatic_bench_baselines WHERE AccountID = :a AND SessionID = :s AND SystemID = :y', array(':a' => $account_id, ':s' => (int)$session_id, ':y' => (string)$system_id));
	}
	public static function system_bench($account_id, $system_id)
	{
		// The bench of a live system, or false
		return self::one('SELECT b.* FROM phoromatic_systems y JOIN phoromatic_benches b ON b.AccountID = y.AccountID AND b.BenchID = y.BenchID WHERE y.AccountID = :a AND y.SystemID = :y AND y.DeletedOn IS NULL', array(':a' => $account_id, ':y' => (string)$system_id));
	}
	public static function outstanding($account_id, $bench_id)
	{
		// Live member systems with a task issued and not yet finished (the client has not asked for new work since)
		return self::all('SELECT * FROM phoromatic_systems WHERE AccountID = :a AND BenchID = :b AND DeletedOn IS NULL AND BenchTaskSession IS NOT NULL', array(':a' => $account_id, ':b' => (int)$bench_id));
	}
	public static function run_state($m)
	{
		// What a system with a task snapshot is doing: 'idle' when its client reported idling / maintenance AFTER the issue (issuing a
		// task overwrites CurrentTask, so an Idling status must come from a later post: the run was abandoned, e.g. a crash, a failed
		// upload or a restart without the task issued again), 'off' when it has been silent for FORCE_SILENCE, else 'running'
		$task = ltrim((string)$m['CurrentTask']);
		if(stripos($task, 'Idling') === 0 || stripos($task, 'Maintenance') === 0)
		{
			return 'idle';
		}
		return strtotime($m['LastCommunication']) <= time() - self::FORCE_SILENCE ? 'off' : 'running';
	}
	public static function outstanding_mode($outstanding)
	{
		// For a session change: 'free' (no task outstanding, or every outstanding run was abandoned), 'force' (the rest is off: allowed
		// only with Force) or 'running' (refused)
		$mode = 'free';
		foreach($outstanding as $m)
		{
			$state = self::run_state($m);
			if($state == 'running')
			{
				return 'running';
			}
			if($state == 'off')
			{
				$mode = 'force';
			}
		}
		return $mode;
	}
	public static function detected_for_system($row)
	{
		// The last stored hardware summary of a system row (at most ~5 minutes old)
		return pts_hardware_summary::from_client_report(isset($row['SystemProperties']) ? $row['SystemProperties'] : null, isset($row['Hardware']) ? $row['Hardware'] : null);
	}
	public static function summary_has_values($summary)
	{
		foreach(pts_hardware_summary::TRACKED as $key)
		{
			if(pts_hardware_summary::display_value($summary, $key) !== null)
			{
				return true;
			}
		}
		return false;
	}
	public static function constant_parts_text($summary, $except_class)
	{
		// "GPU · board · 32 GB" from a summary, without the class under test (prefill for the Config text)
		$parts = array();
		foreach(array('cpu', 'gpu', 'motherboard', 'memory') as $key)
		{
			if($key != $except_class && ($v = pts_hardware_summary::display_value($summary, $key)) !== null)
			{
				$parts[] = $v;
			}
		}
		return self::clean_text(implode(' · ', $parts), 500);
	}
	public static function drift_state($json)
	{
		$d = is_string($json) && $json !== '' ? json_decode($json, true) : null;
		return is_array($d) && isset($d['kind']) ? $d : null;
	}
	public static function holds($drift)
	{
		// The drift state stops tests (awaiting swap, or the class under test changed); a notice does not
		return is_array($drift) && in_array($drift['kind'], array('awaiting', 'drift'), true);
	}
	public static function notice_fields($drift)
	{
		$fields = array();
		if(is_array($drift) && in_array($drift['kind'], array('drift', 'notice'), true) && isset($drift['fields']) && is_array($drift['fields']))
		{
			foreach($drift['fields'] as $f)
			{
				if(empty($f['holds']))
				{
					$fields[] = array('key' => $f['key'], 'baseline' => $f['baseline'], 'detected' => $f['detected']);
				}
			}
		}
		return $fields;
	}

	// ---------------------------------------------------------------- transaction helpers

	protected static function begin()
	{
		return @phoromatic_server::$db->exec('BEGIN IMMEDIATE') !== false;
	}
	protected static function fail($why, $message)
	{
		@phoromatic_server::$db->exec('ROLLBACK');
		phoromatic_admin_audit::mirror_note('rolled back, nothing changed: ' . $why);
		return array('ok' => false, 'message' => $message);
	}
	protected static function refuse($message)
	{
		@phoromatic_server::$db->exec('ROLLBACK');
		return array('ok' => false, 'message' => $message);
	}
	protected static function commit()
	{
		if(@phoromatic_server::$db->exec('COMMIT') === false)
		{
			return false;
		}
		phoromatic_server::bench_cache_reset();
		return true;
	}
	protected static function exec_sql($sql, $params)
	{
		$stmt = phoromatic_server::$db->prepare($sql);
		if($stmt === false)
		{
			return false;
		}
		foreach($params as $k => $v)
		{
			$stmt->bindValue($k, $v);
		}
		return @$stmt->execute() !== false;
	}
	protected static function next_id($table, $column)
	{
		// The id the next INSERT gets (inside BEGIN IMMEDIATE nobody else can take it), so the audit row can name it before the insert
		$seq = phoromatic_server::$db->querySingle('SELECT seq FROM sqlite_sequence WHERE name = \'' . $table . '\'');
		$max = phoromatic_server::$db->querySingle('SELECT MAX(' . $column . ') FROM ' . $table);
		return max((int)$seq, (int)$max) + 1;
	}
	protected static function audit($account_id, $actor, $actor_detail, $action, $subject_type, $subject_id, $subject_label, $detail)
	{
		return phoromatic_admin_audit::record($account_id, self::$actor_type, $actor, $action, array('actor_detail' => $actor_detail, 'subject_type' => $subject_type,
			'subject_id' => $subject_id, 'subject_label' => $subject_label, 'detail' => empty(self::$audit_extra) ? $detail : array_merge((array)$detail, self::$audit_extra)));
	}
	protected static function audit_failed()
	{
		return self::fail('audit failed: ' . phoromatic_admin_audit::last_error(), 'Nothing was changed: the audit record could not be written (' . self::h(phoromatic_admin_audit::last_error()) . ').');
	}
	protected static function db_failed($what)
	{
		return self::fail($what . ' step failed', 'Nothing was changed because of a database error.');
	}

	// ---------------------------------------------------------------- components

	protected static function component_fields($in, &$error)
	{
		// The editable fields of a component from a form, cleaned; $error set when something is not acceptable
		$error = null;
		$out = array();
		$out['Label'] = self::clean_label(isset($in['label']) ? $in['label'] : '');
		if($out['Label'] === false)
		{
			$error = 'The label must be 1-64 printable characters, without &lt; &gt; &quot; \' &amp; or \\.';
			return false;
		}
		foreach(array('vendor' => 'Vendor', 'model' => 'Model', 'serial' => 'Serial', 'revision' => 'Revision') as $k => $col)
		{
			$out[$col] = self::clean_text(isset($in[$k]) ? $in[$k] : '', 128);
		}
		$out['Notes'] = self::clean_text(isset($in['notes']) ? $in['notes'] : '', 1000, true);
		$out['PhotoURL'] = self::clean_photo_url(isset($in['photo_url']) ? $in['photo_url'] : '');
		if($out['PhotoURL'] === false)
		{
			$error = 'The photo link must be an http:// or https:// address.';
			return false;
		}
		return $out;
	}
	protected static function label_taken($account_id, $label, $except_component = 0)
	{
		return self::one('SELECT ComponentID FROM phoromatic_components WHERE AccountID = :a AND lower(Label) = lower(:l) AND RetiredOn IS NULL AND ComponentID <> :c', array(':a' => $account_id, ':l' => $label, ':c' => (int)$except_component)) != false;
	}
	protected static function insert_component_in_tx($account_id, $class, $fields, $detected_json, $actor, $actor_detail, $context)
	{
		// The new ComponentID, or an error array (the transaction is already rolled back then)
		if(!in_array($class, self::CLASSES, true))
		{
			return self::refuse('Unknown component class.');
		}
		if(self::label_taken($account_id, $fields['Label']))
		{
			return self::refuse('A component labelled ' . self::h($fields['Label']) . ' already exists. Labels must be unique (retire the old one, or pick it from the inventory).');
		}
		$id = self::next_id('phoromatic_components', 'ComponentID');
		if(!self::audit($account_id, $actor, $actor_detail, 'component_create', 'component', $id, $fields['Label'], array_merge(array('class' => $class, 'serial' => $fields['Serial'], 'vendor' => $fields['Vendor'], 'model' => $fields['Model']), $context)))
		{
			return self::audit_failed();
		}
		$ok = self::exec_sql('INSERT INTO phoromatic_components (ComponentID, AccountID, Class, Label, Vendor, Model, Serial, Revision, Notes, PhotoURL, DetectedJSON, CreatedOn, CreatedBy) VALUES (:id, :a, :class, :label, :vendor, :model, :serial, :revision, :notes, :photo, :detected, :now, :by)', array(
			':id' => $id, ':a' => $account_id, ':class' => $class, ':label' => $fields['Label'], ':vendor' => $fields['Vendor'], ':model' => $fields['Model'], ':serial' => $fields['Serial'],
			':revision' => $fields['Revision'], ':notes' => $fields['Notes'], ':photo' => $fields['PhotoURL'], ':detected' => $detected_json, ':now' => phoromatic_server::current_time(), ':by' => (string)$actor));
		return $ok ? $id : self::db_failed('component create');
	}
	public static function component_create($account_id, $in, $actor, $actor_detail = null)
	{
		$fields = self::component_fields($in, $error);
		if($fields === false)
		{
			return array('ok' => false, 'message' => $error);
		}
		$class = isset($in['class']) ? (string)$in['class'] : '';
		if(!self::begin())
		{
			return array('ok' => false, 'message' => 'The database is busy; nothing was changed.');
		}
		$id = self::insert_component_in_tx($account_id, $class, $fields, null, $actor, $actor_detail, array());
		if(is_array($id))
		{
			return $id;
		}
		if(!self::commit())
		{
			return self::db_failed('component create commit');
		}
		return array('ok' => true, 'message' => 'Component ' . self::h($fields['Label']) . ' added to the inventory.', 'component_id' => $id);
	}
	public static function component_edit($account_id, $component_id, $in, $actor, $actor_detail = null, $rename_confirmed = false)
	{
		// Label rename is allowed (all results show the new name) but needs confirmation when results exist; the class is locked once a
		// session used the component. Returns 'needs_confirm' => result count when a confirmation is required.
		$fields = self::component_fields($in, $error);
		if($fields === false)
		{
			return array('ok' => false, 'message' => $error);
		}
		if(!self::begin())
		{
			return array('ok' => false, 'message' => 'The database is busy; nothing was changed.');
		}
		$c = self::component($account_id, $component_id);
		if(!$c)
		{
			return self::refuse('Component not found.');
		}
		if($c['RetiredOn'] !== null)
		{
			return self::refuse('A retired component cannot be edited.');
		}
		$class = isset($in['class']) ? (string)$in['class'] : $c['Class'];
		if($class !== $c['Class'])
		{
			if(!in_array($class, self::CLASSES, true))
			{
				return self::refuse('Unknown component class.');
			}
			if(self::one('SELECT 1 AS x FROM phoromatic_bench_session_components WHERE AccountID = :a AND ComponentID = :c', array(':a' => $account_id, ':c' => (int)$c['ComponentID'])))
			{
				return self::refuse('The class of ' . self::h($c['Label']) . ' cannot change: a session has used it.');
			}
		}
		$renamed = $fields['Label'] !== $c['Label'];
		$results = self::component_result_count($account_id, $c['ComponentID']);
		if($renamed && strtolower($fields['Label']) !== strtolower($c['Label']) && self::label_taken($account_id, $fields['Label'], $c['ComponentID']))
		{
			return self::refuse('A component labelled ' . self::h($fields['Label']) . ' already exists.');
		}
		if($renamed && $results > 0 && !$rename_confirmed)
		{
			@phoromatic_server::$db->exec('ROLLBACK');
			return array('ok' => false, 'needs_confirm' => $results, 'message' => pts_strings::plural_handler($results, 'result') . ' will show the new name ' . self::h($fields['Label']) . ' instead of ' . self::h($c['Label']) . '.');
		}
		$before = array();
		$after = array();
		foreach(array_merge(array('Class' => $class), $fields) as $col => $v)
		{
			if((string)$c[$col] !== (string)$v)
			{
				$before[$col] = $c[$col];
				$after[$col] = $v;
			}
		}
		if(empty($after))
		{
			@phoromatic_server::$db->exec('ROLLBACK');
			return array('ok' => true, 'message' => 'Nothing to change.');
		}
		if(!self::audit($account_id, $actor, $actor_detail, 'component_edit', 'component', $c['ComponentID'], $fields['Label'], array('before' => $before, 'after' => $after, 'results_relabelled' => $renamed ? $results : 0)))
		{
			return self::audit_failed();
		}
		$ok = self::exec_sql('UPDATE phoromatic_components SET Class = :class, Label = :label, Vendor = :vendor, Model = :model, Serial = :serial, Revision = :revision, Notes = :notes, PhotoURL = :photo WHERE AccountID = :a AND ComponentID = :c AND RetiredOn IS NULL', array(
			':class' => $class, ':label' => $fields['Label'], ':vendor' => $fields['Vendor'], ':model' => $fields['Model'], ':serial' => $fields['Serial'], ':revision' => $fields['Revision'],
			':notes' => $fields['Notes'], ':photo' => $fields['PhotoURL'], ':a' => $account_id, ':c' => (int)$c['ComponentID'])) && phoromatic_server::$db->changes() == 1;
		if(!$ok || !self::commit())
		{
			return self::db_failed('component edit');
		}
		return array('ok' => true, 'message' => 'Component ' . self::h($fields['Label']) . ' saved.');
	}
	public static function component_retire($account_id, $component_id, $actor, $actor_detail = null)
	{
		if(!self::begin())
		{
			return array('ok' => false, 'message' => 'The database is busy; nothing was changed.');
		}
		$c = self::component($account_id, $component_id);
		if(!$c || $c['RetiredOn'] !== null)
		{
			return self::refuse('Component not found or already retired.');
		}
		$open = self::one('SELECT s.SessionID, b.Title FROM phoromatic_bench_session_components j JOIN phoromatic_bench_sessions s ON s.AccountID = j.AccountID AND s.SessionID = j.SessionID JOIN phoromatic_benches b ON b.AccountID = s.AccountID AND b.BenchID = s.BenchID WHERE j.AccountID = :a AND j.ComponentID = :c AND j.OpenFlag = 1', array(':a' => $account_id, ':c' => (int)$c['ComponentID']));
		if($open)
		{
			return self::refuse(self::h($c['Label']) . ' is under test on ' . self::h($open['Title']) . ' (S' . (int)$open['SessionID'] . '). Swap it out or end that session first.');
		}
		if(!self::audit($account_id, $actor, $actor_detail, 'component_retire', 'component', $c['ComponentID'], $c['Label'], array('class' => $c['Class'], 'results' => self::component_result_count($account_id, $c['ComponentID']))))
		{
			return self::audit_failed();
		}
		$ok = self::exec_sql('UPDATE phoromatic_components SET RetiredOn = :now, RetiredBy = :by WHERE AccountID = :a AND ComponentID = :c AND RetiredOn IS NULL', array(':now' => phoromatic_server::current_time(), ':by' => (string)$actor, ':a' => $account_id, ':c' => (int)$c['ComponentID'])) && phoromatic_server::$db->changes() == 1;
		if(!$ok || !self::commit())
		{
			return self::db_failed('component retire');
		}
		return array('ok' => true, 'message' => self::h($c['Label']) . ' retired. Its results keep its name.');
	}

	// ---------------------------------------------------------------- sessions

	protected static function resolve_component_in_tx($account_id, $o, $detected, $system_title, $actor, $actor_detail, $context)
	{
		// The component for a new session, per the form choice: 'detected' or 'new' (create one), 'inventory' (pick one in stock),
		// 'unspecified' (a placeholder). array(ComponentID, Class, Label), or an error array (transaction rolled back).
		$source = isset($o['source']) ? (string)$o['source'] : '';
		if($source == 'inventory')
		{
			$c = self::component($account_id, isset($o['component_id']) ? $o['component_id'] : 0);
			if(!$c || $c['RetiredOn'] !== null)
			{
				return self::refuse('That component is not in the inventory (it may have been retired).');
			}
			$open = self::one('SELECT s.SessionID, b.Title FROM phoromatic_bench_session_components j JOIN phoromatic_bench_sessions s ON s.AccountID = j.AccountID AND s.SessionID = j.SessionID JOIN phoromatic_benches b ON b.AccountID = s.AccountID AND b.BenchID = s.BenchID WHERE j.AccountID = :a AND j.ComponentID = :c AND j.OpenFlag = 1', array(':a' => $account_id, ':c' => (int)$c['ComponentID']));
			if($open)
			{
				return self::refuse(self::h($c['Label']) . ' is already under test on ' . self::h($open['Title']) . ' (S' . (int)$open['SessionID'] . '). A part is in one bench at a time.');
			}
			return array((int)$c['ComponentID'], $c['Class'], $c['Label']);
		}
		if($source == 'unspecified')
		{
			$label = 'Unspecified baseline (S' . self::next_id('phoromatic_bench_sessions', 'SessionID') . ')';
			$fields = array('Label' => $label, 'Vendor' => null, 'Model' => null, 'Serial' => null, 'Revision' => null, 'Notes' => 'Placeholder: rename it once the part is known.', 'PhotoURL' => null);
			$id = self::insert_component_in_tx($account_id, 'other', $fields, null, $actor, $actor_detail, $context);
			return is_array($id) ? $id : array($id, 'other', $label);
		}
		if($source == 'detected' || $source == 'new')
		{
			$class = isset($o['class']) ? (string)$o['class'] : '';
			if(!in_array($class, self::CLASSES, true))
			{
				return self::refuse('Pick the class under test.');
			}
			$fields = self::component_fields(array('label' => isset($o['label']) ? $o['label'] : '', 'serial' => isset($o['serial']) ? $o['serial'] : ''), $error);
			if($fields === false)
			{
				return self::refuse($error);
			}
			$detected_json = null;
			if($source == 'detected' && in_array($class, self::DETECTABLE, true) && pts_hardware_summary::display_value($detected, $class) !== null)
			{
				$detected_json = json_encode(array($class => $detected[$class]));
			}
			$id = self::insert_component_in_tx($account_id, $class, $fields, $detected_json, $actor, $actor_detail, $context);
			return is_array($id) ? $id : array($id, $class, $fields['Label']);
		}
		return self::refuse('Choose the component under test.');
	}
	protected static function resolve_extras_in_tx($account_id, $o, $primary)
	{
		// The optional further components of a multi-component session (a platform swap: CPU + board): inventory parts only, at most
		// MAX_COMPONENTS in all. array of array(ComponentID, Class, Label), or an error array (transaction rolled back).
		$extras = array();
		$ids = isset($o['extra_component_ids']) && is_array($o['extra_component_ids']) ? $o['extra_component_ids'] : array();
		$seen = array((int)$primary[0] => true);
		foreach($ids as $id)
		{
			if(is_string($id) ? trim($id) === '' : $id === null)
			{
				continue;
			}
			$c = self::component($account_id, $id);
			if(!$c || $c['RetiredOn'] !== null)
			{
				return self::refuse('A further component is not in the inventory (it may have been retired).');
			}
			if(isset($seen[(int)$c['ComponentID']]))
			{
				return self::refuse(self::h($c['Label']) . ' is picked twice.');
			}
			$seen[(int)$c['ComponentID']] = true;
			$open = self::one('SELECT s.SessionID, b.Title FROM phoromatic_bench_session_components j JOIN phoromatic_bench_sessions s ON s.AccountID = j.AccountID AND s.SessionID = j.SessionID JOIN phoromatic_benches b ON b.AccountID = s.AccountID AND b.BenchID = s.BenchID WHERE j.AccountID = :a AND j.ComponentID = :c AND j.OpenFlag = 1', array(':a' => $account_id, ':c' => (int)$c['ComponentID']));
			if($open)
			{
				return self::refuse(self::h($c['Label']) . ' is already under test on ' . self::h($open['Title']) . ' (S' . (int)$open['SessionID'] . '). A part is in one bench at a time.');
			}
			$extras[] = array((int)$c['ComponentID'], $c['Class'], $c['Label']);
		}
		if(1 + count($extras) > self::MAX_COMPONENTS)
		{
			return self::refuse('A session has at most ' . self::MAX_COMPONENTS . ' components.');
		}
		return $extras;
	}
	public static function new_detectable_classes($account_id, $components, $previous_session_id)
	{
		// The detectable classes a swap waits to see change: those of the components that are new to this session (not in the previous
		// one). A component carried over is not awaited. No previous session (first session, or after an end) awaits nothing.
		if(!$previous_session_id)
		{
			return array();
		}
		$previous = array();
		foreach(self::session_components($account_id, $previous_session_id) as $c)
		{
			$previous[(int)$c['ComponentID']] = true;
		}
		$classes = array();
		foreach($components as $c)
		{
			if(!isset($previous[(int)$c[0]]) && in_array($c[1], self::DETECTABLE, true) && !in_array($c[1], $classes, true))
			{
				$classes[] = $c[1];
			}
		}
		return $classes;
	}
	public static function carried_classes($account_id, $components, $previous_session_id)
	{
		// The classes of the components this swap keeps from the previous session
		$classes = array();
		if(!$previous_session_id)
		{
			return $classes;
		}
		$previous = array();
		foreach(self::session_components($account_id, $previous_session_id) as $c)
		{
			$previous[(int)$c['ComponentID']] = true;
		}
		foreach($components as $c)
		{
			if(isset($previous[(int)$c[0]]) && !in_array($c[1], $classes, true))
			{
				$classes[] = $c[1];
			}
		}
		return $classes;
	}
	public static function awaited_for_member($account_id, $system_id, $components, $previous_session_id)
	{
		// array(classes, previous baseline summary|null): what this member waits to see swapped in the new session. The classes of the
		// components new to the session, plus any class of a carried-over component that the member was still waiting for in the previous
		// session (a swap declared while a hold is pending must not forget it). Only classes the previous baseline has a value for.
		if(!$previous_session_id)
		{
			return array(array(), null);
		}
		$previous = self::baseline($account_id, $previous_session_id, $system_id);
		$base = null;
		if($previous)
		{
			$base = $previous['BaselineJSON'] !== null ? json_decode($previous['BaselineJSON'], true) : ($previous['AwaitingFromJSON'] !== null ? json_decode($previous['AwaitingFromJSON'], true) : null);
		}
		if(!is_array($base))
		{
			return array(array(), null);
		}
		$want = self::new_detectable_classes($account_id, $components, $previous_session_id);
		if($previous['AwaitingFromJSON'] !== null)
		{
			$pending = $previous['AwaitingClasses'] !== null ? json_decode($previous['AwaitingClasses'], true) : null;
			if(!is_array($pending))
			{
				$prev_session = self::session($account_id, $previous_session_id);
				$pending = $prev_session ? array($prev_session['Class']) : array();
			}
			$want = array_merge($want, array_intersect($pending, self::carried_classes($account_id, $components, $previous_session_id)));
		}
		$awaited = array();
		foreach(array_unique($want) as $k)
		{
			if(in_array($k, self::DETECTABLE, true) && pts_hardware_summary::display_value($base, $k) !== null)
			{
				$awaited[] = $k;
			}
		}
		return array($awaited, $base);
	}
	protected static function open_session_in_tx($account_id, $bench, $components, $config, $notes, $actor, $actor_detail, $previous_session_id, $extra_detail)
	{
		// Inserts the session (its components, the primary first) and one baseline row per live member system. true, or an error array
		// (transaction rolled back).
		list($component_id, $class, $label) = $components[0];
		$session_id = self::next_id('phoromatic_bench_sessions', 'SessionID');
		$members = self::members($account_id, $bench['BenchID']);
		if(!self::audit($account_id, $actor, $actor_detail, 'session_start', 'bench', $bench['BenchID'], $bench['Title'], array_merge(array('session' => 'S' . $session_id,
			'component_id' => $component_id, 'component' => $label, 'class' => $class, 'components' => array_map(function($c) { return array('id' => (int)$c[0], 'label' => $c[2], 'class' => $c[1]); }, $components), 'systems' => array_map(function($m) { return $m['SystemID']; }, $members),
			'previous_session' => $previous_session_id ? 'S' . $previous_session_id : null), $extra_detail)))
		{
			return self::audit_failed();
		}
		$now = phoromatic_server::current_time();
		if(!self::exec_sql('INSERT INTO phoromatic_bench_sessions (SessionID, AccountID, BenchID, ComponentID, Config, StartedOn, StartedBy, Notes) VALUES (:s, :a, :b, :c, :config, :now, :by, :notes)', array(
			':s' => $session_id, ':a' => $account_id, ':b' => (int)$bench['BenchID'], ':c' => (int)$component_id, ':config' => $config, ':now' => $now, ':by' => (string)$actor, ':notes' => $notes)))
		{
			return self::fail('session insert', 'Nothing was changed: the session could not be opened (is the component already under test elsewhere?).');
		}
		foreach($components as $position => $c)
		{
			if(!self::exec_sql('INSERT INTO phoromatic_bench_session_components (AccountID, SessionID, ComponentID, Position, OpenFlag) VALUES (:a, :s, :c, :p, 1)', array(':a' => $account_id, ':s' => $session_id, ':c' => (int)$c[0], ':p' => $position)))
			{
				return self::fail('session component insert', 'Nothing was changed: a component could not be added to the session (is it already under test elsewhere?).');
			}
		}
		foreach($members as $m)
		{
			$detected = self::detected_for_system($m);
			list($awaited, $previous_base) = self::awaited_for_member($account_id, $m['SystemID'], $components, $previous_session_id);
			$drift = null;
			if(!empty($awaited))
			{
				// 2B awaiting swap: tests hold until this system reports a different part of every awaited class (or "Use current hardware")
				$params = array(':start_hw' => null, ':start' => null, ':base' => null, ':base_on' => null, ':awaiting' => json_encode($previous_base), ':awaiting_classes' => json_encode($awaited));
				$fields = array();
				foreach($awaited as $k)
				{
					$fields[] = array('key' => $k, 'baseline' => pts_hardware_summary::display_value($previous_base, $k), 'detected' => pts_hardware_summary::display_value($detected, $k), 'holds' => true);
				}
				$drift = array('kind' => 'awaiting', 'session' => $session_id, 'since' => $now, 'fields' => $fields);
			}
			else
			{
				$has = self::summary_has_values($detected);
				$params = array(':start_hw' => $has ? $m['Hardware'] : null, ':start' => $has ? json_encode($detected) : null, ':base' => $has ? json_encode($detected) : null, ':base_on' => $has ? $now : null, ':awaiting' => null, ':awaiting_classes' => null);
			}
			if(!self::exec_sql('INSERT INTO phoromatic_bench_baselines (AccountID, SessionID, SystemID, StartHardware, StartJSON, BaselineJSON, BaselineOn, AwaitingFromJSON, AwaitingClasses) VALUES (:a, :s, :y, :start_hw, :start, :base, :base_on, :awaiting, :awaiting_classes)', array_merge($params, array(':a' => $account_id, ':s' => $session_id, ':y' => $m['SystemID']))))
			{
				return self::db_failed('baseline insert');
			}
			// A new session clears any hold or notice of the previous one
			if(!self::exec_sql('UPDATE phoromatic_systems SET BenchDrift = :drift WHERE AccountID = :a AND SystemID = :y', array(':drift' => $drift ? json_encode($drift) : null, ':a' => $account_id, ':y' => $m['SystemID'])))
			{
				return self::db_failed('drift reset');
			}
		}
		return $session_id;
	}
	protected static function end_open_session_in_tx($account_id, $bench, $session, $reason, $actor, $actor_detail, $extra_detail)
	{
		$results = self::one('SELECT COUNT(*) AS n FROM phoromatic_results WHERE AccountID = :a AND BenchSessionID = :s', array(':a' => $account_id, ':s' => (int)$session['SessionID']));
		if(!self::audit($account_id, $actor, $actor_detail, 'session_end', 'bench', $bench['BenchID'], $bench['Title'], array_merge(array('session' => 'S' . $session['SessionID'],
			'component_id' => (int)$session['ComponentID'], 'component' => $session['Label'], 'class' => $session['Class'], 'components' => array_map(function($c) { return array('id' => (int)$c['ComponentID'], 'label' => $c['Label'], 'class' => $c['Class']); }, $session['Components']), 'results' => $results ? (int)$results['n'] : 0, 'reason' => $reason), $extra_detail)))
		{
			return self::audit_failed();
		}
		$ok = self::exec_sql('UPDATE phoromatic_bench_sessions SET EndedOn = :now, EndedBy = :by, EndReason = :reason WHERE AccountID = :a AND SessionID = :s AND EndedOn IS NULL', array(
			':now' => phoromatic_server::current_time(), ':by' => (string)$actor, ':reason' => $reason, ':a' => $account_id, ':s' => (int)$session['SessionID'])) && phoromatic_server::$db->changes() == 1;
		$ok = $ok && self::exec_sql('UPDATE phoromatic_bench_session_components SET OpenFlag = NULL WHERE AccountID = :a AND SessionID = :s', array(':a' => $account_id, ':s' => (int)$session['SessionID']));
		// A part this session held may also be in another open session's row that has no flag (a Benchy 0.9.0 server started it while this
		// one still had the part, see prepare_database()). Now that the holder is gone that row takes the flag, or the part would look free
		// while a session still has it. At most one row per part: the oldest open one.
		foreach($session['Components'] as $c)
		{
			$ok = $ok && self::exec_sql('UPDATE phoromatic_bench_session_components SET OpenFlag = 1 WHERE AccountID = :a AND ComponentID = :c AND OpenFlag IS NULL AND SessionID = (SELECT MIN(j.SessionID) FROM phoromatic_bench_session_components j JOIN phoromatic_bench_sessions s ON s.AccountID = j.AccountID AND s.SessionID = j.SessionID WHERE j.AccountID = :a AND j.ComponentID = :c AND j.OpenFlag IS NULL AND s.EndedOn IS NULL)', array(':a' => $account_id, ':c' => (int)$c['ComponentID']));
		}
		return $ok ? true : self::db_failed('session end');
	}
	protected static function outstanding_refusal($account_id, $bench, $force, &$forced, &$abandoned = null)
	{
		// null when the bench may change sessions now, else the refusal message. The snapshot lasts until the run's final upload (a client
		// restarted mid-run may resume its partial result when the task is issued again). A run the client has visibly abandoned (it
		// reports idling after the issue) does not block; a bench that is off needs Force; a running one is refused. A late upload of the
		// old task still goes to the session it was issued for. $abandoned lists the systems whose outstanding task is set aside.
		$forced = false;
		$abandoned = array();
		$outstanding = self::outstanding($account_id, $bench['BenchID']);
		if(empty($outstanding))
		{
			return null;
		}
		$mode = self::outstanding_mode($outstanding);
		$names = self::h(implode(', ', array_map(function($m) { return $m['Title']; }, $outstanding)));
		if($mode == 'running')
		{
			return 'Not now: ' . self::h($bench['Title']) . ' has a task outstanding and is running it (' . $names . '). Wait until the run uploads its result.';
		}
		if($mode == 'force' && !$force)
		{
			return 'Not now: ' . self::h($bench['Title']) . ' has a task outstanding and has not checked in for 2 hours (' . $names . '). If the bench is off, tick Force to go ahead.';
		}
		$forced = $mode == 'force';
		$abandoned = array_map(function($m) { return $m['SystemID']; }, $outstanding);
		return null;
	}
	public static function promote($account_id, $system_id, $actor, $actor_detail, $o)
	{
		// Creates a bench (title from the form, default the system's title), links the system, opens the first session and its baseline row
		$title = self::clean_label(isset($o['title']) ? $o['title'] : '');
		if($title === false)
		{
			return array('ok' => false, 'message' => 'The bench name must be 1-64 printable characters, without &lt; &gt; &quot; \' &amp; or \\.');
		}
		$config = self::clean_text(isset($o['config']) ? $o['config'] : '', 500);
		$notes = self::clean_text(isset($o['notes']) ? $o['notes'] : '', 1000, true);
		if(!phoromatic_system_admin::valid_system_id($system_id))
		{
			return array('ok' => false, 'message' => 'System not found.');
		}
		if(!self::begin())
		{
			return array('ok' => false, 'message' => 'The database is busy; nothing was changed.');
		}
		$sys = self::one('SELECT * FROM phoromatic_systems WHERE AccountID = :a AND SystemID = :y AND DeletedOn IS NULL', array(':a' => $account_id, ':y' => $system_id));
		if(!$sys)
		{
			return self::refuse('System not found (a deleted system cannot become a bench).');
		}
		if(!in_array((int)$sys['State'], array(0, 1), true))
		{
			return self::refuse('Only an enabled or pending system can become a bench.');
		}
		if($sys['AdoptionCandidate'] !== null && $sys['AdoptionCandidate'] !== '')
		{
			return self::refuse('This system is waiting for an adopt / register-as-new decision. Make that decision first.');
		}
		if($sys['BenchID'] !== null)
		{
			return self::refuse('This system already belongs to a bench.');
		}
		$bench_id = self::next_id('phoromatic_benches', 'BenchID');
		$bench = array('BenchID' => $bench_id, 'Title' => $title);
		$detected = self::detected_for_system($sys);
		if(!self::audit($account_id, $actor, $actor_detail, 'bench_create', 'bench', $bench_id, $title, array('system' => $sys['SystemID'], 'system_title' => $sys['Title'])))
		{
			return self::audit_failed();
		}
		$now = phoromatic_server::current_time();
		if(!self::exec_sql('INSERT INTO phoromatic_benches (BenchID, AccountID, Title, Notes, CreatedOn, CreatedBy) VALUES (:b, :a, :t, :n, :now, :by)', array(':b' => $bench_id, ':a' => $account_id, ':t' => $title, ':n' => $notes, ':now' => $now, ':by' => (string)$actor)))
		{
			return self::db_failed('bench insert');
		}
		$ok = self::exec_sql('UPDATE phoromatic_systems SET BenchID = :b, BenchTaskSession = NULL, BenchTaskIssuedOn = NULL, BenchDrift = NULL WHERE AccountID = :a AND SystemID = :y AND DeletedOn IS NULL AND BenchID IS NULL', array(':b' => $bench_id, ':a' => $account_id, ':y' => $sys['SystemID'])) && phoromatic_server::$db->changes() == 1;
		if(!$ok)
		{
			return self::db_failed('system link');
		}
		$component = self::resolve_component_in_tx($account_id, $o, $detected, $sys['Title'], $actor, $actor_detail, array('bench' => 'B' . $bench_id));
		if(!is_array($component) || isset($component['ok']))
		{
			return $component;
		}
		$session_id = self::open_session_in_tx($account_id, $bench, array($component), $config, null, $actor, $actor_detail, null, array());
		if(is_array($session_id))
		{
			return $session_id;
		}
		if(!self::commit())
		{
			return self::db_failed('promote commit');
		}
		return array('ok' => true, 'message' => self::h($title) . ' (B' . $bench_id . ') created with ' . self::h($sys['Title']) . '; session S' . $session_id . ' tests ' . self::h($component[2]) . '.', 'bench_id' => $bench_id, 'session_id' => $session_id);
	}
	public static function start_session($account_id, $bench_id, $actor, $actor_detail, $o, $force = false)
	{
		// Swap: ends the open session (if any) and opens a new one with the chosen component, in one step
		$config = self::clean_text(isset($o['config']) ? $o['config'] : '', 500);
		$notes = self::clean_text(isset($o['notes']) ? $o['notes'] : '', 1000, true);
		if(!self::begin())
		{
			return array('ok' => false, 'message' => 'The database is busy; nothing was changed.');
		}
		$bench = self::bench($account_id, $bench_id);
		if(!$bench || $bench['RetiredOn'] !== null)
		{
			return self::refuse('Bench not found or retired.');
		}
		$members = self::members($account_id, $bench['BenchID']);
		if(empty($members))
		{
			return self::refuse('This bench has no system.');
		}
		$refusal = self::outstanding_refusal($account_id, $bench, $force, $forced, $abandoned);
		if($refusal !== null)
		{
			return self::refuse($refusal);
		}
		$open = self::open_session($account_id, $bench['BenchID']);
		$open_ids = array();
		foreach($open ? $open['Components'] : array() as $c)
		{
			$open_ids[] = (int)$c['ComponentID'];
		}
		if($open)
		{
			$ended = self::end_open_session_in_tx($account_id, $bench, $open, 'swap', $actor, $actor_detail, array('force' => $forced, 'outstanding_task_set_aside' => $abandoned));
			if($ended !== true)
			{
				return $ended;
			}
		}
		$component = self::resolve_component_in_tx($account_id, $o, self::detected_for_system($members[0]), $members[0]['Title'], $actor, $actor_detail, array('bench' => 'B' . $bench['BenchID']));
		if(!is_array($component) || isset($component['ok']))
		{
			return $component;
		}
		$extras = self::resolve_extras_in_tx($account_id, $o, $component);
		if(isset($extras['ok']))
		{
			return $extras;
		}
		$components = array_merge(array($component), $extras);
		$set = array_map(function($c) { return (int)$c[0]; }, $components);
		sort($set);
		$open_set = $open_ids;
		sort($open_set);
		if($open && $set == $open_set)
		{
			return self::refuse(count($components) == 1 ? self::h($open['Label']) . ' is already the component under test.' : 'That is the component set already under test (' . self::h($open['Label']) . ').');
		}
		$session_id = self::open_session_in_tx($account_id, $bench, $components, $config, $notes, $actor, $actor_detail, $open ? (int)$open['SessionID'] : null, array('force' => $forced, 'outstanding_task_set_aside' => $abandoned));
		if(is_array($session_id))
		{
			return $session_id;
		}
		if(!self::commit())
		{
			return self::db_failed('swap commit');
		}
		$awaited = array();
		foreach($members as $m)
		{
			// the classes some member actually waits for (not those no member has a previous value for)
			$awaited = array_values(array_unique(array_merge($awaited, self::awaited_for_member($account_id, $m['SystemID'], $components, $open ? (int)$open['SessionID'] : null)[0])));
		}
		return array('ok' => true, 'session_id' => $session_id, 'message' => ($open ? 'S' . (int)$open['SessionID'] . ' ended. ' : '') . 'S' . $session_id . ' started with ' . self::h(implode(' + ', array_map(function($c) { return $c[2]; }, $components))) . '.' .
			(!empty($awaited) ? ' Tests hold until the bench reports a different ' . self::h(implode(' and ', array_map(function($c) { return self::class_name($c); }, $awaited))) . '.' : ''));
	}
	public static function end_session($account_id, $bench_id, $actor, $actor_detail, $force = false)
	{
		if(!self::begin())
		{
			return array('ok' => false, 'message' => 'The database is busy; nothing was changed.');
		}
		$bench = self::bench($account_id, $bench_id);
		if(!$bench || $bench['RetiredOn'] !== null)
		{
			return self::refuse('Bench not found or retired.');
		}
		$open = self::open_session($account_id, $bench['BenchID']);
		if(!$open)
		{
			return self::refuse('This bench has no open session.');
		}
		$refusal = self::outstanding_refusal($account_id, $bench, $force, $forced, $abandoned);
		if($refusal !== null)
		{
			return self::refuse($refusal);
		}
		$ended = self::end_open_session_in_tx($account_id, $bench, $open, $forced ? 'force_end' : 'end', $actor, $actor_detail, array('force' => $forced, 'outstanding_task_set_aside' => $abandoned));
		if($ended !== true)
		{
			return $ended;
		}
		if(!self::exec_sql('UPDATE phoromatic_systems SET BenchDrift = NULL WHERE AccountID = :a AND BenchID = :b', array(':a' => $account_id, ':b' => (int)$bench['BenchID'])) || !self::commit())
		{
			return self::db_failed('session end');
		}
		return array('ok' => true, 'message' => 'S' . (int)$open['SessionID'] . ' ended. ' . self::h($bench['Title']) . ' runs no tests until a new component is declared.');
	}
	protected static function member_leaves_in_tx($account_id, $bench, $system_row, $actor, $actor_detail, $why)
	{
		// After a member was unlinked or deleted: ends the open session and retires the bench when no live system is left
		if(!empty(self::members($account_id, $bench['BenchID'])) || $bench['RetiredOn'] !== null)
		{
			return true;
		}
		$open = self::open_session($account_id, $bench['BenchID']);
		if($open)
		{
			$ended = self::end_open_session_in_tx($account_id, $bench, $open, 'bench_empty', $actor, $actor_detail, array('system' => $system_row['SystemID'], 'note' => $why));
			if($ended !== true)
			{
				return $ended;
			}
		}
		if(!self::audit($account_id, $actor, $actor_detail, 'bench_retire', 'bench', $bench['BenchID'], $bench['Title'], array('system' => $system_row['SystemID'], 'note' => $why)))
		{
			return self::audit_failed();
		}
		if(!phoromatic_bench_token::revoke_for_in_tx($account_id, $bench['BenchID'], null, 'bench_retired', self::$actor_type, $actor, $actor_detail))
		{
			return self::audit_failed();
		}
		$ok = self::exec_sql('UPDATE phoromatic_benches SET RetiredOn = :now, RetiredBy = :by WHERE AccountID = :a AND BenchID = :b AND RetiredOn IS NULL', array(':now' => phoromatic_server::current_time(), ':by' => (string)$actor, ':a' => $account_id, ':b' => (int)$bench['BenchID']));
		return $ok ? true : self::db_failed('bench retire');
	}
	public static function demote($account_id, $bench_id, $system_id, $actor, $actor_detail = null, $force = false)
	{
		// The undo of a promote: the system becomes an ordinary system again; the bench retires once its last system leaves.
		// Refused while a task is outstanding (forced only when the bench has been off for 2 hours), like ending a session.
		if(!self::begin())
		{
			return array('ok' => false, 'message' => 'The database is busy; nothing was changed.');
		}
		$bench = self::bench($account_id, $bench_id);
		if(!$bench || $bench['RetiredOn'] !== null)
		{
			return self::refuse('Bench not found or retired.');
		}
		$sys = self::one('SELECT * FROM phoromatic_systems WHERE AccountID = :a AND SystemID = :y AND BenchID = :b AND DeletedOn IS NULL', array(':a' => $account_id, ':y' => (string)$system_id, ':b' => (int)$bench['BenchID']));
		if(!$sys)
		{
			return self::refuse('That system is not on this bench.');
		}
		$refusal = self::outstanding_refusal($account_id, $bench, $force, $forced, $abandoned);
		if($refusal !== null)
		{
			return self::refuse($refusal);
		}
		if(!self::audit($account_id, $actor, $actor_detail, 'bench_demote', 'bench', $bench['BenchID'], $bench['Title'], array('system' => $sys['SystemID'], 'system_title' => $sys['Title'], 'force' => $forced, 'outstanding_task_set_aside' => $abandoned)))
		{
			return self::audit_failed();
		}
		$ok = self::exec_sql('UPDATE phoromatic_systems SET BenchID = NULL, BenchTaskSession = NULL, BenchTaskIssuedOn = NULL, BenchDrift = NULL WHERE AccountID = :a AND SystemID = :y AND BenchID = :b', array(':a' => $account_id, ':y' => $sys['SystemID'], ':b' => (int)$bench['BenchID'])) && phoromatic_server::$db->changes() == 1;
		if(!$ok)
		{
			return self::db_failed('demote');
		}
		if(!phoromatic_bench_token::revoke_for_in_tx($account_id, $bench['BenchID'], $sys['SystemID'], 'member_removed', self::$actor_type, $actor, $actor_detail))
		{
			return self::audit_failed();
		}
		$left = self::member_leaves_in_tx($account_id, $bench, $sys, $actor, $actor_detail, 'demoted');
		if($left !== true)
		{
			return $left;
		}
		if(!self::commit())
		{
			return self::db_failed('demote commit');
		}
		return array('ok' => true, 'message' => self::h($sys['Title']) . ' is an ordinary system again. Its bench results keep their component names.');
	}
	public static function os_name($row)
	{
		// The OS of a system as its client reports it ("OS: Ubuntu 24.04, Kernel: ..." -> "Ubuntu 24.04"), or null
		$software = isset($row['Software']) ? trim((string)$row['Software']) : '';
		if($software === '')
		{
			return null;
		}
		if(preg_match('/(?:^|,\s*)OS:\s*([^,]+)/', $software, $m))
		{
			return trim($m[1]);
		}
		return substr($software, 0, 64);
	}
	public static function os_family($row)
	{
		// Windows, macOS, BSD, Solaris or Linux (any other reported OS), or null when the system reported none
		$os = self::os_name($row);
		if($os === null)
		{
			return null;
		}
		$s = strtolower(isset($row['Software']) ? $row['Software'] : $os);
		foreach(array('windows' => 'Windows', 'macos' => 'macOS', 'mac os' => 'macOS', 'darwin' => 'macOS', 'bsd' => 'BSD', 'solaris' => 'Solaris', 'illumos' => 'Solaris') as $needle => $family)
		{
			if(strpos($s, $needle) !== false)
			{
				return $family;
			}
		}
		return 'Linux';
	}
	public static function member_candidates($account_id)
	{
		// Systems that may join a bench: approved (not pending, not a tombstone) and in no bench
		return self::all('SELECT * FROM phoromatic_systems WHERE AccountID = :a AND State = 1 AND DeletedOn IS NULL AND BenchID IS NULL ORDER BY Title COLLATE NOCASE ASC', array(':a' => $account_id));
	}
	public static function awaiting_swap($account_id, $session)
	{
		// The open session is waiting for a physical swap on at least one member
		if(!$session)
		{
			return false;
		}
		$row = self::one('SELECT COUNT(*) AS n FROM phoromatic_bench_baselines WHERE AccountID = :a AND SessionID = :s AND AwaitingFromJSON IS NOT NULL', array(':a' => $account_id, ':s' => (int)$session['SessionID']));
		return $row && (int)$row['n'] > 0;
	}
	public static function member_warnings($account_id, $members, $candidate, $open)
	{
		// What the add-a-system confirm page warns about (never a refusal), as plain text: another member runs the same OS family,
		// the detected class under test differs (drift normalisation), the bench is waiting for a swap
		$warnings = array();
		$family = self::os_family($candidate);
		$classes = $open ? $open['Classes'] : array();
		$candidate_hw = self::detected_for_system($candidate);
		foreach($members as $m)
		{
			if($family !== null && self::os_family($m) === $family)
			{
				$warnings[] = 'Another member already runs ' . $family . ' (' . $m['Title'] . '). A bench has one system per OS install; a second ' . $family . ' install is fine if that is what this is.';
			}
		}
		foreach($classes as $class)
		{
			if(!in_array($class, self::DETECTABLE, true))
			{
				continue;
			}
			foreach($members as $m)
			{
				$member_hw = self::detected_for_system($m);
				if(pts_hardware_summary::drift_key_differs($member_hw, $candidate_hw, $class))
				{
					$warnings[] = 'Detected ' . self::class_name($class) . ' differs: ' . pts_hardware_summary::display_value($member_hw, $class) . ' (' . $m['Title'] . ') / ' . pts_hardware_summary::display_value($candidate_hw, $class) . ' (' . $candidate['Title'] . '). These may be different machines.';
				}
			}
		}
		if(self::awaiting_swap($account_id, $open))
		{
			$warnings[] = 'The bench is waiting for a swap. Boot this system only after the new part is in, or its first check-in becomes its baseline.';
		}
		return $warnings;
	}
	public static function add_member($account_id, $bench_id, $system_id, $actor, $actor_detail = null)
	{
		// Links an approved system to a live bench (a dual-boot bench: one system per OS install). No baseline is written: the
		// member's first check-in in the open session creates it (drift_guard()). Warnings are recorded, never refuse.
		if(!phoromatic_system_admin::valid_system_id($system_id))
		{
			return array('ok' => false, 'message' => 'System not found.');
		}
		if(!self::begin())
		{
			return array('ok' => false, 'message' => 'The database is busy; nothing was changed.');
		}
		$bench = self::bench($account_id, $bench_id);
		if(!$bench || $bench['RetiredOn'] !== null)
		{
			return self::refuse('Bench not found or retired.');
		}
		$sys = self::one('SELECT * FROM phoromatic_systems WHERE AccountID = :a AND SystemID = :y AND DeletedOn IS NULL', array(':a' => $account_id, ':y' => $system_id));
		if(!$sys)
		{
			return self::refuse('System not found (a deleted system cannot join a bench).');
		}
		if((int)$sys['State'] !== 1)
		{
			return self::refuse('Only an approved, enabled system can join a bench. Approve it first.');
		}
		if($sys['BenchID'] !== null)
		{
			return self::refuse('This system already belongs to a bench.');
		}
		$open = self::open_session($account_id, $bench['BenchID']);
		$warnings = self::member_warnings($account_id, self::members($account_id, $bench['BenchID']), $sys, $open);
		if(!self::audit($account_id, $actor, $actor_detail, 'bench_member_add', 'bench', $bench['BenchID'], $bench['Title'], array('system' => $sys['SystemID'], 'system_title' => $sys['Title'],
			'os' => self::os_name($sys), 'session' => $open ? 'S' . $open['SessionID'] : null, 'warnings' => $warnings)))
		{
			return self::audit_failed();
		}
		$ok = self::exec_sql('UPDATE phoromatic_systems SET BenchID = :b, BenchDrift = NULL, BenchTaskSession = NULL, BenchTaskIssuedOn = NULL WHERE AccountID = :a AND SystemID = :y AND DeletedOn IS NULL AND BenchID IS NULL AND State = 1', array(':b' => (int)$bench['BenchID'], ':a' => $account_id, ':y' => $sys['SystemID'])) && phoromatic_server::$db->changes() == 1;
		if(!$ok)
		{
			return self::db_failed('member link');
		}
		if(!self::commit())
		{
			return self::db_failed('member add commit');
		}
		return array('ok' => true, 'message' => self::h($sys['Title']) . ' is now a system of ' . self::h($bench['Title']) . '.' . ($open ? ' Its first check-in in S' . (int)$open['SessionID'] . ' becomes its baseline.' : ' The bench needs a component before it runs tests.'));
	}
	// ---------------------------------------------------------------- result reassign (2C)

	protected static function bench_result_where($account_id, $bench_id, &$params)
	{
		// The results that belong to a bench: filed under one of its sessions, or of a current member with no session (from before
		// the promote)
		$params[':a'] = $account_id;
		$params[':b'] = (int)$bench_id;
		return 'r.AccountID = :a AND (r.BenchSessionID IN (SELECT SessionID FROM phoromatic_bench_sessions WHERE AccountID = :a AND BenchID = :b) OR (r.BenchSessionID IS NULL AND r.SystemID IN (SELECT SystemID FROM phoromatic_systems WHERE AccountID = :a AND BenchID = :b AND DeletedOn IS NULL)))';
	}
	public static function reassign_candidates($account_id, $bench_id, $filters = array())
	{
		// Finished results of the bench, newest first, filtered by date (from/to, Y-m-d), session ('' any, 'none', or an id) and schedule
		$params = array();
		$where = array(self::bench_result_where($account_id, $bench_id, $params), 'r.InProgress = 0');
		if(!empty($filters['from']) && preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $filters['from']))
		{
			$where[] = 'r.UploadTime >= :from';
			$params[':from'] = $filters['from'] . ' 00:00:00';
		}
		if(!empty($filters['to']) && preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $filters['to']))
		{
			$where[] = 'r.UploadTime <= :to';
			$params[':to'] = $filters['to'] . ' 23:59:59';
		}
		if(isset($filters['session']) && $filters['session'] === 'none')
		{
			$where[] = 'r.BenchSessionID IS NULL';
		}
		else if(isset($filters['session']) && is_numeric($filters['session']))
		{
			$where[] = 'r.BenchSessionID = :s';
			$params[':s'] = (int)$filters['session'];
		}
		if(isset($filters['schedule']) && is_numeric($filters['schedule']))
		{
			$where[] = 'IFNULL(r.ScheduleID, 0) = :sched';
			$params[':sched'] = (int)$filters['schedule'];
		}
		return self::all('SELECT r.UploadID, r.PPRID, r.Title, r.SystemID, r.UploadTime, r.ScheduleID, r.BenchmarkTicketID, r.BenchSessionID, r.BenchSessionSource, y.Title AS SystemTitle FROM phoromatic_results r LEFT JOIN phoromatic_systems y ON y.AccountID = r.AccountID AND y.SystemID = r.SystemID WHERE ' . implode(' AND ', $where) . ' ORDER BY r.UploadTime DESC, r.UploadID DESC LIMIT 2000', $params);
	}
	public static function reassign_warnings($account_id, $bench_id, $rows, $target_session)
	{
		// Plain-text warnings (never refusals) for the confirm page
		$warnings = array();
		$open = self::open_session($account_id, $bench_id);
		$target = $target_session !== null ? self::session($account_id, $target_session) : false;
		$outside = 0;
		$from_open = 0;
		foreach($rows as $r)
		{
			if($target && ($r['UploadTime'] < $target['StartedOn'] || ($target['EndedOn'] !== null && $r['UploadTime'] > $target['EndedOn'])))
			{
				$outside++;
			}
			if($open && $r['BenchSessionID'] !== null && (int)$r['BenchSessionID'] === (int)$open['SessionID'] && (int)$target_session !== (int)$open['SessionID'])
			{
				$from_open++;
			}
		}
		if($outside > 0)
		{
			$warnings[] = $outside . ' of these results were uploaded outside S' . (int)$target_session . ' (' . $target['StartedOn'] . ' to ' . ($target['EndedOn'] !== null ? $target['EndedOn'] : 'now') . ').';
		}
		if($from_open > 0)
		{
			$warnings[] = $from_open . ' of these results are in the open session S' . (int)$open['SessionID'] . ': moving them out lets today\'s schedule run again for that session.';
		}
		return $warnings;
	}
	public static function reassign_results($account_id, $bench_id, $upload_ids, $target_session, $actor, $actor_detail = null)
	{
		// Moves finished results of this bench to one of its sessions ($target_session) or to no session (null). One transaction, audit
		// row (result_reassign, every move) first. A result's deviation is cleared: it was measured against another session's baseline.
		$ids = array();
		foreach((array)$upload_ids as $u)
		{
			if(is_numeric($u) && (int)$u > 0)
			{
				$ids[(int)$u] = true;
			}
		}
		$ids = array_keys($ids);
		if(empty($ids))
		{
			return array('ok' => false, 'message' => 'Tick at least one result.');
		}
		if(count($ids) > self::MAX_REASSIGN)
		{
			return array('ok' => false, 'message' => 'At most ' . self::MAX_REASSIGN . ' results per action; nothing was changed.');
		}
		if(!self::begin())
		{
			return array('ok' => false, 'message' => 'The database is busy; nothing was changed.');
		}
		$bench = self::bench($account_id, $bench_id);
		if(!$bench)
		{
			return self::refuse('Bench not found.');
		}
		if($target_session !== null)
		{
			$target = self::session($account_id, $target_session);
			if(!$target || (int)$target['BenchID'] !== (int)$bench['BenchID'])
			{
				return self::refuse('That session is not a session of this bench; nothing was changed.');
			}
			$target_session = (int)$target['SessionID'];
		}
		$params = array();
		$rows = self::all('SELECT r.UploadID, r.BenchSessionID, r.InProgress FROM phoromatic_results r WHERE ' . self::bench_result_where($account_id, $bench['BenchID'], $params) . ' AND r.UploadID IN (' . implode(',', $ids) . ')', $params);
		$found = array();
		foreach($rows as $r)
		{
			if((int)$r['InProgress'] !== 0)
			{
				return self::refuse('Result #' . (int)$r['UploadID'] . ' is still being uploaded; nothing was changed.');
			}
			$found[(int)$r['UploadID']] = $r['BenchSessionID'] !== null ? (int)$r['BenchSessionID'] : null;
		}
		if(count($found) !== count($ids))
		{
			return self::refuse(count($ids) - count($found) . ' of the ticked results do not belong to this bench; nothing was changed.');
		}
		$moves = array();
		foreach($found as $u => $from)
		{
			if($from !== $target_session)
			{
				$moves[] = array('upload' => $u, 'from' => $from !== null ? 'S' . $from : null, 'to' => $target_session !== null ? 'S' . $target_session : null);
			}
		}
		if(empty($moves))
		{
			return self::refuse('The ticked results are already there; nothing was changed.');
		}
		if(!self::audit($account_id, $actor, $actor_detail, 'result_reassign', 'bench', $bench['BenchID'], $bench['Title'], array('to' => $target_session !== null ? 'S' . $target_session : null, 'count' => count($moves), 'moves' => $moves)))
		{
			return self::audit_failed();
		}
		$move_ids = array_map(function($m) { return (int)$m['upload']; }, $moves);
		if(!self::exec_sql('UPDATE phoromatic_results SET BenchSessionID = :s, BenchSessionSource = :src, BenchDeviation = NULL WHERE AccountID = :a AND UploadID IN (' . implode(',', $move_ids) . ')', array(':s' => $target_session, ':src' => $target_session !== null ? 'reassign' : null, ':a' => $account_id)) || phoromatic_server::$db->changes() != count($move_ids))
		{
			return self::db_failed('reassign');
		}
		if(!self::commit())
		{
			return self::db_failed('reassign commit');
		}
		return array('ok' => true, 'message' => pts_strings::plural_handler(count($moves), 'result') . ' moved to ' . ($target_session !== null ? 'S' . $target_session : 'no session (unassigned)') . '.');
	}

	// ---------------------------------------------------------------- ticket rerun (2C)

	public static function bench_tickets($account_id, $bench_id)
	{
		// Active benchmark tickets that target at least one live member (by system or group), each with 'targets' (the member rows)
		$members = self::members($account_id, $bench_id);
		$out = array();
		foreach(self::all('SELECT * FROM phoromatic_benchmark_tickets WHERE AccountID = :a AND State = 1 ORDER BY TicketIssueTime DESC', array(':a' => $account_id)) as $t)
		{
			$targets = array();
			foreach($members as $m)
			{
				$hit = in_array($m['SystemID'], explode(',', (string)$t['RunTargetSystems']), true);
				foreach(explode(',', (string)$t['RunTargetGroups']) as $g)
				{
					$hit = $hit || ($g !== '' && stripos((string)$m['Groups'], '#' . $g . '#') !== false);
				}
				if($hit)
				{
					$targets[] = $m;
				}
			}
			if(!empty($targets))
			{
				$t['targets'] = $targets;
				$out[] = $t;
			}
		}
		return $out;
	}
	public static function ticket_ran_in_session($account_id, $ticket_id, $system_id, $session_id)
	{
		return (bool)self::one('SELECT UploadID FROM phoromatic_results WHERE AccountID = :a AND BenchmarkTicketID = :t AND SystemID = :y AND BenchSessionID = :s AND InProgress = 0', array(':a' => $account_id, ':t' => (int)$ticket_id, ':y' => (string)$system_id, ':s' => (int)$session_id));
	}
	public static function rerun_requested($account_id, $ticket_id, $session_id)
	{
		return self::one('SELECT * FROM phoromatic_bench_ticket_reruns WHERE AccountID = :a AND TicketID = :t AND SessionID = :s', array(':a' => $account_id, ':t' => (int)$ticket_id, ':s' => (int)$session_id));
	}
	public static function class_hold($account_id, $bench_id)
	{
		// A member is held (awaiting a swap, or the class under test changed)
		foreach(self::members($account_id, $bench_id) as $m)
		{
			if(self::holds(self::drift_state($m['BenchDrift'])))
			{
				return true;
			}
		}
		return false;
	}
	public static function request_ticket_rerun($account_id, $bench_id, $ticket_id, $actor, $actor_detail = null)
	{
		// "Run again on the current component": lets an old ticket run once more on the bench's members it targets, in the open session
		if(!self::begin())
		{
			return array('ok' => false, 'message' => 'The database is busy; nothing was changed.');
		}
		$bench = self::bench($account_id, $bench_id);
		if(!$bench || $bench['RetiredOn'] !== null)
		{
			return self::refuse('Bench not found or retired.');
		}
		$open = self::open_session($account_id, $bench['BenchID']);
		if(!$open)
		{
			return self::refuse('The bench has no open session: declare the component first.');
		}
		if(self::class_hold($account_id, $bench['BenchID']))
		{
			return self::refuse('The bench is on hold (waiting for a swap, or its ' . self::h(self::class_names_text($open['Classes'])) . ' changed); resolve that first.');
		}
		$ticket = null;
		foreach(self::bench_tickets($account_id, $bench['BenchID']) as $t)
		{
			if((int)$t['TicketID'] === (int)$ticket_id)
			{
				$ticket = $t;
			}
		}
		if($ticket === null)
		{
			return self::refuse('No active ticket with that ID targets this bench.');
		}
		if(self::rerun_requested($account_id, $ticket['TicketID'], $open['SessionID']))
		{
			return self::refuse('A rerun of this ticket was already requested for S' . (int)$open['SessionID'] . '.');
		}
		$pending = array();
		foreach($ticket['targets'] as $m)
		{
			if(!self::ticket_ran_in_session($account_id, $ticket['TicketID'], $m['SystemID'], $open['SessionID']))
			{
				$pending[] = $m['SystemID'];
			}
		}
		if(empty($pending))
		{
			return self::refuse('This ticket already ran on every targeted system in S' . (int)$open['SessionID'] . '.');
		}
		if(!self::audit($account_id, $actor, $actor_detail, 'ticket_rerun_request', 'bench', $bench['BenchID'], $bench['Title'], array('ticket' => (int)$ticket['TicketID'], 'ticket_title' => $ticket['Title'], 'session' => 'S' . $open['SessionID'], 'systems' => $pending)))
		{
			return self::audit_failed();
		}
		if(!self::exec_sql('INSERT INTO phoromatic_bench_ticket_reruns (AccountID, TicketID, BenchID, SessionID, RequestedOn, RequestedBy) VALUES (:a, :t, :b, :s, :now, :by)', array(':a' => $account_id, ':t' => (int)$ticket['TicketID'], ':b' => (int)$bench['BenchID'], ':s' => (int)$open['SessionID'], ':now' => phoromatic_server::current_time(), ':by' => (string)$actor)) || !self::commit())
		{
			return self::db_failed('rerun request');
		}
		return array('ok' => true, 'message' => self::h($ticket['Title']) . ' will run again on ' . self::h($open['Label']) . ' (S' . (int)$open['SessionID'] . ') the next time each targeted system asks for work, within 24 hours.');
	}

	public static function member_deleted_in_tx($account_id, $system_row, $actor, $actor_detail)
	{
		// Called by phoromatic_system_admin::delete() inside its transaction, after the tombstone UPDATE. The tombstone keeps its BenchID
		// for history. true, or false after a rollback.
		$bench = self::bench($account_id, $system_row['BenchID']);
		if(!$bench)
		{
			return true;
		}
		if(!self::exec_sql('UPDATE phoromatic_systems SET BenchTaskSession = NULL, BenchTaskIssuedOn = NULL, BenchDrift = NULL WHERE AccountID = :a AND SystemID = :y', array(':a' => $account_id, ':y' => $system_row['SystemID'])))
		{
			return false;
		}
		if(!phoromatic_bench_token::revoke_for_in_tx($account_id, $bench['BenchID'], $system_row['SystemID'], 'system_deleted', self::$actor_type, $actor, $actor_detail))
		{
			return false;
		}
		return self::member_leaves_in_tx($account_id, $bench, $system_row, $actor, $actor_detail, 'system deleted') === true;
	}
	public static function ack($account_id, $bench_id, $system_id, $actor, $actor_detail, $o)
	{
		// Takes the hardware this system reports now as its baseline: "Use current hardware" (awaiting swap), "Same hardware, continue"
		// (class hold) or "Update baseline" (constant-part notice; the session's Config text can be edited in the same form)
		if(!self::begin())
		{
			return array('ok' => false, 'message' => 'The database is busy; nothing was changed.');
		}
		$bench = self::bench($account_id, $bench_id);
		if(!$bench || $bench['RetiredOn'] !== null)
		{
			return self::refuse('Bench not found or retired.');
		}
		$sys = self::one('SELECT * FROM phoromatic_systems WHERE AccountID = :a AND SystemID = :y AND BenchID = :b AND DeletedOn IS NULL', array(':a' => $account_id, ':y' => (string)$system_id, ':b' => (int)$bench['BenchID']));
		$open = self::open_session($account_id, $bench['BenchID']);
		if(!$sys || !$open)
		{
			return self::refuse('Nothing to acknowledge: the system is not on this bench, or the bench has no open session.');
		}
		$drift = self::drift_state($sys['BenchDrift']);
		if(!$drift)
		{
			return self::refuse('Nothing to acknowledge: ' . self::h($sys['Title']) . ' reports no hardware change.');
		}
		if(isset($o['expect_kind']) && $o['expect_kind'] !== $drift['kind'])
		{
			return self::refuse('The state changed since the page was opened; nothing was changed. Please review it again.');
		}
		$base_row = self::baseline($account_id, $open['SessionID'], $sys['SystemID']);
		// The hardware as reported at the drift check, with any part it did not detect filled from the system's stored (current) report
		$detected = self::fill_missing(isset($drift['detected']) && is_array($drift['detected']) ? $drift['detected'] : pts_hardware_summary::empty_summary(), self::detected_for_system($sys));
		if(!self::summary_has_values($detected))
		{
			return self::refuse('No hardware has been detected on ' . self::h($sys['Title']) . ' yet; let it check in first.');
		}
		$before = $base_row ? ($base_row['BaselineJSON'] !== null ? json_decode($base_row['BaselineJSON'], true) : json_decode((string)$base_row['AwaitingFromJSON'], true)) : null;
		$after = is_array($before) ? $before : pts_hardware_summary::empty_summary();
		if($drift['kind'] == 'notice')
		{
			// Only the changed constant parts move forward
			foreach(self::notice_fields($drift) as $f)
			{
				$after = pts_hardware_summary::with_key_from($after, $detected, $f['key']);
			}
		}
		else
		{
			// "Same hardware" / "Use current hardware" accept the part under test only; a constant part that also changed stays a notice
			// "Use current hardware" accepts the classes this member is waiting for; "Same hardware, continue" the session's held classes
			$accept = $open['Classes'];
			if($drift['kind'] == 'awaiting')
			{
				$accept = $base_row && $base_row['AwaitingClasses'] !== null ? json_decode($base_row['AwaitingClasses'], true) : array($open['Class']);
				$accept = is_array($accept) && !empty($accept) ? $accept : array($open['Class']);
			}
			foreach($accept as $key)
			{
				$after = pts_hardware_summary::with_key_from($after, $detected, $key);
			}
		}
		$after = self::fill_missing($after, $detected);
		$remaining = array();
		foreach(pts_hardware_summary::drift_changed_keys($after, $detected) as $key)
		{
			$remaining[] = array('key' => $key, 'baseline' => pts_hardware_summary::display_value($after, $key), 'detected' => pts_hardware_summary::display_value($detected, $key), 'holds' => false);
		}
		$new_state = empty($remaining) ? null : array('kind' => 'notice', 'fields' => $remaining, 'session' => (int)$open['SessionID'], 'since' => phoromatic_server::current_time(), 'detected' => $detected);
		$config = isset($o['config']) ? self::clean_text($o['config'], 500) : $open['Config'];
		$what = array('awaiting' => 'use_current_hardware', 'drift' => 'same_hardware_continue', 'notice' => 'update_baseline');
		if(!self::audit($account_id, $actor, $actor_detail, 'bench_drift_ack', 'bench', $bench['BenchID'], $bench['Title'], array('system' => $sys['SystemID'], 'session' => 'S' . $open['SessionID'],
			'kind' => $drift['kind'], 'action' => $what[$drift['kind']], 'fields' => $drift['fields'], 'baseline_before' => self::summary_display($before), 'baseline_after' => self::summary_display($after),
			'config_before' => $open['Config'], 'config_after' => $config, 'notice_after' => $remaining, 'note' => self::clean_text(isset($o['note']) ? $o['note'] : '', 300))))
		{
			return self::audit_failed();
		}
		$now = phoromatic_server::current_time();
		if($base_row)
		{
			$ok = self::exec_sql('UPDATE phoromatic_bench_baselines SET BaselineJSON = :base, BaselineOn = :now, AwaitingFromJSON = NULL, AwaitingClasses = NULL, StartJSON = IFNULL(StartJSON, :base), StartHardware = IFNULL(StartHardware, :hw) WHERE AccountID = :a AND SessionID = :s AND SystemID = :y', array(
				':base' => json_encode($after), ':now' => $now, ':hw' => $sys['Hardware'], ':a' => $account_id, ':s' => (int)$open['SessionID'], ':y' => $sys['SystemID']));
		}
		else
		{
			$ok = self::exec_sql('INSERT INTO phoromatic_bench_baselines (AccountID, SessionID, SystemID, StartHardware, StartJSON, BaselineJSON, BaselineOn) VALUES (:a, :s, :y, :hw, :base, :base, :now)', array(
				':base' => json_encode($after), ':now' => $now, ':hw' => $sys['Hardware'], ':a' => $account_id, ':s' => (int)$open['SessionID'], ':y' => $sys['SystemID']));
		}
		$ok = $ok && self::exec_sql('UPDATE phoromatic_systems SET BenchDrift = :d WHERE AccountID = :a AND SystemID = :y', array(':d' => $new_state ? json_encode($new_state) : null, ':a' => $account_id, ':y' => $sys['SystemID']));
		if($ok && $new_state && empty(self::notice_fields($drift)))
		{
			// the constant-part notice starts with this acknowledgement (one row per episode, as from the check-in path)
			self::machine_audit($account_id, $sys, $bench, 'bench_constant_changed', array('system' => $sys['SystemID'], 'system_title' => $sys['Title'], 'session' => 'S' . $open['SessionID'], 'class_under_test' => implode('+', $open['Classes']), 'fields' => $remaining));
		}
		$ok = $ok && self::exec_sql('UPDATE phoromatic_bench_sessions SET Config = :config WHERE AccountID = :a AND SessionID = :s', array(':config' => $config, ':a' => $account_id, ':s' => (int)$open['SessionID']));
		if(!$ok || !self::commit())
		{
			return self::db_failed('acknowledge');
		}
		return array('ok' => true, 'message' => 'Baseline of ' . self::h($sys['Title']) . ' updated for S' . (int)$open['SessionID'] . '. The start record is unchanged.' . ($drift['kind'] == 'notice' ? '' : ' Tests run again at the next check-in.') .
			($new_state ? ' A constant part still differs from the baseline, so new results are marked; use "Update baseline" for it.' : ''));
	}
	public static function summary_display($summary)
	{
		$out = array();
		foreach(pts_hardware_summary::TRACKED as $key)
		{
			$out[$key] = pts_hardware_summary::display_value($summary, $key);
		}
		return $out;
	}

	// ---------------------------------------------------------------- task issue (start.php) and the drift guard (2B)

	public static function issue_begin($account_id, $sys_row, $pp, $h)
	{
		// Called by start.php for a bench system. Opens a BEGIN IMMEDIATE transaction that stays open until issue_finish(), so a task
		// issue serialises against a swap. array('hold' => text|null, 'session' => row|null, 'bench' => row|null, 'tx' => bool)
		if(!self::begin())
		{
			return array('hold' => 'Bench: the server is busy; trying again later.', 'session' => null, 'bench' => null, 'tx' => false);
		}
		$issue = array('hold' => null, 'session' => null, 'bench' => null, 'tx' => true);
		// The task snapshot is NOT cleared here: a client asks for work only between runs, except after it restarted mid-run, and then the
		// partial local result can be resumed when the task is issued again. The snapshot ends with the run's final upload
		// (task_completed()), or is replaced when a new task is issued.
		$bench = self::bench($account_id, $sys_row['BenchID']);
		$issue['bench'] = $bench;
		if(!$bench || $bench['RetiredOn'] !== null)
		{
			$issue['hold'] = 'Bench retired: this system takes no bench tests.';
			return $issue;
		}
		$session = self::open_session($account_id, $bench['BenchID']);
		if(!$session)
		{
			$issue['hold'] = 'Bench needs a component: open the bench page.';
			return $issue;
		}
		$issue['session'] = $session;
		$detected = pts_hardware_summary::from_client_report($pp, $h);
		$issue['hold'] = self::drift_guard($account_id, $bench, $session, $sys_row, $detected, $h);
		return $issue;
	}
	protected static function drift_guard($account_id, $bench, $session, $sys_row, $detected, $h)
	{
		// Compares this request's hardware with the system's baseline in the open session and updates BenchDrift. The hold text, or null.
		$classes = $session['Classes'];
		$class = implode('+', $classes);
		$now = phoromatic_server::current_time();
		$previous = self::drift_state($sys_row['BenchDrift']);
		$base_row = self::baseline($account_id, $session['SessionID'], $sys_row['SystemID']);
		$has_detected = self::summary_has_values($detected);
		if(!$base_row)
		{
			self::exec_sql('INSERT INTO phoromatic_bench_baselines (AccountID, SessionID, SystemID, StartHardware, StartJSON, BaselineJSON, BaselineOn) VALUES (:a, :s, :y, :hw, :j, :j, :now)', array(
				':a' => $account_id, ':s' => (int)$session['SessionID'], ':y' => $sys_row['SystemID'], ':hw' => $has_detected ? $h : null, ':j' => $has_detected ? json_encode($detected) : null, ':now' => $has_detected ? $now : null));
			$base_row = self::baseline($account_id, $session['SessionID'], $sys_row['SystemID']);
		}
		$awaiting = $base_row && $base_row['AwaitingFromJSON'] !== null ? json_decode($base_row['AwaitingFromJSON'], true) : null;
		// The classes this member waits to see swapped: those recorded at the swap, else the session's primary class (a 0.9 row)
		$awaited = $base_row && $base_row['AwaitingClasses'] !== null ? json_decode($base_row['AwaitingClasses'], true) : null;
		$awaited = is_array($awaited) && !empty($awaited) ? $awaited : array($session['Class']);
		if(!$has_detected)
		{
			// A detection failure must not lift a hold nor create one: an awaiting swap (from the baseline row itself) keeps holding, and a
			// class-change hold of this session stays; nothing else holds
			if(is_array($awaiting))
			{
				$fields = array();
				foreach($awaited as $k)
				{
					$fields[] = array('key' => $k, 'baseline' => pts_hardware_summary::display_value($awaiting, $k), 'detected' => null, 'holds' => true);
				}
				return self::hold_text($bench, $session, array('kind' => 'awaiting', 'fields' => $fields));
			}
			return $previous !== null && $previous['kind'] === 'drift' && isset($previous['session']) && (int)$previous['session'] == (int)$session['SessionID'] ? self::hold_text($bench, $session, $previous) : null;
		}
		$state = null;
		if(is_array($awaiting))
		{
			$pending = array();
			foreach($awaited as $k)
			{
				if(!pts_hardware_summary::drift_key_differs($awaiting, $detected, $k))
				{
					$pending[] = $k;
				}
			}
			if(empty($pending))
			{
				// The swap happened (every awaited class shows a different part): the new parts become the baseline; the other parts keep
				// the previous baseline, so a constant part that changed at the same time shows as a notice (R12.3) instead of slipping
				// into the baseline unnoticed
				$baseline = $awaiting;
				foreach($awaited as $k)
				{
					$baseline = pts_hardware_summary::with_key_from($baseline, $detected, $k);
				}
				self::exec_sql('UPDATE phoromatic_bench_baselines SET StartHardware = :hw, StartJSON = :start, BaselineJSON = :base, BaselineOn = :now, AwaitingFromJSON = NULL, AwaitingClasses = NULL WHERE AccountID = :a AND SessionID = :s AND SystemID = :y', array(
					':hw' => $h, ':start' => json_encode($detected), ':base' => json_encode($baseline), ':now' => $now, ':a' => $account_id, ':s' => (int)$session['SessionID'], ':y' => $sys_row['SystemID']));
			}
			else
			{
				$fields = array();
				foreach($pending as $k)
				{
					$fields[] = array('key' => $k, 'baseline' => pts_hardware_summary::display_value($awaiting, $k), 'detected' => pts_hardware_summary::display_value($detected, $k), 'holds' => true);
				}
				$state = array('kind' => 'awaiting', 'fields' => $fields);
			}
		}
		if($state === null)
		{
			$baseline = isset($baseline) ? $baseline : ($base_row && $base_row['BaselineJSON'] !== null ? json_decode($base_row['BaselineJSON'], true) : null);
			if(!is_array($baseline))
			{
				// First check-in of the session with detected hardware: it becomes the baseline (and the start record)
				$baseline = $detected;
				self::exec_sql('UPDATE phoromatic_bench_baselines SET StartHardware = IFNULL(StartHardware, :hw), StartJSON = IFNULL(StartJSON, :j), BaselineJSON = :j, BaselineOn = :now WHERE AccountID = :a AND SessionID = :s AND SystemID = :y', array(
					':hw' => $h, ':j' => json_encode($detected), ':now' => $now, ':a' => $account_id, ':s' => (int)$session['SessionID'], ':y' => $sys_row['SystemID']));
			}
			else if(($filled = self::fill_missing($baseline, $detected)) != $baseline)
			{
				// A part the baseline never saw (undetected then) is taken as it is now, so a later change of it can be noticed
				$baseline = $filled;
				self::exec_sql('UPDATE phoromatic_bench_baselines SET BaselineJSON = :j WHERE AccountID = :a AND SessionID = :s AND SystemID = :y', array(
					':j' => json_encode($baseline), ':a' => $account_id, ':s' => (int)$session['SessionID'], ':y' => $sys_row['SystemID']));
			}
			$fields = array();
			$hold = false;
			foreach(pts_hardware_summary::drift_changed_keys($baseline, $detected) as $key)
			{
				$holds = in_array($key, $classes, true);
				$hold = $hold || $holds;
				$fields[] = array('key' => $key, 'baseline' => pts_hardware_summary::display_value($baseline, $key), 'detected' => pts_hardware_summary::display_value($detected, $key), 'holds' => $holds);
			}
			if(!empty($fields))
			{
				$state = array('kind' => $hold ? 'drift' : 'notice', 'fields' => $fields);
			}
		}

		// Write BenchDrift only when it changed, with one machine audit row per episode (not per check-in)
		$same = $previous !== null && $state !== null && $previous['kind'] === $state['kind'] && isset($previous['fields']) && $previous['fields'] == $state['fields'] && isset($previous['session']) && (int)$previous['session'] == (int)$session['SessionID'];
		if($state !== null)
		{
			$state['session'] = (int)$session['SessionID'];
			$state['since'] = $same && isset($previous['since']) ? $previous['since'] : $now;
			$state['detected'] = $detected;
		}
		if($same && (!isset($previous['detected']) || $previous['detected'] != $detected))
		{
			// Same state, but keep the hardware it was last seen with current (an acknowledgement takes its baseline from it); no audit row
			self::exec_sql('UPDATE phoromatic_systems SET BenchDrift = :d WHERE AccountID = :a AND SystemID = :y', array(':d' => json_encode($state), ':a' => $account_id, ':y' => $sys_row['SystemID']));
		}
		if(!$same && !($previous === null && $state === null))
		{
			self::exec_sql('UPDATE phoromatic_systems SET BenchDrift = :d WHERE AccountID = :a AND SystemID = :y', array(':d' => $state ? json_encode($state) : null, ':a' => $account_id, ':y' => $sys_row['SystemID']));
			$was_hold = $previous !== null && $previous['kind'] === 'drift';
			$is_hold = $state !== null && $state['kind'] === 'drift';
			$was_notice = !empty(self::notice_fields($previous));
			$is_notice = !empty(self::notice_fields($state));
			$detail = array('system' => $sys_row['SystemID'], 'system_title' => $sys_row['Title'], 'session' => 'S' . $session['SessionID'], 'class_under_test' => $class);
			if($is_hold && !$was_hold)
			{
				self::machine_audit($account_id, $sys_row, $bench, 'bench_drift_detected', array_merge($detail, array('fields' => $state['fields'])));
			}
			else if($was_hold && !$is_hold)
			{
				self::machine_audit($account_id, $sys_row, $bench, 'bench_drift_cleared', array_merge($detail, array('fields' => $previous['fields'])));
			}
			if($is_notice && !$was_notice)
			{
				self::machine_audit($account_id, $sys_row, $bench, 'bench_constant_changed', array_merge($detail, array('fields' => self::notice_fields($state))));
			}
			else if($was_notice && !$is_notice)
			{
				self::machine_audit($account_id, $sys_row, $bench, 'bench_constant_restored', array_merge($detail, array('fields' => self::notice_fields($previous))));
			}
		}
		return self::holds($state) ? self::hold_text($bench, $session, $state) : null;
	}
	public static function fill_missing($baseline, $detected)
	{
		// $baseline with every tracked part it has no value for taken from $detected (filling an unknown is not absorbing a change)
		foreach(pts_hardware_summary::TRACKED as $key)
		{
			if(pts_hardware_summary::display_value($baseline, $key) === null && pts_hardware_summary::display_value($detected, $key) !== null)
			{
				$baseline = pts_hardware_summary::with_key_from($baseline, $detected, $key);
			}
		}
		return $baseline;
	}
	protected static function machine_audit($account_id, $sys_row, $bench, $action, $detail)
	{
		// Written by the check-in path; capped per account per hour so a flapping detection cannot flood the table. A failure does not
		// change the hold decision (the hold is the safe side).
		try
		{
			$count = self::one('SELECT COUNT(*) AS n FROM phoromatic_admin_audit WHERE AccountID = :a AND ActorType = \'machine\' AND Action IN (\'bench_drift_detected\', \'bench_drift_cleared\', \'bench_constant_changed\', \'bench_constant_restored\') AND EventTime >= :since', array(':a' => $account_id, ':since' => date('Y-m-d H:i:s', time() - 3600)));
			if(!$count || $count['n'] >= self::MACHINE_AUDIT_CAP_PER_HOUR)
			{
				return false;
			}
			return phoromatic_admin_audit::record($account_id, 'machine', $sys_row['SystemID'], $action, array('subject_type' => 'bench', 'subject_id' => $bench['BenchID'], 'subject_label' => $bench['Title'], 'detail' => $detail));
		}
		catch(Throwable $e)
		{
			return false;
		}
	}
	public static function hold_text($bench, $session, $state)
	{
		// The reply text for the client (plain text, never another account's data)
		$f = isset($state['fields'][0]) ? $state['fields'][0] : array('key' => $session['Class'], 'baseline' => null, 'detected' => null);
		if($state['kind'] == 'awaiting')
		{
			$parts = array();
			foreach(!empty($state['fields']) ? $state['fields'] : array($f) as $field)
			{
				$parts[] = 'a ' . self::class_name($field['key']) . ' other than ' . ($field['baseline'] !== null ? $field['baseline'] : 'the previous one');
			}
			return 'Bench ' . $bench['Title'] . ' is waiting for the swap: session S' . $session['SessionID'] . ' (' . $session['Label'] . ') holds tests until ' . implode(' and ', $parts) . (count($parts) > 1 ? ' are' : ' is') . ' detected.';
		}
		foreach($state['fields'] as $field)
		{
			if(!empty($field['holds']))
			{
				$f = $field;
			}
		}
		return 'Hardware changed: ' . self::class_name($f['key']) . ' ' . $f['baseline'] . ' -> ' . $f['detected'] . ' since S' . $session['SessionID'] . '\'s baseline; tests are on hold until it is reviewed on the bench page.';
	}
	public static function issue_finish($account_id, $system_id, &$issue, &$json, $task_issued)
	{
		// Ends the issue transaction. For a schedule or ticket task: records the snapshot and adds the bench object and env variables.
		// If the snapshot cannot be recorded, the task is withdrawn and the client is told to idle (it asks again later).
		if($issue === null || empty($issue['tx']))
		{
			return;
		}
		$issue['tx'] = false;
		$recorded = true;
		if($task_issued && $issue['session'])
		{
			$s = $issue['session'];
			// CurrentTask is overwritten with the issue, so an "Idling" status seen later comes from a post made after it (run_state())
			$recorded = self::exec_sql('UPDATE phoromatic_systems SET BenchTaskSession = :s, BenchTaskIssuedOn = :now, CurrentTask = :task WHERE AccountID = :a AND SystemID = :y', array(':s' => (int)$s['SessionID'], ':now' => phoromatic_server::current_time(), ':task' => 'Task issued (bench S' . (int)$s['SessionID'] . ')', ':a' => $account_id, ':y' => $system_id)) && phoromatic_server::$db->changes() == 1;
			if($recorded)
			{
				// The session goes into the save name (not the trigger, which "already ran" matching depends on), so a client that keeps
				// its result files never reopens another session's file for the same trigger or ticket
				if(isset($json['phoromatic']['save_identifier']))
				{
					$json['phoromatic']['save_identifier'] .= ' - S' . (int)$s['SessionID'];
				}
				$json['phoromatic']['bench'] = array('session' => (int)$s['SessionID'], 'component' => array('label' => $s['PrimaryLabel'], 'class' => $s['Class']), 'bench' => array('id' => (int)$issue['bench']['BenchID'], 'title' => $issue['bench']['Title']));
				if(count($s['Components']) > 1)
				{
					// additive: only a multi-component session carries the list (a one-component reply is byte-identical to 0.9.0's)
					$json['phoromatic']['bench']['components'] = array_map(function($c) { return array('label' => $c['Label'], 'class' => $c['Class']); }, $s['Components']);
				}
				$vars = isset($json['phoromatic']['pre_set_sys_env_vars']) ? (string)$json['phoromatic']['pre_set_sys_env_vars'] : '';
				if($vars !== '' && substr($vars, -1) !== ';')
				{
					$vars .= ';';
				}
				$json['phoromatic']['pre_set_sys_env_vars'] = $vars . 'BENCHY_BENCH_SESSION=' . (int)$s['SessionID'] . ';BENCHY_BENCH_COMPONENT=' . self::env_value($s['Label']) . ';';
			}
		}
		if(!$recorded || @phoromatic_server::$db->exec('COMMIT') === false)
		{
			@phoromatic_server::$db->exec('ROLLBACK');
			if($task_issued)
			{
				$json = array('phoromatic' => array('system_id' => $json['phoromatic']['system_id'], 'server_core_version' => $json['phoromatic']['server_core_version'],
					'task' => 'idle', 'response' => '[' . date('H:i:s') . '] Bench: the task could not be recorded on the server; trying again later.'));
			}
		}
		phoromatic_server::bench_cache_reset();
	}
	public static function task_completed($account_id, $system_id, $session_id)
	{
		// The final upload of an issued task: the run is over, so its snapshot ends (only if it still names that session)
		self::exec_sql('UPDATE phoromatic_systems SET BenchTaskSession = NULL, BenchTaskIssuedOn = NULL WHERE AccountID = :a AND SystemID = :y AND BenchTaskSession = :s', array(':a' => $account_id, ':y' => $system_id, ':s' => (int)$session_id));
		phoromatic_server::bench_cache_reset();
	}
	public static function upload_attribution($account_id, $system_id, $has_task_ids)
	{
		// Which session a result upload of this system is filed under: the snapshot taken at task issue ('task'), else the session
		// open now ('upload', an ad-hoc upload). array(session id|null, source|null, deviation JSON|null); all null for an ordinary system.
		$sys = self::one('SELECT BenchID, BenchTaskSession, BenchDrift FROM phoromatic_systems WHERE AccountID = :a AND SystemID = :y', array(':a' => $account_id, ':y' => (string)$system_id));
		if(!$sys || $sys['BenchID'] === null)
		{
			return array(null, null, null);
		}
		$deviation = self::notice_fields(self::drift_state($sys['BenchDrift']));
		$deviation = empty($deviation) ? null : json_encode($deviation);
		if($has_task_ids && $sys['BenchTaskSession'] !== null)
		{
			return array((int)$sys['BenchTaskSession'], 'task', $deviation);
		}
		$open = self::open_session($account_id, $sys['BenchID']);
		return $open ? array((int)$open['SessionID'], 'upload', $deviation) : array(null, null, null);
	}
	public static function attention_items($account_id)
	{
		// Benches on hold or with a constant-part notice, and live benches with no open session, for the needs-attention box
		$items = array();
		foreach(self::benches($account_id) as $b)
		{
			$open = self::open_session($account_id, $b['BenchID']);
			$members = self::members($account_id, $b['BenchID']);
			if(!$open)
			{
				$items[] = array('bench' => $b, 'kind' => 'no_session', 'system' => null, 'drift' => null);
				continue;
			}
			foreach($members as $m)
			{
				if(($d = self::drift_state($m['BenchDrift'])) !== null)
				{
					$items[] = array('bench' => $b, 'kind' => $d['kind'], 'system' => $m, 'drift' => $d, 'session' => $open);
				}
			}
		}
		return $items;
	}
}

?>
