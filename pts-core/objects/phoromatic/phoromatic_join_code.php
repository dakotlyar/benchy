<?php

/*
	Benchy
	phoromatic_join_code.php: join codes (server discovery D2, Benchy 0.10.0, design section 4)

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

// A join code is ten Crockford base32 characters an administrator reads out or types on a new machine: six random ones (R, 30 bits) and four
// check characters (C, 20 bits) that bind the code to the server that made it:
//
//     C = base32( first 20 bits of SHA-256( "benchy-join-v1" 0x00 R 0x00 anchor ) )
//
// R is the six characters as text (upper case). The anchor is what a client can find out about a server without trusting it:
//   internal HTTPS mode      the SHA-256 of the root CA, lower-case hex (64 characters), the same CA that ?benchy_ca serves
//   file and ACME modes      "name:" + the primary name (the first of the configured HTTPS names, lower case; names are plain ASCII, so it is
//                            already the IDNA A-label)
// The client works out the anchor of the server it is about to talk to, recomputes C and sends nothing when it differs, so a rogue server or a
// man in the middle never receives the code. The server stores only the SHA-256 of the ten characters (Kind 'join' in phoromatic_enrol_codes),
// keeps a code for an hour and takes it once.

class phoromatic_join_code
{
	const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
	const TAG = 'benchy-join-v1';
	const SCHEMA_STEP = 64;   // the Kind column; every "is the schema new enough" check uses this
	const TTL = 3600;
	// Failed join-code attempts across ALL addresses: this many inside GLOBAL_WINDOW seconds switch join codes off for PAUSE seconds. Pairing strings
	// are not affected. (The per-address lockout of phoromatic_agent_auth applies as well.)
	const GLOBAL_CAP = 100;
	const GLOBAL_WINDOW = 600;
	const PAUSE = 600;

	public static function ready()
	{
		return phoromatic_server::$db != null && phoromatic_server::read_database_version() >= self::SCHEMA_STEP;
	}

	// ----- the code

	protected static function encode($n, $chars)
	{
		$out = '';
		for($i = $chars - 1; $i >= 0; $i--)
		{
			$out .= self::ALPHABET[($n >> (5 * $i)) & 31];
		}
		return $out;
	}
	public static function check_chars($r, $anchor)
	{
		// The four check characters for six random ones and an anchor
		$h = hash('sha256', self::TAG . "\0" . $r . "\0" . $anchor, true);
		$n = (ord($h[0]) << 12) | (ord($h[1]) << 4) | (ord($h[2]) >> 4);   // the first 20 bits
		return self::encode($n, 4);
	}
	public static function make($anchor)
	{
		// A new code in its canonical form: ten upper-case characters, no separators
		$r = self::encode(random_int(0, (1 << 30) - 1), 6);
		return $r . self::check_chars($r, $anchor);
	}
	public static function normalize($text)
	{
		// What a person typed -> the canonical ten characters, or false. Case, hyphens and spaces are ignored; I and L read as 1, O as 0 (the
		// Crockford rules). U is not in the alphabet.
		if(!is_string($text))
		{
			return false;
		}
		$t = strtoupper(preg_replace('/[\s-]+/', '', $text));
		$t = strtr($t, 'ILO', '110');
		return preg_match('/^[0-9A-HJKMNP-TV-Z]{10}$/', $t) === 1 ? $t : false;
	}
	public static function verifies($code, $anchor)
	{
		// Whether the check characters of a canonical code fit the anchor (what a client does before sending anything)
		return is_string($code) && strlen($code) == 10 && hash_equals(self::check_chars(substr($code, 0, 6), $anchor), substr($code, 6));
	}
	public static function display($code)
	{
		return substr($code, 0, 3) . '-' . substr($code, 3, 3) . '-' . substr($code, 6);
	}
	public static function hash($code)
	{
		return hash('sha256', $code);
	}

	// ----- the anchor of this server

	public static function anchor($s = null)
	{
		// array('anchor' => text) or array('error' => why a code cannot be made now)
		$s = $s === null ? phoromatic_tls::settings() : $s;
		if($s['mode'] == 'off')
		{
			return array('error' => 'Join codes need the server\'s built-in HTTPS, which is off. Turn it on first (benchy server-https-set), or use a pairing string.');
		}
		if($s['mode'] == 'internal')
		{
			$fp = phoromatic_tls::root_ca_fingerprint();
			return $fp ? array('anchor' => strtolower($fp)) : array('error' => 'This server has no certificate authority of its own yet (the built-in HTTPS has not started). Start the server once, then try again.');
		}
		$name = empty($s['names']) ? '' : strtolower((string)$s['names'][0]);
		if($name === '' || strncmp($name, '*.', 2) == 0)
		{
			return array('error' => 'The first of this server\'s HTTPS names is ' . ($name === '' ? 'missing' : 'a wildcard (' . $name . ')') . '. A join code is tied to that first name, so it has to be the host name machines use: set it with benchy server-https-set, listing that name first.');
		}
		return array('anchor' => 'name:' . $name);
	}

	// ----- the global cap

	public static function paused()
	{
		// Seconds of the pause that are left, 0 when join codes are on. With no counter to read it fails closed (a minute).
		$stmt = @phoromatic_server::$db->prepare('SELECT MAX(At) AS at FROM phoromatic_v2_failures WHERE Kind = \'joinpause\' AND SourceIP = \'*\' AND At >= :since');
		if($stmt === false)
		{
			return 60;
		}
		$stmt->bindValue(':since', time() - self::PAUSE);
		$result = $stmt->execute();
		$row = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;
		if($row === false)
		{
			return 60;
		}
		return $row['at'] === null ? 0 : max(1, (int)$row['at'] + self::PAUSE - time());
	}
	public static function note_failure()
	{
		// One more failed join-code attempt from this address; the one that makes GLOBAL_CAP in GLOBAL_WINDOW starts the pause (and is audited once)
		$ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
		$now = time();
		if(!phoromatic_agent_auth::note_failure('join', $ip, $now))
		{
			return;
		}
		$stmt = @phoromatic_server::$db->prepare('SELECT COUNT(*) AS n FROM phoromatic_v2_failures WHERE Kind = \'join\' AND At >= :since');
		if($stmt === false)
		{
			return;
		}
		$stmt->bindValue(':since', $now - self::GLOBAL_WINDOW);
		$result = $stmt->execute();
		$row = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;
		if($row && (int)$row['n'] >= self::GLOBAL_CAP && self::paused() == 0)
		{
			phoromatic_agent_auth::note_failure('joinpause', '*', $now);
			phoromatic_admin_audit::record('', 'agent', 'unauthenticated', 'join_codes_paused', array('detail' => array('failures' => (int)$row['n'], 'window_s' => self::GLOBAL_WINDOW, 'paused_s' => self::PAUSE,
				'note' => 'Join codes are refused for a while; pairing strings still work.')));
		}
	}
	public static function pause_message($wait)
	{
		return 'Join codes are switched off for about ' . max(1, (int)ceil($wait / 60)) . ' more minute(s) because too many wrong ones were tried on this server. A pairing string still works; so does a join code after the wait.';
	}
}

?>
