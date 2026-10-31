<?php

/*
	Benchy
	Read access to Phoromatic results for the bench pages and the bench labels of the result viewer.

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

// The only new code that opens result composites. Everything is keyed by AccountID; bench labels always resolve through the ROW's own
// account and session (the snapshot taken when the task was issued), never through what the bench holds now.

class phoromatic_result_access
{
	protected static function rows($sql, $params)
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
	public static function session_results($account_id, $session_id, $limit = 200)
	{
		// Newest first
		return self::rows('SELECT * FROM phoromatic_results WHERE AccountID = :a AND BenchSessionID = :s ORDER BY UploadTime DESC LIMIT ' . max(1, (int)$limit), array(':a' => $account_id, ':s' => (int)$session_id));
	}
	public static function bench_schedules($account_id, $bench_id = null)
	{
		// Schedules that have bench results (of one bench, or of all), as ScheduleID => Title
		$rows = self::rows('SELECT DISTINCT r.ScheduleID, sc.Title FROM phoromatic_results r JOIN phoromatic_bench_sessions s ON s.AccountID = r.AccountID AND s.SessionID = r.BenchSessionID LEFT JOIN phoromatic_schedules sc ON sc.AccountID = r.AccountID AND sc.ScheduleID = r.ScheduleID WHERE r.AccountID = :a AND r.ScheduleID > 0' . ($bench_id ? ' AND s.BenchID = :b' : '') . ' ORDER BY sc.Title', $bench_id ? array(':a' => $account_id, ':b' => (int)$bench_id) : array(':a' => $account_id));
		$out = array();
		foreach($rows as $r)
		{
			$out[(int)$r['ScheduleID']] = $r['Title'] !== null ? $r['Title'] : 'Schedule ' . (int)$r['ScheduleID'];
		}
		return $out;
	}
	public static function latest_per_component($account_id, $filters = array())
	{
		// The latest finished bench result per component, optionally for one bench, one class, one schedule (0 = ad-hoc uploads) and
		// one system; on a multi-system bench (0.9) also the latest of each other system in that same session, so both OS installs of a
		// dual-boot bench show. Each row: the result columns plus ComponentID, Label, Class, BenchID, BenchTitle, SessionID, SystemTitle.
		$where = array('r.AccountID = :a', 'r.InProgress = 0');
		$params = array(':a' => $account_id);
		if(!empty($filters['bench']))
		{
			$where[] = 's.BenchID = :b';
			$params[':b'] = (int)$filters['bench'];
		}
		if(!empty($filters['class']))
		{
			// a multi-component session matches if any of its components has the class
			$where[] = 'EXISTS (SELECT 1 FROM phoromatic_bench_session_components kj JOIN phoromatic_components kc ON kc.AccountID = kj.AccountID AND kc.ComponentID = kj.ComponentID WHERE kj.AccountID = s.AccountID AND kj.SessionID = s.SessionID AND kc.Class = :c)';
			$params[':c'] = (string)$filters['class'];
		}
		if(isset($filters['schedule']) && $filters['schedule'] !== null && $filters['schedule'] !== '')
		{
			$where[] = 'IFNULL(r.ScheduleID, 0) = :sched';
			$params[':sched'] = (int)$filters['schedule'];
		}
		if(!empty($filters['system']))
		{
			$where[] = 'r.SystemID = :y';
			$params[':y'] = (string)$filters['system'];
		}
		$rows = self::rows('SELECT r.*, s.ComponentID, s.BenchID, s.SessionID, c.Label, c.Class, b.Title AS BenchTitle, y.Title AS SystemTitle FROM phoromatic_results r JOIN phoromatic_bench_sessions s ON s.AccountID = r.AccountID AND s.SessionID = r.BenchSessionID JOIN phoromatic_components c ON c.AccountID = s.AccountID AND c.ComponentID = s.ComponentID JOIN phoromatic_benches b ON b.AccountID = s.AccountID AND b.BenchID = s.BenchID LEFT JOIN phoromatic_systems y ON y.AccountID = r.AccountID AND y.SystemID = r.SystemID WHERE ' . implode(' AND ', $where) . ' ORDER BY r.UploadTime DESC, r.UploadID DESC', $params);
		$latest = array();
		$exclude = !empty($filters['exclude_sets']) ? $filters['exclude_sets'] : array();
		foreach($rows as $r)
		{
			// Rows are keyed by the session's component set (one part and the platform it was tested in are different rows)
			$set = self::session_set($account_id, $r['SessionID']);
			$r['Label'] = $set['label'];
			$r['Class'] = $set['class'];
			$r['SetKey'] = $set['key'];
			if(in_array($set['key'], $exclude, true))
			{
				continue;
			}
			$c = $set['key'];
			if(!isset($latest[$c]))
			{
				$latest[$c] = array($r['SystemID'] => $r);
			}
			else if(!isset($latest[$c][$r['SystemID']]) && $r['SessionID'] == reset($latest[$c])['SessionID'])
			{
				$latest[$c][$r['SystemID']] = $r;
			}
		}
		$out = array();
		foreach($latest as $per_system)
		{
			foreach($per_system as $r)
			{
				$out[] = $r;
			}
		}
		return $out;
	}
	public static function session_set($account_id, $session_id)
	{
		// A session's component set: array(key = sorted ComponentIDs joined by comma, label = labels joined by " + ", class = classes
		// joined by "+", ids = ComponentIDs in position order); cached per request
		static $cache = array();
		$k = $account_id . '/' . (int)$session_id;
		if(!isset($cache[$k]))
		{
			$components = phoromatic_bench::session_components($account_id, $session_id);
			if(empty($components) && ($sess = phoromatic_bench::session($account_id, $session_id)))
			{
				$components = array(array('ComponentID' => $sess['ComponentID'], 'Label' => $sess['PrimaryLabel'], 'Class' => $sess['Class']));
			}
			$ids = array_map(function($c) { return (int)$c['ComponentID']; }, $components);
			$sorted = $ids;
			sort($sorted);
			$cache[$k] = array('key' => implode(',', $sorted), 'ids' => $ids, 'label' => implode(' + ', array_map(function($c) { return $c['Label']; }, $components)), 'class' => implode('+', array_map(function($c) { return $c['Class']; }, $components)));
		}
		return $cache[$k];
	}
	public static function bench_labels($rows)
	{
		// UploadID => array(label, class, session, bench_id, bench_title, system_title, deviation[], config) for the bench rows among
		// $rows (phoromatic_results rows); resolved with each row's own AccountID
		$out = array();
		foreach($rows as $row)
		{
			if(empty($row['BenchSessionID']))
			{
				continue;
			}
			$stmt = phoromatic_server::$db->prepare('SELECT s.SessionID, s.BenchID, s.ComponentID, s.Config, c.Label, c.Class, b.Title AS BenchTitle, (SELECT y.Title FROM phoromatic_systems y WHERE y.AccountID = s.AccountID AND y.SystemID = :system) AS SystemTitle FROM phoromatic_bench_sessions s JOIN phoromatic_components c ON c.AccountID = s.AccountID AND c.ComponentID = s.ComponentID JOIN phoromatic_benches b ON b.AccountID = s.AccountID AND b.BenchID = s.BenchID WHERE s.AccountID = :a AND s.SessionID = :s');
			$stmt->bindValue(':a', $row['AccountID']);
			$stmt->bindValue(':s', (int)$row['BenchSessionID']);
			$stmt->bindValue(':system', $row['SystemID']);
			$result = $stmt->execute();
			$b = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;
			if(!$b)
			{
				continue;
			}
			$deviation = !empty($row['BenchDeviation']) ? json_decode($row['BenchDeviation'], true) : null;
			// One part: its own label and class as in 0.9; several: the labels joined by " + " and the classes by "+"
			$set = self::session_set($row['AccountID'], $b['SessionID']);
			$out[$row['UploadID'] . '@' . $row['AccountID']] = array('label' => $set['label'], 'class' => $set['class'], 'session' => (int)$b['SessionID'], 'bench_id' => (int)$b['BenchID'], 'component_id' => (int)$b['ComponentID'], 'set_key' => $set['key'], 'account_id' => $row['AccountID'],
				'bench_title' => $b['BenchTitle'], 'system_title' => $b['SystemTitle'] !== null ? $b['SystemTitle'] : $row['SystemID'], 'deviation' => is_array($deviation) ? $deviation : array(),
				'config' => $b['Config'], 'trigger' => $row['Trigger'], 'upload_id' => $row['UploadID'], 'source' => $row['BenchSessionSource']);
		}
		return $out;
	}
	public static function label_key($row)
	{
		return $row['UploadID'] . '@' . $row['AccountID'];
	}
	public static function substitute_tokens($name, $info)
	{
		// The bench tokens, longest first, before any system variable gets a chance (so a system variable named COMPONENT never wins)
		return str_replace(array('.COMPONENT_CLASS', '.COMPONENT', '.SESSION', '.BENCH'), array($info['class'], $info['label'], 'S' . $info['session'], $info['bench_title']), $name);
	}
	public static function has_token($name)
	{
		return preg_match('/\.[A-Z][A-Z0-9_]*/', (string)$name) === 1;
	}
	public static function resolve_run_names($names, $infos)
	{
		// $names: key => run name; $infos: key => bench info (only for bench rows). Bench rows that share a name get, step by step,
		// " · <system title>", " (S<id>)", " · <trigger>", " #<UploadID>" until the names differ (a step is used only if it tells them apart)
		$steps = array(
			function($i) { return ' · ' . $i['system_title']; },
			function($i) { return ' (S' . $i['session'] . ')'; },
			function($i) { return ' · ' . $i['trigger']; },
			function($i) { return ' #' . $i['upload_id']; });
		foreach($steps as $n => $step)
		{
			$groups = array();
			foreach($names as $key => $name)
			{
				$groups[strtolower($name)][] = $key;
			}
			foreach($groups as $keys)
			{
				$bench_keys = array_values(array_filter($keys, function($k) use ($infos) { return isset($infos[$k]); }));
				if(count($keys) < 2 || empty($bench_keys))
				{
					continue;
				}
				$values = array();
				foreach($bench_keys as $k)
				{
					$values[$k] = $step($infos[$k]);
				}
				if(count(array_unique($values)) > 1 || count($bench_keys) < count($keys) || $n == count($steps) - 1)
				{
					foreach($values as $k => $suffix)
					{
						$names[$k] .= $suffix;
					}
				}
			}
		}
		return $names;
	}
	public static function open_composite($account_id, $upload_id)
	{
		$file = phoromatic_server::phoromatic_account_result_path($account_id, (int)$upload_id) . 'composite.xml';
		return is_file($file) ? new pts_result_file($file) : false;
	}
}

?>
