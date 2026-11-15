"""T5: memberships. A user who belongs to both accounts switches between them and sees only the account they are in on every page, endpoint and mail; cannot act
in an account they are not a member of or above their level in it; a removed membership, a lowered level, a turned-off user or a suspended account stops access
at the next request; joining an account is audited in both audits; mail goes to members wherever their own account is; the Users page lists members, not
home users; the console Users page does what the root page's Override User Password did.

Two accounts (T1 and T2, T2 full of CANARY_T2_* values) and a server administrator made by the test (tn1lvl2, a level-2 member of T1). Every user these tests
make is removed afterwards."""
import json
import re
import unittest
import uuid

import tn
from t4 import *
from test_crawl import crawl_paths, filled


def current(b):
    """The account name the header shows (the switcher's summary, or the plain name)."""
    m = re.search(r'<summary[^>]*>(.*?) &#9662;</summary>', b.get('/?main')[2], re.S)
    return m.group(1) if m else None


def follow(b, path):
    """A GET, and the page it redirects to (a session that was moved to another account is sent to the main page, and the request does nothing)."""
    status, headers, body = b.get(path)
    return b.get(headers['Location'])[2] if status in (301, 302) and headers.get('Location') else body


def switch(b, aid, token=None):
    s, h, body = b.post('/?r_tenant_switch', {'account_id': aid, 'token_submit': token or b.token('/?settings')})
    return s, h.get('Location')


def try_login(name, password=tn.PW):
    b = tn.Browser()
    b.get('/')
    return b.post('/?login', {'username': name, 'password': password})[2]


def logged_in(b):
    return 'name="username"' not in b.get('/?main')[2]


def members(aid):
    return {r['UserName']: r['AdminLevel'] for r in tn.sql("SELECT u.UserName, m.AdminLevel FROM phoromatic_memberships m JOIN phoromatic_users u ON u.UserID = m.UserID WHERE m.AccountID='%s'" % aid)}


def membership(name, aid):
    rows = tn.sql("SELECT m.AdminLevel FROM phoromatic_memberships m JOIN phoromatic_users u ON u.UserID = m.UserID WHERE u.UserName='%s' AND m.AccountID='%s'" % (name, aid))
    return rows[0]['AdminLevel'] if rows else None


def crawl_paths_without_activity(st):
    """The crawl, minus the account's own activity page: it prints the ids a request named (another test's removal of a T2 schedule test shows its T2 text there, typed by the
    requester into their own account's stream, so it is not data of T2)."""
    return [(p, path) for p, path in crawl_paths(st) if p != 'account_activity']


def snap(aid):
    """The account's rows and files, except its membership rows (a switch stamps the time a membership was last used, and that is meant to change)."""
    s = tn.snapshot(aid)
    s['rows'].pop('phoromatic_memberships', None)
    s['rows'].pop('phoromatic_admin_audit', None)   # (a level change made on the console is a row of the account's own audit)
    return s


def tenant_audit(action, aid):
    return tn.sql("SELECT * FROM phoromatic_admin_audit WHERE AccountID='%s' AND Action='%s' ORDER BY rowid" % (aid, action))


class T5(Console):
    def dual(self, home_level, other_level, other=None, name=None):
        """A user made in T1 at home_level and added to another account (T2) by the server administrator at other_level."""
        name = name or 'dual' + uuid.uuid4().hex[:6]
        uid = tn.make_user(tn.Browser().login('tn1admin'), name, home_level)
        self.addCleanup(self.forget, uid)
        other = other or self.t2
        s, loc, page = sa_act(self.sa, 'member_add', account_id=other, user_name=name, level=str(other_level))
        self.assertIn('The user was added to the account.', page)
        return name, uid

    def forget(self, uid):
        tn.sql_exec("DELETE FROM phoromatic_memberships WHERE UserID='%s'; DELETE FROM phoromatic_user_settings WHERE UserID='%s'; DELETE FROM phoromatic_server_roles WHERE UserID='%s'; "
                    "DELETE FROM phoromatic_users WHERE UserID='%s'" % (uid, uid, uid, uid))

    def own_page(self, aid):
        """A page of the account that shows its own canary text (the systems list does not show the seeded title)."""
        return '/?systems/%s' % self.st['T1' if aid == self.t1 else 'T2']['system']

    def mails(self):
        return tn.srv_sh('cat /tmp/tn-mail.log 2>/dev/null')


