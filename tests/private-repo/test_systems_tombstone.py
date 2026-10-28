"""Deleting a system writes a tombstone (Part 1a of the systems/benches design): the record stays so its SystemID is never reused,
results keep the old name, every reader ignores it, an audit row is written in the same transaction, and a returning machine
registers as a NEW pending system even when new systems are normally auto-approved.

Run through tests/private-repo/run.sh (part of http/all).
"""
import base64
import re
import unittest
import uuid

from test_private_repo_http import AID, call, new_machine, raw_post, sql, srv_sh
from test_private_repo_tokens import sql_exec
from test_private_repo_web import Browser, WebBase

COMPOSITE = """<?xml version="1.0"?>
<PhoronixTestSuite>
  <Generated><Title>qc</Title><LastModified>2026-01-01 00:00:00</LastModified><TestClient>qc</TestClient><Description>qc</Description></Generated>
  <System><Identifier>.SYSTEM</Identifier><Hardware>Processor: QC</Hardware><Software>OS: QC</Software><User>qc</User><TimeStamp>2026-01-01 00:00:00</TimeStamp><TestClientVersion>1</TestClientVersion><Notes></Notes></System>
  <Result><Identifier>pts/qc-1.0.0</Identifier><Title>QC Test</Title><AppVersion>1</AppVersion><Arguments></Arguments><Description></Description><Scale>Seconds</Scale><Proportion>LIB</Proportion><DisplayFormat>BAR_GRAPH</DisplayFormat>
    <Data><Entry><Identifier>.SYSTEM</Identifier><Value>1.5</Value><RawString>1.5</RawString></Entry></Data></Result>
</PhoronixTestSuite>
"""


def row(sid):
    r = sql("SELECT * FROM phoromatic_systems WHERE AccountID='%s' AND SystemID='%s'" % (AID, sid))
    return r[0] if r else None


def real_system(title, state=-1):
    """A system registered through a real check-in (so it has a machine id and a MAC), returned as (msi, SystemID)."""
    msi = new_machine(approved=False)
    sid = sql("SELECT SystemID FROM phoromatic_systems WHERE MachineSelfID='%s'" % msi)[0]['SystemID']
    assert sql_exec("UPDATE phoromatic_systems SET Title='%s', State=%d, NetworkMAC='02:%s' WHERE SystemID='%s'" % (title, state, uuid.uuid4().hex[:10], sid)) == 'ok'
    return msi, sid


def delete_via_ui(browser, sid):
    body = browser.get('/?systems/%s/delete' % sid)[2]
    tok = re.search(r'name="token_submit" value="([0-9a-f]+)"', body).group(1)
    return browser.post('/?systems/%s' % sid, {'really_delete_system': '1', 'token_submit': tok})[2]


def audit_rows(sid):
    return sql("SELECT * FROM phoromatic_admin_audit WHERE SubjectID='%s' ORDER BY AuditID" % sid)


class AutoApprove:
    def __enter__(self):
        sql_exec("UPDATE phoromatic_account_settings SET AutoApproveNewSystems=1 WHERE AccountID='%s'" % AID)

    def __exit__(self, *a):
        sql_exec("UPDATE phoromatic_account_settings SET AutoApproveNewSystems=0 WHERE AccountID='%s'" % AID)


