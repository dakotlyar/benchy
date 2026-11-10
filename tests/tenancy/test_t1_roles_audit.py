"""T1: the server administrator role and the server audit. N26 (tenant administrators are not server administrators and are bounced from the server
pages; the role is the only way in), N30 (a tenant never sees the server audit; its rows cannot be changed or removed), the server-admin commands, and
that a change of a server-wide setting is in the server audit with who made it."""
import json
import re
import unittest

import tn

SETTINGS = '/?settings'
FORM = 'name="client_phone_home"'


def audit(where=''):
    return tn.sql('SELECT * FROM phoromatic_server_audit %s ORDER BY AuditID' % where)


class ServerAdminRole(unittest.TestCase):
    def setUp(self):
        self.st = tn.state()
        self.user = self.st['users']['T1']['1']
        self.addCleanup(lambda: tn.cli('server-admin-revoke', self.user))   # the two-account fixture has revoked it already: a harmless refusal

    def test_a_tenant_administrator_is_not_a_server_administrator_without_the_role(self):
        # N26: level 1 of a shared server has no role and sees no server form
        b = tn.Browser().login(self.user)
        self.assertNotIn(FORM, b.get(SETTINGS)[2])
        self.assertEqual(tn.sql("SELECT COUNT(*) AS n FROM phoromatic_server_roles")[0]['n'], 0)

    def test_grant_gives_the_forms_and_revoke_takes_them_away_on_the_next_request(self):
        b = tn.Browser().login(self.user)
        n = len(audit())
        self.assertIn('Done', tn.cli('server-admin-grant', self.user))
        self.assertIn(FORM, b.get(SETTINGS)[2])
        listing = tn.cli('server-admin-list')
        self.assertIn(self.user, listing)
        row = tn.sql("SELECT * FROM phoromatic_server_roles")[0]
        self.assertEqual((row['Role'], row['Source']), ('admin', 'local'))
        self.assertTrue(row['GrantedBy'].startswith('local:'), row)
        self.assertIn('Done', tn.cli('server-admin-revoke', self.user))
        self.assertNotIn(FORM, b.get(SETTINGS)[2])
        self.assertNotIn(self.user, tn.cli('server-admin-list'))
        new = audit()[n:]
        self.assertEqual([r['Action'] for r in new], ['server_role_granted', 'server_role_revoked'])
        self.assertEqual({r['SubjectLabel'] for r in new}, {self.user})
        self.assertTrue(all(r['ActorType'] == 'cli' and r['Actor'].startswith('local:') for r in new), new)

    def test_the_role_is_the_users_not_the_accounts(self):
        tn.cli('server-admin-grant', self.user)
        other = tn.Browser().login(self.st['users']['T1']['2'])
        self.assertNotIn(FORM, other.get(SETTINGS)[2])
        self.assertNotIn('name="dc_upload"', other.get('/?caches')[2])

    def test_the_commands_refuse_what_cannot_be_done_and_change_nothing(self):
        n = len(audit())
        self.assertIn('no user named', tn.cli('server-admin-grant', 'nobody-here'))
        self.assertIn('is not a server administrator', tn.cli('server-admin-revoke', self.user))
        tn.cli('server-admin-grant', self.user)
        self.assertIn('already a server administrator', tn.cli('server-admin-grant', self.user))
        tn.sql_exec("UPDATE phoromatic_users SET AdminLevel=0 WHERE UserName='tn1lvl3'")
        self.addCleanup(tn.sql_exec, "UPDATE phoromatic_users SET AdminLevel=3 WHERE UserName='tn1lvl3'")
        self.assertIn('is disabled', tn.cli('server-admin-grant', 'tn1lvl3'))
        self.assertIn('Usage', tn.cli('server-admin-grant'))
        self.assertEqual(len(audit()) - n, 1, 'only the one real grant is in the audit')

    def test_a_user_who_holds_the_role_and_is_disabled_is_not_one(self):
        tn.cli('server-admin-grant', 'tn1lvl3')
        self.addCleanup(tn.cli, 'server-admin-revoke', 'tn1lvl3')
        b = tn.Browser().login('tn1lvl3')
        self.assertIn(FORM, b.get(SETTINGS)[2])
        tn.sql_exec("UPDATE phoromatic_users SET AdminLevel=0 WHERE UserName='tn1lvl3'")
        self.addCleanup(tn.sql_exec, "UPDATE phoromatic_users SET AdminLevel=3 WHERE UserName='tn1lvl3'")
        self.assertNotIn(FORM, b.get(SETTINGS)[2])
        self.assertIn('login_form', b.get('/?main')[2])

    def test_a_tenant_administrator_is_bounced_from_the_root_pages(self):
        b = tn.Browser().login(self.user)
        tn.cli('server-admin-grant', self.user)       # even with the role: the root pages are root's
        for page in ('/?admin', '/?admin_config', '/?admin_data'):
            body = b.get(page)[2]
            self.assertNotIn('Phoromatic Server Administration', body, page)
            self.assertNotIn('update_phoromatic_path', body, page)


