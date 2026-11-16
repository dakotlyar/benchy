"""T4: the server console is refused to everyone who is not a server administrator (or root), at every account level and across accounts, by address and by POST; every change needs the CSRF token and the same origin; the audit page is read only and paged; every action is also a server-tenant-* command.
"""
import json
import re
import unittest
import uuid

import fixture
import tn
from t4 import *



class Refusals(Console):
    def setUp(self):
        # (the level-2 user of T1 is the console's own server administrator in the other tests: here nobody is one)
        tn.cli('server-admin-revoke', 'tn1lvl2')
        self.addCleanup(tn.cli, 'server-admin-grant', 'tn1lvl2')

    def pages(self):
        return ['/?server_tenants', '/?server_tenants/new', '/?server_tenants/show/all', '/?server_tenants/show/deleted', '/?server_tenants/%s' % self.t2, '/?server_tenants/%s' % self.t1,
                '/?server_tenants/%s/suspend' % self.t2, '/?server_tenants/%s/delete' % self.t2, '/?server_tenants/%s/purge' % self.t2, '/?server_audit', '/?server_audit/2']

    def actions(self, aid):
        return [('tenant_create', dict(tenant_name='T4 Refused', register_username='t4refused', register_password=tn.PW, register_password_confirm=tn.PW, register_email='r@example.com', seed_accountid='')),
                ('tenant_rename', dict(account_id=aid, tenant_name='T4 Renamed')), ('tenant_suspend', dict(account_id=aid, reason='x', confirm_running='1')), ('tenant_resume', dict(account_id=aid)),
                ('tenant_delete', dict(account_id=aid, confirm_name='x')), ('tenant_restore', dict(account_id=aid)),
                ('tenant_purge', dict(account_id=aid, confirm_name='x', confirm_id=aid))]

    def test_every_level_of_both_accounts_is_refused_by_address_and_by_post_and_nothing_changes(self):
        browsers = [('T1 level %d' % lvl, b) for lvl, b in tn.logins('T1').items()] + [('T2 admin', tn.Browser().login('tn2admin'))]
        before = (accounts_state(), audit_total(), tn.snapshot(self.t2), tn.snapshot(self.t1))
        for label, b in browsers:
            for p in self.pages():
                with self.subTest(who=label, page=p):
                    s, _, body = b.get(p)
                    self.assertIn('This page is for server administrators', body)
                    for leak in ('Create an account', 'Suspend', 'Purge', 'Rows cannot be changed', 'tenant_created', self.st['T2']['aid'] + '</td>', 'Account ID:'):
                        self.assertNotIn(leak, body)
            for aid in (self.t1, self.t2):
                for action, fields in self.actions(aid):
                    with self.subTest(who=label, action=action, account=aid):
                        s, _, body = act(b, action, **fields)
                        self.assertEqual(s, 403, body[:200])
        self.assertEqual((accounts_state(), audit_total(), tn.snapshot(self.t2), tn.snapshot(self.t1)), before)

    def test_a_visitor_who_is_not_logged_in_gets_the_log_in_page_and_nothing_changes(self):
        b = tn.Browser()
        before = (accounts_state(), audit_total())
        for p in self.pages():
            self.assertNotIn('This page is for server administrators', b.get(p)[2])
            self.assertNotIn('Create an account', b.get(p)[2])
        for action, fields in self.actions(self.t2):
            s, _, body = b.post('/?r_server_action', dict(fields, action=action, token_submit='0' * 40))
            self.assertNotIn('The account was', body)
        self.assertEqual((accounts_state(), audit_total()), before)

    def test_a_server_administrator_of_another_account_is_a_server_administrator_everywhere_and_a_user_who_lost_the_role_is_not(self):
        # the role is read from the database on each request: revoked, the same session is refused
        tn.cli('server-admin-grant', 'tn1lvl3')
        b = tn.Browser().login('tn1lvl3')
        self.assertIn('Create an account', b.get('/?server_tenants/new')[2])
        tn.cli('server-admin-revoke', 'tn1lvl3')
        before = accounts_state()
        self.assertIn('This page is for server administrators', b.get('/?server_tenants')[2])
        for action, fields in self.actions(self.t2):
            self.assertEqual(act(b, action, **fields)[0], 403)
        self.assertEqual(accounts_state(), before)

    def test_a_server_administrator_who_is_disabled_or_demoted_to_nothing_is_refused(self):
        tn.cli('server-admin-grant', 'tn1lvl3')
        self.addCleanup(tn.cli, 'server-admin-revoke', 'tn1lvl3')
        b = tn.Browser().login('tn1lvl3')
        self.assertIn('Create an account', b.get('/?server_tenants/new')[2])
        tn.sql_exec(tn.level_sql('-3', 'tn1lvl3'))
        self.addCleanup(tn.sql_exec, tn.level_sql('3', 'tn1lvl3'))
        before = accounts_state()
        self.assertNotIn('Create an account', b.get('/?server_tenants/new')[2])
        self.assertNotEqual(act(b, 'tenant_rename', account_id=self.t2, tenant_name='x')[0], 302)
        self.assertEqual(accounts_state(), before)


