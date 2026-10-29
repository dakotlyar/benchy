<?php
// Benchy HTTPS mode: run before every PHP script of the Phoromatic web server (php -d auto_prepend_file). PHP listens on loopback behind
// Caddy; Caddy sets the X-Benchy-* request headers (overwriting anything the client sent) and the secret that proves it was Caddy.
// Only a local process can reach the loopback port, and it has no business bypassing Caddy: no secret, no service. No includes.
$benchy_secret = getenv('BENCHY_PROXY_SECRET');
if($benchy_secret !== false && $benchy_secret !== '')
{
	// PHP maps X-Benchy-Client-Ip and X_Benchy_Client_Ip to the same $_SERVER key, and the later one wins. Caddy only overwrites the
	// hyphenated spelling, so a client's underscore spelling (or any other X-Benchy-* header) must stop here, before anything is trusted.
	$benchy_allowed = array('x-benchy-proxy', 'x-benchy-client-ip', 'x-benchy-proto', 'x-benchy-port');
	$benchy_seen = array();
	foreach((function_exists('getallheaders') ? getallheaders() : array()) as $benchy_name => $benchy_value)
	{
		$benchy_norm = strtolower(str_replace('_', '-', (string)$benchy_name));
		if(strpos($benchy_norm, 'x-benchy-') === 0 && (strpos((string)$benchy_name, '_') !== false || !in_array($benchy_norm, $benchy_allowed, true) || isset($benchy_seen[$benchy_norm])))
		{
			http_response_code(403);
			echo 'This port is not for direct use.' . PHP_EOL;
			exit;
		}
		$benchy_seen[$benchy_norm] = true;
	}
	$benchy_ip = isset($_SERVER['HTTP_X_BENCHY_CLIENT_IP']) ? $_SERVER['HTTP_X_BENCHY_CLIENT_IP'] : '';
	if(!isset($_SERVER['HTTP_X_BENCHY_PROXY']) || !hash_equals($benchy_secret, (string)$_SERVER['HTTP_X_BENCHY_PROXY']) || !filter_var($benchy_ip, FILTER_VALIDATE_IP))
	{
		http_response_code(403);
		echo 'This port is not for direct use.' . PHP_EOL;
		exit;
	}
	$_SERVER['REMOTE_ADDR'] = $benchy_ip;
	if(isset($_SERVER['HTTP_X_BENCHY_PROTO']) && $_SERVER['HTTP_X_BENCHY_PROTO'] === 'https')
	{
		$_SERVER['HTTPS'] = 'on';
		$_SERVER['REQUEST_SCHEME'] = 'https';
	}
	else
	{
		unset($_SERVER['HTTPS']);
		$_SERVER['REQUEST_SCHEME'] = 'http';
	}
	if(isset($_SERVER['HTTP_X_BENCHY_PORT']) && ctype_digit((string)$_SERVER['HTTP_X_BENCHY_PORT']))
	{
		$_SERVER['SERVER_PORT'] = $_SERVER['HTTP_X_BENCHY_PORT'];
	}
	unset($_SERVER['HTTP_X_BENCHY_PROXY'], $_SERVER['HTTP_X_BENCHY_CLIENT_IP'], $_SERVER['HTTP_X_BENCHY_PROTO'], $_SERVER['HTTP_X_BENCHY_PORT']);
}
unset($benchy_secret, $benchy_ip, $benchy_allowed, $benchy_seen, $benchy_name, $benchy_value, $benchy_norm);
