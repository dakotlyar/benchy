<?php

/*
	Phoronix Test Suite
	URLs: http://www.phoronix.com, http://www.phoronix-test-suite.com/
	Copyright (C) 2008 - 2021, Phoronix Media
	Copyright (C) 2008 - 2021, Michael Larabel

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

class pts_user_io
{
	public static $readline_completion_possibilities = null;
	// Set once standard input is closed (EOF, e.g. </dev/null or a non-tty pipe): no answer can be read any more, so the prompt
	// helpers stop asking instead of re-prompting forever on the empty string fgets()/readline() keep returning
	private static $input_closed = false;
	private static $reads_after_close = 0;

	public static function input_closed()
	{
		return self::$input_closed;
	}
	protected static function note_input_closed()
	{
		if(!self::$input_closed)
		{
			self::$input_closed = true;
			echo PHP_EOL . 'No more input (standard input is closed); not prompting further.' . PHP_EOL;
		}
	}
	protected static function abort_no_input()
	{
		echo PHP_EOL . 'An answer is required but standard input is closed; exiting.' . PHP_EOL;
		exit(1);
	}
	public static function read_user_input($prompt = null, $completion_handler_elements = null, $question_id = null)
	{
		if(pts_json_output::is_active())
		{
			// JSON mode never reads standard input
			return trim((string)pts_json_output::answer($question_id, 'text', $prompt));
		}
		if(self::$input_closed)
		{
			// Backstop for callers that loop on read_user_input() themselves until they get an answer they like (prompts that
			// have a safe default do not read once input is closed, so they never count here)
			if(++self::$reads_after_close > 50)
			{
				self::abort_no_input();
			}
			echo $prompt;
			return '';
		}
		if(!empty($completion_handler_elements) && is_array($completion_handler_elements) && function_exists('readline') && function_exists('readline_completion_function'))
		{
			pts_user_io::$readline_completion_possibilities = $completion_handler_elements;
			readline_completion_function(array('pts_user_io', 'readline_completion_handler'));
			$input = readline($prompt);
		}
		else
		{
			echo $prompt;
			$input = fgets(STDIN);
		}

		if($input === false)
		{
			self::note_input_closed();
			return '';
		}

		return trim($input);
	}
	public static function strip_ansi_escape_sequences($output)
	{
		if(function_exists('preg_replace'))
		{
			$output = preg_replace('/\x1b(\[|\(|\))[;?0-9]*[0-9A-Za-z]/', '', $output);
			$output = preg_replace('/\x1b(\[|\(|\))[;?0-9]*[0-9A-Za-z]/', '', $output);
			$output = preg_replace('/[\x03|\x1a]/', '', $output);
		}

		return $output;
	}
	public static function prompt_user_input($question, $allow_null = false, $password = false, $line_start = null, $question_id = null)
	{
		if(pts_json_output::is_active())
		{
			return (string)pts_json_output::answer($question_id, 'text', $question);
		}
		do
		{
			echo PHP_EOL . $line_start . pts_client::cli_just_bold($question . ': ');
			if($password && pts_client::executable_in_path('stty') && !phodevi::is_windows())
			{
				system('stty -echo');
			}
			$answer = self::$input_closed && $allow_null ? '' : pts_user_io::read_user_input();
			if($password && pts_client::executable_in_path('stty') && !phodevi::is_windows())
			{
				system('stty echo');
			}
			if(self::$input_closed && !$allow_null && empty($answer))
			{
				self::abort_no_input();
			}
		}
		while(!$allow_null && empty($answer));

		return $answer;
	}
	public static function prompt_numeric_input($question, $allow_null = false, $question_id = null)
	{
		if(pts_json_output::is_active())
		{
			$answer = pts_json_output::answer($question_id, 'number', $question);
			if(!is_numeric($answer))
			{
				pts_json_output::fail('usage', 'The answer to ' . $question_id . ' must be a number.', array('id' => $question_id), pts_json_output::EXIT_USAGE);
			}
			return $answer;
		}
		do
		{
			echo PHP_EOL . pts_client::cli_just_bold($question . ': ');
			$answer = pts_user_io::read_user_input();
			if(self::$input_closed && !is_numeric($answer))
			{
				self::abort_no_input();
			}
		}
		while((!$allow_null && empty($answer)) || !is_numeric($answer));

		return $answer;
	}
	public static function display_text_list($list_items, $line_start = '- ')
	{
		$list = null;

		foreach($list_items as &$item)
		{
			$list .= $line_start . $item . PHP_EOL;
		}

		return $list;
	}
	public static function display_packed_list(&$list)
	{
		$terminal_width = pts_client::terminal_width();
		$longest_item = 0;
		foreach($list as &$item)
		{
			if(isset($item[$longest_item + 1]))
			{
				$longest_item = strlen($item);
			}
		}

		$items_per_line = floor($terminal_width / ($longest_item + 1));
		$i = 0;
		foreach($list as &$item)
		{
			echo $item . str_repeat(' ', $longest_item - strlen($item) + 1);
			$i++;
			if($i % $items_per_line == 0)
			{
				echo PHP_EOL;
			}
		}
	}
	public static function display_text_table(&$table, $prepend_to_lines = null, $extra_width_to_column = 0, $min_width = 0, $border = false, $bold_row = -1, $color_rows = false)
	{
		$column_widths = array();
		$formatted_table = null;
		$longest_line = 0;

		for($r = 0; $r < count($table); $r++)
		{
			for($c = 0; is_array($table[$r]) && $c < count($table[$r]); $c++)
			{
				if(!isset($column_widths[$c]) || isset($table[$r][$c][$column_widths[$c]]))
				{
					$column_widths[$c] = $table[$r][$c] == null ? 0 : strlen($table[$r][$c]);
				}
			}
		}

		for($r = 0; $r < count($table); $r++)
		{
			$line = null;
			for($c = 0; is_array($table[$r]) && $c < count($table[$r]); $c++)
			{
				if($border)
				{
					$line .= '| ';
				}

				$line .= $table[$r][$c];

				$m = (max($min_width, 1 + $extra_width_to_column + $column_widths[$c]) - ($table[$r][$c] != null ? strlen($table[$r][$c]) : 0));
				if($m > 0)
				{
					$line .= str_repeat(' ', $m);
				}
			}
			$line = $prepend_to_lines . $line;
			if($border)
			{
				$line = $line . '|';
			}
			$longest_line = max($longest_line, strlen($line));
			if($color_rows && isset($color_rows[$r]))
			{
				$line = pts_client::cli_colored_text($line, $color_rows[$r], ($r == $bold_row));
			}
			else if($r == $bold_row)
			{
				$line = pts_client::cli_just_bold($line);
			}
			$formatted_table .= ($r == 0 ? '' : PHP_EOL) . $line;
			if($r == 0 && $border)
			{
				$line = null;
				for($c = 0; $c < count($table[$r]); $c++)
				{
					if($border)
					{
						$line .= '| ';
					}

					//$line .= $table[$r][$c];

					$m = (max($min_width, 1 + $extra_width_to_column + $column_widths[$c]));
					$line .= str_repeat('-', $m - 1) . ' ';
				}
				$formatted_table .= PHP_EOL . $line . '|';
			}
		}

		if($border)
		{
			$formatted_table = PHP_EOL. PHP_EOL . $formatted_table . PHP_EOL;
			//$formatted_table = str_repeat('-', $longest_line) . PHP_EOL . $formatted_table . PHP_EOL . str_repeat('-', $longest_line) . PHP_EOL;
		}

		return $formatted_table;
	}
	public static function prompt_bool_input($question, $default = true, $question_id = 'UNKNOWN')
	{
		// Prompt user for yes/no question
		/*if BATCH MODE
		{
			switch($question_id)
			{
				default:
					$auto_answer = 'true';
					break;
			}

			$answer = pts_strings::string_bool($auto_answer);
		}*/
		if($default === true)
		{
			$def = 'Y/n';
		}
		else if($default === false)
		{
			$def = 'y/N';
		}
		else
		{
			$def = 'y/n';
		}

		if(pts_json_output::is_active())
		{
			return pts_json_output::answer($question_id, 'bool', $question, null, (is_bool($default) ? $default : null));
		}
		$question .= ' (' . $def . '): ';

		$answer = -1;
		do
		{
			pts_client::$display->generic_prompt(pts_client::cli_just_bold($question));
			$input = self::$input_closed && is_bool($default) ? '' : strtolower(pts_user_io::read_user_input());
			if(self::$input_closed && $input === '')
			{
				// No answer can come: take a real default, otherwise the safe answer (no)
				$answer = is_bool($default) ? $default : false;
				break;
			}
			switch($input)
			{
				case 'y':
					$answer = true;
					break;
				case 'n':
					$answer = false;
					break;
				case '':
					$answer = $default;
					break;
			}
		}
		while($answer === -1);

		return $answer;
	}
	public static function readline_completion_handler($input)
	{
		$possibilities = array();
		$readline_info = readline_info();
		if(isset($readline_info['end']))
		{
			$input = substr($readline_info['line_buffer'], 0, $readline_info['end']);
		}
		$input_length = strlen($input);

		if(is_array(self::$readline_completion_possibilities))
		{
			foreach(self::$readline_completion_possibilities as $possibility)
			{
				if(!empty($possibility) && substr($possibility, 0, $input_length) == $input)
				{
					$possibilities[] = $possibility;
				}
			}
		}

		//$possibilities[] = '';
		sort($possibilities);
		return $possibilities;
	}
	public static function prompt_text_menu($user_string, $options_r, $allow_multi_select = false, $return_index = false, $line_prefix = null, $question_id = null)
	{
		$option_count = count($options_r);

		if($option_count == 1)
		{
			if($allow_multi_select)
			{
				return $return_index ? array_keys($options_r) : $options_r;
			}
			else
			{
				return $return_index ? pts_arrays::last_element(array_keys($options_r)) : array_pop($options_r);
			}
		}

		if(pts_json_output::is_active())
		{
			// The answer is an option key (several, comma separated, for a multi-select menu)
			$answer = (string)pts_json_output::answer($question_id, ($allow_multi_select ? 'multi' : 'menu'), $user_string, ($allow_multi_select ? null : $options_r));
			$select = array();
			foreach(($allow_multi_select ? pts_strings::comma_explode($answer) : array($answer)) as $key)
			{
				if(!array_key_exists($key, $options_r))
				{
					pts_json_output::fail('usage', 'Not an option of ' . $question_id . ': ' . $key, array('id' => $question_id, 'options' => array_map('strval', array_keys($options_r))), pts_json_output::EXIT_USAGE);
				}
				$select[] = $return_index ? $key : $options_r[$key];
			}
			return $allow_multi_select ? $select : $select[0];
		}

		$select = array();

		do
		{
			echo PHP_EOL;
			$key_index = array();
			foreach(array_keys($options_r) as $i => $key)
			{
				$key_index[($i + 1)] = $key;
				$line_offset = strlen($line_prefix . ($i + 1) . ': ' . str_repeat(' ', strlen($option_count) - strlen(($i + 1))));
				echo $line_prefix . pts_client::cli_just_bold(($i + 1) . ': ') . str_repeat(' ', strlen($option_count) - strlen(($i + 1))) . str_replace(PHP_EOL, PHP_EOL . str_repeat(' ', $line_offset), $options_r[$key]) . PHP_EOL;
			}
			if($allow_multi_select)
			{
				echo $line_prefix . pts_client::cli_colored_text('** Multiple items can be selected, delimit by a comma. **', 'gray') . PHP_EOL;
			}

			if(function_exists('readline') && function_exists('readline_completion_function'))
			{
				$select_choice = pts_user_io::read_user_input($line_prefix . $user_string . ': ', array_merge($options_r, array_keys($key_index)));
			}
			else
			{
				echo $line_prefix . pts_client::cli_just_bold($user_string . ': ');
				$select_choice = pts_user_io::read_user_input();
			}

			foreach(($allow_multi_select ? pts_strings::comma_explode($select_choice) : array($select_choice)) as $choice)
			{
				$choice_trimmed = pts_strings::trim_spaces($choice);
				if(isset($key_index[($c = $choice)]) || isset($key_index[($c = $choice_trimmed)]))
				{
					$select[] = $key_index[$c];
				}
				else if(in_array(($c = $choice), $options_r) || in_array(($c = $choice_trimmed), $options_r))
				{
					$select[] = array_search($c, $options_r);
				}
				else if($allow_multi_select && strpos($choice, '-') !== false)
				{
					$choice_range = pts_strings::trim_explode('-', $choice);

					if(count($choice_range) == 2 && is_numeric($choice_range[0]) && is_numeric($choice_range[1]) && isset($key_index[$choice_range[0]]) && isset($key_index[$choice_range[1]]))
					{
						for($i = min($choice_range); $i <= max($choice_range); $i++)
						{
							$select[] = $key_index[$i];
						}
					}
				}
			}
		}
		while(!isset($select[0]) && !self::$input_closed);

		if(!isset($select[0]))
		{
			self::abort_no_input();
		}

		if($return_index == false)
		{
			foreach($select as &$index)
			{
				$index = $options_r[$index];
			}
		}

		// Technically implode shouldn't be needed as should just be $select[0] to return
		return $allow_multi_select ? $select : implode(',', $select);
	}
}

?>
