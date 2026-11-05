"""Native agent API (Benchy 0.9.4): the pinned protocol text against what the server really does.

tests/private-repo/test_native_*.py already drive every endpoint, rule and error (enrollment, lockout, jobs, trigger rules, results, the
converter goldens). This file adds only what none of them check: that the three places that describe the API agree with each other, and
with the server's replies.

  * the error table in README.md, the server_error_code list in v2.schema.json and the codes the server's source can send are the same set
    (the README promises "the server sends only the codes in this table");
  * every example in examples/ is valid against the definition it is named after, and the replies the server gives to the example
    requests are valid against the reply definitions;
  * each row of the table is paired with the status it is documented under, both in the table and in the live replies for the codes that
    can be reached with a plain call.

Run through tests/private-repo/run.sh (part of http/all). Uses the harness helpers in tests/private-repo/.
"""
import json
import os
import re
import sys
import unittest

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.join(HERE, '..', 'private-repo'))

from test_private_repo_http import AID  # noqa: E402
from v2_helpers import EXAMPLES, admin, allow_http, call, check, enroll, enroll_body, hello_body, new_code, poll_body  # noqa: E402

STATIC = os.path.join(HERE, '..', '..', 'pts-core', 'static', 'protocol-v2')
SOURCES = [os.path.join(HERE, '..', '..', 'pts-core', 'objects', 'phoromatic', f) for f in ('phoromatic_api_v2.php', 'phoromatic_agent_auth.php')]
# the code each example file is a body of, and the schema definition it has to satisfy
EXAMPLE_DEFS = {'enroll_request': 'enroll_request', 'enroll_response': 'enroll_response', 'hello_request': 'hello_request', 'hello_response': 'hello_response',
                'poll_request': 'poll_request', 'poll_response': 'poll_response', 'events_request': 'events_request', 'events_response': 'events_response',
                'result_response': 'result_response', 'end_request': 'end_request', 'end_response': 'end_response', 'error_reply': 'error_reply'}


def read(path):
    with open(path) as f:
        return f.read()


def readme_errors():
    """{code: set of HTTP statuses} from the README's Errors table"""
    text = read(os.path.join(STATIC, 'README.md'))
    table = text.split('## Errors', 1)[1].split('\n\n', 2)[1]
    out = {}
    for line in table.splitlines()[2:]:
        cells = [c.strip() for c in line.strip('|').split('|')]
        status = cells[0]
        for code in re.findall(r'`([a-z][a-z0-9_]*)`', cells[1].replace('on `enroll` only', 'on enroll only')):
            out.setdefault(code, set()).add(status)
    return out


def source_errors():
    """{code: set of HTTP statuses} found in the server's source, from fail(), the auth arrays and refuse() calls"""
    out = {}
    pattern = re.compile(r"(?:fail\(|array\(|refuse\()\s*(\d{3}),\s*'([a-z][a-z0-9_]*)'|'code'\s*=>\s*'([a-z][a-z0-9_]*)'")
    for path in SOURCES:
        for status, code, bare in pattern.findall(read(path)):
            if code:
                out.setdefault(code, set()).add(status)
            elif bare:
                out.setdefault(bare, set())
    return out


class TestTheThreeDescriptionsAgree(unittest.TestCase):
    def test_the_readme_table_the_schema_list_and_the_server_source_name_the_same_codes(self):
        doc = readme_errors()
        schema = json.loads(read(os.path.join(STATIC, 'v2.schema.json')))['$defs']['server_error_code']['enum']
        src = source_errors()
        self.assertEqual(set(doc), set(schema), 'README table vs v2.schema.json server_error_code')
        self.assertEqual(set(src), set(schema), 'what the source can send vs the schema list')

    def test_every_status_the_source_sends_a_code_under_is_one_the_readme_gives_it(self):
        doc = readme_errors()
        for code, statuses in source_errors().items():
            for status in statuses:
                allowed = doc[code]
                if code == 'server_error':
                    self.assertTrue(status.startswith('5'), (code, status))
                else:
                    self.assertIn(status, allowed, '%s is sent under %s but the README gives %s' % (code, status, sorted(allowed)))


