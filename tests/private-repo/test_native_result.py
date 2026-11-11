"""The native result converter (Benchy 0.9.4, E3 step S2): phoromatic_native_result turns the engine's result-v1 document into a
composite.xml that passes validate() and files like any upload, without ever asking OpenBenchmarking or a repository index about a
benchy/ test.

Fixtures (copied from the engine repository, tests/private-repo/fixtures/native/) and the checked-in expected XML next to them.
Run through tests/private-repo/run.sh (part of http/all).
"""
import base64
import hashlib
import json
import os
import re
import unittest
import uuid

from bench_helpers import new_system, req, result_row
from test_private_repo_http import AID, sql, srv_sh
from test_private_repo_tokens import sql_exec
from test_private_repo_web import WebBase

FX = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'fixtures', 'native')
SRV_FX = '/bench/tests/private-repo/fixtures/native/'
CATALOGUE = json.dumps({'catalogue': {'benchy/stream': 'STREAM', 'synth/quick': 'Quick synthetic'}})


def convert(name, options=None):
    out = srv_sh("php /bench/tests/private-repo/native_convert.php %s%s '%s'" % (SRV_FX, name + '.json', json.dumps(options) if options is not None else CATALOGUE))
    return json.loads(out[out.index('{'):])


def normalised(xml):
    """what is not a function of the fixture: the write time and the server's version comment"""
    xml = re.sub(r'<LastModified>[^<]*</LastModified>', '<LastModified>-</LastModified>', xml)
    return re.sub(r'<!--Benchy [^>]*-->\n', '', xml)


