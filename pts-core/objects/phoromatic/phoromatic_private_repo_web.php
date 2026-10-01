<?php

/*
	Benchy
	Web admin page for the private repository: shared guards and helpers.

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

// Used by pages/phoromatic_private_repo.php (renders) and pages/phoromatic_r_private_repo_action.php (POST handler).
// Everything is re-checked against the database on every request; the session is only used to find the user.

class phoromatic_private_repo_web
{
	const AUDIT_PAGE_SIZE = 50;
	const MAX_LIST_ROWS = 500;
	const NEW_TOKEN_TTL = 120;

	public static function h($value)
	{
		return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}
	public static function security_headers()
	{
		// Pages here hold admin controls and (once) a freshly created secret: never framed, never cached
		if(!headers_sent())
		{
			header('X-Frame-Options: DENY');
			header("Content-Security-Policy: frame-ancestors 'none'");
			header('X-Content-Type-Options: nosniff');
			header('Cache-Control: no-store, no-cache, must-revalidate, private');
			header('Pragma: no-cache');
			// same-origin, not no-referrer: a no-referrer page makes browsers send "Origin: null" with its own form posts
			header('Referrer-Policy: same-origin');
		}
	}
	public static function guard()
	{
		// array('user' => row) when the logged-in user may manage the private repository, else array('error' => message).
		// AdminLevel 1 or 2 only, read from the database now (not from what the session remembers), and only the account that
		// owns the repository (the server's oldest account, as for the CLI).
		self::expire_new_token();
		if(!isset($_SESSION['UserID']) || !isset($_SESSION['AccountID']) || phoromatic_server::$db == null)
		{
			return array('error' => 'Please log in.');
		}
		$stmt = phoromatic_server::$db->prepare('SELECT UserID, UserName, AccountID, AdminLevel FROM phoromatic_users WHERE UserID = :user_id AND AccountID = :account_id');
		$stmt->bindValue(':user_id', (string)$_SESSION['UserID']);
		$stmt->bindValue(':account_id', $_SESSION['AccountID']);
		$result = $stmt->execute();
		$row = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;
		if(!$row || $row['AdminLevel'] < 1 || $row['AdminLevel'] > 2)
		{
			unset($_SESSION['PrivateRepoNewToken']);
			return array('error' => 'The private repository is managed by account administrators only.');
		}
		$_SESSION['AdminLevel'] = $row['AdminLevel'];
		if($row['AccountID'] !== phoromatic_private_repo::server_account_id())
		{
			return array('error' => 'For now the private repository belongs to the first account on this server.');
		}
		return array('user' => $row);
	}
	protected static function normalised_origin($url, $default_scheme = 'http')
	{
		// "host[:port]" lower-cased, with the port dropped when it is the scheme's default; false if there is no host
		$parts = parse_url($url);
		if(!is_array($parts) || empty($parts['host']))
		{
			return false;
		}
		$scheme = isset($parts['scheme']) ? strtolower($parts['scheme']) : $default_scheme;
		$port = isset($parts['port']) ? (int)$parts['port'] : null;
		if($port === ($scheme == 'https' ? 443 : 80))
		{
			$port = null;
		}
		return strtolower($parts['host']) . ($port ? ':' . $port : '');
	}
	public static function origin_ok()
	{
		// Cheap extra CSRF sanity on top of the token: when the browser says where the POST came from, it must be this host.
		// "Origin: null" (privacy modes, some redirects) says nothing, so it counts as absent and the CSRF token decides.
		// X-Forwarded-Host is deliberately not trusted.
		$scheme = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';
		$expected = isset($_SERVER['HTTP_HOST']) ? self::normalised_origin($scheme . '://' . $_SERVER['HTTP_HOST'], $scheme) : false;
		foreach(array('HTTP_ORIGIN', 'HTTP_REFERER') as $key)
		{
			if(!empty($_SERVER[$key]) && $_SERVER[$key] !== 'null')
			{
				$got = self::normalised_origin($_SERVER[$key], $scheme);
				return $got !== false && $expected !== false && $got === $expected;
			}
		}
		return true;
	}
	public static function expire_new_token()
	{
		// The one-time token waits in the session only for NEW_TOKEN_TTL seconds; every guarded request enforces that
		if(isset($_SESSION['PrivateRepoNewToken']) && (!is_array($_SESSION['PrivateRepoNewToken']) || !isset($_SESSION['PrivateRepoNewToken']['time']) || time() - $_SESSION['PrivateRepoNewToken']['time'] > self::NEW_TOKEN_TTL))
		{
			unset($_SESSION['PrivateRepoNewToken']);
		}
	}
	public static function flash($type, $message)
	{
		$_SESSION['PrivateRepoFlash'] = array('type' => $type, 'message' => $message);
	}
	public static function take_flash()
	{
		$flash = isset($_SESSION['PrivateRepoFlash']) ? $_SESSION['PrivateRepoFlash'] : null;
		unset($_SESSION['PrivateRepoFlash']);
		return $flash;
	}
	public static function stash_new_token($token, $id, $label)
	{
		// Handed from the POST handler to the next page view, once. It is removed on that view (or ignored after NEW_TOKEN_TTL).
		$_SESSION['PrivateRepoNewToken'] = array('token' => $token, 'id' => $id, 'label' => $label, 'time' => time());
	}
	public static function take_new_token()
	{
		$new = isset($_SESSION['PrivateRepoNewToken']) ? $_SESSION['PrivateRepoNewToken'] : null;
		unset($_SESSION['PrivateRepoNewToken']);
		return is_array($new) && time() - $new['time'] <= self::NEW_TOKEN_TTL ? $new : null;
	}
	public static function size($bytes)
	{
		return $bytes >= 1048576 ? round($bytes / 1048576, 1) . ' MB' : max(1, round($bytes / 1024)) . ' KB';
	}
	public static function actor_fields($user)
	{
		// For audit rows made from the web: the logged-in user is the actor
		return array('actor_type' => 'user', 'actor' => $user['UserName'], 'actor_detail' => 'UserID ' . $user['UserID']);
	}
}

?>
