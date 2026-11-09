"""N12: ?admin, ?admin_config and ?admin_data are root's; a tenant level is sent away on each. The root pages take a change only as a POST with
the CSRF token (the CSRF the plan listed as missing was already in place at 0.9.9: this keeps it that way)."""
import re
import unittest

import tn


class RootPagesAreRootOnly(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.b = tn.logins('T1')

    def test_every_tenant_level_is_sent_away_from_the_admin_pages(self):
        for lvl in tn.LEVELS:
            for page in ('/?admin', '/?admin_config', '/?admin_data', '/?admin_data/delete/result/%s' % tn.state()['T2']['pprid']):
                with self.subTest(level=lvl, page=page):
                    body = self.b[lvl].get(page)[2]
                    for marker in ('Phoromatic Server Administration', 'Phoromatic Server Data', 'Server Configuration', 'Account Topology', 'HTTPS Certificate'):
                        self.assertNotIn(marker, body)
        self.assertEqual(len(tn.sql("SELECT 1 FROM phoromatic_results WHERE PPRID='%s'" % tn.state()['T2']['pprid'])), 1)


class RootChangesNeedTheToken(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.root = tn.Browser().login_root()

    def level_of(self, user):
        return tn.sql("SELECT AdminLevel AS l FROM phoromatic_users WHERE UserName='%s'" % user)[0]['l']

    def test_admin_changes_without_a_token_or_from_another_origin_do_nothing(self):
        tok = re.search(r'name="token_submit" value="([0-9a-f]+)"', self.root.get('/?admin')[2]).group(1)
        for label, extra, headers in (('no token', {}, {}), ('wrong token', {'token_submit': '0' * len(tok)}, {}), ('other origin', {'token_submit': tok}, {'Origin': 'http://evil.example'})):
            with self.subTest(label):
                body = self.root.post('/?admin', dict({'disable_user': 'tn1lvl3'}, **extra), headers=headers)[2]
                self.assertIn('nothing was changed', body)
                self.assertEqual(self.level_of('tn1lvl3'), 3)
                self.root.post('/?admin', dict({'change_user_password': 'tn1lvl3', 'new_user_password': 'tnchanged1'}, **extra), headers=headers)
                tn.Browser().login('tn1lvl3')  # the old password still works

    def test_admin_config_changes_without_a_token_or_from_another_origin_do_nothing(self):
        tok = re.search(r'name="token_submit" value="([0-9a-f]+)"', self.root.get('/?admin_config')[2]).group(1)
        marker = 'TNMSG%s' % tn.uuid.uuid4().hex[:6]
        for label, extra, headers in (('no token', {}, {}), ('wrong token', {'token_submit': '0' * len(tok)}, {}), ('other origin', {'token_submit': tok}, {'Origin': 'http://evil.example'})):
            with self.subTest(label):
                body = self.root.post('/?admin_config', dict({'main_page_message': marker, 'account_creation_alt': marker, 'https_action': 'bootstrap'}, **extra), headers=headers)[2]
                self.assertIn('nothing was changed', body)
                self.assertNotIn(marker, self.root.get('/?admin_config')[2])
        self.assertEqual(tn.tls_mode_from_root(), 'off (plain HTTP)')
        # the same change with the token is taken (so the checks above are not passing because the form is broken)
        self.root.post('/?admin_config', {'main_page_message': marker, 'token_submit': tok})
        self.assertIn(marker, self.root.get('/?admin_config')[2])
        self.root.post('/?admin_config', {'main_page_message': '', 'token_submit': tok})


if __name__ == '__main__':
    unittest.main()
