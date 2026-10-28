"""Test benches 2A (Benchy 0.8): the task issue path (start.php), the issue-time session snapshot, result attribution (result_upload.php),
the session-aware "already ran" check, progressive uploads, ad-hoc uploads, adopt, and an ordinary system's reply staying byte-identical.

Run through tests/private-repo/run.sh (part of http/all).
"""
import json
import os
import re
import subprocess
import unittest
import uuid

from bench_helpers import (CPU_A, act, baseline, bench_of, make_bench, new_system, open_session, req, result_row, schedule, drop_schedule, sessions,
                           start, swap, system_row, token, today, upload)
from test_private_repo_http import AID, CORE, SRV, sql, srv_sh
from test_private_repo_tokens import sql_exec
from test_private_repo_web import WebBase
from test_systems_adoption import action as adoption_action, checkin, by_msi

HERE = os.path.dirname(os.path.abspath(__file__))
BASE_COMMIT = '7ce8d038a'  # v0.7.4, the commit benches 0.8 is based on


def srv_php(code):
    """Run PHP inside the server container with pts-core and the Phoromatic database loaded"""
    driver = ('chdir("/bench/pts-core/phoromatic/public_html"); define("PHOROMATIC_SERVER", true); define("PTS_MODE", "WEB_CLIENT"); define("PTS_AUTO_LOAD_OBJECTS", true);'
              'include("../../pts-core.php"); pts_core::init(); phoromatic_server::prepare_database(); ' + code)
    return subprocess.run(['docker', 'exec', SRV, 'php', '-d', 'display_errors=0', '-r', driver], capture_output=True, text=True).stdout


def has_outstanding_jobs(sid):
    return srv_php('echo phoromatic_server::system_has_outstanding_jobs("%s", "%s") ? "yes" : "no";' % (AID, sid)).strip()


def new_component(cls='other'):
    cid = 700000 + int(uuid.uuid4().int % 99999)
    sql_exec("INSERT INTO phoromatic_components (ComponentID, AccountID, Class, Label, CreatedOn, CreatedBy) VALUES (%d, '%s', '%s', 'qc sqlcomp %d', '2026-01-01', 'qc')" % (cid, AID, cls, cid))
    return cid


def swap_by_sql(bid, old_session):
    """End the open session and open another one directly in the database (bypassing the outstanding-task refusal)"""
    sql_exec("UPDATE phoromatic_bench_sessions SET EndedOn='2026-01-01 00:00:00', EndReason='swap' WHERE SessionID=%d" % old_session)
    sql_exec("INSERT INTO phoromatic_bench_sessions (AccountID, BenchID, ComponentID, StartedOn, StartedBy) VALUES ('%s', %d, %d, '2026-01-01 00:00:01', 'qc')" % (AID, bid, new_component()))
    return int(open_session(bid)['SessionID'])


