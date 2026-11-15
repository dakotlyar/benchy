<?php

/*
	Benchy
	Server administration of Phoromatic accounts (tenants): suspend.

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

class server_tenant_suspend implements pts_option_interface
{
	const doc_section = 'Phoromatic';
	const doc_description = 'Run on the Phoromatic Server host, as the user that owns the server\'s database (e.g. sudo -u USER benchy ...). Suspends an account: its users cannot sign in and its systems and agents are refused until it is resumed. Nothing is deleted. Refused while systems of the account are running a task unless --even-if-running is given. Usage: server-tenant-suspend ACCOUNT [--reason TEXT] [--even-if-running]';

	public static function run($r)
	{
		phoromatic_tenant_cli::run('suspend', $r);
	}
}

?>
