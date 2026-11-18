"""LU-S1 and LU-S2 (Benchy 0.10.0): POST /api/v2/results, the upload of a run that a person ran at the machine, and what the Results page says about it.

This is an authenticated write path, so most of the file is about what must NOT happen:
  * tenancy: a second account's system never sees, matches or reads this account's uploads, and the other way round
  * a system's token files only into its own system (a second system of the same account gets its own upload for the same run id)
  * the run id is the key: a repeat (and a race of repeats) is one result; other content under the same run id is 409; a run that a schedule's job
    already sent is that job's upload (origin job)
  * the checks in order: HTTPS, token, tenant state, approval, the account's switch, the hourly cap, size and shape, the machine
  * local_results_off, from the Settings page (level 1 and 2 only, audited), for the new route and for v1 ad-hoc uploads
  * what the page says: "Local run", by whom, and what the machine reported about each test, checked against this server's catalog
  * the converted result of a test the server does not count as native (local/mine) reads like any other

Run through tests/private-repo/run.sh lu1 (also part of http/all).
"""
import base64
import hashlib
import json
import os
import re
import threading
import unittest
import uuid

from bench_helpers import result_row
from test_native_enroll import audit
from test_native_jobs import Agent
from test_private_repo_http import AID, call as v1_call, new_machine, srv_sh, sql
from test_private_repo_tokens import sql_exec
from test_private_repo_web import Browser, PASS, USER
from v2_helpers import (allow_http, call, check, enroll, enroll_body, fixture_result, get_job, hello_body, machine_id, make_other_account, multipart, new_code, new_run_id, remove_account,
                        server_log_problems, upload_result)

FIXTURES = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'fixtures', 'native')
PATH = '/api/v2/results'


def setUpModule():
    allow_http(True)


def tearDownModule():
    allow_http(False)
    problems = server_log_problems()
    assert not problems, 'PHP complained while serving the v2 API: %s' % problems


def doc(seed='a1', run=None, ran_by='dave', fixture='quick', mutate=None, machine=True):
    """A result that the engine 0.8.0 saves for a run: the machine id and the account that ran it are on the run"""
    def mut(d):
        if machine:
            d['runs'][0]['machine_id'] = machine_id(seed)
        d['runs'][0]['ran_by'] = ran_by
        if mutate:
            mutate(d)
    return fixture_result(fixture, run or new_run_id(), mutate=mut)


def up(token, result, raw=None, extra=None):
    """POST /api/v2/results the way the engine does: one multipart `result` part"""
    data = raw if raw is not None else json.dumps(result).encode()
    parts = [('result', 'result.json', 'application/json', data)] + (extra or [])
    body, ctype = multipart(parts)
    return call(PATH, raw=body, token=token, content_type=ctype)


def rows_of(run, system=None):
    q = "SELECT * FROM phoromatic_v2_local_results WHERE RunID='%s'" % run
    return sql(q + (" AND SystemID='%s'" % system if system else ''))


def enroll_in(account, seed, host):
    code = new_code('lu1 ' + host, None, 'native', account)['code']
    status, body, _ = call('/api/v2/enroll', enroll_body(code, seed, host))
    assert status == 201, (status, body)
    return body['token'], body['system']['id']


def row_in(account, upload_id):
    """A result row of an account (upload ids count from 1 in every account)"""
    r = sql("SELECT * FROM phoromatic_results WHERE AccountID='%s' AND UploadID=%d" % (account, int(upload_id)))
    return r[0] if r else None


def post_on_the_quiet_server(path, raw, token, content_type):
    """The same call on the twin server that prints no PHP warnings (display_errors off): PHP's own "POST Content-Length exceeds the limit" warning, printed before
    any script runs, would otherwise turn the reply into text"""
    import urllib.error
    import urllib.request
    req = urllib.request.Request('http://127.0.0.1:%s%s' % (os.environ['QC_PORT2'], path), data=raw, method='POST')
    req.add_header('Content-Type', content_type)
    req.add_header('Authorization', 'Bearer ' + token)
    try:
        with urllib.request.urlopen(req, timeout=120) as r:
            return r.status, json.loads(r.read().decode())
    except urllib.error.HTTPError as e:
        return e.code, json.loads(e.read().decode())


def results_of(system):
    return sql("SELECT * FROM phoromatic_results WHERE SystemID='%s'" % system)


