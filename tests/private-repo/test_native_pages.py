"""Native agents, step S6 (Benchy 0.9.4): the pages. The engine switch with the list of what will not run natively, enrolment codes and the
pairing string, revoking a token, the machine-mismatch and kept-run-folder warnings, the Native tests box of a schedule, and the agent API
settings. Everything that changes something is a POST with the CSRF token and the same-origin check, for administrators only, audited; and
everything printed is escaped.

Run through tests/private-repo/run.sh (part of http/all).
"""
import json
import re
import unittest
import uuid

from bench_helpers import drop_schedule, new_system, schedule, system_row
from test_native_enrol import audit
from test_private_repo_http import AID, sql, srv_sh
from test_private_repo_tokens import sql_exec
from test_private_repo_web import WebBase
from v2_helpers import (allow_http, call, drop_native_schedule, enrol, enrol_body, get_job, hello_body, native_schedule, poll_body, server_log_problems)


def setUpModule():
    allow_http(True)


def tearDownModule():
    allow_http(False)
    problems = server_log_problems()
    assert not problems, 'PHP complained while serving the v2 API: %s' % problems


def tok(browser, path='/?systems'):
    m = re.search(r'name="token_submit" value="([0-9a-f]+)"', browser.get(path)[2])
    assert m, 'no CSRF token on %s' % path
    return m.group(1)


def post(browser, path, fields, token='auto', headers=None):
    data = dict(fields)
    if token == 'auto':
        token = tok(browser, path)
    if token:
        data['token_submit'] = token
    return browser.post(path, data, headers=headers)[2]


class Pages(WebBase):
    def new_native_candidate(self, title=None):
        msi, sid = new_system(title=title)
        return msi, sid


class TestEngineSwitch(Pages):
    def test_the_system_page_has_the_section_and_the_switch_page_lists_what_will_not_run(self):
        msi, sid = self.new_native_candidate()
        evil = 'qc <b>pts only</b> %s' % uuid.uuid4().hex[:4]
        sched = schedule(sid, title=evil)
        self.addCleanup(drop_schedule, sched)
        sql_exec("UPDATE phoromatic_schedules SET EnvironmentVariables='FORCE_TIMES_TO_RUN=1', SetContextPreRun='x' WHERE ScheduleID=%d" % sched)
        page = self.admin.get('/?systems/%s' % sid)[2]
        self.assertIn('Native Engine', page)
        self.assertIn('Switch to the native engine', page)
        self.assertIn('Enrol a native agent', page)
        confirm = self.admin.get('/?systems/%s/engine' % sid)[2]
        self.assertIn('What will not run natively', confirm)
        lines = re.findall(r'<li>(Schedule [^<]*(?:<[^l/][^<]*)*)</li>', confirm)
        self.assertIn('qc &lt;b&gt;pts only&lt;/b&gt;', confirm)
        self.assertTrue(all('<b>' not in l for l in lines), 'a schedule title is escaped in the list: %s' % lines)
        self.assertIn('has PTS tests but no native tests', confirm)
        self.assertIn('context scripts are not sent', confirm)
        self.assertIn('environment variables', confirm)
        self.assertIn('No native agent is enrolled', confirm)

    def test_switching_needs_the_confirmation_the_token_and_an_administrator_and_is_audited(self):
        msi, sid = self.new_native_candidate()
        path = '/?systems/%s' % sid
        t = tok(self.admin, path)
        post(self.admin, path, {'native_policy': 'native', 'native_confirm': '1'}, token=None)
        self.assertIsNone(system_row(sid)['EnginePolicy'], 'no token: nothing changes')
        post(self.admin, path, {'native_policy': 'native', 'native_confirm': '1'}, token='0' * 64)
        self.assertIsNone(system_row(sid)['EnginePolicy'], 'a wrong token')
        post(self.admin, path, {'native_policy': 'native', 'native_confirm': '1'}, headers={'Origin': 'http://evil.example'})
        self.assertIsNone(system_row(sid)['EnginePolicy'], 'another origin')
        body = post(self.admin, path, {'native_policy': 'native'}, token=t)
        self.assertIn('has to be confirmed', body)
        self.assertIsNone(system_row(sid)['EnginePolicy'])
        for level in (3, 10):
            other = self.as_user(level)
            body = post(other, path, {'native_policy': 'native', 'native_confirm': '1'})
            self.assertIsNone(system_row(sid)['EnginePolicy'], 'level %d may not switch' % level)
            self.assertNotIn('Switch to the native engine', other.get(path)[2])
        post(self.admin, path, {'native_policy': 'native', 'native_confirm': '1'}, token=t)
        self.assertEqual(system_row(sid)['EnginePolicy'], 'native')
        rows = audit('agent_policy_set', sid)
        self.assertEqual(json.loads(rows[-1]['Detail']), {'before': 'pts', 'after': 'native'})
        self.assertIn('Switch back to the PTS engine', self.admin.get(path)[2])
        post(self.admin, path, {'native_policy': 'pts'})
        self.assertIsNone(system_row(sid)['EnginePolicy'], 'back is one click, no confirmation')
        self.assertEqual(json.loads(audit('agent_policy_set', sid)[-1]['Detail']), {'before': 'native', 'after': 'pts'})

    def test_a_get_never_changes_anything(self):
        msi, sid = self.new_native_candidate()
        self.admin.get('/?systems/%s&native_policy=native&native_confirm=1&token_submit=%s' % (sid, tok(self.admin)))
        self.assertIsNone(system_row(sid)['EnginePolicy'])


