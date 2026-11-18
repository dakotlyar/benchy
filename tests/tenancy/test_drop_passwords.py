"""The legacy password hash drop (0.10.0, schema 66, designed 2026-11-17 L3 to L9). This server is past the step, so the states the step meets are made by hand (a user
with a trusted modern hash and a legacy value, a dormant one with only the legacy value, a stale modern hash, root in each) and the sweep that every writable open runs does
what the step's own sweep does (the step itself is run on a real database by rollback_drop.sh).

  1. a trusted modern hash is kept: '!' for the legacy value, no fingerprint, the same PasswordHash, and the user signs in
  2. a dormant user is wrapped, signs in with the old password and then holds a plain modern hash; a wrong password is refused and writes nothing; the legacy value typed as a
     password does not sign anyone in
  3. a stale modern hash (the password was changed on an older release) is not trusted: the legacy value is the truth
  4. root: dormant is wrapped, signs in and becomes plain modern; already modern is kept
  5. no secret left: none of the legacy values appears in any text column of any table (the audit included), in the settings file, in a log
  6. every writer leaves '!' and a plain modern hash, and a lint holds the writers (lint_passwords.py, with its mutants in lint_selftest.py)
  7. the notice on the server settings page counts the database copies that were taken before the drop

Every user these tests make is removed afterwards; the root password is put back. Wrong passwords go through the server's own classes (chk), not the log-in page: ten failures lock
the test host's address out."""
import hashlib
import json
import re
import unittest
import uuid

import tn
from t4 import *
from test_t6_passwords import chk, chk_root, php_str, root_drop, root_put, root_setting, row, set_root_cli, sha, try_login, try_root

OLD_PW = 'old-release-pw-1'
NEW_PW = 'set-by-old-release-2'
PLAIN = r'^\$(2y|argon2id)\$'


def legacy_of(name, password):
    return sha(row(name)['Salt'] + password)


def sweep():
    """Any request that opens the database for writing sweeps; the front page is the cheapest."""
    tn.Browser().get('/')


def settings_dump():
    return tn.php_boot("phoromatic_server::init_settings_store(); $s = phoromatic_settings_store::recover(phoromatic_server::phoromatic_path() . 'phoromatic-settings.pt2so'); echo json_encode($s->get_objects());")


def everywhere(needle):
    """Every place a value could be: any text column of any table, the settings file, the server's logs. Returns where it was found."""
    found = []
    code = ('$d = new SQLite3("%s/phoromatic.db"); $n = $argv[1]; $t = $d->query("SELECT name FROM sqlite_master WHERE type = \'table\'");'
            'while($r = $t->fetchArray(SQLITE3_ASSOC)){ $c = $d->query("PRAGMA table_info(" . $r["name"] . ")"); while($x = $c->fetchArray(SQLITE3_ASSOC)){'
            '$q = $d->querySingle("SELECT COUNT(*) FROM \\"" . $r["name"] . "\\" WHERE CAST(\\"" . $x["name"] . "\\" AS TEXT) LIKE \'%%" . SQLite3::escapeString($n) . "%%\'"); if($q) echo $r["name"] . "." . $x["name"] . "\\n"; } }') % tn.STORAGE
    found += tn.subprocess.run(['docker', 'exec', tn.SRV, 'php', '-r', code, needle], capture_output=True, text=True).stdout.split()
    if needle in settings_dump():
        found.append('settings file')
    if needle in tn.srv_sh('cat /tmp/tn-server.log /var/lib/benchy/phoromatic/*.log 2>/dev/null'):
        found.append('a log')
    return found


