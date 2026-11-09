"""The lints fail on what they should (N36): a new unclassified route, table or path join, an unbound SQL clause, a stale allow-list entry, and the
removal of the fixes they guard. Each case copies the PHP files of the tree under test, breaks one thing, runs one lint and expects it to fail with a
particular message; the unbroken copy must pass first."""
import json
import os
import re
import shutil
import subprocess
import sys
import tempfile
import unittest

HERE = os.path.dirname(os.path.abspath(__file__))
SRC = os.environ.get('TN_TREE') or os.path.normpath(os.path.join(HERE, '..', '..'))


def run(lint, root, *extra):
    r = subprocess.run([sys.executable, '-W', 'ignore', os.path.join(HERE, lint + '.py'), '--root', root] + list(extra), capture_output=True, text=True)
    return r.returncode, r.stdout + r.stderr


class LintsFailWhereTheyShould(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.root = tempfile.mkdtemp(prefix='tn-selftest-')
        for dirpath, dirnames, filenames in os.walk(os.path.join(SRC, 'pts-core')):
            for f in filenames:
                if f.endswith('.php') or 'public_html' in dirpath:
                    src = os.path.join(dirpath, f)
                    dst = os.path.join(cls.root, os.path.relpath(src, SRC))
                    os.makedirs(os.path.dirname(dst), exist_ok=True)
                    shutil.copy(src, dst)

    @classmethod
    def tearDownClass(cls):
        shutil.rmtree(cls.root, ignore_errors=True)

    def setUp(self):
        self.saved = {}

    def tearDown(self):
        for path, content in self.saved.items():
            if content is None:
                os.unlink(path)
            else:
                open(path, 'w').write(content)

    def path(self, rel):
        return os.path.join(self.root, rel)

    def edit(self, rel, fn):
        p = self.path(rel)
        old = open(p).read()
        new = fn(old)
        self.assertNotEqual(old, new, 'the edit changed nothing in ' + rel)
        self.saved.setdefault(p, old)
        open(p, 'w').write(new)

    def add(self, rel, text):
        p = self.path(rel)
        os.makedirs(os.path.dirname(p), exist_ok=True)
        self.saved.setdefault(p, None)
        open(p, 'w').write(text)

    def remove(self, rel):
        p = self.path(rel)
        self.saved.setdefault(p, open(p).read())
        os.unlink(p)

    def assertFails(self, lint, message, *extra):
        rc, out = run(lint, self.root, *extra)
        self.assertEqual(rc, 1, out)
        self.assertRegex(out, message)

    def test_the_unbroken_tree_passes_all_three(self):
        for lint in ('lint_sql', 'lint_paths', 'lint_routes'):
            rc, out = run(lint, self.root)
            self.assertEqual(rc, 0, out)

    # ---- SQL
    def test_sql_a_new_unbound_clause_fails(self):
        self.add('pts-core/objects/phoromatic/phoromatic_tn_probe.php', "<?php $r = phoromatic_server::$db->query('SELECT * FROM phoromatic_results WHERE PPRID = 1'); ?>")
        self.assertFails('lint_sql', r'phoromatic_tn_probe.php:1.*not bound to AccountID')

    def test_sql_a_clause_with_the_account_in_the_other_branch_of_an_or_fails(self):
        self.add('pts-core/objects/phoromatic/phoromatic_tn_probe.php', "<?php $r = $db->query('SELECT * FROM phoromatic_results WHERE PPRID = 1 OR AccountID = 2'); ?>")
        self.assertFails('lint_sql', r'phoromatic_tn_probe.php')

    def test_sql_a_join_with_one_unbound_table_fails(self):
        self.add('pts-core/objects/phoromatic/phoromatic_tn_probe.php', "<?php $r = $db->query('SELECT a.Title FROM phoromatic_results a JOIN phoromatic_systems s ON s.SystemID = a.SystemID WHERE a.AccountID = :a'); ?>")
        self.assertFails('lint_sql', r'phoromatic_systems is not bound')

    def test_sql_removing_the_account_from_a_real_clause_fails(self):
        self.edit('pts-core/phoromatic/pages/phoromatic_settings.php',
                  lambda s: s.replace("SELECT * FROM phoromatic_user_settings WHERE AccountID = :account_id AND UserID = :user_id", "SELECT * FROM phoromatic_user_settings WHERE UserID = :user_id"))
        self.assertFails('lint_sql', r'phoromatic_settings.php.*phoromatic_user_settings is not bound')

    def test_sql_removing_the_tracker_fix_fails(self):
        self.edit('pts-core/phoromatic/pages/phoromatic_tracker.php', lambda s: s.replace("WHERE AccountID = phoromatic_schedules.AccountID AND ScheduleID", "WHERE ScheduleID"))
        self.assertFails('lint_sql', r'phoromatic_tracker.php.*phoromatic_results is not bound')

    def test_sql_a_double_quoted_value_fails_and_cannot_be_allow_listed(self):
        self.add('pts-core/objects/phoromatic/phoromatic_tn_probe.php',
                 "<?php\n$db->exec('UPDATE phoromatic_users SET Password = ' . $p . ' WHERE AccountID = :a AND UserName = \"' . $n . '\"');\n"
                 "$sql .= ' AND UserName = \"' . $n . '\"';\n$ok = $db->exec('SELECT 1 FROM phoromatic_users WHERE AccountID = :a AND UserName LIKE \\'%\"x\"%\\'');\n?>")
        rc, out = run('lint_sql', self.root)
        self.assertEqual(rc, 1, out)
        self.assertRegex(out, r'phoromatic_tn_probe.php:2 SQL with a double-quoted value')
        self.assertRegex(out, r'phoromatic_tn_probe.php:3 SQL with a double-quoted value')
        self.assertNotIn('phoromatic_tn_probe.php:4', out)

    def test_sql_restoring_the_double_quoted_login_update_fails(self):
        self.edit('pts-core/phoromatic/pages/phoromatic_password.php', lambda s: s.replace("$stmt = phoromatic_server::$db->prepare('UPDATE phoromatic_users SET Password = :password WHERE UserID = :user_id AND AccountID = :account_id');",
                  "phoromatic_server::$db->exec('UPDATE phoromatic_users SET Password = \\'' . $new_salted_password . '\\' WHERE UserName = \"' . $_SESSION['UserName'] . '\"'); $stmt = phoromatic_server::$db->prepare('UPDATE phoromatic_users SET Password = :password WHERE UserID = :user_id AND AccountID = :account_id');"))
        self.assertFails('lint_sql', r'phoromatic_password.php.*SQL with a double-quoted value')

    def test_sql_a_changed_allow_listed_clause_makes_its_entry_stale(self):
        self.edit('pts-core/objects/phoromatic/phoromatic_auth.php', lambda s: s.replace("SELECT COUNT(*) FROM phoromatic_accounts", "SELECT COUNT(*) FROM phoromatic_accounts WHERE 1"))
        self.assertFails('lint_sql', r'matches no clause any more')

    def test_sql_a_new_table_must_be_classified(self):
        self.edit('pts-core/objects/phoromatic/phoromatic_server.php', lambda s: s.replace("CREATE TABLE IF NOT EXISTS phoromatic_v2_failures (Kind TEXT NOT NULL,",
                                                                                           "CREATE TABLE IF NOT EXISTS phoromatic_tn_new (X TEXT); CREATE TABLE IF NOT EXISTS phoromatic_v2_failures (Kind TEXT NOT NULL,", 1))
        self.assertFails('lint_sql', r'phoromatic_tn_new is created in the code but not classified')

    def test_sql_a_tenant_table_needs_an_account_column(self):
        tables = json.load(open(os.path.join(HERE, 'tables.json')))
        tables['phoromatic_v2_failures'] = {'scope': 'tenant'}
        tmp = os.path.join(self.root, 'tables.json')
        json.dump(tables, open(tmp, 'w'))
        self.assertFails('lint_sql', r'phoromatic_v2_failures is classified tenant but has no AccountID column', '--tables', tmp)

    # ---- paths
    def test_paths_a_request_value_in_a_path_call_fails_and_the_wrapped_one_does_not(self):
        self.add('pts-core/objects/phoromatic/phoromatic_tn_probe.php',
                 "<?php\n$a = phoromatic_server::phoromatic_account_result_path(ACCOUNT_ID, $_GET['i']);\n$b = phoromatic_server::phoromatic_account_result_path(ACCOUNT_ID, phoromatic_tenant_path::segment($_GET['i']));\n?>")
        rc, out = run('lint_paths', self.root)
        self.assertEqual(rc, 1, out)
        self.assertRegex(out, r'phoromatic_tn_probe.php:2 a request-derived value')
        self.assertNotIn('phoromatic_tn_probe.php:3', out)

    def test_paths_a_value_taken_from_a_request_in_a_variable_fails(self):
        self.add('pts-core/phoromatic/pages/phoromatic_tn_probe.php', "<?php\n$id = $_POST['x'];\n$p = phoromatic_server::phoromatic_account_suite_path($_SESSION['AccountID'], $id);\n?>")
        self.assertFails('lint_paths', r'phoromatic_tn_probe.php:3 a request-derived value')

    def test_paths_a_client_variable_fails(self):
        self.add('pts-core/phoromatic/communication-resources/tn_probe_use.php', "<?php\n$p = phoromatic_server::phoromatic_account_stress_log_path(ACCOUNT_ID, $BENCHMARK_TICKET_ID);\n?>")
        self.assertFails('lint_paths', r'tn_probe_use.php:2 a request-derived value')

    def test_paths_an_unknown_value_needs_a_reviewed_entry(self):
        self.add('pts-core/objects/phoromatic/phoromatic_tn_probe.php', "<?php\n$p = phoromatic_server::phoromatic_account_system_path($account, $something);\n?>")
        self.assertFails('lint_paths', r'phoromatic_tn_probe.php:2 path call with a value that is not a recognized safe form')

    def test_paths_removing_the_upload_fix_fails(self):
        self.edit('pts-core/phoromatic/communication-resources/result_log_upload.php', lambda s: s.replace("phoromatic_tenant_path::segment($ID)", "$ID"))
        self.assertFails('lint_paths', r'result_log_upload.php.*a request-derived value')

    def test_paths_removing_the_stress_log_fix_fails(self):
        self.edit('pts-core/phoromatic/communication-resources/stress_log_upload.php',
                  lambda s: s.replace("phoromatic_server::phoromatic_account_stress_log_path(ACCOUNT_ID, $ticket_id)", "phoromatic_server::phoromatic_account_stress_log_path(ACCOUNT_ID, $BENCHMARK_TICKET_ID)"))
        self.assertFails('lint_paths', r'stress_log_upload.php.*a request-derived value')

    def test_paths_removing_the_view_log_check_fails(self):
        self.edit('pts-core/phoromatic/pages/phoromatic_benchmark.php',
                  lambda s: s.replace("$log_system = isset($_REQUEST['view_log']) ? phoromatic_tenant_path::segment($_REQUEST['view_log']) : false;", "$log_system = $_REQUEST['view_log'];"))
        self.assertFails('lint_paths', r'phoromatic_benchmark.php.*a request-derived value')

    def test_paths_find_suite_file_must_keep_checking_its_argument(self):
        self.edit('pts-core/objects/phoromatic/phoromatic_server.php', lambda s: s.replace("phoromatic_tenant_path::suite_name($suite_name)", "($suite_name)"))
        self.assertFails('lint_paths', r'find_suite_file\(\) no longer calls phoromatic_tenant_path::suite_name')

    # ---- routes
    def test_routes_a_new_page_fails_until_it_is_in_the_matrix(self):
        self.add('pts-core/phoromatic/pages/phoromatic_tn_new.php', '<?php class phoromatic_tn_new {} ?>')
        self.assertFails('lint_routes', r'pages tn_new exists in the code but not in routes.json')

    def test_routes_a_new_client_resource_fails(self):
        self.add('pts-core/phoromatic/communication-resources/tn_new.php', '<?php ?>')
        self.assertFails('lint_routes', r'resources tn_new exists in the code but not in routes.json')

    def test_routes_a_new_public_file_fails(self):
        self.add('pts-core/phoromatic/public_html/tn_new.php', '<?php ?>')
        self.assertFails('lint_routes', r'public_html tn_new.php exists in the code but not in routes.json')

    def test_routes_a_new_agent_api_route_fails(self):
        self.edit('pts-core/objects/phoromatic/phoromatic_api_v2.php', lambda s: s.replace("'/api/v2/agent/hello'", "'/api/v2/agent/hello' || $path === '/api/v2/tn/new'", 1))
        self.assertFails('lint_routes', r'v2 /api/v2/tn/new exists in the code but not in routes.json')

    def test_routes_a_removed_page_leaves_a_stale_entry(self):
        self.remove('pts-core/phoromatic/pages/phoromatic_caches.php')
        self.assertFails('lint_routes', r'routes.json lists pages caches, which is not in the code any more')

    def test_routes_a_resource_missing_from_the_server_list_fails(self):
        self.edit('pts-core/objects/phoromatic/phoromatic_server.php', lambda s: s.replace("'stress_log_upload', ", "", 1))
        self.assertFails('lint_routes', r'client_resources\(\) and communication-resources/ disagree')


if __name__ == '__main__':
    unittest.main()