class ServerAuditLogLines(unittest.TestCase):
    def lines(self):
        path = tn.php_boot("echo phoromatic_server_audit::mirror_path();").strip()
        return int(tn.srv_sh("grep -c . '%s' 2>/dev/null" % path).strip() or 0)

    def test_a_line_is_written_for_a_committed_change_and_none_for_one_that_was_rolled_back(self):
        user = tn.state()['users']['T1']['1']
        n = self.lines()
        tn.cli('server-admin-grant', user)
        self.assertEqual(self.lines(), n + 1)
        tn.cli('server-admin-revoke', user)
        self.assertEqual(self.lines(), n + 2)
        tn.sql_exec("CREATE TRIGGER qc_refuse_role_audit BEFORE INSERT ON phoromatic_server_audit WHEN NEW.Action='server_role_granted' BEGIN SELECT RAISE(ABORT, 'qc refuses'); END")
        try:
            out = tn.cli('server-admin-grant', user)
        finally:
            tn.sql_exec('DROP TRIGGER qc_refuse_role_audit')
        self.assertIn('nothing was changed', out)
        self.assertEqual(self.lines(), n + 2, 'a refused change leaves no line')
        self.assertEqual(tn.sql("SELECT COUNT(*) AS n FROM phoromatic_server_roles")[0]['n'], 0)

    def test_a_change_that_fails_after_its_audit_row_leaves_no_line(self):
        # the row is written inside the transaction; a later failure rolls it back, and the line that waited for the commit is dropped
        n = self.lines()
        before = tn.phone_home_policy()
        tn.sql_exec("CREATE TRIGGER qc_refuse_admin_audit BEFORE INSERT ON phoromatic_admin_audit WHEN NEW.Action='client_phone_home_set' BEGIN SELECT RAISE(ABORT, 'qc refuses'); END")
        try:
            tn.cli('phoromatic-client-phone-home', 'client_choice' if before != 'client_choice' else 'forbidden')
        finally:
            tn.sql_exec('DROP TRIGGER qc_refuse_admin_audit')
        self.assertEqual(tn.phone_home_policy(), before)
        self.assertEqual(self.lines(), n)


