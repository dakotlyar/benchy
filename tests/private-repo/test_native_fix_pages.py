"""Native agents, the delta review of the fourth build round (Benchy 0.9.4), pages: the settings form shows what was just saved and saves all
or nothing, enrolling an existing system lists what will not run natively, a number option can be left blank, a page that shows an
enrollment code is not cached, and the system page copes with deeply nested properties.

Run through tests/private-repo/run.sh (part of http/all).
"""
import json
import re
import unittest
import uuid

from bench_helpers import drop_schedule, new_system, schedule
from test_native_pages import Pages, post, tok
from test_private_repo_http import AID, sql
from test_private_repo_tokens import sql_exec
from v2_helpers import allow_http, call, enroll, hello_body, server_log_problems


def setUpModule():
    allow_http(True)


def tearDownModule():
    allow_http(False)
    problems = server_log_problems()
    assert not problems, 'PHP complained while serving the v2 API: %s' % problems


class TestSettingsForm(Pages):
    def tearDown(self):
        allow_http(True)
        post(self.admin, '/?settings', {'v2_settings': '1', 'v2_allow_http': '1', 'v2_mirrors': ''})

    def test_the_form_after_a_save_shows_what_was_saved(self):
        body = post(self.admin, '/?settings', {'v2_settings': '1', 'v2_allow_http': '1', 'v2_mirrors': 'https://m1.example/files'})
        self.assertIn('Saved.', body)
        self.assertIn('https://m1.example/files</textarea>', body, 'the list the post just saved, not the one before')
        self.assertIn('Plain HTTP is allowed', body)
        body = post(self.admin, '/?settings', {'v2_settings': '1', 'v2_mirrors': ''})
        self.assertNotIn('Plain HTTP is allowed', body, 'the switch just turned off is shown off, so the next save cannot turn it back on')
        self.assertNotIn('checked="checked"', re.search(r'<input type="checkbox" name="v2_allow_http"[^>]*>', body).group(0))
        self.assertNotIn('m1.example', body)

    def test_a_refused_mirror_list_changes_nothing_not_even_the_http_switch(self):
        before = len(sql("SELECT AuditID FROM phoromatic_admin_audit WHERE Action IN ('v2_allow_http_set', 'v2_mirrors_set')"))
        body = post(self.admin, '/?settings', {'v2_settings': '1', 'v2_mirrors': 'http://plain.example/x'})  # switch unticked = off, list refused
        self.assertIn('Not an https:// address', body)
        self.assertIn('Plain HTTP is allowed', self.admin.get('/?settings')[2], 'the switch was left on')
        self.assertEqual(len(sql("SELECT AuditID FROM phoromatic_admin_audit WHERE Action IN ('v2_allow_http_set', 'v2_mirrors_set')")), before)


class TestEnrollBoxListsWhatWillNotRun(Pages):
    def test_a_pts_system_shows_the_list_next_to_the_checkbox_and_a_system_with_nothing_to_lose_says_so(self):
        msi, sid = new_system()
        # (the account setting that asks for system logs is on by default and is itself on the list)
        old = sql("SELECT UploadSystemLogs FROM phoromatic_account_settings WHERE AccountID='%s'" % AID)[0]['UploadSystemLogs']
        self.addCleanup(sql_exec, "UPDATE phoromatic_account_settings SET UploadSystemLogs=%s WHERE AccountID='%s'" % (old if old is not None else 'NULL', AID))
        sql_exec("UPDATE phoromatic_account_settings SET UploadSystemLogs=0 WHERE AccountID='%s'" % AID)
        page = self.admin.get('/?systems/%s' % sid)[2]
        self.assertIn('switch it to the native engine', page)
        self.assertIn('Switching loses nothing', page)
        evil = 'qc <i>lost</i> %s' % uuid.uuid4().hex[:4]
        sched = schedule(sid, title=evil)
        self.addCleanup(drop_schedule, sched)
        sql_exec("UPDATE phoromatic_schedules SET EnvironmentVariables='FORCE_TIMES_TO_RUN=1' WHERE ScheduleID=%d" % sched)
        page = self.admin.get('/?systems/%s' % sid)[2]
        start = page.index('name="native_switch"')
        form = page[start:page.index('</form>', start)]
        self.assertIn('this will not run natively', form)
        self.assertIn('qc &lt;i&gt;lost&lt;/i&gt;', form)
        self.assertNotIn('<i>lost</i>', form)
        self.assertIn('environment variables', form)
        # it is the Switch page's own list
        confirm = self.admin.get('/?systems/%s/engine' % sid)[2]
        self.assertIn('qc &lt;i&gt;lost&lt;/i&gt;', confirm)

    def test_a_native_system_and_the_new_system_form_have_no_list(self):
        token, reply = enroll()
        page = self.admin.get('/?systems/%s' % reply['system']['id'])[2]
        self.assertNotIn('this will not run natively', page)
        self.assertNotIn('Switching loses nothing', page)
        self.assertNotIn('this will not run natively', self.admin.get('/?systems')[2])


