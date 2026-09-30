<?php

/*
	Benchy
	Client transport for the private test profile / test suite repository hosted by a Phoromatic Server.

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

class pts_private_repo_client
{
	const MAX_UPLOAD_BYTES = 5242880;
	protected static $last_error = null;

	public static function repository_name()
	{
		return phoromatic_private_repo::repository_name();
	}
	public static function is_private_repository($repo_name)
	{
		$private_repo = self::repository_name();
		return !empty($private_repo) && $repo_name == $private_repo;
	}
	public static function last_error()
	{
		return self::$last_error;
	}
	public static function connection_file()
	{
		return PTS_USER_PATH . 'private-repo-server';
	}
	public static function save_connection($server_string)
	{
		pts_file_io::mkdir(PTS_USER_PATH);
		return file_put_contents(self::connection_file(), $server_string) !== false;
	}
	public static function parse_connection($server_string)
	{
		// ip:port/ACCOUNT
		if(preg_match('/^([^\/:\s]+):([0-9]+)\/([A-Za-z0-9]+)$/', trim($server_string), $m))
		{
			return array('address' => $m[1], 'port' => $m[2], 'account_id' => $m[3]);
		}
		return false;
	}
	public static function connection()
	{
		// The explicitly configured server, else the one the Phoromatic client module last connected to
		$candidates = array(self::connection_file(), PTS_USER_PATH . 'modules-data/phoromatic/last-phoromatic-server');
		foreach($candidates as $file)
		{
			if(is_file($file) && ($connection = self::parse_connection(file_get_contents($file))))
			{
				return $connection;
			}
		}

		return false;
	}
	protected static function request($resource, $post = array(), $timeout = 60)
	{
		self::$last_error = null;

		if(!($connection = self::connection()))
		{
			self::$last_error = 'No Phoromatic Server configured for the private repository. Run: private-repo-setup IP:PORT/ACCOUNT';
			return false;
		}
		if(!pts_network::network_support_available())
		{
			self::$last_error = 'Network support is unavailable.';
			return false;
		}

		static $identity = null;
		if($identity == null)
		{
			$identity = array(
				'h' => phodevi::system_hardware(true),
				's' => phodevi::system_software(true),
				'n' => phodevi::read_property('system', 'hostname'),
				'lip' => phodevi::read_property('network', 'ip'),
				'nm' => phodevi::read_property('network', 'mac-address')
				);
		}

		$to_post = array_merge($identity, $post, array(
			'r' => $resource,
			'aid' => $connection['account_id'],
			'pts' => PTS_VERSION,
			'pts_core' => PTS_CORE_VERSION,
			'msi' => PTS_MACHINE_SELF_ID
			));

		$response = pts_network::http_upload_via_post('http://' . $connection['address'] . ':' . $connection['port'] . '/phoromatic.php', $to_post, false, $timeout);
		$json = $response ? json_decode($response, true) : false;

		if(!is_array($json) || !isset($json['phoromatic']))
		{
			self::$last_error = 'No valid response from the Phoromatic Server at ' . $connection['address'] . ':' . $connection['port'] . '.';
			return false;
		}
		if(isset($json['phoromatic']['error']))
		{
			self::$last_error = $json['phoromatic']['error'];
			return false;
		}
		foreach(array('index', 'unchanged', 'upload', 'zip', 'delete', 'rebuild') as $payload)
		{
			if(isset($json['phoromatic'][$payload]))
			{
				return $json['phoromatic'];
			}
		}

		// Only a status message came back (e.g. this machine is not yet approved by the account)
		self::$last_error = isset($json['phoromatic']['response']) ? $json['phoromatic']['response'] : 'Unexpected response from the Phoromatic Server.';
		return false;
	}
	public static function index($if_generated = null)
	{
		// Returns the index as a JSON string, true if unchanged since $if_generated, or false on failure
		if(defined('PHOROMATIC_SERVER'))
		{
			$index = phoromatic_private_repo::read_index(phoromatic_private_repo::server_account_id());
			return $index == false ? false : json_encode($index);
		}

		$response = self::request('private_repo_index', array('if_generated' => $if_generated));
		if($response === false)
		{
			return false;
		}
		if(isset($response['unchanged']))
		{
			return true;
		}
		return isset($response['index']) ? json_encode($response['index']) : false;
	}
	public static function fetch($kind, $id)
	{
		// Returns the verified zip file contents, or false
		if(defined('PHOROMATIC_SERVER'))
		{
			$package = phoromatic_private_repo::read_package(phoromatic_private_repo::server_account_id(), $kind, $id);
			return $package == false ? false : $package[1];
		}

		$response = self::request('private_repo_fetch', array('kind' => $kind, 'id' => $id));
		if($response === false || !isset($response['zip']) || !isset($response['sha1']))
		{
			return false;
		}

		$zip = base64_decode($response['zip']);
		if(empty($zip) || sha1($zip) != $response['sha1'])
		{
			self::$last_error = 'Checksum mismatch downloading ' . $id . ' from the private repository.';
			return false;
		}

		return $zip;
	}
	public static function upload($kind, $name, $zip_file, $commit_description, $overwrite = false)
	{
		if(filesize($zip_file) > self::MAX_UPLOAD_BYTES)
		{
			self::$last_error = 'The package is larger than ' . (self::MAX_UPLOAD_BYTES / 1048576) . ' MB. Large payloads should be referenced through downloads.xml instead of bundled.';
			return false;
		}

		$response = self::request('private_repo_upload', array(
			'kind' => $kind,
			'name' => $name,
			'zip' => base64_encode(file_get_contents($zip_file)),
			'zip_sha1' => sha1_file($zip_file),
			'commit_description' => $commit_description,
			'overwrite' => ($overwrite ? 1 : 0)
			), 300);

		return $response !== false && isset($response['upload']) ? $response['upload'] : false;
	}
	public static function delete($kind, $name, $version)
	{
		$response = self::request('private_repo_delete', array('kind' => $kind, 'name' => $name, 'version' => $version));
		return $response !== false && isset($response['delete']) ? $response['delete'] : false;
	}
	public static function rebuild()
	{
		$response = self::request('private_repo_rebuild');
		return $response !== false && isset($response['rebuild']) ? $response['rebuild'] : false;
	}
}

?>
