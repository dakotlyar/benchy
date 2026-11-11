<?php

/*
	Benchy
	phoromatic_feed.php: the key that goes in a results-feed (RSS) link

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

// A feed link is /rss.php?user=<UserID>&t=<AccountID>&v=<key> with key = HMAC-SHA256(server secret, UserID|AccountID|CreatedOn). The key used to
// be sha1 of the user's creation date, which anyone who could guess the day could compute. The server secret is 256 random bits made the
// first time one is needed and kept in the server's settings file; links made before this release stop working, and nothing else uses the
// secret, so replacing it (remove feed_secret from the settings file) invalidates every feed link at once.

class phoromatic_feed
{
	public static function secret()
	{
		// The secret, or false when it cannot be read or made (then no feed link can be made or accepted)
		$secret = phoromatic_server::read_setting('feed_secret');
		if(is_string($secret) && strlen($secret) >= 64)
		{
			return $secret;
		}
		// Made under the settings file's own lock (never the schema step's, which is held for a whole database copy), so two requests that both
		// find none cannot each make their own and no page waits for a migration. The schema 60 step makes it too, so on an upgraded server this
		// is only ever a read.
		return phoromatic_server::ensure_feed_secret();
	}
	public static function key($user_id, $account_id, $created_on)
	{
		// The hex key for this user in this account, or false
		if(($secret = self::secret()) === false)
		{
			return false;
		}
		return hash_hmac('sha256', (string)$user_id . '|' . (string)$account_id . '|' . (string)$created_on, $secret);
	}
	public static function valid($key, $user_id, $account_id, $created_on)
	{
		return is_string($key) && preg_match('/^[0-9a-f]{64}\z/', $key) === 1 && ($expected = self::key($user_id, $account_id, $created_on)) !== false && hash_equals($expected, $key);
	}
	public static function query($user_id, $account_id, $created_on)
	{
		// The query string of the link for the logged-in user (HTML-ready), or '' when no key can be made
		if(($key = self::key($user_id, $account_id, $created_on)) === false)
		{
			return '';
		}
		return 'user=' . rawurlencode((string)$user_id) . '&amp;t=' . rawurlencode((string)$account_id) . '&amp;v=' . $key;
	}
	public static function xml($text)
	{
		// Text for an XML element: markup characters escaped, and the control characters XML 1.0 cannot carry removed
		return htmlspecialchars(preg_replace('/[^\x09\x0a\x0d\x20-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', (string)$text) ?? '', ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}
}

?>
