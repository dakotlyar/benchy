"""HTTP-level QC for the private test-profile repository on a scratch Phoromatic server.

Run through tests/private-repo/run.sh (it provides the QC_* environment). Every test talks to the
server the way a client (or an attacker) would: form POSTs to /phoromatic.php and GETs against
openbenchmarking-cache.php.

Tests whose name ends in _KNOWN_BUG currently FAIL on purpose: they pin down defects found during QC
and should pass once those are fixed.
"""
import base64
import hashlib
import io
import json
import os
import subprocess
import threading
import unittest
import urllib.error
import urllib.parse
import urllib.request
import uuid
import zipfile

PORT = os.environ['QC_PORT']
AID = os.environ['QC_AID']
SRV = os.environ['QC_SRV']
BASE = 'http://127.0.0.1:%s' % PORT
CORE = '10860'
REPO_DIR = '/var/lib/benchy/phoromatic/accounts/%s/private-repo/' % AID


# ---------------------------------------------------------------- helpers
def sql(query):
    php = ('$d=new SQLite3("/var/lib/benchy/phoromatic/phoromatic.db");'
           '$r=$d->query($argv[1]); if($r===false||$r===true){exit;}'
           'while($x=$r->fetchArray(SQLITE3_ASSOC)){echo json_encode($x),"\\n";}')
    out = subprocess.run(['docker', 'exec', SRV, 'php', '-r', php, query], capture_output=True, text=True).stdout
    return [json.loads(l) for l in out.splitlines() if l.strip()]


def srv_sh(cmd):
    return subprocess.run(['docker', 'exec', SRV, 'sh', '-c', cmd], capture_output=True, text=True).stdout


def raw_post(path, fields, timeout=60):
    data = urllib.parse.urlencode(fields).encode()
    try:
        with urllib.request.urlopen(urllib.request.Request(BASE + path, data=data), timeout=timeout) as r:
            return r.read().decode('utf-8', 'replace')
    except urllib.error.HTTPError as e:
        return e.read().decode('utf-8', 'replace')


def parse(body):
    """The JSON reply, tolerating PHP notices printed before it."""
    i = body.find('{"phoromatic"')
    if i < 0:
        return {'_raw': body}
    return json.loads(body[i:])['phoromatic']


def new_machine(approved=True):
    msi = str(uuid.uuid4())
    # first contact registers the machine as "awaiting approval"
    parse(raw_post('/phoromatic.php', {'r': 'private_repo_index', 'aid': AID, 'msi': msi, 'pts_core': CORE}))
    if approved:
        sql("UPDATE phoromatic_systems SET State=1 WHERE MachineSelfID='%s'" % msi)
    return msi


def call(resource, msi, aid=None, **fields):
    f = {'r': resource, 'aid': AID if aid is None else aid, 'msi': msi, 'pts_core': CORE}
    f.update(fields)
    return parse(raw_post('/phoromatic.php', f))


def upload(msi, kind, name, zip_bytes, overwrite=False, sha1=None, b64=None):
    return call('private_repo_upload', msi, kind=kind, name=name,
                zip=b64 if b64 is not None else base64.b64encode(zip_bytes).decode(),
                zip_sha1=sha1 if sha1 is not None else hashlib.sha1(zip_bytes).hexdigest(),
                commit_description='qc', overwrite='1' if overwrite else '0')


TEST_XML = '''<?xml version="1.0"?>
<PhoronixTestSuite>
  <TestInformation><Title>%(title)s</Title><AppVersion>1.0</AppVersion><Description>d</Description>
    <ResultScale>Score</ResultScale><Proportion>HIB</Proportion><TimesToRun>1</TimesToRun></TestInformation>
  <TestProfile><Version>%(version)s</Version><SupportedPlatforms>Linux</SupportedPlatforms>
    <SoftwareType>Benchmark</SoftwareType><TestType>System</TestType><License>Free</License><Status>Verified</Status>
    <SupportedArchitectures>x86_64</SupportedArchitectures><EnvironmentSize>1</EnvironmentSize>
    <ProjectURL>https://example.invalid/</ProjectURL><Maintainer>QC</Maintainer></TestProfile>
  <TestSettings><Default><Arguments></Arguments></Default></TestSettings>
</PhoronixTestSuite>'''

