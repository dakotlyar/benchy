<?php

/*
	Benchy
	Server administration of Phoromatic accounts (tenants): purge.

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

class server_tenant_purge implements pts_option_interface
{
	const doc_section = 'Phoromatic';
	const doc_description = 'Run on the Phoromatic Server host, as the user that owns the server\'s database (e.g. sudo -u USER benchy ...). Permanently destroys a DELETED account\'s rows and files, with no way back; the audit keeps the record and the account ID is never used again. Never automatic. The account\'s name and its account ID must both be typed. Usage: server-tenant-purge ACCOUNT --confirm-name NAME --confirm-id ACCOUNT-ID';

	public static function run($r)
	{
		phoromatic_tenant_cli::run('purge', $r);
	}
}

?>
