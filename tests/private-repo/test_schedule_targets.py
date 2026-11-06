"""A schedule's target lists can be written as a bare number with no groups (one system whose five-character id happens to be all digits,
or the one left after another was deleted). The pages that add the two lists up must cope: before the fix that sum threw a TypeError
that made every logged-in page fatal (page-wide for the all-digit id, the tracker page for any system list with no groups).

Run through tests/private-repo/run.sh (part of http/all).
"""
import unittest
import uuid

from bench_helpers import drop_schedule, new_system
from test_private_repo_http import AID
from test_private_repo_tokens import sql_exec
from test_private_repo_web import WebBase

FATAL = ('Fatal error', 'Uncaught', 'Unsupported operand')


class TestTargetShapes(WebBase):
    def schedule_with_targets(self, systems, groups):
        sched = 770000 + uuid.uuid4().int % 9000
        self.assertEqual(sql_exec("INSERT INTO phoromatic_schedules (AccountID, ScheduleID, Title, Description, State, ActiveOn, RunAt, RunTargetSystems, RunTargetGroups, RunPriority, LastModifiedBy) VALUES ('%s', %d, 'qc targets', 'qc', 1, '0,1,2,3,4,5,6', '00.00', '%s', '%s', 100, 'qc')" % (AID, sched, systems, groups)), 'ok')
        self.addCleanup(drop_schedule, sched)

    def pages_are_fine(self):
        for path in ('/?main', '/?tracker', '/?schedules', '/?results'):
            body = self.admin.get(path)[2]
            for needle in FATAL:
                self.assertNotIn(needle, body, '%s: %s' % (path, needle))
            self.assertIn('phoromatic', body.lower(), path)

    def test_a_bare_number_target_with_no_groups(self):
        self.schedule_with_targets('12345', '')
        self.pages_are_fine()

    def test_a_bare_number_target_with_a_named_group(self):
        self.schedule_with_targets('12345', 'rack')
        self.pages_are_fine()

    def test_an_ordinary_system_list_with_no_groups_on_the_tracker(self):
        self.schedule_with_targets('7TGTL,ABCDE', '')
        self.pages_are_fine()


class TestScheduleWithNoDays(WebBase):
    """0.9.6: a schedule with no days ticked has ActiveOn '', and time_to_next_scheduled_job() multiplied that empty string by 1440, a
    TypeError that made the Systems page (and the dashboard) a 500 as soon as such a schedule targeted a system"""
    def test_the_systems_page_and_the_dashboard_survive_a_schedule_with_no_days(self):
        msi, sid = new_system()
        sched = 780000 + uuid.uuid4().int % 9000
        self.assertEqual(sql_exec("INSERT INTO phoromatic_schedules (AccountID, ScheduleID, Title, Description, State, ActiveOn, RunAt, RunTargetSystems, RunTargetGroups, RunPriority, LastModifiedBy) VALUES ('%s', %d, 'qc no days', 'qc', 1, '', '00.00', '%s', '', 100, 'qc')" % (AID, sched, sid)), 'ok')
        self.assertEqual(sql_exec("INSERT INTO phoromatic_schedules_tests (AccountID, ScheduleID, TestProfile, TestArguments, TestDescription) VALUES ('%s', %d, 'pts/qc-bench-1.0.0', '', 'qc')" % (AID, sched)), 'ok')
        self.addCleanup(drop_schedule, sched)
        for path in ('/?systems', '/?main'):
            status, _, body = self.admin.get(path)
            self.assertEqual(status, 200, path)
            for needle in FATAL:
                self.assertNotIn(needle, body, '%s: %s' % (path, needle))
            self.assertIn('phoromatic', body.lower(), path)


if __name__ == '__main__':
    unittest.main()
