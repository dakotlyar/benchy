"""Test benches 2A (Benchy 0.8): promote, swap, end, demote, the component inventory, the refusals, permissions and fail-closed audit.

Run through tests/private-repo/run.sh (part of http/all).
"""
import json
import re
import threading
import time
import unittest
import uuid

from bench_helpers import (BOARD, CPU_A, CPU_B, GPU_A, act, bench_audit, bench_of, baseline, drift, drop_schedule, make_bench, new_system, open_session, pp,
                           promote, req, schedule, sessions, start, swap, system_row, today, token, upload)
from test_private_repo_http import AID, sql, srv_sh
from test_private_repo_tokens import sql_exec
from test_private_repo_web import BASE, Browser, WebBase
from test_systems_tombstone import delete_via_ui


def component(label):
    r = sql("SELECT * FROM phoromatic_components WHERE AccountID='%s' AND Label='%s'" % (AID, label))
    return r[0] if r else None


def components_count():
    return sql("SELECT COUNT(*) AS n FROM phoromatic_components")[0]['n']


class TestPromote(WebBase):
    def test_promote_creates_bench_link_session_baseline_and_audit(self):
        msi, sid = new_system()
        title = 'Bench-QC %s' % uuid.uuid4().hex[:4]
        body = promote(self.admin, sid, title=title, label=CPU_A + ' ' + uuid.uuid4().hex[:4])
        bid = bench_of(sid)
        self.assertIsNotNone(bid, body[:500])
        b = sql("SELECT * FROM phoromatic_benches WHERE BenchID=%d" % bid)[0]
        self.assertEqual((b['Title'], b['AccountID'], b['RetiredOn']), (title, AID, None))
        s = open_session(bid)
        self.assertEqual(s['Class'], 'cpu')
        self.assertTrue(s['Label'].startswith(CPU_A))
        self.assertEqual(s['Config'], 'qc constant parts')
        c = sql("SELECT * FROM phoromatic_components WHERE ComponentID=%d" % s['ComponentID'])[0]
        self.assertEqual(json.loads(c['DetectedJSON']), {'cpu': {'model': CPU_A}})
        base = baseline(s['SessionID'], sid)
        self.assertEqual(json.loads(base['BaselineJSON'])['cpu']['model'], CPU_A)
        self.assertEqual(base['StartJSON'], base['BaselineJSON'])
        self.assertIsNone(base['AwaitingFromJSON'])
        actions = [a['Action'] for a in sql("SELECT Action FROM phoromatic_admin_audit WHERE (SubjectType='bench' AND SubjectID='%d') OR (SubjectType='component' AND SubjectID='%d') ORDER BY AuditID" % (bid, s['ComponentID']))]
        self.assertEqual(actions, ['bench_create', 'component_create', 'session_start'])
        self.assertEqual(json.loads(bench_audit(bid, 'bench_create')[0]['Detail'])['system'], sid)
        # the system page shows the card, the bench page the component
        self.assertIn('?benches/%d' % bid, self.admin.get('/?systems/%s' % sid)[2])
        page = self.admin.get('/?benches/%d' % bid)[2]
        self.assertIn(title, page)
        self.assertIn('S%d' % s['SessionID'], page)

    def test_promote_from_pending_and_refusals(self):
        msi, sid = new_system(state=0)
        promote(self.admin, sid)
        self.assertIsNotNone(bench_of(sid), 'a pending system can be promoted')
        # already a bench
        before = sql("SELECT COUNT(*) AS n FROM phoromatic_benches")[0]['n']
        self.assertIn('already belongs to a bench', promote(self.admin, sid))
        # disabled
        msi2, sid2 = new_system(state=-1)
        self.assertIn('Only an enabled or pending', promote(self.admin, sid2))
        # tombstone
        msi3, sid3 = new_system(state=-1)
        delete_via_ui(self.admin, sid3)
        self.assertIsNotNone(system_row(sid3)['DeletedOn'])
        self.assertIn('deleted system cannot become a bench', promote(self.admin, sid3))
        # pending adoption decision
        msi4, sid4 = new_system(state=0)
        sql_exec("UPDATE phoromatic_systems SET AdoptionCandidate='%s' WHERE SystemID='%s'" % (sid, sid4))
        self.assertIn('adopt / register-as-new', promote(self.admin, sid4))
        self.assertEqual(sql("SELECT COUNT(*) AS n FROM phoromatic_benches")[0]['n'], before)
        for s in (sid2, sid3, sid4):
            self.assertIsNone(bench_of(s))

    def test_bad_labels_and_titles_change_nothing(self):
        msi, sid = new_system()
        n = components_count()
        for bad in ('', 'x' * 65, '<script>', 'a"b', "a'b", 'a&b', 'a\\b', 'tab\x01'):
            self.assertIsNone(bench_of(sid))
            promote(self.admin, sid, label=bad)
        self.assertIn('bench name must be', promote(self.admin, sid, title='<b>'))
        self.assertIsNone(bench_of(sid))
        self.assertEqual(components_count(), n)

    def test_unspecified_and_inventory_choices(self):
        msi, sid = new_system()
        promote(self.admin, sid, source='unspecified')
        bid = bench_of(sid)
        s = open_session(bid)
        self.assertEqual((s['Class'], s['Label']), ('other', 'Unspecified baseline (S%d)' % s['SessionID']))
        # a component from the inventory
        label = 'qc stock %s' % uuid.uuid4().hex[:6]
        act(self.admin, '/?benches/components', 'component_create', label=label, **{'class': 'gpu'})
        cid = component(label)['ComponentID']
        msi2, sid2 = new_system()
        promote(self.admin, sid2, source='inventory', component_id=str(cid))
        s2 = open_session(bench_of(sid2))
        self.assertEqual((s2['ComponentID'], s2['Class']), (cid, 'gpu'))
        # and it cannot be picked for a second bench while in use
        msi3, sid3 = new_system()
        self.assertIn('already under test', promote(self.admin, sid3, source='inventory', component_id=str(cid)))
        self.assertIsNone(bench_of(sid3))


