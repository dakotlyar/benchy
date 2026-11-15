"""T4: the server console and the tenant lifecycle. Everything is refused for anyone who is not a server administrator (or root), at every account level
and across accounts, by address and by POST; a server administrator at any level and root can create, rename, suspend, resume, delete, restore and purge,
each in the server audit; delete then restore keeps the data; purge removes it, only from deleted and only with the name and ID typed; every change needs
the CSRF token and the same origin; the audit page is read only and paged; the one-time upgrade notice is shown once per user; the same changes are
available as server-tenant-* commands.
"""
import json
import re
import unittest
import uuid

import fixture
import tn

EVIL = {'Origin': 'http://evil.example'}
SUSPENDED = 'This Phoromatic account is suspended.'
TRIGGER = ("CREATE TRIGGER phoromatic_server_audit_no_delete BEFORE DELETE ON phoromatic_server_audit BEGIN SELECT RAISE(ABORT, 'audit rows are append-only'); END")
KEEP = ('phoromatic_accounts', 'phoromatic_users', 'phoromatic_admin_audit', 'phoromatic_private_repo_audit')


def acct(aid):
    rows = tn.sql("SELECT * FROM phoromatic_accounts WHERE AccountID='%s'" % aid)
    return rows[0] if rows else None


def accounts_state():
    return tn.sql('SELECT AccountID, GroupName, State, PurgedOn, DeletedOn, StateChangedBy FROM phoromatic_accounts ORDER BY AccountID')


def audit_total():
    return tn.sql('SELECT COUNT(*) AS n FROM phoromatic_server_audit')[0]['n']


def audit(action, aid=None):
    where = "Action='%s'" % action + (" AND TenantID='%s'" % aid if aid else '')
    return tn.sql('SELECT * FROM phoromatic_server_audit WHERE %s ORDER BY AuditID' % where)


def forget_audit(where):
    """The audit table refuses DELETE; a test that planted or caused rows takes the guard off for one statement and puts it back."""
    tn.sql_exec('DROP TRIGGER phoromatic_server_audit_no_delete; DELETE FROM phoromatic_server_audit WHERE %s; %s' % (where, TRIGGER))


def any_token(b):
    for p in ('/?settings', '/?users', '/?systems', '/?schedules', '/?private_repository', '/?results'):
        m = re.search(r'name="token_submit" value="([0-9a-f]+)"', b.get(p)[2])
        if m:
            return m.group(1)
    return '0' * 40


def act(b, action, token=None, headers=None, **fields):
    """A console change as a browser does it: the POST, then the page it sends the person to. (status, location, page, token used)"""
    tok = token if token is not None else any_token(b)
    s, h, body = b.post('/?r_server_action', dict(fields, action=action, token_submit=tok), headers=headers)
    loc = h.get('Location')
    return s, loc, (b.get(loc)[2] if loc else body)


def console_token(b):
    return b.token('/?server_tenants/new')


def sa_act(b, action, **fields):
    return act(b, action, token=console_token(b), **fields)


def seed_tenant_data(aid):
    """One row of canary data in every table of the tenant, and files in its folder (the repository folder included)."""
    payload = json.dumps({'rows': [], 'done': [], 't2': aid})
    out = tn.srv_php(fixture.SEED_PHP % {'storage': tn.STORAGE}, stdin=payload)
    assert 'seeded' in out, out
    fixture.put_files({'%s/accounts/%s/results/T4CANARY/composite.xml' % (tn.STORAGE, aid): b'canary', '%s/accounts/%s/private-repo/tests/t4canary/1.0.0.zip' % (tn.STORAGE, aid): b'zip',
                       '%s/accounts/%s/systems/T4SYS/log.txt' % (tn.STORAGE, aid): b'log'})


def tenant_tables():
    return [r['name'] for r in tn.sql("SELECT name FROM sqlite_master WHERE type='table' AND name LIKE 'phoromatic_%' ORDER BY name")
            if any(c['name'] == 'AccountID' for c in tn.sql('PRAGMA table_info(%s)' % r['name']))]


