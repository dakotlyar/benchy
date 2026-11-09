"""Changes need a POST with the CSRF token: deleting results, removing a test from a schedule, removing a schedule trigger.
A GET (a link, an image tag) changes nothing, a POST without the token or from another site is refused, a POST with the token works for the
account's own data, and the other account's rows and files stay byte-identical whatever is sent."""
import json
import os
import shutil
import subprocess
import unittest

import tn

EVIL = {'Origin': 'http://evil.example'}


def stamp():
    return tn.uuid.uuid4().hex[:8]


class Fixture(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.st = tn.state()
        cls.t1, cls.t2 = cls.st['T1'], cls.st['T2']
        cls.b = tn.logins('T1')

    def setUp(self):
        self.before_t2 = tn.snapshot(self.t2['aid'])
        self.addCleanup(self.assert_t2_untouched)

    def assert_t2_untouched(self):
        after = tn.snapshot(self.t2['aid'])
        self.assertEqual(after['rows'], self.before_t2['rows'])
        self.assertEqual(after['files'], self.before_t2['files'])


class DeleteResults(Fixture):
    def new_result(self):
        n = stamp()
        upload = 600000 + int(n[:5], 16) % 90000
        pprid = 'TNDEL' + n
        tn.sql_exec("INSERT INTO phoromatic_results (AccountID, UploadID, ScheduleID, SystemID, UploadTime, Title, PPRID) VALUES ('%s', %d, %d, '%s', '2026-11-01 10:00:00', 'tndel %s', '%s')"
                    % (self.t1['aid'], upload, tn.SHARED_SCHEDULE, self.t1['system'], n, pprid))
        tn.srv_sh("mkdir -p %s/accounts/%s/results/%d && echo x > %s/accounts/%s/results/%d/composite.xml" % (tn.STORAGE, self.t1['aid'], upload, tn.STORAGE, self.t1['aid'], upload))
        self.addCleanup(tn.sql_exec, "DELETE FROM phoromatic_results WHERE PPRID = '%s'" % pprid)
        self.addCleanup(tn.srv_sh, "rm -rf %s/accounts/%s/results/%d" % (tn.STORAGE, self.t1['aid'], upload))
        return pprid, upload

    def exists(self, pprid, upload):
        row = bool(tn.sql("SELECT 1 FROM phoromatic_results WHERE PPRID = '%s'" % pprid))
        folder = tn.srv_sh("test -f %s/accounts/%s/results/%d/composite.xml && echo yes" % (tn.STORAGE, self.t1['aid'], upload)).strip() == 'yes'
        self.assertEqual(row, folder, 'the row and the folder must go together')
        return row

    def test_a_get_deletes_nothing(self):
        pprid, upload = self.new_result()
        for lvl in (1, 2, 3):
            status, _, body = self.b[lvl].get('/?results/delete/%s' % pprid)
            self.assertTrue(self.exists(pprid, upload), 'level %d: a GET deleted the result' % lvl)
            self.assertIn('Nothing was deleted', body)

    def test_a_post_without_the_token_or_with_a_wrong_one_deletes_nothing(self):
        pprid, upload = self.new_result()
        for lvl in (1, 2, 3):
            self.b[lvl].post('/?results/delete/%s' % pprid, {'x': '1'})
            self.b[lvl].post('/?results/delete/%s' % pprid, {'token_submit': 'a' * 40})
            self.b[lvl].post('/?results/delete/%s' % pprid, {'token_submit': ''})
            self.assertTrue(self.exists(pprid, upload), 'level %d' % lvl)

    def test_a_post_from_another_site_deletes_nothing_even_with_the_token(self):
        pprid, upload = self.new_result()
        tok = self.b[1].token('/?results')
        self.b[1].post('/?results/delete/%s' % pprid, {'token_submit': tok}, headers=EVIL)
        self.assertTrue(self.exists(pprid, upload))

    def test_a_post_with_the_token_deletes_the_accounts_own_result(self):
        for lvl in (1, 2, 3):
            with self.subTest(level=lvl):
                pprid, upload = self.new_result()
                self.assertTrue(self.exists(pprid, upload))
                tok = self.b[lvl].token('/?results')
                self.b[lvl].post('/?results/delete/%s' % pprid, {'token_submit': tok})
                self.assertFalse(self.exists(pprid, upload))

    def test_several_ids_in_one_post_all_go(self):
        a, b = self.new_result(), self.new_result()
        tok = self.b[1].token('/?results')
        self.b[1].post('/?results/delete/%s,%s' % (a[0], b[0]), {'token_submit': tok})
        self.assertFalse(self.exists(*a))
        self.assertFalse(self.exists(*b))

    def test_a_viewer_cannot_delete_even_with_the_token(self):
        pprid, upload = self.new_result()
        tok = self.b[10].token('/?results')
        self.b[10].post('/?results/delete/%s' % pprid, {'token_submit': tok})
        self.assertTrue(self.exists(pprid, upload))

    def test_the_filter_form_never_posts_back_to_a_delete_address(self):
        # the form carries a valid token, so if it posted to the delete address, pressing Update would delete
        pprid, upload = self.new_result()
        tok = self.b[1].token('/?results')
        for method in ('get', 'post'):
            page = self.b[1].get('/?results/delete/%s' % pprid)[2] if method == 'get' else None
            if method == 'post':
                page = self.b[1].post('/?results/delete/%s' % pprid, {'token_submit': tok})[2]
                self.assertFalse(self.exists(pprid, upload))
                pprid, upload = self.new_result()
            m = tn.re.search(r'<form action="([^"]*)" method="post"><input type="hidden" name="token_submit"', page)
            self.assertTrue(m, 'the filter form is on the page')
            self.assertEqual(m.group(1), '?results')
        # and the Update button on the page after a refused GET really changes nothing
        page = self.b[1].get('/?results/delete/%s' % pprid)[2]
        action = tn.re.search(r'<form action="([^"]*)" method="post"><input type="hidden" name="token_submit"', page).group(1)
        self.b[1].post('/' + action, {'token_submit': tok, 'result_limit': '100'})
        self.assertTrue(self.exists(pprid, upload))

    @unittest.skipUnless(shutil.which('node'), 'node is needed to run the sidebar script')
    def test_the_delete_script_builds_a_post_form_with_the_token(self):
        js = os.path.join(tn.HERE, '..', '..', 'pts-core', 'phoromatic', 'public_html', 'phoromatic.js')
        harness = r'''
        const forms = []; let submitted = null;
        global.localStorage = {comparison_pprids: JSON.stringify(['A1', 'B/2'])};
        localStorage.removeItem = function(k) { delete this[k]; };
        global.Storage = function() {};
        global.confirm = () => true;
        global.window = {location: {}};
        global.document = {documentElement: {setAttribute() {}}, addEventListener() {}, getElementById: () => null, getElementsByTagName: () => [], querySelector: () => null, createElement: (t) => { const e = {tag: t, children: [], appendChild(c) { this.children.push(c); }, submit() { submitted = this; }}; return e; },
                           body: {appendChild(f) { forms.push(f); }}};
        eval(require('fs').readFileSync(process.argv[1], 'utf8') + '; phoromatic_delete_results("?results/delete/", "TOK123");');
        console.log(JSON.stringify({method: submitted.method, action: submitted.action, fields: submitted.children.map(c => [c.name, c.value]), href: window.location.href || null}));
        '''
        out = subprocess.run(['node', '-e', harness, js], capture_output=True, text=True, timeout=30)
        got = json.loads(out.stdout)
        self.assertEqual(got['method'], 'post')
        self.assertEqual(got['action'], '?results/delete/A1,B%2F2')
        self.assertEqual(got['fields'], [['token_submit', 'TOK123']])
        self.assertIsNone(got['href'])      # it does not navigate (a GET) any more

    def test_the_delete_button_posts_the_token(self):
        # the sidebar passes the session token to the script, which builds a POST form (a link is not enough any more)
        tok = self.b[1].token('/?results')
        page = self.b[1].get('/?results')[2]
        self.assertIn("phoromatic_delete_results('?results/delete/', '%s')" % tok, page)

    def test_the_other_accounts_results_are_not_deleted(self):
        tok = self.b[1].token('/?results')
        for pprid in (self.t2['pprid'], 'TN2PPR00', 'TN2PPR01'):
            self.b[1].get('/?results/delete/%s' % pprid)
            self.b[1].post('/?results/delete/%s' % pprid, {'token_submit': tok})
        self.assertTrue(tn.sql("SELECT 1 FROM phoromatic_results WHERE PPRID = '%s'" % self.t2['pprid']))


class ScheduleRemovals(Fixture):
    def setUp(self):
        super().setUp()
        self.sched = tn.SHARED_SCHEDULE
        self.aid = self.t1['aid']
        n = stamp()
        self.test, self.args = 'pts/tn-%s-1.0.0' % n, '--tn %s' % n
        self.trigger = 'tntrigger-%s' % n
        tn.sql_exec("INSERT INTO phoromatic_schedules_tests (AccountID, ScheduleID, TestProfile, TestArguments, TestDescription) VALUES ('%s', %d, '%s', '%s', 'tn')" % (self.aid, self.sched, self.test, self.args))
        tn.sql_exec("INSERT INTO phoromatic_schedules_triggers (AccountID, ScheduleID, Trigger, TriggeredOn) VALUES ('%s', %d, '%s', '2026-11-01 10:00:00')" % (self.aid, self.sched, self.trigger))
        self.addCleanup(tn.sql_exec, "DELETE FROM phoromatic_schedules_tests WHERE TestProfile = '%s'" % self.test)
        self.addCleanup(tn.sql_exec, "DELETE FROM phoromatic_schedules_triggers WHERE Trigger = '%s'" % self.trigger)
        self.test_path = '/?schedules/%d/remove/%s' % (self.sched, tn.b64(self.test + '\n' + self.args))
        self.trigger_path = '/?schedules/%d/delete-trigger/%s' % (self.sched, tn.b64(self.trigger))

    def has_test(self):
        return bool(tn.sql("SELECT 1 FROM phoromatic_schedules_tests WHERE TestProfile = '%s'" % self.test))

    def trigger_present(self):
        return bool(tn.sql("SELECT 1 FROM phoromatic_schedules_triggers WHERE Trigger = '%s'" % self.trigger))

    def test_the_page_offers_buttons_that_post(self):
        page = self.b[1].get('/?schedules/%d' % self.sched)[2]
        self.assertIn('<form action="?schedules/%d/remove/' % self.sched, page)
        self.assertIn('value="Remove Test"', page)
        self.assertIn('<form action="?schedules/%d/delete-trigger/' % self.sched, page)
        self.assertIn('value="Remove Trigger"', page)

    def test_a_get_removes_nothing(self):
        for lvl in (1, 2, 3):
            self.assertIn('Nothing was removed', self.b[lvl].get(self.test_path)[2])
            self.assertIn('Nothing was removed', self.b[lvl].get(self.trigger_path)[2])
            self.assertTrue(self.has_test(), 'level %d' % lvl)
            self.assertTrue(self.trigger_present(), 'level %d' % lvl)

    def test_a_post_without_the_token_or_from_another_site_removes_nothing(self):
        tok = self.b[1].token('/?results')
        for path in (self.test_path, self.trigger_path):
            self.b[1].post(path, {'x': '1'})
            self.b[1].post(path, {'token_submit': 'b' * 40})
            self.b[1].post(path, {'token_submit': tok}, headers=EVIL)
        self.assertTrue(self.has_test())
        self.assertTrue(self.trigger_present())

    def test_a_viewer_cannot_remove_even_with_the_token(self):
        tok = self.b[10].token('/?results')
        self.b[10].post(self.test_path, {'token_submit': tok})
        self.b[10].post(self.trigger_path, {'token_submit': tok})
        self.assertTrue(self.has_test())
        self.assertTrue(self.trigger_present())

    def test_a_post_with_the_token_removes_them(self):
        tok = self.b[1].token('/?results')
        self.b[1].post(self.test_path, {'token_submit': tok})
        self.assertFalse(self.has_test())
        self.assertTrue(self.trigger_present())
        self.b[1].post(self.trigger_path, {'token_submit': tok})
        self.assertFalse(self.trigger_present())

    def test_the_other_accounts_schedule_is_not_touched_with_the_token(self):
        tok = self.b[1].token('/?results')
        t2s = self.t2['sched']
        t2_test = tn.sql("SELECT ScheduleID, TestProfile, TestArguments FROM phoromatic_schedules_tests WHERE AccountID = '%s' LIMIT 1" % self.t2['aid'])
        self.assertTrue(t2_test, 'the fixture seeds a test for the other account, so the loop below is not empty')
        paths = ['/?schedules/%d/delete-trigger/%s' % (t2s, self.t2['trigger_b64']), '/?schedules/%d/delete-trigger/%s' % (self.sched, self.t2['trigger_b64'])]
        for r in t2_test:
            enc = tn.b64(r['TestProfile'] + '\n' + r['TestArguments'])
            paths += ['/?schedules/%d/remove/%s' % (r['ScheduleID'], enc), '/?schedules/%d/remove/%s' % (t2s, enc), '/?schedules/%d/remove/%s' % (self.sched, enc)]
        for p in paths:
            self.b[1].get(p)
            self.b[1].post(p, {'token_submit': tok})
        # (assert_t2_untouched, run after the test, checks every row and file of the other account)


TP = '/var/lib/benchy/test-profiles/'
OUTSIDE = '/var/lib/benchy/tn-outside/'


class CreateTestPage(Fixture):
    """The test profile creator writes into one store shared by every account, so it is off unless the server turns it on, and then it only touches
    local/<name> folders and only on a POST with the token from this site."""

    def setUp(self):
        super().setUp()
        self.n = stamp()
        self.mine = 'tnmine%s-1.0.0' % self.n
        self.shared = 'tnshared%s-1.0.0' % self.n
        tn.srv_sh("mkdir -p %(tp)slocal/%(m)s %(tp)spts/%(s)s %(o)s && echo MINE > %(tp)slocal/%(m)s/install.sh && echo '<PhoronixTestSuite><TestInformation><Title>tn</Title></TestInformation><TestProfile><Version>1.0.0</Version></TestProfile></PhoronixTestSuite>' > %(tp)slocal/%(m)s/test-definition.xml"
                  " && echo SHARED > %(tp)spts/%(s)s/install.sh && echo SHARED > %(tp)spts/%(s)s/test-definition.xml && echo KEEP > %(o)skeep.txt"
                  % {'tp': TP, 'm': self.mine, 's': self.shared, 'o': OUTSIDE})
        self.addCleanup(tn.srv_sh, "rm -rf %slocal/%s %slocal/tnnew%s* %spts/%s" % (TP, self.mine, TP, self.n, TP, self.shared))
        self.addCleanup(tn.srv_sh, "rm -rf %s" % OUTSIDE)
        self.addCleanup(self.setting, 0)
        self.tok = self.b[1].token('/?results')

    def setting(self, value):
        tn.php_boot("phoromatic_server::init_settings_store(); phoromatic_server::save_setting('allow_test_profile_creation', %d);" % value)

    def content(self, path):
        return tn.srv_sh("cat %s 2>/dev/null" % path).strip()

    def exists(self, path):
        return tn.srv_sh("test -e %s && echo yes" % path).strip() == 'yes'

    def attacks(self, tok, headers=None, browser=None):
        """Everything a user could send: delete mine and the shared one by several spellings, overwrite both, write outside the store, create."""
        b = browser or self.b[1]
        h = headers
        p = {'token_submit': tok} if tok is not None else {'x': '1'}
        b.post('/?create_test/local/%s&delete' % self.mine, dict(p), headers=h)
        for spelling in ('pts/%s' % self.shared, 'local/..%%2fpts%%2f%s' % self.shared, 'local/%%2e%%2e%%2fpts%%2f%s' % self.shared, 'local/..%%252fpts%%252f%s' % self.shared):
            b.post('/?create_test/%s&delete' % spelling, dict(p), headers=h)
        for ident in ('local/%s' % self.mine, 'pts/%s' % self.shared, 'local/../pts/%s' % self.shared, 'local/../../tn-outside/x', '../../tn-outside/x', '../pts/%s' % self.shared):
            d = dict(p)
            d.update({'tp_update': ident, 'test-definition_xml': '<PhoronixTestSuite><TestProfile><Version>../../../tn-outside/v</Version></TestProfile></PhoronixTestSuite>', 'install_sh': 'HACKED'})
            b.post('/?create_test/%s' % ident, d, headers=h)

    def intact(self):
        return (self.content('%spts/%s/install.sh' % (TP, self.shared)) == 'SHARED' and self.exists('%slocal/%s/install.sh' % (TP, self.mine))
                and self.content('%slocal/%s/install.sh' % (TP, self.mine)) == 'MINE' and self.content(OUTSIDE + 'keep.txt') == 'KEEP'
                and tn.srv_sh("ls %s" % OUTSIDE).split() == ['keep.txt'])

    def creation_fields(self, base, version='1.0.0'):
        return {'token_submit': self.tok, 'test_profile_base': base,
                'PhoronixTestSuite/TestInformation/Title': 'tn', 'PhoronixTestSuite/TestInformation/Description': 'tn', 'PhoronixTestSuite/TestInformation/ResultScale': 'Seconds',
                'PhoronixTestSuite/TestInformation/Proportion': 'LIB', 'PhoronixTestSuite/TestProfile/Version': version, 'PhoronixTestSuite/TestProfile/SupportedPlatforms': 'Linux',
                'PhoronixTestSuite/TestProfile/SoftwareType': 'Benchmark', 'PhoronixTestSuite/TestProfile/TestType': 'System', 'PhoronixTestSuite/TestProfile/License': 'FREE',
                'PhoronixTestSuite/TestProfile/Status': 'Verified', 'PhoronixTestSuite/TestProfile/Maintainer': 'tn'}

    def test_with_the_setting_off_nothing_is_written_or_deleted_even_with_the_token(self):
        self.setting(0)
        self.attacks(self.tok)
        self.b[1].post('/?create_test/local/%s&delete' % self.mine, {'token_submit': self.tok})
        self.b[1].post('/?create_test', self.creation_fields('tnnew' + self.n))
        self.assertTrue(self.intact())
        self.assertFalse(self.exists('%slocal/tnnew%s-1.0.0' % (TP, self.n)))

    def test_with_the_setting_on_a_get_or_a_post_without_the_token_or_from_another_site_does_nothing(self):
        self.setting(1)
        self.b[1].get('/?create_test/local/%s&delete' % self.mine)
        self.attacks(None)
        self.attacks('c' * 40)
        self.attacks(self.tok, headers=EVIL)
        self.b[1].post('/?create_test', dict(self.creation_fields('tnnew' + self.n), token_submit=''))
        self.b[1].post('/?create_test', self.creation_fields('tnnew' + self.n), headers=EVIL)
        self.assertTrue(self.intact())
        self.assertFalse(self.exists('%slocal/tnnew%s-1.0.0' % (TP, self.n)))
        self.assertIn('Nothing was changed', self.b[1].get('/?create_test/local/%s&delete' % self.mine)[2])

    def test_with_the_setting_on_and_the_token_only_local_profiles_are_touched_and_nothing_leaves_the_store(self):
        self.setting(1)
        self.attacks(self.tok)
        # (the attacks include a valid delete of the account's own local profile, which is allowed: put it back and check the rest)
        self.assertFalse(self.exists('%slocal/%s' % (TP, self.mine)), 'a delete of a local profile with the token works')
        self.assertEqual(self.content('%spts/%s/install.sh' % (TP, self.shared)), 'SHARED')
        self.assertEqual(self.content('%spts/%s/test-definition.xml' % (TP, self.shared)), 'SHARED')
        self.assertEqual(tn.srv_sh("ls %s" % OUTSIDE).split(), ['keep.txt'])
        self.assertEqual(self.content(OUTSIDE + 'keep.txt'), 'KEEP')

    def test_a_local_profile_can_be_edited_with_the_token_and_not_a_shared_one(self):
        self.setting(1)
        d = {'token_submit': self.tok, 'tp_update': 'local/' + self.mine, 'test-definition_xml': '<x/>', 'install_sh': 'EDITED'}
        self.b[1].post('/?create_test/local/' + self.mine, d)
        self.assertEqual(self.content('%slocal/%s/install.sh' % (TP, self.mine)), 'EDITED')
        d['tp_update'] = 'pts/' + self.shared
        self.b[1].post('/?create_test/pts/' + self.shared, d)
        self.assertEqual(self.content('%spts/%s/install.sh' % (TP, self.shared)), 'SHARED')

    def test_the_editor_shows_only_local_profiles_and_its_forms_carry_the_token(self):
        self.setting(1)
        page = self.b[1].get('/?create_test/local/' + self.mine)[2]
        self.assertIn('Test Profile Editor', page)
        self.assertIn('value="%s"' % self.tok, page)
        self.assertNotIn('Test Profile Editor', self.b[1].get('/?create_test/pts/' + self.shared)[2])
        creator = self.b[1].get('/?create_test')[2]
        self.assertIn('name="token_submit" value="%s"' % self.tok, creator)
        self.assertIn('<form action="?create_test"', creator)

    def test_creating_a_profile_with_the_token_works_and_a_version_cannot_leave_the_store(self):
        self.setting(1)
        self.b[1].post('/?create_test', self.creation_fields('tnnew' + self.n))
        self.assertTrue(self.exists('%slocal/tnnew%s-1.0.0/test-definition.xml' % (TP, self.n)), 'creation with the token works')
        for version in ('../../../tn-outside/v', '1.0.0/../../x', '1.0.0/../../../../tn-outside'):
            self.b[1].post('/?create_test', self.creation_fields('tnnew' + self.n + 'v', version))
        self.assertEqual(tn.srv_sh("ls %s" % OUTSIDE).split(), ['keep.txt'])
        self.assertEqual(tn.srv_sh("ls %slocal/ | grep -c 'tnnew%sv'" % (TP, self.n)).strip(), '0')

    def test_a_viewer_cannot_change_anything_even_with_the_token(self):
        self.setting(1)
        self.attacks(self.b[10].token('/?results'), browser=self.b[10])
        self.assertTrue(self.intact())

    def test_the_delete_test_button_is_a_post_form_with_the_token(self):
        # the list is drawn by the page's own function, for a viewer who may edit and a session that has a token
        code = ("define('PHOROMATIC_USER_IS_VIEWER', false); $_SESSION = array('Token' => 'TOK999'); "
                "echo 'HTML=', pts_web_embed::tests_list(array(new pts_test_profile('local/%s')));" % self.mine)
        html = tn.php_boot(code).split('HTML=', 1)[-1]
        self.assertIn('Delete Test', html)
        forms = list(tn.re.finditer(r'<form action="/\?create_test/local/%s&delete" method="post"[^>]*>(.*?)</form>' % tn.re.escape(self.mine), html))
        self.assertEqual(len(forms), 1, html[-600:])
        self.assertIn('name="token_submit" value="TOK999"', forms[0].group(1))
        self.assertIn('value="Delete Test"', forms[0].group(1))
        self.assertNotIn('&delete" onclick', html)


if __name__ == '__main__':
    unittest.main()