class TestSwapAndEnd(WebBase):
    def test_swap_closes_old_session_once_and_opens_new(self):
        msi, sid, bid, s1 = make_bench(self.admin)
        body = swap(self.admin, bid, label='qc swap %s' % uuid.uuid4().hex[:5], cls='cpu')
        rows = sessions(bid)
        self.assertEqual(len(rows), 2, body[:300])
        self.assertEqual((rows[0]['EndReason'], rows[1]['EndedOn']), ('swap', None))
        self.assertIsNotNone(rows[0]['EndedOn'])
        s2 = rows[1]['SessionID']
        # cpu class with a previous baseline: awaiting swap
        b = baseline(s2, sid)
        self.assertIsNone(b['BaselineJSON'])
        self.assertEqual(json.loads(b['AwaitingFromJSON'])['cpu']['model'], CPU_A)
        self.assertEqual(drift(sid)['kind'], 'awaiting')
        self.assertEqual([a['Action'] for a in bench_audit(bid)][-2:], ['session_end', 'session_start'])
        # an undetectable class takes effect at once
        swap(self.admin, bid, cls='psu')
        s3 = open_session(bid)['SessionID']
        self.assertIsNotNone(baseline(s3, sid)['BaselineJSON'])
        self.assertIsNone(drift(sid), 'a new session clears the previous hold')

    def test_swap_to_the_same_component_is_refused(self):
        msi, sid, bid, s1 = make_bench(self.admin)
        cid = open_session(bid)['ComponentID']
        self.assertIn('already the component under test', swap(self.admin, bid, source='inventory', component_id=str(cid)))
        self.assertEqual(len(sessions(bid)), 1)

    def test_second_open_session_is_impossible(self):
        msi, sid, bid, s1 = make_bench(self.admin)
        cid = open_session(bid)['ComponentID']
        self.assertEqual(sql_exec("INSERT INTO phoromatic_bench_sessions (AccountID, BenchID, ComponentID, StartedOn, StartedBy) VALUES ('%s', %d, %d, '2026-01-01', 'qc')" % (AID, bid, cid)), 'refused')
        # concurrent swaps: every one that succeeds ends exactly the previous session, and one session stays open
        tok = token(self.admin)
        out = []
        threads = [threading.Thread(target=lambda: out.append(swap(self.admin, bid, cls='other', tok=tok))) for _ in range(4)]
        [t.start() for t in threads]
        [t.join() for t in threads]
        rows = sessions(bid)
        self.assertEqual(len([r for r in rows if r['EndedOn'] is None]), 1)
        self.assertEqual(len(rows), 1 + len([o for o in out if 'started with' in o]))

    def test_one_component_in_one_open_session(self):
        msi, sid, bid, s1 = make_bench(self.admin)
        msi2, sid2, bid2, s2 = make_bench(self.admin)
        cid = open_session(bid)['ComponentID']
        self.assertEqual(sql_exec("UPDATE phoromatic_bench_sessions SET ComponentID=%d WHERE SessionID=%d" % (cid, s2)), 'refused')
        self.assertIn('already under test', swap(self.admin, bid2, source='inventory', component_id=str(cid)))

    def test_outstanding_task_refuses_and_force_needs_two_hours_of_silence(self):
        msi, sid, bid, s1 = make_bench(self.admin, cls='other')
        sql_exec("UPDATE phoromatic_systems SET BenchTaskSession=%d, BenchTaskIssuedOn=datetime('now'), LastCommunication=datetime('now', 'localtime') WHERE SystemID='%s'" % (s1, sid))
        self.assertIn('has a task outstanding', swap(self.admin, bid))
        self.assertIn('has a task outstanding', act(self.admin, '/?benches/%d' % bid, 'end'))
        self.assertIn('has a task outstanding', act(self.admin, '/?benches/%d' % bid, 'demote', system_id=sid))
        self.assertIn('is running it', act(self.admin, '/?benches/%d' % bid, 'end', force='1'))
        page = self.admin.get('/?benches/%d/swap' % bid)[2]
        self.assertIn('Not now', page)
        self.assertIn('snapshot: session S%d' % s1, page)
        self.assertIn('disabled', page)
        self.assertEqual(len(sessions(bid)), 1)
        # the bench has been off for 3 hours: only a forced end is allowed, audited as such; the snapshot stays for a late upload
        sql_exec("UPDATE phoromatic_systems SET LastCommunication=datetime('now', 'localtime', '-3 hours') WHERE SystemID='%s'" % sid)
        self.assertIn('tick Force', act(self.admin, '/?benches/%d' % bid, 'end'))
        self.assertIsNotNone(open_session(bid))
        self.assertIn('ended', act(self.admin, '/?benches/%d' % bid, 'end', force='1'))
        self.assertEqual(sessions(bid)[0]['EndReason'], 'force_end')
        self.assertTrue(json.loads(bench_audit(bid, 'session_end')[-1]['Detail'])['force'])
        self.assertEqual(system_row(sid)['BenchTaskSession'], s1)

    def test_end_leaves_the_bench_without_a_session(self):
        msi, sid, bid, s1 = make_bench(self.admin, cls='other')
        act(self.admin, '/?benches/%d' % bid, 'end')
        self.assertIsNone(open_session(bid))
        self.assertEqual(sessions(bid)[0]['EndReason'], 'end')
        self.assertIn('Needs a component', self.admin.get('/?benches/%d' % bid)[2])
        # declaring a component again starts a new session
        swap(self.admin, bid, cls='other')
        self.assertIsNotNone(open_session(bid))


