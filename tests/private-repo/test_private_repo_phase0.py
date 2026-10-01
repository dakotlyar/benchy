"""Phase 0 QC for the private-repo admin-upload work: zip hardening, the audit trail, and the CSRF token check.

Run through tests/private-repo/run.sh (it provides the QC_* environment), same as test_private_repo_http.py.
"""
import base64
import hashlib
import io
import random
import struct
import time
import zipfile
import http.cookiejar
import json
import os
import re
import subprocess
import unittest
import urllib.error
import urllib.parse
import urllib.request

from test_private_repo_http import (REPO_TOKEN, AID, BASE, REPO_DIR, SRV, call, make_zip, new_machine, sql, srv_sh, test_zip,
                                    uniq, upload)

USER = os.environ['QC_USER']
PASS = os.environ['QC_PASS']
ALPHA4 = bytes(65 + (i & 3) for i in range(256))
ALPHA2 = bytes(65 + (i & 1) for i in range(256))


def compressible(n, alphabet=ALPHA4, seed=1):
    """n deterministic bytes that deflate roughly 4:1 (ALPHA4) or 8:1 (ALPHA2), nowhere near the zip-bomb ratio."""
    return random.Random(seed).randbytes(n).translate(alphabet)


def lie_about_size(zbytes, name, fake):
    """Patch the uncompressed-size field of one entry's local header and central-directory record."""
    info = zipfile.ZipFile(io.BytesIO(zbytes)).getinfo(name)
    b = bytearray(zbytes)
    struct.pack_into('<I', b, info.header_offset + 22, fake)
    pos = 0
    while True:
        pos = b.find(b'PK\x01\x02', pos)
        if pos < 0:
            break
        nlen = struct.unpack_from('<H', b, pos + 28)[0]
        if bytes(b[pos + 46:pos + 46 + nlen]) == name.encode():
            struct.pack_into('<I', b, pos + 24, fake)
        pos += 4
    return bytes(b)


def corrupt_entry(zbytes, name, crc_only=False):
    """Damage one entry: flip bytes in its deflate stream, or (crc_only) break just the stored CRC-32."""
    info = zipfile.ZipFile(io.BytesIO(zbytes)).getinfo(name)
    b = bytearray(zbytes)
    if crc_only:
        struct.pack_into('<I', b, info.header_offset + 14, info.CRC ^ 0xFFFFFFFF)
        pos = 0
        while True:
            pos = b.find(b'PK\x01\x02', pos)
            if pos < 0:
                break
            nlen = struct.unpack_from('<H', b, pos + 28)[0]
            if bytes(b[pos + 46:pos + 46 + nlen]) == name.encode():
                struct.pack_into('<I', b, pos + 16, info.CRC ^ 0xFFFFFFFF)
            pos += 4
        return bytes(b)
    nlen, elen = struct.unpack_from('<HH', b, info.header_offset + 26)
    start = info.header_offset + 30 + nlen + elen
    for k in range(start + 8, start + info.compress_size - 4, 7):
        b[k] ^= 0x5A
    return bytes(b)


def raw_upload(msi, name, z, kind='test'):
    """The unparsed reply body of a CLI upload (to check that nothing is printed before the JSON)."""
    f = {'r': 'private_repo_upload', 'aid': AID, 'msi': msi, 'pts_core': '10860', 'kind': kind, 'name': name,
         'zip': base64.b64encode(z).decode(), 'zip_sha1': hashlib.sha1(z).hexdigest(), 'commit_description': 'qc', 'overwrite': '0', 'rt': REPO_TOKEN}
    data = urllib.parse.urlencode(f).encode()
    return urllib.request.urlopen(urllib.request.Request(BASE + '/phoromatic.php', data=data), timeout=120).read().decode()


