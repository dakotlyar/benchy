"""One account on the server: today's behavior is kept (the account's level-1 administrator is the server administrator), and level 2 and below
never are. Runs before the second account exists; test_settings_two_accounts.py is the same check after it does."""
import unittest

import tn

SETTINGS = '/?settings'


class SingleAccountServer(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.b = tn.logins('T1')

    def test_level_1_is_the_server_administrator_and_keeps_every_server_setting(self):
        page = self.b[1].get(SETTINGS)[2]
        for marker in ('HTTPS Certificate', 'name="client_phone_home"', 'name="v2_settings"', 'Native Agent API'):
            self.assertIn(marker, page, marker)
        self.assertIn('name="dc_upload"', self.b[1].get('/?caches')[2])

    def test_level_1_can_change_phone_home_and_agent_api_with_the_token(self):
        try:
            tok = self.b[1].token(SETTINGS)
            self.b[1].post(SETTINGS, {'client_phone_home': 'client_choice', 'token_submit': tok})
            self.assertEqual(tn.phone_home_policy(), 'client_choice')
            self.b[1].post(SETTINGS, {'v2_settings': '1', 'v2_allow_http': '1', 'v2_mirrors': '', 'token_submit': tok})
            self.assertEqual(tn.v2_allow_http(), 'on')
        finally:
            tn.cli('phoromatic-client-phone-home', 'forbidden')
            tn.cli('phoromatic-v2-allow-http', 'off')

    def test_level_1_upload_to_the_download_cache_needs_the_token(self):
        name = 'tn-single-%s.txt' % tn.uuid.uuid4().hex[:6]
        no_token = self.b[1].multipart('/?caches', {'dc_submit': 'Upload'}, {'dc_upload': (name, b'x')})[2]
        self.assertIn('nothing was uploaded', no_token)
        self.assertNotIn(name, tn.cache_files())
        tok = self.b[1].token('/?caches')
        self.assertIn('File uploaded', self.b[1].multipart('/?caches', {'dc_submit': 'Upload', 'token_submit': tok}, {'dc_upload': (name, b'x')})[2])
        self.assertIn(name, tn.cache_files())
        tn.srv_sh("rm -f '/var/cache/benchy/download-cache/%s'" % name)

    def test_levels_2_3_and_10_never_see_or_change_server_settings(self):
        before = (tn.phone_home_policy(), tn.v2_allow_http(), tn.tls_mode_from_root())
        for lvl in (2, 3, 10):
            with self.subTest(level=lvl):
                page = self.b[lvl].get(SETTINGS)[2]
                for marker in ('HTTPS Certificate', 'name="client_phone_home"', 'name="v2_settings"', 'Native Agent API'):
                    self.assertNotIn(marker, page, marker)
                self.assertNotIn('name="dc_upload"', self.b[lvl].get('/?caches')[2])
                tok = self.b[lvl].token(SETTINGS)
                self.b[lvl].post(SETTINGS, {'client_phone_home': 'client_choice', 'v2_settings': '1', 'v2_allow_http': '1', 'v2_mirrors': 'https://x.example/',
                                            'https_action': 'bootstrap', 'token_submit': tok})
                name = 'tn-lvl%d.txt' % lvl
                self.b[lvl].multipart('/?caches', {'dc_submit': 'Upload', 'token_submit': self.b[lvl].token('/?caches')}, {'dc_upload': (name, b'x')})
                self.assertNotIn(name, tn.cache_files())
        self.assertEqual((tn.phone_home_policy(), tn.v2_allow_http(), tn.tls_mode_from_root()), before)


if __name__ == '__main__':
    unittest.main()
