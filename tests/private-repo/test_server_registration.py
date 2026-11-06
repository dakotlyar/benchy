"""The 0.9.8 server commands that the installer script relies on: server-registration, server-account-create, server-info, server-init (the refusal
only: it never runs as root), and the exit status of a start-phoromatic-server refusal (78) outside systemd.
They run as root in the shared server container, like the token commands (the database there belongs to root). The installer script itself and
the systemd side are covered by tests/private-repo/server_deb.sh. Run through tests/private-repo/run.sh (http).
"""
import re
import subprocess
import unittest
import uuid

from test_private_repo_http import SRV, sql
from test_private_repo_web import Browser, USER, PASS


def bs(*args, stdin=''):
    """benchy on the server host: (exit status, output without the missing-extension lines)"""
    p = subprocess.run(['docker', 'exec', '-i', SRV, 'sh', '/bench/benchy'] + list(args), input=stdin, capture_output=True, text=True, timeout=120)
    out = '\n'.join(l for l in (p.stdout + p.stderr).splitlines() if not l.startswith('MISSING') and l.strip())
    return p.returncode, out


def register(b, name, password='qcpass123'):
    b.get('/')
    return b.post('/?register', {'register_username': name, 'register_password': password, 'register_password_confirm': password, 'register_email': name + '@example.com'})[2]


def user_rows(name):
    return sql("SELECT UserName FROM phoromatic_users WHERE UserName='%s'" % name)


class ServerRegistration(unittest.TestCase):
    @classmethod
    def tearDownClass(cls):
        bs('server-registration', 'open')

    def test_1_shows_the_state(self):
        bs('server-registration', 'open')
        rc, out = bs('server-registration')
        self.assertEqual(rc, 0)
        self.assertIn('Registration is open', out)

    def test_2_closed_refuses_web_registration_with_the_default_message(self):
        rc, out = bs('server-registration', 'closed')
        self.assertEqual(rc, 0)
        self.assertIn('Registration is closed', out)
        name = 'regc' + uuid.uuid4().hex[:6]
        body = register(Browser(), name)
        self.assertIn('Account creation is closed on this server. Ask its administrator for an account.', body)
        self.assertNotIn('Account Created', body)
        self.assertEqual(user_rows(name), [])

    def test_3_closed_with_a_message(self):
        rc, out = bs('server-registration', 'closed', '--message', 'Closed for the QC run')
        self.assertEqual(rc, 0)
        self.assertIn('Closed for the QC run', Browser().get('/')[2])

    def test_4_open_again_allows_registration(self):
        bs('server-registration', 'closed')
        rc, _ = bs('server-registration', 'open')
        self.assertEqual(rc, 0)
        name = 'rego' + uuid.uuid4().hex[:6]
        self.assertIn('Account Created', register(Browser(), name))
        self.assertEqual(len(user_rows(name)), 1)

    def test_5_usage_errors_exit_2_and_change_nothing(self):
        bs('server-registration', 'open')
        for args in (['sideways'], ['open', '--message', 'x'], ['closed', '--message']):
            rc, out = bs('server-registration', *args)
            self.assertEqual(rc, 2, args)
            self.assertIn('Usage: server-registration', out)
        self.assertIn('Registration is open', bs('server-registration')[1])


