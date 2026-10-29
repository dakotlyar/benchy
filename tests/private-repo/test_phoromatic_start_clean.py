"""A client's "start" reply must be bare JSON, whatever other schedules and tickets the account has.

The client only acts on a reply whose first character is '{' (anything else is dropped silently and it asks again a minute later), so a PHP
notice printed before the JSON means that the system never gets work. A system without groups has Groups NULL, and a schedule or ticket aimed
at other systems made the server pass that NULL to stripos() (a Deprecated notice on PHP 8.1+ with display_errors on). The same for a
schedule with no active day (ActiveOn NULL) and schedules or tickets with NULL targets.

Run through tests/private-repo/run.sh (part of http/all).
"""
import json
import time
import unittest

from bench_helpers import CORE, drop_schedule, new_system, pp, schedule
from test_private_repo_http import AID, raw_post
from test_private_repo_tokens import sql_exec


def raw_start(msi):
    return raw_post('/phoromatic.php', {'r': 'start', 'aid': AID, 'msi': msi, 'pts_core': CORE, 'n': 'qcbench', 'pp': pp()})


class TestStartReplyIsBareJson(unittest.TestCase):
    def test_a_schedule_for_another_system_adds_no_notice(self):
        msi, sid = new_system()
        _, other = new_system()
        sched = schedule(other)
        try:
            body = raw_start(msi)
            self.assertTrue(body.startswith('{'), 'reply does not start with JSON: %r' % body[:300])
            self.assertEqual(json.loads(body)['phoromatic']['task'], 'idle')
        finally:
            drop_schedule(sched)

    def test_a_schedule_with_no_active_days_adds_no_notice(self):
        # the schedule form saves NULL for ActiveOn when no day is ticked (a run-now-only schedule); likewise NULL targets
        msi, sid = new_system()
        sched = schedule(sid)
        sql_exec("UPDATE phoromatic_schedules SET ActiveOn=NULL, RunTargetGroups=NULL WHERE ScheduleID=%d" % sched)
        other = schedule(sid)
        sql_exec("UPDATE phoromatic_schedules SET ActiveOn=NULL, RunTargetSystems=NULL, RunTargetGroups=NULL WHERE ScheduleID=%d" % other)
        try:
            body = raw_start(msi)
            self.assertTrue(body.startswith('{'), 'reply does not start with JSON: %r' % body[:300])
        finally:
            drop_schedule(sched)
            drop_schedule(other)

    def test_a_ticket_for_another_system_adds_no_notice(self):
        msi, sid = new_system()
        _, other = new_system()
        tid = 880000 + int(time.time()) % 100000
        sql_exec("INSERT INTO phoromatic_benchmark_tickets (AccountID, TicketID, TicketIssueTime, Title, State, RunTargetSystems, RunTargetGroups) VALUES ('%s', %d, '%d', 'qc start ticket', 1, '%s', '')" % (AID, tid, time.time() - 60, other))
        try:
            body = raw_start(msi)
            self.assertTrue(body.startswith('{'), 'reply does not start with JSON: %r' % body[:300])
            # a ticket aimed at this very system with NULL EnvironmentVariables (the ticket path of start)
            sql_exec("UPDATE phoromatic_benchmark_tickets SET RunTargetSystems='%s', EnvironmentVariables=NULL WHERE TicketID=%d" % (sid, tid))
            body = raw_start(msi)
            self.assertTrue(body.startswith('{'), 'reply does not start with JSON: %r' % body[:300])
        finally:
            sql_exec("UPDATE phoromatic_benchmark_tickets SET State=0 WHERE TicketID=%d" % tid)


if __name__ == '__main__':
    unittest.main()
