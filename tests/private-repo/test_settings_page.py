"""CP2 of the settings simplification (Benchy 0.9.13): the account Settings page (?settings) rebuilt in task groups, per role.

Sections and wording, one account form with one Save (EC-2), effective values where a server policy overrides an option (EC-1), the
read-only view for viewers (EC-5, and a viewer's forged post changes nothing), what each level sees of the update script and the
Security links, and the mail notice (EC-10). Every column and POST name is the one the page had before. Run through
tests/private-repo/run.sh (part of http/all).
"""
import re
import subprocess
import unittest

from single_account import hide_other_accounts, restore_other_accounts
from test_private_repo_http import AID, SRV, sql
from test_private_repo_tokens import sql_exec
from test_private_repo_web import WebBase
from test_settings_csrf import restore_row, tok

PAGE = '/?settings'
ACCOUNT_COLUMNS = ('ArchiveResultsLocally', 'UploadSystemLogs', 'UploadInstallLogs', 'UploadRunLogs', 'ProgressiveResultUploads', 'RunInstallCommand', 'ForceInstallTests',
                   'UploadResultsToOpenBenchmarking', 'PowerOffWhenDone', 'PreSeedTestInstalls', 'NetworkPowerUpWhenNeeded', 'PowerOnSystemDaily', 'AutoApproveNewSystems',
                   'LimitNetworkCommunication')


def benchy(*args):
    return subprocess.run(['docker', 'exec', SRV, 'sh', '/bench/benchy'] + list(args), capture_output=True, text=True).stdout


def account_row():
    return sql("SELECT * FROM phoromatic_account_settings WHERE AccountID='%s'" % AID)[0]


def checked_boxes(page):
    """What the browser would post from the This-account form as displayed: the checked, enabled boxes (and the hidden markers)."""
    form = re.search(r'<form [^>]*id="account_settings_form".*?</form>', page, re.S).group(0)
    fields = {name: 'yes' for name in re.findall(r'<input type="checkbox" name="(\w+)" checked="checked" value="yes"', form)}
    fields['account_settings_update'] = '1'
    return fields


