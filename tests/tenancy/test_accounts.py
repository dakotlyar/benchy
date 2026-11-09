"""Account creation: the pre-seeded account id (a form field anybody can send) becomes a folder name and goes into SQL, so it is six letters and
digits or it is ignored. And corp and benchy stay reserved repository names."""
import re
import unittest

import tn


class SeedAccountId(unittest.TestCase):
    def test_a_seed_that_is_not_six_letters_and_digits_is_ignored(self):
        before = tn.srv_sh('ls -a %s/accounts | sort' % tn.STORAGE)
        for n, seed in enumerate(('A/../B', "'OR'1'", '..%2f..', '../..', 'AB CDE')):
            user = 'tnseed%d' % n
            tn.register(user, seed)
            row = tn.sql("SELECT AccountID AS a FROM phoromatic_users WHERE UserName='%s'" % user)[0]['a']
            with self.subTest(seed=seed):
                self.assertRegex(row, r'^[A-Z0-9]{6}$')
                self.assertNotEqual(row, seed.upper()[:6])
        after = tn.srv_sh('ls -a %s/accounts | sort' % tn.STORAGE).split()
        self.assertTrue(all(re.match(r'^[A-Z0-9]{6}$', d) or d.startswith('.') for d in after), after)
        self.assertNotIn('A', after)
        self.assertNotIn('B', after)

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
