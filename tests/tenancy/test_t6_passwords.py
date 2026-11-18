"""T6: a password is kept as a password_hash() (schema 62), and since 0.10.0 (the legacy hash drop, schema 66) only as that: the legacy sha256 the releases before read is '!'.
This server is past the drop, so the tests here make the states a rollback leaves behind by hand: a log-in of a user (or the root admin) who still has only the legacy hash
gives them a modern hash and removes the legacy one; every place that sets a password writes the modern hash alone; a password changed by an older release (it writes only the
legacy hash) wins over the modern hash it left stale; no hash is shown, logged or audited; the failure count and lockout are what they were. The two-hash writes of
0.9.14 are held by rollback_t6.sh, which runs this checkout with the drop held back. test_drop_passwords.py holds the drop itself.

Two accounts (T1 and T2). Every user these tests make is removed afterwards; the root password is put back. Wrong passwords are few: each one counts against the address's
log-in lockout (ten in the window), and test_zz_login runs after this file."""
import base64
import hashlib
import json
import re
import unittest
import uuid

import tn
from t4 import *

NEW = 'after-rollback-9'


def sha(text):
    return hashlib.sha256(text.encode()).hexdigest()


def fp(legacy):
    return sha('legacy-fingerprint:' + legacy)


def row(name):
    return tn.sql("SELECT u.UserID, u.AccountID, u.Password, u.PasswordHash, u.PasswordLegacyFp, u.PasswordScheme, a.Salt FROM phoromatic_users u JOIN phoromatic_accounts a ON a.AccountID = u.AccountID WHERE u.UserName='%s'" % name)[0]


def try_login(name, password=tn.PW):
    b = tn.Browser()
    b.get('/')
    return 'Log-In Successful' in b.post('/?login', {'username': name, 'password': password})[2]


def try_root(password=tn.ROOT_PW):
    b = tn.Browser()
    b.get('/')
    b.post('/?login', {'username': 'rootadmin', 'password': password})
    return 'Phoromatic Server Administration' in b.get('/?admin')[2]


def php_str(text):
    """A PHP expression for any string (NUL and `$` included)."""
    return "base64_decode('%s')" % base64.b64encode(text.encode()).decode()


def chk(name, password):
    """The password check a log-in makes, run in the server's own classes with no web request: it does what a log-in does to the row, and a wrong password is not counted against the
    address's lockout (ten failures lock the address out for five minutes, and every test comes from the same one). Opening the database sweeps a legacy value, as a web request does."""
    code = ("phoromatic_server::prepare_database(); $r = phoromatic_server::$db->querySingle(\"SELECT UserID, Password, AccountID, PasswordHash, PasswordLegacyFp, PasswordScheme FROM phoromatic_users WHERE UserName = '%s'\", true);"
            " $s = phoromatic_server::$db->querySingle(\"SELECT Salt FROM phoromatic_accounts WHERE AccountID = '\" . $r['AccountID'] . \"'\"); echo phoromatic_passwords::check_user($r, $s, %s) ? 'yes' : 'no';") % (name, php_str(password))
    out = tn.php_boot(code).strip()
    assert out in ('yes', 'no'), out
    return out == 'yes'


def chk_root(password):
    out = tn.php_boot("phoromatic_server::prepare_database(); echo phoromatic_passwords::check_root(%s) ? 'yes' : 'no';" % php_str(password)).strip()
    assert out in ('yes', 'no'), out
    return out == 'yes'


def root_setting(key):
    return tn.php_boot("phoromatic_server::init_settings_store(); echo (string)phoromatic_server::read_setting('%s');" % key).strip()


def root_put(key, value):
    tn.php_boot("phoromatic_server::init_settings_store(); phoromatic_server::save_setting('%s', '%s');" % (key, value))


def root_drop(key):
    tn.php_boot("phoromatic_server::init_settings_store(); phoromatic_settings_store::remove_in_file(phoromatic_server::phoromatic_path() . 'phoromatic-settings.pt2so', '%s');" % key)


def set_root_cli(password):
    tn.subprocess.run(['docker', 'exec', '-i', tn.SRV, 'sh', '/bench/benchy', 'phoromatic.set-root-admin-password'], input=password + '\n', capture_output=True, text=True)