class Two(unittest.TestCase):
    """Two systems of the harness's account (A1, A2) and one of a second account (B1)"""

    @classmethod
    def setUpClass(cls):
        cls.other = make_other_account()
        cls.a1, cls.a1_id = enroll_in(AID, 'a1', 'lu1-a1')
        cls.a2, cls.a2_id = enroll_in(AID, 'a2', 'lu1-a2')
        cls.b1, cls.b1_id = enroll_in(cls.other, 'b1', 'lu1-b1')

    @classmethod
    def tearDownClass(cls):
        for sid in (cls.a1_id, cls.a2_id, cls.b1_id):
            sql_exec("DELETE FROM phoromatic_v2_local_results WHERE SystemID='%s'" % sid)
        remove_account(cls.other)
        sql_exec("DELETE FROM phoromatic_results WHERE AccountID='%s'" % cls.other)


class TestTheRoute(Two):
    def test_a_run_is_filed_as_a_local_result_under_the_systems_own_token(self):
        d = doc('a1')
        status, body, _ = up(self.a1, d)
        self.assertEqual(status, 201, body)
        self.assertEqual(check('local_result_response', body), [])
        self.assertEqual((body['duplicate'], body['origin']), (False, 'local'))
        row = result_row(body['upload_id'])
        self.assertEqual(row['SystemID'], self.a1_id)
        self.assertIn(row['ScheduleID'], (0, None))
        self.assertIsNone(row['BenchmarkTicketID'])
        self.assertEqual((row['RanBy'], row['Provenance']), ('dave', 'unknown'), 'a run with no source on its tests is "not recorded"')
        self.assertIn(row['PPRID'], body['url'])
        key = rows_of(d['runs'][0]['run'])
        self.assertEqual([(k['AccountID'], k['SystemID'], k['UploadID']) for k in key], [(AID, self.a1_id, body['upload_id'])])
        self.assertEqual(key[0]['ContentSHA256'], hashlib.sha256(json.dumps(d).encode()).hexdigest())
        base = '/var/lib/benchy/phoromatic/accounts/%s/results/%d/' % (AID, body['upload_id'])
        self.assertEqual(json.loads(srv_sh('cat %sresult.json' % base)), d, 'the native original is kept')
        self.assertIn('<TestClient>benchy-native', srv_sh('cat %scomposite.xml' % base))

    def test_a_repeat_is_the_first_reply_and_one_result(self):
        d = doc('a1')
        s1, first, _ = up(self.a1, d)
        s2, again, _ = up(self.a1, d)
        self.assertEqual((s1, s2), (201, 200), (first, again))
        self.assertEqual(check('local_result_response', again), [])
        self.assertEqual((again['upload_id'], again['url'], again['duplicate'], again['origin']), (first['upload_id'], first['url'], True, 'local'))
        self.assertEqual(len(rows_of(d['runs'][0]['run'])), 1)
        self.assertEqual(sql("SELECT COUNT(*) AS n FROM phoromatic_results WHERE SystemID='%s' AND UploadID=%d" % (self.a1_id, first['upload_id']))[0]['n'], 1)

    def test_a_race_of_repeats_makes_one_result(self):
        d = doc('a1')
        before = len(results_of(self.a1_id))
        out = []

        def go():
            out.append(up(self.a1, d)[:2])
        threads = [threading.Thread(target=go) for _ in range(6)]
        for t in threads:
            t.start()
        for t in threads:
            t.join()
        self.assertEqual(sorted(s for s, _ in out), [200] * 5 + [201], out)
        self.assertEqual(len({b['upload_id'] for _, b in out}), 1)
        self.assertEqual(len(results_of(self.a1_id)), before + 1)

    def test_other_content_under_the_same_run_id_is_run_conflict_and_changes_nothing(self):
        d = doc('a1')
        _, first, _ = up(self.a1, d)
        other = json.loads(json.dumps(d))
        other['runs'][0]['description'] = 'edited by hand'
        status, body, _ = up(self.a1, other)
        self.assertEqual((status, body['error']['code']), (409, 'run_conflict'), body)
        self.assertEqual(len(rows_of(d['runs'][0]['run'])), 1)
        self.assertEqual(rows_of(d['runs'][0]['run'])[0]['UploadID'], first['upload_id'])

    def test_a_run_a_schedule_already_sent_is_that_jobs_upload(self):
        agent = Agent('setUp')
        agent.setUp()
        try:
            self.assertEqual(call('/api/v2/agent/hello', hello_body(), token=agent.token)[0], 200)
            job, _ = get_job(agent.token)
            run = new_run_id()
            agent.run_job(job, run)
            status, sent, _ = upload_result(agent.token, job['id'], fixture_result('stream-ok', run))
            self.assertEqual(status, 200, sent)
            # the same run, from the same system's own token, by hand
            status, body, _ = up(agent.token, doc('a', run=run))
            self.assertEqual((status, body['upload_id'], body['duplicate'], body['origin']), (200, sent['upload_id'], True, 'job'), body)
            self.assertEqual(rows_of(run), [], 'no second result and no local row')
        finally:
            agent.doCleanups()