class TestTombstone(WebBase):
    def test_throwaway_client_full_lifecycle(self):
        title = 'Throwaway%s' % uuid.uuid4().hex[:6]
        msi, sid = real_system(title, state=1)
        upload_id = int(uuid.uuid4().int % 10**9)
        pprid = 'qcpprid' + uuid.uuid4().hex[:8]
        sql_exec("UPDATE phoromatic_systems SET Groups='#qcgrp#', SystemProperties='{}' WHERE SystemID='%s'" % sid)
        other = 'QCOTH'
        sql_exec("INSERT INTO phoromatic_schedules (AccountID, ScheduleID, Title, State, RunTargetSystems, RunTargetGroups) VALUES ('%s', 770001, 'qc sched', 1, '%s,%s', '')" % (AID, other, sid))
        sql_exec("INSERT INTO phoromatic_benchmark_tickets (AccountID, TicketID, Title, State, RunTargetSystems, RunTargetGroups) VALUES ('%s', 770002, 'qc ticket', 1, '%s', '')" % (AID, sid))
        sql_exec("INSERT INTO phoromatic_results (AccountID, UploadID, ScheduleID, Trigger, UploadTime, Title, SystemID, PPRID, BenchmarkTicketID) VALUES ('%s', %d, 0, 'qc', '2026-01-01 00:00:00', 'QC result', '%s', '%s', 0)" % (AID, upload_id, sid, pprid))
        sql_exec("INSERT INTO phoromatic_system_client_errors (AccountID, SystemID, UploadTime, ErrorMessage, TestIdentifier) VALUES ('%s', '%s', '2026-01-01 00:00:00', 'boom', 'qc')" % (AID, sid))
        rdir = '/var/lib/benchy/phoromatic/accounts/%s/results/%d/' % (AID, upload_id)
        sdir = '/var/lib/benchy/phoromatic/accounts/%s/systems/%s/' % (AID, sid)
        srv_sh('mkdir -p %s %s && echo %s | base64 -d > %scomposite.xml && echo {} > %ssensors.json' % (rdir, sdir, base64.b64encode(COMPOSITE.encode()).decode(), rdir, sdir))

        # An enabled system cannot be deleted: confirm page says so, and a forged POST changes nothing
        self.assertIn('disable it first', self.admin.get('/?systems/%s/delete' % sid)[2].lower())
        self.assertIn('Only a disabled system', delete_via_ui(self.admin, sid))
        self.assertIsNone(row(sid)['DeletedOn'])
        # Disable through the edit form (needs the CSRF token now), then delete
        self.admin.post('/?systems/%s' % sid, {'system_title': title, 'system_description': 'd', 'system_state': '-1', 'block_power_offs': '0'})
        self.assertEqual(int(row(sid)['State']), 1, 'edit POST without a token was accepted')
        self.admin.post('/?systems/%s' % sid, {'system_title': title, 'system_description': 'd', 'system_state': '-1', 'block_power_offs': '0',
                                               'token_submit': self.admin.token('/?systems/%s/edit' % sid)})
        self.assertEqual(int(row(sid)['State']), -1)
        confirm = self.admin.get('/?systems/%s/delete' % sid)[2]
        self.assertIn('1 test result will be kept', confirm)
        self.assertIn('1 schedule and 1 benchmark ticket', confirm)
        self.assertIn('System deleted', delete_via_ui(self.admin, sid))

        # Tombstone, not a hard delete
        t = row(sid)
        self.assertIsNotNone(t, 'the row must stay so the SystemID is never reused')
        self.assertEqual((int(t['State']), t['Title'], t['MachineSelfID'], t['NetworkMAC'], t['Groups']), (-1, title, None, None, None))
        self.assertEqual(t['DeletedMachineSelfID'], msi)
        self.assertTrue(t['DeletedOn'] and t['DeletedBy'] and t['DeletedNetworkMAC'])
        self.assertIsNone(t['LastIP'])
        # Impact applied
        self.assertEqual(sql("SELECT RunTargetSystems FROM phoromatic_schedules WHERE ScheduleID=770001")[0]['RunTargetSystems'], other)
        self.assertEqual(sql("SELECT RunTargetSystems FROM phoromatic_benchmark_tickets WHERE TicketID=770002")[0]['RunTargetSystems'], '')
        self.assertFalse(sql("SELECT 1 AS x FROM phoromatic_system_client_errors WHERE SystemID='%s'" % sid))
        self.assertEqual(srv_sh('ls %s 2>/dev/null' % sdir).strip(), '', 'sensor directory not purged')
        self.assertTrue(sql("SELECT 1 AS x FROM phoromatic_results WHERE SystemID='%s'" % sid), 'results must be kept')
        # Audit row written, with the impact
        a = audit_rows(sid)
        self.assertEqual([x['Action'] for x in a], ['system_delete'])
        self.assertEqual((a[0]['ActorType'], a[0]['SubjectType'], a[0]['SubjectLabel']), ('user', 'system', title))
        self.assertIn('770001', a[0]['Detail'])
        self.assertIn('"results_kept":1', a[0]['Detail'])

        # Results still open, with the old name
        body = self.admin.get('/?result/%s' % pprid)[2]
        self.assertIn(title, body, 'result of a deleted system lost its name')
        # The tombstone page itself, and a second delete
        self.assertIn('(deleted)', self.admin.get('/?systems/%s' % sid)[2])
        self.assertIn('System not found or already deleted', delete_via_ui(self.admin, sid))
        self.assertEqual(len(audit_rows(sid)), 1)

        # Every reader ignores it
        listing = self.admin.get('/?systems')[2]
        head, _, deleted_section = listing.partition('Deleted systems (')
        self.assertNotIn('?systems/%s"' % sid, head, 'a deleted system is listed as a live one')
        self.assertIn('?systems/%s"' % sid, deleted_section)
        self.assertNotIn('groups_%s' % sid, listing)
        self.assertNotIn(sid, self.admin.get('/?search=%s' % title)[2], 'search found a deleted system')
        for page in ('/?main', '/?sched', '/?benchmark', '/?dashboard'):
            self.assertNotIn(sid, self.admin.get(page)[2], '%s shows a deleted system' % page)
        # Group management cannot touch it either
        tok = self.admin.token('/?systems')
        self.admin.post('/?systems', {'system_group_update': '1', 'token_submit': tok, 'groups_%s[]' % sid: 'qcgrp'})
        self.admin.post('/?systems', {'new_group': 'qcgroup2', 'token_submit': tok, 'systems_for_group[]': sid})
        self.assertIsNone(row(sid)['Groups'])
        # It cannot be re-enabled or edited through a forged POST
        self.admin.post('/?systems/%s' % sid, {'system_title': 'hijack', 'system_description': 'd', 'system_state': '1', 'block_power_offs': '0', 'token_submit': tok})
        self.assertEqual((int(row(sid)['State']), row(sid)['Title']), (-1, title))

        # The machine checks in again: a NEW pending system, even with auto-approve on; the tombstone is untouched
        with AutoApprove():
            control = new_machine(approved=False)
            self.assertEqual(int(sql("SELECT State FROM phoromatic_systems WHERE MachineSelfID='%s'" % control)[0]['State']), 1, 'auto-approve itself is not working')
            reply = call('private_repo_index', msi)
        self.assertIn('Waiting', str(reply))
        new = sql("SELECT * FROM phoromatic_systems WHERE MachineSelfID='%s'" % msi)
        self.assertEqual(len(new), 1)
        self.assertNotEqual(new[0]['SystemID'], sid)
        self.assertEqual(int(new[0]['State']), 0)
        self.assertIn('Previously deleted: %s' % title, new[0]['Description'])
        self.assertIn('previously deleted', new[0]['CurrentTask'])
        self.assertEqual(row(sid)['DeletedMachineSelfID'], msi)
        self.assertTrue(sql("SELECT 1 AS x FROM phoromatic_results WHERE SystemID='%s'" % sid))
        self.assertIn(title, self.admin.get('/?result/%s' % pprid)[2])
        # ... and the old record cannot be restored over the live one
        tok = self.admin.token('/?systems/%s' % sid)
        out = self.admin.post('/?systems/%s' % sid, {'restore_system': '1', 'token_submit': tok})[2]
        self.assertIn('cannot be restored', out)
        self.assertIsNotNone(row(sid)['DeletedOn'])

    def test_restore_brings_back_a_disabled_system(self):
        msi, sid = real_system('Restorable')
        delete_via_ui(self.admin, sid)
        self.assertIsNone(row(sid)['MachineSelfID'])
        tok = self.admin.token('/?systems/%s' % sid)
        # level 3 cannot restore, a GET does nothing
        b = self.as_user(3)
        b.post('/?systems/%s' % sid, {'restore_system': '1', 'token_submit': b.token('/?systems')})
        self.admin.get('/?systems/%s&restore_system=1' % sid)
        self.assertIsNotNone(row(sid)['DeletedOn'])
        self.assertIn('System restored', self.admin.post('/?systems/%s' % sid, {'restore_system': '1', 'token_submit': tok})[2])
        r = row(sid)
        self.assertEqual((r['DeletedOn'], r['MachineSelfID'], int(r['State'])), (None, msi, -1))
        self.assertEqual([x['Action'] for x in audit_rows(sid)], ['system_delete', 'system_restore'])

    def test_audit_failure_stops_the_delete(self):
        msi, sid = real_system('Failclosed')
        self.assertEqual(sql_exec("CREATE TRIGGER qc_block_audit BEFORE INSERT ON phoromatic_admin_audit BEGIN SELECT RAISE(ABORT, 'qc'); END"), 'ok')
        try:
            out = delete_via_ui(self.admin, sid)
        finally:
            sql_exec('DROP TRIGGER qc_block_audit')
        self.assertIn('nothing was changed', out)
        r = row(sid)
        self.assertEqual((r['DeletedOn'], r['MachineSelfID']), (None, msi))
        self.assertEqual(audit_rows(sid), [])

    def test_audit_table_is_append_only(self):
        msi, sid = real_system('Appendonly')
        delete_via_ui(self.admin, sid)
        self.assertEqual(sql_exec("UPDATE phoromatic_admin_audit SET Actor='x' WHERE SubjectID='%s'" % sid), 'refused')
        self.assertEqual(sql_exec("DELETE FROM phoromatic_admin_audit WHERE SubjectID='%s'" % sid), 'refused')

    def test_insert_or_replace_cannot_overwrite_audit_rows(self):
        msi, sid = real_system('Replaceme')
        delete_via_ui(self.admin, sid)
        first = sql("SELECT AuditID, Actor FROM phoromatic_admin_audit WHERE SubjectID='%s'" % sid)[0]
        self.assertEqual(sql_exec("INSERT OR REPLACE INTO phoromatic_admin_audit (AuditID, AccountID, EventTime, ActorType, Actor, Action) VALUES (%s, 'x', 'x', 'user', 'forged', 'system_delete')" % first['AuditID']), 'refused')
        self.assertEqual(sql("SELECT Actor FROM phoromatic_admin_audit WHERE AuditID=%s" % first['AuditID'])[0]['Actor'], first['Actor'])
        rep = sql("SELECT AuditID, Actor FROM phoromatic_private_repo_audit ORDER BY AuditID LIMIT 1")
        if rep:
            self.assertEqual(sql_exec("INSERT OR REPLACE INTO phoromatic_private_repo_audit (AuditID, AccountID, EventTime, ActorType, Actor, Action) VALUES (%s, 'x', 'x', 'user', 'forged', 'upload')" % rep[0]['AuditID']), 'refused')
            self.assertEqual(sql("SELECT Actor FROM phoromatic_private_repo_audit WHERE AuditID=%s" % rep[0]['AuditID'])[0]['Actor'], rep[0]['Actor'])
        # normal appends still work
        self.assertEqual(sql_exec("INSERT INTO phoromatic_admin_audit (AccountID, EventTime, ActorType, Actor, Action) VALUES ('x', 'x', 'user', 'ok', 'system_delete')"), 'ok')

    def test_migration_step_is_repeatable(self):
        for start in (48, 50, 51):
            sql_exec('PRAGMA user_version = %d' % start)
            call('private_repo_index', self.msi)
            self.assertEqual(int(sql('PRAGMA user_version')[0]['user_version']), 54)  # the latest step (multi-system benches, 0.9)
            self.assertEqual(sql("SELECT name FROM sqlite_master WHERE name='phoromatic_systems_one_per_bench'"), [])
        self.assertEqual(len(sql("SELECT name FROM sqlite_master WHERE type='trigger' AND name LIKE '%_no_replace'")), 2)
        cols = [c['name'] for c in sql('PRAGMA table_info(phoromatic_systems)')]
        for c in ('DeletedOn', 'DeletedBy', 'DeletedMachineSelfID', 'DeletedNetworkMAC'):
            self.assertEqual(cols.count(c), 1)

    def test_system_titles_are_escaped(self):
        msi, sid = real_system('x', state=-1)
        evil = '<img src=x id=qcxss>'
        sql_exec("UPDATE phoromatic_systems SET Title='%s' WHERE SystemID='%s'" % (evil, sid))
        for page in ('/?systems/%s' % sid, '/?systems/%s/edit' % sid, '/?systems', '/?systems/%s/delete' % sid):
            body = self.admin.get(page)[2]
            self.assertNotIn(evil, body, 'unescaped title on %s' % page)
        self.assertIn('&lt;img src=x id=qcxss&gt;', self.admin.get('/?systems')[2])
        delete_via_ui(self.admin, sid)
        self.assertNotIn(evil, self.admin.get('/?systems/%s' % sid)[2])
        self.assertNotIn(evil, self.admin.get('/?systems')[2])

    def test_description_round_trips_without_double_escaping(self):
        msi, sid = real_system('Describe', state=-1)
        desc = 'Tom & Jerry "q"'     # (input containing < or > is refused by the page's own input filter)
        for _ in range(2):                                   # edit, save, edit again: nothing may change or grow
            form = self.admin.get('/?systems/%s/edit' % sid)[2]
            tok = re.search(r'name="token_submit" value="([0-9a-f]+)"', form).group(1)
            self.admin.post('/?systems/%s' % sid, {'system_title': 'Describe', 'system_description': desc, 'system_state': '-1', 'block_power_offs': '0', 'token_submit': tok})
            self.assertEqual(sql("SELECT Description FROM phoromatic_systems WHERE SystemID='%s'" % sid)[0]['Description'], 'Tom &amp; Jerry "q"')
        # the edit form hands the stored text back as the entered text once the browser decodes it; the view shows it once-escaped
        self.assertIn('>Tom &amp; Jerry "q"</textarea>', self.admin.get('/?systems/%s/edit' % sid)[2])
        view = self.admin.get('/?systems/%s' % sid)[2]
        self.assertIn('<em>Tom &amp; Jerry "q"</em>', view)
        self.assertNotIn('&amp;amp;', view)

    def test_client_reported_fields_are_escaped_everywhere(self):
        xss = '<script>qcxss()</script>'
        msi = str(uuid.uuid4())
        raw_post('/phoromatic.php', {'r': 'private_repo_index', 'aid': AID, 'msi': msi, 'pts_core': '10860', 'lip': xss, 'pts': xss, 'nm': xss, 'nw': xss,
                                      'h': 'Processor: ' + xss, 's': 'OS: ' + xss, 'n': 'xsshost'})
        sid = sql("SELECT SystemID FROM phoromatic_systems WHERE MachineSelfID='%s'" % msi)[0]['SystemID']
        sql_exec("UPDATE phoromatic_systems SET State=1 WHERE SystemID='%s'" % sid)
        sql_exec("INSERT INTO phoromatic_schedules (AccountID, ScheduleID, Title, State, RunTargetSystems, RunTargetGroups) VALUES ('%s', 770010, 'xss sched', 1, '%s', '')" % (AID, sid))
        stored = sql("SELECT LocalIP FROM phoromatic_systems WHERE SystemID='%s'" % sid)[0]['LocalIP']
        self.assertIn('qcxss', stored or '', 'the hostile value never reached the database, the test proves nothing')
        for page in ('/?schedules/770010', '/?systems', '/?systems/%s' % sid, '/?component_table', '/?maintenance_table', '/?main', '/?dashboard'):
            body = self.admin.get(page)[2]
            self.assertNotIn('<script>qcxss', body, 'unescaped client-reported value on %s' % page)
        for page in ('/?schedules/770010', '/?systems/%s' % sid, '/?component_table'):
            self.assertIn('&lt;script&gt;qcxss', self.admin.get(page)[2], 'expected the escaped value on %s' % page)

    def test_schedule_and_ticket_forms_ignore_unknown_and_deleted_systems(self):
        msi, live = real_system('Livesys', state=1)
        msi2, gone = real_system('Gonesys')
        delete_via_ui(self.admin, gone)
        page = self.admin.get('/?sched')[2]
        self.assertIn(live, page)
        self.assertNotIn(gone, page)
        self.admin.post('/?sched', [('schedule_title', 'qc forced'), ('schedule_description', 'x'), ('run_on_systems[]', live), ('run_on_systems[]', gone), ('run_on_systems[]', 'NOSUCH')])
        rows = sql("SELECT RunTargetSystems FROM phoromatic_schedules WHERE Title='qc forced'")
        self.assertTrue(rows, 'the schedule was not created, the test proves nothing')
        self.assertIn(live, rows[0]['RunTargetSystems'])
        for r in rows:
            self.assertNotIn(gone, r['RunTargetSystems'])
            self.assertNotIn('NOSUCH', r['RunTargetSystems'])

    def test_titles_forced_into_the_database_are_escaped_on_the_remaining_pages(self):
        evil = '<i id=qcd2>x</i>'
        msi, sid = real_system('x', state=1)
        sql_exec("UPDATE phoromatic_systems SET Title='%s', LastCommunication=datetime('now') WHERE SystemID='%s'" % (evil, sid))
        upload_id = int(uuid.uuid4().int % 10**9)
        pprid = 'qcd2' + uuid.uuid4().hex[:8]
        sql_exec("INSERT INTO phoromatic_schedules (AccountID, ScheduleID, Title, State, RunTargetSystems, RunTargetGroups) VALUES ('%s', 770020, 'qc d2', 1, '%s', '')" % (AID, sid))
        sql_exec("INSERT INTO phoromatic_results (AccountID, UploadID, ScheduleID, Trigger, UploadTime, Title, SystemID, PPRID, BenchmarkTicketID) VALUES ('%s', %d, 770020, 'qc', datetime('now'), 'QC d2', '%s', '%s', 0)" % (AID, upload_id, sid, pprid))
        pages = ['/?sched', '/?sched/770020', '/?benchmark', '/?results', '/?maintenance_table', '/?component_table', '/?schedules/770020', '/?main', '/?result/%s' % pprid]
        for page in pages:
            self.assertNotIn(evil, self.admin.get(page)[2], 'unescaped system title on %s' % page)
        for page in ('/?sched', '/?sched/770020', '/?results', '/?maintenance_table'):
            self.assertIn('&lt;i id=qcd2&gt;', self.admin.get(page)[2], 'expected the escaped title on %s' % page)
        # and for a tombstone (its title stays in the result list)
        sql_exec("UPDATE phoromatic_systems SET State=-1 WHERE SystemID='%s'" % sid)
        delete_via_ui(self.admin, sid)
        for page in ('/?results', '/?systems', '/?systems/%s' % sid, '/?schedules/770020'):
            self.assertNotIn(evil, self.admin.get(page)[2], 'unescaped tombstone title on %s' % page)

    def test_confirm_page_says_context_logs_are_kept(self):
        msi, sid = real_system('Ctxlogs')
        self.assertIn('Context logs', self.admin.get('/?systems/%s/delete' % sid)[2])


if __name__ == '__main__':
    unittest.main()