class Switching(T5):
    def test_a_user_of_two_accounts_switches_and_sees_only_the_account_they_are_in_on_every_page(self):
        name, uid = self.dual(2, 10)
        b = tn.Browser().login(name)
        paths_t2_ids = crawl_paths_without_activity(self.st)
        t2_before = snap(self.t2)
        t1_before = snap(self.t1)
        # (viewing an account's own result counts a view: that column is left out; nothing else of a result changes)
        results = lambda aid: [{k: v for k, v in r.items() if k != 'TimesViewed'} for r in tn.sql("SELECT * FROM phoromatic_results WHERE AccountID='%s' ORDER BY UploadID" % aid)]
        r1_before, r2_before = results(self.t1), results(self.t2)
        # the header names both accounts and offers the other
        main = b.get('/?main')[2]
        self.assertIn('r_tenant_switch', main)
        self.assertIn('Account %s' % self.t2, main)
        self.assertEqual(current(b), 'Account %s' % self.t1, 'a user opens the account they were made in')
        # in T1 with T2's ids on every page: nothing of T2 (and the control: T1's own pages show T1)
        leaks = [p for _, p in paths_t2_ids if 'CANARY_T2' in b.get(p)[2]]
        self.assertEqual(leaks, [], 'in T1 nothing of T2 comes back, though the user is a member of T2')
        self.assertEqual((results(self.t1), results(self.t2)), (r1_before, r2_before), 'phase 1 (in T1 with T2 ids) changes no result row')
        self.assertIn('TN1', b.get(self.own_page(self.t1))[2])
        # switch: the next page is the other account's
        s, loc = switch(b, self.t2)
        self.assertEqual((s, loc), (302, '/?main'))
        self.assertEqual(current(b), 'Account %s' % self.t2)
        self.assertIn('CANARY_T2', b.get(self.own_page(self.t2))[2], 'the control: in T2 the user sees T2')
        self.assertNotRegex(b.get(self.own_page(self.t2))[2].lower(), r'tn1 system|tn1_')
        # in T2 with T1's ids on every page: nothing of T1
        swapped = []
        for page, path in sorted((pg, p) for pg, e in tn_routes().items() for p in e.get('crawl', [])):
            try:
                swapped.append(filled(path.replace('{T2_', '{T1_'), self.st))
            except AssertionError:
                continue
        self.assertGreater(len(swapped), 40)
        def own_ids_removed(path, body):
            # (a page that says which id it was asked for says it: that is the request, not data)
            for v in [x for x in self.st['T1'].values() if isinstance(x, str) and len(x) > 4 and x.lower() in path.lower()]:
                body = re.sub(re.escape(v), '', body, flags=re.I)
            return body
        leaks = [p for p in swapped if re.search(r'tn1_|TN1 |tn1item|tn1suite|tn1psuite', own_ids_removed(p, b.get(p)[2]), re.I)]
        self.assertEqual(leaks, [], 'in T2 nothing of T1 comes back')
        self.assertEqual((results(self.t1), results(self.t2)), (r1_before, r2_before), 'phase 2 (in T2 with T1 ids) changes no result row')
        # the same session switches back, and neither switch changed any data
        switch(b, self.t1)
        self.assertEqual(current(b), 'Account %s' % self.t1)
        self.assertEqual(snap(self.t2), t2_before)
        now1 = snap(self.t1)['rows']
        changed = sorted(k for k in set(now1) | set(t1_before['rows']) if now1.get(k) != t1_before['rows'].get(k) and k != 'phoromatic_results')   # (results: compared above, view counts aside)
        self.assertEqual(changed, [], "T1's rows are what they were (its folder gets view folders made by the pages it was asked for: the rows are the check)")

    def test_the_session_id_changes_on_a_switch_and_the_next_login_opens_the_account_last_used(self):
        name, uid = self.dual(3, 3)
        b = tn.Browser().login(name)
        old = [c.value for c in b.jar if c.name.startswith('PHPSESSID')]
        switch(b, self.t2)
        new = [c.value for c in b.jar if c.name.startswith('PHPSESSID')]
        self.assertNotEqual(old, new)
        self.assertEqual(tn.sql("SELECT LastAccountID AS a FROM phoromatic_users WHERE UserID='%s'" % uid)[0]['a'], self.t2)
        again = tn.Browser().login(name)
        self.assertEqual(current(again), 'Account %s' % self.t2)

    def test_a_switch_needs_post_the_token_the_same_site_and_a_membership_that_works(self):
        name, uid = self.dual(3, 3)
        b = tn.Browser().login(name)
        self.assertEqual(b.get('/?r_tenant_switch')[0], 405)
        self.assertEqual(b.post('/?r_tenant_switch', {'account_id': self.t2, 'token_submit': '0' * 40})[0], 403)
        self.assertEqual(b.post('/?r_tenant_switch', {'account_id': self.t2, 'token_submit': b.token('/?settings')}, headers=EVIL)[0], 403)
        self.assertEqual(current(b), 'Account %s' % self.t1)
        # an account they are not a member of (made for the purpose), a purged id, junk and a path
        aid3, name3, user3 = self.new_tenant('T5 other')
        for bad in (aid3, 'NOSUCH', '../' + self.t2, '', 'TNBBBB/..'):
            with self.subTest(bad):
                switch(b, bad)
                self.assertEqual(current(b), 'Account %s' % self.t1)
                self.assertNotIn('CANARY_T2', b.get('/?systems')[2])
        self.assertIn('There is no such account', self.notice(b, aid3))
        # turned off in T2: listed, not clickable, refused when posted
        sa_act(self.sa, 'member_level', account_id=self.t2, user_id=uid, level='off')
        self.assertNotIn('r_tenant_switch', b.get('/?main')[2], 'an account they are turned off in is not offered')
        self.assertIn('your access to it is turned off', self.notice(b, self.t2), 'the switch itself says no (it is not left to the next request to move them back)')
        self.assertEqual(current(b), None)
        self.assertNotIn('CANARY_T2', b.get(self.own_page(self.t2))[2])
        # suspended: the same
        sa_act(self.sa, 'member_level', account_id=self.t2, user_id=uid, level='3')
        aid4, name4, user4 = self.new_tenant('T5 suspended')
        sa_act(self.sa, 'member_add', account_id=aid4, user_name=name, level='10')
        sa_act(self.sa, 'tenant_suspend', account_id=aid4)
        self.assertIn('suspended', b.get('/?main')[2])
        self.assertIn('is not available (it is suspended)', self.notice(b, aid4))
        self.assertEqual(current(b), 'Account %s' % self.t1)
        sa_act(self.sa, 'tenant_resume', account_id=aid4)
        switch(b, aid4)
        self.assertEqual(current(b), name4)

    def notice(self, b, aid):
        switch(b, aid)
        return b.get('/?main')[2]

    def test_a_viewer_in_one_account_cannot_change_it_and_a_level_2_in_another_can(self):
        name, uid = self.dual(2, 10)
        b = tn.Browser().login(name)
        users_before = tn.sql("SELECT COUNT(*) AS n FROM phoromatic_users")[0]['n']
        switch(b, self.t2)
        before = snap(self.t2)
        # a viewer of T2: no Users page, no adding users, no deleting results; nothing changes
        self.assertIn("aren't an account administrator", b.get('/?users')[2])
        tok = b.token('/?settings')
        b.post('/?users', {'username': 'noway' + uuid.uuid4().hex[:4], 'password': tn.PW, 'confirm_password': tn.PW, 'email': 'a@example.com', 'admin_level': '10', 'token_submit': tok})
        b.post('/?results/delete/%s' % self.st['T2']['pprid'], {'token_submit': tok})
        self.assertEqual(tn.sql("SELECT COUNT(*) AS n FROM phoromatic_users")[0]['n'], users_before)
        self.assertEqual(snap(self.t2), before)
        # raised to level 3 it takes effect on the next click (a level 3 may add a viewer); lowered again it stops at once
        sa_act(self.sa, 'member_level', account_id=self.t2, user_id=uid, level='3')
        self.assertNotIn("aren't an account administrator", b.get('/?users')[2])
        sa_act(self.sa, 'member_level', account_id=self.t2, user_id=uid, level='10')
        self.assertIn("aren't an account administrator", b.get('/?users')[2])
        # a level 2 of T1 can add a user in T1 (the control)
        switch(b, self.t1)
        extra = 'extra' + uuid.uuid4().hex[:6]
        tok = b.token('/?users')
        b.post('/?users', {'username': extra, 'password': tn.PW, 'confirm_password': tn.PW, 'email': extra + '@example.com', 'admin_level': '10', 'token_submit': tok})
        row = tn.sql("SELECT UserID FROM phoromatic_users WHERE UserName='%s'" % extra)
        self.assertTrue(row, 'a level-2 member adds a user in their own account')
        self.addCleanup(self.forget, row[0]['UserID'])
        self.assertEqual(membership(extra, self.t1), 10)
        self.assertEqual(snap(self.t2), before)

    def test_a_user_who_is_not_a_member_cannot_reach_another_account_by_any_page_or_id(self):
        # (a user of T1 only, with T2's ids on every page, is the canary crawl of test_crawl.py; here the same by a dual member's other account)
        lone = tn.Browser().login('tn1lvl3')
        before = tn.snapshot(self.t2)
        for page, path in crawl_paths_without_activity(self.st):
            self.assertNotIn('CANARY_T2', lone.get(path)[2], path)
        self.assertEqual(switch(lone, self.t2)[1], '/?main')
        self.assertNotIn('CANARY_T2', lone.get('/?systems')[2])
        self.assertEqual(tn.snapshot(self.t2)['rows'], before['rows'])

    def test_a_member_changes_their_password_from_any_account_it_is_the_one_password(self):
        name, uid = self.dual(3, 10)
        b = tn.Browser().login(name)
        switch(b, self.t2)
        tok = b.token('/?settings')
        s, _, body = b.post('/?password', {'old_password': tn.PW, 'register_password': 'newpass-12345', 'register_password_confirm': 'newpass-12345', 'token_submit': tok})
        self.assertIn('Password Updated', body)
        self.assertIn('Log-In Successful', try_login(name, 'newpass-12345'))
        self.assertNotIn('Log-In Successful', try_login(name, tn.PW))


