<?php

/*
	Benchy
	A small, structured summary of a machine's main hardware (CPU, GPU, motherboard, memory).

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

// Shared by the client (JSON mode's system_info) and the Phoromatic server (the bench drift guard), so it depends on neither.
// summary = array('cpu' => array('model' => string|null), 'gpu' => array(array('model' => string|null, 'device_id' => string|null), ...),
//                 'motherboard' => array('model' => string|null), 'memory' => array('total_mb' => int|null))
// Values are DISPLAY strings exactly as detected (trimmed). Normalisation for comparing two summaries lives only in the
// drift_* methods below and never changes what from_*() return.

class pts_hardware_summary
{
	const TRACKED = array('cpu', 'gpu', 'motherboard', 'memory');
	const MEMORY_TOLERANCE = 0.05;

	public static function empty_summary()
	{
		return array('cpu' => array('model' => null), 'gpu' => array(), 'motherboard' => array('model' => null), 'memory' => array('total_mb' => null));
	}
	protected static function str($value)
	{
		if(!is_scalar($value))
		{
			return null;
		}
		$value = trim(preg_replace('/\s+/', ' ', (string)$value));
		return $value === '' ? null : $value;
	}
	public static function from_properties($pp)
	{
		// $pp: the decoded phodevi::read_all_properties() (what a Phoromatic client sends as 'pp'); a JSON string is accepted too
		if(is_string($pp))
		{
			$pp = json_decode($pp, true);
		}
		$s = self::empty_summary();
		if(!is_array($pp))
		{
			return $s;
		}
		if(isset($pp['benchy_system_info']) && is_array($pp['benchy_system_info']))
		{
			// A native engine reports the same four keys, already in this shape (contract v1, 12.3)
			$i = $pp['benchy_system_info'];
			$s['cpu']['model'] = self::str(isset($i['cpu']['model']) ? $i['cpu']['model'] : null);
			if(isset($i['gpu']) && is_array($i['gpu']))
			{
				foreach($i['gpu'] as $g)
				{
					if(is_array($g))
					{
						$model = self::str(isset($g['model']) ? $g['model'] : null);
						$device_id = self::str(isset($g['device_id']) ? $g['device_id'] : null);
						if($model !== null || $device_id !== null)
						{
							$s['gpu'][] = array('model' => $model, 'device_id' => $device_id);
						}
					}
				}
			}
			$s['motherboard']['model'] = self::str(isset($i['motherboard']['model']) ? $i['motherboard']['model'] : null);
			if(isset($i['memory']['total_mb']) && is_numeric($i['memory']['total_mb']) && $i['memory']['total_mb'] > 0)
			{
				$s['memory']['total_mb'] = (int)round($i['memory']['total_mb']);
			}
			return $s;
		}
		if(isset($pp['cpu']) && is_array($pp['cpu']))
		{
			$s['cpu']['model'] = self::str(isset($pp['cpu']['model']) ? $pp['cpu']['model'] : null);
		}
		if(isset($pp['gpu']) && is_array($pp['gpu']))
		{
			$model = self::str(isset($pp['gpu']['model']) ? $pp['gpu']['model'] : null);
			$device_id = self::str(isset($pp['gpu']['device-id']) ? $pp['gpu']['device-id'] : null);
			if($model !== null || $device_id !== null)
			{
				$s['gpu'][] = array('model' => $model, 'device_id' => $device_id);
			}
		}
		if(isset($pp['motherboard']) && is_array($pp['motherboard']))
		{
			$s['motherboard']['model'] = self::str(isset($pp['motherboard']['identifier']) ? $pp['motherboard']['identifier'] : null);
		}
		if(isset($pp['memory']) && is_array($pp['memory']) && isset($pp['memory']['capacity']) && is_numeric($pp['memory']['capacity']) && $pp['memory']['capacity'] > 0)
		{
			$s['memory']['total_mb'] = (int)round($pp['memory']['capacity']);
		}
		return $s;
	}
	public static function from_hardware_string($h)
	{
		// The 'Type: value, ...' string of phodevi::system_hardware(), for clients that send no properties
		$s = self::empty_summary();
		$c = pts_result_file_analyzer::system_component_string_to_array((string)$h);
		if(isset($c['Processor']))
		{
			$s['cpu']['model'] = self::str($c['Processor']);
		}
		if(isset($c['Graphics']) && ($g = self::str($c['Graphics'])) !== null)
		{
			$s['gpu'][] = array('model' => $g, 'device_id' => null);
		}
		if(isset($c['Motherboard']))
		{
			$s['motherboard']['model'] = self::str($c['Motherboard']);
		}
		if(isset($c['Memory']))
		{
			$s['memory']['total_mb'] = self::memory_string_to_mb($c['Memory']);
		}
		return $s;
	}
	public static function from_client_report($pp, $h)
	{
		// The properties first; any value they lack is taken from the hardware string
		$s = self::from_properties($pp);
		$f = self::from_hardware_string($h);
		if($s['cpu']['model'] === null)
		{
			$s['cpu']['model'] = $f['cpu']['model'];
		}
		if(empty($s['gpu']) || (count($s['gpu']) == 1 && $s['gpu'][0]['model'] === null && !empty($f['gpu'])))
		{
			$s['gpu'] = empty($s['gpu']) ? $f['gpu'] : array(array('model' => $f['gpu'][0]['model'], 'device_id' => $s['gpu'][0]['device_id']));
		}
		if($s['motherboard']['model'] === null)
		{
			$s['motherboard']['model'] = $f['motherboard']['model'];
		}
		if($s['memory']['total_mb'] === null)
		{
			$s['memory']['total_mb'] = $f['memory']['total_mb'];
		}
		return $s;
	}
	public static function local()
	{
		// This machine's own summary
		return self::from_client_report(phodevi::read_all_properties(), phodevi::system_hardware(true));
	}
	public static function memory_string_to_mb($memory)
	{
		// "2 x 16GB DDR5-6000MT/s ...", "32GB", "16384MB": total in MB, null if no size is found
		$memory = (string)$memory;
		$units = array('MB' => 1, 'GB' => 1024, 'TB' => 1048576);
		if(preg_match('/(\d+)\s*x\s*(\d+(?:\.\d+)?)\s*(MB|GB|TB)/i', $memory, $m))
		{
			return (int)round($m[1] * $m[2] * $units[strtoupper($m[3])]);
		}
		if(preg_match('/(\d+(?:\.\d+)?)\s*(MB|GB|TB)/i', $memory, $m))
		{
			return (int)round($m[1] * $units[strtoupper($m[2])]);
		}
		return null;
	}
	public static function display_value($summary, $key)
	{
		// One tracked key as a short display string (null if undetected)
		if(!is_array($summary))
		{
			return null;
		}
		switch($key)
		{
			case 'cpu':
			case 'motherboard':
				return isset($summary[$key]['model']) ? $summary[$key]['model'] : null;
			case 'gpu':
				return isset($summary['gpu'][0]['model']) ? $summary['gpu'][0]['model'] : (isset($summary['gpu'][0]['device_id']) ? $summary['gpu'][0]['device_id'] : null);
			case 'memory':
				return isset($summary['memory']['total_mb']) && $summary['memory']['total_mb'] > 0 ? self::memory_display($summary['memory']['total_mb']) : null;
		}
		return null;
	}
	public static function memory_display($mb)
	{
		return $mb >= 1024 ? round($mb / 1024, ($mb % 1024 == 0 ? 0 : 1)) . ' GB' : $mb . ' MB';
	}

	// ---- drift comparison (normalisation lives here only)

	public static function drift_normalise($value)
	{
		// Lower case, collapsed whitespace, without a clock suffix, a core/thread count, a BIOS version, a GPU clock pair or (R)/(TM)
		if($value === null)
		{
			return null;
		}
		$v = strtolower((string)$value);
		$v = str_replace(array('(r)', '(tm)'), '', $v);
		$v = preg_replace('/\(\s*\d+\s*cores?\s*\/\s*\d+\s*threads?\s*\)/', '', $v);
		$v = preg_replace('/\(\s*\d+\s*cores?\s*\)/', '', $v);
		$v = preg_replace('/@\s*[\d.]+\s*[gm]hz/', '', $v);
		$v = preg_replace('/\([^()]*bios[^()]*\)/', '', $v);
		$v = preg_replace('/\(\s*\d+\s*\/\s*\d+\s*mhz\s*\)/', '', $v);
		$v = trim(preg_replace('/\s+/', ' ', $v));
		return $v === '' ? null : $v;
	}
	public static function drift_key_differs($baseline, $detected, $key)
	{
		// True only when BOTH sides have a value for the key and they differ; an empty or undetected value never trips
		if(!is_array($baseline) || !is_array($detected))
		{
			return false;
		}
		switch($key)
		{
			case 'memory':
				$a = isset($baseline['memory']['total_mb']) ? (int)$baseline['memory']['total_mb'] : 0;
				$b = isset($detected['memory']['total_mb']) ? (int)$detected['memory']['total_mb'] : 0;
				if($a <= 0 || $b <= 0)
				{
					return false;
				}
				return abs($a - $b) / max($a, $b) > self::MEMORY_TOLERANCE;
			case 'gpu':
				$ga = isset($baseline['gpu'][0]) && is_array($baseline['gpu'][0]) ? $baseline['gpu'][0] : array();
				$gb = isset($detected['gpu'][0]) && is_array($detected['gpu'][0]) ? $detected['gpu'][0] : array();
				$ida = isset($ga['device_id']) ? self::drift_normalise($ga['device_id']) : null;
				$idb = isset($gb['device_id']) ? self::drift_normalise($gb['device_id']) : null;
				if($ida !== null && $idb !== null && $ida !== $idb)
				{
					return true;
				}
				$ma = isset($ga['model']) ? self::drift_normalise($ga['model']) : null;
				$mb = isset($gb['model']) ? self::drift_normalise($gb['model']) : null;
				return $ma !== null && $mb !== null && $ma !== $mb;
			case 'cpu':
			case 'motherboard':
				$a = isset($baseline[$key]['model']) ? self::drift_normalise($baseline[$key]['model']) : null;
				$b = isset($detected[$key]['model']) ? self::drift_normalise($detected[$key]['model']) : null;
				return $a !== null && $b !== null && $a !== $b;
		}
		return false;
	}
	public static function drift_changed_keys($baseline, $detected)
	{
		$changed = array();
		foreach(self::TRACKED as $key)
		{
			if(self::drift_key_differs($baseline, $detected, $key))
			{
				$changed[] = $key;
			}
		}
		return $changed;
	}
	public static function with_key_from($summary, $source, $key)
	{
		// $summary with one tracked key replaced by $source's value
		$summary = is_array($summary) ? $summary : self::empty_summary();
		if(is_array($source) && isset($source[$key]))
		{
			$summary[$key] = $source[$key];
		}
		return $summary;
	}
}

?>
