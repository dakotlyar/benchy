"""MAC adoption (Part 1b of the systems/benches design): a client that reports the MAC of an existing system under a DIFFERENT machine
id must never inherit that system's state. It registers as a new pending row (AutoApprove ignored) that names the system it looks like,
and an administrator (AdminLevel 1-2) chooses "Adopt as <title>" or "Register as new".

Run through tests/private-repo/run.sh (part of http/all).
"""
import re
import unittest
import uuid

from test_private_repo_http import AID, CORE, call, parse, raw_post, sql, test_zip, uniq, upload
from test_private_repo_tokens import sql_exec
from test_private_repo_web import WebBase
from test_systems_tombstone import AutoApprove, delete_via_ui, row

OTHER_AID = 'QCOTHERACCT'


def mac():
    h = uuid.uuid4().hex
    return '02:' + ':'.join(h[i:i + 2] for i in range(0, 10, 2))


def checkin(msi, nm=None, resource='private_repo_index', **fields):
    f = {'r': resource, 'aid': AID, 'msi': msi, 'pts_core': CORE, 'n': 'qchost'}
    if nm is not None:
        f['nm'] = nm
    f.update(fields)
    return parse(raw_post('/phoromatic.php', f))


def by_msi(msi):
    r = sql("SELECT * FROM phoromatic_systems WHERE AccountID='%s' AND MachineSelfID='%s'" % (AID, msi))
    return r[0] if r else None


def victim(title, groups=None, state=1, nm=None):
    """An approved system with a machine id and a MAC, registered by a real check-in. Returns (msi, SystemID, mac)."""
    nm = nm or mac()
    msi = str(uuid.uuid4())
    checkin(msi, nm)
    sid = by_msi(msi)['SystemID']
    assert sql_exec("UPDATE phoromatic_systems SET Title='%s', State=%d, Groups=%s WHERE SystemID='%s'" % (title, state, "'%s'" % groups if groups else 'NULL', sid)) == 'ok'
    return msi, sid, nm


def adoption_audit(action, sid):
    return sql("SELECT * FROM phoromatic_admin_audit WHERE Action='%s' AND SubjectID='%s' ORDER BY AuditID" % (action, sid))


def refused_rows(candidate_sid):
    return adoption_audit('system_adopt_refused', candidate_sid)


def action(browser, sid, field, token=None, extra=None, **kw):
    tok = browser.token('/?systems') if token is None else token
    data = {field: '1'}
    data.update(extra or {})
    if tok:
        data['token_submit'] = tok
    return browser.post('/?systems/%s' % sid, data, **kw)[2]


