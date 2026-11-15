<?php

/*
	Benchy
	phoromatic_server_audit_log.php: ?server_audit, the server audit, read only and paged (the router maps ?server_audit to this page: the name phoromatic_server_audit
	is the audit table's own class). For server administrators at any account level and the root login.

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

class phoromatic_server_audit_log implements pts_webui_interface
{
	const PER_PAGE = 50;

	public static function page_title()
	{
		return 'Server audit';
	}
	public static function page_header()
	{
		return null;
	}
	public static function preload($PAGE)
	{
		return true;
	}
	public static function render_page_process($PATH)
	{
		if(!phoromatic_server_console::allowed())
		{
			phoromatic_server_console::refused_page('Server audit');
			return;
		}
		$h = function($t) { return phoromatic_server_console::h($t); };
		$total = phoromatic_server_audit::count_all();
		$pages = max(1, (int)ceil($total / self::PER_PAGE));
		$page = isset($PATH[0]) && ctype_digit($PATH[0]) ? min($pages, max(1, (int)$PATH[0])) : 1;
		$main = phoromatic_server_console::nav('audit') . '<h1>Server audit</h1><p>Everything done to the server and its accounts, newest first. Rows cannot be changed or removed. ' . (int)$total . ' row(s).</p>';
		$main .= '<div style="overflow-x: auto;"><table><tr><th>Time</th><th>Who</th><th>Action</th><th>Account</th><th>About</th><th>Detail</th></tr>';
		foreach(phoromatic_server_audit::page(($page - 1) * self::PER_PAGE, self::PER_PAGE) as $row)
		{
			$main .= '<tr><td>' . $h($row['EventTime']) . '</td><td>' . $h($row['ActorType'] . ' ' . $row['Actor']) . ($row['SourceIP'] ? '<br />' . $h($row['SourceIP']) : '') . '</td><td>' . $h($row['Action']) . '</td><td>' . $h($row['TenantID']) .
				'</td><td>' . $h(trim($row['SubjectType'] . ' ' . $row['SubjectID'] . ' ' . $row['SubjectLabel'])) . '</td><td>' . $h($row['Detail']) . '</td></tr>';
		}
		$main .= '</table></div><p>';
		$main .= $page > 1 ? '<a href="?server_audit/' . ($page - 1) . '">Newer</a> &middot; ' : '';
		$main .= 'Page ' . $page . ' of ' . $pages . ($page < $pages ? ' &middot; <a href="?server_audit/' . ($page + 1) . '">Older</a>' : '') . '</p>';
		echo phoromatic_webui_header_logged_in();
		echo '<div id="pts_phoromatic_main_area">' . $main . '</div>';
		echo phoromatic_webui_footer();
	}
}

?>