class TestNumberOption(Pages):
    def setUp(self):
        self.msi, self.sid = new_system()
        self.sched = schedule(self.sid)
        self.addCleanup(drop_schedule, self.sched)
        self.addCleanup(sql_exec, "DELETE FROM phoromatic_schedules_native_tests WHERE ScheduleID=%d" % self.sched)
        self.addCleanup(sql_exec, "DELETE FROM phoromatic_system_catalogue WHERE AccountID='%s'" % AID)
        self.path = '/?schedules/%d' % self.sched

    def test_a_number_with_no_default_can_be_left_blank_and_a_word_is_still_refused(self):
        token, reply = enroll()
        cat = [{'test': {'ref': 'benchy/stream', 'version': '1.0.0'}, 'title': 'STREAM', 'description': None, 'category': None,
                'options': [{'key': 'size', 'label': 'Size', 'kind': 'number', 'choices': [], 'default': None}]}]
        self.assertEqual(call('/api/v2/agent/hello', hello_body(catalogue=cat), token=token)[0], 200)
        # the schedule offers what its own systems reported
        sql_exec("UPDATE phoromatic_schedules SET RunTargetSystems='%s' WHERE ScheduleID=%d" % (reply['system']['id'], self.sched))
        self.assertIn('must be a number', post(self.admin, self.path, {'native_test_add': 'benchy/stream', 'opt[size]': 'big'}))
        self.assertEqual(sql("SELECT COUNT(*) AS n FROM phoromatic_schedules_native_tests WHERE ScheduleID=%d" % self.sched)[0]['n'], 0)
        self.assertIn('Added', post(self.admin, self.path, {'native_test_add': 'benchy/stream', 'opt[size]': ''}))
        rows = sql("SELECT OptionsJSON FROM phoromatic_schedules_native_tests WHERE ScheduleID=%d" % self.sched)
        self.assertEqual([json.loads(r['OptionsJSON']) for r in rows], [{}], 'blank means the test\'s own default: nothing is stored')


class TestPageDetails(Pages):
    def test_the_page_that_shows_an_enrollment_code_is_not_cached(self):
        msi, sid = new_system()
        path = '/?systems/%s' % sid
        status, headers, body = self.admin.post(path, {'native_enroll': '1', 'native_label': 'qc nocache', 'token_submit': tok(self.admin, path)})
        self.assertIn('Enrollment code', body)
        self.assertIn('no-store', headers.get('Cache-Control', ''))

    def test_deeply_nested_system_properties_render_without_an_error(self):
        token, reply = enroll()
        sid = reply['system']['id']
        props = {'benchy_system_info': {'cpu': {'model': 'X <b>', 'cores': [{'id': 0, 'flags': ['a', 'b']}], 'cache': {'l1': {'size': 32}}}}}
        self.assertEqual(sql_exec("UPDATE phoromatic_systems SET SystemProperties='%s' WHERE SystemID='%s'" % (json.dumps(props), sid)), 'ok')
        status, _, page = self.admin.get('/?systems/%s' % sid)
        self.assertEqual(status, 200)
        self.assertNotRegex(page, r'Fatal error|TypeError|Warning:')
        self.assertIn('BENCHY_SYSTEM_INFO', page)
        self.assertIn('X &lt;b&gt;', page)
        self.assertNotIn('X <b>', page)
        self.assertIn('Native Engine', page, 'the page got to its end')


if __name__ == '__main__':
    unittest.main()