def tn_routes():
    import test_crawl
    return test_crawl.ROUTES['pages']


class MidSession(T5):
    def test_removing_a_membership_moves_the_session_to_another_account_at_the_next_request(self):
        name, uid = self.dual(3, 3)
        b = tn.Browser().login(name)
        switch(b, self.t2)
        self.assertIn('CANARY_T2', b.get(self.own_page(self.t2))[2])
        before = audit_total()
        removed_before = len(tenant_audit('member_removed', self.t2))
        s, loc, page = sa_act(self.sa, 'member_remove', account_id=self.t2, user_id=uid)
        self.assertIn('was removed', page)
        self.assertIsNone(membership(name, self.t2))
        page = follow(b, self.own_page(self.t2))
        self.assertNotIn('CANARY_T2', page, 'the next request is not served from the account they lost')
        self.assertIn('is no longer available to you', page)
        self.assertEqual(current(b), None)   # one account left: no switcher
        self.assertIn('Account %s' % self.t1, page)
        self.assertNotIn('is no longer available to you', b.get('/?systems')[2], 'the note is shown once')
        removed = audit('member_removed', self.t2)
        self.assertEqual(json.loads(removed[-1]['Detail']), {'level': 3})
        self.assertEqual(len(tenant_audit('member_removed', self.t2)), removed_before + 1, "the account's own audit has it too")
        self.assertGreater(audit_total(), before)

    def test_a_user_with_no_other_account_is_logged_out_when_their_only_membership_goes(self):
        name, uid = self.dual(3, 3)
        b = tn.Browser().login(name)
        sa_act(self.sa, 'member_remove', account_id=self.t1, user_id=uid)
        follow(b, '/?main')   # (their account is gone: they are moved to the other one)
        self.assertEqual(current(b), None)
        self.assertTrue(logged_in(b))
        sa_act(self.sa, 'member_remove', account_id=self.t2, user_id=uid)
        self.assertFalse(logged_in(b), 'no account left: the session is over')
        self.assertIn('not a member of any account', try_login(name))

    def test_a_tenant_admin_removing_a_member_ends_their_access_there_and_is_audited_in_the_tenants_audit(self):
        name, uid = self.dual(3, 3)
        admin2 = tn.Browser().login('tn2admin')
        b = tn.Browser().login(name)
        switch(b, self.t2)
        removed_before = len(tenant_audit('member_removed', self.t2))
        page = admin2.get('/?users')[2]
        self.assertIn(name, page)
        self.assertIn('member from another account', page)
        tok = admin2.token('/?users')
        s, _, body = admin2.post('/?users', {'update_user_levels': '', 'remove_member': uid, 'token_submit': tok})
        self.assertIn('was removed from this account', body)
        self.assertIsNone(membership(name, self.t2))
        self.assertEqual(membership(name, self.t1), 3, 'the other account is untouched')
        self.assertNotIn('CANARY_T2', b.get(self.own_page(self.t2))[2])
        self.assertEqual(len(tenant_audit('member_removed', self.t2)), removed_before + 1)
        # (they cannot add the user back: an existing name is taken)
        admin2.post('/?users', {'username': name, 'password': tn.PW, 'confirm_password': tn.PW, 'email': 'x@example.com', 'admin_level': '10', 'token_submit': admin2.token('/?users')})
        self.assertIsNone(membership(name, self.t2))

    def test_a_tenant_admin_cannot_remove_the_group_administrator_or_a_user_of_another_account(self):
        admin2 = tn.Browser().login('tn2admin')
        lone_uid = tn.sql("SELECT UserID FROM phoromatic_users WHERE UserName='tn1lvl3'")[0]['UserID']
        admin_uid = tn.sql("SELECT UserID FROM phoromatic_users WHERE UserName='tn2admin'")[0]['UserID']
        before = (members(self.t1), members(self.t2))
        for victim in (lone_uid, admin_uid):
            admin2.post('/?users', {'update_user_levels': '', 'remove_member': victim, 'token_submit': admin2.token('/?users')})
        self.assertEqual((members(self.t1), members(self.t2)), before)

    def test_a_feed_link_for_an_account_works_for_a_member_of_it_and_stops_with_the_membership(self):
        name, uid = self.dual(3, 3)
        created = tn.sql("SELECT CreatedOn AS c FROM phoromatic_users WHERE UserID='%s'" % uid)[0]['c']
        key = tn.php_boot("phoromatic_server::init_settings_store(); echo phoromatic_feed::key('%s', '%s', '%s');" % (uid, self.t2, created)).strip()
        link = '/rss.php?user=%s&t=%s&v=%s' % (uid, self.t2, key)
        self.assertIn('<rss', tn.Browser().get(link)[2])
        sa_act(self.sa, 'member_remove', account_id=self.t2, user_id=uid)
        self.assertEqual(tn.Browser().get(link)[2], '')

    def test_a_level_change_applies_at_once_and_a_turned_off_membership_is_refused(self):
        name, uid = self.dual(3, 3)
        b = tn.Browser().login(name)
        switch(b, self.t2)
        self.assertNotIn("aren't an account administrator", b.get('/?users')[2])
        sa_act(self.sa, 'member_level', account_id=self.t2, user_id=uid, level='off')
        page = follow(b, '/?users')
        self.assertIn('is no longer available to you', page)
        self.assertEqual(current(b), None)
        self.assertNotIn('CANARY_T2', b.get(self.own_page(self.t2))[2])

    def test_turning_a_user_off_on_the_server_ends_their_session_and_blocks_log_in_until_they_are_turned_on(self):
        name, uid = self.dual(3, 3)
        b = tn.Browser().login(name)
        s, loc, page = sa_act(self.sa, 'user_disable', user_id=uid)
        self.assertIn('The user was turned off.', page)
        self.assertFalse(logged_in(b))
        self.assertIn('Disabled Account', try_login(name))
        # the release before this one reads the legacy level: negative while turned off
        self.assertLess(tn.sql("SELECT AdminLevel AS l FROM phoromatic_users WHERE UserID='%s'" % uid)[0]['l'], 0)
        self.assertEqual(audit('user_disabled')[-1]['SubjectLabel'], name)
        sa_act(self.sa, 'user_enable', user_id=uid)
        self.assertEqual(tn.sql("SELECT AdminLevel AS l FROM phoromatic_users WHERE UserID='%s'" % uid)[0]['l'], 3)
        tn.Browser().login(name)
        # no feed, no trigger while off
        sa_act(self.sa, 'user_disable', user_id=uid)
        key = tn.sql("SELECT 1 FROM phoromatic_users WHERE UserID='%s'" % uid)
        self.assertTrue(key)
        self.assertEqual(tn.Browser().get(tn.feed_path(name))[2], '')
        # (a server administrator cannot turn themselves off)
        me = tn.sql("SELECT UserID FROM phoromatic_users WHERE UserName='tn1lvl2'")[0]['UserID']
        s, loc, page = sa_act(self.sa, 'user_disable', user_id=me)
        self.assertIn('You cannot turn yourself off', page)

    def test_suspending_the_account_they_are_in_moves_a_member_to_the_next_account(self):
        aid3, name3, user3 = self.new_tenant('T5 moving')
        name, uid = self.dual(3, 3, other=aid3)
        b = tn.Browser().login(name)
        switch(b, aid3)
        self.assertEqual(current(b), name3)
        sa_act(self.sa, 'tenant_suspend', account_id=aid3)
        page = follow(b, '/?main')
        self.assertIn('is no longer available to you', page)
        self.assertIn('Account %s' % self.t1, page)
        self.assertIn('(suspended)', page, 'the suspended account is still listed, greyed')


