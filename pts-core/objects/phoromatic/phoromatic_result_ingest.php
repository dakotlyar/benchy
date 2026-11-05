<?php

/*
	Benchy
	phoromatic_result_ingest.php: stores one uploaded composite.xml as a Phoromatic result (the body of result_upload.php, moved here
	so the v1 upload and the native agent's result endpoint share one code path)

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

class phoromatic_result_ingest
{
	// Validates the composite, files it under the system and writes the result rows. The notification mails are notify()'s job, so a caller
	// can answer its client first. Returns
	// array('ok' => bool, 'error' => string|null, 'upload_id' => int|null, 'title' => string); the error strings are the ones v1 has always sent
	// to the client. $progressive is the client's progressive_upload value as it came (1 = a partial result, 2 = the last of a
	// progressive run, anything else = a single final upload). $system_logs is array(base64 text, sha1 of that text) or null.
	// $bench_session false files a bench system's result as v1 always did (the task snapshot, else the open session). A native job passes its
	// own session (an integer, or null for none) instead: a late upload belongs to the session its job was issued in, and it never ends
	// a task snapshot (the caller does, knowing whether a newer job is under way).
	public static function store($account_id, $system_id, $composite_xml, $schedule_id, $ticket_id, $trigger, $elapsed, $progressive = false, $system_logs = null, $bench_session = false, $deviation = false)
	{
		$result_file = new pts_result_file($composite_xml);

		// Validate the XML
		if($result_file->validate() == false)
		{
			return array('ok' => false, 'error' => 'XML Did Not Match Schema Definition', 'upload_id' => null);
		}

		if($result_file->get_title() == null || $result_file->get_system_count() == 0 || $result_file->get_test_count() == 0)
		{
			return array('ok' => false, 'error' => 'Invalid Result File', 'upload_id' => null);
		}

		// DETERMINE UNIQUE UPLOAD ID
		$upload_id = false;
		$to_update = false;

		// Bench systems: the session the result is filed under (the snapshot taken when the task was issued, else the session open now for
		// an ad-hoc upload), plus the constant parts that differed from the baseline. All null for an ordinary system.
		list($bench_session_id, $bench_session_source, $bench_deviation) = phoromatic_bench::upload_attribution($account_id, $system_id, !empty($schedule_id) || !empty($ticket_id), $bench_session, $deviation);
		$bench_progressive_guard = phoromatic_server::bench_state_for_system($account_id, $system_id) ? ' AND IFNULL(BenchSessionID, 0) = :bench_session' : '';

		if($progressive && $progressive > 0)
		{
			// See if previously uploaded (on a bench, only a row of the same session is continued)
			if(!empty($ticket_id))
				$stmt = phoromatic_server::$db->prepare('SELECT UploadID FROM phoromatic_results WHERE AccountID = :account_id AND SystemID = :system_id AND BenchmarkTicketID = :benchmark_ticket_id AND InProgress > 0' . $bench_progressive_guard . ' ORDER BY UploadID DESC LIMIT 1');
			else
				$stmt = phoromatic_server::$db->prepare('SELECT UploadID FROM phoromatic_results WHERE AccountID = :account_id AND SystemID = :system_id AND ScheduleID = :schedule_id AND Trigger = :trigger AND InProgress > 0' . $bench_progressive_guard . ' ORDER BY UploadID DESC LIMIT 1');

			if($bench_progressive_guard != '')
			{
				$stmt->bindValue(':bench_session', (int)$bench_session_id);
			}
			$stmt->bindValue(':account_id', $account_id);
			$stmt->bindValue(':system_id', $system_id);
			$stmt->bindValue(':schedule_id', $schedule_id);
			$stmt->bindValue(':benchmark_ticket_id', $ticket_id);
			$stmt->bindValue(':trigger', $trigger);
			$result = $stmt->execute();
			$row = $result->fetchArray();
			$upload_id = isset($row['UploadID']) ? $row['UploadID'] : false;
			if($upload_id)
			{
				$to_update = true;
			}
		}
		if($upload_id == false)
		{
			$stmt = phoromatic_server::$db->prepare('SELECT UploadID FROM phoromatic_results WHERE AccountID = :account_id ORDER BY UploadID DESC LIMIT 1');
			$stmt->bindValue(':account_id', $account_id);
			$result = $stmt->execute();
			$row = $result->fetchArray();
			$upload_id = (isset($row['UploadID']) ? $row['UploadID'] : 0) + 1;
		}
		$upload_time = phoromatic_server::current_time();
		$xml_upload_hash = sha1($composite_xml);

		if($to_update)
		{
			$stmt = phoromatic_server::$db->prepare('UPDATE phoromatic_results SET UploadTime = :upload_time, Title = :title, Description = :description, ResultCount = :result_count, XmlUploadHash = :xml_upload_hash, ComparisonHash = :comparison_hash, ElapsedTime = :elapsed_time, InProgress = :in_progress WHERE AccountID = :account_id AND UploadID = :upload_id AND SystemID = :system_id');
		}
		else
		{
			$stmt = phoromatic_server::$db->prepare('INSERT INTO phoromatic_results (AccountID, SystemID, UploadID, ScheduleID, BenchmarkTicketID, Trigger, UploadTime, Title, Description, SystemCount, ResultCount, DisplayStatus, XmlUploadHash, ComparisonHash, ElapsedTime, PPRID, InProgress, BenchSessionID, BenchSessionSource, BenchDeviation) VALUES (:account_id, :system_id, :upload_id, :schedule_id, :benchmark_ticket_id, :trigger, :upload_time, :title, :description, :system_count, :result_count, :display_status, :xml_upload_hash, :comparison_hash, :elapsed_time, :pprid, :in_progress, :bench_session_id, :bench_session_source, :bench_deviation)');
			$stmt->bindValue(':bench_session_id', $bench_session_id);
			$stmt->bindValue(':bench_session_source', $bench_session_source);
			$stmt->bindValue(':bench_deviation', $bench_deviation);
		}
		$stmt->bindValue(':account_id', $account_id);
		$stmt->bindValue(':system_id', $system_id);
		$stmt->bindValue(':upload_id', $upload_id);
		$stmt->bindValue(':schedule_id', $schedule_id);
		$stmt->bindValue(':benchmark_ticket_id', $ticket_id);
		$stmt->bindValue(':trigger', $trigger);
		$stmt->bindValue(':upload_time', $upload_time);
		$stmt->bindValue(':title', pts_strings::sanitize($result_file->get_title()));
		$stmt->bindValue(':description', pts_strings::sanitize($result_file->get_description()));
		$stmt->bindValue(':system_count', $result_file->get_system_count());
		$stmt->bindValue(':result_count', $result_file->get_test_count());
		$stmt->bindValue(':display_status', 1);
		$stmt->bindValue(':xml_upload_hash', $xml_upload_hash);
		$stmt->bindValue(':comparison_hash', $result_file->get_contained_tests_hash(false));
		$stmt->bindValue(':elapsed_time', (empty($elapsed) || !is_numeric($elapsed) || $elapsed < 0 ? 0 : $elapsed));
		$stmt->bindValue(':pprid', phoromatic_server::compute_pprid($account_id, $system_id, $upload_time, $xml_upload_hash));
		$stmt->bindValue(':in_progress', ($progressive == 1 ? 1 : 0));

		$result = $stmt->execute();
		$result_row_written = $result !== false;
		$result_directory = phoromatic_server::phoromatic_account_result_path($account_id, $upload_id);
		pts_file_io::mkdir($result_directory);

		$composite_written = $result_row_written && @file_put_contents($result_directory . 'composite.xml', $composite_xml) !== false;
		if(!$result_row_written || !$composite_written)
		{
			// Nothing about this result is kept: a caller that is told ok would trust a row or a file that is not there
			if(!$to_update)
			{
				self::forget($account_id, $upload_id);
			}
			return array('ok' => false, 'error' => $result_row_written ? 'The result could not be written to disk' : 'The result could not be saved in the database', 'upload_id' => null);
		}
		if($result_row_written && $composite_written && $bench_session_source === 'task' && $progressive != 1 && $bench_session === false)
		{
			// The final upload of an issued bench task is stored: the run is over, so its task snapshot ends (a swap is possible again)
			phoromatic_bench::task_completed($account_id, $system_id, $bench_session_id);
		}

		if(is_array($system_logs) && $system_logs[0] != null && $system_logs[1] != null)
		{
			if(sha1($system_logs[0]) == $system_logs[1])
			{
				file_put_contents($result_directory . 'system-logs.zip', base64_decode($system_logs[0]));
			}
		}

		$relative_id = 0;
		$children_written = true;
		foreach($result_file->get_result_objects() as $result_object)
		{
			$relative_id++;
			$stmt = phoromatic_server::$db->prepare('INSERT INTO phoromatic_results_results (AccountID, UploadID, AbstractID, TestProfile, ComparisonHash) VALUES (:account_id, :upload_id, :abstract_id, :test_profile, :comparison_hash)');
			$stmt->bindValue(':account_id', $account_id);
			$stmt->bindValue(':upload_id', $upload_id);
			$stmt->bindValue(':abstract_id', $relative_id);
			$stmt->bindValue(':test_profile', $result_object->test_profile->get_identifier());
			$stmt->bindValue(':comparison_hash', $result_object->get_comparison_hash(true, false));
			$children_written = $children_written && $stmt->execute() !== false;
		}

		if($relative_id == 0)
		{
			return array('ok' => false, 'error' => 'End Termination Error', 'upload_id' => null);
		}

		foreach($result_file->get_systems() as $s)
		{
			$stmt = phoromatic_server::$db->prepare('INSERT INTO phoromatic_results_systems (AccountID, UploadID, SystemIdentifier, Hardware, Software) VALUES (:account_id, :upload_id, :system_identifier, :hardware, :software)');
			$stmt->bindValue(':account_id', $account_id);
			$stmt->bindValue(':upload_id', $upload_id);
			$stmt->bindValue(':system_identifier', pts_strings::sanitize($s->get_identifier()));
			$stmt->bindValue(':hardware', pts_strings::sanitize($s->get_hardware()));
			$stmt->bindValue(':software', pts_strings::sanitize($s->get_software()));
			$children_written = $children_written && $stmt->execute() !== false;
		}
		if(!$children_written)
		{
			if(!$to_update)
			{
				self::forget($account_id, $upload_id);
			}
			return array('ok' => false, 'error' => 'The result could not be saved in the database', 'upload_id' => null);
		}

		return array('ok' => true, 'error' => null, 'upload_id' => $upload_id, 'title' => pts_strings::sanitize($result_file->get_title()));
	}
	// Takes back the rows a new result got before something failed
	protected static function forget($account_id, $upload_id)
	{
		foreach(array('phoromatic_results_systems', 'phoromatic_results_results', 'phoromatic_results') as $table)
		{
			$stmt = phoromatic_server::$db->prepare('DELETE FROM ' . $table . ' WHERE AccountID = :account_id AND UploadID = :upload_id');
			if($stmt)
			{
				$stmt->bindValue(':account_id', $account_id);
				$stmt->bindValue(':upload_id', $upload_id);
				@$stmt->execute();
			}
		}
	}
	// Mails the users who asked to hear about uploads. PHP's mail() waits for the mail system, so the caller sends its reply first; this
	// then pushes what has been written to the client before the first mail goes out (where the server allows it).
	public static function notify($account_id, $system_id, $upload_id, $title)
	{
		$stmt = phoromatic_server::$db->prepare('SELECT UserName, Email FROM phoromatic_users WHERE UserID IN (SELECT UserID FROM phoromatic_user_settings WHERE AccountID = :account_id AND NotifyOnResultUploads = 1) AND AccountID = :account_id');
		$stmt->bindValue(':account_id', $account_id);
		$result = $stmt->execute();
		$released = false;
		while($row = $result->fetchArray())
		{
			if(!$released)
			{
				$released = true;
				ignore_user_abort(true);
				while(ob_get_level() > 0)
				{
					ob_end_flush();
				}
				flush();
				if(function_exists('fastcgi_finish_request'))
				{
					fastcgi_finish_request();
				}
			}
			phoromatic_server::send_email($row['Email'], 'Phoromatic Result Upload', phoromatic_server::account_id_to_group_admin_email($account_id), '<p><strong>' . $row['UserName'] . ':</strong></p><p>A new result file has been uploaded to Phoromatic.</p><p>Upload ID: ' . $upload_id . '<br />Upload Time: ' . phoromatic_server::current_time() . '<br />Title: ' . $title . '<br />System: ' . phoromatic_server::system_id_to_name($system_id, $account_id) . '</p>');
		}
	}
}

?>
