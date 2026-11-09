"""Web log-in (runs last: the lockout it causes lasts five minutes): one message for every failure, failures are counted per address for users and the
root login alike, and ten of them lock the address out even for the right password."""
import re
import unittest

import tn


def message(body):
    m = re.search(r'<h1>([^<]*)</h1>\s*<p>([^<]*)</p>', body)
    return (m.group(1), m.group(2)) if m else re.sub(r'\s+', ' ', body)[:200]


class LoginFailures(unittest.TestCase):
    def attempt(self, user, password):
        b = tn.Browser()
        b.get('/')
        return b.post('/?login', {'username': user, 'password': password})[2]

    def failures(self):
        return tn.sql("SELECT COUNT(*) AS n FROM phoromatic_admin_audit WHERE Action='web_login_failed'")[0]['n']

    def test_a_wrong_password_an_unknown_user_and_a_wrong_root_password_look_the_same(self):
        wrong = message(self.attempt('tn1admin', 'not-the-password'))
        unknown = message(self.attempt('tn-no-such-user', 'whatever1'))
        root = message(self.attempt('rootadmin', 'not-the-root-password'))
        self.assertEqual(wrong, unknown)
        self.assertEqual(wrong, root)
        self.assertNotIn('not found', ' '.join(wrong))
        self.assertNotIn('Root Admin Password Incorrect', str(root))

    def test_failures_are_counted_and_ten_lock_the_address_out(self):
        base = self.failures()
        self.attempt('tn1admin', 'bad1')
        self.attempt('rootadmin', 'bad2')
        self.assertEqual(self.failures(), base + 2)
        for i in range(12):
            self.attempt('tn-no-such-user', 'bad%d' % i)
        locked = self.attempt('tn1admin', tn.PW)
        self.assertNotIn('Log-In Successful', locked)
        self.assertIn('Too Many Failed Log-Ins', locked)
        self.assertNotIn('Log-In Successful', self.attempt('rootadmin', tn.ROOT_PW))


if __name__ == '__main__':
    unittest.main()