class UsersPage(T5):
    def test_the_users_page_lists_the_members_of_the_account_not_the_users_whose_home_it_is(self):
        name, uid = self.dual(3, 10)
        a1, a2 = tn.Browser().login('tn1admin'), tn.Browser().login('tn2admin')
        p1, p2 = a1.get('/?users')[2], a2.get('/?users')[2]
        self.assertIn(name, p1)
        self.assertNotIn('member from another account', p1.split(name)[1][:80])
        self.assertIn(name, p2)
        self.assertIn('member from another account', p2)
        for other in ('tn1admin', 'tn1lvl2', 'tn1lvl3', 'tn1lvl10'):
            self.assertNotIn(other, p2, 'T2 sees none of T1\'s other users')
        self.assertNotIn('tn2admin', p1)
        self.assertNotIn(name + '@example.com', p1 + p2, 'no address is shown')

    def test_a_level_change_on_the_users_page_changes_that_accounts_membership_and_keeps_the_home_level_in_step(self):
        name, uid = self.dual(3, 3)
        a2 = tn.Browser().login('tn2admin')
        tok = a2.token('/?users')
        a2.post('/?users', {'update_user_levels': uid, 'admin_level_' + uid: '10', 'token_submit': tok})
        self.assertEqual((membership(name, self.t2), membership(name, self.t1)), (10, 3))
        self.assertEqual(tn.sql("SELECT AdminLevel AS l FROM phoromatic_users WHERE UserID='%s'" % uid)[0]['l'], 3, 'the legacy level is the home account\'s')
        # the home account's admin changes the home level; the legacy column follows
        a1 = tn.Browser().login('tn1admin')
        a1.post('/?users', {'update_user_levels': uid, 'admin_level_' + uid: '2', 'token_submit': a1.token('/?users')})
        self.assertEqual((membership(name, self.t1), tn.sql("SELECT AdminLevel AS l FROM phoromatic_users WHERE UserID='%s'" % uid)[0]['l']), (2, 2))
        a1.post('/?users', {'update_user_levels': uid, 'admin_level_' + uid: '-1', 'token_submit': a1.token('/?users')})
        self.assertEqual(tn.sql("SELECT AdminLevel AS l FROM phoromatic_users WHERE UserID='%s'" % uid)[0]['l'], -1)
        self.assertEqual(membership(name, self.t2), 10, 'the other account is untouched')
        # the T2 admin cannot change the level of a user who is not a member of T2
        lone = tn.sql("SELECT UserID FROM phoromatic_users WHERE UserName='tn1lvl3'")[0]['UserID']
        a2.post('/?users', {'update_user_levels': lone, 'admin_level_' + lone: '-1', 'token_submit': a2.token('/?users')})
        self.assertEqual(membership('tn1lvl3', self.t1), 3)
        self.assertEqual(audit_total() > 0, True)
        self.assertTrue(tenant_audit('member_level_changed', self.t2))

    def test_a_new_user_made_on_the_users_page_is_a_member_of_that_account_only(self):
        a2 = tn.Browser().login('tn2admin')
        user = 'newbie' + uuid.uuid4().hex[:6]
        a2.post('/?users', {'username': user, 'password': tn.PW, 'confirm_password': tn.PW, 'email': user + '@example.com', 'admin_level': '3', 'token_submit': a2.token('/?users')})
        row = tn.sql("SELECT UserID, AccountID, AdminLevel FROM phoromatic_users WHERE UserName='%s'" % user)[0]
        self.addCleanup(self.forget, row['UserID'])
        self.assertEqual((row['AccountID'], row['AdminLevel']), (self.t2, 3))
        self.assertEqual((membership(user, self.t2), membership(user, self.t1)), (3, None))
        self.assertTrue(tn.sql("SELECT 1 FROM phoromatic_user_settings WHERE UserID='%s' AND AccountID='%s'" % (row['UserID'], self.t2)))
        self.assertIn('Log-In Successful', try_login(user))


