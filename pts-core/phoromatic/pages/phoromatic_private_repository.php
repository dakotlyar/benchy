<?php

/*
	Benchy
	Phoromatic web UI: private repository management (list, delete, rebuild, audit log, repository tokens).

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


// Page name is private_repository, not private_repo: the class phoromatic_private_repo (the store) already exists, so a page of
// that name would never be loaded. It must also not contain "admin" (index.php sends AdminLevel > 0 users away from those pages).
// This page only renders; every change is a POST to phoromatic_r_private_repository_action.

class phoromatic_private_repository implements pts_webui_interface
{
	public static function page_title()
	{
		return 'Private Repository';
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
	protected static function h($v)
	{
		return phoromatic_private_repo_web::h($v);
	}
	protected static function form($action, $fields, $label, $class = '')
	{
		$html = '<form method="post" action="/?r_private_repository_action" class="pr-inline">' . write_token_in_form() . '<input type="hidden" name="action" value="' . self::h($action) . '" />';
		foreach($fields as $k => $v)
		{
			$html .= '<input type="hidden" name="' . self::h($k) . '" value="' . self::h($v) . '" />';
		}
		return $html . '<input type="submit" value="' . self::h($label) . '" class="' . $class . '" /></form>';
	}
	protected static function tabs($current)
	{
		$html = '<p class="pr-tabs">';
		foreach(array('' => 'Repository', 'audit' => 'Audit log', 'tokens' => 'Tokens') as $path => $title)
		{
			$html .= '<a href="/?private_repository' . ($path != '' ? '/' . $path : '') . '"' . ($current == $path ? ' class="pr-current"' : '') . '>' . $title . '</a> ';
		}
		return $html . '</p>';
	}
	protected static function export_csv()
	{
		// ?private_repository/audit&export=csv: index.php renders this without the HTML shell. Read-only; rows are streamed oldest
		// first, formula-looking cells are neutralised and fputcsv does the quoting.
		$guard = phoromatic_private_repo_web::guard();
		if(isset($guard['error']))
		{
			http_response_code(403);
			echo 'Not allowed.';
			return;
		}
		if($_GET['export'] !== 'csv')
		{
			http_response_code(400);
			echo 'Unknown export.';
			return;
		}
		list($action, $name) = self::audit_filters();
		header('Content-Type: text/csv; charset=utf-8');
		header('Content-Disposition: attachment; filename="private-repository-audit.csv"');
		phoromatic_private_repo_audit::export_csv($guard['user']['AccountID'], fopen('php://output', 'w'), $action, $name);
	}
	public static function render_page_process($PATH)
	{
		if(isset($_GET['export']))
		{
			self::export_csv();
			return;
		}
		echo phoromatic_webui_header_logged_in();
		$guard = phoromatic_private_repo_web::guard();
		if(isset($guard['error']))
		{
			echo '<div id="pts_phoromatic_main_area"><h1>Private Repository</h1><p>' . self::h($guard['error']) . '</p></div>' . phoromatic_webui_footer();
			return;
		}

		$view = isset($PATH[0]) ? preg_replace('/&.*$/s', '', $PATH[0]) : '';
		$account_id = $guard['user']['AccountID'];
		$main = '<h1>Private Repository</h1>';
		$flash = phoromatic_private_repo_web::take_flash();
		if($flash)
		{
			$main .= '<p class="pr-flash pr-' . ($flash['type'] == 'error' ? 'error' : 'ok') . '" role="status">' . self::h($flash['message']) . '</p>';
		}
		$main .= self::tabs($view);
		if(phoromatic_private_repo::repository_name() == false)
		{
			$main .= '<p class="pr-flash pr-error">The private repository is disabled on this server (see the PrivateRepository option).</p>';
		}

		switch($view)
		{
			case 'audit':
				$main .= self::audit_view($account_id);
				break;
			case 'tokens':
				$main .= self::tokens_view($account_id);
				break;
			case 'delete':
				$main .= self::confirm_delete($account_id);
				break;
			case 'revoke':
				$main .= self::confirm_revoke($account_id);
				break;
			default:
				$main .= self::list_view($account_id);
				break;
		}

		echo '<div id="pts_phoromatic_main_area" class="pr-page">' . $main . '</div>';
		echo phoromatic_webui_footer();
	}
	protected static function list_view($account_id)
	{
		$data = phoromatic_private_repo::list_versions($account_id);
		$rows = array_slice($data['versions'], 0, phoromatic_private_repo_web::MAX_LIST_ROWS);
		$main = '<p>Test profiles and test suites that approved systems can install as <code>' . self::h(phoromatic_private_repo::repository_name()) . '/NAME</code>. ' .
			'Upload from a system with <code>upload-private-test-profile</code> (the web upload page is planned).</p>';
		$main .= '<p>' . self::form('rebuild', array(), 'Rebuild the index') . ' <span class="pr-muted">Re-reads every stored version; use it after copying files into the store by hand.</span></p>';

		if(empty($rows))
		{
			$main .= '<p>The private repository is empty.</p>';
		}
		else
		{
			$main .= '<div class="pr-scroll"><table class="pr-table"><tr><th>Name</th><th>Version</th><th>Title</th><th>Size</th><th>Checksum</th><th>Uploaded</th><th>By</th><th></th></tr>';
			foreach($rows as $r)
			{
				$hash = $r['sha256'] != null ? 'sha256 ' . $r['sha256'] : ($r['sha1'] != null ? 'sha1 ' . $r['sha1'] : '');
				$main .= '<tr><td>' . self::h($r['kind']) . ' <strong>' . self::h($r['name']) . '</strong></td><td>' . self::h($r['version']) . '</td><td>' . self::h($r['title']) . '</td><td>' . self::h(phoromatic_private_repo_web::size($r['size'])) . '</td>' .
					'<td title="' . self::h($hash) . '"><code>' . self::h(substr($hash, 0, 19)) . '</code></td><td>' . ($r['uploaded'] ? self::h(date('Y-m-d H:i', $r['uploaded'])) : '') . '</td>' .
					'<td>' . self::h($r['uploaded_by']) . ($r['source'] ? ' <span class="pr-muted">(' . self::h($r['source']) . ')</span>' : '') . '</td>' .
					'<td><a href="/?private_repository/delete&amp;kind=' . self::h($r['kind']) . '&amp;name=' . self::h(rawurlencode($r['name'])) . '&amp;version=' . self::h(rawurlencode($r['version'])) . '">Delete</a></td></tr>';
			}
			$main .= '</table></div>';
			if(count($data['versions']) > count($rows))
			{
				$main .= '<p class="pr-muted">Showing the first ' . count($rows) . ' of ' . count($data['versions']) . ' versions.</p>';
			}
		}

		if(!empty($data['skipped']))
		{
			$main .= '<h2>Archives without valid metadata</h2><p class="pr-flash pr-error">These are stored but not listed in the index (a failed upload or a hand-edited store). Delete them, or upload the same version again with overwrite.</p><ul class="pr-plain">';
			foreach($data['skipped'] as $r)
			{
				$main .= '<p>' . self::h($r['kind'] . ' ' . $r['name'] . '-' . $r['version']) . ' (' . self::h(phoromatic_private_repo_web::size($r['size'])) . ') <a href="/?private_repository/delete&amp;kind=' . self::h($r['kind']) . '&amp;name=' . self::h(rawurlencode($r['name'])) . '&amp;version=' . self::h(rawurlencode($r['version'])) . '">Delete</a></p>';
			}
		}
		return $main;
	}
	protected static function confirm_delete($account_id)
	{
		$kind = isset($_GET['kind']) && is_string($_GET['kind']) ? $_GET['kind'] : '';
		$name = isset($_GET['name']) && is_string($_GET['name']) ? $_GET['name'] : '';
		$version = isset($_GET['version']) && is_string($_GET['version']) ? $_GET['version'] : '';
		if(!phoromatic_private_repo::version_exists($account_id, $kind, $name, $version))
		{
			return '<p>No such ' . self::h($kind) . ' version.</p>';
		}
		return '<h2>Delete ' . self::h($kind . ' ' . $name . '-' . $version) . '?</h2><p>The version is moved to the server\'s trash directory (not erased) and removed from the index. ' .
			'Systems that already installed it keep their copy. The deletion is recorded in the audit log.</p><p>' .
			self::form('delete', array('kind' => $kind, 'name' => $name, 'version' => $version), 'Yes, delete it', 'pr-danger') . ' <a href="/?private_repository">Cancel</a></p>';
	}
	protected static function confirm_revoke($account_id)
	{
		$id = isset($_GET['id']) && is_string($_GET['id']) && ctype_digit($_GET['id']) ? (int)$_GET['id'] : 0;
		foreach(phoromatic_private_repo_auth::list_tokens($account_id) as $t)
		{
			if($t['TokenID'] == $id && !$t['RevokedOn'])
			{
				return '<h2>Revoke token #' . $id . ' (' . self::h($t['Label']) . ')?</h2><p>Machines using it can no longer upload, delete or rebuild until they are given another token. ' .
					'The revocation is recorded in the audit log.</p><p>' . self::form('token_revoke', array('token_id' => $id), 'Yes, revoke it', 'pr-danger') . ' <a href="/?private_repository/tokens">Cancel</a></p>';
			}
		}
		return '<p>No active token with that ID.</p>';
	}
	protected static function tokens_view($account_id)
	{
		$main = '';
		if(($new = phoromatic_private_repo_web::take_new_token()) != null)
		{
			$main .= '<div class="pr-flash pr-ok pr-newtoken"><h2>Token #' . (int)$new['id'] . ' (' . self::h($new['label']) . ') created</h2>' .
				'<p>Copy it now. It is shown only here, only once, and the server keeps just a hash. On the machine that will use it run <code>benchy private-repo-login</code> and paste it.</p>' .
				'<p><code class="pr-token">' . self::h($new['token']) . '</code></p></div>';
		}

		$main .= '<p>Upload, delete and rebuild from the command line need a repository token on top of an approved system.</p>' .
			'<form method="post" action="/?r_private_repository_action" class="pr-form">' . write_token_in_form() . '<input type="hidden" name="action" value="token_create" />' .
			'<label>Label <input type="text" name="label" maxlength="' . phoromatic_private_repo_auth::MAX_LABEL . '" required placeholder="e.g. the machine or person" /></label> <input type="submit" value="Create a token" /></form>';

		$tokens = phoromatic_private_repo_auth::list_tokens($account_id);
		if(empty($tokens))
		{
			return $main . '<p>No tokens yet.</p>';
		}
		$main .= '<div class="pr-scroll"><table class="pr-table"><tr><th>ID</th><th>Label</th><th>Created</th><th>By</th><th>Last used</th><th>State</th><th></th></tr>';
		foreach($tokens as $t)
		{
			$main .= '<tr><td>' . (int)$t['TokenID'] . '</td><td>' . self::h($t['Label']) . '</td><td>' . self::h($t['CreatedOn']) . '</td><td>' . self::h($t['CreatedBy']) . '</td>' .
				'<td>' . ($t['LastUsedOn'] ? self::h($t['LastUsedOn'] . ' from ' . $t['LastUsedIP']) : 'never') . '</td>' .
				'<td>' . ($t['RevokedOn'] ? 'revoked ' . self::h($t['RevokedOn']) : 'active') . '</td>' .
				'<td>' . ($t['RevokedOn'] ? '' : '<a href="/?private_repository/revoke&amp;id=' . (int)$t['TokenID'] . '">Revoke</a>') . '</td></tr>';
		}
		return $main . '</table></div>';
	}
	public static function audit_filters()
	{
		// Filters arrive as GET parameters (links) or as a POST of the filter form; both are read-only
		$src = $_SERVER['REQUEST_METHOD'] == 'POST' ? $_POST : $_GET;
		$action = isset($src['action']) && is_string($src['action']) && in_array($src['action'], phoromatic_private_repo_audit::ACTIONS, true) ? $src['action'] : null;
		$name = isset($src['name']) && is_string($src['name']) && $src['name'] !== '' ? substr($src['name'], 0, 128) : null;
		$before = isset($_GET['before']) && is_string($_GET['before']) && ctype_digit($_GET['before']) ? (int)$_GET['before'] : null;
		return array($action, $name, $before);
	}
	public static function audit_query($action, $name, $before = null)
	{
		return ($action ? '&amp;action=' . self::h($action) : '') . ($name ? '&amp;name=' . self::h(rawurlencode($name)) : '') . ($before ? '&amp;before=' . (int)$before : '');
	}
	protected static function audit_view($account_id)
	{
		list($action, $name, $before) = self::audit_filters();
		$size = phoromatic_private_repo_web::AUDIT_PAGE_SIZE;
		$rows = phoromatic_private_repo_audit::recent($account_id, $size + 1, 0, $action, $name, $before);
		$more = count($rows) > $size;
		$rows = array_slice($rows, 0, $size);

		$main = '<form method="post" action="/?private_repository/audit" class="pr-form"><label>Action <select name="action"><option value="">any</option>';
		foreach(phoromatic_private_repo_audit::ACTIONS as $a)
		{
			$main .= '<option value="' . self::h($a) . '"' . ($a === $action ? ' selected' : '') . '>' . self::h($a) . '</option>';
		}
		$main .= '</select></label> <label>Name <input type="text" name="name" maxlength="128" value="' . self::h($name) . '" /></label> <input type="submit" value="Filter" /> ' .
			'<a href="/?private_repository/audit&amp;export=csv' . self::audit_query($action, $name) . '">Download CSV</a></form>';

		if(empty($rows))
		{
			return $main . '<p>No audit rows match.</p>';
		}
		$main .= '<div class="pr-scroll"><table class="pr-table pr-audit"><tr><th>Time</th><th>Who</th><th>From</th><th>Action</th><th>What</th><th>sha256</th><th>Details</th></tr>';
		foreach($rows as $r)
		{
			$what = $r['Kind'] !== null ? $r['Kind'] . ' ' . $r['Name'] . ($r['Version'] !== null ? '-' . $r['Version'] : '') : '';
			$main .= '<tr><td>' . self::h($r['EventTime']) . '</td><td>' . self::h($r['ActorType'] . ' ' . $r['Actor']) . ($r['ActorDetail'] ? ' <span class="pr-muted">(' . self::h($r['ActorDetail']) . ')</span>' : '') . '</td>' .
				'<td>' . self::h($r['SourceIP']) . '</td><td>' . self::h($r['Action']) . '</td><td>' . self::h($what) . '</td>' .
				'<td title="' . self::h($r['Sha256']) . '"><code>' . self::h(substr((string)$r['Sha256'], 0, 12)) . '</code></td>' .
				'<td class="pr-detail" title="' . self::h($r['Detail']) . '">' . self::h(substr((string)$r['Detail'], 0, 160)) . '</td></tr>';
		}
		$main .= '</table></div><p>';
		$main .= $before ? '<a href="/?private_repository/audit' . self::audit_query($action, $name) . '">Newest</a> ' : '';
		$main .= $more ? '<a href="/?private_repository/audit' . self::audit_query($action, $name, $rows[count($rows) - 1]['AuditID']) . '">Older &raquo;</a>' : '';
		return $main . '</p>';
	}
}

?>
