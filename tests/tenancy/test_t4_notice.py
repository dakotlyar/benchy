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
        t1a, t2a = tn.Browser().login('tn1admin'), tn.Browser().login('tn2admin')
        lvl2_sa, lvl3, lvl10 = self.sa, tn.Browser().login('tn1lvl3'), tn.Browser().login('tn1lvl10')
        body = t1a.get('/?main')[2]
        rows = tn.sql("SELECT Action, EventTime, SubjectLabel, Actor FROM phoromatic_server_audit WHERE Action IN ('migration', 'notice_dismissed')")
        me = tn.sql("SELECT UserID, CreatedOn, AdminLevel FROM phoromatic_users WHERE UserName='tn1admin'")
        self.assertIn(self.TEXT, body, (rows, me, re.sub(r'\s+', ' ', body[body.find('Phoromatic</h1>'):])[:300]))
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
        self.assertEqual(lvl3.get('/?r_server_action')[0], 405)
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


if __name__ == '__main__':
    unittest.main()