class Join(T5):
    def tearDown(self):
        tn.sql_exec("DELETE FROM phoromatic_memberships WHERE AccountID='%s' AND UserID IN (SELECT UserID FROM phoromatic_users WHERE UserName='tn1lvl2')" % self.t2)
        tn.sql_exec("DELETE FROM phoromatic_user_settings WHERE AccountID='%s' AND UserID IN (SELECT UserID FROM phoromatic_users WHERE UserName='tn1lvl2')" % self.t2)

    def test_joining_an_account_is_audited_twice_shown_to_its_admins_and_leaving_is_audited_too(self):
        sa = tn.Browser().login('tn1lvl2')
        self.assertEqual(tn.sql("SELECT COUNT(*) AS n FROM phoromatic_memberships WHERE AccountID='%s' AND UserID IN (SELECT UserID FROM phoromatic_users WHERE UserName='tn1lvl2')" % self.t2)[0]['n'], 0)
        # the console page shows counts and members, not the account's data
        detail = sa.get('/?server_tenants/%s' % self.t2)[2]
        self.assertNotIn('CANARY_T2', detail)
        self.assertIn('Join this account', detail)
        s, loc, page = sa_act(sa, 'join_tenant', account_id=self.t2, level='10')
        self.assertIn('You joined the account', page)
        self.assertEqual(membership('tn1lvl2', self.t2), 10, 'Join is a real membership, a viewer by default in the form')
        mine = audit('server_admin_joined_tenant', self.t2)
        self.assertEqual((len(mine), mine[-1]['Actor'], mine[-1]['SubjectLabel'], json.loads(mine[-1]['Detail'])), (1, 'tn1lvl2', 'tn1lvl2', {'level': 10}))
        theirs = tenant_audit('server_admin_joined_tenant', self.t2)
        self.assertEqual((len(theirs), theirs[-1]['Actor']), (1, 'tn1lvl2'), "the account's own audit has it")
        # the account's administrators see them, with the server administrator badge
        page2 = tn.Browser().login('tn2admin').get('/?users')[2]
        self.assertIn('tn1lvl2', page2)
        self.assertIn('server administrator', page2)
        # they switch in and see the account's data, as a viewer
        self.assertIn('Account %s' % self.t2, sa.get('/?main')[2])
        switch(sa, self.t2)
        self.assertIn('CANARY_T2', sa.get(self.own_page(self.t2))[2])
        before = snap(self.t2)
        sa.post('/?results/delete/%s' % self.st['T2']['pprid'], {'token_submit': sa.token('/?settings')})
        self.assertEqual(snap(self.t2), before, 'joined as a viewer, they cannot change anything')
        switch(sa, self.t1)
        # joining twice, and an account that is not active, are refused
        self.assertIn('already a member', sa_act(sa, 'join_tenant', account_id=self.t2, level='10')[2])
        aid3, name3, user3 = self.new_tenant('T5 join suspended')
        sa_act(sa, 'tenant_suspend', account_id=aid3)
        self.assertIn('Only an active account can be joined', sa_act(sa, 'join_tenant', account_id=aid3, level='10')[2])
        self.assertIsNone(membership('tn1lvl2', aid3))
        # leaving
        s, loc, page = sa_act(sa, 'leave_tenant', account_id=self.t2)
        self.assertIn('You left the account', page)
        self.assertIsNone(membership('tn1lvl2', self.t2))
        self.assertEqual(len(audit('server_admin_left_tenant', self.t2)), 1)
        self.assertEqual(len(tenant_audit('server_admin_left_tenant', self.t2)), 1)
        self.assertNotIn('tn1lvl2', tn.Browser().login('tn2admin').get('/?users')[2])
        # the only account cannot be left
        self.assertIn('your only', sa_act(sa, 'leave_tenant', account_id=self.t1)[2])

    def test_root_cannot_join_and_a_user_who_is_not_a_server_administrator_cannot_do_any_of_it(self):
        s, loc, page = act(self.root, 'join_tenant', token=console_token(self.root), account_id=self.t2, level='10')
        self.assertIn('Only a signed-in server administrator', page)
        # everyone who is not one gets 403 for every action of this step
        tn.cli('server-admin-revoke', 'tn1lvl2')
        self.addCleanup(tn.cli, 'server-admin-grant', 'tn1lvl2')
        before = (accounts_state(), audit_total(), members(self.t2))
        uid = tn.sql("SELECT UserID FROM phoromatic_users WHERE UserName='tn1lvl3'")[0]['UserID']
        for label, b in [('T1 level %d' % lvl, bb) for lvl, bb in tn.logins('T1').items()] + [('T2 admin', tn.Browser().login('tn2admin'))]:
            for action, fields in (('join_tenant', dict(account_id=self.t2, level='10')), ('leave_tenant', dict(account_id=self.t2)), ('member_add', dict(account_id=self.t2, user_name='tn1lvl3', level='1')),
                                   ('member_level', dict(account_id=self.t2, user_id=uid, level='1')), ('member_remove', dict(account_id=self.t1, user_id=uid)), ('user_disable', dict(user_id=uid)),
                                   ('user_enable', dict(user_id=uid)), ('user_password_reset', dict(user_id=uid, new_password='hacked-123', new_password_again='hacked-123'))):
                with self.subTest(who=label, action=action):
                    self.assertEqual(act(b, action, **fields)[0], 403)
            for page in ('/?server_users', '/?server_tenants/%s' % self.t2):
                self.assertIn('This page is for server administrators', b.get(page)[2])
        self.assertEqual((accounts_state(), audit_total(), members(self.t2)), before)
        tn.Browser().login('tn1lvl3')   # still works: the password was not reset

    def test_a_tenant_admin_cannot_add_a_user_of_another_account_only_a_server_administrator_can(self):
        before = members(self.t2)
        a2 = tn.Browser().login('tn2admin')
        a2.post('/?users', {'username': 'tn1lvl3', 'password': tn.PW, 'confirm_password': tn.PW, 'email': 'x@example.com', 'admin_level': '10', 'token_submit': a2.token('/?users')})
        self.assertEqual(members(self.t2), before)
        s, loc, page = sa_act(self.sa, 'member_add', account_id=self.t2, user_name='nosuchuser', level='10')
        self.assertIn('There is no user named', page)
        self.assertIn('Choose a level', sa_act(self.sa, 'member_add', account_id=self.t2, user_name='tn1lvl3', level='7')[2])
        self.assertEqual(members(self.t2), before)