class TestSnapshotLifetime(WebBase):
    """M2: a client asks for work only between runs, except after a restart mid-run; the snapshot therefore lasts until the final upload"""

    def test_restart_mid_run_keeps_the_snapshot_until_the_final_upload(self):
        msi, sid, bid, s1 = make_bench(self.admin, cls='other')
        sched = schedule(sid)
        try:
            self.assertEqual(start(msi).get('task'), 'benchmark')
            upload(msi, sched=sched, ts=today(), progressive=1)
            sql_exec("UPDATE phoromatic_schedules SET State=0 WHERE ScheduleID=%d" % sched)  # the restarted client gets no task
            self.assertEqual(start(msi).get('task'), 'idle')
            self.assertEqual(system_row(sid)['BenchTaskSession'], s1, 'a start mid-run released the snapshot')
            self.assertIn('is running it', swap(self.admin, bid))
            # while the bench reports a run: refused, Force too
            req(msi, 'update_system_status', a='Running Benchmarks For: qc')
            self.assertIn('is running it', swap(self.admin, bid, force='1'))
            self.assertIn('disabled', self.admin.get('/?benches/%d/swap' % bid)[2])
            # the client reports idling after the issue: the run was abandoned, the change goes ahead without Force
            req(msi, 'update_system_status', a='Idling, Waiting For Task')
            self.assertIn('set it aside', self.admin.get('/?benches/%d/swap' % bid)[2])
            self.assertIn('started with', swap(self.admin, bid))
            detail = json.loads(bench_audit(bid, 'session_end')[-1]['Detail'])
            self.assertEqual((detail['force'], detail['outstanding_task_set_aside']), (False, [sid]))
        finally:
            drop_schedule(sched)

    def test_an_old_idling_status_does_not_unlock_right_after_an_issue(self):
        # F1: issuing a task overwrites the status, so the Idling the client posted BEFORE the issue does not count
        msi, sid, bid, s1 = make_bench(self.admin, cls='other')
        sched = schedule(sid)
        try:
            req(msi, 'update_system_status', a='Idling, Waiting For Task')
            self.assertEqual(start(msi).get('task'), 'benchmark')
            self.assertEqual(system_row(sid)['CurrentTask'], 'Task issued (bench S%d)' % s1)
            self.assertIn('is running it', swap(self.admin, bid))
            self.assertIn('is running it', swap(self.admin, bid, force='1'))
            req(msi, 'update_system_status', a='Idling, Waiting For Task')
            self.assertIn('started with', swap(self.admin, bid))
        finally:
            drop_schedule(sched)

    def test_final_upload_releases_the_snapshot(self):
        msi, sid, bid, s1 = make_bench(self.admin, cls='other')
        sched = schedule(sid)
        try:
            start(msi)
            upload(msi, sched=sched, ts=today(), progressive=1)
            self.assertEqual(system_row(sid)['BenchTaskSession'], s1, 'an in-progress upload released the snapshot')
            upload(msi, sched=sched, ts=today(), progressive=2)
            self.assertIsNone(system_row(sid)['BenchTaskSession'])
            self.assertIn('started with', swap(self.admin, bid), 'the swap unlocks right after the final upload')
        finally:
            drop_schedule(sched)

    def test_snapshot_is_released_only_once_the_result_file_is_written(self):
        # F2: a final upload whose composite.xml cannot be stored leaves the snapshot (and the swap lock) in place
        msi, sid, bid, s1 = make_bench(self.admin, cls='other')
        sched = schedule(sid)
        nxt = int(sql("SELECT IFNULL(MAX(UploadID), 0) + 1 AS n FROM phoromatic_results WHERE AccountID='%s'" % AID)[0]['n'])
        blocker = '/var/lib/benchy/phoromatic/accounts/%s/results/%d' % (AID, nxt)
        try:
            start(msi)
            srv_sh("mkdir -p $(dirname %s) && rm -rf %s && echo x > %s" % (blocker, blocker, blocker))
            self.assertEqual(upload(msi, sched=sched, ts=today()), nxt)
            self.assertEqual(system_row(sid)['BenchTaskSession'], s1, 'the snapshot was released although composite.xml was not written')
            self.assertIn('has a task outstanding', swap(self.admin, bid))
            srv_sh("rm -f %s" % blocker)
            upload(msi, sched=sched, ts=today())
            self.assertIsNone(system_row(sid)['BenchTaskSession'])
        finally:
            srv_sh("rm -f %s" % blocker)
            drop_schedule(sched)

    def test_no_bench_id_in_the_path(self):
        for action in ('swap', 'end', 'demote', 'ack'):
            body = act(self.admin, '/?benches', action, source='unspecified')
            self.assertIn('Bench not found', body)
            self.assertIsNone(PHP_ERROR.search(body))


