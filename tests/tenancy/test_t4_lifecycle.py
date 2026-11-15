"""T4: the tenant lifecycle through the console: create, rename (escaped), suspend, resume, delete, restore, purge, as a server administrator and as root.
"""
import json
import re
import unittest
import uuid

import fixture
import tn
from t4 import *



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
        self.same(data_snapshot(aid), before, 'suspending touches no data')
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
        for task in ('Running a test (T4)', 'Issued: T4 schedule', 'Finishing T4 schedule', 'Installing T4'):
            with self.subTest(task):
                tn.sql_exec("UPDATE phoromatic_systems SET CurrentTask='%s', LastCommunication='%s' WHERE AccountID='%s'" % (task, tn.sql('SELECT datetime("now") AS t')[0]['t'], aid))
                self.assertIn('running a task now', sa_act(self.sa, 'tenant_suspend', account_id=aid)[2])
                self.assertEqual(acct(aid)['State'], 'active')
        tn.sql_exec("UPDATE phoromatic_systems SET CurrentTask='Idling' WHERE AccountID='%s'" % aid)
        sa_act(self.sa, 'tenant_suspend', account_id=aid)
        self.assertEqual(acct(aid)['State'], 'suspended', 'an idle system does not ask for the tick')
        sa_act(self.sa, 'tenant_resume', account_id=aid)
        tn.sql_exec("UPDATE phoromatic_systems SET CurrentTask='Running a test (T4)' WHERE AccountID='%s'" % aid)
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
        self.same(data_snapshot(aid), before, 'delete is a tombstone: everything stays')
        self.assertNotIn(aid, self.sa.get('/?server_tenants')[2], 'the default list hides deleted accounts')
        self.assertIn(aid, self.sa.get('/?server_tenants/show/deleted')[2])
        self.assertEqual(tn.v1('ping', aid, 'x').get('error'), 'Invalid User')
        self.assertNotIn('Log-In Successful', tn.Browser().post('/?login', {'username': user, 'password': tn.PW})[2])
        sa_act(self.sa, 'tenant_restore', account_id=aid)
        row = acct(aid)
        self.assertEqual((row['State'], row['DeletedOn']), ('suspended', None))
        self.same(data_snapshot(aid), before, 'restore brings back the same rows and files')
        sa_act(self.sa, 'tenant_resume', account_id=aid)
        tn.Browser().login(user)
        self.assertEqual([r['Action'] for r in audit('tenant_deleted', aid) + audit('tenant_restored', aid)], ['tenant_deleted', 'tenant_restored'])
        self.assertIn('only an account that is deleted', sa_act(self.sa, 'tenant_restore', account_id=aid)[2])

    def test_purge_removes_the_data_only_from_deleted_and_only_with_the_name_and_id_typed(self):
        aid, name, user = self.new_tenant('T4 purge')
        seed_tenant_data(aid)
        empty = [x for x in tenant_tables() if tn.sql("SELECT COUNT(*) AS n FROM %s WHERE AccountID='%s'" % (x, aid))[0]['n'] == 0]
        self.assertEqual(empty, [], 'the seed must leave a row in every table that has an account column, or the purge check below proves nothing for it')
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
                self.same(data_snapshot(aid), before)
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
        self.addCleanup(tn.srv_sh, 'umount %s/accounts/.purged-%s-*/stuck; rm -rf %s/accounts/.purged-%s-*' % (tn.STORAGE, aid, tn.STORAGE, aid))
        s, loc, page = sa_act(self.sa, 'tenant_purge', account_id=aid, confirm_name=name, confirm_id=aid)
        self.assertIn('could not be removed completely', page)
        self.assertEqual(len(audit('tenant_purge_cleanup_failed', aid)), 1)
        self.assertTrue(acct(aid)['PurgedOn'])

    def purged_tenant(self, label):
        aid, name, user = self.new_tenant(label)
        seed_tenant_data(aid)
        sa_act(self.sa, 'tenant_suspend', account_id=aid)
        sa_act(self.sa, 'tenant_delete', account_id=aid, confirm_name=name)
        return aid, name

    def purge(self, aid, name):
        return sa_act(self.sa, 'tenant_purge', account_id=aid, confirm_name=name, confirm_id=aid)[2]

    def test_a_purged_account_cannot_be_renamed_in_the_console_or_by_command(self):
        aid, name = self.purged_tenant('T4 renp')
        self.assertIn('was purged', self.purge(aid, name))
        self.assertIn('purged', sa_act(self.sa, 'tenant_rename', account_id=aid, tenant_name='T4 back from the dead')[2])
        self.assertEqual(acct(aid)['GroupName'], name)
        out = tn.cli('server-tenant-rename', aid, '--name', 'T4 back again')
        self.assertIn('purged', out)
        self.assertEqual(acct(aid)['GroupName'], name)
        self.assertEqual(audit('tenant_renamed', aid), [])

    def test_a_purge_also_removes_the_result_export_and_any_set_aside_folder(self):
        aid, name = self.purged_tenant('T4 export')
        fixture.put_files({'%s/result-export/%s/index.json' % (tn.STORAGE, aid): b'{}', '%s/result-export/%s/a/b.txt' % (tn.STORAGE, aid): b'x',
                           '%s/result-export/TNBBBB/keep.txt' % tn.STORAGE: b'other tenant'})
        self.addCleanup(tn.srv_sh, 'rm -rf %s/result-export/TNBBBB' % tn.STORAGE)
        self.assertIn('was purged', self.purge(aid, name))
        self.assertEqual(tn.srv_sh('ls -A %s/result-export | grep -c %s' % (tn.STORAGE, aid)).strip(), '0')
        self.assertEqual(tn.srv_sh('cat %s/result-export/TNBBBB/keep.txt' % tn.STORAGE), 'other tenant', 'another account\'s export is not touched')
        self.assertEqual(audit('tenant_purge_cleanup_failed', aid), [])

    def test_a_purged_account_whose_folder_is_a_link_loses_the_target_and_nothing_a_link_inside_it_points_at(self):
        aid, name = self.purged_tenant('T4 link')
        tn.srv_sh('rm -rf /tmp/t4-target /tmp/t4-keep && mkdir -p /tmp/t4-target/sub /tmp/t4-keep && echo data > /tmp/t4-target/sub/f && echo keep > /tmp/t4-keep/k && ln -s /tmp/t4-keep /tmp/t4-target/inner')
        tn.srv_sh('rm -rf %s/accounts/%s && ln -s /tmp/t4-target %s/accounts/%s' % ((tn.STORAGE, aid) * 2))
        self.addCleanup(tn.srv_sh, 'rm -rf /tmp/t4-target /tmp/t4-keep')
        self.assertIn('was purged', self.purge(aid, name))
        self.assertEqual(tn.srv_sh('test -e /tmp/t4-target && echo there; test -L %s/accounts/%s && echo link' % (tn.STORAGE, aid)).strip(), '')
        self.assertEqual(tn.srv_sh('cat /tmp/t4-keep/k').strip(), 'keep', 'a link inside the tree is removed, never followed')

    def test_a_purged_account_whose_folder_is_a_link_into_the_storage_is_refused_not_followed(self):
        aid, name = self.purged_tenant('T4 evil link')
        t2_before = tn.snapshot(self.t2)
        tn.srv_sh('rm -rf %s/accounts/%s && ln -s %s/accounts/%s %s/accounts/%s' % (tn.STORAGE, aid, tn.STORAGE, self.t2, tn.STORAGE, aid))
        page = self.purge(aid, name)
        self.assertIn('could not be removed completely', page)
        self.assertEqual(tn.snapshot(self.t2), t2_before, 'the other account\'s folder is intact')
        self.assertTrue(acct(aid)['PurgedOn'])
        self.assertEqual(len(audit('tenant_purge_cleanup_failed', aid)), 1)
        tn.srv_sh('rm %s/accounts/%s' % (tn.STORAGE, aid))

    def sweep(self):
        return tn.php_boot('phoromatic_server::prepare_database(); echo phoromatic_tenant_admin::sweep_purged();').strip()

    def test_a_set_aside_folder_named_for_a_live_account_removes_only_itself(self):
        # (a database restored after a failed cleanup: the folder says an account was purged that the database says is alive)
        live, live_name, _ = self.new_tenant('T4 sweep orphan')
        seed_tenant_data(live)
        fixture.put_files({'%s/result-export/%s/keep.txt' % (tn.STORAGE, live): b'keep', '%s/accounts/.purged-%s-20260101000000/old.txt' % (tn.STORAGE, live): b'old',
                           '%s/accounts/.purged-ZZZZZZ-20260101000000/old.txt' % tn.STORAGE: b'unknown account'})
        before = tn.snapshot(live)
        before_ls = tn.srv_sh('ls -A %s/accounts | grep -E "^[.]purged-[A-Za-z0-9]+-[0-9]{14}$"' % tn.STORAGE)
        self.sweep()
        self.assertEqual(tn.srv_sh('ls -A %s/accounts | grep -E "^[.]purged-[A-Za-z0-9]+-[0-9]{14}$"' % tn.STORAGE).strip(), '', 'the orphan folders are gone (there were: %s)' % before_ls.split())
        self.assertEqual(tn.srv_sh('test -d %s/accounts/.purged-TNBBBB-20260101 && echo kept' % tn.STORAGE).strip(), 'kept', 'the fixture\'s own folder, which is not a set-aside folder by name, is left alone')
        self.assertEqual(tn.snapshot(live), before, 'the live account\'s rows and folder are untouched')
        self.assertEqual(tn.srv_sh('cat %s/result-export/%s/keep.txt' % (tn.STORAGE, live)), 'keep', 'and so is its export')
        self.assertEqual(acct(live)['State'], 'active')

    def test_a_link_to_a_mount_point_or_to_another_users_folder_is_refused_not_followed(self):
        for label, prepare in (('another user\'s folder', 'mkdir -p /tmp/t4-foreign && echo x > /tmp/t4-foreign/f && chown 4242 /tmp/t4-foreign'),
                               ('a mount point', 'mkdir -p /tmp/t4-mnt && mount -t tmpfs tmpfs /tmp/t4-mnt && echo x > /tmp/t4-mnt/f')):
            with self.subTest(label):
                aid, name = self.purged_tenant('T4 link ' + label[:5])
                target = '/tmp/t4-foreign' if 'user' in label else '/tmp/t4-mnt'
                tn.srv_sh('%s' % prepare)
                if 'mount' in label and tn.srv_sh('mount | grep -c %s' % target).strip() == '0':
                    self.skipTest('the server container cannot mount')
                self.addCleanup(tn.srv_sh, 'umount %s 2>/dev/null; rm -rf %s %s/accounts/%s' % (target, target, tn.STORAGE, aid))
                tn.srv_sh('rm -rf %s/accounts/%s && ln -s %s %s/accounts/%s' % (tn.STORAGE, aid, target, tn.STORAGE, aid))
                self.assertIn('could not be removed completely', self.purge(aid, name))
                self.assertEqual(tn.srv_sh('cat %s/f' % target).strip(), 'x', 'the target was not touched')
                rows = audit('tenant_purge_cleanup_failed', aid)
                self.assertEqual(len(rows), 1)
                self.assertIn('mount point' if 'mount' in label else 'does not belong', rows[0]['Detail'])

    def test_the_hourly_sweep_removes_what_a_purge_left_and_audits_each_cleanup(self):
        aid, name = self.purged_tenant('T4 sweep')
        self.assertIn('was purged', self.purge(aid, name))
        live_aid, live_name, _ = self.new_tenant('T4 sweep live')
        seed_tenant_data(live_aid)
        live_before = tn.snapshot(live_aid)
        # a crash between the commit and the folder: the folder, a set-aside folder and an export are all still there
        fixture.put_files({'%s/accounts/%s/results/x/a.txt' % (tn.STORAGE, aid): b'x', '%s/accounts/.purged-%s-20260101000000/b.txt' % (tn.STORAGE, aid): b'y', '%s/result-export/%s/c.txt' % (tn.STORAGE, aid): b'z'})
        self.assertEqual(self.sweep(), '1')
        self.assertEqual(tn.srv_sh('ls -A %s/accounts %s/result-export | grep -c %s' % (tn.STORAGE, tn.STORAGE, aid)).strip(), '0')
        self.assertEqual(len(audit('tenant_purge_cleaned', aid)), 1)
        self.assertEqual(tn.snapshot(live_aid), live_before, 'an account that was not purged is not touched')
        self.assertEqual(self.sweep(), '0', 'nothing left to do')
        self.assertEqual(len(audit('tenant_purge_cleaned', aid)), 1)

    def test_a_sweep_that_cannot_remove_a_folder_audits_it_once_a_day_and_tries_again(self):
        aid, name = self.purged_tenant('T4 sweep stuck')
        self.assertIn('was purged', self.purge(aid, name))
        stuck = '%s/accounts/.purged-%s-20260101000000/stuck' % (tn.STORAGE, aid)
        tn.srv_sh('mkdir -p %s && mount -t tmpfs tmpfs %s && echo x > %s/f' % (stuck, stuck, stuck))
        if tn.srv_sh('mount | grep -c "%s"' % stuck).strip() == '0':
            self.skipTest('the server container cannot mount')
        self.addCleanup(tn.srv_sh, 'umount %s; rm -rf %s/accounts/.purged-%s-*' % (stuck, tn.STORAGE, aid))
        self.assertEqual(self.sweep(), '1')
        self.assertEqual(self.sweep(), '1')
        self.assertEqual(len(audit('tenant_purge_cleanup_failed', aid)), 1, 'recorded once, not every hour')
        self.assertEqual(audit('tenant_purge_cleaned', aid), [])
        tn.srv_sh('umount %s' % stuck)
        self.assertEqual(self.sweep(), '1')
        self.assertEqual(len(audit('tenant_purge_cleaned', aid)), 1, 'once the folder can go, the sweep removes it and says so')

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
        server_rows = tn.sql("SELECT ActorType, Actor FROM phoromatic_server_audit WHERE Action='server_setting_changed' AND Detail LIKE '%tenant_auto_approve_set%' ORDER BY AuditID DESC LIMIT 2")
        self.assertEqual([(r['ActorType'], r['Actor']) for r in server_rows], [('root', 'RootAdmin')] * 2)


if __name__ == '__main__':
    unittest.main()