def data_snapshot(aid):
    """Everything of the tenant except its account row (which changes with its state)."""
    s = tn.snapshot(aid)
    s['rows'].pop('phoromatic_accounts', None)
    return s


class Console(unittest.TestCase):
    """Two accounts, T1 and T2, and server administrators made by the test (nobody is one otherwise)."""
    @classmethod
    def setUpClass(cls):
        cls.st = tn.state()
        cls.t1, cls.t2 = cls.st['T1']['aid'], cls.st['T2']['aid']
        tn.reset_v1_limits()
        tn.cli('server-admin-grant', 'tn1lvl2')   # a server administrator at account level 2: the role is not the level
        cls.sa = tn.Browser().login('tn1lvl2')
        cls.root = tn.Browser().login_root()

    @classmethod
    def tearDownClass(cls):
        tn.cli('server-admin-revoke', 'tn1lvl2')

    def new_tenant(self, label='T4', browser=None, name=None):
        """A tenant made through the console; returns (aid, name, first user)."""
        b = browser or self.sa
        tag = uuid.uuid4().hex[:6]
        name = name or '%s %s' % (label, tag)
        user = 't4u' + tag
        s, loc, page = act(b, 'tenant_create', token=console_token(b), tenant_name=name, register_username=user, register_password=tn.PW, register_password_confirm=tn.PW,
                           register_email=user + '@example.com', seed_accountid='')
        self.assertEqual(s, 302, page[:300])
        m = re.search(r'server_tenants/([A-Za-z0-9]{6})$', loc or '')
        self.assertTrue(m, (loc, re.sub(r'\s+', ' ', page)[:300]))
        aid = m.group(1)
        return aid, name, user


class Refusals(Console):
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
                    for leak in ('Create an account', 'Suspend', 'Purge', 'Server audit</h1>', 'tenant_created', self.st['T2']['aid'] + '</td>', 'Account ID:'):
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
        tn.sql_exec("UPDATE phoromatic_users SET AdminLevel=-3 WHERE UserName='tn1lvl3'")
        self.addCleanup(tn.sql_exec, "UPDATE phoromatic_users SET AdminLevel=3 WHERE UserName='tn1lvl3'")
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
                self.assertEqual(self.sa.get('/?r_server_action&action=%s&account_id=%s' % (action, aid))[0], 405)
        self.assertEqual((accounts_state(), audit_total()), before)
        # the same change with the right token and origin is taken (so the refusals above are not a broken form)
        act(self.sa, 'tenant_rename', token=good, account_id=aid, tenant_name=name + ' ok')
        self.assertEqual(acct(aid)['GroupName'], name + ' ok')


