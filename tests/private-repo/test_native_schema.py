"""Native agents, step S3 (Benchy 0.9.4): the schema step (user_version 58), the engine policy and what hangs on it.

The migration is additive and repeatable; a system on the native engine is idle for the PHP client's `start`; and the bench summary
reads a native system's structured system info.

Run through tests/private-repo/run.sh (part of http/all).
"""
import json
import unittest

from bench_helpers import drop_schedule, new_system, schedule, start, system_row
from test_benches_multi import prepare_database
from test_private_repo_http import AID, sql, srv_sh
from test_private_repo_tokens import sql_exec

TABLES = ('phoromatic_enrol_codes', 'phoromatic_system_tokens', 'phoromatic_v2_jobs', 'phoromatic_schedules_native_tests', 'phoromatic_system_catalogue')


class TestMigration57(unittest.TestCase):
    def test_tables_column_and_indexes_exist_and_the_step_repeats(self):
        names = {r['name'] for r in sql("SELECT name FROM sqlite_master")}
        self.assertTrue(set(TABLES) <= names)
        self.assertTrue({'phoromatic_system_tokens_system', 'phoromatic_v2_jobs_system', 'phoromatic_v2_jobs_run'} <= names)
        self.assertIn('EnginePolicy', {c['name'] for c in sql('PRAGMA table_info(phoromatic_systems)')})
        self.assertEqual(sql('PRAGMA user_version')[0]['user_version'], 60)
        # a database that is back at 57 (a rollback and a re-upgrade) runs the step again and nothing changes or fails
        self.assertEqual(sql_exec("INSERT INTO phoromatic_system_catalogue (AccountID, SystemID, Ref, Version, TestJSON, ReportedOn) VALUES ('%s', 'QCMIG', 'benchy/x', '1', '{}', 'now')" % AID), 'ok')
        self.assertEqual(sql_exec('PRAGMA user_version = 57'), 'ok')
        prepare_database()
        prepare_database()
        self.assertEqual(sql('PRAGMA user_version')[0]['user_version'], 60)
        self.assertEqual(len(sql("SELECT * FROM phoromatic_system_catalogue WHERE SystemID='QCMIG'")), 1, 'data survives the repeated step')
        self.assertEqual(sql_exec("DELETE FROM phoromatic_system_catalogue WHERE SystemID='QCMIG'"), 'ok')

    def test_job_runs_are_unique_per_system(self):
        row = "INSERT INTO phoromatic_v2_jobs (AccountID, SystemID, Kind, PayloadJSON, State, RunID, IssuedOn) VALUES ('%s', '%s', 'schedule', '{}', 'issued', %s, 'now')"
        self.assertEqual(sql_exec(row % (AID, 'QCUNQ', "'20261102T030000Z-a1b2c3'")), 'ok')
        try:
            self.assertEqual(sql_exec(row % (AID, 'QCUNQ', "'20261102T030000Z-a1b2c3'")), 'refused')
            self.assertEqual(sql_exec(row % (AID, 'QCUNR', "'20261102T030000Z-a1b2c3'")), 'ok', 'another system may have the same run id')
            self.assertEqual(sql_exec(row % (AID, 'QCUNQ', 'NULL')), 'ok')
            self.assertEqual(sql_exec(row % (AID, 'QCUNQ', 'NULL')), 'ok', 'jobs with no run yet do not collide')
        finally:
            sql_exec("DELETE FROM phoromatic_v2_jobs WHERE SystemID IN ('QCUNQ', 'QCUNR')")


class TestNativePolicyIdlesTheOldClient(unittest.TestCase):
    def test_start_idles_for_a_native_system_and_works_as_before_otherwise(self):
        msi, sid = new_system()
        sched = schedule(sid)
        try:
            self.assertEqual(start(msi).get('task'), 'benchmark', 'control: a PTS system gets the schedule')
            self.assertEqual(sql_exec("UPDATE phoromatic_systems SET EnginePolicy='native' WHERE SystemID='%s'" % sid), 'ok')
            r = start(msi)
            self.assertEqual(r.get('task'), 'idle')
            self.assertIn('native engine', r.get('response', ''))
            self.assertEqual(sql_exec("UPDATE phoromatic_systems SET EnginePolicy=NULL WHERE SystemID='%s'" % sid), 'ok')
            self.assertEqual(start(msi).get('task'), 'benchmark', 'switching back gives the PHP client its work again')
        finally:
            drop_schedule(sched)


class TestSummaryFromNativeSystemInfo(unittest.TestCase):
    def summary(self, pp, hw=''):
        out = srv_sh("php /bench/tests/private-repo/native_convert.php --summary '%s' '%s'" % (json.dumps(pp), hw))
        return json.loads(out[out.index('{'):])

    def test_the_four_keys_are_read_as_they_are(self):
        info = {'summary': {'hardware': 'x', 'software': 'y'}, 'cpu': {'model': 'Example CPU', 'cores': 4, 'threads': 8},
                'gpu': [{'model': 'GPU A', 'device_id': '10de:2684'}, {'model': 'GPU B', 'device_id': None}],
                'motherboard': {'model': 'Example Board'}, 'memory': {'total_mb': 16000}, 'storage': [], 'os': {'name': 'x', 'version': 'y', 'kernel': 'z'}}
        s = self.summary({'benchy_system_info': info})
        self.assertEqual(s['cpu']['model'], 'Example CPU')
        self.assertEqual(s['gpu'], [{'model': 'GPU A', 'device_id': '10de:2684'}, {'model': 'GPU B', 'device_id': None}])
        self.assertEqual((s['motherboard']['model'], s['memory']['total_mb']), ('Example Board', 16000))

    def test_what_the_engine_could_not_detect_falls_back_to_the_hardware_string(self):
        info = {'cpu': {'model': None}, 'gpu': [], 'motherboard': {'model': None}, 'memory': {'total_mb': None}}
        s = self.summary({'benchy_system_info': info}, 'Processor: Fallback CPU, Memory: 8GB')
        self.assertEqual(s['cpu']['model'], 'Fallback CPU')
        self.assertEqual(s['memory']['total_mb'], 8192)

    def test_phodevi_properties_are_read_as_before(self):
        s = self.summary({'cpu': {'model': 'PTS CPU'}, 'gpu': {'model': 'PTS GPU', 'device-id': '1'}, 'motherboard': {'identifier': 'PTS Board'}, 'memory': {'capacity': 4096}})
        self.assertEqual((s['cpu']['model'], s['gpu'][0]['model'], s['motherboard']['model'], s['memory']['total_mb']), ('PTS CPU', 'PTS GPU', 'PTS Board', 4096))


if __name__ == '__main__':
    unittest.main()
