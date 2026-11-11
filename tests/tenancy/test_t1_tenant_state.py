"""T1: the tenant state gate. N20 (suspended: every client resource gets the suspended error and nothing changes; deleted: "Invalid User"), N23
(a suspended account's native agent gets 403 and no job), N28 (a session loses access on the next request after a suspend, a level cut or a
removal), discovery that ignores suspended and removed accounts, and the places that skip a suspended account (feed, event.php, event server).
The lifecycle pages come later; here the state is put in the database the way they will."""
import hashlib
import json
import re
import unittest

import tn

SUSPENDED = 'This Phoromatic account is suspended.'


def setUpModule():
    tn.reset_v1_limits()


def tearDownModule():
    tn.reset_v1_limits()


class StateRestoring(unittest.TestCase):
    def setUp(self):
        self.st = tn.state()
        self.t1, self.t2 = self.st['T1']['aid'], self.st['T2']['aid']
        self.addCleanup(tn.set_state, self.t1, 'active')
        self.addCleanup(tn.set_state, self.t2, 'active')
        self.addCleanup(tn.sql_exec, "UPDATE phoromatic_users SET AdminLevel=2 WHERE UserName='tn1lvl2'")


class V1ClientsOfAnInactiveAccount(StateRestoring):
    def setUp(self):
        super().setUp()
        tn.reset_v1_limits()   # (a deleted account's requests count as requests for an unknown one: about twenty in a test)
        self.addCleanup(tn.reset_v1_limits)

    def resources(self):
        return json.loads(tn.php_boot('echo json_encode(phoromatic_server::client_resources());'))

    def test_a_suspended_account_gets_the_suspended_error_from_every_resource_and_nothing_changes(self):
        # N20
        msi = 'CANARY_T2_msi'
        tn.set_state(self.t2, 'suspended')
        before = tn.snapshot(self.t2)
        names = self.resources()
        self.assertGreater(len(names), 10)
        for r in names:
            with self.subTest(resource=r):
                reply = tn.v1(r, self.t2, msi)
                self.assertEqual(reply.get('error'), SUSPENDED, reply)
        self.assertEqual(tn.snapshot(self.t2), before)

    def test_a_deleted_or_unknown_account_is_an_invalid_user_and_nothing_changes(self):
        tn.set_state(self.t2, 'deleted')
        before = tn.snapshot(self.t2)
        rows = tn.sql('SELECT COUNT(*) AS n FROM phoromatic_systems')[0]['n']
        for r in self.resources():
            with self.subTest(resource=r):
                self.assertEqual(tn.v1(r, self.t2, 'CANARY_T2_msi').get('error'), 'Invalid User')
        self.assertEqual(tn.v1('ping', 'ZZZZZZ', 'tn-unknown-msi').get('error'), 'Invalid User')
        self.assertEqual(tn.snapshot(self.t2), before)
        self.assertEqual(tn.sql('SELECT COUNT(*) AS n FROM phoromatic_systems')[0]['n'], rows, 'no pending row was made')

    def test_an_active_account_is_unchanged_by_the_gate(self):
        msi = tn.approved_machine(self.t1)
        self.assertNotIn('error', tn.v1('ping', self.t1, msi))

    def test_discovery_by_machine_id_ignores_an_inactive_account(self):
        # N17
        msi = tn.approved_machine(self.t1)
        self.assertEqual(tn.parse_v1(tn.raw_post('/phoromatic.php', {'r': 'ping', 'msi': msi, 'pts_core': tn.CORE})).get('account_id'), self.t1)
        for state in ('suspended', 'deleted'):
            tn.set_state(self.t1, state)
            self.assertNotIn('account_id', tn.parse_v1(tn.raw_post('/phoromatic.php', {'r': 'ping', 'msi': msi, 'pts_core': tn.CORE})), state)

    def test_discovery_by_ip_or_mac_claim_ignores_an_inactive_account(self):
        msi = str(tn.uuid.uuid4())
        tn.sql_exec("INSERT INTO phoromatic_system_association_claims (AccountID, IPAddress, NetworkMAC, CreationTime) VALUES ('%s', 'tn-ip', 'aa:bb:cc:00:11:22', '2026-11-01 10:00:00')" % self.t1)
        self.addCleanup(tn.sql_exec, "DELETE FROM phoromatic_system_association_claims WHERE IPAddress='tn-ip'")
        ask = lambda: tn.parse_v1(tn.raw_post('/phoromatic.php', {'r': 'ping', 'msi': msi, 'nm': 'aa:bb:cc:00:11:22', 'pts_core': tn.CORE}))
        self.assertEqual(ask().get('account_id'), self.t1)
        tn.set_state(self.t1, 'suspended')
        self.assertIsNone(ask().get('account_id'))


