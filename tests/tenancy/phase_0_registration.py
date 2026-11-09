"""Account registration is closed unless an administrator opened it (0.9.10): a fresh server, a server that was upgraded, and `server-registration open`.
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
        self.assertIn('server-registration open', page)
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


class B_RegistrationSetting(unittest.TestCase):
    def test_1_server_registration_open_opens_it_and_closed_closes_it(self):
        rc, out = bs('server-registration', 'open')
        self.assertIn('Registration is open', out)
        self.assertIn('name="register_username"', welcome())
        self.assertIn('Account Created', try_register('tnopen1'))
        self.assertIn('Registration: open', bs('server-info')[1])
        bs('server-registration', 'closed')
        self.assertNotIn('Account Created', try_register('tnopen2'))
        self.assertIn(CLOSED, welcome())

    def test_2_an_upgraded_server_keeps_an_explicit_open(self):
        # an administrator of an earlier release ran `server-registration open`: the stored value is the empty text, and it survives
        bs('server-registration', 'open')
        self.assertIn('Account Created', try_register('tnupgopen'))
        self.assertIn('name="register_username"', welcome())

    def test_3_an_upgraded_server_that_never_set_it_is_closed(self):
        # indistinguishable from "left at the old default" and treated as closed: reported in the release notes
        unset_setting()
        self.assertIn(CLOSED, welcome())
        self.assertNotIn('Account Created', try_register('tnupgnever'))
        self.assertIn('Registration: closed', bs('server-info')[1])

    def test_4_an_explicit_message_is_kept(self):
        bs('server-registration', 'closed', '--message', 'Ask the QC team')
        self.assertIn('Ask the QC team', welcome())
        self.assertNotIn('Account Created', try_register('tnupgmsg'))

    def test_5_root_sees_the_default_message_not_an_empty_field_that_would_open_it(self):
        unset_setting()
        subprocess.run(['docker', 'exec', '-i', SRV, 'sh', '/bench/benchy', 'phoromatic.set-root-admin-password'], input=tn.ROOT_PW + '\n', capture_output=True, text=True)
        root = tn.Browser(BASE)
        root.get('/')
        root.post('/?login', {'username': 'rootadmin', 'password': tn.ROOT_PW})
        page = root.get('/?admin_config')[2]
        self.assertRegex(page, r'<textarea name="account_creation_alt"[^>]*>%s' % re.escape(CLOSED))


if __name__ == '__main__':
    unittest.main()
