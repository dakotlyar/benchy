"""Bundled windows test profiles on the Phoromatic server: the zips under ob-cache/openbenchmarking.org/windows/ are seeded into the scratch
path on any web request, so the Tests page, Build Test Suite and the schedule "Add A Test" details show them with a title and description
without OpenBenchmarking.org being reachable.

Run through tests/private-repo/run.sh (http). The server container mounts the exported tree at /bench.
"""
import hashlib
import os
import re
import unittest
import zipfile
import xml.dom.minidom

from test_private_repo_http import srv_sh
from test_private_repo_web import Browser, USER, PASS

BUNDLE = '/bench/ob-cache/openbenchmarking.org/windows'
SCRATCH = '/var/cache/benchy/openbenchmarking.org'
PROFILES = '/var/lib/benchy/test-profiles'
TITLES = {'apptimer': 'AppTimer', 'diskspd': 'Diskspd', 'euler3d': 'Euler3D', 'hdtunepro': 'HD Tune Pro', 'openssl': 'OpenSSL',
          'passmark': 'PassMark PerformanceTEST', 'pcmark10': 'PCMark 10', 'redis': 'Redis', 'winsat': 'WinSAT'}


def sha1_remote(path):
    return srv_sh("sha1sum '%s' 2>/dev/null | cut -d' ' -f1" % path).strip()


class WindowsProfiles(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.admin = Browser().login(USER, PASS)
        # Like a long-running server: the pts/system/git profiles are already extracted, so only the windows ones are in question here
        cls.preseeded = [d for d in ('pts', 'system', 'git') if not srv_sh("test -d %s/%s && echo y" % (PROFILES, d)).strip()]
        srv_sh("mkdir -p %s && cp -rn /bench/ob-cache/test-profiles/. %s/" % (PROFILES, PROFILES))
        cls.bundled = sorted(srv_sh("ls %s" % BUNDLE).split())

    @classmethod
    def tearDownClass(cls):
        for d in cls.preseeded:
            srv_sh("rm -rf %s/%s" % (PROFILES, d))

    def test_bundle_is_sane(self):
        self.assertGreaterEqual(len(self.bundled), 9)
        for name in self.bundled:
            self.assertTrue(name.endswith('.zip'), name)
            local = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '..', 'ob-cache', 'openbenchmarking.org', 'windows', name)
            if os.path.exists(local):
                with zipfile.ZipFile(local) as z:
                    self.assertIsNone(z.testzip(), name)
                    xml.dom.minidom.parseString(z.read('test-definition.xml'))

    def test_pages_seed_and_list_windows_profiles(self):
        status, _, body = self.admin.get('/?tests')
        self.assertEqual(status, 200)
        for name in self.bundled:
            self.assertEqual(sha1_remote(SCRATCH + '/windows/' + name), sha1_remote(BUNDLE + '/' + name), name)
        for title in TITLES.values():
            self.assertIn('<strong>%s</strong>' % title, body, title)
        self.assertRegex(body, r'/\?tests/windows/diskspd-1\.1\.0"')
        # the existing repositories are still listed the same way
        self.assertRegex(body, r'/\?tests/pts/fio-[0-9.]+"')

    def test_add_test_details_have_title_and_description(self):
        _, _, body = self.admin.get('/?r_add_test_details/&tp=windows/diskspd')
        self.assertIn('<h2>Diskspd [windows/diskspd-1.1.0] </h2>', body)
        self.assertRegex(body, r'<p><em>Diskspd is an open-source storage testing software')
        self.assertIn('Windows', body)

    def test_build_suite_offers_windows_profiles(self):
        _, _, body = self.admin.get('/?build_suite')
        for name in TITLES:
            self.assertIn('windows/%s"' % name, body, name)

    def test_seeding_never_overwrites_and_reseeds_deleted(self):
        z = SCRATCH + '/windows/diskspd-1.0.0.zip'
        srv_sh("cp %s %s.orig && printf kept > %s" % (z, z, z))
        try:
            self.admin.get('/?tests')
            self.assertEqual(srv_sh("cat %s" % z), 'kept')
        finally:
            srv_sh("mv %s.orig %s" % (z, z))
        srv_sh("rm -f %s" % z)
        self.admin.get('/?tests')
        self.assertEqual(sha1_remote(z), sha1_remote(BUNDLE + '/diskspd-1.0.0.zip'))
        self.assertEqual(srv_sh("ls %s/windows | grep -c tmp" % SCRATCH).strip(), '0')

    def test_scratch_windows_dir_holds_only_the_zips(self):
        self.admin.get('/?tests')
        self.assertEqual(srv_sh("ls %s/windows | grep -vc '\\.zip$'" % SCRATCH).strip(), '0')


if __name__ == '__main__':
    unittest.main()