class NativeAgentOfASuspendedAccount(StateRestoring):
    def test_a_suspended_account_gets_403_and_no_job(self):
        # N23
        tn.cli('phoromatic-v2-allow-http', 'on')
        self.addCleanup(tn.cli, 'phoromatic-v2-allow-http', 'off')
        msi = tn.approved_machine(self.t2)       # a live approved system (the canary rows carry junk in DeletedOn)
        system = tn.sql("SELECT SystemID FROM phoromatic_systems WHERE MachineSelfID='%s'" % msi)[0]['SystemID']
        token = tn.add_token_agent(self.t2, system)
        jobs = tn.sql("SELECT COUNT(*) AS n FROM phoromatic_v2_jobs WHERE AccountID='%s'" % self.t2)[0]['n']
        self.assertNotIn(tn.post_json('/api/v2/agent/poll', {}, token)[0], (401, 403, 426), 'the token works while the account is active')
        tn.set_state(self.t2, 'suspended')
        status, body = tn.post_json('/api/v2/agent/poll', {}, token)
        self.assertEqual(status, 403, body)
        self.assertEqual(body['error']['code'], 'tenant_suspended')
        status, body = tn.post_json('/api/v2/agent/hello', {}, token)
        self.assertEqual(status, 403, body)
        self.assertEqual(tn.sql("SELECT COUNT(*) AS n FROM phoromatic_v2_jobs WHERE AccountID='%s'" % self.t2)[0]['n'], jobs)
        tn.set_state(self.t2, 'deleted')
        self.assertEqual(tn.post_json('/api/v2/agent/poll', {}, token)[0], 401)


