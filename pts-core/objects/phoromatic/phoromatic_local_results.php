<?php

/*
	Benchy
	phoromatic_local_results.php: results that people run themselves on one of an account's systems and upload with POST /api/v2/results
	(benchy-local-result-upload.md, LU-S1 and LU-S2): the schema step, the account's gate, the per-system hourly cap, the run-id key and what the
	result page says about where each test came from

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

class phoromatic_local_results
{
	const SCHEMA_STEP = 66;   // every "is the schema new enough" check uses this, so renumbering the step is one line
	const HOURLY_CAP = 120;   // local uploads per system per hour (a script stuck in a loop, not a person)

	// What the step adds: the run-id key table, RanBy and Provenance on a result, the account's switch and the token scope that a later upload-only token
	// uses (NULL = the full system token; nothing writes it yet). Additive and repeatable (a column is added only when it is missing); a release that does
	// not know the step ignores all of it.
	public static function schema_sql()
	{
		return array(
			'CREATE TABLE IF NOT EXISTS phoromatic_v2_local_results (AccountID TEXT NOT NULL, SystemID TEXT NOT NULL, RunID TEXT NOT NULL, ContentSHA256 TEXT NOT NULL, UploadID INTEGER NOT NULL, UploadedOn INTEGER NOT NULL, PRIMARY KEY (AccountID, SystemID, RunID))',
			'CREATE INDEX IF NOT EXISTS phoromatic_v2_local_results_rate ON phoromatic_v2_local_results (AccountID, SystemID, UploadedOn)');
	}
	// table => array(column => definition)
	public static function added_columns()
	{
		return array('phoromatic_results' => array('RanBy' => 'TEXT', 'Provenance' => 'TEXT'),
			'phoromatic_account_settings' => array('AcceptLocalResults' => 'INTEGER DEFAULT 1'),
			'phoromatic_system_tokens' => array('Scope' => 'TEXT'));
	}
	public static function ready()
	{
		return phoromatic_server::$db != null && phoromatic_server::read_database_version() >= self::SCHEMA_STEP;
	}

	// ---- the account's gate (2.5) ----

	// True when the account takes results that people run themselves (the default). Before the step there is no switch and nothing is refused.
	public static function accepted($account_id)
	{
		if(!self::ready())
		{
			return true;
		}
		$stmt = @phoromatic_server::$db->prepare('SELECT AcceptLocalResults FROM phoromatic_account_settings WHERE AccountID = :a');
		if($stmt === false)
		{
			return true;
		}
		$stmt->bindValue(':a', $account_id);
		$result = @$stmt->execute();
		$row = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;
		return !$row || $row['AcceptLocalResults'] === null || (int)$row['AcceptLocalResults'] != 0;
	}
	// A result that no schedule and no ticket made is one that someone ran at the machine (this also labels every v1 ad-hoc upload made before the step)
	public static function is_local($row)
	{
		return empty($row['ScheduleID']) && empty($row['BenchmarkTicketID']);
	}

	// ---- the cap (2.2 step 4) ----

	// Seconds to wait when this system has made HOURLY_CAP local uploads in the past hour, else null
	public static function rate_wait($account_id, $system_id)
	{
		$since = time() - 3600;
		$stmt = phoromatic_server::$db->prepare('SELECT COUNT(*) AS n, MIN(UploadedOn) AS oldest FROM phoromatic_v2_local_results WHERE AccountID = :a AND SystemID = :y AND UploadedOn > :since');
		$stmt->bindValue(':a', $account_id);
		$stmt->bindValue(':y', $system_id);
		$stmt->bindValue(':since', $since);
		$result = @$stmt->execute();
		$row = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;
		if(!$row || (int)$row['n'] < self::HOURLY_CAP)
		{
			return null;
		}
		return max(1, min(3600, (int)$row['oldest'] + 3600 - time()));
	}

	// ---- the key (2.4) ----

	// The upload this run already has for this system: array('upload_id' => int, 'origin' => 'job'|'local', 'sha256' => string|null), or null. Only this account's
	// and this system's rows are ever looked at.
	public static function existing($account_id, $system_id, $run)
	{
		$stmt = phoromatic_server::$db->prepare('SELECT UploadID FROM phoromatic_v2_jobs WHERE AccountID = :a AND SystemID = :y AND RunID = :r AND UploadID IS NOT NULL LIMIT 1');
		$stmt->bindValue(':a', $account_id);
		$stmt->bindValue(':y', $system_id);
		$stmt->bindValue(':r', $run);
		$result = @$stmt->execute();
		if($result && ($row = $result->fetchArray(SQLITE3_ASSOC)))
		{
			return array('upload_id' => (int)$row['UploadID'], 'origin' => 'job', 'sha256' => null);
		}
		$stmt = phoromatic_server::$db->prepare('SELECT UploadID, ContentSHA256 FROM phoromatic_v2_local_results WHERE AccountID = :a AND SystemID = :y AND RunID = :r');
		$stmt->bindValue(':a', $account_id);
		$stmt->bindValue(':y', $system_id);
		$stmt->bindValue(':r', $run);
		$result = @$stmt->execute();
		if($result && ($row = $result->fetchArray(SQLITE3_ASSOC)))
		{
			return array('upload_id' => (int)$row['UploadID'], 'origin' => 'local', 'sha256' => $row['ContentSHA256']);
		}
		return null;
	}

	// A result is deleted: the run keys that point at it go too (a run the server no longer holds can be uploaded again, and its upload id may be given to another result)
	public static function forget_upload($account_id, $upload_id)
	{
		if(self::ready())
		{
			$stmt = phoromatic_server::$db->prepare('DELETE FROM phoromatic_v2_local_results WHERE AccountID = :a AND UploadID = :u');
			$stmt->bindValue(':a', $account_id);
			$stmt->bindValue(':u', (int)$upload_id);
			@$stmt->execute();
		}
		$stmt = @phoromatic_server::$db->prepare('UPDATE phoromatic_v2_jobs SET UploadID = NULL WHERE AccountID = :a AND UploadID = :u');
		if($stmt)
		{
			$stmt->bindValue(':a', $account_id);
			$stmt->bindValue(':u', (int)$upload_id);
			@$stmt->execute();
		}
	}

	// ---- what the machine said about the run ----

	// The OS account that ran it, as the machine reported it: printable characters only, at most 64 of them, or null
	public static function ran_by($value)
	{
		if(!is_string($value) || !preg_match('//u', $value))
		{
			return null;
		}
		$cut = substr($value, 0, 1024);
		while($cut !== '' && !preg_match('//u', $cut))
		{
			$cut = substr($cut, 0, -1);   // (the cut may have split a character)
		}
		$chars = preg_split('//u', preg_replace('/[\x00-\x1f\x7f<>&"\']/u', '', $cut), -1, PREG_SPLIT_NO_EMPTY);
		$name = trim(implode('', array_slice($chars ?: array(), 0, 64)));
		return $name === '' ? null : $name;
	}
	// What one test of a run was, from what the engine reported checked against what this server knows (design 2.3). Only the tiers this account is served
	// are looked at, so a test of another tenant's private tier reads "not in this server's catalog" and reveals nothing more.
	// One of: catalog, catalog_unmatched, catalog_withdrawn, builtin, local, unknown. $reason is set for catalog_withdrawn.
	public static function test_provenance($account_id, $entry, &$reason = null)
	{
		$reason = null;
		$source = is_array($entry) && isset($entry['source']) && is_array($entry['source']) ? $entry['source'] : null;
		$tier = $source !== null && isset($source['tier']) && is_string($source['tier']) ? $source['tier'] : null;
		if($tier === 'builtin' || $tier === 'local')
		{
			return $tier;
		}
		if(!phoromatic_catalog::valid_name($tier))
		{
			return 'unknown';
		}
		$ref = isset($entry['test']['ref']) && is_string($entry['test']['ref']) ? $entry['test']['ref'] : '';
		$version = isset($entry['test']['version']) && is_string($entry['test']['version']) ? $entry['test']['version'] : '';
		$tree = isset($source['tree_sha256']) && is_string($source['tree_sha256']) ? $source['tree_sha256'] : '';
		if(!phoromatic_catalog::ready() || $ref === '' || $version === '')
		{
			return 'catalog_unmatched';
		}
		$tiers = phoromatic_catalog::tiers_for_account($account_id);
		foreach($tiers as $tier)
		{
			// (a withdrawal of a tier this account is not served is not looked at: its reason is not this account's to read)
			foreach(phoromatic_catalog::revoked($tier) as $r)
			{
				if($r['Ref'] === $ref . '@' . $version)
				{
					$reason = (string)$r['Reason'];
					return 'catalog_withdrawn';
				}
			}
		}
		foreach(phoromatic_catalog::entries_of_id($tiers, $ref) as $e)
		{
			$json = json_decode((string)$e['EntryJSON'], true);
			if($e['Version'] === $version && $tree !== '' && is_array($json) && isset($json['tree_sha256']) && is_string($json['tree_sha256']) && hash_equals($json['tree_sha256'], $tree))
			{
				return 'catalog';
			}
		}
		return 'catalog_unmatched';
	}
	// The one-word summary kept on the result row: catalog, mixed, local, unverified or unknown
	public static function provenance($account_id, $run)
	{
		$kinds = array();
		foreach(isset($run['tests']) && is_array($run['tests']) ? $run['tests'] : array() as $entry)
		{
			if(is_array($entry) && isset($entry['status']) && $entry['status'] === 'ok')
			{
				$kinds[self::test_provenance($account_id, $entry)] = true;
			}
		}
		if(isset($kinds['catalog_unmatched']) || isset($kinds['catalog_withdrawn']))
		{
			return 'unverified';
		}
		$catalog = isset($kinds['catalog']) || isset($kinds['builtin']);
		$local = isset($kinds['local']);
		$unknown = isset($kinds['unknown']);
		if($unknown && !$catalog && !$local)
		{
			return 'unknown';
		}
		if($catalog && !$local && !$unknown)
		{
			return 'catalog';
		}
		if($local && !$catalog && !$unknown)
		{
			return 'local';
		}
		return 'mixed';
	}

	// ---- words (LU-S2): always "what the machine reported" ----

	public static function provenance_word($summary)
	{
		$words = array('catalog' => 'Catalog tests', 'mixed' => 'A mix of catalog and local tests', 'local' => 'Local tests', 'unverified' => 'Catalog tests that this server\'s catalog does not match',
			'unknown' => 'Where the tests came from was not recorded');
		return isset($words[$summary]) ? $words[$summary] : $words['unknown'];
	}
	public static function test_word($kind, $reason = null)
	{
		$words = array('catalog' => 'Catalog test', 'catalog_unmatched' => 'Catalog test, not in this server\'s catalog', 'catalog_withdrawn' => 'Catalog test, withdrawn version' . ($reason !== null && $reason !== '' ? ' (' . $reason . ')' : ''),
			'builtin' => 'Built-in test', 'local' => 'Local test', 'unknown' => 'Not recorded');
		return isset($words[$kind]) ? $words[$kind] : $words['unknown'];
	}
	// The HTML for a result page's header: where the run was made, by whom, and what the machine said its tests were. $row is the phoromatic_results row. Empty
	// for a result a schedule made (unless it carries a provenance word, which a job's result does too). Everything printed is escaped.
	public static function header_html($row, $system_name)
	{
		$h = function($text) { return htmlspecialchars((string)$text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); };
		$local = self::is_local($row);
		$ran_by = isset($row['RanBy']) && $row['RanBy'] !== null && $row['RanBy'] !== '' ? $row['RanBy'] : null;
		$summary = isset($row['Provenance']) && $row['Provenance'] !== null ? $row['Provenance'] : null;
		if(!$local && $summary === null)
		{
			return '';
		}
		$out = '<div class="local_run_header" style="margin: 1em 5%;"><p>';
		if($local)
		{
			$out .= '<strong>Local run</strong>: run at ' . $h($system_name) . ($ran_by !== null ? ' by ' . $h($ran_by) : '') . ' and uploaded ' . $h(phoromatic_server::user_friendly_timedate($row['UploadTime'])) . '. ';
		}
		if($summary !== null)
		{
			$out .= 'What the machine reported about its tests: ' . $h(self::provenance_word($summary)) . '.';
		}
		else if($local)
		{
			$out .= 'The machine did not say where its tests came from.';
		}
		$out .= '</p>';
		$tests = self::tests_html($row);
		if($tests !== '')
		{
			$out .= '<details><summary>Each test, as the machine reported it</summary>' . $tests . '</details>';
		}
		return $out . '</div>';
	}
	protected static function tests_html($row)
	{
		$file = phoromatic_server::phoromatic_account_result_path($row['AccountID'], $row['UploadID']) . 'result.json';
		$data = is_file($file) ? json_decode((string)@file_get_contents($file, false, null, 0, 16777216), true) : null;
		if(!is_array($data) || !isset($data['runs'][0]['tests']) || !is_array($data['runs'][0]['tests']))
		{
			return '';
		}
		$seen = array();
		$out = '';
		foreach($data['runs'][0]['tests'] as $entry)
		{
			if(!is_array($entry) || !isset($entry['status']) || $entry['status'] !== 'ok' || !isset($entry['test']['ref'], $entry['test']['version']) || !is_string($entry['test']['ref']) || !is_string($entry['test']['version']))
			{
				continue;
			}
			$key = $entry['test']['ref'] . ' ' . $entry['test']['version'];
			if(isset($seen[$key]))
			{
				continue;
			}
			$seen[$key] = true;
			$kind = self::test_provenance($row['AccountID'], $entry, $reason);
			$out .= '<li>' . htmlspecialchars($key, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ': ' . htmlspecialchars(self::test_word($kind, $reason), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</li>';
		}
		return $out === '' ? '' : '<ul>' . $out . '</ul>';
	}
}

?>