class ConsoleUsers(T5):
    def test_the_users_page_of_the_console_lists_users_and_their_accounts_and_nothing_of_the_accounts_data(self):
        name, uid = self.dual(3, 10)
        page = self.sa.get('/?server_users')[2]
        self.assertIn(name, page)
        self.assertIn('Account %s (Power User)' % self.t1, page)
        self.assertIn('Account %s (Viewer)' % self.t2, page)
        self.assertNotIn('CANARY_T2', page)
        self.assertNotIn(name + '@example.com', page)
        self.assertIn(name, self.root.get('/?server_users')[2], 'the root login reaches it too')

    def test_a_server_administrator_and_root_set_a_password_and_it_is_audited_without_the_password(self):
        name, uid = self.dual(3, 3)
        for who, b in (('server administrator', self.sa), ('root', self.root)):
            new = 'reset-%s-12345' % who.split()[0]
            s, loc, page = act(b, 'user_password_reset', token=console_token(b), user_id=uid, new_password=new, new_password_again=new)
            self.assertIn('The password was changed.', page)
            tn.Browser().login(name, new)
            row = audit('user_password_reset')[-1]
            self.assertEqual((row['SubjectLabel'], row['ActorType']), (name, 'root' if who == 'root' else 'user'))
            self.assertNotIn(new, json.dumps(row))
        self.assertIn('do not match', act(self.sa, 'user_password_reset', token=console_token(self.sa), user_id=uid, new_password='abcdefg', new_password_again='abcdefh')[2])
        self.assertIn('at least 6', act(self.sa, 'user_password_reset', token=console_token(self.sa), user_id=uid, new_password='abc', new_password_again='abc')[2])
        tn.Browser().login(name, 'reset-root-12345')

    def test_root_s_admin_page_no_longer_has_the_password_override_or_the_disable_form(self):
        page = self.root.get('/?admin')[2]
        for gone in ('Override User Password', 'Change User Password', 'Disable User', 'change_user_password'):
            self.assertNotIn(gone, page)
        self.assertIn('server_users', page)
        before = tn.sql("SELECT UserName, Password, AdminLevel FROM phoromatic_users ORDER BY UserID")
        tok = self.root.token('/?admin')
        self.root.post('/?admin', {'change_user_password': 'tn1lvl3', 'new_user_password': 'hacked-12345', 'token_submit': tok})
        self.root.post('/?admin', {'disable_user': 'tn1lvl3', 'token_submit': tok})
        self.assertEqual(tn.sql("SELECT UserName, Password, AdminLevel FROM phoromatic_users ORDER BY UserID"), before, 'the old forms do nothing even when posted')


class Mail(T5):
    def ping_new_system(self, aid):
        tn.reset_v1_limits()
        tn.srv_sh('rm -f /tmp/tn-mail.log')
        tn.parse_v1(tn.raw_post('/phoromatic.php', {'r': 'ping', 'aid': aid, 'msi': str(uuid.uuid4()), 'pts_core': tn.CORE}))
        return self.mails()

    def notify_on(self, uid, aid, column='NotifyOnNewSystems', value=1):
        tn.sql_exec("UPDATE phoromatic_user_settings SET %s=%d WHERE UserID='%s' AND AccountID='%s'" % (column, value, uid, aid))

    def test_mail_goes_to_the_members_of_the_account_that_asked_wherever_their_own_account_is(self):
        name, uid = self.dual(3, 3)
        self.notify_on(uid, self.t2)
        self.notify_on(uid, self.t1, value=0)
        mail = self.ping_new_system(self.t2)
        self.assertIn('Phoromatic New System Added', mail)
        self.assertIn('<strong>%s:</strong>' % name, mail, 'a member whose own account is another one gets the mail of this one')
        self.assertIn('To: %s@example.com' % name, mail)
        # the mail of the other account (where they did not ask) does not reach them, and a T1 mail does when they ask there
        self.assertNotIn(name, self.ping_new_system(self.t1))
        self.notify_on(uid, self.t1)
        self.assertIn('<strong>%s:</strong>' % name, self.ping_new_system(self.t1))
        # the T2 administrator's choices are theirs: nobody else of T1 got T2's mail
        self.notify_on(uid, self.t2)
        for other in ('tn1admin', 'tn1lvl2', 'tn1lvl3'):
            self.assertNotIn('<strong>%s:</strong>' % other, self.ping_new_system(self.t2))

    def test_a_removed_a_turned_off_and_a_disabled_member_get_no_mail(self):
        name, uid = self.dual(3, 3)
        self.notify_on(uid, self.t2)
        self.assertIn(name, self.ping_new_system(self.t2))
        sa_act(self.sa, 'member_level', account_id=self.t2, user_id=uid, level='off')
        self.assertNotIn(name, self.ping_new_system(self.t2), 'turned off in the account')
        sa_act(self.sa, 'member_level', account_id=self.t2, user_id=uid, level='3')
        self.notify_on(uid, self.t2)
        self.assertIn(name, self.ping_new_system(self.t2))
        sa_act(self.sa, 'user_disable', user_id=uid)
        self.assertNotIn(name, self.ping_new_system(self.t2), 'turned off on the server')
        sa_act(self.sa, 'user_enable', user_id=uid)
        sa_act(self.sa, 'member_remove', account_id=self.t2, user_id=uid)
        self.assertNotIn(name, self.ping_new_system(self.t2), 'removed from the account')

    def test_every_notification_kind_uses_the_members_not_the_home_users(self):
        name, uid = self.dual(3, 3)
        for column in ('NotifyOnNewSystems', 'NotifyOnWarnings', 'NotifyOnHungSystems', 'NotifyOnResultUploads'):
            self.notify_on(uid, self.t2, column)
            got = tn.php_web_boot("echo json_encode(phoromatic_memberships::notify_recipients('%s', '%s'));" % (self.t2, column))
            self.assertIn(name, got, column)
            home = tn.php_web_boot("echo json_encode(phoromatic_memberships::notify_recipients('%s', '%s'));" % (self.t1, column))
            self.assertNotIn(name, home, column + ' in the account where they did not ask')
            self.notify_on(uid, self.t2, column, 0)
        self.assertEqual(tn.php_web_boot("echo json_encode(phoromatic_memberships::notify_recipients('%s', 'Password'));" % self.t2).strip(), '[]', 'only the four settings are a column')


class ServerRoles(T5):
    def test_a_purge_removes_the_server_role_of_a_user_who_belongs_to_no_other_account_and_keeps_it_for_one_who_does(self):
        aid, name, user = self.new_tenant('T5 purge roles')
        # a second user of the account, who also belongs to T2
        other = 'purgeother' + uuid.uuid4().hex[:5]
        tn.make_user(tn.Browser().login(user), other, 3)
        other_uid = tn.sql("SELECT UserID FROM phoromatic_users WHERE UserName='%s'" % other)[0]['UserID']
        self.addCleanup(self.forget, other_uid)
        sa_act(self.sa, 'member_add', account_id=self.t2, user_name=other, level='10')
        for u in (user, other):
            self.assertIn('Done', tn.cli('server-admin-grant', u))
        gone_uid = tn.sql("SELECT UserID FROM phoromatic_users WHERE UserName='%s'" % user)[0]['UserID']
        self.addCleanup(self.forget, gone_uid)
        self.assertIn(user, tn.cli('server-admin-list'))
        sa_act(self.sa, 'tenant_suspend', account_id=aid)
        sa_act(self.sa, 'tenant_delete', account_id=aid, confirm_name=name)
        listing = tn.cli('server-admin-list')
        self.assertIn(user, listing)
        self.assertIn('belongs to no active account', listing.split(user)[1].splitlines()[0], 'deleted but not purged: the role is listed as not active')
        self.assertNotIn('belongs to no active account', listing.split(other)[1].splitlines()[0])
        s, loc, page = sa_act(self.sa, 'tenant_purge', account_id=aid, confirm_name=name, confirm_id=aid)
        self.assertIn('was purged', page)
        roles = {r['UserID'] for r in tn.sql('SELECT UserID FROM phoromatic_server_roles')}
        self.assertNotIn(gone_uid, roles, 'the purged account\'s own server administrator has no role left to carry into another account')
        self.assertIn(other_uid, roles, 'a user who still belongs to T2 keeps it')
        revoked = [r for r in audit('server_role_revoked', aid) if r['SubjectLabel'] == user]
        self.assertEqual(len(revoked), 1)
        self.assertEqual(json.loads(audit('tenant_purged', aid)[-1]['Detail'])['server_roles_removed'], [user])
        self.assertNotIn(user, tn.cli('server-admin-list'))
        # and the role cannot be granted to them again: they belong to no account
        self.assertIn('belongs to no working account', tn.cli('server-admin-grant', user))
        # the one who is still a member of T2 can log in there (the purge took only the purged account's membership)
        tn.Browser().login(other)


