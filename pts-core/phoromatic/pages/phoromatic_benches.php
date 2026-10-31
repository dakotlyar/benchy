<?php

/*
	Benchy
	Hardware test benches: list, bench page, promote, swap / end / demote, drift acknowledgement, component inventory, compare.

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

// Routes: ?benches, ?benches/<BenchID> (/swap, /end, /demote, /add, /reassign, /ack/<SystemID>, /session/<SessionID>), ?benches/promote/<SystemID>,
// ?benches/add/<SystemID> (pick a bench for a system), ?benches/components (/<ComponentID>), ?benches/compare. Everybody logged in can read. Every change is a POST back to the page,
// checked here (Origin, CSRF token, AdminLevel 1-2 re-read from the database) and carried out by phoromatic_bench. GET never changes
// anything. All user-supplied text is escaped on output.

class phoromatic_benches implements pts_webui_interface
{
	protected static $aid = null;
	protected static $can = false;

	public static function page_title()
	{
		return 'Benches';
	}
	public static function page_header()
	{
		return null;
	}
	public static function preload($PAGE)
	{
		return true;
	}
	protected static function h($v)
	{
		return phoromatic_bench::h($v);
	}
	protected static function ago($time)
	{
		if(empty($time))
		{
			return 'never';
		}
		$s = time() - strtotime($time);
		if($s < 90)
		{
			return 'now';
		}
		return pts_strings::format_time($s, 'SECONDS', true, 60) . ' ago';
	}
	protected static function message($outcome)
	{
		if($outcome === null)
		{
			return '';
		}
		return '<p style="' . ($outcome['ok'] ? '' : 'color: red; ') . 'text-align: center;"><strong>' . $outcome['message'] . '</strong></p>';
	}
	protected static function post($name, $default = '')
	{
		return isset($_POST[$name]) && is_string($_POST[$name]) ? $_POST[$name] : $default;
	}
	protected static function form_open($action, $what, $extra = '')
	{
		return '<form action="?' . $action . '" method="post"' . $extra . '>' . write_token_in_form() . '<input type="hidden" name="bench_action" value="' . self::h($what) . '" />';
	}
	protected static function class_options($selected, $only = null)
	{
		$html = '';
		foreach($only !== null ? $only : phoromatic_bench::CLASSES as $c)
		{
			$html .= '<option value="' . $c . '"' . ($c === $selected ? ' selected="selected"' : '') . '>' . $c . '</option>';
		}
		return $html;
	}

	// ---------------------------------------------------------------- POST handling

	protected static function handle_post($P)
	{
		// array(outcome, where to show next) or null when there is nothing to do
		if($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['bench_action']) || !is_string($_POST['bench_action']))
		{
			return null;
		}
		if(!self::$can)
		{
			return array(array('ok' => false, 'message' => 'Only account administrators (levels 1 and 2) can change benches and components; nothing was changed.'), null);
		}
		if(!phoromatic_private_repo_web::origin_ok() || !verify_submission_token())
		{
			return array(array('ok' => false, 'message' => 'The form expired or was not submitted from this site; nothing was changed. Please try again.'), null);
		}
		$actor = isset($_SESSION['UserName']) ? $_SESSION['UserName'] : '?';
		$detail = 'UserID ' . (isset($_SESSION['UserID']) ? $_SESSION['UserID'] : '?');
		$aid = self::$aid;
		$force = self::post('force') === '1';
		if(in_array($_POST['bench_action'], array('swap', 'end', 'demote', 'ack', 'member_add', 'token_create', 'token_revoke', 'ticket_rerun', 'reassign_confirm', 'reassign'), true) && (!isset($P[0]) || !is_numeric($P[0])))
		{
			return array(array('ok' => false, 'message' => 'Bench not found; nothing was changed.'), null);
		}
		$session_opts = array('source' => self::post('source'), 'component_id' => self::post('component_id'), 'class' => self::post('class'), 'label' => self::post('label'),
			'serial' => self::post('serial'), 'config' => self::post('config'), 'notes' => self::post('notes'), 'title' => self::post('title'),
			'extra_component_ids' => isset($_POST['extra_component_id']) && is_array($_POST['extra_component_id']) ? array_values(array_filter($_POST['extra_component_id'], 'is_string')) : array());
		switch($_POST['bench_action'])
		{
			case 'promote':
				$o = phoromatic_bench::promote($aid, isset($P[1]) ? $P[1] : '', $actor, $detail, $session_opts);
				return array($o, $o['ok'] ? array('bench', $o['bench_id']) : null);
			case 'swap':
				return array(phoromatic_bench::start_session($aid, $P[0], $actor, $detail, $session_opts, $force), null);
			case 'end':
				return array(phoromatic_bench::end_session($aid, $P[0], $actor, $detail, $force), null);
			case 'demote':
				$o = phoromatic_bench::demote($aid, $P[0], self::post('system_id'), $actor, $detail, $force);
				return array($o, $o['ok'] ? (phoromatic_bench::members($aid, $P[0]) ? array('bench', $P[0]) : array('list')) : null);
			case 'token_create':
				$t = phoromatic_bench_token::create($aid, $P[0], self::post('label'), self::post('system_id') === 'any' ? null : self::post('system_id'), 'user', $actor, $detail);
				if(isset($t['error']))
				{
					return array(array('ok' => false, 'message' => self::h($t['error'])), null);
				}
				return array(array('ok' => true, 'message' => 'Bench token BT' . (int)$t['id'] . ' (' . self::h($t['label']) . ', ' . ($t['system'] === null ? 'any system of this bench' : 'system ' . self::h($t['system']) . ' only') . '). Copy it now; it is not shown again:<br /><code class="bench_new_token">' . self::h($t['token']) . '</code><br />On the bench run <code>benchy bench-login</code> and paste it.'), array('bench', $P[0]));
			case 'token_revoke':
				$t = phoromatic_bench_token::revoke($aid, self::post('token_id'), 'user', $actor, $detail, $P[0]);
				return array(isset($t['error']) ? array('ok' => false, 'message' => self::h($t['error'])) : array('ok' => true, 'message' => 'Bench token BT' . (int)$t['revoked'] . ' revoked.'), array('bench', $P[0]));
			case 'ticket_rerun':
				$o = phoromatic_bench::request_ticket_rerun($aid, $P[0], self::post('ticket_id'), $actor, $detail);
				return array($o, array('bench', $P[0]));
			case 'reassign_confirm':
				return array(null, array('reassign_confirm', $P[0]));
			case 'reassign':
				$ids = isset($_POST['upload_ids']) && is_array($_POST['upload_ids']) ? $_POST['upload_ids'] : array();
				$target = self::post('target');
				$o = phoromatic_bench::reassign_results($aid, $P[0], $ids, $target === 'none' ? null : (int)$target, $actor, $detail);
				return array($o, $o['ok'] ? array('reassign', $P[0]) : null);
			case 'member_add':
				$o = phoromatic_bench::add_member($aid, $P[0], self::post('system_id'), $actor, $detail);
				return array($o, $o['ok'] ? array('bench', $P[0]) : null);
			case 'ack':
				$sys = isset($P[2]) ? $P[2] : '';
				$kind = self::post('expect_kind');
				if(in_array($kind, array('awaiting', 'drift'), true) && self::post('confirm_checked') !== '1')
				{
					return array(array('ok' => false, 'message' => 'Tick the confirmation first; nothing was changed.'), null);
				}
				$o = array('expect_kind' => $kind, 'note' => self::post('note'));
				if(isset($_POST['config']))
				{
					$o['config'] = self::post('config');
				}
				$out = phoromatic_bench::ack($aid, $P[0], $sys, $actor, $detail, $o);
				return array($out, $out['ok'] ? array('bench', $P[0]) : null);
			case 'component_create':
				return array(phoromatic_bench::component_create($aid, $_POST, $actor, $detail), null);
			case 'component_edit':
				$o = phoromatic_bench::component_edit($aid, self::post('component_id'), $_POST, $actor, $detail, self::post('rename_confirmed') === '1');
				return array($o, isset($o['needs_confirm']) ? array('rename_confirm', $o) : ($o['ok'] ? array('components') : null));
			case 'component_retire':
				return array(phoromatic_bench::component_retire($aid, self::post('component_id'), $actor, $detail), array('components'));
		}
		return array(array('ok' => false, 'message' => 'Unknown action; nothing was changed.'), null);
	}

	// ---------------------------------------------------------------- page

	public static function render_page_process($PATH)
	{
		self::$aid = $_SESSION['AccountID'];
		self::$can = phoromatic_system_admin::admin_allowed();
		$P = array();
		foreach($PATH as $p)
		{
			if(($x = strpos($p, '&')) !== false)
			{
				$p = substr($p, 0, $x);
			}
			$P[] = $p;
		}
		while(!empty($P) && end($P) === '')
		{
			array_pop($P);
		}

		$main = '';
		$posted = self::handle_post($P);
		if($posted !== null)
		{
			list($outcome, $next) = $posted;
			$main .= self::message($outcome);
			if($next !== null && $next[0] == 'bench')
			{
				$P = array((string)$next[1]);
			}
			else if($next !== null && $next[0] == 'list')
			{
				$P = array();
			}
			else if($next !== null && $next[0] == 'components')
			{
				$P = array('components');
			}
			else if($next !== null && $next[0] == 'reassign_confirm' && ($b = phoromatic_bench::bench(self::$aid, $next[1])))
			{
				$main .= self::reassign_confirm_page($b);
				$P = null;
			}
			else if($next !== null && $next[0] == 'reassign')
			{
				$P = array((string)$next[1], 'reassign');
			}
			else if($next !== null && $next[0] == 'rename_confirm')
			{
				$main = self::rename_confirm_page($next[1]);
				$P = null;
			}
			else if($outcome['ok'] && isset($P[0]) && is_numeric($P[0]))
			{
				$P = array($P[0]);
			}
		}

		if($P !== null)
		{
			$first = isset($P[0]) ? $P[0] : '';
			if($first === '')
			{
				$main .= self::list_page();
			}
			else if($first === 'promote')
			{
				$main .= self::promote_page(isset($P[1]) ? $P[1] : '');
			}
			else if($first === 'add')
			{
				$main .= self::pick_bench_page(isset($P[1]) ? $P[1] : '');
			}
			else if($first === 'components')
			{
				$main .= isset($P[1]) && is_numeric($P[1]) ? self::component_edit_page($P[1]) : self::components_page();
			}
			else if($first === 'compare')
			{
				$main .= self::compare_page();
			}
			else if(is_numeric($first) && ($bench = phoromatic_bench::bench(self::$aid, $first)))
			{
				$sub = isset($P[1]) ? $P[1] : '';
				if($sub === 'swap' || $sub === 'end' || $sub === 'demote')
				{
					$main .= self::session_change_page($bench, $sub);
				}
				else if($sub === 'reassign')
				{
					$main .= self::reassign_page($bench);
				}
				else if($sub === 'add')
				{
					$main .= self::add_member_page($bench, isset($_GET['system']) && is_string($_GET['system']) ? $_GET['system'] : null);
				}
				else if($sub === 'ack')
				{
					$main .= self::ack_page($bench, isset($P[2]) ? $P[2] : '');
				}
				else if($sub === 'session' && isset($P[2]))
				{
					$main .= self::session_page($bench, $P[2]);
				}
				else
				{
					$main .= self::bench_page($bench);
				}
			}
			else
			{
				$main .= '<h1>Bench not found</h1><p><a href="?benches">All benches</a></p>';
			}
		}

		echo phoromatic_webui_header_logged_in();
		echo phoromatic_webui_main($main, phoromatic_webui_right_panel_logged_in(self::right_panel()));
		echo phoromatic_webui_footer();
	}
	protected static function right_panel()
	{
		$html = '<h3><a href="?benches">Benches</a></h3><ul>';
		foreach(phoromatic_bench::benches(self::$aid) as $b)
		{
			$html .= '<li><a href="?benches/' . (int)$b['BenchID'] . '">' . self::h($b['Title']) . '</a></li>';
		}
		$html .= '</ul><p><a href="?benches/components">Components</a><br /><a href="?benches/compare">Compare by component</a></p>';
		return $html;
	}

	// ---------------------------------------------------------------- list

	protected static function bench_state($bench, $open, $members)
	{
		// array(short state, is hold) for a bench
		if(!$open)
		{
			return array('needs a component', true);
		}
		foreach($members as $m)
		{
			$d = phoromatic_bench::drift_state($m['BenchDrift']);
			if($d && $d['kind'] == 'awaiting')
			{
				return array('on hold: awaiting swap', true);
			}
			if($d && $d['kind'] == 'drift')
			{
				return array('on hold: ' . phoromatic_bench::class_names_text($open['Classes']) . ' changed', true);
			}
			if($d && $d['kind'] == 'notice')
			{
				$keys = array();
				foreach(phoromatic_bench::notice_fields($d) as $f)
				{
					$keys[] = phoromatic_bench::class_name($f['key']);
				}
				return array('notice: ' . implode(', ', $keys) . ' changed', false);
			}
		}
		return array('testing', false);
	}
	protected static function list_page()
	{
		$html = '<h1>Test Benches</h1><p>A bench tests one component at a time. Results are filed under the session that was open when the task was issued, and graphs label them by component. <a href="?benches/components">Component inventory</a> &middot; <a href="?benches/compare">Compare by component</a></p>';
		$html .= '<p>To create a bench, open a system\'s page and choose <em>Promote to bench</em>' . (self::$can ? '' : ' (account administrators only)') . '. A dual-boot bench has one system per OS install: add the second one with <em>Add to bench</em>.</p>';
		$benches = phoromatic_bench::benches(self::$aid, true);
		if(empty($benches))
		{
			return $html . '<p><em>No benches yet.</em></p>';
		}
		$html .= '<table class="bench_list" style="width: 100%;"><tr><th>Bench</th><th>Systems</th><th>Component under test</th><th>State</th><th>Last check-in</th></tr>';
		foreach($benches as $b)
		{
			$members = phoromatic_bench::members(self::$aid, $b['BenchID']);
			$open = phoromatic_bench::open_session(self::$aid, $b['BenchID']);
			list($state, $hold) = $b['RetiredOn'] !== null ? array('retired ' . $b['RetiredOn'], false) : self::bench_state($b, $open, $members);
			$systems = array();
			$last = null;
			foreach($members as $m)
			{
				$family = phoromatic_bench::os_family($m);
				$systems[] = '<a href="?systems/' . self::h($m['SystemID']) . '">' . self::h($m['Title']) . '</a>' . ($family !== null ? ' (' . self::h($family) . ')' : '');
				$last = max($last, $m['LastCommunication']);
			}
			$html .= '<tr><td><a href="?benches/' . (int)$b['BenchID'] . '">' . self::h($b['Title']) . '</a> <small>B' . (int)$b['BenchID'] . '</small></td><td>' . implode(', ', $systems) . '</td><td>' .
				($open ? self::h($open['Label']) . ' <small>(' . self::h(implode(' + ', $open['Classes'])) . ', S' . (int)$open['SessionID'] . ')</small>' : '&mdash;') . '</td><td>' . ($hold ? '<strong style="color: red;">' . self::h($state) . '</strong>' : self::h($state)) . '</td><td>' . self::h(self::ago($last)) . '</td></tr>';
		}
		return $html . '</table>';
	}

	// ---------------------------------------------------------------- bench page

	protected static function member_state($m, $open)
	{
		// A member's own hold or notice state, as short text
		if(!$open)
		{
			return 'needs a component';
		}
		$d = phoromatic_bench::drift_state($m['BenchDrift']);
		if($d && $d['kind'] == 'awaiting')
		{
			return 'on hold: awaiting swap';
		}
		if($d && $d['kind'] == 'drift')
		{
			return 'on hold: ' . phoromatic_bench::class_names_text($open['Classes']) . ' changed';
		}
		if($d && $d['kind'] == 'notice')
		{
			$keys = array();
			foreach(phoromatic_bench::notice_fields($d) as $f)
			{
				$keys[] = phoromatic_bench::class_name($f['key']);
			}
			return 'notice: ' . implode(', ', $keys) . ' changed';
		}
		return 'testing';
	}
	protected static function systems_strip($bench, $members)
	{
		// One row per member: OS, last check-in, its own state, its own baseline of the class under test, its outstanding task
		$id = (int)$bench['BenchID'];
		if(empty($members))
		{
			return '<p><strong>Systems in this bench:</strong> <em>none</em></p>';
		}
		$open = $bench['RetiredOn'] === null ? phoromatic_bench::open_session(self::$aid, $id) : false;
		$class = $open ? phoromatic_bench::class_names_text($open['Classes']) : null;
		$remove = self::$can && $bench['RetiredOn'] === null;
		$html = '<h2>Systems in this bench</h2><table class="bench_members" style="width: 100%;"><tr><th>System</th><th>OS</th><th>Last check-in</th><th>State</th>' . ($open ? '<th>' . self::h($class) . ' baseline (S' . (int)$open['SessionID'] . ')</th>' : '') . '<th>Outstanding task</th>' . ($remove ? '<th></th>' : '') . '</tr>';
		foreach($members as $m)
		{
			$base = '';
			if($open)
			{
				$b = phoromatic_bench::baseline(self::$aid, $open['SessionID'], $m['SystemID']);
				if($b && $b['AwaitingFromJSON'] !== null)
				{
					$base = 'awaiting swap from ' . self::h(phoromatic_bench::values_text(json_decode($b['AwaitingFromJSON'], true), $open['Classes']));
				}
				else if($b && $b['BaselineJSON'] !== null)
				{
					$v = phoromatic_bench::values_text(json_decode($b['BaselineJSON'], true), $open['Classes']);
					$base = $v !== null ? self::h($v) : '<em>not detectable</em>';
				}
				else
				{
					$base = '<em>at its first check-in</em>';
				}
				$base = '<td>' . $base . '</td>';
			}
			$task = '&mdash;';
			if($m['BenchTaskSession'] !== null)
			{
				$task = (!empty($m['CurrentProcessSchedule']) ? self::h(phoromatic_server::schedule_id_to_name($m['CurrentProcessSchedule'])) : (!empty($m['CurrentProcessTicket']) ? self::h(phoromatic_server::ticket_id_to_name($m['CurrentProcessTicket'])) : 'a task')) . ' <small>(S' . (int)$m['BenchTaskSession'] . ', issued ' . self::h($m['BenchTaskIssuedOn']) . ')</small>';
			}
			$os = phoromatic_bench::os_name($m);
			$html .= '<tr><td><a href="?systems/' . self::h($m['SystemID']) . '">' . self::h($m['Title']) . '</a> <small>' . self::h($m['SystemID']) . '</small></td><td>' . ($os !== null ? self::h($os) : '&mdash;') . '</td><td>' . self::h(self::ago($m['LastCommunication'])) . '</td><td>' . self::h(self::member_state($m, $open)) . '</td>' . $base . '<td>' . $task . '</td>' .
				($remove ? '<td><a href="?benches/' . $id . '/demote&amp;system=' . self::h($m['SystemID']) . '">Remove from bench&hellip;</a></td>' : '') . '</tr>';
		}
		return $html . '</table>';
	}
	protected static function drift_banners($bench, $open, $members)
	{
		$html = '';
		$id = (int)$bench['BenchID'];
		if($bench['RetiredOn'] !== null)
		{
			return '<div class="bench_banner"><h2>Retired bench</h2><p>Retired ' . self::h($bench['RetiredOn']) . ' by ' . self::h($bench['RetiredBy']) . '. Its sessions and results are kept.</p></div>';
		}
		if(!$open)
		{
			return '<div class="bench_banner" style="border: 2px solid #c00; padding: 4px 10px;"><h2 style="color: #c00;">Needs a component: tests are on hold</h2><p>No session is open, so ' . self::h($bench['Title']) . ' runs no tests.' .
				(self::$can ? ' <a href="?benches/' . $id . '/swap">Declare the component under test&hellip;</a>' : '') . '</p></div>';
		}
		foreach($members as $m)
		{
			$d = phoromatic_bench::drift_state($m['BenchDrift']);
			if(!$d)
			{
				continue;
			}
			$sys = self::h($m['Title']);
			$ack = self::$can ? '<a href="?benches/' . $id . '/ack/' . self::h($m['SystemID']) . '">' : null;
			if($d['kind'] == 'awaiting')
			{
				// one entry per class still waiting: "CPU X, Motherboard Y"
				$still = array();
				foreach($d['fields'] as $f)
				{
					$still[] = (count($d['fields']) > 1 ? phoromatic_bench::class_name($f['key']) . ' ' : '') . ($f['detected'] !== null ? $f['detected'] : $f['baseline']);
				}
				$waiting = array_map(function($f) { return phoromatic_bench::class_name($f['key']); }, $d['fields']);
				$html .= '<div class="bench_banner" style="border: 2px solid #c60; padding: 4px 10px;"><h2 style="color: #c60;">Waiting for the swap: tests are on hold</h2><p>Session S' . (int)$open['SessionID'] . ' (' . self::h($open['Label']) . ') started ' . self::h($open['StartedOn']) . '. ' . $sys . ' still reports ' . self::h(implode(', ', $still)) . '.</p><p>Power down and swap the ' . self::h(implode(' and ', $waiting)) . '; tests start by themselves once the new part is detected.' . (in_array('memory', $open['Classes'], true) ? ' Memory is told apart by its size only: a kit of the same size needs &quot;Use current hardware&quot;.' : '') .
					($ack ? ' ' . $ack . 'Use current hardware&hellip;</a> <small>(only if detection cannot tell the two parts apart, e.g. two samples of one model)</small>' : '') . '</p></div>';
			}
			else if($d['kind'] == 'drift')
			{
				$html .= '<div class="bench_banner" style="border: 2px solid #c00; padding: 4px 10px;"><h2 style="color: #c00;">' . self::h(phoromatic_bench::class_names_text($open['Classes'])) . ' changed on ' . $sys . ' since this session\'s baseline: tests are on hold</h2><p>Seen ' . self::h($d['since']) . '. ' .
					($ack ? $ack . 'Review&hellip;</a>' : 'An account administrator needs to review it.') . '</p>' . self::drift_table($d, $m, $open) . '</div>';
			}
			else if($d['kind'] == 'notice')
			{
				$parts = array();
				foreach(phoromatic_bench::notice_fields($d) as $f)
				{
					$parts[] = self::h(phoromatic_bench::class_name($f['key'])) . ' ' . self::h($f['baseline']) . ' &rarr; ' . self::h($f['detected']);
				}
				$html .= '<div class="bench_banner" style="border: 2px solid #888; padding: 4px 10px;"><h2>Constant part changed on ' . $sys . '</h2><p>' . implode('; ', $parts) . ' since S' . (int)$open['SessionID'] . '\'s baseline (seen ' . self::h($d['since']) . '). Tests keep running; their results are marked &quot;constant part differed&quot;.' .
					($ack ? ' ' . $ack . 'Update baseline and constant parts&hellip;</a> or put the old part back (the notice clears itself).' : '') . '</p></div>';
			}
		}
		return $html;
	}
	protected static function drift_table($d, $m, $open)
	{
		// Baseline vs detected for every tracked part; the stored drift JSON, not a recomputation from the 5-minute-stale properties
		$base_row = phoromatic_bench::baseline(self::$aid, $open['SessionID'], $m['SystemID']);
		$baseline = $base_row && $base_row['BaselineJSON'] !== null ? json_decode($base_row['BaselineJSON'], true) : ($base_row ? json_decode((string)$base_row['AwaitingFromJSON'], true) : null);
		$detected = isset($d['detected']) ? $d['detected'] : null;
		$changed = array();
		foreach($d['fields'] as $f)
		{
			$changed[$f['key']] = !empty($f['holds']) ? 'changed: holds' : 'changed: notice';
		}
		$html = '<table><tr><th>Part</th><th>' . self::h($m['Title']) . ' baseline (S' . (int)$open['SessionID'] . ($base_row && $base_row['BaselineOn'] ? ', ' . self::h($base_row['BaselineOn']) : '') . ')</th><th>Detected now</th><th></th></tr>';
		foreach(pts_hardware_summary::TRACKED as $key)
		{
			$html .= '<tr><td>' . self::h(phoromatic_bench::class_name($key)) . '</td><td>' . self::h(pts_hardware_summary::display_value($baseline, $key)) . '</td><td>' . self::h(pts_hardware_summary::display_value($detected, $key)) . '</td><td>' .
				(isset($changed[$key]) ? '<strong>' . $changed[$key] . '</strong>' : 'same') . '</td></tr>';
		}
		return $html . '</table><p><small>Undetected values never trip anything; storage is not tracked; memory counts only beyond &plusmn;5%.</small></p>';
	}
	protected static function bench_page($bench)
	{
		$id = (int)$bench['BenchID'];
		$members = phoromatic_bench::members(self::$aid, $id);
		$open = phoromatic_bench::open_session(self::$aid, $id);
		$sessions = phoromatic_bench::sessions(self::$aid, $id);
		$html = '<h1>' . self::h($bench['Title']) . ' <small>B' . $id . '</small></h1>' . self::systems_strip($bench, $members);
		$html .= self::drift_banners($bench, $open, $members);
		if($open)
		{
			$count = 0;
			foreach($sessions as $s)
			{
				if($s['SessionID'] == $open['SessionID'])
				{
					$count = (int)$s['TaskResults'] + (int)$s['AdhocResults'];
				}
			}
			$html .= '<div class="pts_phoromatic_info_box_area"><ul><li><h1>Component under test</h1></li><li><strong style="font-size: 130%;">' . self::h($open['Label']) . '</strong> <em>' . self::h(implode(' + ', $open['Classes'])) . '</em><br />Session S' . (int)$open['SessionID'] . ' &middot; since ' . self::h($open['StartedOn']) . ' by ' . self::h($open['StartedBy']) . ' &middot; ' . pts_strings::plural_handler($count, 'result') .
				($open['Config'] !== null ? '<br />Constant parts: ' . self::h($open['Config']) : '') . ($open['Notes'] !== null ? '<br /><em>' . nl2br(self::h($open['Notes'])) . '</em>' : '') . '</li></ul></div>';
		}
		if(self::$can && $bench['RetiredOn'] === null)
		{
			$html .= '<p><a href="?benches/' . $id . '/swap">' . ($open ? 'Swap component&hellip;' : 'Declare component&hellip;') . '</a>' . ($open ? ' &middot; <a href="?benches/' . $id . '/end">End session&hellip;</a>' : '') . ' &middot; <a href="?benches/' . $id . '/add">Add a system&hellip;</a> &middot; <a href="?benches/' . $id . '/demote">Remove a system&hellip;</a> &middot; <a href="?benches/' . $id . '/reassign">Reassign results&hellip;</a></p>';
		}

		$html .= '<h2>Session history</h2><table style="width: 100%;"><tr><th>Session</th><th>Component</th><th>Class</th><th>Started</th><th>Ended</th><th>Results</th></tr>';
		foreach($sessions as $s)
		{
			$ended = $s['EndedOn'] === null ? 'open' : self::h(substr($s['EndedOn'], 0, 16)) . ' <small>(' . self::h($s['EndReason']) . ')</small>';
			$results = (int)$s['TaskResults'] . ($s['AdhocResults'] > 0 ? ' + ' . (int)$s['AdhocResults'] . ' ad-hoc' : '');
			$html .= '<tr><td>S' . (int)$s['SessionID'] . '</td><td>' . self::h($s['Label']) . '</td><td>' . self::h(implode(' + ', $s['Classes'])) . '</td><td>' . self::h(substr($s['StartedOn'], 0, 16)) . '</td><td>' . $ended . '</td><td>' .
				($s['TaskResults'] + $s['AdhocResults'] > 0 ? '<a href="?benches/' . $id . '/session/' . (int)$s['SessionID'] . '">' . $results . '</a>' : $results) . '</td></tr>';
		}
		$html .= '</table>';

		$html .= self::tickets_box($bench, $open);
		$html .= self::tokens_box($bench, $members);

		// Latest result per component for each schedule the bench ran
		foreach(phoromatic_result_access::bench_schedules(self::$aid, $id) as $schedule_id => $title)
		{
			$rows = phoromatic_result_access::latest_per_component(self::$aid, array('bench' => $id, 'schedule' => $schedule_id));
			if(empty($rows))
			{
				continue;
			}
			$html .= '<h2>Latest result per component &middot; ' . self::h($title) . '</h2>' . self::compare_table($rows, false);
		}
		return $html;
	}
	protected static function compare_table($rows, $show_bench)
	{
		$show_system = false;
		$per_bench = array();
		foreach($rows as $r)
		{
			$per_bench[$r['BenchID']][$r['SystemID']] = true;
			$show_system = $show_system || count($per_bench[$r['BenchID']]) > 1;
		}
		$form_id = 'cmp' . substr(md5(serialize(array_keys($rows)) . mt_rand()), 0, 6);
		$html = '<form id="' . $form_id . '" onsubmit="var ids = []; var boxes = this.querySelectorAll(\'input[name=bench_compare]:checked\'); for(var i = 0; i < boxes.length; i++) { ids.push(boxes[i].value); } if(ids.length) { window.location.href = \'?result/\' + ids.join(\',\'); } return false;">';
		$html .= '<table style="width: 100%;"><tr><th></th><th>Component</th>' . ($show_bench ? '<th>Bench</th>' : '') . ($show_system ? '<th>System</th>' : '') . '<th>Session</th><th>Uploaded</th><th>Result</th><th>Note</th></tr>';
		foreach($rows as $r)
		{
			$note = array();
			$deviation = !empty($r['BenchDeviation']) ? json_decode($r['BenchDeviation'], true) : array();
			foreach(is_array($deviation) ? $deviation : array() as $d)
			{
				$note[] = phoromatic_bench::class_name(isset($d['key']) ? $d['key'] : '') . ' differed';
			}
			if($r['BenchSessionSource'] === 'upload')
			{
				$note[] = 'ad-hoc';
			}
			$html .= '<tr><td><input type="checkbox" name="bench_compare" value="' . self::h($r['PPRID']) . '" /></td><td>' . self::h($r['Label']) . '</td>' . ($show_bench ? '<td>B' . (int)$r['BenchID'] . ' ' . self::h($r['BenchTitle']) . '</td>' : '') .
				($show_system ? '<td>' . self::h($r['SystemTitle']) . '</td>' : '') . '<td>S' . (int)$r['SessionID'] . '</td><td>' . self::h(substr($r['UploadTime'], 0, 16)) . '</td><td><a href="?result/' . self::h($r['PPRID']) . '">' . self::h($r['Title']) . '</a></td><td>' . self::h(implode(', ', $note)) . '</td></tr>';
		}
		return $html . '</table><p><input type="submit" value="Compare selected" /> <small>Opens the normal result viewer, runs labelled by component.</small></p></form>';
	}
	protected static function session_page($bench, $session_id)
	{
		$s = phoromatic_bench::session(self::$aid, $session_id);
		if(!$s || (int)$s['BenchID'] != (int)$bench['BenchID'])
		{
			return '<h1>Session not found</h1>';
		}
		$html = '<h1>' . self::h($bench['Title']) . ' &middot; S' . (int)$s['SessionID'] . '</h1><p>' . self::h($s['Label']) . ' (' . self::h(implode(' + ', $s['Classes'])) . '), ' . self::h($s['StartedOn']) . ' &ndash; ' . ($s['EndedOn'] !== null ? self::h($s['EndedOn']) : 'open') . '</p>';
		foreach(phoromatic_bench::members(self::$aid, $bench['BenchID']) as $m)
		{
			$b = phoromatic_bench::baseline(self::$aid, $s['SessionID'], $m['SystemID']);
			if($b && $b['StartJSON'] !== null)
			{
				$start = json_decode($b['StartJSON'], true);
				$parts = array();
				foreach(pts_hardware_summary::TRACKED as $key)
				{
					if(($v = pts_hardware_summary::display_value($start, $key)) !== null)
					{
						$parts[] = self::h(phoromatic_bench::class_name($key)) . ': ' . self::h($v);
					}
				}
				$html .= '<p><strong>Start hardware on ' . self::h($m['Title']) . ':</strong> ' . implode(', ', $parts) . '</p>';
			}
		}
		$html .= '<div class="pts_phoromatic_info_box_area"><ul><li><h1>Results</h1></li>';
		foreach(phoromatic_result_access::session_results(self::$aid, $s['SessionID']) as $r)
		{
			$html .= '<a href="?result/' . self::h($r['PPRID']) . '"><li>' . self::h($r['Title']) . ($r['BenchSessionSource'] === 'upload' ? ' <em>(ad-hoc)</em>' : '') . '<br /><table><tr><td>' . self::h($r['Trigger']) . '</td><td>' . self::h($r['UploadTime']) . '</td></tr></table></li></a>';
		}
		return $html . '</ul></div><p><a href="?benches/' . (int)$bench['BenchID'] . '">Back to the bench</a></p>';
	}

	// ---------------------------------------------------------------- swap / end / demote

	protected static function component_choice($detected, $class, $prefill_label, $default_source, $in_stock)
	{
		// The radio group shared by promote and swap: create from detection / create new / pick from inventory / unspecified
		$html = '<p><label><input type="radio" name="source" value="detected"' . ($default_source == 'detected' ? ' checked="checked"' : '') . ' /> Create from detected hardware</label> ';
		$html .= '<label><input type="radio" name="source" value="new"' . ($default_source == 'new' ? ' checked="checked"' : '') . ' /> Create new component</label></p>';
		$html .= '<p><div style="width: 200px; font-weight: bold; float: left;">Class under test:</div><select name="class" id="bench_class" onchange="bench_prefill(this.value);">' . self::class_options($class) . '</select></p>';
		$html .= '<p><div style="width: 200px; font-weight: bold; float: left;">Label (shown in graphs):</div><input type="text" name="label" id="bench_label" maxlength="64" style="width: 400px;" value="' . self::h($prefill_label) . '" /></p>';
		$html .= '<p><div style="width: 200px; font-weight: bold; float: left;">Serial (optional):</div><input type="text" name="serial" maxlength="128" style="width: 400px;" /></p>';
		$detected_map = array();
		foreach(phoromatic_bench::DETECTABLE as $c)
		{
			$detected_map[$c] = (string)pts_hardware_summary::display_value($detected, $c);
		}
		$html .= '<p><small>Detected: ' . self::h(implode(' &middot; ', array_filter($detected_map))) . '</small></p>';
		$html .= '<script type="text/javascript">var bench_detected = ' . json_encode($detected_map, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . '; function bench_prefill(c) { var l = document.getElementById("bench_label"); if(bench_detected[c] !== undefined && bench_detected[c] !== "") { l.value = bench_detected[c].substr(0, 64); } }</script>';
		$html .= '<p><label><input type="radio" name="source" value="inventory"' . ($default_source == 'inventory' ? ' checked="checked"' : '') . (empty($in_stock) ? ' disabled="disabled"' : '') . ' /> Use a component from the inventory</label> <select name="component_id"><option value="">&mdash;</option>';
		foreach($in_stock as $c)
		{
			$html .= '<option value="' . (int)$c['ComponentID'] . '">' . self::h($c['Label']) . ' (' . self::h($c['Class']) . ($c['Serial'] !== null ? ', ' . self::h($c['Serial']) : '') . (!empty($c['Carried']) ? '; under test now, carried over' : '') . ')</option>';
		}
		$html .= '</select></p>';
		$html .= '<p><label><input type="radio" name="source" value="unspecified"' . ($default_source == 'unspecified' ? ' checked="checked"' : '') . ' /> Unspecified baseline (name it later)</label></p>';
		return $html;
	}
	protected static function outstanding_panel($bench, $what)
	{
		// While a task is outstanding: array(html, mode). Mode 'running' refuses, 'force' (the bench is off) needs the Force tick-box,
		// 'free' (the client reported idling after the issue: the run was abandoned) lets the change go ahead with a note
		$outstanding = phoromatic_bench::outstanding(self::$aid, $bench['BenchID']);
		if(empty($outstanding))
		{
			return '';
		}
		$mode = phoromatic_bench::outstanding_mode($outstanding);
		$open = phoromatic_bench::open_session(self::$aid, $bench['BenchID']);
		$rows = '';
		foreach($outstanding as $m)
		{
			$task = !empty($m['CurrentProcessSchedule']) ? self::h(phoromatic_server::schedule_id_to_name($m['CurrentProcessSchedule'])) . ' (schedule)' : (!empty($m['CurrentProcessTicket']) ? self::h(phoromatic_server::ticket_id_to_name($m['CurrentProcessTicket'])) . ' (benchmark ticket)' : 'a schedule or ticket');
			$rows .= '<tr><td><strong>' . self::h($m['Title']) . '</strong></td><td>Outstanding task: ' . $task . '</td></tr><tr><td></td><td>Issued ' . self::h($m['BenchTaskIssuedOn']) . ' &middot; snapshot: session S' . (int)$m['BenchTaskSession'] . '</td></tr><tr><td></td><td>Bench reports: ' . phoromatic_task_html($m['CurrentTask']) .
				($m['TaskPercentComplete'] > 0 ? ' &middot; ' . (int)$m['TaskPercentComplete'] . '%' : '') . ' &middot; last check-in ' . self::h(self::ago($m['LastCommunication'])) . '</td></tr>';
		}
		$session = $open ? 'S' . (int)$open['SessionID'] : 'the session';
		if($mode == 'free')
		{
			return array('<div class="bench_banner" style="border: 2px solid #888; padding: 4px 10px;"><h3>A task was issued but the bench has set it aside</h3><p>The bench reports that it is idling since the task was issued (a crash, a failed upload or a restart), so the change can go ahead. Should a late result of that task still arrive, it is filed under the session it was issued for.</p><table>' . $rows . '</table></div>', $mode);
		}
		$html = '<div class="bench_banner" style="border: 2px solid #c00; padding: 4px 10px;"><h2 style="color: #c00;">Not now: ' . self::h($bench['Title']) . ' has a task outstanding</h2><p>Ending ' . $session . ' now could file a run that is still going under the wrong component. Wait until the run finishes and uploads its result; this then unlocks.</p><table>' . $rows . '</table><p>To stop the run, use Halt Testing on the system page.</p>';
		$html .= '<h3>Force (only when the bench is off)</h3><p>Unlocks when the bench has not checked in for 2 hours. A late upload from the outstanding task still goes to the session it was issued for.</p>';
		return array($html . '</div>', $mode);
	}
	protected static function session_change_page($bench, $what)
	{
		$id = (int)$bench['BenchID'];
		$open = phoromatic_bench::open_session(self::$aid, $id);
		$members = phoromatic_bench::members(self::$aid, $id);
		$titles = array('swap' => ($open ? 'Swap component on ' : 'Declare component on ') . $bench['Title'], 'end' => 'End the session on ' . $bench['Title'], 'demote' => 'Remove a system from ' . $bench['Title']);
		$html = '<h1>' . self::h($titles[$what]) . '</h1>' . self::systems_strip($bench, $members);
		if(!self::$can)
		{
			return $html . '<p>Only account administrators (levels 1 and 2) can do this.</p>';
		}
		if($bench['RetiredOn'] !== null)
		{
			return $html . '<p>This bench is retired.</p>';
		}
		if($what == 'end' && !$open)
		{
			return $html . '<p>No session is open.</p>';
		}
		$force_field = '';
		$panel = self::outstanding_panel($bench, $what);
		if(is_array($panel))
		{
			list($panel_html, $mode) = $panel;
			$html .= $panel_html;
			if($mode == 'running')
			{
				return $html . '<p><input type="checkbox" disabled="disabled" /> The bench is powered off <input type="submit" value="Force" disabled="disabled" /> <em>disabled: the bench is running the task (it checked in within the last 2 hours and is not idling)</em></p><p><a href="?benches/' . $id . '">Back to the bench</a></p>';
			}
			if($mode == 'force')
			{
				$force_field = '<p><label><input type="checkbox" name="force" value="1" /> The bench is powered off; go ahead anyway (audited, force)</label></p>';
			}
		}
		$counts = array();
		foreach(phoromatic_bench::sessions(self::$aid, $id) as $s)
		{
			$counts[$s['SessionID']] = (int)$s['TaskResults'] + (int)$s['AdhocResults'];
		}
		$ending = $open ? '<p><strong>Ending:</strong> S' . (int)$open['SessionID'] . ' &middot; ' . self::h($open['Label']) . ' (' . self::h(implode(' + ', $open['Classes'])) . ') &middot; since ' . self::h($open['StartedOn']) . ' &middot; ' . pts_strings::plural_handler(isset($counts[$open['SessionID']]) ? $counts[$open['SessionID']] : 0, 'result') . ' stay with it</p>' : '';
		if($what == 'end')
		{
			return $html . $ending . self::form_open('benches/' . $id, 'end') . $force_field . '<p>The bench then runs no tests until a new component is declared.</p><p><input type="submit" value="End S' . (int)$open['SessionID'] . '" /> <a href="?benches/' . $id . '">Cancel</a></p></form>';
		}
		if($what == 'demote')
		{
			$html .= '<p>The system becomes an ordinary system again and keeps its results. The bench and its open session stay while it has another system; when its last system leaves, ' . ($open ? 'S' . (int)$open['SessionID'] . ' ends and ' : '') . 'the bench retires. Its sessions, components and result labels stay.</p>';
			$html .= self::form_open('benches/' . $id, 'demote') . $force_field . '<p>System: <select name="system_id">';
			$selected = isset($_GET['system']) && is_string($_GET['system']) ? $_GET['system'] : null;
			foreach($members as $m)
			{
				$html .= '<option value="' . self::h($m['SystemID']) . '"' . ($m['SystemID'] === $selected ? ' selected="selected"' : '') . '>' . self::h($m['Title']) . ' (' . self::h($m['SystemID']) . ')</option>';
			}
			return $html . '</select></p><p><input type="submit" value="Remove from bench" /> <a href="?benches/' . $id . '">Cancel</a></p></form>';
		}

		// swap: prefilled from a system's detected hardware (the drift page links here with &prefill=<SystemID>)
		$prefill_sys = isset($_GET['prefill']) && is_string($_GET['prefill']) ? $_GET['prefill'] : null;
		$source_member = !empty($members) ? $members[0] : null;
		foreach($members as $m)
		{
			if($m['SystemID'] === $prefill_sys)
			{
				$source_member = $m;
			}
		}
		$detected = $source_member ? phoromatic_bench::detected_for_system($source_member) : pts_hardware_summary::empty_summary();
		if($source_member && ($d = phoromatic_bench::drift_state($source_member['BenchDrift'])) && isset($d['detected']))
		{
			$detected = $d['detected'];
		}
		$class = $open ? $open['Class'] : 'cpu';
		$html .= $ending . self::form_open('benches/' . $id, 'swap') . $force_field;
		$in_stock = phoromatic_bench::in_stock_components(self::$aid, $id);
		$html .= self::component_choice($detected, $class, (string)pts_hardware_summary::display_value($detected, $class), $prefill_sys !== null || empty(array_filter($in_stock, function($c) { return empty($c['Carried']); })) ? 'detected' : 'inventory', $in_stock);
		if(!empty($in_stock))
		{
			// A platform swap (CPU + board, say) is one session with up to four components: the part above is the primary, these are the others
			$html .= '<h3>Also under test (optional)</h3><p><small>For a swap of several parts at once, such as a CPU with its board. Inventory parts only; the result label joins them with &quot; + &quot;. At most ' . (phoromatic_bench::MAX_COMPONENTS - 1) . ' more.</small></p>';
			for($n = 1; $n < phoromatic_bench::MAX_COMPONENTS; $n++)
			{
				$html .= '<p><select name="extra_component_id[]"><option value="">&mdash;</option>';
				foreach($in_stock as $c)
				{
					$html .= '<option value="' . (int)$c['ComponentID'] . '">' . self::h($c['Label']) . ' (' . self::h($c['Class']) . (!empty($c['Carried']) ? '; under test now, carried over' : '') . ')</option>';
				}
				$html .= '</select></p>';
			}
		}
		$html .= '<p><div style="width: 200px; font-weight: bold; float: left;">Constant parts:</div><input type="text" name="config" maxlength="500" style="width: 500px;" value="' . self::h($open && $open['Config'] !== null ? $open['Config'] : phoromatic_bench::constant_parts_text($detected, $class)) . '" /></p>';
		$html .= '<p><div style="width: 200px; font-weight: bold; float: left;">Note (optional):</div><textarea name="notes" style="width: 500px;"></textarea></p>';
		$html .= '<h3>What happens</h3><ol>' . ($open ? '<li>S' . (int)$open['SessionID'] . ' ends now and the new session starts, in one step, audited.</li>' : '<li>A new session starts, audited.</li>') .
			'<li>For a CPU, GPU, motherboard or memory, tests stay on hold until the bench reports a part of that class other than the previous one (classes detection cannot see take effect at the next task). Memory is told apart by its size only: after swapping to a kit of the same size, use &quot;Use current hardware&quot; on the bench page.</li>' . self::swap_wait_lines($members) . '<li>Then the detected hardware becomes the new baseline and the schedules run again, including today\'s (a schedule runs once per component, not once per day).</li></ol>';
		return $html . '<p><input type="submit" value="' . ($open ? 'Swap' : 'Start session') . '" /> <a href="?benches/' . $id . '">Cancel</a></p></form>';
	}

	protected static function swap_wait_lines($members)
	{
		// On a multi-system bench each OS install confirms the swap on its own boot
		if(count($members) < 2)
		{
			return '';
		}
		$lines = '';
		foreach($members as $m)
		{
			$os = phoromatic_bench::os_family($m);
			$lines .= '<li>' . self::h($m['Title']) . ($os !== null ? ' (' . self::h($os) . ')' : '') . ' will wait for the swap until it boots and reports the new part.</li>';
		}
		return '<li>Each system of this bench confirms the swap on its own boot:<ul>' . $lines . '</ul></li>';
	}

	// ---------------------------------------------------------------- bench tokens, tickets, reassign (2C, 0.9)

	protected static function tokens_box($bench, $members)
	{
		// Level 1-2 only: the bench's tokens (bench-swap / bench-end from the bench itself), create and revoke
		if(!self::$can)
		{
			return '';
		}
		$id = (int)$bench['BenchID'];
		$html = '<h2>Bench tokens</h2><p>A bench token lets <code>bench-swap</code> and <code>bench-end</code> run on the bench itself (after <code>bench-login</code>). It acts only on this bench, and only from the system it is bound to if it is bound to one. It is revoked automatically when the bench retires or its system leaves the bench or is deleted.</p>';
		$rows = '';
		foreach(phoromatic_bench_token::tokens(self::$aid, $id) as $t)
		{
			$rows .= '<tr><td>BT' . (int)$t['TokenID'] . '</td><td>' . self::h($t['Label']) . '</td><td>' . ($t['SystemID'] === null ? 'any system' : self::h($t['SystemID'])) . '</td><td>' . self::h(substr($t['CreatedOn'], 0, 16)) . ' by ' . self::h($t['CreatedBy']) . '</td><td>' .
				($t['LastUsedOn'] !== null ? self::h($t['LastUsedOn']) . ' &middot; ' . self::h($t['LastUsedIP']) . ' &middot; ' . self::h($t['LastUsedSystem']) : 'never') . '</td><td>' .
				($t['RevokedOn'] !== null ? 'revoked ' . self::h(substr($t['RevokedOn'], 0, 16)) . ' (' . self::h($t['RevokeReason']) . ')' : self::form_open('benches/' . $id, 'token_revoke', ' style="display: inline;"') . '<input type="hidden" name="token_id" value="' . (int)$t['TokenID'] . '" /><input type="submit" value="Revoke" onclick="return confirm(\'Revoke this bench token? The bench can no longer use it.\');" /></form>') . '</td></tr>';
		}
		if($rows !== '')
		{
			$html .= '<table class="bench_tokens" style="width: 100%;"><tr><th>Token</th><th>Label</th><th>Bound to</th><th>Created</th><th>Last used</th><th></th></tr>' . $rows . '</table>';
		}
		if($bench['RetiredOn'] === null)
		{
			$html .= self::form_open('benches/' . $id, 'token_create') . '<p>Label <input type="text" name="label" maxlength="64" style="width: 250px;" /> for <select name="system_id"><option value="any">any system of this bench</option>';
			foreach($members as $m)
			{
				$html .= '<option value="' . self::h($m['SystemID']) . '">' . self::h($m['Title']) . ' only</option>';
			}
			$html .= '</select> <input type="submit" value="Create bench token" /></p></form>';
		}
		return $html;
	}
	protected static function tickets_box($bench, $open)
	{
		// Active benchmark tickets aimed at this bench's systems, whether each ran in the open session, and "Run again on the current
		// component" for one that ran before (an old ticket is otherwise only offered for 24 hours after it was issued)
		$tickets = phoromatic_bench::bench_tickets(self::$aid, $bench['BenchID']);
		if(empty($tickets))
		{
			return '';
		}
		$hold = phoromatic_bench::class_hold(self::$aid, $bench['BenchID']);
		$html = '<h2>Tickets</h2><table class="bench_tickets" style="width: 100%;"><tr><th>Ticket</th><th>Issued</th><th>' . ($open ? 'Ran in S' . (int)$open['SessionID'] : 'Ran') . '</th><th></th></tr>';
		foreach($tickets as $t)
		{
			$ran = array();
			$all = true;
			foreach($t['targets'] as $m)
			{
				$did = $open && phoromatic_bench::ticket_ran_in_session(self::$aid, $t['TicketID'], $m['SystemID'], $open['SessionID']);
				$all = $all && $did;
				$ran[] = self::h($m['Title']) . ': ' . ($did ? 'yes' : 'no');
			}
			$requested = $open ? phoromatic_bench::rerun_requested(self::$aid, $t['TicketID'], $open['SessionID']) : false;
			$button = '';
			if(!$open || $bench['RetiredOn'] !== null)
			{
				$button = '<em>no open session</em>';
			}
			else if($requested)
			{
				$button = '<em>rerun requested ' . self::h(substr($requested['RequestedOn'], 0, 16)) . '</em>';
			}
			else if($all)
			{
				$button = '<em>ran on every targeted system in S' . (int)$open['SessionID'] . '</em>';
			}
			else if($hold)
			{
				$button = '<em>the bench is on hold</em>';
			}
			else if(self::$can)
			{
				$button = self::form_open('benches/' . (int)$bench['BenchID'], 'ticket_rerun', ' style="display: inline;"') . '<input type="hidden" name="ticket_id" value="' . (int)$t['TicketID'] . '" /><input type="submit" value="Run again on the current component" /></form>';
			}
			$html .= '<tr><td><a href="?benchmark/' . (int)$t['TicketID'] . '">' . self::h($t['Title']) . '</a></td><td>' . self::h(date('Y-m-d H:i', (int)$t['TicketIssueTime'])) . '</td><td>' . implode('; ', $ran) . '</td><td>' . $button . '</td></tr>';
		}
		return $html . '</table>';
	}
	protected static function target_options($bench, $selected = null)
	{
		$html = '<option value="none"' . ($selected === 'none' ? ' selected="selected"' : '') . '>unassigned (no session)</option>';
		foreach(phoromatic_bench::sessions(self::$aid, $bench['BenchID']) as $s)
		{
			$html .= '<option value="' . (int)$s['SessionID'] . '"' . ((string)$selected === (string)$s['SessionID'] ? ' selected="selected"' : '') . '>S' . (int)$s['SessionID'] . ' &middot; ' . self::h($s['Label']) . ' (' . self::h(substr($s['StartedOn'], 0, 10)) . ' &ndash; ' . ($s['EndedOn'] !== null ? self::h(substr($s['EndedOn'], 0, 10)) : 'open') . ')</option>';
		}
		return $html;
	}
	protected static function reassign_page($bench)
	{
		$id = (int)$bench['BenchID'];
		$html = '<h1>Reassign results of ' . self::h($bench['Title']) . '</h1><p>Move results of this bench (filed under its sessions, or from its systems before the bench existed) to another session of the bench, or to no session. Graphs relabel at once; a moved result loses its &quot;constant part differed&quot; mark. <a href="?benches/' . $id . '">Back to the bench</a></p>';
		if(!self::$can)
		{
			return $html . '<p>Only account administrators (levels 1 and 2) can reassign results.</p>';
		}
		$g = function($k) { return isset($_GET[$k]) && is_string($_GET[$k]) ? $_GET[$k] : ''; };
		$filters = array('from' => $g('from'), 'to' => $g('to'), 'session' => $g('session'), 'schedule' => $g('schedule'));
		$html .= '<form onsubmit="var f = this; window.location.href = \'?benches/' . $id . '/reassign&from=\' + encodeURIComponent(f.from.value) + \'&to=\' + encodeURIComponent(f.to.value) + \'&session=\' + encodeURIComponent(f.session.value) + \'&schedule=\' + encodeURIComponent(f.schedule.value); return false;">';
		$html .= 'From <input type="date" name="from" value="' . self::h($filters['from']) . '" /> to <input type="date" name="to" value="' . self::h($filters['to']) . '" /> Session <select name="session"><option value="">any</option>' . str_replace('value="none">unassigned (no session)', 'value="none">unassigned', self::target_options($bench, $filters['session'])) . '</select>';
		$html .= ' Schedule <select name="schedule"><option value="">any</option><option value="0"' . ($filters['schedule'] === '0' ? ' selected="selected"' : '') . '>(none: ad-hoc or ticket)</option>';
		foreach(phoromatic_result_access::bench_schedules(self::$aid, $id) as $sid => $title)
		{
			$html .= '<option value="' . (int)$sid . '"' . ($filters['schedule'] === (string)$sid ? ' selected="selected"' : '') . '>' . self::h($title) . '</option>';
		}
		$html .= '</select> <input type="submit" value="Show" /></form>';
		$rows = phoromatic_bench::reassign_candidates(self::$aid, $id, $filters);
		if(empty($rows))
		{
			return $html . '<p><em>No results match.</em></p>';
		}
		$html .= self::form_open('benches/' . $id . '/reassign', 'reassign_confirm') . '<table class="bench_reassign" style="width: 100%;"><tr><th><input type="checkbox" onclick="var b = this.form.querySelectorAll(\'input[name=&quot;upload_ids[]&quot;]\'); for(var i = 0; i < b.length; i++) { b[i].checked = this.checked; }" /></th><th>Result</th><th>System</th><th>Uploaded</th><th>Session now</th></tr>';
		foreach($rows as $r)
		{
			$html .= '<tr><td><input type="checkbox" name="upload_ids[]" value="' . (int)$r['UploadID'] . '" /></td><td><a href="?result/' . self::h($r['PPRID']) . '">' . self::h($r['Title']) . '</a></td><td>' . self::h($r['SystemTitle']) . '</td><td>' . self::h(substr($r['UploadTime'], 0, 16)) . '</td><td>' . ($r['BenchSessionID'] !== null ? 'S' . (int)$r['BenchSessionID'] . ($r['BenchSessionSource'] === 'reassign' ? ' <small>(reassigned)</small>' : '') : '<em>none</em>') . '</td></tr>';
		}
		return $html . '</table><p>Move the ticked results to <select name="target">' . self::target_options($bench) . '</select> <input type="submit" value="Review&hellip;" /> <small>At most ' . phoromatic_bench::MAX_REASSIGN . ' per action.</small></p></form>';
	}
	protected static function reassign_confirm_page($bench)
	{
		$id = (int)$bench['BenchID'];
		$ids = array();
		foreach(isset($_POST['upload_ids']) && is_array($_POST['upload_ids']) ? $_POST['upload_ids'] : array() as $u)
		{
			if(is_string($u) && ctype_digit($u))
			{
				$ids[(int)$u] = true;
			}
		}
		$target = self::post('target');
		$target_session = $target === 'none' ? null : (int)$target;
		$html = '<h1>Reassign ' . pts_strings::plural_handler(count($ids), 'result') . ' of ' . self::h($bench['Title']) . '?</h1>';
		if(empty($ids))
		{
			return $html . '<p>No result was ticked. <a href="?benches/' . $id . '/reassign">Back</a></p>';
		}
		$rows = array();
		foreach(phoromatic_bench::reassign_candidates(self::$aid, $id) as $r)
		{
			if(isset($ids[(int)$r['UploadID']]))
			{
				$rows[] = $r;
			}
		}
		$html .= '<p>To: <strong>' . ($target_session === null ? 'no session (unassigned)' : 'S' . $target_session) . '</strong>. Audited as result_reassign, with every move.</p>';
		$warnings = phoromatic_bench::reassign_warnings(self::$aid, $id, $rows, $target_session);
		if(!empty($warnings))
		{
			$html .= '<div class="bench_banner bench_reassign_warnings" style="border: 2px solid #c60; padding: 4px 10px;"><ul><li>' . implode('</li><li>', array_map(array('phoromatic_bench', 'h'), $warnings)) . '</li></ul><p>These are warnings: the move can still go ahead.</p></div>';
		}
		$html .= self::form_open('benches/' . $id . '/reassign', 'reassign') . '<input type="hidden" name="target" value="' . self::h($target) . '" />';
		foreach(array_keys($ids) as $u)
		{
			$html .= '<input type="hidden" name="upload_ids[]" value="' . (int)$u . '" />';
		}
		return $html . '<p><input type="submit" value="Move ' . pts_strings::plural_handler(count($ids), 'result') . '" /> <a href="?benches/' . $id . '/reassign">Cancel</a></p></form>';
	}

	// ---------------------------------------------------------------- add a system (multi-system benches, 0.9)

	protected static function pick_bench_page($system_id)
	{
		// ?benches/add/<SystemID>: the system page's "Add to bench...": pick the bench, then the confirm page
		$sys = phoromatic_system_admin::valid_system_id($system_id) ? phoromatic_server::get_system_details(self::$aid, $system_id) : false;
		if(!$sys || $sys['DeletedOn'] !== null)
		{
			return '<h1>System not found</h1>';
		}
		$html = '<h1>Add ' . self::h($sys['Title']) . ' to a bench</h1>';
		if(!self::$can)
		{
			return $html . '<p>Only account administrators (levels 1 and 2) can add a system to a bench.</p>';
		}
		if($sys['BenchID'] !== null)
		{
			return $html . '<p>This system already belongs to a bench. <a href="?benches/' . (int)$sys['BenchID'] . '">Open it</a>.</p>';
		}
		if((int)$sys['State'] !== 1)
		{
			return $html . '<p>Only an approved, enabled system can join a bench. Approve it on the <a href="?systems/' . self::h($sys['SystemID']) . '">system page</a> first.</p>';
		}
		$benches = phoromatic_bench::benches(self::$aid);
		if(empty($benches))
		{
			return $html . '<p>There is no bench yet. <a href="?benches/promote/' . self::h($sys['SystemID']) . '">Promote this system to a bench</a> instead.</p>';
		}
		$html .= '<p>For a dual-boot bench: each OS install is its own system; they share the bench\'s component and sessions. Pick the bench:</p><ul>';
		foreach($benches as $b)
		{
			$names = array();
			foreach(phoromatic_bench::members(self::$aid, $b['BenchID']) as $m)
			{
				$family = phoromatic_bench::os_family($m);
				$names[] = self::h($m['Title']) . ($family !== null ? ' (' . self::h($family) . ')' : '');
			}
			$html .= '<li><a href="?benches/' . (int)$b['BenchID'] . '/add&amp;system=' . self::h($sys['SystemID']) . '">' . self::h($b['Title']) . '</a> <small>B' . (int)$b['BenchID'] . '</small> &middot; ' . (empty($names) ? '<em>no system</em>' : implode(', ', $names)) . '</li>';
		}
		return $html . '</ul><p><a href="?systems/' . self::h($sys['SystemID']) . '">Cancel</a></p>';
	}
	protected static function add_member_page($bench, $system_id)
	{
		// ?benches/<BenchID>/add: pick a system; with &system=<SystemID>: the confirm page, members and candidate side by side
		$id = (int)$bench['BenchID'];
		$html = '<h1>Add a system to ' . self::h($bench['Title']) . '</h1>';
		if(!self::$can)
		{
			return $html . '<p>Only account administrators (levels 1 and 2) can add a system to a bench.</p>';
		}
		if($bench['RetiredOn'] !== null)
		{
			return $html . '<p>This bench is retired.</p>';
		}
		$members = phoromatic_bench::members(self::$aid, $id);
		if($system_id === null)
		{
			$candidates = phoromatic_bench::member_candidates(self::$aid);
			$html .= '<p>For a dual-boot bench: each OS install is its own system; they share the bench\'s component and sessions. Only approved systems that are in no bench can be added (approve a pending system first).</p>';
			if(empty($candidates))
			{
				return $html . '<p><em>No system can be added.</em> <a href="?benches/' . $id . '">Back to the bench</a></p>';
			}
			$html .= '<ul>';
			foreach($candidates as $c)
			{
				$os = phoromatic_bench::os_name($c);
				$html .= '<li><a href="?benches/' . $id . '/add&amp;system=' . self::h($c['SystemID']) . '">' . self::h($c['Title']) . '</a> <small>' . self::h($c['SystemID']) . '</small>' . ($os !== null ? ' &middot; ' . self::h($os) : '') . ' &middot; last check-in ' . self::h(self::ago($c['LastCommunication'])) . '</li>';
			}
			return $html . '</ul><p><a href="?benches/' . $id . '">Cancel</a></p>';
		}
		$sys = phoromatic_system_admin::valid_system_id($system_id) ? phoromatic_server::get_system_details(self::$aid, $system_id) : false;
		if(!$sys || $sys['DeletedOn'] !== null)
		{
			return $html . '<p>System not found. <a href="?benches/' . $id . '/add">Pick another</a></p>';
		}
		if($sys['BenchID'] !== null)
		{
			return $html . '<p>' . self::h($sys['Title']) . ' already belongs to a bench. <a href="?benches/' . (int)$sys['BenchID'] . '">Open it</a>.</p>';
		}
		if((int)$sys['State'] !== 1)
		{
			return $html . '<p>Only an approved, enabled system can join a bench. Approve ' . self::h($sys['Title']) . ' on its <a href="?systems/' . self::h($sys['SystemID']) . '">system page</a> first.</p>';
		}
		$open = phoromatic_bench::open_session(self::$aid, $id);
		$classes = $open ? $open['Classes'] : array();
		$columns = array();
		foreach($members as $m)
		{
			$columns[] = array($m, 'member');
		}
		$columns[] = array($sys, 'candidate');
		$rows = array('System' => array(), 'OS' => array(), 'Last check-in' => array());
		foreach($classes as $class)
		{
			$rows['Detected ' . phoromatic_bench::class_name($class)] = array();
		}
		$head = '<tr><th></th>';
		foreach($columns as $col)
		{
			list($c, $role) = $col;
			$head .= '<th>' . ($role == 'candidate' ? 'To add' : 'Member') . '</th>';
			$rows['System'][] = self::h($c['Title']) . ' <small>' . self::h($c['SystemID']) . '</small>';
			$os = phoromatic_bench::os_name($c);
			$rows['OS'][] = $os !== null ? self::h($os) : '&mdash;';
			$rows['Last check-in'][] = self::h(self::ago($c['LastCommunication']));
			foreach($classes as $class)
			{
				$v = pts_hardware_summary::display_value(phoromatic_bench::detected_for_system($c), $class);
				$rows['Detected ' . phoromatic_bench::class_name($class)][] = $v !== null ? self::h($v) : '<em>not detected</em>';
			}
		}
		$table = '<table class="bench_add_compare" style="width: 100%;">' . $head . '</tr>';
		foreach($rows as $label => $cells)
		{
			$table .= '<tr><th>' . self::h($label) . '</th><td>' . implode('</td><td>', $cells) . '</td></tr>';
		}
		$html .= $table . '</table>';
		$warnings = phoromatic_bench::member_warnings(self::$aid, $members, $sys, $open);
		if(!empty($warnings))
		{
			$html .= '<div class="bench_banner bench_add_warnings" style="border: 2px solid #c60; padding: 4px 10px;"><h3 style="color: #c60;">Check before adding</h3><ul><li>' . implode('</li><li>', array_map(array('phoromatic_bench', 'h'), $warnings)) . '</li></ul><p>These are warnings: the system can still be added.</p></div>';
		}
		$html .= '<h3>What happens</h3><ul><li>' . self::h($sys['Title']) . ' joins ' . self::h($bench['Title']) . ' and shares its component and sessions. It keeps its ID, title, groups, schedules and results.</li>' .
			($open ? '<li>Its first check-in in S' . (int)$open['SessionID'] . ' (' . self::h($open['Label']) . ') becomes its own baseline; each system compares only with its own.</li>' : '<li>The bench has no open session: like the other systems, it runs no bench tests until a component is declared.</li>') .
			'<li>A task outstanding on any system blocks a swap; each system confirms a swap on its own boot.</li><li>Undo: Remove from bench. Audit: bench_member_add.</li></ul>';
		$html .= self::form_open('benches/' . $id . '/add', 'member_add') . '<input type="hidden" name="system_id" value="' . self::h($sys['SystemID']) . '" />';
		return $html . '<p><input type="submit" value="Add ' . self::h($sys['Title']) . ' to ' . self::h($bench['Title']) . '" /> <a href="?benches/' . $id . '">Cancel</a></p></form>';
	}

	// ---------------------------------------------------------------- acknowledge

	protected static function ack_page($bench, $system_id)
	{
		$id = (int)$bench['BenchID'];
		$open = phoromatic_bench::open_session(self::$aid, $id);
		$m = null;
		foreach(phoromatic_bench::members(self::$aid, $id) as $member)
		{
			if($member['SystemID'] === $system_id)
			{
				$m = $member;
			}
		}
		$d = $m ? phoromatic_bench::drift_state($m['BenchDrift']) : null;
		if(!$m || !$open || !$d)
		{
			return '<h1>Nothing to review</h1><p>The system reports no hardware change for the open session. <a href="?benches/' . $id . '">Back to the bench</a></p>';
		}
		$class = phoromatic_bench::class_names_text($open['Classes']);
		$html = '<h1>' . self::h($bench['Title']) . ': ' . ($d['kind'] == 'awaiting' ? 'waiting for the swap' : ($d['kind'] == 'drift' ? self::h($class) . ' changed on ' . self::h($m['Title']) : 'constant part changed on ' . self::h($m['Title']))) . '</h1>';
		$html .= self::drift_table($d, $m, $open);
		if(!self::$can)
		{
			return $html . '<p>Only account administrators (levels 1 and 2) can acknowledge this.</p>';
		}
		$form = self::form_open('benches/' . $id . '/ack/' . self::h($m['SystemID']), 'ack') . '<input type="hidden" name="expect_kind" value="' . self::h($d['kind']) . '" />';
		if($d['kind'] == 'awaiting')
		{
			$html .= '<h2>Use current hardware</h2><p>Only if the new ' . self::h($class) . ' is already in the bench and detection cannot tell it from the previous one (e.g. two samples of one model). The detected hardware becomes S' . (int)$open['SessionID'] . '\'s baseline and tests run.</p>' .
				$form . '<p><label><input type="checkbox" name="confirm_checked" value="1" /> The new part is in the bench</label></p><p>Note (optional): <input type="text" name="note" maxlength="300" style="width: 400px;" /></p><p><input type="submit" value="Use current hardware" /> <a href="?benches/' . $id . '">Cancel</a></p></form>';
		}
		else if($d['kind'] == 'drift')
		{
			$html .= '<div style="float: left; width: 48%;"><h2>A different ' . self::h($class) . ' is in the bench</h2><p>Start a new session; the swap page opens prefilled with the detected hardware.</p><p><a href="?benches/' . $id . '/swap&prefill=' . self::h($m['SystemID']) . '">Start new session&hellip;</a></p></div>';
			$html .= '<div style="float: left; width: 48%;"><h2>Same ' . self::h($class) . ', continue</h2><p>Only if the same physical part is in the bench and just its name changed (BIOS, microcode or kernel update). Moves ' . self::h($m['Title']) . '\'s baseline forward; the start record stays.</p>' .
				$form . '<p><label><input type="checkbox" name="confirm_checked" value="1" /> I checked the ' . self::h($class) . ' in the bench</label></p><p>Note (optional): <input type="text" name="note" maxlength="300" style="width: 300px;" /></p><p><input type="submit" value="Continue with S' . (int)$open['SessionID'] . '" /></p></form></div><div style="clear: both;"></div>';
		}
		else
		{
			$html .= '<h2>Update baseline and constant parts</h2><p>Tests keep running either way. Updating moves the changed parts of ' . self::h($m['Title']) . '\'s baseline forward, so new results are no longer marked. Or put the old part back and the notice clears itself.</p>' .
				$form . '<p>Constant parts: <input type="text" name="config" maxlength="500" style="width: 500px;" value="' . self::h($open['Config']) . '" /></p><p>Note (optional): <input type="text" name="note" maxlength="300" style="width: 400px;" /></p><p><input type="submit" value="Update baseline" /> <a href="?benches/' . $id . '">Cancel</a></p></form>';
		}
		return $html;
	}

	// ---------------------------------------------------------------- promote

	protected static function promote_page($system_id)
	{
		$sys = phoromatic_system_admin::valid_system_id($system_id) ? phoromatic_server::get_system_details(self::$aid, $system_id) : false;
		if(!$sys || $sys['DeletedOn'] !== null)
		{
			return '<h1>System not found</h1>';
		}
		$html = '<h1>Promote ' . self::h($sys['Title']) . ' to a bench?</h1>';
		if($sys['BenchID'] !== null)
		{
			return $html . '<p>This system already belongs to a bench. <a href="?benches/' . (int)$sys['BenchID'] . '">Open it</a>.</p>';
		}
		if(!self::$can)
		{
			return $html . '<p>Only account administrators (levels 1 and 2) can promote a system.</p>';
		}
		$results = phoromatic_server::$db->querySingle('SELECT COUNT(*) FROM phoromatic_results WHERE AccountID = \'' . SQLite3::escapeString(self::$aid) . '\' AND SystemID = \'' . SQLite3::escapeString($sys['SystemID']) . '\'');
		$html .= '<p>' . self::h($sys['Title']) . ' &middot; SystemID ' . self::h($sys['SystemID']) . ' &middot; last check-in ' . self::h(self::ago($sys['LastCommunication'])) . ' &middot; ' . pts_strings::plural_handler((int)$results, 'result') . ' so far</p>';
		$html .= '<div class="pts_phoromatic_info_box_area"><ul><li><h1>What promoting does</h1></li><li>Creates a bench and makes ' . self::h($sys['Title']) . ' its system. The system keeps its ID, title, groups, schedules and results.</li><li>Scheduled results are filed under the session open when the task is issued. Old results stay as they are (no session); assigning them by date comes with 0.9.</li><li>Keep this OS install\'s drive across board swaps: a fresh install shows up as a new system. Approve it, add it to the bench and remove the old one: the history stays with the bench.</li><li>Undo: Demote (the bench retires when its last system leaves).</li></ul></div>';
		if((int)$sys['State'] === 1 && !empty(phoromatic_bench::benches(self::$aid)))
		{
			$html .= '<p><strong>Or add it to an existing bench</strong> (a dual-boot bench: one system per OS install): <a href="?benches/add/' . self::h($sys['SystemID']) . '">Add to an existing bench&hellip;</a></p><h2>Create a new bench</h2>';
		}
		$detected = phoromatic_bench::detected_for_system($sys);
		$html .= self::form_open('benches/promote/' . self::h($sys['SystemID']), 'promote');
		$html .= '<p><div style="width: 200px; font-weight: bold; float: left;">Bench name:</div><input type="text" name="title" maxlength="64" style="width: 400px;" value="' . self::h(substr($sys['Title'], 0, 64)) . '" /> <small>The system title stays &quot;' . self::h($sys['Title']) . '&quot;.</small></p>';
		$html .= '<h2>Component in the bench now</h2>' . self::component_choice($detected, 'cpu', (string)pts_hardware_summary::display_value($detected, 'cpu'), 'detected', phoromatic_bench::in_stock_components(self::$aid));
		$html .= '<p><div style="width: 200px; font-weight: bold; float: left;">Constant parts:</div><input type="text" name="config" maxlength="500" style="width: 500px;" value="' . self::h(phoromatic_bench::constant_parts_text($detected, 'cpu')) . '" /> <small>free text, prefilled from detection</small></p>';
		$html .= '<p>The system\'s baseline (CPU, GPU, board, memory) is taken from its last check-in. Audit: bench_create, component_create, session_start.</p>';
		return $html . '<p><input type="submit" value="Promote to bench" /> <a href="?systems/' . self::h($sys['SystemID']) . '">Cancel</a></p></form>';
	}

	// ---------------------------------------------------------------- components

	protected static function component_form($c, $locked_class)
	{
		$v = function($k) use ($c) { return $c && isset($c[$k]) ? self::h($c[$k]) : ''; };
		$html = '<p><div style="width: 200px; font-weight: bold; float: left;">Label (shown in graphs):</div><input type="text" name="label" maxlength="64" style="width: 400px;" value="' . $v('Label') . '" /></p>';
		$html .= '<p><div style="width: 200px; font-weight: bold; float: left;">Class' . ($locked_class ? ' (locked: has sessions)' : '') . ':</div>' . ($locked_class ? '<input type="hidden" name="class" value="' . $v('Class') . '" />' . $v('Class') : '<select name="class">' . self::class_options($c ? $c['Class'] : 'cpu') . '</select>') . '</p>';
		foreach(array('serial' => array('Serial', 'Serial'), 'vendor' => array('Vendor', 'Vendor'), 'model' => array('Model', 'Model'), 'revision' => array('Revision', 'Revision'), 'photo_url' => array('PhotoURL', 'Photo link (opened by your browser only)')) as $name => $spec)
		{
			$html .= '<p><div style="width: 200px; font-weight: bold; float: left;">' . $spec[1] . ':</div><input type="text" name="' . $name . '" maxlength="' . ($name == 'photo_url' ? 500 : 128) . '" style="width: 400px;" value="' . $v($spec[0]) . '" /></p>';
		}
		return $html . '<p><div style="width: 200px; font-weight: bold; float: left;">Notes:</div><textarea name="notes" style="width: 400px;">' . $v('Notes') . '</textarea></p>';
	}
	protected static function components_page()
	{
		$html = '<h1>Components</h1><p>A component is one physical part. Retire it when it leaves the lab; it cannot be deleted once a session used it. Renaming relabels its past results.</p>';
		$html .= '<table style="width: 100%;"><tr><th>Component</th><th>Class</th><th>Serial</th><th>Where</th><th>Sessions</th><th>Results</th><th></th></tr>';
		foreach(phoromatic_bench::components(self::$aid) as $c)
		{
			if($c['RetiredOn'] !== null)
			{
				$where = 'retired';
			}
			else if($c['OpenSessionID'] !== null)
			{
				$where = '<a href="?benches/' . (int)$c['OpenBenchID'] . '">' . self::h($c['OpenBenchTitle']) . '</a> &middot; S' . (int)$c['OpenSessionID'];
			}
			else
			{
				$where = 'in stock';
			}
			$actions = '';
			if($c['RetiredOn'] === null)
			{
				$actions = '<a href="?benches/components/' . (int)$c['ComponentID'] . '">Edit</a>';
				if(self::$can && $c['OpenSessionID'] === null)
				{
					$actions .= ' ' . self::form_open('benches/components', 'component_retire', ' style="display: inline;"') . '<input type="hidden" name="component_id" value="' . (int)$c['ComponentID'] . '" /><input type="submit" value="Retire" onclick="return confirm(\'Retire this component? It stays in the history but can no longer be picked.\');" /></form>';
				}
			}
			$serial = $c['Serial'] !== null ? (strlen($c['Serial']) > 4 ? '&hellip;' . self::h(substr($c['Serial'], -4)) : self::h($c['Serial'])) : '&mdash;';
			$photo = $c['PhotoURL'] !== null ? ' <a href="' . self::h($c['PhotoURL']) . '" rel="noopener noreferrer" target="_blank">photo</a>' : '';
			$html .= '<tr><td>' . self::h($c['Label']) . $photo . '</td><td>' . self::h($c['Class']) . '</td><td>' . $serial . '</td><td>' . $where . '</td><td>' . (int)$c['SessionCount'] . '</td><td>' . (int)$c['ResultCount'] . '</td><td>' . $actions . '</td></tr>';
		}
		$html .= '</table>';
		if(self::$can)
		{
			$html .= '<h2>Add component</h2>' . self::form_open('benches/components', 'component_create') . self::component_form(null, false) . '<p><input type="submit" value="Add component" /></p></form>';
		}
		return $html;
	}
	protected static function component_edit_page($component_id)
	{
		$c = phoromatic_bench::component(self::$aid, $component_id);
		if(!$c)
		{
			return '<h1>Component not found</h1>';
		}
		$used = phoromatic_server::$db->querySingle('SELECT COUNT(*) FROM phoromatic_bench_session_components WHERE AccountID = \'' . SQLite3::escapeString(self::$aid) . '\' AND ComponentID = ' . (int)$c['ComponentID']) > 0;
		$html = '<h1>' . ($c['RetiredOn'] === null && self::$can ? 'Edit ' : '') . self::h($c['Label']) . '</h1><p>' . self::h($c['Class']) . ' &middot; created ' . self::h($c['CreatedOn']) . ' by ' . self::h($c['CreatedBy']) . ($c['RetiredOn'] !== null ? ' &middot; retired ' . self::h($c['RetiredOn']) : '') . ' &middot; ' . pts_strings::plural_handler(phoromatic_bench::component_result_count(self::$aid, $c['ComponentID']), 'result') . '</p>';
		if($c['RetiredOn'] !== null || !self::$can)
		{
			foreach(array('Serial', 'Vendor', 'Model', 'Revision', 'Notes') as $k)
			{
				$html .= $c[$k] !== null ? '<p><strong>' . $k . ':</strong> ' . nl2br(self::h($c[$k])) . '</p>' : '';
			}
			return $html . '<p><a href="?benches/components">All components</a></p>';
		}
		return $html . self::form_open('benches/components', 'component_edit') . '<input type="hidden" name="component_id" value="' . (int)$c['ComponentID'] . '" />' . self::component_form($c, $used) . '<p><input type="submit" value="Save" /> <a href="?benches/components">Cancel</a></p></form>';
	}
	protected static function rename_confirm_page($outcome)
	{
		// The label of a component with results changes: confirm, then the same form is posted again with rename_confirmed
		$html = '<h1>Rename this component?</h1><p>' . $outcome['message'] . ' The class stays locked. This is audited.</p>' . self::form_open('benches/components', 'component_edit') . '<input type="hidden" name="rename_confirmed" value="1" />';
		foreach(array('component_id', 'label', 'class', 'serial', 'vendor', 'model', 'revision', 'photo_url', 'notes') as $k)
		{
			$html .= '<input type="hidden" name="' . $k . '" value="' . self::h(self::post($k)) . '" />';
		}
		return $html . '<p><input type="submit" value="Yes, rename" /> <a href="?benches/components/' . (int)self::post('component_id') . '">Cancel</a></p></form>';
	}

	// ---------------------------------------------------------------- compare

	protected static function compare_page()
	{
		$bench = isset($_GET['bench']) && is_numeric($_GET['bench']) ? (int)$_GET['bench'] : 0;
		$class = isset($_GET['class']) && in_array($_GET['class'], phoromatic_bench::CLASSES, true) ? $_GET['class'] : '';
		$schedules = phoromatic_result_access::bench_schedules(self::$aid, $bench ? $bench : null);
		$schedule = isset($_GET['schedule']) && is_numeric($_GET['schedule']) ? (int)$_GET['schedule'] : (empty($schedules) ? 0 : key($schedules));
		$system = isset($_GET['system']) && is_string($_GET['system']) && phoromatic_system_admin::valid_system_id($_GET['system']) ? $_GET['system'] : '';
		$rows = phoromatic_result_access::latest_per_component(self::$aid, array('bench' => $bench, 'class' => $class, 'schedule' => $schedule));
		// The System filter appears with the System column: when a listed bench has results from more than one system
		$systems = array();
		$per_bench = array();
		$show_system = false;
		foreach($rows as $r)
		{
			$systems[$r['SystemID']] = $r['SystemTitle'] !== null ? $r['SystemTitle'] : $r['SystemID'];
			$per_bench[$r['BenchID']][$r['SystemID']] = true;
			$show_system = $show_system || count($per_bench[$r['BenchID']]) > 1;
		}
		$html = '<h1>Compare by component</h1><p>The latest result of one schedule for each component. Compare opens the normal result viewer, with runs labelled by component.</p>';
		$html .= '<form onsubmit="var f = this; window.location.href = \'?benches/compare&bench=\' + encodeURIComponent(f.bench.value) + \'&class=\' + encodeURIComponent(f.cls.value) + \'&schedule=\' + encodeURIComponent(f.schedule.value) + (f.system ? \'&system=\' + encodeURIComponent(f.system.value) : \'\'); return false;">';
		$html .= 'Bench <select name="bench"><option value="0">All benches</option>';
		foreach(phoromatic_bench::benches(self::$aid, true) as $b)
		{
			$html .= '<option value="' . (int)$b['BenchID'] . '"' . ($bench == $b['BenchID'] ? ' selected="selected"' : '') . '>B' . (int)$b['BenchID'] . ' ' . self::h($b['Title']) . '</option>';
		}
		$html .= '</select> Class <select name="cls"><option value="">any</option>' . self::class_options($class) . '</select> Schedule <select name="schedule">';
		foreach($schedules as $sid => $title)
		{
			$html .= '<option value="' . (int)$sid . '"' . ($sid == $schedule ? ' selected="selected"' : '') . '>' . self::h($title) . '</option>';
		}
		$html .= '<option value="0"' . ($schedule === 0 ? ' selected="selected"' : '') . '>(ad-hoc uploads)</option></select>';
		if($show_system || $system !== '')
		{
			asort($systems, SORT_NATURAL | SORT_FLAG_CASE);
			$html .= ' System <select name="system"><option value="">any</option>';
			foreach($systems as $sid => $title)
			{
				$html .= '<option value="' . self::h($sid) . '"' . ((string)$sid === $system ? ' selected="selected"' : '') . '>' . self::h($title) . '</option>';
			}
			$html .= '</select>';
		}
		$html .= ' <input type="submit" value="Show" /></form>';
		if($system !== '')
		{
			$rows = phoromatic_result_access::latest_per_component(self::$aid, array('bench' => $bench, 'class' => $class, 'schedule' => $schedule, 'system' => $system));
		}
		if(empty($rows))
		{
			return $html . '<p><em>No bench results match.</em></p>';
		}
		return $html . self::compare_table($rows, true) . '<p><small>Same-name components on two sessions get &quot;(S&lt;id&gt;)&quot; added in the viewer; the same session twice gets the trigger. &quot;differed&quot;: a constant part changed during that run.</small></p>';
	}
}

?>
