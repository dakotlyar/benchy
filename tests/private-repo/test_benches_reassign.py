"""Test benches 2C (Benchy 0.9): the result reassign tool and "Run again on the current component" for an old benchmark ticket.

Run through tests/private-repo/run.sh (part of http/all and benches).
"""
import json
import time
import unittest
import uuid

from bench_helpers import (act, bench_audit, drop_schedule, expect_task, make_bench, new_system, open_session, result_row, schedule, start, swap, today, token,
                           upload)
from test_benches_issue import srv_php
from test_private_repo_http import AID, sql
from test_private_repo_tokens import sql_exec
from test_private_repo_web import WebBase


def rpost(browser, bid, action, uids, target, tok='auto'):
    """The reassign form as a browser posts it (upload_ids[] repeated)"""
    if tok == 'auto':
        tok = token(browser)
    data = [('bench_action', action), ('target', str(target))] + [('upload_ids[]', str(u)) for u in uids] + ([('token_submit', tok)] if tok else [])
    return browser.post('/?benches/%d/reassign' % bid, data)[2]


def reassign(browser, bid, uids, target, tok='auto'):
    return rpost(browser, bid, 'reassign', uids, target, tok)


def session_of(uid):
    r = result_row(uid)
    return (r['BenchSessionID'], r['BenchSessionSource'])


class TestReassign(WebBase):
    def test_history_and_moves_between_sessions(self):
        msi, sid = new_system()
        old = upload(msi)  # before the promote: no session
        from bench_helpers import promote, bench_of
        promote(self.admin, sid, source='new', cls='other', label='qc ra A %s' % uuid.uuid4().hex[:4])
        bid = bench_of(sid)
        s1 = open_session(bid)['SessionID']
        page = self.admin.get('/?benches/%d/reassign&session=none' % bid)[2]
        self.assertIn('value="%d"' % int(old), page, 'pre-bench history of a member is listed')
        # the confirm page (GET-free: a POST that changes nothing)
        body = rpost(self.admin, bid, 'reassign_confirm', [old], s1)
        self.assertIn('Reassign 1 result', body)
        self.assertEqual(session_of(old), (None, None), 'the confirm step changes nothing')
        self.assertIn('moved to S%d' % s1, reassign(self.admin, bid, [old], s1))
        self.assertEqual(session_of(old), (s1, 'reassign'))
        a = bench_audit(bid, 'result_reassign')[-1]
        self.assertEqual(json.loads(a['Detail'])['moves'], [{'upload': int(old), 'from': None, 'to': 'S%d' % s1}])
        # a result of S2 back to S1, then to "unassigned"; the deviation mark goes
        swap(self.admin, bid, cls='other')
        s2 = open_session(bid)['SessionID']
        b = upload(msi)
        # (an hour later than now, so it is clearly after S1 ended, not in the same second)
        sql_exec("UPDATE phoromatic_results SET BenchDeviation='[{\"key\": \"gpu\"}]', UploadTime=datetime('now', 'localtime', '+1 hour') WHERE UploadID=%d" % int(b))
        body = rpost(self.admin, bid, 'reassign_confirm', [b], s1)
        self.assertIn('outside S%d' % s1, body, 'uploaded after S1 ended: a warning')
        self.assertIn('open session S%d' % s2, body)
        self.assertIn('moved to S%d' % s1, reassign(self.admin, bid, [b], s1))
        self.assertEqual(session_of(b), (s1, 'reassign'))
        self.assertIsNone(result_row(b)['BenchDeviation'])
        self.assertIn('moved to no session', reassign(self.admin, bid, [b], 'none'))
        self.assertEqual(session_of(b), (None, None))
        # the viewer label follows: S1's component, then nothing bench-like
        pprid = result_row(old)['PPRID']
        self.assertIn(sql("SELECT c.Label FROM phoromatic_bench_sessions s JOIN phoromatic_components c ON c.ComponentID=s.ComponentID WHERE s.SessionID=%d" % s1)[0]['Label'], self.admin.get('/?result/%s' % pprid)[2])

    def test_moving_out_of_the_open_session_lets_the_schedule_run_again(self):
        msi, sid, bid, s1 = make_bench(self.admin, cls='other')
        swap(self.admin, bid, cls='other')
        s2 = open_session(bid)['SessionID']
        sched = schedule(sid)
        try:
            expect_task(msi, sid, 'benchmark', 'the schedule is due', sched)
            uid = upload(msi, sched=sched, ts=today())
            expect_task(msi, sid, 'idle', 'it ran today in S2', sched)
            self.assertIn('moved to S%d' % s1, reassign(self.admin, bid, [uid], s1))
            expect_task(msi, sid, 'benchmark', 'S2 has no run of today any more', sched)
        finally:
            drop_schedule(sched)

    def test_refusals(self):
        msi, sid, bid, s1 = make_bench(self.admin, cls='other')
        msi2, sid2, bid2, s2 = make_bench(self.admin, cls='other')
        mine = upload(msi)
        other = upload(msi2)
        self.assertIn('do not belong to this bench', reassign(self.admin, bid, [mine, other], 'none'))
        self.assertIn('not a session of this bench', reassign(self.admin, bid, [mine], s2))
        sql_exec("UPDATE phoromatic_results SET InProgress=1 WHERE UploadID=%d" % int(mine))
        self.assertIn('still being uploaded', reassign(self.admin, bid, [mine], 'none'))
        sql_exec("UPDATE phoromatic_results SET InProgress=0 WHERE UploadID=%d" % int(mine))
        self.assertIn('At most 500', reassign(self.admin, bid, list(range(1, 502)), 'none'))
        self.assertIn('Tick at least one', reassign(self.admin, bid, [], 'none'))
        b3 = self.as_user(3)
        self.assertIn('Only account administrators', reassign(b3, bid, [mine], 'none', tok=token(b3) or token(self.admin)))
        self.assertIn('form expired', reassign(self.admin, bid, [mine], 'none', tok=None))
        self.assertEqual(session_of(mine), (s1, 'upload'))
        self.assertEqual(session_of(other), (s2, 'upload'))
        self.assertEqual(bench_audit(bid, 'result_reassign'), [])