class LegacyLevel(T5):
    def test_the_legacy_level_is_the_home_membership_after_every_kind_of_change(self):
        name, uid = self.dual(3, 10)

        def legacy():
            return tn.sql("SELECT AdminLevel AS l FROM phoromatic_users WHERE UserID='%s'" % uid)[0]['l']
        self.assertEqual(legacy(), 3)
        sa_act(self.sa, 'member_level', account_id=self.t2, user_id=uid, level='1')
        self.assertEqual(legacy(), 3, 'a change in another account does not touch it')
        sa_act(self.sa, 'member_level', account_id=self.t1, user_id=uid, level='2')
        self.assertEqual(legacy(), 2)
        sa_act(self.sa, 'member_level', account_id=self.t1, user_id=uid, level='off')
        self.assertEqual(legacy(), -1)
        sa_act(self.sa, 'member_level', account_id=self.t1, user_id=uid, level='10')
        sa_act(self.sa, 'user_disable', user_id=uid)
        self.assertEqual(legacy(), -10, 'turned off on the server: negative, so the release before this one refuses them too')
        sa_act(self.sa, 'user_enable', user_id=uid)
        self.assertEqual(legacy(), 10)
        sa_act(self.sa, 'member_remove', account_id=self.t1, user_id=uid)
        self.assertEqual(legacy(), -1, 'no home membership at all: the release before this one cannot let them in either')