class TestPagesRender(Pages):
    def test_the_pages_render_without_php_errors_for_a_pts_and_a_native_system(self):
        token, reply = enrol()
        call('/api/v2/agent/hello', hello_body(), token=token)
        msi, sid = new_system()
        sched = schedule(sid)
        self.addCleanup(drop_schedule, sched)
        for path in ('/?systems', '/?systems/%s' % reply['system']['id'], '/?systems/%s' % sid, '/?systems/%s/engine' % sid, '/?systems/%s/engine' % reply['system']['id'], '/?schedules/%d' % sched, '/?settings'):
            for browser in (self.admin, self.as_user(3), self.as_user(10)):
                body = browser.get(path)[2]
                for needle in ('Fatal error', 'Warning</b>', 'Notice</b>', 'Deprecated</b>', 'Uncaught', 'Array to string'):
                    self.assertNotIn(needle, body, '%s: %s' % (path, needle))


class TestEnrolPages(Pages):
    def code_from(self, page):
        m = re.search(r'<code>(ben_[A-Za-z0-9_-]{43})</code>', page)
        self.assertTrue(m, 'the code is on the page')
        return m.group(1)

    def test_enrolling_from_a_system_page_shows_the_code_once_and_the_code_enrols(self):
        msi, sid = self.new_native_candidate()
        path = '/?systems/%s' % sid
        body = post(self.admin, path, {'native_enrol': '1', 'native_label': 'qc rig label', 'native_switch': '1'})
        self.assertIn('Enrolment code', body)
        code = self.code_from(body)
        self.assertIn('benchy-enrol:', body)
        pairing = re.search(r'<textarea id="pairing_string"[^>]*>([^<]*)</textarea>', body).group(1)
        self.assertIn('?code=' + code, pairing)
        self.assertRegex(pairing, r'^benchy-enrol:https?://[^?]+\?code=ben_')
        self.assertIn('CA fingerprint', body)
        self.assertNotIn('ben_', self.admin.get(path)[2], 'the code is shown once')
        stored = sql("SELECT * FROM phoromatic_enrol_codes WHERE Label='qc rig label'")
        self.assertEqual([(r['SystemID'], r['SetPolicy']) for r in stored], [(sid, 'native')])
        # a label with markup characters is refused, nothing is stored
        self.assertIn('without markup', post(self.admin, path, {'native_enrol': '1', 'native_label': '<b>x</b>'}))
        self.assertEqual(len(sql("SELECT * FROM phoromatic_enrol_codes WHERE Label LIKE '%<b>%'")), 0)
        # the code works, for this system, and the switch checkbox flipped the policy
        status, reply, _ = call('/api/v2/enrol', enrol_body(code, 'f'))
        self.assertEqual((status, reply['system']['id']), (201, sid))
        self.assertEqual(system_row(sid)['EnginePolicy'], 'native')
        # and it was audited with the user, without the code
        row = [r for r in audit('agent_code_create') if r['SubjectLabel'] == 'qc rig label'][-1]
        self.assertEqual(row['ActorType'], 'user')
        self.assertNotIn(code, row['Detail'] or '')

    def test_enrolling_needs_the_token_and_an_administrator(self):
        msi, sid = self.new_native_candidate()
        path = '/?systems/%s' % sid
        before = len(sql('SELECT CodeID FROM phoromatic_enrol_codes'))
        self.assertNotIn('Enrolment code', post(self.admin, path, {'native_enrol': '1', 'native_label': 'x'}, token=None))
        self.assertNotIn('Enrolment code', post(self.admin, path, {'native_enrol': '1', 'native_label': 'x'}, headers={'Origin': 'http://evil.example'}))
        self.assertNotIn('Enrolment code', post(self.as_user(3), path, {'native_enrol': '1', 'native_label': 'x'}))
        self.assertEqual(len(sql('SELECT CodeID FROM phoromatic_enrol_codes')), before)
        self.assertNotIn('Enrol a native agent', self.as_user(3).get(path)[2])

    def test_a_new_native_system_is_enrolled_from_the_list_and_open_codes_can_be_revoked(self):
        body = post(self.admin, '/?systems', {'native_enrol': '1', 'native_label': 'qc brand new'})
        code = self.code_from(body)
        row = sql("SELECT * FROM phoromatic_enrol_codes WHERE Label='qc brand new' ORDER BY CodeID DESC LIMIT 1")[0]
        self.assertEqual((row['SystemID'], row['SetPolicy']), (None, 'native'))
        listing = self.admin.get('/?systems')[2]
        self.assertIn('Enrol A Native System', listing)
        self.assertIn('qc brand new', listing)
        post(self.admin, '/?systems', {'native_revoke_code': str(row['CodeID'])})
        self.assertIsNotNone(sql("SELECT RevokedOn FROM phoromatic_enrol_codes WHERE CodeID=%d" % row['CodeID'])[0]['RevokedOn'])
        self.assertEqual(call('/api/v2/enrol', enrol_body(code))[1]['error']['code'], 'code_invalid')
        self.assertNotIn('qc brand new', self.admin.get('/?systems')[2], 'a revoked code leaves the open list')

    def test_revoking_a_token_from_the_page(self):
        token, reply = enrol()
        sid = reply['system']['id']
        call('/api/v2/agent/hello', hello_body(), token=token)
        page = self.admin.get('/?systems/%s' % sid)[2]
        self.assertIn('Revoke agent token', page)
        self.assertIn('benchy-native', page)
        post(self.admin, '/?systems/%s' % sid, {'native_revoke_token': '1'}, token=None)
        self.assertEqual(call('/api/v2/agent/poll', poll_body(), token=token)[0], 200, 'no token, nothing happens')
        post(self.admin, '/?systems/%s' % sid, {'native_revoke_token': '1'})
        self.assertEqual(call('/api/v2/agent/poll', poll_body(), token=token)[1]['error']['code'], 'token_revoked')
        self.assertNotIn('Revoke agent token', self.admin.get('/?systems/%s' % sid)[2])

    def test_the_cloned_machine_warning_and_the_kept_run_folders_are_shown(self):
        token, reply = enrol()
        sid = reply['system']['id']
        h = hello_body()
        h['kept_run_dirs'] = 3
        call('/api/v2/agent/hello', h, token=token)
        call('/api/v2/agent/poll', poll_body('c'), token=token)
        page = self.admin.get('/?systems/%s' % sid)[2]
        self.assertIn('3 interrupted run folder', page)
        self.assertIn('cloned VM', page)


