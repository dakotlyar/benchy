"""Multi-system benches (Benchy 0.9): one bench owning several systems, one per OS install of a dual-boot bench. Migration case 53
(the one-system index goes), the audited "Add a system to this bench" with its warnings (never refusals), two members sharing one
session, the per-member UI, removal, and the board-swap-plus-reinstall path.

Run through tests/private-repo/run.sh (part of http/all and benches).
"""
import json
import re
import unittest
import uuid

from bench_helpers import (CPU_A, CPU_B, act, baseline, bench_audit, bench_of, drift, drop_schedule, make_bench, new_system, open_session,
                           result_row, schedule, sessions, start, swap, system_row, today, token, upload)
from test_private_repo_http import AID, sql, srv_sh
from test_private_repo_tokens import sql_exec
from test_private_repo_web import Browser, WebBase
from test_systems_adoption import action as adoption_action, by_msi, checkin, mac
from test_systems_tombstone import delete_via_ui

LINUX = 'OS: Ubuntu 24.04, Kernel: 6.8.0-45-generic (x86_64), Desktop: GNOME Shell 46.0'
LINUX2 = 'OS: Fedora Linux 41, Kernel: 6.11.4-301.fc41.x86_64 (x86_64)'
WINDOWS = 'OS: Microsoft Windows 11 Pro Build 26100, Kernel: 10.0 (x86_64)'
PHP_ERROR = re.compile(r'(<b>)?(Fatal error|Warning|Notice)(</b>)?: |Uncaught|Stack trace|(<b>)?Deprecated(</b>)?: [^\n]*(phoromatic_bench|phoromatic_benches|phoromatic_result_access|pts_hardware_summary)\.php')


def set_os(sid, software):
    assert sql_exec("UPDATE phoromatic_systems SET Software='%s' WHERE SystemID='%s'" % (software, sid)) == 'ok'


def add(browser, bid, sid, tok='auto', headers=None):
    return act(browser, '/?benches/%d/add' % bid, 'member_add', tok=tok, headers=headers, system_id=sid)


def member_add_rows(sid):
    return [a for a in sql("SELECT * FROM phoromatic_admin_audit WHERE Action='bench_member_add' ORDER BY AuditID") if json.loads(a['Detail'])['system'] == sid]


def retired(bid):
    return sql("SELECT RetiredOn FROM phoromatic_benches WHERE BenchID=%d" % bid)[0]['RetiredOn'] is not None


def prepare_database():
    srv_sh("cd /bench/pts-core/phoromatic/public_html && php -r 'define(\"PHOROMATIC_SERVER\", true); define(\"PTS_MODE\", \"WEB_CLIENT\"); define(\"PTS_AUTO_LOAD_OBJECTS\", true); include(\"../../pts-core.php\"); pts_core::init(); phoromatic_server::prepare_database();'")


