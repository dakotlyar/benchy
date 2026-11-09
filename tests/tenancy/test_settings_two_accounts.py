"""Server-wide settings once the server has a second account (N10, N11): HTTPS certificate, phone-home policy, agent API settings and mirrors, and
the download cache are for server administrators only. No tenant level 1 is one any more; the root login still is."""
import unittest

import tn

SETTINGS = '/?settings'
MARKERS = ('HTTPS Certificate', 'name="client_phone_home"', 'name="v2_settings"', 'Native Agent API', 'name="https_action"')


class ServerSettingsAreServerAdminOnly(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.b = tn.logins('T1')

    def test_no_tenant_level_sees_a_server_setting_form(self):
        for lvl in tn.LEVELS:
            with self.subTest(level=lvl):
                page = self.b[lvl].get(SETTINGS)[2]
                for marker in MARKERS:
                    self.assertNotIn(marker, page, marker)

    def test_posting_the_forms_anyway_changes_nothing(self):
        # N11: form absent, POST refused, value unchanged (a valid token and the right origin, so only the permission is in the way)
        before = (tn.phone_home_policy(), tn.v2_allow_http(), tn.tls_mode_from_root())
        for lvl in tn.LEVELS:
            with self.subTest(level=lvl):
                tok = self.b[lvl].token(SETTINGS)
                for fields in ({'https_action': 'bootstrap'}, {'client_phone_home': 'client_choice'}, {'v2_settings': '1', 'v2_allow_http': '1', 'v2_mirrors': 'https://x.example/'}):
                    self.b[lvl].post(SETTINGS, dict(fields, token_submit=tok))
        self.assertEqual((tn.phone_home_policy(), tn.v2_allow_http(), tn.tls_mode_from_root()), before)

    def test_the_download_cache_takes_no_upload_from_a_tenant_level(self):
        # N10: refused at every tenant level; and a missing token is refused too
        for lvl in tn.LEVELS:
            with self.subTest(level=lvl):
                self.assertNotIn('name="dc_upload"', self.b[lvl].get('/?caches')[2])
                name = 'tn-two-%d.txt' % lvl
                self.b[lvl].multipart('/?caches', {'dc_submit': 'Upload', 'token_submit': self.b[lvl].token(SETTINGS)}, {'dc_upload': (name, b'x')})
                self.b[lvl].multipart('/?caches', {'dc_submit': 'Upload'}, {'dc_upload': (name + '.notoken', b'x')})
                files = tn.cache_files()
                self.assertNotIn(name, files)
                self.assertNotIn(name + '.notoken', files)


if __name__ == '__main__':
    unittest.main()