def sql_exec(stmt):
    """Run a statement and return whether SQLite accepted it (no JSON, no warnings)."""
    php = '$d=new SQLite3("/var/lib/benchy/phoromatic/phoromatic.db"); echo @$d->exec($argv[1]) ? "ok" : "refused";'
    return subprocess.run(['docker', 'exec', SRV, 'php', '-r', php, stmt], capture_output=True, text=True).stdout


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

    def test_lying_headers_are_caught_by_counting_real_bytes(self):
        # headers claim 10 bytes; the stream really holds 12 MB (over the per-entry cap)
        z = lie_about_size(test_zip(extra=[('big.bin', compressible(12 * 1024 * 1024))]), 'big.bin', 10)
        self.refused(upload(self.msi, 'test', uniq('lie'), z), 'larger than 10 MB', 'lying per-entry size')
        # three honest-looking entries that really add up to 27 MB
        z = test_zip(extra=[('p%d.bin' % i, compressible(9 * 1024 * 1024, ALPHA2, i)) for i in range(3)])
        for i in range(3):
            z = lie_about_size(z, 'p%d.bin' % i, 10)
        self.assertLess(len(z), 5 * 1024 * 1024)
        self.refused(upload(self.msi, 'test', uniq('lie'), z), 'expands', 'lying total')

    def test_total_cap_with_many_entries_just_under_the_ratio_floor(self):
        def zeros(n):
            return test_zip(extra=[('z%d.bin' % i, b'\0' * (1024 * 1024 - 1)) for i in range(n)])
        self.accepted(upload(self.msi, 'test', uniq('tot'), zeros(24)), '24 x (1 MB - 1)')
        self.refused(upload(self.msi, 'test', uniq('tot'), zeros(27)), 'expands', '27 x (1 MB - 1)')

    def test_duplicate_entry_names_rejected(self):
        for label, extra in (('exact duplicate', [('dup.txt', 'a'), ('dup.txt', 'b')]),
                             ('backslash vs slash', [('a/b.txt', 'a'), ('a\\b.txt', 'b')]),
                             ('file and dir with the same name', [('d', 'x'), ('d/', '')])):
            with self.subTest(label):
                self.refused(upload(self.msi, 'test', uniq('dup'), test_zip(extra=extra)), 'twice', label)

    def test_uppercase_sh_extension_gets_the_crlf_check(self):
        self.refused(upload(self.msi, 'test', uniq('crlf'), test_zip(extra=[('RUN.SH', 'a\r\nb\r\n')])), 'CRLF', 'RUN.SH')

    def test_backslash_rewrite_cannot_be_used_to_burn_cpu(self):
        # ~2.9 MB zip, 18 MB expanded, backslash names: used to pass inspection and then spend ~36 s recompressing
        name = uniq('bs')
        z = test_zip(extra=[('sub\\a.bin', compressible(9 * 1024 * 1024, ALPHA2, 1)), ('sub\\b.bin', compressible(9 * 1024 * 1024, ALPHA2, 2))])
        self.assertLess(len(z), 5 * 1024 * 1024)
        t = time.time()
        body = raw_upload(self.msi, name, z)
        self.assertLess(time.time() - t, 10)
        self.assertTrue(body.startswith('{"phoromatic"'), body[:80])
        self.assertIn('backslash', json.loads(body)['phoromatic']['error'])
        self.assertEqual([f for f in srv_sh('ls -a %s' % REPO_DIR).split() if f.startswith('upload-')], [])
        self.assertEqual([r['Action'] for r in audit_rows(Name=name)], ['upload_rejected'])

    def test_stale_temp_files_are_swept_fresh_ones_kept(self):
        srv_sh('cd %s && touch -d "2 hours ago" upload-aaaaaaaaaaaa.zip upload-aaaaaaaaaaaa.zip.fixed.Xy12Ab && touch upload-bbbbbbbbbbbb.zip' % REPO_DIR)
        self.addCleanup(srv_sh, 'rm -f %supload-bbbbbbbbbbbb.zip' % REPO_DIR)
        self.accepted(upload(self.msi, 'test', uniq('sweep'), test_zip()), 'upload')
        left = srv_sh('ls -a %s' % REPO_DIR).split()
        self.assertNotIn('upload-aaaaaaaaaaaa.zip', left)
        self.assertNotIn('upload-aaaaaaaaaaaa.zip.fixed.Xy12Ab', left)
        self.assertIn('upload-bbbbbbbbbbbb.zip', left)

    def test_corrupt_entries_are_rejected_without_warnings_in_the_reply(self):
        text = ''.join('line %d of some perfectly ordinary script text\n' % i for i in range(3000))
        cases = (('corrupt deflate in an ordinary file', corrupt_entry(test_zip(extra=[('data.txt', text)]), 'data.txt')),
                 ('corrupt deflate in a .sh', corrupt_entry(test_zip(extra=[('big.sh', text)]), 'big.sh')),
                 ('bad CRC in an ordinary file', corrupt_entry(test_zip(extra=[('data.txt', text)]), 'data.txt', crc_only=True)),
                 ('corrupt deflate in test-definition.xml', corrupt_entry(test_zip(), 'test-definition.xml')))
        for label, z in cases:
            with self.subTest(label):
                name = uniq('cor')
                body = raw_upload(self.msi, name, z)
                self.assertTrue(body.startswith('{"phoromatic"'), '%s: %r' % (label, body[:100]))
                self.assertIn('error', json.loads(body)['phoromatic'], label)
                self.assertEqual(srv_sh('ls %stests/%s 2>/dev/null' % (REPO_DIR, name)).strip(), '', label)

    def test_real_byte_ratio_check_without_help_from_the_headers(self):
        # 40 MB of zeros whose headers claim 10 bytes: only the streaming ratio check can say "bomb" before the 10 MB cap
        z = lie_about_size(test_zip(extra=[('zeros.bin', b'\0' * (40 * 1024 * 1024))]), 'zeros.bin', 10)
        self.refused(upload(self.msi, 'test', uniq('rat'), z), 'compression ratio', 'lying zeros')

    def test_case_and_dot_variants_count_as_duplicates(self):
        for label, extra in (('case', [('A.sh', 'echo a\n'), ('a.sh', 'echo b\n')]),
                             ('dot-slash', [('./x.txt', 'a'), ('x.txt', 'b')]),
                             ('double slash', [('d/y.txt', 'a'), ('d//y.txt', 'b')])):
            with self.subTest(label):
                self.refused(upload(self.msi, 'test', uniq('dup'), test_zip(extra=extra)), 'twice', label)

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
        self.assertEqual([r['Action'] for r in rows], ['upload_attempt', 'upload_committed'])
        r = rows[-1]
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
        self.assertEqual([r['Action'] for r in audit_rows(Name=name)], ['delete_failed'])

    def test_table_is_append_only(self):
        name = uniq('ao')
        upload(self.msi, 'test', name, test_zip())
        for stmt in ("UPDATE phoromatic_private_repo_audit SET Actor='tampered' WHERE Name='%s'", "DELETE FROM phoromatic_private_repo_audit WHERE Name='%s'"):
            self.assertEqual(sql_exec(stmt % name), 'refused', stmt)
        rows = audit_rows(Name=name)
        self.assertEqual([r['Action'] for r in rows], ['upload_attempt', 'upload_committed'])
        self.assertNotIn('tampered', [r['Actor'] for r in rows])

    def test_event_mirrored_to_log_file(self):
        name = uniq('mir')
        upload(self.msi, 'test', name, test_zip())
        # a later request must not wipe it (phoromatic.log is truncated per request; the mirror has its own file)
        call('private_repo_index', self.msi)
        self.assertIn(name, srv_sh("cat /var/log/phoromatic-private-repo-audit.log"))