class TestDemoteAndDelete(WebBase):
    def test_demote_ends_session_and_retires_bench(self):
        msi, sid, bid, s1 = make_bench(self.admin, cls='other')
        act(self.admin, '/?benches/%d' % bid, 'demote', system_id=sid)
        self.assertIsNone(bench_of(sid))
        self.assertEqual(sessions(bid)[0]['EndReason'], 'bench_empty')
        self.assertIsNotNone(sql("SELECT RetiredOn FROM phoromatic_benches WHERE BenchID=%d" % bid)[0]['RetiredOn'])
        self.assertEqual([a['Action'] for a in bench_audit(bid)][-3:], ['bench_demote', 'session_end', 'bench_retire'])
        # the system can become a bench again (a new one)
        promote(self.admin, sid, source='new', cls='other', label='qc again %s' % uuid.uuid4().hex[:5])
        self.assertNotEqual(bench_of(sid), bid)

    def test_deleting_the_only_system_retires_the_bench(self):
        msi, sid, bid, s1 = make_bench(self.admin, cls='other')
        sql_exec("UPDATE phoromatic_systems SET State=-1 WHERE SystemID='%s'" % sid)
        delete_via_ui(self.admin, sid)
        r = system_row(sid)
        self.assertIsNotNone(r['DeletedOn'])
        self.assertEqual(r['BenchID'], bid, 'the tombstone keeps its bench for history')
        self.assertEqual(sessions(bid)[0]['EndReason'], 'bench_empty')
        self.assertIsNotNone(sql("SELECT RetiredOn FROM phoromatic_benches WHERE BenchID=%d" % bid)[0]['RetiredOn'])
        self.assertEqual(json.loads(sql("SELECT Detail FROM phoromatic_admin_audit WHERE Action='system_delete' AND SubjectID='%s'" % sid)[0]['Detail'])['bench'], 'B%d' % bid)
        # restoring does not rejoin the retired bench
        self.admin.post('/?systems/%s' % sid, {'restore_system': '1', 'token_submit': token(self.admin)})
        r = system_row(sid)
        self.assertIsNone(r['DeletedOn'])
        self.assertIsNone(r['BenchID'])
        self.assertIsNone(open_session(bid))

    def test_second_live_system_on_a_bench_is_allowed_from_09(self):
        # 0.8's one-system index is gone (migration case 53); adding a member goes through add_member (test_benches_members.py)
        msi, sid, bid, s1 = make_bench(self.admin, cls='other')
        msi2, sid2 = new_system()
        self.assertEqual(sql_exec("UPDATE phoromatic_systems SET BenchID=%d WHERE SystemID='%s'" % (bid, sid2)), 'ok')
        sql_exec("UPDATE phoromatic_systems SET BenchID=NULL WHERE SystemID='%s'" % sid2)


