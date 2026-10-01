"""Phase 0 QC for the private-repo admin-upload work: zip hardening, the audit trail, and the CSRF token check.

Run through tests/private-repo/run.sh (it provides the QC_* environment), same as test_private_repo_http.py.
"""
import hashlib
import http.cookiejar
import json
import os
import re
import unittest
import urllib.error
import urllib.parse
import urllib.request

from test_private_repo_http import (AID, BASE, REPO_DIR, call, make_zip, new_machine, sql, srv_sh, test_zip,
                                    uniq, upload)

USER = os.environ['QC_USER']
PASS = os.environ['QC_PASS']
ALPHA4 = bytes(65 + (i & 3) for i in range(256))


def compressible(n):
    """n bytes that deflate roughly 4:1, nowhere near the zip-bomb ratio."""
    return os.urandom(n).translate(ALPHA4)


def audit_rows(**where):
    cond = ' AND '.join("%s='%s'" % (k, v) for k, v in where.items())
    return sql('SELECT * FROM phoromatic_private_repo_audit WHERE %s ORDER BY AuditID' % cond)


class Hardening(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.msi = new_machine()

    def accepted(self, out, msg):
        self.assertIn('upload', out, '%s -> %r' % (msg, out))

    def refused(self, out, needle, msg):
        self.assertIn('error', out, '%s was accepted: %r' % (msg, out))
        self.assertIn(needle, out['error'], msg)

    def test_per_entry_cap_10mb(self):
        self.refused(upload(self.msi, 'test', uniq('ent'), test_zip(extra=[('big.bin', compressible(11 * 1024 * 1024))])),
                     'larger than 10 MB', '11 MB entry')
        self.accepted(upload(self.msi, 'test', uniq('ent'), test_zip(extra=[('big.bin', compressible(9 * 1024 * 1024))])), '9 MB entry')

    def test_compression_ratio(self):
        out = upload(self.msi, 'test', uniq('ratio'), test_zip(extra=[('zeros.bin', b'\0' * (3 * 1024 * 1024))]))
        self.refused(out, 'compression ratio', '3 MB of zeros')
        # small, highly compressible entries (well under 1 MB) are ordinary
        self.accepted(upload(self.msi, 'test', uniq('ratio'), test_zip(extra=[('pad.txt', b' ' * 200000)])), '200 KB of spaces')

    def test_total_cap_message_still_wins_for_huge_entries(self):
        out = upload(self.msi, 'test', uniq('huge'), test_zip(extra=[('zeros.bin', b'\0' * (30 * 1024 * 1024))]))
        self.refused(out, 'expands', '30 MB of zeros')

    def test_crlf_found_across_stream_chunk_boundary(self):
        # the CR is the last byte of the first 64 KB read, the LF the first of the next
        script = b'a' * 65535 + b'\r\n' + b'b'
        self.refused(upload(self.msi, 'test', uniq('crlf'), test_zip(extra=[('big.sh', script)])), 'CRLF', 'CRLF at chunk edge')
        self.accepted(upload(self.msi, 'test', uniq('crlf'), test_zip(extra=[('big.sh', b'a' * 200000 + b'\n')])), 'large LF-only script')


class Audit(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.msi = new_machine()
        cls.system_id = sql("SELECT SystemID FROM phoromatic_systems WHERE MachineSelfID='%s'" % cls.msi)[0]['SystemID']

    def test_migration_created_table(self):
        self.assertGreaterEqual(int(sql('PRAGMA user_version')[0]['user_version']), 46)
        self.assertTrue(sql("SELECT name FROM sqlite_master WHERE type='table' AND name='phoromatic_private_repo_audit'"))

    def test_upload_wire_format_unchanged_and_audited(self):
        name = uniq('aud')
        z = test_zip()
        out = upload(self.msi, 'test', name, z)
        self.assertEqual(sorted(out['upload']), ['id', 'replaced', 'sha1', 'warnings'])
        self.assertEqual(out['upload']['id'], 'private/%s-1.0.0' % name)
        rows = audit_rows(Name=name)
        self.assertEqual([r['Action'] for r in rows], ['upload_committed'])
        r = rows[0]
        self.assertEqual((r['AccountID'], r['ActorType'], r['Actor'], r['Kind'], r['Version']), (AID, 'machine', self.system_id, 'test', '1.0.0'))
        self.assertEqual(r['Sha256'], hashlib.sha256(z).hexdigest())
        self.assertEqual(r['Sha1'], hashlib.sha1(z).hexdigest())
        self.assertIsNone(r['PrevSha256'])
        self.assertTrue(r['SourceIP'])
        self.assertEqual(json.loads(r['Detail'])['commit_description'], 'qc')
        meta = json.loads(srv_sh('cat %stests/%s/1.0.0.json' % (REPO_DIR, name)))
        self.assertEqual((meta['sha256'], meta['uploaded_by'], meta['source']), (hashlib.sha256(z).hexdigest(), 'system:' + self.system_id, 'cli'))

    def test_rejected_upload_is_audited_and_stores_nothing(self):
        name = uniq('rej')
        out = upload(self.msi, 'test', name, test_zip(extra=[('../x', 'y')]))
        self.assertIn('error', out)
        rows = audit_rows(Name=name)
        self.assertEqual([r['Action'] for r in rows], ['upload_rejected'])
        self.assertIn('unsafe path', json.loads(rows[0]['Detail'])['error'])
        self.assertEqual(srv_sh('ls %stests/%s 2>/dev/null' % (REPO_DIR, name)).strip(), '')

    def test_overwrite_delete_rebuild_audited(self):
        name = uniq('life')
        old, new = test_zip(), test_zip(extra=[('extra.txt', 'changed')])
        upload(self.msi, 'test', name, old)
        self.assertIn('upload', upload(self.msi, 'test', name, new, overwrite=True))
        committed = audit_rows(Name=name, Action='upload_committed')
        self.assertEqual(len(committed), 2)
        self.assertEqual(committed[1]['PrevSha256'], hashlib.sha256(old).hexdigest())
        self.assertEqual(committed[1]['Sha256'], hashlib.sha256(new).hexdigest())

        self.assertIn('delete', call('private_repo_delete', self.msi, kind='test', name=name, version='1.0.0'))
        d = audit_rows(Name=name, Action='delete')
        self.assertEqual(len(d), 1)
        self.assertEqual(d[0]['Sha256'], hashlib.sha256(new).hexdigest())
        self.assertIn(name, json.loads(d[0]['Detail'])['trash'])

        before = len(audit_rows(Action='rebuild'))
        self.assertIn('rebuild', call('private_repo_rebuild', self.msi))
        after = audit_rows(Action='rebuild')
        self.assertEqual(len(after), before + 1)
        self.assertEqual(after[-1]['Actor'], self.system_id)

    def test_failed_delete_is_not_audited_as_delete(self):
        name = uniq('nodel')
        self.assertIn('error', call('private_repo_delete', self.msi, kind='test', name=name, version='9.9.9'))
        self.assertEqual(audit_rows(Name=name), [])

    def test_table_is_append_only(self):
        name = uniq('ao')
        upload(self.msi, 'test', name, test_zip())
        sql("UPDATE phoromatic_private_repo_audit SET Actor='tampered' WHERE Name='%s'" % name)
        sql("DELETE FROM phoromatic_private_repo_audit WHERE Name='%s'" % name)
        rows = audit_rows(Name=name)
        self.assertEqual(len(rows), 1)
        self.assertNotEqual(rows[0]['Actor'], 'tampered')

    def test_event_mirrored_to_log_file(self):
        name = uniq('mir')
        upload(self.msi, 'test', name, test_zip())
        found = srv_sh("grep -rl 'private repo audit: upload_committed' /var/lib/benchy /var/cache/benchy /root/.benchy ~/.benchy 2>/dev/null | head -1")
        self.assertTrue(found.strip(), 'no log file carries the audit mirror line')


class Csrf(unittest.TestCase):
    """The group-name form on ?users is the cheapest CSRF-protected POST that is harmless to repeat."""

    def login(self):
        jar = http.cookiejar.CookieJar()
        opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
        opener.open(BASE + '/', timeout=30).read()
        body = opener.open(urllib.request.Request(BASE + '/?login', urllib.parse.urlencode({'username': USER, 'password': PASS}).encode()), timeout=30).read().decode()
        self.assertIn('Log-In Successful', body)
        return opener

    def token(self, opener):
        m = re.search(r'name="token_submit" value="([0-9a-f]+)"', opener.open(BASE + '/?users', timeout=30).read().decode())
        self.assertTrue(m, 'no token on ?users')
        return m.group(1)

    def group_name(self):
        return sql("SELECT GroupName FROM phoromatic_accounts WHERE AccountID='%s'" % AID)[0]['GroupName']

    def post_group(self, opener, name, token=None):
        f = {'group_name': name}
        if token is not None:
            f['token_submit'] = token
        opener.open(urllib.request.Request(BASE + '/?users', urllib.parse.urlencode(f).encode()), timeout=30).read()

    def test_token_required_and_compared(self):
        a, b = self.login(), self.login()
        ta, tb = self.token(a), self.token(b)
        self.assertNotEqual(ta, tb)
        marker = uniq('grp')
        for label, tok in (('no token', None), ('empty token', ''), ('wrong token', 'x' * len(ta)), ('other session token', tb), ('prefix of token', ta[:10])):
            with self.subTest(label):
                self.post_group(a, marker, tok)
                self.assertNotEqual(self.group_name(), marker, label + ' changed state')
        self.post_group(a, marker, ta)
        self.assertEqual(self.group_name(), marker, 'the right token must still work')

    def test_session_cookie_is_samesite_lax(self):
        req = urllib.request.Request(BASE + '/?login', urllib.parse.urlencode({'username': USER, 'password': PASS}).encode())
        cookies = [v for k, v in urllib.request.urlopen(req, timeout=30).getheaders() if k.lower() == 'set-cookie']
        self.assertTrue(any('SameSite=Lax' in c for c in cookies), cookies)

    def test_verify_submission_token_unit(self):
        php = (r'''$src=file_get_contents("/bench/pts-core/phoromatic/phoromatic_functions.php");'''
               r'''preg_match('/function verify_submission_token\(\)\s*\{.*?\n\}/s',$src,$m); eval($m[0]);'''
               r'''$cases=array(array(array(),array(),0),array(array(),array("token_submit"=>""),0),array(array("Token"=>""),array("token_submit"=>""),0),'''
               r'''array(array("Token"=>"abc"),array("token_submit"=>"abc"),1),array(array("Token"=>"abc"),array("token_submit"=>"abd"),0),'''
               r'''array(array("Token"=>"0"),array("token_submit"=>"0.0"),0),array(array("Token"=>"abc"),array("token_submit"=>array("abc")),0));'''
               r'''foreach($cases as $c){$_SESSION=$c[0];$_REQUEST=$c[1];echo (int)verify_submission_token(),"\n";}''')
        out = srv_sh("php -r '%s'" % php.replace("'", "'\\''")).split()
        self.assertEqual(out, ['0', '0', '0', '1', '0', '0', '0'])


if __name__ == '__main__':
    unittest.main()
