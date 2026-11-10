"""T1: the changes put off from 0.9.10. Saving a schedule (it stores uploaded scripts) and a suite takes the session's token; the changes that were links with
the token in the address are POST forms; logging out is a POST; viewers cannot do what the buttons do; the OpenBenchmarking mirror does not zip a working copy
of an official profile."""
import hashlib
import re
import unittest
import urllib.request

import tn


def aid():
    return tn.state()['T1']['aid']


class ScheduleCreate(unittest.TestCase):
    def post(self, b, token, title):
        fields = {'schedule_title': title, 'schedule_description': 'd', 'schedule_hour': '01', 'schedule_minute': '00', 'days_active': '1', 'run_priority': '100'}
        if token:
            fields['token_submit'] = token
        script = b'#!/bin/sh\necho %s\n' % title.encode()
        files = {'pre_install_set_context': ('pre.sh', script), 'post_install_set_context': ('', b''), 'pre_run_set_context': ('', b''), 'post_run_set_context': ('', b'')}
        return script, b.multipart('/?sched', fields, files)[2]

    def setUp(self):
        self.title = 'tn schedule %s' % tn.uuid.uuid4().hex[:6]
        self.addCleanup(tn.sql_exec, "DELETE FROM phoromatic_schedules WHERE Title='%s'" % self.title)

    def test_without_the_token_nothing_is_saved_and_no_script_is_stored(self):
        b = tn.Browser().login(tn.state()['users']['T1']['1'])
        script, body = self.post(b, None, self.title)
        self.assertIn('Nothing Was Saved', body)
        self.assertEqual(tn.sql("SELECT COUNT(*) AS n FROM phoromatic_schedules WHERE Title='%s'" % self.title)[0]['n'], 0)
        self.assertEqual(tn.srv_sh("ls %s/accounts/%s/context_%s 2>/dev/null" % (tn.STORAGE, aid(), tn.sha1(script))).strip(), '')

    def test_with_a_wrong_token_or_a_foreign_origin_nothing_is_saved(self):
        b = tn.Browser().login(tn.state()['users']['T1']['1'])
        script, body = self.post(b, 'f' * 40, self.title)
        self.assertIn('Nothing Was Saved', body)
        fields = {'schedule_title': self.title, 'token_submit': b.token(), 'schedule_hour': '01', 'schedule_minute': '00', 'days_active': '1'}
        body = b.multipart('/?sched', fields, {'pre_install_set_context': ('', b'')}, headers={'Origin': 'http://evil.example'})[2]
        self.assertIn('Nothing Was Saved', body)
        self.assertEqual(tn.sql("SELECT COUNT(*) AS n FROM phoromatic_schedules WHERE Title='%s'" % self.title)[0]['n'], 0)

    def test_with_the_token_the_schedule_and_its_script_are_saved(self):
        b = tn.Browser().login(tn.state()['users']['T1']['1'])
        page = b.get('/?sched')[2]
        self.assertIn('name="token_submit"', page, 'the form carries the token')
        script, body = self.post(b, b.token('/?sched'), self.title)
        self.assertNotIn('Nothing Was Saved', body)
        self.assertEqual(tn.sql("SELECT COUNT(*) AS n FROM phoromatic_schedules WHERE Title='%s'" % self.title)[0]['n'], 1)
        self.assertEqual(tn.srv_sh("cat %s/accounts/%s/context_%s" % (tn.STORAGE, aid(), tn.sha1(script))), script.decode())
        tn.srv_sh("rm -f %s/accounts/%s/context_%s" % (tn.STORAGE, aid(), tn.sha1(script)))

    def test_a_viewer_cannot_save_a_schedule_even_with_a_token(self):
        b = tn.Browser().login(tn.state()['users']['T1']['10'])
        script, body = self.post(b, b.token(), self.title)
        self.assertEqual(tn.sql("SELECT COUNT(*) AS n FROM phoromatic_schedules WHERE Title='%s'" % self.title)[0]['n'], 0)
        self.assertEqual(tn.srv_sh("ls %s/accounts/%s/context_%s 2>/dev/null" % (tn.STORAGE, aid(), tn.sha1(script))).strip(), '')