class TestComponents(WebBase):
    def test_component_rules(self):
        label = 'qc comp %s' % uuid.uuid4().hex[:6]
        act(self.admin, '/?benches/components', 'component_create', label=label, serial='SN123', photo_url='https://photos.example/x', **{'class': 'cpu'})
        c = component(label)
        self.assertEqual((c['Class'], c['Serial'], c['PhotoURL']), ('cpu', 'SN123', 'https://photos.example/x'))
        # unique live label, case-insensitive
        n = components_count()
        self.assertIn('already exists', act(self.admin, '/?benches/components', 'component_create', label=label.upper(), **{'class': 'cpu'}))
        self.assertIn('photo link', act(self.admin, '/?benches/components', 'component_create', label=label + 'x', photo_url='javascript:alert(1)', **{'class': 'cpu'}))
        self.assertEqual(components_count(), n)
        # class can change while unused
        act(self.admin, '/?benches/components', 'component_edit', component_id=str(c['ComponentID']), label=label, **{'class': 'gpu'})
        self.assertEqual(component(label)['Class'], 'gpu')
        # used by a session: class locked, retire refused while in use
        msi, sid = new_system()
        promote(self.admin, sid, source='inventory', component_id=str(c['ComponentID']))
        bid = bench_of(sid)
        self.assertIn('cannot change', act(self.admin, '/?benches/components', 'component_edit', component_id=str(c['ComponentID']), label=label, **{'class': 'cpu'}))
        self.assertIn('under test', act(self.admin, '/?benches/components', 'component_retire', component_id=str(c['ComponentID'])))
        self.assertIsNone(component(label)['RetiredOn'])
        # rename with results: confirm first, then audited
        s = open_session(bid)
        sql_exec("INSERT INTO phoromatic_results (AccountID, UploadID, ScheduleID, Trigger, UploadTime, Title, SystemID, PPRID, BenchSessionID, BenchSessionSource) VALUES ('%s', %d, 0, 'qc', '2026-01-01 00:00:00', 'r', '%s', 'qcp%s', %d, 'task')" % (AID, 990000000 + int(uuid.uuid4().int % 9999999), sid, uuid.uuid4().hex[:8], s['SessionID']))
        new_label = label + ' renamed'
        body = act(self.admin, '/?benches/components', 'component_edit', component_id=str(c['ComponentID']), label=new_label, **{'class': 'gpu'})
        self.assertIn('1 result will show the new name', body)
        self.assertIsNotNone(component(label), 'renamed without confirmation')
        act(self.admin, '/?benches/components', 'component_edit', component_id=str(c['ComponentID']), label=new_label, rename_confirmed='1', **{'class': 'gpu'})
        self.assertIsNotNone(component(new_label))
        edit = sql("SELECT * FROM phoromatic_admin_audit WHERE Action='component_edit' AND SubjectID='%d' ORDER BY AuditID DESC LIMIT 1" % c['ComponentID'])[0]
        self.assertEqual(json.loads(edit['Detail'])['before']['Label'], label)
        # retire after the swap-out; then the label is free again; a retired one cannot be picked; there is no delete
        swap(self.admin, bid, cls='other')
        act(self.admin, '/?benches/components', 'component_retire', component_id=str(c['ComponentID']))
        self.assertIsNotNone(component(new_label)['RetiredOn'])
        self.assertIn('not in the inventory', swap(self.admin, bid, source='inventory', component_id=str(c['ComponentID'])))
        self.assertIn('Unknown action', act(self.admin, '/?benches/components', 'component_delete', component_id=str(c['ComponentID'])))
        act(self.admin, '/?benches/components', 'component_create', label=new_label, **{'class': 'gpu'})
        self.assertEqual(len(sql("SELECT 1 FROM phoromatic_components WHERE Label='%s'" % new_label)), 2)

    def test_inventory_page_escapes_and_lists(self):
        label = 'qc esc %s' % uuid.uuid4().hex[:6]
        act(self.admin, '/?benches/components', 'component_create', label=label, notes='<script>alert(1)</script>', serial='<b>x</b>', **{'class': 'other'})
        cid = component(label)['ComponentID']
        page = self.admin.get('/?benches/components/%d' % cid)[2]
        self.assertNotIn('<script>alert(1)', page)
        self.assertIn('&lt;script&gt;', page)
        self.assertNotIn('<b>x</b>', self.admin.get('/?benches/components')[2])


