<?php

/*
	Benchy
	pts_json_output.php: the JSON-lines event stream of `benchy --json` (protocol major 1)

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

// In objects/ rather than objects/client/ because shared objects (pts_user_io, pts_network) ask is_active(), and the
// Phoromatic server's web pages load only objects/; everything else here runs in a JSON-mode client only.
// The contract is pts-core/static/json-events/v1.schema.json (documentation/json-events.md). stdout carries events and
// nothing else: events are written with fwrite(STDOUT), any other output (a stray echo) is caught by an output buffer
// and becomes a `log` event, PHP's own messages go to stderr. The first line is `hello`, the last `done`, and
// done.exit_code is always the process exit code. Nothing reads standard input (prompts are answered from flags or
// refused, see answer()), except `--json-args -`, read once in detect() before startup.

class pts_json_output
{
	const PROTOCOL = 1;
	const EXIT_OK = 0;
	const EXIT_ERROR = 1;
	const EXIT_USAGE = 2;
	const EXIT_PROMPT = 3;
	const EXIT_CANCELLED = 4;
	const EXIT_PARTIAL = 5;

	// Contract command (as typed, dashes) => engine command it runs as
	const COMMANDS = array(
		'hello' => 'hello',
		'system-info' => 'system_info',
		'list-available-tests' => 'list_available_tests',
		'list-available-suites' => 'list_available_suites',
		'info' => 'info',
		'install' => 'install_test',
		'benchmark' => 'benchmark',
		'cancel' => 'cancel',
		'result' => 'result',
		'private-repo-refresh' => 'private_repo_refresh',
		'phoromatic.upload-result' => 'phoromatic.upload_result',
		);
	// Commands that do work get a run ID (hello.run) and can be cancelled
	const RUN_COMMANDS = array('install', 'benchmark', 'private-repo-refresh', 'phoromatic.upload-result');
	// Flags taking a value, and boolean flags, accepted after the command in JSON mode
	const VALUE_FLAGS = array('option', 'answer', 'name', 'identifier', 'description', 'accept-license');
	const BOOL_FLAGS = array('no-save', 'all-options', 'defaults', 'no-install', 'force-append', 'accept-licenses', 'skip-missing-dependencies', 'json-verbose');
	// Prompts that may take their default when nothing answers them; every other prompt is refused
	const SAFE_DEFAULTS = array('SAVE_RESULTS' => true, 'UPLOAD_OB' => false, 'UPLOAD_SYSTEM_LOGS' => false, 'OPEN_BROWSER' => false, 'STOP_AND_INSTALL' => true, 'TRY_DOWNLOAD_AGAIN' => false, 'TEST_IDENTIFIER' => null, 'TEST_DESCRIPTION' => null);

	private static $active = false;
	private static $seq = 0;
	private static $hello_sent = false;
	private static $done_sent = false;
	private static $pending = array();
	private static $line_buffer = '';
	private static $command = null;
	private static $run = null;
	private static $flags = array();
	private static $answers = array();
	private static $cancelled = false;
	private static $cancel_checked = 0;
	private static $last_error_code = null;
	private static $license_refusals = array();
	private static $startup_failure = null;

	public static function is_active()
	{
		return self::$active;
	}

	// ---- startup

	public static function detect(&$argv, &$argc)
	{
		// Called before anything else runs. Returns true when JSON mode is on; strips --json[=N] and the JSON-only flags from
		// $argv so the rest of the startup never sees them.
		$requested = null;
		if(isset($argv[1]) && ($argv[1] === '--json' || strpos($argv[1], '--json=') === 0))
		{
			$requested = $argv[1];
			array_splice($argv, 1, 1);
		}
		else if(strtolower(trim((string)getenv('BENCHY_OUTPUT'))) === 'json')
		{
			$requested = '--json';
		}
		if($requested === null)
		{
			return false;
		}

		self::$active = true;
		ini_set('display_errors', 'stderr');
		ini_set('html_errors', '0');
		putenv('NO_COLOR=1');
		putenv('PTS_SILENT_MODE=0');
		ob_start(array('pts_json_output', 'output_buffer_callback'), 1);
		register_shutdown_function(array('pts_json_output', 'shutdown_first'));
		if(function_exists('pcntl_async_signals') && function_exists('pcntl_signal'))
		{
			pcntl_async_signals(true);
			pcntl_signal(SIGINT, array('pts_json_output', 'signal_handler'));
			pcntl_signal(SIGTERM, array('pts_json_output', 'signal_handler'));
		}

		if($requested !== '--json')
		{
			$major = substr($requested, 7);
			if($major !== (string)self::PROTOCOL)
			{
				// A client asking for a major this engine does not speak gets one error line it can read whatever it expects,
				// and nothing else (no hello: the hello it would get is in a protocol it did not ask for)
				self::write(array('v' => self::PROTOCOL, 'type' => 'error', 'seq' => ++self::$seq, 'ts' => self::timestamp(), 'code' => 'unsupported_schema', 'message' => 'This engine speaks JSON protocol ' . self::PROTOCOL . ', not ' . $major . '.', 'fatal' => true, 'detail' => array('supported' => array(self::PROTOCOL))));
				self::$done_sent = true;
				exit(self::EXIT_USAGE);
			}
		}

		$command = isset($argv[1]) ? strtolower($argv[1]) : '';
		self::$command = $command;
		$rest = array_slice($argv, 2);
		$args = array();
		$usage_error = null;
		// --json-args <file|-> (0.9, capability json_args): anywhere after the command, at most once. Its JSON array of strings
		// is appended after the last argv token and parsed by the loop below like argv. `-` reads standard input to EOF, here,
		// before any other startup work: the one exception to "nothing reads stdin"
		$json_args_at = array_keys($rest, '--json-args', true);
		if(count($json_args_at) > 1)
		{
			$usage_error = '--json-args given twice.';
		}
		else if(count($json_args_at) == 1)
		{
			$at = $json_args_at[0];
			if(!isset($rest[$at + 1]))
			{
				$usage_error = '--json-args needs a file or -.';
			}
			else
			{
				$source = $rest[$at + 1];
				array_splice($rest, $at, 2);
				$extra = self::read_json_args($source, $usage_error);
				if($extra !== null)
				{
					$rest = array_merge($rest, $extra);
				}
			}
		}
		foreach($rest as $a)
		{
			if($usage_error === null && ($a === '--json' || strpos($a, '--json=') === 0))
			{
				$usage_error = $a . ' must be the first argument.';
			}
		}
		for($i = 0; $usage_error === null && $i < count($rest); $i++)
		{
			$a = $rest[$i];
			if(substr($a, 0, 2) != '--')
			{
				$args[] = $a;
				continue;
			}
			$name = substr($a, 2);
			$value = null;
			if(($eq = strpos($name, '=')) !== false)
			{
				$value = substr($name, $eq + 1);
				$name = substr($name, 0, $eq);
			}
			if(in_array($name, self::VALUE_FLAGS))
			{
				if($value === null)
				{
					if(!isset($rest[$i + 1]))
					{
						$usage_error = '--' . $name . ' needs a value.';
						break;
					}
					$value = $rest[++$i];
				}
				if(in_array($name, array('option', 'answer', 'accept-license')))
				{
					self::$flags[$name][] = $value;
				}
				else
				{
					self::$flags[$name] = $value;
				}
			}
			else if(in_array($name, self::BOOL_FLAGS) && $value === null)
			{
				self::$flags[$name] = true;
			}
			else
			{
				$usage_error = 'Unknown option: --' . $name;
				break;
			}
		}
		$argv = array_merge(array($argv[0], isset(self::COMMANDS[$command]) ? self::COMMANDS[$command] : $command), $args);
		$argc = count($argv);

		foreach(isset(self::$flags['answer']) ? self::$flags['answer'] : array() as $answer)
		{
			if(($eq = strpos($answer, '=')) === false || $eq == 0)
			{
				$usage_error = '--answer takes ID=value, not: ' . $answer;
				break;
			}
			self::$answers[substr($answer, 0, $eq)] = substr($answer, $eq + 1);
		}

		if(in_array($command, self::RUN_COMMANDS))
		{
			self::$run = gmdate('Ymd\THis\Z') . '-' . bin2hex(random_bytes(3));
		}

		if(!isset(self::COMMANDS[$command]))
		{
			self::$startup_failure = array('unsupported_command', ($command == '' ? 'No command given.' : 'The command ' . $command . ' has no JSON mode.'), array('command' => $command));
		}
		else if($usage_error)
		{
			self::$startup_failure = array('usage', $usage_error, null);
		}
		return true;
	}
	protected static function read_json_args($source, &$error)
	{
		// The strings of a --json-args file (or of stdin for `-`), or null with $error set: UTF-8 JSON, an array of strings,
		// at most 1 MiB, holding no --json, --json=N or --json-args
		$max = 1048576;
		if($source === '-')
		{
			$bytes = defined('STDIN') ? @stream_get_contents(STDIN, $max + 1) : false;
			if($bytes === false)
			{
				$error = 'can\'t read stdin.';
				return null;
			}
		}
		else
		{
			$bytes = is_file($source) ? @file_get_contents($source, false, null, 0, $max + 1) : false;
			if($bytes === false)
			{
				$error = 'can\'t read the args file.';
				return null;
			}
		}
		if(strlen($bytes) > $max)
		{
			$error = 'the args are over 1 MiB.';
			return null;
		}
		if(!preg_match('//u', $bytes))
		{
			$error = 'the args aren\'t UTF-8.';
			return null;
		}
		$decoded = json_decode($bytes, false);
		if(json_last_error() !== JSON_ERROR_NONE || !is_array($decoded))
		{
			$error = 'the args aren\'t a JSON array.';
			return null;
		}
		foreach($decoded as $item)
		{
			if(!is_string($item))
			{
				$error = 'the args hold a non-string.';
				return null;
			}
			if($item === '--json' || $item === '--json-args' || strpos($item, '--json=') === 0)
			{
				$error = 'the args hold ' . $item . '.';
				return null;
			}
		}
		return $decoded;
	}
	public static function begin()
	{
		// Right after pts_client::init() (configuration and user path ready), before any network, module or command work
		self::hello();
		if(self::$startup_failure)
		{
			list($code, $message, $detail) = self::$startup_failure;
			self::fail($code, $message, $detail, self::EXIT_USAGE);
		}
		self::clean_stale_run_files();
	}
	public static function command()
	{
		return self::$command;
	}
	public static function run_id()
	{
		return self::$run;
	}
	public static function flag($name, $default = null)
	{
		return isset(self::$flags[$name]) ? self::$flags[$name] : $default;
	}
	public static function set_answer($id, $value)
	{
		// A dedicated flag's answer (the command layer maps --name to SAVE_NAME and so on)
		self::$answers[$id] = $value;
	}
	public static function skip_startup_refresh()
	{
		// Commands that never touch a repository index start without the OpenBenchmarking/private repo refresh
		return in_array(self::$command, array('hello', 'cancel', 'result', 'system-info'));
	}
	public static function hello()
	{
		if(self::$hello_sent)
		{
			return;
		}
		$capabilities = array('cancel', 'prompt_answers', 'private_repo', 'json_args');
		if(defined('PTS_CORE_STORAGE') && pts_client::no_phone_home())
		{
			$capabilities[] = 'no_phone_home';
		}
		self::$hello_sent = true;
		$fields = array(
			'engine' => array('name' => 'benchy-pts', 'version' => defined('PTS_VERSION') ? PTS_VERSION : null),
			'protocol' => self::PROTOCOL,
			'capabilities' => $capabilities,
			'capabilities_detail' => array('php_version' => PHP_VERSION, 'os_detail' => php_uname('s') . ' ' . php_uname('r')),
			'os' => (strtoupper(substr(PHP_OS, 0, 3)) == 'WIN' ? 'windows' : strtolower(PHP_OS_FAMILY)),
			'command' => self::$command,
			'pid' => getmypid(),
			'run' => self::$run,
			);
		self::write_event('hello', $fields, false);
		if(self::$run && defined('PTS_USER_PATH'))
		{
			pts_file_io::mkdir(self::runs_dir());
			file_put_contents(self::runs_dir() . self::$run . '.active', getmypid());
		}
		foreach(self::$pending as $p)
		{
			self::write_event($p[0], $p[1]);
		}
		self::$pending = array();
	}
	public static function runs_dir()
	{
		return PTS_USER_PATH . 'json-runs/';
	}
	public static function clean_stale_run_files()
	{
		foreach(pts_file_io::glob(self::runs_dir() . '*') as $f)
		{
			// (never the run lock: deleting a held lock file would let a second run lock a new one)
			if(is_file($f) && filemtime($f) < time() - 86400 && strpos(basename($f), 'run.lock') !== 0)
			{
				unlink($f);
			}
		}
	}

	// ---- writing

	public static function timestamp()
	{
		$t = microtime(true);
		return gmdate('Y-m-d\TH:i:s', (int)$t) . sprintf('.%03dZ', (int)(($t - floor($t)) * 1000));
	}
	protected static function write($event)
	{
		$line = json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION);
		if($line === false)
		{
			$line = json_encode(array('v' => self::PROTOCOL, 'type' => 'log', 'seq' => $event['seq'], 'ts' => $event['ts'], 'level' => 'error', 'text' => 'An event could not be encoded: ' . json_last_error_msg(), 'source' => 'engine'));
		}
		fwrite(STDOUT, $line . "\n");
		fflush(STDOUT);
	}
	protected static function write_event($type, $fields, $with_run = true)
	{
		$event = array('v' => self::PROTOCOL, 'type' => $type, 'seq' => ++self::$seq, 'ts' => self::timestamp());
		if($with_run && self::$run !== null && !isset($fields['run']))
		{
			$event['run'] = self::$run;
		}
		self::write(array_merge($event, $fields));
	}
	public static function emit($type, $fields = array())
	{
		if(!self::$active || self::$done_sent)
		{
			return;
		}
		if(!self::$hello_sent)
		{
			self::$pending[] = array($type, $fields);
			return;
		}
		self::flush_line_buffer();
		self::write_event($type, $fields);
	}
	public static function log($level, $text, $source = 'engine')
	{
		$text = trim(pts_user_io::strip_ansi_escape_sequences((string)$text));
		if($text !== '')
		{
			self::emit('log', array('level' => $level, 'text' => $text, 'source' => $source));
		}
	}
	public static function error($code, $message, $fatal = false, $detail = null)
	{
		$fields = array('code' => $code, 'message' => $message, 'fatal' => $fatal);
		if($detail !== null)
		{
			$fields['detail'] = $detail;
		}
		self::$last_error_code = $code;
		self::emit('error', $fields);
	}
	public static function fail($code, $message, $detail = null, $exit_code = self::EXIT_ERROR)
	{
		// A fatal error ends the command: error event, then done
		self::error($code, $message, true, $detail);
		self::finish($code == 'cancelled' ? 'cancelled' : 'error', $exit_code);
	}
	public static function finish($status, $exit_code)
	{
		if(!self::$active || self::$done_sent)
		{
			exit($exit_code);
		}
		self::hello();
		while(ob_get_level() > 0)
		{
			ob_end_flush();
		}
		self::flush_line_buffer();
		if(class_exists('pts_run_lock', false))
		{
			// Before done: a client may start the next run as soon as it reads done
			pts_run_lock::release(true);
		}
		self::write_event('done', array('status' => $status, 'exit_code' => $exit_code));
		self::$done_sent = true;
		if(self::$run && defined('PTS_USER_PATH'))
		{
			pts_file_io::unlink(self::runs_dir() . self::$run . '.active');
			pts_file_io::unlink(self::runs_dir() . self::$run . '.cancel');
		}
		exit($exit_code);
	}
	public static function output_buffer_callback($buffer, $phase)
	{
		// Anything printed with echo/print while JSON mode is on: a log event per line, or stderr once done has gone out
		if(self::$done_sent)
		{
			if($buffer !== '')
			{
				fwrite(STDERR, $buffer);
			}
			return '';
		}
		self::$line_buffer .= $buffer;
		while(($p = strpos(self::$line_buffer, "\n")) !== false)
		{
			$line = rtrim(substr(self::$line_buffer, 0, $p), "\r");
			self::$line_buffer = substr(self::$line_buffer, $p + 1);
			self::log_output_line($line);
		}
		if($phase & PHP_OUTPUT_HANDLER_FINAL)
		{
			self::flush_line_buffer();
		}
		return '';
	}
	protected static function log_output_line($line)
	{
		$line = trim(pts_user_io::strip_ansi_escape_sequences($line));
		if($line === '')
		{
			return;
		}
		if(!self::$hello_sent)
		{
			self::$pending[] = array('log', array('level' => 'info', 'text' => $line, 'source' => 'output'));
			return;
		}
		self::write_event('log', array('level' => 'info', 'text' => $line, 'source' => 'output'));
	}
	protected static function flush_line_buffer()
	{
		if(self::$line_buffer !== '')
		{
			$line = self::$line_buffer;
			self::$line_buffer = '';
			self::log_output_line($line);
		}
	}
	public static function shutdown_first()
	{
		// Registered first, so it runs first; re-registering puts the real handler after every other shutdown function
		register_shutdown_function(array('pts_json_output', 'shutdown_last'));
	}
	public static function shutdown_last()
	{
		if(!self::$active || self::$done_sent)
		{
			return;
		}
		// Nobody called finish(): a fatal PHP error, or an exit() somewhere in the engine
		$e = error_get_last();
		if($e && in_array($e['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR)))
		{
			self::error('internal', 'Fatal error: ' . $e['message'], true);
		}
		else if(self::$last_error_code === null)
		{
			self::error('internal', 'The engine stopped before the command finished.', true);
		}
		self::finish('error', self::EXIT_ERROR);
	}
	public static function triggered_error($level, $message)
	{
		// Engine notices (trigger_error) are human messages: stderr, and a log event so a GUI can show them (once per process)
		fwrite(STDERR, $message . PHP_EOL);
		self::log_once($level == 'ERROR' ? 'error' : 'warning', $message);
	}
	public static function log_once($level, $text)
	{
		// A known engine message (startup notices: no Internet, a missing PHP extension) as log{source: engine}, once per process
		static $seen = array();
		if(isset($seen[$level . "\0" . $text]))
		{
			return;
		}
		$seen[$level . "\0" . $text] = true;
		self::log($level, $text, 'engine');
	}

	// ---- prompts

	public static function answer($id, $kind, $question, $options = null, $default = null)
	{
		// Every prompt in JSON mode ends here. Order: a dedicated flag or --answer ID=value (both land in $answers), an
		// existing environment preset (the caller passes it as $default with id ENV), the default for a prompt on the safe
		// list. Otherwise refused: prompt event, then error prompt_required, exit 3. INSTALL_AGREEMENT is refused without
		// ending the run: that test's install fails with license_required.
		$id = $id == null ? 'UNKNOWN' : $id;
		$question = trim(pts_user_io::strip_ansi_escape_sequences((string)$question));
		$menu = null;
		if(is_array($options))
		{
			$menu = array();
			foreach($options as $k => $label)
			{
				$menu[] = array('key' => (string)$k, 'label' => trim(pts_user_io::strip_ansi_escape_sequences((string)$label)));
			}
		}
		$answer = null;
		$source = null;
		if($id == 'INSTALL_AGREEMENT' && ($test = self::current_install_test()) !== null)
		{
			// --accept-license takes the contract ref (repo/name, private/ for the private repository), with or without @version
			$ref = pts_client::$display->current_install_test_ref();
			$accepted = self::flag('accept-licenses', false);
			foreach(self::flag('accept-license', array()) as $accept)
			{
				$accepted = $accepted || ($ref !== null && ($accept === $ref['ref'] || $accept === $ref['ref'] . '@' . $ref['version']));
			}
			if($accepted)
			{
				$answer = true;
				$source = 'flag';
			}
		}
		else if(isset(self::$answers[$id]))
		{
			$answer = self::$answers[$id];
			$source = 'answer';
		}
		else if(array_key_exists($id, self::SAFE_DEFAULTS))
		{
			$answer = self::SAFE_DEFAULTS[$id] !== null ? self::SAFE_DEFAULTS[$id] : $default;
			$source = 'default';
		}

		if($source !== null && $kind == 'bool' && !is_bool($answer))
		{
			$answer = pts_strings::string_bool($answer);
		}
		if($source !== null && $kind == 'menu' && is_array($options) && !array_key_exists((string)$answer, $options))
		{
			// A menu answer is one of the option keys
			$source = null;
			$answer = null;
		}

		$event = array('id' => $id, 'kind' => $kind, 'question' => $question, 'options' => $menu, 'default' => (is_scalar($default) || $default === null) ? $default : null, 'answer' => $source === null ? null : $answer, 'answer_source' => $source === null ? 'refused' : $source);
		self::emit('prompt', $event);
		if($source !== null)
		{
			return $answer;
		}
		if($id == 'INSTALL_AGREEMENT')
		{
			self::$license_refusals[self::current_install_test()] = true;
			return false;
		}
		self::fail('prompt_required', 'An answer is needed for: ' . ($question != '' ? $question : $id), array('id' => $id, 'question' => $question, 'options' => $menu), self::EXIT_PROMPT);
	}
	public static function license_refused($test)
	{
		return isset(self::$license_refusals[$test]);
	}
	protected static function current_install_test()
	{
		return pts_client::$display instanceof pts_json_display_mode ? pts_client::$display->current_install_test() : null;
	}

	// ---- cancellation

	public static function cancel_file($run)
	{
		return self::runs_dir() . $run . '.cancel';
	}
	public static function signal_handler($signal)
	{
		if(self::$run !== null)
		{
			// A run stops at its next cancel check, like `cancel <run>`
			self::$cancelled = true;
			return;
		}
		// No run to cancel: the command ends now, still with error + done
		self::fail('cancelled', 'Stopped by signal ' . $signal . '.', null, self::EXIT_CANCELLED);
	}
	public static function cancel_requested()
	{
		if(!self::$active || self::$run === null)
		{
			return false;
		}
		if(!self::$cancelled && (microtime(true) - self::$cancel_checked) > 0.2)
		{
			self::$cancel_checked = microtime(true);
			clearstatcache(true, self::cancel_file(self::$run));
			self::$cancelled = is_file(self::cancel_file(self::$run));
		}
		return self::$cancelled;
	}
	public static function valid_run_id($run)
	{
		return is_string($run) && preg_match('/^[0-9]{8}T[0-9]{6}Z-[0-9a-f]{6}$/', $run);
	}

	// ---- contract helpers

	public static function ref_to_identifier($ref)
	{
		// repo/name@version (the contract) -> repo/name-version (PTS)
		if(($at = strrpos($ref, '@')) !== false)
		{
			return substr($ref, 0, $at) . '-' . substr($ref, $at + 1);
		}
		return $ref;
	}
	public static function ref_without_version($identifier)
	{
		// pts/x264-2.7.0 -> pts/x264
		return preg_replace('/-[0-9]+(\.[0-9]+)+$/', '', $identifier);
	}
	public static function repo_name($repo)
	{
		return pts_private_repo_client::is_private_repository($repo) ? 'private' : $repo;
	}
	public static function test_ref($test_profile)
	{
		$ref = self::ref_without_version($test_profile->get_identifier(false));
		$repo = pts_openbenchmarking::repository_of_identifier($ref);
		if($repo !== null && pts_private_repo_client::is_private_repository($repo))
		{
			$ref = 'private' . substr($ref, strlen($repo));
		}
		$version = $test_profile->get_test_profile_version();
		return array('ref' => $ref, 'version' => ($version == null ? null : (string)$version));
	}
	public static function option_objects($test_profile)
	{
		$error = null;
		$options = $test_profile->get_test_option_objects(true, $error);
		return is_array($options) ? $options : array();
	}
	public static function option_contract($o)
	{
		// One test option as the contract describes it: key/label/kind, choice keys are PTS option names
		$choices = array();
		for($i = 0; $i < $o->option_count(); $i++)
		{
			$choices[] = array('key' => (string)$o->get_option_name($i), 'label' => (string)$o->get_option_name($i), 'help' => ($o->get_option_message($i) != null ? (string)$o->get_option_message($i) : null));
		}
		$kind = $o->option_count() > 0 ? 'choice' : ($o->get_identifier() == 'positive-number' ? 'number' : 'text');
		return array('key' => $o->get_identifier(), 'label' => $o->get_name(), 'help' => ($o->get_helper_message() != null ? $o->get_helper_message() : null), 'kind' => $kind, 'choices' => $choices, 'default' => ($o->option_count() > 0 ? (string)$o->get_option_name($o->get_option_default()) : null));
	}
	public static function options_from_description($test_profile, $description)
	{
		// The option key/value pairs a run used, recovered from its arguments description ("Name: value - Name: value"),
		// which is built in option order; values are mapped back to choice keys where they match
		$values = array();
		$description = (string)$description;
		$objects = self::option_objects($test_profile);
		$pos = 0;
		foreach($objects as $n => $o)
		{
			$prefix = $o->get_name() . ': ';
			$start = strpos($description, $prefix, $pos);
			if($start === false)
			{
				continue;
			}
			$start += strlen($prefix);
			$end = strlen($description);
			for($m = $n + 1; $m < count($objects); $m++)
			{
				if(($next = strpos($description, ' - ' . $objects[$m]->get_name() . ': ', $start)) !== false)
				{
					$end = $next;
					break;
				}
			}
			$value = substr($description, $start, $end - $start);
			$pos = $end;
			for($i = 0; $i < $o->option_count(); $i++)
			{
				$name = $o->get_option_name($i);
				$cut = ($c = strpos($name, '(')) > 1 && strpos($name, ')') > $c ? trim(substr($name, 0, $c)) : $name;
				if($value === $name || $value === $cut)
				{
					$value = $name;
					break;
				}
			}
			$values[$o->get_identifier()] = $value;
		}
		return empty($values) ? new stdClass() : $values;
	}
}

?>
