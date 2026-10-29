<?php

/*
	Benchy
	bench_action: the bench's own command line (bench-status, bench-swap, bench-end; Benchy 0.9, 2C)

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

// The machine is approved by now (phoromatic.php). The bench is the presenting system's own (found from its machine id, never from a
// parameter). act=status needs nothing more; swap and end need a bench token (POST bt) of that bench, and of this system when the
// token is bound to one, and then follow exactly the web's rules (a task outstanding on any member refuses; no force from here).
// Replies {"phoromatic": {"bench": {...}}} or {"phoromatic": {"error": "..."}}; texts are plain (no HTML).

ini_set('display_errors', '0');
$plain = function($html) {
	return trim(html_entity_decode(strip_tags((string)$html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
};
$reply = function($fields) {
	echo json_encode(array('phoromatic' => $fields));
	return true;
};
$act = isset($_POST['act']) && is_string($_POST['act']) ? $_POST['act'] : '';
if(!in_array($act, array('status', 'swap', 'end'), true))
{
	return $reply(array('error' => 'Unknown bench action.'));
}
$sys_row = phoromatic_server::get_system_details(ACCOUNT_ID, SYSTEM_ID);
$bench = $sys_row && $sys_row['BenchID'] !== null ? phoromatic_bench::bench(ACCOUNT_ID, $sys_row['BenchID']) : false;
if(!$bench || $bench['RetiredOn'] !== null)
{
	return $reply(array('error' => 'This system isn\'t part of a bench.'));
}

if($act === 'status')
{
	$open = phoromatic_bench::open_session(ACCOUNT_ID, $bench['BenchID']);
	$members = array();
	foreach(phoromatic_bench::members(ACCOUNT_ID, $bench['BenchID']) as $m)
	{
		$d = phoromatic_bench::drift_state($m['BenchDrift']);
		$members[] = array('system' => $m['SystemID'], 'title' => $m['Title'], 'os' => phoromatic_bench::os_name($m), 'this' => $m['SystemID'] === SYSTEM_ID,
			'state' => !$open ? 'needs a component' : ($d ? $d['kind'] : 'testing'), 'hold' => $open && $d && phoromatic_bench::holds($d) ? $plain(phoromatic_bench::hold_text($bench, $open, $d)) : null,
			'task' => $m['BenchTaskSession'] !== null ? array('session' => 'S' . (int)$m['BenchTaskSession'], 'issued' => $m['BenchTaskIssuedOn'], 'reports' => $m['CurrentTask']) : null);
	}
	return $reply(array('bench' => array('id' => 'B' . (int)$bench['BenchID'], 'title' => $bench['Title'], 'members' => $members,
		'session' => $open ? array('id' => 'S' . (int)$open['SessionID'], 'components' => array(array('id' => (int)$open['ComponentID'], 'label' => $open['Label'], 'class' => $open['Class'])), 'config' => $open['Config'], 'started' => $open['StartedOn'], 'started_by' => $open['StartedBy']) : null)));
}

$token = phoromatic_bench_token::authorize_bench_request((int)$bench['BenchID']);
if(isset($token['error']))
{
	return $reply(array('error' => $token['error']));
}
phoromatic_bench::$actor_type = 'bench_token';
phoromatic_bench::$audit_extra = array('via_system' => SYSTEM_ID);
$actor = 'BT' . (int)$token['TokenID'] . ' ' . $token['Label'];
$actor_detail = 'system ' . SYSTEM_ID;

if($act === 'end')
{
	$out = phoromatic_bench::end_session(ACCOUNT_ID, $bench['BenchID'], $actor, $actor_detail, false);
	return $reply($out['ok'] ? array('bench' => array('done' => $plain($out['message']))) : array('error' => $plain($out['message'])));
}

// swap: args (JSON) {component: "<ID or label>"} or {new: "<class>:<label>"}, optional config, notes
$args = isset($_POST['args']) && is_string($_POST['args']) ? json_decode($_POST['args'], true) : null;
$args = is_array($args) ? $args : array();
$o = array('config' => isset($args['config']) && is_string($args['config']) ? $args['config'] : '', 'notes' => isset($args['notes']) && is_string($args['notes']) ? $args['notes'] : '');
if(isset($args['component']) && is_string($args['component']) && trim($args['component']) !== '')
{
	$want = trim($args['component']);
	$found = null;
	foreach(phoromatic_bench::components(ACCOUNT_ID) as $c)
	{
		if($c['RetiredOn'] === null && ((ctype_digit($want) && (int)$want === (int)$c['ComponentID']) || strcasecmp($want, $c['Label']) === 0))
		{
			$found = $c;
		}
	}
	if($found === null)
	{
		return $reply(array('error' => 'No live component "' . $want . '" in the inventory (give its ID or its label, or use --new CLASS:LABEL).'));
	}
	$o['source'] = 'inventory';
	$o['component_id'] = (int)$found['ComponentID'];
}
else if(isset($args['new']) && is_string($args['new']) && strpos($args['new'], ':') !== false)
{
	list($class, $label) = explode(':', $args['new'], 2);
	if(!in_array(strtolower(trim($class)), phoromatic_bench::CLASSES, true))
	{
		return $reply(array('error' => 'Unknown class "' . trim($class) . '"; one of: ' . implode(', ', phoromatic_bench::CLASSES) . '.'));
	}
	$o['source'] = 'new';
	$o['class'] = strtolower(trim($class));
	$o['label'] = $label;
}
else
{
	return $reply(array('error' => 'Name the component: --component ID-or-label, or --new CLASS:LABEL.'));
}
$out = phoromatic_bench::start_session(ACCOUNT_ID, $bench['BenchID'], $actor, $actor_detail, $o, false);
return $reply($out['ok'] ? array('bench' => array('done' => $plain($out['message']), 'session' => 'S' . (int)$out['session_id'])) : array('error' => $plain($out['message'])));

?>
