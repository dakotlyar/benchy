"""The HTTPS expiry banner goes to server administrators (any account level, and root), not to every level-1 user (Benchy 0.9.13).

A certificate near its end is faked the way the server sees one: HTTPS in file mode and a cached handshake result that ends tomorrow. Both are
put back afterwards."""
import unittest

import tn

BANNER = '<strong>HTTPS:</strong>'


class ExpiryBannerFollowsTheServerRole(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.st = tn.state()
        cls.b = tn.logins('T1')
        cls.root = tn.Browser().login_root()
        tn.php_boot("$s = phoromatic_tls::settings(); $s['mode'] = 'file'; phoromatic_tls::save_settings($s); "
                    "file_put_contents(phoromatic_tls::tls_path() . 'live-cert.cache', json_encode(array('info' => array('subject' => 'CN=qc', 'sans' => array('qc.example'), 'issuer' => 'CN=qc', 'not_after' => time() + 86400, 'sha256' => str_repeat('ab', 32)))));")

    @classmethod
    def tearDownClass(cls):
        tn.php_boot("$s = phoromatic_tls::settings(); $s['mode'] = 'off'; phoromatic_tls::save_settings($s); @unlink(phoromatic_tls::tls_path() . 'live-cert.cache');")

    def test_a_level_1_user_without_the_role_sees_no_banner_and_a_level_3_server_administrator_does(self):
        user3 = self.st['users']['T1']['3']
        self.addCleanup(tn.cli, 'server-admin-revoke', user3)
        for lvl in tn.LEVELS:
            self.assertNotIn(BANNER, self.b[lvl].get('/?main')[2], 'level %d, no role' % lvl)
        self.assertIn('Done', tn.cli('server-admin-grant', user3))
        page = self.b[3].get('/?main')[2]
        self.assertIn(BANNER, page)
        self.assertIn('expires on', page)
        self.assertNotIn(BANNER, self.b[1].get('/?main')[2], 'the level-1 user still has no role')
        tn.cli('server-admin-revoke', user3)
        self.assertNotIn(BANNER, self.b[3].get('/?main')[2], 'the next request after a revoke')

    def test_root_sees_it_on_the_admin_page(self):
        self.assertIn(BANNER, self.root.get('/?admin')[2])


if __name__ == '__main__':
    unittest.main()