class FailClosed(unittest.TestCase):
    """If the audit trail cannot record an attempt, nothing may change."""

    @classmethod
    def setUpClass(cls):
        cls.msi = new_machine()
        cls.log = '/var/log/phoromatic-private-repo-audit.log'

    def block(self, action):
        self.assertEqual(sql_exec("CREATE TRIGGER qc_block BEFORE INSERT ON phoromatic_private_repo_audit WHEN NEW.Action='%s' BEGIN SELECT RAISE(ABORT,'qc'); END" % action), 'ok')
        self.addCleanup(sql_exec, 'DROP TRIGGER IF EXISTS qc_block')

    def raw(self, resource, **fields):
        f = {'r': resource, 'aid': AID, 'msi': self.msi, 'pts_core': '10860', 'rt': REPO_TOKEN}
        f.update(fields)
        data = urllib.parse.urlencode(f).encode()
        return urllib.request.urlopen(urllib.request.Request(BASE + '/phoromatic.php', data=data), timeout=60).read().decode()

    def upload_raw(self, name, z, **kw):
        return self.raw('private_repo_upload', kind='test', name=name, zip=base64.b64encode(z).decode(),
                        zip_sha1=hashlib.sha1(z).hexdigest(), commit_description='qc', overwrite='0')

    def test_upload_refused_when_the_attempt_row_fails(self):
        name = uniq('fc')
        self.block('upload_attempt')
        body = self.upload_raw(name, test_zip())
        self.assertTrue(body.startswith('{"phoromatic"'), 'output must be clean JSON, got %r' % body[:80])
        self.assertIn('audit', json.loads(body)['phoromatic']['error'])
        self.assertEqual(srv_sh('ls %stests/%s 2>/dev/null' % (REPO_DIR, name)).strip(), '')
        self.assertEqual([f for f in srv_sh('ls -a %s' % REPO_DIR).split() if f.startswith('upload-')], [])
        self.assertNotIn('upload_committed', [r['Action'] for r in audit_rows(Name=name)])

    def test_upload_refused_when_the_mirror_log_cannot_be_written(self):
        name = uniq('fcm')
        srv_sh('rm -f %s; mkdir %s' % (self.log, self.log))   # a directory where the file should be
        self.addCleanup(srv_sh, 'rmdir %s' % self.log)
        body = self.upload_raw(name, test_zip())
        self.assertTrue(body.startswith('{"phoromatic"'), body[:80])
        self.assertIn('error', json.loads(body)['phoromatic'])
        self.assertEqual(srv_sh('ls %stests/%s 2>/dev/null' % (REPO_DIR, name)).strip(), '')

    def test_delete_and_rebuild_refused_when_the_attempt_row_fails(self):
        name = uniq('fcd')
        self.assertIn('upload', upload(self.msi, 'test', name, test_zip()))
        self.block('delete_attempt')
        body = self.raw('private_repo_delete', kind='test', name=name, version='1.0.0')
        self.assertTrue(body.startswith('{"phoromatic"'), body[:80])
        self.assertIn('error', json.loads(body)['phoromatic'])
        self.assertTrue(srv_sh('ls %stests/%s/1.0.0.zip' % (REPO_DIR, name)).strip(), 'delete must not have happened')
        sql_exec('DROP TRIGGER qc_block')
        self.block('rebuild_attempt')
        self.assertIn('error', json.loads(self.raw('private_repo_rebuild'))['phoromatic'])

    def test_index_failure_after_the_rename_is_audited_as_stored(self):
        name = uniq('part')
        srv_sh('rm -f %sindex.json; mkdir %sindex.json' % (REPO_DIR, REPO_DIR))   # makes the index rename fail
        try:
            out = upload(self.msi, 'test', name, test_zip())
        finally:
            srv_sh('rmdir %sindex.json' % REPO_DIR)
            call('private_repo_rebuild', self.msi)
        self.assertIn('stored', out['error'])
        self.assertTrue(srv_sh('ls %stests/%s/1.0.0.zip' % (REPO_DIR, name)).strip())
        rows = audit_rows(Name=name)
        self.assertEqual([r['Action'] for r in rows], ['upload_attempt', 'upload_committed'])
        self.assertTrue(json.loads(rows[1]['Detail'])['partial'])

    def test_failure_after_attempt_is_upload_failed_not_rejected(self):
        name = uniq('late')
        # a file where the item directory should be makes the store step fail after the attempt row
        srv_sh('mkdir -p %stests && touch %stests/%s' % (REPO_DIR, REPO_DIR, name))
        self.addCleanup(srv_sh, 'rm -f %stests/%s' % (REPO_DIR, name))
        self.assertIn('error', upload(self.msi, 'test', name, test_zip()))
        self.assertEqual([r['Action'] for r in audit_rows(Name=name)], ['upload_attempt', 'upload_failed'])

    def test_failed_completion_rows_surface_a_warning(self):
        name = uniq('warn')
        self.block('upload_committed')
        out = upload(self.msi, 'test', name, test_zip())
        self.assertIn('upload', out, 'the upload itself succeeded')
        self.assertTrue(any('audit log' in w for w in out['upload']['warnings']), out)
        sql_exec('DROP TRIGGER qc_block')

        self.block('delete')
        out = call('private_repo_delete', self.msi, kind='test', name=name, version='1.0.0')
        self.assertIn('delete', out)
        self.assertIn('audit log', out.get('warning', ''), out)
        sql_exec('DROP TRIGGER qc_block')

        self.block('rebuild')
        out = call('private_repo_rebuild', self.msi)
        self.assertIn('rebuild', out)
        self.assertIn('audit log', out.get('warning', ''), out)

    def test_unwritable_storage_is_a_clean_audited_error(self):
        name = uniq('disk')
        base = REPO_DIR.rstrip('/')
        srv_sh('mv %s %s.bak && touch %s' % (base, base, base))   # storage that cannot take a file
        try:
            body = raw_upload(self.msi, name, test_zip())
        finally:
            srv_sh('rm -f %s && mv %s.bak %s' % (base, base, base))
        self.assertTrue(body.startswith('{"phoromatic"'), body[:100])
        self.assertIn('could not write', json.loads(body)['phoromatic']['error'])
        rows = audit_rows(Name=name)
        self.assertEqual([r['Action'] for r in rows], ['upload_rejected'])
        self.assertIn('could not write', json.loads(rows[0]['Detail'])['error'])

    def test_short_write_of_the_metadata_file_is_reported_as_stored_and_audited(self):
        # /dev/full accepts the open and fails every write with ENOSPC, like a full disk
        name = uniq('enospc')
        srv_sh('mkdir -p %stests/%s && ln -s /dev/full %stests/%s/1.0.0.json' % (REPO_DIR, name, REPO_DIR, name))
        self.addCleanup(srv_sh, 'rm -rf %stests/%s' % (REPO_DIR, name))
        body = raw_upload(self.msi, name, test_zip())
        self.assertTrue(body.startswith('{"phoromatic"'), body[:100])
        self.assertIn('metadata could not be written', json.loads(body)['phoromatic']['error'])
        rows = audit_rows(Name=name)
        self.assertEqual([r['Action'] for r in rows], ['upload_attempt', 'upload_committed'])
        self.assertTrue(json.loads(rows[1]['Detail'])['partial'])
        # recovery is what the message says: no half-written metadata is left, and re-uploading with overwrite completes it
        self.assertIn('overwrite', json.loads(body)['phoromatic']['error'])
        self.assertEqual(srv_sh('ls %stests/%s' % (REPO_DIR, name)).split(), ['1.0.0.zip'])
        self.assertIn('upload', upload(self.msi, 'test', name, test_zip(), overwrite=True))
        self.assertIn(name, call('private_repo_index', self.msi)['index']['tests'])

    def test_truncated_metadata_is_reported_by_rebuild_and_recoverable(self):
        name = uniq('trunc')
        self.assertIn('upload', upload(self.msi, 'test', name, test_zip()))
        srv_sh(': > %stests/%s/1.0.0.json' % (REPO_DIR, name))   # what a short write used to leave behind
        out = call('private_repo_rebuild', self.msi)
        self.assertIn('test %s-1.0.0' % name, out['rebuild'].get('skipped', []), out)
        self.assertNotIn(name, call('private_repo_index', self.msi)['index']['tests'])
        self.assertIn('skipped', audit_rows(Action='rebuild')[-1]['Detail'])
        self.assertIn('already exists', upload(self.msi, 'test', name, test_zip())['error'])
        self.assertIn('upload', upload(self.msi, 'test', name, test_zip(), overwrite=True))
        self.assertIn(name, call('private_repo_index', self.msi)['index']['tests'])
        self.assertNotIn('test %s-1.0.0' % name, call('private_repo_rebuild', self.msi)['rebuild'].get('skipped', []))

    def test_fatal_after_the_final_audit_row_adds_no_second_row_and_no_false_error(self):
        # The cache sync that follows the completion row reads the cached repository index in full. Make that file huge and run
        # the real endpoint under a memory limit that the upload itself fits in: the fatal then lands AFTER the final audit row.
        # Expected: exactly one completion row (no partial/failed row from the shutdown handler) and the success reply.
        index = srv_sh('find /var/cache/benchy /var/lib/benchy -name private.index 2>/dev/null | head -1').strip()
        self.assertTrue(index, 'cached private.index not found')
        srv_sh('cp %s %s.qcbak && truncate -s 80M %s' % (index, index, index))
        self.addCleanup(srv_sh, 'mv -f %s.qcbak %s' % (index, index))
        z = test_zip()
        driver = ('$raw=stream_get_contents(STDIN); $_POST=$_REQUEST=json_decode($raw,true); unset($raw);'
                  '$_SERVER["REMOTE_ADDR"]="127.0.0.9"; chdir("/bench/pts-core/phoromatic/public_html"); include "phoromatic.php";')
        name = uniq('late')
        self.addCleanup(srv_sh, 'rm -rf %stests/%s' % (REPO_DIR, name))
        fields = {'r': 'private_repo_upload', 'aid': AID, 'msi': self.msi, 'pts_core': '10860', 'kind': 'test', 'name': name,
                  'zip': base64.b64encode(z).decode(), 'zip_sha1': hashlib.sha1(z).hexdigest(), 'commit_description': 'qc', 'overwrite': '0', 'rt': REPO_TOKEN}
        run = subprocess.run(['docker', 'exec', '-i', SRV, 'php', '-d', 'memory_limit=40M', '-d', 'display_errors=0', '-d', 'log_errors=1',
                              '-d', 'error_log=/dev/stderr', '-r', driver], input=json.dumps(fields), capture_output=True, text=True)
        self.assertIn('Allowed memory size', run.stderr, 'the injected fatal did not happen: ' + run.stderr[:200])
        self.assertEqual([r['Action'] for r in audit_rows(Name=name)], ['upload_attempt', 'upload_committed'])
        self.assertNotIn('partial', audit_rows(Name=name, Action='upload_committed')[0]['Detail'])
        reply = json.loads(run.stdout[run.stdout.index('{"phoromatic"'):])['phoromatic']
        self.assertIn('upload', reply)
        self.assertNotIn('error', reply)

    def test_short_write_of_the_index_never_replaces_the_good_index(self):
        upload(self.msi, 'test', uniq('idx'), test_zip())
        good = srv_sh('cat %sindex.json' % REPO_DIR)
        self.assertIn('"tests"', good)
        srv_sh('ln -s /dev/full %sindex.json.tmp' % REPO_DIR)
        self.addCleanup(srv_sh, 'rm -f %sindex.json.tmp' % REPO_DIR)
        before = len(audit_rows(Action='rebuild_failed'))
        out = call('private_repo_rebuild', self.msi)
        self.assertIn('error', out)
        self.assertEqual(srv_sh('cat %sindex.json' % REPO_DIR), good, 'the old index must survive')
        self.assertEqual(len(audit_rows(Action='rebuild_failed')), before + 1)

    def test_fatal_error_mid_upload_is_audited_and_answered(self):
        # Run the real endpoint in a CLI process with a memory limit low enough to die inside the upload handling
        import random
        z = test_zip(extra=[('blob.bin', random.Random(5).randbytes(int(3.5 * 1024 * 1024)))])
        driver = ('$raw=stream_get_contents(STDIN); $_POST=$_REQUEST=json_decode($raw,true); unset($raw);'
                  '$_SERVER["REMOTE_ADDR"]="127.0.0.9"; chdir("/bench/pts-core/phoromatic/public_html"); include "phoromatic.php";')
        fields = {'r': 'private_repo_upload', 'aid': AID, 'msi': self.msi, 'pts_core': '10860', 'kind': 'test', 'name': '',
                  'zip': base64.b64encode(z).decode(), 'zip_sha1': hashlib.sha1(z).hexdigest(), 'commit_description': 'qc', 'overwrite': '0', 'rt': REPO_TOKEN}
        body = None
        for limit in (40, 32, 26, 22, 18, 15, 12):
            fields['name'] = name = uniq('fatal')   # runs that do not die store their package under their own name
            self.addCleanup(srv_sh, 'rm -rf %stests/%s' % (REPO_DIR, name))
            out = subprocess.run(['docker', 'exec', '-i', SRV, 'php', '-d', 'memory_limit=%dM' % limit, '-d', 'display_errors=0', '-r', driver],
                                 input=json.dumps(fields), capture_output=True, text=True).stdout
            if 'The server stopped' in out:
                body = out
                break
        self.assertIsNotNone(body, 'no memory limit produced a fatal inside the upload handler')
        self.assertIn('nothing was stored', json.loads(body[body.index('{"phoromatic"'):])['phoromatic']['error'])
        rows = audit_rows(Name=name)
        self.assertEqual(rows[-1]['Action'], 'upload_failed')
        self.assertIn('Allowed memory size', json.loads(rows[-1]['Detail'])['error'])
        self.assertEqual(srv_sh('ls %stests/%s 2>/dev/null' % (REPO_DIR, name)).strip(), '')
        self.assertEqual([f for f in srv_sh('ls -a %s' % REPO_DIR).split() if f.startswith('upload-')], [])

    def test_early_rejections_are_audited(self):
        name = uniq('early')
        data = urllib.parse.urlencode({'r': 'private_repo_upload', 'aid': AID, 'msi': self.msi, 'pts_core': '10860',
                                       'kind': 'test', 'name': name, 'zip[]': 'x', 'rt': REPO_TOKEN}).encode()
        body = urllib.request.urlopen(urllib.request.Request(BASE + '/phoromatic.php', data=data), timeout=60).read().decode()
        self.assertIn('Malformed', body)
        self.assertEqual([r['Action'] for r in audit_rows(Name=name)], ['upload_rejected'])

    def test_hostile_fields_are_sanitised_in_row_and_log(self):
        name = 'x\n[fake] audit line ' + 'A' * 500
        out = upload(self.msi, 'te\nst', name, test_zip())
        self.assertIn('error', out)
        row = sql("SELECT * FROM phoromatic_private_repo_audit WHERE Name LIKE '%fake%' ORDER BY AuditID DESC LIMIT 1")[0]
        self.assertNotIn('\n', row['Name'])
        self.assertLessEqual(len(row['Name']), 140)
        self.assertNotIn('\n[fake]', srv_sh('cat %s' % self.log))


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
