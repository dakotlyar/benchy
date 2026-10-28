<?php

/*
	Benchy
	pts_json_display_mode.php: the display mode of `benchy --json`; turns the engine's install and run hooks into events

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

class pts_json_display_mode implements pts_display_mode_interface
{
	// Install state
	private $install_index = 0;
	private $install_total = 0;
	private $install_test = null;		// identifier being installed (pts/x-1.0.0)
	private $install_test_ref = null;	// its contract ref
	private $install_started = 0;
	private $install_errors = array();
	private $download = null;			// open download: array(item, size, source)
	private $download_last_progress = 0;
	private $download_had_error = false;
	private $install_tally = array('ok' => 0, 'failed' => 0);

	// Run state
	private $run_request = null;
	private $run_test_emitted = false;
	private $run_any_ok = false;
	private $run_errors = array();
	private $trial = 0;
	private $trial_values_before = 0;
	private $tally = array('completed' => 0, 'failed' => 0, 'cancelled' => 0);
	private $planned = 0;
	private $planned_refs = array();

	public function __construct()
	{
	}
	public function get_tab()
	{
		return '';
	}
	public function current_install_test()
	{
		return $this->install_test;
	}
	public function current_install_test_ref()
	{
		return $this->install_test === null ? null : $this->install_test_ref;
	}
	public function planned_refs()
	{
		return $this->planned_refs;
	}
	public function install_tally()
	{
		return $this->install_tally;
	}
	public function tally()
	{
		return $this->tally;
	}
	public function planned_test_count()
	{
		return $this->planned;
	}
	protected static function mb($bytes)
	{
		return $bytes > 0 ? round($bytes / 1048576, 2) : 0;
	}

	// ---- install

	public function test_install_process(&$test_install_manager)
	{
		$tests = array();
		$download = 0;
		$cached = 0;
		$disk = 0;
		foreach($test_install_manager->get_test_run_requests() as $request)
		{
			$tests[] = pts_json_output::test_ref($request->test_profile);
			$disk += $request->test_profile->get_environment_size();
			foreach($request->get_download_objects() as $file)
			{
				switch($file->get_download_location_type())
				{
					case 'IN_DESTINATION_DIR':
						break;
					case 'LOCAL_DOWNLOAD_CACHE':
					case 'REMOTE_DOWNLOAD_CACHE':
					case 'LOOKASIDE_DOWNLOAD_CACHE':
					case 'MAIN_DOWNLOAD_CACHE':
						$cached += $file->get_filesize();
						break;
					default:
						$download += $file->get_filesize();
						break;
				}
			}
		}
		$this->install_index = 0;
		$this->install_total = count($tests);
		pts_json_output::emit('install_plan', array('tests' => $tests, 'download_total_mb' => self::mb($download), 'disk_total_mb' => (float)ceil($disk), 'cached_mb' => self::mb($cached)));
	}
	public function test_install_start($identifier)
	{
		$this->install_index++;
		$this->install_test = $identifier;
		$this->install_test_ref = pts_json_output::test_ref(new pts_test_profile($identifier));
		$this->install_started = microtime(true);
		$this->install_errors = array();
		$this->download = null;
		pts_json_output::emit('install_start', array('test' => $this->install_test_ref, 'index' => $this->install_index, 'total' => $this->install_total));
	}
	public function json_install_end($test_profile, $installed)
	{
		// Called by the installer once a test's install attempt is over
		$this->close_download();
		$error = null;
		if(!$installed)
		{
			if(pts_json_output::license_refused($test_profile->get_identifier()))
			{
				$agreement = $test_profile->get_installation_agreement_message();
				$error = array('code' => 'license_required', 'message' => 'The test\'s licence agreement must be accepted (--accept-license ' . pts_json_output::test_ref($test_profile)['ref'] . ').', 'detail' => array('test' => pts_json_output::test_ref($test_profile), 'license' => (pts_strings::is_url($agreement) ? null : $agreement), 'url' => (pts_strings::is_url($agreement) ? $agreement : null)));
			}
			else if(pts_json_output::cancel_requested())
			{
				$error = array('code' => 'cancelled', 'message' => 'The install was cancelled.');
			}
			else
			{
				$error = array('code' => 'install_failed', 'message' => (empty($this->install_errors) ? 'The install failed.' : implode(' ', array_unique($this->install_errors))));
			}
		}
		$this->install_tally[$installed ? 'ok' : 'failed']++;
		pts_json_output::emit('install_end', array('test' => pts_json_output::test_ref($test_profile), 'status' => ($installed ? 'ok' : 'failed'), 'error' => $error, 'duration_s' => round(microtime(true) - $this->install_started, 1)));
		$this->install_test = null;
	}
	public function test_install_begin($test_install_request)
	{
		$this->close_download();
		pts_json_output::emit('install_progress', array('test' => $this->install_test_ref, 'message' => 'Installing', 'pct' => null));
	}
	public function test_install_downloads($test_install_request)
	{
	}
	public function test_install_download_file($process, &$pts_test_file_download)
	{
		if($this->install_test === null)
		{
			pts_json_output::log('info', 'Downloading ' . $pts_test_file_download->get_filename());
			return;
		}
		$this->close_download();
		switch($process)
		{
			case 'DOWNLOAD':
				$source = 'upstream';
				break;
			case 'DOWNLOAD_FROM_CACHE':
				$source = 'mirror';
				break;
			default:
				$source = 'cache';
				break;
		}
		$this->download = array('item' => $pts_test_file_download->get_filename(), 'size' => (int)$pts_test_file_download->get_filesize(), 'source' => $source);
		$this->download_last_progress = 0;
		$this->download_had_error = false;
		pts_json_output::emit('download_start', array('test' => $this->install_test_ref, 'item' => $this->download['item'], 'size' => $this->download['size'], 'source' => $source));
		if(in_array($process, array('FILE_FOUND', 'LINK_FROM_CACHE')))
		{
			$this->close_download('ok');
		}
	}
	protected function close_download($status = null)
	{
		if($this->download === null)
		{
			return;
		}
		if($status === null)
		{
			$status = $this->download_had_error ? 'failed' : 'ok';
		}
		pts_json_output::emit('download_end', array('test' => $this->install_test_ref, 'item' => $this->download['item'], 'size' => $this->download['size'], 'source' => $this->download['source'], 'status' => $status, 'verified' => null));
		$this->download = null;
	}
	public function test_install_progress_start($process)
	{
	}
	public function test_install_progress_update($progress_float)
	{
		if($this->download === null || (microtime(true) - $this->download_last_progress) < 0.25)
		{
			return;
		}
		$this->download_last_progress = microtime(true);
		$pct = round(max(0, min(1, $progress_float)) * 100, 1);
		pts_json_output::emit('download_progress', array('test' => $this->install_test_ref, 'item' => $this->download['item'], 'size' => $this->download['size'], 'bytes' => (int)round($this->download['size'] * $pct / 100), 'pct' => $pct));
	}
	public function test_install_progress_completed()
	{
		if($this->download !== null && $this->download['source'] != 'upstream')
		{
			// A cache copy is done when the copy returns; an upstream download is closed by the next step (its checksum decides)
			$this->close_download();
		}
	}
	public function display_interrupt_message($message)
	{
		pts_json_output::log('notice', $message);
	}
	public function test_install_output(&$to_output)
	{
		if(pts_json_output::flag('json-verbose') && $this->install_test !== null)
		{
			foreach(array_slice(explode("\n", (string)$to_output), -200) as $line)
			{
				if(trim($line) !== '')
				{
					pts_json_output::emit('install_output', array('test' => $this->install_test_ref, 'text' => rtrim($line)));
				}
			}
		}
	}
	public function test_install_message($msg_string)
	{
		pts_json_output::emit('install_progress', array('test' => $this->install_test_ref, 'message' => trim(pts_user_io::strip_ansi_escape_sequences($msg_string)), 'pct' => null));
	}
	public function test_install_error($error_string)
	{
		$error_string = trim(pts_user_io::strip_ansi_escape_sequences($error_string));
		if($this->download !== null)
		{
			$this->download_had_error = true;
		}
		$this->install_errors[] = $error_string;
		pts_json_output::log('error', $error_string);
	}
	public function test_install_prompt($prompt_string)
	{
		pts_json_output::log('info', $prompt_string);
	}

	// ---- run

	public function test_run_process_start(&$test_run_manager)
	{
		$tests = array();
		foreach($test_run_manager->get_tests_to_run() as $request)
		{
			$this->planned_refs[] = pts_json_output::test_ref($request->test_profile)['ref'];
			$tests[] = array('test' => pts_json_output::test_ref($request->test_profile), 'options' => pts_json_output::options_from_description($request->test_profile, $request->get_arguments_description()), 'summary' => (string)$request->get_arguments_description(), 'estimated_s' => (int)$request->get_estimated_run_time());
		}
		$this->planned = count($tests);
		pts_json_output::emit('run_plan', array('result_name' => ($test_run_manager->do_save_results() ? $test_run_manager->get_file_name() : null), 'result_identifier' => $test_run_manager->get_results_identifier(), 'description' => $test_run_manager->get_description(), 'tests' => $tests, 'estimated_total_s' => (int)$test_run_manager->get_estimated_run_time()));
	}
	public function test_run_configure(&$test_profile)
	{
	}
	public function test_run_start(&$test_run_manager, &$test_result)
	{
		$this->run_request = $test_result;
		$this->run_test_emitted = false;
		$this->run_any_ok = false;
		$this->run_errors = array();
		$this->trial = 0;
		pts_json_output::emit('test_start', array('test' => pts_json_output::test_ref($test_result->test_profile), 'options' => pts_json_output::options_from_description($test_result->test_profile, $test_result->get_arguments_description()), 'summary' => (string)$test_result->get_arguments_description(), 'index' => $test_run_manager->get_test_run_position(), 'total' => $test_run_manager->get_test_run_count_reported()));
	}
	protected function trial_values_count($test_result)
	{
		if(!empty($test_result->generated_result_buffers))
		{
			$first = reset($test_result->generated_result_buffers);
			return is_object($first->active) ? count($first->active->results) : 0;
		}
		return 0;
	}
	public function test_run_instance_header(&$test_result)
	{
		$this->trial++;
		$this->trial_values_before = $this->trial_values_count($test_result);
		pts_json_output::emit('trial_start', array('test' => pts_json_output::test_ref($test_result->test_profile), 'trial' => $this->trial, 'of' => (int)$test_result->test_profile->get_times_to_run()));
	}
	public function test_run_instance_complete(&$test_result)
	{
		$value = null;
		$ok = $this->trial_values_count($test_result) > $this->trial_values_before;
		if($ok)
		{
			$first = reset($test_result->generated_result_buffers);
			$last = end($first->active->results);
			$value = is_numeric($last) ? (float)$last : null;
		}
		pts_json_output::emit('trial_end', array('test' => pts_json_output::test_ref($test_result->test_profile), 'trial' => $this->trial, 'of' => (int)$test_result->test_profile->get_times_to_run(), 'value' => $value, 'ok' => $ok));
	}
	public function test_run_end(&$test_result)
	{
		// One call per metric the test produced
		$request = $this->run_request;
		$trials = array();
		foreach($test_result->active->results as $r)
		{
			if(is_numeric($r))
			{
				$trials[] = (float)$r;
			}
		}
		$end = $test_result->active->get_result();
		$ok = (is_numeric($end) && $end > 0) || (!is_numeric($end) && is_string($end) && isset($end[3]));
		$description = (string)$test_result->get_arguments_description();
		$request_description = $request ? (string)$request->get_arguments_description() : '';
		$metric = $test_result->test_profile->get_title();
		if($description !== '' && $description !== $request_description)
		{
			$metric = $request_description !== '' && strpos($description, $request_description) === 0 ? trim(substr($description, strlen($request_description)), " -") : $description;
		}
		$std_dev_pct = null;
		if(count($trials) > 1 && ($mean = array_sum($trials) / count($trials)) != 0)
		{
			$var = 0;
			foreach($trials as $t)
			{
				$var += pow($t - $mean, 2);
			}
			$std_dev_pct = round(sqrt($var / (count($trials) - 1)) / $mean * 100, 2);
		}
		$proportion = $test_result->test_profile->get_result_proportion();
		$profile_for_options = $request ? $request : $test_result;
		pts_json_output::emit('test_result', array(
			'test' => pts_json_output::test_ref($test_result->test_profile),
			'options' => pts_json_output::options_from_description($profile_for_options->test_profile, $profile_for_options->get_arguments_description()),
			'summary' => $request_description,
			'metric' => $metric,
			'value' => (is_numeric($end) ? (float)$end : null),
			'unit' => $test_result->test_profile->get_result_scale(),
			'direction' => ($proportion == 'HIB' ? 'higher' : ($proportion == 'LIB' ? 'lower' : null)),
			'std_dev_pct' => $std_dev_pct,
			'trials' => $trials,
			'status' => ($ok ? 'ok' : 'failed'),
			'error' => ($ok ? null : array('code' => 'run_failed', 'message' => (empty($this->run_errors) ? 'The test did not produce a result.' : implode(' ', array_unique($this->run_errors))))),
			));
		$this->run_any_ok = $this->run_any_ok || $ok;
		$this->run_test_emitted = true;
	}
	public function json_test_finished(&$test_run_request, $test_successful)
	{
		// Called after each test's run attempt: a test that produced no test_result (failed early, or was cancelled) gets one
		if($this->run_test_emitted && $this->run_request === $test_run_request)
		{
			$this->tally[$this->run_any_ok ? 'completed' : 'failed']++;
			return;
		}
		$cancelled = pts_json_output::cancel_requested();
		pts_json_output::emit('test_result', array(
			'test' => pts_json_output::test_ref($test_run_request->test_profile),
			'options' => pts_json_output::options_from_description($test_run_request->test_profile, $test_run_request->get_arguments_description()),
			'summary' => (string)$test_run_request->get_arguments_description(),
			'metric' => $test_run_request->test_profile->get_title(),
			'value' => null,
			'unit' => $test_run_request->test_profile->get_result_scale(),
			'direction' => null,
			'std_dev_pct' => null,
			'trials' => array(),
			'status' => ($cancelled ? 'cancelled' : 'failed'),
			'error' => ($cancelled ? array('code' => 'cancelled', 'message' => 'The run was cancelled.') : array('code' => 'run_failed', 'message' => (empty($this->run_errors) ? 'The test did not run.' : implode(' ', array_unique($this->run_errors))))),
			));
		$this->tally[$cancelled ? 'cancelled' : 'failed']++;
		$this->run_request = $test_run_request;
		$this->run_test_emitted = true;
	}
	public function json_test_skipped(&$test_run_request, $reason)
	{
		// A planned test the run manager passed over without running it: reported, and not counted as completed or cancelled
		pts_json_output::emit('test_result', array(
			'test' => pts_json_output::test_ref($test_run_request->test_profile),
			'options' => pts_json_output::options_from_description($test_run_request->test_profile, $test_run_request->get_arguments_description()),
			'summary' => (string)$test_run_request->get_arguments_description(),
			'metric' => $test_run_request->test_profile->get_title(),
			'value' => null,
			'unit' => $test_run_request->test_profile->get_result_scale(),
			'direction' => null,
			'std_dev_pct' => null,
			'trials' => array(),
			'status' => 'skipped',
			'error' => array('code' => 'usage', 'message' => $reason),
			));
		$this->tally['failed']++;
	}
	public function test_run_message($message_string)
	{
		pts_json_output::log('debug', $message_string);
	}
	public function test_run_instance_error($error_string)
	{
		$error_string = trim(pts_user_io::strip_ansi_escape_sequences($error_string));
		$this->run_errors[] = $error_string;
		pts_json_output::log('warning', $error_string);
	}
	public function test_run_instance_output(&$to_output)
	{
		if(pts_json_output::flag('json-verbose') && $this->run_request !== null)
		{
			foreach(array_slice(explode("\n", (string)$to_output), -200) as $line)
			{
				if(trim($line) !== '')
				{
					pts_json_output::emit('test_output', array('test' => pts_json_output::test_ref($this->run_request->test_profile), 'text' => rtrim($line)));
				}
			}
		}
	}
	public function test_run_success_inline($test_result)
	{
	}
	public function test_run_error($error_string)
	{
		$error_string = trim(pts_user_io::strip_ansi_escape_sequences($error_string));
		$this->run_errors[] = $error_string;
		pts_json_output::log('error', $error_string);
	}

	// ---- generic

	public function generic_prompt($prompt_string)
	{
		pts_json_output::log('info', $prompt_string);
	}
	public function generic_heading($string)
	{
	}
	public function generic_sub_heading($string)
	{
		pts_json_output::log('info', $string);
	}
	public function triggered_system_error($level, $message, $file, $line)
	{
		pts_json_output::triggered_error($level, $message . ($file != null && $line != null ? ' in ' . $file . ':' . $line : ''));
	}
}

?>