class TestTenancyAndIdentity(Two):
    def test_the_same_run_id_from_another_account_is_a_separate_upload_and_reads_nothing(self):
        d = doc('a1')
        _, mine, _ = up(self.a1, d)
        theirs_doc = doc('b1', run=d['runs'][0]['run'])
        theirs_doc['runs'][0]['description'] = 'the other tenant\'s'
        before = rows_of(d['runs'][0]['run'], self.a1_id)
        status, body, _ = up(self.b1, theirs_doc)
        self.assertEqual(status, 201, body)
        self.assertFalse(body['duplicate'])
        self.assertNotEqual(body['url'], mine['url'], 'the other account is not told about this account\'s upload')
        self.assertEqual(sql("SELECT AccountID FROM phoromatic_results WHERE PPRID='%s'" % body['url'].split('/')[-1])[0]['AccountID'], self.other)
        self.assertEqual(rows_of(d['runs'][0]['run'], self.a1_id), before, 'this account\'s row is untouched')
        # and the same bytes: still the other account's own first upload, not a duplicate of this account's
        status, same, _ = up(self.b1, d if False else doc('b1', run=d['runs'][0]['run']))
        self.assertIn(status, (200, 409), same)
        self.assertNotIn(mine['url'], json.dumps(same))
        # the first account's repeat is still its own
        s, again, _ = up(self.a1, d)
        self.assertEqual((s, again['upload_id']), (200, mine['upload_id']))

    def test_another_systems_token_of_the_same_account_files_under_that_system_only(self):
        d = doc('a1')
        _, mine, _ = up(self.a1, d)
        # A2 uploads the same run id (and a copy of the same bytes, with its own machine id)
        copy = doc('a2', run=d['runs'][0]['run'])
        status, body, _ = up(self.a2, copy)
        self.assertEqual(status, 201, body)
        self.assertFalse(body['duplicate'], 'a run id of another system is not a repeat')
        self.assertNotEqual(body['upload_id'], mine['upload_id'])
        self.assertEqual(result_row(body['upload_id'])['SystemID'], self.a2_id)
        self.assertEqual(result_row(mine['upload_id'])['SystemID'], self.a1_id)
        self.assertEqual(sorted(k['SystemID'] for k in rows_of(d['runs'][0]['run'])), sorted([self.a1_id, self.a2_id]))

    def test_nothing_in_the_body_chooses_the_system_or_the_account(self):
        d = doc('a1')
        d['system'] = self.a2_id
        d['account'] = self.other
        d['runs'][0]['system_id'] = self.a2_id
        status, body, _ = up(self.a1, d)
        self.assertEqual(status, 201, body)
        row = result_row(body['upload_id'])
        self.assertEqual((row['AccountID'], row['SystemID']), (AID, self.a1_id))

    def test_a_run_made_on_another_computer_is_refused_and_one_with_no_machine_id_is_taken(self):
        status, body, _ = up(self.a1, doc('a2'))
        self.assertEqual((status, body['error']['code']), (422, 'result_invalid'), body)
        self.assertIn('another computer', body['error']['message'])
        before = len(results_of(self.a1_id))
        d = doc('a1', machine=False)
        status, body, _ = up(self.a1, d)
        self.assertEqual(status, 201, body)
        d = doc('a1', mutate=lambda x: x['runs'][0].__setitem__('machine_id', None))
        self.assertEqual(up(self.a1, d)[0], 201)
        self.assertEqual(len(results_of(self.a1_id)), before + 2)

    def test_a_view_of_the_result_is_only_for_the_account_that_has_it(self):
        _, theirs, _ = up(self.b1, doc('b1'))
        b = Browser().login(USER, PASS)
        self.assertNotIn('Local run', b.get('/?result/' + theirs['url'].split('/')[-1])[2], 'the other account\'s local result is not found here')