class TestMacClash(WebBase):
    def test_attacker_with_victims_mac_stays_pending_and_gets_nothing(self):
        vmsi, vsid, nm = victim('Victim%s' % uuid.uuid4().hex[:4], groups='#qcgrp#')
        name = uniq('advpkg')
        self.assertIn('upload', upload(vmsi, 'test', name, test_zip()))
        self.assertIn('zip', call('private_repo_fetch', vmsi, kind='test', id=name + '-1.0.0'), 'the victim itself must be able to fetch (control)')
        with AutoApprove():
            attacker = str(uuid.uuid4())
            reply = checkin(attacker, nm)
            self.assertIn('Waiting', str(reply))
            # both the check-in itself and every later one stay pending
            for resource in ('private_repo_index', 'private_repo_fetch', 'start', 'tick', 'list_results'):
                extra = {'kind': 'test', 'id': name + '-1.0.0'} if resource == 'private_repo_fetch' else {}
                r = checkin(attacker, nm, resource, **extra)
                self.assertIn('Waiting', str(r), 'a MAC-clash client reached %s: %s' % (resource, str(r)[:200]))
                self.assertNotIn('zip', r)
        a = by_msi(attacker)
        self.assertEqual((int(a['State']), a['AdoptionCandidate']), (0, vsid))
        self.assertEqual(a['CurrentTask'].startswith('Awaiting Authorization (same MAC as Victim'), True, a['CurrentTask'])
        self.assertNotEqual(a['SystemID'], vsid)
        self.assertIsNone(a['Groups'])
        self.assertEqual(len(sql("SELECT 1 FROM phoromatic_systems WHERE MachineSelfID='%s'" % attacker)), 1, 'repeat check-ins created more rows')
        v = row(vsid)
        self.assertEqual((v['MachineSelfID'], int(v['State']), v['Groups']), (vmsi, 1, '#qcgrp#'))
        # Past the 5-minute refresh window the attacker row is still its own row, and the victim's machine id is not rewritten
        sql_exec("UPDATE phoromatic_systems SET LastCommunication='2020-01-01 00:00:00' WHERE SystemID IN ('%s','%s')" % (vsid, a['SystemID']))
        self.assertIn('Waiting', str(checkin(attacker, nm)))
        self.assertEqual(row(vsid)['MachineSelfID'], vmsi)
        self.assertEqual(by_msi(attacker)['SystemID'], a['SystemID'])
        # the victim still works
        self.assertIn('zip', call('private_repo_fetch', vmsi, kind='test', id=name + '-1.0.0'))
        # visible to the administrator as a refused adoption
        self.assertTrue(refused_rows(vsid))

    def test_mac_match_is_case_insensitive(self):
        vmsi, vsid, nm = victim('Casey', nm='02:aa:bb:cc:dd:ee')
        attacker = str(uuid.uuid4())
        checkin(attacker, '02:AA:BB:CC:DD:EE')
        self.assertEqual(by_msi(attacker)['AdoptionCandidate'], vsid)

    def test_empty_or_missing_mac_never_matches(self):
        vmsi, vsid, nm = victim('Nomac')
        sql_exec("UPDATE phoromatic_systems SET NetworkMAC='' WHERE SystemID='%s'" % vsid)
        for nm_value in (None, '', ' '):
            msi = str(uuid.uuid4())
            checkin(msi, nm_value)
            r = by_msi(msi)
            self.assertIsNotNone(r)
            self.assertIsNone(r['AdoptionCandidate'])
            self.assertEqual(int(r['State']), 0)
            self.assertEqual(row(vsid)['MachineSelfID'], vmsi)

    def test_auto_approve_is_not_honoured_for_a_clash_but_still_for_others(self):
        vmsi, vsid, nm = victim('Autoclash')
        with AutoApprove():
            clash = str(uuid.uuid4())
            checkin(clash, nm)
            plain = str(uuid.uuid4())
            checkin(plain, mac())
        self.assertEqual(int(by_msi(clash)['State']), 0)
        self.assertEqual(int(by_msi(plain)['State']), 1, 'auto-approve itself is not working')

    def test_legacy_row_without_machine_id_is_bound_once_and_immediately(self):
        vmsi, vsid, nm = victim('Legacy', groups='#legacy#')
        sql_exec("UPDATE phoromatic_systems SET MachineSelfID=NULL, LastCommunication=datetime('now') WHERE SystemID='%s'" % vsid)
        first = str(uuid.uuid4())
        self.assertNotIn('Waiting', str(checkin(first, nm)), 'a legacy row (no machine id) is still found by MAC')
        # bound in that very check-in, not at the next 5-minute refresh
        self.assertEqual(row(vsid)['MachineSelfID'], first)
        # a second client with the MAC cannot squat it, now or later
        for _ in range(2):
            second = str(uuid.uuid4())
            self.assertIn('Waiting', str(checkin(second, nm)))
            self.assertEqual(by_msi(second)['AdoptionCandidate'], vsid)
            self.assertEqual(row(vsid)['MachineSelfID'], first)
            self.assertEqual(int(row(vsid)['State']), 1)

    def test_legacy_match_skips_pending_adoption_rows_and_placeholder_macs(self):
        nm = mac()
        sql_exec("INSERT INTO phoromatic_systems (AccountID, SystemID, Title, State, NetworkMAC, AdoptionCandidate, CreatedOn, LastCommunication) VALUES ('%s', 'QCLGP', 'legpend', 1, '%s', 'QCXXX', '2026-01-01 00:00:00', '2026-01-01 00:00:00')" % (AID, nm))
        sql_exec("INSERT INTO phoromatic_systems (AccountID, SystemID, Title, State, NetworkMAC, CreatedOn, LastCommunication) VALUES ('%s', 'QCLGZ', 'legzero', 1, '00:00:00:00:00:00', '2026-01-01 00:00:00', '2026-01-01 00:00:00')" % AID)
        try:
            for nm_value in (nm, '00:00:00:00:00:00'):
                msi = str(uuid.uuid4())
                self.assertIn('Waiting', str(checkin(msi, nm_value)))
                r = by_msi(msi)
                self.assertNotIn(r['SystemID'], ('QCLGP', 'QCLGZ'))
            self.assertIsNone(sql("SELECT MachineSelfID FROM phoromatic_systems WHERE SystemID='QCLGP'")[0]['MachineSelfID'])
            self.assertIsNone(sql("SELECT MachineSelfID FROM phoromatic_systems WHERE SystemID='QCLGZ'")[0]['MachineSelfID'])
        finally:
            sql_exec("DELETE FROM phoromatic_systems WHERE SystemID IN ('QCLGP','QCLGZ')")

    def test_client_without_machine_id_is_not_registered(self):
        nm = mac()
        before = sql("SELECT COUNT(*) AS n FROM phoromatic_systems")[0]['n']
        r = parse(raw_post('/phoromatic.php', {'r': 'private_repo_index', 'aid': AID, 'gsid': 'QCGSIDX', 'pts_core': CORE, 'nm': nm}))
        self.assertIn('error', r)
        self.assertEqual(sql("SELECT COUNT(*) AS n FROM phoromatic_systems")[0]['n'], before)
        # and it cannot take over a legacy row either
        vmsi, vsid, nm2 = victim('Legacy2')
        sql_exec("UPDATE phoromatic_systems SET MachineSelfID=NULL WHERE SystemID='%s'" % vsid)
        r = parse(raw_post('/phoromatic.php', {'r': 'private_repo_index', 'aid': AID, 'gsid': 'QCGSIDX', 'pts_core': CORE, 'nm': nm2}))
        self.assertIn('error', r)
        self.assertIsNone(row(vsid)['MachineSelfID'])

    def test_mac_normalisation_and_placeholders(self):
        vmsi, vsid, nm = victim('Normal', nm='02-AA-BB-CC-DD-EF')
        a = str(uuid.uuid4())
        checkin(a, ' 02:aa:bb:cc:dd:ef ')
        self.assertEqual(by_msi(a)['AdoptionCandidate'], vsid, 'dash/colon/case/whitespace variants must still clash')
        for ph in ('00:00:00:00:00:00', 'FF:FF:FF:FF:FF:FF', '00-00-00-00-00-00', '0'):
            v2, s2, _ = victim('Ph', nm=ph)
            b = str(uuid.uuid4())
            checkin(b, ph)
            self.assertIsNone(by_msi(b)['AdoptionCandidate'], 'placeholder MAC %s clashed' % ph)
            self.assertEqual(row(s2)['MachineSelfID'], v2)
        # a pending row with a placeholder MAC and a candidate gets no Adopt button
        sql_exec("INSERT INTO phoromatic_systems (AccountID, SystemID, Title, State, MachineSelfID, NetworkMAC, AdoptionCandidate, CreatedOn, LastCommunication) VALUES ('%s', 'QCPHP', 'phpend', 0, 'phmsi', '00:00:00:00:00:00', '%s', '2026-01-01 00:00:00', '2026-01-01 00:00:00')" % (AID, vsid))
        try:
            body = self.admin.get('/?systems')[2]
            self.assertIn('?systems/QCPHP', body)
            self.assertNotIn('value="Adopt as Normal"', body.split('QCPHP')[1].split('</li>')[0])
            self.assertIn('no longer report the same MAC', action(self.admin, 'QCPHP', 'adopt_system'))
        finally:
            sql_exec("DELETE FROM phoromatic_systems WHERE SystemID='QCPHP'")

    def test_row_found_by_machine_id_never_changes_its_machine_id(self):
        vmsi, vsid, nm = victim('Stable')
        sql_exec("UPDATE phoromatic_systems SET LastCommunication='2020-01-01 00:00:00' WHERE SystemID='%s'" % vsid)
        checkin(vmsi, nm)
        r = row(vsid)
        self.assertEqual(r['MachineSelfID'], vmsi)
        self.assertNotEqual(r['LastCommunication'], '2020-01-01 00:00:00', 'the refresh did not run, the test proves nothing')

    def test_clash_composes_with_a_tombstone(self):
        # machine D was deleted (its msi and MAC kept on the tombstone); a live system L has taken the same MAC.
        dmsi, dsid, nm = victim('Tombed', state=-1)
        delete_via_ui(self.admin, dsid)
        lmsi, lsid, _ = victim('Livemac', nm=nm)
        with AutoApprove():
            checkin(dmsi, nm)
        n = by_msi(dmsi)
        self.assertNotIn(n['SystemID'], (dsid, lsid))
        self.assertEqual((int(n['State']), n['AdoptionCandidate']), (0, lsid))
        self.assertIn('Previously deleted: Tombed', n['Description'])
        self.assertIsNotNone(row(dsid)['DeletedOn'])
        self.assertEqual(row(lsid)['MachineSelfID'], lmsi)
        # and a deleted machine whose MAC nobody else has is still just a new pending system (Part 1a behaviour)
        d2, s2, nm2 = victim('Tombed2', state=-1)
        delete_via_ui(self.admin, s2)
        with AutoApprove():
            checkin(d2, nm2)
        n2 = by_msi(d2)
        self.assertEqual((int(n2['State']), n2['AdoptionCandidate']), (0, None))
        self.assertIn('Previously deleted', n2['Description'])

    def test_current_task_cannot_carry_markup_from_a_title(self):
        vmsi, vsid, nm = victim('x')
        sql_exec("UPDATE phoromatic_systems SET Title='<i id=qcad>x</i>' WHERE SystemID='%s'" % vsid)
        attacker = str(uuid.uuid4())
        checkin(attacker, nm)
        self.assertNotIn('<', by_msi(attacker)['CurrentTask'])
        for page in ('/?main', '/?systems', '/?dashboard'):
            self.assertNotIn('<i id=qcad>', self.admin.get(page)[2], 'unescaped title on %s' % page)
        self.assertIn('&lt;i id=qcad&gt;x&lt;/i&gt;', self.admin.get('/?systems')[2])

    def test_refused_audit_is_rate_limited_per_msi_and_mac(self):
        vmsi, vsid, nm = victim('Rated')
        attacker = str(uuid.uuid4())
        checkin(attacker, nm)
        self.assertEqual(len(refused_rows(vsid)), 1)
        first = refused_rows(vsid)[0]
        self.assertEqual((first['ActorType'], first['Actor']), ('machine', by_msi(attacker)['SystemID']))
        self.assertNotIn(attacker, first['Detail'] + first['ActorDetail'], 'the machine id (a bearer secret) must not be logged in clear')
        # the admin discards the pending row, the same client comes back within the hour: no second row
        sql_exec("DELETE FROM phoromatic_systems WHERE MachineSelfID='%s'" % attacker)
        checkin(attacker, nm)
        self.assertEqual(len(refused_rows(vsid)), 1)
        # a different machine id is a different key
        checkin(str(uuid.uuid4()), nm)
        self.assertEqual(len(refused_rows(vsid)), 2)
        # an hour later the same key is logged again (age the row; the table is append-only, so lift the trigger for the test)
        self.assertEqual(sql_exec("DROP TRIGGER phoromatic_admin_audit_no_update"), 'ok')
        try:
            sql_exec("UPDATE phoromatic_admin_audit SET EventTime='2020-01-01 00:00:00' WHERE Action='system_adopt_refused' AND SubjectID='%s' AND AuditID=%s" % (vsid, first['AuditID']))
        finally:
            sql_exec("CREATE TRIGGER phoromatic_admin_audit_no_update BEFORE UPDATE ON phoromatic_admin_audit BEGIN SELECT RAISE(ABORT, 'audit rows are append-only'); END")
        sql_exec("DELETE FROM phoromatic_systems WHERE MachineSelfID='%s'" % attacker)
        checkin(attacker, nm)
        self.assertEqual(len(refused_rows(vsid)), 3)