class Lifecycle(Console):
    def test_create_makes_a_named_account_with_a_first_user_who_can_log_in_and_audits_it(self):
        total = audit_total()
        aid, name, user = self.new_tenant('T4 create')
        row = acct(aid)
        self.assertEqual((row['GroupName'], row['State'], row['PurgedOn']), (name, 'active', None))
        self.assertEqual(row['CreatedBy'], 'tn1lvl2')
        self.assertEqual(tn.sql("SELECT AdminLevel FROM phoromatic_users WHERE UserName='%s'" % user)[0]['AdminLevel'], 1)
        self.assertEqual(tn.sql("SELECT COUNT(*) AS n FROM phoromatic_server_roles r JOIN phoromatic_users u ON u.UserID = r.UserID WHERE u.UserName='%s'" % user)[0]['n'], 0, 'a new tenant admin is not a server administrator')
        tn.Browser().login(user)
        rows = audit('tenant_created', aid)
        self.assertEqual(len(rows), 1)
        self.assertEqual((rows[0]['Actor'], rows[0]['ActorType'], rows[0]['SubjectLabel']), ('tn1lvl2', 'user', name))
        self.assertEqual(audit_total(), total + 1)
        self.assertIn(name, self.sa.get('/?server_tenants')[2])

    def test_create_refuses_bad_input_without_leaving_anything(self):
        before = (accounts_state(), audit_total(), tn.sql('SELECT COUNT(*) AS n FROM phoromatic_users')[0]['n'])
        aid, name, user = self.new_tenant('T4 dup')
        ok = dict(register_password=tn.PW, register_password_confirm=tn.PW, seed_accountid='')
        cases = [('empty name', dict(tenant_name='  ', register_username='t4bad1', register_email='a@example.com')),
                 ('same name, other case', dict(tenant_name=name.upper(), register_username='t4bad2', register_email='a@example.com')),
                 ('the name of a T1 account', dict(tenant_name=tn.sql("SELECT GroupName FROM phoromatic_accounts WHERE AccountID='%s'" % self.t2)[0]['GroupName'] or 'x', register_username='t4bad3', register_email='a@example.com')),
                 ('too long', dict(tenant_name='n' * 65, register_username='t4bad4', register_email='a@example.com')),
                 ('control character', dict(tenant_name='bad\x07name', register_username='t4bad5', register_email='a@example.com')),
                 ('taken user name', dict(tenant_name='T4 other name', register_username='tn1admin', register_email='a@example.com')),
                 ('bad email', dict(tenant_name='T4 other name', register_username='t4bad6', register_email='nope')),
                 ('short password', dict(tenant_name='T4 other name', register_username='t4bad7', register_email='a@example.com', register_password='abc', register_password_confirm='abc'))]
        mid = (accounts_state(), audit_total(), tn.sql('SELECT COUNT(*) AS n FROM phoromatic_users')[0]['n'])
        for label, f in cases:
            with self.subTest(label):
                if label == 'the name of a T1 account':
                    tn.sql_exec("UPDATE phoromatic_accounts SET GroupName='TN Two T4' WHERE AccountID='%s'" % self.t2)
                    self.addCleanup(tn.sql_exec, "UPDATE phoromatic_accounts SET GroupName=NULL WHERE AccountID='%s'" % self.t2)
                    f['tenant_name'] = 'tn two t4'
                s, loc, page = act(self.sa, 'tenant_create', token=console_token(self.sa), **dict(ok, **f))
                self.assertEqual(loc, '/?server_tenants/new')
                self.assertIn('<strong>', page)
                mid2 = (len(accounts_state()), audit_total(), tn.sql('SELECT COUNT(*) AS n FROM phoromatic_users')[0]['n'])
                self.assertEqual(mid2, (len(mid[0]), mid[1], mid[2]), label)
        self.assertGreater(len(mid[0]), len(before[0]))

    def test_rename_is_unique_ignoring_case_audited_and_escaped_everywhere(self):
        aid, name, user = self.new_tenant('T4 ren')
        aid2, name2, _ = self.new_tenant('T4 ren2')
        bad = [('', 'Give the account a name'), (name2.lower(), 'already'), ('x' * 65, 'at most'), ('a\x07b', 'control')]
        before = acct(aid)['GroupName']
        for new, why in bad:
            with self.subTest(new[:10]):
                s, loc, page = sa_act(self.sa, 'tenant_rename', account_id=aid, tenant_name=new)
                self.assertIn(why, page)
                self.assertEqual(acct(aid)['GroupName'], before)
        xss = '<script>alert("t4")</script> <b>x</b>'
        sa_act(self.sa, 'tenant_rename', account_id=aid, tenant_name=xss)
        self.assertEqual(acct(aid)['GroupName'], xss)
        rows = audit('tenant_renamed', aid)
        self.assertEqual(json.loads(rows[-1]['Detail'])['before'], before)
        member = tn.Browser().login(user)
        pages = {'console list': self.sa.get('/?server_tenants')[2], 'console detail': self.sa.get('/?server_tenants/%s' % aid)[2], 'delete page': None,
                 'audit page': self.sa.get('/?server_audit')[2], 'tenant main': member.get('/?main')[2], 'tenant settings': member.get('/?settings')[2], 'tenant users': member.get('/?users')[2]}
        sa_act(self.sa, 'tenant_suspend', account_id=aid)
        pages['delete page'] = self.sa.get('/?server_tenants/%s/delete' % aid)[2]
        sa_act(self.sa, 'tenant_resume', account_id=aid)
        for label, body in pages.items():
            with self.subTest(label):
                self.assertNotIn('<script>alert("t4")', body)
                self.assertNotIn('<b>x</b>', body)
        self.assertIn('&lt;script&gt;alert(&quot;t4&quot;)&lt;/script&gt;', pages['console detail'] + pages['console list'])
        # the tenant's own administrator cannot rename it any more
        member.post('/?users', {'group_name': 'TENANT-CHOSEN', 'token_submit': member.token('/?users')})
        self.assertEqual(acct(aid)['GroupName'], xss)
        self.assertNotIn('Update Group Name', member.get('/?users')[2])

    def test_suspend_and_resume_behave_as_the_state_gate_defines(self):
        aid, name, user = self.new_tenant('T4 sus')
        msi = tn.approved_machine(aid)
        member = tn.Browser().login(user)
        self.assertNotIn('error', tn.v1('ping', aid, msi))
        before = data_snapshot(aid)
        # a suspend with a reason; an own-account suspend is refused (that session would end)
        s, loc, page = sa_act(self.sa, 'tenant_suspend', account_id=aid, reason='T4 test')
        self.assertIn('was suspended', page)
        row = acct(aid)
        self.assertEqual((row['State'], row['StateReason'], row['StateChangedBy']), ('suspended', 'T4 test', 'tn1lvl2'))
        self.assertEqual(tn.v1('ping', aid, msi).get('error'), SUSPENDED)
        self.assertNotIn('Log Out', member.get('/?main')[2], 'the open session ends on its next request')
        self.assertNotIn('Log-In Successful', tn.Browser().post('/?login', {'username': user, 'password': tn.PW})[2])
        self.assertEqual(data_snapshot(aid), before, 'suspending touches no data')
        # only an active account can be suspended, only a suspended one resumed
        self.assertIn('only an account that is active', sa_act(self.sa, 'tenant_suspend', account_id=aid)[2])
        sa_act(self.sa, 'tenant_resume', account_id=aid)
        self.assertEqual(acct(aid)['State'], 'active')
        self.assertNotIn('error', tn.v1('ping', aid, msi))
        tn.Browser().login(user)
        self.assertIn('only an account that is suspended', sa_act(self.sa, 'tenant_resume', account_id=aid)[2])
        self.assertEqual([r['Action'] for r in audit('tenant_suspended', aid) + audit('tenant_resumed', aid)], ['tenant_suspended', 'tenant_resumed'])
        # the server administrator's own account cannot be suspended or deleted by them
        s, loc, page = sa_act(self.sa, 'tenant_suspend', account_id=self.t1)
        self.assertIn('signed in to this account', page)
        self.assertEqual(acct(self.t1)['State'], 'active')

    def test_suspending_an_account_with_a_running_system_asks_for_the_tick(self):
        aid, name, user = self.new_tenant('T4 run')
        msi = tn.approved_machine(aid)
        tn.sql_exec("UPDATE phoromatic_systems SET CurrentTask='Running a test (T4)', LastCommunication='%s' WHERE AccountID='%s'" % (tn.sql('SELECT datetime("now") AS t')[0]['t'], aid))
        self.assertIn('running a task now', sa_act(self.sa, 'tenant_suspend', account_id=aid)[2])
        self.assertEqual(acct(aid)['State'], 'active')
        self.assertIn('I understand', self.sa.get('/?server_tenants/%s/suspend' % aid)[2])
        sa_act(self.sa, 'tenant_suspend', account_id=aid, confirm_running='1')
        self.assertEqual(acct(aid)['State'], 'suspended')

    def test_delete_needs_a_suspended_account_and_the_typed_name_and_restore_keeps_every_row_and_file(self):
        aid, name, user = self.new_tenant('T4 del')
        seed_tenant_data(aid)
        before = data_snapshot(aid)
        self.assertGreater(len(before['rows']), 20)
        # not from active
        self.assertIn('only an account that is suspended', sa_act(self.sa, 'tenant_delete', account_id=aid, confirm_name=name)[2])
        sa_act(self.sa, 'tenant_suspend', account_id=aid)
        for wrong in ('', 'nope', name.upper(), name + ' '):
            if wrong == name + ' ':
                continue   # (surrounding spaces are not part of a name)
            with self.subTest(wrong):
                self.assertIn('Type the account', sa_act(self.sa, 'tenant_delete', account_id=aid, confirm_name=wrong)[2])
                self.assertEqual(acct(aid)['State'], 'suspended')
        sa_act(self.sa, 'tenant_delete', account_id=aid, confirm_name=name, reason='T4')
        row = acct(aid)
        self.assertEqual((row['State'], row['PurgedOn']), ('deleted', None))
        self.assertTrue(row['DeletedOn'])
        self.assertEqual(data_snapshot(aid), before, 'delete is a tombstone: everything stays')
        self.assertNotIn(aid, self.sa.get('/?server_tenants')[2], 'the default list hides deleted accounts')
        self.assertIn(aid, self.sa.get('/?server_tenants/show/deleted')[2])
        self.assertEqual(tn.v1('ping', aid, 'x').get('error'), 'Invalid User')
        self.assertNotIn('Log-In Successful', tn.Browser().post('/?login', {'username': user, 'password': tn.PW})[2])
        sa_act(self.sa, 'tenant_restore', account_id=aid)
        row = acct(aid)
        self.assertEqual((row['State'], row['DeletedOn']), ('suspended', None))
        self.assertEqual(data_snapshot(aid), before, 'restore brings back the same rows and files')
        sa_act(self.sa, 'tenant_resume', account_id=aid)
        tn.Browser().login(user)
        self.assertEqual([r['Action'] for r in audit('tenant_deleted', aid) + audit('tenant_restored', aid)], ['tenant_deleted', 'tenant_restored'])
        self.assertIn('only an account that is deleted', sa_act(self.sa, 'tenant_restore', account_id=aid)[2])

    def test_purge_removes_the_data_only_from_deleted_and_only_with_the_name_and_id_typed(self):
        aid, name, user = self.new_tenant('T4 purge')
        seed_tenant_data(aid)
        t1_before, t2_before = tn.snapshot(self.t1), tn.snapshot(self.t2)
        before = data_snapshot(aid)
        def purge(**f):
            return sa_act(self.sa, 'tenant_purge', account_id=aid, **f)[2]
        # never from active or suspended, even with everything typed right
        self.assertIn('Only a deleted account', purge(confirm_name=name, confirm_id=aid))
        sa_act(self.sa, 'tenant_suspend', account_id=aid)
        self.assertIn('Only a deleted account', purge(confirm_name=name, confirm_id=aid))
        sa_act(self.sa, 'tenant_delete', account_id=aid, confirm_name=name)
        for label, f in (('nothing typed', {}), ('name only', dict(confirm_name=name)), ('id only', dict(confirm_id=aid)), ('wrong name', dict(confirm_name='nope', confirm_id=aid)),
                         ('wrong id', dict(confirm_name=name, confirm_id=self.t2)), ('id in lower case', dict(confirm_name=name, confirm_id=aid.lower()))):
            with self.subTest(label):
                self.assertIn('Type the account', purge(**f))
                self.assertEqual(data_snapshot(aid), before)
                self.assertIsNone(acct(aid)['PurgedOn'])
        self.assertTrue(tn.srv_sh('test -d %s/accounts/%s && echo yes' % (tn.STORAGE, aid)).strip())
        self.assertIn('was purged', purge(confirm_name=name, confirm_id=aid))
        row = acct(aid)
        self.assertEqual((row['State'], row['GroupName']), ('deleted', name))
        self.assertTrue(row['PurgedOn'])
        # every row of the account is gone, in every table that has an account column, except the account row, its users and the audit tables
        left = {t: tn.sql("SELECT COUNT(*) AS n FROM %s WHERE AccountID='%s'" % (t, aid))[0]['n'] for t in tenant_tables() if t not in KEEP}
        self.assertEqual({t: n for t, n in left.items() if n}, {})
        self.assertEqual(tn.sql("SELECT COUNT(*) AS n FROM phoromatic_users WHERE AccountID='%s'" % aid)[0]['n'], 1, 'users are never deleted by a purge')
        self.assertEqual(tn.srv_sh('ls -A %s/accounts | grep -c "%s"' % (tn.STORAGE, aid)).strip(), '0', 'no folder and no .purged-* leftover')
        rows = audit('tenant_purged', aid)
        self.assertEqual(len(rows), 1)
        removed = json.loads(rows[0]['Detail'])['rows_removed']
        self.assertGreater(sum(removed.values()), 15)
        self.assertEqual(len(audit('tenant_created', aid)), 1, 'the audit keeps the account\'s history')
        # nothing left to do to it; its id is never handed out again; other accounts are untouched
        for action in ('tenant_restore', 'tenant_resume', 'tenant_suspend', 'tenant_delete'):
            s, loc, page = sa_act(self.sa, action, account_id=aid, confirm_name=name)
            self.assertIn(acct(aid)['State'], ('deleted',))
            self.assertIn('purged', page)
        self.assertIn('Nothing is left to change', self.sa.get('/?server_tenants/%s' % aid)[2])
        self.assertIn('already purged', purge(confirm_name=name, confirm_id=aid))
        s, loc, page = act(self.sa, 'tenant_create', token=console_token(self.sa), tenant_name='T4 reuse ' + aid, register_username='t4re' + aid.lower(), register_password=tn.PW,
                           register_password_confirm=tn.PW, register_email='re@example.com', seed_accountid=aid)
        self.assertNotIn(aid, loc, 'a purged id is not reissued')
        self.assertEqual((tn.snapshot(self.t1), tn.snapshot(self.t2)), (t1_before, t2_before))

    def test_a_purge_that_cannot_remove_the_folder_says_so_and_the_account_is_purged(self):
        aid, name, user = self.new_tenant('T4 stuck')
        seed_tenant_data(aid)
        sa_act(self.sa, 'tenant_suspend', account_id=aid)
        sa_act(self.sa, 'tenant_delete', account_id=aid, confirm_name=name)
        # a folder inside the account's that cannot be emptied: the directory is immutable-like for this process because it is a mount point
        tn.srv_sh('mkdir -p %s/accounts/%s/stuck && mount -t tmpfs tmpfs %s/accounts/%s/stuck 2>/dev/null && echo x > %s/accounts/%s/stuck/f' % ((tn.STORAGE, aid) * 3))
        if tn.srv_sh('mount | grep -c "accounts/%s/stuck"' % aid).strip() == '0':
            self.skipTest('the server container cannot mount (no privilege), so a folder that cannot be removed cannot be made')
        s, loc, page = sa_act(self.sa, 'tenant_purge', account_id=aid, confirm_name=name, confirm_id=aid)
        self.assertIn('could not be removed completely', page)
        self.assertEqual(len(audit('tenant_purge_cleanup_failed', aid)), 1)
        self.assertTrue(acct(aid)['PurgedOn'])

    def test_root_can_do_all_of_it_and_is_recorded_as_root(self):
        aid, name, user = self.new_tenant('T4 root', browser=self.root)
        row = audit('tenant_created', aid)[0]
        self.assertEqual((row['ActorType'], row['Actor']), ('root', 'RootAdmin'))
        self.assertEqual(acct(aid)['CreatedBy'], 'RootAdmin')
        for action, f in (('tenant_rename', dict(tenant_name=name + ' r')), ('tenant_suspend', {}), ('tenant_resume', {}), ('tenant_suspend', {}), ('tenant_delete', dict(confirm_name=name + ' r')), ('tenant_restore', {}),
                          ('tenant_resume', {}), ('tenant_suspend', {}), ('tenant_delete', dict(confirm_name=name + ' r')), ('tenant_purge', dict(confirm_name=name + ' r', confirm_id=aid))):
            with self.subTest(action):
                sa_act(self.root, action, account_id=aid, **f)
        self.assertTrue(acct(aid)['PurgedOn'])
        who = {r['ActorType'] for r in tn.sql("SELECT ActorType FROM phoromatic_server_audit WHERE TenantID='%s'" % aid)}
        self.assertEqual(who, {'root'})
        self.assertIn('Server audit', self.root.get('/?server_audit')[2])
        self.assertIn('Accounts', self.root.get('/?server_tenants')[2])
        # root still has no ?server_settings (its settings are on ?admin_config), and the tenant pages are not root's
        self.assertNotIn('Server settings</h1>', self.root.get('/?server_settings')[2])

    def test_a_root_policy_change_is_recorded_as_the_root_actor_with_no_account(self):
        # the T3 review: a root HTTPS or policy change wrote its admin audit row with an empty account and the actor type "user"
        tok = self.root.token('/?admin_config')
        first = tn.sql('SELECT COALESCE(MAX(AuditID), 0) AS m FROM phoromatic_admin_audit')[0]['m']
        current = re.search(r'name="tenant_auto_approve" value="(\w+)" checked', self.root.get('/?admin_config')[2]).group(1)
        other = 'allowed' if current == 'forbidden' else 'forbidden'
        for want in (other, current):
            self.root.post('/?admin_config', {'tenant_auto_approve': want, 'token_submit': tok})
        rows = tn.sql("SELECT AccountID, ActorType, Actor, ActorDetail FROM phoromatic_admin_audit WHERE AuditID > %d AND Action='tenant_auto_approve_set'" % first)
        self.assertEqual(len(rows), 2)
        for r in rows:
            self.assertEqual((r['ActorType'], r['Actor'], r['ActorDetail']), ('root', 'RootAdmin', 'root login'))
            self.assertIn(r['AccountID'], ('', None), 'root belongs to no account')
        server_rows = tn.sql("SELECT ActorType, Actor FROM phoromatic_server_audit WHERE Action='server_setting_changed' AND SubjectID='tenant_auto_approve_set' ORDER BY AuditID DESC LIMIT 2")
        self.assertEqual([(r['ActorType'], r['Actor']) for r in server_rows], [('root', 'RootAdmin')] * 2)


