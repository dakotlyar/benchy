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
	// Whether the agent said it has a capability word at its last hello (server design 5.1). NULL and anything that doesn't parse mean no.
	public static function has_capability($system_row, $word)
	{
		$list = is_array($system_row) && isset($system_row['AgentCapabilities']) && is_string($system_row['AgentCapabilities']) ? json_decode($system_row['AgentCapabilities'], true) : null;
		return is_array($list) && in_array($word, $list, true);
	}
	// Forgets when this system last said hello, so its next poll asks it to say hello again. Used when an administrator approves (enables) a system: the
	// hello of an unapproved system was answered without the catalog (server design 4.3), so it has to say hello once more to get it.
	public static function forget_hello($account_id, $system_id)
	{
		$row = self::one('SELECT SystemProperties FROM phoromatic_systems WHERE AccountID = :a AND SystemID = :y', array(':a' => $account_id, ':y' => $system_id));
		$props = $row ? json_decode((string)$row['SystemProperties'], true) : null;
		if(!is_array($props) || !isset($props['benchy_agent']['hello_on']))
		{
			return;
		}
		unset($props['benchy_agent']['hello_on']);
		$stmt = phoromatic_server::$db->prepare('UPDATE phoromatic_systems SET SystemProperties = :p WHERE AccountID = :a AND SystemID = :y');
		$stmt->bindValue(':p', json_encode($props));
		$stmt->bindValue(':a', $account_id);
		$stmt->bindValue(':y', $system_id);
		@$stmt->execute();
	}
	public static function rows_of($sql, $params)
	{
		return self::all($sql, $params);
	}
	public static function actor_of()
	{
		return self::actor();
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
	// With $catalog_only only what the signed catalog's rules keep from a native system (6.4, 6.6): a pin its engine can't run, a withdrawn or too new version, a
	// test that needs administrator rights and what to do about it
	public static function wont_run($account_id, $system_row, $catalog_only = false)
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
			if(isset($system_row['EnginePolicy']) && $system_row['EnginePolicy'] === 'native' && (int)$sched['NativeTests'] > 0 && ($h = phoromatic_catalog_sched::hold($account_id, $system_row, $sched)) !== null)
			{
				$lines[] = $h['text'];
			}
			if($catalog_only)
			{
				continue;
			}
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
		if($catalog_only)
		{
			return $lines;
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
	// What a system on the PTS engine won't run (catalog suites, section 5): a schedule aimed at it that holds a native suite. The PTS tests of the same schedule keep running. A list of text lines.
	public static function pts_wont_run($account_id, $system_row)
	{
		$lines = array();
		if(!phoromatic_native_suites::ready())
		{
			return $lines;
		}
		foreach(self::all('SELECT s.ScheduleID, s.Title, s.RunTargetSystems, s.RunTargetGroups FROM phoromatic_schedules s WHERE s.AccountID = :a AND s.State = 1 AND EXISTS (SELECT 1 FROM phoromatic_schedules_native_tests n WHERE n.AccountID = s.AccountID AND n.ScheduleID = s.ScheduleID AND n.Kind = \'suite\') ORDER BY s.Title', array(':a' => $account_id)) as $sched)
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
			$titles = array();
			foreach(phoromatic_native_suites::expanded_rows($account_id, (int)$sched['ScheduleID']) as $r)
			{
				if($r['Suite'] !== null)
				{
					$titles[$r['Suite']['title']] = true;
				}
			}
			if(!empty($titles))
			{
				$lines[] = 'Schedule "' . $sched['Title'] . '" has native tests (suite ' . implode(', ', array_keys($titles)) . '), which only systems on the native engine run. Switch this system to the native engine to run them.';
			}
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
				($problem['command'] ? 'To add it, run on the server: <code>' . self::h(phoromatic_tls::as_service_user($problem['command'])) . '</code>. The list replaces the old one, so it repeats the names already there; list every name and address machines use to reach this server, including a NAT or port-forward address on the host. Until then, open this page by one of the names above and make a new code.'
				: 'This server uses a certificate you supplied, and only a certificate that includes that name will do. Until then, open this page by one of the names above and make a new code.') . '</p>';
		}
		if(!phoromatic_tls::is_on() && !phoromatic_api_v2::allow_http())
		{
			$html .= '<p style="color: red;"><strong>This server is not serving HTTPS, and the agent API refuses plain HTTP. Turn on the built-in HTTPS (or put a proxy with a certificate in front) before enrolling, or the agent will be refused.</strong></p>';
		}
		return $html . '</div>';
	}
	// The box that shows a new join code, once (server discovery D2): ten characters to type on the new machine
	public static function join_box($made)
	{
		if(!headers_sent())
		{
			header('Cache-Control: no-store');
		}
		return '<div style="border: 2px solid #c30; padding: 10px; margin: 10px 5%;"><h2>Join code</h2>
		<p>This is shown once. Type this on the new machine within an hour. It works once, and expires at ' . self::h($made['expires']) . ' (server time).</p>
		<p style="font-size: 2em; font-family: monospace; letter-spacing: 0.1em;"><code id="join_code">' . self::h(phoromatic_join_code::display($made['code'])) . '</code></p>
		<p><button type="button" onclick="var t = document.getElementById(\'join_code\').textContent; if(navigator.clipboard) { navigator.clipboard.writeText(t); }">Copy</button></p>
		<p>The new machine joins <strong>this account</strong> (' . self::h(trim((string)phoromatic_server::account_id_to_group_name($_SESSION['AccountID'])) !== '' ? trim((string)phoromatic_server::account_id_to_group_name($_SESSION['AccountID'])) : $_SESSION['AccountID']) . '). Server address, if it is asked for: <code>' . self::h(self::server_url()) . '</code></p>
		<p>Take the code from this page, not from anyone who sends you one: a join code only works with the server that made it.</p></div>';
	}
	// Handles the posts of the systems page; $system_id is the system being viewed (null on the list). The html to show above the page.
	public static function handle_systems_post($account_id, $system_id)
	{
		$keys = array('native_enroll', 'native_join', 'native_policy', 'native_revoke_token', 'native_revoke_code');
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
		if(isset($_POST['native_join']))
		{
			if($system_id !== null)
			{
				return self::notice('A join code makes a new system; nothing was changed.', true);
			}
			$label = isset($_POST['native_label']) && is_string($_POST['native_label']) ? $_POST['native_label'] : '';
			$made = phoromatic_agent_auth::create_code($account_id, null, 'native', $label, 'user', $actor, $detail, 'join');
			return isset($made['error']) ? self::notice($made['error'], true) : self::join_box($made);
		}
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
		if($native && phoromatic_catalog::ready() && strpos((string)$row['ClientVersion'], 'benchy-native') === 0)
		{
			$info['Administrator tests:'] = self::h(self::admin_row($row));
		}
		$html .= pts_webui::r2d_array_to_table($info, 'auto');
		if($native && phoromatic_catalog::ready() && !empty($lines = self::wont_run($_SESSION['AccountID'], $row, true)))
		{
			$html .= '<p><strong>Waiting on this system:</strong></p><ul><li>' . implode('</li><li>', array_map(array(__CLASS__, 'h'), $lines)) . '</li></ul>';
		}
		if(!$native && !empty($lines = self::pts_wont_run($_SESSION['AccountID'], $row)))
		{
			$html .= '<p><strong>Will not run on this system:</strong></p><ul><li>' . implode('</li><li>', array_map(array(__CLASS__, 'h'), $lines)) . '</li></ul>';
		}
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
	// What the "Administrator tests" row of a native system's page says (6.6: what runs as administrator, and what is missing)
	protected static function admin_row($row)
	{
		if(phoromatic_catalog_sched::engine_older($row, phoromatic_catalog_sched::ADMIN_ENGINE))
		{
			return phoromatic_catalog_sched::engine_fix();
		}
		if(phoromatic_catalog_sched::has_word($row, 'elevation_unattended'))
		{
			return 'Run with no prompt. Only an accepted, signed test that needs administrator rights runs with them; every other test runs as a standard user.';
		}
		if(phoromatic_catalog_sched::has_word($row, 'elevation_desktop'))
		{
			return 'Each run waits for someone at the machine to approve it, then asks Windows for administrator rights.';
		}
		return 'Not set up yet. ' . phoromatic_catalog_sched::fix_text($row);
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
		$join_note = '';
		if(!phoromatic_tls::is_on())
		{
			$join_note = '<p>Join codes need the server\'s built-in HTTPS, which is off.</p>';
		}
		else if(phoromatic_join_code::ready() && ($wait = phoromatic_join_code::paused()) > 0)
		{
			$join_note = '<p style="color: red;"><strong>' . self::h(phoromatic_join_code::pause_message($wait)) . '</strong></p>';
		}
		$html = '<h2>Enroll A Native System</h2><p>A code for a machine that is not in this list yet: it joins as a new system running the native engine. A pairing string works once and expires after 24 hours. A join code is short enough to type on the machine; it works once and expires after an hour.</p>' .
			self::form('<input type="hidden" name="native_enroll" value="1" /> Label: <input type="text" name="native_label" maxlength="64" /> <input type="submit" value="Enroll a new native system" />' .
				(phoromatic_tls::is_on() ? ' <input type="submit" name="native_join" value="Make a join code" />' : ''), 'systems') . $join_note;
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
			$html .= '<table><tr><th>Open codes</th><th>Kind</th><th>For</th><th>Expires</th><th></th></tr>';
			foreach($open as $c)
			{
				$html .= '<tr><td>' . self::h($c['Label']) . '</td><td>' . (isset($c['Kind']) && $c['Kind'] === 'join' ? 'Join code' : 'Pairing string') . '</td><td>' . ($c['SystemID'] !== null ? self::h($c['SystemID']) : 'a new system') . '</td><td>' . self::h($c['ExpiresOn']) . '</td><td>' .
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
				$out[$row['Ref']] = array('title' => isset($t['title']) && is_string($t['title']) ? $t['title'] : $row['Ref'], 'description' => isset($t['description']) && is_string($t['description']) ? $t['description'] : '', 'options' => isset($t['options']) && is_array($t['options']) ? $t['options'] : array(), 'version' => $row['Version'],
					'category' => isset($t['category']) ? $t['category'] : null, 'tags' => isset($t['tags']) ? $t['tags'] : null, 'estimated_run_s' => isset($t['estimated_run_s']) ? $t['estimated_run_s'] : null,
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
	public static function checked_options($test, $posted)
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
			else if(strlen($value) > 200 || preg_match('/[\x00-\x1f\x7f]/', $value))
			{
				return 'The value for ' . (isset($o['label']) ? $o['label'] : $key) . ' is too long or has control characters.';
			}
			// The engine's own number format (it refuses anything else when the job starts): digits with an optional decimal part, above
			// zero. is_numeric() also takes 1e3, 1e999 (infinity), .5 and 0x-less oddities the engine would turn away.
			else if($kind === 'number' && $value !== '' && (!preg_match('/^[0-9]+(\.[0-9]+)?\z/', $value) || !((float)$value > 0)))
			{
				return 'The value for ' . (isset($o['label']) ? $o['label'] : $key) . ' must be a number, digits with an optional decimal part such as 8 or 2.5, above zero.';
			}
			if($value !== '')
			{
				$options[$key] = $value;
			}
		}
		ksort($options);
		return $options;
	}
	const NEEDS_ADMIN = 'An account administrator has to change this, because the schedule runs tests that need administrator rights.';

	// One audit row for a change to an administrator schedule (6.6 S3), in the caller's transaction when it has one. True when it was written.
	public static function admin_change_audit($account_id, $schedule_id, $field, $from, $to, $tests)
	{
		list($actor, $detail) = self::actor();
		$title = self::one('SELECT Title FROM phoromatic_schedules WHERE AccountID = :a AND ScheduleID = :s', array(':a' => $account_id, ':s' => (int)$schedule_id));
		return phoromatic_admin_audit::record($account_id, 'user', $actor, 'admin_schedule_changed', array('actor_detail' => $detail, 'subject_type' => 'schedule', 'subject_id' => (string)$schedule_id,
			'subject_label' => $title ? $title['Title'] : null, 'detail' => array('schedule' => (int)$schedule_id, 'field' => $field, 'from' => $from, 'to' => $to, 'tests' => array_values($tests))));
	}
	// The version a posted choice means for a test in a schedule: array(true, version or null) or array(false, why). The server works the offer out again; the form isn't trusted (6.2).
	protected static function posted_version($account_id, $schedule_id, $ref, $posted)
	{
		if(!phoromatic_catalog::ready())
		{
			return array(true, null);
		}
		$pick = phoromatic_catalog_sched::pick($account_id, phoromatic_catalog_sched::targets($account_id, $schedule_id), $ref);
		if($posted === null || $posted === 'default')
		{
			return array(true, $pick['default']);
		}
		if($posted === 'none')
		{
			return array(true, null);
		}
		if(phoromatic_catalog_sched::valid_version($posted) && in_array($posted, $pick['choices'], true))
		{
			return array(true, $posted);
		}
		return array(false, 'That version is not one this schedule can pin (a system it runs on can\'t run a chosen version, or the version is not in the catalog); nothing was changed.');
	}
	// What a schedule's Add A Native Test box offers (CS4): the signed catalog's tests, then what the schedule's own systems report that the catalog doesn't hold, in the shape
	// schedule_catalogue() uses (title, options, version, systems) plus group ('free', 'nonfree' or 'reported') and catalog_only (no system reports it, so the options come from the manifest)
	public static function schedule_offer($account_id, $schedule_id)
	{
		$reported = self::schedule_catalogue($account_id, $schedule_id);
		$out = array();
		if(phoromatic_catalog::ready())
		{
			$catalog = phoromatic_native_suites::offer($account_id)['catalog'];
			foreach(array('public' => 'free', 'private' => 'nonfree') as $tier => $group)
			{
				foreach($catalog as $ref => $c)
				{
					if($c['tier'] !== $tier)
					{
						continue;
					}
					$newest = $c['versions'][$c['order'][0]];
					$out[$ref] = (isset($reported[$ref]) ? $reported[$ref] + array('group' => $group, 'catalog_only' => false) :
						array('title' => $c['title'], 'description' => $c['description'], 'options' => $newest['options'], 'version' => $c['order'][0], 'systems' => array(), 'group' => $group, 'catalog_only' => true));
					// the catalog's own category and tags win over what a system's report says (the report's raw fields are in the array already)
					$out[$ref]['cat'] = $c['cat'];
					$out[$ref]['tags'] = $c['tags'];
				}
			}
		}
		foreach($reported as $ref => $t)
		{
			if(!isset($out[$ref]))
			{
				$facts = phoromatic_native_taxonomy::facts($t['category'], $t['tags'], null);
				$out[$ref] = array('group' => 'reported', 'catalog_only' => false, 'cat' => $facts['cat'], 'tags' => $facts['tags']) + $t;
			}
		}
		return $out;
	}
	// Adds a native suite to a schedule by reference (section 5): one row, Kind 'suite', Ref native:<SuiteID>. The level needed is the schedule's own (a suite holds no administrator test).
	protected static function suite_add($account_id, $schedule_id, $posted, $actor, $detail)
	{
		$db = phoromatic_server::$db;
		if(!phoromatic_native_suites::ready())
		{
			return self::notice('This server has not been upgraded for native suites yet; nothing was added.', true);
		}
		$suite = ctype_digit($posted) && strlen($posted) <= 9 ? phoromatic_native_suites::get($account_id, (int)$posted) : null;
		if($suite === null)
		{
			return self::notice('No such suite; nothing was added.', true);
		}
		$gate = phoromatic_catalog_sched::gate($account_id, $schedule_id);
		if(!$gate['allowed'])
		{
			return self::notice(self::NEEDS_ADMIN . ' Nothing was added.', true);
		}
		$offer = phoromatic_native_suites::offer($account_id);
		$rows = phoromatic_native_suites::expanded_rows($account_id, $schedule_id);
		foreach($suite['members'] as $m)
		{
			$rows[] = array('Ref' => $m['Ref'], 'Version' => $m['Version'], 'OptionsJSON' => $m['OptionsJSON'], 'Suite' => array('id' => (int)$suite['SuiteID'], 'title' => $suite['Title'], 'revision' => (int)$suite['Revision']));
		}
		if(($pair = phoromatic_native_suites::version_conflict($rows)) !== null)
		{
			return self::notice(phoromatic_native_suites::conflict_text($offer, $pair) . ' Nothing was added.', true);
		}
		if(@$db->exec('BEGIN IMMEDIATE') === false)
		{
			return self::notice('The database is busy; nothing was added.', true);
		}
		$ref = 'native:' . (int)$suite['SuiteID'];
		$pos = self::one('SELECT COALESCE(MAX(Position), -1) + 1 AS p FROM phoromatic_schedules_native_tests WHERE AccountID = :a AND ScheduleID = :s', array(':a' => $account_id, ':s' => $schedule_id));
		$ins = $db->prepare('INSERT OR IGNORE INTO phoromatic_schedules_native_tests (AccountID, ScheduleID, Position, Ref, OptionsJSON, Description, AddedOn, AddedBy, Version, Kind) VALUES (:a, :s, :p, :r, \'{}\', :d, :now, :by, NULL, \'suite\')');
		foreach(array(':a' => $account_id, ':s' => $schedule_id, ':p' => (int)$pos['p'], ':r' => $ref, ':d' => $suite['Title'], ':now' => phoromatic_server::current_time(), ':by' => $actor) as $k => $v)
		{
			$ins->bindValue($k, $v);
		}
		$ok = @$ins->execute() !== false && $db->changes() == 1;
		$ok = $ok && phoromatic_admin_audit::record($account_id, 'user', $actor, 'native_test_add', array('actor_detail' => $detail, 'subject_type' => 'schedule', 'subject_id' => (string)$schedule_id,
			'detail' => array('kind' => 'suite', 'ref' => $ref, 'suite' => $suite['Title'], 'revision' => (int)$suite['Revision'], 'members' => count($suite['members']))));
		// the schedule is an administrator schedule because of its loose rows: any change to it is audited as one (E4.4 6.6)
		if($ok && !empty($gate['tests']) && !self::admin_change_audit($account_id, $schedule_id, 'tests', null, 'suite ' . $suite['Title'], $gate['tests']))
		{
			$ok = false;
		}
		if(!$ok || @$db->exec('COMMIT') === false)
		{
			@$db->exec('ROLLBACK');
			return self::notice('Nothing was added (the suite is already in the schedule, or the audit record could not be written).', true);
		}
		return self::notice('Added suite ' . $suite['Title'] . ' (' . count($suite['members']) . ' tests).');
	}
	public static function schedule_post($account_id, $schedule_id)
	{
		if(!isset($_POST['native_test_add']) && !isset($_POST['native_test_remove']) && !isset($_POST['native_test_version_set']) && !isset($_POST['native_suite_add']))
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
		$posted_version = isset($_POST['native_test_version']) && is_string($_POST['native_test_version']) ? $_POST['native_test_version'] : null;
		if(isset($_POST['native_suite_add']))
		{
			return self::suite_add($account_id, $schedule_id, is_string($_POST['native_suite_add']) ? $_POST['native_suite_add'] : '', $actor, $detail);
		}
		if(isset($_POST['native_test_add']))
		{
			$ref = is_string($_POST['native_test_add']) ? $_POST['native_test_add'] : '';
			$cat = self::schedule_offer($account_id, $schedule_id);
			if(!isset($cat[$ref]))
			{
				return self::notice('That test is not one this server\'s catalog holds, and none of the systems this schedule runs on has reported it; nothing was added.', true);
			}
			list($fine, $version) = self::posted_version($account_id, $schedule_id, $ref, $posted_version);
			if(!$fine)
			{
				return self::notice($version, true);
			}
			// A test only the catalog holds (no system has reported it yet) has the options its manifest declares at the version chosen (newest when not pinned)
			if(!empty($cat[$ref]['catalog_only']))
			{
				$offer = phoromatic_native_suites::offer($account_id);
				$cat[$ref]['options'] = phoromatic_native_suites::member_options($offer, $ref, $version) ?: array();
			}
			$options = self::checked_options($cat[$ref], isset($_POST['opt']) && is_array($_POST['opt']) ? $_POST['opt'] : array());
			if(is_string($options))
			{
				return self::notice($options, true);
			}
			// A test that needs administrator rights is added by an account administrator only (6.6); every handler checks again, so a forged form changes nothing
			$admin_test = phoromatic_catalog::ready() && phoromatic_catalog_sched::is_admin_ref($account_id, $ref);
			if($admin_test && !phoromatic_catalog_sched::level_one())
			{
				return self::notice(self::NEEDS_ADMIN . ' Nothing was added.', true);
			}
			// the same test with the same options at another version, already in the schedule through a suite, is refused (one version of a test setting in a schedule)
			if(phoromatic_native_suites::ready())
			{
				$rows = phoromatic_native_suites::expanded_rows($account_id, $schedule_id);
				$rows[] = array('Ref' => $ref, 'Version' => $version, 'OptionsJSON' => json_encode((object)$options, JSON_UNESCAPED_SLASHES), 'Suite' => null);
				if(($pair = phoromatic_native_suites::version_conflict($rows)) !== null)
				{
					return self::notice(phoromatic_native_suites::conflict_text(phoromatic_native_suites::offer($account_id), $pair) . ' Nothing was added.', true);
				}
			}
			$json = json_encode((object)$options, JSON_UNESCAPED_SLASHES);
			if(@$db->exec('BEGIN IMMEDIATE') === false)
			{
				return self::notice('The database is busy; nothing was added.', true);
			}
			$pos = self::one('SELECT COALESCE(MAX(Position), -1) + 1 AS p FROM phoromatic_schedules_native_tests WHERE AccountID = :a AND ScheduleID = :s', array(':a' => $account_id, ':s' => $schedule_id));
			$have_version = phoromatic_catalog::ready();
			$ins = $db->prepare('INSERT OR IGNORE INTO phoromatic_schedules_native_tests (AccountID, ScheduleID, Position, Ref, OptionsJSON, Description, AddedOn, AddedBy' . ($have_version ? ', Version' : '') . ') VALUES (:a, :s, :p, :r, :o, :d, :now, :by' . ($have_version ? ', :v' : '') . ')');
			$binds = array(':a' => $account_id, ':s' => $schedule_id, ':p' => (int)$pos['p'], ':r' => $ref, ':o' => $json, ':d' => $cat[$ref]['title'], ':now' => phoromatic_server::current_time(), ':by' => $actor);
			if($have_version)
			{
				$binds[':v'] = $version;
			}
			foreach($binds as $k => $v)
			{
				$ins->bindValue($k, $v);
			}
			$ok = @$ins->execute() !== false && $db->changes() == 1;
			$entry_version = $version !== null ? $version : $cat[$ref]['version'];
			$admin = $have_version ? phoromatic_catalog_sched::admin_detail($account_id, $ref, $entry_version) : null;
			$audit = array('ref' => $ref, 'options' => $options);
			if($have_version)
			{
				$audit['version'] = $version;
			}
			if($admin !== null)
			{
				$audit['admin'] = $admin;
			}
			if($ok && !phoromatic_admin_audit::record($account_id, 'user', $actor, 'native_test_add', array('actor_detail' => $detail, 'subject_type' => 'schedule', 'subject_id' => (string)$schedule_id, 'detail' => $audit)))
			{
				$ok = false;
			}
			if($ok && $admin_test && !self::admin_change_audit($account_id, $schedule_id, 'tests', null, $ref . ($version !== null ? '@' . $version : ''), array($ref)))
			{
				$ok = false;
			}
			if(!$ok || @$db->exec('COMMIT') === false)
			{
				@$db->exec('ROLLBACK');
				return self::notice($ok ? 'The test could not be saved.' : 'Nothing was added (it is already in the schedule with those options, or the audit record could not be written).', true);
			}
			return self::notice('Added ' . $cat[$ref]['title'] . ($version !== null ? ' pinned to ' . $version : '') . '.');
		}
		$ref = isset($_POST['native_test_ref']) && is_string($_POST['native_test_ref']) ? $_POST['native_test_ref'] : '';
		$options = isset($_POST['native_test_remove']) ? (is_string($_POST['native_test_remove']) ? $_POST['native_test_remove'] : '') : (isset($_POST['native_test_options']) && is_string($_POST['native_test_options']) ? $_POST['native_test_options'] : '');
		if(isset($_POST['native_test_version_set']))
		{
			return self::version_set($account_id, $schedule_id, $ref, $options, $posted_version, $actor, $detail);
		}
		if(@$db->exec('BEGIN IMMEDIATE') === false)
		{
			return self::notice('The database is busy; nothing was removed.', true);
		}
		$admin_test = phoromatic_catalog::ready() && phoromatic_catalog_sched::is_admin_ref($account_id, $ref);
		// a suite row (native:<SuiteID>) is audited with the suite's title and revision; a schedule that is an administrator schedule because of its loose rows is audited as one
		$suite_row = phoromatic_native_suites::ready() && preg_match('/^native:([0-9]{1,9})\z/', $ref, $sm) === 1 ? phoromatic_native_suites::get($account_id, (int)$sm[1]) : null;
		$admin_schedule = $suite_row !== null ? phoromatic_catalog_sched::admin_tests($account_id, $schedule_id) : array();
		$del = $db->prepare('DELETE FROM phoromatic_schedules_native_tests WHERE AccountID = :a AND ScheduleID = :s AND Ref = :r AND OptionsJSON = :o');
		foreach(array(':a' => $account_id, ':s' => $schedule_id, ':r' => $ref, ':o' => $options) as $k => $v)
		{
			$del->bindValue($k, $v);
		}
		$ok = @$del->execute() !== false && $db->changes() == 1 && phoromatic_admin_audit::record($account_id, 'user', $actor, 'native_test_remove', array('actor_detail' => $detail, 'subject_type' => 'schedule',
			'subject_id' => (string)$schedule_id, 'detail' => array('ref' => $ref, 'options' => json_decode($options, true)) + ($suite_row !== null ? array('kind' => 'suite', 'suite' => $suite_row['Title'], 'revision' => (int)$suite_row['Revision']) : array())));
		if($ok && !empty($admin_schedule) && !self::admin_change_audit($account_id, $schedule_id, 'tests', 'suite ' . $suite_row['Title'], null, $admin_schedule))
		{
			$ok = false;
		}
		// Taking an administrator test out is allowed at level 2 (it only narrows what runs), and is audited the same way
		if($ok && $admin_test && !self::admin_change_audit($account_id, $schedule_id, 'tests', $ref, null, array($ref)))
		{
			$ok = false;
		}
		if(!$ok || @$db->exec('COMMIT') === false)
		{
			@$db->exec('ROLLBACK');
			return self::notice('Nothing was removed.', true);
		}
		return self::notice('Removed.');
	}
	// "Pin to" and changing the version of a test that is already in the schedule (6.2); null is not pinned
	protected static function version_set($account_id, $schedule_id, $ref, $options, $posted, $actor, $detail)
	{
		$db = phoromatic_server::$db;
		if(!phoromatic_catalog::ready())
		{
			return self::notice('This server has not been upgraded for version pins yet; nothing was changed.', true);
		}
		if($posted === null || $posted === 'default')
		{
			return self::notice('Pick a version; nothing was changed.', true);
		}
		list($fine, $version) = self::posted_version($account_id, $schedule_id, $ref, $posted);
		if(!$fine)
		{
			return self::notice($version, true);
		}
		$admin_test = phoromatic_catalog_sched::is_admin_ref($account_id, $ref);
		if($admin_test && !phoromatic_catalog_sched::level_one())
		{
			return self::notice(self::NEEDS_ADMIN . ' Nothing was changed.', true);
		}
		// the new version must not clash with the same test setting already in the schedule at another version (through a suite, say)
		if(phoromatic_native_suites::ready())
		{
			// (the expanded list drops a loose row that repeats a suite member exactly, so the row is taken out and put back at its new version)
			$rows = array_values(array_filter(phoromatic_native_suites::expanded_rows($account_id, $schedule_id), function($r) use ($ref, $options) { return $r['Suite'] !== null || $r['Ref'] !== $ref || $r['OptionsJSON'] !== $options; }));
			$rows[] = array('Ref' => $ref, 'Version' => $version, 'OptionsJSON' => $options, 'Suite' => null);
			if(($pair = phoromatic_native_suites::version_conflict($rows)) !== null)
			{
				return self::notice(phoromatic_native_suites::conflict_text(phoromatic_native_suites::offer($account_id), $pair) . ' Nothing was changed.', true);
			}
		}
		if(@$db->exec('BEGIN IMMEDIATE') === false)
		{
			return self::notice('The database is busy; nothing was changed.', true);
		}
		$row = self::one('SELECT Version FROM phoromatic_schedules_native_tests WHERE AccountID = :a AND ScheduleID = :s AND Ref = :r AND OptionsJSON = :o', array(':a' => $account_id, ':s' => $schedule_id, ':r' => $ref, ':o' => $options));
		if(!$row || $row['Version'] === $version)
		{
			@$db->exec('ROLLBACK');
			return self::notice($row ? 'It is already set to that; nothing was changed.' : 'That test is not in the schedule any more; nothing was changed.', true);
		}
		$upd = $db->prepare('UPDATE phoromatic_schedules_native_tests SET Version = :v WHERE AccountID = :a AND ScheduleID = :s AND Ref = :r AND OptionsJSON = :o');
		foreach(array(':v' => $version, ':a' => $account_id, ':s' => $schedule_id, ':r' => $ref, ':o' => $options) as $k => $v)
		{
			$upd->bindValue($k, $v);
		}
		$ok = @$upd->execute() !== false && $db->changes() == 1 && phoromatic_admin_audit::record($account_id, 'user', $actor, 'native_test_version_set', array('actor_detail' => $detail, 'subject_type' => 'schedule',
			'subject_id' => (string)$schedule_id, 'detail' => array('ref' => $ref, 'options' => json_decode($options, true), 'from' => $row['Version'], 'to' => $version)));
		if($ok && $admin_test && !self::admin_change_audit($account_id, $schedule_id, 'version', $ref . ($row['Version'] !== null ? '@' . $row['Version'] : ''), $ref . ($version !== null ? '@' . $version : ''), array($ref)))
		{
			$ok = false;
		}
		if(!$ok || @$db->exec('COMMIT') === false)
		{
			@$db->exec('ROLLBACK');
			return self::notice('Nothing was changed.', true);
		}
		return self::notice($version === null ? 'Not pinned any more: each system runs its own newest version.' : 'Pinned to ' . $version . '.');
	}
	// The version choice of an Add form or a row's menu: the options of a select (6.2). $current is the row's pin (null on an Add form).
	protected static function version_options($pick, $current, $adding)
	{
		$html = '';
		if($adding)
		{
			$html .= '<option value="default" selected="selected">' . ($pick['default'] !== null ? self::h($pick['default']) . ' (what every system has now)' : 'Not pinned') . '</option>';
			if($pick['default'] !== null)
			{
				$html .= '<option value="none">Not pinned (each system runs its own newest)</option>';
			}
		}
		else
		{
			$html .= '<option value="none"' . ($current === null ? ' selected="selected"' : '') . '>Not pinned</option>';
		}
		foreach($pick['choices'] as $v)
		{
			if($adding && $v === $pick['default'])
			{
				continue;
			}
			$html .= '<option value="' . self::h($v) . '"' . (!$adding && $v === $current ? ' selected="selected"' : '') . '>' . self::h($v) . '</option>';
		}
		if(!$adding && $current !== null && !in_array($current, $pick['choices'], true))
		{
			$html .= '<option value="' . self::h($current) . '" selected="selected" disabled="disabled">' . self::h($current) . ' (pinned now)</option>';
		}
		return $html;
	}
	// A suite held by a schedule (section 5): one row, expandable to its members with their versions, labels and what each target says
	protected static function suite_row_html($account_id, $schedule_id, $r, $offer, $targets, $can_edit)
	{
		$suite = preg_match('/^native:([0-9]{1,9})\z/', $r['Ref'], $m) === 1 && phoromatic_native_suites::ready() ? phoromatic_native_suites::get($account_id, (int)$m[1]) : null;
		if($suite === null)
		{
			return '<p><strong>Suite</strong> <code>' . self::h($r['Ref']) . '</code> - <span style="color: red;">this suite no longer exists, so it runs nothing.</span>' .
				($can_edit ? ' ' . self::form('<input type="hidden" name="native_test_ref" value="' . self::h($r['Ref']) . '" /><input type="hidden" name="native_test_remove" value="' . self::h($r['OptionsJSON']) . '" /><input type="submit" value="Remove" />', 'schedules/' . (int)$schedule_id) : '') . '</p>';
		}
		$platforms = phoromatic_native_suites::suite_platforms($offer, array_map(function($x) { return array('ref' => $x['Ref'], 'version' => $x['Version']); }, $suite['members']));
		$html = '<details><summary><strong>Suite: ' . self::h($suite['Title']) . '</strong>, revision ' . (int)$suite['Revision'] . ', ' . count($suite['members']) . ' test' . (count($suite['members']) == 1 ? '' : 's') .
			(empty($platforms) ? '' : ' (' . self::h(phoromatic_native_suites::platform_text($platforms)) . ')') . '</summary>';
		foreach($suite['members'] as $mem)
		{
			$options = phoromatic_native_suites::options_text($offer, $mem['Ref'], $mem['Version'], $mem['OptionsJSON']);
			$html .= '<p>' . self::h(phoromatic_native_suites::title_of($offer, $mem['Ref'])) . ' <code>' . self::h($mem['Ref']) . '</code>' . ($options !== '' ? ' - ' . self::h($options) : '') . '<br />' .
				($mem['Version'] !== null ? 'Pinned to <strong>' . self::h($mem['Version']) . '</strong>' : 'Not pinned');
			if(phoromatic_catalog::ready())
			{
				$html .= self::label_html(phoromatic_catalog_sched::labels($account_id, $mem['Ref'], $mem['Version']), $targets, $account_id, $mem['Ref'], $mem['Version']);
				if($mem['Version'] !== null && ($why = phoromatic_catalog_sched::withdrawn($mem['Ref'], $mem['Version'])) !== null)
				{
					$html .= '<br /><span style="color: red;">' . self::h($mem['Ref'] . ' ' . $mem['Version']) . ' was withdrawn: ' . self::h($why) . '. Pick another version in the suite.</span>';
				}
				else if($mem['Version'] !== null && phoromatic_catalog_sched::entry($account_id, $mem['Ref'], $mem['Version']) === null)
				{
					$html .= '<br /><span style="color: red;">' . self::h($mem['Ref'] . ' ' . $mem['Version']) . ' is no longer in this server\'s catalog. Pick another version in the suite.</span>';
				}
			}
			$html .= '</p>';
		}
		$html .= '</details><p><em>Edit the suite on its own page' . ($can_edit ? ' (<a href="?build_suite/native/' . (int)$suite['SuiteID'] . '">edit ' . self::h($suite['Title']) . '</a>)' : '') . '; changes apply to every schedule that uses it.</em>';
		if($can_edit)
		{
			$html .= ' ' . self::form('<input type="hidden" name="native_test_ref" value="' . self::h($r['Ref']) . '" /><input type="hidden" name="native_test_remove" value="' . self::h($r['OptionsJSON']) . '" /><input type="submit" value="Remove suite" />', 'schedules/' . (int)$schedule_id);
		}
		return $html . '</p>';
	}
	// "Add a native suite": a menu of the account's native suites with the number of tests and the platforms
	protected static function suite_add_form($account_id, $schedule_id, $offer)
	{
		$suites = phoromatic_native_suites::all($account_id);
		if(empty($suites))
		{
			return '';
		}
		$options = '';
		foreach($suites as $s)
		{
			$members = phoromatic_native_suites::members($account_id, (int)$s['SuiteID']);
			$platforms = phoromatic_native_suites::suite_platforms($offer, array_map(function($x) { return array('ref' => $x['Ref'], 'version' => $x['Version']); }, $members));
			$options .= '<option value="' . (int)$s['SuiteID'] . '">' . self::h($s['Title']) . ' (' . (int)$s['MemberCount'] . ' test' . ((int)$s['MemberCount'] == 1 ? '' : 's') . (empty($platforms) ? '' : ', ' . phoromatic_native_suites::platform_text($platforms)) . ')</option>';
		}
		return '<h3>Add A Native Suite</h3>' . self::form('<select name="native_suite_add">' . $options . '</select> <input type="submit" value="Add" />', 'schedules/' . (int)$schedule_id);
	}
	public static function schedule_box($account_id, $schedule_id, $can_edit)
	{
		$html = '<hr /><h2>Native Tests</h2><p>These run on systems that use the native engine; the tests above run on PTS systems. A schedule can have both.</p>';
		$rows = self::all('SELECT * FROM phoromatic_schedules_native_tests WHERE AccountID = :a AND ScheduleID = :s ORDER BY Position, Ref', array(':a' => $account_id, ':s' => (int)$schedule_id));
		$cat = self::catalogue($account_id);
		$ready = phoromatic_catalog::ready();
		$targets = $ready ? phoromatic_catalog_sched::targets($account_id, $schedule_id) : array();
		$level_one = $ready && phoromatic_catalog_sched::level_one();
		if(empty($rows))
		{
			$html .= '<p><em>No native tests yet.</em></p>';
		}
		$suites_offer = $ready && phoromatic_native_suites::ready() ? phoromatic_native_suites::offer($account_id) : array('catalog' => array(), 'reported' => array());
		foreach($rows as $r)
		{
			if(isset($r['Kind']) && $r['Kind'] === 'suite')
			{
				$html .= self::suite_row_html($account_id, $schedule_id, $r, $suites_offer, $targets, $can_edit);
				continue;
			}
			$options = json_decode($r['OptionsJSON'], true);
			$text = array();
			foreach(is_array($options) ? $options : array() as $k => $v)
			{
				$text[] = self::h($k) . ' = ' . self::h($v);
			}
			$html .= '<p>' . self::h(isset($cat[$r['Ref']]) ? $cat[$r['Ref']]['title'] : $r['Ref']) . ' <code>' . self::h($r['Ref']) . '</code>' . (empty($text) ? '' : ' - ' . implode(', ', $text));
			$admin_row = false;
			if($ready)
			{
				$pin = isset($r['Version']) ? $r['Version'] : null;
				$pick = phoromatic_catalog_sched::pick($account_id, $targets, $r['Ref']);
				$shown = $pin !== null ? $pin : (isset($cat[$r['Ref']]) && count(array_unique(array_values($pick['reported']))) == 1 ? $cat[$r['Ref']]['version'] : null);
				$html .= '<br />' . ($pin !== null ? 'Pinned to <strong>' . self::h($pin) . '</strong>' : 'Not pinned');
				$admin_row = phoromatic_catalog_sched::is_admin_ref($account_id, $r['Ref']);
				$html .= self::label_html(phoromatic_catalog_sched::labels($account_id, $r['Ref'], $shown), $targets, $account_id, $r['Ref'], $pin);
				if($pin !== null && ($why = phoromatic_catalog_sched::withdrawn($r['Ref'], $pin)) !== null)
				{
					$html .= '<br /><span style="color: red;">' . self::h($r['Ref'] . ' ' . $pin) . ' was withdrawn: ' . self::h($why) . '. Pick another version.</span>';
				}
				if($pin === null && $pick['default'] !== null && $can_edit)
				{
					$html .= ' ' . self::form('<input type="hidden" name="native_test_ref" value="' . self::h($r['Ref']) . '" /><input type="hidden" name="native_test_options" value="' . self::h($r['OptionsJSON']) . '" /><input type="hidden" name="native_test_version" value="' . self::h($pick['default']) . '" /><input type="hidden" name="native_test_version_set" value="1" /><input type="submit" value="Pin to ' . self::h($pick['default']) . '"' . ($admin_row && !$level_one ? ' disabled="disabled"' : '') . ' />', 'schedules/' . (int)$schedule_id);
				}
				else if($pin !== null && $can_edit)
				{
					$html .= ' ' . self::form('<input type="hidden" name="native_test_ref" value="' . self::h($r['Ref']) . '" /><input type="hidden" name="native_test_options" value="' . self::h($r['OptionsJSON']) . '" /><input type="hidden" name="native_test_version_set" value="1" /><select name="native_test_version">' .
						self::version_options($pick, $pin, false) . '</select> <input type="submit" value="Change version"' . ($admin_row && !$level_one ? ' disabled="disabled"' : '') . ' />', 'schedules/' . (int)$schedule_id);
				}
				if($pin === null && $pick['say'] !== '')
				{
					$html .= '<br /><em>' . self::h($pick['say']) . '</em>';
				}
			}
			if($can_edit)
			{
				$html .= ' ' . self::form('<input type="hidden" name="native_test_ref" value="' . self::h($r['Ref']) . '" /><input type="hidden" name="native_test_remove" value="' . self::h($r['OptionsJSON']) . '" /><input type="submit" value="Remove" />', 'schedules/' . (int)$schedule_id);
			}
			if($admin_row && $can_edit && !$level_one)
			{
				$html .= '<br /><em>' . self::h(self::NEEDS_ADMIN) . '</em>';
			}
			$html .= '</p>';
		}
		if(!$can_edit)
		{
			return $html;
		}
		$offer = self::schedule_offer($account_id, $schedule_id);
		$html .= self::suite_add_form($account_id, $schedule_id, $suites_offer);
		if(empty($offer))
		{
			return $html . '<p>' . (empty($cat) ? 'Enroll a native agent first; its tests appear here.' : 'None of the systems this schedule runs on has reported any tests yet. Pick its systems or groups, and enroll a native agent on them; their tests appear here.') . '</p>';
		}
		$html .= '<h3>Add A Native Test</h3>';
		$count = count(phoromatic_server::systems_associated_with_schedule($account_id, (int)$schedule_id));
		// by category in the fixed order, then Other; each group open, each row saying where the test comes from and its tags (categories.md 8)
		$groups = array('free' => 'Free', 'nonfree' => 'Non-free', 'reported' => 'Reported by your systems');
		$by_cat = array();
		foreach($offer as $ref => $t)
		{
			$by_cat[isset(phoromatic_native_taxonomy::$categories[$t['cat']]) ? $t['cat'] : phoromatic_native_taxonomy::OTHER][$ref] = $t;
		}
		$cat_order = array_merge(array_keys(phoromatic_native_taxonomy::$categories), array(phoromatic_native_taxonomy::OTHER));
		foreach($cat_order as $cat_key)
		{
		if(!isset($by_cat[$cat_key]))
		{
			continue;
		}
		$html .= '<details open="open"><summary><strong>' . self::h(phoromatic_native_taxonomy::category_label($cat_key)) . '</strong> (' . count($by_cat[$cat_key]) . ')</summary>';
		foreach($by_cat[$cat_key] as $ref => $t)
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
			$extra = '';
			$blocked = false;
			if($ready)
			{
				$pick = phoromatic_catalog_sched::pick($account_id, $targets, $ref);
				if(!empty($pick['choices']))
				{
					$fields .= '<p>Version: <select name="native_test_version">' . self::version_options($pick, null, true) . '</select></p>';
				}
				if($pick['say'] !== '')
				{
					$fields .= '<p><em>' . self::h($pick['say']) . '</em></p>';
				}
				$shown = $pick['default'];
				if($shown === null && count(array_unique(array_values($pick['reported']))) == 1)
				{
					$shown = $t['version'];
				}
				$extra = self::label_html(phoromatic_catalog_sched::labels($account_id, $ref, $shown), $targets, $account_id, $ref, null);
				$blocked = phoromatic_catalog_sched::is_admin_ref($account_id, $ref) && !$level_one;
				if($blocked)
				{
					$fields .= '<p><em>' . self::h(self::NEEDS_ADMIN) . '</em></p>';
				}
			}
			// with several targets, say which of them can run the test
			$where = $count > 1 && !empty($t['systems']) ? ' <em>(' . (count($t['systems']) == $count ? 'all ' . $count . ' systems' : self::h(implode(', ', $t['systems']))) . ')</em>' : '';
			$about = $groups[$t['group']] . (empty($t['tags']) ? '' : ', ' . phoromatic_native_taxonomy::tag_text($t['tags']));
			$html .= '<details><summary>' . self::h($t['title']) . ' <code>' . self::h($ref) . '</code> <em>(' . self::h($about) . ')</em>' . $where . '</summary>' . $extra .
				self::form($fields . '<input type="hidden" name="native_test_add" value="' . self::h($ref) . '" /><input type="submit" value="Add"' . ($blocked ? ' disabled="disabled"' : '') . ' />', 'schedules/' . (int)$schedule_id) . '</details>';
		}
		$html .= '</details>';
		}
		return $html;
	}
	// The labels of 6.3 for a test: tier, what it installs, "Runs as administrator", and what each target says about it
	protected static function label_html($l, $targets, $account_id, $ref, $pin)
	{
		if(!$l['known'])
		{
			return '<br /><em>Not in this server\'s catalog.</em>';
		}
		$html = '<br /><strong>' . self::h($l['tier']) . '</strong>';
		if($l['install'] !== null)
		{
			$html .= ' - Installs ' . self::h($l['install']) . ' for all users';
		}
		if($l['elevated'])
		{
			$html .= ' - Runs as administrator';
		}
		$lines = ($l['install'] !== null || $l['elevated']) ? phoromatic_catalog_sched::status_lines($account_id, $targets, $ref, $pin) : array();
		if(!empty($lines))
		{
			$html .= '<ul><li>' . implode('</li><li>', array_map(array(__CLASS__, 'h'), $lines)) . '</li></ul>';
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
		if(!phoromatic_auth::is_server_admin())
		{
			return self::notice('Only a server administrator can change these settings; nothing was changed.', true);
		}
		if(!self::posted_ok())
		{
			return self::notice('The form expired or was not submitted from this site; nothing was changed. Please try again.', true);
		}
		list($actor_account, $actor_type, $actor, $detail) = phoromatic_auth::audit_actor();
		// All or nothing: both settings are saved in one step, so a refused list, or a write that fails, leaves the plain-HTTP switch as it was too
		$mirrors = array_filter(array_map('trim', preg_split('/\R/', isset($_POST['v2_mirrors']) && is_string($_POST['v2_mirrors']) ? $_POST['v2_mirrors'] : '')), 'strlen');
		if(($e = phoromatic_server::set_v2_settings(isset($_POST['v2_allow_http']) ? 'on' : 'off', array_values($mirrors), $actor_account, $actor_type, $actor, $detail)) !== null)
		{
			return self::notice($e, true);
		}
		return self::notice('Saved.');
	}
	public static function settings_section($page = 'server_settings')
	{
		if(!phoromatic_auth::is_server_admin())
		{
			return '';
		}
		// The post comes first: the form has to show what is saved now, not what was saved before this request
		$notice = self::settings_post();
		$mirrors = phoromatic_server::read_setting('v2_mirrors');
		$allow = phoromatic_api_v2::allow_http();
		return $notice . self::form('<input type="hidden" name="v2_settings" value="1" />
<hr /><h2>Native agent connections</h2>
<p>Native agents talk to <code>/api/v2</code> and send a bearer token with every call, so the API insists on HTTPS.</p>' .
			($allow ? '<p style="color: red;"><strong>Plain HTTP is allowed for the agent API. Agents send their token in the clear. Use this for a test rig only.</strong></p>' : '') .
			'<p><label><input type="checkbox" name="v2_allow_http" value="1"' . ($allow ? ' checked="checked"' : '') . ' /> Allow plain HTTP (test rigs only; agents\' tokens travel unencrypted)</label></p>
<p>Download mirrors offered to agents, one https:// address per line (this server\'s own host name only; an agent never uses others).</p>
<p><textarea name="v2_mirrors" rows="3" style="width: 60%;">' . self::h(implode("\n", is_array($mirrors) ? $mirrors : array())) . '</textarea></p>
<p><input type="submit" value="Save" /></p>', $page);
	}
}

?>