class TestExamples(unittest.TestCase):
    def test_every_example_is_valid_for_the_definition_it_is_named_after(self):
        names = {f[:-5] for f in os.listdir(EXAMPLES) if f.endswith('.json')}
        self.assertEqual(names, set(EXAMPLE_DEFS), 'a new example needs a line in EXAMPLE_DEFS')
        for name, definition in sorted(EXAMPLE_DEFS.items()):
            body = json.loads(read(os.path.join(EXAMPLES, name + '.json')))
            self.assertEqual(check(definition, body), [], name)


class TestRepliesAreValidForTheirDefinitions(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        allow_http(True)

    @classmethod
    def tearDownClass(cls):
        allow_http(False)

    def test_the_replies_to_the_example_calls_validate_and_carry_the_envelope(self):
        code = new_code()['code']
        status, body, _ = call('/api/v2/enroll', enroll_body(code))
        self.assertEqual(status, 201, body)
        self.assertEqual(check('enroll_response', body), [])
        token = body['token']
        for path, request, definition in (('/api/v2/agent/hello', hello_body(), 'hello_response'), ('/api/v2/agent/poll', poll_body(), 'poll_response')):
            status, body, _ = call(path, request, token=token)
            self.assertEqual(status, 200, (path, body))
            self.assertEqual(check(definition, body), [], path)
            self.assertEqual(body['api'], 2)

    def test_the_documented_status_and_code_pairs_that_a_plain_call_reaches(self):
        token, reply = enroll()
        sid = reply['system']['id']
        revoked_token, revoked_reply = enroll()
        admin('revoke-token', AID, revoked_reply['system']['id'])
        used = new_code()['code']
        self.assertEqual(call('/api/v2/enroll', enroll_body(used))[0], 201)
        expired = new_code()
        from test_private_repo_tokens import sql_exec
        sql_exec("UPDATE phoromatic_enrol_codes SET ExpiresOn='2020-01-01 00:00:00' WHERE CodeID=%d" % expired['id'])
        cases = (
            (400, 'bad_request', lambda: call('/api/v2/agent/poll', raw=b'[]', token=token)),
            (401, 'unauthenticated', lambda: call('/api/v2/agent/poll', poll_body(), token='bsa_' + 'A' * 43)),
            (401, 'token_revoked', lambda: call('/api/v2/agent/poll', poll_body(), token=revoked_token)),
            (401, 'code_invalid', lambda: call('/api/v2/enroll', enroll_body('ben_' + 'Y' * 43))),
            (401, 'code_expired', lambda: call('/api/v2/enroll', enroll_body(expired['code']))),
            (401, 'code_used', lambda: call('/api/v2/enroll', enroll_body(used, machine_seed='b'))),
            (404, 'not_found', lambda: call('/api/v2/agent/nothing', {}, token=token)),
            (404, 'not_found', lambda: call('/api/v2/jobs/2147483000/events', {'events': []}, token=token)),
            (413, 'too_large', lambda: call('/api/v2/enroll', raw=b'{"x": "' + b'a' * (1024 * 1024 + 10) + b'"}')),
        )
        doc = readme_errors()
        for status, code, make in cases:
            got_status, got_body, _ = make()
            self.assertEqual((got_status, got_body['error']['code']), (status, code))
            self.assertEqual(check('error_reply', got_body), [], code)
            self.assertEqual(got_body['api'], 2)
            self.assertIn(str(status), doc[code], '%s: the README does not list it under %d' % (code, status))
        # the plain-HTTP refusal needs the switch off
        allow_http(False)
        try:
            got_status, got_body, _ = call('/api/v2/agent/poll', poll_body(), token=token)
            self.assertEqual((got_status, got_body['error']['code']), (426, 'https_required'))
            self.assertEqual(check('error_reply', got_body), [])
        finally:
            allow_http(True)
        self.assertTrue(sid)


if __name__ == '__main__':
    unittest.main()
