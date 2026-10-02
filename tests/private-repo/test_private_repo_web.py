"""Phase 2 QC for the private-repo admin work: the Phoromatic web page (list, delete, rebuild, audit log, tokens).

Run through tests/private-repo/run.sh (http or web). Users of each AdminLevel are created through the Users page as the account's
administrator, then every test talks to the page the way a browser does: cookie jar, scraped CSRF token, redirects not followed.
"""
import csv
import hashlib
import http.cookiejar
import io
import json
import os
import re
import subprocess
import unittest
import urllib.error
import urllib.parse
import urllib.request
import uuid

from test_private_repo_http import AID, BASE, REPO_DIR, REPO_TOKEN, SRV, call, new_machine, sql, srv_sh, test_zip, uniq, upload, TEST_XML, make_zip
from test_private_repo_tokens import sql_exec, create_token

USER = os.environ['QC_USER']
PASS = os.environ['QC_PASS']
PAGE = '/?private_repository'
ACTION = '/?r_private_repository_action'


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k):
        return None


class Browser:
    def __init__(self, base=None):
        self.base = base or BASE
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar), NoRedirect)

    def request(self, path, data=None, headers=None):
        """(status, headers, body); redirects are returned, not followed."""
        body = urllib.parse.urlencode(data).encode() if data is not None else None
        req = urllib.request.Request(self.base + path, data=body, headers=headers or {})
        try:
            r = self.opener.open(req, timeout=60)
        except urllib.error.HTTPError as e:
            r = e
        return r.status if hasattr(r, 'status') else r.code, r.headers, r.read().decode('utf-8', 'replace')

    def get(self, path, **kw):
        return self.request(path, None, **kw)

    def post(self, path, data, **kw):
        return self.request(path, data, **kw)

    def login(self, user, password):
        self.get('/')
        status, _, body = self.post('/?login', {'username': user, 'password': password})
        assert 'Log-In Successful' in body, body[:300]
        return self

    def token(self, path=PAGE):
        m = re.search(r'name="token_submit" value="([0-9a-f]+)"', self.get(path)[2])
        assert m, 'no CSRF token on the page'
        return m.group(1)

    def act(self, action, token=None, **fields):
        data = dict(fields, action=action)
        data['token_submit'] = self.token() if token is None else token
        if token == '':
            del data['token_submit']
        return self.post(ACTION, data)


def make_user(admin, username, level):
    """Create a user of an AdminLevel through the Users page, as the account's administrator would."""
    t = admin.get('/?users')[2]
    tok = re.search(r'name="token_submit" value="([0-9a-f]+)"', t).group(1)
    admin.post('/?users', {'username': username, 'password': 'qcpass123', 'confirm_password': 'qcpass123', 'email': username + '@example.com',
                           'admin_level': str(level), 'token_submit': tok})
    row = sql("SELECT UserID, AdminLevel FROM phoromatic_users WHERE UserName='%s'" % username)
    assert row, 'user %s not created' % username
    return row[0]['UserID']


def audit(**where):
    cond = ' AND '.join("%s='%s'" % (k, v) for k, v in where.items())
    return sql('SELECT * FROM phoromatic_private_repo_audit WHERE %s ORDER BY AuditID' % cond)


def stored(kind, name, version):
    return srv_sh('ls %s%ss/%s/%s.zip 2>/dev/null' % (REPO_DIR, kind, name, version)).strip() != ''


def escaped_title_zip(title_escaped):
    return make_zip([('test-definition.xml', TEST_XML % {'title': title_escaped, 'version': '1.0.0'}), ('install.sh', '#!/bin/sh\necho ok\n')])


