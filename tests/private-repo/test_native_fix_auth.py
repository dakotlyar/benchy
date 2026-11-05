"""Native agents, the delta review of the fourth build round (Benchy 0.9.4), enrollment and lockouts: a failure counter that cannot be used
neither crashes nor opens the door, a development database that lacks the counter table gets it, the audit trail has a cap across all
addresses, and the enroll reply always has an account name.

Run through tests/private-repo/run.sh (part of http/all).
"""
import json
import unittest

from test_native_enroll import audit
from test_private_repo_http import AID, sql
from test_private_repo_tokens import sql_exec
from v2_helpers import Sidecar, allow_http, call, call_local, enroll, enroll_body, new_code, poll_body, server_log_problems


def setUpModule():
    allow_http(True)


def tearDownModule():
    allow_http(False)
    problems = server_log_problems()
    assert not problems, 'PHP complained while serving the v2 API: %s' % problems


BAD = 'bsa_' + 'Z' * 43


class TestCounterTable(unittest.TestCase):
    def test_a_database_without_the_counter_table_gets_it_on_the_next_request(self):
        self.assertEqual(sql_exec('DROP TABLE phoromatic_v2_failures'), 'ok')
        status, body, _ = call('/api/v2/agent/poll', poll_body(), token=BAD)
        self.assertEqual((status, body['error']['code']), (401, 'unauthenticated'))
        names = {r['name'] for r in sql('SELECT name FROM sqlite_master')}
        self.assertTrue({'phoromatic_v2_failures', 'phoromatic_v2_failures_ip', 'phoromatic_v2_failures_at'} <= names)
        self.assertGreaterEqual(sql("SELECT COUNT(*) AS n FROM phoromatic_v2_failures WHERE Kind='auth'")[0]['n'], 1, 'and the failure was counted in it')


class TestCounterThatCannotBeUsed(unittest.TestCase):
    """The table is swapped for a view for the length of the test, which a start-up check cannot repair, so the counter is unusable"""

    def swap(self, view_sql):
        self.addCleanup(self.restore)
        self.assertEqual(sql_exec('ALTER TABLE phoromatic_v2_failures RENAME TO qc_failures_away'), 'ok')
        self.assertEqual(sql_exec(view_sql), 'ok')

    def restore(self):
        sql_exec('DROP VIEW phoromatic_v2_failures')
        sql_exec('ALTER TABLE qc_failures_away RENAME TO phoromatic_v2_failures')

    def test_a_counter_that_cannot_be_written_is_a_401_not_a_crash(self):
        self.swap("CREATE VIEW phoromatic_v2_failures AS SELECT 'x' AS Kind, 'x' AS SourceIP, 0 AS At WHERE 0")
        with Sidecar() as side:
            status, body = side.call('/api/v2/agent/poll', poll_body(), token=BAD)
            self.assertEqual((status, body['error']['code']), (401, 'unauthenticated'))
            status, body = side.call('/api/v2/enroll', enroll_body('ben_' + 'Q' * 43))
            self.assertEqual((status, body['error']['code']), (401, 'code_invalid'))
            self.assertNotIn('uncaught', side.logged())

    def test_a_counter_that_cannot_be_read_keeps_the_address_out(self):
        self.swap('CREATE VIEW phoromatic_v2_failures AS SELECT 1 AS Foo')
        with Sidecar() as side:
            status, body = side.call('/api/v2/agent/poll', poll_body(), token=BAD)
            self.assertEqual((status, body['error']['code']), (429, 'rate_limited'), 'closed, not open')
            status, body = side.call('/api/v2/enroll', enroll_body('ben_' + 'Q' * 43))
            self.assertEqual((status, body['error']['code']), (429, 'rate_limited'))


class TestAuditCap(unittest.TestCase):
    def tearDown(self):
        sql_exec("DELETE FROM phoromatic_v2_failures WHERE SourceIP IN ('*', '127.0.0.1')")

    def test_above_the_cap_failures_are_counted_but_not_audited_and_one_summary_row_is_written(self):
        CAP = 30  # phoromatic_agent_auth::AUDIT_CAP
        sql_exec("DELETE FROM phoromatic_v2_failures WHERE SourceIP IN ('*', '127.0.0.1')")
        now = int(__import__('time').time())
        for _ in range(CAP):
            sql_exec("INSERT INTO phoromatic_v2_failures (Kind, SourceIP, At) VALUES ('audited', '*', %d)" % now)
        audited = len(audit('agent_enroll_failed')) + len(audit('agent_auth_failed'))
        summaries = len(audit('agent_failures_capped'))
        for i in range(3):
            sql_exec("DELETE FROM phoromatic_v2_failures WHERE SourceIP = '127.0.0.1'")  # a first failure of its window each time, which is normally audited
            status, body, _ = call_local('/api/v2/enroll', enroll_body('ben_' + ('%043d' % (900 + i))))
            self.assertEqual((status, body['error']['code']), (401, 'code_invalid'))
        self.assertEqual(len(audit('agent_enroll_failed')) + len(audit('agent_auth_failed')), audited, 'none of the three was audited')
        self.assertEqual(len(audit('agent_failures_capped')) - summaries, 1, 'one summary row for the window')
        self.assertEqual(sql("SELECT COUNT(*) AS n FROM phoromatic_v2_failures WHERE Kind='capped'")[0]['n'], 3, 'but all three were counted')
        # the lockout of the address does not depend on the audit
        for i in range(10):
            call_local('/api/v2/enroll', enroll_body('ben_' + ('%043d' % (950 + i))))
        self.assertEqual(call_local('/api/v2/enroll', enroll_body(new_code()['code']))[0], 429)

    def test_under_the_cap_the_first_failure_is_still_audited(self):
        sql_exec("DELETE FROM phoromatic_v2_failures WHERE SourceIP IN ('*', '127.0.0.1')")
        before = len(audit('agent_auth_failed'))
        call_local('/api/v2/agent/poll', poll_body(), token=BAD)
        self.assertEqual(len(audit('agent_auth_failed')) - before, 1)


class TestAccountName(unittest.TestCase):
    def test_the_enroll_reply_names_the_account_even_when_it_has_no_group_name(self):
        old = sql("SELECT GroupName FROM phoromatic_accounts WHERE AccountID='%s'" % AID)[0]['GroupName']
        self.addCleanup(sql_exec, "UPDATE phoromatic_accounts SET GroupName=%s WHERE AccountID='%s'" % ("'%s'" % old if old is not None else 'NULL', AID))
        sql_exec("UPDATE phoromatic_accounts SET GroupName='' WHERE AccountID='%s'" % AID)
        token, reply = enroll()
        self.assertEqual(reply['account'], {'id': AID, 'name': AID})
        sql_exec("UPDATE phoromatic_accounts SET GroupName='QC Group' WHERE AccountID='%s'" % AID)
        token, reply = enroll()
        self.assertEqual(reply['account']['name'], 'QC Group')


if __name__ == '__main__':
    unittest.main()