class ServerAuditIsNotATenantsToRead(unittest.TestCase):
    def test_a_tenant_never_sees_server_audit_rows(self):
        # N30: a canary in a server audit row about T2 turns up on no page of T1 at any level (the crawl asks every page; here the audit-flavoured ones)
        tn.sql_exec("INSERT INTO phoromatic_server_audit (EventTime, ActorType, Actor, Action, TenantID, Detail) VALUES ('2026-11-01 10:00:00', 'cli', 'CANARY_T2_actor', 'server_setting_changed', '%s', 'CANARY_T2_server_audit')" % tn.state()['T2']['aid'])
        b = tn.logins('T1')
        for lvl in tn.LEVELS:
            for page in ('/?main', '/?account_activity', '/?settings', '/?users', '/?systems', '/?admin', '/?server_audit', '/?serveraudit'):
                self.assertNotIn('CANARY_T2_server_audit', b[lvl].get(page)[2], (lvl, page))
                self.assertNotIn('CANARY_T2_actor', b[lvl].get(page)[2], (lvl, page))

    def test_server_audit_rows_cannot_be_changed_or_removed(self):
        rows = audit()
        self.assertTrue(rows)
        first = rows[0]['AuditID']
        for q in ("UPDATE phoromatic_server_audit SET Actor='x' WHERE AuditID=%d" % first, 'DELETE FROM phoromatic_server_audit WHERE AuditID=%d' % first,
                  "INSERT OR REPLACE INTO phoromatic_server_audit (AuditID, EventTime, ActorType, Actor, Action) VALUES (%d, 'x', 'cli', 'x', 'migration')" % first):
            with self.assertRaises(AssertionError, msg=q) as cm:
                tn.sql_exec(q)
            self.assertIn('append-only', str(cm.exception))
        self.assertEqual(audit(), rows)

    def test_a_server_wide_setting_change_is_in_the_server_audit_with_who_made_it(self):
        n = len(audit())
        before = tn.phone_home_policy()
        self.addCleanup(tn.cli, 'phoromatic-client-phone-home', before)
        tn.cli('phoromatic-client-phone-home', 'client_choice' if before != 'client_choice' else 'forbidden')
        tn.cli('phoromatic-v2-allow-http', 'on')
        self.addCleanup(tn.cli, 'phoromatic-v2-allow-http', 'off')
        rows = audit()[n:]
        self.assertEqual([r['Action'] for r in rows], ['server_setting_changed'] * 2)
        details = [json.loads(r['Detail']) for r in rows]
        self.assertEqual([d['change'] for d in details], ['client_phone_home_set', 'v2_allow_http_set'])
        self.assertEqual(details[0]['before'], before)
        self.assertTrue(all(r['ActorType'] == 'cli' and r['Actor'].startswith('local:') and r['TenantID'] is None for r in rows), rows)
        # and the account's own log still has its row (the settings page of an account shows it)
        self.assertTrue(tn.sql("SELECT 1 FROM phoromatic_admin_audit WHERE Action='client_phone_home_set' ORDER BY AuditID DESC LIMIT 1"))

    def test_a_setting_change_by_a_web_server_administrator_is_attributed_to_the_user(self):
        user = tn.state()['users']['T1']['1']
        tn.cli('server-admin-grant', user)
        self.addCleanup(tn.cli, 'server-admin-revoke', user)
        b = tn.Browser().login(user)
        n = len(audit())
        before = tn.phone_home_policy()
        self.addCleanup(tn.cli, 'phoromatic-client-phone-home', before)
        b.post(SETTINGS, {'client_phone_home': 'client_choice' if before != 'client_choice' else 'forbidden', 'token_submit': b.token(SETTINGS)})
        rows = audit()[n:]
        self.assertEqual(len(rows), 1, rows)
        self.assertEqual((rows[0]['ActorType'], rows[0]['Actor']), ('user', user))
        self.assertEqual(json.loads(rows[0]['Detail'])['from_account'], tn.state()['T1']['aid'])

    def test_a_failed_server_audit_write_stops_the_setting_change(self):
        n = len(audit())
        before = tn.phone_home_policy()
        tn.sql_exec("CREATE TRIGGER qc_refuse_server_audit BEFORE INSERT ON phoromatic_server_audit WHEN NEW.Action='server_setting_changed' BEGIN SELECT RAISE(ABORT, 'qc refuses'); END")
        try:
            out = tn.cli('phoromatic-client-phone-home', 'client_choice' if before != 'client_choice' else 'forbidden')
        finally:
            tn.sql_exec('DROP TRIGGER qc_refuse_server_audit')
        self.assertEqual(tn.phone_home_policy(), before, out)
        self.assertEqual(len(audit()), n)


if __name__ == '__main__':
    unittest.main()