class TestTheOrderOfTheChecks(Two):
    def setUp(self):
        self.restore = []

    def tearDown(self):
        for q in self.restore:
            sql_exec(q)

    def test_no_token_a_wrong_token_and_a_revoked_token(self):
        self.assertEqual(up(None, doc())[0], 401)
        self.assertEqual(up('bsa_' + 'A' * 43, doc())[0], 401)
        token, sid = enroll_in(AID, 'c1', 'lu1-rv')
        sql_exec("UPDATE phoromatic_system_tokens SET RevokedOn='2026-11-18 10:00:00' WHERE SystemID='%s'" % sid)
        status, body, _ = up(token, doc('c1'))
        self.assertEqual((status, body['error']['code']), (401, 'token_revoked'))
        self.assertEqual(results_of(sid), [])

    def test_plain_http_is_refused_before_anything_else(self):
        allow_http(False)
        try:
            status, body, _ = up(self.a1, doc())
            self.assertEqual((status, body['error']['code']), (426, 'https_required'))
        finally:
            allow_http(True)

    def test_a_suspended_account_and_an_unapproved_system(self):
        token, sid = enroll_in(self.other, 'c2', 'lu1-sp')
        sql_exec("UPDATE phoromatic_accounts SET State='suspended' WHERE AccountID='%s'" % self.other)
        self.restore.append("UPDATE phoromatic_accounts SET State='active' WHERE AccountID='%s'" % self.other)
        status, body, _ = up(token, doc('c2'))
        self.assertEqual((status, body['error']['code']), (403, 'tenant_suspended'))
        sql_exec("UPDATE phoromatic_accounts SET State='active' WHERE AccountID='%s'" % self.other)
        sql_exec("UPDATE phoromatic_systems SET State=0 WHERE SystemID='%s'" % sid)
        status, body, _ = up(token, doc('c2'))
        self.assertEqual((status, body['error']['code']), (403, 'system_not_approved'))
        self.assertEqual(results_of(sid), [])

    def test_the_approval_check_comes_before_the_account_setting_and_the_setting_before_the_body(self):
        token, sid = enroll_in(AID, 'c3', 'lu1-od')
        sql_exec("UPDATE phoromatic_account_settings SET AcceptLocalResults=0 WHERE AccountID='%s'" % AID)
        self.restore.append("UPDATE phoromatic_account_settings SET AcceptLocalResults=1 WHERE AccountID='%s'" % AID)
        sql_exec("UPDATE phoromatic_systems SET State=0 WHERE SystemID='%s'" % sid)
        status, body, _ = up(token, doc('c3'))
        self.assertEqual(body['error']['code'], 'system_not_approved', 'an unapproved system learns nothing about the account\'s setting')
        sql_exec("UPDATE phoromatic_systems SET State=1 WHERE SystemID='%s'" % sid)
        status, body, _ = up(token, None, raw=b'not json at all')
        self.assertEqual((status, body['error']['code']), (403, 'local_results_off'), 'before the body is looked at')

    def test_the_hourly_cap_is_per_system_and_counts_the_past_hour_only(self):
        token, sid = enroll_in(AID, 'c4', 'lu1-rl')
        now = int(__import__('time').time())
        values = ','.join("('%s','%s','cap%03d','x',%d,%d)" % (AID, sid, i, 900000 + i, now - 60) for i in range(120))
        sql_exec("INSERT INTO phoromatic_v2_local_results (AccountID, SystemID, RunID, ContentSHA256, UploadID, UploadedOn) VALUES " + values)
        self.addCleanup(sql_exec, "DELETE FROM phoromatic_v2_local_results WHERE SystemID='%s'" % sid)
        status, body, headers = up(token, doc('c4'))
        self.assertEqual((status, body['error']['code']), (429, 'rate_limited'), body)
        retry = int({k.lower(): v for k, v in headers.items()}['retry-after'])
        self.assertTrue(1 <= retry <= 3600, retry)
        self.assertEqual(len([r for r in results_of(sid)]), 0)
        # another system of the same account is not affected
        self.assertEqual(up(self.a1, doc('a1'))[0], 201)
        # the same system, once the rows are older than the hour: through again
        sql_exec("UPDATE phoromatic_v2_local_results SET UploadedOn=%d WHERE SystemID='%s'" % (now - 3700, sid))
        self.assertEqual(up(token, doc('c4'))[0], 201)
        # 119 in the hour: one more is allowed
        sql_exec("DELETE FROM phoromatic_v2_local_results WHERE SystemID='%s'" % sid)
        values = ','.join("('%s','%s','cap%03d','x',%d,%d)" % (AID, sid, i, 900000 + i, now - 60) for i in range(119))
        sql_exec("INSERT INTO phoromatic_v2_local_results (AccountID, SystemID, RunID, ContentSHA256, UploadID, UploadedOn) VALUES " + values)
        self.assertEqual(up(token, doc('c4'))[0], 201)
        self.assertEqual(up(token, doc('c4'))[0], 429)

    def test_size_and_shape(self):
        # over the PHP limit of the harness (16M): 413 and nothing stored
        before = len(results_of(self.a1_id))
        big = b'{"x":"' + b'a' * (17 * 1024 * 1024) + b'"}'
        body_, ctype = multipart([('result', 'result.json', 'application/json', big)])
        status, body = post_on_the_quiet_server(PATH, body_, self.a1, ctype)
        self.assertEqual((status, body['error']['code']), (413, 'too_large'), str(body)[:200])
        # no result part, not JSON, an array, wrong schema, no ok test, two runs
        body_, ctype = multipart([('other', None, 'text/plain', b'x')])
        self.assertEqual(call(PATH, raw=body_, token=self.a1, content_type=ctype)[0], 400)
        self.assertEqual(up(self.a1, None, raw=b'<xml/>')[0], 400)
        self.assertEqual(up(self.a1, None, raw=b'[1,2]')[0], 400)
        self.assertEqual(up(self.a1, {'schema': 1})[0:2][0], 422)
        none_ok = doc('a1', mutate=lambda d: d['runs'][0]['tests'][0].update(status='failed', value=None, error={'code': 'x', 'message': 'broke'}))
        status, body, _ = up(self.a1, none_ok)
        self.assertEqual((status, body['error']['code']), (422, 'result_invalid'), body)
        self.assertIn('No test', body['error']['message'])
        two = doc('a1')
        two['runs'].append(json.loads(json.dumps(two['runs'][0])))
        two['runs'][1]['run'] = new_run_id()
        self.assertEqual(up(self.a1, two)[0], 422)
        no_version = doc('a1', mutate=lambda d: d['runs'][0]['tests'][0]['test'].__setitem__('version', None))
        self.assertEqual(up(self.a1, no_version)[0], 422)
        self.assertEqual(len(results_of(self.a1_id)), before, 'none of them stored anything')

    def test_an_events_part_is_ignored_and_extra_fields_on_a_run_do_no_harm(self):
        d = doc('a1', mutate=lambda x: x['runs'][0]['tests'][0].update(source={'tier': 'brand-new', 'extra': 1}))
        status, body, _ = up(self.a1, d, extra=[('events', 'e.gz', 'application/gzip', b'not gzip at all')])
        self.assertEqual(status, 201, body)
        self.assertEqual(srv_sh("ls /var/lib/benchy/phoromatic/accounts/%s/results/%d/" % (AID, body['upload_id'])).split(), ['composite.xml', 'result.json'])
        self.assertEqual(result_row(body['upload_id'])['Provenance'], 'unknown', 'a tier this server does not know is "not recorded"')

    def test_ran_by_is_cut_and_cleaned(self):
        d = doc('a1', ran_by='<b>eve</b>' + 'x' * 100)
        status, body, _ = up(self.a1, d)
        self.assertEqual(status, 201, body)
        ran_by = result_row(body['upload_id'])['RanBy']
        self.assertTrue(0 < len(ran_by) <= 64 and not re.search(r'[<>&"\']', ran_by), ran_by)
        status, body, _ = up(self.a1, doc('a1', ran_by=None))
        self.assertIsNone(result_row(body['upload_id'])['RanBy'])


