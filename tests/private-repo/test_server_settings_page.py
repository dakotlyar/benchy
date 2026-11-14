"""CP1 of the settings simplification (Benchy 0.9.13): the Server settings page (?server_settings) and root's ?admin_config.

Server settings: gated by the server role (never the account level), every form keeps its CSRF token + same-origin check and its audit row,
the old ?settings page still takes the moved posts for one release, and what stays on ?settings is the account's. Root's ?admin_config:
the same sections, the root-only settings, and storage / cache / ports / proxy shown read-only (a stale post changes nothing and names
the command). Run through tests/private-repo/run.sh (part of http/all).
"""
import base64
import json
import re
import subprocess
import unittest
import uuid

from bench_helpers import composite
from single_account import hide_other_accounts, restore_other_accounts
from test_private_repo_http import AID, SRV, sql, srv_sh
from test_settings_csrf import restore_row
from test_private_repo_tokens import sql_exec
from test_private_repo_web import Browser, WebBase, USER, PASS, make_user

SERVER = '/?server_settings'
EVIL = {'Origin': 'http://evil.example'}
SECTIONS = ('New systems, all accounts', 'Contact with OpenBenchmarking.org', '<h2>HTTPS</h2>', 'Native agent connections', 'Shared download cache', 'Set on the server only')


def tok(b, path):
    m = re.search(r'name="token_submit" value="([0-9a-f]+)"', b.get(path)[2])
    assert m, 'no token on ' + path
    return m.group(1)


def benchy(*args):
    return subprocess.run(['docker', 'exec', SRV, 'sh', '/bench/benchy'] + list(args), capture_output=True, text=True).stdout


def policy_of(command):
    """The policy a server command reports on its first line (the help line under it names both values, so a substring test would always pass)."""
    m = re.search(r'policy: (\w+)|auto-approve new systems: (\w+)', benchy(command))
    return (m.group(1) or m.group(2)) if m else None


def audit(action):
    return sql("SELECT * FROM phoromatic_admin_audit WHERE Action='%s' ORDER BY AuditID" % action)