class TestAdoptionButtons(WebBase):
    def clash(self, title, groups='#qcgrp#', age=True):
        vmsi, vsid, nm = victim(title, groups=groups)
        if age:       # adopting a system that checked in within 10 minutes needs an explicit confirmation
            sql_exec("UPDATE phoromatic_systems SET LastCommunication='2020-01-01 00:00:00' WHERE SystemID='%s'" % vsid)
        new = str(uuid.uuid4())
        checkin(new, nm)
        return vmsi, vsid, nm, new, by_msi(new)['SystemID']

    def test_pending_row_shows_both_buttons_to_admins_only(self):
        title = 'Btn%s' % uuid.uuid4().hex[:5]
        vmsi, vsid, nm, new, psid = self.clash(title)
        for page in ('/?main', '/?systems'):
            body = self.admin.get(page)[2]
            self.assertIn('value="Adopt as %s"' % title, body)
            self.assertIn('value="Register as new"', body)
            self.assertIn('?systems/%s' % psid, body)
        b = self.as_user(3)
        body = b.get('/?systems')[2]
        self.assertIn(psid, body)
        self.assertNotIn('Adopt as %s' % title, body, 'level 3 is offered the adoption buttons')

    def test_adopt_keeps_state_groups_and_results_and_removes_the_pending_row(self):
        title = 'Reimaged%s' % uuid.uuid4().hex[:4]
        vmsi, vsid, nm, new, psid = self.clash(title)
        pprid = 'qcad' + uuid.uuid4().hex[:8]
        sql_exec("INSERT INTO phoromatic_results (AccountID, UploadID, ScheduleID, Trigger, UploadTime, Title, SystemID, PPRID, BenchmarkTicketID) VALUES ('%s', %d, 0, 'qc', '2026-01-01 00:00:00', 'QC result', '%s', '%s', 0)" % (AID, int(uuid.uuid4().int % 10**9), vsid, pprid))
        # the new client is still pending
        self.assertIn('Waiting', str(checkin(new, nm)))
        out = action(self.admin, psid, 'adopt_system')
        self.assertIn('now uses the existing system record', out)
        v = row(vsid)
        self.assertEqual((v['MachineSelfID'], v['NetworkMAC'], int(v['State']), v['Groups'], v['Title'], v['AdoptionCandidate']), (new, nm, 1, '#qcgrp#', title, None))
        t = row(psid)
        self.assertIsNotNone(t, 'the pending SystemID must stay reserved')
        self.assertEqual((t['MachineSelfID'], t['NetworkMAC'], t['AdoptionCandidate'], int(t['State'])), (None, None, None, -1))
        self.assertTrue(t['DeletedOn'] and t['CurrentTask'] == 'Adopted into ' + vsid)
        self.assertNotIn('?systems/%s"' % psid, self.admin.get('/?systems')[2].partition('Deleted systems (')[0], 'the adopted pending row is still listed as live')
        self.assertTrue(sql("SELECT 1 AS x FROM phoromatic_results WHERE SystemID='%s'" % vsid))
        # the new client is now the approved system; the old install (old machine id, same MAC) is the clash now
        self.assertNotIn('Waiting', str(checkin(new, nm)))
        self.assertEqual(by_msi(new)['SystemID'], vsid)
        old = checkin(vmsi, nm)
        self.assertIn('Waiting', str(old))
        self.assertEqual(by_msi(vmsi)['AdoptionCandidate'], vsid)
        # audit
        a = adoption_audit('system_adopt', vsid)
        self.assertEqual(len(a), 1)
        self.assertEqual((a[0]['ActorType'], a[0]['SubjectType'], a[0]['SubjectLabel']), ('user', 'system', title))
        self.assertIn(psid, a[0]['Detail'])
        self.assertNotIn(new, a[0]['Detail'], 'machine id logged in clear')
        # second submit: nothing left to adopt
        self.assertIn('not waiting for an adoption decision', action(self.admin, psid, 'adopt_system'))
        self.assertIn('not waiting for an adoption decision', action(self.admin, psid, 'register_new_system'))
        self.assertEqual(len(adoption_audit('system_adopt', vsid)), 1)

    def test_adopt_keeps_a_disabled_state_too(self):
        vmsi, vsid, nm, new, psid = self.clash('Disabledad')
        sql_exec("UPDATE phoromatic_systems SET State=-1 WHERE SystemID='%s'" % vsid)
        action(self.admin, psid, 'adopt_system')
        self.assertEqual((row(vsid)['MachineSelfID'], int(row(vsid)['State'])), (new, -1))

    def test_adopt_resets_running_work_and_register_has_a_confirm(self):
        vmsi, vsid, nm, new, psid = self.clash('Resets')
        sql_exec("UPDATE phoromatic_systems SET CurrentProcessSchedule='770099', CurrentProcessTicket=5, TickThreadEvent='1:reboot' WHERE SystemID='%s'" % vsid)
        body = self.admin.get('/?systems')[2]
        self.assertRegex(body, r'value="Register as new" onclick="return confirm\(')
        action(self.admin, psid, 'adopt_system')
        r = row(vsid)
        self.assertEqual((r['MachineSelfID'], r['CurrentProcessSchedule'], int(r['CurrentProcessTicket']), r['TickThreadEvent']), (new, None, 0, None))

    def test_adopting_a_recently_active_system_needs_confirmation(self):
        vmsi, vsid, nm, new, psid = self.clash('Busy', age=False)
        body = self.admin.get('/?systems')[2]
        self.assertIn('name="confirm_active"', body)
        self.assertIn('nothing was changed', action(self.admin, psid, 'adopt_system'))
        self.assertEqual(row(vsid)['MachineSelfID'], vmsi)
        self.assertIn('now uses the existing system record', action(self.admin, psid, 'adopt_system', extra={'confirm_active': '1'}))
        self.assertEqual(row(vsid)['MachineSelfID'], new)

    def test_adopt_rechecks_mac_and_machine_id_inside_the_transaction(self):
        vmsi, vsid, nm, new, psid = self.clash('Rechecked')
        sql_exec("UPDATE phoromatic_systems SET NetworkMAC='%s' WHERE SystemID='%s'" % (mac(), vsid))
        self.assertIn('no longer report the same MAC', action(self.admin, psid, 'adopt_system'))
        self.assertEqual(row(vsid)['MachineSelfID'], vmsi)
        sql_exec("UPDATE phoromatic_systems SET NetworkMAC='%s' WHERE SystemID='%s'" % (nm.upper().replace(':', '-'), vsid))     # same MAC, other spelling: fine
        sql_exec("INSERT INTO phoromatic_systems (AccountID, SystemID, Title, State, MachineSelfID, CreatedOn, LastCommunication) VALUES ('%s', 'QCDUP', 'dup', 1, '%s', '2026-01-01 00:00:00', '2026-01-01 00:00:00')" % (AID, new))
        try:
            self.assertIn('already used by another system', action(self.admin, psid, 'adopt_system'))
            self.assertEqual(row(vsid)['MachineSelfID'], vmsi)
        finally:
            sql_exec("DELETE FROM phoromatic_systems WHERE SystemID='QCDUP'")
        self.assertIn('now uses the existing system record', action(self.admin, psid, 'adopt_system'))

    def test_state_is_whitelisted_on_the_edit_form(self):
        vmsi, vsid, nm = victim('Statey', state=-1)
        tok = self.admin.token('/?systems/%s/edit' % vsid)
        for bad in ('abc', '2', '', '1.5', '-2', '1 OR 1', '0x1'):
            out = self.admin.post('/?systems/%s' % vsid, {'system_title': 'Statey', 'system_description': 'd', 'system_state': bad, 'block_power_offs': '0', 'token_submit': tok})[2]
            self.assertEqual(sql("SELECT typeof(State) AS t, State FROM phoromatic_systems WHERE SystemID='%s'" % vsid)[0], {'t': 'integer', 'State': -1}, 'state %r was stored' % bad)
        self.admin.post('/?systems/%s' % vsid, {'system_title': 'Statey', 'system_description': 'd', 'system_state': '1', 'block_power_offs': '0', 'token_submit': tok})
        self.assertEqual(int(row(vsid)['State']), 1)

    def test_a_disabled_or_restored_candidate_row_stays_resolvable(self):
        # disabled through the edit form: still marked, cannot be enabled, can be decided from its detail page, and adopt works on it
        vmsi, vsid, nm, new, psid = self.clash('Strand')
        tok = self.admin.token('/?systems/%s/edit' % psid)
        self.admin.post('/?systems/%s' % psid, {'system_title': 'Strand2', 'system_description': 'd', 'system_state': '-1', 'block_power_offs': '0', 'token_submit': tok})
        self.assertEqual((int(row(psid)['State']), row(psid)['AdoptionCandidate']), (-1, vsid))
        self.admin.post('/?systems/%s' % psid, {'system_title': 'Strand2', 'system_description': 'd', 'system_state': '1', 'block_power_offs': '0', 'token_submit': tok})
        self.assertEqual(int(row(psid)['State']), -1, 'a disabled candidate row was enabled without a decision')
        detail = self.admin.get('/?systems/%s' % psid)[2]
        self.assertIn('value="Register as new"', detail)
        self.assertIn('value="Adopt as Strand"', detail)
        self.assertIn('now uses the existing system record', action(self.admin, psid, 'adopt_system'))
        self.assertEqual(row(vsid)['MachineSelfID'], new)
        # disabled by the bulk remove-inactive style update, then deleted and restored: still a candidate row, register as new works
        vmsi, vsid, nm, new, psid = self.clash('Strand3')
        sql_exec("UPDATE phoromatic_systems SET State=-1 WHERE SystemID='%s'" % psid)
        delete_via_ui(self.admin, psid)
        self.assertEqual(self.admin.post('/?systems/%s' % psid, {'adopt_system': '1', 'token_submit': self.admin.token('/?systems')})[2].count('now uses'), 0)
        self.assertIn('cannot be restored', self.admin.post('/?systems/%s' % psid, {'restore_system': '1', 'token_submit': self.admin.token('/?systems/%s' % psid)})[2], 'restoring over the live system with the same MAC must be refused')
        sql_exec("UPDATE phoromatic_systems SET NetworkMAC='%s' WHERE SystemID='%s'" % (mac(), vsid))
        self.assertIn('System restored', self.admin.post('/?systems/%s' % psid, {'restore_system': '1', 'token_submit': self.admin.token('/?systems/%s' % psid)})[2])
        r = row(psid)
        self.assertEqual((int(r['State']), r['AdoptionCandidate'], r['MachineSelfID']), (-1, vsid, new))
        self.assertIn('value="Register as new"', self.admin.get('/?systems/%s' % psid)[2])
        self.assertIn('separate pending system', action(self.admin, psid, 'register_new_system'))
        r = row(psid)
        self.assertEqual((int(r['State']), r['AdoptionCandidate']), (-1, None))

    def test_refused_audit_has_a_per_account_ceiling(self):
        vmsi, vsid, nm = victim('Ceiling')
        marker = 'qccap'
        self.assertEqual(sql_exec("INSERT INTO phoromatic_admin_audit (AccountID, EventTime, ActorType, Actor, ActorDetail, Action) WITH RECURSIVE n(i) AS (SELECT 1 UNION ALL SELECT i+1 FROM n WHERE i < 100) SELECT '%s', datetime('now'), 'machine', 'qc', '%s', 'system_adopt_refused' FROM n" % (AID, marker)), 'ok')
        try:
            attacker = str(uuid.uuid4())
            checkin(attacker, nm)
            self.assertEqual(int(by_msi(attacker)['State']), 0, 'the client is still registered pending')
            self.assertEqual(refused_rows(vsid), [], 'a refused-adoption row was written above the ceiling')
        finally:
            self.assertEqual(sql_exec("DROP TRIGGER phoromatic_admin_audit_no_delete"), 'ok')
            try:
                sql_exec("DELETE FROM phoromatic_admin_audit WHERE ActorDetail='%s'" % marker)
            finally:
                sql_exec("CREATE TRIGGER phoromatic_admin_audit_no_delete BEFORE DELETE ON phoromatic_admin_audit BEGIN SELECT RAISE(ABORT, 'audit rows are append-only'); END")

    def test_register_as_new(self):
        vmsi, vsid, nm, new, psid = self.clash('Realnew')
        # cannot be enabled by editing while the decision is open
        tok = self.admin.token('/?systems/%s/edit' % psid)
        out = self.admin.post('/?systems/%s' % psid, {'system_title': 'sneaky', 'system_description': 'd', 'system_state': '1', 'block_power_offs': '0', 'token_submit': tok})[2]
        self.assertIn('Nothing was changed', out)
        self.assertEqual((int(row(psid)['State']), row(psid)['AdoptionCandidate']), (0, vsid))
        self.assertIn('Waiting', str(checkin(new, nm)))
        out = action(self.admin, psid, 'register_new_system')
        self.assertIn('separate pending system', out)
        p = row(psid)
        self.assertEqual((int(p['State']), p['AdoptionCandidate'], p['MachineSelfID']), (0, None, new))
        v = row(vsid)
        self.assertEqual((v['MachineSelfID'], int(v['State']), v['Groups']), (vmsi, 1, '#qcgrp#'))
        self.assertEqual([a['Action'] for a in sql("SELECT Action FROM phoromatic_admin_audit WHERE SubjectID='%s' AND Action='system_register_new'" % psid)], ['system_register_new'])
        self.assertIn('Waiting', str(checkin(new, nm)), 'registering as new must not approve')
        # now it is approved the normal way
        tok = self.admin.token('/?systems/%s/edit' % psid)
        self.admin.post('/?systems/%s' % psid, {'system_title': 'Realnew2', 'system_description': 'd', 'system_state': '1', 'block_power_offs': '0', 'token_submit': tok})
        self.assertEqual(int(row(psid)['State']), 1)
        self.assertNotIn('Waiting', str(checkin(new, nm)))
        self.assertEqual(row(vsid)['MachineSelfID'], vmsi)

    def test_level_2_may_decide_level_3_and_10_may_not(self):
        vmsi, vsid, nm, new, psid = self.clash('Levels')
        for level in (3, 10):
            b = self.as_user(level)
            tok = 'x'
            for page in ('/?systems', '/?main', '/?results', '/?schedules'):
                m = re.search(r'name="token_submit" value="([0-9a-f]+)"', b.get(page)[2])
                if m:
                    tok = m.group(1)
                    break
            for field in ('adopt_system', 'register_new_system'):
                action(b, psid, field, token=tok)
            p = row(psid)
            self.assertEqual((p['AdoptionCandidate'], int(p['State'])), (vsid, 0), 'level %d changed a pending row' % level)
            self.assertEqual(row(vsid)['MachineSelfID'], vmsi)
        self.assertEqual(adoption_audit('system_adopt', vsid), [])
        b2 = self.admin2()
        self.assertIn('now uses the existing system record', action(b2, psid, 'adopt_system', token=b2.token('/?systems')))
        self.assertEqual(row(vsid)['MachineSelfID'], new)
        # a demoted user loses it at once (level re-read from the database)
        vmsi, vsid, nm, new, psid = self.clash('Demoted')
        b = self.as_user(2)
        tok = b.token('/?systems')
        sql_exec("UPDATE phoromatic_users SET AdminLevel=3 WHERE UserName='%s'" % self.names[2])
        try:
            action(b, psid, 'adopt_system', token=tok)
        finally:
            sql_exec("UPDATE phoromatic_users SET AdminLevel=2 WHERE UserName='%s'" % self.names[2])
        self.assertEqual(row(vsid)['MachineSelfID'], vmsi)

    def test_forged_requests_change_nothing(self):
        vmsi, vsid, nm, new, psid = self.clash('Forged')
        good = self.admin.token('/?systems')
        # GET never mutates
        for q in ('adopt_system=1', 'register_new_system=1', 'adopt_system=1&token_submit=' + good):
            self.admin.get('/?systems/%s&%s' % (psid, q))
        # no token, wrong token, another session's token
        other = self.admin2().token('/?systems')
        for tok in ('', 'deadbeef', other):
            for field in ('adopt_system', 'register_new_system'):
                action(self.admin, psid, field, token=tok)
        # cross-site origin / referer with a valid token
        for hdr in ({'Origin': 'http://evil.example'}, {'Referer': 'http://evil.example/x'}):
            for field in ('adopt_system', 'register_new_system'):
                action(self.admin, psid, field, token=good, headers=hdr)
        # anonymous
        from test_private_repo_web import Browser
        Browser().post('/?systems/%s' % psid, {'adopt_system': '1', 'token_submit': good})
        p = row(psid)
        self.assertEqual((p['AdoptionCandidate'], int(p['State'])), (vsid, 0))
        self.assertEqual(row(vsid)['MachineSelfID'], vmsi)
        self.assertEqual(adoption_audit('system_adopt', vsid), [])

    def test_audit_failure_stops_adopt_and_register_new(self):
        vmsi, vsid, nm, new, psid = self.clash('Failclosed2')
        self.assertEqual(sql_exec("CREATE TRIGGER qc_block_audit2 BEFORE INSERT ON phoromatic_admin_audit BEGIN SELECT RAISE(ABORT, 'qc'); END"), 'ok')
        try:
            o1 = action(self.admin, psid, 'adopt_system')
            o2 = action(self.admin, psid, 'register_new_system')
        finally:
            sql_exec('DROP TRIGGER qc_block_audit2')
        self.assertIn('nothing was changed', o1)
        self.assertIn('nothing was changed', o2)
        self.assertEqual(row(vsid)['MachineSelfID'], vmsi)
        p = row(psid)
        self.assertEqual((p['AdoptionCandidate'], int(p['State'])), (vsid, 0))

    def test_cannot_adopt_across_accounts(self):
        vmsi, vsid, nm, new, psid = self.clash('Mine')
        # a system of another account, and a pending row of that account pointing at one of ours
        sql_exec("INSERT OR IGNORE INTO phoromatic_systems (AccountID, SystemID, Title, State, MachineSelfID, NetworkMAC, CreatedOn, LastCommunication) VALUES ('%s', 'QCFOR', 'Foreign', 1, 'foreign-msi', '02:ff', '2026-01-01 00:00:00', '2026-01-01 00:00:00')" % OTHER_AID)
        sql_exec("INSERT OR IGNORE INTO phoromatic_systems (AccountID, SystemID, Title, State, MachineSelfID, NetworkMAC, AdoptionCandidate, CreatedOn, LastCommunication) VALUES ('%s', 'QCFPN', 'ForeignPending', 0, 'foreign-new', '02:ff', '%s', '2026-01-01 00:00:00', '2026-01-01 00:00:00')" % (OTHER_AID, vsid))
        try:
            # our pending row pointed (by forgery) at the foreign system: refused
            sql_exec("UPDATE phoromatic_systems SET AdoptionCandidate='QCFOR' WHERE SystemID='%s'" % psid)
            out = action(self.admin, psid, 'adopt_system')
            self.assertIn('no longer exists', out)
            self.assertEqual(sql("SELECT MachineSelfID FROM phoromatic_systems WHERE SystemID='QCFOR'")[0]['MachineSelfID'], 'foreign-msi')
            self.assertEqual(row(vsid)['MachineSelfID'], vmsi)
            # the other account's pending row is not ours to decide
            action(self.admin, 'QCFPN', 'adopt_system')
            action(self.admin, 'QCFPN', 'register_new_system')
            r = sql("SELECT MachineSelfID, AdoptionCandidate FROM phoromatic_systems WHERE SystemID='QCFPN'")[0]
            self.assertEqual((r['MachineSelfID'], r['AdoptionCandidate']), ('foreign-new', vsid))
            self.assertEqual(row(vsid)['MachineSelfID'], vmsi)
            # and a client of OUR account never matches another account's MAC
            attacker = str(uuid.uuid4())
            checkin(attacker, '02:ff')
            self.assertIsNone(by_msi(attacker)['AdoptionCandidate'])
        finally:
            sql_exec("DELETE FROM phoromatic_systems WHERE AccountID='%s'" % OTHER_AID)

    def test_adopt_refused_when_the_original_was_deleted_but_register_new_works(self):
        vmsi, vsid, nm, new, psid = self.clash('Vanishing')
        sql_exec("UPDATE phoromatic_systems SET State=-1 WHERE SystemID='%s'" % vsid)
        delete_via_ui(self.admin, vsid)
        out = action(self.admin, psid, 'adopt_system')
        self.assertIn('no longer exists', out)
        self.assertIsNotNone(row(psid))
        self.assertNotIn('value="Adopt as Vanishing"', self.admin.get('/?systems')[2], 'adopt offered for a deleted original')
        self.assertIn('separate pending system', action(self.admin, psid, 'register_new_system'))

    def test_pending_row_without_machine_id_cannot_adopt(self):
        vmsi, vsid, nm = victim('Nomsi')
        psid = 'QCNMS'
        sql_exec("INSERT INTO phoromatic_systems (AccountID, SystemID, Title, State, NetworkMAC, AdoptionCandidate, CreatedOn, LastCommunication) VALUES ('%s', '%s', 'pend', 0, '%s', '%s', '2026-01-01 00:00:00', '2026-01-01 00:00:00')" % (AID, psid, nm, vsid))
        self.assertIn('no machine id', action(self.admin, psid, 'adopt_system'))
        self.assertEqual(row(vsid)['MachineSelfID'], vmsi)