def offered(sid):
    return srv_php('$r = phoromatic_server::get_system_details("%s", "%s"); $t = phoromatic_server::system_check_for_open_benchmark_ticket("%s", "%s", $r); echo $t ? $t["TicketID"] : "none";' % (AID, sid, AID, sid)).strip()


class TestTicketRerun(WebBase):
    def test_an_old_ticket_runs_again_once_on_the_new_component(self):
        msi, sid, bid, s1 = make_bench(self.admin, cls='other')
        msi2, sid2 = new_system()  # not a member, also targeted
        tid = 860000 + int(uuid.uuid4().int % 99999)
        sql_exec("INSERT INTO phoromatic_benchmark_tickets (AccountID, TicketID, TicketIssueTime, Title, State, RunTargetSystems, RunTargetGroups) VALUES ('%s', %d, '%d', 'qc old ticket %d', 1, '%s,%s', '')" % (AID, tid, int(time.time()) - 3 * 86400, tid, sid, sid2))
        self.assertNotEqual(offered(sid), str(tid), 'three days old: no longer offered')
        page = self.admin.get('/?benches/%d' % bid)[2]
        self.assertIn('qc old ticket %d' % tid, page)
        self.assertIn('Run again on the current component', page)
        out = act(self.admin, '/?benches/%d' % bid, 'ticket_rerun', ticket_id=str(tid))
        self.assertIn('will run again', out)
        self.assertEqual(json.loads(bench_audit(bid, 'ticket_rerun_request')[-1]['Detail'])['ticket'], tid)
        self.assertEqual(offered(sid), str(tid), 'offered to the member again')
        self.assertNotEqual(offered(sid2), str(tid), 'not to a non-member')
        self.assertIn('already requested', act(self.admin, '/?benches/%d' % bid, 'ticket_rerun', ticket_id=str(tid)))
        upload(msi, bid=tid, identifier='qc rerun')
        self.assertNotEqual(offered(sid), str(tid), 'once: it ran in this session')
        self.assertIn('rerun requested', self.admin.get('/?benches/%d' % bid)[2])
        # a new session: the button is offered again
        swap(self.admin, bid, cls='other')
        self.assertIn('Run again on the current component', self.admin.get('/?benches/%d' % bid)[2])
        # refused while on hold, and for level 3
        b3 = self.as_user(3)
        self.assertIn('Only account administrators', act(b3, '/?benches/%d' % bid, 'ticket_rerun', tok=token(b3) or token(self.admin), ticket_id=str(tid)))
        sql_exec("UPDATE phoromatic_systems SET BenchDrift='{\"kind\": \"drift\", \"fields\": []}' WHERE SystemID='%s'" % sid)
        self.assertIn('on hold', act(self.admin, '/?benches/%d' % bid, 'ticket_rerun', ticket_id=str(tid)))
        sql_exec("UPDATE phoromatic_systems SET BenchDrift=NULL WHERE SystemID='%s'" % sid)
        sql_exec("UPDATE phoromatic_benchmark_tickets SET State=0 WHERE TicketID=%d" % tid)


if __name__ == '__main__':
    unittest.main()
