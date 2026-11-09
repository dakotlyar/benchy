"""N1, N2: the canary crawl. Two accounts, the second full of CANARY_T2_* values in every column of every tenant table and in every file. Every page in
routes.json is requested as each tenant level of the first account with the second account's ids filled in, and nothing of the second account
may come back; afterwards the second account's rows and files must be byte-identical to what they were before."""
import json
import os
import unittest

import tn

ROUTES = json.load(open(os.path.join(tn.HERE, 'routes.json')))
NAMES = {'pprid': 'PPRID', 'upload': 'UPLOADID', 'system': 'SYSTEMID', 'sched': 'SCHEDID', 'ticket': 'TICKETID', 'bench': 'BENCHID', 'session': 'SESSIONID',
         'component': 'COMPONENTID', 'suite': 'SUITE', 'aid': 'AID', 'repo_item': 'REPO_ITEM', 'trigger_b64': 'TRIGGER_B64', 'trigger': 'TRIGGER'}


def filled(path, st):
    for who in ('T1', 'T2'):
        for key, name in NAMES.items():
            if key in st[who]:
                path = path.replace('{%s_%s}' % (who, name), str(st[who][key]))
    assert '{' not in path.replace('{id}', ''), 'unfilled placeholder in ' + path
    return path


def crawl_paths(st):
    out = []
    for page, e in sorted(ROUTES['pages'].items()):
        for p in e.get('crawl', []):
            out.append((page, filled(p, st)))
    return out


class CanaryCrawl(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.st = tn.state()
        cls.paths = crawl_paths(cls.st)
        cls.b = tn.logins('T1')

    def test_the_crawl_is_not_empty_and_the_fixture_is_visible_to_its_own_account(self):
        # the control: T2's own administrator sees the canaries on the pages the crawl asks T1 for, so a clean crawl means something
        self.assertGreater(len(self.paths), 60)
        own = tn.Browser().login('tn2admin')
        t2 = self.st['T2']
        seen = [p for p in ('/?systems/%s' % t2['system'], '/?schedules/%d' % t2['sched'], '/?benchmark/%d' % t2['ticket'], '/?benchmark/%d/&view_log=%s' % (t2['ticket'], t2['system']),
                            '/?results', '/?systems', '/?schedules') if 'CANARY_T2' in own.get(p)[2]]
        self.assertGreaterEqual(len(seen), 4, seen)

    def test_no_page_returns_the_other_accounts_data_and_none_of_it_changes(self):
        t2 = self.st['T2']['aid']
        before = tn.snapshot(t2)
        leaks = []
        for lvl in tn.LEVELS:
            for page, path in self.paths:
                status, _, body = self.b[lvl].get(path)
                if 'CANARY_T2' in body:
                    leaks.append('level %d %s' % (lvl, path))
        self.assertEqual(leaks, [])
        after = tn.snapshot(t2)
        self.assertEqual(after['rows'], before['rows'])
        self.assertEqual(after['files'], before['files'])

    def test_a_visitor_who_is_not_logged_in_gets_nothing_either(self):
        anon = tn.Browser()
        for page, path in self.paths:
            self.assertNotIn('CANARY_T2', anon.get(path)[2], path)


if __name__ == '__main__':
    unittest.main()
