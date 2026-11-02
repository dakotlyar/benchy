"""The v1 result upload (result_upload.php) after its body moved into phoromatic_result_ingest::store() (Benchy 0.9.4, E3 step S1):
what a client's upload leaves behind is what it always did. The rows, the stored composite.xml, the progressive continuation, the logs
zip and the three error replies.

Run through tests/private-repo/run.sh (part of http/all).
"""
import base64
import hashlib
import unittest
import uuid

from bench_helpers import composite, new_system, req, result_row
from test_private_repo_http import AID, sql, srv_sh


def post_upload(msi, xml, **extra):
    fields = {'composite_xml': base64.b64encode(xml.encode()).decode(), 'composite_xml_hash': hashlib.sha1(xml.encode()).hexdigest()}
    fields.update(extra)
    return req(msi, 'result_upload', **fields)


def composite_on_disk(upload_id):
    return srv_sh('cat /var/lib/benchy/phoromatic/accounts/%s/results/%d/composite.xml' % (AID, upload_id))


class TestIngestKeepsV1Behaviour(unittest.TestCase):
    def setUp(self):
        self.msi, self.sid = new_system()

    def unique(self, **kw):
        return composite(identifier=kw.pop('identifier', 'qc-ingest'), title=kw.pop('title', 'qc ingest ' + uuid.uuid4().hex[:6]), value=kw.pop('value', '%d.5' % (uuid.uuid4().int % 900)))

    def test_a_final_upload_stores_the_row_the_files_and_the_detail_rows(self):
        xml = self.unique(identifier='qc-trigger-1')
        r = post_upload(self.msi, xml, sched='7101', ts='qc-trigger-1', et='93')
        uid = int(r['upload_id'])
        self.assertEqual(r['response'], 'Result Upload: %d' % uid)
        row = result_row(uid)
        self.assertEqual((row['SystemID'], row['ScheduleID'], row['Trigger'], row['InProgress'], row['DisplayStatus']), (self.sid, 7101, 'qc-trigger-1', 0, 1))
        self.assertEqual((row['SystemCount'], row['ResultCount'], row['ElapsedTime']), (1, 1, 93))
        self.assertTrue(row['Title'].startswith('qc ingest '))
        self.assertEqual(row['XmlUploadHash'], hashlib.sha1(xml.encode()).hexdigest())
        self.assertTrue(row['ComparisonHash'])
        self.assertTrue(row['PPRID'])
        self.assertIsNone(row['BenchSessionID'], 'an ordinary system has no bench attribution')
        self.assertEqual(composite_on_disk(uid), xml, 'the file on disk is the uploaded composite, byte for byte')
        parts = sql("SELECT * FROM phoromatic_results_results WHERE AccountID='%s' AND UploadID=%d" % (AID, uid))
        self.assertEqual([(p['AbstractID'], p['TestProfile']) for p in parts], [(1, 'pts/qc-1.0.0')])
        self.assertTrue(parts[0]['ComparisonHash'])
        systems = sql("SELECT * FROM phoromatic_results_systems WHERE AccountID='%s' AND UploadID=%d" % (AID, uid))
        self.assertEqual([(s['SystemIdentifier'], s['Hardware'], s['Software']) for s in systems], [('qc-trigger-1', 'Processor: QC', 'OS: QC')])

    def test_upload_ids_count_up_within_the_account(self):
        a = int(post_upload(self.msi, self.unique(), sched='7102', ts='a')['upload_id'])
        b = int(post_upload(self.msi, self.unique(), sched='7102', ts='b')['upload_id'])
        self.assertEqual(b, a + 1)

    def test_progressive_uploads_continue_one_row_until_the_last(self):
        first = int(post_upload(self.msi, self.unique(identifier='qc-prog'), sched='7103', ts='qc-prog', progressive_upload='1')['upload_id'])
        self.assertEqual(result_row(first)['InProgress'], 1)
        xml2 = self.unique(identifier='qc-prog')
        again = int(post_upload(self.msi, xml2, sched='7103', ts='qc-prog', progressive_upload='1')['upload_id'])
        self.assertEqual(again, first, 'a second partial upload of the same trigger updates the same row')
        xml3 = self.unique(identifier='qc-prog')
        last = int(post_upload(self.msi, xml3, sched='7103', ts='qc-prog', progressive_upload='2')['upload_id'])
        self.assertEqual(last, first)
        row = result_row(first)
        self.assertEqual(row['InProgress'], 0, 'progressive_upload=2 is the final one')
        self.assertEqual(row['XmlUploadHash'], hashlib.sha1(xml3.encode()).hexdigest())
        self.assertEqual(composite_on_disk(first), xml3)
        n = sql("SELECT COUNT(*) AS n FROM phoromatic_results WHERE AccountID='%s' AND SystemID='%s' AND ScheduleID=7103" % (AID, self.sid))[0]['n']
        self.assertEqual(n, 1)

    def test_a_logs_zip_with_a_matching_hash_is_kept_and_a_wrong_hash_is_not(self):
        b64 = base64.b64encode(b'PK-qc-log-bytes').decode()
        uid = int(post_upload(self.msi, self.unique(), sched='7104', ts='logs', system_logs_zip=b64, system_logs_hash=hashlib.sha1(b64.encode()).hexdigest())['upload_id'])
        self.assertEqual(srv_sh('cat /var/lib/benchy/phoromatic/accounts/%s/results/%d/system-logs.zip' % (AID, uid)), 'PK-qc-log-bytes')
        uid2 = int(post_upload(self.msi, self.unique(), sched='7104', ts='logs2', system_logs_zip=b64, system_logs_hash='0' * 40)['upload_id'])
        self.assertEqual(srv_sh('ls /var/lib/benchy/phoromatic/accounts/%s/results/%d/' % (AID, uid2)).split(), ['composite.xml'])

    def test_the_three_refusals_keep_their_messages_and_store_nothing(self):
        before = sql("SELECT COUNT(*) AS n FROM phoromatic_results WHERE AccountID='%s'" % AID)[0]['n']
        xml = self.unique()
        r = req(self.msi, 'result_upload', composite_xml=base64.b64encode(xml.encode()).decode(), composite_xml_hash='1' * 40)
        self.assertEqual(r['error'], 'XML Hash Mismatch')
        bad = '<?xml version="1.0"?><NotAResult/>'
        self.assertEqual(post_upload(self.msi, bad)['error'], 'XML Did Not Match Schema Definition')
        no_title = xml.replace(xml[xml.index('<Title>') + 7:xml.index('</Title>')], '', 1)
        self.assertEqual(post_upload(self.msi, no_title)['error'], 'Invalid Result File')
        self.assertEqual(sql("SELECT COUNT(*) AS n FROM phoromatic_results WHERE AccountID='%s'" % AID)[0]['n'], before)


if __name__ == '__main__':
    unittest.main()
