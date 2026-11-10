"""The "Clients May Phone Home" toggle on the Settings page (server-wide client_phone_home policy).

Server administrators only (a user with the server role, re-read from the database, so a user who lost it or is level 2 and below never), POST + CSRF token + same-origin, every change audited in
phoromatic_admin_audit first and refused if that fails, and the next phoromatic.php reply carries the new policy.
Run through tests/private-repo/run.sh (http).
"""
import json
import re
import subprocess
import unittest
import uuid

from test_private_repo_http import SRV, raw_post, sql
from test_private_repo_tokens import sql_exec
from test_private_repo_web import Browser, USER, PASS, make_user
from single_account import hide_other_accounts, restore_other_accounts

PAGE = '/?settings'
CHECKED = r'name="client_phone_home" value="%s" checked="checked"'


def cli(*args):
    return subprocess.run(['docker', 'exec', SRV, 'sh', '/bench/benchy', 'phoromatic-client-phone-home'] + list(args), capture_output=True, text=True).stdout


def policy():
    m = re.search(r'Client phone-home policy: (\w+)', cli())
    return m.group(1) if m else None


def reply_policy():
    body = raw_post('/phoromatic.php', {'r': 'ping', 'aid': 'NOSUCHACCT', 'msi': 'qc-phone-home', 'pts_core': '10860'})
    return json.loads(body[body.index('{"phoromatic"'):])['phoromatic']['policy']['no_phone_home']


def audit_rows():
    return sql("SELECT * FROM phoromatic_admin_audit WHERE Action='client_phone_home_set' ORDER BY AuditID")