SUITE_XML = '''<?xml version="1.0"?>
<PhoronixTestSuite>
  <SuiteInformation><Title>%(title)s</Title><Version>%(version)s</Version><TestType>System</TestType>
    <Description>d</Description><Maintainer>QC</Maintainer></SuiteInformation>
  %(tests)s
</PhoronixTestSuite>'''


def make_zip(entries):
    """entries: list of (name, bytes|str[, unix_mode_or_None]). Names are stored verbatim."""
    buf = io.BytesIO()
    with zipfile.ZipFile(buf, 'w', zipfile.ZIP_DEFLATED) as z:
        for e in entries:
            name, data = e[0], e[1]
            info = zipfile.ZipInfo(name)
            info.compress_type = zipfile.ZIP_DEFLATED
            info.create_system = 3
            info.external_attr = ((e[2] if len(e) > 2 and e[2] else 0o100644) << 16)
            z.writestr(info, data)
    return buf.getvalue()


def test_zip(version='1.0.0', title='QC Test', extra=(), xml=None):
    return make_zip([('test-definition.xml', xml or TEST_XML % {'title': title, 'version': version}),
                     ('install.sh', '#!/bin/sh\necho ok\n')] + list(extra))


def suite_zip(version='1.0.0', title='QC Suite', tests=('private/qc-base',)):
    body = ''.join('<Execute><Test>%s</Test></Execute>' % t for t in tests)
    return make_zip([('suite-definition.xml', SUITE_XML % {'title': title, 'version': version, 'tests': body})])


def uniq(prefix='qc'):
    return '%s-%s' % (prefix, uuid.uuid4().hex[:8])


