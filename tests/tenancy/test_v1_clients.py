"""N13: the account id a v1 client sends names a folder. Only an account of the server is accepted; '.', '..', paths, a purged account's folder, other
spellings and unknown ids are 'Invalid User' and leave no row and no folder behind."""
import unittest

import tn


class AccountIdChecks(unittest.TestCase):
    def snapshot(self):
        rows = tn.sql("SELECT COUNT(*) AS n FROM phoromatic_systems")[0]['n']
        stray = tn.sql("SELECT COUNT(*) AS n FROM phoromatic_systems WHERE AccountID NOT IN ('%s', '%s')" % (tn.state()['T1']['aid'], tn.state()['T2']['aid']))[0]['n']
        dirs = tn.srv_sh('ls -a %s/accounts | sort' % tn.STORAGE)
        return rows, stray, dirs

    def test_malformed_and_foreign_account_ids_are_refused_and_leave_nothing(self):
        st = tn.state()
        t1, t2 = st['T1']['aid'], st['T2']['aid']
        before = self.snapshot()
        for aid in ('.', '..', '../' + t2, '../accounts/' + t2, t1 + '/../' + t2, t1 + '/.', '.purged-%s-20260101' % t2, t1.lower(), 'NOSUCHACCOUNT', t1 + '/', t1 + '%2f..%2f' + t2, 'X/../' + t1):
            with self.subTest(aid=aid):
                msi = 'tn-v1-' + tn.uuid.uuid4().hex
                body = tn.raw_post('/phoromatic.php', {'r': 'ping', 'aid': aid, 'msi': msi, 'pts_core': tn.CORE})
                self.assertIn('Invalid User', body)
        self.assertEqual(self.snapshot(), before)

    def test_a_valid_account_id_is_still_taken(self):
        # the control: a normal client gets in (a pending system row is the answer for an unknown machine)
        msi = 'tn-v1-' + tn.uuid.uuid4().hex
        body = tn.raw_post('/phoromatic.php', {'r': 'ping', 'aid': tn.state()['T1']['aid'], 'msi': msi, 'pts_core': tn.CORE})
        self.assertNotIn('Invalid User', body)
        self.assertEqual(len(tn.sql("SELECT 1 FROM phoromatic_systems WHERE MachineSelfID='%s' AND AccountID='%s'" % (msi, tn.state()['T1']['aid']))), 1)

    def test_a_client_of_one_account_naming_the_other_gets_at_most_a_pending_row(self):
        st = tn.state()
        msi = 'tn-v1-' + tn.uuid.uuid4().hex
        r = tn.v1('list_results', st['T2']['aid'], msi)
        self.assertNotIn('CANARY_T2', str(r))
        row = tn.sql("SELECT State FROM phoromatic_systems WHERE MachineSelfID='%s'" % msi)
        self.assertTrue(all(x['State'] == 0 for x in row))


if __name__ == '__main__':
    unittest.main()
