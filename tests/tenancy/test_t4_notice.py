"""T4: the one-time upgrade notice: shown once per user to level-1 users and server administrators of a server that was upgraded, to nobody else.
"""
import json
import re
import unittest
import uuid

import fixture
import tn
from t4 import *



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
        # (accounts of their own: the fixture's T1 holds seeded canary values that its main page cannot draw)
        a, _, a1 = self.new_tenant('T4 notice A')
        b_aid, _, b1 = self.new_tenant('T4 notice B')
        t1a, t2a = tn.Browser().login(a1), tn.Browser().login(b1)
        tn.make_user(t1a, 't4n2' + a1[-6:], 2)
        tn.make_user(t1a, 't4n3' + a1[-6:], 3)
        tn.make_user(t1a, 't4n10' + a1[-6:], 10)
        tn.cli('server-admin-grant', 't4n2' + a1[-6:])
        self.addCleanup(tn.cli, 'server-admin-revoke', 't4n2' + a1[-6:])
        lvl2_sa, lvl3, lvl10 = tn.Browser().login('t4n2' + a1[-6:]), tn.Browser().login('t4n3' + a1[-6:]), tn.Browser().login('t4n10' + a1[-6:])
        body = t1a.get('/?main')[2]
        self.assertIn(self.TEXT, body)
        for line in ('public results page is gone', 'Results feed (RSS) links changed', 'Self-registration is closed', 'New systems wait for approval', 'enrollment code', 'server settings',
                     'HTTPS', 'private test repository'):
            self.assertIn(line, body)
        self.assertNotIn('Server</a> pages', body, 'only a server administrator is pointed at the console')
        self.assertIn(self.TEXT, lvl2_sa.get('/?main')[2], 'a server administrator at level 2')
        self.assertIn('Server</a> pages', lvl2_sa.get('/?main')[2])
        for label, b in (('level 3', lvl3), ('viewer', lvl10)):
            self.assertNotIn(self.TEXT, b.get('/?main')[2], label)
        self.assertNotIn(self.TEXT, self.root.get('/?admin')[2], 'root')
        # a refused dismissal changes nothing; a GET is not a dismissal
        total = audit_total()
        self.assertEqual(lvl3.get('/?r_server_action')[0], 405)
        act(lvl3, 'dismiss_notice')
        act(t1a, 'dismiss_notice', token='0' * 40)
        act(t1a, 'dismiss_notice', token=any_token(t1a), headers=EVIL)
        self.assertEqual(audit_total(), total)
        self.assertIn(self.TEXT, t1a.get('/?main')[2])
        # dismissed by one user: gone for that user, in a new session too, and still there for the others
        act(t1a, 'dismiss_notice', token=any_token(t1a))
        self.assertNotIn(self.TEXT, t1a.get('/?main')[2])
        self.assertNotIn(self.TEXT, tn.Browser().login(a1).get('/?main')[2])
        self.assertIn(self.TEXT, t2a.get('/?main')[2])
        self.assertIn(self.TEXT, lvl2_sa.get('/?main')[2])
        self.assertEqual(audit_total(), total + 1)
        row = audit('notice_dismissed')[-1]
        self.assertEqual((row['Actor'], row['SubjectLabel'], row['TenantID']), (a1, 'upgrade-0.9.14', a))

    def test_a_server_that_was_made_new_or_a_user_made_after_the_upgrade_sees_nothing(self):
        # users made now are newer than a migration dated in 2020
        forget_audit("Action = 'migration' AND EventTime = '2099-01-01 00:00:00'")
        self.addCleanup(tn.sql_exec, "INSERT INTO phoromatic_server_audit (EventTime, ActorType, Actor, Action, SubjectType, SubjectID) VALUES ('2099-01-01 00:00:00', 'migration', 'migration', 'migration', 'schema', '59')")
        aid, _, user = self.new_tenant('T4 notice C')
        self.assertNotIn(self.TEXT, tn.Browser().login(user).get('/?main')[2], 'no migration row: nothing changed')
        tn.sql_exec("INSERT INTO phoromatic_server_audit (EventTime, ActorType, Actor, Action, SubjectType, SubjectID) VALUES ('2020-01-01 00:00:00', 'migration', 'migration', 'migration', 'schema', '59')")
        self.addCleanup(forget_audit, "EventTime = '2020-01-01 00:00:00'")
        self.assertNotIn(self.TEXT, tn.Browser().login(user).get('/?main')[2], 'the user is newer than the upgrade')


if __name__ == '__main__':
    unittest.main()
