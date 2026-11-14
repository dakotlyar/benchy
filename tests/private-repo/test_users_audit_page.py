"""CP3 and CP3a of the settings simplification (Benchy 0.9.13): the Users page text and what each level is offered, and the admin-audit rows for
the account changes that decide who joins or what every client runs (auto-approve box, test options, client update script, a user's level, a new user),
plus the same-origin check on the Users forms. Run through tests/private-repo/run.sh (part of http/all)."""
import json
import re
import subprocess
import unittest
import uuid

from single_account import hide_other_accounts, restore_other_accounts
from test_private_repo_http import AID, SRV, sql, srv_sh
from test_private_repo_tokens import sql_exec
from test_private_repo_web import WebBase
from test_settings_csrf import restore_row, tok

SETTINGS = '/?settings'
USERS = '/?users'
EVIL = {'Origin': 'http://evil.example'}
MIRROR = '/var/log/phoromatic-admin-audit.log'
SCRIPT = '/var/lib/benchy/phoromatic/accounts/%s/client-update-script.sh' % AID


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
        sql_exec('DROP TRIGGER IF EXISTS qc_break_cp3')
        srv_sh('[ -d %s ] && rmdir %s; [ -d %s.qcsave ] && { rmdir %s 2>/dev/null; mv %s.qcsave %s; }; rm -f %s; true' % (SCRIPT, SCRIPT, MIRROR, MIRROR, MIRROR, MIRROR, SCRIPT))

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

    # ---- fail closed (S1, S2): each audited change is one transaction (open, record, change, commit) and rolls back whole
    def mirror_notes(self, action):
        return srv_sh('cat %s' % MIRROR).count('%s rolled back, nothing changed' % action)

    def check_fail_closed(self, action, post, state, break_change, repair_change, red):
        rows = len(audit(action))
        before = state()
        # (a) the audit row is refused by the database: nothing changes, and the audit log file says the change was rolled back
        notes = self.mirror_notes(action)
        sql_exec("CREATE TRIGGER qc_refuse_cp3a BEFORE INSERT ON phoromatic_admin_audit WHEN NEW.Action='%s' BEGIN SELECT RAISE(ABORT, 'qc refuses'); END" % action)
        body = post()
        sql_exec('DROP TRIGGER qc_refuse_cp3a')
        self.assertEqual(state(), before, 'a: the change was not made')
        self.assertEqual(len(audit(action)), rows, 'a: and no row stays')
        self.assertEqual(self.mirror_notes(action), notes + 1, 'a: the mirror says it was rolled back')
        self.assertIn(red, body, 'a: the admin is told')
        # (b) the mirror line cannot be appended: the row written inside the transaction does not stay either
        srv_sh('mv %s %s.qcsave; mkdir %s' % (MIRROR, MIRROR, MIRROR))
        try:
            body = post()
        finally:
            srv_sh('rmdir %s; mv %s.qcsave %s' % (MIRROR, MIRROR, MIRROR))
        self.assertEqual(state(), before, 'b: the change was not made')
        self.assertEqual(len(audit(action)), rows, 'b: and no database row stays')
        self.assertIn(red, body, 'b: the admin is told')
        # (c) the change itself fails after the audit row was written: the row goes too
        notes = self.mirror_notes(action)
        break_change()
        try:
            body = post()
        finally:
            repair_change()
        self.assertEqual(state(), before, 'c: the change was not made')
        self.assertEqual(len(audit(action)), rows, 'c: the audit row was rolled back')
        self.assertEqual(self.mirror_notes(action), notes + 1, 'c: the mirror says it was rolled back')
        self.assertIn(red, body, 'c: the admin is told')
        # control: with nothing broken the same post goes through, with one row
        post()
        self.assertNotEqual(state(), before, 'the change is made when nothing is broken')
        self.assertEqual(len(audit(action)), rows + 1)

    def trigger(self, event, table):
        return (lambda: sql_exec("CREATE TRIGGER qc_break_cp3 BEFORE %s ON %s BEGIN SELECT RAISE(ABORT, 'qc breaks'); END" % (event, table)), lambda: sql_exec('DROP TRIGGER qc_break_cp3'))

    def test_the_auto_approve_box_fails_closed(self):
        benchy('phoromatic-tenant-auto-approve', 'allowed')
        sql_exec("UPDATE phoromatic_account_settings SET AutoApproveNewSystems=0 WHERE AccountID='%s'" % AID)
        page = self.admin.get(SETTINGS)[2]
        form = re.search(r'<form [^>]*id="account_settings_form".*?</form>', page, re.S).group(0)
        fields = {k: 'yes' for k in re.findall(r'<input type="checkbox" name="(\w+)" checked="checked" value="yes"', form)}
        fields.update({'account_settings_update': '1', 'AutoApproveNewSystems': 'yes'})
        self.check_fail_closed('account_setting_set', lambda: self.admin.post(SETTINGS, dict(fields, token_submit=tok(self.admin, SETTINGS)))[2],
                               lambda: sql("SELECT AutoApproveNewSystems AS v FROM phoromatic_account_settings WHERE AccountID='%s'" % AID)[0]['v'], *self.trigger('UPDATE', 'phoromatic_account_settings'), 'color: red')

    def test_the_test_options_fail_closed(self):
        var = re.search(r'<input type="number"[^>]*name="(\w+)"', self.admin.get(SETTINGS)[2]).group(1)
        sql_exec("UPDATE phoromatic_account_settings SET GlobalEnvironmentVariables=NULL WHERE AccountID='%s'" % AID)
        self.check_fail_closed('account_env_set', lambda: self.admin.post(SETTINGS, {'env_var_update': '1', var: '7', 'token_submit': tok(self.admin, SETTINGS)})[2],
                               lambda: sql("SELECT GlobalEnvironmentVariables AS v FROM phoromatic_account_settings WHERE AccountID='%s'" % AID)[0]['v'], *self.trigger('UPDATE', 'phoromatic_account_settings'), 'color: red')

    def test_the_update_script_fails_closed(self):
        # the "change fails" case is a file that cannot be written: a directory sits where the script goes
        state = lambda: srv_sh('[ -f %s ] && cat %s' % (SCRIPT, SCRIPT))
        self.check_fail_closed('client_script_set', lambda: self.admin.post(SETTINGS, {'client_update_script': 'echo qc-failclosed\n', 'token_submit': tok(self.admin, SETTINGS)})[2], state,
                               lambda: srv_sh('mkdir %s' % SCRIPT), lambda: srv_sh('rmdir %s' % SCRIPT), 'color: red')

    def test_a_level_change_fails_closed(self):
        name = self.make(3)
        uid = sql("SELECT UserID FROM phoromatic_users WHERE UserName='%s'" % name)[0]['UserID']
        self.check_fail_closed('member_level_changed', lambda: self.admin.post(USERS, {'update_user_levels': uid, 'admin_level_' + uid: '10', 'token_submit': tok(self.admin, USERS)})[2],
                               lambda: level_of(name), *self.trigger('UPDATE', 'phoromatic_users'), 'color: red')

    def test_a_new_user_fails_closed(self):
        name = 'cp3n%s' % uuid.uuid4().hex[:5]
        count = lambda: sql("SELECT COUNT(*) AS n FROM phoromatic_users WHERE UserName='%s'" % name)[0]['n']
        users = lambda: sql("SELECT COUNT(*) AS n FROM phoromatic_user_settings WHERE AccountID='%s'" % AID)[0]['n']
        n_settings = users()
        self.check_fail_closed('user_created', lambda: self.admin.post(USERS, {'username': name, 'password': 'qcpass123', 'confirm_password': 'qcpass123', 'email': name + '@example.com', 'admin_level': '10',
                                                                                'token_submit': tok(self.admin, USERS)})[2], count, *self.trigger('INSERT', 'phoromatic_user_settings'), 'was not created')
        self.assertEqual(users(), n_settings + 1, 'the failed attempts left no half-made user (one settings row, from the control)')

    def test_the_power_user_limits_name_the_tokens_and_codes(self):
        page = self.as_user(3).get(USERS)[2]
        self.assertIn('Can run commands on the account&#039;s test machines, through schedule scripts', page.replace("'", '&#039;'))
        for word in ('system variables', 'test options'):
            self.assertIn(word, page)
        self.assertIn('manage the private repository, bench tokens or native enrollment codes, or set the client update script (only Group Administrators and Administrators can)', page)


if __name__ == '__main__':
    unittest.main()
