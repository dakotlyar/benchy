<?php

/*
	Benchy
	phoromatic_catalog_zip.php: a raw-record zip reader for catalog bundles (E4.4 C1, catalog spec 7.1 and 7.2)

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

// A hand-written reader of the raw zip records, for the bundle of catalog spec 7.2. PHP's ZipArchive accepts prefix and trailing data and
// doesn't report a local header that disagrees with the central directory, so it can't be used (catalog spec 7.1). This is a port of
// benchy-engine internal/pack/zipcheck.go (Inspect, checkLocal, Open) with the bundle policy, and it refuses with the same reason words in
// the same order of checks. The data descriptor rule (design S1): when a local header has general-purpose bit 3 set, its CRC and sizes are
// not compared (Go's writer leaves zeros there) and the descriptor right after the entry's data, with or without its PK\x07\x08 signature,
// must agree with the central directory.

class phoromatic_catalog_zip
{
	const MAX_BYTES = 134217728;           // 128 MiB, the whole bundle
	const MAX_ENTRIES = 1002;
	const MAX_INDEX_SIGNED = 16781312;     // 16 MiB plus 4 KiB
	const MAX_PACK = 67108864;             // 64 MiB
	const MAX_UNPACKED = 167772160;        // the bundle limit plus 32 MiB
	const MAX_ENGINE_FILE = 134217728;     // one engine archive in an engine bundle (the whole bundle is also at most MAX_BYTES)
	const RATIO = 100;
	const CHUNK = 16384;

	public $data;
	public $entries = array();             // list of entry arrays, central directory order
	public $by_name = array();
	private $engine_bundle = false;       // an engine bundle (engine.signed and the archives it names) instead of a catalog bundle

	// Checks every record of the zip in $data, before anything is decompressed. Throws phoromatic_catalog_refusal.
	public static function inspect($data, $engine_bundle = false)
	{
		$z = new self();
		$z->data = $data;
		$z->engine_bundle = $engine_bundle;
		$z->run();
		return $z;
	}

	private static function bad($msg)
	{
		return new phoromatic_catalog_refusal(phoromatic_catalog_refusal::ZIP_STRUCTURE, $msg);
	}

	private static function refuse($reason, $msg)
	{
		return new phoromatic_catalog_refusal($reason, $msg);
	}

	private function u16($off)
	{
		return ord($this->data[$off]) | (ord($this->data[$off + 1]) << 8);
	}

	private function u32($off)
	{
		return ord($this->data[$off]) | (ord($this->data[$off + 1]) << 8) | (ord($this->data[$off + 2]) << 16) | (ord($this->data[$off + 3]) << 24);
	}

	private function run()
	{
		$d = $this->data;
		$n = strlen($d);
		if($n > self::MAX_BYTES)
		{
			throw self::refuse(phoromatic_catalog_refusal::PACK_LIMIT, 'the zip is ' . $n . ' bytes; the limit is ' . self::MAX_BYTES);
		}
		if($n < 22)
		{
			throw self::bad('this is not a zip: it is shorter than an end record');
		}
		$e = $n - 22;
		if($this->u32($e) !== 0x06054b50)
		{
			throw self::bad('the zip does not end with its end record (trailing data, or a zip comment)');
		}
		if($n >= 22 + 20 && $this->u32($n - 22 - 20) === 0x07064b50)
		{
			throw self::bad('zip64 records are not allowed');
		}
		if($this->u16($e + 20) !== 0)
		{
			throw self::bad('a zip comment is not allowed');
		}
		$disk = $this->u16($e + 4);
		$cd_disk = $this->u16($e + 6);
		$here = $this->u16($e + 8);
		$total = $this->u16($e + 10);
		$cd_size = $this->u32($e + 12);
		$cd_off = $this->u32($e + 16);
		if($disk !== 0 || $cd_disk !== 0 || $here !== $total)
		{
			throw self::bad('a multi-disk zip is not allowed');
		}
		if($total === 0xFFFF || $cd_size === 0xFFFFFFFF || $cd_off === 0xFFFFFFFF)
		{
			throw self::bad('zip64 records are not allowed');
		}
		if($total > self::MAX_ENTRIES)
		{
			throw self::refuse(phoromatic_catalog_refusal::PACK_LIMIT, 'the zip has ' . $total . ' entries; the limit is ' . self::MAX_ENTRIES);
		}
		if($cd_off + $cd_size !== $n - 22)
		{
			throw self::bad('the central directory does not end where the end record starts (data between or after them)');
		}

		$pos = $cd_off;
		$cd_end = $cd_off + $cd_size;
		for($i = 0; $i < $total; $i++)
		{
			if($pos + 46 > $cd_end || $this->u32($pos) !== 0x02014b50)
			{
				throw self::bad('entry ' . ($i + 1) . ': the central directory record is damaged');
			}
			$made_by = $this->u16($pos + 4);
			$flags = $this->u16($pos + 8);
			$method = $this->u16($pos + 10);
			$crc = $this->u32($pos + 16);
			$cs = $this->u32($pos + 20);
			$us = $this->u32($pos + 24);
			$nl = $this->u16($pos + 28);
			$xl = $this->u16($pos + 30);
			$cl = $this->u16($pos + 32);
			$start_disk = $this->u16($pos + 34);
			$attrs = $this->u32($pos + 38);
			$off = $this->u32($pos + 42);
			$next = $pos + 46 + $nl + $xl + $cl;
			if($next > $cd_end)
			{
				throw self::bad('entry ' . ($i + 1) . ': the central directory record runs past the directory');
			}
			$raw_name = substr($d, $pos + 46, $nl);
			if($start_disk !== 0)
			{
				throw self::bad('a multi-disk zip is not allowed');
			}
			if($cs === 0xFFFFFFFF || $us === 0xFFFFFFFF || $off === 0xFFFFFFFF)
			{
				throw self::bad('zip64 records are not allowed');
			}
			$this->check_extra(substr($d, $pos + 46 + $nl, $xl), 'central directory');
			if(($flags & ((1 << 0) | (1 << 6) | (1 << 13))) !== 0)
			{
				throw self::refuse(phoromatic_catalog_refusal::ZIP_METHOD, $raw_name . ': encrypted entries are not allowed');
			}
			if($method !== 0 && $method !== 8)
			{
				throw self::refuse(phoromatic_catalog_refusal::ZIP_METHOD, $raw_name . ': compression method ' . $method . '; only store and deflate are allowed');
			}
			$ent = array('name' => $raw_name, 'dir' => false, 'method' => $method, 'crc' => $crc, 'csize' => $cs, 'usize' => $us, 'flags' => $flags, 'off' => $off, 'data_off' => 0, 'end' => 0);
			$this->entry_type($ent, $made_by, $attrs);
			if($method === 0 && $cs !== $us)
			{
				throw self::refuse(phoromatic_catalog_refusal::ZIP_MISMATCH, $raw_name . ': a stored entry\'s compressed and unpacked sizes differ');
			}
			if($ent['dir'] && $us !== 0)
			{
				throw self::refuse(phoromatic_catalog_refusal::ZIP_ENTRY_TYPE, $raw_name . ': a directory entry must be empty');
			}
			if(($why = ($this->engine_bundle ? self::engine_name_problem($ent['name'], $ent['dir']) : self::name_problem($ent['name'], $ent['dir']))) !== '')
			{
				throw self::refuse(phoromatic_catalog_refusal::PACK_PATH, $raw_name . ': ' . $why);
			}
			$max = $ent['name'] === 'index.signed' || $ent['name'] === 'engine.signed' ? ($this->engine_bundle ? 65536 : self::MAX_INDEX_SIGNED) : ($this->engine_bundle ? self::MAX_ENGINE_FILE : self::MAX_PACK);
			if(!$ent['dir'] && $us > $max)
			{
				throw self::refuse(phoromatic_catalog_refusal::PACK_LIMIT, $raw_name . ': ' . $us . ' bytes unpacked; the limit for it is ' . $max);
			}
			$this->entries[] = $ent;
			$pos = $next;
		}
		if($pos !== $cd_end)
		{
			throw self::bad('the central directory holds bytes beyond its records');
		}

		$this->check_names();
		$unpacked = 0;
		foreach($this->entries as $ent)
		{
			$unpacked += $ent['usize'];
		}
		if($unpacked > self::MAX_UNPACKED)
		{
			throw self::refuse(phoromatic_catalog_refusal::PACK_LIMIT, 'the zip unpacks to ' . $unpacked . ' bytes; the limit is ' . self::MAX_UNPACKED);
		}
		if($unpacked > self::RATIO * $n + (1 << 20))
		{
			throw self::refuse(phoromatic_catalog_refusal::PACK_LIMIT, 'the zip unpacks to ' . $unpacked . ' bytes from ' . $n . '; at most ' . self::RATIO . 'x plus 1 MiB is allowed');
		}

		foreach($this->entries as $i => $ent)
		{
			$this->check_local($this->entries[$i], $cd_off);
		}
		// The entries, in file order, must tile the bytes from 0 to the central directory.
		$order = $this->entries;
		usort($order, function($x, $y) { return $x['off'] <=> $y['off']; });
		$at = 0;
		foreach($order as $ent)
		{
			if($ent['off'] < $at)
			{
				throw self::bad($ent['name'] . ': entries overlap');
			}
			if($ent['off'] > $at && $at === 0)
			{
				throw self::bad('there is data before the first entry');
			}
			if($ent['off'] > $at)
			{
				throw self::bad('there is data between entries, before ' . $ent['name']);
			}
			$at = $ent['end'];
		}
		if($at !== $cd_off)
		{
			throw self::bad('there is data between the last entry and the central directory');
		}
		// the entries now carry where their data starts and ends, so the by-name copies are made last
		foreach($this->entries as $ent)
		{
			$this->by_name[$ent['name']] = $ent;
		}
	}

	private function check_extra($x, $where)
	{
		$len = strlen($x);
		$p = 0;
		while($p < $len)
		{
			if($len - $p < 4)
			{
				throw self::bad('a ' . $where . ' extra field is damaged');
			}
			$id = ord($x[$p]) | (ord($x[$p + 1]) << 8);
			$l = ord($x[$p + 2]) | (ord($x[$p + 3]) << 8);
			if(4 + $l > $len - $p)
			{
				throw self::bad('a ' . $where . ' extra field is damaged');
			}
			if($id === 0x0001)
			{
				throw self::bad('zip64 records are not allowed');
			}
			$p += 4 + $l;
		}
	}

	private function entry_type(&$ent, $made_by, $attrs)
	{
		$name = $ent['name'];
		$slash = substr($name, -1) === '/';
		$dir = $slash;
		if(($made_by >> 8) === 3 || ($attrs >> 16) !== 0) // a mode in the high 16 bits is a Unix mode, whatever host wrote it
		{
			$mode = $attrs >> 16;
			if(($mode & 07000) !== 0)
			{
				throw self::refuse(phoromatic_catalog_refusal::ZIP_ENTRY_TYPE, $name . ': setuid, setgid and sticky bits are not allowed');
			}
			switch($mode & 0170000)
			{
				case 0:
				case 0100000:
					if($slash && ($mode & 0170000) !== 0)
					{
						throw self::refuse(phoromatic_catalog_refusal::ZIP_ENTRY_TYPE, $name . ': a regular file named like a directory');
					}
					break;
				case 0040000:
					$dir = true;
					if(!$slash)
					{
						throw self::refuse(phoromatic_catalog_refusal::ZIP_ENTRY_TYPE, $name . ': a directory entry\'s name must end in a slash');
					}
					break;
				default:
					throw self::refuse(phoromatic_catalog_refusal::ZIP_ENTRY_TYPE, $name . ': only regular files and directories are allowed (not a link, device, FIFO or socket)');
			}
		}
		else if(($attrs & 0x10) !== 0)
		{
			$dir = true;
			if(!$slash)
			{
				throw self::refuse(phoromatic_catalog_refusal::ZIP_ENTRY_TYPE, $name . ': a directory entry\'s name must end in a slash');
			}
		}
		$ent['dir'] = $dir;
		if($slash)
		{
			$ent['name'] = substr($name, 0, -1);
		}
		if($ent['name'] === '')
		{
			throw self::refuse(phoromatic_catalog_refusal::PACK_PATH, 'an entry has an empty name');
		}
	}

	// A bundle holds index.signed and packs/<64 lowercase hex>.zip, and nothing else (7.2)
	private static function name_problem($path, $dir)
	{
		if($dir)
		{
			return 'a bundle holds no directories';
		}
		if($path === 'index.signed' || preg_match('~^packs/[0-9a-f]{64}\.zip\z~', $path) === 1)
		{
			return '';
		}
		return 'a bundle holds index.signed and packs/<64 lowercase hex>.zip only';
	}

	// An engine bundle holds engine.signed and the archives it names: benchy-engine-<version>-<platform>.zip or .tar.gz, and nothing else
	private static function engine_name_problem($path, $dir)
	{
		if($dir)
		{
			return 'an engine bundle holds no directories';
		}
		if($path === 'engine.signed' || preg_match('~^benchy-engine-[0-9]{1,9}\.[0-9]{1,9}\.[0-9]{1,9}-(windows-x64|linux-x64|linux-arm64)\.(zip|tar\.gz)\z~', $path) === 1)
		{
			return '';
		}
		return 'an engine bundle holds engine.signed and benchy-engine-<version>-<platform> archives only';
	}

	private function check_names()
	{
		$seen = array();
		foreach($this->entries as $ent)
		{
			$low = strtolower($ent['name']);
			if(isset($seen[$low]))
			{
				throw self::refuse(phoromatic_catalog_refusal::PACK_PATH, $ent['name'] . ': the name is used twice (names compare without letter case)');
			}
			$seen[$low] = true;
		}
	}

	private function check_local(&$ent, $cd_off)
	{
		$d = $this->data;
		$off = $ent['off'];
		if($off < 0 || $off + 30 > $cd_off || $this->u32($off) !== 0x04034b50)
		{
			throw self::bad($ent['name'] . ': the local header is missing or damaged');
		}
		$flags = $this->u16($off + 6);
		$method = $this->u16($off + 8);
		$crc = $this->u32($off + 14);
		$cs = $this->u32($off + 18);
		$us = $this->u32($off + 22);
		$nl = $this->u16($off + 26);
		$xl = $this->u16($off + 28);
		if($off + 30 + $nl + $xl > $cd_off)
		{
			throw self::bad($ent['name'] . ': the local header runs past the entries');
		}
		$name = substr($d, $off + 30, $nl);
		$name_slash = substr($name, -1) === '/';
		if(($name_slash ? substr($name, 0, -1) : $name) !== $ent['name'] || $name_slash !== $ent['dir'])
		{
			throw self::refuse(phoromatic_catalog_refusal::ZIP_MISMATCH, $ent['name'] . ': the local header names ' . json_encode($name));
		}
		if($method !== $ent['method'])
		{
			throw self::refuse(phoromatic_catalog_refusal::ZIP_MISMATCH, $ent['name'] . ': the local header\'s compression method differs from the directory\'s');
		}
		$this->check_extra(substr($d, $off + 30 + $nl, $xl), 'local');
		if((($flags ^ $ent['flags']) & 0x8) !== 0)
		{
			throw self::refuse(phoromatic_catalog_refusal::ZIP_MISMATCH, $ent['name'] . ': the local header and the directory disagree about a data descriptor');
		}
		if(($flags & ((1 << 0) | (1 << 6) | (1 << 13))) !== 0)
		{
			throw self::refuse(phoromatic_catalog_refusal::ZIP_METHOD, $ent['name'] . ': encrypted entries are not allowed');
		}
		$has_desc = ($flags & 0x8) !== 0;
		if(!$has_desc && ($crc !== $ent['crc'] || $cs !== $ent['csize'] || $us !== $ent['usize']))
		{
			throw self::refuse(phoromatic_catalog_refusal::ZIP_MISMATCH, $ent['name'] . ': the local header\'s sizes or checksum differ from the directory\'s');
		}
		$ent['data_off'] = $off + 30 + $nl + $xl;
		$end = $ent['data_off'] + $ent['csize'];
		if($end > $cd_off)
		{
			throw self::bad($ent['name'] . ': the data runs past the entries');
		}
		if($has_desc)
		{
			$desc_end = -1;
			$matches = function($p) use ($ent)
			{
				return $this->u32($p) === $ent['crc'] && $this->u32($p + 4) === $ent['csize'] && $this->u32($p + 8) === $ent['usize'];
			};
			if($end + 16 <= $cd_off && $this->u32($end) === 0x08074b50 && $matches($end + 4))
			{
				$desc_end = $end + 16;
			}
			else if($end + 12 <= $cd_off && $matches($end))
			{
				$desc_end = $end + 12;
			}
			if($desc_end < 0)
			{
				throw self::refuse(phoromatic_catalog_refusal::ZIP_MISMATCH, $ent['name'] . ': the data descriptor is missing or differs from the directory');
			}
			$end = $desc_end;
		}
		$ent['end'] = $end;
	}

	public function lookup($name)
	{
		return isset($this->by_name[$name]) ? $this->by_name[$name] : null;
	}

	// The regular files, sorted by byte order of the path
	public function files()
	{
		$out = array();
		foreach($this->entries as $ent)
		{
			if(!$ent['dir'])
			{
				$out[] = $ent;
			}
		}
		usort($out, function($x, $y) { return strcmp($x['name'], $y['name']); });
		return $out;
	}

	// Streams a file entry's unpacked bytes to $sink(string $chunk), at most its declared size, and refuses an entry whose data is
	// damaged, whose checksum fails or whose size is not the declared one. Nothing is held in memory but one chunk.
	public function stream($ent, $sink)
	{
		$name = $ent['name'];
		$crc = hash_init('crc32b');
		$total = 0;
		$pos = $ent['data_off'];
		$end = $ent['data_off'] + $ent['csize'];
		$inflate = $ent['method'] === 8 ? inflate_init(ZLIB_ENCODING_RAW) : null;
		$emit = function($out) use (&$total, $ent, $name, $crc, $sink)
		{
			$total += strlen($out);
			if($total > $ent['usize'])
			{
				throw self::refuse(phoromatic_catalog_refusal::PACK_SIZE, $name . ': unpacks to more than its declared ' . $ent['usize'] . ' bytes');
			}
			hash_update($crc, $out);
			$sink($out);
		};
		while($pos < $end)
		{
			$piece = substr($this->data, $pos, min(self::CHUNK, $end - $pos));
			$pos += strlen($piece);
			if($inflate === null)
			{
				$emit($piece);
				continue;
			}
			$out = @inflate_add($inflate, $piece, ZLIB_NO_FLUSH);
			if($out === false)
			{
				throw self::refuse(phoromatic_catalog_refusal::PACK_SIZE, $name . ': the compressed data is damaged');
			}
			if($out !== '')
			{
				$emit($out);
			}
		}
		if($inflate !== null && inflate_get_status($inflate) !== ZLIB_STREAM_END)
		{
			throw self::refuse(phoromatic_catalog_refusal::PACK_SIZE, $name . ': the compressed data is damaged: unexpected end of data');
		}
		if($total !== $ent['usize'])
		{
			throw self::refuse(phoromatic_catalog_refusal::PACK_SIZE, $name . ': unpacks to ' . $total . ' bytes, not its declared ' . $ent['usize']);
		}
		if(hexdec(hash_final($crc)) !== $ent['crc'])
		{
			throw self::refuse(phoromatic_catalog_refusal::PACK_SIZE, $name . ': the checksum does not match');
		}
	}

	// A small member in memory (index.signed), at most $max bytes
	public function read_all($ent, $max)
	{
		if($ent['usize'] > $max)
		{
			throw self::refuse(phoromatic_catalog_refusal::PACK_LIMIT, $ent['name'] . ' is ' . $ent['usize'] . ' bytes; the limit is ' . $max);
		}
		$buf = '';
		$this->stream($ent, function($c) use (&$buf) { $buf .= $c; });
		return $buf;
	}
}

?>