class T6(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.st = tn.state()
        cls.t1 = cls.st['T1']['aid']
        cls.admin = tn.Browser().login('tn1admin')
        cls.root = tn.Browser().login_root()
        tn.reset_v1_limits()

    def make(self, level=3, password=tn.PW):
        name = 't6u' + uuid.uuid4().hex[:6]
        uid = tn.make_user(self.admin, name, level)
        self.addCleanup(lambda: tn.sql_exec("DELETE FROM phoromatic_memberships WHERE UserID='%s'; DELETE FROM phoromatic_user_settings WHERE UserID='%s'; DELETE FROM phoromatic_users WHERE UserID='%s'" % (uid, uid, uid)))
        return name, uid

    def both(self, name, password):
        """The modern hash alone (the drop): a password_hash() that verifies this password, the legacy value '!', no fingerprint, no scheme."""
        r = row(name)
        self.assertEqual(r['Password'], '!')
        self.assertRegex(r['PasswordHash'] or '', r'^\$(2y|argon2id)\$')
        self.assertIsNone(r['PasswordLegacyFp'])
        self.assertIsNone(r['PasswordScheme'])
        self.assertEqual(tn.php_boot("echo password_verify(base64_encode(hash('sha256', %s, true)), %s) ? 'yes' : 'no';" % (php_str(password), php_str(r['PasswordHash']))).strip(), 'yes')
        return r

    def as_old_release_made(self, name):
        """The state of a user the release before T6 made (or an older release left after a rollback): only the legacy hash."""
        tn.sql_exec("UPDATE phoromatic_users SET Password = '%s', PasswordHash = NULL, PasswordLegacyFp = NULL, PasswordScheme = NULL WHERE UserName='%s'" % (sha(row(name)['Salt'] + tn.PW), name))


class Setting(T6):
    def test_the_users_page_writes_the_modern_hash_and_the_user_logs_in(self):
        name, uid = self.make()
        self.both(name, tn.PW)
        self.assertTrue(try_login(name))

    def test_the_password_page_writes_the_modern_hash_and_the_old_password_stops_working(self):
        name, uid = self.make()
        b = tn.Browser().login(name)
        before = row(name)
        s, _, body = b.post('/?password', {'old_password': tn.PW, 'register_password': 'changed-pw-1', 'register_password_confirm': 'changed-pw-1', 'token_submit': b.token('/?settings')})
        self.assertIn('Password Updated', body)
        after = self.both(name, 'changed-pw-1')
        self.assertNotEqual(after['PasswordHash'], before['PasswordHash'])
        self.assertTrue(try_login(name, 'changed-pw-1'))
        self.assertFalse(chk(name, tn.PW))

    def test_the_password_page_checks_the_old_password_against_the_modern_hash_too(self):
        name, uid = self.make()
        b = tn.Browser().login(name)
        s, _, body = b.post('/?password', {'old_password': 'not-it-12345', 'register_password': 'changed-pw-2', 'register_password_confirm': 'changed-pw-2', 'token_submit': b.token('/?settings')})
        self.assertNotIn('Password Updated', body)
        self.both(name, tn.PW)

    def test_the_console_reset_writes_the_modern_hash(self):
        name, uid = self.make()
        s, loc, page = act(self.root, 'user_password_reset', token=console_token(self.root), user_id=uid, new_password='console-pw-12', new_password_again='console-pw-12')
        self.assertIn('The password was changed.', page)
        self.both(name, 'console-pw-12')
        self.assertTrue(try_login(name, 'console-pw-12'))

    def test_server_account_create_writes_the_modern_hash_and_so_does_a_console_made_account(self):
        user = 't6c' + uuid.uuid4().hex[:5]
        aid = ('T6' + uuid.uuid4().hex[:4]).upper()
        self.assertTrue(tn.register(user, aid))
        self.addCleanup(lambda: tn.sql_exec("DELETE FROM phoromatic_memberships WHERE AccountID='%s'; DELETE FROM phoromatic_user_settings WHERE AccountID='%s'; DELETE FROM phoromatic_users WHERE UserName='%s'; DELETE FROM phoromatic_account_settings WHERE AccountID='%s'; DELETE FROM phoromatic_accounts WHERE AccountID='%s'" % (aid, aid, user, aid, aid)))
        self.both(user, tn.PW)
        self.assertTrue(try_login(user))

    def test_set_root_admin_password_writes_the_modern_hash_and_login_works(self):
        try:
            set_root_cli('root-new-12345')
            self.assertEqual(root_setting('root_admin_pw'), '!')
            self.assertRegex(root_setting('root_admin_pw_hash'), r'^\$(2y|argon2id)\$')
            self.assertEqual(root_setting('root_admin_pw_fp'), '')
            self.assertEqual(root_setting('root_admin_pw_scheme'), '')
            self.assertTrue(try_root('root-new-12345'))
        finally:
            set_root_cli(tn.ROOT_PW)
        self.assertTrue(try_root())


class LoggingIn(T6):
    def test_a_user_with_only_the_legacy_hash_logs_in_and_gets_a_modern_one_and_loses_the_legacy_one(self):
        name, uid = self.make()
        self.as_old_release_made(name)
        self.assertTrue(try_login(name))
        self.both(name, tn.PW)
        self.assertTrue(try_login(name), 'and the next log-in, now on the modern hash, works')

    def test_a_wrong_password_is_refused_and_changes_nothing(self):
        # (every request that opens the database sweeps a legacy value first, so the row a refused check leaves is the wrapped one the sweep made)
        name, uid = self.make()
        self.as_old_release_made(name)
        self.assertFalse(chk(name, 'wrong-pass-123'))
        r = row(name)
        self.assertEqual((r['Password'], r['PasswordScheme']), ('!', 'legacy-wrapped'))
        self.assertFalse(chk(name, 'wrong-pass-124'))
        self.assertEqual(row(name)['PasswordHash'], r['PasswordHash'], 'a refused log-in writes nothing')

    def test_the_modern_hash_is_the_one_checked_when_the_fingerprint_vouches_for_it(self):
        # the modern hash is replaced by that of another password with the fingerprint left alone: it decides, not the legacy hash
        name, uid = self.make()
        other = tn.php_boot("echo password_hash(base64_encode(hash('sha256', 'only-modern-12', true)), PASSWORD_DEFAULT);").strip()
        tn.sql_exec("UPDATE phoromatic_users SET PasswordHash='%s' WHERE UserName='%s'" % (other, name))
        self.assertTrue(try_login(name, 'only-modern-12'))
        self.assertFalse(chk(name, tn.PW))

    def test_a_password_changed_by_the_release_before_wins_over_the_stale_modern_hash(self):
        # 0.9.13 writes only the legacy hash: the modern hash and its fingerprint are those of the password before (the state is made by hand: a sweep that came first would have wrapped it)
        name, uid = self.make()
        r = row(name)
        tn.sql_exec("UPDATE phoromatic_users SET Password='%s', PasswordLegacyFp='%s' WHERE UserName='%s'" % (sha(r['Salt'] + NEW), fp(sha(r['Salt'] + tn.PW)), name))
        self.assertFalse(chk(name, tn.PW), 'the password before the rollback no longer works')
        self.assertTrue(try_login(name, NEW), 'the one set on the old release does')
        after = self.both(name, NEW)
        self.assertNotEqual(after['PasswordHash'], r['PasswordHash'], 'and the modern hash was made again')
        self.assertTrue(try_login(name, NEW))
        self.assertFalse(chk(name, tn.PW))

    def test_a_refused_log_in_after_the_old_release_changed_the_password_writes_nothing(self):
        name, uid = self.make()
        r = row(name)
        tn.sql_exec("UPDATE phoromatic_users SET Password='%s', PasswordLegacyFp='%s' WHERE UserName='%s'" % (sha(r['Salt'] + NEW), fp(sha(r['Salt'] + tn.PW)), name))
        self.assertFalse(chk(name, tn.PW))
        r = row(name)   # (as the sweep that opening the database made it: wrapped)
        self.assertEqual((r['Password'], r['PasswordScheme']), ('!', 'legacy-wrapped'))
        self.assertFalse(chk(name, tn.PW))
        self.assertEqual(row(name)['PasswordHash'], r['PasswordHash'], 'a refused log-in writes nothing')

    def test_the_rehash_follows_the_cost(self):
        # a modern hash made at a lower cost than PHP's default is made again (password_needs_rehash)
        name, uid = self.make()
        low = tn.php_boot("echo password_hash(base64_encode(hash('sha256', '%s', true)), PASSWORD_BCRYPT, array('cost' => 4));" % tn.PW).strip()
        tn.sql_exec("UPDATE phoromatic_users SET PasswordHash='%s' WHERE UserName='%s'" % (low, name))
        self.assertTrue(try_login(name))
        r = self.both(name, tn.PW)
        self.assertNotEqual(r['PasswordHash'], low)
        self.assertNotIn('$04$', r['PasswordHash'])

    def test_a_long_password_is_all_significant_and_a_nul_byte_does_not_break_it(self):
        name, uid = self.make()
        long_pw = 'x' * 80
        act(self.root, 'user_password_reset', token=console_token(self.root), user_id=uid, new_password=long_pw, new_password_again=long_pw)
        self.assertTrue(try_login(name, long_pw))
        self.assertFalse(chk(name, 'x' * 72 + 'y' * 8), 'bcrypt alone would have let this one in')
        self.assertFalse(chk(name, tn.PW + '\x00'))


class Root(T6):
    def tearDown(self):
        set_root_cli(tn.ROOT_PW)

    def test_the_root_admin_with_only_the_legacy_hash_logs_in_and_gets_a_modern_one(self):
        root_drop('root_admin_pw_hash')
        root_drop('root_admin_pw_fp')
        root_put('root_admin_pw', sha('PTS' + tn.ROOT_PW))   # what an older release left
        self.assertTrue(try_root())
        self.assertEqual(root_setting('root_admin_pw'), '!', 'the legacy value is gone after a log-in')
        self.assertRegex(root_setting('root_admin_pw_hash'), r'^\$(2y|argon2id)\$')
        self.assertEqual(root_setting('root_admin_pw_fp'), '')
        self.assertTrue(try_root())

    def test_a_root_password_set_by_the_release_before_wins_over_the_stale_modern_hash(self):
        stale = root_setting('root_admin_pw_hash')
        root_put('root_admin_pw', sha('PTS' + NEW))   # what 0.9.13's set-root-admin-password writes (the stale hash and the fingerprint of the password before stay)
        root_put('root_admin_pw_fp', fp(sha('PTS' + tn.ROOT_PW)))
        self.assertFalse(chk_root(tn.ROOT_PW), 'the password before does not work')
        self.assertTrue(try_root(NEW))
        self.assertNotEqual(root_setting('root_admin_pw_hash'), stale)
        self.assertEqual(root_setting('root_admin_pw'), '!')
        self.assertEqual(root_setting('root_admin_pw_fp'), '')
        self.assertTrue(try_root(NEW))


class Hygiene(T6):
    def test_no_hash_is_shown_logged_or_audited(self):
        name, uid = self.make()
        b = tn.Browser().login(name)
        act(self.root, 'user_password_reset', token=console_token(self.root), user_id=uid, new_password='hygiene-pw-1', new_password_again='hygiene-pw-1')
        r = self.both(name, 'hygiene-pw-1')
        secrets = [r['PasswordHash']]
        trail = json.dumps(tn.sql('SELECT * FROM phoromatic_server_audit') + tn.sql('SELECT * FROM phoromatic_admin_audit'))
        log = tn.srv_sh('cat /tmp/tn-server.log /var/lib/benchy/phoromatic/*.log 2>/dev/null')
        pages = ''.join(self.root.get(p)[2] for p in ('/?admin', '/?server_users')) + ''.join(b.get(p)[2] for p in ('/?main', '/?users', '/?password', '/?settings'))
        for s in secrets:
            self.assertGreater(len(s), 30)
            for where, text in (('the audit', trail), ('the server log', log), ('a page', pages)):
                self.assertNotIn(s, text, where)
        for text in ('hygiene-pw-1', tn.PW):
            self.assertNotIn(text, trail, 'no password in the audit')
        self.assertNotIn('$2y$', trail + pages)

    def test_a_wrong_password_counts_as_a_failure_as_before(self):
        name, uid = self.make()
        base = tn.sql("SELECT COUNT(*) AS n FROM phoromatic_admin_audit WHERE Action='web_login_failed'")[0]['n']
        self.assertFalse(try_login(name, 'wrong-pass-124'))
        self.assertFalse(try_login('t6-no-such-user', 'wrong-pass-124'))
        self.assertEqual(tn.sql("SELECT COUNT(*) AS n FROM phoromatic_admin_audit WHERE Action='web_login_failed'")[0]['n'], base + 2)
        self.assertTrue(try_login(name), 'two failures lock nobody out')


if __name__ == '__main__':
    unittest.main()
