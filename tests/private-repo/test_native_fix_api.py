"""Native agents, the delta review of the fourth build round (Benchy 0.9.4), API side: a crash before a result or an end has been checked is
a 500 the agent retries and never a refusal it gives up on, a busy database is a 503, an upload too big for the server's memory is a refused
result, a choice with no choices stays out of the catalogue, and the poll reads the agent's hello marker as JSON.

The tests that crash the v2 code on purpose run against a second server (v2_helpers.Sidecar): the shared one prints what it logs to the
container log, which every other module treats as a failure.

Run through tests/private-repo/run.sh (part of http/all).
"""
import json
import subprocess
import time
import unittest

from test_native_jobs import Agent
from test_private_repo_http import AID, SRV, sql, srv_sh
from test_private_repo_tokens import sql_exec
from v2_helpers import Sidecar, allow_http, call, check, enrol, get_job, hello_body, multipart, poll_body, server_log_problems


def setUpModule():
    allow_http(True)


def tearDownModule():
    allow_http(False)
    problems = server_log_problems()
    assert not problems, 'PHP complained while serving the v2 API: %s' % problems


class TestCrashBeforeTheChecks(Agent):
    def test_a_crash_before_the_end_or_the_result_is_checked_is_a_500_the_agent_retries(self):
        job, _ = get_job(self.token)
        self.hello()
        body, ctype = multipart([('result', 'result.json', 'application/json', b'{}')])
        with Sidecar() as side:
            # the jobs table is out of reach for a moment: finding the job fails the way a database that will not open would
            self.assertEqual(sql_exec('ALTER TABLE phoromatic_v2_jobs RENAME TO qc_jobs_away'), 'ok')
            try:
                status, reply = side.call('/api/v2/jobs/%d/end' % job['id'], {'status': 'failed', 'reason': None, 'run': None}, token=self.token)
                self.assertEqual((status, reply['error']['code']), (500, 'server_error'), 'not the 409 that tells the agent to give up')
                status, reply = side.call('/api/v2/jobs/%d/result' % job['id'], raw=body, token=self.token, content_type=ctype)
                self.assertEqual((status, reply['error']['code']), (500, 'server_error'), 'not the 422 that sends the result to rejected')
            finally:
                self.assertEqual(sql_exec('ALTER TABLE qc_jobs_away RENAME TO phoromatic_v2_jobs'), 'ok')
            self.assertIn('uncaught', side.logged(), 'the crash was logged')
            # and once the table is back the same calls are served
            self.assertEqual(side.call('/api/v2/jobs/%d/end' % job['id'], {'status': 'failed', 'reason': None, 'run': None}, token=self.token)[0], 200)


class TestBusyDatabase(Agent):
    def test_an_end_that_waits_out_the_busy_timeout_is_a_503_and_works_once_the_lock_is_gone(self):
        job, _ = get_job(self.token)
        # the token was just used, so the request has no write to wait for before the end itself
        locker = "$d = new SQLite3('/var/lib/benchy/phoromatic/phoromatic.db'); $d->exec('BEGIN IMMEDIATE'); sleep(13); $d->exec('ROLLBACK');"
        proc = subprocess.Popen(['docker', 'exec', SRV, 'php', '-r', locker])
        try:
            time.sleep(1.5)
            started = time.time()
            status, reply, _ = self.end(job['id'], 'failed', 'x')
            self.assertEqual((status, reply['error']['code']), (503, 'server_error'))
            self.assertGreaterEqual(time.time() - started, 8, 'it did wait for the lock first')
            self.assertEqual(check('error_reply', reply), [])
        finally:
            proc.wait(timeout=30)
        self.assertEqual(self.end(job['id'], 'failed', 'x')[0], 200, 'nothing was recorded by the busy attempt, and the retry works')


class TestResultTooBigForMemory(Agent):
    def test_an_upload_the_server_runs_out_of_memory_on_is_a_refused_result(self):
        job, _ = get_job(self.token)
        # about 14 MB of tiny arrays: inside the size limit, far beyond the sidecar's 64 MB once decoded
        big = b'[' + b','.join([b'[1]'] * 3500000) + b']'
        body, ctype = multipart([('result', 'result.json', 'application/json', big)])
        with Sidecar() as side:
            status, reply = side.call('/api/v2/jobs/%d/result' % job['id'], raw=body, token=self.token, content_type=ctype)
            self.assertIn('Allowed memory size', side.logged(), 'it really ran out of memory')
        self.assertEqual(status, 422, reply)
        self.assertEqual(reply['error']['code'], 'result_invalid')
        self.assertEqual(check('error_reply', reply), [])


class TestCatalogueChoices(Agent):
    def test_a_choice_with_no_choices_is_left_out_and_the_system_says_so(self):
        good = {'test': {'ref': 'benchy/stream', 'version': '1.0.0'}, 'title': 'STREAM', 'description': None, 'category': None,
                'options': [{'key': 'threads', 'label': 'Threads', 'kind': 'choice', 'choices': [{'key': '4', 'label': 'four'}], 'default': '4'}]}
        bad = json.loads(json.dumps(good))
        bad['test']['ref'] = 'benchy/c-ray'
        bad['options'][0]['choices'] = []
        self.hello(catalogue=[good, bad])
        self.assertEqual([r['Ref'] for r in sql("SELECT Ref FROM phoromatic_system_catalogue WHERE SystemID='%s'" % self.sid)], ['benchy/stream'])
        warnings = sql("SELECT Warning FROM phoromatic_system_warnings WHERE SystemID='%s'" % self.sid)
        self.assertTrue(any('benchy/c-ray' in w['Warning'] for w in warnings), warnings)


class TestRehello(Agent):
    def test_the_poll_asks_for_a_hello_until_the_agent_marker_is_there_and_text_that_looks_like_it_does_not_count(self):
        def rehello():
            status, reply, _ = call('/api/v2/agent/poll', poll_body(), token=self.token)
            self.assertEqual(status, 200, reply)
            return reply['rehello']
        self.assertTrue(rehello(), 'enrolled, no hello yet')
        props = json.dumps({'benchy_system_info': {'cpu': {'hello_on': 'a key of that name, but not the agent marker'}}}).replace("'", "''")
        self.assertEqual(sql_exec("UPDATE phoromatic_systems SET SystemProperties='%s' WHERE SystemID='%s'" % (props, self.sid)), 'ok')
        self.assertTrue(rehello(), 'a key of that name elsewhere is not a hello')
        self.hello()
        self.assertFalse(rehello())


if __name__ == '__main__':
    unittest.main()
