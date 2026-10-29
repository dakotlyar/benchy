# Shared by nophone.sh and json.sh (sourced; needs QC_SRV and QC_AID). Server-side state hygiene between harness phases.
#   qc_state_dump [label]   print what could route work to / hold back an agent: systems (state, bench, drift), active
#                           schedules, triggers, active tickets, open bench sessions. Used as the evidence of a failing check.
#   qc_phase_reset <phase>  start a phase from a known server state. Prints one line per leak it had to clear, so a phase that
#                           leaves state behind shows up in the log of the NEXT phase immediately. Clears: active schedules and
#                           tickets (and their triggers), bench tokens/sessions/baselines and the systems' bench assignment, and
#                           pending/unapproved systems (approved). Leaves systems, results and settings alone.
_qc_php() { docker exec -i "$QC_SRV" php -r '$d=new SQLite3("/var/lib/benchy/phoromatic/phoromatic.db"); $d->busyTimeout(5000); eval($argv[1]);' "$1"; }

qc_state_dump() {
	echo "    --- server state ${1:-} (now $(date -u +%H:%M:%S) UTC; php/server clock: $(docker exec "$QC_SRV" date -u +%H:%M:%S) UTC, $(docker exec "$QC_SRV" php -r 'echo date("Y-m-d H.i T");')) ---"
	_qc_php '
function rows($d, $sql) { $r = $d->query($sql); while ($r && ($x = $r->fetchArray(SQLITE3_ASSOC))) echo "    | ", json_encode($x), "\n"; }
echo "    systems:\n";   rows($d, "SELECT Title, SystemID, State, BenchID, BenchDrift, CurrentTask, LastCommunication, DeletedOn FROM phoromatic_systems ORDER BY CreatedOn");
echo "    active schedules:\n"; rows($d, "SELECT ScheduleID, Title, RunPriority, ActiveOn, RunAt, RunTargetSystems, (SELECT COUNT(*) FROM phoromatic_schedules_tests t WHERE t.ScheduleID = s.ScheduleID) AS Tests FROM phoromatic_schedules s WHERE State = 1");
echo "    triggers:\n";  rows($d, "SELECT ScheduleID, Trigger, TriggeredOn, TriggerTarget FROM phoromatic_schedules_triggers");
echo "    active tickets:\n"; rows($d, "SELECT TicketID, Title, TicketIssueTime, RunTargetSystems FROM phoromatic_benchmark_tickets WHERE State = 1");
echo "    open bench sessions:\n"; rows($d, "SELECT SessionID, BenchID, ComponentID FROM phoromatic_bench_sessions WHERE EndedOn IS NULL");
echo "    account settings:\n"; rows($d, "SELECT * FROM phoromatic_account_settings");
echo "    results (last 8):\n"; rows($d, "SELECT UploadID, ScheduleID, Trigger, SystemID, UploadTime, InProgress, BenchSessionID FROM phoromatic_results ORDER BY UploadTime DESC LIMIT 8");
' 2>&1
}

qc_start_reply() {  # qc_start_reply <title>: what the server answers a system's "start" request now (the client's work request), plus the server's non-200 log lines
	local msi; msi=$(docker exec "$QC_SRV" php -r '$d=new SQLite3("/var/lib/benchy/phoromatic/phoromatic.db"); echo $d->querySingle("SELECT MachineSelfID FROM phoromatic_systems WHERE Title=\"" . SQLite3::escapeString($argv[1]) . "\" AND DeletedOn IS NULL");' "$1")
	echo "    a start reply for '$1' (msi ${msi:-?}) now reads:"
	curl -s -m 30 "http://127.0.0.1:$QC_PORT/phoromatic.php" -d "aid=$QC_AID" -d "msi=$msi" -d pts_core=99999 -d r=start | head -c 1000 | sed 's/^/      | /'; echo
	echo "    server log, non-200 lines and PHP messages (last 15):"
	docker logs "$QC_SRV" 2>&1 | grep -v '\[200\]: ' | tail -15 | cut -c1-300 | sed 's/^/      srv| /'
}

qc_phase_reset() {
	local out; out=$(_qc_php '
$n = function($sql) use ($d) { return (int)$d->querySingle($sql); };
$leaks = array();
$c = $n("SELECT COUNT(*) FROM phoromatic_schedules WHERE State = 1");               if ($c) { $leaks[] = "$c active schedule(s)"; $d->exec("UPDATE phoromatic_schedules SET State = 0"); }
$c = $n("SELECT COUNT(*) FROM phoromatic_schedules_triggers");                      if ($c) { $leaks[] = "$c trigger(s)"; $d->exec("DELETE FROM phoromatic_schedules_triggers"); }
$c = $n("SELECT COUNT(*) FROM phoromatic_benchmark_tickets WHERE State = 1");       if ($c) { $leaks[] = "$c active ticket(s)"; $d->exec("UPDATE phoromatic_benchmark_tickets SET State = 0"); }
$c = $n("SELECT COUNT(*) FROM phoromatic_bench_sessions WHERE EndedOn IS NULL");    if ($c) { $leaks[] = "$c open bench session(s)"; $d->exec("UPDATE phoromatic_bench_sessions SET EndedOn = datetime(\"now\"), EndedBy = \"qc-reset\", EndReason = \"qc-reset\" WHERE EndedOn IS NULL"); }
$c = $n("SELECT COUNT(*) FROM phoromatic_bench_tokens WHERE RevokedOn IS NULL");    if ($c) { $leaks[] = "$c live bench token(s)"; $d->exec("UPDATE phoromatic_bench_tokens SET RevokedOn = datetime(\"now\"), RevokedBy = \"qc-reset\", RevokeReason = \"qc-reset\" WHERE RevokedOn IS NULL"); }
$c = $n("SELECT COUNT(*) FROM phoromatic_systems WHERE BenchID IS NOT NULL OR BenchDrift IS NOT NULL OR BenchTaskSession IS NOT NULL"); if ($c) { $leaks[] = "$c system(s) on a bench / with drift"; $d->exec("UPDATE phoromatic_systems SET BenchID = NULL, BenchDrift = NULL, BenchTaskSession = NULL, BenchTaskIssuedOn = NULL"); }
$c = $n("SELECT COUNT(*) FROM phoromatic_systems WHERE State != 1 AND DeletedOn IS NULL"); if ($c) { $leaks[] = "$c unapproved system(s)"; $d->exec("UPDATE phoromatic_systems SET State = 1 WHERE DeletedOn IS NULL"); }
echo implode(", ", $leaks);
' 2>&1)
	echo "  [state reset before ${1:-phase}] ${out:-nothing left over}"
}
