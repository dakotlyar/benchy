"""Native agents, step S4 (Benchy 0.9.4): the v2 front controller, the HTTPS rule, the error model, enrollment codes, system agent tokens,
revocation, the lockouts and the audit trail.

Replies are checked against the pinned schemas (the server's own validator, run in the container). Plain HTTP is only allowed while a
class that needs it runs (the server setting v2_allow_http is off by default and is switched back at the end of the module).

Run through tests/private-repo/run.sh (part of http/all).
"""
import hashlib
import json
import unittest
import uuid

from bench_helpers import new_system, system_row
from test_private_repo_http import AID, sql, srv_sh
from test_private_repo_tokens import sql_exec
from v2_helpers import (make_other_account, remove_account, server_log_problems, admin, allow_http, call, call_local, check, enroll, enroll_body, example, hello_body, machine_id, new_code, poll_body)

ENDPOINTS = ('/api/v2/enroll', '/api/v2/agent/hello', '/api/v2/agent/poll', '/api/v2/jobs/1/events', '/api/v2/jobs/1/result', '/api/v2/jobs/1/end')


def setUpModule():
    allow_http(False)


def tearDownModule():
    allow_http(False)
    problems = server_log_problems()
    assert not problems, 'PHP complained while serving the v2 API: %s' % problems


def audit(action, system=None):
    rows = sql("SELECT * FROM phoromatic_admin_audit WHERE Action='%s' ORDER BY AuditID" % action)
    return [r for r in rows if system is None or r['SubjectID'] == system]


class TestSchemeRule(unittest.TestCase):
    def test_plain_http_is_refused_before_anything_else_unless_the_setting_is_on(self):
        allow_http(False)
        for path in ENDPOINTS:
            for token in (None, 'bsa_' + 'A' * 43):
                status, body, headers = call(path, {}, token=token)
                self.assertEqual(status, 426, path)
                self.assertEqual(body['error']['code'], 'https_required')
                self.assertEqual(check('error_reply', body), [])
                self.assertIn('application/json', headers.get('Content-Type', ''))
        allow_http(True)
        try:
            status, body, _ = call('/api/v2/agent/poll', {})
            self.assertEqual((status, body['error']['code']), (401, 'unauthenticated'), 'with the setting on the request gets as far as the token check')
        finally:
            allow_http(False)
        self.assertEqual(call('/api/v2/agent/poll', {})[0], 426, 'and it is refused again when the setting goes off')

    def test_the_setting_change_is_audited(self):
        allow_http(True)
        allow_http(False)
        rows = audit('v2_allow_http_set')
        self.assertTrue(len(rows) >= 2)
        self.assertEqual([json.loads(r['Detail'])['after'] for r in rows[-2:]], ['on', 'off'])