class Tokens(Console):
    def test_every_change_needs_the_token_and_the_same_origin_and_a_get_changes_nothing(self):
        aid, name, user = self.new_tenant('T4 tok')
        before = (accounts_state(), audit_total())
        good = console_token(self.sa)
        fields = {'tenant_create': dict(tenant_name='T4 nope', register_username='t4nope', register_password=tn.PW, register_password_confirm=tn.PW, register_email='n@example.com', seed_accountid=''),
                  'tenant_rename': dict(account_id=aid, tenant_name='T4 nope'), 'tenant_suspend': dict(account_id=aid), 'tenant_resume': dict(account_id=aid),
                  'tenant_delete': dict(account_id=aid, confirm_name=name), 'tenant_restore': dict(account_id=aid), 'tenant_purge': dict(account_id=aid, confirm_name=name, confirm_id=aid)}
        for action, f in fields.items():
            for label, token, headers in (('no token', '', None), ('wrong token', '0' * len(good), None), ('other origin', good, EVIL)):
                with self.subTest(action=action, case=label):
                    s, _, _ = act(self.sa, action, token=token, headers=headers, **f)
                    self.assertIn(s, (302, 403))
            with self.subTest(action=action, case='GET'):
                self.assertEqual(self.sa.get('/?r_server_action')[0], 405)
        self.assertEqual((accounts_state(), audit_total()), before)
        # the same change with the right token and origin is taken (so the refusals above are not a broken form)
        act(self.sa, 'tenant_rename', token=good, account_id=aid, tenant_name=name + ' ok')
        self.assertEqual(acct(aid)['GroupName'], name + ' ok')