class TestIssue(WebBase):
    def test_bench_without_session_gets_no_task(self):
        msi, sid, bid, s1 = make_bench(self.admin, cls='other')
        sched = schedule(sid)
        try:
            act(self.admin, '/?benches/%d' % bid, 'end')
            r = start(msi)
            self.assertEqual(r.get('task'), 'idle')
            self.assertIn('Bench needs a component', r['response'])
            self.assertEqual(has_outstanding_jobs(sid), 'no')
            self.assertIsNone(system_row(sid)['BenchTaskSession'])
            # with a session the schedule is due again (control)
            swap(self.admin, bid, cls='other')
            self.assertEqual(has_outstanding_jobs(sid), 'yes')
        finally:
            drop_schedule(sched)

    def test_task_carries_bench_object_env_vars_and_snapshot(self):
        msi, sid, bid, s1 = make_bench(self.admin, cls='other', label='qc env, comp=x;y %s' % uuid.uuid4().hex[:4])
        sql_exec("UPDATE phoromatic_systems SET SystemVariables='SERIAL=abc;' WHERE SystemID='%s'" % sid)
        sched = schedule(sid)
        try:
            r = start(msi)
            self.assertEqual(r.get('task'), 'benchmark', str(r)[:300])
            s = open_session(bid)
            self.assertEqual(r['bench'], {'session': s1, 'component': {'label': s['Label'], 'class': 'other'}, 'bench': {'id': bid, 'title': sql("SELECT Title FROM phoromatic_benches WHERE BenchID=%d" % bid)[0]['Title']}})
            vars_ = r['pre_set_sys_env_vars']
            self.assertTrue(vars_.startswith('SERIAL=abc;BENCHY_BENCH_SESSION=%d;BENCHY_BENCH_COMPONENT=' % s1), vars_)
            for v in vars_.strip(';').split(';'):
                self.assertEqual(len(v.split('=')), 2, 'the client splits on = and ;: %r' % v)
            self.assertEqual(r['trigger_id'], today(), 'the trigger is not suffixed with the session')
            self.assertTrue(r['save_identifier'].endswith(' - S%d' % s1), r['save_identifier'])
            self.assertEqual(system_row(sid)['CurrentTask'], 'Task issued (bench S%d)' % s1)
            row = system_row(sid)
            self.assertEqual(row['BenchTaskSession'], s1)
            self.assertIsNotNone(row['BenchTaskIssuedOn'])
            # asking for work again does not end the snapshot (a restarted client may resume its run); the final upload does
            sql_exec("UPDATE phoromatic_schedules SET State=0 WHERE ScheduleID=%d" % sched)
            r = start(msi)
            self.assertEqual(r.get('task'), 'idle')
            self.assertNotIn('bench', r)
            self.assertEqual(system_row(sid)['BenchTaskSession'], s1)
            upload(msi, sched=sched, ts=today())
            self.assertIsNone(system_row(sid)['BenchTaskSession'])
        finally:
            drop_schedule(sched)

    def test_upload_goes_to_the_session_of_the_issue_snapshot(self):
        msi, sid, bid, s1 = make_bench(self.admin, cls='other')
        sched = schedule(sid)
        try:
            self.assertEqual(start(msi).get('task'), 'benchmark')
            s2 = swap_by_sql(bid, s1)
            uid = upload(msi, sched=sched, ts=today())
            r = result_row(uid)
            self.assertEqual((r['BenchSessionID'], r['BenchSessionSource']), (s1, 'task'))
        finally:
            drop_schedule(sched)

    def test_daily_schedule_runs_once_per_component_per_day(self):
        msi, sid, bid, s1 = make_bench(self.admin, cls='other')
        sched = schedule(sid)
        try:
            self.assertEqual(start(msi).get('task'), 'benchmark')
            upload(msi, sched=sched, ts=today())
            self.assertEqual(start(msi).get('task'), 'idle', 'ran twice in one session')
            swap(self.admin, bid, cls='other')
            s2 = open_session(bid)['SessionID']
            r = start(msi)
            self.assertEqual(r.get('task'), 'benchmark', 'the new component did not get today\'s run')
            self.assertEqual(r['bench']['session'], s2)
            self.assertTrue(r['save_identifier'].endswith(' - S%d' % s2), 'a second same-day session gets its own save name')
            self.assertEqual(r['trigger_id'], today())
            uid = upload(msi, sched=sched, ts=today())
            self.assertEqual(result_row(uid)['BenchSessionID'], s2)
            self.assertEqual(start(msi).get('task'), 'idle', 'a third run')
            self.assertEqual(len(sql("SELECT 1 FROM phoromatic_results WHERE SystemID='%s' AND ScheduleID=%d" % (sid, sched))), 2)
            # a schedule skip still applies to every session
            swap(self.admin, bid, cls='other')
            sql_exec("INSERT INTO phoromatic_schedules_trigger_skips (AccountID, ScheduleID, Trigger) VALUES ('%s', %d, '%s')" % (AID, sched, today()))
            self.assertEqual(start(msi).get('task'), 'idle')
        finally:
            drop_schedule(sched)

    def test_progressive_upload_does_not_cross_sessions(self):
        msi, sid, bid, s1 = make_bench(self.admin, cls='other')
        sched = schedule(sid)
        try:
            start(msi)
            first = upload(msi, sched=sched, ts=today(), progressive=1)
            self.assertEqual((result_row(first)['BenchSessionID'], int(result_row(first)['InProgress'])), (s1, 1))
            s2 = swap_by_sql(bid, s1)
            self.assertEqual(start(msi)['bench']['session'], s2)
            final = upload(msi, sched=sched, ts=today(), progressive=2)
            self.assertNotEqual(final, first, 'S2\'s run continued S1\'s in-progress row')
            self.assertEqual(result_row(final)['BenchSessionID'], s2)
            self.assertEqual(result_row(first)['BenchSessionID'], s1)
            # within one session a progressive run still continues its own row (control)
            start(msi)
            sql_exec("DELETE FROM phoromatic_results WHERE UploadID=%d" % final)
            again = upload(msi, sched=sched, ts=today(), progressive=1)
            done = upload(msi, sched=sched, ts=today(), progressive=2)
            self.assertEqual(again, done)
        finally:
            drop_schedule(sched)

    def test_adhoc_upload_goes_to_the_open_session(self):
        msi, sid, bid, s1 = make_bench(self.admin, cls='other')
        uid = upload(msi)
        self.assertEqual((result_row(uid)['BenchSessionID'], result_row(uid)['BenchSessionSource']), (s1, 'upload'))
        # a scheduled upload without a snapshot (issued before the upgrade) is ad-hoc too
        uid = upload(msi, sched=12345, ts='2026-01-01')
        self.assertEqual(result_row(uid)['BenchSessionSource'], 'upload')
        act(self.admin, '/?benches/%d' % bid, 'end')
        uid = upload(msi)
        self.assertIsNone(result_row(uid)['BenchSessionID'])
        self.assertIn('ad-hoc', self.admin.get('/?benches/%d' % bid)[2])
        # an ordinary system's uploads carry no bench columns
        msi2, sid2 = new_system()
        uid = upload(msi2)
        r = result_row(uid)
        self.assertEqual((r['BenchSessionID'], r['BenchSessionSource'], r['BenchDeviation']), (None, None, None))

    def test_adopt_of_a_bench_clears_the_snapshot_and_keeps_the_bench(self):
        nm = '02:' + ':'.join(uuid.uuid4().hex[i:i + 2] for i in range(0, 10, 2))
        msi = str(uuid.uuid4())
        checkin(msi, nm)
        sid = by_msi(msi)['SystemID']
        sql_exec("UPDATE phoromatic_systems SET State=1, Title='qcadopt%s', LastCommunication='2020-01-01 00:00:00' WHERE SystemID='%s'" % (uuid.uuid4().hex[:4], sid))
        act(self.admin, '/?benches/promote/%s' % sid, 'promote', title='qc adopt bench %s' % uuid.uuid4().hex[:4], source='new', label='qc adopt %s' % uuid.uuid4().hex[:5], **{'class': 'other'})
        bid = bench_of(sid)
        s1 = open_session(bid)['SessionID']
        sql_exec("UPDATE phoromatic_systems SET BenchTaskSession=%d, BenchTaskIssuedOn='2026-01-01' WHERE SystemID='%s'" % (s1, sid))
        newmsi = str(uuid.uuid4())
        checkin(newmsi, nm)
        pending = by_msi(newmsi)['SystemID']
        self.assertIn('now uses the existing system', adoption_action(self.admin, pending, 'adopt_system', extra={'confirm_active': '1'}))
        r = system_row(sid)
        self.assertEqual((r['MachineSelfID'], r['BenchID'], r['BenchTaskSession'], r['BenchTaskIssuedOn']), (newmsi, bid, None, None))
        self.assertEqual(open_session(bid)['SessionID'], s1)
        # F4: clearing is safe because the old install (whose run the snapshot was for) can no longer upload; the clear is audited
        detail = json.loads(sql("SELECT Detail FROM phoromatic_admin_audit WHERE Action='system_adopt' AND SubjectID='%s' ORDER BY AuditID DESC LIMIT 1" % sid)[0]['Detail'])
        self.assertEqual(detail['bench_task_session_cleared'], s1)
        before = sql("SELECT COUNT(*) AS n FROM phoromatic_results WHERE SystemID='%s'" % sid)[0]['n']
        self.assertIsNone(upload(msi, sched=1, ts=today()), 'the old install uploaded a result after the adoption')
        self.assertEqual(sql("SELECT COUNT(*) AS n FROM phoromatic_results WHERE SystemID='%s'" % sid)[0]['n'], before)
        self.assertIn('started with', swap(self.admin, bid), 'the cleared snapshot still blocks a session change')


    def test_held_bench_is_never_powered_off(self):
        msi, sid, bid, s1 = make_bench(self.admin, cls='other')
        msi2, sid2 = new_system()
        sql_exec("UPDATE phoromatic_account_settings SET PowerOffWhenDone=1 WHERE AccountID='%s'" % AID)
        try:
            self.assertEqual(start(msi2).get('task'), 'shutdown', 'control: an ordinary idle system is powered off')
            act(self.admin, '/?benches/%d' % bid, 'end')
            r = start(msi)
            self.assertEqual(r.get('task'), 'idle')
            self.assertIn('Bench needs a component', r['response'])
            swap(self.admin, bid, cls='cpu', label='qc hold %s' % uuid.uuid4().hex[:4])
            swap(self.admin, bid, cls='cpu', label='qc hold2 %s' % uuid.uuid4().hex[:4])  # awaiting swap: a hold
            self.assertEqual(start(msi).get('task'), 'idle')
        finally:
            sql_exec("UPDATE phoromatic_account_settings SET PowerOffWhenDone=0 WHERE AccountID='%s'" % AID)

    def test_snapshot_failure_withdraws_the_task(self):
        msi, sid, bid, s1 = make_bench(self.admin, cls='other')
        sched = schedule(sid)
        self.assertEqual(sql_exec("CREATE TRIGGER qc_block_snapshot BEFORE UPDATE OF BenchTaskSession ON phoromatic_systems WHEN NEW.BenchTaskSession IS NOT NULL BEGIN SELECT RAISE(ABORT, 'qc'); END"), 'ok')
        try:
            r = start(msi)
            self.assertEqual(r.get('task'), 'idle', str(r)[:300])
            self.assertNotIn('bench', r)
            self.assertNotIn('test_suite', r)
            self.assertIn('could not be recorded', r['response'])
        finally:
            sql_exec('DROP TRIGGER qc_block_snapshot')
            drop_schedule(sched)
        self.assertIsNone(system_row(sid)['BenchTaskSession'])

    def test_ticket_is_done_once_per_session(self):
        msi, sid, bid, s1 = make_bench(self.admin, cls='other')
        ticket = 880000 + int(uuid.uuid4().int % 99999)
        uid = upload(msi, bid=ticket, identifier='qc ticket')
        self.assertEqual(result_row(uid)['BenchSessionID'], s1)
        match = lambda: srv_php('echo phoromatic_server::check_for_benchmark_ticket_result_match(%d, "%s", "%s", 0) ? "done" : "open";' % (ticket, AID, sid)).strip()
        self.assertEqual(match(), 'done')
        swap(self.admin, bid, cls='other')
        self.assertEqual(match(), 'open', 'the new component never runs the ticket')
        msi2, sid2 = new_system()
        upload(msi2, bid=ticket, identifier='qc ticket')
        self.assertEqual(srv_php('echo phoromatic_server::check_for_benchmark_ticket_result_match(%d, "%s", "%s", 0) ? "done" : "open";' % (ticket, AID, sid2)).strip(), 'done', 'ordinary systems unchanged')