class TestMigration(WebBase):
    def test_adoption_migration_is_repeatable(self):
        for start in (48, 49, 50, 51):
            sql_exec('PRAGMA user_version = %d' % start)
            call('private_repo_index', self.msi)
            self.assertEqual(int(sql('PRAGMA user_version')[0]['user_version']), 60)  # the latest step (the server policy, 0.9.12)
            self.assertEqual(sql("SELECT name FROM sqlite_master WHERE name='phoromatic_systems_one_per_bench'"), [])
        # a step re-run on a database that already has everything
        sql_exec('PRAGMA user_version = 51')
        call('private_repo_index', self.msi)
        self.assertEqual(int(sql('PRAGMA user_version')[0]['user_version']), 60)  # the latest step (the server policy, 0.9.12)
        cols = [c['name'] for c in sql('PRAGMA table_info(phoromatic_systems)')]
        self.assertEqual(cols.count('AdoptionCandidate'), 1)
        idx = [i['name'] for i in sql("SELECT name FROM sqlite_master WHERE type='index' AND tbl_name='phoromatic_systems'")]
        for i in ('phoromatic_systems_msi', 'phoromatic_systems_mac'):
            self.assertEqual(idx.count(i), 1)
        # existing rows are untouched by the new column
        self.assertEqual(sql("SELECT COUNT(*) AS n FROM phoromatic_systems WHERE AdoptionCandidate IS NOT NULL AND State=1")[0]['n'], 0)


if __name__ == '__main__':
    unittest.main()