class TestPermissions(WebBase):
    def test_only_levels_1_and_2_change_anything(self):
        msi, sid, bid, s1 = make_bench(self.admin, cls='other')
        msi2, sid2 = new_system()
        n_sessions = len(sessions(bid))
        n_comp = components_count()
        admin_tok = token(self.admin)
        for level in (3, 10):
            b = self.as_user(level)
            tok = token(b) or admin_tok
            self.assertIn('Only account administrators', swap(b, bid, tok=tok))
            self.assertIn('Only account administrators', promote(b, sid2))
            self.assertIn('Only account administrators', act(b, '/?benches/components', 'component_create', tok=tok, label='qc lvl %d' % level, **{'class': 'cpu'}))
            self.assertNotIn('Swap component', b.get('/?benches/%d' % bid)[2])
        # a disabled user (level 0) cannot log in at all; anonymous gets the login page and nothing changes
        anon = Browser()
        anon.get('/')
        swap(anon, bid, tok=admin_tok)
        # level 2 may act
        self.assertIn('started with', swap(self.admin2(), bid))
        n_sessions += 1
        self.assertEqual(len(sessions(bid)), n_sessions)
        self.assertIsNone(bench_of(sid2))
        self.assertEqual(components_count(), n_comp + 1)

    def test_token_and_origin_checks(self):
        msi, sid, bid, s1 = make_bench(self.admin, cls='other')
        n = len(sessions(bid))
        other = self.admin2()
        foreign = token(other)
        self.assertIn('form expired', swap(self.admin, bid, tok=None))
        self.assertIn('form expired', swap(self.admin, bid, tok=foreign))
        self.assertIn('form expired', swap(self.admin, bid, headers={'Origin': 'http://evil.example'}))
        self.assertEqual(len(sessions(bid)), n)
        # GET never changes anything
        self.admin.get('/?benches/%d&bench_action=swap&source=unspecified' % bid)
        self.admin.get('/?benches/%d/end' % bid)
        self.assertEqual(len(sessions(bid)), n)
        self.assertIsNotNone(open_session(bid))

    def test_other_account_cannot_see_or_touch_a_bench(self):
        msi, sid, bid, s1 = make_bench(self.admin, cls='other')
        user = 'qcoth%s' % uuid.uuid4().hex[:6]
        b = Browser()
        b.get('/')
        b.post('/?register', {'register_username': user, 'register_password': 'qcpass123', 'register_password_confirm': 'qcpass123', 'register_email': user + '@example.com'})
        b = Browser().login(user, 'qcpass123')
        page = b.get('/?benches/%d' % bid)[2]
        self.assertIn('Bench not found', page)
        self.assertNotIn(open_session(bid)['Label'], page)
        self.assertIn('Bench not found', swap(b, bid))
        self.assertIn('Bench not found', act(b, '/?benches/%d' % bid, 'end'))
        self.assertEqual(len(sessions(bid)), 1)
        self.assertIn('System not found', promote(b, sid))