class Base(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.msi = new_machine()


# ---------------------------------------------------------------- authentication / gate
class TestAuth(Base):
    RESOURCES = ['private_repo_index', 'private_repo_fetch', 'private_repo_upload', 'private_repo_delete', 'private_repo_rebuild']

    def payload(self, r):
        return {'kind': 'test', 'id': 'nope-1.0.0', 'name': 'nope', 'version': '1.0.0', 'zip': 'AAAA', 'zip_sha1': 'x'}

    def test_no_credentials(self):
        for r in self.RESOURCES:
            with self.subTest(r):
                out = parse(raw_post('/phoromatic.php', dict(self.payload(r), r=r, pts_core=CORE)))
                self.assertIn('error', out)
                self.assertNotIn('index', out)

    def test_bad_account_id(self):
        for r in self.RESOURCES:
            with self.subTest(r):
                out = call(r, self.msi, aid='ZZZZZZ', **self.payload(r))
                self.assertEqual(out.get('error'), 'Invalid User')

    def test_account_id_path_tricks(self):
        for aid in ['../', '..', '4PF/../4PF', AID + '/../' + AID, AID + '%00', '']:
            with self.subTest(aid):
                out = call('private_repo_index', self.msi, aid=aid)
                self.assertNotIn('index', out)

    def test_missing_msi(self):
        for r in self.RESOURCES:
            with self.subTest(r):
                out = parse(raw_post('/phoromatic.php', dict(self.payload(r), r=r, aid=AID, pts_core=CORE)))
                self.assertIn('error', out)

    def test_unknown_then_unapproved_machine(self):
        for r in self.RESOURCES:
            with self.subTest(r):
                msi = new_machine(approved=False)
                for _ in range(2):  # first contact, then repeat contact from the same unapproved machine
                    out = call(r, msi, **self.payload(r))
                    self.assertNotIn('index', out)
                    self.assertNotIn('upload', out)
                    self.assertNotIn('zip', out)
                    self.assertNotIn('delete', out)
                    self.assertNotIn('rebuild', out)
                    self.assertIn('Waiting For Approval', out.get('response', ''))

    def test_unapproved_machine_cannot_upload(self):
        msi = new_machine(approved=False)
        name = uniq('unappr')
        out = upload(msi, 'test', name, test_zip())
        self.assertNotIn('upload', out)
        self.assertEqual(srv_sh('ls %stests/%s 2>/dev/null' % (REPO_DIR, name)).strip(), '')

    def test_old_core_version_rejected(self):
        out = parse(raw_post('/phoromatic.php', {'r': 'private_repo_index', 'aid': AID, 'msi': self.msi, 'pts_core': '100'}))
        self.assertIn('must update', out.get('error', ''))

    def test_unknown_resource_and_resource_traversal(self):
        self.assertIn('Unknown Resource', call('private_repo_nothing', self.msi).get('error', ''))
        out = call('../communication-resources/private_repo_index', self.msi)  # stays inside the resources dir, so allowed upstream
        self.assertIsInstance(out, dict)

    def test_get_parameters_do_not_bypass_post_handling(self):
        # upload/fetch/delete read $_POST; a GET must not be able to drive them
        for r, qs in [('private_repo_upload', 'kind=test&name=getonly&zip=AAAA'), ('private_repo_delete', 'kind=test&name=x&version=1.0.0')]:
            with self.subTest(r):
                url = '%s/phoromatic.php?r=%s&aid=%s&msi=%s&pts_core=%s&%s' % (BASE, r, AID, self.msi, CORE, qs)
                body = urllib.request.urlopen(url, timeout=30).read().decode()
                self.assertNotIn('"upload"', body)
                self.assertNotIn('"delete"', body)

    def test_second_account_is_isolated(self):
        name = uniq('iso')
        self.assertIn('upload', upload(self.msi, 'test', name, test_zip()))
        # a second account on the same server, with its own approved machine
        s = srv_sh('curl -s -c /tmp/cj_iso localhost:8080/ >/dev/null; curl -s -b /tmp/cj_iso -X POST "localhost:8080/?register" '
                   '-d register_username=isolator -d register_password=qcpass123 -d register_password_confirm=qcpass123 -d register_email=i@example.com')
        rows = sql("SELECT AccountID FROM phoromatic_accounts ORDER BY CreatedOn DESC")
        other = [r['AccountID'] for r in rows if r['AccountID'] != AID][0]
        msi2 = str(uuid.uuid4())
        call('private_repo_index', msi2, aid=other)
        sql("UPDATE phoromatic_systems SET State=1 WHERE MachineSelfID='%s'" % msi2)
        idx = call('private_repo_index', msi2, aid=other)['index']
        self.assertNotIn(name, idx['tests'])
        self.assertIn('error', call('private_repo_fetch', msi2, aid=other, kind='test', id=name + '-1.0.0'))
        # machine of account 1 presenting account 2's id is just an unknown/unapproved machine there
        self.assertNotIn('index', call('private_repo_index', self.msi, aid=other))
        # and account 2's machine presenting account 1's id
        self.assertNotIn('index', call('private_repo_index', msi2, aid=AID))


# ---------------------------------------------------------------- unauthenticated mirror
class TestMirrorRefusesPrivate(Base):
    """openbenchmarking-cache.php is unauthenticated and must never return anything from private/."""

    @classmethod
    def setUpClass(cls):
        super().setUpClass()
        cls.name = uniq('mirror')
        assert 'upload' in upload(cls.msi, 'test', cls.name, test_zip())
        assert 'upload' in upload(cls.msi, 'suite', cls.name + '-s', suite_zip(tests=('private/' + cls.name,)))

    def get(self, qs):
        try:
            with urllib.request.urlopen('%s/openbenchmarking-cache.php?%s' % (BASE, qs), timeout=60) as r:
                return r.read().decode('utf-8', 'replace')
        except urllib.error.HTTPError as e:
            return e.read().decode('utf-8', 'replace')

    def assertNoLeak(self, body, what):
        self.assertNotIn(self.name, body, what + ' leaked the private repo name')
        self.assertFalse(body.strip().startswith('UEsD'), what + ' returned a zip (base64 PK header)')
        self.assertNotIn('"versions"', body, what + ' returned an index')

    def test_direct_routes(self):
        n = self.name
        for qs in ['index&repo=private', 'test=%s-1.0.0&repo=private' % n, 'test=private/%s-1.0.0' % n,
                   'suite&test=%s-s-1.0.0&repo=private' % n, 'is_test&test=private/%s' % n, 'is_test&test=private/%s-1.0.0' % n,
                   'repos']:
            with self.subTest(qs):
                self.assertNoLeak(self.get(qs), qs)

    def test_repo_name_normalisation_bypass_KNOWN_BUG(self):
        # The refusal compares the raw parameter, but the handlers strip '/' and '..' before using it, so
        # 'pri/vate' and 'priv..ate' both resolve to 'private' after the check.
        n = self.name
        for qs in ['index&repo=pri/vate', 'index&repo=priv..ate', 'index&repo=pri%2Fvate',
                   'test=%s-1.0.0&repo=pri/vate' % n, 'test=%s-1.0.0&repo=priv..ate' % n,
                   'suite&test=%s-s-1.0.0&repo=pri/vate' % n, 'suite&test=%s-s-1.0.0&repo=priv..ate' % n,
                   'is_test&test=pri/vate/%s' % n, 'is_test&test=priv..ate/%s' % n]:
            with self.subTest(qs):
                self.assertNoLeak(self.get(qs), qs)


# ---------------------------------------------------------------- upload validation
class TestUploadValidation(Base):
    def ok(self, out, msg=''):
        self.assertIn('upload', out, '%s -> %r' % (msg, out))

    def rejected(self, out, msg=''):
        self.assertIn('error', out, '%s was accepted: %r' % (msg, out))
        self.assertNotIn('upload', out)

    def stored(self, kind, name):
        return srv_sh('ls %s%ss/%s 2>/dev/null' % (REPO_DIR, kind, name)).strip()

    def test_valid_test_and_suite_roundtrip(self):
        name = uniq('ok')
        z = test_zip()
        out = upload(self.msi, 'test', name, z)
        self.ok(out)
        self.assertEqual(out['upload']['id'], 'private/%s-1.0.0' % name)
        self.assertEqual(out['upload']['sha1'], hashlib.sha1(z).hexdigest())
        idx = call('private_repo_index', self.msi)['index']
        self.assertEqual(idx['tests'][name]['versions'], ['1.0.0'])
        fetched = call('private_repo_fetch', self.msi, kind='test', id=name + '-1.0.0')
        self.assertEqual(base64.b64decode(fetched['zip']), z)
        self.assertEqual(fetched['sha1'], hashlib.sha1(z).hexdigest())
        sname = uniq('suite')
        self.ok(upload(self.msi, 'suite', sname, suite_zip(tests=('private/' + name,))))
        self.assertIn(sname, call('private_repo_index', self.msi)['index']['suites'])

    def test_duplicate_version_rejected_then_overwrite_keeps_trash(self):
        name = uniq('dup')
        self.ok(upload(self.msi, 'test', name, test_zip()))
        out = upload(self.msi, 'test', name, test_zip(title='changed'))
        self.rejected(out, 'duplicate version')
        self.assertIn('already exists', out['error'])
        out = upload(self.msi, 'test', name, test_zip(title='changed'), overwrite=True)
        self.ok(out)
        self.assertTrue(out['upload']['replaced'])
        self.assertIn('-test-%s-1.0.0.zip' % name, srv_sh('ls %strash/' % REPO_DIR))
        self.assertEqual(call('private_repo_index', self.msi)['index']['tests'][name]['title'], 'changed')

    def test_versions_sort_newest_first(self):
        name = uniq('ver')
        for v in ['1.0.0', '1.0.10', '1.0.9', '2.0.0']:
            self.ok(upload(self.msi, 'test', name, test_zip(version=v)))
        self.assertEqual(call('private_repo_index', self.msi)['index']['tests'][name]['versions'], ['2.0.0', '1.0.10', '1.0.9', '1.0.0'])

    def test_unsafe_zip_paths(self):
        cases = {
            'dot-dot': '../evil.txt', 'nested dot-dot': 'a/../../evil.txt', 'absolute': '/tmp/evil.txt',
            'backslash dot-dot': '..\\evil.txt', 'windows drive': 'C:evil.txt', 'drive abs': 'C:\\evil.txt',
            'backslash abs': '\\evil.txt', 'colon': 'a:b',
        }
        for label, entry in cases.items():
            with self.subTest(label):
                name = uniq('slip')
                self.rejected(upload(self.msi, 'test', name, test_zip(extra=[(entry, 'x')])), label)
                self.assertEqual(self.stored('test', name), '')
        self.assertEqual(srv_sh('ls /tmp/evil.txt /var/lib/benchy/evil.txt %sevil.txt 2>/dev/null' % REPO_DIR).strip(), '')

    def test_backslash_separators_are_normalised_not_rejected(self):
        # zips written on Windows may use backslashes for subdirectories; the server rewrites them
        name = uniq('bs')
        out = upload(self.msi, 'test', name, test_zip(extra=[('sub\\dir\\file.txt', 'x')]))
        self.ok(out)
        z = zipfile.ZipFile(io.BytesIO(base64.b64decode(call('private_repo_fetch', self.msi, kind='test', id=name + '-1.0.0')['zip'])))
        self.assertIn('sub/dir/file.txt', z.namelist())
        self.assertFalse([n for n in z.namelist() if '\\' in n])

    def test_symlink_entry(self):
        name = uniq('sym')
        self.rejected(upload(self.msi, 'test', name, test_zip(extra=[('link', '/etc/passwd', 0o120777)])), 'symlink')

    def test_zip_bomb(self):
        name = uniq('bomb')
        big = test_zip(extra=[('zeros.bin', b'\0' * (30 * 1024 * 1024))])
        self.assertLess(len(big), 5 * 1024 * 1024, 'fixture should be small compressed')
        out = upload(self.msi, 'test', name, big)
        self.rejected(out, 'zip bomb')
        self.assertIn('expands', out['error'])
        # right under the 25 MB limit is fine
        self.ok(upload(self.msi, 'test', uniq('bigok'), test_zip(extra=[('zeros.bin', b'\0' * (20 * 1024 * 1024))])), '20MB expanded')

    def test_too_many_entries(self):
        name = uniq('many')
        self.rejected(upload(self.msi, 'test', name, test_zip(extra=[('f%d' % i, 'x') for i in range(520)])), '>500 entries')
        self.ok(upload(self.msi, 'test', uniq('many'), test_zip(extra=[('f%d' % i, 'x') for i in range(490)])), '492 entries')

    def test_size_cap_5mb(self):
        import random
        rnd = random.Random(1)
        big = test_zip(extra=[('blob.bin', bytes(rnd.getrandbits(8) for _ in range(6 * 1024 * 1024)))])
        self.assertGreater(len(big), 5 * 1024 * 1024)
        out = upload(self.msi, 'test', uniq('big'), big)
        self.rejected(out, '>5MB')
        self.assertIn('MB', out['error'])
        # just under the cap (4.5 MB of incompressible data) still goes through
        ok = test_zip(extra=[('blob.bin', bytes(rnd.getrandbits(8) for _ in range(int(4.5 * 1024 * 1024))))])
        self.assertLess(len(ok), 5 * 1024 * 1024)
        self.ok(upload(self.msi, 'test', uniq('nearcap'), ok), '4.5MB')

    def test_crlf_shell_scripts(self):
        for entry in ['install.sh', 'sub/run.sh', 'install_windows.sh']:
            with self.subTest(entry):
                out = upload(self.msi, 'test', uniq('crlf'), test_zip(extra=[(entry, '#!/bin/sh\r\necho hi\r\n')]))
                self.rejected(out, 'CRLF ' + entry)
                self.assertIn('CRLF', out['error'])
        # CRLF is fine in non-.sh files (e.g. PowerShell scripts, XML)
        self.ok(upload(self.msi, 'test', uniq('crlfok'), test_zip(extra=[('x.ps1', 'Write-Host hi\r\n')])), 'CRLF in .ps1')

    def test_crlf_in_test_definition_xml_is_fine(self):
        self.ok(upload(self.msi, 'test', uniq('crlfxml'), test_zip(xml=(TEST_XML % {'title': 'tt', 'version': '1.0.0'}).replace('\n', '\r\n'))))

    def test_bad_archives(self):
        self.rejected(upload(self.msi, 'test', uniq('bad'), b'not a zip at all'), 'garbage')
        self.rejected(upload(self.msi, 'test', uniq('bad'), b''), 'empty')
        self.rejected(upload(self.msi, 'test', uniq('bad'), make_zip([('readme.txt', 'x')])), 'no definition')
        self.rejected(upload(self.msi, 'test', uniq('bad'), make_zip([('sub/test-definition.xml', TEST_XML % {'title': 't', 'version': '1.0.0'})])), 'definition not at root')
        self.rejected(upload(self.msi, 'suite', uniq('bad'), test_zip()), 'test zip uploaded as suite')
        self.rejected(upload(self.msi, 'test', uniq('bad'), suite_zip()), 'suite zip uploaded as test')
        trunc = test_zip()[:-30]
        self.rejected(upload(self.msi, 'test', uniq('bad'), trunc), 'truncated zip')

    def test_bad_definition_xml(self):
        for label, xml in [('malformed', '<PhoronixTestSuite><oops>'), ('wrong schema', '<PhoronixTestSuite><Nope/></PhoronixTestSuite>'),
                           ('not xml', 'hello')]:
            with self.subTest(label):
                self.rejected(upload(self.msi, 'test', uniq('badxml'), make_zip([('test-definition.xml', xml)])), label)

    def test_empty_definition_xml_is_a_clean_error_KNOWN_BUG(self):
        # DOMDocument::loadXML('') throws ValueError on PHP 8, which surfaces as an uncaught fatal and leaves upload-*.zip behind
        before = srv_sh('ls %s | grep -c "^upload-"' % REPO_DIR).strip()
        out = upload(self.msi, 'test', uniq('emptyxml'), make_zip([('test-definition.xml', '')]))
        self.assertIn('error', out, out)
        self.assertEqual(srv_sh('ls %s | grep -c "^upload-"' % REPO_DIR).strip(), before)

    def test_xxe_and_entity_expansion_do_not_leak(self):
        xml = ('<?xml version="1.0"?><!DOCTYPE r [<!ENTITY x SYSTEM "file:///etc/passwd">]>\n'
               + (TEST_XML % {'title': '&x;', 'version': '1.0.0'}).split('?>', 1)[1])
        name = uniq('xxe')
        out = upload(self.msi, 'test', name, make_zip([('test-definition.xml', xml)]))
        if 'upload' in out:
            meta = json.dumps(call('private_repo_index', self.msi)['index'])
            self.assertNotIn('root:', meta)
        billion = ('<?xml version="1.0"?><!DOCTYPE r [<!ENTITY a "aaaaaaaaaa"><!ENTITY b "&a;&a;&a;&a;&a;&a;&a;&a;&a;&a;">'
                   '<!ENTITY c "&b;&b;&b;&b;&b;&b;&b;&b;&b;&b;"><!ENTITY d "&c;&c;&c;&c;&c;&c;&c;&c;&c;&c;">]>\n'
                   + (TEST_XML % {'title': '&d;', 'version': '1.0.0'}).split('?>', 1)[1])
        upload(self.msi, 'test', uniq('lol'), make_zip([('test-definition.xml', billion)]))  # must simply not hang/crash
        self.assertIn('index', call('private_repo_index', self.msi))

    def test_bad_versions(self):
        for v in ['1.0', '1', '1.0.0.0', 'a.b.c', '1.0.0-beta', '../1.0.0', '1.0.0/../2.0.0', ' 1.0.0', '', '123456.0.0', '1.2.3\n']:
            with self.subTest(repr(v)):
                out = upload(self.msi, 'test', uniq('ver'), test_zip(version=v))
                self.rejected(out, 'version %r' % v)

    def test_bad_names(self):
        for n in ['', 'Foo', 'foo bar', 'foo/bar', '../x', '..', '.', '-x', 'foo_bar', 'foo.bar', 'foo-1.0.0', 'x-123', 'a' * 0, 'foo\x00bar',
                  'foo\n', 'FOO', 'fo\u00f6', 'foo;rm', '$(id)', 'foo*']:
            with self.subTest(repr(n)):
                self.rejected(upload(self.msi, 'test', n, test_zip()), 'name %r' % n)
        self.assertEqual(srv_sh('ls %stests | grep -c "[^a-z0-9-]"' % REPO_DIR).strip() in ('0', ''), True)

    def test_bad_kind_and_fields(self):
        z = test_zip()
        for kind in ['', 'tests', 'TEST', '../x', 'test\x00']:
            with self.subTest(kind):
                self.rejected(upload(self.msi, kind, uniq('k'), z), 'kind %r' % kind)
        self.rejected(call('private_repo_upload', self.msi), 'no fields at all')
        self.rejected(upload(self.msi, 'test', uniq('sha'), z, sha1='0' * 40), 'wrong sha1')
        self.rejected(upload(self.msi, 'test', uniq('sha'), z, sha1=''), 'empty sha1')
        self.rejected(upload(self.msi, 'test', uniq('b64'), z, b64='!!!not base64!!!'), 'bad base64')
        self.rejected(upload(self.msi, 'test', uniq('b64'), z, b64=base64.b64encode(z).decode()[:-7]), 'truncated base64')

    def test_array_valued_fields_do_not_crash_KNOWN_BUG(self):
        # kind[]=..., name[]=... style type confusion
        for f in [{'kind[]': 'test'}, {'name[]': 'x'}, {'zip[]': 'AAAA'}, {'zip_sha1[]': 'x'}, {'commit_description[]': 'x'}]:
            with self.subTest(list(f)):
                base = {'kind': 'test', 'name': uniq('arr'), 'zip': base64.b64encode(test_zip()).decode(),
                        'zip_sha1': hashlib.sha1(test_zip()).hexdigest()}
                for k in list(f):
                    base.pop(k[:-2], None)
                base.update(f)
                body = raw_post('/phoromatic.php', dict({'r': 'private_repo_upload', 'aid': AID, 'msi': self.msi, 'pts_core': CORE}, **base))
                self.assertNotIn('Fatal error', body, body[:300])
        self.assertIn('index', call('private_repo_index', self.msi))

    def test_name_in_zip_cannot_redirect_storage(self):
        # the stored path comes from the request name and the XML version only
        name = uniq('redir')
        out = upload(self.msi, 'test', name, test_zip())
        self.ok(out)
        self.assertEqual(srv_sh('ls %stests/%s' % (REPO_DIR, name)).split(), ['1.0.0.json', '1.0.0.zip'])

    def test_suite_warnings_for_unresolvable_tests(self):
        out = upload(self.msi, 'suite', uniq('warn'), suite_zip(tests=('local/whatever', 'private/does-not-exist', 'pts/definitely-not-a-test-xyz')))
        self.ok(out)
        self.assertEqual(len(out['upload']['warnings']), 3, out['upload']['warnings'])

    def test_failed_upload_leaves_no_temp_files(self):
        before = srv_sh('ls -a %s' % REPO_DIR).split()
        upload(self.msi, 'test', uniq('tmp'), test_zip(extra=[('../x', 'y')]))
        upload(self.msi, 'test', uniq('tmp'), test_zip(extra=[('a.sh', '\r\n')]))
        after = srv_sh('ls -a %s' % REPO_DIR).split()
        self.assertEqual([f for f in after if f.startswith('upload-')], [f for f in before if f.startswith('upload-')])

    def test_concurrent_same_version_exactly_one_wins(self):
        name = uniq('race')
        z = test_zip()
        results = []

        def go():
            results.append(upload(self.msi, 'test', name, z))

        threads = [threading.Thread(target=go) for _ in range(6)]
        [t.start() for t in threads]
        [t.join() for t in threads]
        self.assertEqual(sum('upload' in r for r in results), 1, results)
        self.assertEqual(self.stored('test', name).split(), ['1.0.0.json', '1.0.0.zip'])

    def test_concurrent_different_versions_all_indexed(self):
        name = uniq('race2')
        versions = ['1.0.%d' % i for i in range(8)]
        results = []

        def go(v):
            results.append(upload(self.msi, 'test', name, test_zip(version=v)))

        threads = [threading.Thread(target=go, args=(v,)) for v in versions]
        [t.start() for t in threads]
        [t.join() for t in threads]
        self.assertTrue(all('upload' in r for r in results), results)
        self.assertEqual(sorted(call('private_repo_index', self.msi)['index']['tests'][name]['versions']), sorted(versions))


# ---------------------------------------------------------------- fetch / index / delete / rebuild
class TestFetchIndexDeleteRebuild(Base):
    def test_fetch_id_hardening(self):
        name = uniq('fetch')
        upload(self.msi, 'test', name, test_zip())
        bad = ['../../accounts/x-1.0.0', name + '-1.0.0/../..', '../' + name + '-1.0.0', name + '-1.0', name + '-1.0.0.0', name,
               '-1.0.0', '', name + '-1.0.0\x00', name.upper() + '-1.0.0', '/' + name + '-1.0.0', name + '-1.0.0.zip',
               ('x' * 300) + '-1.0.0']
        for i in bad:
            with self.subTest(repr(i)):
                out = call('private_repo_fetch', self.msi, kind='test', id=i)
                self.assertIn('error', out)
                self.assertNotIn('zip', out)
        self.assertIn('error', call('private_repo_fetch', self.msi, kind='suite', id=name + '-1.0.0'))  # kind keeps namespaces apart
        self.assertIn('error', call('private_repo_fetch', self.msi, kind='../tests', id=name + '-1.0.0'))
        self.assertIn('zip', call('private_repo_fetch', self.msi, kind='test', id=name + '-1.0.0'))

    def test_index_conditional_fetch(self):
        gen = call('private_repo_index', self.msi)['index']['main']['generated']
        self.assertTrue(call('private_repo_index', self.msi, if_generated=str(gen)).get('unchanged'))
        self.assertTrue(call('private_repo_index', self.msi, if_generated=str(gen + 1000)).get('unchanged'))
        self.assertIn('index', call('private_repo_index', self.msi, if_generated=str(gen - 1)))
        self.assertIn('index', call('private_repo_index', self.msi, if_generated='junk'))
        self.assertIn('index', call('private_repo_index', self.msi, if_generated='0'))

    def test_delete_moves_to_trash_and_updates_index(self):
        name = uniq('del')
        upload(self.msi, 'test', name, test_zip(version='1.0.0'))
        upload(self.msi, 'test', name, test_zip(version='1.0.1'))
        self.assertIn('delete', call('private_repo_delete', self.msi, kind='test', name=name, version='1.0.1'))
        self.assertEqual(call('private_repo_index', self.msi)['index']['tests'][name]['versions'], ['1.0.0'])
        self.assertIn('error', call('private_repo_fetch', self.msi, kind='test', id=name + '-1.0.1'))
        self.assertIn('-test-%s-1.0.1.zip' % name, srv_sh('ls %strash' % REPO_DIR))
        self.assertIn('error', call('private_repo_delete', self.msi, kind='test', name=name, version='1.0.1'))  # already gone
        # deleting the last version removes the entry entirely
        self.assertIn('delete', call('private_repo_delete', self.msi, kind='test', name=name, version='1.0.0'))
        self.assertNotIn(name, call('private_repo_index', self.msi)['index']['tests'])

    def test_delete_hardening(self):
        for f in [dict(kind='test', name='../x', version='1.0.0'), dict(kind='test', name='a', version='../../1.0.0'),
                  dict(kind='bogus', name='a', version='1.0.0'), dict(kind='test', name='', version=''), dict()]:
            with self.subTest(f):
                self.assertIn('error', call('private_repo_delete', self.msi, **f))

    def test_rebuild_recovers_from_corrupt_or_missing_index(self):
        name = uniq('rebuild')
        upload(self.msi, 'test', name, test_zip())
        for corrupt in ['garbage{{{', '[]', '', 'null']:
            with self.subTest(corrupt):
                srv_sh("printf '%s' '" + corrupt + "' > " + REPO_DIR + 'index.json')
                out = call('private_repo_index', self.msi)
                self.assertIn('index', out, out)
                self.assertIn(name, out['index']['tests'])
        srv_sh('rm -f %sindex.json' % REPO_DIR)
        self.assertIn(name, call('private_repo_index', self.msi)['index']['tests'])
        self.assertIn('rebuild', call('private_repo_rebuild', self.msi))

    def test_rebuild_ignores_orphans_and_bad_meta(self):
        srv_sh('mkdir -p %stests/orphan-x && echo junk > %stests/orphan-x/1.0.0.zip && echo "{bad" > %stests/orphan-x/2.0.0.json' % ((REPO_DIR,) * 3))
        out = call('private_repo_rebuild', self.msi)
        self.assertIn('rebuild', out)
        self.assertNotIn('orphan-x', call('private_repo_index', self.msi)['index']['tests'])
        srv_sh('rm -rf %stests/orphan-x' % REPO_DIR)

    def test_empty_repo_index_is_valid(self):
        # a brand-new account has an empty but well-formed index
        idx = call('private_repo_index', self.msi)['index']
        self.assertIn('main', idx)
        self.assertIn('generated', idx['main'])


if __name__ == '__main__':
    unittest.main()