class AuditPage(Console):
    def test_the_audit_page_is_read_only_paged_escaped_and_for_server_administrators_only(self):
        # at least two pages, so the paging branch runs: pad with plain rows (taken out again at the end; the table refuses DELETE, so the guard is lifted for it)
        need = 120 - audit_total()
        if need > 0:
            tn.sql_exec(';'.join("INSERT INTO phoromatic_server_audit (EventTime, ActorType, Actor, Action) VALUES ('2026-01-01 00:00:00', 'system', 't4pad', 'server_setting_changed')" for _ in range(need)))
            self.addCleanup(forget_audit, "Actor = 't4pad'")
        aid, name, user = self.new_tenant('T4 <i>aud</i>')
        total = audit_total()
        body = self.sa.get('/?server_audit')[2]
        self.assertIn('Server audit', body)
        self.assertIn('tenant_created', body)
        self.assertNotIn('<form', body.split('Server audit</h1>')[1].split('<div id="pts_phoromatic_bottom_footer">')[0], 'read only: no form on the page')
        self.assertNotIn('<i>aud</i>', body)
        self.assertIn('&lt;i&gt;aud&lt;/i&gt;', body)
        pages = max(1, -(-total // 50))
        self.assertIn('Page 1 of %d' % pages, body)
        self.assertGreater(pages, 1)
        if pages > 1:
            self.assertIn('Older', body)
            self.assertIn('Page 2 of %d' % pages, self.sa.get('/?server_audit/2')[2])
            self.assertIn('Page %d of %d' % (pages, pages), self.sa.get('/?server_audit/99999')[2])
        self.assertIn('Page 1 of', self.sa.get('/?server_audit/0')[2])
        self.assertIn('Page 1 of', self.sa.get('/?server_audit/abc')[2])
        # the audit cannot be changed
        for sql in ("UPDATE phoromatic_server_audit SET Actor='x'", "DELETE FROM phoromatic_server_audit"):
            with self.assertRaises(AssertionError):
                tn.sql_exec(sql)
        self.assertEqual(audit_total(), total)
        # a tenant's administrator never sees these rows
        member = tn.Browser().login('tn2admin')
        self.assertNotIn('tenant_created', member.get('/?server_audit')[2])
        self.assertNotIn('tenant_created', member.get('/?account_activity')[2])


class Commands(Console):
    def sh(self, *args, stdin=None):
        import subprocess
        r = subprocess.run(['docker', 'exec', '-i', tn.SRV, 'sh', '/bench/benchy'] + list(args), input=stdin, capture_output=True, text=True)
        return r.returncode, r.stdout + r.stderr

    def test_every_lifecycle_action_is_a_command_with_the_same_refusals_and_audit(self):
        name = 'T4 cli ' + uuid.uuid4().hex[:6]
        rc, out = self.sh('server-account-create', '--user', 't4cli' + name[-6:], '--email', 'c@example.com', '--name', name, stdin=tn.PW + '\n')
        self.assertIn('Account created', out, out)
        aid = re.search(r'in account (\w{6})', out).group(1)
        self.assertEqual(acct(aid)['GroupName'], name)
        self.assertEqual(audit('tenant_created', aid)[0]['ActorType'], 'cli')
        rc, out = self.sh('server-account-create', '--user', 't4cli2' + name[-5:], '--email', 'c@example.com', '--name', name.lower(), stdin=tn.PW + '\n')
        self.assertNotIn('Account created', out)
        self.assertIn('already', out)
        self.assertIn(aid, self.sh('server-tenant-list')[1])
        self.assertIn('server-tenant-rename ACCOUNT --name', self.sh('server-tenant-rename', aid)[1])
        self.assertIn('Done', self.sh('server-tenant-rename', name, '--name', name + ' b')[1])
        name += ' b'
        self.assertEqual(acct(aid)['GroupName'], name)
        self.assertIn('Only a deleted account', self.sh('server-tenant-purge', aid, '--confirm-name', name, '--confirm-id', aid)[1])
        self.assertIn('only an account that is suspended', self.sh('server-tenant-resume', aid)[1])
        self.assertIn('Done', self.sh('server-tenant-suspend', aid, '--reason', 'cli test')[1])
        self.assertEqual(acct(aid)['State'], 'suspended')
        self.assertIn('Done', self.sh('server-tenant-resume', aid)[1])
        self.sh('server-tenant-suspend', aid)
        self.assertIn('Type the account', self.sh('server-tenant-delete', aid, '--confirm-name', 'wrong')[1])
        self.assertIn('Done', self.sh('server-tenant-delete', aid, '--confirm-name', name)[1])
        self.assertNotIn(aid, self.sh('server-tenant-list')[1])
        self.assertIn(aid, self.sh('server-tenant-list', '--all')[1])
        self.assertIn('Done', self.sh('server-tenant-restore', aid)[1])
        self.sh('server-tenant-delete', aid, '--confirm-name', name)
        self.assertIn('Type the account', self.sh('server-tenant-purge', aid, '--confirm-name', name)[1])
        self.assertIsNone(acct(aid)['PurgedOn'])
        self.assertIn('purged', self.sh('server-tenant-purge', aid, '--confirm-name', name, '--confirm-id', aid)[1])
        self.assertTrue(acct(aid)['PurgedOn'])
        self.assertEqual([r['Action'] for r in tn.sql("SELECT Action FROM phoromatic_server_audit WHERE TenantID='%s' ORDER BY AuditID" % aid)],
                         ['tenant_created', 'tenant_renamed', 'tenant_suspended', 'tenant_resumed', 'tenant_suspended', 'tenant_deleted', 'tenant_restored', 'tenant_deleted', 'tenant_purged'])
        self.assertEqual({r['ActorType'] for r in tn.sql("SELECT ActorType FROM phoromatic_server_audit WHERE TenantID='%s'" % aid)}, {'cli'})
        self.assertIn('no account', self.sh('server-tenant-suspend', 'ZZZZZZ')[1].lower().replace('there is no account', 'no account'))


if __name__ == '__main__':
    unittest.main()