class TestNativeTestsBox(Pages):
    def setUp(self):
        self.msi, self.sid = new_system()
        self.sched = schedule(self.sid)
        self.addCleanup(drop_schedule, self.sched)
        self.addCleanup(sql_exec, "DELETE FROM phoromatic_schedules_native_tests WHERE ScheduleID=%d" % self.sched)
        self.path = '/?schedules/%d' % self.sched

    def catalogue(self, token, title='STREAM <script>alert(1)</script>'):
        h = hello_body(catalogue=[{'test': {'ref': 'benchy/stream', 'version': '1.0.0'}, 'title': title, 'description': None, 'category': None,
                                    'options': [{'key': 'threads', 'label': 'Threads <b>', 'kind': 'choice', 'choices': [{'key': '4', 'label': 'four'}, {'key': '8', 'label': 'eight'}], 'default': '8'},
                                                {'key': 'note', 'label': 'Note', 'kind': 'text', 'choices': [], 'default': None}]}])
        self.assertEqual(call('/api/v2/agent/hello', h, token=token)[0], 200)

    def test_without_a_reported_catalogue_the_box_says_to_enrol_an_agent(self):
        sql_exec("DELETE FROM phoromatic_system_catalogue WHERE AccountID='%s'" % AID)
        page = self.admin.get(self.path)[2]
        self.assertIn('Native Tests', page)
        self.assertIn('Enrol a native agent first; its tests appear here.', page)

    def test_add_with_options_list_remove_and_the_refusals(self):
        token, reply = enrol()
        self.catalogue(token)
        self.addCleanup(sql_exec, "DELETE FROM phoromatic_system_catalogue WHERE AccountID='%s'" % AID)
        page = self.admin.get(self.path)[2]
        self.assertIn('Add A Native Test', page)
        self.assertIn('STREAM &lt;script&gt;alert(1)&lt;/script&gt;', page)
        self.assertNotIn('<script>alert(1)</script>', page, 'catalogue text is escaped')
        self.assertIn('Threads &lt;b&gt;', page)
        self.assertIn('<option value="8" selected="selected">eight</option>', page, 'the default is preselected')
        body = post(self.admin, self.path, {'native_test_add': 'benchy/stream', 'opt[threads]': '4', 'opt[note]': 'hello'})
        self.assertIn('Added', body)
        rows = sql("SELECT * FROM phoromatic_schedules_native_tests WHERE ScheduleID=%d" % self.sched)
        self.assertEqual([(r['Ref'], json.loads(r['OptionsJSON'])) for r in rows], [('benchy/stream', {'note': 'hello', 'threads': '4'})])
        self.assertEqual(rows[0]['OptionsJSON'], '{"note":"hello","threads":"4"}', 'sorted keys')
        self.assertEqual(len(audit('native_test_add', str(self.sched))), 1)
        # the same test with the same options again is refused; other options are another row
        self.assertIn('Nothing was added', post(self.admin, self.path, {'native_test_add': 'benchy/stream', 'opt[threads]': '4', 'opt[note]': 'hello'}))
        post(self.admin, self.path, {'native_test_add': 'benchy/stream', 'opt[threads]': '8'})
        self.assertEqual(len(sql("SELECT * FROM phoromatic_schedules_native_tests WHERE ScheduleID=%d" % self.sched)), 2)
        # refusals: unknown test, a value that is not a choice, no token, a viewer-level user
        self.assertIn('not in the catalogue', post(self.admin, self.path, {'native_test_add': 'benchy/not-reported'}))
        self.assertIn('not one of the choices', post(self.admin, self.path, {'native_test_add': 'benchy/stream', 'opt[threads]': '3'}))
        post(self.admin, self.path, {'native_test_add': 'benchy/stream', 'opt[threads]': '4'}, token=None)
        viewer = self.as_user(10)
        post(viewer, self.path, {'native_test_add': 'benchy/stream', 'opt[threads]': '4'}, token=None)  # a viewer's pages carry no token
        self.assertEqual(len(sql("SELECT * FROM phoromatic_schedules_native_tests WHERE ScheduleID=%d" % self.sched)), 2)
        # the list shows them (escaped options), removal is a POST and audited
        listing = self.admin.get(self.path)[2]
        self.assertIn('threads = 4', listing)
        removed = rows[0]
        post(self.admin, self.path, {'native_test_ref': removed['Ref'], 'native_test_remove': removed['OptionsJSON']}, token=None)
        self.assertEqual(len(sql("SELECT * FROM phoromatic_schedules_native_tests WHERE ScheduleID=%d" % self.sched)), 2)
        post(self.admin, self.path, {'native_test_ref': removed['Ref'], 'native_test_remove': removed['OptionsJSON']})
        self.assertEqual(len(sql("SELECT * FROM phoromatic_schedules_native_tests WHERE ScheduleID=%d" % self.sched)), 1)
        self.assertEqual(len(audit('native_test_remove', str(self.sched))), 1)
        # and a native agent aimed at the schedule is handed exactly the remaining row
        sql_exec("UPDATE phoromatic_schedules SET RunTargetSystems='%s'" % reply['system']['id'] + " WHERE ScheduleID=%d" % self.sched)
        job, _ = get_job(token)
        self.assertEqual([t['options'] for t in job['tests']], [{'threads': '8'}])
        sql_exec("DELETE FROM phoromatic_v2_jobs WHERE SystemID='%s'" % reply['system']['id'])


