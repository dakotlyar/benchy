"""CSRF on the settings account / environment / user forms and on the root admin pages ?admin and ?admin_config (Benchy 0.9.1, L2): each
form needs the session's token and a same-origin request; without them nothing is saved. (The system page and the bench pages were
covered earlier.) Run through tests/private-repo/run.sh (part of http/all).
"""
import re
import unittest
import uuid

from test_private_repo_http import AID, sql
from test_private_repo_tokens import sql_exec
from test_private_repo_web import Browser, WebBase, make_user

EVIL = {'Origin': 'http://evil.example'}


def tok(browser, path):
    m = re.search(r'name="token_submit" value="([0-9a-f]+)"', browser.get(path)[2])
    assert m, 'no token on ' + path
    return m.group(1)


class TestSettingsFormsCsrf(WebBase):
    def check(self, fields, observe, changed_to, reset):
        """a POST without the token, with a wrong token and cross-origin changes nothing; with the token it saves"""
        reset()
        before = observe()
        for label, data, headers in (('no token', fields, None), ('wrong token', dict(fields, token_submit='0' * 40), None),
                                     ('cross-origin', dict(fields, token_submit=tok(self.admin, '/?settings')), EVIL)):
            status, _, body = self.admin.post('/?settings', data, headers=headers)
            self.assertEqual(observe(), before, label)
            self.assertIn('nothing was changed', body, label)
        self.admin.post('/?settings', dict(fields, token_submit=tok(self.admin, '/?settings')))
        self.assertEqual(observe(), changed_to)
        reset()

    def test_user_settings_form(self):
        uid = sql("SELECT UserID FROM phoromatic_users WHERE UserName='%s'" % __import__('os').environ['QC_USER'])[0]['UserID']
        observe = lambda: sql("SELECT NotifyOnWarnings AS v FROM phoromatic_user_settings WHERE UserID='%s'" % uid)[0]['v']
        reset = lambda: sql_exec("UPDATE phoromatic_user_settings SET NotifyOnWarnings=0 WHERE UserID='%s'" % uid)
        self.admin.get('/?settings')
        self.check({'user_settings_update': '1', 'NotifyOnWarnings': 'yes'}, observe, 1, reset)

    def test_account_settings_form(self):
        observe = lambda: sql("SELECT PowerOffWhenDone AS v FROM phoromatic_account_settings WHERE AccountID='%s'" % AID)[0]['v']
        reset = lambda: sql_exec("UPDATE phoromatic_account_settings SET PowerOffWhenDone=0 WHERE AccountID='%s'" % AID)
        self.admin.get('/?settings')
        self.check({'account_settings_update': '1', 'PowerOffWhenDone': 'yes'}, observe, 1, reset)

    def test_environment_variable_form(self):
        observe = lambda: sql("SELECT GlobalEnvironmentVariables AS v FROM phoromatic_account_settings WHERE AccountID='%s'" % AID)[0]['v']
        reset = lambda: sql_exec("UPDATE phoromatic_account_settings SET GlobalEnvironmentVariables='QC=1' WHERE AccountID='%s'" % AID)
        self.admin.get('/?settings')
        self.check({'env_var_update': '1'}, observe, '', reset)

    def test_every_settings_form_carries_the_token(self):
        page = self.admin.get('/?settings')[2]
        forms = re.findall(r'<form [^>]*method="post"[^>]*>(.*?)</form>', page, re.S)
        self.assertGreaterEqual(len(forms), 5)
        for f in forms:
            self.assertIn('name="token_submit"', f)


class TestRootAdminCsrf(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.admin = Browser().login(__import__('os').environ['QC_USER'], __import__('os').environ['QC_PASS'])
        cls.root_name = 'qcroot' + uuid.uuid4().hex[:6]
        cls.victim = 'qcvict' + uuid.uuid4().hex[:6]
        make_user(cls.admin, cls.root_name, 10)
        make_user(cls.admin, cls.victim, 10)
        sql_exec("UPDATE phoromatic_users SET AdminLevel=-40 WHERE UserName='%s'" % cls.root_name)
        cls.root = Browser().login(cls.root_name, 'qcpass123')

    @classmethod
    def tearDownClass(cls):
        sql_exec("UPDATE phoromatic_users SET AdminLevel=10 WHERE UserName='%s'" % cls.victim)

    def level(self):
        return int(sql("SELECT AdminLevel AS v FROM phoromatic_users WHERE UserName='%s'" % self.victim)[0]['v'])

    def test_admin_disable_user_needs_the_token(self):
        sql_exec("UPDATE phoromatic_users SET AdminLevel=10 WHERE UserName='%s'" % self.victim)
        fields = {'disable_user': self.victim}
        for label, data, headers in (('no token', fields, None), ('wrong token', dict(fields, token_submit='0' * 40), None),
                                     ('cross-origin', dict(fields, token_submit=tok(self.root, '/?admin')), EVIL)):
            body = self.root.post('/?admin', data, headers=headers)[2]
            self.assertEqual(self.level(), 10, label)
            self.assertIn('nothing was changed', body, label)
        self.root.post('/?admin', dict(fields, token_submit=tok(self.root, '/?admin')))
        self.assertEqual(self.level(), -10)

    def test_admin_config_support_email_needs_the_token(self):
        value = 'csrf-%s@example.com' % uuid.uuid4().hex[:6]
        fields = {'new_admin_support_email': value}
        for label, data, headers in (('no token', fields, None), ('wrong token', dict(fields, token_submit='0' * 40), None),
                                     ('cross-origin', dict(fields, token_submit=tok(self.root, '/?admin_config')), EVIL)):
            body = self.root.post('/?admin_config', data, headers=headers)[2]
            self.assertNotIn(value, self.root.get('/?admin_config')[2], label)
            self.assertIn('nothing was changed', body, label)
        self.root.post('/?admin_config', dict(fields, token_submit=tok(self.root, '/?admin_config')))
        self.assertIn(value, self.root.get('/?admin_config')[2])

    def test_every_admin_form_carries_the_token(self):
        for path in ('/?admin', '/?admin_config'):
            page = self.root.get(path)[2]
            forms = re.findall(r'<form [^>]*method="post"[^>]*>(.*?)</form>', page, re.S)
            self.assertGreaterEqual(len(forms), 4, path)
            for f in forms:
                self.assertIn('name="token_submit"', f, path)


if __name__ == '__main__':
    unittest.main()
