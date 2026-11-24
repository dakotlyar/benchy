<?php

/*
	Benchy
	phoromatic_r_server_update.php: ?r_server_update, the server update actions (POST: upload, check_github, update) and its status (GET, JSON) for server administrators (0.16.2, U2)

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

class phoromatic_r_server_update implements pts_webui_interface
{
	const ACTIONS = array('upload', 'check_github', 'update');

	public static function page_title()
	{
		return '';
	}
	public static function page_header()
	{
		return null;
	}
	public static function preload($PAGE)
	{
		phoromatic_private_repo_web::security_headers();
		return true;
	}
	protected static $level = 0;
	protected static function stop($code, $text)
	{
		while(ob_get_level() > self::$level)
		{
			ob_end_clean();
		}
		http_response_code($code);
		echo $text;
	}
	public static function render_page_process($PATH)
	{
		ini_set('display_errors', '0');
		self::$level = ob_get_level();
		ob_start();
		phoromatic_private_repo_web::security_headers();
		// Nothing below runs for anyone who is not a server administrator (a tenant administrator is not one), so a forged request changes nothing
		if(!phoromatic_server_console::allowed())
		{
			self::stop(403, 'You do not have access to this action.');
			return;
		}
		if($_SERVER['REQUEST_METHOD'] === 'GET')
		{
			$view = phoromatic_server_update::view();
			phoromatic_server_update::audit_result_once($view['status']);
			$st = $view['status'];
			while(ob_get_level() > self::$level)
			{
				ob_end_clean();
			}
			header('Content-Type: application/json');
			echo json_encode(array('state' => $view['state'], 'running' => $view['running'], 'text' => phoromatic_server_update::state_text($view['state'], $view['status']),
				'step' => $st === null ? '' : $st['step'], 'message' => $st === null ? '' : $st['message'], 'from' => $st === null ? '' : $st['from'], 'to' => $st === null ? '' : $st['to'], 'log' => $view['log']));
			return;
		}
		if($_SERVER['REQUEST_METHOD'] !== 'POST')
		{
			header('Allow: GET, POST');
			self::stop(405, 'GET or POST only.');
			return;
		}
		$action = isset($_POST['action']) && is_string($_POST['action']) ? $_POST['action'] : null;
		if($action === null || !in_array($action, self::ACTIONS, true))
		{
			self::stop(400, 'Unknown action.');
			return;
		}
		if(!phoromatic_private_repo_web::origin_ok())
		{
			self::stop(403, 'This request was blocked because it did not appear to come from this site. Go back, reload the page and try again.');
			return;
		}
		$page = isset($_POST['page']) && is_string($_POST['page']) && in_array($_POST['page'], phoromatic_server_update_web::PAGES, true) ? $_POST['page'] : 'server_settings';
		if(!verify_submission_token())
		{
			phoromatic_server_update_web::flash(false, 'The form expired or was not submitted from this site; nothing was changed. Please try again.');
		}
		else
		{
			list($ok, $message) = phoromatic_server_update_web::act($action);
			phoromatic_server_update_web::flash($ok, $message);
		}
		while(ob_get_level() > self::$level)
		{
			ob_end_clean();
		}
		header('Location: /?' . $page . '#server-update');
	}
}

?>
