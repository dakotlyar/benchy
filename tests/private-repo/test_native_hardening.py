"""Native agents, the fixes after the CP-S2 review (Benchy 0.9.4): the validator reads {} as an object and is strict, a crash is never a
reason for an agent to retry for ever, the failure counters, a late upload keeps its job's bench session, live progress, the trigger rules
(an agent stop never uses a trigger up), re-enrolment, and the environment cap.

Run through tests/private-repo/run.sh (part of http/all).
"""
import gzip
import json
import os
import unittest
import uuid

from bench_helpers import make_bench, swap, system_row
from test_native_enrol import audit
from test_native_jobs import Agent
from test_private_repo_http import AID, sql, srv_sh
from test_private_repo_tokens import sql_exec
from test_private_repo_web import WebBase
from v2_helpers import (admin, allow_http, call, call_local, check, check_inline, drop_native_schedule, enrol, enrol_body, event, fixture_result, get_job, hello_body, hello_event,
                        job_row, native_schedule, new_code, new_run_id, poll_body, server_log_problems, upload_result)


def setUpModule():
    allow_http(True)


def tearDownModule():
    allow_http(False)
    problems = server_log_problems()
    assert not problems, 'PHP complained while serving the v2 API: %s' % problems


class TestValidator(unittest.TestCase):
    def test_an_empty_object_is_an_object_and_has_to_have_what_is_required(self):
        for def_name in ('end_request', 'poll_request', 'hello_request', 'events_request', 'enrol_request'):
            self.assertTrue(check(def_name, {}), def_name)
        self.assertTrue(check('events_request', {'events': [{}]}))
        b = enrol_body(new_code()['code'])
        b['machine'] = {}
        self.assertTrue(check('enrol_request', b))
        # an empty list is not an object, and an empty object is not a list
        self.assertTrue(check_inline({'type': 'object'}, []))
        self.assertTrue(check_inline({'type': 'array'}, {}))
        self.assertEqual(check_inline({'type': 'object', 'required': []}, {}), [])
        self.assertEqual(check_inline({'type': 'array', 'minItems': 0}, []), [])
        self.assertTrue(check_inline({'type': 'array', 'minItems': 1}, []))

    def test_an_empty_body_and_a_list_body_are_bad_requests_on_every_endpoint(self):
        token, reply = enrol()
        sid = reply['system']['id']
        sched = native_schedule(sid)
        self.addCleanup(drop_native_schedule, sched)
        self.addCleanup(sql_exec, "DELETE FROM phoromatic_v2_jobs WHERE SystemID='%s'" % sid)
        job, _ = get_job(token)
        for path in ('/api/v2/enrol', '/api/v2/agent/hello', '/api/v2/agent/poll', '/api/v2/jobs/%d/events' % job['id'], '/api/v2/jobs/%d/end' % job['id']):
            for raw in (b'{}', b'[]'):
                status, body, _ = call(path, raw=raw, token=token)
                self.assertEqual((status, body['error']['code']), (400, 'bad_request'), (path, raw))

    def test_an_empty_result_is_refused(self):
        token, reply = enrol()
        sid = reply['system']['id']
        sched = native_schedule(sid)
        self.addCleanup(drop_native_schedule, sched)
        self.addCleanup(sql_exec, "DELETE FROM phoromatic_v2_jobs WHERE SystemID='%s'" % sid)
        job, _ = get_job(token)
        status, body, _ = upload_result(token, job['id'], b'{}')
        self.assertEqual((status, body['error']['code']), (422, 'result_invalid'))
        status, body, _ = upload_result(token, job['id'], b'[]')
        self.assertEqual((status, body['error']['code']), (422, 'result_invalid'))

    def test_patterns_have_no_trailing_newline_loophole(self):
        self.assertTrue(check('poll_request', dict(poll_body(), machine_id='a' * 64 + '\n')))
        b = enrol_body('ben_' + 'A' * 43 + '\n')
        self.assertTrue(check('enrol_request', b))
        self.assertEqual(call('/api/v2/agent/poll%0A', {})[0], 404)

    def test_const_and_integer_are_strict_and_additional_properties_false_is_honoured(self):
        self.assertTrue(check_inline({'const': 1}, 1.5))
        self.assertEqual(check_inline({'const': 1}, 1), [])
        self.assertTrue(check_inline({'type': 'integer'}, 1.0), '1.0 is not an integer here')
        self.assertTrue(check('events_request', {'events': [{'v': 1, 'type': 'log', 'seq': 1.0, 'ts': 'x'}]}))
        self.assertTrue(check_inline({'type': 'object', 'properties': {'a': {}}, 'additionalProperties': False}, {'a': 1, 'b': 2}))
        self.assertEqual(check_inline({'type': 'object', 'properties': {'a': {}}, 'additionalProperties': False}, {'a': 1}), [])


