"""Reads that must not cross accounts, and the pages that are gone or escaped: the public results page (N8), counts that mix ids between accounts
(N6), and tenant text on pages the server administrator looks at."""
import unittest

import tn


class PublicPage(unittest.TestCase):
    def test_the_public_results_page_is_gone(self):
        # N8: it served any result by id without a login
        t2 = tn.state()['T2']
        for path in ('/public.php', '/public.php?ut=' + t2['pprid'], '/public.php?t=result&ut=' + t2['pprid']):
            with self.subTest(path):
                status, _, body = tn.Browser().get(path)
                self.assertEqual(status, 404)
                self.assertNotIn('CANARY_T2', body)

    def test_nothing_still_offers_the_public_page(self):
        anon = tn.Browser().get('/')[2]
        self.assertNotIn('public.php', anon)
        self.assertNotIn('View Public Results', anon)
        page = tn.logins('T1')[1].get('/?settings')[2]
        self.assertNotIn('LetPublicViewResults', page)
        self.assertNotIn('public viewer', page)


class CountsStayInTheAccount(unittest.TestCase):
    def test_tracker_does_not_count_the_other_accounts_results(self):
        # N6: T2 has 20 results with ScheduleID 5001, the id T1's schedule also has; T1 has one
        b = tn.logins('T1')[1]
        page = b.get('/?tracker')[2]
        self.assertNotIn('TN1 Same Id Schedule', page)
        self.assertEqual(tn.sql("SELECT COUNT(*) AS n FROM phoromatic_results WHERE ScheduleID=%d" % tn.SHARED_SCHEDULE)[0]['n'], 21)


class TenantTextIsEscaped(unittest.TestCase):
    def setUp(self):
        self.st = tn.state()

    def test_group_name_is_escaped_in_the_header_and_the_users_page(self):
        aid = self.st['T1']['aid']
        old = tn.sql("SELECT GroupName AS g FROM phoromatic_accounts WHERE AccountID='%s'" % aid)[0]['g']
        tn.sql_exec("UPDATE phoromatic_accounts SET GroupName='\"><i id=\"tnxss\">x</i>' WHERE AccountID='%s'" % aid)
        try:
            b = tn.logins('T1')[1]
            for page in ('/?main', '/?users', '/?settings', '/?systems', '/?results', '/?schedules', '/?dashboard', '/?benchmark', '/?maintenance_table', '/?result/TN1PPRA'):
                with self.subTest(page):
                    self.assertNotIn('<i id="tnxss">', b.get(page)[2])
            self.assertIn('&lt;i id=', b.get('/?main')[2])
        finally:
            tn.sql_exec("UPDATE phoromatic_accounts SET GroupName=%s WHERE AccountID='%s'" % ("NULL" if old is None else "'%s'" % old.replace("'", "''"), aid))

    def test_server_data_page_escapes_titles_and_group_names_of_every_account(self):
        t2 = self.st['T2']
        sets = ["UPDATE phoromatic_accounts SET GroupName='<u id=\"tnrootgn\">g</u>' WHERE AccountID='%s'" % t2['aid'],
                "UPDATE phoromatic_schedules SET Title='<b id=\"tnroot1\">t</b>' WHERE AccountID='%s' AND ScheduleID=%d" % (t2['aid'], t2['sched']),
                "UPDATE phoromatic_schedules_triggers SET Trigger='<s id=\"tnroot2\">x</s>' WHERE AccountID='%s'" % t2['aid']]
        undo = ["UPDATE phoromatic_accounts SET GroupName=NULL WHERE AccountID='%s'" % t2['aid'],
                "UPDATE phoromatic_schedules SET Title='CANARY_T2_phoromatic_schedules_Title' WHERE AccountID='%s' AND ScheduleID=%d" % (t2['aid'], t2['sched']),
                "UPDATE phoromatic_schedules_triggers SET Trigger='%s' WHERE AccountID='%s'" % (t2['trigger'], t2['aid'])]
        for q in sets:
            tn.sql_exec(q)
        try:
            page = tn.Browser().login_root().get('/?admin_data')[2]
            for raw in ('<u id="tnrootgn">', '<b id="tnroot1">', '<s id="tnroot2">'):
                self.assertNotIn(raw, page)
            self.assertIn('&lt;b id=', page)
        finally:
            for q in undo:
                tn.sql_exec(q)

    def test_the_cache_listing_escapes_file_names(self):
        name = "tn-<b id=tnfile>.txt"
        tn.srv_sh("touch '/var/cache/benchy/download-cache/%s'" % name)
        try:
            page = tn.logins('T1')[1].get('/?caches')[2]
            self.assertNotIn('<b id=tnfile>', page)
        finally:
            tn.srv_sh("rm -f '/var/cache/benchy/download-cache/%s'" % name)


if __name__ == '__main__':
    unittest.main()