class SessionsFollowTheDatabase(StateRestoring):
    def test_a_session_ends_on_the_next_request_after_a_suspend(self):
        # N28
        b = tn.Browser().login(self.st['users']['T1']['1'])
        self.assertIn('Logout', b.get('/?main')[2])
        tn.set_state(self.t1, 'suspended')
        page = b.get('/?main')[2]
        self.assertIn('login_form', page)
        self.assertNotIn('Logout', page)
        tn.set_state(self.t1, 'active')
        self.assertIn('login_form', b.get('/?main')[2], 'the session did not come back by itself')

    def test_logging_in_to_a_suspended_account_is_refused_with_the_right_password_only(self):
        tn.set_state(self.t1, 'suspended')
        b = tn.Browser()
        b.get('/')
        user = self.st['users']['T1']['1']
        good = b.post('/?login', {'username': user, 'password': tn.PW})[2]
        self.assertIn('Account Suspended', good)
        self.assertNotIn('Log-In Successful', good)
        wrong = b.post('/?login', {'username': user, 'password': 'not-the-password'})[2]
        self.assertIn('Invalid Information', wrong)
        self.assertNotIn('suspended', wrong.lower())
        tn.set_state(self.t1, 'deleted')
        self.assertIn('Disabled Account', b.post('/?login', {'username': user, 'password': tn.PW})[2])
        tn.set_state(self.t1, 'active')
        tn.Browser().login(user)

    def test_a_demoted_users_level_applies_on_the_next_request(self):
        b = tn.Browser().login('tn1lvl2')
        page = b.get('/?main')[2]
        self.assertIn('Create A Schedule', page)
        self.assertIn('?users', page)
        tn.sql_exec("UPDATE phoromatic_users SET AdminLevel=10 WHERE UserName='tn1lvl2'")
        page = b.get('/?main')[2]
        self.assertNotIn('Create A Schedule', page, 'now a viewer')
        self.assertNotIn('?users', page)
        self.assertIn('Logout', page, 'still logged in, as a viewer')
        self.assertEqual(b.get('/?sched')[2].count('name="schedule_title"'), 0)

    def test_a_level_raised_applies_too(self):
        b = tn.Browser().login('tn1lvl3')
        tn.sql_exec("UPDATE phoromatic_users SET AdminLevel=2 WHERE UserName='tn1lvl3'")
        self.addCleanup(tn.sql_exec, "UPDATE phoromatic_users SET AdminLevel=3 WHERE UserName='tn1lvl3'")
        self.assertIn('?users', b.get('/?main')[2])

    def test_a_disabled_or_removed_user_is_logged_out_on_the_next_request(self):
        for how, sql, undo in (('disabled', "UPDATE phoromatic_users SET AdminLevel=0 WHERE UserName='tn1lvl2'", "UPDATE phoromatic_users SET AdminLevel=2 WHERE UserName='tn1lvl2'"),):
            with self.subTest(how=how):
                b = tn.Browser().login('tn1lvl2')
                self.assertIn('Logout', b.get('/?main')[2])
                tn.sql_exec(sql)
                self.addCleanup(tn.sql_exec, undo)
                self.assertIn('login_form', b.get('/?main')[2])
                tn.sql_exec(undo)
        # a removed user: the row is gone
        row = tn.sql("SELECT * FROM phoromatic_users WHERE UserName='tn1lvl3'")[0]
        b = tn.Browser().login('tn1lvl3')
        tn.sql_exec("DELETE FROM phoromatic_users WHERE UserName='tn1lvl3'")
        try:
            self.assertIn('login_form', b.get('/?main')[2])
        finally:
            cols = ', '.join(row)
            vals = ', '.join("'%s'" % str(v).replace("'", "''") if v is not None else 'NULL' for v in row.values())
            tn.sql_exec('INSERT INTO phoromatic_users (%s) VALUES (%s)' % (cols, vals))

    def test_the_session_level_comes_from_the_database_not_from_the_cookie_alone(self):
        # a viewer whose row says 1 is a level-1 user from the next request (the same refresh, the other way)
        b = tn.Browser().login('tn1lvl3')
        tn.sql_exec("UPDATE phoromatic_users SET AdminLevel=1 WHERE UserName='tn1lvl3'")
        self.addCleanup(tn.sql_exec, "UPDATE phoromatic_users SET AdminLevel=3 WHERE UserName='tn1lvl3'")
        self.assertIn('Create A Schedule', b.get('/?main')[2])