class TestConverter(unittest.TestCase):
    def test_each_fixture_matches_its_golden_and_validates(self):
        for name in ('quick', 'stream-ok', 'mixed'):
            with self.subTest(fixture=name):
                r = convert(name)
                self.assertTrue(r['ok'], r['error'])
                self.assertTrue(r['valid'], 'passes pts_result_file::validate()')
                golden = open(os.path.join(FX, name + '.golden.xml')).read()
                self.assertEqual(normalised(r['xml']), golden)

    def test_the_four_stream_metrics_get_four_distinct_stable_hashes(self):
        a = convert('stream-ok')
        hashes = [t[1] for t in a['tests']]
        self.assertEqual(len(set(hashes)), 4)
        self.assertEqual([t[0] for t in a['tests']], ['benchy/stream-1.0.0'] * 4, 'the identifier is kept as written, version included')
        self.assertEqual([t[2] for t in a['tests']], ['threads=8 metric=%s' % m for m in ('Copy', 'Scale', 'Add', 'Triad')])
        # another run of the same test (other values, other run id) hashes the same
        again = convert('stream-ok')
        self.assertEqual([t[1] for t in again['tests']], hashes)

    def test_a_metric_that_failed_does_not_change_its_siblings_hashes(self):
        # the metric count is every entry of the test, not just the ok ones: with Scale failed the other three still say metric=<name>
        full = {t[2]: t[1] for t in convert('stream-ok')['tests']}
        part = convert('stream-ok', {'catalogue': {'benchy/stream': 'STREAM'}, '_fail_entry': 1})
        self.assertTrue(part['ok'])
        got = {t[2]: t[1] for t in part['tests']}
        self.assertEqual(sorted(got), ['threads=8 metric=Add', 'threads=8 metric=Copy', 'threads=8 metric=Triad'])
        for args, h in got.items():
            self.assertEqual(h, full[args])
        self.assertIn('benchy/stream: failed: qc made it fail', part['xml'], 'the failure is visible in the notes')

    def test_a_single_metric_test_has_no_metric_suffix(self):
        t = convert('quick')['tests'][0]
        self.assertEqual((t[0], t[2], t[3], t[4]), ('synth/quick-1.0.0', '', '', 'Quick synthetic'))

    def test_the_title_comes_from_the_catalogue_else_the_ref(self):
        self.assertEqual({t[4] for t in convert('stream-ok')['tests']}, {'STREAM'})
        self.assertEqual({t[4] for t in convert('stream-ok', {})['tests']}, {'benchy/stream'})

    def test_a_failed_test_is_left_out_and_noted(self):
        r = convert('mixed')
        self.assertEqual([t[0] for t in r['tests']], ['synth/quick-1.0.0'])
        self.assertIn('synth/fail: failed: The run script exited 1: this test fails', r['xml'])

    def test_a_run_with_nothing_ok_makes_no_composite(self):
        r = convert('stream-failed')
        self.assertFalse(r['ok'])
        self.assertIn('No test in the run produced a result', r['error'])

    def test_the_job_title_trigger_and_description_win_over_the_runs_own(self):
        r = convert('quick', {'title': 'Nightly quick', 'identifier': '2026-11-03', 'description': 'scheduled', 'catalogue': {}})
        self.assertIn('<Title>Nightly quick</Title>', r['xml'])
        self.assertIn('<Identifier>2026-11-03</Identifier>', r['xml'])
        self.assertIn('<Description>scheduled</Description>', r['xml'])
        self.assertIn('<TestClient>benchy-native 0.2.0</TestClient>', r['xml'])

    def test_only_a_plain_repo_slash_name_is_an_engine_ref(self):
        ids = ['benchy/stream', 'benchy/stream-1.0.0', 'synth/quick-1.0.0', 'benchy/<script>alert(1)</script>', 'benchy/../../x', 'benchy/a/b', 'benchy/', 'benchy/' + 'a' * 101, 'pts/stream', 'benchy', '', 'benchy/x y']
        want = [True, True, True, False, False, False, False, False, False, False, False, False]
        out = srv_sh('php /bench/tests/private-repo/native_convert.php --isref --account %s ' % AID + ' '.join("'%s'" % i for i in ids))
        self.assertEqual(json.loads(out[out.index('['):]), want)

    def test_a_ref_the_catalogue_knows_is_an_engine_ref_whatever_its_repo(self):
        # the protocol lets an engine have any repo; a result naming one of its tests must not send the server to OpenBenchmarking.org
        sql_exec("INSERT OR REPLACE INTO phoromatic_system_catalogue (AccountID, SystemID, Ref, Version, TestJSON, ReportedOn) VALUES ('%s', 'QCREF', 'acme/widget', '1.0.0', '{}', '2026-01-01 00:00:00')" % AID)
        self.addCleanup(sql_exec, "DELETE FROM phoromatic_system_catalogue WHERE SystemID='QCREF'")
        ids = ['acme/widget', 'acme/widget-2.1.0', 'acme/other', 'pts/stream', 'acme/widget/x', 'acme/<b>']
        out = srv_sh('php /bench/tests/private-repo/native_convert.php --isref --account %s ' % AID + ' '.join("'%s'" % i for i in ids))
        self.assertEqual(json.loads(out[out.index('['):]), [True, True, False, False, False, False])
        # ... in the account whose agents reported it, and in no other (T2): a web request of another account, or of none, does not know it
        for who in ('--account QCOTHR', ''):
            out = srv_sh('php /bench/tests/private-repo/native_convert.php --isref %s ' % who + ' '.join("'%s'" % i for i in ids))
            self.assertEqual(json.loads(out[out.index('['):]), [False] * 6, who)

    def test_openbenchmarking_repositories_are_never_engine_refs_whatever_a_catalogue_says(self):
        # one account's agent reporting pts/x264 must not make the parser skip qualification for pts/x264 in every account
        for ref in ('pts/x264', 'system/cpu', 'local/mine', 'git/tool', 'PTS/x264'):
            sql_exec("INSERT OR REPLACE INTO phoromatic_system_catalogue (AccountID, SystemID, Ref, Version, TestJSON, ReportedOn) VALUES ('%s', 'QCOB', '%s', '1.0.0', '{}', '2026-01-01 00:00:00')" % (AID, ref))
        self.addCleanup(sql_exec, "DELETE FROM phoromatic_system_catalogue WHERE SystemID='QCOB'")
        ids = ['pts/x264', 'pts/x264-2.0.0', 'system/cpu', 'local/mine', 'git/tool', 'PTS/x264']
        out = srv_sh('php /bench/tests/private-repo/native_convert.php --isref --account %s ' % AID + ' '.join("'%s'" % i for i in ids))
        self.assertEqual(json.loads(out[out.index('['):]), [False] * 6)

    def test_a_result_with_no_title_is_named_after_its_first_test(self):
        r = convert('quick', {'_no_title': 1, 'catalogue': {'synth/quick': 'Quick synthetic'}})
        self.assertTrue(r['ok'], r['error'])
        self.assertTrue(r['valid'], 'an empty title would fail the schema; it is named instead')
        self.assertIn('<Title>Quick synthetic</Title>', r['xml'])
        self.assertIn('<Title>synth/quick</Title>', convert('quick', {'_no_title': 1})['xml'])

    def test_a_dollar_or_backslash_in_the_engine_version_survives(self):
        r = convert('quick', {'_engine_version': '0.3.0-$1\\2$0', 'catalogue': {}})
        self.assertTrue(r['ok'])
        self.assertIn('<TestClient>benchy-native 0.3.0-$1\\2$0</TestClient>', r['xml'])
        self.assertTrue(r['valid'])

    def test_numbers_that_are_not_finite_are_refused(self):
        for opt in ('_inf_value', '_nan_trial'):
            with self.subTest(opt=opt):
                r = convert('quick', {opt: 1, 'catalogue': {}})
                self.assertFalse(r['ok'])
                self.assertIn('finite', r['error'])


