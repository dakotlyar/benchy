"""Findings from the review of T0: a user name that SQLite reads as a column, text in the root page's log box, levels that are not whole numbers,
and root's own route to the server-wide settings."""
import re
import unittest

import tn

OTHERS = "SELECT UserName, Password, LastLogin, LastIP, AdminLevel FROM phoromatic_users WHERE UserName <> '%s' ORDER BY UserName"


class ColumnLikeUserName(unittest.TestCase):
    """WHERE UserName = "UserName" is true for every row (a double-quoted value is a column name first), so a user with that name could overwrite every
    password on the server by changing their own, and rewrote every LastLogin by logging in."""

    def setUp(self):
        self.admin = tn.Browser().login(tn.state()['users']['T1']['1'])
        self.name = 'tnquirk%s' % tn.uuid.uuid4().hex[:4]
        tn.make_user(self.admin, self.name, 10)
        tn.sql_exec("UPDATE phoromatic_users SET UserName='UserName' WHERE UserName='%s'" % self.name)
        self.addCleanup(tn.sql_exec, "UPDATE phoromatic_users SET UserName='%s' WHERE UserName='UserName'" % self.name)

    def test_logging_in_touches_only_that_users_row(self):
        tn.sql_exec("UPDATE phoromatic_users SET LastLogin='2001-01-01 00:00:00' WHERE UserName <> 'UserName'")
        before = tn.sql(OTHERS % 'UserName')
        tn.Browser().login('UserName')
        self.assertEqual(tn.sql(OTHERS % 'UserName'), before)
        self.assertNotEqual(tn.sql("SELECT LastLogin AS l FROM phoromatic_users WHERE UserName='UserName'")[0]['l'], '2001-01-01 00:00:00')

    def test_changing_the_password_changes_only_that_users_password(self):
        before = tn.sql(OTHERS % 'UserName')
        b = tn.Browser().login('UserName')
        body = b.post('/?password', {'old_password': tn.PW, 'register_password': 'tnnewpass99', 'register_password_confirm': 'tnnewpass99'})[2]
        self.assertIn('Password Updated', body)
        self.assertEqual(tn.sql(OTHERS % 'UserName'), before)
        tn.Browser().login(tn.state()['users']['T1']['1'])      # everybody else can still log in
        tn.Browser().login('UserName', 'tnnewpass99')

    def test_a_column_name_is_not_a_free_user_name(self):
        for n, name in enumerate(('username', 'AccountID', 'Password', 'adminlevel')):
            tok = self.admin.token('/?users')
            self.admin.post('/?users', {'username': name, 'password': tn.PW, 'confirm_password': tn.PW, 'email': 'q%d@example.com' % n, 'admin_level': '10', 'token_submit': tok})
            self.assertEqual(tn.sql("SELECT 1 FROM phoromatic_users WHERE lower(UserName)='%s' AND UserName <> 'UserName'" % name.lower()), [], name)


class RootLogBox(unittest.TestCase):
    def test_text_in_the_server_log_is_shown_as_text(self):
        tn.srv_sh("echo '[404] GET /x?y=</textarea><script id=tnlog>1</script>' >> /tmp/tn-server.log")
        page = tn.Browser().login_root().get('/?admin')[2]
        self.assertNotIn('<script id=tnlog>', page)
        self.assertIn('&lt;script id=tnlog&gt;', page)


