<?php

/*
	Benchy
	phoromatic_tenant_context.php: the account a request is working for, set once at the front door of each entry point

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

// Set by the web bootstrap after login, by phoromatic.php after the client's account is checked, and by rss.php, event.php and the v2
// API once they know the account. Nothing set means nothing allowed: get() gives '' and phoromatic_server::phoromatic_account_path()
// (which every account file path is built from) refuses an account the request is not working for. The few places that read another
// account on purpose (a shared result, a new account being made) name it with allow() after their own check.
// Only web requests are held to this. A command run on the server host (or the event server) is the operator and names accounts itself.

class phoromatic_tenant_context
{
	protected static $account_id = '';
	protected static $also = array();

	public static function set($account_id)
	{
		self::$also = array();
		self::$account_id = ($id = phoromatic_tenant_path::segment($account_id)) === false ? '' : $id;
		return self::$account_id !== '';
	}
	public static function get()
	{
		return self::$account_id;
	}
	public static function allow($account_id, $folder = null)
	{
		// One more account this request may read besides its own, after the caller has checked that it may (sharing, creating the account):
		// the whole account, or with $folder only that folder of it (private-repo)
		if(($id = phoromatic_tenant_path::segment($account_id)) !== false)
		{
			if($folder === null)
			{
				self::$also[$id] = true;
			}
			else if(!isset(self::$also[$id]) || is_array(self::$also[$id]))
			{
				self::$also[$id][$folder] = true;
			}
		}
	}
	public static function allows($account_id, $folder = null)
	{
		if(!defined('PTS_MODE') || PTS_MODE !== 'WEB_CLIENT')
		{
			return true;
		}
		if(isset($_SESSION['AdminLevel']) && $_SESSION['AdminLevel'] == -40)
		{
			// The root login looks into any account (?admin_data)
			return true;
		}
		$account_id = (string)$account_id;
		if($account_id !== '' && $account_id === self::$account_id)
		{
			return true;
		}
		return isset(self::$also[$account_id]) && (self::$also[$account_id] === true || ($folder !== null && isset(self::$also[$account_id][$folder])));
	}
	public static function require_account($account_id, $folder = null)
	{
		if(!self::allows($account_id, $folder))
		{
			throw new RuntimeException('No permission to use account files here (the request is not working for that account)');
		}
	}
}

?>
