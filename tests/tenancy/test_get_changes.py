"""Changes need a POST with the CSRF token: deleting results, removing a test from a schedule, removing a schedule trigger.
A GET (a link, an image tag) changes nothing, a POST without the token or from another site is refused, a POST with the token works for the
account's own data, and the other account's rows and files stay byte-identical whatever is sent."""
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
            self.b[lvl].get(self.test_path)
            self.b[lvl].get(self.trigger_path)
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
        t2_test = tn.sql("SELECT TestProfile, TestArguments FROM phoromatic_schedules_tests WHERE AccountID = '%s' LIMIT 1" % self.t2['aid'])
        paths = ['/?schedules/%d/delete-trigger/%s' % (t2s, self.t2['trigger_b64']), '/?schedules/%d/delete-trigger/%s' % (self.sched, self.t2['trigger_b64'])]
        for r in t2_test:
            enc = tn.b64(r['TestProfile'] + '\n' + r['TestArguments'])
            paths += ['/?schedules/%d/remove/%s' % (t2s, enc), '/?schedules/%d/remove/%s' % (self.sched, enc)]
        for p in paths:
            self.b[1].get(p)
            self.b[1].post(p, {'token_submit': tok})
        # (assert_t2_untouched, run after the test, checks every row and file of the other account)


class TestProfileDelete(Fixture):
    def test_the_delete_in_create_test_is_guarded_by_post_and_token(self):
        # a source check only: the page is off by default (allow_test_profile_creation) and has no delete link, so there is no page to drive here
        src = tn.srv_sh("grep -c \"strpos(\\$PATH\\[1\\], '&delete') !== false && \\$_SERVER\\['REQUEST_METHOD'\\] == 'POST' && verify_submission_token()\" /bench/pts-core/phoromatic/pages/phoromatic_create_test.php").strip()
        self.assertEqual(src, '1')


if __name__ == '__main__':
    unittest.main()
