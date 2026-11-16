<?php

/*
	Benchy
	phoromatic_catalog_refusal.php: why a signed catalog bundle or index was not accepted (E4.4 C1)

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

// The reason is a stable word and is the engine's detail.reason for the same fault (benchy-engine internal/catindex/envelope.go and
// internal/pack/refusal.go), so an admin reads the same word from a server and from `benchy-engine catalog import`. The words only the
// server has are license_tier (catalog spec 9.3, check 4) and key_table (the server's own key table is damaged).

class phoromatic_catalog_refusal extends Exception
{
	// catindex
	const LIMIT = 'limit';
	const BAD_ENVELOPE = 'bad_envelope';
	const UNKNOWN_KEY = 'unknown_key';
	const DENIED_KEY = 'denied_key';
	const RETIRED_KEY = 'retired_key';
	const BAD_SIGNATURE = 'bad_signature';
	const BAD_INDEX = 'bad_index';
	const BAD_TIER = 'bad_tier';
	const ROLLBACK = 'rollback';
	const FLOOR = 'floor';
	const SAME_PAIR_DIFFERS = 'same_pair_differs';
	const LEDGER_CONFLICT = 'ledger_conflict';
	const REVOCATION_DROPPED = 'revocation_dropped';
	const REVOKED_LISTED = 'revoked_listed';
	// pack (the zip reader)
	const ZIP_STRUCTURE = 'zip_structure';
	const ZIP_MISMATCH = 'zip_mismatch';
	const ZIP_METHOD = 'zip_method';
	const ZIP_ENTRY_TYPE = 'zip_entry_type';
	const PACK_PATH = 'pack_path';
	const PACK_LIMIT = 'pack_limit';
	const PACK_SIZE = 'pack_size';
	// catstore, after the signature
	const BUNDLE_PACK_UNLISTED = 'bundle_pack_unlisted';
	const BUNDLE_PACK_HASH = 'bundle_pack_hash';
	const BUNDLE_PACK_MISSING = 'bundle_pack_missing';
	// the server only
	const LICENSE_TIER = 'license_tier';
	const KEY_TABLE = 'key_table';
	const STATE_CORRUPT = 'state_corrupt'; // the engine's detail.reason (its error code is catalog_state_corrupt): the stored state handed in isn't what the database should hold

	// the server's own, E4.4 C2: a refusal that is not the bundle's fault (the packaged import tries again at the next start)
	const PRECONDITION = 'precondition'; // openssl, zlib or the database schema isn't ready
	const BUSY = 'busy';                  // another import holds the lock
	const STATE_WRITE = 'state_write';    // the database write (or its audit row) failed and was rolled back
	const STORAGE = 'storage';            // the catalog folder or a file in it couldn't be read or written
	const BUNDLE_UNREADABLE = 'bundle_unreadable';
	const PACKAGE_TIER = 'package_tier';  // a bundle that came with the package must be the public tier (catalog spec 9.3, the fifth guard); final, not transient

	public $reason;

	// The words above that say nothing about the bundle itself
	public static function transient($reason)
	{
		return in_array($reason, array(self::PRECONDITION, self::BUSY, self::STATE_WRITE, self::STORAGE, self::BUNDLE_UNREADABLE, self::KEY_TABLE, self::STATE_CORRUPT), true);
	}

	public function __construct($reason, $message)
	{
		parent::__construct($message);
		$this->reason = $reason;
	}
}

?>