class PhoneHomeToggle(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        hide_other_accounts()
        cls.admin = Browser().login(USER, PASS)
        suffix = uuid.uuid4().hex[:6]
        cls.names = {}
        for level in (1, 2, 3, 10):
            cls.names[level] = 'phq%d%s' % (level, suffix)
            make_user(cls.admin, cls.names[level], 2 if level == 1 else level)   # the Users page makes levels 2 and below; a second level-1 user is set directly
        sql_exec("UPDATE phoromatic_users SET AdminLevel=1 WHERE UserName='%s'" % cls.names[1])
        # a level-1 user is a server administrator only with the role (the account's first user has it; this one is the operator's choice)
        subprocess.run(['docker', 'exec', SRV, 'sh', '/bench/benchy', 'server-admin-grant', cls.names[1]], capture_output=True, text=True)
        cli('forbidden')

    @classmethod
    def tearDownClass(cls):
        sql_exec('DROP TRIGGER IF EXISTS qc_refuse_phone_home_audit')
        cli('forbidden')
        restore_other_accounts()

    def as_user(self, level):
        return Browser().login(self.names[level], 'qcpass123')

    def token(self, b):
        m = re.search(r'name="token_submit" value="([0-9a-f]+)"', b.get(PAGE)[2])
        return m.group(1) if m else 'x'

    def toggle(self, b, value, token=None, headers=None):
        data = {'client_phone_home': value}
        if token != '':
            data['token_submit'] = self.token(b) if token is None else token
        return b.post(PAGE, data, headers=headers)[2]

    def test_shows_the_current_value(self):
        cli('forbidden')
        self.assertRegex(self.admin.get(PAGE)[2], CHECKED % 'forbidden')
        cli('client_choice')
        self.assertRegex(self.admin.get(PAGE)[2], CHECKED % 'client_choice')
        cli('forbidden')

    def test_level_1_changes_it_audited_and_the_next_reply_follows(self):
        cli('forbidden')
        b = self.as_user(1)
        before = len(audit_rows())
        body = self.toggle(b, 'client_choice')
        self.assertIn('Saved.', body)
        self.assertEqual(policy(), 'client_choice')
        self.assertRegex(body, CHECKED % 'client_choice')
        self.assertFalse(reply_policy())
        rows = audit_rows()
        self.assertEqual(len(rows), before + 1)
        self.assertEqual(rows[-1]['Actor'], self.names[1])
        self.assertEqual(rows[-1]['ActorType'], 'user')
        self.assertEqual(json.loads(rows[-1]['Detail']), {'before': 'forbidden', 'after': 'client_choice'})
        self.assertIn('Saved.', self.toggle(self.admin, 'forbidden'))
        self.assertEqual(policy(), 'forbidden')
        self.assertTrue(reply_policy())
        self.assertEqual(json.loads(audit_rows()[-1]['Detail']), {'before': 'client_choice', 'after': 'forbidden'})

    def test_level_2_3_and_viewer_cannot_see_or_change_it(self):
        cli('forbidden')
        before = len(audit_rows())
        for level in (2, 3, 10):
            with self.subTest(level=level):
                b = self.as_user(level)
                self.assertNotIn('name="client_phone_home"', b.get(PAGE)[2])
                self.toggle(b, 'client_choice')
                self.assertEqual(policy(), 'forbidden')
        self.assertEqual(len(audit_rows()), before)

    def test_a_demoted_admin_is_refused_at_once(self):
        cli('forbidden')
        b = self.as_user(1)
        tok = self.token(b)
        sql_exec("UPDATE phoromatic_users SET AdminLevel=3 WHERE UserName='%s'" % self.names[1])
        self.addCleanup(sql_exec, "UPDATE phoromatic_users SET AdminLevel=1 WHERE UserName='%s'" % self.names[1])
        self.toggle(b, 'client_choice', token=tok)
        self.assertEqual(policy(), 'forbidden')

    def test_missing_wrong_token_or_foreign_origin_change_nothing(self):
        cli('forbidden')
        before = len(audit_rows())
        tok = self.token(self.admin)
        for label, kw in (('no token', {'token': ''}), ('wrong token', {'token': '0' * len(tok)}),
                          ('other origin', {'token': tok, 'headers': {'Origin': 'http://evil.example'}})):
            with self.subTest(label):
                body = self.toggle(self.admin, 'client_choice', **kw)
                self.assertIn('the phone-home policy was not changed', body)
                self.assertEqual(policy(), 'forbidden')
        self.assertEqual(len(audit_rows()), before)
        self.assertEqual(self.admin.get(PAGE + '&client_phone_home=client_choice')[0], 200)
        self.assertEqual(policy(), 'forbidden', 'a GET changed it')

    def test_an_unknown_value_or_an_array_is_refused(self):
        cli('forbidden')
        before = len(audit_rows())
        body = self.toggle(self.admin, '<script>x</script>')
        self.assertIn('Unknown policy', body)
        self.assertNotIn('<script>x</script>', body)
        b = self.admin
        b.post(PAGE, {'client_phone_home[]': 'client_choice', 'token_submit': self.token(b)})
        self.assertEqual(policy(), 'forbidden')
        self.assertEqual(len(audit_rows()), before)

    def test_fails_closed_when_the_audit_row_cannot_be_written(self):
        cli('forbidden')
        self.assertEqual(sql_exec("CREATE TRIGGER qc_refuse_phone_home_audit BEFORE INSERT ON phoromatic_admin_audit "
                                  "WHEN NEW.Action='client_phone_home_set' BEGIN SELECT RAISE(ABORT, 'qc refuses'); END"), 'ok')
        self.addCleanup(sql_exec, 'DROP TRIGGER IF EXISTS qc_refuse_phone_home_audit')
        body = self.toggle(self.admin, 'client_choice')
        self.assertIn('could not be recorded in the audit log', body)
        self.assertEqual(policy(), 'forbidden')
        self.assertTrue(reply_policy())
        out = cli('client_choice')
        self.assertIn('could not be recorded in the audit log', out)
        self.assertEqual(policy(), 'forbidden')


if __name__ == '__main__':
    unittest.main()
