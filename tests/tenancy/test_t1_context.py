"""T1: phoromatic_tenant_context is read. Every account file path is built by phoromatic_server::phoromatic_account_path(), which refuses an account the
web request is not working for (nothing set means nothing allowed); a command run on the server host is the operator and is not held to it.
Also segment(): Windows reserved names and trailing dots."""
import unittest

import tn

BOOT_OK = 'OK'


def web(code, level=None):
    return tn.php_web_boot("function t($f) { try { $f(); return 'allowed'; } catch(RuntimeException $e) { return 'refused'; } } " + code, level)


class ContextIsEnforced(unittest.TestCase):
    def setUp(self):
        st = tn.state()
        self.t1, self.t2 = st['T1']['aid'], st['T2']['aid']

    def test_nothing_set_means_nothing_allowed(self):
        out = web("echo t(function() { phoromatic_server::phoromatic_account_path('%s'); }), ' ', t(function() { phoromatic_server::phoromatic_account_result_path('%s', 1); }), ' ', "
                  "t(function() { phoromatic_server::find_suite_file('%s', 'x-1.0.0'); });" % (self.t1, self.t1, self.t1))
        self.assertEqual(out.strip(), 'refused refused refused')

    def test_the_request_account_is_allowed_and_no_other(self):
        out = web("phoromatic_tenant_context::set('%s'); echo t(function() { phoromatic_server::phoromatic_account_path('%s'); }), ' ', t(function() { phoromatic_server::phoromatic_account_system_path('%s', 'S'); }), ' ', "
                  "t(function() { phoromatic_server::phoromatic_account_path('%s'); });" % (self.t1, self.t1, self.t2, self.t2))
        self.assertEqual(out.strip(), 'allowed refused refused')

    def test_a_named_second_account_is_allowed_and_setting_the_context_again_forgets_it(self):
        out = web("phoromatic_tenant_context::set('%s'); phoromatic_tenant_context::allow('%s'); $a = t(function() { phoromatic_server::phoromatic_account_path('%s'); }); "
                  "phoromatic_tenant_context::set('%s'); echo $a, ' ', t(function() { phoromatic_server::phoromatic_account_path('%s'); });" % (self.t1, self.t2, self.t2, self.t1, self.t2))
        self.assertEqual(out.strip(), 'allowed refused')

    def test_a_folder_scoped_allowance_opens_that_folder_and_no_other(self):
        # the first account's private repository is served to every account until T3: only its private-repo folder
        out = web("phoromatic_tenant_context::set('%s'); phoromatic_tenant_context::allow('%s', 'private-repo'); "
                  "echo t(function() { phoromatic_server::phoromatic_account_path('%s', 'private-repo'); }), ' ', t(function() { phoromatic_server::phoromatic_account_path('%s'); }), ' ', "
                  "t(function() { phoromatic_server::phoromatic_account_result_path('%s', 1); }), ' ', t(function() { phoromatic_server::phoromatic_account_path('%s', 'other'); });" % (self.t1, self.t2, self.t2, self.t2, self.t2, self.t2))
        self.assertEqual(out.strip(), 'allowed refused refused refused')

    def test_the_first_accounts_repository_is_open_to_a_request_of_another_account_but_only_that_folder(self):
        out = web("phoromatic_tenant_context::set('%s'); $first = phoromatic_private_repo::server_account_id(); echo $first, ' ', "
                  "t(function() use ($first) { phoromatic_server::phoromatic_account_path($first, 'private-repo'); }), ' ', t(function() use ($first) { phoromatic_server::phoromatic_account_result_path($first, 1); }), ' ', "
                  "t(function() use ($first) { phoromatic_server::phoromatic_account_system_path($first, 'S'); });" % self.t2)
        self.assertEqual(out.strip(), '%s allowed refused refused' % self.t1)

    def test_a_value_that_is_not_a_path_segment_sets_nothing(self):
        out = web("var_export(phoromatic_tenant_context::set('../%s')); echo ' [', phoromatic_tenant_context::get(), '] '; phoromatic_tenant_context::allow('..'); "
                  "echo t(function() { phoromatic_server::phoromatic_account_path('..'); });" % self.t2)
        self.assertEqual(out.strip(), "false [] refused")

    def test_the_root_session_may_look_into_any_account_and_a_command_line_may_too(self):
        self.assertEqual(web("echo t(function() { phoromatic_server::phoromatic_account_path('%s'); });" % self.t2, level=-40).strip(), 'allowed')
        self.assertEqual(web("echo t(function() { phoromatic_server::phoromatic_account_path('%s'); });" % self.t2, level=1).strip(), 'refused')
        self.assertIn('/accounts/%s/' % self.t2, tn.php_boot("echo phoromatic_server::phoromatic_account_path('%s');" % self.t2))

    def test_no_page_reads_another_account_on_purpose_any_more(self):
        # result sharing is gone (T2): a flag left in the database (by a server that was rolled back, say) opens nothing, and nothing is thrown
        tn.sql_exec("UPDATE phoromatic_account_settings SET LetOtherGroupsViewResults=1 WHERE AccountID='%s'" % self.t2)
        self.addCleanup(tn.sql_exec, "UPDATE phoromatic_account_settings SET LetOtherGroupsViewResults=0 WHERE AccountID='%s'" % self.t2)
        b = tn.Browser().login(tn.state()['users']['T1']['1'])
        body = b.get('/?result/TN2PPRA')[2]
        self.assertNotIn('CANARY_T2', body)
        self.assertNotIn('Fatal error', body)
        self.assertNotIn('thrown', body)


class SegmentNames(unittest.TestCase):
    def check(self, values):
        php = "foreach(%s as $v) echo json_encode(phoromatic_tenant_path::segment($v)), ' ';" % ('array(' + ', '.join("'%s'" % v for v in values) + ')')
        return dict(zip(values, [x != 'false' for x in tn.php_boot(php).split()]))

    def test_windows_device_names_are_refused_with_or_without_an_extension(self):
        r = self.check(['con', 'CON', 'Prn', 'aux', 'NUL', 'com1', 'COM9', 'lpt1', 'LPT9', 'con.txt', 'nul.tar.gz', 'aux.', 'Com5.log'])
        self.assertEqual([v for v, ok in r.items() if ok], [])

    def test_a_trailing_dot_is_refused_because_the_file_system_drops_it(self):
        r = self.check(['x.', 'suite-1.0.0.', 'a..', 'TNAAAA.'])
        self.assertEqual([v for v, ok in r.items() if ok], [])

    def test_ordinary_names_still_pass(self):
        r = self.check(['TNAAAA', 'suite-1.0.0', 'a.b', 'con-1.0.0', 'com10', 'lpt', 'aux1', 'console', 'nullable', '0', '.hidden', 'x_y-z.1'])
        self.assertEqual([v for v, ok in r.items() if not ok], [])

    def test_a_suite_cannot_be_saved_or_opened_under_a_device_name(self):
        b = tn.Browser().login(tn.state()['users']['T1']['1'])
        page = b.get('/?build_suite/con')[2]
        self.assertNotIn('Fatal', page)
        self.assertEqual(tn.srv_sh('ls %s/accounts/%s/suites/' % (tn.STORAGE, tn.state()['T1']['aid'])).split().count('con'), 0)


if __name__ == '__main__':
    unittest.main()
