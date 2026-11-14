"""CP3 and CP3a of the settings simplification (Benchy 0.9.13): the Users page text and what each level is offered, and the admin-audit rows for
the account changes that decide who joins or what every client runs (auto-approve box, test options, client update script, a user's level, a new user),
plus the same-origin check on the Users forms. Run through tests/private-repo/run.sh (part of http/all)."""
import json
import re
import subprocess
import unittest
import uuid

from single_account import hide_other_accounts, restore_other_accounts
from test_private_repo_http import AID, SRV, sql
from test_private_repo_tokens import sql_exec
from test_private_repo_web import WebBase
from test_settings_csrf import restore_row, tok

SETTINGS = '/?settings'
USERS = '/?users'
EVIL = {'Origin': 'http://evil.example'}


def benchy(*args):
    return subprocess.run(['docker', 'exec', SRV, 'sh', '/bench/benchy'] + list(args), capture_output=True, text=True).stdout


def audit(action):
    return sql("SELECT * FROM phoromatic_admin_audit WHERE AccountID='%s' AND Action='%s' ORDER BY AuditID" % (AID, action))


def level_of(name):
    return sql("SELECT AdminLevel AS l FROM phoromatic_users WHERE UserName='%s'" % name)[0]['l']


class TestUsersPage(WebBase):
    @classmethod
    def setUpClass(cls):
        hide_other_accounts()
        super().setUpClass()

    @classmethod
    def tearDownClass(cls):
        benchy('phoromatic-tenant-auto-approve', 'forbidden')
        restore_other_accounts()

    def setUp(self):
        self.admin.get(SETTINGS)
        self.addCleanup(restore_row('phoromatic_account_settings', "AccountID='%s'" % AID))
        sql_exec('DROP TRIGGER IF EXISTS qc_refuse_cp3a')

    def tearDown(self):
        sql_exec('DROP TRIGGER IF EXISTS qc_refuse_cp3a')

    def test_the_role_text_matches_the_code_and_each_level_is_offered_what_it_may_create(self):
        for level, offers in ((1, ('2', '3', '10')), (2, ('3', '10')), (3, ('10',))):
            b = self.admin if level == 1 else self.as_user(level)
            page = b.get(USERS)[2]
            with self.subTest(level=level):
                for text in ('Group Administrator (level 1)', 'like a Group Administrator, except changing levels and creating other Administrators', 'can add Viewers',
                             'Viewer (level 10)', 'is a role on the server, not a level here', 'Add a user'):
                    self.assertIn(text, page)
                for gone in ('originally signed up', 'same access rights as the group administrator', 'aside from being able to create additional users', 'Create Additional Account',
                             'sharing of data', 'Account Administrator'):
                    self.assertNotIn(gone, page)
                form = re.search(r'<form [^>]*name="add_user".*?</form>', page, re.S).group(0)
                self.assertEqual(tuple(re.findall(r'<option value="(\d+)"', form)), offers)
                self.assertEqual('name="update_user_levels"' in page and 'name="admin_level_' in page, level == 1, 'only a Group Administrator changes levels')
        self.assertIn("aren't an account administrator", self.as_user(10).get(USERS)[2])

    def make(self, level):
        name = 'cp3q%d%s' % (level, uuid.uuid4().hex[:5])
        self.admin.post(USERS, {'username': name, 'password': 'qcpass123', 'confirm_password': 'qcpass123', 'email': name + '@example.com', 'admin_level': str(level), 'token_submit': tok(self.admin, USERS)})
        return name

    def test_a_new_user_and_a_level_change_are_audited_and_the_change_needs_the_origin(self):
        n_created = len(audit('user_created'))
        name = self.make(3)
        rows = audit('user_created')
        self.assertEqual(len(rows) - n_created, 1)
        self.assertEqual((rows[-1]['ActorType'], rows[-1]['Actor'], rows[-1]['SubjectLabel']), ('user', __import__('os').environ['QC_USER'], name))
        self.assertEqual(json.loads(rows[-1]['Detail']), {'level': 3})
        uid = sql("SELECT UserID FROM phoromatic_users WHERE UserName='%s'" % name)[0]['UserID']
        fields = {'update_user_levels': uid, 'admin_level_' + uid: '10'}
        n = len(audit('member_level_changed'))
        for label, data, headers in (('no token', fields, None), ('wrong token', dict(fields, token_submit='0' * 40), None), ('cross-origin', dict(fields, token_submit=tok(self.admin, USERS)), EVIL)):
            body = self.admin.post(USERS, data, headers=headers)[2]
            self.assertEqual(level_of(name), 3, label)
            self.assertIn('nothing was changed', body, label)
        self.assertEqual(len(audit('member_level_changed')), n, 'a refused change leaves no row')
        self.admin.post(USERS, dict(fields, token_submit=tok(self.admin, USERS)))
        self.assertEqual(level_of(name), 10)
        rows = audit('member_level_changed')
        self.assertEqual(len(rows) - n, 1)
        self.assertEqual((rows[-1]['SubjectLabel'], rows[-1]['Actor']), (name, __import__('os').environ['QC_USER']))
        self.assertEqual(json.loads(rows[-1]['Detail']), {'before': 3, 'after': 10})
        self.admin.post(USERS, dict(fields, token_submit=tok(self.admin, USERS)))
        self.assertEqual(len(audit('member_level_changed')) - n, 1, 'the same level again is no change and no row')
        # fail closed: with the audit row refused the level stays
        sql_exec("CREATE TRIGGER qc_refuse_cp3a BEFORE INSERT ON phoromatic_admin_audit WHEN NEW.Action='member_level_changed' BEGIN SELECT RAISE(ABORT, 'qc refuses'); END")
        body = self.admin.post(USERS, {'update_user_levels': uid, 'admin_level_' + uid: '2', 'token_submit': tok(self.admin, USERS)})[2]
        self.assertEqual(level_of(name), 10)
        self.assertIn('could not be recorded in the audit log', body)

    def test_a_cross_origin_new_user_and_group_name_are_refused_too(self):
        count = lambda: sql("SELECT COUNT(*) AS n FROM phoromatic_users")[0]['n']
        before = count()
        body = self.admin.post(USERS, {'username': 'cp3evil%s' % uuid.uuid4().hex[:4], 'password': 'qcpass123', 'confirm_password': 'qcpass123', 'email': 'e@example.com', 'admin_level': '10',
                                       'token_submit': tok(self.admin, USERS)}, headers=EVIL)[2]
        self.assertEqual(count(), before)
        self.assertIn('nothing was changed', body)
        group = lambda: sql("SELECT GroupName AS g FROM phoromatic_accounts WHERE AccountID='%s'" % AID)[0]['g']
        old = group()
        self.admin.post(USERS, {'group_name': 'evilgroup', 'token_submit': tok(self.admin, USERS)}, headers=EVIL)
        self.assertEqual(group(), old)

    def test_the_auto_approve_box_is_audited_when_it_changes(self):
        benchy('phoromatic-tenant-auto-approve', 'allowed')
        sql_exec("UPDATE phoromatic_account_settings SET AutoApproveNewSystems=0 WHERE AccountID='%s'" % AID)
        n = len(audit('account_setting_set'))
        page = self.admin.get(SETTINGS)[2]
        form = re.search(r'<form [^>]*id="account_settings_form".*?</form>', page, re.S).group(0)
        fields = {k: 'yes' for k in re.findall(r'<input type="checkbox" name="(\w+)" checked="checked" value="yes"', form)}
        fields['account_settings_update'] = '1'
        self.admin.post(SETTINGS, dict(fields, token_submit=tok(self.admin, SETTINGS)))
        self.assertEqual(len(audit('account_setting_set')), n, 'nothing changed, no row')
        self.admin.post(SETTINGS, dict(fields, AutoApproveNewSystems='yes', token_submit=tok(self.admin, SETTINGS)))
        rows = audit('account_setting_set')
        self.assertEqual(len(rows) - n, 1)
        self.assertEqual((rows[-1]['SubjectID'], rows[-1]['ActorType']), ('AutoApproveNewSystems', 'user'))
        self.assertEqual(json.loads(rows[-1]['Detail']), {'before': 0, 'after': 1})
        # a viewer's or a refused post leaves no row, and with the audit refused the box does not change
        self.admin.post(SETTINGS, dict(fields, token_submit='0' * 40))
        self.assertEqual(len(audit('account_setting_set')) - n, 1)
        sql_exec("CREATE TRIGGER qc_refuse_cp3a BEFORE INSERT ON phoromatic_admin_audit WHEN NEW.Action='account_setting_set' BEGIN SELECT RAISE(ABORT, 'qc refuses'); END")
        fields.pop('AutoApproveNewSystems', None)
        self.admin.post(SETTINGS, dict(fields, token_submit=tok(self.admin, SETTINGS)))
        self.assertEqual(sql("SELECT AutoApproveNewSystems AS v FROM phoromatic_account_settings WHERE AccountID='%s'" % AID)[0]['v'], 1)

    def test_the_test_options_and_the_update_script_are_audited(self):
        page = self.admin.get(SETTINGS)[2]
        var = re.search(r'<input type="number"[^>]*name="(\w+)"', page).group(1)
        n = len(audit('account_env_set'))
        self.admin.post(SETTINGS, {'env_var_update': '1', var: '7', 'token_submit': tok(self.admin, SETTINGS)})
        rows = audit('account_env_set')
        self.assertEqual(len(rows) - n, 1)
        detail = json.loads(rows[-1]['Detail'])
        self.assertEqual(detail['changed'], [var])
        self.assertNotIn('7', json.dumps(detail['changed']), 'names only, not values')
        self.admin.post(SETTINGS, {'env_var_update': '1', var: '7', 'token_submit': tok(self.admin, SETTINGS)})
        self.assertEqual(len(audit('account_env_set')) - n, 1, 'no change, no row')
        self.admin.post(SETTINGS, {'env_var_update': '1', 'token_submit': tok(self.admin, SETTINGS)})   # put it back
        self.assertEqual(len(audit('account_env_set')) - n, 2)
        # the script: a row with hashes, not the text; none for an unchanged save, a level-3 post or a refused post
        n = len(audit('client_script_set'))
        text = '#!/bin/sh\necho qc-audit-%s\n' % uuid.uuid4().hex[:6]
        self.admin.post(SETTINGS, {'client_update_script': text, 'token_submit': tok(self.admin, SETTINGS)})
        rows = audit('client_script_set')
        self.assertEqual(len(rows) - n, 1)
        self.assertNotIn('qc-audit', rows[-1]['Detail'])
        self.assertEqual(json.loads(rows[-1]['Detail'])['bytes'], len(text))
        self.admin.post(SETTINGS, {'client_update_script': text, 'token_submit': tok(self.admin, SETTINGS)})
        p3 = self.as_user(3)
        p3.post(SETTINGS, {'client_update_script': 'echo three', 'token_submit': tok(p3, SETTINGS)})
        self.admin.post(SETTINGS, {'client_update_script': 'echo evil', 'token_submit': tok(self.admin, SETTINGS)}, headers=EVIL)
        self.assertEqual(len(audit('client_script_set')) - n, 1)
        self.assertIn('qc-audit', self.admin.get(SETTINGS)[2])
        sql_exec("CREATE TRIGGER qc_refuse_cp3a BEFORE INSERT ON phoromatic_admin_audit WHEN NEW.Action='client_script_set' BEGIN SELECT RAISE(ABORT, 'qc refuses'); END")
        self.admin.post(SETTINGS, {'client_update_script': 'echo refused', 'token_submit': tok(self.admin, SETTINGS)})
        self.assertNotIn('echo refused', self.admin.get(SETTINGS)[2])
        sql_exec('DROP TRIGGER qc_refuse_cp3a')
        self.admin.post(SETTINGS, {'client_update_script': '', 'token_submit': tok(self.admin, SETTINGS)})


if __name__ == '__main__':
    unittest.main()