class AuditPage(Console):
    def test_the_audit_page_is_read_only_paged_escaped_and_for_server_administrators_only(self):
        aid, name, user = self.new_tenant('T4 <i>aud</i>')
        total = audit_total()
        # (at least two pages: pad with settings rows the way the console would)
        body = self.sa.get('/?server_audit')[2]
        self.assertIn('Server audit', body)
        self.assertIn('tenant_created', body)
        self.assertNotIn('<form', body.split('Server audit</h1>')[1].split('<div id="pts_phoromatic_bottom_footer">')[0], 'read only: no form on the page')
        self.assertNotIn('<i>aud</i>', body)
        self.assertIn('&lt;i&gt;aud&lt;/i&gt;', body)
        pages = max(1, -(-total // 50))
        self.assertIn('Page 1 of %d' % pages, body)
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


class UpgradeNotice(Console):
    TEXT = 'What changed in this upgrade'

    @classmethod
    def setUpClass(cls):
        super().setUpClass()
        # the server counts as upgraded when its audit holds a schema migration row; a fixture server was made new, so one is planted (dated ahead of the test users)
        tn.sql_exec("INSERT INTO phoromatic_server_audit (EventTime, ActorType, Actor, Action, SubjectType, SubjectID) VALUES ('2099-01-01 00:00:00', 'migration', 'migration', 'migration', 'schema', '59')")

    @classmethod
    def tearDownClass(cls):
        forget_audit("Action IN ('migration', 'notice_dismissed') AND (EventTime = '2099-01-01 00:00:00' OR SubjectLabel = 'upgrade-0.9.14')")
        super().tearDownClass()

    def test_level_one_users_and_server_administrators_see_it_once_each_and_nobody_else_does(self):
        t1a, t2a = tn.Browser().login('tn1admin'), tn.Browser().login('tn2admin')
        lvl2_sa, lvl3, lvl10 = self.sa, tn.Browser().login('tn1lvl3'), tn.Browser().login('tn1lvl10')
        body = t1a.get('/?main')[2]
        self.assertIn(self.TEXT, body)
        for line in ('public results page is gone', 'Results feed (RSS) links changed', 'Self-registration is closed', 'New systems wait for approval', 'enrollment code', 'server settings',
                     'HTTPS', 'private test repository'):
            self.assertIn(line, body)
        self.assertNotIn('Server</a> pages', body, 'only a server administrator is pointed at the console')
        self.assertIn(self.TEXT, lvl2_sa.get('/?main')[2], 'a server administrator at level 2')
        self.assertIn('Server</a> pages', lvl2_sa.get('/?main')[2])
        for label, b in (('level 3', lvl3), ('viewer', lvl10), ('root', self.root)):
            self.assertNotIn(self.TEXT, b.get('/?main')[2] if label != 'root' else b.get('/?admin')[2], label)
        # a refused dismissal changes nothing; a GET is not a dismissal
        total = audit_total()
        self.assertEqual(lvl3.get('/?r_server_action&action=dismiss_notice')[0], 405)
        act(lvl3, 'dismiss_notice')
        act(t1a, 'dismiss_notice', token='0' * 40)
        act(t1a, 'dismiss_notice', token=any_token(t1a), headers=EVIL)
        self.assertEqual(audit_total(), total)
        self.assertIn(self.TEXT, t1a.get('/?main')[2])
        # dismissed by one user: gone for that user, in a new session too, and still there for the others
        act(t1a, 'dismiss_notice', token=any_token(t1a))
        self.assertNotIn(self.TEXT, t1a.get('/?main')[2])
        self.assertNotIn(self.TEXT, tn.Browser().login('tn1admin').get('/?main')[2])
        self.assertIn(self.TEXT, t2a.get('/?main')[2])
        self.assertIn(self.TEXT, lvl2_sa.get('/?main')[2])
        self.assertEqual(audit_total(), total + 1)
        row = audit('notice_dismissed')[-1]
        self.assertEqual((row['Actor'], row['SubjectLabel'], row['TenantID']), ('tn1admin', 'upgrade-0.9.14', self.t1))

    def test_a_server_that_was_made_new_or_a_user_made_after_the_upgrade_sees_nothing(self):
        # users made now are newer than a migration dated in 2020
        forget_audit("Action = 'migration' AND EventTime = '2099-01-01 00:00:00'")
        self.addCleanup(tn.sql_exec, "INSERT INTO phoromatic_server_audit (EventTime, ActorType, Actor, Action, SubjectType, SubjectID) VALUES ('2099-01-01 00:00:00', 'migration', 'migration', 'migration', 'schema', '59')")
        self.assertNotIn(self.TEXT, tn.Browser().login('tn2admin').get('/?main')[2], 'no migration row: nothing changed')
        tn.sql_exec("INSERT INTO phoromatic_server_audit (EventTime, ActorType, Actor, Action, SubjectType, SubjectID) VALUES ('2020-01-01 00:00:00', 'migration', 'migration', 'migration', 'schema', '59')")
        self.addCleanup(forget_audit, "EventTime = '2020-01-01 00:00:00'")
        self.assertNotIn(self.TEXT, tn.Browser().login('tn2admin').get('/?main')[2], 'the user is newer than the upgrade')


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