class TestOrdinarySystemUnchanged(WebBase):
    def old_start_php(self):
        try:
            return subprocess.run(['git', '-C', HERE, 'show', BASE_COMMIT + ':pts-core/phoromatic/communication-resources/start.php'], capture_output=True, text=True, check=True).stdout
        except Exception:
            return None

    def reply(self, msi, old=False):
        # old: the same request served by the base commit's start.php, through a copy of phoromatic.php whose resource require
        # points at it (phoromatic.php only includes listed resources, so r= cannot name the copy)
        f = {'r': 'start', 'aid': AID, 'msi': msi, 'pts_core': CORE, 'n': 'qcbench'}
        driver = ('$_POST=$_REQUEST=json_decode(stream_get_contents(STDIN),true); $_SERVER["REMOTE_ADDR"]="127.0.0.9";'
                  'chdir("/bench/pts-core/phoromatic/public_html"); include "%s";' % ('/tmp/qcold/phoromatic.php' if old else 'phoromatic.php'))
        out = subprocess.run(['docker', 'exec', '-i', SRV, 'php', '-d', 'display_errors=0', '-r', driver], input=json.dumps(f), capture_output=True, text=True).stdout
        out = out[out.index('{"phoromatic"'):]
        return re.sub(r'\[\d\d:\d\d:\d\d\]', '[T]', out)

    def test_task_reply_is_byte_identical_to_base(self):
        old = self.old_start_php()
        if old is None:
            self.skipTest('base commit %s not available in this checkout' % BASE_COMMIT)
        subprocess.run(['docker', 'exec', '-i', SRV, 'sh', '-c', 'mkdir -p /tmp/qcold && cat > /tmp/qcold/start.php'], input=old, text=True, check=True)
        subprocess.run(['docker', 'exec', SRV, 'sh', '-c', "sed \"s|require('../communication-resources/' . \\$REQUEST . '.php');|require('/tmp/qcold/start.php');|\" "
                        "/bench/pts-core/phoromatic/public_html/phoromatic.php > /tmp/qcold/phoromatic.php && grep -q \"require('/tmp/qcold/start.php')\" /tmp/qcold/phoromatic.php"], check=True)
        msi, sid = new_system()
        sql_exec("UPDATE phoromatic_systems SET SystemVariables='SERIAL=abc;' WHERE SystemID='%s'" % sid)
        # idle, then a schedule task
        self.assertEqual(self.reply(msi, old=True), self.reply(msi))
        sched = schedule(sid)
        try:
            new = self.reply(msi)
            self.assertIn('"task":"benchmark"', new)
            self.assertEqual(self.reply(msi, old=True), new)
            self.assertNotIn('"bench"', new)
            self.assertIsNone(system_row(sid)['BenchTaskSession'])
        finally:
            drop_schedule(sched)


