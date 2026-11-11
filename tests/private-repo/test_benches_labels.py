"""Test benches 2A (Benchy 0.8): the result viewer labels bench runs by the component of the session they were filed under, with the
collision chain " · <system>", " (S<id>)", " · <trigger>", " #<UploadID>", bench tokens, the header line, "Other components on this
bench", non-bench results unchanged, cross-account labels, and the export dump keeping two sessions apart.

Run through tests/private-repo/run.sh (part of http/all).
"""
import base64
import json
import unittest
import uuid

from bench_helpers import (act, bench_of, composite, make_bench, new_system, open_session, result_row, sessions, swap, system_row, upload)
from test_private_repo_http import AID, sql, srv_sh
from test_private_repo_tokens import sql_exec
from test_private_repo_web import WebBase
from test_benches_issue import srv_php


def pprid(uid):
    return result_row(uid)['PPRID']


def view(browser, *uids):
    return browser.get('/?result/' + ','.join(pprid(u) for u in uids))[2]


class TestLabels(WebBase):
    def test_multi_compare_single_and_ticket_show_component_labels(self):
        msi, sid, bid, s1 = make_bench(self.admin, cls='other', label='qc PartA %s' % uuid.uuid4().hex[:4])
        label1 = open_session(bid)['Label']
        a = upload(msi, sched=4242, ts='2026-10-20')
        swap(self.admin, bid, cls='other', label='qc PartB %s' % uuid.uuid4().hex[:4])
        label2 = open_session(bid)['Label']
        b = upload(msi, sched=4242, ts='2026-10-20')
        page = view(self.admin, a, b)
        self.assertIn(label1, page)
        self.assertIn(label2, page)
        self.assertIn('component under test: other', page)
        self.assertIn('?benches/%d' % bid, page)
        # single result
        self.assertIn(label2, view(self.admin, b))
        # ticket result (ORIGINAL_DATA naming)
        t = upload(msi, bid=777, identifier='qc ticket run')
        page = view(self.admin, t)
        self.assertIn(label2, page)
        self.assertEqual(result_row(t)['BenchmarkTicketID'], 777)

    def test_collision_chain(self):
        msi, sid, bid, s1 = make_bench(self.admin, cls='other', label='qc Same %s' % uuid.uuid4().hex[:4])
        label = open_session(bid)['Label']
        a = upload(msi, sched=4243, ts='2026-10-21')
        b = upload(msi, sched=4243, ts='2026-10-22')
        page = view(self.admin, a, b)
        self.assertIn('%s · 2026-10-21' % label, page, 'same session twice: the trigger is added')
        self.assertIn('%s · 2026-10-22' % label, page)
        # the same label on two sessions (the first component retired, a new one created with its name): "(S<id>)"
        cid = open_session(bid)['ComponentID']
        swap(self.admin, bid, cls='other')
        act(self.admin, '/?benches/components', 'component_retire', component_id=str(cid))
        swap(self.admin, bid, cls='other', label=label)
        s3 = open_session(bid)['SessionID']
        c = upload(msi, sched=4243, ts='2026-10-21')
        page = view(self.admin, a, c)
        self.assertIn('%s (S%d)' % (label, s1), page)
        self.assertIn('%s (S%d)' % (label, s3), page)

    def test_tokens_and_system_variable_named_component(self):
        msi, sid, bid, s1 = make_bench(self.admin, cls='other', label='qc Tok %s' % uuid.uuid4().hex[:4])
        label = open_session(bid)['Label']
        sql_exec("UPDATE phoromatic_systems SET SystemVariables='COMPONENT=evilvar;SERIAL=xyz;' WHERE SystemID='%s'" % sid)
        u = upload(msi, identifier='.COMPONENT @ .SESSION on .BENCH .SERIAL')
        bench_title = sql("SELECT Title FROM phoromatic_benches WHERE BenchID=%d" % bid)[0]['Title']
        page = view(self.admin, u)
        self.assertIn('%s @ S%d on %s xyz' % (label, s1, bench_title), page)
        self.assertNotIn('evilvar', page)

    def test_non_bench_results_keep_todays_naming(self):
        msi, sid = new_system(title='qcplain%s' % uuid.uuid4().hex[:4])
        msi2, sid2 = new_system(title='qcplainb%s' % uuid.uuid4().hex[:4])
        a = upload(msi, sched=4244, ts='2026-10-23')
        b = upload(msi2, sched=4244, ts='2026-10-23')
        page = view(self.admin, a, b)
        title_a = system_row(sid)['Title']
        self.assertIn('%s: 2026-10-23' % title_a, page, 'TRIGGER_AND_SYSTEM naming changed')
        self.assertNotIn('component under test', page)
        # mixed with a bench run: the ordinary row keeps its name
        msi3, sid3, bid3, s3 = make_bench(self.admin, cls='other', label='qc Mix %s' % uuid.uuid4().hex[:4])
        c = upload(msi3, sched=4244, ts='2026-10-23')
        page = view(self.admin, a, c)
        self.assertIn('%s: 2026-10-23' % title_a, page)
        self.assertIn(open_session(bid3)['Label'], page)

    def test_header_marks_deviation_and_other_components_box(self):
        msi, sid, bid, s1 = make_bench(self.admin, cls='other', label='qc Dev %s' % uuid.uuid4().hex[:4])
        a = upload(msi, sched=4245, ts='2026-10-24')
        sql_exec("UPDATE phoromatic_results SET BenchDeviation='[{\"key\":\"gpu\",\"baseline\":\"x\",\"detected\":\"y\"}]' WHERE UploadID=%d" % a)
        first = open_session(bid)['Label']
        swap(self.admin, bid, cls='other', label='qc Dev2 %s' % uuid.uuid4().hex[:4])
        b = upload(msi, sched=4245, ts='2026-10-24')
        page = view(self.admin, b)
        self.assertIn('Other components on this bench', page)
        self.assertIn(first, page)
        self.assertIn('value="%s" name="compare_results"' % pprid(a), page)
        page = view(self.admin, a)
        self.assertIn('constant part differed: GPU', page)
        compare = self.admin.get('/?benches/compare&bench=%d&schedule=4245' % bid)[2]
        self.assertIn('GPU differed', compare)
        self.assertIn(first, compare)

    def test_labels_of_another_accounts_result_are_not_shown(self):
        other = 'QCBENCHOTHER%s' % uuid.uuid4().hex[:4].upper()
        uid = 960000000 + int(uuid.uuid4().int % 9999999)
        pp = 'qcx' + uuid.uuid4().hex[:10]
        bid, cid, sess = [990000 + int(uuid.uuid4().int % 9999) for _ in range(3)]
        for stmt in ("INSERT INTO phoromatic_account_settings (AccountID, LetOtherGroupsViewResults) VALUES ('%s', 1)" % other,
                     "INSERT INTO phoromatic_benches (BenchID, AccountID, Title, CreatedOn, CreatedBy) VALUES (%d, '%s', 'Other Bench', '2026-01-01', 'o')" % (bid, other),
                     "INSERT INTO phoromatic_components (ComponentID, AccountID, Class, Label, CreatedOn, CreatedBy) VALUES (%d, '%s', 'gpu', 'Other Account Part', '2026-01-01', 'o')" % (cid, other),
                     "INSERT INTO phoromatic_bench_sessions (SessionID, AccountID, BenchID, ComponentID, Config, StartedOn, StartedBy) VALUES (%d, '%s', %d, %d, 'QC SECRET CONFIG', '2026-01-01', 'o')" % (sess, other, bid, cid),
                     "INSERT INTO phoromatic_results (AccountID, UploadID, ScheduleID, Trigger, UploadTime, Title, SystemID, PPRID, BenchSessionID, BenchSessionSource, InProgress) VALUES ('%s', %d, 0, 'o', '2026-01-01 00:00:00', 'other result', 'OTHSY', '%s', %d, 'task', 0)" % (other, uid, pp, sess)):
            self.assertEqual(sql_exec(stmt), 'ok', stmt)
        rdir = '/var/lib/benchy/phoromatic/accounts/%s/results/%d/' % (other, uid)
        srv_sh('mkdir -p %s && echo %s | base64 -d > %scomposite.xml' % (rdir, base64.b64encode(composite('2026-01-01').encode()).decode(), rdir))
        page = self.admin.get('/?result/' + pp)[2]
        # (0.9.12: results are shared inside an account only, so another account's result is not shown at all, whatever its sharing setting says)
        self.assertNotIn('Other Account Part', page, 'another account\'s part label')
        self.assertNotIn('?benches/%d' % bid, page, 'no link into another account\'s bench')
        self.assertNotIn('QC SECRET CONFIG', page, 'another account\'s constant-parts text')

    def test_export_dump_keeps_two_sessions_apart(self):
        msi, sid, bid, s1 = make_bench(self.admin, cls='other', label='qc Exp %s' % uuid.uuid4().hex[:4])
        title = 'qc export %s' % uuid.uuid4().hex[:4]
        sched = 4300 + int(uuid.uuid4().int % 500)
        sql_exec("INSERT INTO phoromatic_schedules (AccountID, ScheduleID, Title, State) VALUES ('%s', %d, '%s', 1)" % (AID, sched, title))
        sql_exec("INSERT INTO phoromatic_schedules_tests (AccountID, ScheduleID, TestProfile, TestArguments) VALUES ('%s', %d, 'pts/qc-1.0.0', '')" % (AID, sched))
        for _ in range(3):
            upload(msi, sched=sched, ts='2026-10-25')
        swap(self.admin, bid, cls='other')
        s2 = open_session(bid)['SessionID']
        for _ in range(2):
            upload(msi, sched=sched, ts='2026-10-25')
        srv_php('phoromatic_server::generate_result_export_dump("%s");' % AID)
        base = '/var/lib/benchy/phoromatic/result-export/%s/%s/2026-10-25/' % (AID, title.replace(' ', '-').lower())
        listing = srv_sh('ls %s' % base)
        name = system_row(sid)['Title']
        self.assertIn('%s - S%d' % (name, s1), listing)
        self.assertIn('%s - S%d' % (name, s2), listing)
        sql_exec("UPDATE phoromatic_schedules SET State=0 WHERE ScheduleID=%d" % sched)


if __name__ == '__main__':
    unittest.main()
