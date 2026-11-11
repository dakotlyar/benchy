"""T2: the places where one account could reach another on purpose are closed, and each one has its negative test (plan B8): result sharing in the five
queries (N2, N7, N16), the results feed key (N7), registration (N-registration), IP/MAC claims (N17), event.php, adoption and native-ref scoping (N25), the
auto-approve policy, the pending-system cap and the unknown-account limit for v1 clients (N13, N14), and --tenant on the server commands (N26).
Every test puts back what it changed; the other account's rows and files are compared before and after (the fixture's snapshot)."""
import base64
import json
import re
import subprocess
import unittest
import xml.etree.ElementTree as ET

import tn

CLOSED = 'Account creation is closed on this server'


class Two(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.st = tn.state()
        cls.t1, cls.t2 = cls.st['T1'], cls.st['T2']
        cls.a1, cls.a2 = cls.t1['aid'], cls.t2['aid']

    def setUp(self):
        self.before_t2 = tn.snapshot(self.a2)
        self.addCleanup(self.assert_t2_untouched)

    def assert_t2_untouched(self):
        after = tn.snapshot(self.a2)
        changed = sorted(t for t in set(after['rows']) | set(self.before_t2['rows']) if after['rows'].get(t) != self.before_t2['rows'].get(t))
        self.assertEqual(changed, [], 'tables of the other account that changed')
        self.assertEqual(after['rows'], self.before_t2['rows'])
        self.assertEqual(after['files'], self.before_t2['files'])

    def sql_cleanup(self, undo):
        self.addCleanup(tn.sql_exec, undo)

    def settings(self):
        return tn.Browser().login(self.st['users']['T1']['1'])


def read_setting(key):
    return json.loads(tn.php_boot("phoromatic_server::init_settings_store(); echo json_encode(phoromatic_server::read_setting('%s'));" % key) or 'null')


def set_setting(key, value):
    tn.php_boot("phoromatic_server::init_settings_store(); phoromatic_server::save_setting('%s', %s);" % (key, json.dumps(value)))


def remove_setting(key):
    tn.php_boot("phoromatic_server::init_settings_store(); phoromatic_server::remove_setting('%s');" % key)


class SharingIsGone(Two):
    """The five queries (and the pages around them) take no share clause: the flags and the server setting that used to open them change nothing, and a
    suspended account's results are in nobody's view."""

    def flags(self, on):
        v = 1 if on else 0
        tn.sql_exec("UPDATE phoromatic_account_settings SET LetOtherGroupsViewResults=%d, LetPublicViewResults=%d WHERE AccountID='%s'" % (v, v, self.a2))
        if on:
            set_setting('force_result_sharing', 1)
        else:
            remove_setting('force_result_sharing')

    def setUp(self):
        super().setUp()
        self.flags(True)
        self.addCleanup(self.flags, False)
        self.b = tn.logins('T1')

    def test_the_result_page_shows_nothing_of_the_other_account_alone_or_mixed(self):
        for path in ('/?result/%s' % self.t2['pprid'], '/?result/%s,%s' % (self.t1['pprid'], self.t2['pprid']), '/?result/%s,%s' % (self.t2['pprid'], self.t1['pprid'])):
            for lvl, b in self.b.items():
                page = b.get(path)[2]
                self.assertNotIn('CANARY_T2', page, (path, lvl))
        self.assertIn('TN1_FILE', self.b[1].get('/?result/%s,%s' % (self.t1['pprid'], self.t2['pprid']))[2])

    def test_the_results_list_has_no_shared_by_other_groups_part(self):
        for lvl, b in self.b.items():
            page = b.get('/?results')[2]
            self.assertNotIn('CANARY_T2', page, lvl)
            self.assertNotIn('Shared By Other Groups', page, lvl)
            self.assertNotIn(self.t2['pprid'], page, lvl)
        search = self.b[1].post('/?results', {'search': 'CANARY', 'token_submit': self.b[1].token('/?results')})[2]
        self.assertNotIn('CANARY_T2', search)

    def test_the_account_settings_page_has_no_sharing_box_and_a_post_cannot_turn_it_on(self):
        b = self.b[1]
        page = b.get('/?settings')[2]
        self.assertNotIn('LetOtherGroupsViewResults', page)
        self.assertNotIn('LetPublicViewResults', page)
        b.post('/?settings', {'account_settings_update': '1', 'LetOtherGroupsViewResults': 'yes', 'LetPublicViewResults': 'yes', 'token_submit': b.token('/?settings')})
        row = tn.sql("SELECT LetOtherGroupsViewResults AS a, LetPublicViewResults AS b FROM phoromatic_account_settings WHERE AccountID='%s'" % self.a1)[0]
        self.assertFalse(row['a'])
        self.assertFalse(row['b'])

    def test_a_v1_client_lists_and_clones_only_its_own_results(self):
        msi = tn.approved_machine(self.a1)
        listed = tn.v1('list_results', self.a1, msi)
        self.assertIn(self.t1['pprid'], listed['results'])
        self.assertNotIn(self.t2['pprid'], listed['results'])
        self.assertNotIn('CANARY_T2', json.dumps(listed))
        for pprid in listed['results']:
            self.assertEqual(tn.sql("SELECT AccountID AS a FROM phoromatic_results WHERE PPRID='%s'" % pprid)[0]['a'], self.a1)
        other = tn.v1('clone_result', self.a1, msi, i=self.t2['pprid'])
        self.assertNotIn('result', other)
        self.assertNotIn('CANARY_T2', json.dumps(other))
        for bad in ('../' + self.t2['pprid'], '%', "x' OR '1'='1", ''):
            self.assertNotIn('result', tn.v1('clone_result', self.a1, msi, i=bad))

    def test_clone_returns_the_result_itself_not_a_path(self):
        msi = tn.approved_machine(self.a1)
        mine = tn.v1('clone_result', self.a1, msi, i=self.t1['pprid'])
        content = base64.b64decode(mine['result']['composite_xml']).decode()
        self.assertTrue(content.startswith('<?xml'), content[:40])
        self.assertIn('TN1_FILE', content)
        self.assertNotIn('/var/lib', json.dumps(mine))
        self.assertNotIn('accounts/', content[:200] if False else json.dumps(mine))
        ET.fromstring(content)

    def test_a_suspended_accounts_results_are_in_no_ones_view(self):
        tn.set_state(self.a2, 'suspended')
        self.addCleanup(tn.set_state, self.a2, 'active')
        for path in ('/?result/%s' % self.t2['pprid'], '/?results'):
            self.assertNotIn('CANARY_T2', self.b[1].get(path)[2])
        self.assertNotIn(self.t2['pprid'], self.b[1].get(tn.feed_path('tn1admin'))[2])
        self.assertNotIn(self.t2['pprid'], json.dumps(tn.v1('list_results', self.a1, tn.approved_machine(self.a1))))

    def test_there_is_no_public_page_for_results(self):
        status, _, _ = tn.Browser().get('/public.php?ut=%s' % self.t2['pprid'])
        self.assertEqual(status, 404)


class ResultsFeed(Two):
    def test_the_key_is_an_hmac_and_the_link_carries_the_account(self):
        main = self.settings().get('/?main')[2]
        m = re.search(r'/rss\.php\?user=([A-Za-z0-9]+)&amp;t=([A-Z0-9]{6})&amp;v=([0-9a-f]+)', main)
        self.assertTrue(m, 'the page links the feed')
        self.assertEqual(m.group(2), self.a1)
        self.assertEqual(len(m.group(3)), 64)
        row = tn.sql("SELECT UserID, CreatedOn FROM phoromatic_users WHERE UserName='tn1admin'")[0]
        import hashlib
        self.assertNotEqual(m.group(3), hashlib.sha1(row['CreatedOn'].encode()).hexdigest())

    def test_the_own_feed_works_and_holds_only_own_results(self):
        body = tn.Browser().get(tn.feed_path('tn1admin'))[2]
        ET.fromstring(body)
        self.assertIn(self.t1['pprid'], body)
        self.assertNotIn('CANARY_T2', body)
        self.assertNotIn(self.t2['pprid'], body)

    def test_a_key_for_another_account_and_the_old_sha1_form_give_nothing(self):
        row = tn.sql("SELECT UserID, CreatedOn FROM phoromatic_users WHERE UserName='tn1admin'")[0]
        import hashlib
        old = hashlib.sha1(row['CreatedOn'].encode()).hexdigest()
        for path in (tn.feed_path('tn1admin', aid=self.a2),
                     '/rss.php?user=%s&v=%s' % (row['UserID'], old),
                     '/rss.php?user=%s&t=%s&v=%s' % (row['UserID'], self.a1, old),
                     '/rss.php?user=%s&t=%s&v=%s' % (row['UserID'], self.a2, old),
                     '/rss.php?user=%s&t=%s&v=%s' % (row['UserID'], self.a1, 'A' * 64),
                     '/rss.php?user=%s&t=%s&v=%s' % (row['UserID'], self.a1, ''),
                     '/rss.php?user[]=%s&t=%s&v=%s' % (row['UserID'], self.a1, old),
                     '/rss.php?user=%s&t=%s&v[]=x' % (row['UserID'], self.a1)):
            status, _, body = tn.Browser().get(path)
            self.assertEqual(body.strip(), '', path)

    def test_a_feed_key_of_one_user_does_not_work_for_another_user_or_account(self):
        t1_user = tn.sql("SELECT UserID, CreatedOn FROM phoromatic_users WHERE UserName='tn1admin'")[0]
        t2_user = tn.sql("SELECT UserID, AccountID FROM phoromatic_users WHERE UserName='tn2admin'")[0]
        key1 = re.search(r'v=([0-9a-f]{64})', tn.feed_path('tn1admin')).group(1)
        for uid, aid in ((t2_user['UserID'], self.a2), (t2_user['UserID'], self.a1), (t1_user['UserID'], self.a2)):
            self.assertEqual(tn.Browser().get('/rss.php?user=%s&t=%s&v=%s' % (uid, aid, key1))[2].strip(), '', (uid, aid))
        self.assertIn('<rss', tn.Browser().get(tn.feed_path('tn2admin'))[2])
        self.assertNotIn(self.t1['pprid'], tn.Browser().get(tn.feed_path('tn2admin'))[2])

    def test_a_disabled_user_has_no_feed_a_viewer_has(self):
        tn.sql_exec("UPDATE phoromatic_users SET AdminLevel=0 WHERE UserName='tn1lvl3'")
        self.sql_cleanup("UPDATE phoromatic_users SET AdminLevel=3 WHERE UserName='tn1lvl3'")
        self.assertEqual(tn.Browser().get(tn.feed_path('tn1lvl3'))[2].strip(), '')
        tn.sql_exec("UPDATE phoromatic_users SET AdminLevel=1.5 WHERE UserName='tn1lvl3'")
        self.assertEqual(tn.Browser().get(tn.feed_path('tn1lvl3'))[2].strip(), '')
        self.assertIn('<rss', tn.Browser().get(tn.feed_path('tn1lvl10'))[2])

    def test_a_title_with_markup_cannot_break_out_of_the_feed(self):
        evil = 'TNFEED1'
        tn.sql_exec("INSERT INTO phoromatic_results (AccountID, UploadID, ScheduleID, SystemID, UploadTime, Title, Description, PPRID) VALUES ('%s', 660099, 1, 'T1SYS', '2026-11-02 10:00:00', "
                    "'</title><script>alert(1)</script>]]><x>', 'a & b <img src=x onerror=alert(2)>', '%s')" % (self.a1, evil))
        self.sql_cleanup("DELETE FROM phoromatic_results WHERE PPRID = '%s'" % evil)
        body = tn.Browser().get(tn.feed_path('tn1admin'))[2]
        root = ET.fromstring(body)   # well-formed
        self.assertNotIn('<script>', body)
        self.assertNotIn('<img', body)
        titles = [t.text for t in root.iter('title')]
        self.assertIn('</title><script>alert(1)</script>]]><x>', titles)
        self.assertEqual(len([i for i in root.iter('item')]), len([i for i in root.iter('guid')]))

    def test_a_suspended_account_has_no_feed(self):
        tn.set_state(self.a1, 'suspended')
        self.addCleanup(tn.set_state, self.a1, 'active')
        self.assertEqual(tn.Browser().get(tn.feed_path('tn1admin'))[2].strip(), '')


class RegistrationIsGone(Two):
    def attempt(self, name, **extra):
        b = tn.Browser()
        b.get('/')
        fields = {'register_username': name, 'register_password': tn.PW, 'register_password_confirm': tn.PW, 'register_email': name + '@example.com'}
        fields.update(extra)
        return b.post('/?register', fields)[2]

    def snapshot(self):
        return (tn.sql('SELECT COUNT(*) AS n FROM phoromatic_users')[0]['n'], tn.sql('SELECT COUNT(*) AS n FROM phoromatic_accounts')[0]['n'], tn.srv_sh('ls %s/accounts | sort' % tn.STORAGE))

    def test_posting_the_old_form_creates_nothing_whatever_the_settings_say(self):
        before = self.snapshot()
        set_setting('account_creation_alt', '')              # what an old `server-registration open` stored
        set_setting('add_new_users_to_account', self.a1)     # and the old "add to an existing account" setting
        self.addCleanup(remove_setting, 'add_new_users_to_account')
        self.addCleanup(set_setting, 'account_creation_alt', 'Account creation is closed on this server. Ask its administrator for an account.')
        for n, extra in enumerate(({}, {'seed_accountid': self.a1}, {'seed_accountid': 'NEWSEED'})):
            body = self.attempt('tnreg%d' % n, **extra)
            self.assertNotIn('Account Created', body)
            self.assertIn(CLOSED, body)
        self.assertEqual(self.snapshot(), before)
        self.assertEqual(tn.sql("SELECT UserName FROM phoromatic_users WHERE UserName LIKE 'tnreg%'"), [])

    def test_the_welcome_page_has_no_form_and_escapes_the_message(self):
        set_setting('account_creation_alt', 'Ask <i>IT</i> & wait')
        self.addCleanup(set_setting, 'account_creation_alt', 'Account creation is closed on this server. Ask its administrator for an account.')
        page = tn.Browser().get('/')[2]
        self.assertNotIn('register_username', page)
        self.assertNotIn('Create A New Account', page)
        self.assertIn('Ask &lt;i&gt;IT&lt;/i&gt; &amp; wait', page)

    def test_a_new_account_is_never_a_user_in_an_existing_one(self):
        # the function behind server-account-create: even with the old setting naming an account, the user gets an account of its own
        set_setting('add_new_users_to_account', self.a1)
        self.addCleanup(remove_setting, 'add_new_users_to_account')
        out = tn.php_boot("phoromatic_server::prepare_database(); echo var_export(phoromatic_accounts::create('tnaddto1', 'tnpass12345', 'tnpass12345', 'a@example.com'), true);")
        self.assertEqual(out.strip(), 'NULL')
        row = tn.sql("SELECT AccountID AS a, AdminLevel AS l FROM phoromatic_users WHERE UserName='tnaddto1'")[0]
        self.addCleanup(tn.set_state, row['a'], 'deleted')   # (an account of its own now; park it so later tests see the two fixture accounts)
        self.assertNotIn(row['a'], (self.a1, self.a2))
        self.assertEqual(row['l'], 1)
        self.assertEqual(tn.sql("SELECT COUNT(*) AS n FROM phoromatic_users WHERE AccountID='%s' AND UserName='tnaddto1'" % self.a1)[0]['n'], 0)
        # (cleanup: the extra account stays a tenant of its own; it holds nothing of the fixture)

    def test_the_command_refuses_open(self):
        out = tn.cli('server-registration', 'open')
        self.assertIn('Open registration was removed', out)
        self.assertIn(CLOSED, tn.Browser().get('/')[2])


class Claims(Two):
    def claim_page(self, b, ip='', mac=''):
        return b.post('/?system_claim', {'ip_claim': ip, 'mac_claim': mac, 'token_submit': b.token('/?settings')})[2]

    def mine(self):
        return tn.sql("SELECT IPAddress AS i, NetworkMAC AS m FROM phoromatic_system_association_claims WHERE AccountID='%s' ORDER BY rowid" % self.a1)

    def setUp(self):
        super().setUp()
        self.b = tn.logins('T1')
        tn.sql_exec("DELETE FROM phoromatic_system_association_claims WHERE AccountID='%s'" % self.a1)
        self.addCleanup(tn.sql_exec, "DELETE FROM phoromatic_system_association_claims WHERE AccountID='%s'" % self.a1)

    def test_levels_one_and_two_may_claim_and_three_and_ten_may_not(self):
        n = 0
        for lvl, expect in ((1, True), (2, True), (3, False), (10, False)):
            n += 1
            page = self.claim_page(self.b[lvl], ip='192.0.2.%d' % n)
            got = [r for r in self.mine() if r['i'] == '192.0.2.%d' % n]
            self.assertEqual(bool(got), expect, lvl)
            if not expect:
                self.assertIn('Only the administrators', self.b[lvl].get('/?system_claim')[2])
                self.assertNotIn('name="ip_claim"', self.b[lvl].get('/?system_claim')[2])

    def t2_claim(self, ip, mac, when='2026-11-01 10:00:00'):
        tn.sql_exec("INSERT INTO phoromatic_system_association_claims (AccountID, IPAddress, NetworkMAC, CreationTime) VALUES ('%s', '%s', '%s', '%s')" % (self.a2, ip, mac, when))
        self.addCleanup(tn.sql_exec, "DELETE FROM phoromatic_system_association_claims WHERE AccountID='%s' AND IPAddress='%s'" % (self.a2, ip))

    def test_an_address_or_mac_of_another_account_is_refused_without_naming_it(self):
        self.t2_claim('198.51.100.7', 'aa:bb:cc:dd:ee:07')
        for ip, mac in (('198.51.100.7', ''), ('', 'aa:bb:cc:dd:ee:07'), ('', 'AA-BB-CC-DD-EE-07'), ('', ' aa:bb:cc:dd:ee:07 '), ('198.51.100.7', '00:11:22:33:44:55'), ('198.51.100.99', 'AA:BB:CC:DD:EE:07')):
            page = self.claim_page(self.b[1], ip, mac)
            self.assertIn('already associated with another account', page, (ip, mac))
            self.assertNotIn(self.a2, page)
            self.assertNotIn('CANARY', page)
        self.assertEqual(self.mine(), [])
        refused = tn.sql("SELECT Detail FROM phoromatic_admin_audit WHERE AccountID='%s' AND Action='claim_refused'" % self.a1)
        self.assertGreaterEqual(len(refused), 6)
        self.assertEqual(tn.sql("SELECT COUNT(*) AS n FROM phoromatic_admin_audit WHERE AccountID='%s' AND Action='claim_refused'" % self.a2)[0]['n'], 0)

    def test_an_address_or_mac_a_live_system_of_another_account_has_is_refused_and_a_deleted_one_does_not_count(self):
        # (the fixture's canary values make every text column non-empty, DeletedOn included: this system is "deleted" until it is made live here)
        orig = tn.sql("SELECT LastIP, NetworkMAC, DeletedOn FROM phoromatic_systems WHERE AccountID='%s' AND SystemID='%s'" % (self.a2, self.t2['system']))[0]
        where = "AccountID='%s' AND SystemID='%s'" % (self.a2, self.t2['system'])
        self.addCleanup(tn.sql_exec, "UPDATE phoromatic_systems SET LastIP='%s', NetworkMAC='%s', DeletedOn='%s' WHERE %s" % (orig['LastIP'], orig['NetworkMAC'], orig['DeletedOn'], where))
        tn.sql_exec("UPDATE phoromatic_systems SET LastIP='203.0.113.50', NetworkMAC='AA:BB:CC:00:00:50', DeletedOn=NULL WHERE " + where)
        self.assertIn('already associated', self.claim_page(self.b[1], ip='203.0.113.50'))
        self.assertIn('already associated', self.claim_page(self.b[1], mac='aa:bb:cc:00:00:50'))
        self.assertEqual(self.mine(), [])
        tn.sql_exec("UPDATE phoromatic_systems SET DeletedOn='2026-11-01 00:00:00' WHERE " + where)
        self.assertIn('Claim saved', self.claim_page(self.b[1], ip='203.0.113.50'))

    def test_claims_of_a_system_of_the_own_account_are_fine_and_junk_is_refused(self):
        self.assertIn('Claim saved', self.claim_page(self.b[1], ip='192.0.2.1', mac='AA-BB-CC-00-00-01'))
        self.assertEqual(self.mine(), [{'i': '192.0.2.1', 'm': 'aa:bb:cc:00:00:01'}])
        for ip, mac in (('999.1.1.1', ''), ('1.2.3.4; id', ''), ('1.2.3.4 -c 1000', ''), ('', 'not-a-mac'), ('', '00:00:00:00:00:00'), ('', 'ff:ff:ff:ff:ff:ff'), ('<script>', ''), ('', '')):
            self.claim_page(self.b[1], ip, mac)
        self.assertEqual(len(self.mine()), 1)

    def test_a_claim_needs_the_token_and_the_same_site(self):
        self.b[1].post('/?system_claim', {'ip_claim': '192.0.2.77', 'mac_claim': ''})
        self.b[1].post('/?system_claim', {'ip_claim': '192.0.2.78', 'mac_claim': '', 'token_submit': self.b[1].token('/?settings')}, headers={'Origin': 'http://evil.example'})
        self.assertEqual(self.mine(), [])

    def test_removing_a_claim_removes_only_this_accounts_row(self):
        self.t2_claim('198.51.100.8', 'aa:bb:cc:dd:ee:08')
        self.b[1].post('/?system_claim', {'remove_claim': '198.51.100.8,aa:bb:cc:dd:ee:08', 'token_submit': self.b[1].token('/?settings')})
        left = tn.sql("SELECT IPAddress AS i FROM phoromatic_system_association_claims WHERE AccountID='%s' AND IPAddress='198.51.100.8'" % self.a2)
        self.assertEqual(len(left), 1)
        for junk in ('nocomma', 'a,b,c', ''):
            self.b[1].post('/?system_claim', {'remove_claim': junk, 'token_submit': self.b[1].token('/?settings')})

    def discover(self, ip_header=None, mac='aa:bb:cc:dd:ee:09'):
        return tn.parse_v1(tn.raw_post('/phoromatic.php', {'r': 'ping', 'msi': 'tn-unknown-' + tn.uuid.uuid4().hex, 'nm': mac, 'pts_core': tn.CORE}))

    def test_discovery_answers_for_one_claimant_and_for_none_when_two_accounts_both_claim(self):
        tn.sql_exec("INSERT INTO phoromatic_system_association_claims (AccountID, IPAddress, NetworkMAC, CreationTime) VALUES ('%s', '198.51.100.9', 'aa:bb:cc:dd:ee:09', '2026-11-01 10:00:00')" % self.a1)
        self.assertEqual(self.discover().get('account_id'), self.a1)
        # rows from before the cross-account rule can clash; neither account is named then
        self.t2_claim('198.51.100.10', 'aa:bb:cc:dd:ee:09', '2026-10-01 10:00:00')
        self.assertIsNone(self.discover().get('account_id'))
        # a suspended or removed account's claim is not an answer, and does not make the other one ambiguous
        tn.set_state(self.a2, 'suspended')
        self.addCleanup(tn.set_state, self.a2, 'active')
        self.assertEqual(self.discover().get('account_id'), self.a1)
        tn.set_state(self.a2, 'deleted')
        self.assertEqual(self.discover().get('account_id'), self.a1)
        tn.set_state(self.a1, 'suspended')
        self.addCleanup(tn.set_state, self.a1, 'active')
        self.assertIsNone(self.discover().get('account_id'))

    def test_discovery_matches_a_mac_whatever_its_case_and_separator(self):
        # the claim is stored lowercase with colons; a Windows client reports capitals and dashes
        self.assertIn('Claim saved', self.claim_page(self.b[1], mac='AA-BB-CC-00-12-34'))
        self.assertEqual(self.discover(mac='AA:BB:CC:00:12:34').get('account_id'), self.a1)
        self.assertEqual(self.discover(mac='aa-bb-cc-00-12-34').get('account_id'), self.a1)
        self.assertIsNone(self.discover(mac='AA:BB:CC:00:12:35').get('account_id'))

    def test_an_ipv6_claim_is_stored_in_one_spelling_and_compared_in_it(self):
        self.assertIn('Claim saved', self.claim_page(self.b[1], ip='2001:DB8:0:0:0:0:0:ABCD'))
        self.assertEqual(self.mine()[0]['i'], '2001:db8::abcd', 'stored lowercase and compressed')
        # the other account holds a claim an older release stored in capitals; no spelling of it can be claimed here
        self.t2_claim('2001:DB8::DEAD', '')
        for spelling in ('2001:db8::dead', '2001:0db8:0:0:0:0:0:DEAD', '2001:DB8:0::dead'):
            self.assertIn('already associated', self.claim_page(self.b[1], ip=spelling), spelling)
        # and the discovery compares the canonical form on both sides
        out = tn.php_boot("phoromatic_server::prepare_database(true); echo json_encode(array(phoromatic_system_admin::claimed_accounts('2001:db8::dead', '', 5), "
                          "phoromatic_system_admin::claimed_accounts('2001:0DB8:0:0:0:0:0:ABCD', '', 5)));")
        found = tn.json.loads(out)
        self.assertEqual(found[0], [self.a2])
        self.assertEqual(found[1], [self.a1])

    def test_a_machine_id_known_to_two_accounts_names_neither(self):
        msi = 'tn-twice-' + tn.uuid.uuid4().hex
        tn.v1('ping', self.a1, msi)
        tn.v1('ping', self.a2, msi)
        self.addCleanup(tn.sql_exec, "DELETE FROM phoromatic_systems WHERE MachineSelfID='%s'" % msi)
        reply = tn.parse_v1(tn.raw_post('/phoromatic.php', {'r': 'ping', 'msi': msi, 'pts_core': tn.CORE}))
        self.assertNotIn('account_id', reply)
        tn.sql_exec("UPDATE phoromatic_systems SET DeletedOn='2026-11-01 00:00:00' WHERE MachineSelfID='%s' AND AccountID='%s'" % (msi, self.a2))
        reply = tn.parse_v1(tn.raw_post('/phoromatic.php', {'r': 'ping', 'msi': msi, 'pts_core': tn.CORE}))
        self.assertEqual(reply.get('account_id'), self.a1)


class EventTriggers(Two):
    def trigger(self, user, key, name, extra=''):
        return tn.Browser().get('/event.php?type=trigger&user=%s&public_key=%s&trigger=%s%s' % (user, key, name, extra))[2]

    def count(self):
        return tn.sql("SELECT COUNT(*) AS n FROM phoromatic_schedules_triggers")[0]['n']

    def key(self, aid):
        return tn.sql("SELECT PublicKey AS k FROM phoromatic_schedules WHERE AccountID='%s' AND ScheduleID=%d" % (aid, tn.SHARED_SCHEDULE if aid == self.a1 else self.t2['sched']))[0]['k']

    def test_a_viewer_and_a_disabled_user_cannot_trigger_and_all_failures_say_the_same(self):
        key = self.key(self.a1)
        n = self.count()
        self.assertIn('added', self.trigger('tn1admin', key, 'tnt1'))
        self.assertIn('added', self.trigger('tn1lvl3', key, 'tnt2'))
        self.assertEqual(self.count(), n + 2)
        tn.sql_exec("UPDATE phoromatic_users SET AdminLevel=0 WHERE UserName='tn1lvl2'")
        self.sql_cleanup("UPDATE phoromatic_users SET AdminLevel=2 WHERE UserName='tn1lvl2'")
        answers = {self.trigger('tn1lvl10', key, 'tnt3'), self.trigger('tn1lvl2', key, 'tnt4'), self.trigger('no-such-user', key, 'tnt5'), self.trigger('tn1admin', 'wrong', 'tnt6'), self.trigger('tn1admin', '', 'tnt7')}
        self.assertEqual(answers, {'Incorrect user information.'})
        self.assertEqual(self.count(), n + 2)

    def test_the_other_accounts_key_does_nothing_for_a_user_of_this_one(self):
        n = self.count()
        self.assertEqual(self.trigger('tn1admin', self.key(self.a2), 'tnx1'), 'Incorrect user information.')
        self.assertEqual(self.trigger('tn2admin', self.key(self.a1), 'tnx2'), 'Incorrect user information.')
        self.assertEqual(self.count(), n)

    def test_a_request_for_this_address_with_no_system_there_records_nothing(self):
        key = self.key(self.a1)
        n = self.count()
        # systems that earlier tests registered from this address would be an answer; set them aside for the test
        held = tn.sql("SELECT SystemID AS s, LastIP AS ip FROM phoromatic_systems WHERE AccountID='%s' AND LastIP IS NOT NULL" % self.a1)
        tn.sql_exec("UPDATE phoromatic_systems SET LastIP = NULL WHERE AccountID='%s'" % self.a1)
        for h in held:
            self.sql_cleanup("UPDATE phoromatic_systems SET LastIP = '%s' WHERE AccountID='%s' AND SystemID='%s'" % (h['ip'], self.a1, h['s']))
        out = self.trigger('tn1admin', key, 'tnip', '&sub_target_this_ip=1')
        self.assertIn('No system found associated to this IP address', out)
        self.assertEqual(self.count(), n)
        self.assertEqual(tn.sql("SELECT COUNT(*) AS n FROM phoromatic_schedules_triggers WHERE Trigger = 'tnip'")[0]['n'], 0)

    def test_array_parameters_are_not_a_crash(self):
        self.assertIn('Missing', tn.Browser().get('/event.php?type=trigger&user[]=x&public_key=y&trigger=z')[2])


class AdoptionAndNativeRefs(Two):
    def test_adopting_is_not_blocked_by_another_accounts_machine_id(self):
        mac = 'aa:bb:cc:dd:ee:21'
        existing_msi, new_msi = 'tn-adopt-old-' + tn.uuid.uuid4().hex, 'tn-adopt-new-' + tn.uuid.uuid4().hex
        tn.v1('ping', self.a1, existing_msi, nm=mac)
        tn.sql_exec("UPDATE phoromatic_systems SET State=1 WHERE MachineSelfID='%s'" % existing_msi)
        tn.v1('ping', self.a2, new_msi)    # the other account already knows the machine id the new client will present
        tn.v1('ping', self.a1, new_msi, nm=mac)
        pending = tn.sql("SELECT SystemID AS s, AdoptionCandidate AS c FROM phoromatic_systems WHERE MachineSelfID='%s' AND AccountID='%s'" % (new_msi, self.a1))[0]
        self.assertTrue(pending['c'], 'the new client is a pending clash with the existing system')
        self.addCleanup(tn.sql_exec, "DELETE FROM phoromatic_systems WHERE MachineSelfID IN ('%s', '%s') OR AdoptionCandidate IS NOT NULL AND AccountID='%s'" % (existing_msi, new_msi, self.a1))
        out = tn.php_boot("phoromatic_server::prepare_database(); echo json_encode(phoromatic_system_admin::adopt('%s', '%s', 'tn', null, true));" % (self.a1, pending['s']))
        self.assertTrue(json.loads(out.strip())['ok'], out)
        self.assertEqual(tn.sql("SELECT MachineSelfID AS m FROM phoromatic_systems WHERE MachineSelfID='%s' AND AccountID='%s' AND DeletedOn IS NULL" % (new_msi, self.a1))[0]['m'], new_msi)

    def test_adopting_still_refuses_a_machine_id_the_own_account_already_uses(self):
        mac = 'aa:bb:cc:dd:ee:22'
        old_msi, other_msi, new_msi = ['tn-adopt-%s-' % n + tn.uuid.uuid4().hex for n in ('a', 'b', 'c')]
        tn.v1('ping', self.a1, old_msi, nm=mac)
        tn.sql_exec("UPDATE phoromatic_systems SET State=1 WHERE MachineSelfID='%s'" % old_msi)
        tn.v1('ping', self.a1, new_msi, nm=mac)
        pending = tn.sql("SELECT SystemID AS s FROM phoromatic_systems WHERE MachineSelfID='%s'" % new_msi)[0]['s']
        self.addCleanup(tn.sql_exec, "DELETE FROM phoromatic_systems WHERE MachineSelfID IN ('%s', '%s', '%s')" % (old_msi, other_msi, new_msi))
        # a second live row of this account with the same machine id as the pending one
        tn.sql_exec("INSERT INTO phoromatic_systems (AccountID, SystemID, MachineSelfID, State, CreatedOn, LastCommunication) VALUES ('%s', 'TNDUP', '%s', 1, '2026-11-01 10:00:00', '2026-11-01 10:00:00')" % (self.a1, new_msi))
        self.addCleanup(tn.sql_exec, "DELETE FROM phoromatic_systems WHERE SystemID='TNDUP'")
        out = json.loads(tn.php_boot("phoromatic_server::prepare_database(); echo json_encode(phoromatic_system_admin::adopt('%s', '%s', 'tn', null, true));" % (self.a1, pending)).strip())
        self.assertFalse(out['ok'])
        self.assertIn('already used by another system', out['message'])

    def test_a_native_ref_is_the_ones_the_own_accounts_agents_reported(self):
        tn.sql_exec("INSERT INTO phoromatic_system_catalogue (AccountID, SystemID, Ref, Version, TestJSON, ReportedOn) VALUES ('%s', '%s', 'acme/widget', '1.0.0', '{}', '2026-11-01 10:00:00')" % (self.a2, self.t2['system']))
        self.addCleanup(tn.sql_exec, "DELETE FROM phoromatic_system_catalogue WHERE Ref = 'acme/widget'")
        ask = "echo json_encode(phoromatic_native_result::is_native_ref('acme/widget-1.0.0'));"
        mine = lambda aid: tn.php_web_boot("phoromatic_tenant_context::set('%s');" % aid + ask).strip()
        self.assertEqual(mine(self.a2), 'true')
        self.assertEqual(mine(self.a1), 'false')
        self.assertEqual(tn.php_web_boot(ask).strip(), 'false', 'a web request with no account')
        self.assertEqual(tn.php_web_boot(ask, level=-40).strip(), 'true', 'the root login looks at every account')
        self.assertEqual(tn.php_boot("phoromatic_server::prepare_database();" + ask).strip(), 'true', 'a command on the server host is the operator')
        # the engine's own tests and OpenBenchmarking's repositories do not depend on any account
        both = "echo json_encode(array(phoromatic_native_result::is_native_ref('benchy/stream'), phoromatic_native_result::is_native_ref('pts/acme')));"
        self.assertEqual(tn.php_web_boot("phoromatic_tenant_context::set('%s');" % self.a1 + both).strip(), '[true,false]')


class AutoApprovePolicy(Two):
    def flag(self, aid, v):
        tn.sql_exec("UPDATE phoromatic_account_settings SET AutoApproveNewSystems=%d WHERE AccountID='%s'" % (v, aid))

    def policy(self):
        return re.search(r'may auto-approve new systems: (\w+)', tn.cli('phoromatic-tenant-auto-approve')).group(1)

    def register(self, aid):
        msi = 'tn-auto-' + tn.uuid.uuid4().hex
        reply = tn.v1('ping', aid, msi)
        self.addCleanup(tn.sql_exec, "DELETE FROM phoromatic_systems WHERE MachineSelfID='%s'" % msi)
        return reply, tn.sql("SELECT State AS s FROM phoromatic_systems WHERE MachineSelfID='%s'" % msi)[0]['s']

    def setUp(self):
        super().setUp()
        self.b = tn.logins('T1')
        self.addCleanup(tn.cli, 'phoromatic-tenant-auto-approve', 'forbidden')
        self.addCleanup(tn.cli, 'server-admin-revoke', 'tn1admin')
        self.addCleanup(self.flag, self.a1, 0)

    def test_forbidden_is_the_default_and_a_system_waits_whatever_the_account_says(self):
        self.assertEqual(self.policy(), 'forbidden')
        self.flag(self.a1, 1)            # a database restored from before the policy, or a rollback and forward again
        reply, state = self.register(self.a1)
        self.assertEqual(state, 0)
        self.assertIn('Waiting For Approval', json.dumps(reply))

    def test_the_settings_page_shows_the_box_off_and_disabled_and_ignores_a_post(self):
        b = self.b[1]
        self.flag(self.a1, 1)
        page = b.get('/?settings')[2]
        box = re.search(r'<input type="checkbox" name="AutoApproveNewSystems"[^>]*>', page).group(0)
        self.assertIn('disabled="disabled"', box)
        self.assertNotIn('checked', box)
        self.assertIn('Forbidden by the server administrator', page)
        b.post('/?settings', {'account_settings_update': '1', 'AutoApproveNewSystems': 'yes', 'token_submit': b.token('/?settings')})
        # the form did not read the box, so what the database holds is left alone (0 after a switch to forbidden, whatever an old restore put there)
        self.assertEqual(tn.sql("SELECT AutoApproveNewSystems AS a FROM phoromatic_account_settings WHERE AccountID='%s'" % self.a1)[0]['a'], 1)
        reply, state = self.register(self.a1)
        self.assertEqual(state, 0)

    def test_a_tenant_administrator_cannot_change_the_policy_a_server_administrator_can(self):
        b = self.b[1]
        b.post('/?settings', {'tenant_auto_approve': 'allowed', 'token_submit': b.token('/?settings')})
        self.assertEqual(self.policy(), 'forbidden')
        tn.cli('server-admin-grant', 'tn1admin')
        b.post('/?settings', {'tenant_auto_approve': 'allowed'})
        b.post('/?settings', {'tenant_auto_approve': 'allowed', 'token_submit': b.token('/?settings')}, headers={'Origin': 'http://evil.example'})
        self.assertEqual(self.policy(), 'forbidden')
        self.assertIn('Saved', b.post('/?settings', {'tenant_auto_approve': 'allowed', 'token_submit': b.token('/?settings')})[2])
        self.assertEqual(self.policy(), 'allowed')

    def test_allowed_lets_the_account_turn_it_on_and_forbidden_again_turns_every_account_off(self):
        self.assertIn('allowed', tn.cli('phoromatic-tenant-auto-approve', 'allowed'))
        b = self.b[1]
        page = b.get('/?settings')[2]
        self.assertNotIn('disabled="disabled"', re.search(r'<input type="checkbox" name="AutoApproveNewSystems"[^>]*>', page).group(0))
        b.post('/?settings', {'account_settings_update': '1', 'AutoApproveNewSystems': 'yes', 'token_submit': b.token('/?settings')})
        self.assertEqual(tn.sql("SELECT AutoApproveNewSystems AS a FROM phoromatic_account_settings WHERE AccountID='%s'" % self.a1)[0]['a'], 1)
        reply, state = self.register(self.a1)
        self.assertEqual(state, 1)
        self.flag(self.a2, 1)
        self.addCleanup(self.flag, self.a2, 0)
        out = tn.cli('phoromatic-tenant-auto-approve', 'forbidden', '--tenant', self.a1)
        self.assertIn('forbidden', out)
        self.assertEqual(tn.sql("SELECT COUNT(*) AS n FROM phoromatic_account_settings WHERE AutoApproveNewSystems <> 0")[0]['n'], 0)
        reply, state = self.register(self.a1)
        self.assertEqual(state, 0)
        # recorded: the account named by --tenant sees it, the server audit has it, with the number of accounts switched off
        rows = tn.sql("SELECT Detail FROM phoromatic_admin_audit WHERE AccountID='%s' AND Action='tenant_auto_approve_set' ORDER BY AuditID DESC" % self.a1)
        self.assertEqual(json.loads(rows[0]['Detail']), {'before': 'allowed', 'after': 'forbidden', 'accounts_switched_off': 2})
        srv = tn.sql("SELECT Detail FROM phoromatic_server_audit WHERE Action='server_setting_changed' AND SubjectID='tenant_auto_approve' ORDER BY AuditID DESC LIMIT 1")
        self.assertTrue(srv and json.loads(srv[0]['Detail'])['after'] == 'forbidden')

    def test_a_returning_deleted_machine_and_a_mac_clash_still_wait_when_allowed(self):
        tn.cli('phoromatic-tenant-auto-approve', 'allowed')
        self.flag(self.a1, 1)
        old, new = 'tn-ar-old-' + tn.uuid.uuid4().hex, 'tn-ar-new-' + tn.uuid.uuid4().hex
        tn.v1('ping', self.a1, old, nm='aa:bb:cc:dd:ee:31')
        self.addCleanup(tn.sql_exec, "DELETE FROM phoromatic_systems WHERE MachineSelfID IN ('%s', '%s')" % (old, new))
        tn.sql_exec("UPDATE phoromatic_systems SET State=1 WHERE MachineSelfID='%s'" % old)
        tn.v1('ping', self.a1, new, nm='aa:bb:cc:dd:ee:31')
        self.assertEqual(tn.sql("SELECT State AS s FROM phoromatic_systems WHERE MachineSelfID='%s'" % new)[0]['s'], 0)

    def test_the_command_refuses_nonsense(self):
        self.assertIn('Usage', tn.cli('phoromatic-tenant-auto-approve', 'yes'))
        self.assertIn('Usage', tn.cli('phoromatic-tenant-auto-approve', 'allowed', '--tenant'))
        self.assertEqual(self.policy(), 'forbidden')


class PendingCapAndUnknownAccounts(Two):
    def setUp(self):
        super().setUp()
        tn.reset_v1_limits()
        self.addCleanup(tn.reset_v1_limits)

    def fill(self, aid, n):
        tn.srv_php('$d=new SQLite3("%s/phoromatic.db"); $d->busyTimeout(20000); $d->exec("BEGIN"); for($i=0;$i<%d;$i++){ $d->exec("INSERT INTO phoromatic_systems (AccountID, SystemID, MachineSelfID, State, CreatedOn, LastCommunication) VALUES (\'%s\', \'CAP".$i."\', \'tn-cap-".$i."\', 0, \'2026-11-01 10:00:00\', \'2026-11-01 10:00:00\')"); } $d->exec("COMMIT");' % (tn.STORAGE, n, aid))
        self.addCleanup(tn.sql_exec, "DELETE FROM phoromatic_systems WHERE SystemID LIKE 'CAP%%' AND AccountID='%s'" % aid)

    def test_a_full_pending_list_refuses_new_systems_and_not_known_ones_or_other_accounts(self):
        known = tn.approved_machine(self.a1)
        pending = tn.sql("SELECT COUNT(*) AS n FROM phoromatic_systems WHERE AccountID='%s' AND State=0 AND DeletedOn IS NULL" % self.a1)[0]['n']
        self.fill(self.a1, 200 - pending)
        new = 'tn-cap-new-' + tn.uuid.uuid4().hex
        reply = tn.v1('ping', self.a1, new)
        self.assertIn('Too many systems are already waiting', reply.get('error', ''))
        self.assertEqual(tn.sql("SELECT COUNT(*) AS n FROM phoromatic_systems WHERE MachineSelfID='%s'" % new)[0]['n'], 0)
        self.assertNotIn('error', tn.v1('ping', self.a1, known))                               # an approved system keeps checking in
        again = tn.v1('ping', self.a1, 'tn-cap-5')                                              # and so does a pending one: it is already on the list
        self.assertNotIn('Too many', again.get('error', ''))
        other = 'tn-cap-other-' + tn.uuid.uuid4().hex
        self.addCleanup(tn.sql_exec, "DELETE FROM phoromatic_systems WHERE MachineSelfID='%s'" % other)
        self.assertNotIn('Too many', tn.v1('ping', self.a2, other).get('error', ''))        # another account is not held back by this one
        # approving one makes room for exactly one
        tn.sql_exec("UPDATE phoromatic_systems SET State=1 WHERE SystemID='CAP0' AND AccountID='%s'" % self.a1)
        self.assertNotIn('Too many', tn.v1('ping', self.a1, new).get('error', ''))
        self.addCleanup(tn.sql_exec, "DELETE FROM phoromatic_systems WHERE MachineSelfID='%s'" % new)
        self.assertIn('Too many', tn.v1('ping', self.a1, 'tn-cap-new2-' + tn.uuid.uuid4().hex).get('error', ''))

    def test_twenty_requests_for_an_account_that_does_not_exist_lock_the_address_out(self):
        known = tn.approved_machine(self.a1)
        for i in range(20):
            self.assertEqual(tn.v1('ping', 'ZZ%04d' % i, 'tn-unk-%d' % i).get('error'), 'Invalid User', i)
        for r in ('ping', 'tick', 'list_results'):
            self.assertIn('Too many requests naming an unknown account', tn.v1(r, self.a1, known).get('error', ''), r)
        reply = tn.parse_v1(tn.raw_post('/phoromatic.php', {'r': 'ping', 'msi': known, 'pts_core': tn.CORE}))
        self.assertIn('Too many requests', reply.get('error', ''), 'discovery is held back too')
        audited = tn.sql("SELECT Detail FROM phoromatic_admin_audit WHERE Action='v1_account_unknown'")
        self.assertTrue(1 <= len(audited) <= 4, len(audited))
        self.assertTrue(any('"locked_out":true' in a['Detail'] for a in audited))
        self.assertEqual(tn.sql("SELECT COUNT(*) AS n FROM phoromatic_systems WHERE MachineSelfID LIKE 'tn-unk-%'")[0]['n'], 0)
        # a different address is not held back, and the window ends: clearing the counter (what 15 minutes do) lets the address in again
        tn.reset_v1_limits()
        self.assertNotIn('error', tn.v1('ping', self.a1, known))

    def test_requests_for_known_accounts_and_malformed_ids_are_counted_only_when_the_account_is_unknown(self):
        known = tn.approved_machine(self.a1)
        for i in range(25):
            self.assertNotIn('error', tn.v1('ping', self.a1, known))
        self.assertEqual(tn.sql("SELECT COUNT(*) AS n FROM phoromatic_v2_failures WHERE Kind='v1_unknown_account'")[0]['n'], 0)
        self.assertEqual(tn.parse_v1(tn.raw_post('/phoromatic.php', {'r': 'ping', 'pts_core': tn.CORE})).get('error'), 'Invalid Credentials')
        self.assertEqual(tn.sql("SELECT COUNT(*) AS n FROM phoromatic_v2_failures WHERE Kind='v1_unknown_account'")[0]['n'], 0)


class TenantOption(Two):
    """--tenant on the eight server commands: with several active accounts it is required (and the error lists them), a name or an id works, a suspended or
    unknown account is refused, and with one active account it is optional."""
    TOKEN_CMDS = (('private-repo-token-list',), ('private-repo-token-revoke', '1'), ('bench-token-list',), ('bench-token-revoke', '1'))

    def setUp(self):
        super().setUp()
        tn.sql_exec("UPDATE phoromatic_accounts SET GroupName='TN One' WHERE AccountID='%s'" % self.a1)
        tn.sql_exec("UPDATE phoromatic_accounts SET GroupName='TN Two' WHERE AccountID='%s'" % self.a2)
        self.addCleanup(tn.sql_exec, "UPDATE phoromatic_accounts SET GroupName=NULL WHERE AccountID IN ('%s', '%s')" % (self.a1, self.a2))
        self.n_tokens = lambda aid: tn.sql("SELECT COUNT(*) AS n FROM phoromatic_private_repo_tokens WHERE AccountID='%s'" % aid)[0]['n']

    def test_with_two_active_accounts_every_command_asks_which_and_lists_them(self):
        t1, t2 = self.n_tokens(self.a1), self.n_tokens(self.a2)
        cmds = list(self.TOKEN_CMDS) + [('private-repo-token-create', 'tn-label'), ('bench-token-create', '99', 'tn-label')]
        for cmd in cmds:
            out = tn.cli(*cmd)
            self.assertIn('ERROR', out, cmd)
            self.assertIn('--tenant', out, cmd)
            self.assertIn(self.a1, out, cmd)
            self.assertIn(self.a2, out, cmd)
            self.assertIn('TN One', out, cmd)
            self.assertNotIn('bpr_', out, cmd)
            self.assertNotIn('Token #', out, cmd)
        self.assertEqual((self.n_tokens(self.a1), self.n_tokens(self.a2)), (t1, t2))

    def test_an_id_or_a_name_picks_the_account_and_the_token_goes_there(self):
        # (only the first account gets tokens here: the other account's rows are compared byte for byte afterwards)
        t1 = self.n_tokens(self.a1)
        out = tn.cli('private-repo-token-create', 'tn-by-id', '--tenant', self.a1)
        self.assertIn('for account ' + self.a1, out)
        self.assertRegex(out, r'bpr_[A-Za-z0-9_-]{43}')
        out = tn.cli('private-repo-token-create', '--tenant', 'tn one', 'tn-by-name')       # (any case; before or after the label)
        self.assertIn('for account ' + self.a1, out)
        self.assertEqual(self.n_tokens(self.a1), t1 + 2)
        tids = [r['t'] for r in tn.sql("SELECT TokenID AS t FROM phoromatic_private_repo_tokens WHERE AccountID='%s' AND Label IN ('tn-by-id', 'tn-by-name')" % self.a1)]
        self.addCleanup(tn.sql_exec, "DELETE FROM phoromatic_private_repo_tokens WHERE TokenID IN (%s) AND AccountID='%s'" % (','.join(str(t) for t in tids), self.a1))
        self.assertIn('tn-by-id', tn.cli('private-repo-token-list', '--tenant', 'TN One'))
        self.assertNotIn('tn-by-id', tn.cli('private-repo-token-list', '--tenant', self.a2))
        self.assertNotIn('tn-by-name', tn.cli('private-repo-token-list', '--tenant', self.a2))
        tn.cli('private-repo-token-revoke', str(tids[0]), '--tenant', self.a1)
        self.assertIsNotNone(tn.sql("SELECT RevokedOn AS r FROM phoromatic_private_repo_tokens WHERE TokenID=%d AND AccountID='%s'" % (tids[0], self.a1))[0]['r'])
        # suspended: the other token is still revoked by the command
        tn.set_state(self.a1, 'suspended')
        self.addCleanup(tn.set_state, self.a1, 'active')
        self.assertIn('tn-by-name', tn.cli('private-repo-token-list', '--tenant', self.a1))
        tn.cli('private-repo-token-revoke', str(tids[1]), '--tenant', self.a1)
        self.assertIsNotNone(tn.sql("SELECT RevokedOn AS r FROM phoromatic_private_repo_tokens WHERE TokenID=%d AND AccountID='%s'" % (tids[1], self.a1))[0]['r'])
        tn.set_state(self.a1, 'active')

    def test_bench_token_commands_take_it_too(self):
        self.assertIn('No bench tokens', tn.cli('bench-token-list', '--tenant', self.a1))
        self.assertNotIn('--tenant', tn.cli('bench-token-list', '--tenant', 'TN One'))
        self.assertIn('ERROR', tn.cli('bench-token-revoke', '5', '--tenant', self.a1))        # (no such token, but the account was accepted: the error is about the token)
        self.assertNotIn('--tenant', tn.cli('bench-token-revoke', '5', '--tenant', self.a1))

    def test_unknown_suspended_deleted_and_ambiguous_accounts_are_refused(self):
        for value, text in (('NOSUCH', 'No such account'), ('', 'Usage')):
            args = ['private-repo-token-list', '--tenant'] + ([value] if value else [])
            self.assertIn(text, tn.cli(*args), value)
        tn.set_state(self.a2, 'suspended')
        self.addCleanup(tn.set_state, self.a2, 'active')
        # a suspended account cannot get new tokens, but its tokens can be listed and revoked (a leaked one has to be stoppable during an incident)
        self.assertIn('is suspended', tn.cli('private-repo-token-create', 'tn-nope', '--tenant', self.a2))
        self.assertIn('is suspended', tn.cli('bench-token-create', '99', 'tn-nope', '--tenant', self.a2))
        self.assertIn('Repository tokens for account ' + self.a2, tn.cli('private-repo-token-list', '--tenant', self.a2))
        self.assertNotIn('is suspended', tn.cli('bench-token-list', '--tenant', self.a2))
        self.assertNotIn('is suspended', tn.cli('private-repo-token-revoke', '987654', '--tenant', self.a2))
        self.assertNotIn('is suspended', tn.cli('bench-token-revoke', '987654', '--tenant', self.a2))
        tn.set_state(self.a2, 'deleted')
        self.assertIn('No such account', tn.cli('private-repo-token-list', '--tenant', self.a2))
        tn.set_state(self.a2, 'active')
        tn.sql_exec("UPDATE phoromatic_accounts SET GroupName='TN One' WHERE AccountID='%s'" % self.a2)
        out = tn.cli('private-repo-token-list', '--tenant', 'TN One')
        self.assertIn('More than one account is named', out)
        self.assertIn(self.a1, out)
        self.assertIn(self.a2, out)

    def test_with_one_active_account_it_is_optional(self):
        others = [r['AccountID'] for r in tn.sql("SELECT AccountID FROM phoromatic_accounts WHERE AccountID <> '%s' AND State = 'active'" % self.a1)]
        for aid in others:
            tn.set_state(aid, 'suspended')
            self.addCleanup(tn.set_state, aid, 'active')
        self.assertIn('Repository tokens for account ' + self.a1, tn.cli('private-repo-token-list'))
        self.assertIn('No bench tokens', tn.cli('bench-token-list'))

    def test_the_two_server_wide_settings_work_without_it_and_record_the_account_when_given(self):
        before = tn.phone_home_policy()
        self.addCleanup(tn.cli, 'phoromatic-client-phone-home', before)
        self.addCleanup(tn.cli, 'phoromatic-v2-allow-http', 'off')
        n_audit = lambda action, aid: tn.sql("SELECT COUNT(*) AS n FROM phoromatic_admin_audit WHERE Action='%s' AND AccountID='%s'" % (action, aid))[0]['n']
        n_none = lambda action: tn.sql("SELECT COUNT(*) AS n FROM phoromatic_admin_audit WHERE Action='%s' AND AccountID=''" % action)[0]['n']
        a1, none = n_audit('client_phone_home_set', self.a1), n_none('client_phone_home_set')
        tn.cli('phoromatic-client-phone-home', 'client_choice')                                   # two accounts, none named: the server audit has it, no account's log does
        self.assertEqual((n_audit('client_phone_home_set', self.a1), n_none('client_phone_home_set')), (a1, none + 1))
        tn.cli('phoromatic-client-phone-home', 'forbidden', '--tenant', self.a1)
        self.assertEqual(n_audit('client_phone_home_set', self.a1), a1 + 1)
        h1 = n_audit('v2_allow_http_set', self.a1)
        tn.cli('phoromatic-v2-allow-http', 'on', '--tenant', 'TN One')
        self.assertEqual(n_audit('v2_allow_http_set', self.a1), h1 + 1)
        self.assertEqual(tn.v2_allow_http(), 'on')
        self.assertIn('No such account', tn.cli('phoromatic-v2-allow-http', 'off', '--tenant', 'nobody'))
        self.assertEqual(tn.v2_allow_http(), 'on')


if __name__ == '__main__':
    unittest.main()