class Test0Migration(WebBase):
    # Named to run first in this module: a v53 database can hold the 0.8 index only while no bench has two live systems

    def test_v53_database_upgrades_to_54_and_again_is_a_no_op(self):
        make_bench(self.admin, cls='other')
        # A v53 database has at most one live system per bench: other suites may already have made multi-system benches here, so
        # their extra members leave their bench for the test and come back after it
        extra = [r['SystemID'] for r in sql("SELECT y.SystemID FROM phoromatic_systems y WHERE y.BenchID IS NOT NULL AND y.DeletedOn IS NULL AND EXISTS (SELECT 1 FROM phoromatic_systems z WHERE z.AccountID = y.AccountID AND z.BenchID = y.BenchID AND z.DeletedOn IS NULL AND z.rowid < y.rowid)")]
        benches = {s: sql("SELECT BenchID FROM phoromatic_systems WHERE SystemID='%s'" % s)[0]['BenchID'] for s in extra}
        for s in extra:
            sql_exec("UPDATE phoromatic_systems SET BenchID=NULL WHERE SystemID='%s'" % s)
        self.addCleanup(lambda: [sql_exec("UPDATE phoromatic_systems SET BenchID=%d WHERE SystemID='%s'" % (b, s)) for s, b in benches.items()])
        self.addCleanup(lambda: sql_exec('DROP INDEX IF EXISTS phoromatic_systems_one_per_bench'))
        self.assertEqual(sql_exec('PRAGMA user_version = 53'), 'ok')
        self.assertEqual(sql_exec('CREATE UNIQUE INDEX IF NOT EXISTS phoromatic_systems_one_per_bench ON phoromatic_systems (AccountID, BenchID) WHERE BenchID IS NOT NULL AND DeletedOn IS NULL'), 'ok')
        snapshot = lambda: (sql('SELECT SystemID, BenchID, BenchDrift, BenchTaskSession FROM phoromatic_systems ORDER BY SystemID'), sql('SELECT * FROM phoromatic_benches ORDER BY BenchID'),
                            sql('SELECT * FROM phoromatic_bench_sessions ORDER BY SessionID'), sql('SELECT * FROM phoromatic_bench_baselines ORDER BY SessionID, SystemID'))
        before = snapshot()
        index = lambda: [r['name'] for r in sql("SELECT name FROM sqlite_master WHERE name='phoromatic_systems_one_per_bench'")]
        self.assertEqual(index(), ['phoromatic_systems_one_per_bench'])
        prepare_database()
        self.assertEqual(sql('PRAGMA user_version')[0]['user_version'], 58)
        self.assertEqual(index(), [])
        self.assertEqual(snapshot(), before, 'the migration changes no row')
        prepare_database()
        self.assertEqual(sql('PRAGMA user_version')[0]['user_version'], 58)
        self.assertEqual(snapshot(), before)
        # case 53 itself is repeatable (run again with the index already gone)
        self.assertEqual(sql_exec('PRAGMA user_version = 53'), 'ok')
        prepare_database()
        self.assertEqual(sql('PRAGMA user_version')[0]['user_version'], 58)
        self.assertEqual(snapshot(), before)


