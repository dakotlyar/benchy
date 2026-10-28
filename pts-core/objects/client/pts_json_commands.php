<?php

/*
	Benchy
	pts_json_commands.php: the commands of `benchy --json` (documentation/json-events.md)

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

// Each contract command lives here rather than in the human command files, so the human output stays as it is and the
// JSON command set is exactly the contract's. Every command ends with pts_json_output::finish() (done) or fail().

class pts_json_commands
{
	// identifier without version (pts/x264) => array(arguments[], descriptions[]) chosen from --option/--defaults/--all-options
	protected static $test_options = array();

	public static function run($command, $args)
	{
		switch(pts_json_output::command())
		{
			case 'hello':
				break;
			case 'system-info':
				pts_json_output::emit('system_info', self::system_info_local());
				break;
			case 'list-available-tests':
				self::list_tests();
				break;
			case 'list-available-suites':
				self::list_suites();
				break;
			case 'info':
				self::info($args);
				break;
			case 'install':
				self::install($args);
				break;
			case 'benchmark':
				self::benchmark($args);
				break;
			case 'cancel':
				self::cancel($args);
				break;
			case 'result':
				self::result($args);
				break;
			case 'private-repo-refresh':
				self::private_repo_refresh();
				break;
			case 'phoromatic.upload-result':
				self::upload_result($command, $args);
				break;
		}
		pts_json_output::finish('ok', pts_json_output::EXIT_OK);
	}
	public static function test_options($test_profile)
	{
		// Used by the run manager in place of its option prompts
		$key = pts_json_output::ref_without_version($test_profile->get_identifier(false));
		return isset(self::$test_options[$key]) ? self::$test_options[$key] : null;
	}

	// ---- system information

	public static function system_info_local()
	{
		$summary = pts_hardware_summary::local();
		$num = function($v) { return is_numeric($v) && $v > 0 ? (int)$v : null; };
		$str = function($v) { $v = is_scalar($v) ? trim((string)$v) : ''; return $v === '' ? null : $v; };
		$info = array('summary' => array('hardware' => phodevi::system_hardware(true), 'software' => phodevi::system_software(true)));
		$info['cpu'] = array_merge($summary['cpu'], array('cores' => $num(phodevi::read_property('cpu', 'physical-core-count')), 'threads' => $num(phodevi::read_property('cpu', 'thread-count'))));
		$info['gpu'] = $summary['gpu'];
		$info['motherboard'] = $summary['motherboard'];
		$info['memory'] = $summary['memory'];
		$info['storage'] = self::storage_list(phodevi::read_property('disk', 'identifier'));
		$info['os'] = array('name' => $str(phodevi::read_property('system', 'operating-system')), 'version' => $str(phodevi::read_property('system', 'os-version')), 'kernel' => $str(phodevi::read_property('system', 'kernel')));
		return $info;
	}
	public static function system_info_from_strings($hardware, $software)
	{
		// A saved result's system, from the hardware and software strings stored with it
		$summary = pts_hardware_summary::from_hardware_string($hardware);
		$sw = pts_result_file_analyzer::system_component_string_to_array((string)$software);
		$hw = pts_result_file_analyzer::system_component_string_to_array((string)$hardware);
		$info = array('summary' => array('hardware' => (string)$hardware, 'software' => (string)$software));
		$info['cpu'] = array_merge($summary['cpu'], array('cores' => null, 'threads' => null));
		$info['gpu'] = $summary['gpu'];
		$info['motherboard'] = $summary['motherboard'];
		$info['memory'] = $summary['memory'];
		$info['storage'] = self::storage_list(isset($hw['Disk']) ? $hw['Disk'] : null);
		$info['os'] = array('name' => (isset($sw['OS']) ? $sw['OS'] : null), 'version' => null, 'kernel' => (isset($sw['Kernel']) ? $sw['Kernel'] : null));
		return $info;
	}
	protected static function storage_list($disks)
	{
		$storage = array();
		foreach(explode(' + ', (string)$disks) as $disk)
		{
			if(($disk = trim($disk)) !== '')
			{
				$storage[] = array('model' => $disk);
			}
		}
		return $storage;
	}

	// ---- listing

	protected static function installed_versions()
	{
		// ref (pts/x264) => installed versions
		$installed = array();
		foreach(pts_tests::installed_tests() as $identifier)
		{
			$ref = pts_json_output::ref_without_version($identifier);
			$installed[$ref][] = substr($identifier, strlen($ref) + 1);
		}
		foreach($installed as &$versions)
		{
			usort($versions, 'version_compare');
			$versions = array_reverse($versions);
		}
		return $installed;
	}
	protected static function contract_ref($identifier)
	{
		// pts/x264 or <private repo>/x -> the contract ref (the private repository is always "private/")
		$repo = pts_openbenchmarking::repository_of_identifier($identifier);
		return $repo !== null && pts_private_repo_client::is_private_repository($repo) ? 'private' . substr($identifier, strlen($repo)) : $identifier;
	}
	protected static function list_tests()
	{
		$installed = self::installed_versions();
		$count = 0;
		foreach(pts_openbenchmarking::available_tests(false) as $identifier)
		{
			$repo = substr($identifier, 0, strpos($identifier, '/'));
			$id = substr($identifier, strlen($repo) + 1);
			$index = pts_openbenchmarking::read_repository_index($repo);
			$t = isset($index['tests'][$id]) ? $index['tests'][$id] : null;
			// The same filter as the human listing: supported here, verified, graphics only with a display
			if($t == null || empty($t['title']) || (!empty($t['supported_platforms']) && !in_array(phodevi::os_under_test(), $t['supported_platforms'])))
			{
				continue;
			}
			if((!empty($t['status']) && $t['status'] != 'Verified') || (isset($t['test_type']) && $t['test_type'] == 'Graphics' && !phodevi::is_display_server_active()))
			{
				continue;
			}
			$versions = isset($t['versions']) ? $t['versions'] : array();
			pts_json_output::emit('test', array(
				'test' => array('ref' => self::contract_ref($identifier), 'version' => (isset($versions[0]) ? (string)$versions[0] : null)),
				'repo' => pts_json_output::repo_name($repo),
				'title' => $t['title'],
				'description' => (isset($t['description']) ? $t['description'] : null),
				'category' => (isset($t['test_type']) ? $t['test_type'] : null),
				'os' => array_map('strtolower', isset($t['supported_platforms']) ? $t['supported_platforms'] : array()),
				'license' => (isset($t['license']) ? $t['license'] : null),
				'status' => (!empty($t['status']) ? $t['status'] : null),
				'installed' => isset($installed[$identifier]),
				'installed_version' => (isset($installed[$identifier]) ? $installed[$identifier][0] : null),
				'download_size_mb' => null,
				'disk_size_mb' => null,
				'estimated_run_s' => (isset($t['average_run_time']) && is_numeric($t['average_run_time']) ? (int)$t['average_run_time'] : null),
				'option_count' => null,
				));
			$count++;
		}
		foreach(pts_tests::local_tests() as $identifier)
		{
			$tp = new pts_test_profile($identifier);
			if($tp->get_title() != null && $tp->is_supported(false))
			{
				pts_json_output::emit('test', self::test_fields($tp, $installed));
				$count++;
			}
		}
		pts_json_output::emit('list_end', array('kind' => 'tests', 'count' => $count));
	}
	protected static function test_fields($tp, $installed = null)
	{
		$installed = $installed === null ? self::installed_versions() : $installed;
		$ref = pts_json_output::test_ref($tp);
		$engine_ref = pts_json_output::ref_without_version($tp->get_identifier(false));
		$estimated = $tp->get_estimated_run_time();
		return array(
			'test' => $ref,
			'repo' => pts_json_output::repo_name(pts_openbenchmarking::repository_of_identifier($engine_ref)),
			'title' => $tp->get_title(),
			'description' => $tp->get_description(),
			'category' => $tp->get_test_hardware_type(),
			'os' => array_map('strtolower', $tp->get_supported_platforms()),
			'license' => $tp->get_license(),
			'status' => $tp->get_status(),
			'installed' => isset($installed[$engine_ref]),
			'installed_version' => (isset($installed[$engine_ref]) ? $installed[$engine_ref][0] : null),
			'download_size_mb' => (float)$tp->get_download_size(),
			'disk_size_mb' => (float)$tp->get_environment_size(),
			'estimated_run_s' => (is_numeric($estimated) && $estimated > 0 ? (int)$estimated : null),
			'option_count' => count(pts_json_output::option_objects($tp)),
			);
	}
	protected static function suite_fields($suite)
	{
		$identifier = $suite->get_identifier(false);
		$tests = array();
		foreach($suite->get_contained_test_profiles() as $tp)
		{
			$tests[] = pts_json_output::test_ref($tp);
		}
		return array(
			'suite' => array('ref' => self::contract_ref(pts_json_output::ref_without_version($identifier)), 'version' => ($suite->get_version() != null ? (string)$suite->get_version() : null)),
			'repo' => pts_json_output::repo_name(pts_openbenchmarking::repository_of_identifier($identifier)),
			'title' => $suite->get_title(),
			'description' => $suite->get_description(),
			'tests' => $tests,
			);
	}
	protected static function list_suites()
	{
		$count = 0;
		foreach(pts_test_suites::all_suites(true, true) as $identifier)
		{
			$suite = new pts_test_suite($identifier);
			if($suite->is_supported() && $suite->get_title() != null && !$suite->is_deprecated())
			{
				pts_json_output::emit('suite', self::suite_fields($suite));
				$count++;
			}
		}
		pts_json_output::emit('list_end', array('kind' => 'suites', 'count' => $count));
	}
	protected static function resolve($ref)
	{
		// A contract ref to a test profile or suite object, or a not_found failure
		$identifier = pts_json_output::ref_to_identifier($ref);
		if(strpos($identifier, 'private/') === 0 && ($private = pts_private_repo_client::repository_name()) != false)
		{
			$identifier = $private . substr($identifier, 7);
		}
		$object = pts_types::identifier_to_object($identifier);
		if(($object instanceof pts_test_profile && $object->get_title() != null) || ($object instanceof pts_test_suite && !($object instanceof pts_virtual_test_suite) && $object->get_title() != null))
		{
			return $object;
		}
		pts_json_output::fail('not_found', 'No test or suite called ' . $ref . '.', array('ref' => $ref));
	}
	protected static function info($args)
	{
		if(count($args) != 1)
		{
			pts_json_output::fail('usage', 'info takes one test or suite.', null, pts_json_output::EXIT_USAGE);
		}
		$object = self::resolve($args[0]);
		if($object instanceof pts_test_profile)
		{
			$info = self::test_fields($object);
			$info['options'] = array();
			foreach(pts_json_output::option_objects($object) as $o)
			{
				$info['options'][] = pts_json_output::option_contract($o);
			}
			$info['dependencies'] = array_values(array_filter($object->get_external_dependencies()));
			$count = 0;
			$size = 0;
			foreach($object->get_downloads() as $d)
			{
				$count++;
				$size += $d->get_filesize();
			}
			$info['downloads'] = array('count' => $count, 'size_mb' => ($size > 0 ? round($size / 1048576, 2) : 0), 'cached_mb' => null);
			pts_json_output::emit('test_info', $info);
		}
		else
		{
			$info = self::suite_fields($object);
			$info['contained'] = array();
			foreach($object->get_contained_test_result_objects() as $ro)
			{
				$info['contained'][] = array('test' => pts_json_output::test_ref($ro->test_profile), 'options' => pts_json_output::options_from_description($ro->test_profile, $ro->get_arguments_description()), 'summary' => (string)$ro->get_arguments_description());
			}
			pts_json_output::emit('suite_info', $info);
		}
	}

	// ---- install and run

	protected static function targets($args)
	{
		if(empty($args))
		{
			pts_json_output::fail('usage', 'Name at least one test or suite.', null, pts_json_output::EXIT_USAGE);
		}
		$targets = array();
		foreach($args as $ref)
		{
			$targets[] = self::resolve($ref);
		}
		return $targets;
	}
	protected static function identifiers($targets)
	{
		$ids = array();
		foreach($targets as $t)
		{
			$ids[] = $t->get_identifier();
		}
		return $ids;
	}
	protected static function profiles_of($targets)
	{
		$profiles = array();
		foreach($targets as $t)
		{
			if($t instanceof pts_test_profile)
			{
				$profiles[] = $t;
			}
			else
			{
				foreach($t->get_contained_test_profiles() as $tp)
				{
					$profiles[] = $tp;
				}
			}
		}
		return $profiles;
	}
	protected static function check_dependencies($profiles)
	{
		// The engine never installs system packages in JSON mode: missing ones are refused (exit 3) unless
		// --skip-missing-dependencies, which drops the tests that need them
		if(pts_env::read('NO_EXTERNAL_DEPENDENCIES') != false || pts_env::read('SKIP_EXTERNAL_DEPENDENCIES') == 1)
		{
			return;
		}
		$missing = array();
		$tests = array();
		foreach(pts_external_dependencies::missing_dependencies_for($profiles) as $package => $needed_by)
		{
			$missing[] = $package;
			foreach($needed_by as $tp)
			{
				$tests[] = pts_json_output::test_ref($tp)['ref'];
			}
		}
		if(empty($missing))
		{
			putenv('NO_EXTERNAL_DEPENDENCIES=1');
			return;
		}
		if(!pts_json_output::flag('skip-missing-dependencies'))
		{
			$question = 'Missing system packages: ' . implode(', ', $missing);
			pts_json_output::emit('prompt', array('id' => 'MISSING_DEPENDENCIES', 'kind' => 'bool', 'question' => $question, 'options' => null, 'default' => null, 'answer' => null, 'answer_source' => 'refused'));
			pts_json_output::fail('dependency_missing', $question . '. Install them, or pass --skip-missing-dependencies.', array('id' => 'MISSING_DEPENDENCIES', 'packages' => $missing, 'tests' => array_values(array_unique($tests))), pts_json_output::EXIT_PROMPT);
		}
	}
	protected static function resolve_options($profiles)
	{
		// --option <ref>.<key>=<choice key or value> (repeatable), --defaults, --all-options -> each test's argument sets.
		// A choice must be one of the option's choice keys exactly; an option left without a value is refused.
		$given = array();
		foreach(pts_json_output::flag('option', array()) as $o)
		{
			$eq = strpos($o, '=');
			$dot = $eq === false ? false : strrpos(substr($o, 0, $eq), '.');
			if($eq === false || $dot === false)
			{
				pts_json_output::fail('usage', '--option takes <test>.<option>=<value>, not: ' . $o, null, pts_json_output::EXIT_USAGE);
			}
			$ref = pts_json_output::ref_without_version(pts_json_output::ref_to_identifier(substr($o, 0, $dot)));
			$given[$ref][substr($o, $dot + 1, $eq - $dot - 1)][] = substr($o, $eq + 1);
		}
		$all = pts_json_output::flag('all-options', false);
		$defaults = pts_json_output::flag('defaults', false);
		$missing = array();
		foreach($profiles as $tp)
		{
			$test_ref = pts_json_output::test_ref($tp)['ref'];
			$ref = pts_json_output::ref_without_version($tp->get_identifier(false));
			$base = $tp->get_identifier_base_name();
			$values = isset($given[$test_ref]) ? $given[$test_ref] : (isset($given[$ref]) ? $given[$ref] : (isset($given[$base]) ? $given[$base] : array()));
			unset($given[$test_ref], $given[$ref], $given[$base]);
			$user_args = array();
			$text_args = array();
			$objects = pts_json_output::option_objects($tp);
			$known = array();
			foreach($objects as $o)
			{
				$key = $o->get_identifier();
				$known[] = $key;
				$args = array();
				$texts = array();
				if(isset($values[$key]))
				{
					foreach($values[$key] as $v)
					{
						if($o->option_count() > 0)
						{
							$index = array_search($v, $o->get_all_option_names(), true);
							if($index === false)
							{
								pts_json_output::fail('usage', 'Not a choice of ' . $test_ref . '.' . $key . ': ' . $v, array('option' => $test_ref . '.' . $key, 'value' => $v, 'choices' => $o->get_all_option_names()), pts_json_output::EXIT_USAGE);
							}
							$args[] = $o->format_option_value_from_select($index);
							$texts[] = $o->format_option_display_from_select($index);
						}
						else
						{
							if($key == 'positive-number' && (!is_numeric($v) || $v <= 0))
							{
								pts_json_output::fail('usage', $test_ref . '.' . $key . ' needs a positive number, not: ' . $v, array('option' => $test_ref . '.' . $key, 'value' => $v), pts_json_output::EXIT_USAGE);
							}
							$args[] = $o->format_option_value_from_input($v);
							$texts[] = $o->format_option_display_from_input($v);
						}
					}
				}
				else if($o->option_count() > 0 && $all)
				{
					for($i = 0; $i < $o->option_count(); $i++)
					{
						$args[] = $o->format_option_value_from_select($i);
						$texts[] = $o->format_option_display_from_select($i);
					}
				}
				else if($o->option_count() > 0 && $defaults)
				{
					$args[] = $o->format_option_value_from_select($o->get_option_default());
					$texts[] = $o->format_option_display_from_select($o->get_option_default());
				}
				else
				{
					$missing[] = array('id' => 'TEST_OPTION:' . $test_ref . '.' . $key, 'test' => pts_json_output::test_ref($tp), 'option' => pts_json_output::option_contract($o));
					continue;
				}
				$user_args[] = $args;
				$text_args[] = $texts;
			}
			foreach(array_keys($values) as $key)
			{
				if(!in_array($key, $known))
				{
					pts_json_output::fail('usage', $test_ref . ' has no option ' . $key . '.', array('option' => $test_ref . '.' . $key, 'options' => $known), pts_json_output::EXIT_USAGE);
				}
			}
			$test_args = array();
			$test_args_description = array();
			pts_test_run_options::compute_all_combinations($test_args, null, $user_args, 0);
			pts_test_run_options::compute_all_combinations($test_args_description, null, $text_args, 0, ' - ');
			if(count($test_args_description) == 1 && $test_args_description[0] == '' && $tp->get_test_subtitle() != '')
			{
				$test_args_description[0] = $tp->get_test_subtitle();
			}
			self::$test_options[$ref] = array($test_args, $test_args_description);
		}
		if(!empty($given))
		{
			$ref = array_keys($given)[0];
			pts_json_output::fail('usage', '--option names ' . $ref . ', which is not a test given on the command line.', array('ref' => $ref), pts_json_output::EXIT_USAGE);
		}
		if(!empty($missing))
		{
			foreach($missing as $m)
			{
				pts_json_output::emit('prompt', array('id' => $m['id'], 'kind' => ($m['option']['kind'] == 'choice' ? 'menu' : $m['option']['kind']), 'question' => $m['test']['ref'] . ': ' . $m['option']['label'], 'options' => ($m['option']['kind'] == 'choice' ? array_map(function($c) { return array('key' => $c['key'], 'label' => $c['label']); }, $m['option']['choices']) : null), 'default' => $m['option']['default'], 'answer' => null, 'answer_source' => 'refused'));
			}
			pts_json_output::fail('prompt_required', 'Test options need values: ' . implode(', ', array_map(function($m) { return substr($m['id'], 12); }, $missing)) . ' (--option, --defaults or --all-options).', array('id' => $missing[0]['id'], 'question' => $missing[0]['test']['ref'] . ': ' . $missing[0]['option']['label'], 'options' => null, 'missing' => $missing), pts_json_output::EXIT_PROMPT);
		}
	}
	protected static function do_install($targets, $report_installed)
	{
		$profiles = self::profiles_of($targets);
		if($report_installed)
		{
			foreach($profiles as $tp)
			{
				if(!$tp->is_supported(false))
				{
					pts_json_output::emit('install_end', array('test' => pts_json_output::test_ref($tp), 'status' => 'skipped', 'error' => array('code' => 'not_found', 'message' => $tp->get_identifier() . ' is not supported on this system.'), 'duration_s' => 0));
				}
				else if(!$tp->needs_updated_install())
				{
					pts_json_output::emit('install_end', array('test' => pts_json_output::test_ref($tp), 'status' => 'already_installed', 'error' => null, 'duration_s' => 0));
				}
			}
		}
		self::check_dependencies($profiles);
		pts_test_installer::standard_install(self::identifiers($targets), false, true, pts_json_output::flag('skip-missing-dependencies', false));
	}
	protected static function install($args)
	{
		$targets = self::targets($args);
		self::do_install($targets, true);
		$t = pts_client::$display->install_tally();
		if(pts_json_output::cancel_requested())
		{
			pts_json_output::fail('cancelled', 'The install was cancelled.', null, pts_json_output::EXIT_CANCELLED);
		}
		if($t['failed'] > 0)
		{
			if($t['ok'] == 0)
			{
				pts_json_output::fail('install_failed', 'No test installed.');
			}
			pts_json_output::error('install_failed', $t['failed'] . ' of ' . ($t['ok'] + $t['failed']) . ' installs failed.', false);
			pts_json_output::finish('partial', pts_json_output::EXIT_PARTIAL);
		}
	}
	protected static function benchmark($args)
	{
		$targets = self::targets($args);
		$tests = array();
		foreach($targets as $t)
		{
			if($t instanceof pts_test_profile)
			{
				$tests[] = $t;
			}
		}
		self::resolve_options($tests);

		$save = !pts_json_output::flag('no-save', false);
		$name = pts_json_output::flag('name');
		if($save && $name === null)
		{
			$name = ($env = pts_env::read('TEST_RESULTS_NAME')) ? $env : pts_json_output::answer('SAVE_NAME', 'text', 'Enter a name for the result file');
		}
		if($save)
		{
			$clean = pts_test_run_manager::clean_save_name($name);
			if($clean == '' || strlen($name) > 126 || pts_types::is_test_or_suite($name))
			{
				pts_json_output::fail('usage', 'Not a usable result name: ' . $name . ' (2 to 126 characters, not the name of a test or suite).', array('name' => $name), pts_json_output::EXIT_USAGE);
			}
			$identifier = pts_json_output::flag('identifier', pts_env::read('TEST_RESULTS_IDENTIFIER'));
			if($identifier && is_file(PTS_SAVE_RESULTS_PATH . $clean . '/composite.xml'))
			{
				// Appending to an existing identifier that was run on other hardware or software needs --force-append
				$identifier = pts_test_run_manager::clean_results_identifier($identifier);
				if(pts_json_output::flag('force-append'))
				{
					pts_json_output::set_answer('HW_MISMATCH', true);
					pts_json_output::set_answer('SW_MISMATCH', true);
				}
				foreach(pts_types::identifier_to_object($clean)->get_systems() as $s)
				{
					if($s->get_identifier() != $identifier)
					{
						continue;
					}
					foreach(array('HW_MISMATCH' => array($s->get_hardware(), phodevi::system_hardware(true), 'hardware'), 'SW_MISMATCH' => array($s->get_software(), phodevi::system_software(true), 'software')) as $id => $c)
					{
						if($c[0] != $c[1] && pts_json_output::answer($id, 'bool', 'The ' . $c[2] . ' differs from the saved run "' . $identifier . '" in ' . $clean . '. Append anyway?') !== true)
						{
							pts_json_output::fail('usage', 'Not appending to "' . $identifier . '": its ' . $c[2] . ' differs.', array('id' => $id), pts_json_output::EXIT_USAGE);
						}
					}
				}
			}
			pts_env::set('TEST_RESULTS_NAME', $name);
			if($identifier)
			{
				pts_env::set('TEST_RESULTS_IDENTIFIER', $identifier);
			}
		}
		$description = pts_json_output::flag('description');
		if($description !== null)
		{
			pts_env::set('TEST_RESULTS_DESCRIPTION', $description);
		}

		if(!pts_json_output::flag('no-install', false))
		{
			self::do_install($targets, false);
		}
		if(pts_json_output::cancel_requested())
		{
			pts_json_output::fail('cancelled', 'The run was cancelled before any test started.', null, pts_json_output::EXIT_CANCELLED);
		}

		$run_manager = new pts_test_run_manager(array(
			'UploadResults' => false,
			'SaveResults' => $save,
			'PromptForTestDescription' => ($description !== null),
			'RunAllTestCombinations' => false,
			'PromptSaveName' => false,
			'PromptForTestIdentifier' => false,
			'OpenBrowser' => false,
			));
		$run_manager->standard_run(self::identifiers($targets));
		$display = pts_client::$display;
		$skipped = 0;
		foreach($tests as $tp)
		{
			// A test named on the command line that the run left out (not installed: --no-install or a failed install; not
			// supported here) is reported, so the counts add up
			$ref = pts_json_output::test_ref($tp);
			if(!in_array($ref['ref'], $display->planned_refs()) && !pts_json_output::cancel_requested())
			{
				$skipped++;
				pts_json_output::emit('test_result', array('test' => $ref, 'options' => new stdClass(), 'summary' => '', 'metric' => $tp->get_title(), 'value' => null, 'unit' => $tp->get_result_scale(), 'direction' => null, 'std_dev_pct' => null, 'trials' => array(), 'status' => 'skipped', 'error' => array('code' => ($tp->is_supported(false) ? 'install_failed' : 'not_found'), 'message' => ($tp->is_supported(false) ? $ref['ref'] . ' is not installed.' : $ref['ref'] . ' is not supported on this system.'))));
			}
		}
		if($display->planned_test_count() == 0)
		{
			if(pts_json_output::cancel_requested())
			{
				pts_json_output::fail('cancelled', 'The run was cancelled before any test started.', null, pts_json_output::EXIT_CANCELLED);
			}
			pts_json_output::fail('run_failed', 'Nothing could be run (tests not installed or not supported here).');
		}
		$t = $display->tally();
		if(pts_json_output::cancel_requested())
		{
			// The planned tests that never started because of the cancel
			$t['cancelled'] += max(0, $display->planned_test_count() - $t['completed'] - $t['failed'] - $t['cancelled']);
		}
		$t['failed'] += $skipped;
		$saved = $save && $run_manager->get_file_name() != null && is_file(PTS_SAVE_RESULTS_PATH . $run_manager->get_file_name() . '/composite.xml');
		if(pts_json_output::cancel_requested())
		{
			$status = 'cancelled';
		}
		else if($t['failed'] == 0)
		{
			$status = 'ok';
		}
		else
		{
			$status = $t['completed'] > 0 ? 'partial' : 'failed';
		}
		pts_json_output::emit('run_end', array('status' => $status, 'result' => ($saved ? array('id' => $run_manager->get_file_name(), 'title' => $run_manager->get_title()) : null), 'completed' => $t['completed'], 'failed' => $t['failed'], 'cancelled' => $t['cancelled']));
		switch($status)
		{
			case 'cancelled':
				pts_json_output::finish('cancelled', pts_json_output::EXIT_CANCELLED);
			case 'partial':
				pts_json_output::finish('partial', pts_json_output::EXIT_PARTIAL);
			case 'failed':
				pts_json_output::fail('run_failed', 'No test produced a result.');
		}
	}
	protected static function cancel($args)
	{
		if(count($args) != 1 || !pts_json_output::valid_run_id($args[0]))
		{
			pts_json_output::fail('usage', 'cancel takes the run ID from hello.run.', null, pts_json_output::EXIT_USAGE);
		}
		if(!is_file(pts_json_output::runs_dir() . $args[0] . '.active'))
		{
			pts_json_output::fail('not_found', 'No run ' . $args[0] . ' is in progress.', array('run' => $args[0]));
		}
		file_put_contents(pts_json_output::cancel_file($args[0]), time());
	}

	// ---- results, private repository, upload

	protected static function result($args)
	{
		if(count($args) != 2 || $args[0] != 'show')
		{
			pts_json_output::fail('usage', 'result takes: show <id>.', null, pts_json_output::EXIT_USAGE);
		}
		$id = $args[1];
		if(pts_test_run_manager::clean_save_name($id, false) !== $id || !is_file(PTS_SAVE_RESULTS_PATH . $id . '/composite.xml'))
		{
			pts_json_output::fail('not_found', 'No saved result ' . $id . '.', array('id' => $id));
		}
		$result_file = new pts_result_file($id);
		$systems = $result_file->get_systems();
		$system = null;
		$wanted = pts_json_output::flag('identifier');
		foreach($systems as $s)
		{
			if($wanted === null || $s->get_identifier() == $wanted)
			{
				$system = $s;
			}
		}
		if($system === null)
		{
			pts_json_output::fail('not_found', 'The result ' . $id . ' has no run "' . $wanted . '".', array('id' => $id, 'identifier' => $wanted, 'identifiers' => array_map(function($s) { return $s->get_identifier(); }, $systems)));
		}
		$tests = array();
		foreach($result_file->get_result_objects() as $ro)
		{
			$item = $ro->test_result_buffer->find_buffer_item($system->get_identifier());
			if($item === false)
			{
				continue;
			}
			$value = $item->get_result_value();
			$trials = array();
			foreach(explode(':', (string)$item->get_result_raw()) as $r)
			{
				if(is_numeric($r))
				{
					$trials[] = (float)$r;
				}
			}
			$ok = (is_numeric($value) && $value > 0) || (!is_numeric($value) && $value != '');
			$std_dev_pct = null;
			if(count($trials) > 1 && ($mean = array_sum($trials) / count($trials)) != 0)
			{
				$var = 0;
				foreach($trials as $tr)
				{
					$var += pow($tr - $mean, 2);
				}
				$std_dev_pct = round(sqrt($var / (count($trials) - 1)) / $mean * 100, 2);
			}
			$proportion = $ro->test_profile->get_result_proportion();
			$error = $item->get_error();
			$tests[] = array(
				'test' => pts_json_output::test_ref($ro->test_profile),
				'options' => pts_json_output::options_from_description($ro->test_profile, $ro->get_arguments_description()),
				'summary' => (string)$ro->get_arguments_description(),
				'metric' => $ro->test_profile->get_title(),
				'value' => (is_numeric($value) ? (float)$value : null),
				'unit' => $ro->test_profile->get_result_scale(),
				'direction' => ($proportion == 'HIB' ? 'higher' : ($proportion == 'LIB' ? 'lower' : null)),
				'std_dev_pct' => $std_dev_pct,
				'trials' => $trials,
				'status' => ($ok ? 'ok' : 'failed'),
				'error' => ($ok ? null : array('code' => 'run_failed', 'message' => ($error ? $error : 'No result.'))),
				);
		}
		pts_json_output::emit('result', array('id' => $id, 'title' => $result_file->get_title(), 'identifier' => $system->get_identifier(), 'system' => self::system_info_from_strings($system->get_hardware(), $system->get_software()), 'tests' => $tests));
	}
	protected static function private_repo_refresh()
	{
		$repo = pts_private_repo_client::repository_name();
		if($repo == false)
		{
			pts_json_output::fail('not_found', 'The private repository is disabled (Options/OpenBenchmarking/PrivateRepository).');
		}
		if(pts_private_repo_client::connection() == false)
		{
			pts_json_output::fail('not_found', 'No private repository server is set up (private-repo-setup).');
		}
		pts_openbenchmarking::refresh_repository_lists(array($repo), true);
		$index = pts_openbenchmarking::read_repository_index($repo, true);
		$error = pts_openbenchmarking::$private_refresh_error;
		$items = is_array($index) ? (isset($index['tests']) && is_array($index['tests']) ? count($index['tests']) : 0) + (isset($index['suites']) && is_array($index['suites']) ? count($index['suites']) : 0) : 0;
		$generated = is_array($index) && isset($index['main']['generated']) && is_numeric($index['main']['generated']) ? gmdate('Y-m-d\TH:i:s\Z', (int)$index['main']['generated']) : null;
		pts_json_output::emit('repo_refresh', array('repo' => 'private', 'status' => ($error != null ? 'failed' : 'ok'), 'items' => $items, 'generated' => $generated, 'cached' => ($error != null && is_array($index))));
		if($error != null)
		{
			pts_json_output::fail('server', 'Refresh failed: ' . $error, array('cached_index_kept' => is_array($index)));
		}
	}
	protected static function upload_result($command, $args)
	{
		if(empty($args))
		{
			pts_json_output::fail('usage', 'phoromatic.upload-result takes a saved result ID.', null, pts_json_output::EXIT_USAGE);
		}
		pts_client::execute_command($command, $args);
		$uploads = self::$uploads;
		if(empty($uploads))
		{
			pts_json_output::fail('not_found', 'No saved result to upload, or no Phoromatic server is configured.', array('args' => $args));
		}
		if(in_array('failed', $uploads))
		{
			pts_json_output::fail('server', 'The Phoromatic server did not accept the upload.');
		}
	}
	protected static $uploads = array();
	public static function upload_event($server, $result, $ok, $message = null)
	{
		// Called by the phoromatic module for each result it uploads in JSON mode
		self::$uploads[] = $ok ? 'ok' : 'failed';
		pts_json_output::emit('upload', array('target' => 'phoromatic', 'server' => $server, 'result' => $result, 'status' => ($ok ? 'ok' : 'failed'), 'url' => null, 'message' => $message));
	}
}

?>
