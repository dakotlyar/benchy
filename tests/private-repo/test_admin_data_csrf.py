"""Root-only deletes on the Phoromatic Server Data page (results, schedules, tickets, triggers) are POST + CSRF token + same-origin only.

Run through tests/private-repo/run.sh (http). The root-admin password is set on the throw-away server with the server's own command.
"""
import os
import re
import subprocess
import unittest
import uuid

from test_private_repo_http import AID, SRV, sql
from test_private_repo_tokens import sql_exec
from test_private_repo_web import Browser

ROOT_PW = 'qcrootpass1'
DATA = '/?admin_data'


def row_exists(table, where):
    return len(sql('SELECT 1 AS x FROM %s WHERE %s' % (table, where))) > 0


class AdminDataDeletes(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        run = subprocess.run(['docker', 'exec', '-i', SRV, 'sh', '/bench/benchy', 'phoromatic.set-root-admin-password'], input=ROOT_PW + '\n', capture_output=True, text=True)
        # the twin server with display_errors off: the root login regenerates the session id, which PHP warnings printed first would break
        cls.root = Browser('http://127.0.0.1:%s' % os.environ['QC_PORT2'])
        cls.root.get('/')
        # Without output buffering (no php.ini here) the redirect after a root login cannot be sent, but the session is set: ask for the page
        cls.root.post('/?login', {'username': 'rootadmin', 'password': ROOT_PW})
        if 'Phoromatic Server Data' not in cls.root.get(DATA)[2]:
            raise unittest.SkipTest('root login is not available here: ' + (run.stdout + run.stderr)[-200:])

    def setUp(self):
        n = uuid.uuid4().int % 900000 + 100000
        self.sid, self.tid, self.trig, self.ppr = n, n + 1, 'qctrig' + str(n), 'QCPPR' + str(n)
        sql_exec("INSERT INTO phoromatic_schedules (AccountID, ScheduleID, Title, State, ActiveOn, RunAt) VALUES ('%s', %d, 'qc sched', 1, '1,2,3', '00.00')" % (AID, self.sid))
        sql_exec("INSERT INTO phoromatic_benchmark_tickets (AccountID, TicketID, TicketIssueTime, Title, State) VALUES ('%s', %d, '2026-01-01 00:00:00', 'qc ticket', 1)" % (AID, self.tid))
        sql_exec("INSERT INTO phoromatic_schedules_triggers (AccountID, ScheduleID, Trigger, TriggeredOn) VALUES ('%s', %d, '%s', '2026-01-01 00:00:00')" % (AID, self.sid, self.trig))
        sql_exec("INSERT INTO phoromatic_results (AccountID, UploadID, PPRID, UploadTime, Title, SystemID) VALUES ('%s', %d, '%s', '2026-01-01 00:00:00', 'qc result', 'x')" % (AID, self.tid, self.ppr))

    def targets(self):
        return [('schedule', '/?admin_data/delete/schedule/%s/%d' % (AID, self.sid), 'phoromatic_schedules', "ScheduleID=%d" % self.sid),
                ('ticket', '/?admin_data/delete/ticket/%s/%d' % (AID, self.tid), 'phoromatic_benchmark_tickets', "TicketID=%d" % self.tid),
                ('trigger', '/?admin_data/delete/trigger/%s/%d/%s' % (AID, self.sid, self.trig), 'phoromatic_schedules_triggers', "Trigger='%s'" % self.trig),
                ('result', '/?admin_data/delete/result/' + self.ppr, 'phoromatic_results', "PPRID='%s'" % self.ppr)]

    def test_a_plain_link_or_image_tag_deletes_nothing(self):
        for kind, path, table, where in self.targets():
            with self.subTest(kind):
                body = self.root.get(path)[2]
                self.assertIn('Nothing was changed', body)
                self.assertTrue(row_exists(table, where))

    def test_post_without_or_with_a_wrong_token_or_from_another_origin_deletes_nothing(self):
        tok = re.search(r'name="token_submit" value="([0-9a-f]+)"', self.root.get(DATA)[2]).group(1)
        for kind, path, table, where in self.targets():
            for label, data, headers in (('no token', {}, {}), ('wrong token', {'token_submit': '0' * len(tok)}, {}),
                                         ('other origin', {'token_submit': tok}, {'Origin': 'http://evil.example'})):
                with self.subTest(kind + ' ' + label):
                    body = self.root.post(path, data, headers=headers)[2]
                    self.assertIn('Nothing was changed', body)
                    self.assertTrue(row_exists(table, where))

    def test_post_with_the_token_deletes_and_the_page_offers_post_forms(self):
        page = self.root.get(DATA)[2]
        tok = re.search(r'name="token_submit" value="([0-9a-f]+)"', page).group(1)
        for kind in ('schedule', 'ticket', 'trigger'):
            self.assertRegex(page, r'<form method="post"[^>]*action="/\?admin_data/delete/%s/' % kind)
        self.assertNotRegex(page, r'href="/\?admin_data/delete/')
        self.assertIn('phoromatic_admin_delete_results', page)
        for kind, path, table, where in self.targets():
            with self.subTest(kind):
                self.root.post(path, {'token_submit': tok})
                self.assertFalse(row_exists(table, where))
