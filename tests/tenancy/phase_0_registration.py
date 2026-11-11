"""There is no account registration form (0.9.12; closed by default since 0.9.10): a fresh server, a server that was upgraded, a server where the
setting says "open" (what `server-registration open` stored in earlier releases), and `server-registration open` itself, which is refused.
Runs on a server of its own (TN_REG_SRV / TN_REG_PORT) that no other check registers on."""
import os
import re
import subprocess
import unittest

import tn

SRV = os.environ['TN_REG_SRV']
BASE = 'http://127.0.0.1:%s' % os.environ['TN_REG_PORT']
CLOSED = 'Account creation is closed on this server'


def bs(*args, stdin=None):
    r = subprocess.run(['docker', 'exec', '-i', SRV, 'sh', '/bench/benchy'] + list(args), input=stdin if stdin is not None else '', capture_output=True, text=True)
    return r.returncode, r.stdout + r.stderr


def welcome():
    b = tn.Browser(BASE)
    return b.get('/')[2]


def try_register(name):
    b = tn.Browser(BASE)
    b.get('/')
    return b.post('/?register', {'register_username': name, 'register_password': tn.PW, 'register_password_confirm': tn.PW, 'register_email': name + '@example.com'})[2]


def accounts():
    php = '$d=new SQLite3("/var/lib/benchy/phoromatic/phoromatic.db"); echo (int)$d->querySingle("SELECT COUNT(*) FROM phoromatic_accounts");'
    return int(subprocess.run(['docker', 'exec', SRV, 'php', '-r', php], capture_output=True, text=True).stdout or 0)


def unset_setting():
    """The state of a server that never had the setting made (what an older release left it in)."""
    code = ("define('PTS_MODE','CLIENT');define('PTS_AUTO_LOAD_OBJECTS',true);define('PHOROMATIC_SERVER',true);$_SERVER['argv']=array('x');include('/bench/pts-core/pts-core.php');pts_core::init();"
            "phoromatic_server::init_settings_store(); pts_storage_object::remove_in_file(phoromatic_server::phoromatic_path() . 'phoromatic-settings.pt2so', 'account_creation_alt');")
    subprocess.run(['docker', 'exec', SRV, 'php', '-r', code], capture_output=True, text=True)


class A_FreshServer(unittest.TestCase):
    def test_1_a_server_nobody_configured_is_closed_and_says_how_to_make_the_first_account(self):
        page = welcome()
        self.assertIn(CLOSED, page)
        self.assertNotIn('name="register_username"', page)
        self.assertIn('server-account-create', page)
        self.assertNotIn('server-registration open', page)
        self.assertIn('Registration: closed', bs('server-info')[1])

    def test_2_posting_the_registration_form_anyway_creates_nothing(self):
        body = try_register('tnfreshreg')
        self.assertNotIn('Account Created', body)
        self.assertEqual(accounts(), 0)

    def test_3_the_command_line_makes_the_first_account_while_registration_is_closed(self):
        rc, out = bs('server-account-create', '--user', 'tnfirst', '--email', 'first@example.com', stdin='tnfirst-pass-1\n')
        self.assertEqual(rc, 0, out)
        self.assertEqual(accounts(), 1)
        b = tn.Browser(BASE)
        b.get('/')
        self.assertIn('Log-In Successful', b.post('/?login', {'username': 'tnfirst', 'password': 'tnfirst-pass-1'})[2])
        page = welcome()
        self.assertIn(CLOSED, page)
        self.assertNotIn('This server has no accounts yet', page)


def set_setting(value):
    """Put the account_creation_alt setting straight into the settings file, as an earlier release left it ('' is what `server-registration open` stored)."""
    code = ("define('PTS_MODE','CLIENT');define('PTS_AUTO_LOAD_OBJECTS',true);define('PHOROMATIC_SERVER',true);$_SERVER['argv']=array('x');include('/bench/pts-core/pts-core.php');pts_core::init();"
            "phoromatic_server::init_settings_store(); phoromatic_server::save_setting('account_creation_alt', %s);" % ("'%s'" % value))
    subprocess.run(['docker', 'exec', SRV, 'php', '-r', code], capture_output=True, text=True)


class B_RegistrationSetting(unittest.TestCase):
    def test_1_server_registration_open_is_refused(self):
        rc, out = bs('server-registration', 'open')
        self.assertEqual(rc, 1)
        self.assertIn('Open registration was removed', out)
        self.assertNotIn('name="register_username"', welcome())
        self.assertNotIn('Account Created', try_register('tnopen1'))

    def test_2_a_server_that_had_it_open_is_closed_whatever_the_stored_text(self):
        # an administrator of an earlier release ran `server-registration open`: the stored value is the empty text. Nothing opens it any more.
        set_setting('')
        self.assertIn(CLOSED, welcome())
        self.assertNotIn('name="register_username"', welcome())
        before = accounts()
        self.assertNotIn('Account Created', try_register('tnupgopen'))
        self.assertEqual(accounts(), before)
        self.assertIn('Registration: closed', bs('server-info')[1])

    def test_3_an_upgraded_server_that_never_set_it_is_closed(self):
        unset_setting()
        self.assertIn(CLOSED, welcome())
        self.assertNotIn('Account Created', try_register('tnupgnever'))
        self.assertIn('Registration: closed', bs('server-info')[1])

    def test_4_an_explicit_message_is_kept_and_shown_escaped(self):
        bs('server-registration', 'closed', '--message', 'Ask the <b>QC</b> team')
        page = welcome()
        self.assertIn('Ask the &lt;b&gt;QC&lt;/b&gt; team', page)
        self.assertNotIn('Ask the <b>QC</b> team', page)
        self.assertNotIn('Account Created', try_register('tnupgmsg'))
        bs('server-registration', 'closed')

    def test_5_root_sees_the_message_in_the_field_and_no_add_to_account_form(self):
        unset_setting()
        subprocess.run(['docker', 'exec', '-i', SRV, 'sh', '/bench/benchy', 'phoromatic.set-root-admin-password'], input=tn.ROOT_PW + '\n', capture_output=True, text=True)
        root = tn.Browser(BASE)
        root.get('/')
        root.post('/?login', {'username': 'rootadmin', 'password': tn.ROOT_PW})
        page = root.get('/?admin_config')[2]
        self.assertRegex(page, r'<textarea name="account_creation_alt"[^>]*>%s' % re.escape(CLOSED))
        for gone in ('add_new_users_to_account', 'force_result_sharing', 'allow_test_profile_creation'):
            self.assertNotIn(gone, page)


if __name__ == '__main__':
    unittest.main()