class TestAgentApiSettings(Pages):
    def tearDown(self):
        allow_http(True)
        sql_exec("UPDATE phoromatic_systems SET State=State WHERE 1=0")

    def test_the_plain_http_setting_warns_is_a_post_and_is_audited_and_mirrors_are_validated(self):
        page = self.admin.get('/?settings')[2]
        self.assertIn('Native Agent API', page)
        self.assertIn('test rigs only', page)
        # (the harness has it on so the other tests can talk plain HTTP): turn it off through the page, on again through the page
        self.assertIn('Plain HTTP is allowed for the agent API', page)
        n = len(audit('v2_allow_http_set'))
        post(self.admin, '/?settings', {'v2_settings': '1', 'v2_mirrors': ''}, token=None)
        self.assertIn('Plain HTTP is allowed', self.admin.get('/?settings')[2], 'no token: unchanged')
        post(self.admin, '/?settings', {'v2_settings': '1', 'v2_mirrors': ''})
        self.assertNotIn('Plain HTTP is allowed', self.admin.get('/?settings')[2])
        self.assertEqual(call('/api/v2/agent/poll', {})[1]['error']['code'], 'https_required')
        post(self.admin, '/?settings', {'v2_settings': '1', 'v2_allow_http': '1', 'v2_mirrors': 'https://mirror.example/files\nhttps://other.example:8443/x'})
        self.assertIn('Plain HTTP is allowed', self.admin.get('/?settings')[2])
        self.assertEqual(len(audit('v2_allow_http_set')) - n, 2)
        mirrors = sql("SELECT Detail FROM phoromatic_admin_audit WHERE Action='v2_mirrors_set' ORDER BY AuditID DESC LIMIT 1")
        self.assertEqual(json.loads(mirrors[0]['Detail'])['after'], ['https://mirror.example/files', 'https://other.example:8443/x'])
        body = post(self.admin, '/?settings', {'v2_settings': '1', 'v2_allow_http': '1', 'v2_mirrors': 'http://plain.example/x'})
        self.assertIn('Not an https:// address', body)
        self.assertIn('https://mirror.example/files', self.admin.get('/?settings')[2], 'a refused list changes nothing')
        post(self.admin, '/?settings', {'v2_settings': '1', 'v2_allow_http': '1', 'v2_mirrors': ''})

    def test_only_administrators_see_and_change_it(self):
        self.assertNotIn('Native Agent API', self.as_user(3).get('/?settings')[2])
        post(self.as_user(3), '/?settings', {'v2_settings': '1'})
        self.assertIn('Plain HTTP is allowed', self.admin.get('/?settings')[2])


if __name__ == '__main__':
    unittest.main()