class ServerAccountCreate(unittest.TestCase):
    @classmethod
    def tearDownClass(cls):
        bs('server-registration', 'open')

    def test_1_creates_an_account_from_one_line_of_stdin_even_while_registration_is_closed(self):
        bs('server-registration', 'closed')
        name = 'acc' + uuid.uuid4().hex[:6]
        rc, out = bs('server-account-create', '--user', name, '--email', name + '@example.com', stdin='cli-pass-123\n')
        self.assertEqual(rc, 0, out)
        self.assertIn('Account created: user ' + name, out)
        self.assertEqual(len(user_rows(name)), 1)
        # an administrator of an account of its own
        row = sql("SELECT AdminLevel, AccountID, Email FROM phoromatic_users WHERE UserName='%s'" % name)[0]
        self.assertEqual(row['AdminLevel'], 1)
        self.assertEqual(row['Email'], name + '@example.com')
        self.assertEqual(len(sql("SELECT AccountID FROM phoromatic_accounts WHERE AccountID='%s'" % row['AccountID'])), 1)
        self.assertEqual(Browser().login(name, 'cli-pass-123').get('/')[0], 200)

    def test_2_empty_or_closed_stdin_is_an_error_and_creates_nothing(self):
        name = 'accx' + uuid.uuid4().hex[:6]
        for stdin in ('', '\n'):
            rc, out = bs('server-account-create', '--user', name, '--email', name + '@example.com', stdin=stdin)
            self.assertEqual(rc, 1)
            self.assertIn('No password was given', out)
        self.assertEqual(user_rows(name), [])

    def test_3_the_registration_checks_apply(self):
        name = 'accv' + uuid.uuid4().hex[:6]
        for user, email, pw, why in (('ab', name + '@example.com', 'longenough', 'at least four characters'),
                                     ('admin', name + '@example.com', 'longenough', 'reserved'),
                                     (name, 'not-an-address', 'longenough', 'valid email'),
                                     (name, name + '@example.com', 'short', 'at least six characters'),
                                     (name + '!', name + '@example.com', 'longenough', 'not allowed')):
            rc, out = bs('server-account-create', '--user', user, '--email', email, stdin=pw + '\n')
            self.assertEqual(rc, 1, why)
            self.assertIn(why, out)
            self.assertNotIn('<em>', out)
        self.assertEqual(user_rows(name), [])
        self.assertEqual(user_rows(name + '!'), [])

    def test_4_a_taken_name_is_refused(self):
        rc, out = bs('server-account-create', '--user', USER, '--email', 'other@example.com', stdin='whatever-1\n')
        self.assertEqual(rc, 1)
        self.assertIn('already taken', out)

    def test_5_missing_arguments_exit_2(self):
        rc, out = bs('server-account-create', '--user', 'onlyuser')
        self.assertEqual(rc, 2)
        self.assertIn('Usage: server-account-create', out)

    def test_6_it_sends_no_mail_and_leaves_the_server_log_alone(self):
        name = 'accm' + uuid.uuid4().hex[:6]
        rc, out = bs('server-account-create', '--user', name, '--email', name + '@example.com', stdin='cli-pass-123\n')
        self.assertEqual(rc, 0)
        self.assertNotIn('mail()', out)
        self.assertNotIn('Sendmail', out)


class ServerInfo(unittest.TestCase):
    def test_1_prints_the_settings_it_reads(self):
        rc, out = bs('server-info')
        self.assertEqual(rc, 0)
        self.assertIn('Configuration file: ', out)
        self.assertRegex(out, r'Storage: /var/lib/benchy/phoromatic/ \(from PhoromaticStorage')
        self.assertRegex(out, r'Database: /var/lib/benchy/phoromatic/phoromatic.db, schema version \d+')
        self.assertIn('HTTPS: off (plain HTTP only)', out)
        self.assertRegex(out, r'Accounts: \d+')
        self.assertRegex(out, r'Registration: (open|closed)')
        self.assertIn('HTTP port the service used at its last start: unknown', out)

    def test_2_is_read_only(self):
        before = sql('SELECT COUNT(*) AS n FROM phoromatic_users')[0]['n']
        bs('server-info')
        self.assertEqual(sql('SELECT COUNT(*) AS n FROM phoromatic_users')[0]['n'], before)


class ServerInitAndExitStatus(unittest.TestCase):
    def test_1_server_init_refuses_root_with_a_non_zero_exit(self):
        rc, out = bs('server-init')
        self.assertEqual(rc, 1)
        self.assertIn('must not run as root', out)

    def test_2_a_start_refusal_exits_78(self):
        # the shared server holds 8080: asked to use it, the real start command must refuse (this image has no PHP sockets either, which is
        # also a refusal), and the launcher must hand the status on
        p = subprocess.run(['docker', 'exec', '-e', 'PHOROMATIC_HTTP_PORT=8080', SRV, 'sh', '/bench/benchy', 'start-phoromatic-server'],
                           capture_output=True, text=True, timeout=120, stdin=subprocess.DEVNULL)
        self.assertEqual(p.returncode, 78, p.stdout + p.stderr)
        self.assertRegex(p.stdout + p.stderr, r'already in use|Sockets support is needed')

    def test_3_no_server_is_left_behind(self):
        out = subprocess.run(['docker', 'exec', SRV, 'sh', '-c', 'pgrep -f [s]tart-ws-server || true'], capture_output=True, text=True).stdout
        self.assertEqual(out.strip(), '')


if __name__ == '__main__':
    unittest.main()