class TestServerSettingsPage(WebBase):
    @classmethod
    def setUpClass(cls):
        hide_other_accounts()
        super().setUpClass()
        cls.roles = {}
        # EC-4: the role is the user's, not the level's: a level-3 and a level-10 user can hold it, a second level-1 user need not
        for level in (1, 3, 10):
            name = 'ssq%d%s' % (level, cls.suffix)
            make_user(cls.admin, name, 2 if level == 1 else level)
            if level == 1:
                sql_exec("UPDATE phoromatic_users SET AdminLevel=1 WHERE UserName='%s'" % name)
            cls.roles[level] = name
        for level in (3, 10):
            benchy('server-admin-grant', cls.roles[level])

    @classmethod
    def tearDownClass(cls):
        for level in (3, 10):
            benchy('server-admin-revoke', cls.roles[level])
        benchy('phoromatic-client-phone-home', 'forbidden')
        benchy('phoromatic-tenant-auto-approve', 'forbidden')
        restore_other_accounts()

    def role_user(self, level):
        return Browser().login(self.roles[level], 'qcpass123')

    def test_a_server_administrator_sees_the_six_sections_in_order(self):
        page = self.admin.get(SERVER)[2]
        pos = [page.find(s) for s in SECTIONS]
        self.assertNotIn(-1, pos, dict(zip(SECTIONS, pos)))
        self.assertEqual(pos, sorted(pos))
        for form in ('tenant_auto_approve', 'client_phone_home', 'v2_settings'):
            self.assertIn('name="%s"' % form, page)
        self.assertIn('server-admin-grant', page)
        self.assertIn('href="?settings"', page)

    def test_the_server_role_decides_not_the_level(self):
        for level in (3, 10):
            page = self.role_user(level).get(SERVER)[2]
            self.assertIn('name="client_phone_home"', page, 'level %d with the role' % level)
            settings = self.role_user(level).get('/?settings')[2]
            self.assertIn('href="?server_settings"', settings, 'and the Settings page links to it')
        for who, b in (('level 1 without the role', self.role_user(1)), ('level 2', self.as_user(2)), ('level 3', self.as_user(3)), ('viewer', self.as_user(10))):
            page = b.get(SERVER)[2]
            self.assertIn('Server settings are for server administrators', page, who)
            for marker in ('name="tenant_auto_approve"', 'name="client_phone_home"', 'name="v2_settings"', 'name="https_action"'):
                self.assertNotIn(marker, page, who)
            self.assertNotIn('href="?server_settings"', b.get('/?settings')[2], who)

    def test_a_forged_post_from_a_user_without_the_role_changes_nothing(self):
        benchy('phoromatic-client-phone-home', 'forbidden')
        benchy('phoromatic-tenant-auto-approve', 'forbidden')
        before = (len(audit('client_phone_home_set')), len(audit('tenant_auto_approve_set')))
        for b in (self.role_user(1), self.as_user(2), self.as_user(3), self.as_user(10)):
            t = tok(b, '/?settings')
            b.post(SERVER, {'client_phone_home': 'client_choice', 'token_submit': t})
            b.post(SERVER, {'tenant_auto_approve': 'allowed', 'token_submit': t})
        self.assertEqual(policy_of('phoromatic-client-phone-home'), 'forbidden')
        self.assertEqual(policy_of('phoromatic-tenant-auto-approve'), 'forbidden')
        self.assertEqual((len(audit('client_phone_home_set')), len(audit('tenant_auto_approve_set'))), before)

    def test_each_policy_form_needs_the_token_and_the_origin_and_is_audited(self):
        for field, off, on, action, cmd, words in (('tenant_auto_approve', 'forbidden', 'allowed', 'tenant_auto_approve_set', 'phoromatic-tenant-auto-approve', 'auto-approve policy was not changed'),
                                                   ('client_phone_home', 'forbidden', 'client_choice', 'client_phone_home_set', 'phoromatic-client-phone-home', 'phone-home policy was not changed')):
            with self.subTest(field):
                benchy(cmd, off)
                n = len(audit(action))
                for label, data, headers in (('no token', {field: on}, None), ('wrong token', {field: on, 'token_submit': '0' * 40}, None),
                                             ('cross-origin', {field: on, 'token_submit': tok(self.admin, SERVER)}, EVIL)):
                    body = self.admin.post(SERVER, data, headers=headers)[2]
                    self.assertIn(words, body, label)
                    self.assertEqual(policy_of(cmd), off, label)
                self.assertEqual(len(audit(action)), n)
                self.assertIn('Saved.', self.admin.post(SERVER, {field: on, 'token_submit': tok(self.admin, SERVER)})[2])
                self.assertEqual(policy_of(cmd), on)
                rows = audit(action)
                self.assertEqual(len(rows) - n, 1)
                self.assertEqual((rows[-1]['ActorType'], rows[-1]['Actor']), ('user', USER))
                benchy(cmd, off)

    def test_the_old_settings_page_takes_the_moved_posts_for_one_release(self):
        benchy('phoromatic-tenant-auto-approve', 'forbidden')
        body = self.admin.post('/?settings', {'tenant_auto_approve': 'allowed', 'token_submit': tok(self.admin, '/?settings')})[2]
        self.assertIn('Saved.', body)
        self.assertIn('href="?server_settings"', body)
        self.assertNotIn('name="tenant_auto_approve"', body, 'the form itself is not drawn on the account page')
        self.assertEqual(policy_of('phoromatic-tenant-auto-approve'), 'allowed')
        benchy('phoromatic-tenant-auto-approve', 'forbidden')

    def test_the_account_page_keeps_only_the_accounts_options(self):
        page = self.admin.get('/?settings')[2]
        for gone in ('Accounts May Auto-Approve', 'Clients May Phone Home', 'Native Agent API', 'name="https_action"', 'name="v2_settings"', 'name="client_phone_home"'):
            self.assertNotIn(gone, page)
        for kept in ('name="account_settings_update"', 'name="user_settings_update"', 'name="env_var_update"', 'name="client_update_script"'):
            self.assertIn(kept, page)
        self.assertIn('Server-wide settings', page)

    def test_saving_account_settings_leaves_the_hidden_openbenchmarking_choice_alone(self):
        # EC-1: while the server forbids phone-home the account's OpenBenchmarking box is not on the page, so a save must not write it
        benchy('phoromatic-client-phone-home', 'forbidden')
        q = "SELECT UploadResultsToOpenBenchmarking AS v FROM phoromatic_account_settings WHERE AccountID='%s'" % AID
        old = sql(q)[0]['v']
        # (a saved account form sets every box on it: put the whole row back, or the later phases' clients run with other defaults)
        self.addCleanup(restore_row('phoromatic_account_settings', "AccountID='%s'" % AID))
        sql_exec("UPDATE phoromatic_account_settings SET UploadResultsToOpenBenchmarking=1 WHERE AccountID='%s'" % AID)
        self.admin.post('/?settings', {'account_settings_update': '1', 'PowerOffWhenDone': 'yes', 'token_submit': tok(self.admin, '/?settings')})
        self.assertEqual(sql(q)[0]['v'], 1)