class TestAddMember(WebBase):
    def test_add_from_the_bench_page(self):
        msi, sid, bid, s1 = make_bench(self.admin, cls='other')
        msi2, sid2 = new_system()
        title2 = system_row(sid2)['Title']
        sql_exec("UPDATE phoromatic_systems SET BenchDrift='{\"kind\": \"notice\"}', BenchTaskSession=999, BenchTaskIssuedOn='2026-01-01' WHERE SystemID='%s'" % sid2)
        page = self.admin.get('/?benches/%d' % bid)[2]
        self.assertIn('?benches/%d/add"' % bid, page, 'the bench page offers "Add a system..."')
        page = self.admin.get('/?benches/%d/add' % bid)[2]
        self.assertIn('?benches/%d/add&amp;system=%s' % (bid, sid2), page, 'the eligible system is listed')
        page = self.admin.get('/?benches/%d/add&system=%s' % (bid, sid2))[2]
        self.assertIn('value="member_add"', page)
        self.assertIn(title2, page)
        self.assertNotIn('Check before adding', page, 'no warning applies')
        self.assertEqual(bench_of(sid2), None, 'GET changes nothing')
        body = add(self.admin, bid, sid2)
        self.assertIn('is now a system of', body)
        r = system_row(sid2)
        self.assertEqual((r['BenchID'], r['BenchDrift'], r['BenchTaskSession'], r['BenchTaskIssuedOn']), (bid, None, None, None))
        self.assertEqual(bench_of(sid), bid, 'the first member stays')
        audit = bench_audit(bid)
        self.assertEqual(audit[-1]['Action'], 'bench_member_add')
        detail = json.loads(audit[-1]['Detail'])
        self.assertEqual((detail['system'], detail['system_title'], detail['session'], detail['warnings']), (sid2, title2, 'S%d' % s1, []))
        # no baseline is written at join time; the member's first check-in in the open session creates its own
        self.assertIsNone(baseline(s1, sid2))
        start(msi2)
        self.assertIsNotNone(baseline(s1, sid2))
        self.assertEqual(open_session(bid)['SessionID'], s1, 'the session stays')
        page = self.admin.get('/?benches/%d' % bid)[2]
        self.assertIn(title2, page)
        self.assertIn('/demote&amp;system=%s' % sid2, page, 'a "Remove from bench" link per member')

    def test_add_from_the_system_page_and_the_promote_page(self):
        msi, sid, bid, s1 = make_bench(self.admin, cls='other')
        msi2, sid2 = new_system()
        self.assertIn('?benches/add/%s' % sid2, self.admin.get('/?systems/%s' % sid2)[2], 'the system page offers "Add to bench..."')
        self.assertIn('Add to an existing bench', self.admin.get('/?benches/promote/%s' % sid2)[2])
        page = self.admin.get('/?benches/add/%s' % sid2)[2]
        self.assertIn('?benches/%d/add&amp;system=%s' % (bid, sid2), page, 'the bench is offered')
        self.assertIn('is now a system of', add(self.admin, bid, sid2))
        self.assertEqual(bench_of(sid2), bid)
        self.assertIn('already belongs to a bench', self.admin.get('/?benches/add/%s' % sid2)[2])
        # a pending system is not offered "Add to bench" (it is approved first)
        msi3, sid3 = new_system(state=0)
        self.assertNotIn('?benches/add/%s' % sid3, self.admin.get('/?systems/%s' % sid3)[2])
        self.assertNotIn('system=%s' % sid3, self.admin.get('/?benches/%d/add' % bid)[2])

    def test_refusals_change_nothing(self):
        msi, sid, bid, s1 = make_bench(self.admin, cls='other')
        # pending
        msi2, sid2 = new_system(state=0)
        self.assertIn('Only an approved', add(self.admin, bid, sid2))
        # a tombstone
        msi3, sid3 = new_system()
        sql_exec("UPDATE phoromatic_systems SET State=-1 WHERE SystemID='%s'" % sid3)
        delete_via_ui(self.admin, sid3)
        self.assertIsNotNone(system_row(sid3)['DeletedOn'])
        self.assertIn('System not found', add(self.admin, bid, sid3))
        # a disabled system
        msi4, sid4 = new_system()
        sql_exec("UPDATE phoromatic_systems SET State=-1 WHERE SystemID='%s'" % sid4)
        self.assertIn('Only an approved', add(self.admin, bid, sid4))
        # a system in another bench
        msi5, sid5, bid5, s5 = make_bench(self.admin, cls='other')
        self.assertIn('already belongs to a bench', add(self.admin, bid, sid5))
        # a retired bench
        msi6, sid6, bid6, s6 = make_bench(self.admin, cls='other')
        act(self.admin, '/?benches/%d' % bid6, 'demote', system_id=sid6)
        self.assertTrue(retired(bid6))
        msi7, sid7 = new_system()
        self.assertIn('Bench not found or retired', add(self.admin, bid6, sid7))
        # an unknown bench and a malformed system id
        self.assertIn('Bench not found', add(self.admin, 999999, sid7))
        self.assertIn('System not found', add(self.admin, bid, 'no/such'))
        for s in (sid2, sid3, sid4, sid7):
            self.assertIsNone(system_row(s)['BenchID'], s)
            self.assertEqual(member_add_rows(s), [])
        self.assertEqual(bench_of(sid5), bid5)
        self.assertEqual([m['SystemID'] for m in sql("SELECT SystemID FROM phoromatic_systems WHERE BenchID=%d AND DeletedOn IS NULL" % bid)], [sid])

    def test_levels_tokens_origin_and_other_account(self):
        msi, sid, bid, s1 = make_bench(self.admin, cls='other')
        msi2, sid2 = new_system()
        admin_tok = token(self.admin)
        for level in (3, 10):
            b = self.as_user(level)
            self.assertIn('Only account administrators', add(b, bid, sid2, tok=token(b) or admin_tok))
            self.assertNotIn('?benches/%d/add"' % bid, b.get('/?benches/%d' % bid)[2])
            self.assertIn('Only account administrators', b.get('/?benches/%d/add&system=%s' % (bid, sid2))[2])
        anon = Browser()
        anon.get('/')
        add(anon, bid, sid2, tok=admin_tok)
        self.assertIn('form expired', add(self.admin, bid, sid2, tok=None))
        self.assertIn('form expired', add(self.admin, bid, sid2, tok=token(self.admin2())))
        self.assertIn('form expired', add(self.admin, bid, sid2, headers={'Origin': 'http://evil.example'}))
        self.admin.get('/?benches/%d/add&system=%s&bench_action=member_add&system_id=%s' % (bid, sid2, sid2))
        self.assertIsNone(bench_of(sid2))
        # another account sees neither the bench nor the system
        user = 'qcmem%s' % uuid.uuid4().hex[:6]
        b = Browser()
        b.get('/')
        b.post('/?register', {'register_username': user, 'register_password': 'qcpass123', 'register_password_confirm': 'qcpass123', 'register_email': user + '@example.com'})
        b = Browser().login(user, 'qcpass123')
        self.assertIn('Bench not found', add(b, bid, sid2))
        self.assertIn('Bench not found', b.get('/?benches/%d/add' % bid)[2])
        self.assertIn('System not found', b.get('/?benches/add/%s' % sid2)[2])
        self.assertIsNone(bench_of(sid2))
        self.assertEqual(member_add_rows(sid2), [])
        # level 2 may
        self.assertIn('is now a system of', add(self.admin2(), bid, sid2))
        self.assertEqual(bench_of(sid2), bid)

    def test_warnings_show_and_do_not_block(self):
        msi, sid, bid, s1 = make_bench(self.admin)  # class cpu, CPU_A
        set_os(sid, LINUX)
        title = system_row(sid)['Title']
        # same OS family (a second Linux) and a different CPU: two warnings
        msi2, sid2 = new_system(cpu=CPU_B)
        set_os(sid2, LINUX2)
        page = self.admin.get('/?benches/%d/add&system=%s' % (bid, sid2))[2]
        self.assertIn('Check before adding', page)
        self.assertIn('Another member already runs Linux (%s)' % title, page)
        self.assertIn('Detected CPU differs: %s (%s) / %s' % (CPU_A, title, CPU_B), page)
        self.assertIn('Ubuntu 24.04', page, 'each system\'s OS is shown')
        self.assertIn('Fedora Linux 41', page)
        self.assertIn('is now a system of', add(self.admin, bid, sid2))
        warnings = json.loads(member_add_rows(sid2)[-1]['Detail'])['warnings']
        self.assertEqual(len(warnings), 2, warnings)
        self.assertEqual(json.loads(member_add_rows(sid2)[-1]['Detail'])['os'], 'Fedora Linux 41')
        # Windows with the same CPU spelled differently ((TM), a clock suffix): no warning
        msi3, sid3, bid3, s3 = make_bench(self.admin)
        set_os(sid3, LINUX)
        msi4, sid4 = new_system(cpu='AMD Ryzen(TM) 7 9800X3D 8-Core @ 4.70GHz')
        set_os(sid4, WINDOWS)
        page = self.admin.get('/?benches/%d/add&system=%s' % (bid3, sid4))[2]
        self.assertNotIn('Check before adding', page)
        self.assertIn('Microsoft Windows 11', page)
        self.assertIn('is now a system of', add(self.admin, bid3, sid4))
        self.assertEqual(json.loads(member_add_rows(sid4)[-1]['Detail'])['warnings'], [])
        # the bench list shows each member with its OS family
        listing = self.admin.get('/?benches')[2]
        self.assertIn('%s</a> (Linux)' % system_row(sid3)['Title'], listing)
        self.assertIn('%s</a> (Windows)' % system_row(sid4)['Title'], listing)

    def test_awaiting_swap_warning(self):
        msi, sid, bid, s1 = make_bench(self.admin)
        swap(self.admin, bid, cls='cpu', label='qc cpu %s' % uuid.uuid4().hex[:5])
        self.assertEqual(drift(sid)['kind'], 'awaiting')
        msi2, sid2 = new_system()
        page = self.admin.get('/?benches/%d/add&system=%s' % (bid, sid2))[2]
        self.assertIn('The bench is waiting for a swap. Boot this system only after the new part is in, or its first check-in becomes its baseline.', page)
        self.assertIn('is now a system of', add(self.admin, bid, sid2))
        self.assertIn('waiting for a swap', json.loads(member_add_rows(sid2)[-1]['Detail'])['warnings'][0])

    def test_audit_failure_changes_nothing(self):
        msi, sid, bid, s1 = make_bench(self.admin, cls='other')
        msi2, sid2 = new_system()
        self.assertEqual(sql_exec("CREATE TRIGGER qc_block_member_audit BEFORE INSERT ON phoromatic_admin_audit BEGIN SELECT RAISE(ABORT, 'qc'); END"), 'ok')
        try:
            self.assertIn('audit record could not be written', add(self.admin, bid, sid2))
        finally:
            sql_exec('DROP TRIGGER qc_block_member_audit')
        self.assertIsNone(bench_of(sid2))