class TestRoutingAndBodies(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        allow_http(True)

    def test_every_error_has_the_envelope_and_the_documented_shape(self):
        status, body, _ = call('/api/v2/agent/nothing', {})
        self.assertEqual((status, body['error']['code']), (404, 'not_found'))
        self.assertEqual(check('error_reply', body), [])
        self.assertEqual(body['api'], 2)
        self.assertEqual(call('/api/v2/jobs/abc/events', {})[0], 404)
        self.assertEqual(call('/api/v2/', {})[0], 404)

    def test_the_query_string_and_a_trailing_slash_do_not_change_the_route(self):
        self.assertEqual(call('/api/v2/agent/poll?x=1', {})[1]['error']['code'], 'unauthenticated')
        self.assertEqual(call('/api/v2/agent/poll/', {})[1]['error']['code'], 'unauthenticated')

    def test_only_post_is_taken(self):
        status, body, headers = call('/api/v2/agent/poll', method='GET')
        self.assertEqual((status, body['error']['code']), (400, 'bad_request'))
        self.assertEqual(headers.get('Allow'), 'POST')

    def test_bodies_that_are_not_one_json_object_are_bad_requests(self):
        for raw in (b'', b'not json', b'[1, 2]', b'"text"', b'null'):
            status, body, _ = call('/api/v2/enroll', raw=raw)
            self.assertEqual((status, body['error']['code']), (400, 'bad_request'), raw)

    def test_a_body_over_1_mib_is_too_large(self):
        status, body, _ = call('/api/v2/enroll', raw=b'{"x": "' + b'a' * (1024 * 1024 + 10) + b'"}')
        self.assertEqual((status, body['error']['code']), (413, 'too_large'))
        self.assertEqual(check('error_reply', body), [])


class TestEnrollment(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        allow_http(True)

    def test_a_new_system_is_created_approved_and_native(self):
        made = new_code('qc new rig', None, 'native')
        self.assertRegex(made['code'], r'^ben_[A-Za-z0-9_-]{43}$')
        status, body, _ = call('/api/v2/enroll', enroll_body(made['code'], 'b', 'Kamino <b>x</b> host'))
        self.assertEqual(status, 201, body)
        self.assertEqual(check('enroll_response', body), [])
        self.assertRegex(body['token'], r'^bsa_[A-Za-z0-9_-]{43}$')
        sid = body['system']['id']
        row = system_row(sid)
        self.assertEqual((row['State'], row['EnginePolicy'], row['AccountID']), (1, 'native', AID))
        self.assertEqual(body['policy'], 'native')
        self.assertNotIn('<', row['Title'], 'the hostname is cleaned before it becomes a title')
        self.assertIn('Example CPU', row['Hardware'])
        self.assertIn('benchy_system_info', row['SystemProperties'])
        self.assertEqual(row['ClientVersion'], 'benchy-native 0.3.0')
        # only hashes are stored; neither secret is on disk anywhere
        tok = sql("SELECT * FROM phoromatic_system_tokens WHERE SystemID='%s'" % sid)[0]
        self.assertEqual((tok['TokenHash'], tok['MachineID']), (hashlib.sha256(body['token'].encode()).hexdigest(), machine_id('b')))
        code = sql("SELECT * FROM phoromatic_enrol_codes WHERE CodeID=%d" % made['id'])[0]
        self.assertEqual((code['CodeHash'], code['Uses']), (hashlib.sha256(made['code'].encode()).hexdigest(), 1))
        for secret in (body['token'], made['code']):
            found = srv_sh("grep -rlF '%s' /var/lib/benchy /var/cache/benchy 2>/dev/null; true" % secret).strip()
            self.assertEqual(found, '', 'a secret was written to ' + found)
        # audit: a code row and an enrollment row, with no secrets in them
        created = [r for r in audit('agent_code_create') if r['SubjectID'] == 'EC%d' % made['id']]
        enrolled = audit('agent_enrolled', sid)
        self.assertEqual((len(created), len(enrolled)), (1, 1))
        self.assertTrue(json.loads(enrolled[0]['Detail'])['new_system'])
        for r in created + enrolled:
            self.assertNotIn(body['token'], r['Detail'] or '')
            self.assertNotIn(made['code'], r['Detail'] or '')

    def test_the_old_enrol_path_is_an_alias_and_code_problems_never_404(self):
        # Engine 0.3.0 tries /enroll and goes to /enrol only on a 404, so no code problem may look like a missing path
        made = new_code('qc alias rig')
        status, body, _ = call('/api/v2/enrol', enroll_body(made['code'], 'e'))
        self.assertEqual((status, check('enroll_response', body)), (201, []))
        for path in ('/api/v2/enroll', '/api/v2/enrol'):
            status, body, _ = call(path, enroll_body(made['code'], 'e'))
            self.assertEqual((status, body['error']['code']), (401, 'code_used'), path)
            status, body, _ = call(path, enroll_body('ben_' + 'Y' * 43))
            self.assertEqual((status, body['error']['code']), (401, 'code_invalid'), path)
            self.assertEqual(call(path, enroll_body('ben_short'))[0], 400, path)

    def test_a_code_works_once(self):
        made = new_code()
        self.assertEqual(call('/api/v2/enroll', enroll_body(made['code']))[0], 201)
        status, body, _ = call('/api/v2/enroll', enroll_body(made['code']))
        self.assertEqual((status, body['error']['code']), (401, 'code_used'))
        self.assertEqual(check('error_reply', body), [])

    def test_unknown_revoked_and_expired_codes_are_refused_with_their_own_codes(self):
        status, body, _ = call('/api/v2/enroll', enroll_body('ben_' + 'Z' * 43))
        self.assertEqual((status, body['error']['code']), (401, 'code_invalid'))
        revoked = new_code()
        self.assertEqual(admin('revoke-code', AID, revoked['id']), {'revoked': revoked['id']})
        self.assertEqual(call('/api/v2/enroll', enroll_body(revoked['code']))[1]['error']['code'], 'code_invalid')
        expired = new_code()
        self.assertEqual(sql_exec("UPDATE phoromatic_enrol_codes SET ExpiresOn='2020-01-01 00:00:00' WHERE CodeID=%d" % expired['id']), 'ok')
        self.assertEqual(call('/api/v2/enroll', enroll_body(expired['code']))[1]['error']['code'], 'code_expired')
        # none of the refusals used the codes up or made a system
        self.assertEqual(sql("SELECT Uses FROM phoromatic_enrol_codes WHERE CodeID=%d" % expired['id'])[0]['Uses'], 0)

    def test_a_malformed_enroll_body_is_a_bad_request_not_a_failed_attempt(self):
        b = enroll_body('ben_short')
        status, body, _ = call('/api/v2/enroll', b)
        self.assertEqual((status, body['error']['code']), (400, 'bad_request'))
        b = enroll_body(new_code()['code'])
        b['machine']['machine_id'] = 'nothex'
        self.assertEqual(call('/api/v2/enroll', b)[0], 400)

    def test_enrolling_an_existing_system_keeps_its_id_flips_the_policy_and_revokes_the_old_token(self):
        msi, sid = new_system(state=0)
        before = system_row(sid)
        status, first, _ = call('/api/v2/enroll', enroll_body(new_code('qc existing', sid, 'native')['code'], 'c'))
        self.assertEqual((status, first['system']['id']), (201, sid))
        row = system_row(sid)
        self.assertEqual((row['State'], row['EnginePolicy'], row['MachineSelfID']), (1, 'native', before['MachineSelfID']))
        self.assertEqual(row['Title'], before['Title'], 'an existing system keeps its title')
        # a second enrollment (another machine, or a rotation) revokes the first token
        status, second, _ = call('/api/v2/enroll', enroll_body(new_code('qc rotate', sid, None)['code'], 'd'))
        self.assertEqual(status, 201)
        self.assertEqual(second['system']['id'], sid)
        self.assertEqual(row['EnginePolicy'], system_row(sid)['EnginePolicy'], 'no policy given: the policy is left alone')
        live = sql("SELECT * FROM phoromatic_system_tokens WHERE SystemID='%s' AND RevokedOn IS NULL" % sid)
        self.assertEqual(len(live), 1, 'one live token per system')
        old = sql("SELECT RevokeReason FROM phoromatic_system_tokens WHERE SystemID='%s' AND RevokedOn IS NOT NULL" % sid)
        self.assertEqual([r['RevokeReason'] for r in old], ['re-enrolled'])
        status, body, _ = call('/api/v2/agent/poll', poll_body('c'), token=first['token'])
        self.assertEqual((status, body['error']['code']), (401, 'token_revoked'))
        self.assertEqual(call('/api/v2/agent/poll', poll_body('d'), token=second['token'])[0], 200)
        self.assertEqual(len(audit('agent_enrolled', sid)), 2)

    def test_a_code_for_a_system_of_another_account_or_a_deleted_one_cannot_be_made(self):
        other = make_other_account()
        self.addCleanup(remove_account, other)
        self.assertEqual(sql_exec("INSERT INTO phoromatic_systems (AccountID, SystemID, Title, State) VALUES ('%s', 'QCX%s', 'other', 1)" % (other, other[-4:])), 'ok')
        self.assertIn('error', admin('code', AID, 'cross', 'QCX' + other[-4:], 'native'))
        # and the other account's own admin can
        self.assertIn('code', admin('code', other, 'own', 'QCX' + other[-4:], 'native'))
        # a code made in one account never enrolls into another: enrolling it can only ever touch its own account
        code = admin('code', other, 'own2', '-', 'native')['code']
        status, body, _ = call('/api/v2/enroll', enroll_body(code))
        self.assertEqual(status, 201)
        self.assertEqual(body['account']['id'], other)
        self.assertEqual(system_row(body['system']['id']) is None, True, 'the system is not in this account')

    def test_the_label_is_checked(self):
        self.assertIn('error', admin('code', AID, '<script>', '-', 'native'))
        self.assertIn('error', admin('code', AID, '', '-', 'native'))
        self.assertIn('error', admin('code', AID, 'x' * 65, '-', 'native'))
        self.assertIn('error', admin('code', AID, 'ok', '-', 'ptsx'))


class TestTokens(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        allow_http(True)

    def test_bad_tokens_are_unauthenticated(self):
        for token in (None, 'bsa_' + 'A' * 43, 'bsa_short', 'bbt_' + 'A' * 43, 'Basic abc'):
            status, body, _ = call('/api/v2/agent/poll', poll_body(), token=token)
            self.assertEqual((status, body['error']['code']), (401, 'unauthenticated'), token)
            self.assertEqual(check('error_reply', body), [])

    def test_a_revoked_token_says_so_and_a_deleted_system_revokes_its_token(self):
        token, reply = enroll()
        sid = reply['system']['id']
        self.assertEqual(call('/api/v2/agent/poll', poll_body(), token=token)[0], 200)
        self.assertEqual(admin('revoke-token', AID, sid), {'revoked': 1})
        status, body, _ = call('/api/v2/agent/poll', poll_body(), token=token)
        self.assertEqual((status, body['error']['code']), (401, 'token_revoked'))
        self.assertEqual(len(audit('agent_token_revoke', sid)), 1)
        self.assertIn('error', admin('revoke-token', AID, sid), 'nothing left to revoke')
        token2, reply2 = enroll(system=sid)
        self.assertEqual(call('/api/v2/agent/poll', poll_body(), token=token2)[0], 200)
        self.assertTrue(admin('delete-system', AID, sid)['ok'])
        status, body, _ = call('/api/v2/agent/poll', poll_body(), token=token2)
        self.assertEqual((status, body['error']['code']), (401, 'token_revoked'))
        reasons = [r['Detail'] for r in audit('agent_token_revoke', sid)]
        self.assertTrue(any('system_deleted' in d for d in reasons))

    def test_only_an_approved_system_is_served_but_hello_is_answered(self):
        token, reply = enroll()
        sid = reply['system']['id']
        for state, approved in ((0, False), (-1, False)):
            self.assertEqual(sql_exec("UPDATE phoromatic_systems SET State=%d WHERE SystemID='%s'" % (state, sid)), 'ok')
            status, body, _ = call('/api/v2/agent/hello', hello_body(), token=token)
            self.assertEqual((status, body['system']['approved']), (200, approved))
            status, body, _ = call('/api/v2/agent/poll', poll_body(), token=token)
            self.assertEqual((status, body['error']['code']), (403, 'system_not_approved'))
            self.assertEqual(check('error_reply', body), [])
            self.assertEqual(call('/api/v2/jobs/1/events', {'events': []}, token=token)[0], 403)
        self.assertEqual(sql_exec("UPDATE phoromatic_systems SET State=1 WHERE SystemID='%s'" % sid), 'ok')
        self.assertEqual(call('/api/v2/agent/poll', poll_body(), token=token)[0], 200)

    def test_a_suspended_or_deleted_tenant_is_refused_when_tenancy_exists(self):
        token, reply = enroll()
        cols = {c['name'] for c in sql('PRAGMA table_info(phoromatic_accounts)')}
        if 'State' not in cols:
            self.assertEqual(sql_exec('ALTER TABLE phoromatic_accounts ADD COLUMN State TEXT'), 'ok')
        try:
            self.assertEqual(sql_exec("UPDATE phoromatic_accounts SET State='suspended' WHERE AccountID='%s'" % AID), 'ok')
            status, body, _ = call('/api/v2/agent/poll', poll_body(), token=token)
            self.assertEqual((status, body['error']['code']), (403, 'tenant_suspended'))
            self.assertEqual(sql_exec("UPDATE phoromatic_accounts SET State='deleted' WHERE AccountID='%s'" % AID), 'ok')
            status, body, _ = call('/api/v2/agent/poll', poll_body(), token=token)
            self.assertEqual((status, body['error']['code']), (401, 'unauthenticated'))
        finally:
            sql_exec("UPDATE phoromatic_accounts SET State=NULL WHERE AccountID='%s'" % AID)
        self.assertEqual(call('/api/v2/agent/poll', poll_body(), token=token)[0], 200, 'an account with no state is active')


class TestLockouts(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        allow_http(True)

    def test_ten_failed_enrollments_from_one_address_lock_that_address_out(self):
        # from inside the container (127.0.0.1), so the host's address, which every other test uses, stays free
        good = new_code()['code']
        for i in range(10):
            status, body, _ = call_local('/api/v2/enroll', enroll_body('ben_' + ('%043d' % i)))
            self.assertEqual((status, body['error']['code']), (401, 'code_invalid'))
        status, body, retry = call_local('/api/v2/enroll', enroll_body(good))
        self.assertEqual((status, body['error']['code']), (429, 'rate_limited'))
        self.assertIn('Too many failed enrollments', body['error']['message'])
        self.assertEqual(check('error_reply', body), [])
        self.assertTrue(retry and retry > 0, 'Retry-After is sent')
        self.assertEqual(call('/api/v2/enroll', enroll_body(good))[0], 201, 'another address is not affected, and the code was never spent')

    def test_twenty_bad_tokens_lock_the_address_out_of_every_call(self):
        token, reply = enroll()
        for i in range(20):
            status, body, _ = call_local('/api/v2/agent/poll', poll_body(), token='bsa_' + ('%043d' % i))
            self.assertEqual((status, body['error']['code']), (401, 'unauthenticated'))
        status, body, retry = call_local('/api/v2/agent/poll', poll_body(), token=token)
        self.assertEqual((status, body['error']['code']), (429, 'rate_limited'), 'even a good token, from that address')
        self.assertTrue(retry and retry > 0)
        self.assertEqual(call('/api/v2/agent/poll', poll_body(), token=token)[0], 200)

    def test_a_revoked_token_is_not_counted_as_an_attack(self):
        token, reply = enroll()
        admin('revoke-token', AID, reply['system']['id'])
        for i in range(25):
            self.assertEqual(call_local('/api/v2/agent/poll', poll_body(), token=token)[1]['error']['code'], 'token_revoked')


class TestHello(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        allow_http(True)

    def test_hello_stores_the_sysinfo_and_the_catalogue_and_answers_with_the_system(self):
        token, reply = enroll()
        sid = reply['system']['id']
        body = hello_body()
        body['system_info']['cpu']['model'] = 'Hello CPU'
        body['system_info']['summary']['hardware'] = 'Processor: Hello CPU, Memory: 8GB'
        body['engine']['version'] = '0.4.0'
        second = example('hello_request')['catalogue'][0]
        second = json.loads(json.dumps(second))
        second['test']['ref'] = 'benchy/stream'
        second['title'] = 'STREAM'
        body['catalogue'] = [example('hello_request')['catalogue'][0], second]
        status, r, _ = call('/api/v2/agent/hello', body, token=token)
        self.assertEqual(status, 200, r)
        self.assertEqual(check('hello_response', r), [])
        self.assertEqual((r['system']['id'], r['system']['policy'], r['system']['approved'], r['auth']), (sid, 'native', True, ['bearer']))
        row = system_row(sid)
        self.assertEqual((row['Hardware'], row['ClientVersion']), ('Processor: Hello CPU, Memory: 8GB', 'benchy-native 0.4.0'))
        self.assertEqual(json.loads(row['SystemProperties'])['benchy_system_info']['cpu']['model'], 'Hello CPU')
        cat = sql("SELECT Ref, Version, TestJSON FROM phoromatic_system_catalogue WHERE SystemID='%s' ORDER BY Ref" % sid)
        self.assertEqual([c['Ref'] for c in cat], ['benchy/c-ray', 'benchy/stream'])
        self.assertEqual(json.loads(cat[1]['TestJSON'])['title'], 'STREAM')
        # a later hello replaces the list
        body['catalogue'] = [second]
        call('/api/v2/agent/hello', body, token=token)
        self.assertEqual([c['Ref'] for c in sql("SELECT Ref FROM phoromatic_system_catalogue WHERE SystemID='%s'" % sid)], ['benchy/stream'])

    def test_a_bad_hello_body_is_a_bad_request_and_changes_nothing(self):
        token, reply = enroll()
        sid = reply['system']['id']
        before = system_row(sid)['Hardware']
        b = hello_body()
        del b['catalogue']
        b['system_info']['summary']['hardware'] = 'changed'
        status, body, _ = call('/api/v2/agent/hello', b, token=token)
        self.assertEqual((status, body['error']['code']), (400, 'bad_request'))
        self.assertEqual(system_row(sid)['Hardware'], before)

    def test_server_mirrors_are_https_on_the_servers_own_host_only(self):
        token, reply = enroll()
        out = srv_sh("""php -r 'define("PHOROMATIC_SERVER", true); define("PTS_MODE", "WEB_CLIENT"); define("PTS_AUTO_LOAD_OBJECTS", true); include("/bench/pts-core/pts-core.php"); pts_core::init(); phoromatic_server::prepare_database();
            $_SERVER["HTTP_HOST"] = "bench.example:8443";
            phoromatic_server::save_setting("v2_mirrors", array("https://bench.example/mirror/", "https://bench.example:8443/m", "http://bench.example/plain", "https://elsewhere.example/x", "file:///etc", "//unc/share", "https://bench.example/a;b", "https://user@bench.example/x"));
            echo json_encode(phoromatic_api_v2::mirrors()); phoromatic_server::save_setting("v2_mirrors", null);'""")
        self.assertEqual(json.loads(out[out.index('['):]), ['https://bench.example/mirror/', 'https://bench.example:8443/m'])
        status, r, _ = call('/api/v2/agent/hello', hello_body(), token=token)
        self.assertEqual(r['mirrors'], [], 'none configured: none offered')


if __name__ == '__main__':
    unittest.main()