class SuiteSave(unittest.TestCase):
    def fields(self, name):
        return {'suite_title': name, 'suite_version': '1.0.0', 'suite_description': 'd', 'test_add[]': 'pts/sysbench-1.1.0', 'test_prefix[]': 'pts_sysbench_1_1_0'}

    def setUp(self):
        self.name = 'tnsuite%s' % tn.uuid.uuid4().hex[:5]
        self.path = '%s/accounts/%s/suites/%s-1.0.0' % (tn.STORAGE, aid(), self.name)
        self.addCleanup(tn.srv_sh, "rm -rf '%s'" % self.path)

    def exists(self):
        return tn.srv_sh("test -e '%s/suite-definition.xml' && echo yes" % self.path).strip() == 'yes'

    def test_without_the_token_no_suite_is_saved(self):
        b = tn.Browser().login(tn.state()['users']['T1']['1'])
        body = b.post('/?build_suite', self.fields(self.name))[2]
        self.assertIn('Nothing was saved', body)
        self.assertFalse(self.exists())

    def test_with_the_token_the_suite_is_saved_and_the_form_carries_it(self):
        b = tn.Browser().login(tn.state()['users']['T1']['1'])
        self.assertIn('name="token_submit"', b.get('/?build_suite')[2])
        body = b.post('/?build_suite', dict(self.fields(self.name), token_submit=b.token('/?build_suite')))[2]
        self.assertIn('Saved As', body)
        self.assertTrue(self.exists())

    def test_a_viewer_cannot_save_a_suite_even_with_a_token(self):
        b = tn.Browser().login(tn.state()['users']['T1']['10'])
        b.post('/?build_suite', dict(self.fields(self.name), token_submit=b.token()))
        self.assertFalse(self.exists())

    def test_a_foreign_origin_is_refused(self):
        b = tn.Browser().login(tn.state()['users']['T1']['1'])
        b.request('/?build_suite', data=dict(self.fields(self.name), token_submit=b.token()), headers={'Origin': 'http://evil.example'})
        self.assertFalse(self.exists())


