"""CSRF on the settings account / environment / user forms and on the root admin pages ?admin and ?admin_config (Benchy 0.9.1, L2): each
form needs the session's token and a same-origin request; without them nothing is saved. (The system page and the bench pages were
covered earlier.) Run through tests/private-repo/run.sh (part of http/all).
"""
import re
import unittest
import uuid

from test_private_repo_http import AID, sql, srv_sh
from test_private_repo_tokens import sql_exec
from test_private_repo_web import Browser, WebBase, make_user

EVIL = {'Origin': 'http://evil.example'}


def tok(browser, path):
    status, _, body = browser.get(path)
    m = re.search(r'name="token_submit" value="([0-9a-f]+)"', body)
    assert m, 'no token on %s (status %s): %s' % (path, status, re.sub(r'\s+', ' ', re.sub(r'<[^>]+>', ' ', body))[-600:])
    return m.group(1)


def restore_row(table, where):
    """Snapshot one row now; the returned function writes it back. A saved settings form sets EVERY setting of its section (unticked
    boxes become 0), which would leave later phases (the e2e clients) with changed account defaults."""
    row = sql("SELECT * FROM %s WHERE %s" % (table, where))[0]

    def q(v):
        return 'NULL' if v is None else ("'%s'" % v.replace("'", "''") if isinstance(v, str) else str(v))

    def restore():
        sql_exec("UPDATE %s SET %s WHERE %s" % (table, ', '.join('%s=%s' % (k, q(v)) for k, v in row.items()), where))
    return restore


class TestSettingsFormsCsrf(WebBase):
    def check(self, fields, observe, changed_to, reset):
        """a POST without the token, with a wrong token and cross-origin changes nothing; with the token it saves"""
        self.addCleanup(reset)
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
        self.addCleanup(restore_row('phoromatic_user_settings', "UserID='%s'" % uid))
        self.check({'user_settings_update': '1', 'NotifyOnWarnings': 'yes'}, observe, 1, reset)

    def test_account_settings_form(self):
        observe = lambda: sql("SELECT PowerOffWhenDone AS v FROM phoromatic_account_settings WHERE AccountID='%s'" % AID)[0]['v']
        reset = lambda: sql_exec("UPDATE phoromatic_account_settings SET PowerOffWhenDone=0 WHERE AccountID='%s'" % AID)
        self.admin.get('/?settings')
        self.addCleanup(restore_row('phoromatic_account_settings', "AccountID='%s'" % AID))
        self.check({'account_settings_update': '1', 'PowerOffWhenDone': 'yes'}, observe, 1, reset)

    def test_environment_variable_form(self):
        observe = lambda: sql("SELECT GlobalEnvironmentVariables AS v FROM phoromatic_account_settings WHERE AccountID='%s'" % AID)[0]['v']
        reset = lambda: sql_exec("UPDATE phoromatic_account_settings SET GlobalEnvironmentVariables='QC=1' WHERE AccountID='%s'" % AID)
        self.admin.get('/?settings')
        self.addCleanup(restore_row('phoromatic_account_settings', "AccountID='%s'" % AID))
        self.check({'env_var_update': '1'}, observe, '', reset)

    def test_every_settings_form_carries_the_token(self):
        page = self.admin.get('/?settings')[2]
        forms = re.findall(r'<form [^>]*action="\?settings"[^>]*>(.*?)</form>', page, re.S)
        self.assertGreaterEqual(len(forms), 5)
        for f in forms:
            self.assertIn('name="token_submit"', f)


class TestRootAdminCsrf(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.admin = Browser().login(__import__('os').environ['QC_USER'], __import__('os').environ['QC_PASS'])
        cls.victim = 'qcvict' + uuid.uuid4().hex[:6]
        make_user(cls.admin, cls.victim, 10)
        # the root admin signs in as "rootadmin" with the password stored by `phoromatic.set-root-admin-password` (sha256 of 'PTS' + it)
        srv_sh("cd /bench/pts-core/phoromatic/public_html && php -r 'define(\"PHOROMATIC_SERVER\", true); define(\"PTS_MODE\", \"WEB_CLIENT\"); define(\"PTS_AUTO_LOAD_OBJECTS\", true); include(\"../../pts-core.php\"); pts_core::init(); phoromatic_server::prepare_database(); phoromatic_server::save_setting(\"root_admin_pw\", hash(\"sha256\", \"PTSqcrootpw1\"));'")
        cls.root = Browser()
        cls.root.get('/')
        cls.root.post('/?login', {'username': 'rootadmin', 'password': 'qcrootpw1'})
        assert 'Root' in cls.root.get('/?admin')[2] or 'Disable Account' in cls.root.get('/?admin')[2]

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