class TestAdminConfigPage(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        srv_sh("cd /bench/pts-core/phoromatic/public_html && php -r 'define(\"PHOROMATIC_SERVER\", true); define(\"PTS_MODE\", \"WEB_CLIENT\"); define(\"PTS_AUTO_LOAD_OBJECTS\", true); include(\"../../pts-core.php\"); pts_core::init(); phoromatic_server::prepare_database(); phoromatic_server::save_setting(\"root_admin_pw\", hash(\"sha256\", \"PTSqcrootpw1\"));'")
        cls.root = Browser()
        cls.root.get('/')
        cls.root.post('/?login', {'username': 'rootadmin', 'password': 'qcrootpw1'})
        assert 'Admin_Config' in cls.root.get('/?admin')[2] or 'admin_config' in cls.root.get('/?admin')[2]

    def facts(self):
        """The read-only lines of the managed section: what the server's configuration holds now."""
        page = self.root.get('/?admin_config')[2]
        return re.search(r'Managed by benchy-server-setup.*?</p>', page, re.S).group(0)

    def test_root_sees_the_server_sections_then_root_only_then_the_managed_ones(self):
        page = self.root.get('/?admin_config')[2]
        pos = [page.find(s) for s in SECTIONS + ('Root only', 'Managed by benchy-server-setup')]
        self.assertNotIn(-1, pos)
        self.assertEqual(pos, sorted(pos))
        self.assertEqual(page.count('<h2>HTTPS</h2>'), 1, 'one HTTPS section')
        for gone in ('name="new_phoromatic_path"', 'name="new_dc_path"', 'name="new_proxy_address"', 'name="new_http_port"', 'name="new_ws_port"', 'Account Creation String', 'Update Phoromatic Storage Location'):
            self.assertNotIn(gone, page)
        for kept in ('name="account_creation_alt"', 'name="main_page_message"', 'name="show_local_tests_only"', 'name="new_admin_support_email"', 'name="rebuild_results_db"'):
            self.assertIn(kept, page)
        self.assertNotIn('<select', page, 'the False/True selects are gone')
        self.assertIn('benchy-server-setup --storage-path', page)
        self.assertIn('Setup does not move data', page)

    def test_a_stale_post_for_a_moved_setting_changes_nothing_and_names_the_command(self):
        before = self.facts()
        t = tok(self.root, '/?admin_config')
        for fields, command in (({'new_phoromatic_path': '/tmp/qc-moved-storage'}, '--storage-path'), ({'new_dc_path': '/tmp/qc-moved-cache'}, 'CacheDirectory'),
                                ({'new_proxy_address': '127.0.0.1', 'new_proxy_port': '9'}, 'ProxyAddress'), ({'new_http_port': '12345', 'new_ws_port': '12346'}, 'RemoteAccessPort'),
                                ({'new_http_port': 'junk', 'new_ws_port': ''}, 'RemoteAccessPort')):
            body = self.root.post('/?admin_config', dict(fields, token_submit=t))[2]
            self.assertIn('This is set on the server now', body, fields)
            self.assertIn(command, body, fields)
        self.assertEqual(self.facts(), before)
        self.assertEqual(srv_sh('test -e /tmp/qc-moved-storage -o -e /tmp/qc-moved-cache && echo made || echo none').strip(), 'none')

    def test_the_rebuild_runs_only_from_its_button(self):
        upload_id = 'qcrebuild%s' % uuid.uuid4().hex[:6]
        rdir = '/var/lib/benchy/phoromatic/accounts/%s/results/%s/' % (AID, upload_id)
        srv_sh('mkdir -p %s && echo %s | base64 -d > %scomposite.xml' % (rdir, base64.b64encode(composite('2026-01-01').encode()).decode(), rdir))
        self.addCleanup(srv_sh, 'rm -rf %s' % rdir)
        self.addCleanup(sql_exec, "DELETE FROM phoromatic_results_results WHERE UploadID='%s'; DELETE FROM phoromatic_results_systems WHERE UploadID='%s'" % (upload_id, upload_id))
        count = lambda: sql("SELECT COUNT(*) AS n FROM phoromatic_results_results WHERE UploadID='%s'" % upload_id)[0]['n']
        sql_exec("DELETE FROM phoromatic_results_results WHERE UploadID='%s'" % upload_id)
        t = tok(self.root, '/?admin_config')
        self.root.post('/?admin_config', {'rebuild_results_db': '0', 'token_submit': t})
        self.assertEqual(count(), 0, 'False no longer rebuilds')
        self.assertIn('form expired', self.root.post('/?admin_config', {'rebuild_results_db': '1'})[2])
        self.assertEqual(count(), 0, 'no token, nothing')
        self.assertIn('form expired', self.root.post('/?admin_config', {'rebuild_results_db': '1', 'token_submit': t}, headers=EVIL)[2])
        self.assertEqual(count(), 0, 'foreign origin, nothing')
        self.root.post('/?admin_config', {'rebuild_results_db': '1', 'token_submit': t})
        self.assertGreater(count(), 0, 'the button rebuilds')

    def managed_block(self, http_port_env):
        env = ['-e', 'PHOROMATIC_HTTP_PORT=' + http_port_env] if http_port_env else []
        return subprocess.run(['docker', 'exec'] + env + [SRV, 'php', '/bench/tests/private-repo/admin_config_probe.php'], capture_output=True, text=True).stdout

    def test_the_managed_block_shows_the_http_port_the_systemd_drop_in_sets(self):
        # on a .deb server the service unit's PHOROMATIC_HTTP_PORT overrides RemoteAccessPort, so the config alone would say RANDOM
        block = self.managed_block('39080')
        self.assertIn('plain HTTP 39080', block)
        self.assertIn('PHOROMATIC_HTTP_PORT in the systemd drop-in', block)
        self.assertIn('HTTPS 39443', block)
        self.assertNotIn('WebSocket', block)
        self.assertIn('sudo -u phoromatic env HOME=/var/lib/phoromatic benchy user-config-set', block)
        self.assertIn('Setup does not move data', block)
        # without the variable the page says plainly that it cannot see it, instead of printing RANDOM as if it were the answer
        self.assertIn('cannot see PHOROMATIC_HTTP_PORT', self.managed_block(''))

    def test_the_cache_only_box_saves_both_ways(self):
        t = tok(self.root, '/?admin_config')
        self.addCleanup(self.root.post, '/?admin_config', {'show_local_tests_only': '0', 'token_submit': t})
        self.root.post('/?admin_config', {'show_local_tests_only': '1', 'token_submit': t})
        self.assertRegex(self.root.get('/?admin_config')[2], r'name="show_local_tests_only" value="1" checked="checked"')
        self.root.post('/?admin_config', {'show_local_tests_only': '0', 'token_submit': t})
        self.assertNotRegex(self.root.get('/?admin_config')[2], r'name="show_local_tests_only" value="1" checked="checked"')

    def test_the_server_forms_on_admin_config_keep_their_checks(self):
        benchy('phoromatic-tenant-auto-approve', 'forbidden')
        self.addCleanup(benchy, 'phoromatic-tenant-auto-approve', 'forbidden')
        body = self.root.post('/?admin_config', {'tenant_auto_approve': 'allowed'})[2]
        self.assertIn('form expired or was not submitted from this site', body, 'no token')
        self.assertEqual(policy_of('phoromatic-tenant-auto-approve'), 'forbidden')
        body = self.root.post('/?admin_config', {'tenant_auto_approve': 'allowed', 'token_submit': tok(self.root, '/?admin_config')}, headers=EVIL)[2]
        self.assertIn('form expired or was not submitted from this site', body, 'foreign origin')
        self.assertEqual(policy_of('phoromatic-tenant-auto-approve'), 'forbidden')
        self.assertIn('Saved.', self.root.post('/?admin_config', {'tenant_auto_approve': 'allowed', 'token_submit': tok(self.root, '/?admin_config')})[2])
        self.assertEqual(policy_of('phoromatic-tenant-auto-approve'), 'allowed')


if __name__ == '__main__':
    unittest.main()