class Drop(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.st = tn.state()
        cls.admin = tn.Browser().login('tn1admin')
        cls.root = tn.Browser().login_root()
        tn.reset_v1_limits()

    def make(self, password=tn.PW):
        name = 'dp' + uuid.uuid4().hex[:7]
        uid = tn.make_user(self.admin, name, 3)
        self.addCleanup(lambda: tn.sql_exec("DELETE FROM phoromatic_memberships WHERE UserID='%s'; DELETE FROM phoromatic_user_settings WHERE UserID='%s'; DELETE FROM phoromatic_users WHERE UserID='%s'" % (uid, uid, uid)))
        return name, uid

    def plain(self, name, password):
        r = row(name)
        self.assertEqual((r['Password'], r['PasswordLegacyFp'], r['PasswordScheme']), ('!', None, None), r)
        self.assertRegex(r['PasswordHash'] or '', PLAIN)
        self.assertEqual(tn.php_boot("echo password_verify(base64_encode(hash('sha256', %s, true)), %s) ? 'yes' : 'no';" % (php_str(password), php_str(r['PasswordHash']))).strip(), 'yes')
        return r


class Users(Drop):
    def test_a_trusted_modern_hash_is_kept_and_the_legacy_value_goes(self):
        name, uid = self.make()
        before = row(name)
        legacy = sha(before['Salt'] + tn.PW)
        tn.sql_exec("UPDATE phoromatic_users SET Password='%s', PasswordLegacyFp='%s' WHERE UserName='%s'" % (legacy, sha('legacy-fingerprint:' + legacy), name))   # the 0.9.14 state: both hashes
        sweep()
        r = self.plain(name, tn.PW)
        self.assertEqual(r['PasswordHash'], before['PasswordHash'], 'the modern hash is the same one')
        self.assertEqual(everywhere(legacy), [], 'the legacy value is nowhere')
        self.assertTrue(try_login(name))

    def test_a_dormant_user_is_wrapped_signs_in_and_ends_with_a_plain_modern_hash(self):
        name, uid = self.make()
        legacy = legacy_of(name, tn.PW)
        tn.sql_exec("UPDATE phoromatic_users SET Password='%s', PasswordHash=NULL, PasswordLegacyFp=NULL, PasswordScheme=NULL WHERE UserName='%s'" % (legacy, name))   # 0.9.14 user nobody signed in as
        sweep()
        r = row(name)
        self.assertEqual((r['Password'], r['PasswordLegacyFp'], r['PasswordScheme']), ('!', None, 'legacy-wrapped'), r)
        self.assertRegex(r['PasswordHash'], PLAIN)
        self.assertNotIn(legacy, json.dumps(r))
        self.assertEqual(everywhere(legacy), [])
        # a wrong password is refused and writes nothing; so is the legacy value itself typed as the password (the wrap is no stand-in for it)
        self.assertFalse(chk(name, 'wrong-pass-123'))
        self.assertFalse(chk(name, legacy), 'the hash from a stolen backup is not a password')
        self.assertEqual(row(name), r)
        self.assertTrue(try_login(name))
        after = self.plain(name, tn.PW)
        self.assertNotEqual(after['PasswordHash'], r['PasswordHash'], 'the wrap is replaced by a plain hash')
        self.assertTrue(try_login(name))
        self.assertFalse(chk(name, legacy))

    def test_a_modern_hash_that_is_not_trusted_loses_to_the_legacy_value(self):
        # the password was changed on an older release after the modern hash was made: the fingerprint no longer fits
        name, uid = self.make()
        r = row(name)
        old_legacy = sha(r['Salt'] + tn.PW)
        new_legacy = sha(r['Salt'] + NEW_PW)
        tn.sql_exec("UPDATE phoromatic_users SET Password='%s', PasswordLegacyFp='%s' WHERE UserName='%s'" % (new_legacy, sha('legacy-fingerprint:' + old_legacy), name))
        sweep()
        self.assertEqual(row(name)['PasswordScheme'], 'legacy-wrapped')
        self.assertFalse(chk(name, tn.PW), 'the password before does not work')
        self.assertTrue(try_login(name, NEW_PW))
        self.plain(name, NEW_PW)
        self.assertEqual(everywhere(new_legacy) + everywhere(old_legacy), [])

    def test_a_scheme_left_over_from_a_wrap_does_not_follow_a_new_password(self):
        # wrapped, then an older release (after a rollback) sets a password and both hashes: the sweep keeps that modern hash and the scheme goes with the old wrap
        name, uid = self.make()
        legacy = legacy_of(name, tn.PW)
        tn.sql_exec("UPDATE phoromatic_users SET Password='%s', PasswordHash=NULL, PasswordLegacyFp=NULL WHERE UserName='%s'" % (legacy, name))
        sweep()
        self.assertEqual(row(name)['PasswordScheme'], 'legacy-wrapped')
        new_legacy = sha(row(name)['Salt'] + NEW_PW)
        modern = tn.php_boot("echo password_hash(base64_encode(hash('sha256', %s, true)), PASSWORD_DEFAULT);" % php_str(NEW_PW)).strip()
        tn.sql_exec("UPDATE phoromatic_users SET Password='%s', PasswordHash='%s', PasswordLegacyFp='%s' WHERE UserName='%s'" % (new_legacy, modern, sha('legacy-fingerprint:' + new_legacy), name))
        sweep()
        r = self.plain(name, NEW_PW)
        self.assertEqual(r['PasswordHash'], modern)
        self.assertTrue(try_login(name, NEW_PW))

    def test_no_user_is_left_with_a_legacy_value_or_a_fingerprint(self):
        sweep()
        self.assertEqual(tn.sql("SELECT COUNT(*) AS n FROM phoromatic_users WHERE Password != '!' OR PasswordLegacyFp IS NOT NULL")[0]['n'], 0)


class Root(Drop):
    def tearDown(self):
        set_root_cli(tn.ROOT_PW)

    def test_a_dormant_root_is_wrapped_signs_in_and_ends_with_a_plain_modern_hash(self):
        legacy = sha('PTS' + tn.ROOT_PW)
        root_drop('root_admin_pw_hash')
        root_put('root_admin_pw', legacy)
        root_put('root_admin_pw_fp', sha('legacy-fingerprint:' + legacy))   # (a fingerprint with no hash vouches for nothing)
        sweep()
        self.assertEqual((root_setting('root_admin_pw'), root_setting('root_admin_pw_fp'), root_setting('root_admin_pw_scheme')), ('!', '', 'legacy-wrapped'))
        wrapped = root_setting('root_admin_pw_hash')
        self.assertRegex(wrapped, PLAIN)
        self.assertEqual(everywhere(legacy), [])
        self.assertFalse(chk_root('wrong-root-pass'))
        self.assertFalse(chk_root(legacy), 'root\'s legacy value typed as the password does not work')
        self.assertEqual(root_setting('root_admin_pw_hash'), wrapped)
        self.assertTrue(try_root())
        self.assertEqual(root_setting('root_admin_pw_scheme'), '')
        self.assertNotEqual(root_setting('root_admin_pw_hash'), wrapped)
        self.assertRegex(root_setting('root_admin_pw_hash'), PLAIN)
        self.assertTrue(try_root())

    def test_a_root_that_was_already_modern_keeps_its_hash(self):
        set_root_cli(tn.ROOT_PW)
        modern = root_setting('root_admin_pw_hash')
        legacy = sha('PTS' + tn.ROOT_PW)
        root_put('root_admin_pw', legacy)   # what 0.9.14 had beside the modern hash
        root_put('root_admin_pw_fp', sha('legacy-fingerprint:' + legacy))
        sweep()
        self.assertEqual((root_setting('root_admin_pw'), root_setting('root_admin_pw_fp'), root_setting('root_admin_pw_scheme'), root_setting('root_admin_pw_hash')), ('!', '', '', modern))
        self.assertEqual(everywhere(legacy), [])
        self.assertTrue(try_root())

    def test_a_fingerprint_left_by_a_stop_between_root_writes_is_removed(self):
        # a stop after root_admin_pw became '!' and before root_admin_pw_fp was removed (AP-5): the next open finishes the job
        set_root_cli(tn.ROOT_PW)
        root_put('root_admin_pw_fp', sha('legacy-fingerprint:' + sha('PTS' + tn.ROOT_PW)))
        sweep()
        self.assertEqual((root_setting('root_admin_pw'), root_setting('root_admin_pw_fp')), ('!', ''))
        self.assertTrue(try_root())

    def test_root_never_set_stays_never_set(self):
        # (a settings file with no root password: the sweep has nothing to do and must not write a '!' that would read as "set")
        saved = {k: root_setting(k) for k in ('root_admin_pw', 'root_admin_pw_hash', 'root_admin_pw_fp', 'root_admin_pw_scheme')}
        try:
            for k in saved:
                root_drop(k)
            sweep()
            self.assertEqual(root_setting('root_admin_pw'), '')
            b = tn.Browser()
            b.get('/')
            self.assertIn('Root Admin Password Not Set', b.post('/?login', {'username': 'rootadmin', 'password': 'whatever-1'})[2])
        finally:
            set_root_cli(tn.ROOT_PW)


class Writers(Drop):
    def test_every_place_that_sets_a_password_leaves_only_a_modern_hash(self):
        # the users page
        name, uid = self.make()
        self.plain(name, tn.PW)
        # the password page
        b = tn.Browser().login(name)
        self.assertIn('Password Updated', b.post('/?password', {'old_password': tn.PW, 'register_password': 'changed-dp-1', 'register_password_confirm': 'changed-dp-1', 'token_submit': b.token('/?settings')})[2])
        self.plain(name, 'changed-dp-1')
        # the console's Set password
        s, loc, page = act(self.root, 'user_password_reset', token=console_token(self.root), user_id=uid, new_password='console-dp-12', new_password_again='console-dp-12')
        self.assertIn('The password was changed.', page)
        self.plain(name, 'console-dp-12')
        # server-account-create (a password on stdin), which is also what benchy-server-setup --admin-password-file and an account made in the console call
        user = 'dpc' + uuid.uuid4().hex[:5]
        aid = ('DP' + uuid.uuid4().hex[:4]).upper()
        self.assertTrue(tn.register(user, aid))
        self.addCleanup(lambda: tn.sql_exec("DELETE FROM phoromatic_memberships WHERE AccountID='%s'; DELETE FROM phoromatic_user_settings WHERE AccountID='%s'; DELETE FROM phoromatic_users WHERE UserName='%s'; DELETE FROM phoromatic_account_settings WHERE AccountID='%s'; DELETE FROM phoromatic_accounts WHERE AccountID='%s'" % (aid, aid, user, aid, aid)))
        self.plain(user, tn.PW)
        # the root password
        set_root_cli('root-dp-12345')
        try:
            self.assertEqual((root_setting('root_admin_pw'), root_setting('root_admin_pw_fp'), root_setting('root_admin_pw_scheme')), ('!', '', ''))
            self.assertRegex(root_setting('root_admin_pw_hash'), PLAIN)
            self.assertTrue(try_root('root-dp-12345'))
        finally:
            set_root_cli(tn.ROOT_PW)
        sweep()
        self.assertEqual(tn.sql("SELECT COUNT(*) AS n FROM phoromatic_users WHERE Password != '!' OR PasswordLegacyFp IS NOT NULL")[0]['n'], 0)
        self.assertEqual(root_setting('root_admin_pw'), '!')

    def test_the_lint_that_holds_the_writers_passes_and_fails_on_a_mutant(self):
        here = tn.HERE
        tree = tn.os.environ.get('TN_TREE') or tn.os.path.normpath(tn.os.path.join(here, '..', '..'))
        ok = tn.subprocess.run(['python3', '-W', 'ignore', tn.os.path.join(here, 'lint_passwords.py'), '--root', tree], capture_output=True, text=True)
        self.assertEqual(ok.returncode, 0, ok.stdout)
        # the mutant: lint_selftest.py copies the tree and puts a legacy_user() call back in a page (test_passwords_a_page_that_goes_back_to_its_own_legacy_hash_fails)
        r = tn.subprocess.run(['python3', '-W', 'ignore', '-m', 'unittest', 'lint_selftest.LintsFailWhereTheyShould.test_passwords_a_page_that_goes_back_to_its_own_legacy_hash_fails'], capture_output=True, text=True, cwd=here)
        self.assertEqual(r.returncode, 0, r.stdout + r.stderr)


class Notice(Drop):
    """The server settings page (for server administrators) says how many database copies in storage/backups/ were taken before the drop and so hold the legacy hashes."""
    @classmethod
    def setUpClass(cls):
        super().setUpClass()
        tn.cli('server-admin-grant', 'tn1lvl2')
        cls.sa = tn.Browser().login('tn1lvl2')

    @classmethod
    def tearDownClass(cls):
        tn.cli('server-admin-revoke', 'tn1lvl2')

    def count(self):
        m = re.search(r'(\d+) older database cop(?:y|ies) in <code>storage/backups/</code> still holds? the old password hashes', self.sa.get('/?server_settings')[2])
        return int(m.group(1)) if m else 0

    def test_the_server_settings_page_counts_the_copies_taken_before_the_drop(self):
        folder = tn.STORAGE + '/backups'
        planted = [folder + '/phoromatic-pre-63-20261101-100000.db', folder + '/phoromatic-pre-66-20261101-100001.db']
        later = folder + '/phoromatic-pre-67-20261101-100002.db'   # a later step's copy holds no legacy hash: not counted
        self.addCleanup(lambda: tn.srv_sh('rm -f ' + ' '.join(planted + [later])))
        base = self.count()
        tn.srv_sh('mkdir -p %s && touch %s' % (folder, ' '.join(planted + [later])))
        self.assertEqual(self.count(), base + 2)
        page = self.sa.get('/?server_settings')[2]
        self.assertIn('color: #b00020', page)
        self.assertIn('you will not go back to 0.9.15 or older.', page)
        tn.srv_sh('rm -f ' + ' '.join(planted[:1]))
        self.assertEqual(self.count(), base + 1)
        tn.srv_sh('rm -f ' + ' '.join(planted + [later]))
        self.assertEqual(self.count(), base, 'with the copies deleted the count is back to what it was')


if __name__ == '__main__':
    unittest.main()
