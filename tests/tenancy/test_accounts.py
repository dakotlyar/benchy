"""Account creation: the pre-seeded account id (a form field anybody can send) becomes a folder name and goes into SQL, so it is six letters and
digits or it is ignored. And corp and benchy stay reserved repository names."""
import re
import unittest

import tn


class SeedAccountId(unittest.TestCase):
    GOOD = r'[A-Z0-9]{6}'

    def test_a_seed_that_is_not_six_letters_and_digits_is_refused_or_ignored(self):
        for n, seed in enumerate(('A/../B', "'OR'1'", '..%2f..', '../..', 'AB CDE')):
            with self.subTest(seed=seed):
                # the command refuses it outright ...
                self.assertFalse(tn.register('tnseed%d' % n, seed, check=False), seed)
                self.assertEqual(tn.sql("SELECT AccountID AS a FROM phoromatic_users WHERE UserName='tnseed%d'" % n), [])
                # ... and the function underneath, which a request could reach in 0.9.x as the form field seed_accountid, ignores it
                out = tn.php_boot("phoromatic_server::prepare_database(); echo phoromatic_accounts::create('tnseedf%d', 'tnpass12345', 'tnpass12345', 'f@example.com', '%s') === null ? 'created' : 'refused';" % (n, seed.replace("'", "\\'")))
                self.assertEqual(out.strip(), 'created', seed)
                aid = tn.sql("SELECT AccountID AS a FROM phoromatic_users WHERE UserName='tnseedf%d'" % n)[0]['a']
                self.assertRegex(aid, '^' + self.GOOD + '$')
                self.assertNotEqual(aid, seed.upper()[:6])
        # nothing was left behind under a name that is not an account id: no row, no folder
        bad = tn.sql("SELECT AccountID AS a FROM phoromatic_accounts WHERE AccountID NOT GLOB '[A-Z0-9][A-Z0-9][A-Z0-9][A-Z0-9][A-Z0-9][A-Z0-9]'")
        self.assertEqual(bad, [])
        folders = tn.srv_sh('ls -a %s/accounts' % tn.STORAGE).split()
        self.assertTrue(all(re.match('^' + self.GOOD + '$', d) or d.startswith('.') for d in folders), folders)
        self.assertNotIn('A', folders)
        self.assertNotIn('B', folders)

    def test_a_good_seed_is_used(self):
        tn.register('tnseedok', 'TNSEED')
        self.assertEqual(tn.sql("SELECT AccountID AS a FROM phoromatic_users WHERE UserName='tnseedok'")[0]['a'], 'TNSEED')


class ReservedRepositoryNames(unittest.TestCase):
    def test_corp_and_benchy_are_refused_as_the_private_repository_name(self):
        out = {}
        for n in ('private', 'corp', 'benchy', 'pts', 'off'):
            code = ("pts_config::user_config_generate(array('PhoronixTestSuite/Options/OpenBenchmarking/PrivateRepository' => '%s')); "
                    "echo 'RESULT=', var_export(phoromatic_private_repo::repository_name(), true);") % n
            out[n] = tn.php_boot(code).split('RESULT=')[-1].strip()
        self.assertEqual(out['private'], "'private'", out)
        for n in ('corp', 'benchy', 'pts', 'off'):
            self.assertEqual(out[n], 'false', out)
        tn.php_boot("pts_config::user_config_generate(array('PhoronixTestSuite/Options/OpenBenchmarking/PrivateRepository' => 'private'));")


if __name__ == '__main__':
    unittest.main()