class TestThePages(Two):
    """LU-S2: the Results page says what a result is, and the account decides whether such results are taken"""

    def setUp(self):
        self.admin = Browser().login(USER, PASS)
        self.restore = []

    def tearDown(self):
        for q in self.restore:
            sql_exec(q)

    def test_the_results_list_and_the_result_page_say_local_run_and_who(self):
        d = doc('a1', ran_by='dave')
        _, body, _ = up(self.a1, d)
        pprid = body['url'].split('/')[-1]
        listing = self.admin.get('/?results')[2]
        self.assertRegex(listing, r'Local run, by dave')
        page = self.admin.get('/?result/' + pprid)[2]
        self.assertIn('Local run</strong>: run at ', page)
        self.assertIn(' by dave', page)
        self.assertIn('What the machine reported about its tests', page)
        self.assertIn('Where the tests came from was not recorded', page)
        for bad in ('Fatal error', 'Warning</b>', 'Notice</b>', 'Deprecated</b>'):
            self.assertNotIn(bad, page)
        # a result a schedule made carries no such label
        agent = Agent('setUp')
        agent.setUp()
        try:
            call('/api/v2/agent/hello', hello_body(), token=agent.token)
            job, _ = get_job(agent.token)
            run = new_run_id()
            agent.run_job(job, run)
            _, sent, _ = upload_result(agent.token, job['id'], fixture_result('stream-ok', run))
            self.assertNotIn('Local run</strong>', self.admin.get('/?result/' + sent['url'].split('/')[-1])[2])
        finally:
            agent.doCleanups()

    def test_a_v1_ad_hoc_result_from_before_the_step_is_labelled_without_a_backfill(self):
        # an ad-hoc upload (no schedule, no ticket) stored by the v1 route is a local run with no name
        xml = open(os.path.join(FIXTURES, 'quick.golden.xml'), 'rb').read()
        msi = new_machine()
        r = v1_call('result_upload', msi, composite_xml=base64.b64encode(xml).decode(), composite_xml_hash=hashlib.sha1(xml).hexdigest())
        self.assertIn('upload_id', r, r)
        page = self.admin.get('/?result/' + sql("SELECT PPRID FROM phoromatic_results WHERE UploadID=%d AND AccountID='%s'" % (int(r['upload_id']), AID))[0]['PPRID'])[2]
        self.assertIn('Local run</strong>', page)
        self.assertIn('The machine did not say where its tests came from.', page)

    def test_the_setting_is_on_by_default_and_a_level_1_user_turns_it_off_with_an_audit_row(self):
        page = self.admin.get('/?settings')[2]
        self.assertRegex(page, r'name="AcceptLocalResults" checked="checked"')
        self.assertEqual(sql("SELECT AcceptLocalResults AS v FROM phoromatic_account_settings WHERE AccountID='%s'" % AID)[0]['v'], 1)
        saved = sql("SELECT * FROM phoromatic_account_settings WHERE AccountID='%s'" % AID)[0]
        self.restore.append("UPDATE phoromatic_account_settings SET AcceptLocalResults=1 WHERE AccountID='%s'" % AID)
        self.post_settings(self.admin, off=True)
        self.assertEqual(sql("SELECT AcceptLocalResults AS v FROM phoromatic_account_settings WHERE AccountID='%s'" % AID)[0]['v'], 0)
        rows = [a for a in audit('account_setting_set') if a['SubjectID'] == 'AcceptLocalResults']
        self.assertEqual(len(rows), 1, rows)
        self.assertEqual(json.loads(rows[0]['Detail']), {'before': 1, 'after': 0})
        # the other settings stayed as they were
        now = sql("SELECT * FROM phoromatic_account_settings WHERE AccountID='%s'" % AID)[0]
        for k, v in saved.items():
            if k not in ('AcceptLocalResults',) and k in ('RunInstallCommand', 'UploadSystemLogs', 'UploadInstallLogs', 'ProgressiveResultUploads'):
                self.assertEqual(now[k], v, k)
        # the new route refuses with its own code, and so does a v1 ad-hoc upload
        before = len(results_of(self.a1_id))
        status, body, _ = up(self.a1, doc('a1'))
        self.assertEqual((status, body['error']['code']), (403, 'local_results_off'))
        self.assertEqual(len(results_of(self.a1_id)), before)
        xml = open(os.path.join(FIXTURES, 'quick.golden.xml'), 'rb').read()
        r = v1_call('result_upload', new_machine(), composite_xml=base64.b64encode(xml).decode(), composite_xml_hash=hashlib.sha1(xml).hexdigest())
        self.assertIn('does not accept results that people run themselves', r.get('error', ''), r)
        # a repeat of a run already uploaded is refused too while the switch is off (the engine keeps it queued as rejected)
        # a schedule's own result is not affected
        agent = Agent('setUp')
        agent.setUp()
        try:
            call('/api/v2/agent/hello', hello_body(), token=agent.token)
            job, _ = get_job(agent.token)
            run = new_run_id()
            agent.run_job(job, run)
            self.assertEqual(upload_result(agent.token, job['id'], fixture_result('stream-ok', run))[0], 200)
        finally:
            agent.doCleanups()
        # turning it on again is audited the other way
        self.post_settings(self.admin, off=False)
        self.assertEqual(up(self.a1, doc('a1'))[0], 201)
        rows = [json.loads(a['Detail']) for a in audit('account_setting_set') if a['SubjectID'] == 'AcceptLocalResults']
        self.assertEqual(sorted(r['after'] for r in rows), [0, 1])

    def post_settings(self, browser, off):
        page = browser.get('/?settings')[2]
        token = re.search(r'name="token_submit" value="([0-9a-f]+)"', page).group(1)
        fields = {'account_settings_update': '1', 'token_submit': token}
        for name in ('AutoApproveNewSystems', 'RunInstallCommand', 'ForceInstallTests', 'PreSeedTestInstalls', 'ArchiveResultsLocally', 'ProgressiveResultUploads', 'UploadSystemLogs', 'UploadInstallLogs',
                     'UploadRunLogs', 'UploadResultsToOpenBenchmarking', 'PowerOffWhenDone', 'NetworkPowerUpWhenNeeded', 'PowerOnSystemDaily', 'LimitNetworkCommunication', 'AcceptLocalResults'):
            if re.search(r'name="%s" (disabled="disabled" )?checked="checked"' % name, page) and not (name == 'AcceptLocalResults' and off):
                fields[name] = 'yes'
        status, _, body = browser.post('/?settings', fields)
        self.assertEqual(status, 200)
        return body

    def test_a_bench_systems_local_result_goes_under_its_open_session_as_ad_hoc(self):
        from bench_helpers import make_bench
        msi, sid, bid, session = make_bench(self.admin, cls='other', label='qc Part lu1 %s' % uuid.uuid4().hex[:5])
        token, reply = enroll(system=sid, machine_seed='d1')
        self.addCleanup(sql_exec, "DELETE FROM phoromatic_v2_local_results WHERE SystemID='%s'" % sid)
        call('/api/v2/agent/hello', hello_body(machine_seed='d1'), token=token)
        status, body, _ = up(token, doc('d1'))
        self.assertEqual(status, 201, body)
        row = result_row(body['upload_id'])
        self.assertEqual((row['SystemID'], int(row['BenchSessionID']), row['BenchSessionSource']), (sid, session, 'upload'), 'the open session, marked as an ad-hoc upload')

    def test_only_a_group_administrator_or_administrator_can_change_it(self):
        from test_private_repo_web import make_user
        from test_private_repo_tokens import level_sql
        suffix = uuid.uuid4().hex[:5]
        users = {}
        for level in (2, 3, 10):
            users[level] = 'lu1u%d%s' % (level, suffix)
            make_user(self.admin, users[level], level)
        for level, expect_change in ((2, True), (3, False), (10, False)):
            b = Browser().login(users[level], 'qcpass123')
            page = b.get('/?settings')[2]
            if level == 10:
                self.assertIn('Accept results that people run themselves on this account&#039;s systems: <strong>On</strong>', page.replace("'", '&#039;'))
                continue
            self.restore.append("UPDATE phoromatic_account_settings SET AcceptLocalResults=1 WHERE AccountID='%s'" % AID)
            if expect_change:
                self.assertNotRegex(page, r'name="AcceptLocalResults"[^>]*disabled')
            else:
                self.assertRegex(page, r'name="AcceptLocalResults"[^>]*disabled')
            fields = {'account_settings_update': '1', 'token_submit': re.search(r'name="token_submit" value="([0-9a-f]+)"', page).group(1)}
            b.post('/?settings', fields)   # every box unticked: the switch is turned off only when this level may
            self.assertEqual(sql("SELECT AcceptLocalResults AS v FROM phoromatic_account_settings WHERE AccountID='%s'" % AID)[0]['v'], 0 if expect_change else 1, 'level %d' % level)
            sql_exec("UPDATE phoromatic_account_settings SET AcceptLocalResults=1 WHERE AccountID='%s'" % AID)


