"""Bundled windows test profiles on the Phoromatic server: the zips under ob-cache/openbenchmarking.org/windows/ are seeded into the scratch
path on any web request, so the Tests page, Build Test Suite and the schedule "Add A Test" details show them with a title and description
without OpenBenchmarking.org being reachable, and the unauthenticated OpenBenchmarking mirror (openbenchmarking-cache.php) serves them on.

Run through tests/private-repo/run.sh (http). The server container mounts the exported tree at /bench.
"""
import base64
import hashlib
import json
import os
import re
import subprocess
import unittest
import urllib.error
import urllib.parse
import urllib.request
import xml.dom.minidom
import zipfile

from test_private_repo_http import BASE, SRV, srv_sh
from test_private_repo_web import Browser, USER, PASS

BUNDLE = '/bench/ob-cache/openbenchmarking.org/windows'
MANIFEST = '/bench/ob-cache/MANIFEST-windows-profiles.sha256'
SCRATCH = '/var/cache/benchy/openbenchmarking.org'
PROFILES = '/var/lib/benchy/test-profiles'
LOCAL_BUNDLE = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '..', 'ob-cache', 'openbenchmarking.org', 'windows')
TITLES = {'apptimer': 'AppTimer', 'diskspd': 'Diskspd', 'euler3d': 'Euler3D', 'hdtunepro': 'HD Tune Pro', 'openssl': 'OpenSSL',
          'passmark': 'PassMark PerformanceTEST', 'pcmark10': 'PCMark 10', 'redis': 'Redis', 'winsat': 'WinSAT'}


def sha1_remote(path):
    return srv_sh("sha1sum '%s' 2>/dev/null | cut -d' ' -f1" % path).strip()


def mirror(query):
    """(status, body bytes) of an unauthenticated openbenchmarking-cache.php request."""
    try:
        r = urllib.request.urlopen(BASE + '/openbenchmarking-cache.php?' + query, timeout=60)
        return r.status, r.read()
    except urllib.error.HTTPError as e:
        return e.code, e.read()