class TestSettingsPage(WebBase):
    @classmethod
    def setUpClass(cls):
        hide_other_accounts()
        super().setUpClass()

    @classmethod
    def tearDownClass(cls):
        benchy('phoromatic-client-phone-home', 'forbidden')
        benchy('phoromatic-tenant-auto-approve', 'forbidden')
        restore_other_accounts()

    def setUp(self):
        self.admin.get(PAGE)
        self.addCleanup(restore_row('phoromatic_account_settings', "AccountID='%s'" % AID))
        self.addCleanup(restore_row('phoromatic_user_settings', "AccountID='%s'" % AID + " AND UserID='%s'" % self.uid(self.admin_name())))

    def admin_name(self):
        return __import__('os').environ['QC_USER']

    def uid(self, name):
        return sql("SELECT UserID FROM phoromatic_users WHERE UserName='%s'" % name)[0]['UserID']

    def test_sections_come_in_the_designed_order_with_one_account_form(self):
        page = self.admin.get(PAGE)[2]
        marks = ('<h1>Settings: ', 'Your notifications', 'This account', '<h3>New systems</h3>', '<h3>Running tests</h3>', '<h3>Results and logs</h3>', '<h3>Power and network</h3>',
                 'Client update script', 'Security and access')
        pos = [page.find(m) for m in marks]
        self.assertNotIn(-1, pos, dict(zip(marks, pos)))
        self.assertEqual(pos, sorted(pos))
        self.assertEqual(len(re.findall(r'name="account_settings_update"', page)), 1, 'one account form, one Save')
        self.assertEqual(len(re.findall(r'name="env_var_update"', page)), 1)
        form = re.search(r'<form [^>]*id="account_settings_form".*?</form>', page, re.S).group(0)
        for c in ACCOUNT_COLUMNS:
            self.assertIn('name="%s"' % c, form, c)
        self.assertIn('name="env_var_update"', form, 'the test options are in the same form')
        self.assertIn('Only applies when the option above is on', page)
        self.assertEqual(page.count('(PTS clients only)'), 3)
        for gone in ('Build A Suite', 'Cache Settings', 'Global Settings', 'Account settings are system-wide', 'Benchy 10.8 or newer'):
            self.assertNotIn(gone, page)

    def test_saving_one_group_leaves_every_other_value_as_it_was(self):
        # EC-2: unticked boxes post nothing, so a save writes the whole form; what was on stays on and what was off stays off
        benchy('phoromatic-client-phone-home', 'client_choice')
        benchy('phoromatic-tenant-auto-approve', 'allowed')
        pattern = {c: (1 if i % 2 else 0) for i, c in enumerate(ACCOUNT_COLUMNS)}
        sql_exec("UPDATE phoromatic_account_settings SET %s WHERE AccountID='%s'" % (', '.join('%s=%d' % kv for kv in pattern.items()), AID))
        before = account_row()
        fields = checked_boxes(self.admin.get(PAGE)[2])
        self.assertEqual({k for k in fields if k in ACCOUNT_COLUMNS}, {c for c, v in pattern.items() if v})
        self.admin.post(PAGE, dict(fields, token_submit=tok(self.admin, PAGE)))
        self.assertEqual({c: account_row()[c] for c in ACCOUNT_COLUMNS}, {c: before[c] for c in ACCOUNT_COLUMNS}, 'a save with nothing changed')
        off = next(c for c, v in pattern.items() if not v and c not in ('AutoApproveNewSystems',))
        fields[off] = 'yes'
        self.admin.post(PAGE, dict(fields, token_submit=tok(self.admin, PAGE)))
        after = account_row()
        self.assertEqual(after[off], 1)
        self.assertEqual({c: after[c] for c in ACCOUNT_COLUMNS if c != off}, {c: before[c] for c in ACCOUNT_COLUMNS if c != off})
        # the notifications form and the account form do not touch each other
        self.admin.post(PAGE, {'user_settings_update': '1', 'NotifyOnWarnings': 'yes', 'token_submit': tok(self.admin, PAGE)})
        self.assertEqual({c: account_row()[c] for c in ACCOUNT_COLUMNS}, {c: after[c] for c in ACCOUNT_COLUMNS})
        user_now = sql("SELECT * FROM phoromatic_user_settings WHERE UserID='%s'" % self.uid(self.admin_name()))[0]
        self.assertEqual(user_now['NotifyOnWarnings'], 1)
        self.admin.post(PAGE, dict(checked_boxes(self.admin.get(PAGE)[2]), token_submit=tok(self.admin, PAGE)))
        self.assertEqual(sql("SELECT * FROM phoromatic_user_settings WHERE UserID='%s'" % self.uid(self.admin_name()))[0], user_now)

    def test_the_policy_locked_options_show_the_value_that_applies_and_a_save_keeps_the_stored_choice(self):
        # EC-1: the server forbids both; the boxes are shown off and disabled with the reason, and a save does not write them
        benchy('phoromatic-client-phone-home', 'forbidden')
        benchy('phoromatic-tenant-auto-approve', 'forbidden')
        sql_exec("UPDATE phoromatic_account_settings SET UploadResultsToOpenBenchmarking=1, AutoApproveNewSystems=1, PowerOffWhenDone=1 WHERE AccountID='%s'" % AID)
        page = self.admin.get(PAGE)[2]
        for col, words in (('UploadResultsToOpenBenchmarking', 'Your saved choice is kept'), ('AutoApproveNewSystems', 'requires approval for every new system')):
            box = re.search(r'<input type="checkbox" name="%s"[^>]*>' % col, page).group(0)
            self.assertIn('disabled="disabled"', box)
            self.assertNotIn('checked', box, 'shown as it applies: off')
            self.assertIn(words, page)
        self.assertIn('Locked by server', page)
        fields = checked_boxes(page)
        self.assertNotIn('UploadResultsToOpenBenchmarking', fields)
        fields.pop('PowerOffWhenDone')
        self.admin.post(PAGE, dict(fields, token_submit=tok(self.admin, PAGE)))
        row = account_row()
        self.assertEqual((row['UploadResultsToOpenBenchmarking'], row['AutoApproveNewSystems']), (1, 1), 'the stored choices are kept')
        self.assertEqual(row['PowerOffWhenDone'], 0, 'while the open boxes were saved')
        # a post that names the locked boxes is not read either: stored 0 stays 0 while the server forbids them
        sql_exec("UPDATE phoromatic_account_settings SET UploadResultsToOpenBenchmarking=0, AutoApproveNewSystems=0 WHERE AccountID='%s'" % AID)
        self.admin.post(PAGE, {'account_settings_update': '1', 'UploadResultsToOpenBenchmarking': 'yes', 'AutoApproveNewSystems': 'yes', 'token_submit': tok(self.admin, PAGE)})
        self.assertEqual((account_row()['UploadResultsToOpenBenchmarking'], account_row()['AutoApproveNewSystems']), (0, 0))
        sql_exec("UPDATE phoromatic_account_settings SET UploadResultsToOpenBenchmarking=1, AutoApproveNewSystems=1 WHERE AccountID='%s'" % AID)
        # open again: editable, and they show their stored value
        benchy('phoromatic-client-phone-home', 'client_choice')
        page = self.admin.get(PAGE)[2]
        self.assertNotIn('disabled', re.search(r'<input type="checkbox" name="UploadResultsToOpenBenchmarking"[^>]*>', page).group(0))
        self.assertIn('checked', re.search(r'<input type="checkbox" name="UploadResultsToOpenBenchmarking"[^>]*>', page).group(0))

    def test_a_viewer_sees_the_account_options_read_only_and_a_forged_post_changes_nothing(self):
        # EC-5 and decision 2; decision 7: the forged post is refused and tested, with no security bump
        viewer = self.as_user(10)
        page = viewer.get(PAGE)[2]
        self.assertIn('Only Group Administrators, Administrators and Power Users can change these.', page)
        self.assertIn('Read-only', page)
        self.assertNotIn('name="account_settings_update"', page)
        self.assertNotIn('name="PowerOffWhenDone"', page)
        self.assertNotIn('name="client_update_script"', page)
        self.assertIn('Power systems off when the day', page, 'the value is shown as text')
        self.assertIn('name="user_settings_update"', page, 'their own notifications are theirs to change')
        self.assertIn('A Group Administrator or Administrator can set a script', page)
        sql_exec("UPDATE phoromatic_account_settings SET GlobalEnvironmentVariables='FORCE_TIMES_TO_RUN=4' WHERE AccountID='%s'" % AID)
        before = account_row()
        t = tok(viewer, PAGE)
        viewer.post(PAGE, {'account_settings_update': '1', 'PowerOffWhenDone': 'yes', 'RunInstallCommand': 'yes', 'token_submit': t})
        viewer.post(PAGE, {'env_var_update': '1', 'FORCE_TIMES_TO_RUN': '9', 'token_submit': t})
        self.assertEqual(account_row()['GlobalEnvironmentVariables'], 'FORCE_TIMES_TO_RUN=4')
        viewer.post(PAGE, {'client_update_script': 'echo forged', 'token_submit': t})
        self.assertEqual(account_row(), before)
        self.assertNotIn('echo forged', self.admin.get(PAGE)[2])
        uid = self.uid(self.names[10])
        viewer.post(PAGE, {'user_settings_update': '1', 'NotifyOnHungSystems': 'yes', 'token_submit': t})
        self.assertEqual(sql("SELECT NotifyOnHungSystems AS v FROM phoromatic_user_settings WHERE UserID='%s'" % uid)[0]['v'], 1)

    def test_each_level_sees_the_update_script_and_the_links_it_can_use(self):
        # (the download cache page has no level gate, so everyone gets its link, as the old page gave it)
        links = {1: ('Private repository tokens', 'Bench tokens', 'Native enrollment codes', 'Download cache'), 2: ('Private repository tokens', 'Bench tokens', 'Native enrollment codes', 'Download cache'),
                 3: ('Bench tokens', 'Download cache'), 10: ('Download cache',)}
        every = ('Private repository tokens', 'Bench tokens', 'Native enrollment codes', 'Download cache')
        for level, browser in ((1, self.admin), (2, self.as_user(2)), (3, self.as_user(3)), (10, self.as_user(10))):
            page = browser.get(PAGE)[2]
            with self.subTest(level=level):
                self.assertEqual(('name="client_update_script"' in page), level in (1, 2))
                self.assertEqual(('A Group Administrator or Administrator can set a script' in page), level in (3, 10))
                for always in ('Your password', 'Your results feed (RSS)', 'Recent account activity'):
                    self.assertIn(always, page)
                for name in every:
                    self.assertEqual(name in page, name in links[level], name)
                self.assertEqual('name="account_settings_update"' in page, level != 10)
        self.assertIn('Benches</a> (view)', self.as_user(3).get(PAGE)[2])

    def test_the_update_script_still_saves_for_level_1_and_2_only(self):
        a2 = self.as_user(2)
        text = '#!/bin/sh\necho qc-script-%s\n' % self.suffix
        a2.post(PAGE, {'client_update_script': text, 'token_submit': tok(a2, PAGE)})
        self.assertIn('qc-script-%s' % self.suffix, self.admin.get(PAGE)[2])
        p3 = self.as_user(3)
        p3.post(PAGE, {'client_update_script': 'echo level3', 'token_submit': tok(p3, PAGE)})
        self.assertNotIn('level3', self.admin.get(PAGE)[2])
        self.admin.post(PAGE, {'client_update_script': '', 'token_submit': tok(self.admin, PAGE)})

    def test_the_mail_notice_is_shown_exactly_when_the_server_cannot_send_mail(self):
        # EC-10
        has_mail = subprocess.run(['docker', 'exec', SRV, 'sh', '-c', 'command -v sendmail >/dev/null 2>&1'], capture_output=True).returncode == 0
        page = self.admin.get(PAGE)[2]
        self.assertEqual("can't send email yet" in page.replace('&#039;', "'"), not has_mail)

    def test_a_link_cannot_prefill_a_test_option_and_a_quote_is_escaped(self):
        # a GET never fills a field (it was reflected unescaped; the URL filter only rejects a few known payloads, so the probe is a made-up attribute), and a posted value is escaped when it is shown again
        page = self.admin.get(PAGE + '/&FORCE_TIMES_TO_RUN=%22%20qcinject%3D1%20x%3D%22')[2]
        self.assertIn('name="env_var_update"', page, 'the settings page itself was served')
        self.assertNotIn('" qcinject', page)
        var = re.search(r'<input type="number"[^>]*name="(\w+)"', page).group(1)
        for path in ('/?sched', '/?benchmark'):
            body = self.admin.get(path + '/&' + var + '=%22%20qcinject%3D1%20x%3D%22')[2]
            self.assertIn('env_var_options', body, path + ' was served')
            self.assertNotIn('" qcinject', body, path)   # (the encoded text in a form action's URL is harmless)
        text_var = re.search(r'<input name="(\w+)" value="', page)
        if text_var:
            body = self.admin.post(PAGE, {'env_var_update': '1', text_var.group(1): '" qcinject=1 x="', 'token_submit': tok(self.admin, PAGE)})[2]
            self.assertNotIn('qcinject=1', body)

    def test_the_server_box_is_for_server_administrators_only(self):
        # EC-6
        self.assertIn('href="?server_settings"', self.admin.get(PAGE)[2])
        for level in (2, 3, 10):
            self.assertNotIn('?server_settings', self.as_user(level).get(PAGE)[2])


if __name__ == '__main__':
    unittest.main()
