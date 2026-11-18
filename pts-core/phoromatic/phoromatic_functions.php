<?php

/*
	Phoronix Test Suite
	URLs: http://www.phoronix.com, http://www.phoronix-test-suite.com/
	Copyright (C) 2013 - 2022, Phoronix Media
	Copyright (C) 2013 - 2022, Michael Larabel

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

define('PHOROMATIC_SERVER_WEB_INTERFACE', true);
define('PAGE_LOAD_START_TIME', microtime(true));

function phoromatic_quit_if_invalid_input_found($input_keys = null)
{
	if(empty($input_keys))
	{
		// Check them all if not being selective about what keys to check
		$input_keys = array_keys($_REQUEST);
	}
	// backup as to sanitization and stripping elsewhere, safeguard namely check for things like < for fields that shouldn't have it
	// plus a few simple backups as safeguards for words that really have no legit relevance within Phoromatic...

	foreach(pts_strings::safety_strings_to_reject() as $invalid_string)
	{
		foreach($input_keys as $key)
		{
			if(isset($_GET[$key]) && !empty($_GET[$key]))
			{
				foreach(pts_arrays::to_array($_GET[$key]) as $val_to_check)
				{
					if(stripos($val_to_check, $invalid_string) !== false)
					{
						echo '<strong>Exited due to invalid input ( ' . $invalid_string . ') attempted:</strong> ' . htmlspecialchars($val_to_check);
						exit;
					}
				}
			}
			if(isset($_POST[$key]) && !empty($_POST[$key]))
			{
				foreach(pts_arrays::to_array($_POST[$key]) as $val_to_check)
				{
					if(stripos($val_to_check, $invalid_string) !== false)
					{
						echo '<strong>Exited due to invalid input ( ' . $invalid_string . ') attempted:</strong> ' . htmlspecialchars($val_to_check);
						exit;
					}
				}
			}
		}
	}
}
function phoromatic_init_web_page_setup()
{
	if(session_save_path() == null || !is_writable(session_save_path()))
	{
		// This is needed since on at least EL6 by default there is no session_save_path set
		if(is_writable('/var/lib/php') && is_dir('/var/lib/php'))
		{
			session_save_path('/var/lib/php');
		}
		else if(is_writable('/var/lib/php5') && is_dir('/var/lib/php5'))
		{
			session_save_path('/var/lib/php5');
		}
		else if(is_writable('/tmp'))
		{
			session_save_path('/tmp');
		}
		else if(is_writable('.'))
		{
			session_save_path('.');
		}
	}

	define('PHOROMATIC_SERVER', true);
	if(defined('PTS_IS_DEV_BUILD') && PTS_IS_DEV_BUILD)
	{
		error_reporting(E_ALL);
	}
	// Lax keeps the session cookie off cross-site POSTs (CSRF defence in depth) while ordinary links into the UI still work
	session_set_cookie_params(array_merge(session_get_cookie_params(), array('samesite' => 'Lax', 'httponly' => true, 'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'))));
	// An id the browser brings that the server never issued is not taken up (a planted id cannot become a logged-in session)
	ini_set('session.use_strict_mode', '1');
	session_start();

	define('PTS_MODE', 'WEB_CLIENT');
	define('PTS_AUTO_LOAD_OBJECTS', true);

	include('../../pts-core.php');
	pts_core::init();
	if(extension_loaded('sqlite3'))
	{
		// Who the session is comes from the database on every request (a suspended tenant, a removed or demoted user), so this
		// has to be after pts_core::init() and before the first thing that reads the level
		phoromatic_server::prepare_database();
		phoromatic_auth::refresh_session();
	}
	define('PHOROMATIC_USER_IS_VIEWER', !isset($_SESSION['AdminLevel']) || $_SESSION['AdminLevel'] >= 10 || $_SESSION['AdminLevel'] < 1 ? true : false);
	// The web interface never runs pts_client::init(), so seed bundled OpenBenchmarking indexes missing from the scratch path here
	pts_openbenchmarking::seed_bundled_indexes();

	if(isset($_SERVER['REQUEST_URI']) && !empty($_SERVER['REQUEST_URI']))
	{
		pts_strings::exit_if_contains_unsafe_data($_SERVER['REQUEST_URI']);
	}
}
function phoromatic_brand_lockup()
{
	// The banner: the Benchy mark and "benchy Phoromatic", outlined paths (static/images/brand/benchy-phoromatic-light.svg), with the
	// fills on the --ph-* tokens so both themes work without a filter. The fallbacks in var() are the light values.
	return <<<'SVG'
<svg xmlns="http://www.w3.org/2000/svg" class="img_logo_pg" style="vertical-align: middle;" viewBox="0 0 235.62 30.4" width="235.62" height="30.4" role="img" aria-label="Benchy Phoromatic"><rect x="0" y="11" width="28" height="4" rx="1" style="fill:var(--ph-brand,#0b7480)"/><rect x="3" y="15" width="4" height="10" rx="0.5" style="fill:var(--ph-brand,#0b7480)"/><rect x="21" y="15" width="4" height="10" rx="0.5" style="fill:var(--ph-brand,#0b7480)"/><rect x="4" y="3" width="10" height="8" rx="1" style="fill:var(--ph-brand,#0b7480)"/><rect x="17" y="0" width="7" height="7" rx="1" style="fill:var(--ph-brand,#0b7480)"/><path style="fill:var(--ph-text,#1f2430)" d="M46.746 16.561Q46.746 18.208 46.449 19.612Q45.963 21.664 44.937 22.69Q43.911 23.716 42.237 23.716Q40.32 23.716 39.294 22.42Q39.24 22.339 39.186 22.366Q39.132 22.393 39.132 22.474V23.176Q39.132 23.311 39.037 23.405Q38.943 23.5 38.808 23.5H36.324Q36.189 23.5 36.094 23.405Q36 23.311 36 23.176V4.924Q36 4.789 36.094 4.695Q36.189 4.6 36.324 4.6H38.808Q38.943 4.6 39.037 4.695Q39.132 4.789 39.132 4.924V10.675Q39.132 10.756 39.186 10.77Q39.24 10.783 39.294 10.702Q40.239 9.487 42.129 9.487Q45.666 9.487 46.503 13.591Q46.746 14.968 46.746 16.561ZM43.587 16.588Q43.587 14.833 43.263 13.78Q42.696 12.187 41.292 12.187Q39.888 12.187 39.456 13.807Q39.159 14.752 39.159 16.588Q39.159 18.37 39.429 19.315Q39.888 21.016 41.319 21.016Q42.831 21.016 43.317 19.288Q43.587 18.343 43.587 16.588Z M59.949 16.345 59.922 17.506Q59.922 17.83 59.598 17.83H52.362Q52.227 17.83 52.227 17.965Q52.281 18.721 52.335 19.072Q52.47 19.963 53.091 20.503Q53.712 21.043 54.657 21.043Q55.467 21.016 56.02 20.638Q56.574 20.26 56.871 19.585Q57.006 19.288 57.276 19.369L59.49 19.828Q59.814 19.936 59.706 20.233Q59.166 21.907 57.856 22.812Q56.547 23.716 54.657 23.716Q52.416 23.716 51.066 22.636Q49.716 21.556 49.311 19.639Q49.176 18.91 49.135 18.1Q49.095 17.29 49.095 15.94Q49.095 14.536 49.23 13.807Q49.5 11.836 50.904 10.662Q52.308 9.487 54.468 9.487Q56.817 9.487 58.261 10.81Q59.706 12.133 59.868 14.293Q59.949 15.184 59.949 16.345ZM52.308 14.131Q52.227 14.725 52.227 15.481Q52.227 15.616 52.362 15.616H56.655Q56.79 15.616 56.79 15.481Q56.736 14.563 56.682 14.158Q56.574 13.267 55.98 12.727Q55.386 12.187 54.495 12.187Q53.604 12.187 53.023 12.713Q52.443 13.24 52.308 14.131Z M72.882 14.158V23.176Q72.882 23.311 72.787 23.405Q72.693 23.5 72.558 23.5H70.074Q69.939 23.5 69.845 23.405Q69.75 23.311 69.75 23.176V14.59Q69.75 13.483 69.197 12.835Q68.643 12.187 67.698 12.187Q66.726 12.187 66.145 12.848Q65.565 13.51 65.565 14.617V23.176Q65.565 23.311 65.471 23.405Q65.376 23.5 65.241 23.5H62.757Q62.622 23.5 62.528 23.405Q62.433 23.311 62.433 23.176V10.027Q62.433 9.892 62.528 9.797Q62.622 9.703 62.757 9.703H65.241Q65.376 9.703 65.471 9.797Q65.565 9.892 65.565 10.027V10.729Q65.565 10.81 65.619 10.837Q65.673 10.864 65.727 10.783Q66.807 9.487 68.697 9.487Q70.668 9.487 71.775 10.729Q72.882 11.971 72.882 14.158Z M75.555 19.531Q75.339 18.424 75.339 16.561Q75.339 14.86 75.555 13.618Q75.852 11.728 77.256 10.607Q78.66 9.487 80.82 9.487Q82.899 9.487 84.33 10.553Q85.761 11.62 86.058 13.348Q86.085 13.456 86.112 13.618Q86.139 13.78 86.139 14.023V14.077Q86.139 14.32 85.842 14.374L83.385 14.617H83.331Q83.088 14.617 83.034 14.32Q83.034 14.131 82.98 13.915Q82.845 13.186 82.264 12.686Q81.684 12.187 80.793 12.187Q79.902 12.187 79.322 12.727Q78.741 13.267 78.606 14.158Q78.471 15.184 78.471 16.588Q78.471 18.127 78.633 19.072Q78.768 19.963 79.335 20.489Q79.902 21.016 80.793 21.016Q81.711 21.016 82.291 20.489Q82.872 19.963 82.98 19.207L83.007 18.937Q83.034 18.802 83.142 18.707Q83.25 18.613 83.385 18.64L85.815 18.856Q86.139 18.91 86.139 19.207L86.085 19.666Q85.842 21.502 84.411 22.609Q82.98 23.716 80.82 23.716Q78.633 23.716 77.243 22.596Q75.852 21.475 75.555 19.531Z M98.964 14.158V23.176Q98.964 23.311 98.87 23.405Q98.775 23.5 98.64 23.5H96.156Q96.021 23.5 95.927 23.405Q95.832 23.311 95.832 23.176V14.59Q95.832 13.483 95.279 12.835Q94.725 12.187 93.78 12.187Q92.808 12.187 92.228 12.848Q91.647 13.51 91.647 14.617V23.176Q91.647 23.311 91.553 23.405Q91.458 23.5 91.323 23.5H88.839Q88.704 23.5 88.609 23.405Q88.515 23.311 88.515 23.176V4.924Q88.515 4.789 88.609 4.695Q88.704 4.6 88.839 4.6H91.323Q91.458 4.6 91.553 4.695Q91.647 4.789 91.647 4.924V10.729Q91.647 10.81 91.701 10.837Q91.755 10.864 91.809 10.783Q92.889 9.487 94.779 9.487Q96.75 9.487 97.857 10.729Q98.964 11.971 98.964 14.158Z M101.07 28.549V26.605Q101.07 26.281 101.367 26.281Q102.366 26.254 102.933 26.052Q103.5 25.849 103.851 25.268Q104.202 24.688 104.418 23.581Q104.445 23.527 104.418 23.419L100.503 10.054L100.476 9.946Q100.476 9.676 100.773 9.676H103.446Q103.716 9.676 103.797 9.946L105.957 19.18Q105.984 19.288 106.038 19.288Q106.092 19.288 106.119 19.18L108.279 9.973Q108.36 9.676 108.63 9.676L111.249 9.703Q111.411 9.703 111.478 9.797Q111.546 9.892 111.519 10.054L107.334 24.256Q106.74 26.173 106.132 27.118Q105.525 28.063 104.458 28.468Q103.392 28.873 101.421 28.873H101.259Q101.178 28.873 101.124 28.779Q101.07 28.684 101.07 28.549Z"/><path style="fill:var(--ph-text-muted,#626c7a)" d="M131.564 10.344Q131.564 12.606 130.277 13.971Q128.99 15.336 126.91 15.336H123.036Q122.932 15.336 122.932 15.44V23.24Q122.932 23.5 122.672 23.5H121.346Q121.086 23.5 121.086 23.24V5.534Q121.086 5.274 121.346 5.274H126.91Q128.99 5.274 130.277 6.665Q131.564 8.056 131.564 10.344ZM129.718 10.37Q129.718 8.784 128.886 7.835Q128.054 6.886 126.676 6.886H123.036Q122.932 6.886 122.932 6.99V13.698Q122.932 13.802 123.036 13.802H126.676Q128.054 13.802 128.886 12.866Q129.718 11.93 129.718 10.37Z M143.316 14.478V23.24Q143.316 23.5 143.056 23.5H141.73Q141.47 23.5 141.47 23.24V14.738Q141.47 13.386 140.768 12.58Q140.066 11.774 138.844 11.774Q137.622 11.774 136.881 12.593Q136.14 13.412 136.14 14.79V23.24Q136.14 23.5 135.88 23.5H134.554Q134.294 23.5 134.294 23.24V5.56Q134.294 5.3 134.554 5.3H135.88Q136.14 5.3 136.14 5.56V11.618Q136.14 11.67 136.179 11.696Q136.218 11.722 136.27 11.67Q137.284 10.162 139.39 10.162Q141.21 10.162 142.263 11.319Q143.316 12.476 143.316 14.478Z M146.54 19.912Q146.384 18.612 146.384 16.896Q146.384 15.18 146.54 13.932Q146.774 12.216 148.009 11.176Q149.244 10.136 151.142 10.136Q153.04 10.136 154.275 11.176Q155.51 12.216 155.744 13.932Q155.9 14.972 155.9 16.896Q155.9 18.872 155.744 19.912Q155.51 21.654 154.275 22.681Q153.04 23.708 151.142 23.708Q149.244 23.708 148.022 22.681Q146.8 21.654 146.54 19.912ZM153.95 19.574Q154.054 18.612 154.054 16.922Q154.054 15.206 153.95 14.27Q153.82 13.126 153.053 12.437Q152.286 11.748 151.142 11.748Q149.998 11.748 149.244 12.437Q148.49 13.126 148.334 14.27Q148.23 15.206 148.23 16.922Q148.23 18.638 148.334 19.574Q148.464 20.718 149.231 21.407Q149.998 22.096 151.142 22.096Q152.26 22.096 153.027 21.407Q153.794 20.718 153.95 19.574Z M165.416 10.526Q165.598 10.604 165.546 10.838L165.234 12.19Q165.208 12.45 164.922 12.346Q164.506 12.164 164.012 12.164L163.7 12.19Q162.556 12.216 161.802 13.178Q161.048 14.14 161.048 15.518V23.24Q161.048 23.5 160.788 23.5H159.462Q159.202 23.5 159.202 23.24V10.604Q159.202 10.344 159.462 10.344H160.788Q161.048 10.344 161.048 10.604V12.294Q161.048 12.372 161.087 12.372Q161.126 12.372 161.152 12.32Q161.594 11.332 162.335 10.773Q163.076 10.214 164.09 10.214Q164.87 10.214 165.416 10.526Z M167.444 19.912Q167.288 18.612 167.288 16.896Q167.288 15.18 167.444 13.932Q167.678 12.216 168.913 11.176Q170.148 10.136 172.046 10.136Q173.944 10.136 175.179 11.176Q176.414 12.216 176.648 13.932Q176.804 14.972 176.804 16.896Q176.804 18.872 176.648 19.912Q176.414 21.654 175.179 22.681Q173.944 23.708 172.046 23.708Q170.148 23.708 168.926 22.681Q167.704 21.654 167.444 19.912ZM174.854 19.574Q174.958 18.612 174.958 16.922Q174.958 15.206 174.854 14.27Q174.724 13.126 173.957 12.437Q173.19 11.748 172.046 11.748Q170.902 11.748 170.148 12.437Q169.394 13.126 169.238 14.27Q169.134 15.206 169.134 16.922Q169.134 18.638 169.238 19.574Q169.368 20.718 170.135 21.407Q170.902 22.096 172.046 22.096Q173.164 22.096 173.931 21.407Q174.698 20.718 174.854 19.574Z M195.784 14.504V23.24Q195.784 23.5 195.524 23.5H194.224Q193.964 23.5 193.964 23.24V14.764Q193.964 13.386 193.275 12.58Q192.586 11.774 191.468 11.774Q190.298 11.774 189.583 12.567Q188.868 13.36 188.868 14.712V23.24Q188.868 23.5 188.608 23.5H187.282Q187.022 23.5 187.022 23.24V14.764Q187.022 13.386 186.333 12.58Q185.644 11.774 184.526 11.774Q183.356 11.774 182.654 12.567Q181.952 13.36 181.952 14.712V23.24Q181.952 23.5 181.692 23.5H180.366Q180.106 23.5 180.106 23.24V10.604Q180.106 10.344 180.366 10.344H181.692Q181.952 10.344 181.952 10.604V11.514Q181.952 11.566 181.991 11.579Q182.03 11.592 182.082 11.54Q182.602 10.838 183.408 10.5Q184.214 10.162 185.124 10.162Q186.268 10.162 187.126 10.682Q187.984 11.202 188.426 12.138Q188.478 12.268 188.556 12.138Q189.076 11.124 189.999 10.643Q190.922 10.162 192.04 10.162Q193.782 10.162 194.783 11.319Q195.784 12.476 195.784 14.504Z M207.926 14.504V23.24Q207.926 23.5 207.666 23.5H206.34Q206.08 23.5 206.08 23.24V22.304Q206.08 22.252 206.041 22.226Q206.002 22.2 205.95 22.252Q205.378 22.98 204.494 23.344Q203.61 23.708 202.518 23.708Q200.88 23.708 199.723 22.824Q198.566 21.94 198.566 19.938Q198.566 17.858 199.905 16.779Q201.244 15.7 203.558 15.7H205.976Q206.08 15.7 206.08 15.596V14.608Q206.08 13.282 205.417 12.515Q204.754 11.748 203.454 11.748Q202.44 11.748 201.764 12.255Q201.088 12.762 200.958 13.594Q200.932 13.828 200.672 13.828L199.242 13.75Q199.112 13.75 199.047 13.672Q198.982 13.594 199.008 13.49Q199.19 11.982 200.425 11.059Q201.66 10.136 203.48 10.136Q205.56 10.136 206.743 11.332Q207.926 12.528 207.926 14.504ZM206.08 19.392V17.286Q206.08 17.182 205.976 17.182H203.688Q202.206 17.182 201.322 17.871Q200.438 18.56 200.438 19.834Q200.438 20.978 201.114 21.537Q201.79 22.096 202.882 22.096Q204.182 22.096 205.131 21.381Q206.08 20.666 206.08 19.392Z M216.246 11.8H213.776Q213.672 11.8 213.672 11.904V19.704Q213.672 20.926 214.153 21.407Q214.634 21.888 215.7 21.888H216.142Q216.402 21.888 216.402 22.148V23.24Q216.402 23.5 216.142 23.5L215.336 23.526Q213.646 23.526 212.762 22.837Q211.878 22.148 211.878 20.276V11.904Q211.878 11.8 211.774 11.8H210.474Q210.214 11.8 210.214 11.54V10.604Q210.214 10.344 210.474 10.344H211.774Q211.878 10.344 211.878 10.24V7.276Q211.878 7.016 212.138 7.016H213.412Q213.672 7.016 213.672 7.276V10.24Q213.672 10.344 213.776 10.344H216.246Q216.506 10.344 216.506 10.604V11.54Q216.506 11.8 216.246 11.8Z M219.158 6.496Q219.158 5.846 219.561 5.443Q219.964 5.04 220.614 5.04Q221.238 5.04 221.654 5.443Q222.07 5.846 222.07 6.496Q222.07 7.12 221.654 7.536Q221.238 7.952 220.614 7.952Q219.964 7.952 219.561 7.536Q219.158 7.12 219.158 6.496ZM219.652 23.24V10.604Q219.652 10.344 219.912 10.344H221.238Q221.498 10.344 221.498 10.604V23.24Q221.498 23.5 221.238 23.5H219.912Q219.652 23.5 219.652 23.24Z M225.268 19.912Q225.112 18.586 225.112 16.896Q225.112 15.57 225.268 13.906Q225.502 12.216 226.75 11.176Q227.998 10.136 229.844 10.136Q231.69 10.136 232.951 11.124Q234.212 12.112 234.42 13.594L234.498 14.14V14.192Q234.498 14.296 234.433 14.361Q234.368 14.426 234.264 14.426L232.938 14.504Q232.704 14.504 232.652 14.27Q232.652 14.036 232.626 13.958Q232.496 13.048 231.729 12.398Q230.962 11.748 229.844 11.748Q228.7 11.748 227.959 12.45Q227.218 13.152 227.088 14.27Q226.958 15.388 226.958 16.922Q226.958 18.482 227.088 19.6Q227.218 20.744 227.959 21.42Q228.7 22.096 229.844 22.096Q230.988 22.096 231.755 21.446Q232.522 20.796 232.626 19.86L232.652 19.652Q232.704 19.366 232.938 19.418L234.238 19.522Q234.472 19.574 234.472 19.756V19.86Q234.472 19.964 234.42 20.172Q234.238 21.706 232.977 22.707Q231.716 23.708 229.844 23.708Q227.972 23.708 226.75 22.681Q225.528 21.654 225.268 19.912Z"/></svg>
SVG;
}
function phoromatic_webui_header($left_items, $right = null)
{
	$ret = PHP_EOL . '<div id="pts_phoromatic_top_header">
	<ul>
	<li><a href="?" title="Benchy Phoromatic">' . phoromatic_brand_lockup() . '</a>';

	if(isset($_SESSION['AdminLevel']) &&$_SESSION['AdminLevel'] > 0 && isset($_SESSION['AccountID']) && !empty($_SESSION['AccountID']))
	{
		$ret .= '<ul id="pts_phoromatic_info">';
		$ret .= '<li><a class="ph_date" href="#">' . date('H:i T - j F') . '</a></li>';
		$group_name = phoromatic_server::account_id_to_group_name($_SESSION['AccountID']);
		$switcher = phoromatic_account_switcher($group_name);
		if($switcher !== '')
		{
			$ret .= $switcher;
		}
		else if($group_name != null)
		{
			$ret .= '<li><a href="#">' . htmlspecialchars($group_name) . '</a></li>';
		}
		$ret .= '</ul>';
	}
	$ret .= '</li>';

	//$ret .= '<ul>';
	foreach($left_items as $i => $item)
	{
		if(is_array($item))
		{
			$ret .= '<li>' . $i;

			if(!empty($item))
			{
				$ret .= '<ul>';
				foreach($item as $sub_item)
				{
					$ret .= '<li>' . $sub_item . '</li>';
				}
				$ret .= '</ul>';
			}
			$ret .= '</li>' . PHP_EOL;
		}
		else
		{
			$ret .= '<li>' . $item . '</li>' . PHP_EOL;
		}
	}
	$ret .= '<li><div id="phoromatic_result_selected_info_box"></div> <a href="#" onclick="javascript:phoromatic_generate_comparison(\'?result/\');"><div id="phoromatic_result_compare_info_box">Compare</div></a> <a href="#" onclick="javascript:phoromatic_delete_results(\'?results/delete/\', \'' . htmlspecialchars(isset($_SESSION['Token']) && is_string($_SESSION['Token']) ? $_SESSION['Token'] : '', ENT_QUOTES) . '\'); return false;"><div id="phoromatic_result_delete_box">Delete</div></a></li>';
	$ret .= '</ul>';

	// Light/dark mode toggle -- purely visual/client-side (see phoromatic.js),
	// shown on every page since they all render through this function.
	$theme_toggle = '<button type="button" id="phoromatic_theme_toggle" class="phoromatic_theme_toggle" onclick="phoromatic_toggle_theme();" aria-pressed="false">Dark Mode</button>';

	$ret .= '<div id="pts_phoromatic_top_header_right">' . $right . $theme_toggle . '</div>';

	$ret .=' </div>';

	return $ret;
}
function phoromatic_get_posted_var($name, $default_value = null)
{
	if(isset($_POST[$name]))
	{
		phoromatic_quit_if_invalid_input_found(array($name));
	}

	return isset($_POST[$name]) ? $_POST[$name] : null;
}
function phoromatic_webui_main($main, $right = null)
{
	return '<div id="pts_phoromatic_main">' . ($right != null ? '<div id="pts_phoromatic_menu_right">' . $right . '</div>' : null) . '<div id="pts_phoromatic_main_area">' . $main . '</div><div style="clear: both;"></div></div>';
}
function phoromatic_webui_box(&$box)
{
	return '<div id="pts_phoromatic_main_box"><div id="pts_phoromatic_main_box_inside">' . $box . '</div></div>';
}
function phoromatic_results_for_schedule($schedule_id, $limit_results = false)
{
	switch($limit_results)
	{
		case 'TODAY':
			$stmt = phoromatic_server::$db->prepare('SELECT COUNT(UploadID) As UploadCount FROM phoromatic_results WHERE AccountID = :account_id AND ScheduleID = :schedule_id AND UploadTime LIKE :today_date');
			$stmt->bindValue(':today_date', date('Y-m-d') . '%');
			break;
		default:
			$stmt = phoromatic_server::$db->prepare('SELECT COUNT(UploadID) As UploadCount FROM phoromatic_results WHERE AccountID = :account_id AND ScheduleID = :schedule_id');
			break;
	}

	$stmt->bindValue(':account_id', $_SESSION['AccountID']);
	$stmt->bindValue(':schedule_id', $schedule_id);
	$test_result_result = $stmt->execute();
	$row = $test_result_result->fetchArray();

	return empty($row) ? 0 : $row['UploadCount'];
}
function phoromatic_schedule_activeon_string($active_on, $active_at = null)
{
	if(!empty($active_on))
	{
		$active_days = explode(',', $active_on);
		$week = array('M', 'T', 'W', 'TH', 'F', 'S', 'SU');
		foreach($active_days as $i => &$day)
		{
			if(!isset($week[$day]))
			{
				unset($active_days[$i]);
			}
			else
			{
				$day = $week[$day];
			}
		}
		return implode(' ', $active_days) . (!empty($active_at) ? ' @ ' . str_replace('.', ':', $active_at) : null );
	}
}
function phoromatic_webui_footer()
{
	return '<div id="pts_phoromatic_bottom_footer">
<p style="margin: 6px 15px;"><strong>' . date('H:i T - j F Y') . '</strong>' . (PTS_IS_DEV_BUILD ? ' &nbsp; [' . round(microtime(true) - PAGE_LOAD_START_TIME, 2) . 's Page Load Time]' : null) . '<br />' . pts_core::gpl_notice() . '<br />' . pts_core::program_title() . ' (PHP ' . PHP_VERSION . ')</p></div> <script type="text/javascript"> phoromatic_checkbox_toggle_result_comparison(\'\'); </script>';
}
function phoromatic_add_activity_stream_event($activity_event, $activity_event_id, $activity_event_type)
{
	$stmt = phoromatic_server::$db->prepare('INSERT INTO phoromatic_activity_stream (AccountID, ActivityTime, ActivityCreator, ActivityCreatorType, ActivityEvent, ActivityEventID, ActivityEventType) VALUES (:account_id, :activity_time, :activity_creator, :activity_creator_type, :activity_event, :activity_event_id, :activity_event_type)');
	$stmt->bindValue(':account_id', $_SESSION['AccountID']);
	$stmt->bindValue(':activity_time', phoromatic_server::current_time());
	$stmt->bindValue(':activity_creator', $_SESSION['UserName']);
	$stmt->bindValue(':activity_creator_type', 'USER');
	$stmt->bindValue(':activity_event', $activity_event);
	$stmt->bindValue(':activity_event_id', $activity_event_id);
	$stmt->bindValue(':activity_event_type', $activity_event_type);
	return $stmt->execute();
}
function phoromatic_tracker_page_relevant()
{
	$stmt = phoromatic_server::$db->prepare('SELECT RunTargetSystems, RunTargetGroups, (SELECT COUNT(*) FROM phoromatic_results WHERE AccountID = phoromatic_schedules.AccountID AND ScheduleID = phoromatic_schedules.ScheduleID) AS UploadedResultCount FROM phoromatic_schedules WHERE AccountID = :account_id AND State >= 1 ORDER BY Title ASC');
	$stmt->bindValue(':account_id', $_SESSION['AccountID']);
	$result = $stmt->execute();
	$row = $result->fetchArray();

	if($row)
	{
		do
		{
			if(is_numeric($row['RunTargetSystems']) && $row['UploadedResultCount'] > (((int)$row['RunTargetSystems'] + (int)$row['RunTargetGroups'] + 1) * 7))
			{
				return true;
			}
		}
		while($row = $result->fetchArray());
	}

	return false;
}
function phoromatic_webui_header_logged_in()
{
	$html_links = array();
	$pages = array();
	if($_SESSION['AdminLevel'] == -40)
	{
		$pages = array('Admin', 'Admin_Config', 'Admin_Data', '<a href="?server_tenants">Server</a>', phoromatic_logout_button());
	}
	else if($_SESSION['AdminLevel'] > 0)
	{
		$sub_main_menu = array();
		$sub_tests_menu = array();
		$sub_systems_menu = array();
		$sub_testing_menu = array();
		$sub_results_menu = array();

		if(phoromatic_account_system_count() > 0)
		{
			$sub_systems_menu[] = 'Dashboard';
			$sub_systems_menu[] = 'Maintenance Table';
			$sub_systems_menu[] = 'Component Table';
			$sub_systems_menu[] = 'Benches';
		}

		//$sub_main_menu[] = '<a href="?tests">Test Profiles</a>';
		if(isset($_SESSION['AdminLevel']) && $_SESSION['AdminLevel'] < 4)
		{
			$sub_main_menu[] = 'Users';
		}

		array_push($sub_main_menu, 'Settings', '<a href="?account_activity">Account Activity</a>');
		if(phoromatic_auth::is_server_admin())
		{
			// (the pages and r_server_action check the role again; this only decides whether to show the link)
			$sub_main_menu[] = '<a href="?server_tenants">Server</a>';
		}
		$sub_main_menu[] = phoromatic_logout_button();
		$sub_testing_menu[] = '<a href="?schedules">Test Schedules</a>';

		if(isset($_SESSION['AdminLevel']) && $_SESSION['AdminLevel'] >= 1 && $_SESSION['AdminLevel'] <= 2)
		{
			// The page re-checks the level against the database on every request; this only decides whether to show the link
			$sub_tests_menu[] = '<a href="?private_repository">Private Repository</a>';
		}

		if(!PHOROMATIC_USER_IS_VIEWER)
		{
			array_push($sub_tests_menu, '<a href="?build_suite">Build Test Suite</a>');
			array_push($sub_testing_menu, '<a href="?sched">Create A Schedule</a>', '<a href="?benchmark">Run A Benchmark</a>');
		}

		if(phoromatic_tracker_page_relevant())
		{
			$sub_results_menu[] = 'Tracker';
		}
		if(($feed_query = phoromatic_feed::query($_SESSION['UserID'], $_SESSION['AccountID'], $_SESSION['CreatedOn'])) !== '')
		{
			$sub_results_menu[] = '<a href="/rss.php?' . $feed_query . '">Results Feed <img src="images/rss.svg" width="16" height="16" /></a>';
		}

		$pages = array('Main' => $sub_main_menu, 'Systems' => $sub_systems_menu, 'Tests' => $sub_tests_menu, '<a href="/?testing">Testing</a>' => $sub_testing_menu, 'Results' => $sub_results_menu, '<form action="/?search" method="post" id="search"><input type="search" name="search" id="seach_input" size="16" /> <input type="submit" name="sa" value="Search" /><div class="search_expander"></div></form>');
	}

	foreach($pages as $title => $page)
	{
		if(is_array($page) || empty($page))
		{
			$menu_row = array();
			foreach($page as $sub_page)
			{
				$menu_row[] = menu_item_to_html($sub_page);
			}
			$html_links[menu_item_to_html($title)] = $menu_row;
		}
		else
		{
			$html_links[] = menu_item_to_html($page);
		}
	}

	return phoromatic_webui_header($html_links, null) . phoromatic_tls_web::banner() . phoromatic_account_notice();
}
function phoromatic_account_switcher($current_name)
{
	// The header's account menu, only for a user who belongs to more than one account: the current account's name, and a small POST form per account
	// to move to it (suspended and turned-off ones are listed but cannot be clicked). Empty for everyone else.
	if(!isset($_SESSION['UserID']) || !isset($_SESSION['AccountID']) || !phoromatic_memberships::ready())
	{
		return '';
	}
	$shown = array();
	foreach(phoromatic_memberships::for_user($_SESSION['UserID']) as $m)
	{
		if($m['State'] !== phoromatic_tenant::DELETED && phoromatic_memberships::valid_level($m['Level']))
		{
			$shown[] = $m;
		}
	}
	if(count($shown) < 2)
	{
		return '';
	}
	$h = '<li><details style="display: inline-block;"><summary style="cursor: pointer;">' . htmlspecialchars(trim((string)$current_name) !== '' ? $current_name : 'Account ' . $_SESSION['AccountID']) . ' &#9662;</summary><ul style="list-style: none; margin: 0; padding: 4px 8px;">';
	foreach($shown as $m)
	{
		$label = htmlspecialchars($m['Name'] . ' - ' . phoromatic_memberships::level_name($m['Level']));
		if((string)$m['AccountID'] === (string)$_SESSION['AccountID'])
		{
			$h .= '<li><strong>' . $label . '</strong> (this account)</li>';
		}
		else if($m['Usable'])
		{
			$h .= '<li><form action="/?r_tenant_switch" method="post" style="display: inline;">' . write_token_in_form() . '<input type="hidden" name="account_id" value="' . htmlspecialchars($m['AccountID'], ENT_QUOTES) . '" /><button type="submit" style="background: none; border: 0; color: inherit; font: inherit; padding: 0; cursor: pointer;">' . $label . '</button></form></li>';
		}
		else
		{
			$h .= '<li style="opacity: 0.55;">' . $label . ' (' . htmlspecialchars($m['State'] === phoromatic_tenant::ACTIVE ? 'turned off' : $m['State']) . ')</li>';
		}
	}
	return $h . '</ul></details></li>';
}
function phoromatic_account_notice()
{
	// A one-time note left in the session (an account that stopped being available, a switch that was refused)
	if(!isset($_SESSION['TenantNotice']) || !is_string($_SESSION['TenantNotice']))
	{
		return '';
	}
	$text = $_SESSION['TenantNotice'];
	unset($_SESSION['TenantNotice']);
	return '<p style="margin: 8px 16px;"><strong>' . htmlspecialchars($text) . '</strong></p>';
}
function phoromatic_post_button($action_url, $label, $hidden = array())
{
	// A change that used to be a link with the token in the address: a small POST form with the token, so the token never shows in
	// a URL, the history or a referrer, and a link on another site cannot trigger it
	$html = '<form action="' . htmlspecialchars($action_url, ENT_QUOTES) . '" method="post" style="display: inline;">' . write_token_in_form();
	foreach($hidden as $name => $value)
	{
		$html .= '<input type="hidden" name="' . htmlspecialchars($name, ENT_QUOTES) . '" value="' . htmlspecialchars($value, ENT_QUOTES) . '" />';
	}
	return $html . '<input type="submit" value="' . htmlspecialchars($label, ENT_QUOTES) . '" /></form>';
}
function phoromatic_post_allowed()
{
	// A change is made only by a POST with the session's token that came from this site, and never by a viewer
	return !PHOROMATIC_USER_IS_VIEWER && $_SERVER['REQUEST_METHOD'] === 'POST' && phoromatic_private_repo_web::origin_ok() && verify_submission_token();
}
function phoromatic_logout_button()
{
	// Logging out changes the session, so it is a POST with the token, not a link
	return '<form action="/?logout" method="post" style="display: inline;">' . write_token_in_form() . '<button type="submit" style="background: none; border: 0; color: inherit; font: inherit; padding: 0; cursor: pointer;">Logout</button></form>';
}
function menu_item_to_html($page)
{
	if(strpos($page, '</') !== false)
		return $page;

	$page_link = strtolower($page);
	if(($x = strpos($page_link, '<br />')) !== false)
	{
		$page_link = trim(substr($page_link, $x + 6));
	}
	$page_link = str_replace(' ', '_', $page_link);

	if(strtolower($page) == PAGE_REQUEST)
	{
		return '<a href="?' . $page_link . '"><u>' . str_replace('_', ' ', $page) . '</u></a>';
	}
	else
	{
		return '<a href="?' . $page_link . '">' . str_replace('_', ' ', $page) . '</a>';
	}
}
function phoromatic_webui_right_panel_logged_in($add = null)
{
	$right = null;
	if($_SESSION['AdminLevel'] == -40)
	{
		$right .= '<h3>Phoromatic Server</h3><hr /><p><strong>' . date('H:i T - j F Y') . '</p>';
	}
	else if($_SESSION['AdminLevel'] > 0)
	{
		//$right .= '<a href="#" onclick="javascript:phoromatic_generate_comparison(\'?result/\');"><div id="phoromatic_result_compare_info_box"></div></a> <a href="#" onclick="javascript:phoromatic_delete_results(\'?results/delete/\'); return false;"><div id="phoromatic_result_delete_box">Delete Selected Results</div></a>';
		if(($bad_systems = phoromatic_server::systems_appearing_down()) != false)
		{
			$right .= '<ul><li><span class="alert">Systems Needing Attention</span></li>';
			foreach($bad_systems as $system)
			{
				$right .= '<li><a href="?systems/' . $system . '">' . htmlspecialchars(phoromatic_server::system_id_to_name($system)) . '</a></li>';
			}
			$right .= '</ul><hr />';
		}

		$right .= $add;

		if($add == null)
		{
			$recently_active_systems = phoromatic_server::recently_active_systems($_SESSION['AccountID']);
			if(!empty($recently_active_systems))
			{
				$right .= '<ul><li>Recently Active Systems</li>';

				foreach($recently_active_systems as &$row)
				{
					$right .= '<li><a href="?systems/' . htmlspecialchars($row['SystemID']) . '">' . htmlspecialchars($row['Title']) . '</a></li>';
				}

				$right .= '</ul><hr />';
			}

			$right .= '
				<ul>
					<li>Today\'s Scheduled Events</li>';

				$stmt = phoromatic_server::$db->prepare('SELECT Title, ScheduleID, RunAt FROM phoromatic_schedules WHERE AccountID = :account_id AND State >= 1  AND ActiveOn LIKE :active_on ORDER BY RunAt,Title ASC');
				$stmt->bindValue(':account_id', $_SESSION['AccountID']);
				$stmt->bindValue(':active_on', '%' . (date('N') - 1) . '%');
				$result = $stmt->execute();
				$row = $result->fetchArray();

				if($row == false)
				{
					$right .= '</ul><p style="text-align: left; margin: 6px 10px;">No Events Found</p>';
				}
				else
				{
					do
					{
						$right .= '<li>' . $row['RunAt'] . ' <a href="?schedules/' . $row['ScheduleID'] . '">' . $row['Title'] . '</a></li>';
					}
					while($row = $result->fetchArray());
					$right .= '</ul>';
				}
		}

		$system_count = phoromatic_account_system_count();
		$schedule_count = phoromatic_account_schedule_count();
		$stmt = phoromatic_server::$db->prepare('SELECT COUNT(UploadID) AS ResultCount FROM phoromatic_results WHERE AccountID = :account_id');
		$stmt->bindValue(':account_id', $_SESSION['AccountID']);
		$result = $stmt->execute();
		$row = $result->fetchArray();
		$result_count = $row['ResultCount'];
		$stmt = phoromatic_server::$db->prepare('SELECT COUNT(ActivityTime) AS ActivityCount FROM phoromatic_activity_stream WHERE AccountID = :account_id AND ActivityTime LIKE :today_date');
		$stmt->bindValue(':account_id', $_SESSION['AccountID']);
		$stmt->bindValue(':today_date', date('Y-m-d') . '%');
		$result = $stmt->execute();
		$row = $result->fetchArray();
		$activity_count = $row['ActivityCount'];

		$group_name = phoromatic_server::account_id_to_group_name($_SESSION['AccountID']);
		if($group_name != null)
		{
			$group_name = '<strong>' . htmlspecialchars($group_name) . '</strong><br />';
		}

		$right .= '<hr /><p><strong>' . date('H:i T - j F Y') . '</strong><br />' . $group_name . '<a href="?systems">' . $system_count . ' System' . ($system_count == 1 ? '' : 's') . '</a><br /><a href="?schedules">' . $schedule_count . ' Schedule' . ($schedule_count == 1 ? '' : 's') . '</a><br /><a href="?results">' . $result_count . ' Result' . ($result_count == 1 ? '' : 's') . '</a>';
		if(($feed_query = phoromatic_feed::query($_SESSION['UserID'], $_SESSION['AccountID'], $_SESSION['CreatedOn'])) !== '')
		{
			$right .= ' <a href="/rss.php?' . $feed_query . '"><img src="images/rss.svg" width="16" height="16" /></a>';
		}
		$right .= '<br /><a href="?account_activity">' . $activity_count . ' Activity Events Today</a></p>';
	}

	return $right;
}
function phoromatic_account_schedule_count()
{
	static $schedule_count = 0;

	if($schedule_count == 0)
	{
		$stmt = phoromatic_server::$db->prepare('SELECT COUNT(Title) AS ScheduleCount FROM phoromatic_schedules WHERE AccountID = :account_id AND State >= 1');
		$stmt->bindValue(':account_id', $_SESSION['AccountID']);
		$result = $stmt->execute();
		$row = $result->fetchArray();
		$schedule_count = $row['ScheduleCount'];
	}
	return $schedule_count;
}
function phoromatic_account_system_count()
{
	static $sys_count = 0;

	if($sys_count == 0)
	{
		$stmt = phoromatic_server::$db->prepare('SELECT COUNT(Title) AS SystemCount FROM phoromatic_systems WHERE AccountID = :account_id AND State >= 0');
		$stmt->bindValue(':account_id', $_SESSION['AccountID']);
		$result = $stmt->execute();
		$row = $result->fetchArray();
		$sys_count = $row['SystemCount'];
	}
	return $sys_count;
}
function phoromatic_web_socket_server_ip()
{
	$server_ip = $_SERVER['HTTP_HOST'];
	if(($x = strpos($server_ip, ':')) !== false)
	{
		$server_ip = substr($server_ip, 0, $x);
	}

	if($server_ip == 'localhost' || $server_ip == '0.0.0.0')
	{
		$local_ip = phodevi::read_property('network', 'ip');
		if($local_ip)
		{
			$server_ip = $local_ip;
		}
	}
	// getenv('PTS_WEBSOCKET_PORT')
	return $server_ip . ':' . $_SERVER['SERVER_PORT'];
}
function phoromatic_web_socket_server_addr()
{
	// With the built-in HTTPS on, the string clients are given is the https:// one (https://host:39443/ACCOUNT)
	if(phoromatic_tls::is_on())
	{
		return phoromatic_tls::public_base() . '/' . $_SESSION['AccountID'];
	}
	return phoromatic_web_socket_server_ip() . '/' . $_SESSION['AccountID'];
}
function phoromatic_error_page($title, $description)
{
	echo phoromatic_webui_header(array(''), '');
	$box = '<h1>' . $title . '</h1>
		<h2>' . $description . '</h2>';
	echo phoromatic_webui_box($box);
	echo phoromatic_webui_footer();
}
function phoromatic_systems_needing_attention()
{
	$main = null;
	$stmt = phoromatic_server::$db->prepare('SELECT Title, SystemID, State, LastIP, LocalIP, LastCommunication, NetworkMAC, AdoptionCandidate, (SELECT c.LastCommunication FROM phoromatic_systems c WHERE c.AccountID = phoromatic_systems.AccountID AND c.SystemID = phoromatic_systems.AdoptionCandidate AND c.DeletedOn IS NULL) AS CandidateLastCommunication, (SELECT c.Title FROM phoromatic_systems c WHERE c.AccountID = phoromatic_systems.AccountID AND c.SystemID = phoromatic_systems.AdoptionCandidate AND c.DeletedOn IS NULL) AS CandidateTitle FROM phoromatic_systems WHERE AccountID = :account_id AND State = 0 AND DeletedOn IS NULL ORDER BY LastCommunication DESC');
	$stmt->bindValue(':account_id', $_SESSION['AccountID']);
	$result = $stmt->execute();
	if($row = $result->fetchArray())
	{
		$main .= '<div class="pts_phoromatic_info_box_area"><div style="float: left; width: 100%;"><ul><li><h1>Systems Needing Attention</h1></li><li class="light" style="font-weight: normal;">The following systems have attempted to sync with this Phoromatic account but have not been validated. When clicking on them you are able to approve or disable them from your account along with editing the system information.</li>';

		do
		{
			$ip = $row['LocalIP'];
			if($row['LastIP'] != $row['LocalIP'])
			{
				$ip .= ' / ' . $row['LastIP'];
			}

			if($row['AdoptionCandidate'] !== null && $row['AdoptionCandidate'] !== '')
			{
				// Same MAC address as an existing system but a different machine id: an administrator (level 1-2) decides
				$h_id = htmlspecialchars($row['SystemID']);
				$main .= '<li><a href="?systems/' . $h_id . '/edit">' . htmlspecialchars($row['Title']) . '</a><br /><em><strong>IP:</strong> ' . htmlspecialchars($ip) . ' <strong>Last Communication:</strong> ' . htmlspecialchars($row['LastCommunication']) . '</em><br /><strong>Reports the same MAC address as ' . ($row['CandidateTitle'] !== null ? htmlspecialchars($row['CandidateTitle']) . ' (' . htmlspecialchars($row['AdoptionCandidate']) . ')' : 'a system that no longer exists') . '.</strong> Is this the same machine reinstalled, or a different one?';
				$main .= phoromatic_system_admin::adoption_buttons($row['SystemID'], $row['NetworkMAC'], $row['CandidateTitle'], $row['CandidateLastCommunication']);
				$main .= '</li>';
				continue;
			}

			$main .= '<a href="?systems/' . $row['SystemID'] . '/edit"><li>' . htmlspecialchars($row['Title']) . '<br /><em><strong>IP:</strong> ' . htmlspecialchars($ip) . ' <strong>Last Communication:</strong> ' . $row['LastCommunication'] . '</em></li></a>';
		}
		while($row = $result->fetchArray());

		$main .= '</ul></div></div>';
	}

	// Benches on hold (no component, awaiting a swap, the class under test changed) or with a constant-part notice
	$bench_items = '';
	foreach(phoromatic_bench::attention_items($_SESSION['AccountID']) as $item)
	{
		$b = $item['bench'];
		$link = '?benches/' . (int)$b['BenchID'];
		$name = phoromatic_bench::h($b['Title']);
		$sys = $item['system'] ? phoromatic_bench::h($item['system']['Title']) : '';
		switch($item['kind'])
		{
			case 'no_session':
				$bench_items .= '<li><a href="' . $link . '">' . $name . '</a><br /><strong>Needs a component:</strong> no open session. Tests on hold.</li>';
				break;
			case 'awaiting':
				$bench_items .= '<li><a href="' . $link . '">' . $name . '</a><br /><strong>Awaiting swap</strong> on ' . $sys . ' (S' . (int)$item['session']['SessionID'] . ', ' . phoromatic_bench::h($item['session']['Label']) . '). Tests on hold.</li>';
				break;
			case 'drift':
				$f = $item['drift']['fields'][0];
				foreach($item['drift']['fields'] as $field)
				{
					$f = !empty($field['holds']) ? $field : $f;
				}
				$bench_items .= '<li><a href="' . $link . '/ack/' . phoromatic_bench::h($item['system']['SystemID']) . '">' . $name . '</a><br /><strong>' . phoromatic_bench::h(phoromatic_bench::class_name($f['key'])) . ' changed:</strong> ' . phoromatic_bench::h($f['baseline']) . ' &rarr; ' . phoromatic_bench::h($f['detected']) . ' on ' . $sys . '. Tests on hold.</li>';
				break;
			case 'notice':
				$parts = array();
				foreach(phoromatic_bench::notice_fields($item['drift']) as $f)
				{
					$parts[] = phoromatic_bench::h(phoromatic_bench::class_name($f['key']));
				}
				$bench_items .= '<li><a href="' . $link . '">' . $name . '</a><br /><strong>Constant part changed:</strong> ' . implode(', ', $parts) . ' on ' . $sys . '. Tests keep running; results marked.</li>';
				break;
		}
	}
	if($bench_items != '')
	{
		$main .= '<div class="pts_phoromatic_info_box_area bench_attention"><div style="float: left; width: 100%;"><ul><li><h1>Benches Needing Attention</h1></li>' . $bench_items . '</ul></div></div>';
	}

	return $main;
}
function phoromatic_oldest_result_for_schedule($schedule_id)
{
	static $old_time;

	if(!isset($old_time[$schedule_id]))
	{
		$stmt = phoromatic_server::$db->prepare('SELECT UploadTime FROM phoromatic_results WHERE AccountID = :account_id AND ScheduleID = :schedule_id ORDER BY UploadTime ASC LIMIT 1');
		$stmt->bindValue(':account_id', $_SESSION['AccountID']);
		$stmt->bindValue(':schedule_id', $schedule_id);
		$result = $stmt->execute();
		$row = $result->fetchArray();
		$old_time[$schedule_id] = $row['UploadTime'];
	}

	return $old_time[$schedule_id];
}
function phoromatic_task_html($current_task)
{
	// CurrentTask is stored already HTML-escaped (pts_strings::sanitize), so keep existing entities instead of escaping them twice;
	// anything stored raw still gets escaped
	return htmlspecialchars((string)$current_task, ENT_QUOTES, 'UTF-8', false);
}
function write_token_in_form()
{
	return '<input type="hidden" name="token_submit" value="' . (isset($_SESSION['Token']) && is_string($_SESSION['Token']) ? $_SESSION['Token'] : '') . '" />';
}
function verify_submission_token()
{
	return isset($_SESSION['Token']) && is_string($_SESSION['Token']) && $_SESSION['Token'] !== '' && isset($_REQUEST['token_submit']) && is_string($_REQUEST['token_submit']) && hash_equals($_SESSION['Token'], $_REQUEST['token_submit']);
}
function phoromatic_post_rejected()
{
	// A POST that carries fields but fails the same-origin check or the CSRF token (for pages whose forms all carry write_token_in_form())
	return $_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST) && (!phoromatic_private_repo_web::origin_ok() || !verify_submission_token());
}

?>
