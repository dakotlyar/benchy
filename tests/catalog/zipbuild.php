<?php
// Test helper (tests/catalog): builds zip bytes with exactly the faults a case needs, field by field. Used by make_bundles.php (the
// committed bundle fixtures) and by the tests that need a bundle too big or too long to commit. Deterministic: no clock, no randomness.
//
// build(array $entries, array $opts): each entry is array('name' =>, 'data' =>, and any of)
//   method (8 deflate by default, 0 store), flags (0 or 8), desc ('sig' | 'nosig', for flags 8), raw (the stored bytes instead of the
//   compressed data), gap_before and gap_after (bytes put before this entry's local header, and after its data), local => array(name, method, flags, crc, csize, usize, extra),
//   central => array(name, method, flags, crc, csize, usize, extra, attrs, made_by, disk, offset)
// $opts: prefix, suffix (after the end record), comment, eocd => array(disk, cd_disk, here, total, cd_size, cd_off), before_eocd (bytes
// between the central directory and the end record), zip64_locator (true)
class catalog_zipbuild
{
	private static function le16($n) { return pack('v', $n & 0xFFFF); }
	private static function le32($n) { return pack('V', $n & 0xFFFFFFFF); }

	public static function build($entries, $opts = array())
	{
		$out = isset($opts['prefix']) ? $opts['prefix'] : '';
		$central = '';
		$count = 0;
		foreach($entries as $e)
		{
			$method = isset($e['method']) ? $e['method'] : 8;
			$flags = isset($e['flags']) ? $e['flags'] : 0;
			$data = isset($e['data']) ? $e['data'] : '';
			$crc = crc32($data);
			$usize = strlen($data);
			$raw = isset($e['raw']) ? $e['raw'] : ($method === 8 ? gzdeflate($data, 9) : $data);
			$csize = strlen($raw);
			$l = isset($e['local']) ? $e['local'] : array();
			$c = isset($e['central']) ? $e['central'] : array();
			if(isset($e['gap_before']))
			{
				$out .= $e['gap_before'];
			}
			$offset = strlen($out);
			$has_desc = ($flags & 8) !== 0;
			$lname = isset($l['name']) ? $l['name'] : $e['name'];
			$lflags = isset($l['flags']) ? $l['flags'] : $flags;
			$out .= self::le32(0x04034b50) . self::le16(20) . self::le16($lflags) . self::le16(isset($l['method']) ? $l['method'] : $method) . self::le16(0) . self::le16(0x21)
				. self::le32(isset($l['crc']) ? $l['crc'] : ($has_desc ? 0 : $crc))
				. self::le32(isset($l['csize']) ? $l['csize'] : ($has_desc ? 0 : $csize))
				. self::le32(isset($l['usize']) ? $l['usize'] : ($has_desc ? 0 : $usize))
				. self::le16(strlen($lname)) . self::le16(strlen(isset($l['extra']) ? $l['extra'] : '')) . $lname . (isset($l['extra']) ? $l['extra'] : '');
			$out .= $raw;
			if($has_desc && isset($e['desc']))
			{
				$d = self::le32($crc) . self::le32($csize) . self::le32($usize);
				if(isset($e['desc_override']))
				{
					$d = $e['desc_override'];
				}
				$out .= ($e['desc'] === 'sig' ? self::le32(0x08074b50) : '') . $d;
			}
			if(isset($e['gap_after']))
			{
				$out .= $e['gap_after'];
			}
			$cname = isset($c['name']) ? $c['name'] : $e['name'];
			$cextra = isset($c['extra']) ? $c['extra'] : '';
			$central .= self::le32(0x02014b50) . self::le16(isset($c['made_by']) ? $c['made_by'] : 20) . self::le16(20) . self::le16(isset($c['flags']) ? $c['flags'] : $flags)
				. self::le16(isset($c['method']) ? $c['method'] : $method) . self::le16(0) . self::le16(0x21)
				. self::le32(isset($c['crc']) ? $c['crc'] : $crc) . self::le32(isset($c['csize']) ? $c['csize'] : $csize) . self::le32(isset($c['usize']) ? $c['usize'] : $usize)
				. self::le16(strlen($cname)) . self::le16(strlen($cextra)) . self::le16(0) . self::le16(isset($c['disk']) ? $c['disk'] : 0) . self::le16(0)
				. self::le32(isset($c['attrs']) ? $c['attrs'] : 0) . self::le32(isset($c['offset']) ? $c['offset'] : $offset) . $cname . $cextra;
			$count++;
		}
		$cd_off = strlen($out);
		$out .= $central;
		$out .= isset($opts['before_eocd']) ? $opts['before_eocd'] : '';
		if(!empty($opts['zip64_locator']))
		{
			$out .= self::le32(0x07064b50) . str_repeat("\0", 16);
		}
		$z = isset($opts['eocd']) ? $opts['eocd'] : array();
		$comment = isset($opts['comment']) ? $opts['comment'] : '';
		$out .= self::le32(0x06054b50) . self::le16(isset($z['disk']) ? $z['disk'] : 0) . self::le16(isset($z['cd_disk']) ? $z['cd_disk'] : 0)
			. self::le16(isset($z['here']) ? $z['here'] : $count) . self::le16(isset($z['total']) ? $z['total'] : $count)
			. self::le32(isset($z['cd_size']) ? $z['cd_size'] : strlen($central)) . self::le32(isset($z['cd_off']) ? $z['cd_off'] : $cd_off)
			. self::le16(strlen($comment)) . $comment;
		return $out . (isset($opts['suffix']) ? $opts['suffix'] : '');
	}
}