class Review(T5):
    """What AP-5's review of the first T5 build found, and Dave's last-administrator rule."""

    def test_a_post_sent_after_the_session_was_moved_changes_nothing_in_either_account(self):
        name, uid = self.dual(3, 3)
        b = tn.Browser().login(name)
        switch(b, self.t2)
        tok = b.token('/?users')
        users_before = tn.sql("SELECT COUNT(*) AS n FROM phoromatic_users")[0]['n']
        sa_act(self.sa, 'member_remove', account_id=self.t2, user_id=uid)
        before = (snap(self.t1), snap(self.t2))
        late = 'late' + uuid.uuid4().hex[:6]
        old_cookie = [c.value for c in b.jar if c.name.startswith('PHPSESSID')]
        s, h, body = b.post('/?users', {'username': late, 'password': tn.PW, 'confirm_password': tn.PW, 'email': late + '@example.com', 'admin_level': '10', 'token_submit': tok})
        self.assertEqual((s, h.get('Location')), (302, '/?main'), 'the request that finds out ends in a redirect, it is not carried on')
        self.assertFalse(tn.sql("SELECT 1 FROM phoromatic_users WHERE UserName='%s'" % late))
        self.assertEqual(tn.sql("SELECT COUNT(*) AS n FROM phoromatic_users")[0]['n'], users_before)
        self.assertEqual((snap(self.t1)['rows'], snap(self.t2)['rows']), (before[0]['rows'], before[1]['rows']))
        self.assertNotEqual(old_cookie, [c.value for c in b.jar if c.name.startswith('PHPSESSID')], 'and the session id changed')
        self.assertIn('is no longer available to you', b.get('/?main')[2])

    def test_the_last_group_administrator_cannot_be_removed_demoted_or_turned_off_anywhere(self):
        aid, name, user = self.new_tenant('T5 last admin')
        uid = tn.sql("SELECT UserID FROM phoromatic_users WHERE UserName='%s'" % user)[0]['UserID']
        for action, fields in (('member_level', dict(level='3')), ('member_level', dict(level='off')), ('member_remove', {})):
            s, loc, page = sa_act(self.sa, action, account_id=aid, user_id=uid, **fields)
            self.assertIn('last Group Administrator', page, action)
        self.assertIn('last Group Administrator', sa_act(self.sa, 'user_disable', user_id=uid)[2])
        self.assertEqual(membership(user, aid), 1)
        self.assertIsNone(tn.sql("SELECT DisabledOn AS d FROM phoromatic_users WHERE UserID='%s'" % uid)[0]['d'])
        # the account's own Group Administrator: no Users-page control touches a Group Administrator at all
        a = tn.Browser().login(user)
        a.post('/?users', {'update_user_levels': uid, 'admin_level_' + uid: '-1', 'remove_member': uid, 'token_submit': a.token('/?users')})
        self.assertEqual(membership(user, aid), 1)
        # with a second one the first can go, and then the second is the last (also when it is a server administrator who joined: leaving is refused)
        sa = tn.Browser().login('tn1lvl2')
        self.addCleanup(tn.sql_exec, "DELETE FROM phoromatic_memberships WHERE AccountID='%s' AND UserID IN (SELECT UserID FROM phoromatic_users WHERE UserName='tn1lvl2')" % aid)
        self.assertIn('You joined', sa_act(sa, 'join_tenant', account_id=aid, level='1')[2])
        self.assertEqual(membership('tn1lvl2', aid), 1)
        self.assertIn('The level was changed', sa_act(self.sa, 'member_level', account_id=aid, user_id=uid, level='off')[2])
        self.assertIn('last Group Administrator', sa_act(sa, 'leave_tenant', account_id=aid)[2])
        self.assertEqual(membership('tn1lvl2', aid), 1)
        # the Group Administrator cannot touch another Group Administrator on the Users page (F4), whatever the level they ask for
        sa_act(self.sa, 'member_level', account_id=aid, user_id=uid, level='1')
        a = tn.Browser().login(user)   # (turned off a moment ago, their session ended)
        other = tn.sql("SELECT UserID FROM phoromatic_users WHERE UserName='tn1lvl2'")[0]['UserID']
        a.post('/?users', {'update_user_levels': other, 'admin_level_' + other: '10', 'token_submit': a.token('/?users')})
        self.assertEqual(membership('tn1lvl2', aid), 1)
        a.post('/?users', {'update_user_levels': '', 'remove_member': other, 'token_submit': a.token('/?users')})
        self.assertEqual(membership('tn1lvl2', aid), 1)
        # a server administrator can demote one of two
        self.assertIn('The level was changed', sa_act(self.sa, 'member_level', account_id=aid, user_id=other, level='10')[2])

    def test_a_group_administrator_cannot_take_a_user_out_of_their_own_account(self):
        aid, name, user = self.new_tenant('T5 home')
        a = tn.Browser().login(user)
        member = 'homey' + uuid.uuid4().hex[:5]
        muid = tn.make_user(a, member, 3)
        self.addCleanup(self.forget, muid)
        page = a.post('/?users', {'update_user_levels': '', 'remove_member': muid, 'token_submit': a.token('/?users')})[2]
        self.assertIn('server administrator has to take them out', page)
        self.assertEqual(membership(member, aid), 3)
        # a member from another account can be removed (the user keeps their own account)
        sa_act(self.sa, 'member_add', account_id=aid, user_name='tn1lvl10', level='10')
        luid = tn.sql("SELECT UserID FROM phoromatic_users WHERE UserName='tn1lvl10'")[0]['UserID']
        self.assertIn('was removed from this account', a.post('/?users', {'update_user_levels': '', 'remove_member': luid, 'token_submit': a.token('/?users')})[2])
        self.assertEqual(membership('tn1lvl10', self.t1), 10)

    def test_leaving_counts_every_membership_and_only_working_accounts_need_another(self):
        aid3, n3, u3 = self.new_tenant('T5 leave a')
        aid4, n4, u4 = self.new_tenant('T5 leave b')
        sa = tn.Browser().login('tn1lvl2')
        for aid in (aid3, aid4):
            self.addCleanup(tn.sql_exec, "DELETE FROM phoromatic_memberships WHERE AccountID='%s' AND UserID IN (SELECT UserID FROM phoromatic_users WHERE UserName='tn1lvl2')" % aid)
            sa_act(sa, 'join_tenant', account_id=aid, level='10')
        me = tn.sql("SELECT UserID FROM phoromatic_users WHERE UserName='tn1lvl2'")[0]['UserID']
        # turned off in the first: not a working account, and it can still be left
        sa_act(self.sa, 'member_level', account_id=aid3, user_id=me, level='off')
        self.assertIn('You left the account', sa_act(sa, 'leave_tenant', account_id=aid3)[2])
        self.assertIsNone(membership('tn1lvl2', aid3))
        # suspended: not working either, so the working ones are T1 and the second; the second can be left, T1 not after it
        sa_act(self.sa, 'tenant_suspend', account_id=aid4)
        self.assertIn('You left the account', sa_act(sa, 'leave_tenant', account_id=aid4)[2])
        self.assertIn('only working account', sa_act(sa, 'leave_tenant', account_id=self.t1)[2])
        self.assertEqual(membership('tn1lvl2', self.t1), 2)

    def test_the_users_page_shows_when_a_membership_was_last_used_not_the_last_sign_in(self):
        name, uid = self.dual(3, 3)
        b = tn.Browser().login(name)
        row = lambda page: page.split(name)[1][:700]
        self.assertIn('Last used: Never', row(tn.Browser().login('tn2admin').get('/?users')[2]), 'signed in, but never used in T2')
        switch(b, self.t2)
        self.assertNotIn('Last used: Never', row(tn.Browser().login('tn2admin').get('/?users')[2]))
        self.assertNotIn('Last Login', tn.Browser().login('tn2admin').get('/?users')[2])

    def test_turning_a_user_off_and_a_new_password_are_in_the_audit_of_every_account_the_user_belongs_to(self):
        name, uid = self.dual(3, 3)
        before = {(a, act_): len(tenant_audit(act_, a)) for a in (self.t1, self.t2) for act_ in ('user_disabled', 'user_enabled', 'user_password_reset')}
        sa_act(self.sa, 'user_disable', user_id=uid)
        sa_act(self.sa, 'user_enable', user_id=uid)
        sa_act(self.sa, 'user_password_reset', user_id=uid, new_password='another-12345', new_password_again='another-12345')
        for (a, act_), n in before.items():
            self.assertEqual(len(tenant_audit(act_, a)), n + 1, (a, act_))
        self.assertNotIn('another-12345', json.dumps(tenant_audit('user_password_reset', self.t2)))

    def test_what_the_release_before_wrote_to_the_legacy_level_is_kept_when_the_server_runs_again(self):
        name, uid = self.dual(3, 3)
        off = tn.make_user(tn.Browser().login('tn1admin'), 'rbk' + uuid.uuid4().hex[:5], 3)
        self.addCleanup(self.forget, off)
        # (the old release writes only phoromatic_users.AdminLevel: a level change and a turn-off)
        tn.sql_exec("UPDATE phoromatic_users SET AdminLevel=10 WHERE UserID='%s'; UPDATE phoromatic_users SET AdminLevel=-3 WHERE UserID='%s'" % (uid, off))
        tn.Browser().get('/')
        self.assertEqual(membership(name, self.t1), 10)
        self.assertEqual(membership(name, self.t2), 3, 'the other account is not touched')
        self.assertEqual(tn.sql("SELECT AdminLevel AS l FROM phoromatic_memberships WHERE UserID='%s'" % off)[0]['l'], -3)
        self.assertTrue(audit('member_level_changed'))
        # a user made on the old release has no membership: they get their home one
        row = tn.sql("SELECT * FROM phoromatic_users WHERE UserID='%s'" % uid)[0]
        newid = 'RB' + uuid.uuid4().hex[:4].upper()
        self.addCleanup(self.forget, newid)
        tn.sql_exec("INSERT INTO phoromatic_users (UserID, AccountID, UserName, Email, Password, CreatedOn, AdminLevel) VALUES ('%s', '%s', 'oldmade%s', 'x@example.com', '%s', '2026-01-01 00:00:00', 2)" % (newid, self.t1, newid.lower(), row['Password']))
        tn.Browser().get('/')
        self.assertEqual(membership('oldmade' + newid.lower(), self.t1), 2)
        self.assertEqual(len(tn.sql("SELECT 1 FROM phoromatic_memberships WHERE UserID='%s'" % newid)), 1)
        self.assertEqual(tn.sql("SELECT AdminLevel AS l FROM phoromatic_users WHERE UserID='%s'" % newid)[0]['l'], 2, 'and the legacy level is what it was')


if __name__ == '__main__':
    unittest.main()