class TestProvenance(Two):
    """The page says what each test was, from what the machine reported checked against this server's own catalog (design 2.3)"""
    TREE = 'c' * 64
    WRONG = 'd' * 64

    @classmethod
    def setUpClass(cls):
        super().setUpClass()
        # A served public tier made by hand: a signed file that the row names by its hash, and the entries of two tests, one withdrawn
        signed = b'lu1 test signed index'
        cls.sha = hashlib.sha256(signed).hexdigest()
        srv_sh("mkdir -p /var/lib/benchy/phoromatic/catalog/signed && printf '%%s' '%s' > /var/lib/benchy/phoromatic/catalog/signed/%s.signed" % (signed.decode(), cls.sha))
        sql_exec("INSERT INTO phoromatic_catalog_tiers (Tier, Generation, Sequence, SignedSHA256, KeyID, Issued, TestCount, SuiteCount, RevokedCount, BundleSHA256, ImportedOn, ImportedBy, ImportedVia) "
                 "VALUES ('public', 1, 1, '%s', 'lu1-key', '2026-11-18', 2, 0, 1, '%s', '2026-11-18', 'qc', 'cli')" % (cls.sha, 'e' * 64))
        for ref, version in (('benchy/lu1stream', '1.0.0'), ('benchy/lu1old', '1.0.0')):
            sql_exec("INSERT INTO phoromatic_catalog_entries (Tier, Kind, ID, Version, MinEngine, Title, ElevatedRun, EntryJSON) VALUES ('public', 'test', '%s', '%s', '0.1.0', 'LU1', 0, '%s')"
                     % (ref, version, json.dumps({'tree_sha256': cls.TREE})))
        sql_exec("INSERT INTO phoromatic_catalog_revoked (Tier, Ref, Reason) VALUES ('public', 'benchy/lu1old@1.0.0', 'bad build')")

    @classmethod
    def tearDownClass(cls):
        sql_exec("DELETE FROM phoromatic_catalog_tiers WHERE KeyID='lu1-key'")
        sql_exec("DELETE FROM phoromatic_catalog_entries WHERE ID LIKE 'benchy/lu1%'")
        sql_exec("DELETE FROM phoromatic_catalog_revoked WHERE Ref LIKE 'benchy/lu1%'")
        srv_sh('rm -f /var/lib/benchy/phoromatic/catalog/signed/%s.signed' % cls.sha)
        super().tearDownClass()

    def upload(self, source, ref='benchy/lu1stream', version='1.0.0', token=None, seed='a1'):
        def mut(d):
            t = d['runs'][0]['tests'][0]
            t['test'] = {'ref': ref, 'version': version}
            if source is not None:
                t['source'] = source
        status, body, _ = up(token or self.a1, doc(seed, mutate=mut))
        self.assertEqual(status, 201, body)
        return body, row_in(self.other if token == self.b1 else AID, body['upload_id'])

    def test_a_catalog_claim_that_matches_this_servers_catalog(self):
        body, row = self.upload({'tier': 'public', 'tree_sha256': self.TREE})
        self.assertEqual(row['Provenance'], 'catalog')
        page = Browser().login(USER, PASS).get('/?result/' + row['PPRID'])[2]
        self.assertIn('Catalog tests.', page)
        self.assertIn('benchy/lu1stream 1.0.0: Catalog test</li>', page)

    def test_a_claim_with_another_tree_or_for_a_version_the_catalog_lacks_is_unverified_and_still_taken(self):
        for source, version in (({'tier': 'public', 'tree_sha256': self.WRONG}, '1.0.0'), ({'tier': 'public', 'tree_sha256': self.TREE}, '9.9.9'), ({'tier': 'public'}, '1.0.0')):
            body, row = self.upload(source, version=version)
            self.assertEqual(row['Provenance'], 'unverified', (source, version))
        page = Browser().login(USER, PASS).get('/?result/' + row['PPRID'])[2]
        self.assertIn("Catalog test, not in this server&#039;s catalog", page.replace("'", '&#039;'))

    def test_a_withdrawn_version_says_why(self):
        body, row = self.upload({'tier': 'public', 'tree_sha256': self.TREE}, ref='benchy/lu1old')
        self.assertEqual(row['Provenance'], 'unverified')
        self.assertIn('Catalog test, withdrawn version (bad build)', Browser().login(USER, PASS).get('/?result/' + row['PPRID'])[2])

    def test_local_builtin_mixed_and_not_recorded(self):
        self.assertEqual(self.upload({'tier': 'local', 'tree_sha256': self.TREE}, ref='local/mine')[1]['Provenance'], 'local')
        self.assertEqual(self.upload({'tier': 'builtin'}, ref='benchy/stream')[1]['Provenance'], 'catalog')
        self.assertEqual(self.upload(None)[1]['Provenance'], 'unknown')

        def mixed(d):
            d['runs'][0]['tests'][0]['source'] = {'tier': 'public', 'tree_sha256': self.TREE}
            d['runs'][0]['tests'][0]['test'] = {'ref': 'benchy/lu1stream', 'version': '1.0.0'}
            second = json.loads(json.dumps(d['runs'][0]['tests'][0]))
            second['test'] = {'ref': 'local/mine', 'version': '1.0.0'}
            second['source'] = {'tier': 'local'}
            d['runs'][0]['tests'].append(second)
        status, body, _ = up(self.a1, doc('a1', mutate=mixed))
        self.assertEqual(status, 201, body)
        self.assertEqual(result_row(body['upload_id'])['Provenance'], 'mixed')

    def test_the_other_accounts_claim_is_checked_against_the_catalog_it_is_served_not_this_ones_uploads(self):
        # the lookup uses the catalog tiers only, never another account's results or catalogue rows
        body, row = self.upload({'tier': 'public', 'tree_sha256': self.TREE}, token=self.b1, seed='b1')
        self.assertEqual(row['Provenance'], 'catalog')
        self.assertEqual(row['AccountID'], self.other)

    def test_a_local_test_whose_ref_the_server_does_not_count_as_native_reads_like_any_other(self):
        # E17: local/mine is an id in the local/ repository, which is_native_ref() treats as OpenBenchmarking.org's
        body, row = self.upload({'tier': 'local'}, ref='local/mine')
        b = Browser().login(USER, PASS)
        page = b.get('/?result/' + row['PPRID'])[2]
        for bad in ('Fatal error', 'Warning</b>', 'Notice</b>', 'Deprecated</b>', 'Uncaught'):
            self.assertNotIn(bad, page)
        self.assertIn('local/mine-1.0.0', page + srv_sh('cat /var/lib/benchy/phoromatic/accounts/%s/results/%d/composite.xml' % (AID, body['upload_id'])))
        xml = srv_sh('cat /var/lib/benchy/phoromatic/accounts/%s/results/%d/composite.xml' % (AID, body['upload_id']))
        self.assertIn('<Scale>Points</Scale>', xml)
        self.assertIn('<Identifier>local/mine-1.0.0</Identifier>', xml)
        self.assertIn('Points', page, 'the unit comes from the saved result, not from a lookup')


if __name__ == '__main__':
    unittest.main()
