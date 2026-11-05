<?php

/*
	Benchy
	phoromatic_native_result.php: turns a native engine result (result-v1, one run) into a composite.xml the rest of Phoromatic
	reads like any other upload. This is the only place on the server that knows both formats.

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

class phoromatic_native_result
{
	// Test refs written repo/name belong to the engine, not to OpenBenchmarking.org ("synth" is the engine's built-in fixture tests). The server
	// never asks an index about them. That is every benchy/ and synth/ name, and every repo/name an agent of this server has reported in its
	// catalogue (the protocol lets an engine have any repo, so the list cannot be fixed here); a result's identifier may carry a version suffix.
	protected static $known_refs = array();
	public static function is_native_ref($identifier)
	{
		// Anything that is not a plain repo/name (a path, markup, a long string) is not ours and takes the ordinary route
		if(!is_string($identifier))
		{
			return false;
		}
		if(preg_match('#^(benchy|synth)/[A-Za-z0-9._-]{1,100}$#', $identifier) === 1)
		{
			return true;
		}
		if(preg_match('#^[A-Za-z0-9_.-]{1,64}/[A-Za-z0-9_.-]{1,100}$#', $identifier) !== 1 || !isset(phoromatic_server::$db))
		{
			return false;
		}
		if(in_array(strtolower(substr($identifier, 0, strpos($identifier, '/'))), array('pts', 'system', 'local', 'git'), true))
		{
			// OpenBenchmarking.org's own repositories are never native, whatever any account's catalogue says
			return false;
		}
		if(!isset(self::$known_refs[$identifier]))
		{
			$base = preg_replace('/-[0-9]+(\.[0-9]+)*$/', '', $identifier);
			$stmt = @phoromatic_server::$db->prepare('SELECT 1 FROM phoromatic_system_catalogue WHERE Ref = :a OR Ref = :b LIMIT 1');
			$found = false;
			if($stmt)
			{
				$stmt->bindValue(':a', $identifier);
				$stmt->bindValue(':b', $base);
				$result = @$stmt->execute();
				$found = $result && $result->fetchArray() !== false;
			}
			self::$known_refs[$identifier] = $found;
		}
		return self::$known_refs[$identifier];
	}
	// $upload is the decoded `result` document (result-v1, exactly one run). $options:
	//   title, description, identifier  override the run's own (the job's title and trigger)
	//   catalogue                       ref => the test's title from the system's catalogue row (the ref itself when missing)
	// Returns array('ok' => bool, 'xml' => string, 'error' => string|null, 'result_count' => int)
	public static function convert($upload, $options = array())
	{
		if(!is_array($upload) || !isset($upload['schema']) || $upload['schema'] !== 1 || !isset($upload['runs']) || !is_array($upload['runs']) || count($upload['runs']) != 1 || !is_array($upload['runs'][0]))
		{
			return self::refuse('The result must be a schema 1 document with exactly one run');
		}
		$run = $upload['runs'][0];
		if(!isset($upload['engine']['version']) || !is_string($upload['engine']['version']) || $upload['engine']['version'] === '' || !isset($run['system']['summary']['hardware'], $run['system']['summary']['software'], $run['tests']) || !is_array($run['tests']))
		{
			return self::refuse('The result lacks the engine version, the system summary or the tests');
		}
		$engine_version = $upload['engine']['version'];
		$catalogue = isset($options['catalogue']) && is_array($options['catalogue']) ? $options['catalogue'] : array();

		// A test's metric count is the number of its entries whatever their status (the engine writes one per declared metric), so a
		// partly failed run keeps the same Arguments, and the same comparison hash, as a clean one
		$metric_count = array();
		foreach($run['tests'] as $entry)
		{
			if(!is_array($entry) || !isset($entry['test']['ref'], $entry['test']['version']) || !is_string($entry['test']['ref']) || !is_string($entry['test']['version']) || $entry['test']['version'] === '')
			{
				return self::refuse('Every test entry needs a ref and a version');
			}
			$k = self::group_key($entry);
			$metric_count[$k] = isset($metric_count[$k]) ? $metric_count[$k] + 1 : 1;
		}

		$identifier = isset($options['identifier']) && $options['identifier'] !== '' ? $options['identifier'] : (isset($run['identifier']) ? (string)$run['identifier'] : '');
		$title = isset($options['title']) && $options['title'] !== '' ? $options['title'] : (isset($upload['title']) ? (string)$upload['title'] : '');
		$description = isset($options['description']) ? $options['description'] : (isset($run['description']) ? (string)$run['description'] : '');

		if($title === '')
		{
			// A result with no title is refused by the schema; name it after the first test that ran instead
			foreach($run['tests'] as $entry)
			{
				if(isset($entry['status']) && $entry['status'] === 'ok')
				{
					$title = isset($catalogue[$entry['test']['ref']]) && is_string($catalogue[$entry['test']['ref']]) && $catalogue[$entry['test']['ref']] !== '' ? $catalogue[$entry['test']['ref']] : $entry['test']['ref'];
					break;
				}
			}
		}
		$result_file = new pts_result_file();
		$result_file->set_title($title);
		$result_file->set_description($description);

		$notes = array();
		$ok_count = 0;
		$results = array();
		foreach($run['tests'] as $entry)
		{
			$ref = $entry['test']['ref'];
			if(!isset($entry['status']) || $entry['status'] !== 'ok')
			{
				// PTS keeps no failed results either; the failure is still readable on the result page through the notes
				$why = isset($entry['error']['message']) ? $entry['error']['message'] : '';
				$notes[$ref . ': ' . (isset($entry['status']) ? $entry['status'] : 'unknown') . ($why !== '' ? ': ' . $why : '')] = true;
				continue;
			}
			if(!isset($entry['value']) || !is_numeric($entry['value']) || !is_finite((float)$entry['value']) || !isset($entry['metric']) || !is_string($entry['metric']))
			{
				return self::refuse('An ok entry of ' . $ref . ' has no finite numeric value or no metric');
			}
			$multi = $metric_count[self::group_key($entry)] > 1;
			$options_map = isset($entry['options']) && is_array($entry['options']) ? $entry['options'] : array();
			ksort($options_map);
			$arguments = array();
			foreach($options_map as $k => $v)
			{
				$arguments[] = $k . '=' . $v;
			}
			if($multi)
			{
				$arguments[] = 'metric=' . $entry['metric'];
			}
			$summary = isset($entry['summary']) && is_string($entry['summary']) ? $entry['summary'] : '';
			$arguments_description = $multi ? ($summary !== '' ? $summary . ' - ' : '') . $entry['metric'] : $summary;

			// No qualifying lookup: the identifier is set as it is, never resolved against a repository index
			$profile = new pts_test_profile($ref . '-' . $entry['test']['version'], false);
			$test_title = isset($catalogue[$ref]) && is_string($catalogue[$ref]) && $catalogue[$ref] !== '' ? $catalogue[$ref] : $ref;
			$app_version = '';
			$scale = isset($entry['unit']) && is_string($entry['unit']) ? $entry['unit'] : '';
			$direction = isset($entry['direction']) ? $entry['direction'] : null;
			$proportion = $direction === 'higher' ? 'HIB' : ($direction === 'lower' ? 'LIB' : '');
			$format = 'BAR_GRAPH';
			$profile->set_identifier($ref . '-' . $entry['test']['version']);
			$profile->set_test_title($test_title);
			$profile->set_version($app_version);
			$profile->set_result_scale($scale);
			$profile->set_result_proportion($proportion);
			$profile->set_display_format($format);

			$result = new pts_test_result($profile);
			$result->set_used_arguments(implode(' ', $arguments));
			$result->set_used_arguments_description($arguments_description);

			$trials = array();
			foreach(isset($entry['trials']) && is_array($entry['trials']) ? $entry['trials'] : array() as $t)
			{
				if(is_numeric($t) && !is_finite((float)$t))
				{
					return self::refuse('A trial of ' . $ref . ' is not a finite number');
				}
				if(is_numeric($t))
				{
					$trials[] = $t;
				}
			}
			$value = self::number($entry['value']);
			$raw = count($trials) > 0 ? implode(':', array_map(array('phoromatic_native_result', 'number'), $trials)) : $value;
			$json = null;
			if(count($trials) > 0)
			{
				$json = array('min-result' => (float)min($trials), 'max-result' => (float)max($trials));
			}
			$buffer = new pts_test_result_buffer();
			$buffer->add_test_result($identifier, $value, $raw, $json);
			$result->set_test_result_buffer($buffer);
			$results[] = $result;
			$ok_count++;
		}

		if($ok_count == 0)
		{
			return self::refuse('No test in the run produced a result' . (count($notes) > 0 ? ' (' . implode('; ', array_keys($notes)) . ')' : ''));
		}
		foreach($results as $result)
		{
			$result_file->add_result($result);
		}

		$timestamp = isset($run['started']) && ($t = strtotime($run['started'])) !== false ? gmdate('Y-m-d H:i:s', $t) : '';
		// The result pages print every System/JSON entry as text, so it holds flat strings only. The structured system info stays in the
		// result.json the endpoint keeps next to the composite (and in the system's properties), not here.
		$system_json = array('benchy_run' => isset($run['run']) && is_string($run['run']) ? $run['run'] : '');
		$result_file->add_system(new pts_result_file_system($identifier, $run['system']['summary']['hardware'], $run['system']['summary']['software'], $system_json, '', implode("\n", array_keys($notes)), $timestamp, $engine_version, $result_file));

		$xml = $result_file->get_xml();
		// get_xml() writes this PTS client's own name; the result says which engine made it
		$client = '<TestClient>' . htmlspecialchars('benchy-native ' . $engine_version, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</TestClient>';
		$xml = preg_replace_callback('#<TestClient>.*?</TestClient>#s', function($m) use ($client) { return $client; }, $xml, 1);

		return array('ok' => true, 'xml' => $xml, 'error' => null, 'result_count' => $ok_count);
	}
	protected static function group_key($entry)
	{
		$o = isset($entry['options']) && is_array($entry['options']) ? $entry['options'] : array();
		ksort($o);
		return $entry['test']['ref'] . "\n" . $entry['test']['version'] . "\n" . json_encode($o);
	}
	// A number as Go prints it: the shortest text that reads back the same, no trailing ".0"
	public static function number($n)
	{
		if(is_int($n))
		{
			return (string)$n;
		}
		$s = json_encode((float)$n, JSON_PRESERVE_ZERO_FRACTION);
		return substr($s, -2) === '.0' ? substr($s, 0, -2) : $s;
	}
	protected static function refuse($why)
	{
		return array('ok' => false, 'xml' => '', 'error' => $why, 'result_count' => 0);
	}
}

?>
