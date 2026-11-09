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

// Set by the web bootstrap after login and by phoromatic.php after the client's account is checked. Nothing set means nothing allowed:
// get() gives '' and a caller that needs an account refuses an empty one.

class phoromatic_tenant_context
{
	protected static $account_id = '';

	public static function set($account_id)
	{
		self::$account_id = ($id = phoromatic_tenant_path::segment($account_id)) === false ? '' : $id;
		return self::$account_id !== '';
	}
	public static function get()
	{
		return self::$account_id;
	}
}

?>