class SkippedWhileSuspended(StateRestoring):
    def feed(self, user='tn1admin'):
        return tn.Browser().get(tn.feed_path(user))[2]

    def test_the_results_feed_of_a_suspended_account_is_empty(self):
        self.assertIn('<rss', self.feed())
        tn.set_state(self.t1, 'suspended')
        self.assertEqual(self.feed().strip(), '')

    def test_a_suspended_accounts_results_are_in_nobodys_feed_even_if_a_sharing_flag_is_left_on(self):
        # (sharing is gone, so the flag changes nothing: the T2 results are in no feed before or after the suspension)
        tn.sql_exec("UPDATE phoromatic_account_settings SET LetOtherGroupsViewResults=1 WHERE AccountID='%s'" % self.t2)
        self.addCleanup(tn.sql_exec, "UPDATE phoromatic_account_settings SET LetOtherGroupsViewResults=0 WHERE AccountID='%s'" % self.t2)
        self.assertNotIn('TN2PPRA', self.feed())
        tn.set_state(self.t2, 'suspended')
        self.assertNotIn('TN2PPRA', self.feed())

    def trigger(self, user, key, name):
        return tn.Browser().get('/event.php?type=trigger&user=%s&public_key=%s&trigger=%s' % (user, key, name))[2]

    def test_event_php_records_nothing_for_a_suspended_or_unknown_account(self):
        key = tn.sql("SELECT PublicKey FROM phoromatic_schedules WHERE AccountID='%s' AND ScheduleID=%d" % (self.t1, tn.SHARED_SCHEDULE))[0]['PublicKey']
        count = lambda: tn.sql("SELECT COUNT(*) AS n FROM phoromatic_schedules_triggers")[0]['n']
        n = count()
        self.assertIn('added', self.trigger('tn1admin', key, 'tnev1'))
        self.assertEqual(count(), n + 1)
        tn.set_state(self.t1, 'suspended')
        self.assertIn('Incorrect user information', self.trigger('tn1admin', key, 'tnev2'))
        tn.set_state(self.t1, 'active')
        self.assertIn('Incorrect user information', self.trigger('no-such-user', key, 'tnev3'))
        self.assertIn('Incorrect user information', self.trigger('tn1admin', 'no-such-key', 'tnev4'))
        self.assertEqual(count(), n + 1)
        self.assertEqual(tn.sql("SELECT COUNT(*) AS n FROM phoromatic_schedules_triggers WHERE AccountID IS NULL OR ScheduleID IS NULL")[0]['n'], 0)

    def test_the_event_servers_walks_leave_out_a_suspended_account(self):
        # the clause every loop of the event server uses; the lint (lint_gates.py) shows each loop does
        tn.set_state(self.t2, 'suspended')
        out = tn.php_boot("phoromatic_server::prepare_database(); $r = phoromatic_server::$db->query('SELECT DISTINCT AccountID FROM phoromatic_systems WHERE ' . phoromatic_tenant::active_clause()); "
                          "while($r && ($x = $r->fetchArray(SQLITE3_ASSOC))) { echo $x['AccountID'], ' '; }")
        self.assertIn(self.t1, out)
        self.assertNotIn(self.t2, out)


class TenantStateFunction(unittest.TestCase):
    def test_state_is_a_row_not_a_folder(self):
        st = tn.state()
        t1, t2 = st['T1']['aid'], st['T2']['aid']
        code = "foreach(array('%s','%s','.purged-%s-20260101','..','ZZZZZZ','', 'A/B', null) as $a) echo json_encode($a), '=', json_encode(phoromatic_tenant::state($a)), ' ';" % (t1, t2, t2)
        out = tn.php_boot('phoromatic_server::prepare_database(); ' + code)
        self.assertIn('"%s"="active"' % t1, out)
        self.assertIn('"%s"="active"' % t2, out)
        for bad in ('".purged-%s-20260101"=null' % t2, '".."=null', '"ZZZZZZ"=null', '""=null', '"A\\/B"=null', 'null=null'):
            self.assertIn(bad, out)
        # an account with a folder but no row does not exist, and a row without a folder does
        tn.srv_sh('mkdir -p %s/accounts/TNFOLD' % tn.STORAGE)
        self.addCleanup(tn.srv_sh, 'rmdir %s/accounts/TNFOLD' % tn.STORAGE)
        self.assertIn('null', tn.php_boot("phoromatic_server::prepare_database(); echo json_encode(phoromatic_tenant::state('TNFOLD'));"))
        self.assertEqual(tn.v1('ping', 'TNFOLD', 'x').get('error'), 'Invalid User')

    def test_an_unknown_state_word_is_not_usable(self):
        t1 = tn.state()['T1']['aid']
        tn.sql_exec("UPDATE phoromatic_accounts SET State='odd' WHERE AccountID='%s'" % t1)
        self.addCleanup(tn.set_state, t1, 'active')
        self.assertIn('suspended', tn.php_boot("phoromatic_server::prepare_database(); echo phoromatic_tenant::state('%s');" % t1))
        tn.sql_exec("UPDATE phoromatic_accounts SET State=NULL WHERE AccountID='%s'" % t1)
        self.assertIn('active', tn.php_boot("phoromatic_server::prepare_database(); echo phoromatic_tenant::state('%s');" % t1))


if __name__ == '__main__':
    unittest.main()