def dual(test, cls='other', **ppkw):
    """A bench with two members added through the UI: (msiA, sidA, msiB, sidB, BenchID, SessionID)"""
    msi, sid, bid, s1 = make_bench(test.admin, cls=cls)
    msi2, sid2 = new_system(**ppkw)
    test.assertIn('is now a system of', add(test.admin, bid, sid2))
    return msi, sid, msi2, sid2, bid, s1


class TestTwoMembers(WebBase):
    def test_each_member_has_its_own_baseline(self):
        msi, sid, msi2, sid2, bid, s1 = dual(self, cls='cpu', cpu=CPU_A)
        base_a = baseline(s1, sid)['BaselineJSON']
        self.assertIsNone(baseline(s1, sid2))
        start(msi2)
        self.assertIsNone(drift(sid2))
        b = baseline(s1, sid2)
        self.assertEqual(json.loads(b['BaselineJSON'])['cpu']['model'], CPU_A)
        self.assertEqual(baseline(s1, sid)['BaselineJSON'], base_a, 'A\'s baseline untouched')
        page = self.admin.get('/?benches/%d' % bid)[2]
        self.assertIn('CPU baseline (S%d)' % s1, page)

    def test_a_task_on_either_member_blocks_and_force_needs_both_silent(self):
        msi, sid, msi2, sid2, bid, s1 = dual(self)
        sql_exec("UPDATE phoromatic_systems SET BenchTaskSession=%d, BenchTaskIssuedOn=datetime('now', 'localtime'), CurrentTask='Running', LastCommunication=datetime('now', 'localtime') WHERE SystemID='%s'" % (s1, sid))
        self.assertIn('has a task outstanding', swap(self.admin, bid), 'a task on A blocks a swap while B is idle')
        self.assertIn('has a task outstanding', act(self.admin, '/?benches/%d' % bid, 'end'))
        # A silent for 3 hours, B running a task too: Force does not help
        sql_exec("UPDATE phoromatic_systems SET LastCommunication=datetime('now', 'localtime', '-3 hours') WHERE SystemID='%s'" % sid)
        sql_exec("UPDATE phoromatic_systems SET BenchTaskSession=%d, BenchTaskIssuedOn=datetime('now', 'localtime'), CurrentTask='Running', LastCommunication=datetime('now', 'localtime') WHERE SystemID='%s'" % (s1, sid2))
        self.assertIn('is running it', swap(self.admin, bid, force='1'))
        # both silent: Force goes ahead
        sql_exec("UPDATE phoromatic_systems SET LastCommunication=datetime('now', 'localtime', '-3 hours') WHERE SystemID='%s'" % sid2)
        self.assertIn('tick Force', swap(self.admin, bid))
        self.assertIn('started with', swap(self.admin, bid, force='1'))
        self.assertNotEqual(open_session(bid)['SessionID'], s1)
        self.assertEqual(sorted(json.loads(bench_audit(bid, 'session_end')[-1]['Detail'])['outstanding_task_set_aside']), sorted([sid, sid2]))

    def test_a_swap_awaits_on_each_member_and_each_confirms_on_its_own(self):
        msi, sid, msi2, sid2, bid, s1 = dual(self, cls='cpu', cpu=CPU_A)
        start(msi)
        start(msi2)
        swap(self.admin, bid, cls='cpu', label='qc cpu %s' % uuid.uuid4().hex[:5])
        s2 = open_session(bid)['SessionID']
        for s in (sid, sid2):
            self.assertIsNotNone(baseline(s2, s)['AwaitingFromJSON'], s)
            self.assertEqual(drift(s)['kind'], 'awaiting', s)
        # the swap page listed each member's own wait
        self.assertIn('will wait for the swap until it boots', self.admin.get('/?benches/%d/swap' % bid)[2])
        # A boots with the new CPU: A confirms; B (the other OS, not booted yet) still waits
        start(msi, cpu=CPU_B)
        self.assertIsNone(baseline(s2, sid)['AwaitingFromJSON'])
        self.assertEqual(json.loads(baseline(s2, sid)['BaselineJSON'])['cpu']['model'], CPU_B)
        self.assertIsNone(drift(sid))
        start(msi2, cpu=CPU_A)
        self.assertEqual(drift(sid2)['kind'], 'awaiting')
        self.assertIsNotNone(baseline(s2, sid2)['AwaitingFromJSON'])
        page = self.admin.get('/?benches/%d' % bid)[2]
        self.assertIn('on hold: awaiting swap', page)
        self.assertIn('awaiting swap from', page)
        start(msi2, cpu=CPU_B)
        self.assertIsNone(baseline(s2, sid2)['AwaitingFromJSON'])
        self.assertIsNone(drift(sid2))

    def test_a_daily_schedule_runs_once_on_each_member_per_session(self):
        msi, sid, msi2, sid2, bid, s1 = dual(self)
        sched = schedule('%s,%s' % (sid, sid2))
        try:
            for m in (msi, msi2):
                r = start(m)
                self.assertEqual(r.get('task'), 'benchmark')
                self.assertEqual(r['bench']['session'], s1)
                upload(m, sched=sched, ts=today())
                self.assertEqual(start(m).get('task'), 'idle', 'ran twice in one session')
            swap(self.admin, bid, cls='other')
            s2 = open_session(bid)['SessionID']
            for m in (msi, msi2):
                r = start(m)
                self.assertEqual(r.get('task'), 'benchmark', 'a new session runs again on each member')
                self.assertEqual(r['bench']['session'], s2)
        finally:
            drop_schedule(sched)

    def test_labels_compare_column_and_filter(self):
        msi, sid, msi2, sid2, bid, s1 = dual(self)
        label = open_session(bid)['Label']
        ta, tb = system_row(sid)['Title'], system_row(sid2)['Title']
        sched = schedule('%s,%s' % (sid, sid2))
        try:
            start(msi)
            a = upload(msi, sched=sched, ts=today())
            start(msi2)
            b = upload(msi2, sched=sched, ts=today())
        finally:
            drop_schedule(sched)
        pa, pb = result_row(a)['PPRID'], result_row(b)['PPRID']
        page = self.admin.get('/?result/%s,%s' % (pa, pb))[2]
        self.assertIn('%s · %s' % (label, ta), page)
        self.assertIn('%s · %s' % (label, tb), page)
        # compare shows both OS results of the component, the System column and the System filter
        page = self.admin.get('/?benches/compare&bench=%d&schedule=%d' % (bid, sched))[2]
        self.assertIn(pa, page)
        self.assertIn(pb, page)
        self.assertIn('<th>System</th>', page)
        self.assertIn('name="system"', page)
        page = self.admin.get('/?benches/compare&bench=%d&schedule=%d&system=%s' % (bid, sched, sid2))[2]
        self.assertIn(pb, page)
        self.assertNotIn(pa, page)
        # the bench page's latest-per-component table shows both too
        page = self.admin.get('/?benches/%d' % bid)[2]
        self.assertIn(pa, page)
        self.assertIn(pb, page)

    def test_remove_one_member_then_the_last(self):
        msi, sid, msi2, sid2, bid, s1 = dual(self)
        self.assertIn('selected="selected">%s' % system_row(sid2)['Title'], self.admin.get('/?benches/%d/demote&system=%s' % (bid, sid2))[2])
        act(self.admin, '/?benches/%d' % bid, 'demote', system_id=sid2)
        self.assertIsNone(bench_of(sid2))
        self.assertFalse(retired(bid))
        self.assertEqual(open_session(bid)['SessionID'], s1, 'the session stays')
        act(self.admin, '/?benches/%d' % bid, 'demote', system_id=sid)
        self.assertTrue(retired(bid))
        self.assertEqual(sessions(bid)[-1]['EndReason'], 'bench_empty')

    def test_board_swap_with_os_reinstall(self):
        # The new install registers as a new (pending) system: approve it, add it, remove the old one; the history stays with the bench
        msi, sid, bid, s1 = make_bench(self.admin, cls='other')
        upload(msi)
        msi2, sid2 = new_system(state=0)
        self.assertIn('Only an approved', add(self.admin, bid, sid2))
        sql_exec("UPDATE phoromatic_systems SET State=1 WHERE SystemID='%s'" % sid2)
        self.assertIn('is now a system of', add(self.admin, bid, sid2))
        act(self.admin, '/?benches/%d' % bid, 'demote', system_id=sid)
        self.assertFalse(retired(bid))
        self.assertEqual(open_session(bid)['SessionID'], s1)
        self.assertEqual(sql("SELECT COUNT(*) AS n FROM phoromatic_results WHERE BenchSessionID=%d" % s1)[0]['n'], 1)
        start(msi2)
        self.assertIsNotNone(baseline(s1, sid2), 'the new install takes part in the open session')
        self.assertIn(system_row(sid2)['Title'], self.admin.get('/?benches/%d' % bid)[2])

    def test_adopting_a_member_keeps_its_bench(self):
        msi, sid, bid, s1 = make_bench(self.admin, cls='other')
        nm = mac()
        msi2 = str(uuid.uuid4())
        checkin(msi2, nm)
        sid2 = by_msi(msi2)['SystemID']
        sql_exec("UPDATE phoromatic_systems SET State=1, Title='qcmemadopt%s', LastCommunication='2020-01-01 00:00:00' WHERE SystemID='%s'" % (uuid.uuid4().hex[:4], sid2))
        self.assertIn('is now a system of', add(self.admin, bid, sid2))
        newmsi = str(uuid.uuid4())
        checkin(newmsi, nm)
        pending = by_msi(newmsi)['SystemID']
        self.assertIn('now uses the existing system', adoption_action(self.admin, pending, 'adopt_system', extra={'confirm_active': '1'}))
        r = system_row(sid2)
        self.assertEqual((r['MachineSelfID'], r['BenchID']), (newmsi, bid))
        self.assertEqual(bench_of(sid), bid)

    def test_pages_render_without_php_errors(self):
        msi, sid, msi2, sid2, bid, s1 = dual(self)
        set_os(sid, LINUX)
        set_os(sid2, WINDOWS)
        msi3, sid3 = new_system()
        pages = ['/?benches', '/?benches/%d' % bid, '/?benches/%d/add' % bid, '/?benches/%d/add&system=%s' % (bid, sid3), '/?benches/%d/add&system=NOPE' % bid,
                 '/?benches/add/%s' % sid3, '/?benches/add/NOPE', '/?benches/%d/demote&system=%s' % (bid, sid2), '/?benches/%d/swap' % bid,
                 '/?benches/compare&bench=%d&system=%s' % (bid, sid), '/?benches/promote/%s' % sid3, '/?systems/%s' % sid3, '/?systems/%s' % sid2]
        for who, browser in (('admin', self.admin), ('viewer', self.as_user(10))):
            for page in pages:
                status, _, body = browser.get(page)
                self.assertEqual(status, 200, '%s %s' % (who, page))
                m = PHP_ERROR.search(body)
                self.assertIsNone(m, '%s %s: %s' % (who, page, body[max(0, m.start() - 200):m.start() + 300] if m else ''))


if __name__ == '__main__':
    unittest.main()
