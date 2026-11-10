"""T1: the session id is a new one once the password is right (user and root log-in), and an id the server never issued is not taken up."""
import re
import unittest
import urllib.request

import tn


def sid(b):
    return {c.name: c.value for c in b.jar}.get('PHPSESSID')


class SessionIdChangesAtLogin(unittest.TestCase):
    def test_a_users_session_id_is_new_after_log_in(self):
        b = tn.Browser()
        b.get('/')
        before = sid(b)
        self.assertTrue(before)
        b.login(tn.state()['users']['T1']['1'])
        after = sid(b)
        self.assertTrue(after and after != before, (before, after))
        self.assertIn('Logout', b.get('/?main')[2])

    def test_the_old_id_is_no_use_after_log_in(self):
        b = tn.Browser()
        b.get('/')
        old = sid(b)
        b.login(tn.state()['users']['T1']['1'])
        other = tn.Browser()
        req = urllib.request.Request(tn.BASE + '/?main', headers={'Cookie': 'PHPSESSID=' + old})
        self.assertNotIn('Logout', urllib.request.urlopen(req, timeout=30).read().decode())

    def test_the_root_session_id_is_new_after_log_in(self):
        b = tn.Browser()
        b.get('/')
        before = sid(b)
        b.login_root()
        self.assertNotEqual(sid(b), before)

    def test_an_id_the_server_never_issued_is_replaced_not_adopted(self):
        planted = 'a' * 26
        req = urllib.request.Request(tn.BASE + '/', headers={'Cookie': 'PHPSESSID=' + planted})
        r = urllib.request.urlopen(req, timeout=30)
        cookies = [h for h in r.headers.get_all('Set-Cookie') or [] if h.startswith('PHPSESSID=')]
        self.assertTrue(cookies, 'a new id is issued')
        self.assertNotIn(planted, cookies[0])


if __name__ == '__main__':
    unittest.main()