class TestFailClosed(WebBase):
    def test_audit_failure_changes_nothing(self):
        msi, sid = new_system()
        msi2, sid2, bid, s1 = make_bench(self.admin, cls='other')
        before = {t: sql("SELECT COUNT(*) AS n FROM %s" % t)[0]['n'] for t in ('phoromatic_benches', 'phoromatic_bench_sessions', 'phoromatic_components', 'phoromatic_bench_baselines')}
        self.assertEqual(sql_exec("CREATE TRIGGER qc_block_bench_audit BEFORE INSERT ON phoromatic_admin_audit BEGIN SELECT RAISE(ABORT, 'qc'); END"), 'ok')
        try:
            self.assertIn('audit record could not be written', promote(self.admin, sid))
            self.assertIn('audit record could not be written', swap(self.admin, bid))
            self.assertIn('audit record could not be written', act(self.admin, '/?benches/%d' % bid, 'end'))
            self.assertIn('audit record could not be written', act(self.admin, '/?benches/%d' % bid, 'demote', system_id=sid2))
            self.assertIn('audit record could not be written', act(self.admin, '/?benches/components', 'component_create', label='qc fc %s' % uuid.uuid4().hex[:5], **{'class': 'cpu'}))
        finally:
            sql_exec('DROP TRIGGER qc_block_bench_audit')
        after = {t: sql("SELECT COUNT(*) AS n FROM %s" % t)[0]['n'] for t in before}
        self.assertEqual(before, after)
        self.assertIsNone(bench_of(sid))
        self.assertEqual(bench_of(sid2), bid)
        self.assertEqual(open_session(bid)['SessionID'], s1)


# Any fatal error, warning or notice; a deprecation only from the new bench code (the older pages print some for NULL columns, e.g.
# LocalIP, a known leftover that production does not display)
PHP_ERROR = re.compile(r'(<b>)?(Fatal error|Warning|Notice)(</b>)?: |Uncaught|Stack trace|(<b>)?Deprecated(</b>)?: [^\n]*(phoromatic_bench|phoromatic_benches|phoromatic_result_access|pts_hardware_summary)\.php')


