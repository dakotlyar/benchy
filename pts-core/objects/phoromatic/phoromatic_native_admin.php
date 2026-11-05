<?php

/*
	Benchy
	phoromatic_native_admin.php: the web side of native agents (Benchy 0.9.4): the engine policy of a system with the list of what will not
	run natively, enrollment codes and the pairing string, revoking an agent token, the "Native tests" box of a schedule, and the two
	server settings (plain HTTP for the agent API, download mirrors).

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

// Everything that changes something is a POST with the CSRF token and the same-origin check, only for administrators (levels 1-2, read
// from the database now), and is audited by the class that does it. Everything printed is escaped with h().

class phoromatic_native_admin
{
	public static function h($text)
	{
		return htmlspecialchars((string)$text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}
	protected static function actor()
	{
		return array(isset($_SESSION['UserName']) ? $_SESSION['UserName'] : '?', 'UserID ' . (isset($_SESSION['UserID']) ? $_SESSION['UserID'] : '?'));
	}
	protected static function posted_ok()
	{
		return $_SERVER['REQUEST_METHOD'] === 'POST' && phoromatic_private_repo_web::origin_ok() && verify_submission_token();
	}
	protected static function notice($text, $bad = false)
	{
		return '<p style="text-align: center;' . ($bad ? ' color: red;' : '') . '"><strong>' . self::h($text) . '</strong></p>';
	}
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

	// ----- the engine policy

	// What will not run on a native system: the schedules aimed at it (directly or through a group) and the account settings that are not
	// carried over (T10.5). A list of text lines; empty when nothing is lost.
	public static function wont_run($account_id, $system_row)
	{
		$lines = array();
		$schedules = self::all('SELECT ScheduleID, Title, RunTargetSystems, RunTargetGroups, SetContextPreInstall, SetContextPostInstall, SetContextPreRun, SetContextPostRun, EnvironmentVariables, ' .
			'(SELECT COUNT(*) FROM phoromatic_schedules_tests t WHERE t.AccountID = s.AccountID AND t.ScheduleID = s.ScheduleID) AS PtsTests, ' .
			'(SELECT COUNT(*) FROM phoromatic_schedules_native_tests n WHERE n.AccountID = s.AccountID AND n.ScheduleID = s.ScheduleID) AS NativeTests FROM phoromatic_schedules s WHERE s.AccountID = :a AND s.State = 1 ORDER BY s.Title', array(':a' => $account_id));
		foreach($schedules as $sched)
		{
			$aimed = in_array($system_row['SystemID'], explode(',', (string)$sched['RunTargetSystems']), true);
			foreach(explode(',', (string)$sched['RunTargetGroups']) as $group)
			{
				if($group !== '' && stripos((string)$system_row['Groups'], '#' . $group . '#') !== false)
				{
					$aimed = true;
				}
			}
			if(!$aimed)
			{
				continue;
			}
			$why = array();
			if((int)$sched['PtsTests'] > 0 && (int)$sched['NativeTests'] == 0)
			{
				$why[] = 'it has PTS tests but no native tests, so nothing of it runs on this system';
			}
			foreach(array('SetContextPreInstall', 'SetContextPostInstall', 'SetContextPreRun', 'SetContextPostRun') as $col)
			{
				if(!empty($sched[$col]))
				{
					$why[] = 'its context scripts are not sent to native systems';
					break;
				}
			}
			if(trim((string)$sched['EnvironmentVariables']) !== '')
			{
				$why[] = 'its environment variables (PTS settings) are not sent to native systems';
			}
			if(!empty($why))
			{
				$lines[] = 'Schedule "' . $sched['Title'] . '": ' . implode('; ', $why) . '.';
			}
		}
		$tickets = self::all('SELECT Title, RunTargetSystems, RunTargetGroups FROM phoromatic_benchmark_tickets WHERE AccountID = :a AND State = 1', array(':a' => $account_id));
		foreach($tickets as $t)
		{
			$aimed = in_array($system_row['SystemID'], explode(',', (string)$t['RunTargetSystems']), true);
			foreach(explode(',', (string)$t['RunTargetGroups']) as $group)
			{
				if($group !== '' && stripos((string)$system_row['Groups'], '#' . $group . '#') !== false)
				{
					$aimed = true;
				}
			}
			if($aimed)
			{
				$lines[] = 'Benchmark ticket "' . $t['Title'] . '": native agents do not run tickets yet.';
			}
		}
		$settings = self::one('SELECT PowerOffWhenDone, PreSeedTestInstalls, UploadSystemLogs FROM phoromatic_account_settings WHERE AccountID = :a', array(':a' => $account_id));
		if($settings && (int)$settings['PowerOffWhenDone'] == 1)
		{
			$lines[] = 'The account setting "power off when done" does not apply to native systems.';
		}
		if($settings && (int)$settings['PreSeedTestInstalls'] == 1)
		{
			$lines[] = 'The account setting "pre-seed test installs" does not apply to native systems.';
		}
		if($settings && (int)$settings['UploadSystemLogs'] == 1)
		{
			$lines[] = 'System logs are not uploaded by native systems.';
		}
		return $lines;
	}
	// null on success, else why nothing was changed. The audit row comes first, in the same transaction.
	public static function set_policy($account_id, $system_id, $policy, $actor, $actor_detail)
	{
		if($policy !== 'native' && $policy !== 'pts')
		{
			return 'Unknown engine; nothing was changed.';
		}
		$db = phoromatic_server::$db;
		if(@$db->exec('BEGIN IMMEDIATE') === false)
		{
			return 'The database is busy; nothing was changed.';
		}
		$row = self::one('SELECT * FROM phoromatic_systems WHERE AccountID = :a AND SystemID = :y AND DeletedOn IS NULL', array(':a' => $account_id, ':y' => $system_id));
		if(!$row)
		{
			@$db->exec('ROLLBACK');
			return 'System not found.';
		}
		$before = $row['EnginePolicy'] === 'native' ? 'native' : 'pts';
		if(!phoromatic_admin_audit::record($account_id, 'user', $actor, 'agent_policy_set', array('actor_detail' => $actor_detail, 'subject_type' => 'system', 'subject_id' => $system_id, 'subject_label' => $row['Title'],
			'detail' => array('before' => $before, 'after' => $policy))))
		{
			$why = phoromatic_admin_audit::last_error();
			@$db->exec('ROLLBACK');
			return 'The engine was not changed: the audit record could not be written (' . $why . ').';
		}
		$u = $db->prepare('UPDATE phoromatic_systems SET EnginePolicy = :p WHERE AccountID = :a AND SystemID = :y');
		$u->bindValue(':p', $policy === 'native' ? 'native' : null);
		$u->bindValue(':a', $account_id);
		$u->bindValue(':y', $system_id);
		if(@$u->execute() === false || @$db->exec('COMMIT') === false)
		{
			@$db->exec('ROLLBACK');
			return 'The engine was not changed because of a database error.';
		}
		return null;
	}

	// ----- enrollment

	// The https address an agent is given: the built-in HTTPS address when it is on, else this host over https (an agent refuses plain HTTP
	// unless the server allows it for a test rig, then the http address is the honest one)
	public static function server_url()
	{
		if(phoromatic_tls::is_on())
		{
			// The name the admin is browsing with, so the agent uses the same one. A Host header that is no valid name falls back to the first configured one.
			return phoromatic_tls::request_host() === false ? phoromatic_tls::public_base(phoromatic_tls::sni_name(phoromatic_tls::settings())) : phoromatic_tls::public_base();
		}
		$host = isset($_SERVER['HTTP_HOST']) ? preg_replace('/[^A-Za-z0-9.:\[\]-]/', '', $_SERVER['HTTP_HOST']) : 'localhost';
		return (phoromatic_api_v2::allow_http() ? 'http://' : 'https://') . $host;
	}
	public static function pairing_string($code)
	{
		$ca = phoromatic_tls::is_on() ? phoromatic_tls::root_ca_fingerprint() : false;
		return 'benchy-enroll:' . self::server_url() . '?code=' . $code . ($ca ? '&ca=' . $ca : '');
	}
	// The box that shows a new code, once
	public static function code_box($made)
	{
		if(!headers_sent())
		{
			// the page holds a live enrollment code: neither the browser nor a proxy keeps it
			header('Cache-Control: no-store');
		}
		$ca = phoromatic_tls::is_on() ? phoromatic_tls::root_ca_fingerprint() : false;
		$pairing = self::pairing_string($made['code']);
		$html = '<div style="border: 2px solid #c30; padding: 10px; margin: 10px 5%;"><h2>Enrollment code</h2>
		<p>This is shown once. The code works once and expires at ' . self::h($made['expires']) . ' (server time).</p>
		<table><tr><td><strong>Server</strong></td><td><code>' . self::h(self::server_url()) . '</code></td></tr>
		<tr><td><strong>Code</strong></td><td><code>' . self::h($made['code']) . '</code></td></tr>
		<tr><td><strong>CA fingerprint (SHA-256)</strong></td><td><code>' . ($ca ? self::h(phoromatic_tls::fingerprint_display($ca)) : 'none: this server is not using its own certificate authority') . '</code></td></tr></table>
		<p><strong>Pairing string</strong></p><p><textarea id="pairing_string" readonly="readonly" rows="3" style="width: 95%; font-family: monospace;">' . self::h($pairing) . '</textarea></p>
		<p><button type="button" onclick="var t = document.getElementById(\'pairing_string\'); t.select(); if(navigator.clipboard) { navigator.clipboard.writeText(t.value); }">Copy</button></p>
		<p>On the machine: <code>benchy-engine --json agent enroll --json-args -</code> with <code>["&lt;pairing string&gt;"]</code> on standard input (the code stays out of the process list).</p>';
		$problem = phoromatic_tls::is_on() ? phoromatic_tls::host_problem() : null;
		if($problem)
		{
			$html .= '<p style="color: red;"><strong>The address in this string, <code>' . self::h($problem['host']) . '</code>, is not one of the names this server\'s HTTPS certificate covers (' . self::h(implode(', ', $problem['names'])) . ').</strong> An agent that connects to it will fail the certificate check. ' .
				($problem['command'] ? 'To add it, run on the server: <code>' . self::h($problem['command']) . '</code> (the list replaces the old one, so it repeats the existing names). Until then, open this page by one of the names above and make a new code.'
				: 'This server uses a certificate you supplied, and only a certificate that includes that name will do. Until then, open this page by one of the names above and make a new code.') . '</p>';
		}
		if(!phoromatic_tls::is_on() && !phoromatic_api_v2::allow_http())
		{
			$html .= '<p style="color: red;"><strong>This server is not serving HTTPS, and the agent API refuses plain HTTP. Turn on the built-in HTTPS (or put a proxy with a certificate in front) before enrolling, or the agent will be refused.</strong></p>';
		}
		return $html . '</div>';
	}
	// Handles the posts of the systems page; $system_id is the system being viewed (null on the list). The html to show above the page.
	public static function handle_systems_post($account_id, $system_id)
	{
		$keys = array('native_enroll', 'native_policy', 'native_revoke_token', 'native_revoke_code');
		$sent = false;
		foreach($keys as $k)
		{
			$sent = $sent || isset($_POST[$k]);
		}
		if(!$sent)
		{
			return '';
		}
		if(!phoromatic_system_admin::admin_allowed())
		{
			return self::notice('Only administrators can change native agent settings; nothing was changed.', true);
		}
		if(!self::posted_ok())
		{
			return self::notice('The form expired or was not submitted from this site; nothing was changed. Please try again.', true);
		}
		list($actor, $detail) = self::actor();
		if(isset($_POST['native_enroll']))
		{
			$label = isset($_POST['native_label']) && is_string($_POST['native_label']) ? $_POST['native_label'] : '';
			$policy = $system_id === null || isset($_POST['native_switch']) ? 'native' : null;
			$made = phoromatic_agent_auth::create_code($account_id, $system_id, $policy, $label, 'user', $actor, $detail);
			return isset($made['error']) ? self::notice($made['error'], true) : self::code_box($made);
		}
		if(isset($_POST['native_policy']) && $system_id !== null)
		{
			$to = is_string($_POST['native_policy']) ? $_POST['native_policy'] : '';
			if($to === 'native' && !isset($_POST['native_confirm']))
			{
				return self::notice('Switching to the native engine has to be confirmed; nothing was changed.', true);
			}
			$error = self::set_policy($account_id, $system_id, $to, $actor, $detail);
			return $error === null ? self::notice($to === 'native' ? 'This system now takes its work from the native engine.' : 'This system is back on the PTS engine.') : self::notice($error, true);
		}
		if(isset($_POST['native_revoke_token']) && $system_id !== null)
		{
			$r = phoromatic_agent_auth::revoke_system_token($account_id, $system_id, 'manual', 'user', $actor, $detail);
			return isset($r['error']) ? self::notice($r['error'], true) : self::notice('The agent token was revoked. The agent stops at its next call; enroll it again with a new code.');
		}
		if(isset($_POST['native_revoke_code']) && is_string($_POST['native_revoke_code']))
		{
			$r = phoromatic_agent_auth::revoke_code($account_id, (int)$_POST['native_revoke_code'], 'user', $actor, $detail);
			return isset($r['error']) ? self::notice($r['error'], true) : self::notice('The enrollment code was revoked.');
		}
		return '';
	}
	protected static function form($body, $action_query)
	{
		return '<form action="?' . self::h($action_query) . '" method="post" style="display: inline;">' . write_token_in_form() . $body . '</form>';
	}
	// The "Native Engine" section of a system page
	public static function system_section($row, $admin)
	{
		$native = $row['EnginePolicy'] === 'native';
		$token = phoromatic_agent_auth::live_token($_SESSION['AccountID'], $row['SystemID']);
		$props = json_decode((string)$row['SystemProperties'], true);
		$props = is_array($props) ? $props : array();
		$q = 'systems/' . $row['SystemID'];
		$html = '<hr /><h2>Native Engine</h2>';
		$info = array('Engine:' => '<strong>' . ($native ? 'Native' : 'PTS') . '</strong>');
		if($token || $native)
		{
			$info['Agent:'] = $token ? (strpos((string)$row['ClientVersion'], 'benchy-native') === 0 ? self::h($row['ClientVersion']) : 'enrolled, no hello yet') : 'no agent enrolled';
			if($token)
			{
				$info['Token:'] = 'created ' . self::h($token['CreatedOn']) . ($token['LastUsedOn'] !== null ? ', last used ' . self::h($token['LastUsedOn']) . ' from ' . self::h($token['LastUsedIP']) : ', never used');
			}
		}
		$html .= pts_webui::r2d_array_to_table($info, 'auto');
		if(isset($props['benchy_agent']['kept_run_dirs']) && (int)$props['benchy_agent']['kept_run_dirs'] > 0)
		{
			$html .= '<p style="color: red;"><strong>' . (int)$props['benchy_agent']['kept_run_dirs'] . ' interrupted run folder(s) are kept on this machine for a person to look at.</strong></p>';
		}
		foreach(self::all('SELECT Warning, WarningTime FROM phoromatic_system_warnings WHERE AccountID = :a AND SystemID = :y ORDER BY WarningTime DESC LIMIT 5', array(':a' => $_SESSION['AccountID'], ':y' => $row['SystemID'])) as $w)
		{
			$html .= '<p style="color: red;"><strong>' . self::h($w['WarningTime']) . ':</strong> ' . self::h($w['Warning']) . '</p>';
		}
		if($admin)
		{
			$html .= '<p>';
			$html .= $native ? self::form('<input type="hidden" name="native_policy" value="pts" /><input type="submit" value="Switch back to the PTS engine" />', $q) :
				'<a href="?' . self::h($q) . '/engine">Switch to the native engine&hellip;</a>';
			$html .= '</p>';
			// With the switch ticked the system's work moves to the native engine, so the same list as on the Switch page sits next to the box
			$lost = '';
			if(!$native)
			{
				$lines = self::wont_run($_SESSION['AccountID'], $row);
				$lost = '<div>' . (empty($lines) ? 'Switching loses nothing that runs on this system today.' : 'If it is switched, this will not run natively:<ul><li>' .
					implode('</li><li>', array_map(array(__CLASS__, 'h'), $lines)) . '</li></ul>') . '</div>';
			}
			$html .= '<div>' . self::form('<input type="hidden" name="native_enroll" value="1" /> Label: <input type="text" name="native_label" value="' . self::h(phoromatic_system_admin::plain_title($row['Title'])) . '" maxlength="64" /> ' .
				($native ? '' : '<label><input type="checkbox" name="native_switch" value="1" checked="checked" /> switch it to the native engine</label> ') . '<input type="submit" value="Enroll a native agent" />' . $lost, $q) . '</div>';
			if($token)
			{
				$html .= '<p>' . self::form('<input type="hidden" name="native_revoke_token" value="1" /><input type="submit" value="Revoke agent token" onclick="return confirm(\'Revoke the agent token? The agent stops and has to be enrolled again.\');" />', $q) . '</p>';
			}
		}
		return $html;
	}
	// The confirmation page for switching a system to the native engine
	public static function policy_page($row)
	{
		$q = 'systems/' . $row['SystemID'];
		$html = '<h1>Switch ' . self::h($row['Title']) . ' to the native engine</h1>';
		if($row['EnginePolicy'] === 'native')
		{
			return $html . '<p>This system already runs the native engine. <a href="?' . self::h($q) . '">Back</a></p>';
		}
		$lines = self::wont_run($_SESSION['AccountID'], $row);
		$html .= '<p>From now on the PHP client on this machine idles and a native agent takes the work. Switching back is one click on the system page.</p>';
		if(!phoromatic_agent_auth::live_token($_SESSION['AccountID'], $row['SystemID']))
		{
			$html .= '<p style="color: red;"><strong>No native agent is enrolled for this system: nothing will run until one is.</strong></p>';
		}
		if(empty($lines))
		{
			$html .= '<p>Nothing that runs on this system today is lost.</p>';
		}
		else
		{
			$html .= '<h3>What will not run natively</h3><ul>';
			foreach($lines as $line)
			{
				$html .= '<li>' . self::h($line) . '</li>';
			}
			$html .= '</ul>';
		}
		$html .= self::form('<input type="hidden" name="native_policy" value="native" /><input type="hidden" name="native_confirm" value="1" /><input type="submit" value="Switch to the native engine" /> <a href="?' . self::h($q) . '">Cancel</a>', $q);
		return $html;
	}
	// On the systems list: a code for a new native system, and the codes that are still open
	public static function list_section($admin)
	{
		if(!$admin)
		{
			return '';
		}
		$html = '<h2>Enroll A Native System</h2><p>A code for a machine that is not in this list yet: it joins as a new system running the native engine. Codes work once and expire after 24 hours.</p>' .
			self::form('<input type="hidden" name="native_enroll" value="1" /> Label: <input type="text" name="native_label" maxlength="64" /> <input type="submit" value="Enroll a new native system" />', 'systems');
		$open = array();
		foreach(phoromatic_agent_auth::codes($_SESSION['AccountID']) as $c)
		{
			if($c['RevokedOn'] === null && $c['ExpiresOn'] >= phoromatic_server::current_time() && (int)$c['Uses'] < (int)$c['MaxUses'])
			{
				$open[] = $c;
			}
		}
		if(!empty($open))
		{
			$html .= '<table><tr><th>Open codes</th><th>For</th><th>Expires</th><th></th></tr>';
			foreach($open as $c)
			{
				$html .= '<tr><td>' . self::h($c['Label']) . '</td><td>' . ($c['SystemID'] !== null ? self::h($c['SystemID']) : 'a new system') . '</td><td>' . self::h($c['ExpiresOn']) . '</td><td>' .
					self::form('<input type="hidden" name="native_revoke_code" value="' . (int)$c['CodeID'] . '" /><input type="submit" value="Revoke" />', 'systems') . '</td></tr>';
			}
			$html .= '</table>';
		}
		return $html . '<hr />';
	}

	// ----- the Native tests box of a schedule

	// The tests the account's agents have reported: ref => array(title, options (the contract's option list), version, systems), the most recent
	// report of a ref winning, and systems = array(SystemID => title) of every system that reported it. With $system_ids only those systems'
	// reports count, which is what a schedule offers for its own targets. A stop-gap until the repositories of E4.
	public static function catalogue($account_id, $system_ids = null)
	{
		$out = array();
		$params = array(':a' => $account_id);
		$where = '';
		if(is_array($system_ids))
		{
			if(empty($system_ids))
			{
				return $out;
			}
			$marks = array();
			foreach(array_values($system_ids) as $i => $id)
			{
				$marks[] = ':s' . $i;
				$params[':s' . $i] = (string)$id;
			}
			$where = ' AND c.SystemID IN (' . implode(', ', $marks) . ')';
		}
		foreach(self::all('SELECT c.Ref, c.Version, c.TestJSON, c.SystemID, s.Title FROM phoromatic_system_catalogue c LEFT JOIN phoromatic_systems s ON s.AccountID = c.AccountID AND s.SystemID = c.SystemID WHERE c.AccountID = :a' . $where . ' ORDER BY c.Ref, c.ReportedOn DESC', $params) as $row)
		{
			$title = $row['Title'] !== null && $row['Title'] !== '' ? phoromatic_system_admin::plain_title($row['Title']) : $row['SystemID'];
			if(isset($out[$row['Ref']]))
			{
				$out[$row['Ref']]['systems'][$row['SystemID']] = $title;
				continue;
			}
			$t = json_decode($row['TestJSON'], true);
			if(is_array($t))
			{
				$out[$row['Ref']] = array('title' => isset($t['title']) && is_string($t['title']) ? $t['title'] : $row['Ref'], 'options' => isset($t['options']) && is_array($t['options']) ? $t['options'] : array(), 'version' => $row['Version'],
					'systems' => array($row['SystemID'] => $title));
			}
		}
		return $out;
	}
	// What a schedule may offer: the tests reported by the systems it targets (its systems and the members of its groups)
	public static function schedule_catalogue($account_id, $schedule_id)
	{
		return self::catalogue($account_id, array_values(phoromatic_server::systems_associated_with_schedule($account_id, $schedule_id)));
	}
	// The options a form posted for a catalogue test, checked against what the test declares: array(options) or a string saying what is wrong
	protected static function checked_options($test, $posted)
	{
		$options = array();
		foreach($test['options'] as $o)
		{
			if(!is_array($o) || !isset($o['key']) || !is_string($o['key']) || preg_match('/^[A-Za-z0-9_.-]{1,64}\z/', $o['key']) !== 1)
			{
				continue;
			}
			$key = $o['key'];
			$value = isset($posted[$key]) && is_string($posted[$key]) ? $posted[$key] : (isset($o['default']) && is_string($o['default']) ? $o['default'] : '');
			$kind = isset($o['kind']) ? $o['kind'] : 'text';
			if($kind === 'choice')
			{
				$keys = array();
				foreach(isset($o['choices']) && is_array($o['choices']) ? $o['choices'] : array() as $c)
				{
					$keys[] = isset($c['key']) ? (string)$c['key'] : '';
				}
				if(!in_array($value, $keys, true))
				{
					return 'The value for ' . (isset($o['label']) ? $o['label'] : $key) . ' is not one of the choices.';
				}
			}
			else if($kind === 'number' && !is_numeric($value) && $value !== '')
			{
				return 'The value for ' . (isset($o['label']) ? $o['label'] : $key) . ' must be a number.';
			}
			else if(strlen($value) > 200 || preg_match('/[\x00-\x1f\x7f]/', $value))
			{
				return 'The value for ' . (isset($o['label']) ? $o['label'] : $key) . ' is too long or has control characters.';
			}
			if($value !== '')
			{
				$options[$key] = $value;
			}
		}
		ksort($options);
		return $options;
	}
	public static function schedule_post($account_id, $schedule_id)
	{
		if(!isset($_POST['native_test_add']) && !isset($_POST['native_test_remove']))
		{
			return '';
		}
		if(PHOROMATIC_USER_IS_VIEWER)
		{
			return self::notice('Viewers cannot change schedules; nothing was changed.', true);
		}
		if(!self::posted_ok())
		{
			return self::notice('The form expired or was not submitted from this site; nothing was changed. Please try again.', true);
		}
		list($actor, $detail) = self::actor();
		$db = phoromatic_server::$db;
		if(isset($_POST['native_test_add']))
		{
			$ref = is_string($_POST['native_test_add']) ? $_POST['native_test_add'] : '';
			$cat = self::schedule_catalogue($account_id, $schedule_id);
			if(!isset($cat[$ref]))
			{
				return self::notice('None of the systems this schedule runs on has reported that test; nothing was added.', true);
			}
			$options = self::checked_options($cat[$ref], isset($_POST['opt']) && is_array($_POST['opt']) ? $_POST['opt'] : array());
			if(is_string($options))
			{
				return self::notice($options, true);
			}
			$json = json_encode((object)$options, JSON_UNESCAPED_SLASHES);
			if(@$db->exec('BEGIN IMMEDIATE') === false)
			{
				return self::notice('The database is busy; nothing was added.', true);
			}
			$pos = self::one('SELECT COALESCE(MAX(Position), -1) + 1 AS p FROM phoromatic_schedules_native_tests WHERE AccountID = :a AND ScheduleID = :s', array(':a' => $account_id, ':s' => $schedule_id));
			$ins = $db->prepare('INSERT OR IGNORE INTO phoromatic_schedules_native_tests (AccountID, ScheduleID, Position, Ref, OptionsJSON, Description, AddedOn, AddedBy) VALUES (:a, :s, :p, :r, :o, :d, :now, :by)');
			foreach(array(':a' => $account_id, ':s' => $schedule_id, ':p' => (int)$pos['p'], ':r' => $ref, ':o' => $json, ':d' => $cat[$ref]['title'], ':now' => phoromatic_server::current_time(), ':by' => $actor) as $k => $v)
			{
				$ins->bindValue($k, $v);
			}
			$ok = @$ins->execute() !== false && $db->changes() == 1;
			if($ok && !phoromatic_admin_audit::record($account_id, 'user', $actor, 'native_test_add', array('actor_detail' => $detail, 'subject_type' => 'schedule', 'subject_id' => (string)$schedule_id, 'detail' => array('ref' => $ref, 'options' => $options))))
			{
				$ok = false;
			}
			if(!$ok || @$db->exec('COMMIT') === false)
			{
				@$db->exec('ROLLBACK');
				return self::notice($ok ? 'The test could not be saved.' : 'Nothing was added (it is already in the schedule with those options, or the audit record could not be written).', true);
			}
			return self::notice('Added ' . $cat[$ref]['title'] . '.');
		}
		$ref = isset($_POST['native_test_ref']) && is_string($_POST['native_test_ref']) ? $_POST['native_test_ref'] : '';
		$options = isset($_POST['native_test_remove']) && is_string($_POST['native_test_remove']) ? $_POST['native_test_remove'] : '';
		if(@$db->exec('BEGIN IMMEDIATE') === false)
		{
			return self::notice('The database is busy; nothing was removed.', true);
		}
		$del = $db->prepare('DELETE FROM phoromatic_schedules_native_tests WHERE AccountID = :a AND ScheduleID = :s AND Ref = :r AND OptionsJSON = :o');
		foreach(array(':a' => $account_id, ':s' => $schedule_id, ':r' => $ref, ':o' => $options) as $k => $v)
		{
			$del->bindValue($k, $v);
		}
		$ok = @$del->execute() !== false && $db->changes() == 1 && phoromatic_admin_audit::record($account_id, 'user', $actor, 'native_test_remove', array('actor_detail' => $detail, 'subject_type' => 'schedule',
			'subject_id' => (string)$schedule_id, 'detail' => array('ref' => $ref, 'options' => json_decode($options, true))));
		if(!$ok || @$db->exec('COMMIT') === false)
		{
			@$db->exec('ROLLBACK');
			return self::notice('Nothing was removed.', true);
		}
		return self::notice('Removed.');
	}
	public static function schedule_box($account_id, $schedule_id, $can_edit)
	{
		$html = '<hr /><h2>Native Tests</h2><p>These run on systems that use the native engine; the tests above run on PTS systems. A schedule can have both.</p>';
		$rows = self::all('SELECT * FROM phoromatic_schedules_native_tests WHERE AccountID = :a AND ScheduleID = :s ORDER BY Position, Ref', array(':a' => $account_id, ':s' => (int)$schedule_id));
		$cat = self::catalogue($account_id);
		if(empty($rows))
		{
			$html .= '<p><em>No native tests yet.</em></p>';
		}
		foreach($rows as $r)
		{
			$options = json_decode($r['OptionsJSON'], true);
			$text = array();
			foreach(is_array($options) ? $options : array() as $k => $v)
			{
				$text[] = self::h($k) . ' = ' . self::h($v);
			}
			$html .= '<p>' . self::h(isset($cat[$r['Ref']]) ? $cat[$r['Ref']]['title'] : $r['Ref']) . ' <code>' . self::h($r['Ref']) . '</code>' . (empty($text) ? '' : ' - ' . implode(', ', $text));
			if($can_edit)
			{
				$html .= ' ' . self::form('<input type="hidden" name="native_test_ref" value="' . self::h($r['Ref']) . '" /><input type="hidden" name="native_test_remove" value="' . self::h($r['OptionsJSON']) . '" /><input type="submit" value="Remove" />', 'schedules/' . (int)$schedule_id);
			}
			$html .= '</p>';
		}
		if(!$can_edit)
		{
			return $html;
		}
		$offer = self::schedule_catalogue($account_id, $schedule_id);
		if(empty($offer))
		{
			return $html . '<p>' . (empty($cat) ? 'Enroll a native agent first; its tests appear here.' : 'None of the systems this schedule runs on has reported any tests yet. Pick its systems or groups, and enroll a native agent on them; their tests appear here.') . '</p>';
		}
		$html .= '<h3>Add A Native Test</h3>';
		$targets = count(phoromatic_server::systems_associated_with_schedule($account_id, (int)$schedule_id));
		foreach($offer as $ref => $t)
		{
			$fields = '';
			foreach($t['options'] as $o)
			{
				if(!is_array($o) || !isset($o['key']) || !is_string($o['key']) || preg_match('/^[A-Za-z0-9_.-]{1,64}\z/', $o['key']) !== 1)
				{
					continue;
				}
				$label = self::h(isset($o['label']) ? $o['label'] : $o['key']);
				$name = 'opt[' . self::h($o['key']) . ']';
				$default = isset($o['default']) && is_string($o['default']) ? $o['default'] : '';
				if(isset($o['kind']) && $o['kind'] === 'choice' && !empty($o['choices']))
				{
					$fields .= '<p>' . $label . ': <select name="' . $name . '">';
					foreach($o['choices'] as $c)
					{
						$ck = isset($c['key']) ? (string)$c['key'] : '';
						$fields .= '<option value="' . self::h($ck) . '"' . ($ck === $default ? ' selected="selected"' : '') . '>' . self::h(isset($c['label']) ? $c['label'] : $ck) . '</option>';
					}
					$fields .= '</select></p>';
				}
				else
				{
					$fields .= '<p>' . $label . ': <input type="text" name="' . $name . '" value="' . self::h($default) . '" maxlength="200" /></p>';
				}
			}
			// with several targets, say which of them can run the test
			$where = $targets > 1 ? ' <em>(' . (count($t['systems']) == $targets ? 'all ' . $targets . ' systems' : self::h(implode(', ', $t['systems']))) . ')</em>' : '';
			$html .= '<details><summary>' . self::h($t['title']) . ' <code>' . self::h($ref) . '</code>' . $where . '</summary>' .
				self::form($fields . '<input type="hidden" name="native_test_add" value="' . self::h($ref) . '" /><input type="submit" value="Add" />', 'schedules/' . (int)$schedule_id) . '</details>';
		}
		return $html;
	}

	// ----- the settings page

	public static function settings_post()
	{
		if(!isset($_POST['v2_settings']))
		{
			return '';
		}
		if(!phoromatic_system_admin::admin_allowed())
		{
			return self::notice('Only administrators can change these settings; nothing was changed.', true);
		}
		if(!self::posted_ok())
		{
			return self::notice('The form expired or was not submitted from this site; nothing was changed. Please try again.', true);
		}
		list($actor, $detail) = self::actor();
		// All or nothing: both settings are saved in one step, so a refused list, or a write that fails, leaves the plain-HTTP switch as it was too
		$mirrors = array_filter(array_map('trim', preg_split('/\R/', isset($_POST['v2_mirrors']) && is_string($_POST['v2_mirrors']) ? $_POST['v2_mirrors'] : '')), 'strlen');
		if(($e = phoromatic_server::set_v2_settings(isset($_POST['v2_allow_http']) ? 'on' : 'off', array_values($mirrors), $_SESSION['AccountID'], 'user', $actor, $detail)) !== null)
		{
			return self::notice($e, true);
		}
		return self::notice('Saved.');
	}
	public static function settings_section()
	{
		if(!phoromatic_system_admin::admin_allowed())
		{
			return '';
		}
		// The post comes first: the form has to show what is saved now, not what was saved before this request
		$notice = self::settings_post();
		$mirrors = phoromatic_server::read_setting('v2_mirrors');
		$allow = phoromatic_api_v2::allow_http();
		return $notice . self::form('<input type="hidden" name="v2_settings" value="1" />
<hr /><h2>Native Agent API</h2>
<p>Native agents talk to <code>/api/v2</code> and send a bearer token with every call, so the API insists on HTTPS.</p>' .
			($allow ? '<p style="color: red;"><strong>Plain HTTP is allowed for the agent API. Agents send their token in the clear. Use this for a test rig only.</strong></p>' : '') .
			'<p><label><input type="checkbox" name="v2_allow_http" value="1"' . ($allow ? ' checked="checked"' : '') . ' /> Allow the agent API over plain HTTP (test rigs only)</label></p>
<p>Download mirrors offered to agents, one https:// address per line. An agent only uses the ones on this server\'s own host name; others are never offered.</p>
<p><textarea name="v2_mirrors" rows="3" style="width: 60%;">' . self::h(implode("\n", is_array($mirrors) ? $mirrors : array())) . '</textarea></p>
<p><input type="submit" value="Save Agent API Settings" /></p>', 'settings');
	}
}

?>