class WebBase(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.admin = Browser().login(USER, PASS)                # AdminLevel 1, the account's first user
        cls.suffix = uuid.uuid4().hex[:6]
        cls.names = {}
        for level in (2, 3, 10):
            cls.names[level] = 'webq%d%s' % (level, cls.suffix)
            make_user(cls.admin, cls.names[level], level)
        cls.names[0] = 'webq0%s' % cls.suffix
        make_user(cls.admin, cls.names[0], 10)
        sql_exec("UPDATE phoromatic_users SET AdminLevel=0 WHERE UserName='%s'" % cls.names[0])
        cls.msi = new_machine()

    def as_user(self, level):
        return Browser().login(self.names[level], 'qcpass123')

    def admin2(self):
        return self.as_user(2)


class TestAccess(WebBase):
    def test_anonymous_gets_the_login_page_and_cannot_act(self):
        name = uniq('anon')
        self.assertIn('upload', upload(self.msi, 'test', name, test_zip()))
        b = Browser()
        status, _, body = b.get(PAGE)
        self.assertNotIn('Private Repository</h1>', body)
        self.assertIn('login', body.lower())
        status, _, body = b.post(ACTION, {'action': 'delete', 'kind': 'test', 'name': name, 'version': '1.0.0', 'token_submit': 'x'})
        self.assertTrue(stored('test', name, '1.0.0'), 'an anonymous POST deleted something')
        self.assertNotEqual(b.get(PAGE + '/audit&export=csv')[1].get('Content-Type', '').split(';')[0], 'text/csv')

    def test_levels_1_and_2_are_allowed_and_see_the_menu_entry(self):
        for label, b in (('level 1', self.admin), ('level 2', self.admin2())):
            with self.subTest(label):
                status, headers, body = b.get(PAGE)
                self.assertEqual(status, 200)
                self.assertIn('Private Repository</h1>', body)
                self.assertIn('href="?private_repository"', body, 'menu entry missing')
                for tab in ('/audit', '/tokens'):
                    self.assertIn('Private Repository</h1>', b.get(PAGE + tab)[2])

    def test_other_levels_are_refused_everywhere(self):
        name = uniq('lvl')
        upload(self.msi, 'test', name, test_zip())
        for level in (3, 10):
            with self.subTest(level):
                b = self.as_user(level)
                body = b.get(PAGE)[2]
                self.assertIn('account administrators only', body)
                self.assertNotIn('href="?private_repository"', b.get('/?main')[2], 'menu entry shown to level %d' % level)
                for tab in ('/audit', '/tokens', '/delete&kind=test&name=%s&version=1.0.0' % name):
                    self.assertNotIn('Yes, delete', b.get(PAGE + tab)[2])
                self.assertEqual(b.get(PAGE + '/audit&export=csv')[0], 403)
                self.assertNotEqual(b.get(PAGE + '/audit&export=csv')[1].get('Content-Type', '').split(';')[0], 'text/csv')
                # the handler re-checks the level itself, even with a perfectly valid session token
                m = re.search(r'name="token_submit" value="([0-9a-f]+)"', b.get('/?users')[2])
                if m:
                    self.assertEqual(b.post(ACTION, {'action': 'delete', 'kind': 'test', 'name': name, 'version': '1.0.0', 'token_submit': m.group(1)})[0], 403)
                self.assertTrue(stored('test', name, '1.0.0'))

    def test_a_disabled_user_cannot_log_in_and_a_demoted_session_loses_access_at_once(self):
        with self.assertRaises(AssertionError):
            Browser().login(self.names[0], 'qcpass123')
        b = self.admin2()
        self.assertIn('Private Repository</h1>', b.get(PAGE)[2])
        sql_exec("UPDATE phoromatic_users SET AdminLevel=3 WHERE UserName='%s'" % self.names[2])      # demoted while logged in
        self.addCleanup(sql_exec, "UPDATE phoromatic_users SET AdminLevel=2 WHERE UserName='%s'" % self.names[2])
        self.assertIn('account administrators only', b.get(PAGE)[2])
        self.assertEqual(b.post(ACTION, {'action': 'rebuild', 'token_submit': 'whatever'})[0], 403)

    def test_only_the_repository_owning_account_may_use_it(self):
        s = srv_sh('curl -s -c /tmp/cj_web -b /tmp/cj_web localhost:8080/ >/dev/null; curl -s -b /tmp/cj_web -X POST "localhost:8080/?register" '
                   '-d register_username=webiso%s -d register_password=qcpass123 -d register_password_confirm=qcpass123 -d register_email=w@example.com' % self.suffix)
        b = Browser().login('webiso' + self.suffix, 'qcpass123')
        self.assertIn('first account', b.get(PAGE)[2])
        self.assertEqual(b.post(ACTION, {'action': 'rebuild', 'token_submit': 'x'})[0], 403)


class TestHeadersAndMethods(WebBase):
    def test_framing_caching_and_method_hardening(self):
        for path in (PAGE, PAGE + '/audit', PAGE + '/tokens'):
            h = self.admin.get(path)[1]
            self.assertEqual(h.get('X-Frame-Options'), 'DENY', path)
            self.assertIn("frame-ancestors 'none'", h.get('Content-Security-Policy', ''))
            self.assertIn('no-store', h.get('Cache-Control', ''))
        status, h, _ = self.admin.get(ACTION)
        self.assertEqual(status, 405)
        self.assertEqual(h.get('Allow'), 'POST')
        self.assertEqual(h.get('X-Frame-Options'), 'DENY')

    def test_no_state_change_through_get(self):
        name = uniq('get')
        upload(self.msi, 'test', name, test_zip())
        tok = self.admin.token()
        before = len(audit(Action='delete_attempt', Name=name))
        for path in (ACTION + '&action=delete&kind=test&name=%s&version=1.0.0&token_submit=%s' % (name, tok),
                     PAGE + '/delete&kind=test&name=%s&version=1.0.0&action=delete&token_submit=%s' % (name, tok),
                     PAGE + '/revoke&id=1&token_submit=' + tok,
                     PAGE + '&action=rebuild&token_submit=' + tok):
            self.admin.get(path)
            self.assertTrue(stored('test', name, '1.0.0'), path)
        self.assertEqual(len(audit(Action='delete_attempt', Name=name)), before)

    def test_cross_origin_posts_are_refused(self):
        name = uniq('org')
        upload(self.msi, 'test', name, test_zip())
        tok = self.admin.token()
        status, _, _ = self.admin.post(ACTION, {'action': 'delete', 'kind': 'test', 'name': name, 'version': '1.0.0', 'token_submit': tok},
                                       headers={'Origin': 'http://evil.example'})
        self.assertEqual(status, 403)
        self.assertTrue(stored('test', name, '1.0.0'))


class TestCsrf(WebBase):
    """Every mutation, with no token, an empty one, a wrong one and a token from another session."""

    def attempts(self):
        a, b = self.admin, self.admin2()
        return [('no token', ''), ('wrong token', 'f' * 40), ('other session token', b.token()), ('prefix of token', a.token()[:10])]

    def check_refused(self, action, fields, unchanged):
        for label, tok in self.attempts():
            with self.subTest('%s / %s' % (action, label)):
                status, headers, _ = self.admin.act(action, token=tok, **fields)
                self.assertEqual(status, 302, 'refusal is a redirect with a flash, not a page')
                self.assertIn('nothing was changed', self.admin.get(PAGE)[2])
                self.assertTrue(unchanged())

    def test_delete(self):
        name = uniq('csrfd')
        upload(self.msi, 'test', name, test_zip())
        self.check_refused('delete', {'kind': 'test', 'name': name, 'version': '1.0.0'}, lambda: stored('test', name, '1.0.0') and not audit(Action='delete_attempt', Name=name))

    def test_rebuild(self):
        before = len(audit(Action='rebuild_attempt'))
        self.check_refused('rebuild', {}, lambda: len(audit(Action='rebuild_attempt')) == before)

    def test_token_create(self):
        before = sql('SELECT COUNT(*) AS n FROM phoromatic_private_repo_tokens')[0]['n']
        self.check_refused('token_create', {'label': 'csrf label'}, lambda: sql('SELECT COUNT(*) AS n FROM phoromatic_private_repo_tokens')[0]['n'] == before)

    def test_token_revoke(self):
        tok, tid = create_token('csrf revoke')
        self.check_refused('token_revoke', {'token_id': str(tid)}, lambda: sql('SELECT RevokedOn FROM phoromatic_private_repo_tokens WHERE TokenID=%d' % tid)[0]['RevokedOn'] is None)

    def test_unknown_action_changes_nothing(self):
        status, _, _ = self.admin.act('format_disk')
        self.assertEqual(status, 302)
        self.assertIn('Unknown action', self.admin.get(PAGE)[2])


class TestListDeleteRebuild(WebBase):
    def test_list_shows_the_stored_versions_escaped(self):
        name = uniq('lst')
        z = escaped_title_zip('&lt;img src=x onerror=alert(1)&gt;')
        self.assertIn('upload', upload(self.msi, 'test', name, z))
        body = self.admin.get(PAGE)[2]
        self.assertIn(name, body)
        self.assertIn('1.0.0', body)
        self.assertIn(hashlib.sha256(z).hexdigest()[:16], body)
        self.assertIn('&lt;img src=x onerror=alert(1)&gt;', body)
        self.assertNotIn('<img src=x onerror', body)
        self.assertRegex(body, r'system:\w+\s*<span class="pr-muted">\(cli\)')

    def test_delete_through_confirm_page_with_audit_and_redirect(self):
        name = uniq('del')
        upload(self.msi, 'test', name, test_zip())
        confirm = self.admin.get(PAGE + '/delete&kind=test&name=%s&version=1.0.0' % name)[2]
        self.assertIn('Yes, delete it', confirm)
        self.assertTrue(stored('test', name, '1.0.0'), 'the confirm page itself must not delete')
        status, headers, body = self.admin.act('delete', kind='test', name=name, version='1.0.0')
        self.assertEqual((status, headers['Location'], body), (302, '/?private_repository', ''))
        self.assertFalse(stored('test', name, '1.0.0'))
        self.assertIn('Deleted test %s-1.0.0' % name, self.admin.get(PAGE)[2])
        self.assertNotIn('Deleted test', self.admin.get(PAGE)[2], 'the flash message shows once')
        self.assertNotIn(name, self.admin.get(PAGE)[2].replace('Deleted test %s' % name, ''))
        self.assertTrue(srv_sh('ls %strash | grep -c %s' % (REPO_DIR, name)).strip() != '0', 'moved to trash')
        rows = audit(Name=name)
        self.assertEqual([r['Action'] for r in rows if r['Action'].startswith('delete')], ['delete_attempt', 'delete'])
        r = rows[-1]
        self.assertEqual((r['ActorType'], r['Actor'], r['Version'], r['Kind']), ('user', USER, '1.0.0', 'test'))
        self.assertRegex(r['ActorDetail'], r'^UserID \w+$')
        self.assertEqual(json.loads(r['Detail'])['source'], 'web')
        self.assertEqual(len(r['Sha256']), 64)
        self.assertNotIn(name, call('private_repo_index', self.msi)['index']['tests'])

    def test_delete_failures_are_audited_and_do_not_crash(self):
        name = uniq('delnone')
        self.admin.act('delete', kind='test', name=name, version='1.0.0')
        self.assertIn('No such', self.admin.get(PAGE)[2])
        self.assertEqual([r['Action'] for r in audit(Name=name)], ['delete_failed'])
        for fields in ({'kind': 'x', 'name': 'a', 'version': '1.0.0'}, {'kind': 'test', 'name': '../..', 'version': '1.0.0'}, {'kind': 'test', 'name': 'a', 'version': 'x'}, {}):
            status, _, _ = self.admin.act('delete', **fields)
            self.assertEqual(status, 302)

    def test_delete_fails_closed_when_the_audit_cannot_be_written(self):
        name = uniq('delfc')
        upload(self.msi, 'test', name, test_zip())
        self.assertEqual(sql_exec("CREATE TRIGGER qc_web_block BEFORE INSERT ON phoromatic_private_repo_audit WHEN NEW.Action='delete_attempt' BEGIN SELECT RAISE(ABORT,'qc'); END"), 'ok')
        self.addCleanup(sql_exec, 'DROP TRIGGER IF EXISTS qc_web_block')
        self.admin.act('delete', kind='test', name=name, version='1.0.0')
        self.assertIn('audit log could not be written', self.admin.get(PAGE)[2])
        self.assertTrue(stored('test', name, '1.0.0'), 'nothing may be deleted without an audit row')

    def test_rebuild_audited_and_reports_skipped_versions(self):
        name = uniq('skip')
        upload(self.msi, 'test', name, test_zip())
        srv_sh(': > %stests/%s/1.0.0.json' % (REPO_DIR, name))      # metadata lost: stored but not listed
        status, headers, _ = self.admin.act('rebuild')
        self.assertEqual((status, headers['Location']), (302, '/?private_repository'))
        page = self.admin.get(PAGE)[2]
        self.assertIn('Index rebuilt', page)
        self.assertIn('test %s-1.0.0' % name, page)
        again = self.admin.get(PAGE)[2]
        self.assertIn('Archives without valid metadata', again)          # persistent section on the list
        self.assertIn('delete&amp;kind=test&amp;name=%s' % name, again)
        rows = audit(Action='rebuild')
        self.assertEqual(json.loads(rows[-1]['Detail'])['source'], 'web')
        self.assertIn('test %s-1.0.0' % name, json.loads(rows[-1]['Detail'])['skipped'])
        self.assertEqual((rows[-1]['ActorType'], rows[-1]['Actor']), ('user', USER))
        self.assertTrue(audit(Action='rebuild_attempt'))
        # the skipped archive can be deleted from the page
        self.admin.act('delete', kind='test', name=name, version='1.0.0')
        self.assertFalse(stored('test', name, '1.0.0'))

    def test_rebuild_fails_closed_when_the_audit_cannot_be_written(self):
        self.assertEqual(sql_exec("CREATE TRIGGER qc_web_block2 BEFORE INSERT ON phoromatic_private_repo_audit WHEN NEW.Action='rebuild_attempt' BEGIN SELECT RAISE(ABORT,'qc'); END"), 'ok')
        self.addCleanup(sql_exec, 'DROP TRIGGER IF EXISTS qc_web_block2')
        before = srv_sh('stat -c %%Y %sindex.json' % REPO_DIR)
        self.admin.act('rebuild')
        self.assertIn('index was not rebuilt', self.admin.get(PAGE)[2])
        self.assertEqual(srv_sh('stat -c %%Y %sindex.json' % REPO_DIR), before)


class TestTokens(WebBase):
    def test_create_shows_the_token_once_and_only_there(self):
        b = self.admin
        status, headers, body = b.act('token_create', label='web <b>label</b>')
        self.assertEqual((status, body), (302, ''))
        self.assertEqual(headers['Location'], '/?private_repository/tokens')
        self.assertNotIn('bpr_', json.dumps(dict(headers)), 'the token must not be in the redirect')
        status, h, page = b.get(PAGE + '/tokens')
        tok = re.search(r'bpr_[A-Za-z0-9_-]{43}', page)
        self.assertTrue(tok, 'token shown on the post-create page')
        self.assertIn('no-store', h.get('Cache-Control', ''))
        token = tok.group(0)
        self.assertEqual(len(re.findall(re.escape(token), page)), 1)
        for later in (PAGE + '/tokens', PAGE, PAGE + '/audit'):
            self.assertNotIn(token, b.get(later)[2], 'the token must never be shown again (%s)' % later)
        other = self.admin2()
        self.assertNotIn(token, other.get(PAGE + '/tokens')[2])
        # label is escaped, the DB holds only the hash, and the token really works for the CLI endpoints
        self.assertIn('web &lt;b&gt;label&lt;/b&gt;', b.get(PAGE + '/tokens')[2])
        row = sql("SELECT * FROM phoromatic_private_repo_tokens WHERE TokenHash='%s'" % hashlib.sha256(token.encode()).hexdigest())
        self.assertEqual(len(row), 1)
        self.assertEqual(srv_sh("grep -rlaF '%s' /var/lib/benchy /var/log /var/cache/benchy /tmp 2>/dev/null | grep -v '/tmp/sess_' | head -1" % token).strip(), '')
        self.assertNotIn(token, json.dumps(sql('SELECT * FROM phoromatic_private_repo_audit')))
        self.assertIn('rebuild', call('private_repo_rebuild', self.msi, rt=token))
        # audit: a user created it, and later page loads show when/where it was last used
        created = [r for r in audit(Action='token_created') if json.loads(r['Detail'])['token_id'] == row[0]['TokenID']][0]
        self.assertEqual((created['ActorType'], created['Actor']), ('user', USER))
        self.assertRegex(created['ActorDetail'], r'^UserID \w+$')
        tokens_page = b.get(PAGE + '/tokens')[2]
        self.assertRegex(tokens_page, r'\d{4}-\d\d-\d\d[^<]* from [0-9.]+')

    def test_label_validation_and_fail_closed(self):
        n = lambda: sql('SELECT COUNT(*) AS n FROM phoromatic_private_repo_tokens')[0]['n']
        before = n()
        for label in ('', '   ', 'x' * 65):
            self.admin.act('token_create', label=label)
            self.assertIn('label of 1 to 64', self.admin.get(PAGE + '/tokens')[2], repr(label))
        self.assertEqual(n(), before)
        self.assertEqual(sql_exec("CREATE TRIGGER qc_web_block3 BEFORE INSERT ON phoromatic_private_repo_audit WHEN NEW.Action='token_created' BEGIN SELECT RAISE(ABORT,'qc'); END"), 'ok')
        self.addCleanup(sql_exec, 'DROP TRIGGER IF EXISTS qc_web_block3')
        self.admin.act('token_create', label='blocked')
        page = self.admin.get(PAGE + '/tokens')[2]
        self.assertIn('could not be created', page)
        self.assertNotRegex(page, r'bpr_[A-Za-z0-9_-]{43}')
        self.assertEqual(n(), before)

    def test_revoke_through_confirm_page(self):
        tok, tid = create_token('web revoke me')
        self.assertIn('rebuild', call('private_repo_rebuild', self.msi, rt=tok))
        self.assertIn('Yes, revoke it', self.admin.get(PAGE + '/revoke&id=%d' % tid)[2])
        self.assertIsNone(sql('SELECT RevokedOn FROM phoromatic_private_repo_tokens WHERE TokenID=%d' % tid)[0]['RevokedOn'], 'confirm page must not revoke')
        status, headers, _ = self.admin.act('token_revoke', token_id=str(tid))
        self.assertEqual((status, headers['Location']), (302, '/?private_repository/tokens'))
        self.assertIn('Token #%d revoked' % tid, self.admin.get(PAGE + '/tokens')[2])
        self.assertIn('not accepted', call('private_repo_rebuild', self.msi, rt=tok)['error'])
        self.assertNotIn('Yes, revoke it', self.admin.get(PAGE + '/revoke&id=%d' % tid)[2])
        self.admin.act('token_revoke', token_id=str(tid))
        self.assertIn('No active token', self.admin.get(PAGE + '/tokens')[2])
        row = [r for r in audit(Action='token_revoked') if json.loads(r['Detail'])['token_id'] == tid]
        self.assertEqual(len(row), 1)
        self.assertEqual((row[0]['ActorType'], row[0]['Actor']), ('user', USER))
        # a revoke that cannot be audited leaves the token alive
        tok2, tid2 = create_token('web revoke blocked')
        sql_exec("CREATE TRIGGER qc_web_block4 BEFORE INSERT ON phoromatic_private_repo_audit WHEN NEW.Action='token_revoked' BEGIN SELECT RAISE(ABORT,'qc'); END")
        self.addCleanup(sql_exec, 'DROP TRIGGER IF EXISTS qc_web_block4')
        self.admin.act('token_revoke', token_id=str(tid2))
        self.assertIn('rebuild', call('private_repo_rebuild', self.msi, rt=tok2))


class TestAuditView(WebBase):
    PREFIX = 'pgx'

    @classmethod
    def setUpClass(cls):
        super().setUpClass()
        cls.tag = 'pg' + cls.suffix
        php = ('$d=new SQLite3("/var/lib/benchy/phoromatic/phoromatic.db");'
               'for($i=0;$i<120;$i++){$st=$d->prepare("INSERT INTO phoromatic_private_repo_audit (AccountID, EventTime, ActorType, Actor, SourceIP, Action, Kind, Name, Version, Detail) '
               'VALUES (:a, :t, \'machine\', \'qc\', \'10.9.9.9\', :act, \'test\', :n, \'1.0.\'||:i, \'{}\')");'
               '$st->bindValue(":a",$argv[1]);$st->bindValue(":t",date("Y-m-d H:i:s"));$st->bindValue(":act",$i%2?"upload_rejected":"upload_failed");$st->bindValue(":n",$argv[2]);$st->bindValue(":i",$i);$st->execute();}')
        subprocess.run(['docker', 'exec', SRV, 'php', '-r', php, AID, cls.tag], capture_output=True)

    def rows_on(self, body):
        return re.findall(r'<td>test %s-1\.0\.(\d+)</td>' % self.tag, body)

    def test_pagination_walks_every_row_once_newest_first(self):
        seen = []
        path = PAGE + '/audit&name=' + self.tag
        pages = 0
        while path and pages < 10:
            body = self.admin.get(path)[2]
            seen += self.rows_on(body)
            m = re.search(r'href="(/\?private_repository/audit[^"]*before=\d+[^"]*)">Older', body)
            path = m.group(1).replace('&amp;', '&') if m else None
            pages += 1
            self.assertLessEqual(len(self.rows_on(body)), 50)
        self.assertEqual(pages, 3)
        self.assertEqual(len(seen), 120)
        self.assertEqual(len(set(seen)), 120)
        self.assertEqual(seen, [str(i) for i in range(119, -1, -1)])

    def test_filters_by_action_and_name_including_the_post_form(self):
        body = self.admin.get(PAGE + '/audit&name=%s&action=upload_failed' % self.tag)[2]
        self.assertEqual(len(self.rows_on(body)), 50)
        self.assertTrue(all(int(i) % 2 == 0 for i in self.rows_on(body)))
        status, _, body = self.admin.post(PAGE + '/audit', {'name': self.tag, 'action': 'upload_rejected'})
        self.assertTrue(all(int(i) % 2 == 1 for i in self.rows_on(body)))
        self.assertIn('No audit rows match', self.admin.get(PAGE + '/audit&name=no-such-name-here')[2])

    def hostile(self):
        cells = {'html': '<script>alert(1)</script>', 'attr': '"><img src=x onerror=alert(2)>', 'eq': '=cmd|\' /C calc\'!A0', 'plus': '+1+1', 'minus': '-2+3', 'at': '@SUM(1+1)',
                 'quote': 'a"b', 'comma': 'a,b', 'newline': 'line1\nline2', 'tab': '\t=tab'}
        for key, value in cells.items():
            php = ('$d=new SQLite3("/var/lib/benchy/phoromatic/phoromatic.db");$st=$d->prepare("INSERT INTO phoromatic_private_repo_audit (AccountID, EventTime, ActorType, Actor, SourceIP, Action, Kind, Name, Detail) '
                   'VALUES (:a, :t, \'machine\', :v, \'10.9.9.8\', \'upload_rejected\', \'test\', :n, :v)");$st->bindValue(":a",$argv[1]);$st->bindValue(":t",date("Y-m-d H:i:s"));$st->bindValue(":v",$argv[2]);$st->bindValue(":n",$argv[3]);$st->execute();')
            subprocess.run(['docker', 'exec', SRV, 'php', '-r', php, AID, value, 'hostile-' + self.suffix], capture_output=True)
        return cells

    def test_html_and_script_in_audit_values_are_escaped(self):
        self.hostile()
        body = self.admin.get(PAGE + '/audit&name=hostile-' + self.suffix)[2]
        self.assertNotIn('<script>alert(1)</script>', body)
        self.assertNotIn('<img src=x onerror=alert(2)>', body)
        self.assertIn('&lt;script&gt;alert(1)&lt;/script&gt;', body)
        self.assertIn('&quot;&gt;&lt;img src=x onerror=alert(2)&gt;', body)

    def test_csv_export_is_proper_csv_and_formula_safe(self):
        cells = self.hostile()
        status, headers, body = self.admin.get(PAGE + '/audit&export=csv&name=hostile-' + self.suffix)
        self.assertEqual(status, 200)
        self.assertEqual(headers['Content-Type'].split(';')[0], 'text/csv')
        self.assertIn('attachment', headers['Content-Disposition'])
        self.assertEqual(headers.get('X-Frame-Options'), 'DENY')
        rows = list(csv.reader(io.StringIO(body, newline='')))
        self.assertEqual(rows[0][:4], ['AuditID', 'AccountID', 'EventTime', 'ActorType'])
        actors = [r[4] for r in rows[1:]]
        self.assertEqual(len(rows) - 1, len(cells))
        ids = [int(r[0]) for r in rows[1:]]
        self.assertEqual(ids, sorted(ids), 'oldest first')
        self.assertIn("'=cmd|' /C calc'!A0", actors)
        self.assertIn("'+1+1", actors)
        self.assertIn("'-2+3", actors)
        self.assertIn("'@SUM(1+1)", actors)
        self.assertIn("'\t=tab", actors)
        for safe in ('a"b', 'a,b', 'line1\nline2', '<script>alert(1)</script>'):
            self.assertIn(safe, actors, 'CSV quoting must round-trip ' + repr(safe))
        for r in rows[1:]:
            for cell in r:
                self.assertFalse(cell[:1] in '=+-@\t\r' and cell != '', 'unneutralised cell %r' % cell)

    def test_csv_export_honours_filters_and_streams_everything(self):
        body = self.admin.get(PAGE + '/audit&export=csv&name=' + self.tag)[2]
        rows = list(csv.reader(io.StringIO(body, newline='')))
        self.assertEqual(len(rows) - 1, 120)
        body = self.admin.get(PAGE + '/audit&export=csv&name=%s&action=upload_failed' % self.tag)[2]
        self.assertEqual(len(list(csv.reader(io.StringIO(body, newline='')))) - 1, 60)
        self.assertEqual(self.admin.get(PAGE + '/audit&export=xml')[0], 400)

    def test_real_web_actions_show_up_in_the_view(self):
        tok, tid = create_token('audit visible')
        self.admin.act('rebuild')
        body = self.admin.get(PAGE + '/audit&action=rebuild')[2]
        self.assertIn('user ' + USER, body)
        self.assertRegex(body, r'UserID \w+')


class TestOrigin(WebBase):
    """What real browsers send: Origin on form POSTs (null under some privacy settings), Referer on older ones."""

    PORT = BASE.rsplit(':', 1)[1]

    def post_rebuild(self, headers, token=None):
        b = self.admin
        return b.post(ACTION, {'action': 'rebuild', 'token_submit': b.token() if token is None else token}, headers=headers)

    def assert_accepted(self, headers, label):
        status, h, body = self.post_rebuild(headers)
        self.assertEqual(status, 302, label)
        self.assertIn('Index rebuilt', self.admin.get(PAGE)[2], label)

    def assert_blocked(self, headers, label):
        status, h, body = self.post_rebuild(headers)
        self.assertEqual(status, 403, label)
        self.assertIn('did not appear to come from this site', body, label)
        self.assertNotIn('Index rebuilt', self.admin.get(PAGE)[2], label)

    def test_same_origin_is_accepted(self):
        self.assert_accepted({'Origin': BASE}, 'same Origin')

    def test_origin_null_is_treated_as_absent(self):
        self.assert_accepted({'Origin': 'null'}, 'Origin null with a valid token')
        status, _, _ = self.post_rebuild({'Origin': 'null'}, token='f' * 40)
        self.assertEqual(status, 302)
        self.assertIn('nothing was changed', self.admin.get(PAGE)[2], 'Origin null with a bad token is refused by the token check')

    def test_cross_origin_is_blocked_with_a_clear_message(self):
        self.assert_blocked({'Origin': 'http://evil.example'}, 'cross Origin')
        self.assert_blocked({'Origin': 'null', 'Referer': 'http://evil.example/page'}, 'null Origin falls through to a cross-site Referer')
        self.assert_blocked({'Origin': 'http://evil.example', 'X-Forwarded-Host': 'evil.example'}, 'X-Forwarded-Host is not trusted')

    def test_referer_only(self):
        self.assert_accepted({'Referer': BASE + '/?private_repository'}, 'same-site Referer')
        self.assert_blocked({'Referer': 'http://evil.example/?private_repository'}, 'cross-site Referer')

    def test_host_case_and_default_ports(self):
        self.assert_accepted({'Host': 'Web.Example.TEST:%s' % self.PORT, 'Origin': 'http://web.example.test:%s' % self.PORT}, 'different case')
        self.assert_accepted({'Host': 'web.example.test', 'Origin': 'http://web.example.test:80'}, 'explicit default port')
        self.assert_blocked({'Host': 'web.example.test:%s' % self.PORT, 'Origin': 'http://web.example.test:%d' % (int(self.PORT) + 1)}, 'other port')

    def test_referrer_policy_lets_browsers_send_a_real_origin(self):
        self.assertEqual(self.admin.get(PAGE)[1].get('Referrer-Policy'), 'same-origin')
        self.assertEqual(self.admin.get(ACTION)[1].get('Referrer-Policy'), 'same-origin')


class TestSession(WebBase):
    def session_file_with(self, needle):
        return srv_sh("grep -l '%s' /tmp/sess_* /var/lib/php/sessions/sess_* 2>/dev/null | head -1" % needle).strip()

    def test_session_cookie_flags(self):
        b = Browser()
        _, headers, _ = b.get('/')                       # the session cookie is issued on first contact
        cookies = ' '.join(headers.get_all('Set-Cookie') or [])
        self.assertIn('SameSite=Lax', cookies)
        self.assertIn('HttpOnly', cookies)

    def test_stashed_token_expires_on_any_guarded_request_and_leaves_no_trace(self):
        b = self.admin2()
        b.act('token_create', label='expiry check')
        f = self.session_file_with('PrivateRepoNewToken')
        self.assertTrue(f, 'the token waits in the session between the redirect and the next view')
        token_in_file = srv_sh("grep -o 'bpr_[A-Za-z0-9_-]*' %s | head -1" % f).strip()
        self.assertRegex(token_in_file, r'^bpr_')
        srv_sh("sed -i 's/s:4:\"time\";i:[0-9]*;/s:4:\"time\";i:1000;/' %s" % f)          # pretend it was stashed long ago
        b.get(PAGE + '/audit')                                                          # any other guarded request
        self.assertEqual(srv_sh("grep -c 'PrivateRepoNewToken' %s" % f).strip(), '0', 'the expired token must be gone from the session file')
        self.assertNotIn(token_in_file, b.get(PAGE + '/tokens')[2])

    def test_viewing_the_token_removes_it_from_the_session_file(self):
        b = self.admin2()
        b.act('token_create', label='view once')
        f = self.session_file_with('PrivateRepoNewToken')
        self.assertTrue(f)
        self.assertRegex(b.get(PAGE + '/tokens')[2], r'bpr_[A-Za-z0-9_-]{43}')
        self.assertEqual(srv_sh("grep -c 'bpr_' %s" % f).strip(), '0')

    def test_demotion_drops_a_stashed_token(self):
        b = self.admin2()
        b.act('token_create', label='demoted soon')
        f = self.session_file_with('PrivateRepoNewToken')
        self.assertTrue(f)
        sql_exec("UPDATE phoromatic_users SET AdminLevel=3 WHERE UserName='%s'" % self.names[2])
        self.addCleanup(sql_exec, "UPDATE phoromatic_users SET AdminLevel=2 WHERE UserName='%s'" % self.names[2])
        b.get(PAGE)
        self.assertEqual(srv_sh("grep -c 'PrivateRepoNewToken' %s" % f).strip(), '0')


class TestCsvEdgeCases(WebBase):
    def insert(self, name, actors, detail=None):
        php = ('$d=new SQLite3("/var/lib/benchy/phoromatic/phoromatic.db");foreach(json_decode($argv[3],true) as $v){$st=$d->prepare("INSERT INTO phoromatic_private_repo_audit '
               '(AccountID, EventTime, ActorType, Actor, SourceIP, Action, Kind, Name, Detail) VALUES (:a, :t, \'machine\', :v, \'10.9.9.7\', \'upload_rejected\', \'test\', :n, :d)");'
               '$st->bindValue(":a",$argv[1]);$st->bindValue(":t",date("Y-m-d H:i:s"));$st->bindValue(":v",$v);$st->bindValue(":n",$argv[2]);$st->bindValue(":d",$v);$st->execute();}')
        subprocess.run(['docker', 'exec', SRV, 'php', '-r', php, AID, name, json.dumps(actors)], capture_output=True)

    def test_backslash_and_quote_payloads_round_trip_and_cannot_inject_formulas(self):
        name = 'bs-' + self.suffix
        payloads = ['a" ,=1+1', 'x\\"y', '\\"', '\\\\', '=\\"cmd', '\\"=1+1', 'plain\\', 'q"\\",=2', '", =HYPERLINK("http://evil")', '\\",\n=1+1']
        self.insert(name, payloads)
        status, headers, body = self.admin.get(PAGE + '/audit&export=csv&name=' + name)
        self.assertEqual(status, 200)
        rows = list(csv.reader(io.StringIO(body, newline=''), escapechar=None, doublequote=True))
        self.assertEqual(len(rows) - 1, len(payloads))
        for r in rows:
            self.assertEqual(len(r), 15, 'every record must have exactly the 15 columns: %r' % r)
        got = [r[4] for r in rows[1:]]
        for p in payloads:
            expected = "'" + p if p[0] in '=+-@\t\r' else p
            self.assertIn(expected, got, repr(p))
        for r in rows[1:]:
            for cell in r:
                self.assertFalse(cell != '' and cell[0] in '=+-@\t\r', 'cell starting with a formula character: %r' % cell)

    def test_export_row_cap_is_marked_in_the_file(self):
        name = 'cap-' + self.suffix
        php = ('$d=new SQLite3("/var/lib/benchy/phoromatic/phoromatic.db");$d->exec("BEGIN");$st=$d->prepare("INSERT INTO phoromatic_private_repo_audit '
               '(AccountID, EventTime, ActorType, Actor, SourceIP, Action, Kind, Name, Detail) VALUES (:a, :t, \'machine\', \'qc\', \'10.9.9.6\', \'upload_rejected\', \'test\', :n, \'{}\')");'
               '$st->bindValue(":a",$argv[1]);$st->bindValue(":t",date("Y-m-d H:i:s"));$st->bindValue(":n",$argv[2]);for($i=0;$i<50001;$i++){$st->execute();$st->reset();}$d->exec("COMMIT");')
        subprocess.run(['docker', 'exec', SRV, 'php', '-r', php, AID, name], capture_output=True)
        body = self.admin.get(PAGE + '/audit&export=csv&name=' + name)[2]
        rows = list(csv.reader(io.StringIO(body, newline='')))
        # Phase 3 (F2): the cap keeps the NEWEST rows, so the marker is now the first data row (older rows are what is left out)
        self.assertEqual(len(rows), 1 + 1 + 50000, 'header + marker + capped rows')
        self.assertTrue(rows[1][0].startswith('# TRUNCATED: only the newest 50000 rows'))
        self.assertFalse(any(r[0].startswith('# TRUNCATED') for r in rows[2:]))


class TestMigration48(WebBase):
    def test_v47_database_gains_the_name_index(self):
        sql_exec('DROP INDEX IF EXISTS phoromatic_private_repo_audit_name')
        sql_exec('PRAGMA user_version = 47')
        self.assertEqual(sql('PRAGMA user_version')[0]['user_version'], 47)
        call('private_repo_index', self.msi)                       # any request runs prepare_database()
        self.assertGreaterEqual(sql('PRAGMA user_version')[0]['user_version'], 48)     # later steps (tombstones, audit) run too
        self.assertTrue(sql("SELECT name FROM sqlite_master WHERE type='index' AND name='phoromatic_private_repo_audit_name'"))
        call('private_repo_index', self.msi)                       # idempotent
        self.assertGreaterEqual(sql('PRAGMA user_version')[0]['user_version'], 48)


class TestRobustnessAndDisabled(WebBase):
    def test_hand_edited_metadata_never_breaks_the_page(self):
        good, bad = uniq('good'), uniq('bad')
        upload(self.msi, 'test', good, test_zip())
        upload(self.msi, 'test', bad, test_zip())
        meta = {'info': {}, 'title': ['array'], 'uploaded': 'yesterday', 'source': {'a': 1}, 'uploaded_by': ['u'], 'sha256': ['h'], 'sha1': {'x': 1}, 'commit_description': ['d']}
        srv_sh("printf '%%s' '%s' > %stests/%s/1.0.0.json" % (json.dumps(meta), REPO_DIR, bad))
        status, _, body = self.admin.get(PAGE)
        self.assertEqual(status, 200)
        self.assertIn(good, body)
        self.assertIn(bad, body)
        self.assertIn('</html>', body, 'the page must render to its end')
        srv_sh("printf '%%s' '[1,2' > %stests/%s/1.0.0.json" % (REPO_DIR, bad))   # not even JSON: listed as an archive without metadata
        body = self.admin.get(PAGE)[2]
        self.assertIn(good, body)
        self.assertIn('Archives without valid metadata', body)

    def test_disabled_repository_hides_and_blocks_the_forms(self):
        cfg = '/etc/phoronix-test-suite.xml'     # where the daemonized server layout reads its configuration
        had = srv_sh('test -f %s && echo yes' % cfg).strip() == 'yes'
        srv_sh('cp %s %s.qcbak' % (cfg, cfg) if had else 'true')
        self.addCleanup(srv_sh, ('mv -f %s.qcbak %s' % (cfg, cfg)) if had else 'rm -f %s' % cfg)
        name = uniq('dis')
        upload(self.msi, 'test', name, test_zip())
        srv_sh("printf '%%s' '<?xml version=\"1.0\"?><PhoronixTestSuite><Options><OpenBenchmarking><PrivateRepository>off</PrivateRepository></OpenBenchmarking></Options></PhoronixTestSuite>' > %s" % cfg)
        body = self.admin.get(PAGE)[2]
        self.assertIn('disabled on this server', body)
        self.assertNotIn('Rebuild the index', body)
        self.assertNotIn('private_repository/delete', body)
        self.assertIn('disabled', self.admin.get(PAGE + '/delete&kind=test&name=%s&version=1.0.0' % name)[2])
        before = len(audit(Action='rebuild_attempt'))
        tok = self.admin.token(PAGE + '/tokens')          # the list page has no forms while disabled
        self.admin.act('rebuild', token=tok)
        self.assertIn('disabled on this server; nothing was changed', self.admin.get(PAGE)[2])
        self.admin.act('delete', token=tok, kind='test', name=name, version='1.0.0')
        self.assertIn('disabled', self.admin.get(PAGE)[2])
        self.assertEqual(len(audit(Action='rebuild_attempt')), before)
        self.assertTrue(stored('test', name, '1.0.0'))


class TestShutdownHandler(WebBase):
    def test_fatal_after_the_final_audit_row_ends_in_a_message_not_a_blank_page(self):
        name = uniq('webfatal')
        upload(self.msi, 'test', name, test_zip())
        index = srv_sh('find /var/cache/benchy /var/lib/benchy -name private.index 2>/dev/null | head -1').strip()
        self.assertTrue(index)
        srv_sh('cp %s %s.qcbak && truncate -s 80M %s' % (index, index, index))      # the cache sync after the delete reads this in full
        self.addCleanup(srv_sh, 'mv -f %s.qcbak %s' % (index, index))
        b = self.admin
        sid = [c.value for c in b.jar if c.name == 'PHPSESSID'][0]
        tok = b.token()
        driver = ('$in=json_decode(stream_get_contents(STDIN),true); $_POST=$_REQUEST=$in["post"]; $_COOKIE=array("PHPSESSID"=>$in["sid"]);'
                  '$_SERVER["REQUEST_METHOD"]="POST"; $_SERVER["REQUEST_URI"]="/?r_private_repository_action"; $_SERVER["QUERY_STRING"]="r_private_repository_action";'
                  '$_SERVER["REMOTE_ADDR"]="127.0.0.5"; $_SERVER["HTTP_HOST"]="x"; chdir("/bench/pts-core/phoromatic/public_html"); include "index.php";')
        run = subprocess.run(['docker', 'exec', '-i', SRV, 'php', '-d', 'memory_limit=40M', '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', 'error_log=/dev/stderr', '-r', driver],
                             input=json.dumps({'sid': sid, 'post': {'action': 'delete', 'kind': 'test', 'name': name, 'version': '1.0.0', 'token_submit': tok}}), capture_output=True, text=True)
        self.assertIn('Allowed memory size', run.stderr, 'the injected fatal did not happen: ' + run.stderr[:200])
        self.assertFalse(stored('test', name, '1.0.0'), 'the delete itself completed')
        self.assertEqual([r['Action'] for r in audit(Name=name) if r['Action'].startswith('delete')], ['delete_attempt', 'delete'])
        page = b.get(PAGE)[2]
        self.assertIn('The server stopped while handling this action after it had been completed and recorded', page)


if __name__ == '__main__':
    unittest.main()
