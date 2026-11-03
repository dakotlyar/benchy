<?php
// Harness helper: one POST to the server from inside its own container, so the request comes from 127.0.0.1 and not from the host's
// docker gateway (the v2 lockouts are per source address, and a test that trips one must not lock the other tests out).
//   php post_local.php <path> <token or -> <json body>   prints the status line, then the body
$ctx = stream_context_create(array('http' => array('method' => 'POST', 'ignore_errors' => true, 'timeout' => 30,
	'header' => "Content-Type: application/json\r\n" . ($argv[2] !== '-' ? 'Authorization: Bearer ' . $argv[2] . "\r\n" : ''), 'content' => $argv[3])));
$body = file_get_contents('http://127.0.0.1:8080' . $argv[1], false, $ctx);
echo $http_response_header[0], "\n";
foreach($http_response_header as $h)
{
	if(stripos($h, 'Retry-After:') === 0)
	{
		echo $h, "\n";
	}
}
echo "\n", $body;