class TestCrashesAreNotRetriedForEver(Agent):
    def test_an_end_the_database_refuses_is_a_409_not_a_500_and_works_once_it_can(self):
        job, _ = get_job(self.token)
        self.assertEqual(sql_exec("CREATE TRIGGER qc_no_end BEFORE UPDATE ON phoromatic_v2_jobs WHEN NEW.EndStatus = 'failed' BEGIN SELECT RAISE(ABORT, 'qc'); END"), 'ok')
        try:
            status, body, _ = self.end(job['id'], 'failed', 'x')
            self.assertEqual((status, body['error']['code']), (409, 'job_ended'))
            self.assertEqual(check('error_reply', body), [])
        finally:
            self.assertEqual(sql_exec('DROP TRIGGER qc_no_end'), 'ok')
        self.assertEqual(self.end(job['id'], 'failed', 'x')[0], 200)

    def test_a_run_id_that_is_another_jobs_does_not_stop_a_job_ending_or_uploading(self):
        first, _ = get_job(self.token)
        run = new_run_id()
        self.run_job(first, run)
        self.assertEqual(self.end(first['id'], 'failed', 'x', run)[0], 200)
        other = native_schedule(self.sid, title='qc second %s' % uuid.uuid4().hex[:4])
        self.addCleanup(drop_native_schedule, other)
        second, _ = get_job(self.token)
        self.assertNotEqual(second['id'], first['id'])
        status, body, _ = upload_result(self.token, second['id'], fixture_result('quick', run))
        self.assertEqual((status, body['error']['code']), (422, 'result_invalid'), 'the run is the first job\'s')
        self.assertIn('another job', body['error']['message'])
        status, body, _ = self.end(second['id'], 'failed', 'y', run)
        self.assertEqual(status, 200, 'the end is recorded without the run id rather than refused')
        row = job_row(second['id'])
        self.assertEqual((row['State'], row['EndStatus'], row['RunID']), ('ended', 'failed', None))
        # events: the same clash is tolerated, as before
        third, _ = get_job(self.token)
        if third:
            self.events(third['id'], [hello_event(run)])
            self.assertEqual(job_row(third['id'])['State'], 'running')


