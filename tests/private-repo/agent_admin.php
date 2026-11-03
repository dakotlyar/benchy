<?php
// Harness helper (run inside the server container by the native agent tests): the administrator's side of native agents, as the Systems
// page will do it, printed as one JSON object.
//   php agent_admin.php code <account> <label> [<system id or ->] [<policy or ->]
//   php agent_admin.php revoke-code <account> <code id>
//   php agent_admin.php revoke-token <account> <system id>
//   php agent_admin.php delete-system <account> <system id>
//   php agent_admin.php allow-http on|off
//   php agent_admin.php validate <schema reference> <base64 json>
define('PHOROMATIC_SERVER', true);
define('PTS_MODE', 'WEB_CLIENT');
define('PTS_AUTO_LOAD_OBJECTS', true);
include('/bench/pts-core/pts-core.php');
pts_core::init();
phoromatic_server::prepare_database();

$none = function($v) { return $v === null || $v === '-' || $v === '' ? null : $v; };
switch($argv[1])
{
	case 'code':
		$r = phoromatic_agent_auth::create_code($argv[2], isset($argv[4]) ? $none($argv[4]) : null, isset($argv[5]) ? $none($argv[5]) : null, $argv[3], 'user', 'qc admin');
		break;
	case 'revoke-code':
		$r = phoromatic_agent_auth::revoke_code($argv[2], $argv[3], 'user', 'qc admin');
		break;
	case 'revoke-token':
		$r = phoromatic_agent_auth::revoke_system_token($argv[2], $argv[3], 'manual', 'user', 'qc admin');
		break;
	case 'allow-http':
		$r = array('error' => phoromatic_server::set_v2_allow_http($argv[2], '', 'user', 'qc admin'));
		break;
	case 'delete-system':
		// disables the system, then deletes it the way the Systems page does (which also revokes its agent token)
		phoromatic_server::$db->exec("UPDATE phoromatic_systems SET State = -1 WHERE AccountID = '" . SQLite3::escapeString($argv[2]) . "' AND SystemID = '" . SQLite3::escapeString($argv[3]) . "'");
		$r = phoromatic_system_admin::delete($argv[2], $argv[3], 'qc admin');
		break;
	case 'validate':
		// the problems a body has against a pinned schema (empty list = valid): php agent_admin.php validate <reference> <base64 of the json>
		$r = array('errors' => phoromatic_json_schema::validate(json_decode(base64_decode($argv[3]), true), $argv[2]));
		break;
	default:
		$r = array('error' => 'unknown command');
}
echo json_encode($r);