class TestNativeResultsAreFiledAndViewed(WebBase):
    def scratch_entries(self):
        path = srv_sh('php /bench/tests/private-repo/native_convert.php --scratch').strip()
        return path, sorted(srv_sh('ls -1 %s 2>/dev/null' % path).split())

    def test_a_native_upload_is_stored_and_viewed_without_any_repository_lookup(self):
        # Asking the index of a repository starts with making that repository's directory in the OpenBenchmarking scratch path
        # (pts_openbenchmarking::refresh_repository_lists). Parsing a benchy/ result at upload, on the result page, in compare and in
        # the results list must leave that path as it found it, and no benchy.index or synth.index may appear.
        msi, sid = new_system()
        path, before = self.scratch_entries()
        r = convert('stream-ok')
        xml = r['xml']
        reply = req(msi, 'result_upload', sched='7201', ts='native-a', composite_xml=base64.b64encode(xml.encode()).decode(), composite_xml_hash=hashlib.sha1(xml.encode()).hexdigest())
        uid = int(reply['upload_id'])
        parts = sql("SELECT AbstractID, TestProfile FROM phoromatic_results_results WHERE AccountID='%s' AND UploadID=%d ORDER BY AbstractID" % (AID, uid))
        self.assertEqual([p['TestProfile'] for p in parts], ['benchy/stream-1.0.0'] * 4, 'the stored test profile is the identifier as written')
        pprid = result_row(uid)['PPRID']
        page = self.admin.get('/?result/%s' % pprid)[2]
        self.assertIn('STREAM', page)
        self.assertNotIn('Array to string', page, 'the system table prints System/JSON as text, so it must hold nothing nested')
        xml2 = convert('stream-ok', {'identifier': 'native-b', 'catalogue': {}})['xml']
        uid2 = int(req(msi, 'result_upload', sched='7201', ts='native-b', composite_xml=base64.b64encode(xml2.encode()).decode(), composite_xml_hash=hashlib.sha1(xml2.encode()).hexdigest())['upload_id'])
        both = self.admin.get('/?result/%s,%s' % (pprid, result_row(uid2)['PPRID']))[2]
        self.assertNotIn('Array to string', both)
        self.assertIn('native-b', both)
        self.admin.get('/?results')
        self.admin.get('/?results/system/%s' % sid)
        self.assertEqual(self.scratch_entries(), (path, before), 'no repository directory or index was created by a native result')