class TestPagesRender(WebBase):
    def test_every_bench_page_renders_without_php_errors(self):
        msi, sid, bid, s1 = make_bench(self.admin)
        start(msi, cpu=CPU_B)  # a class hold, so the drift banner, the ack page and the attention box all render
        uid = upload(msi)
        msi2, sid2 = new_system()
        cid = open_session(bid)['ComponentID']
        pprid = sql("SELECT PPRID FROM phoromatic_results WHERE UploadID=%d" % int(uid))[0]['PPRID']
        pages = ['/?benches', '/?benches/%d' % bid, '/?benches/%d/swap' % bid, '/?benches/%d/swap&prefill=%s' % (bid, sid), '/?benches/%d/end' % bid,
                 '/?benches/%d/demote' % bid, '/?benches/%d/ack/%s' % (bid, sid), '/?benches/%d/session/%d' % (bid, s1), '/?benches/promote/%s' % sid2,
                 '/?benches/components', '/?benches/components/%d' % cid, '/?benches/compare', '/?benches/compare&bench=%d&class=cpu&schedule=0' % bid,
                 '/?benches/999999', '/?benches/promote/NOPE', '/?systems', '/?systems/%s' % sid, '/?systems/%s' % sid2, '/?result/%s' % pprid, '/?main']
        for who, browser in (('admin', self.admin), ('viewer', self.as_user(10)), ('level 3', self.as_user(3))):
            for page in pages:
                status, _, body = browser.get(page)
                self.assertEqual(status, 200, '%s %s' % (who, page))
                m = PHP_ERROR.search(body)
                self.assertIsNone(m, '%s %s: %s' % (who, page, body[max(0, m.start() - 200):m.start() + 300] if m else ''))
        self.assertIn('Benches Needing Attention', self.admin.get('/?systems')[2])
        self.assertIn('?benches"', self.admin.get('/?main')[2], 'menu entry')


class TestMigration(unittest.TestCase):
    def test_schema_version_and_objects(self):
        self.assertEqual(sql('PRAGMA user_version')[0]['user_version'], 57)
        names = {r['name'] for r in sql("SELECT name FROM sqlite_master")}
        self.assertNotIn('phoromatic_systems_one_per_bench', names, 'dropped by case 53 (0.9)')
        for n in ('phoromatic_benches', 'phoromatic_components', 'phoromatic_bench_sessions', 'phoromatic_bench_baselines',
                  'phoromatic_bench_sessions_open', 'phoromatic_bench_sessions_component_open', 'phoromatic_components_live_label', 'phoromatic_results_bench_session'):
            self.assertIn(n, names)
        cols = {r['name'] for r in sql('PRAGMA table_info(phoromatic_systems)')}
        self.assertTrue({'BenchID', 'BenchTaskSession', 'BenchTaskIssuedOn', 'BenchDrift'} <= cols)
        cols = {r['name'] for r in sql('PRAGMA table_info(phoromatic_results)')}
        self.assertTrue({'BenchSessionID', 'BenchSessionSource', 'BenchDeviation'} <= cols)

    def test_migration_step_is_repeatable(self):
        # run case 53 again on the live database (user_version back to 53): nothing fails, nothing is duplicated. (Case 52 again would
        # recreate the 0.8 one-system index, which fails once a bench has two live systems; no upgrade path re-runs it.)
        self.assertEqual(sql_exec('PRAGMA user_version = 53'), 'ok')
        from test_private_repo_http import srv_sh
        srv_sh("cd /bench/pts-core/phoromatic/public_html && php -r 'define(\"PHOROMATIC_SERVER\", true); define(\"PTS_MODE\", \"WEB_CLIENT\"); define(\"PTS_AUTO_LOAD_OBJECTS\", true); include(\"../../pts-core.php\"); pts_core::init(); phoromatic_server::prepare_database();'")
        self.assertEqual(sql('PRAGMA user_version')[0]['user_version'], 57)


if __name__ == '__main__':
    unittest.main()
