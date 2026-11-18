<?php

/*
	Phoronix Test Suite
	URLs: http://www.phoronix.com, http://www.phoronix-test-suite.com/
	Copyright (C) 2008 - 2020, Phoronix Media
	Copyright (C) 2008 - 2020, Michael Larabel
	update_checker.php: Kept as an empty module (no upstream version check) so an AutoLoadModules entry for it still loads.

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

class update_checker extends pts_module_interface
{
	const module_name = 'Update Checker';
	const module_version = '0.4.0';
	const module_description = 'This module used to ask the upstream Phoronix Test Suite site for its latest version. Benchy no longer makes that call, so it only stays for configurations that still list it in AutoLoadModules.';
	const module_author = 'Phoronix Media';

	public static function __pre_option_process()
	{
		return pts_module::MODULE_UNLOAD;
	}
}

?>
