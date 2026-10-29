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
	public static function require_enabled()
	{
		if(self::repository_name() == false)
		{
			echo PHP_EOL . 'The private repository is disabled or its name is invalid. Set PhoronixTestSuite/Options/OpenBenchmarking/PrivateRepository in the user configuration to a name like "private" (it cannot be pts, system, git, local or windows).' . PHP_EOL . PHP_EOL;
			return false;
		}
		return true;
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
	public static function token_file()
	{
		return PTS_USER_PATH . 'private-repo-token';
	}
	public static function token()
	{
		// The environment wins over the saved file; false if neither holds one
		$env = getenv('BENCHY_PRIVATE_REPO_TOKEN');
		if(is_string($env) && trim($env) !== '')
		{
			return trim($env);
		}
		if(is_file(self::token_file()) && ($saved = trim(file_get_contents(self::token_file()))) !== '')
		{
			return $saved;
		}
		return false;
	}
	public static function save_token($token, $file = null)
	{
		// The file exists with owner-only permissions before the secret is written to it, and replaces (never follows) any
		// symlink that was there (the repository token file unless another is given)
		pts_file_io::mkdir(PTS_USER_PATH);
		$file = $file === null ? self::token_file() : $file;
		$tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
		$old_umask = umask(0077);
		$fh = @fopen($tmp, 'x');
		umask($old_umask);
		if($fh === false)
		{
			return false;
		}
		@chmod($tmp, 0600);
		$ok = fwrite($fh, $token . PHP_EOL) === strlen($token) + strlen(PHP_EOL);
		fclose($fh);
		if(!$ok || !@rename($tmp, $file))
		{
			@unlink($tmp);
			return false;
		}
		return true;
	}
	public static function parse_connection($server_string)
	{
		// [https://]ip:port/ACCOUNT (no scheme = http)
		if(($a = pts_server_address::parse($server_string)) !== false)
		{
			return array('address' => $a['host'], 'port' => (string)$a['port'], 'account_id' => $a['account'], 'scheme' => $a['scheme']);
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
	protected static function request($resource, $post = array(), $timeout = 60, $needs_repository = true)
	{
		self::$last_error = null;

		if($needs_repository && self::repository_name() == false)
		{
			self::$last_error = 'The private repository is disabled.';
			return false;
		}
		if(!($connection = self::connection()))
		{
			self::$last_error = 'No Phoromatic Server configured. Run: ' . ($needs_repository ? 'private-repo-setup IP:PORT/ACCOUNT' : 'phoromatic.connect IP:PORT/ACCOUNT (or private-repo-setup IP:PORT/ACCOUNT)');
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

		pts_server_tls::register($connection['address'], $connection['port']);
		$to_post = array_merge($identity, $post, array(
			'r' => $resource,
			'aid' => $connection['account_id'],
			'pts' => PTS_VERSION,
			'pts_core' => PTS_CORE_VERSION,
			'msi' => PTS_MACHINE_SELF_ID
			));

		$response = pts_network::http_upload_via_post(pts_server_address::base_url($connection['scheme'], $connection['address'], $connection['port']) . '/phoromatic.php', $to_post, false, $timeout);
		$json = $response ? json_decode($response, true) : false;

		if(!is_array($json) || !isset($json['phoromatic']))
		{
			self::$last_error = 'No valid response from the Phoromatic Server at ' . $connection['address'] . ':' . $connection['port'] . '.';
			return false;
		}
		if(isset($json['phoromatic']['error']))
		{
			self::$last_error = $json['phoromatic']['error'];
			if(isset($post['zip']) && strpos($json['phoromatic']['error'], 'You must update') === 0)
			{
				// phoromatic.php sees an empty request when PHP drops a POST over post_max_size
				self::$last_error = 'The server did not receive the upload; it probably exceeds the Phoromatic host\'s PHP post_max_size (set it to 16M).';
			}
			return false;
		}
		foreach(array('index', 'unchanged', 'upload', 'zip', 'delete', 'rebuild', 'bench') as $payload)
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
	protected static function with_token($post)
	{
		// Upload, delete and rebuild need the repository token on top of an approved machine
		return ($token = self::token()) !== false ? array_merge($post, array('rt' => $token)) : $post;
	}
	public static function upload($kind, $name, $zip_file, $commit_description, $overwrite = false)
	{
		if(filesize($zip_file) > self::MAX_UPLOAD_BYTES)
		{
			self::$last_error = 'The package is larger than ' . (self::MAX_UPLOAD_BYTES / 1048576) . ' MB. Large payloads should be referenced through downloads.xml instead of bundled.';
			return false;
		}

		$response = self::request('private_repo_upload', self::with_token(array(
			'kind' => $kind,
			'name' => $name,
			'zip' => base64_encode(file_get_contents($zip_file)),
			'zip_sha1' => sha1_file($zip_file),
			'commit_description' => $commit_description,
			'overwrite' => ($overwrite ? 1 : 0)
			)), 300);

		return $response !== false && isset($response['upload']) ? $response['upload'] : false;
	}
	public static function delete($kind, $name, $version)
	{
		$response = self::request('private_repo_delete', self::with_token(array('kind' => $kind, 'name' => $name, 'version' => $version)));
		return $response !== false && isset($response['delete']) ? $response['delete'] : false;
	}
	public static function read_secret($prompt)
	{
		// stdin, never argv. Hidden while typing at a terminal (stty on Unix, PowerShell on Windows); echo is restored on any exit,
		// including Ctrl-C when pcntl is available.
		$tty = function_exists('stream_isatty') && stream_isatty(STDIN);
		echo PHP_EOL . $prompt;

		if($tty && DIRECTORY_SEPARATOR == '\\')
		{
			$line = shell_exec('powershell -NoProfile -Command "$p = Read-Host -AsSecureString; [Runtime.InteropServices.Marshal]::PtrToStringAuto([Runtime.InteropServices.Marshal]::SecureStringToBSTR($p))"');
			if($line !== null && $line !== false)
			{
				return trim($line);
			}
			echo PHP_EOL . 'WARNING: could not hide the input; what you type will be visible.' . PHP_EOL . $prompt;
		}
		else if($tty)
		{
			$restore = function() {
				shell_exec('stty echo 2>/dev/null');
			};
			shell_exec('stty -echo 2>/dev/null');
			register_shutdown_function($restore);
			if(function_exists('pcntl_async_signals') && function_exists('pcntl_signal'))
			{
				pcntl_async_signals(true);
				foreach(array(SIGINT, SIGTERM) as $signal)
				{
					pcntl_signal($signal, function() use ($restore) {
						$restore();
						echo PHP_EOL;
						exit(130);
					});
				}
			}
			$line = fgets(STDIN);
			$restore();
			echo PHP_EOL;
			return $line === false ? false : trim($line);
		}

		$line = fgets(STDIN);
		echo PHP_EOL;
		return $line === false ? false : trim($line);
	}
	public static function bench_token_file()
	{
		return PTS_USER_PATH . 'bench-token';
	}
	public static function bench_token()
	{
		// BENCHY_BENCH_TOKEN wins over the file saved by bench-login; false if neither holds one
		$env = getenv('BENCHY_BENCH_TOKEN');
		if(is_string($env) && trim($env) !== '')
		{
			return trim($env);
		}
		return is_file(self::bench_token_file()) && ($saved = trim(file_get_contents(self::bench_token_file()))) !== '' ? $saved : false;
	}
	public static function bench_action($act, $args = array())
	{
		// The bench_action resource (bench-status, bench-swap, bench-end): the reply's 'bench' part, or false (last_error() says why).
		// Talks to the private repository server or, without one, the Phoromatic server this client last connected to.
		$post = array('act' => $act, 'args' => json_encode($args));
		if($act != 'status' && ($token = self::bench_token()) !== false)
		{
			$post['bt'] = $token;
		}
		$response = self::request('bench_action', $post, 60, false);
		return $response !== false && isset($response['bench']) ? $response['bench'] : false;
	}
	public static function rebuild()
	{
		$response = self::request('private_repo_rebuild', self::with_token(array()));
		return $response !== false && isset($response['rebuild']) ? $response['rebuild'] : false;
	}
}

?>