class TokenIsNotInAnAddress(unittest.TestCase):
    PAGES = ('/?main', '/?schedules', '/?schedules/%d' % tn.SHARED_SCHEDULE, '/?local_suites', '/?benchmark', '/?results', '/?systems', '/?settings', '/?users', '/?sched', '/?build_suite')

    def test_no_page_puts_the_token_in_a_link(self):
        b = tn.Browser().login(tn.state()['users']['T1']['1'])
        st = tn.state()
        for page in self.PAGES + ('/?benchmark/%d' % st['T1']['ticket'],):
            body = b.get(page)[2]
            self.assertIsNone(re.search(r'(?:href|src|action)="[^"]*token_submit=', body), page)

    def test_a_get_with_the_token_in_the_address_changes_nothing(self):
        # local suite delete
        b = tn.Browser().login(tn.state()['users']['T1']['1'])
        t1 = aid()
        path = '%s/accounts/%s/suites/tngetdel-1.0.0' % (tn.STORAGE, t1)
        tn.srv_sh("mkdir -p '%s' && cp '%s/accounts/%s/suites/%s/suite-definition.xml' '%s/'" % (path, tn.STORAGE, t1, tn.state()['T1']['suite'], path))
        self.addCleanup(tn.srv_sh, "rm -rf '%s'" % path)
        tok = b.token()
        b.get('/?local_suites/delete/tngetdel-1.0.0&token_submit=%s' % tok)
        b.get('/?local_suites/delete/tngetdel-1.0.0/&token_submit=%s' % tok)
        self.assertEqual(tn.srv_sh("test -e '%s/suite-definition.xml' && echo yes" % path).strip(), 'yes')
        # schedule activate / deactivate
        sched = tn.SHARED_SCHEDULE
        state = lambda: tn.sql("SELECT State FROM phoromatic_schedules WHERE AccountID='%s' AND ScheduleID=%d" % (t1, sched))[0]['State']
        self.addCleanup(tn.sql_exec, "UPDATE phoromatic_schedules SET State=1 WHERE AccountID='%s' AND ScheduleID=%d" % (t1, sched))
        b.get('/?schedules/%d/deactivate&token_submit=%s' % (sched, tok))
        b.get('/?schedules/%d/deactivate/&token_submit=%s' % (sched, tok))
        self.assertEqual(state(), 1)
        # benchmark ticket remove / repeat / disable
        ticket = tn.state()['T1']['ticket']
        before = tn.sql("SELECT State, TicketIssueTime FROM phoromatic_benchmark_tickets WHERE AccountID='%s' AND TicketID=%d" % (t1, ticket))
        for action in ('remove', 'repeat', 'disable'):
            b.get('/?benchmark/%d/&%s&token_submit=%s' % (ticket, action, tok))
        self.assertEqual(tn.sql("SELECT State, TicketIssueTime FROM phoromatic_benchmark_tickets WHERE AccountID='%s' AND TicketID=%d" % (t1, ticket)), before)

    def test_the_post_forms_work_and_need_the_token_and_a_non_viewer(self):
        t1 = aid()
        sched = tn.SHARED_SCHEDULE
        state = lambda: tn.sql("SELECT State FROM phoromatic_schedules WHERE AccountID='%s' AND ScheduleID=%d" % (t1, sched))[0]['State']
        self.addCleanup(tn.sql_exec, "UPDATE phoromatic_schedules SET State=1 WHERE AccountID='%s' AND ScheduleID=%d" % (t1, sched))
        b = tn.Browser().login(tn.state()['users']['T1']['1'])
        v = tn.Browser().login(tn.state()['users']['T1']['10'])
        self.assertIn('/deactivate"', b.get('/?schedules/%d' % sched)[2])
        b.post('/?schedules/%d/deactivate' % sched, {})
        self.assertEqual(state(), 1, 'no token')
        v.post('/?schedules/%d/deactivate' % sched, {'token_submit': v.token()})
        self.assertEqual(state(), 1, 'a viewer')
        b.request('/?schedules/%d/deactivate' % sched, data={'token_submit': b.token()}, headers={'Origin': 'http://evil.example'})
        self.assertEqual(state(), 1, 'a foreign origin')
        b.post('/?schedules/%d/deactivate' % sched, {'token_submit': b.token()})
        self.assertEqual(state(), 0)
        self.assertIn('/activate"', b.get('/?schedules/%d' % sched)[2])
        b.post('/?schedules/%d/activate' % sched, {'token_submit': b.token()})
        self.assertEqual(state(), 1)
        # local suite delete
        path = '%s/accounts/%s/suites/tnpostdel-1.0.0' % (tn.STORAGE, t1)
        tn.srv_sh("mkdir -p '%s' && cp '%s/accounts/%s/suites/%s/suite-definition.xml' '%s/'" % (path, tn.STORAGE, t1, tn.state()['T1']['suite'], path))
        self.addCleanup(tn.srv_sh, "rm -rf '%s'" % path)
        v.post('/?local_suites/delete/tnpostdel-1.0.0', {'token_submit': v.token()})
        self.assertEqual(tn.srv_sh("test -e '%s/suite-definition.xml' && echo yes" % path).strip(), 'yes', 'a viewer')
        b.post('/?local_suites/delete/tnpostdel-1.0.0', {})
        self.assertEqual(tn.srv_sh("test -e '%s/suite-definition.xml' && echo yes" % path).strip(), 'yes', 'no token')
        b.post('/?local_suites/delete/tnpostdel-1.0.0', {'token_submit': b.token()})
        self.assertEqual(tn.srv_sh("test -e '%s/suite-definition.xml' && echo yes" % path).strip(), '')

    def test_ticket_actions_by_post(self):
        t1 = aid()
        ticket = tn.state()['T1']['ticket']
        row = lambda: tn.sql("SELECT State, TicketIssueTime FROM phoromatic_benchmark_tickets WHERE AccountID='%s' AND TicketID=%d" % (t1, ticket))
        saved = {k: int(v) for k, v in row()[0].items()}
        self.addCleanup(tn.sql_exec, "UPDATE phoromatic_benchmark_tickets SET State=%d, TicketIssueTime=%d WHERE AccountID='%s' AND TicketID=%d" % (saved['State'], saved['TicketIssueTime'], t1, ticket))
        b = tn.Browser().login(tn.state()['users']['T1']['1'])
        v = tn.Browser().login(tn.state()['users']['T1']['10'])
        page = b.get('/?benchmark/%d' % ticket)[2]
        for label in ('Repeat Ticket', 'Remove Ticket', 'End Ticket'):
            self.assertIn(label, page)
            self.assertNotIn(label, v.get('/?benchmark/%d' % ticket)[2], 'viewers are not offered it')
        for who, data in ((b, {'ticket_action': 'repeat'}), (v, {'ticket_action': 'repeat', 'token_submit': v.token()})):
            who.post('/?benchmark/%d' % ticket, data)
        self.assertEqual(int(row()[0]['TicketIssueTime']), saved['TicketIssueTime'], 'no token / viewer')
        b.post('/?benchmark/%d' % ticket, {'ticket_action': 'repeat', 'token_submit': b.token()})
        self.assertGreater(int(row()[0]['TicketIssueTime']), saved['TicketIssueTime'])
        b.post('/?benchmark/%d' % ticket, {'ticket_action': 'disable', 'token_submit': b.token()})
        self.assertEqual(int(row()[0]['State']), 0)
        v.post('/?benchmark/%d' % ticket, {'ticket_action': 'remove', 'token_submit': v.token()})
        self.assertEqual(len(row()), 1, 'a viewer cannot remove it')
        b.post('/?benchmark/%d' % ticket, {'ticket_action': 'remove', 'token_submit': b.token()})
        self.assertEqual(row(), [])