class TestTwoMemberBench(WebBase):
    """The dual-boot path on 0.8 data: a second member linked directly (0.9 dropped the one-system index), and the bench-wide logic works"""

    def test_refusal_and_baselines_are_bench_wide(self):
        msi, sid, bid, s1 = make_bench(self.admin, cls='other')
        msi2, sid2 = new_system()
        try:
            self.assertEqual(sql_exec("UPDATE phoromatic_systems SET BenchID=%d WHERE SystemID='%s'" % (bid, sid2)), 'ok')
            # a snapshot on the OTHER member refuses the swap
            sql_exec("UPDATE phoromatic_systems SET BenchTaskSession=%d, LastCommunication=datetime('now', 'localtime') WHERE SystemID='%s'" % (s1, sid2))
            self.assertIn('has a task outstanding', swap(self.admin, bid))
            sql_exec("UPDATE phoromatic_systems SET BenchTaskSession=NULL WHERE SystemID='%s'" % sid2)
            swap(self.admin, bid, cls='other')
            s2 = open_session(bid)['SessionID']
            self.assertIsNotNone(baseline(s2, sid))
            self.assertIsNotNone(baseline(s2, sid2), 'one baseline row per member system')
            # each member issues its own task for the shared session
            sched = schedule('%s,%s' % (sid, sid2))
            try:
                self.assertEqual(start(msi)['bench']['session'], s2)
                self.assertEqual(start(msi2)['bench']['session'], s2)
            finally:
                drop_schedule(sched)
            # demoting one member keeps the bench; the last one retires it
            act(self.admin, '/?benches/%d' % bid, 'demote', system_id=sid2)
            self.assertIsNone(sql("SELECT RetiredOn FROM phoromatic_benches WHERE BenchID=%d" % bid)[0]['RetiredOn'])
            self.assertIsNotNone(open_session(bid))
        finally:
            sql_exec("UPDATE phoromatic_systems SET BenchID=NULL WHERE SystemID='%s'" % sid2)


if __name__ == '__main__':
    unittest.main()