class LevelsAreWholeNumbers(unittest.TestCase):
    def level(self, user_name):
        row = tn.sql("SELECT UserID, AccountID FROM phoromatic_users WHERE UserName='%s'" % user_name)[0]
        code = ("phoromatic_server::prepare_database(); $_SESSION = array('UserID' => '%s', 'AccountID' => '%s'); echo 'LEVEL=', var_export(phoromatic_auth::level('%s'), true);"
                % (row['UserID'], row['AccountID'], row['AccountID']))
        return tn.php_boot(code).split('LEVEL=')[-1].strip()

    def test_level_is_read_only_when_it_is_exactly_1_2_3_or_10(self):
        user = tn.state()['users']['T1']['10']
        old = tn.sql("SELECT AdminLevel AS l FROM phoromatic_users WHERE UserName='%s'" % user)[0]['l']
        self.addCleanup(tn.sql_exec, "UPDATE phoromatic_users SET AdminLevel=%s WHERE UserName='%s'" % (old, user))
        for value, expect in (('10', '10'), ('1', '1'), ('1.5', '0'), ("'1x'", '0'), ('2.0', '2'), ('0', '0'), ('-1', '0'), ('7', '0')):
            tn.sql_exec("UPDATE phoromatic_users SET AdminLevel=%s WHERE UserName='%s'" % (value, user))
            self.assertEqual(self.level(user), expect, value)

    def test_a_user_with_an_odd_level_cannot_log_in(self):
        name = 'tnodd%s' % tn.uuid.uuid4().hex[:4]
        admin = tn.Browser().login(tn.state()['users']['T1']['1'])
        tn.make_user(admin, name, 10)
        tn.sql_exec("UPDATE phoromatic_users SET AdminLevel=1.5 WHERE UserName='%s'" % name)
        b = tn.Browser()
        b.get('/')
        self.assertNotIn('Log-In Successful', b.post('/?login', {'username': name, 'password': tn.PW})[2])

    def test_the_users_page_makes_only_the_levels_it_offers(self):
        st = tn.state()['users']['T1']
        before = tn.sql("SELECT COUNT(*) AS n FROM phoromatic_users")[0]['n']
        power = tn.Browser().login(st['3'])
        n = 0
        for level in ('1', '1.5', '2', '2.0', '1e0', '0', '-1', '3', '03', ' 3', 'x'):
            n += 1
            tok = power.token('/?settings')
            power.post('/?users', {'username': 'tnmk%d%s' % (n, tn.uuid.uuid4().hex[:3]), 'password': tn.PW, 'confirm_password': tn.PW, 'email': 'm@example.com', 'admin_level': level, 'token_submit': tok})
        made = tn.sql("SELECT AdminLevel AS l FROM phoromatic_users WHERE UserName LIKE 'tnmk%'")
        self.assertEqual([m['l'] for m in made if m['l'] not in (10,)], [])
        # a group administrator may set the offered levels only
        admin = tn.Browser().login(st['1'])
        victim = tn.sql("SELECT UserID AS u FROM phoromatic_users WHERE UserName='%s'" % st['10'])[0]['u']
        for level in ('1', '1.5', 'x'):
            admin.post('/?users', {'update_user_levels': str(victim), 'admin_level_%s' % victim: level, 'token_submit': admin.token('/?users')})
            self.assertEqual(tn.sql("SELECT AdminLevel AS l FROM phoromatic_users WHERE UserName='%s'" % st['10'])[0]['l'], 10, level)


class RootReachesTheServerSettings(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.root = tn.Browser().login_root()

    def test_root_has_the_forms_on_admin_config_and_the_cache_page(self):
        page = self.root.get('/?admin_config')[2]
        for marker in ('HTTPS Certificate', 'name="client_phone_home"', 'name="v2_settings"'):
            self.assertIn(marker, page)
        self.assertIn('name="dc_upload"', self.root.get('/?caches')[2])

    def test_root_can_change_them_with_the_token_and_not_without(self):
        try:
            self.root.post('/?admin_config', {'client_phone_home': 'client_choice'})
            self.assertEqual(tn.phone_home_policy(), 'forbidden')
            tok = self.root.token('/?admin_config')
            self.root.post('/?admin_config', {'client_phone_home': 'client_choice', 'token_submit': tok})
            self.assertEqual(tn.phone_home_policy(), 'client_choice')
            self.root.post('/?admin_config', {'v2_settings': '1', 'v2_allow_http': '1', 'v2_mirrors': 'https://m.example/f', 'token_submit': tok})
            self.assertEqual(tn.v2_allow_http(), 'on')
            self.assertIn('https://m.example/f', self.root.get('/?admin_config')[2])
        finally:
            tn.cli('phoromatic-client-phone-home', 'forbidden')
            tn.cli('phoromatic-v2-allow-http', 'off')
            self.root.post('/?admin_config', {'v2_settings': '1', 'v2_mirrors': '', 'token_submit': self.root.token('/?admin_config')})

    def test_root_can_turn_on_https_and_cache_uploads_work(self):
        name = 'tn-root-%s.txt' % tn.uuid.uuid4().hex[:5]
        try:
            self.assertIn('File uploaded', self.root.multipart('/?caches', {'dc_submit': 'Upload', 'token_submit': self.root.token('/?admin_config')}, {'dc_upload': (name, b'x')})[2])
            self.assertIn(name, tn.cache_files())
            self.root.post('/?admin_config', {'https_action': 'bootstrap', 'token_submit': self.root.token('/?admin_config')})
            self.assertEqual(tn.tls_mode_from_root(), 'internal')
        finally:
            tn.srv_sh("rm -f '/var/cache/benchy/download-cache/%s'" % name)
            tn.cli('server-https-off')
        self.assertEqual(tn.tls_mode_from_root(), 'off')


if __name__ == '__main__':
    unittest.main()