class TestFailureCounters(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        allow_http(True)

    def test_indexes_exist_and_the_counter_is_an_index_lookup(self):
        names = {r['name'] for r in sql('SELECT name FROM sqlite_master')}
        self.assertTrue({'phoromatic_admin_audit_action_ip', 'phoromatic_v2_failures_ip', 'phoromatic_v2_failures_at'} <= names)
        plan = ' '.join(r['detail'] for r in sql("EXPLAIN QUERY PLAN SELECT At FROM phoromatic_v2_failures WHERE Kind='enrol' AND SourceIP='1.2.3.4' AND At >= 1 ORDER BY At DESC LIMIT 10"))
        self.assertIn('phoromatic_v2_failures_ip', plan)
        self.assertNotIn('SCAN', plan.replace('SCAN phoromatic_v2_failures USING', ''), plan)

    def test_a_flood_from_one_address_writes_two_audit_rows_not_hundreds(self):
        before = len(sql("SELECT AuditID FROM phoromatic_admin_audit WHERE Action='agent_enrol_failed' AND SourceIP='127.0.0.1'"))
        for i in range(30):
            call_local('/api/v2/enrol', enrol_body('ben_' + ('%043d' % (i + 500))))
        rows = sql("SELECT Detail FROM phoromatic_admin_audit WHERE Action='agent_enrol_failed' AND SourceIP='127.0.0.1' ORDER BY AuditID")
        details = [json.loads(r['Detail']) for r in rows[before:]]
        self.assertTrue(len(details) <= 2, details)
        self.assertTrue(any(d['locked_out'] for d in details) or before > 0)

    def test_old_failures_are_purged_as_new_ones_arrive(self):
        self.assertEqual(sql_exec("INSERT INTO phoromatic_v2_failures (Kind, SourceIP, At) VALUES ('enrol', '9.9.9.9', 1000)"), 'ok')
        call('/api/v2/enrol', enrol_body('ben_' + 'Y' * 43))
        self.assertEqual(sql("SELECT COUNT(*) AS n FROM phoromatic_v2_failures WHERE SourceIP='9.9.9.9'")[0]['n'], 0)


class TestLaunchLimitsAndIndexPage(unittest.TestCase):
    def test_the_api_front_controller_turns_display_errors_off_before_loading_anything(self):
        path = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '..', 'pts-core', 'phoromatic', 'public_html', 'api', 'v2', 'index.php')
        src = open(path).read()
        self.assertLess(src.index("ini_set('display_errors', '0')"), src.index('include('))

    def test_the_launcher_gives_the_web_server_room_for_a_16_mib_result(self):
        out = srv_sh("""php -r 'define("PTS_MODE", "WEB_CLIENT"); define("PTS_AUTO_LOAD_OBJECTS", true); include("/bench/pts-core/pts-core.php"); pts_core::init(); echo phoromatic_tls::web_php_limits(), "|", phoromatic_tls::post_max_bytes();'""")
        flags, _, limit = out.strip().partition('|')
        self.assertIn('-d post_max_size=24M', flags)
        self.assertIn('-d upload_max_filesize=24M', flags)
        self.assertIn('-d memory_limit=256M', flags)
        self.assertGreaterEqual(int(limit), 16 * 1024 * 1024 + 4 * 1024 * 1024, 'the Caddy body limit is derived from the same value')


