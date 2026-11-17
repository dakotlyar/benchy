<?php

/*
	Benchy built-in HTTPS (0.10). This program is free software; you can redistribute it and/or modify it under the terms of the
	GNU General Public License as published by the Free Software Foundation; either version 3 of the License, or (at your option) any later version.
*/

class server_https_renew_leaf implements pts_option_interface
{
	const doc_section = 'Phoromatic';
	const doc_skip = true;
	const doc_description = 'Run by the Phoromatic Server launcher every few hours, not by hand. In the internal mode it makes the one certificate that carries every configured name again when it is due (or missing, or for other names) and has the running service pick it up. With the argument "restore" (after a failed reload) it puts the previous certificate back if it is still good, else makes a new one, and raises no reload. It changes nothing in any other mode. Usage: server-https-renew-leaf [restore]';

	public static function run($r)
	{
		phoromatic_tls::honour_silence();
		if(phodevi::is_windows())
		{
			return false;
		}
		$restore = !empty($r) && $r[0] === 'restore';
		if($restore)
		{
			// The launcher put the previous Caddy configuration back: the settings go back with it (as the web page does), so the
			// certificate is checked against the names that configuration serves
			phoromatic_tls_web::reconcile_failed_apply();
		}
		$s = phoromatic_tls::settings();
		if($s['mode'] != 'internal' || empty($s['names']) || empty($s['caddy']['path']))
		{
			return true;
		}
		if($restore)
		{
			list($changed, $error) = phoromatic_tls::restore_leaf_prev($s);
			// What is serving now is the restored config: the marker follows it
			phoromatic_tls::note_leaf_result($error !== null ? $error : (phoromatic_tls::caddy_json_uses_leaf() ? null : 'the restored configuration gives each name its own certificate'));
			phoromatic_tls::leaf_log($error === null ? ($changed ? 'after a failed reload: a good certificate is in place' : 'after a failed reload: the current certificate is fine') : 'after a failed reload: ' . $error);
			return $error === null;
		}
		$due = phoromatic_tls::leaf_needs_issue($s);
		$had_problem = phoromatic_tls::leaf_problem() !== null;
		if($due === null && !$had_problem)
		{
			return true;
		}
		list($changed, $error) = phoromatic_tls::ensure_internal_leaf($s);
		if($error !== null)
		{
			phoromatic_tls::note_leaf_result($error);
			// No reload: Caddy keeps what it serves and the problem is in server-https-status and internal-leaf.log
			return false;
		}
		if(!$changed && !$had_problem)
		{
			return true;
		}
		// write_config (inside apply_live) writes the config for the certificate; the launcher restarts Caddy alone, as for any other change
		list($applied, $message) = phoromatic_tls::apply_live($s);
		phoromatic_tls::leaf_log(($due === null ? 'back to one certificate for every name' : $due) . ': ' . ($applied ? 'renewed' : 'not applied (' . $message . ')'));
		return $applied;
	}
}

?>
