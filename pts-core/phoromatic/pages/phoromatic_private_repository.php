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
		foreach(array('' => 'Repository', 'upload' => 'Upload', 'audit' => 'Audit log', 'tokens' => 'Tokens') as $path => $title)
		{
			$html .= '<a href="/?private_repository' . ($path != '' ? '/' . $path : '') . '"' . ($current == $path ? ' class="pr-current"' : '') . '>' . $title . '</a> ';
		}
		return $html . '</p>';
	}
	protected static function export_csv()
	{
		// ?private_repository/audit&export=csv: index.php renders this without the HTML shell. Read-only; rows are streamed oldest
		// first, formula-looking cells are neutralised and fputcsv does the quoting.
		ini_set('display_errors', '0');
		$guard = phoromatic_private_repo_web::guard();
		if(isset($guard['error']))
		{
			http_response_code(403);
			echo 'You do not have access to this export.';
			return;
		}
		if($_GET['export'] !== 'csv')
		{
			http_response_code(400);
			echo 'Unknown export.';
			return;
		}
		list($action, $name) = self::audit_filters();
		// Nothing below needs the session, and a long export must not hold its lock or run into the time limit
		session_write_close();
		@set_time_limit(300);
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
		phoromatic_private_repo_stage::sweep($guard['user']['AccountID'], phoromatic_private_repo_web::expired_audit($guard['user']['AccountID']));
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
			case 'upload':
				$main .= self::upload_view($guard['user']);
				break;
			case 'review':
				$main .= self::review_view($guard['user']);
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
		$enabled = phoromatic_private_repo::repository_name() != false;
		$data = phoromatic_private_repo::list_versions($account_id);
		$rows = array_slice($data['versions'], 0, phoromatic_private_repo_web::MAX_LIST_ROWS);
		$main = '<p>Test profiles and test suites that approved systems can install as <code>' . self::h($enabled ? phoromatic_private_repo::repository_name() : 'REPOSITORY') . '/NAME</code>. ' .
			'Add one with the <a href="/?private_repository/upload">Upload</a> tab (zip, reviewed before it is stored) or from a system with <code>upload-private-test-profile</code>.</p>';
		if($enabled)
		{
			$main .= '<p>' . self::form('rebuild', array(), 'Rebuild the index') . ' <span class="pr-muted">Re-reads every stored version; use it after copying files into the store by hand.</span></p>';
		}

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
					'<td>' . ($enabled ? '<a href="/?private_repository/delete&amp;kind=' . self::h($r['kind']) . '&amp;name=' . self::h(rawurlencode($r['name'])) . '&amp;version=' . self::h(rawurlencode($r['version'])) . '">Delete</a>' : '') . '</td></tr>';
			}
			$main .= '</table></div>';
			if(count($data['versions']) > count($rows))
			{
				$main .= '<p class="pr-muted">Showing the first ' . count($rows) . ' of ' . count($data['versions']) . ' versions.</p>';
			}
		}

		if(!empty($data['skipped']))
		{
			$main .= '<h2>Archives without valid metadata</h2><p class="pr-flash pr-error">These are stored but not listed in the index (a failed upload or a hand-edited store). Delete them, or upload the same version again with overwrite.</p>';
			foreach($data['skipped'] as $r)
			{
				$main .= '<p>' . self::h($r['kind'] . ' ' . $r['name'] . '-' . $r['version']) . ' (' . self::h(phoromatic_private_repo_web::size($r['size'])) . ')' . ($enabled ? ' <a href="/?private_repository/delete&amp;kind=' . self::h($r['kind']) . '&amp;name=' . self::h(rawurlencode($r['name'])) . '&amp;version=' . self::h(rawurlencode($r['version'])) . '">Delete</a>' : '') . '</p>';
			}
		}
		return $main;
	}
	protected static function upload_view($user)
	{
		$account_id = $user['AccountID'];
		if(phoromatic_private_repo::repository_name() == false)
		{
			return '<p>Uploads are unavailable while the repository is disabled.</p>';
		}
		$main = '<p>Upload a test profile or test suite as a zip (at most ' . (phoromatic_private_repo::MAX_ZIP_BYTES / 1048576) . ' MB, with <code>test-definition.xml</code> or <code>suite-definition.xml</code> at its root). ' .
			'Nothing is stored in the repository until you have read the scripts it contains and confirmed. Uploads you do not confirm are removed after 30 minutes. ' .
			'(Server note: PHP must allow requests of at least ' . ini_get('post_max_size') . ' - the current post_max_size - and should have display_errors off, as a production php.ini does, or an oversized upload shows a raw PHP warning instead of a message.)</p>' .
			'<form method="post" enctype="multipart/form-data" action="/?r_private_repository_action" class="pr-form pr-upload">' . write_token_in_form() . '<input type="hidden" name="action" value="stage" />' .
			'<p><label>Zip file <input type="file" name="zip" accept=".zip,application/zip" required /></label></p>' .
			'<p><label>Name <input type="text" name="name" maxlength="64" placeholder="optional; taken from the file name" /></label> <span class="pr-muted">lower-case letters, numbers and dashes</span></p>' .
			'<p><label>Commit description <textarea name="commit_description" rows="2" maxlength="1000" placeholder="what changed"></textarea></label></p>' .
			'<p><input type="submit" value="Upload for review" /></p></form>';

		$mine = phoromatic_private_repo_stage::live_stages($account_id, $user['UserID']);
		if(!empty($mine))
		{
			$main .= '<h2>Waiting for your review</h2><div class="pr-scroll"><table class="pr-table"><tr><th>Name</th><th>Version</th><th>Size</th><th>Expires in</th><th></th></tr>';
			foreach($mine as $m)
			{
				$main .= '<tr><td>' . self::h($m['kind'] . ' ' . $m['name']) . '</td><td>' . self::h($m['version']) . '</td><td>' . self::h(phoromatic_private_repo_web::size($m['size'])) . '</td>' .
					'<td>' . max(1, (int)ceil(($m['expires'] - time()) / 60)) . ' min</td><td><a href="/?private_repository/review&amp;id=' . self::h($m['id']) . '">Review</a></td></tr>';
			}
			$main .= '</table></div>';
		}
		return $main;
	}
	protected static function vh($text)
	{
		// For anything taken from inside an uploaded package (entry names, URLs, titles): escaped for HTML after control, invisible and
		// text-direction characters are made visible, so a name cannot spoof how it reads
		return self::h(phoromatic_private_repo_stage::visible_name($text));
	}
	protected static function pre($text)
	{
		return '<pre class="pr-pre">' . self::h($text) . '</pre>';
	}
	protected static function review_view($user)
	{
		$account_id = $user['AccountID'];
		$id = isset($_GET['id']) && is_string($_GET['id']) ? $_GET['id'] : '';
		$m = phoromatic_private_repo_stage::get_stage($account_id, $id, $user['UserID']);
		if(isset($m['error']))
		{
			return '<p class="pr-flash pr-error">' . self::h($m['error']) . '</p><p><a href="/?private_repository/upload">Back to Upload</a></p>';
		}
		$p = $m['preview'];
		$main = '<h2>Review: ' . self::h($m['kind'] . ' ' . $m['name'] . '-' . $m['version']) . '</h2>' .
			'<p class="pr-muted">Nothing has been stored yet. This upload is removed automatically in ' . max(1, (int)ceil(($m['expires'] - time()) / 60)) . ' minutes if you do nothing.</p>' .
			'<div class="pr-scroll"><table class="pr-table pr-kv"><tr><th>Will be added as</th><td><code>' . self::h(phoromatic_private_repo::repository_name() . '/' . $m['name'] . '-' . $m['version']) . '</code> (' . self::h($m['kind']) . ')</td></tr>' .
			'<tr><th>Title</th><td>' . self::vh($m['title']) . '</td></tr>' .
			'<tr><th>Description</th><td>' . self::vh(isset($m['info']['description']) ? $m['info']['description'] : '') . '</td></tr>' .
			(isset($m['info']['supported_platforms']) && !empty($m['info']['supported_platforms']) ? '<tr><th>Platforms</th><td>' . self::h(implode(', ', $m['info']['supported_platforms'])) . '</td></tr>' : '') .
			'<tr><th>Archive</th><td>' . self::h(phoromatic_private_repo_web::size($m['size'])) . ', ' . count($p['files']) . ' files, from <code>' . self::vh($m['original_name']) . '</code></td></tr>' .
			'<tr><th>sha256</th><td><code class="pr-hash">' . self::h($m['sha256']) . '</code></td></tr>' .
			'<tr><th>Commit description</th><td>' . self::h($m['commit_description']) . '</td></tr></table></div>';
		if(!empty($m['warnings']))
		{
			$main .= '<p class="pr-flash pr-error"><strong>Warnings:</strong><br />' . implode('<br />', array_map(function($w) { return self::vh($w); }, $m['warnings'])) . '</p>';
		}

		if($m['existing'] !== null)
		{
			$d = $p['diff'];
			$main .= '<div class="pr-flash pr-error"><h3>This version already exists</h3><p>Overwriting replaces version ' . self::h($m['version']) . ' of <code>' . self::h($m['name']) . '</code> under the same version number. ' .
				'<strong>Systems that already installed it keep the old copy until they reinstall</strong>, while systems installing now get the new one, so the same version string will mean different code on different machines. Bumping the version in the package is safer. The old version is moved to the server trash and the overwrite is recorded in the audit log.</p>' .
				'<p>Existing sha256 <code class="pr-hash">' . self::h($m['existing']['sha256']) . '</code><br />New sha256 <code class="pr-hash">' . self::h($m['sha256']) . '</code>' . ($m['existing']['sha256'] === $m['sha256'] ? ' (identical content)' : '') . '</p>';
			foreach(array('added' => 'Files added', 'removed' => 'Files removed', 'changed' => 'Files changed') as $key => $label)
			{
				if(!empty($d[$key]))
				{
					$main .= '<p><strong>' . $label . ':</strong> ' . implode(', ', array_map(function($f) { return '<code>' . self::vh($f) . '</code>'; }, $d[$key])) . '</p>';
				}
			}
			if($d !== null && empty($d['added']) && empty($d['removed']) && empty($d['changed']))
			{
				$main .= '<p>No file differs from the stored version.</p>';
			}
			$main .= '</div>';
		}

		if(!empty($p['downloads']))
		{
			$main .= '<h3>Downloads fetched at install time (downloads.xml)</h3><p class="pr-muted">Clients download these files and the package then uses them, so they matter as much as the scripts. "Saved as" is where the file lands in the test directory.</p><div class="pr-scroll"><table class="pr-table"><tr><th>URL</th><th>MD5</th><th>SHA256</th><th>Size</th><th>Saved as</th><th>Platform</th><th>Notes</th></tr>';
			foreach($p['downloads'] as $dl)
			{
				$notes = array();
				if(!$dl['checksum'])
				{
					$notes[] = '<span class="pr-bad">no checksum</span>';
				}
				if(!$dl['https'])
				{
					$notes[] = '<span class="pr-bad">not https</span>';
				}
				if(!empty($dl['odd_file']))
				{
					$notes[] = '<span class="pr-bad">file name has a path separator or ..</span>';
				}
				$main .= '<tr><td class="pr-wrap">' . self::vh($dl['url']) . '</td><td class="pr-wrap"><code>' . self::vh($dl['md5']) . '</code></td><td class="pr-wrap"><code>' . self::vh($dl['sha256']) . '</code></td><td>' . self::vh($dl['size']) . '</td><td class="pr-wrap">' . self::vh($dl['file']) . '</td><td>' . self::vh($dl['platform']) . '</td><td>' . implode(' ', $notes) . '</td></tr>';
			}
			$main .= '</table></div>';
		}

		$ns = !empty($p['downloads_namespaced']);
		if($ns)
		{
			$main .= '<p class="pr-flash pr-error"><strong>downloads.xml uses XML namespaces (xmlns).</strong> Clients ignore any element that is in a namespace and read only plain, un-namespaced Downloads/Package entries, so what you read in the file can differ from what is fetched. The table above (if any) is what clients fetch; any download the file lists that is missing from it is not fetched by clients, but other tools may read it. Prefer a downloads.xml without xmlns.</p>';
		}
		if(!empty($p['downloads_present']) && empty($p['downloads']))
		{
			$main .= '<p class="pr-flash pr-error"><strong>downloads.xml is in the package but no download could be read from it</strong> (' . ($ns ? 'it declares XML namespaces and clients only read un-namespaced Downloads/Package entries, or it is not well-formed' : 'it is not well-formed, or has no Downloads/Package entries the way clients read them') . '). Read the file below yourself.</p>';
		}
		$main .= '<h3>Files</h3><div class="pr-scroll"><table class="pr-table"><tr><th>Path</th><th>Size</th><th>sha256</th><th>Review</th></tr>';
		foreach($p['files'] as $f)
		{
			$status = $f['shown'] ? 'shown below' : '<span class="pr-bad">NOT SHOWN: ' . self::h($f['reason']) . '</span>';
			$main .= '<tr><td class="pr-wrap">' . self::vh($f['path']) . '</td><td>' . ($f['size'] === null ? '' : self::h(phoromatic_private_repo_web::size($f['size']))) . '</td><td class="pr-wrap"><code>' . self::h(substr((string)$f['sha256'], 0, 16)) . '</code></td><td>' . $status . '</td></tr>';
		}
		$main .= '</table></div>';

		$complete = !isset($p['complete']) || $p['complete'] !== false;
		$unreviewable = isset($p['unreviewable']) ? $p['unreviewable'] : array();
		if(!$complete)
		{
			$main .= '<div class="pr-flash pr-error"><h3>Too large to review completely here</h3><p>' . self::vh($p['incomplete_reason']) . '. Scripts and definition files that cannot be shown in full cannot be confirmed from this page: what you cannot read, clients would still run. ' .
				'Upload it from a system with <code>upload-private-test-profile</code>, or split the package.</p></div>';
		}
		else if(!empty($unreviewable))
		{
			$main .= '<div class="pr-flash pr-error"><h3>' . count($unreviewable) . ' file(s) cannot be reviewed here</h3><p>These are in the package but are not shown below (binary data, or text too large to show). They end up on every client that installs it:</p><p>' .
				implode('<br />', array_map(function($u) { return '<code>' . self::vh($u['path']) . '</code> (' . self::h($u['reason']) . ')'; }, array_slice($unreviewable, 0, 30))) . (count($unreviewable) > 30 ? '<br />and ' . (count($unreviewable) - 30) . ' more' : '') . '</p></div>';
		}

		$main .= '<h3>Scripts and definition files</h3><p class="pr-muted">Scripts run on every client that installs this package; they are listed in the order a client runs them, install scripts first. ' .
			'Control, invisible and text-direction characters are shown as <code>&lt;U+XXXX&gt;</code> (a NUL byte as <code>\0</code>) and bytes that are not valid UTF-8 as <code>&lt;0xNN&gt;</code>, so nothing is hidden by the display. ' .
			'The notes are simple pattern hints to point you at lines, not a verdict: no hint does not mean safe.</p>';
		foreach($p['scripts'] as $sc)
		{
			$main .= '<h4>' . self::vh($sc['path']) . ' <span class="pr-muted">(' . self::h(phoromatic_private_repo_web::size($sc['size'])) . ', sha256 ' . self::h(substr($sc['sha256'], 0, 16)) . '...)</span></h4>';
			if(!empty($sc['hints']))
			{
				$main .= '<p class="pr-hints">Hints: ' . implode(', ', array_map('phoromatic_private_repo_web::h', $sc['hints'])) . '</p>';
			}
			$main .= self::pre($sc['text']);
		}
		if(!empty($p['texts']))
		{
			$main .= '<h3>Other text files</h3><p class="pr-muted">Not scripts, but a script can read them (configuration, patches, source). Shown in full, collapsed.</p>';
			foreach($p['texts'] as $t)
			{
				$main .= '<details><summary>' . self::vh($t['path']) . ' <span class="pr-muted">(' . self::h(phoromatic_private_repo_web::size($t['size'])) . ', sha256 ' . self::h(substr($t['sha256'], 0, 16)) . '...)</span></summary>' . self::pre($t['text']) . '</details>';
			}
		}

		$main .= '<h3>Decision</h3>';
		if(!$complete)
		{
			$main .= '<p>This upload cannot be confirmed here.</p>';
		}
		else
		{
			$main .= '<form method="post" action="/?r_private_repository_action" class="pr-form">' . write_token_in_form() . '<input type="hidden" name="action" value="confirm" />' .
				'<input type="hidden" name="stage_id" value="' . self::h($m['id']) . '" /><input type="hidden" name="sha256" value="' . self::h($m['sha256']) . '" />' .
				'<p><label><input type="checkbox" name="reviewed" value="1" required /> I have read the scripts and downloads above and want them to run on systems that install this package.</label></p>';
			if(!empty($unreviewable))
			{
				$main .= '<p><label><input type="checkbox" name="accept_unreviewable" value="1" required /> This package contains ' . count($unreviewable) . ' binary or non-reviewable file(s) (listed above). I accept that I could not review them.</label></p>';
			}
			if($m['existing'] !== null)
			{
				$main .= '<p><label><input type="checkbox" name="overwrite" value="1" /> Overwrite the existing version ' . self::h($m['version']) . ' (see the warning above).</label></p>';
			}
			$main .= '<p><input type="submit" value="Confirm and add to the private repository" /></p></form>';
		}
		$main .= '<form method="post" action="/?r_private_repository_action" class="pr-form">' . write_token_in_form() . '<input type="hidden" name="action" value="cancel" /><input type="hidden" name="stage_id" value="' . self::h($m['id']) . '" /><input type="submit" value="Cancel and discard this upload" /></form>';
		return $main;
	}
	protected static function confirm_delete($account_id)
	{
		$kind = isset($_GET['kind']) && is_string($_GET['kind']) ? $_GET['kind'] : '';
		$name = isset($_GET['name']) && is_string($_GET['name']) ? $_GET['name'] : '';
		$version = isset($_GET['version']) && is_string($_GET['version']) ? $_GET['version'] : '';
		if(phoromatic_private_repo::repository_name() == false)
		{
			return '<p>The private repository is disabled on this server.</p>';
		}
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