class WindowsProfiles(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.admin = Browser().login(USER, PASS)
        # Like a long-running server: the pts/system/git profiles are already extracted, so only the windows ones are in question here
        cls.copied_profile_repos = [d for d in ('pts', 'system', 'git') if not srv_sh("test -d %s/%s && echo y" % (PROFILES, d)).strip()]
        srv_sh("mkdir -p %s && cp -rn /bench/ob-cache/test-profiles/. %s/" % (PROFILES, PROFILES))
        cls.bundled = sorted(srv_sh("ls %s" % BUNDLE).split())

    @classmethod
    def tearDownClass(cls):
        for d in cls.copied_profile_repos:
            srv_sh("rm -rf %s/%s" % (PROFILES, d))
        srv_sh("rm -f %s/windows/*.orig %s/private.index" % (SCRATCH, SCRATCH))

    def test_bundle_is_sane(self):
        self.assertGreaterEqual(len(self.bundled), 9)
        self.assertTrue(os.path.isdir(LOCAL_BUNDLE), 'the bundle must be reachable from the test tree: ' + LOCAL_BUNDLE)
        self.assertEqual(sorted(os.listdir(LOCAL_BUNDLE)), self.bundled)
        for name in self.bundled:
            self.assertTrue(name.endswith('.zip'), name)
            with zipfile.ZipFile(os.path.join(LOCAL_BUNDLE, name)) as z:
                self.assertIsNone(z.testzip(), name)
                for entry in z.namelist():
                    self.assertTrue('/' not in entry and '\\' not in entry and '..' not in entry and not os.path.isabs(entry), '%s: %s' % (name, entry))
                xml.dom.minidom.parseString(z.read('test-definition.xml'))

    def test_manifest_matches_the_bundled_zips(self):
        entries = {}
        text = srv_sh("cat %s" % MANIFEST)
        self.assertIn('Provenance: fetched', text)
        for line in text.splitlines():
            if line and not line.startswith('#'):
                digest, name = line.split(None, 1)
                entries[name.strip()] = digest
        self.assertEqual(sorted(entries), self.bundled)
        for name, digest in entries.items():
            self.assertEqual(hashlib.sha256(open(os.path.join(LOCAL_BUNDLE, name), 'rb').read()).hexdigest(), digest, name)
            self.assertEqual(srv_sh("sha256sum %s/%s | cut -d' ' -f1" % (BUNDLE, name)).strip(), digest, name)

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
        self.admin.get('/?tests')
        z = SCRATCH + '/windows/diskspd-1.0.0.zip'
        keep = '/tmp/diskspd-1.0.0.zip.orig'          # outside the scratch dir, so a failing run leaves nothing behind in it
        srv_sh("cp %s %s && printf kept > %s" % (z, keep, z))
        try:
            self.admin.get('/?tests')
            self.assertEqual(srv_sh("cat %s" % z), 'kept')
        finally:
            srv_sh("mv %s %s" % (keep, z))
        srv_sh("rm -f %s" % z)
        self.admin.get('/?tests')
        self.assertEqual(sha1_remote(z), sha1_remote(BUNDLE + '/diskspd-1.0.0.zip'))
        self.assertEqual(srv_sh("ls %s/windows | grep -c tmp" % SCRATCH).strip(), '0')

    def test_scratch_windows_dir_holds_only_the_zips(self):
        self.admin.get('/?tests')
        self.assertEqual(srv_sh("ls %s/windows | grep -vc '\\.zip$'" % SCRATCH).strip(), '0')
        self.assertEqual(srv_sh("ls %s/windows | wc -l" % SCRATCH).strip(), str(len(self.bundled)))

    def test_linux_cli_client_gets_no_windows_repository(self):
        """First run of a Linux CLI client copies the whole bundle; the windows index and zips must not stay behind."""
        out = subprocess.run(['docker', 'exec', '-u', '1000', '-e', 'HOME=/home/bob', SRV, 'sh', '-c',
                              'rm -rf $HOME/.benchy; sh /bench/benchy list-available-tests </dev/null >/dev/null 2>&1; '
                              'ls $HOME/.benchy/openbenchmarking.org; rm -rf $HOME/.benchy'], capture_output=True, text=True).stdout.split()
        self.assertIn('pts.index', out)
        self.assertNotIn('windows', out)
        self.assertNotIn('windows.index', out)

    def test_non_server_context_does_not_seed(self):
        """seed_bundled_indexes() outside the Phoromatic server (PTS_MODE CLIENT on Linux) seeds the official indexes but no windows ones."""
        php = ('define("PTS_MODE","CLIENT");define("PTS_AUTO_LOAD_OBJECTS",true);include("/bench/pts-core/pts-core.php");pts_core::init();'
               'pts_openbenchmarking::seed_bundled_indexes();')
        run = subprocess.run(['docker', 'exec', '-u', '1000', '-e', 'HOME=/home/bob', SRV, 'sh', '-c',
                              'rm -rf $HOME/.benchy; mkdir -p $HOME/.benchy/openbenchmarking.org; php -r \'%s\'; ls $HOME/.benchy/openbenchmarking.org; rm -rf $HOME/.benchy' % php],
                             capture_output=True, text=True)
        listing = run.stdout.split()
        self.assertIn('pts.index', listing, run.stdout + run.stderr)        # not vacuous: the official indexes were seeded
        self.assertNotIn('windows', listing)
        self.assertNotIn('windows.index', listing)


class OutdatedExemptSetting(unittest.TestCase):
    """OutdatedTestsExemptRepos: repositories whose old profiles the Tests page keeps listing (default windows)."""

    @classmethod
    def setUpClass(cls):
        cls.admin = Browser().login(USER, PASS)
        cls.copied_profile_repos = [d for d in ('pts', 'system', 'git') if not srv_sh("test -d %s/%s && echo y" % (PROFILES, d)).strip()]
        srv_sh("mkdir -p %s && cp -rn /bench/ob-cache/test-profiles/. %s/" % (PROFILES, PROFILES))
        cls.admin.get('/?tests')
        cls.had_config = bool(srv_sh("test -f /etc/phoronix-test-suite.xml && echo y").strip())

    @classmethod
    def tearDownClass(cls):
        cls.set('windows')
        for d in cls.copied_profile_repos:
            srv_sh("rm -rf %s/%s" % (PROFILES, d))

    @classmethod
    def set(cls, value):
        out = srv_sh("sh /bench/benchy user-config-set 'OutdatedTestsExemptRepos=%s' 2>&1" % value)
        assert 'New user configuration file written' in out, out

    def tiles(self, cookie=None):
        headers = None
        if cookie:                                       # keep the session cookie, add the page's own toggle cookie
            headers = {'Cookie': '; '.join('%s=%s' % (c.name, c.value) for c in self.admin.jar) + '; ' + cookie}
        _, _, body = self.admin.get('/?tests', headers=headers)
        return re.findall(r'href="/\?tests/([a-z0-9_-]+)/[^"]*"><div class="table_test_box">', body)

    def count(self, repo, cookie=None):
        return self.tiles(cookie).count(repo)

    def test_default_shows_windows_profiles_and_cookie_toggle_still_works(self):
        self.set('windows')
        self.assertEqual(self.count('windows'), len(TITLES))
        baseline = self.count('pts')
        self.assertEqual(self.count('windows', 'include_outdated_tests=1'), len(TITLES))
        self.assertGreaterEqual(self.count('pts', 'include_outdated_tests=1'), baseline)

    def test_none_hides_them_again_unless_the_cookie_is_ticked_and_other_repos_are_unaffected(self):
        self.set('windows')
        pts_before, system_before = self.count('pts'), self.count('system')
        self.set('none')
        self.assertEqual(self.count('windows'), 0)
        self.assertEqual(self.count('windows', 'include_outdated_tests=1'), len(TITLES))
        self.assertEqual((self.count('pts'), self.count('system')), (pts_before, system_before))
        self.set('off')
        self.assertEqual(self.count('windows'), 0)

    def test_list_exempts_each_named_repository(self):
        self.set('none')
        pts_without = self.count('pts')
        self.set('windows,pts')
        self.assertEqual(self.count('windows'), len(TITLES))
        self.assertGreaterEqual(self.count('pts'), pts_without)
        self.assertEqual(self.count('pts'), self.count('pts', 'include_outdated_tests=1'))     # nothing old left hidden for pts

    def test_junk_is_ignored(self):
        self.set('WINDOWS,,..x,a.b,@@,-')
        self.assertEqual(self.count('windows'), len(TITLES))
        self.set('..x,a.b,@@,-')
        self.assertEqual(self.count('windows'), 0)

    def test_empty_value_falls_back_to_the_default(self):
        self.set('')
        self.assertEqual(self.count('windows'), len(TITLES))


class Mirror(unittest.TestCase):
    """openbenchmarking-cache.php is unauthenticated: it serves the OpenBenchmarking repositories, never the private one."""

    @classmethod
    def setUpClass(cls):
        Browser().login(USER, PASS).get('/?tests')      # seed
        srv_sh("printf '%%s' '{\"main\":{\"generated\":1},\"tests\":{}}' > %s/private.index" % SCRATCH)

    @classmethod
    def tearDownClass(cls):
        srv_sh("rm -f %s/private.index" % SCRATCH)

    def test_windows_zip_is_served_base64(self):
        status, body = mirror('test&repo=windows&test=diskspd-1.1.0')
        self.assertEqual(status, 200)
        self.assertEqual(hashlib.sha1(base64.b64decode(body)).hexdigest(), sha1_remote(BUNDLE + '/diskspd-1.1.0.zip'))

    def test_index_is_served(self):
        status, body = mirror('index&repo=windows')
        self.assertEqual(status, 200)
        self.assertIn('diskspd', json.loads(body)['tests'])

    def test_repos_lists_repositories_but_not_the_private_one(self):
        status, body = mirror('repos')
        repos = json.loads(body)['repos']
        for r in ('windows', 'pts', 'system', 'git'):
            self.assertIn(r, repos)
        self.assertNotIn('private', [k.lower() for k in repos])

    def test_private_repository_is_refused_on_every_route(self):
        for q in ['index&repo=private', 'index&repo=PRIVATE', 'index&repo=pri/vate', 'index&repo=priv..ate', 'test&repo=private&test=x-1.0.0',
                  'test&repo=windows&test=private/x-1.0.0', 'suite&repo=private&test=x', 'is_test&test=private', 'is_test&test=private/x-1.0.0',
                  'is_test&test=pri/vate', 'is_test&test=priv..ate']:
            status, body = mirror(q)
            self.assertEqual(body, b'', q)

    def test_traversal_is_refused(self):
        real = mirror('test&repo=windows&test=diskspd-1.1.0')[1]
        for q in ['test&repo=..&test=windows/diskspd-1.1.0', 'test&repo=windows&test=../windows/diskspd-1.1.0', 'test&repo=windows/..&test=x',
                  'test&repo=windows&test=..%2f..%2fwindows.index', 'index&repo=../windows',
                  'test&repo=..%2f..%2f..%2fetc&test=passwd']:
            status, body = mirror(q)
            self.assertNotEqual(body, real, q)
            try:
                decoded = base64.b64decode(body + b'=' * (-len(body) % 4)) if body else b''
            except ValueError:
                decoded = body
            self.assertNotIn(b'root:', decoded, q)
        self.assertEqual(mirror('test&repo=windows&test=..%2f..%2fwindows.index')[1], b'')
        # '..' and '/' are stripped, never followed: this stays inside <repo>/ and is the same file as the plain request
        self.assertEqual(mirror('suite&repo=windows&test=../diskspd-1.1.0')[1], real)

    def test_non_string_params_exit(self):
        for q in ['index&repo[]=windows', 'test&repo=windows&test[]=diskspd-1.1.0', 'is_test&test[]=x', 'test&repo[x]=windows&test=diskspd-1.1.0']:
            status, body = mirror(q)
            self.assertEqual(body, b'', q)

    def test_control_characters_and_nul_exit_quietly(self):
        # a NUL in the name made realpath() throw an uncaught ValueError (a 500 with a stack trace in the response)
        for q in ['test&repo=windows&test=diskspd%001.1.0', 'test&repo=windows%00&test=diskspd-1.1.0', 'test&repo=%00&test=x', 'suite&repo=windows&test=a%00b',
                  'is_test&test=windows/a%00b', 'index&repo=windows%00', 'test&repo=windows&test=a%0ab', 'test&repo=win%1fdows&test=x', 'is_test&test=%7f']:
            status, body = mirror(q)
            self.assertEqual(status, 200, q)
            self.assertEqual(body, b'', q)

    def test_unknown_repository_names_never_trigger_an_outbound_refresh(self):
        # Looking a test up under an unknown repository name used to call refresh_repository_lists(), which contacts
        # OpenBenchmarking.org for every linked repository whose index is missing or stale. Those calls create <scratch>/<repo>/ first,
        # so remove those directories and check that no request brings them back.
        refreshed = ('pts', 'system', 'git')
        for r in refreshed:
            srv_sh('rm -rf %s/%s %s/%s.index' % (SCRATCH, r, SCRATCH, r))
        for q in ['test&repo=windowspts&test=diskspd-1.1.0', 'test&repo=nosuchrepo&test=x-1.0.0', 'test&repo=&test=x', 'is_test&test=windowspts/diskspd-1.1.0',
                  'is_test&test=nosuchrepo/x', 'test&repo=WINDOWSPTS&test=x-1.0.0']:
            with self.subTest(q):
                status, body = mirror(q)
                self.assertEqual(body if q.startswith('test') else body.strip(), b'')
                for r in refreshed:
                    self.assertEqual(srv_sh('ls -d %s/%s %s/%s.index 2>/dev/null' % (SCRATCH, r, SCRATCH, r)).strip(), '', '%s refreshed %s' % (q, r))

    def test_known_repositories_match_case_insensitively(self):
        real = mirror('test&repo=windows&test=diskspd-1.1.0')[1]
        self.assertTrue(real)
        for repo in ('WINDOWS', 'Windows'):
            self.assertEqual(mirror('test&repo=%s&test=diskspd-1.1.0' % repo)[1], real, repo)


if __name__ == '__main__':
    unittest.main()