class TestReEnrol(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        allow_http(True)

    def test_every_live_token_is_revoked_with_its_own_audit_row(self):
        token, reply = enrol()
        sid = reply['system']['id']
        # a second live token, as only a bug could leave it
        self.assertEqual(sql_exec("INSERT INTO phoromatic_system_tokens (AccountID, SystemID, TokenHash, CreatedOn) VALUES ('%s', '%s', '%s', 'now')" % (AID, sid, 'f' * 64)), 'ok')
        before = len(audit('agent_token_revoke', sid))
        token2, _ = enrol(system=sid)
        self.assertEqual(len(sql("SELECT * FROM phoromatic_system_tokens WHERE SystemID='%s' AND RevokedOn IS NULL" % sid)), 1)
        self.assertEqual(len(audit('agent_token_revoke', sid)) - before, 2)
        self.assertEqual(call('/api/v2/agent/poll', poll_body(), token=token)[1]['error']['code'], 'token_revoked')


class TestProgressAndEnvironment(Agent):
    def test_live_progress_follows_every_test_start(self):
        job, _ = get_job(self.token)
        run = new_run_id()
        def start(seq, index):
            return event('test_start', seq, run, test={'ref': 'benchy/stream', 'version': '1.0.0'}, options={}, summary='', index=index, total=3)
        self.events(job['id'], [hello_event(run), start(2, 1)])
        row = system_row(self.sid)
        self.assertEqual((row['CurrentTask'], int(row['TaskPercentComplete'])), ('Running benchy/stream (1 of 3)', 0))
        # the next one comes straight after (inside what used to be the throttle window)
        self.events(job['id'], [start(3, 2)])
        row = system_row(self.sid)
        self.assertEqual((row['CurrentTask'], int(row['TaskPercentComplete'])), ('Running benchy/stream (2 of 3)', 33))
        self.events(job['id'], [start(4, 3)])
        self.assertEqual(int(system_row(self.sid)['TaskPercentComplete']), 67)

    def test_the_environment_loses_line_breaks_and_is_capped_in_total(self):
        value = 'x' * 4000
        pairs = ''.join('v%d=%s;' % (i, value) for i in range(12))
        self.assertEqual(sql_exec("UPDATE phoromatic_systems SET SystemVariables='crlf=a\r\nb\x7fc;%s' WHERE SystemID='%s'" % (pairs, self.sid)), 'ok')
        job, _ = get_job(self.token)
        env = job['environment']
        self.assertEqual(env['BENCHY_VAR_CRLF'], 'abc')
        total = sum(len(k) - len('BENCHY_VAR_') + len(v) for k, v in env.items())
        self.assertTrue(total <= 32768, total)
        self.assertEqual(len([k for k in env if k.startswith('BENCHY_VAR_V')]), 8, 'eight 4000-byte values fit in 32 KiB, the rest are left out')


class TestTriggerRules(Agent):
    def partial_result_then(self, status, reason):
        job, _ = get_job(self.token)
        run = self.run_job(job, with_run_end=False)
        self.assertEqual(upload_result(self.token, job['id'], fixture_result('quick', run))[0], 200)
        self.end(job['id'], status, reason, run)
        return job

    def test_a_partial_result_from_a_job_the_agent_stopped_does_not_use_the_trigger_up(self):
        for reason in ('agent_stopped', 'agent_restarted'):
            job = self.partial_result_then('cancelled', reason)
            nxt, _ = get_job(self.token)
            self.assertIsNotNone(nxt, 'the trigger comes again after %s even though a partial result is stored' % reason)
            self.end(nxt['id'], 'deferred', 'x')

    def test_a_partial_result_from_a_job_a_person_stopped_does_use_it_up(self):
        self.partial_result_then('cancelled', 'local_cancel')
        self.assertIsNone(get_job(self.token)[0])


class TestBenchSessions(WebBase):
    def setup_member(self):
        msi, sid, bid, session = make_bench(self.admin, cls='other', label='qc Part %s' % uuid.uuid4().hex[:5])
        token, reply = enrol(system=sid)
        sched = native_schedule(sid)
        self.addCleanup(drop_native_schedule, sched)
        self.addCleanup(sql_exec, "DELETE FROM phoromatic_v2_jobs WHERE SystemID='%s'" % sid)
        call('/api/v2/agent/hello', hello_body(), token=token)
        return sid, bid, session, token

    def test_a_late_upload_is_filed_in_its_jobs_session_and_leaves_the_next_jobs_snapshot_alone(self):
        sid, bid, s1, token = self.setup_member()
        job1, _ = get_job(token)
        run1 = new_run_id()
        call('/api/v2/jobs/%d/events' % job1['id'], {'events': [hello_event(run1)]}, token=token)
        # the end comes first (an ok end with no upload yet frees the snapshot, so the bench can swap)
        self.assertEqual(call('/api/v2/jobs/%d/end' % job1['id'], {'status': 'ok', 'reason': None, 'run': run1}, token=token)[0], 200)
        self.assertIsNone(system_row(sid)['BenchTaskSession'])
        swap(self.admin, bid, cls='other', label='qc Part %s' % uuid.uuid4().hex[:5])
        from bench_helpers import open_session
        s2 = int(open_session(bid)['SessionID'])
        self.assertNotEqual(s1, s2)
        job2, _ = get_job(token)
        self.assertIsNotNone(job2, 'a new session runs the schedule again')
        self.assertEqual(job2['bench']['session'], s2)
        self.assertEqual(system_row(sid)['BenchTaskSession'], s2)
        # job 1's result arrives late
        status, body, _ = upload_result(token, job1['id'], fixture_result('quick', run1))
        self.assertEqual(status, 200, body)
        from bench_helpers import result_row
        row = result_row(body['upload_id'])
        self.assertEqual((int(row['BenchSessionID']), row['BenchSessionSource']), (s1, 'task'), 'filed in the session its job was issued in')
        self.assertEqual(system_row(sid)['BenchTaskSession'], s2, 'the next job\'s snapshot is not cleared by the old job\'s upload')

    def test_lost_jobs_are_counted_per_session(self):
        sid, bid, s1, token = self.setup_member()
        first, _ = get_job(token)
        second, _ = get_job(token)
        self.assertIsNotNone(second)
        self.assertIsNone(get_job(token)[0], 'two lost jobs in the session: the trigger is used up in it')
        # release the snapshot of the last lost job, then a new session starts afresh
        sql_exec("UPDATE phoromatic_systems SET BenchTaskSession=NULL, BenchTaskIssuedOn=NULL WHERE SystemID='%s'" % sid)
        swap(self.admin, bid, cls='other', label='qc Part %s' % uuid.uuid4().hex[:5])
        job, _ = get_job(token)
        self.assertIsNotNone(job, 'the lost jobs of the old session do not count in the new one')


if __name__ == '__main__':
    unittest.main()