class TriggersAndViewers(unittest.TestCase):
    def setUp(self):
        self.t1 = aid()
        tn.sql_exec("INSERT INTO phoromatic_schedules_triggers (AccountID, ScheduleID, Trigger, TriggeredOn) VALUES ('%s', %d, 'tn1trig', '2026-11-01 10:00:00')" % (self.t1, tn.SHARED_SCHEDULE))
        self.addCleanup(tn.sql_exec, "DELETE FROM phoromatic_schedules_triggers WHERE AccountID='%s' AND Trigger='tn1trig'" % self.t1)

    def test_a_viewer_is_not_offered_remove_trigger_and_cannot_remove_one(self):
        page = '/?schedules/%d' % tn.SHARED_SCHEDULE
        b = tn.Browser().login(tn.state()['users']['T1']['1'])
        v = tn.Browser().login(tn.state()['users']['T1']['10'])
        self.assertIn('Remove Trigger', b.get(page)[2])
        self.assertIn('tn1trig', v.get(page)[2], 'the viewer still sees the trigger')
        self.assertNotIn('Remove Trigger', v.get(page)[2])
        v.post(page + '/delete-trigger/' + tn.b64('tn1trig'), {'token_submit': v.token()})
        self.assertEqual(tn.sql("SELECT COUNT(*) AS n FROM phoromatic_schedules_triggers WHERE Trigger='tn1trig'")[0]['n'], 1)
        b.post(page + '/delete-trigger/' + tn.b64('tn1trig'), {'token_submit': b.token()})
        self.assertEqual(tn.sql("SELECT COUNT(*) AS n FROM phoromatic_schedules_triggers WHERE Trigger='tn1trig'")[0]['n'], 0)

    def test_a_viewer_cannot_remove_a_test_from_a_schedule(self):
        v = tn.Browser().login(tn.state()['users']['T1']['10'])
        tn.sql_exec("INSERT INTO phoromatic_schedules_tests (AccountID, ScheduleID, TestProfile, TestArguments) VALUES ('%s', %d, 'pts/tnx-1.0.0', 'a')" % (self.t1, tn.SHARED_SCHEDULE))
        self.addCleanup(tn.sql_exec, "DELETE FROM phoromatic_schedules_tests WHERE AccountID='%s' AND TestProfile='pts/tnx-1.0.0'" % self.t1)
        v.post('/?schedules/%d/remove/%s' % (tn.SHARED_SCHEDULE, tn.b64('pts/tnx-1.0.0\na')), {'token_submit': v.token()})
        self.assertEqual(tn.sql("SELECT COUNT(*) AS n FROM phoromatic_schedules_tests WHERE TestProfile='pts/tnx-1.0.0'")[0]['n'], 1)

    def test_a_refused_results_delete_says_so_inside_the_page(self):
        b = tn.Browser().login(tn.state()['users']['T1']['1'])
        for body in (b.get('/?results/delete/TN1PPRA')[2], b.post('/?results/delete/TN1PPRA', {})[2]):
            self.assertEqual(body.count('Nothing was deleted'), 1)
            self.assertNotIn('Invalid Form Submission', body)
            self.assertIn('pts_phoromatic_top_header', body, 'the page header is there')
            self.assertTrue(body.rstrip().endswith('</html>'), 'the page ends properly')
        self.assertEqual(tn.sql("SELECT COUNT(*) AS n FROM phoromatic_results WHERE PPRID='TN1PPRA'")[0]['n'], 1)


