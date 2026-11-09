"""Request-derived names that become file names (N3, N4, N15): a log, a suite and two uploads must not reach another account's folder.
The names are decoded first and then checked; a filter on the raw request URI sees neither %2e%2e%2f nor a POST body."""
import os
import unittest

import tn

UP = '../../../'


class StressLogViewer(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.st = tn.state()
        cls.b = tn.logins('T1')
        cls.t1, cls.t2 = cls.st['T1'], cls.st['T2']
        cls.page = '/?benchmark/%d' % cls.t1['ticket']
        cls.attack = UP + '%s/stress-logs/%d/%s' % (cls.t2['aid'], cls.t2['ticket'], cls.t2['system'])

    def test_own_log_is_shown(self):
        # the control: the viewer works, so the refusals below are not an empty page
        for lvl in (1, 2, 3):
            self.assertIn('TN1_FILE stress log', self.b[lvl].get(self.page + '/&view_log=' + self.t1['system'])[2])

    def test_another_accounts_log_is_not_readable_by_any_spelling(self):
        for lvl in (1, 2, 3):
            with self.subTest(level=lvl):
                tries = [
                    self.b[lvl].get(self.page + '/&view_log=' + tn.pct(self.attack))[2],
                    self.b[lvl].get(self.page + '/&view_log=' + tn.pct(self.attack).replace('%2f', '%2F'))[2],
                    self.b[lvl].post(self.page, {'view_log': self.attack})[2],
                    self.b[lvl].post(self.page + '/', {'view_log': '/var/lib/benchy/phoromatic/accounts/%s/stress-logs/%d/%s' % (self.t2['aid'], self.t2['ticket'], self.t2['system'])})[2],
                    self.b[lvl].get(self.page + '/&view_log=' + tn.pct('..%2f' * 3 + self.t2['aid'] + '/stress-logs/%d/%s' % (self.t2['ticket'], self.t2['system'])))[2],
                ]
                for body in tries:
                    self.assertNotIn('CANARY_T2', body)

    def test_a_ticket_name_that_decodes_to_a_path_reads_nothing(self):
        # the ticket comes from the raw URI: %2e%2e is a name there, not ".."
        body = self.b[1].get('/?benchmark/' + tn.pct('..') + '/&view_log=' + self.t1['system'])[2]
        self.assertNotIn('CANARY_T2', body)
        self.assertNotIn('TN1_FILE stress log', body)


class SuiteNames(unittest.TestCase):
    def test_find_suite_file_will_not_leave_the_account_or_the_official_suites(self):
        st = tn.state()
        t1, t2 = st['T1']['aid'], st['T2']['aid']
        code = ("foreach(array('../../%(t2)s/suites/%(s2)s', '%(t2)s/../../%(t2)s/suites/%(s2)s', '/etc/passwd', 'a/b/c', '..', '.', '', '%(s1)s/..', %(arr)s, 'pts/../../%(t2)s/suites/%(s2)s') as $n)"
                "{ echo var_export(phoromatic_server::find_suite_file('%(t1)s', $n), true), \"\\n\"; }"
                "echo 'own:', var_export(is_string(phoromatic_server::find_suite_file('%(t1)s', '%(s1)s')), true), \"\\n\";") % {
                    't1': t1, 't2': t2, 's1': st['T1']['suite'], 's2': st['T2']['suite'], 'arr': "array('x')"}
        lines = tn.php_boot(code).strip().splitlines()
        self.assertEqual(lines[-1], 'own:true', lines)
        self.assertEqual(set(lines[:-1]), {'false'}, lines)

    def test_suite_add_with_another_accounts_suite_adds_nothing(self):
        st = tn.state()
        b = tn.logins('T1')[1]
        sched = '/?schedules/%d' % tn.SHARED_SCHEDULE
        before = tn.sql("SELECT COUNT(*) AS n FROM phoromatic_schedules_tests WHERE AccountID='%s'" % st['T1']['aid'])[0]['n']
        for name in ('../../%s/suites/%s' % (st['T2']['aid'], st['T2']['suite']), '%s/../../%s/suites/%s' % (st['T1']['suite'], st['T2']['aid'], st['T2']['suite'])):
            body = b.post(sched, {'suite_add': name, 'token_submit': b.token(sched)})[2]
            self.assertNotIn('CANARY_T2', body)
        after = tn.sql("SELECT COUNT(*) AS n FROM phoromatic_schedules_tests WHERE AccountID='%s'" % st['T1']['aid'])[0]['n']
        self.assertEqual(before, after)
        self.assertEqual(tn.sql("SELECT COUNT(*) AS n FROM phoromatic_schedules_tests WHERE TestDescription LIKE '%CANARY_T2%' OR TestProfile LIKE '%CANARY_T2%'")[0]['n'], 0)

    def test_the_suite_fragment_page_gives_nothing_for_another_accounts_suite(self):
        st = tn.state()
        b = tn.logins('T1')[1]
        for ts in ('../../%s/suites/%s' % (st['T2']['aid'], st['T2']['suite']), tn.pct('../../%s/suites/%s' % (st['T2']['aid'], st['T2']['suite']))):
            self.assertNotIn('CANARY_T2', b.get('/?r_basic_suite_details&ts=' + ts)[2])


class ClientUploads(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.st = tn.state()
        cls.t1, cls.t2 = cls.st['T1'], cls.st['T2']
        cls.msi = tn.approved_machine(cls.t1['aid'])
        cls.acc = tn.STORAGE + '/accounts/'

    def exists(self, path):
        return tn.srv_sh("test -e '%s' && echo yes" % path).strip() == 'yes'

    def test_log_archive_upload_goes_to_the_clients_own_result_only(self):
        zip_text = 'PK-tn-%s' % tn.uuid.uuid4().hex
        fields = {'system_logs_type': 'installation-logs', 'system_logs_zip': tn.base64.b64encode(zip_text.encode()).decode()}
        fields['system_logs_hash'] = tn.sha1(fields['system_logs_zip'].encode())
        own = tn.v1('result_log_upload', self.t1['aid'], self.msi, i=str(self.t1['upload']), **fields)
        self.assertEqual(own.get('uploaded'), 1, own)
        self.assertTrue(self.exists('%s%s/results/%d/installation-logs.zip' % (self.acc, self.t1['aid'], self.t1['upload'])))
        for i in ('../../%s/results/%d' % (self.t2['aid'], self.t2['upload']), '../%s/results/%d' % (self.t1['aid'], self.t1['upload']), '..', '.', '%s/../%s' % (self.t1['upload'], self.t1['upload'])):
            with self.subTest(i=i):
                r = tn.v1('result_log_upload', self.t1['aid'], self.msi, i=i, **fields)
                self.assertNotEqual(r.get('uploaded'), 1, r)
        self.assertFalse(self.exists('%s%s/results/%d/installation-logs.zip' % (self.acc, self.t2['aid'], self.t2['upload'])))

    def test_stress_log_upload_creates_folders_in_the_clients_own_account_only(self):
        own = tn.v1('stress_log_upload', self.t1['aid'], self.msi, bid='555', l='tn log line')
        self.assertEqual(own.get('response'), 'Log Updated', own)
        self.assertTrue(self.exists('%s%s/stress-logs/555' % (self.acc, self.t1['aid'])))
        for bid in ('../../%s/stress-logs/tnevil' % self.t2['aid'], '..', '.', '../tnevil2'):
            with self.subTest(bid=bid):
                r = tn.v1('stress_log_upload', self.t1['aid'], self.msi, bid=bid, l='tn log line')
                self.assertNotEqual(r.get('response'), 'Log Updated', r)
        self.assertFalse(self.exists('%s%s/stress-logs/tnevil' % (self.acc, self.t2['aid'])))
        self.assertFalse(self.exists('%s%s/stress-logs/tnevil2' % (self.acc, self.t1['aid'])))


if __name__ == '__main__':
    unittest.main()
