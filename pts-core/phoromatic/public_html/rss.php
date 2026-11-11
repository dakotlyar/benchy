<?php

/*
	Phoronix Test Suite
	URLs: http://www.phoronix.com, http://www.phoronix-test-suite.com/
	Copyright (C) 2015, Phoronix Media
	Copyright (C) 2015, Michael Larabel

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

include('../phoromatic_functions.php');
phoromatic_init_web_page_setup();
phoromatic_server::prepare_database();

// The link names the user, the account and a key made from them with the server's secret (phoromatic_feed). The user must still be an
// enabled user of that account, and the account must be active; any other combination is the same empty answer.
$user_id = isset($_GET['user']) && is_string($_GET['user']) ? phoromatic_tenant_path::segment($_GET['user']) : false;
$account_id = isset($_GET['t']) && is_string($_GET['t']) ? phoromatic_tenant_path::segment($_GET['t']) : false;
$key = isset($_GET['v']) && is_string($_GET['v']) ? $_GET['v'] : '';
$user_name = null;
if($user_id !== false && $account_id !== false)
{
	$stmt = phoromatic_server::$db->prepare('SELECT CreatedOn, UserName FROM phoromatic_users WHERE UserID = :user_id AND AccountID = :account_id AND typeof(AdminLevel) = \'integer\' AND AdminLevel IN (1, 2, 3, 10)');
	$stmt->bindValue(':user_id', $user_id);
	$stmt->bindValue(':account_id', $account_id);
	$result = $stmt->execute();
	$matching_user = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;
	if(!empty($matching_user) && phoromatic_feed::valid($key, $user_id, $account_id, $matching_user['CreatedOn']))
	{
		$user_name = $matching_user['UserName'];
	}
}

if($user_name === null || !phoromatic_tenant::is_active($account_id))
{
	exit; // no such feed, or the account is suspended or removed
}
phoromatic_tenant_context::set($account_id);

header('Content-Type: text/xml; charset=utf-8');

echo '<?xml version="1.0"?>
<rss version="2.0">
 <channel>
	<title>Phoromatic - ' . phoromatic_feed::xml($user_name) . '</title>
	<link>' . phoromatic_feed::xml($_SERVER['HTTP_HOST']) . '</link>
	<description>Phoronix Test Suite Phoromatic feed.</description>
	<language>en-us</language>';

// This account's own results only (results are not shared between accounts)
$stmt = phoromatic_server::$db->prepare('SELECT Title, Description, PPRID, UploadTime FROM phoromatic_results WHERE AccountID = :account_id ORDER BY UploadTime DESC LIMIT 30');
$stmt->bindValue(':account_id', $account_id);
$test_result_result = $stmt->execute();
$results = 0;
while($row = $test_result_result->fetchArray())
{

	echo '  <item>' . PHP_EOL;
	echo '   <title>' . phoromatic_feed::xml($row['Title']) . '</title>' . PHP_EOL;
	echo '   <link>' . phoromatic_feed::xml((phoromatic_tls::is_on() ? phoromatic_tls::public_base() : 'http://' . $_SERVER['HTTP_HOST']) . '/?result/' . $row['PPRID']) . '</link>' . PHP_EOL;
	echo '   <guid>' . phoromatic_feed::xml($row['PPRID']) . '</guid>' . PHP_EOL;
	echo '   <description>' . phoromatic_feed::xml($row['Description']) . '</description>' . PHP_EOL;
	echo '   <pubDate>' . date('r', strtotime($row['UploadTime'])) . '</pubDate>' . PHP_EOL;
	echo '  </item>' . PHP_EOL;
}

echo ' </channel>
</rss>';

?>