class Logout(unittest.TestCase):
    def logged_in(self, b):
        return 'Logout' in b.get('/?main')[2]

    def test_a_get_does_not_log_out(self):
        b = tn.Browser().login(tn.state()['users']['T1']['1'])
        b.get('/?logout')
        b.get('/?logout&token_submit=%s' % b.token())
        self.assertTrue(self.logged_in(b))

    def test_a_post_without_the_token_or_from_another_site_does_not_log_out(self):
        b = tn.Browser().login(tn.state()['users']['T1']['1'])
        b.post('/?logout', {})
        self.assertTrue(self.logged_in(b))
        b.request('/?logout', data={'token_submit': b.token()}, headers={'Origin': 'http://evil.example'})
        self.assertTrue(self.logged_in(b))
        b.post('/?logout', {'token_submit': 'f' * 40})
        self.assertTrue(self.logged_in(b))

    def test_a_post_with_the_token_logs_out_and_the_menu_has_a_button_not_a_link(self):
        b = tn.Browser().login(tn.state()['users']['T1']['1'])
        page = b.get('/?main')[2]
        self.assertIn('<form action="/?logout" method="post"', page)
        self.assertNotIn('href="?logout"', page)
        b.post('/?logout', {'token_submit': b.token()})
        self.assertFalse(self.logged_in(b))
        self.assertIn('login_form', b.get('/?main')[2])

    def test_the_root_login_logs_out_by_post_too(self):
        root = tn.Browser().login_root()
        page = root.get('/?admin')[2]
        self.assertIn('<form action="/?logout" method="post"', page)
        root.get('/?logout')
        self.assertIn('Phoromatic Server Administration', root.get('/?admin')[2])
        root.post('/?logout', {'token_submit': root.token('/?admin')})
        self.assertNotIn('Phoromatic Server Administration', root.get('/?admin')[2])


class OpenBenchmarkingMirror(unittest.TestCase):
    PROFILE = ('<?xml version="1.0"?><PhoronixTestSuite><TestInformation><Title>tnfb</Title><Description>d</Description><ResultScale>x</ResultScale><Proportion>HIB</Proportion>'
               '<TimesToRun>1</TimesToRun></TestInformation><TestProfile><Version>1.0.0</Version><SupportedPlatforms>Linux</SupportedPlatforms><SoftwareType>Benchmark</SoftwareType>'
               '<TestType>System</TestType><License>Free</License><Status>Verified</Status><ExternalDependencies></ExternalDependencies><EnvironmentSize>1</EnvironmentSize>'
               '<ProjectURL>http://x</ProjectURL></TestProfile></PhoronixTestSuite>')

    def make(self, repo):
        d = '/var/lib/benchy/test-profiles/%s/tnfb-1.0.0' % repo
        tn.srv_sh("mkdir -p %s && cat > %s/test-definition.xml" % (d, d), stdin=self.PROFILE)
        self.addCleanup(tn.srv_sh, 'rm -rf %s' % d)

    def get(self, repo):
        return urllib.request.urlopen('%s/openbenchmarking-cache.php?test=tnfb-1.0.0&repo=%s' % (tn.BASE, repo), timeout=60).read().decode()

    def test_an_official_profile_that_exists_only_as_a_folder_is_not_zipped_and_served(self):
        for repo in ('pts', 'system', 'git', 'windows', 'PTS'):
            with self.subTest(repo=repo):
                self.make(repo.lower())
                self.assertEqual(self.get(repo).strip(), '')

    def test_a_local_profile_has_no_zip_anywhere_so_it_is_still_served_from_its_folder(self):
        self.make('local')
        body = self.get('local').strip()
        self.assertTrue(body.startswith('UEsD'), body[:40])   # base64 of a zip ("PK\x03\x04")

    def test_an_official_profile_with_a_cached_zip_is_still_served(self):
        tn.srv_sh('mkdir -p /var/cache/benchy/openbenchmarking.org/pts && cd /var/cache/benchy/openbenchmarking.org/pts && printf "PKtn" > tnzip-1.0.0.zip')
        self.addCleanup(tn.srv_sh, 'rm -f /var/cache/benchy/openbenchmarking.org/pts/tnzip-1.0.0.zip')
        body = urllib.request.urlopen('%s/openbenchmarking-cache.php?test=tnzip-1.0.0&repo=pts' % tn.BASE, timeout=60).read().decode()
        self.assertEqual(body.strip(), 'UEt0bg==')


if __name__ == '__main__':
    unittest.main()
