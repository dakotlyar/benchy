<?php

/*
	Benchy
	phoromatic_api_v2.php: the server side of the native agent protocol (v2, /api/v2). All v2 logic lives here; the front controller
	(public_html/api/v2/index.php) only loads PTS and calls run().

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

// The contract is static/protocol-v2/ (README.md and the schemas, pinned from the engine repository). Bodies are checked against those
// schemas; what a schema cannot say is checked by hand where it is used. The token says which account and which system a call is for.

class phoromatic_api_v2
{
	const POLL_INTERVAL = 60;
	const MAX_JSON = 1048576;
	const MAX_RESULT = 16777216;
	const MAX_EVENTS_FILE = 4194304;
	const MAX_CATALOGUE = 5000;

	// ----- the request

	public static function run()
	{
		ini_set('display_errors', '0');
		ini_set('log_errors', '1');
		set_error_handler(function($no, $str, $file, $line) {
			if(!(error_reporting() & $no))
			{
				return true; // written with @: the code checks the result itself
			}
			error_log('api v2: ' . $str . ' in ' . $file . ':' . $line);
			return true;
		});
		set_exception_handler(function($e) {
			error_log('api v2: uncaught ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
			if(headers_sent())
			{
				return;
			}
			// An agent retries a 5xx for ever, so a crash that the same request would hit again is not one: a result it could not process
			// is a refused result (422), an end it could not record is a refused end (409). That holds only while the request is being
			// checked against what the agent sent ($checking); a crash before that (the database would not open, the disk is full) says
			// nothing about the agent's data, and a 422 would make it throw the result away. Those, and everything else, are a 500.
			if(self::$checking && self::$route === 'job_result')
			{
				self::reply(422, self::envelope(array('error' => array('code' => 'result_invalid', 'message' => 'The server could not process this result.'))));
			}
			if(self::$checking && self::$route === 'job_end')
			{
				self::reply(409, self::envelope(array('error' => array('code' => 'job_ended', 'message' => 'The server could not record this end.'))));
			}
			self::reply(500, self::envelope(array('error' => array('code' => 'server_error', 'message' => 'The server hit an error handling that request.'))));
		});
		// Running out of memory is a fatal error, which the handlers above never see. On /result it is the result that is too big to
		// handle (the same upload would do it again), so say so rather than let the agent read a bare 500 and retry for ever. The
		// reserve is let go first, to leave room for the reply.
		$reserve = str_repeat('x', 65536);
		register_shutdown_function(function() use (&$reserve) {
			$reserve = null;
			$err = error_get_last();
			if(self::$route === 'job_result' && $err !== null && $err['type'] === E_ERROR && strpos($err['message'], 'Allowed memory size') === 0 && !headers_sent())
			{
				// PHP has already put a 500 status line on the reply; a bare status code does not replace that
				header('HTTP/1.1 422 Unprocessable Entity', true, 422);
				self::reply(422, self::envelope(array('error' => array('code' => 'result_invalid', 'message' => 'The result is too large for this server to process.'))));
			}
		});
		$path = rtrim((string)parse_url(isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/', PHP_URL_PATH), '/');
		$method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';

		// The scheme rule comes before anything else, so a token never has to be read from a plain-HTTP request
		$https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
		if(!$https && !self::allow_http())
		{
			self::fail(426, 'https_required', 'The agent API needs HTTPS. Use the server\'s https:// address.');
		}
		$route = null;
		$job_id = null;
		if($path === '/api/v2/enrol')
		{
			$route = 'enrol';
		}
		else if($path === '/api/v2/agent/hello' || $path === '/api/v2/agent/poll')
		{
			$route = substr($path, strlen('/api/v2/agent/'));
		}
		else if(preg_match('#^/api/v2/jobs/([0-9]{1,18})/(events|result|end)\z#', $path, $m))
		{
			$route = 'job_' . $m[2];
			$job_id = (int)$m[1];
		}
		if($route === null)
		{
			self::fail(404, 'not_found', 'No such endpoint.');
		}
		self::$route = $route;
		if($method !== 'POST')
		{
			self::fail(400, 'bad_request', 'These endpoints take POST.', array('Allow' => 'POST'));
		}
		phoromatic_server::prepare_database();

		if($route === 'enrol')
		{
			$body = self::json_body(self::MAX_JSON);
			self::check_body($body, 'enrol_request');
			$r = phoromatic_agent_auth::enrol($body);
			if(isset($r['error']))
			{
				self::fail_from($r['error']);
			}
			self::reply($r['status'], self::envelope($r['body']));
		}

		$auth = phoromatic_agent_auth::authenticate();
		if(isset($auth['error']))
		{
			self::fail_from($auth['error']);
		}
		$ctx = array('account' => $auth['token']['AccountID'], 'system' => $auth['system'], 'token' => $auth['token']);
		if($route !== 'hello' && (int)$ctx['system']['State'] != 1)
		{
			self::fail(403, 'system_not_approved', 'This system is not approved. An administrator has to approve it on the Systems page.');
		}
		switch($route)
		{
			case 'hello':
				self::hello($ctx);
			case 'poll':
				self::poll($ctx);
		}
		$job = self::job_for($ctx, $job_id);
		self::$checking = true;
		switch($route)
		{
			case 'job_events':
				self::events($ctx, $job);
			case 'job_result':
				self::result($ctx, $job);
			case 'job_end':
				self::end($ctx, $job);
		}
	}
	public static function allow_http()
	{
		phoromatic_server::init_settings_store();
		return phoromatic_server::read_setting('v2_allow_http') === 'on';
	}
	protected static function envelope($body)
	{
		return array_merge(array('api' => 2, 'server' => defined('PTS_VERSION') ? PTS_VERSION : ''), $body);
	}
	// Sends the reply and ends the request; $after (optional) runs once the client has it
	protected static function reply($status, $body, $headers = array(), $after = null)
	{
		http_response_code($status);
		header('Content-Type: application/json');
		header('Cache-Control: no-store');
		foreach($headers as $k => $v)
		{
			header($k . ': ' . $v);
		}
		echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION);
		if($after !== null)
		{
			ignore_user_abort(true);
			while(ob_get_level() > 0)
			{
				ob_end_flush();
			}
			flush();
			if(function_exists('fastcgi_finish_request'))
			{
				fastcgi_finish_request();
			}
			$after();
		}
		exit;
	}
	protected static function fail($status, $code, $message, $headers = array())
	{
		self::reply($status, self::envelope(array('error' => array('code' => $code, 'message' => $message))), $headers);
	}
	protected static function fail_from($e)
	{
		self::fail($e[0], $e[1], $e[2], isset($e[3]) ? $e[3] : array());
	}
	// The decoded body twice: as an array for the handlers, and as objects (self::$body_object) for the schema checks, where {} and [] must
	// stay different things (json_decode(..., true) turns both into array())
	protected static $body_object = null;
	protected static $route = null;
	// True while a /result or /end is being checked against what the agent sent, after the job was found (see the exception handler)
	protected static $checking = false;
	protected static function json_body($max)
	{
		if(isset($_SERVER['CONTENT_LENGTH']) && (int)$_SERVER['CONTENT_LENGTH'] > $max)
		{
			self::fail(413, 'too_large', 'The request body is larger than ' . $max . ' bytes.');
		}
		$raw = file_get_contents('php://input', false, null, 0, $max + 1);
		if($raw === false || strlen($raw) > $max)
		{
			self::fail(413, 'too_large', 'The request body is larger than ' . $max . ' bytes.');
		}
		$object = json_decode($raw, false, 64);
		if(!($object instanceof stdClass))
		{
			self::fail(400, 'bad_request', 'The body must be one JSON object.');
		}
		self::$body_object = $object;
		return json_decode($raw, true, 64);
	}
	protected static function check_body($body, $def)
	{
		$errors = phoromatic_json_schema::validate(self::$body_object !== null ? self::$body_object : $body, 'v2.schema.json#/$defs/' . $def);
		if(!empty($errors))
		{
			self::fail(400, 'bad_request', 'The body does not match ' . $def . ': ' . implode('; ', $errors));
		}
	}
	protected static function system_path($ctx, $suffix = '')
	{
		$dir = phoromatic_server::phoromatic_account_system_path($ctx['account'], $ctx['system']['SystemID']) . 'jobs/';
		pts_file_io::mkdir($dir);
		return $dir . $suffix;
	}
	protected static function touch_system($ctx, $task = null, $extra = '')
	{
		$stmt = phoromatic_server::$db->prepare('UPDATE phoromatic_systems SET LastCommunication = :now, LastIP = :ip, LastScheme = :scheme, TimeToNextCommunication = :next' . ($task !== null ? ', CurrentTask = :task' : '') . $extra . ' WHERE AccountID = :a AND SystemID = :y');
		$stmt->bindValue(':now', phoromatic_server::current_time());
		$stmt->bindValue(':ip', isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '');
		$stmt->bindValue(':scheme', !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http');
		$stmt->bindValue(':next', self::POLL_INTERVAL);
		if($task !== null)
		{
			$stmt->bindValue(':task', $task);
		}
		$stmt->bindValue(':a', $ctx['account']);
		$stmt->bindValue(':y', $ctx['system']['SystemID']);
		@$stmt->execute();
	}
	protected static function text($value, $max)
	{
		$value = is_scalar($value) ? preg_replace('/[^\x20-\x7e]/', '', (string)$value) : '';
		return trim(substr($value, 0, $max));
	}

	// ----- the clone check

	// The hold text when the machine id differs from the one this token was enrolled with (a cloned VM sharing the token), else null.
	// A new mismatch shows a system warning and is audited once per token and id.
	protected static function clone_hold($ctx, $machine_id)
	{
		$enrolled = $ctx['token']['MachineID'];
		if($enrolled === null || $enrolled === '' || hash_equals((string)$enrolled, (string)$machine_id))
		{
			return null;
		}
		$token = 'AT' . (int)$ctx['token']['TokenID'];
		$seen = phoromatic_server::$db->prepare('SELECT COUNT(*) AS n FROM phoromatic_admin_audit WHERE AccountID = :a AND Action = \'agent_machine_mismatch\' AND SubjectID = :y AND Detail LIKE :t AND Detail LIKE :m');
		$seen->bindValue(':a', $ctx['account']);
		$seen->bindValue(':y', $ctx['system']['SystemID']);
		$seen->bindValue(':t', '%"token":"' . $token . '"%');
		$seen->bindValue(':m', '%"machine_id":"' . substr(preg_replace('/[^0-9a-f]/', '', (string)$machine_id), 0, 64) . '"%');
		$result = $seen->execute();
		$row = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;
		if(!$row || (int)$row['n'] == 0)
		{
			phoromatic_admin_audit::record($ctx['account'], 'agent', $ctx['system']['SystemID'], 'agent_machine_mismatch', array('subject_type' => 'system', 'subject_id' => $ctx['system']['SystemID'],
				'subject_label' => $ctx['system']['Title'], 'detail' => array('token' => $token, 'machine_id' => substr(preg_replace('/[^0-9a-f]/', '', (string)$machine_id), 0, 64), 'enrolled_machine_id' => $enrolled)));
			$w = phoromatic_server::$db->prepare('INSERT INTO phoromatic_system_warnings (AccountID, SystemID, Warning, WarningTime) VALUES (:a, :y, :w, :now)');
			$w->bindValue(':a', $ctx['account']);
			$w->bindValue(':y', $ctx['system']['SystemID']);
			$w->bindValue(':w', 'This agent token is in use on another machine (a cloned VM?). The new machine gets no work until it is enrolled again.');
			$w->bindValue(':now', phoromatic_server::current_time());
			@$w->execute();
		}
		return 'This token is in use on another machine (a cloned VM?). Re-enrol this machine.';
	}

	// ----- hello

	protected static function hello($ctx)
	{
		$body = self::json_body(self::MAX_JSON);
		self::check_body($body, 'hello_request');
		if(count($body['catalogue']) > self::MAX_CATALOGUE)
		{
			self::fail(400, 'bad_request', 'The catalogue lists more than ' . self::MAX_CATALOGUE . ' tests.');
		}
		$db = phoromatic_server::$db;
		$mismatch = self::clone_hold($ctx, $body['machine_id']) !== null;
		if(!$mismatch)
		{
			// The clone's report must not overwrite the original machine's sysinfo or catalogue
			$info = $body['system_info'];
			$stmt = $db->prepare('UPDATE phoromatic_systems SET Hardware = :hw, Software = :sw, SystemProperties = :pp, ClientVersion = :cv WHERE AccountID = :a AND SystemID = :y');
			$stmt->bindValue(':hw', self::text($info['summary']['hardware'], 2000));
			$stmt->bindValue(':sw', self::text($info['summary']['software'], 2000));
			// benchy_agent says the agent has introduced itself (poll asks for a hello until it has); both are arrays of arrays, which is what the
			// system page's properties block can print
			$stmt->bindValue(':pp', json_encode(array('benchy_system_info' => $info, 'benchy_agent' => array('hello_on' => phoromatic_server::current_time(), 'kept_run_dirs' => (int)$body['kept_run_dirs']))));
			$stmt->bindValue(':cv', 'benchy-native ' . self::text($body['engine']['version'], 40));
			$stmt->bindValue(':a', $ctx['account']);
			$stmt->bindValue(':y', $ctx['system']['SystemID']);
			@$stmt->execute();
			if(@$db->exec('BEGIN IMMEDIATE') !== false)
			{
				$ok = true;
				$del = $db->prepare('DELETE FROM phoromatic_system_catalogue WHERE AccountID = :a AND SystemID = :y');
				$del->bindValue(':a', $ctx['account']);
				$del->bindValue(':y', $ctx['system']['SystemID']);
				$ok = @$del->execute() !== false;
				$refused = array();
				foreach($body['catalogue'] as $test)
				{
					$json = json_encode($test, JSON_INVALID_UTF8_SUBSTITUTE);
					if($json === false || strlen($json) > 65535)
					{
						continue;
					}
					// A choice with nothing to choose from can never be given a valid value, so a schedule could never use the test: it is
					// not stored, and the system page says so
					foreach($test['options'] as $option)
					{
						if($option['kind'] === 'choice' && empty($option['choices']))
						{
							$refused[] = $test['test']['ref'];
							continue 2;
						}
					}
					$ins = $db->prepare('INSERT OR IGNORE INTO phoromatic_system_catalogue (AccountID, SystemID, Ref, Version, TestJSON, ReportedOn) VALUES (:a, :y, :r, :v, :j, :now)');
					$ins->bindValue(':a', $ctx['account']);
					$ins->bindValue(':y', $ctx['system']['SystemID']);
					$ins->bindValue(':r', $test['test']['ref']);
					$ins->bindValue(':v', (string)$test['test']['version']);
					$ins->bindValue(':j', $json);
					$ins->bindValue(':now', phoromatic_server::current_time());
					$ok = $ok && @$ins->execute() !== false;
				}
				@$db->exec($ok ? 'COMMIT' : 'ROLLBACK');
				if($ok && !empty($refused))
				{
					self::warn($ctx['account'], $ctx['system']['SystemID'], 'These tests were left out of the catalogue because an option of theirs is a choice with no choices: ' . self::text(implode(', ', array_slice($refused, 0, 10)), 300));
				}
			}
		}
		self::touch_system($ctx);
		self::reply(200, self::envelope(array('system' => array('id' => $ctx['system']['SystemID'], 'title' => (string)$ctx['system']['Title'],
			'policy' => $ctx['system']['EnginePolicy'] === 'native' ? 'native' : 'pts', 'approved' => (int)$ctx['system']['State'] == 1),
			'poll_interval_s' => self::POLL_INTERVAL, 'mirrors' => self::mirrors(), 'auth' => array('bearer'), 'settings' => new stdClass())));
	}
	// Download mirrors the server offers: https only, and only on the server's own host (an agent drops anything else, so the server
	// never offers it). The list is the v2_mirrors server setting.
	public static function mirrors()
	{
		$host = isset($_SERVER['HTTP_HOST']) ? strtolower(preg_replace('/:\d+\z/', '', $_SERVER['HTTP_HOST'])) : '';
		$configured = phoromatic_server::read_setting('v2_mirrors');
		$out = array();
		foreach(is_array($configured) ? $configured : array() as $url)
		{
			if(is_string($url) && preg_match('#^https://([^/@\s;:]+)(:\d+)?(/[^\s;]*)?\z#', $url, $m) === 1 && strtolower($m[1]) === $host)
			{
				$out[] = $url;
			}
		}
		return $out;
	}

	// ----- poll and the job

	protected static function sys_env($sys_row)
	{
		// The system's variables, each as BENCHY_VAR_<NAME>; nothing else (the engine accepts nothing else from a server). Values lose
		// CR, LF and DEL (a line break would split the variable list the old client reads), and the lot is capped at 32 KiB.
		$env = array();
		$total = 0;
		foreach(explode(';', (string)$sys_row['SystemVariables']) as $pair)
		{
			if(($eq = strpos($pair, '=')) === false)
			{
				continue;
			}
			$name = strtoupper(ltrim(trim(substr($pair, 0, $eq)), '.'));
			$value = str_replace(array("\r", "\n", "\x7f"), '', substr($pair, $eq + 1));
			if(preg_match('/^[A-Z0-9_]{1,64}\z/', $name) === 1 && strlen($value) <= 4096 && !preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/', $value) && $total + strlen($name) + strlen($value) <= 32768)
			{
				$env['BENCHY_VAR_' . $name] = $value;
				$total += strlen($name) + strlen($value);
			}
		}
		return array_slice($env, 0, 62, true);
	}
	protected static function poll($ctx)
	{
		$body = self::json_body(self::MAX_JSON);
		self::check_body($body, 'poll_request');
		$sys = $ctx['system'];
		$busy = $body['state'] === 'busy';
		$hold = self::clone_hold($ctx, $body['machine_id']);
		$job = null;
		if($hold === null && $sys['EnginePolicy'] !== 'native')
		{
			$hold = 'This system is set to the PTS engine.';
		}
		else if($hold === null && $busy)
		{
			$hold = 'Busy with a local run.';
		}
		else if($hold === null && (int)$sys['MaintenanceMode'] == 1)
		{
			$hold = 'This system is in maintenance mode.';
		}
		$props = json_decode((string)$sys['SystemProperties'], true);
		$has_info = is_array($props) && isset($props['benchy_agent']) && is_array($props['benchy_agent']) && isset($props['benchy_agent']['hello_on']);
		if($hold === null)
		{
			$job = self::issue($ctx, $body, $hold);
		}
		$task = $busy ? 'Busy with a local run' . ($body['busy_reason'] ? ': ' . self::text($body['busy_reason'], 100) : '') : ($job === null && $body['current_job'] === null ? 'Idle (native agent)' : null);
		if($hold !== null && $job === null && !$busy && $body['current_job'] === null)
		{
			$task = 'Idle: ' . self::text($hold, 100);
		}
		self::touch_system($ctx, $task);
		self::reply(200, self::envelope(array('job' => $job, 'hold' => $job === null ? $hold : null, 'poll_interval_s' => self::POLL_INTERVAL, 'rehello' => !$has_info)));
	}
	// The job to hand out (an array in the shape of job in the schema) or null, with $hold saying why when there is a reason.
	protected static function issue($ctx, $body, &$hold)
	{
		$db = phoromatic_server::$db;
		$account = $ctx['account'];
		$system_id = $ctx['system']['SystemID'];
		$sys = phoromatic_server::$db->querySingle('SELECT * FROM phoromatic_systems WHERE AccountID = \'' . SQLite3::escapeString($account) . '\' AND SystemID = \'' . SQLite3::escapeString($system_id) . '\'', true);
		$issue = null;
		if($sys['BenchID'] !== null)
		{
			// Opens the transaction that stays open until issue_finish(), so a task issue serialises against a swap
			$issue = phoromatic_bench::issue_begin($account, $sys, $sys['SystemProperties'], $sys['Hardware']);
			if(empty($issue['tx']))
			{
				$hold = $issue['hold'];
				return null;
			}
		}
		else if(@$db->exec('BEGIN IMMEDIATE') === false)
		{
			$hold = 'The server is busy; trying again later.';
			return null;
		}
		$dummy = array('phoromatic' => array('system_id' => $system_id, 'server_core_version' => defined('PTS_CORE_VERSION') ? PTS_CORE_VERSION : 0));
		$end = function($task_issued) use ($db, $issue, $account, $system_id, &$dummy, &$hold) {
			// Commits (the bench path through its own function, which also records the task snapshot)
			if($issue !== null)
			{
				$i = $issue;
				phoromatic_bench::issue_finish($account, $system_id, $i, $dummy, $task_issued);
				return isset($dummy['phoromatic']['task']) && $dummy['phoromatic']['task'] === 'idle' ? false : true;
			}
			if(@$db->exec('COMMIT') === false)
			{
				@$db->exec('ROLLBACK');
				return false;
			}
			return true;
		};

		// A job this system still holds is re-sent; one it no longer reports is lost
		$current = $body['current_job'];
		$live = $db->prepare('SELECT * FROM phoromatic_v2_jobs WHERE AccountID = :a AND SystemID = :y AND State IN (\'issued\', \'running\') ORDER BY JobID');
		$live->bindValue(':a', $account);
		$live->bindValue(':y', $system_id);
		$result = $live->execute();
		$rows = array();
		while($result && $row = $result->fetchArray(SQLITE3_ASSOC))
		{
			$rows[] = $row;
		}
		foreach($rows as $row)
		{
			if($current !== null && (int)$current['id'] === (int)$row['JobID'])
			{
				$end(false);
				// decoded as objects, so an empty options or environment object stays one
				return json_decode($row['PayloadJSON']);
			}
			self::end_job($row, 'lost', 'The agent did not report it as its current job.');
			if(self::lost_count($row) >= 2)
			{
				self::warn($account, $system_id, 'A job was lost twice for ' . $row['Title'] . '; its trigger is no longer retried.');
			}
		}
		if($current !== null)
		{
			$hold = 'Waiting for the agent to finish its previous job.';
			$end(false);
			return null;
		}
		if($issue !== null && $issue['hold'] !== null)
		{
			$hold = $issue['hold'];
			$end(false);
			return null;
		}
		$schedule = phoromatic_server::system_check_for_open_schedule_run($account, $system_id, 0, $sys, true, 'native');
		if(!$schedule)
		{
			$end(false);
			return null;
		}
		$trigger = isset($schedule['Trigger']) ? $schedule['Trigger'] : date('Y-m-d');
		$title = $schedule['Title'] . ' - ' . $trigger;
		$bench = null;
		$session_id = null;
		if($issue !== null && $issue['session'])
		{
			$s = $issue['session'];
			$session_id = (int)$s['SessionID'];
			$title .= ' - S' . $session_id;
			$bench = array('session' => $session_id, 'component' => array('label' => $s['PrimaryLabel'], 'class' => $s['Class']), 'bench' => array('id' => (int)$issue['bench']['BenchID'], 'title' => $issue['bench']['Title']));
		}
		$tests = array();
		$t = $db->prepare('SELECT Ref, OptionsJSON FROM phoromatic_schedules_native_tests WHERE AccountID = :a AND ScheduleID = :s ORDER BY Position, Ref');
		$t->bindValue(':a', $account);
		$t->bindValue(':s', (int)$schedule['ScheduleID']);
		$result = $t->execute();
		while($result && $row = $result->fetchArray(SQLITE3_ASSOC))
		{
			$options = json_decode($row['OptionsJSON'], true);
			$tests[] = array('ref' => $row['Ref'], 'format' => 'native', 'options' => is_array($options) && !empty($options) ? $options : new stdClass());
		}
		if(empty($tests))
		{
			$end(false);
			return null;
		}
		$env = self::sys_env($sys);
		if($session_id !== null)
		{
			$env['BENCHY_BENCH_SESSION'] = (string)$session_id;
			$env['BENCHY_BENCH_COMPONENT'] = phoromatic_bench::env_value($issue['session']['Label']);
		}
		$job = array('id' => 0, 'kind' => 'schedule', 'title' => $title, 'identifier' => (string)$trigger, 'description' => trim((string)$schedule['Description']) !== '' ? (string)$schedule['Description'] : 'A Phoromatic run.',
			'schedule' => array('id' => (int)$schedule['ScheduleID'], 'trigger' => (string)$trigger), 'tests' => $tests, 'environment' => empty($env) ? new stdClass() : $env, 'bench' => $bench);
		$ins = $db->prepare('INSERT INTO phoromatic_v2_jobs (AccountID, SystemID, Kind, ScheduleID, Trigger, Title, PayloadJSON, State, BenchSessionID, IssuedOn) VALUES (:a, :y, \'schedule\', :s, :t, :title, \'{}\', \'issued\', :b, :now)');
		foreach(array(':a' => $account, ':y' => $system_id, ':s' => (int)$schedule['ScheduleID'], ':t' => (string)$trigger, ':title' => $title, ':b' => $session_id, ':now' => phoromatic_server::current_time()) as $k => $v)
		{
			$ins->bindValue($k, $v);
		}
		$ok = @$ins->execute() !== false;
		if($ok)
		{
			$job['id'] = (int)$db->lastInsertRowID();
			$upd = $db->prepare('UPDATE phoromatic_v2_jobs SET PayloadJSON = :p WHERE JobID = :id');
			$upd->bindValue(':p', json_encode($job, JSON_UNESCAPED_SLASHES));
			$upd->bindValue(':id', $job['id']);
			$ok = @$upd->execute() !== false;
		}
		if($ok && $issue === null)
		{
			$c = $db->prepare('UPDATE phoromatic_systems SET CurrentTask = :task WHERE AccountID = :a AND SystemID = :y');
			$c->bindValue(':task', 'Issued: ' . self::text($title, 100));
			$c->bindValue(':a', $account);
			$c->bindValue(':y', $system_id);
			@$c->execute();
		}
		if(!$ok)
		{
			@$db->exec('ROLLBACK');
			if($issue !== null)
			{
				$i = $issue;
				phoromatic_bench::issue_finish($account, $system_id, $i, $dummy, false);
			}
			$hold = 'The job could not be recorded on the server; trying again later.';
			return null;
		}
		if(!$end(true))
		{
			$hold = $issue !== null && isset($dummy['phoromatic']['response']) ? $dummy['phoromatic']['response'] : 'The job could not be recorded on the server; trying again later.';
			return null;
		}
		return $job;
	}
	protected static function warn($account, $system_id, $text)
	{
		$w = phoromatic_server::$db->prepare('INSERT INTO phoromatic_system_warnings (AccountID, SystemID, Warning, WarningTime) VALUES (:a, :y, :w, :now)');
		$w->bindValue(':a', $account);
		$w->bindValue(':y', $system_id);
		$w->bindValue(':w', $text);
		$w->bindValue(':now', phoromatic_server::current_time());
		@$w->execute();
	}
	protected static function lost_count($job)
	{
		$stmt = phoromatic_server::$db->prepare('SELECT COUNT(*) AS n FROM phoromatic_v2_jobs WHERE AccountID = :a AND SystemID = :y AND ScheduleID = :s AND Trigger = :t AND State = \'ended\' AND EndStatus = \'lost\' AND BenchSessionID IS :b');
		$stmt->bindValue(':b', $job['BenchSessionID'] === null ? null : (int)$job['BenchSessionID']);
		$stmt->bindValue(':a', $job['AccountID']);
		$stmt->bindValue(':y', $job['SystemID']);
		$stmt->bindValue(':s', (int)$job['ScheduleID']);
		$stmt->bindValue(':t', $job['Trigger']);
		$result = $stmt->execute();
		$row = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;
		return $row ? (int)$row['n'] : 0;
	}
	// Ends a job and lets go of its bench task snapshot (a task that is over blocks no swap)
	protected static function end_job($job, $status, $reason, $run = null)
	{
		$sql = 'UPDATE phoromatic_v2_jobs SET State = \'ended\', EndStatus = :s, EndReason = :r, EndedOn = :now%s WHERE JobID = :id AND State <> \'ended\'';
		$ok = false;
		// A run id that is already another job's must not stop the job ending (the agent would retry the end for ever): end it without
		foreach($run !== null ? array(true, false) : array(false) as $with_run)
		{
			$stmt = phoromatic_server::$db->prepare(sprintf($sql, $with_run ? ', RunID = COALESCE(RunID, :run)' : ''));
			$stmt->bindValue(':s', $status);
			$stmt->bindValue(':r', $reason === null ? null : self::text($reason, 300));
			$stmt->bindValue(':now', phoromatic_server::current_time());
			if($with_run)
			{
				$stmt->bindValue(':run', $run);
			}
			$stmt->bindValue(':id', (int)$job['JobID']);
			$ok = @$stmt->execute() !== false;
			if($ok)
			{
				break;
			}
			if(phoromatic_server::$db->lastErrorCode() == 5)
			{
				// SQLITE_BUSY after the whole busy timeout; trying again without the run id would only wait as long again
				return 'busy';
			}
		}
		if($ok)
		{
			self::release_snapshot($job);
		}
		return $ok;
	}
	// The bench task snapshot of a job that is over is let go, unless a newer job of the system is under way: that one's snapshot is
	// recorded under the same session id, and a late end or upload of the old job must not clear it
	protected static function release_snapshot($job)
	{
		if($job['BenchSessionID'] === null)
		{
			return;
		}
		$stmt = phoromatic_server::$db->prepare('SELECT COUNT(*) AS n FROM phoromatic_v2_jobs WHERE AccountID = :a AND SystemID = :y AND State IN (\'issued\', \'running\') AND JobID <> :id');
		$stmt->bindValue(':a', $job['AccountID']);
		$stmt->bindValue(':y', $job['SystemID']);
		$stmt->bindValue(':id', (int)$job['JobID']);
		$result = $stmt->execute();
		$row = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;
		if($row && (int)$row['n'] == 0)
		{
			phoromatic_bench::task_completed($job['AccountID'], $job['SystemID'], (int)$job['BenchSessionID']);
		}
	}
	// Whether a trigger of a schedule has been used up for a system by native jobs (the v1 rules, a stored result or a skip, are the
	// caller's): a run that reached its end, a job the person stopped on the machine (local_cancel), or two jobs that died without one.
	// A job the agent deferred, or that was stopped for the agent's own reasons, never counts.
	public static function trigger_consumed($account_id, $system_id, $schedule_id, $trigger)
	{
		$bench = phoromatic_server::bench_state_for_system($account_id, $system_id);
		$session = $bench && $bench['session'] !== null ? (int)$bench['session'] : null;
		$stmt = phoromatic_server::$db->prepare('SELECT EndStatus, EndReason, RunEnded, UploadID FROM phoromatic_v2_jobs WHERE AccountID = :a AND SystemID = :y AND ScheduleID = :s AND Trigger = :t AND State = \'ended\'' . ($session !== null ? ' AND BenchSessionID = :b' : ''));
		if($stmt === false)
		{
			return false;
		}
		$stmt->bindValue(':a', $account_id);
		$stmt->bindValue(':y', $system_id);
		$stmt->bindValue(':s', (int)$schedule_id);
		$stmt->bindValue(':t', (string)$trigger);
		if($session !== null)
		{
			$stmt->bindValue(':b', $session);
		}
		$result = $stmt->execute();
		$died = 0;
		while($result && $row = $result->fetchArray(SQLITE3_ASSOC))
		{
			if(in_array($row['EndStatus'], array('ok', 'partial', 'failed'), true))
			{
				if((int)$row['RunEnded'] == 1 || $row['UploadID'] !== null)
				{
					return true;
				}
				$died++;
			}
			else if($row['EndStatus'] === 'cancelled' && $row['EndReason'] === 'local_cancel')
			{
				return true;
			}
			else if($row['EndStatus'] === 'lost')
			{
				$died++;
			}
		}
		return $died >= 2;
	}

	// ----- a job's own calls

	protected static function job_for($ctx, $job_id)
	{
		$stmt = phoromatic_server::$db->prepare('SELECT * FROM phoromatic_v2_jobs WHERE JobID = :id');
		$stmt->bindValue(':id', (int)$job_id);
		$result = $stmt->execute();
		$job = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;
		// Another account's job is a job that does not exist: ids must reveal nothing across accounts
		if(!$job || $job['AccountID'] !== $ctx['account'])
		{
			self::fail(404, 'not_found', 'No such job.');
		}
		if($job['SystemID'] !== $ctx['system']['SystemID'])
		{
			self::fail(409, 'job_not_yours', 'That job belongs to another system.');
		}
		return $job;
	}
	protected static function events_path($job, $name = '.events.jsonl')
	{
		$dir = phoromatic_server::phoromatic_account_system_path($job['AccountID'], $job['SystemID']) . 'jobs/';
		pts_file_io::mkdir($dir);
		return $dir . (int)$job['JobID'] . $name;
	}
	protected static function events($ctx, $job)
	{
		$body = self::json_body(self::MAX_JSON);
		self::check_body($body, 'events_request');
		if($job['State'] === 'ended')
		{
			self::fail(409, 'job_ended', 'That job has ended.');
		}
		$db = phoromatic_server::$db;
		$file = self::events_path($job);
		$seen = array();
		$size = is_file($file) ? filesize($file) : 0;
		if($size > 0 && !empty($body['events']))
		{
			foreach(file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line)
			{
				$e = json_decode($line, true);
				if(is_array($e) && isset($e['seq']))
				{
					$seen[(isset($e['run']) ? $e['run'] : '') . '|' . $e['seq']] = true;
				}
			}
		}
		$append = '';
		$run_id = $job['RunID'];
		$run_ended = false;
		$last_test = null;
		$estimate = null;
		foreach($body['events'] as $e)
		{
			$key = (isset($e['run']) && is_string($e['run']) ? $e['run'] : '') . '|' . $e['seq'];
			if(isset($seen[$key]))
			{
				continue;
			}
			$seen[$key] = true;
			$line = json_encode($e, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
			if($size + strlen($append) + strlen($line) <= self::MAX_EVENTS_FILE)
			{
				$append .= $line;
			}
			$type = $e['type'];
			$run = isset($e['run']) && is_string($e['run']) ? $e['run'] : null;
			if($type === 'hello' && $run !== null && $run_id === null && preg_match('/^[0-9]{8}T[0-9]{6}Z-[0-9a-f]{6}\z/', $run) === 1 && (!isset($e['command']) || $e['command'] === 'benchmark'))
			{
				$run_id = $run;
			}
			if($type === 'run_end' && $run !== null && $run === $run_id)
			{
				$run_ended = true;
			}
			if($type === 'test_start' && isset($e['test']['ref'], $e['index'], $e['total']))
			{
				$last_test = $e;
			}
			if($type === 'run_plan' && isset($e['estimated_total_s']) && is_numeric($e['estimated_total_s']))
			{
				$estimate = (int)ceil($e['estimated_total_s'] / 60);
			}
		}
		if($append !== '')
		{
			@file_put_contents($file, $append, FILE_APPEND | LOCK_EX);
		}
		$set = 'LastContactOn = :now, State = \'running\'';
		if($run_id !== null && $job['RunID'] === null)
		{
			$set .= ', RunID = :run';
		}
		if($run_ended)
		{
			$set .= ', RunEnded = 1';
		}
		$stmt = $db->prepare('UPDATE phoromatic_v2_jobs SET ' . $set . ' WHERE JobID = :id AND State <> \'ended\'');
		$stmt->bindValue(':now', phoromatic_server::current_time());
		if($run_id !== null && $job['RunID'] === null)
		{
			$stmt->bindValue(':run', $run_id);
		}
		$stmt->bindValue(':id', (int)$job['JobID']);
		if(@$stmt->execute() === false && $run_id !== null && $job['RunID'] === null)
		{
			// the run id is already another job's: keep this job's own record without it
			$plain = $db->prepare('UPDATE phoromatic_v2_jobs SET LastContactOn = :now, State = \'running\'' . ($run_ended ? ', RunEnded = 1' : '') . ' WHERE JobID = :id AND State <> \'ended\'');
			$plain->bindValue(':now', phoromatic_server::current_time());
			$plain->bindValue(':id', (int)$job['JobID']);
			@$plain->execute();
		}
		// The system page's status follows the run: written when the test (or the percentage) changes, and always when the run ends
		$task = null;
		$extra = '';
		if($run_ended)
		{
			$task = 'Finishing ' . self::text($job['Title'], 80);
		}
		else if($last_test !== null)
		{
			$task = 'Running ' . self::text($last_test['test']['ref'], 80) . ' (' . (int)$last_test['index'] . ' of ' . (int)$last_test['total'] . ')';
			if((int)$last_test['total'] > 0)
			{
				$percent = (int)round(max(0, (int)$last_test['index'] - 1) * 100 / (int)$last_test['total']);
				if($task !== $ctx['system']['CurrentTask'] || (int)$ctx['system']['TaskPercentComplete'] !== $percent)
				{
					$extra = ', TaskPercentComplete = ' . $percent;
				}
			}
			if($task === $ctx['system']['CurrentTask'])
			{
				$task = null;
			}
		}
		if($estimate !== null)
		{
			$extra .= ', EstimatedTimeForTask = ' . (int)$estimate;
		}
		self::touch_system($ctx, $task, $extra);
		self::reply(200, self::envelope(array('ok' => true, 'cancel' => false)));
	}
	protected static function end($ctx, $job)
	{
		$body = self::json_body(self::MAX_JSON);
		self::check_body($body, 'end_request');
		self::$checking = false;
		if($job['State'] !== 'ended')
		{
			$ended = self::end_job($job, $body['status'], $body['reason'], $body['run']);
			if($ended === 'busy')
			{
				// the database stayed locked for the whole busy timeout: not the end's fault, the agent should try again
				self::fail(503, 'server_error', 'The server is busy; try again.');
			}
			if(!$ended)
			{
				// the update itself was refused (not a busy database: that would have waited): a retry would be refused too
				self::fail(409, 'job_ended', 'The job could not be ended.');
			}
		}
		// A repeat of the same end (or an end after the server marked the job lost) is answered the same way: ok
		self::touch_system($ctx, 'Idle (native agent)', ', TaskPercentComplete = 0');
		self::reply(200, self::envelope(array('ok' => true)));
	}
	protected static function result($ctx, $job)
	{
		$raw = null;
		$events_gz = null;
		foreach(array('result', 'events') as $part)
		{
			$text = null;
			if(isset($_FILES[$part]))
			{
				$f = $_FILES[$part];
				if(is_array($f['error']) || $f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE)
				{
					self::fail(413, 'too_large', 'The ' . $part . ' part is too large.');
				}
				if($f['error'] === UPLOAD_ERR_OK && is_uploaded_file($f['tmp_name']))
				{
					$text = file_get_contents($f['tmp_name'], false, null, 0, self::MAX_RESULT + 1);
				}
			}
			else if(isset($_POST[$part]) && is_string($_POST[$part]))
			{
				$text = $_POST[$part];
			}
			if($part === 'result')
			{
				$raw = $text;
			}
			else
			{
				$events_gz = $text;
			}
		}
		if($raw === null && isset($_SERVER['CONTENT_LENGTH']) && (int)$_SERVER['CONTENT_LENGTH'] > self::postmax())
		{
			self::fail(413, 'too_large', 'The upload is larger than this server accepts (' . self::postmax() . ' bytes).');
		}
		if($raw === null)
		{
			self::fail(400, 'bad_request', 'A multipart body with a result part is required.');
		}
		if($events_gz !== null && strlen($events_gz) > self::MAX_EVENTS_FILE)
		{
			self::fail(413, 'too_large', 'The events part is larger than ' . self::MAX_EVENTS_FILE . ' bytes.');
		}
		if(strlen($raw) > self::MAX_RESULT)
		{
			self::fail(413, 'too_large', 'The result is larger than ' . self::MAX_RESULT . ' bytes.');
		}
		$data = json_decode($raw, true, 64);
		if(!is_array($data))
		{
			self::fail(400, 'bad_request', 'The result part is not JSON.');
		}
		$run = isset($data['runs'][0]['run']) && is_string($data['runs'][0]['run']) ? $data['runs'][0]['run'] : null;

		// A second upload for the same run returns the first reply (an agent retries freely)
		if($job['UploadID'] !== null)
		{
			if($run === null || $run !== $job['RunID'])
			{
				self::fail(422, 'result_invalid', 'This job already has a result, for another run.');
			}
			self::reply(200, self::envelope(self::result_reply($job['AccountID'], (int)$job['UploadID'])));
		}
		if($job['State'] === 'ended' && !in_array($job['EndStatus'], array('ok', 'partial', 'failed', 'lost'), true))
		{
			self::fail(409, 'job_ended', 'That job has ended without a run.');
		}
		$problems = self::result_problems($data, $job, $raw);
		if(!empty($problems))
		{
			self::fail(422, 'result_invalid', implode('; ', $problems));
		}
		self::$checking = false;
		if($job['RunID'] === null)
		{
			// a run id that is already another job's of this system cannot be this job's
			$clash = phoromatic_server::$db->prepare('SELECT JobID FROM phoromatic_v2_jobs WHERE AccountID = :a AND SystemID = :y AND RunID = :r AND JobID <> :id');
			$clash->bindValue(':a', $job['AccountID']);
			$clash->bindValue(':y', $job['SystemID']);
			$clash->bindValue(':r', $run);
			$clash->bindValue(':id', (int)$job['JobID']);
			$cr = $clash->execute();
			if($cr && $cr->fetchArray(SQLITE3_ASSOC))
			{
				self::fail(422, 'result_invalid', 'The run ' . $run . ' belongs to another job of this system');
			}
		}
		$payload = json_decode($job['PayloadJSON'], true);
		$catalogue = array();
		$c = phoromatic_server::$db->prepare('SELECT Ref, TestJSON FROM phoromatic_system_catalogue WHERE AccountID = :a AND SystemID = :y');
		$c->bindValue(':a', $job['AccountID']);
		$c->bindValue(':y', $job['SystemID']);
		$cr = $c->execute();
		while($cr && $row = $cr->fetchArray(SQLITE3_ASSOC))
		{
			$t = json_decode($row['TestJSON'], true);
			if(is_array($t) && isset($t['title']) && is_string($t['title']))
			{
				$catalogue[$row['Ref']] = $t['title'];
			}
		}
		self::$checking = true;
		$converted = phoromatic_native_result::convert($data, array('title' => $job['Title'], 'identifier' => isset($payload['identifier']) ? $payload['identifier'] : $job['Trigger'],
			'description' => isset($payload['description']) ? $payload['description'] : '', 'catalogue' => $catalogue));
		self::$checking = false;
		if(!$converted['ok'])
		{
			self::fail(422, 'result_invalid', $converted['error']);
		}
		$started = isset($data['runs'][0]['started']) ? strtotime($data['runs'][0]['started']) : false;
		$ended = isset($data['runs'][0]['ended']) ? strtotime($data['runs'][0]['ended']) : false;
		$elapsed = $started !== false && $ended !== false && $ended >= $started ? $ended - $started : 0;

		$db = phoromatic_server::$db;
		if(@$db->exec('BEGIN IMMEDIATE') === false)
		{
			self::fail(503, 'server_error', 'The server is busy; try again.');
		}
		$fresh = $db->prepare('SELECT UploadID, RunID FROM phoromatic_v2_jobs WHERE JobID = :id');
		$fresh->bindValue(':id', (int)$job['JobID']);
		$fr = $fresh->execute();
		$now_row = $fr ? $fr->fetchArray(SQLITE3_ASSOC) : false;
		if($now_row && $now_row['UploadID'] !== null)
		{
			// another request of the same agent got there first
			@$db->exec('ROLLBACK');
			self::reply(200, self::envelope(self::result_reply($job['AccountID'], (int)$now_row['UploadID'])));
		}
		$stored = phoromatic_result_ingest::store($job['AccountID'], $job['SystemID'], $converted['xml'], $job['ScheduleID'], null, $job['Trigger'], $elapsed, false, null, $job['BenchSessionID'] === null ? null : (int)$job['BenchSessionID']);
		$ok = $stored['ok'];
		if($ok)
		{
			// without the run id when another job got it first (the clash is checked above; this is the race)
			foreach(array(true, false) as $with_run)
			{
				$set = $db->prepare('UPDATE phoromatic_v2_jobs SET UploadID = :u' . ($with_run ? ', RunID = COALESCE(RunID, :run)' : '') . ', RunEnded = 1 WHERE JobID = :id');
				$set->bindValue(':u', (int)$stored['upload_id']);
				if($with_run)
				{
					$set->bindValue(':run', $run);
				}
				$set->bindValue(':id', (int)$job['JobID']);
				$ok = @$set->execute() !== false;
				if($ok)
				{
					break;
				}
			}
		}
		if(!$ok || @$db->exec('COMMIT') === false)
		{
			@$db->exec('ROLLBACK');
			self::fail($stored['ok'] ? 500 : 422, $stored['ok'] ? 'server_error' : 'result_invalid', $stored['ok'] ? 'The result could not be saved.' : (string)$stored['error']);
		}
		self::release_snapshot($job);

		// The native original and the event stream sit next to the composite
		$dir = phoromatic_server::phoromatic_account_result_path($job['AccountID'], (int)$stored['upload_id']);
		pts_file_io::mkdir($dir);
		@file_put_contents($dir . 'result.json', $raw);
		$accumulated = self::events_path($job);
		$events_text = null;
		if($events_gz !== null && $events_gz !== '')
		{
			$events_text = @gzdecode($events_gz, self::MAX_RESULT);
		}
		if(is_string($events_text) && $events_text !== '')
		{
			@file_put_contents($dir . 'native-events.jsonl', $events_text);
			@unlink($accumulated);
		}
		else if(is_file($accumulated))
		{
			@rename($accumulated, $dir . 'native-events.jsonl');
		}
		$title = $stored['title'];
		self::reply(200, self::envelope(self::result_reply($job['AccountID'], (int)$stored['upload_id'])), array(), function() use ($job, $stored, $title) {
			phoromatic_result_ingest::notify($job['AccountID'], $job['SystemID'], $stored['upload_id'], $title);
		});
	}
	protected static function postmax()
	{
		return phoromatic_tls::post_max_bytes();
	}
	protected static function result_reply($account_id, $upload_id)
	{
		$stmt = phoromatic_server::$db->prepare('SELECT PPRID FROM phoromatic_results WHERE AccountID = :a AND UploadID = :u');
		$stmt->bindValue(':a', $account_id);
		$stmt->bindValue(':u', (int)$upload_id);
		$result = $stmt->execute();
		$row = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;
		return array('upload_id' => (int)$upload_id, 'url' => '/?result/' . ($row ? $row['PPRID'] : ''));
	}
	// What the schema cannot say about an upload: its shape (the schema's own $defs/upload), one run for this job, and no entry twice
	protected static function result_problems($data, $job, $raw)
	{
		// checked in the object form of the document, so that an empty options object is an object and not an empty list
		$errors = phoromatic_json_schema::validate(json_decode($raw, false, 64), 'result-v1.schema.json#/$defs/upload');
		if(!empty($errors))
		{
			return $errors;
		}
		$run = $data['runs'][0];
		if($job['RunID'] !== null && $run['run'] !== $job['RunID'])
		{
			return array('The run (' . $run['run'] . ') is not the one this job started');
		}
		if(count($run['tests']) > 2000)
		{
			return array('The run lists more than 2000 entries');
		}
		$seen = array();
		foreach($run['tests'] as $i => $entry)
		{
			$options = $entry['options'];
			ksort($options);
			$key = $entry['test']['ref'] . "\n" . $entry['test']['version'] . "\n" . json_encode($options) . "\n" . $entry['metric'];
			if(isset($seen[$key]))
			{
				return array('tests[' . $i . '] repeats ' . $entry['test']['ref'] . ' / ' . $entry['metric'] . ' with the same options');
			}
			$seen[$key] = true;
			if($entry['status'] === 'ok' && ($entry['value'] === null || !is_finite((float)$entry['value'])))
			{
				return array('tests[' . $i . '] is ok but has no finite value');
			}
		}
		return array();
	}
}

?>
