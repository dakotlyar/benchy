"""T4: the one-time upgrade notice: shown once per user to level-1 users and server administrators of a server that was upgraded, to nobody else.
"""
import json
import re
import unittest
import uuid

import fixture
import tn
from t4 import *



MARKER = "SELECT MIN(EventTime) AS t, COUNT(*) AS n FROM phoromatic_server_audit WHERE Action = 'notice_marker'"


def set_marker(when):
    """The server's notice marker (the time it first started on this release) moved to `when`: the audit refuses DELETE, so the guard is lifted for one statement."""
    tn.sql_exec("DROP TRIGGER phoromatic_server_audit_no_delete; DELETE FROM phoromatic_server_audit WHERE Action = 'notice_marker'; "
                "INSERT INTO phoromatic_server_audit (EventTime, ActorType, Actor, Action, SubjectType, SubjectID) VALUES ('%s', 'system', 'server', 'notice_marker', 'notice', 'upgrade-0.9.14'); %s" % (when, TRIGGER))


class UpgradeNotice(Console):
    TEXT = 'What changed in this upgrade'

    @classmethod
    def setUpClass(cls):
        super().setUpClass()
        # the fixture server first started now, so every user it has is newer than its marker; the marker is moved ahead of the test users, as if they were there before the upgrade
        tn.reset_v1_limits()
        row = tn.sql(MARKER)[0]
        assert row['n'] == 1, 'the first start writes exactly one marker: %s' % row
        cls.real_marker = row['t']

    def setUp(self):
        set_marker('2099-01-01 00:00:00')

    @classmethod
    def tearDownClass(cls):
        forget_audit("Action = 'notice_dismissed' AND SubjectLabel = 'upgrade-0.9.14'")
        set_marker(cls.real_marker)
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

    def test_a_server_first_started_on_this_release_shows_nothing_to_anyone(self):
        set_marker(self.real_marker)
        aid, _, user = self.new_tenant('T4 notice C')
        b = tn.Browser().login(user)
        self.assertNotIn(self.TEXT, b.get('/?main')[2], 'every user is newer than the marker')
        tn.sql_exec("UPDATE phoromatic_users SET CreatedOn = '2026-01-01 00:00:00' WHERE UserName = '%s'" % user)
        self.assertIn(self.TEXT, tn.Browser().login(user).get('/?main')[2], 'control: the same user, if it had been there first, would see it')

    def test_a_user_made_between_earlier_releases_and_this_one_sees_it_and_one_made_after_does_not(self):
        # a server that was already on 0.9.11 to 0.9.13 shows it to every user it has when it first starts on this release
        set_marker('2026-06-01 00:00:00')
        old_aid, _, old_user = self.new_tenant('T4 notice old')
        new_aid, _, new_user = self.new_tenant('T4 notice new')
        tn.sql_exec("UPDATE phoromatic_users SET CreatedOn = '2026-02-01 00:00:00' WHERE UserName = '%s'" % old_user)
        tn.sql_exec("UPDATE phoromatic_users SET CreatedOn = '2026-09-01 00:00:00' WHERE UserName = '%s'" % new_user)
        self.assertIn(self.TEXT, tn.Browser().login(old_user).get('/?main')[2])
        self.assertNotIn(self.TEXT, tn.Browser().login(new_user).get('/?main')[2])
        self.assertEqual(tn.sql(MARKER)[0]['n'], 1, 'requests do not write more markers')


if __name__ == '__main__':
    unittest.main()
