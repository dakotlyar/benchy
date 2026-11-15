"""T3: the private repository is per account. Two accounts, each with its own `private/` repository (the fixture seeds both, with a test of the same name
in each), and nothing of one account's may be seen, fetched, listed, resolved or run by the other: through the web pages, the client endpoints (v1; v2 has no
private-repository surface), the server's own test and suite lists, the private repository fetch and index, and an upload's reference check. A request with no
account resolves `private/...` to nothing, and the shared cache never holds private content.
"""
import base64
import json
import time
import unittest

import fixture
import tn

CANARY = 'CANARY_T2'


def web(code, aid=None, stdin=None):
    """PHP run as a web request is (the context is enforced), working for `aid` or for no account."""
    ctx = "phoromatic_tenant_context::set('%s'); " % aid if aid else ''
    return tn.php_web_boot("function t($f) { try { $r = $f(); return $r === false ? 'false' : ($r === null ? 'null' : (is_string($r) ? $r : json_encode($r))); } catch(RuntimeException $e) { return 'refused'; } } " + ctx + code, stdin=stdin)


def shared_paths():
    out = tn.php_boot("echo PTS_TEST_PROFILE_PATH, ' ', PTS_TEST_SUITE_PATH, ' ', PTS_OPENBENCHMARKING_SCRATCH_PATH;").split()
    return out


class Base(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.st = tn.state()
        cls.t1, cls.t2 = cls.st['T1']['aid'], cls.st['T2']['aid']


class ResolverIsPerAccount(Base):
    def view(self, aid, kind='test-profiles'):
        return '%s/accounts/%s/private-repo/view/%s/' % (tn.STORAGE, aid, kind)

    def test_each_account_has_its_own_root_index_and_titles(self):
        for aid, title, only, not_only in ((self.t1, 'TN1 shared title', 'tn1item', 'canary'), (self.t2, CANARY + '_shared_title', 'canary', 'tn1item')):
            out = web("echo implode('|', array(pts_openbenchmarking::profile_root('private'), pts_openbenchmarking::suite_root('private'), "
                      "json_encode(array_keys(pts_openbenchmarking::read_repository_index('private')['tests'])), (new pts_test_profile('private/shared-1.0.0'))->get_title()));", aid).strip().split('|')
            self.assertEqual(out[0], self.view(aid))
            self.assertEqual(out[1], self.view(aid, 'test-suites'))
            self.assertEqual(sorted(json.loads(out[2])), sorted([only, 'shared']))
            self.assertNotIn(not_only, out[2])
            self.assertEqual(out[3], title)

    def test_official_repositories_are_the_shared_cache_whatever_the_account(self):
        shared = shared_paths()
        for aid in (self.t1, self.t2, None):
            out = web("echo pts_openbenchmarking::profile_root('pts'), ' ', pts_openbenchmarking::suite_root('pts'), ' ', pts_openbenchmarking::profile_root('local'), ' ', "
                      "pts_openbenchmarking::identifier_root('pts/sysbench-1.1.0', 'test'), ' ', pts_openbenchmarking::identifier_root('sysbench', 'test');", aid)
            self.assertEqual(out.strip(), '%s %s %s %s %s' % (shared[0], shared[1], shared[0], shared[0], shared[0]), aid)

    def test_no_account_resolves_private_to_nothing(self):
        out = web("echo pts_openbenchmarking::profile_root('private'), ' ', pts_openbenchmarking::suite_root('private'), ' ', var_export(pts_openbenchmarking::read_repository_index('private'), true), ' ', "
                  "var_export(pts_test_profile::is_test_profile('private/shared-1.0.0'), true), ' ', var_export(pts_test_suite::is_suite('private/tn1psuite-1.0.0'), true), ' ', "
                  "var_export(in_array('private', pts_openbenchmarking::linked_repositories()), true), ' ', var_export(pts_openbenchmarking::download_test_profile('private/shared-1.0.0'), true), ' ', "
                  "var_export(pts_openbenchmarking::download_test_suite('private/tn1psuite-1.0.0'), true), ' ', var_export(pts_openbenchmarking::is_test_profile_downloaded('private/shared-1.0.0'), true), ' ', "
                  "var_export(in_array('private/tn1psuite', pts_test_suites::suites_on_disk(false, true)), true), ' ', var_export(count(pts_test_suites::all_suites_cached()) >= 0, true), ' ', "
                  "t(function() { return pts_private_repo_client::index(); }), ' ', t(function() { return pts_private_repo_client::fetch('test', 'shared-1.0.0'); });")
        self.assertEqual(out.strip(), '/dev/null/benchy-no-tenant/ /dev/null/benchy-no-tenant/ NULL false false false false false false false true false false')

    def test_one_account_cannot_resolve_the_others_names(self):
        private = "function($l) { return array_values(array_filter($l, function($x) { return strpos($x, 'private/') === 0; })); }"
        out = web("$only = %(private)s; echo implode('|', array(var_export(pts_test_profile::is_test_profile('private/canary-1.0.0'), true), var_export(pts_test_suite::is_suite('private/canarypsuite-1.0.0'), true), "
                  "t(function() { return phoromatic_server::find_suite_file('%(t1)s', 'private/canarypsuite-1.0.0'); }), t(function() { return phoromatic_server::find_suite_file('%(t2)s', 'private/canarypsuite-1.0.0'); }), "
                  "is_string(phoromatic_server::find_suite_file('%(t1)s', 'private/tn1psuite-1.0.0')) ? 'own' : 'none', "
                  "t(function() { return phoromatic_private_repo::view_root('test', '%(t2)s'); }), t(function() { return phoromatic_private_repo::read_index('%(t2)s'); }), "
                  "t(function() { return phoromatic_private_repo::read_package('%(t2)s', 'test', 'canary-1.0.0'); }), "
                  "json_encode($only(pts_openbenchmarking::available_tests(false, true, true))), json_encode($only(pts_openbenchmarking::available_suites(false)))));"
                  % {'private': private, 't1': self.t1, 't2': self.t2}, self.t1).strip().split('|')
        self.assertEqual(out[:8], ['false', 'false', 'false', 'refused', 'own', 'refused', 'refused', 'refused'], out)
        self.assertEqual(sorted(json.loads(out[8])), ['private/shared-1.0.0', 'private/tn1item-1.0.0'])
        self.assertEqual(json.loads(out[9]), ['private/tn1psuite'])

    def test_an_identifier_that_walks_out_of_the_view_resolves_to_nothing(self):
        up = '../' * 5
        tries = ["private/%s%s/private-repo/view/test-profiles/private/canary-1.0.0" % (up, self.t2), "private/../private/canary-1.0.0", "private//canary-1.0.0", "private/./canary-1.0.0",
                 "private/%s%s/private-repo/view/test-suites/private/canarypsuite-1.0.0" % (up, self.t2), "private/canary-1.0.0/..", "private/..\\\\canary-1.0.0"]
        code = ("foreach(array(%s) as $i) { echo var_export(pts_openbenchmarking::identifier_root($i, 'test') === phoromatic_private_repo::NO_TENANT_ROOT, true), ' ', var_export(pts_test_profile::is_test_profile($i), true), ' ', "
                "var_export(pts_test_suite::is_suite($i), true), ' ', var_export(pts_openbenchmarking::is_test_profile_downloaded($i), true), ' ', var_export((new pts_test_profile($i))->get_title() == '', true), PHP_EOL; }"
                % ', '.join("'%s'" % t for t in tries))
        lines = web(code, self.t1).strip().splitlines()
        self.assertEqual(lines, ['true false false false true'] * len(tries))

    def test_a_reference_to_the_private_repository_means_the_uploading_account_s_own(self):
        refs = ('private/tn1item-1.0.0', 'private/tn1item', 'private/canary-1.0.0', 'private/canarypsuite', 'private/tn1psuite', 'local/x')
        mine = {self.t1: ('private/tn1item-1.0.0', 'private/tn1item', 'private/tn1psuite'), self.t2: ('private/canary-1.0.0', 'private/canarypsuite')}
        probe = ("$m = new ReflectionMethod('phoromatic_private_repo', 'reference_resolves'); $m->setAccessible(true); "
                 "foreach(array(%s) as $r) { foreach(array(%s) as $a) { echo $r, '@', $a, '=', var_export($m->invoke(null, $r, $a), true), PHP_EOL; } }" % (', '.join("'%s'" % r for r in refs), '%s'))
        # on the server's own command line (the operator names the account in the call, no request account) and as a request working for the account
        for out, accounts in ((tn.php_boot(probe % ("'%s', '%s', null" % (self.t1, self.t2))), (self.t1, self.t2, '')),
                              (web(probe % ("'%s'" % self.t1), self.t1), (self.t1,)), (web(probe % ("'%s'" % self.t2), self.t2), (self.t2,)), (web(probe % 'null'), ('',))):
            got = dict(l.split('=') for l in out.strip().splitlines() if '@' in l and '=' in l)
            self.assertEqual(len(got), len(refs) * (3 if len(accounts) == 3 else 1), out)
            for aid in accounts:
                for r in refs:
                    self.assertEqual(got['%s@%s' % (r, aid)], 'true' if r in mine.get(aid, ()) else 'false', (r, aid))

    def test_the_server_s_own_suite_lists_hold_only_the_requests_own_private_suites(self):
        for aid, own, other in ((self.t1, 'private/tn1psuite', 'canarypsuite'), (self.t2, 'private/canarypsuite', 'tn1psuite')):
            out = web("echo json_encode(array_values(pts_test_suites::suites_on_disk(false, true))), '|', json_encode(array_values(pts_test_suites::all_suites_cached(true)));", aid).strip().split('|')
            on_disk, cached = json.loads(out[0]), json.loads(out[1])
            self.assertIn(own + '-1.0.0', on_disk)
            self.assertIn(own + '-1.0.0', cached)
            self.assertEqual([x for x in on_disk + cached if x.startswith('private/') and not x.startswith(own)], [])


class SharedCacheHoldsNoPrivateContent(Base):
    def test_nothing_private_in_the_shared_cache_and_the_cleanup_is_once_and_narrow(self):
        prof, suite, scratch = shared_paths()
        self.assertEqual(tn.srv_sh("ls -d '%sprivate' '%sprivate' '%sprivate' '%sprivate.index' 2>&1 | grep -vc 'No such'" % (prof, suite, scratch, scratch)).strip(), '0')
        self.assertEqual(tn.php_boot("phoromatic_server::init_settings_store(); echo var_export(phoromatic_server::read_setting('private_repo_shared_cache_removed'), true);").strip(), "'1'")
        # an upgraded server still holds the old shared copy; the next start removes it, once, and leaves official repositories alone
        keep = ('%spts/tn3keep-1.0.0/test-definition.xml' % prof, '%sopenbenchmarking.org-keep.index' % scratch)
        plant = ('%sprivate/old-1.0.0/.DS_Store' % prof, '%sprivate/old-1.0.0/test-definition.xml' % prof, '%sprivate/old-1.0.0/suite-definition.xml' % suite, '%sprivate.index' % scratch, '%sprivate.index.failed' % scratch,
                 '%sprivate/old-1.0.0.zip' % scratch)
        for f in keep + plant:
            tn.srv_sh("mkdir -p '%s' && echo x > '%s'" % (f.rsplit('/', 1)[0], f))
        self.addCleanup(tn.srv_sh, "rm -rf '%spts/tn3keep-1.0.0' '%sopenbenchmarking.org-keep.index'" % (prof, scratch))
        tn.php_boot("phoromatic_server::init_settings_store(); phoromatic_server::save_setting('private_repo_shared_cache_removed', '0');")
        tn.php_web_boot("echo 'booted';")
        self.assertEqual(tn.srv_sh("for f in %s; do test -e \"$f\" && echo \"$f\"; done" % ' '.join("'%s'" % p for p in plant)).strip(), '')
        self.assertEqual(tn.srv_sh("for f in %s; do test -e \"$f\" && echo \"$f\"; done" % ' '.join("'%s'" % p for p in keep)).split(), list(keep))
        self.assertEqual(tn.php_boot("phoromatic_server::init_settings_store(); echo var_export(phoromatic_server::read_setting('private_repo_shared_cache_removed'), true);").strip(), "'1'")
        # once: content planted after the setting is '1' is not looked at (and nothing reads it: the leftover index is not a linked repository)
        tn.srv_sh("echo x > '%sprivate.index'" % scratch)
        self.addCleanup(tn.srv_sh, "rm -f '%sprivate.index'" % scratch)
        out = web("echo var_export(in_array('private', pts_openbenchmarking::linked_repositories()), true), ' ', var_export(pts_openbenchmarking::read_repository_index('private') === null, true);")
        self.assertEqual(out.strip(), 'false true')


class ViewFollowsTheStore(Base):
    """The view is rebuilt from the store whenever its index changes, in whichever process changed it: a new item, an overwritten version, a deletion, a view that is gone."""

    def store(self, aid, kind, name, title, overwrite=False):
        fixture.put_files({'/tmp/tn3-%s.zip' % name: fixture.repo_zip(kind, title, 'TN3_' + name)})
        out = tn.php_boot("$r = phoromatic_private_repo::store_zip('%s', 'cli', '%s', '%s', '/tmp/tn3-%s.zip', 'x', %s, 'cli'); echo isset($r['error']) ? $r['error'] : 'stored';" % (aid, kind, name, name, 'true' if overwrite else 'false'))
        self.assertEqual(out.strip(), 'stored')

    def title(self, aid, ident='private/tn3temp-1.0.0'):
        return web("echo (new pts_test_profile('%s'))->get_title(), '|', var_export(pts_test_profile::is_test_profile('%s'), true);" % (ident, ident), aid).strip()

    def test_new_overwritten_deleted_and_missing_views(self):
        aid = self.t1
        self.addCleanup(lambda: (tn.php_boot("phoromatic_private_repo::delete('%s', 'test', 'tn3temp', '1.0.0'); echo 'x';" % aid), tn.srv_sh("rm -f /tmp/tn3-*.zip")))
        self.assertEqual(self.title(aid), '|false')
        self.store(aid, 'test', 'tn3temp', 'First title')
        self.assertEqual(self.title(aid), 'First title|' + "'private/tn3temp-1.0.0'")      # (no sync call: the next request sees the index changed)
        self.store(aid, 'test', 'tn3temp', 'Second title', overwrite=True)
        self.assertEqual(self.title(aid), 'Second title|' + "'private/tn3temp-1.0.0'")
        self.assertEqual(self.title(self.t2), '|false')                                     # (the other account has none of it)
        # a view that is gone is built again on the first use
        tn.srv_sh("rm -rf '%s/accounts/%s/private-repo/view'" % (tn.STORAGE, aid))
        self.assertEqual(self.title(aid), 'Second title|' + "'private/tn3temp-1.0.0'")
        self.assertTrue(tn.srv_sh("test -f '%s/accounts/%s/private-repo/view/test-profiles/private/tn1item-1.0.0/test-definition.xml' && echo yes" % (tn.STORAGE, aid)).strip() == 'yes')
        out = tn.php_boot("phoromatic_private_repo::delete('%s', 'test', 'tn3temp', '1.0.0'); echo 'deleted';" % aid)
        self.assertEqual(out.strip(), 'deleted')
        self.assertEqual(self.title(aid), '|false')

    def test_an_index_entry_with_a_bad_name_or_no_zip_is_not_extracted(self):
        # (the view is built from what the store holds: it neither writes outside itself nor fails)
        base = '%s/accounts/%s/private-repo' % (tn.STORAGE, self.t1)
        saved = tn.srv_sh("cat %s/index.json" % base).encode()
        idx = json.loads(saved)
        idx['tests']['../../evil'] = dict(idx['tests']['tn1item'])
        idx['tests']['nozip'] = dict(idx['tests']['tn1item'], versions=['9.9.9'])
        self.addCleanup(lambda: (fixture.put_files({base + '/index.json': saved}), tn.srv_sh("rm -rf '%s/view'" % base)))
        fixture.put_files({base + '/index.json': json.dumps(idx).encode()})
        out = web("echo var_export(pts_test_profile::is_test_profile('private/tn1item-1.0.0'), true), ' ', var_export(pts_test_profile::is_test_profile('private/nozip-9.9.9'), true);", self.t1)
        self.assertEqual(out.strip(), "'private/tn1item-1.0.0' false")
        self.assertEqual(tn.srv_sh("ls %s/view/test-profiles/private/" % base).split(), ['shared-1.0.0', 'tn1item-1.0.0'])
        self.assertEqual(tn.srv_sh("ls -d %s/accounts/evil %s/evil %s/view/evil 2>/dev/null | wc -l" % (tn.STORAGE, base, base)).strip(), '0')


class PaddedPaths(Base):
    """A test or suite identifier that is really a path: a value of 200 characters or more used to skip the lookup and be loaded as a file (an absolute
    path padded with /./ or // gets past the ../ filter). Another account's extracted test, and the zip in its store, must not come back."""

    def targets(self):
        view = '%s/accounts/%s/private-repo/view/test-profiles/private/canary-1.0.0/test-definition.xml' % (tn.STORAGE, self.t2)
        zipf = '%s/accounts/%s/private-repo/tests/canary/1.0.0.zip' % (tn.STORAGE, self.t2)
        suite = '%s/accounts/%s/private-repo/view/test-suites/private/canarypsuite-1.0.0/suite-definition.xml' % (tn.STORAGE, self.t2)
        out = []
        for path in (view, zipf, suite):
            out.append(path)
            out.append(path.replace('/', '/./'))
            out.append(path.replace('/', '//'))
            out.append('/./' * 40 + path.lstrip('/'))
            out.append('/' * 200 + path.lstrip('/'))
        return out

    def test_the_two_detail_pages_return_nothing_of_the_other_accounts_files(self):
        b = tn.logins('T1')
        leaks = []
        for lvl in tn.LEVELS:
            for t in self.targets():
                for page in ('/?r_add_test_details/&tp=', '/?r_add_test_build_suite_details/&tp='):
                    body = b[lvl].get(page + t.replace('&', '%26'))[2]
                    if CANARY in body or 'PK\x03\x04' in body:
                        leaks.append((lvl, page, t[:60]))
        self.assertEqual(leaks, [])

    def test_the_constructors_and_lookups_load_no_file_named_by_a_request(self):
        out = web("$r = array(); foreach(json_decode(file_get_contents('php://stdin'), true) as $t) { "
                  "$r[] = (new pts_test_profile($t))->get_title() . '|' . var_export(pts_test_profile::is_test_profile($t), true) . '|' . var_export(pts_test_suite::is_suite($t), true) . '|' "
                  ". var_export(pts_openbenchmarking::server_identifier_ok($t), true); } echo json_encode($r);", self.t1, stdin=json.dumps(self.targets()))
        for row in json.loads(out):
            self.assertEqual(row, '|false|false|false')

    def test_real_identifiers_still_pass(self):
        out = web("echo implode(',', array_map(function($i) { return var_export(pts_openbenchmarking::server_identifier_ok($i), true); }, array('pts/sysbench-1.1.0', 'private/shared-1.0.0', 'sysbench', 'local/x', 'a/b/c', '/etc/passwd', 'pts//x', 'pts/./x', '')));", self.t1)
        self.assertEqual(out.strip(), 'true,true,true,true,false,false,false,false,false')


class DotFilesAndTheSwap(Base):
    def repo(self, aid=None):
        return '%s/accounts/%s/private-repo' % (tn.STORAGE, aid or self.t1)

    def test_a_zip_with_macos_files_leaves_nothing_behind_when_the_view_is_replaced(self):
        import io
        import zipfile
        buf = io.BytesIO()
        with zipfile.ZipFile(buf, 'w') as z:
            z.writestr('test-definition.xml', fixture.TEST_XML % {'title': 'TN3 mac title', 'tag': 'TN3_mac', 'version': '1.0.0'})
            z.writestr('install.sh', '#!/bin/sh\n')
            z.writestr('.DS_Store', 'x')
            z.writestr('__MACOSX/._install.sh', 'x')
        fixture.put_files({'/tmp/tn3-mac.zip': buf.getvalue()})
        self.addCleanup(lambda: (tn.php_boot("phoromatic_private_repo::delete('%s', 'test', 'tn3mac', '1.0.0'); echo 'x';" % self.t1), tn.srv_sh('rm -f /tmp/tn3-mac.zip')))
        out = tn.php_boot("$r = phoromatic_private_repo::store_zip('%s', 'cli', 'test', 'tn3mac', '/tmp/tn3-mac.zip', 'x', false, 'cli'); echo isset($r['error']) ? $r['error'] : 'stored';" % self.t1)
        self.assertEqual(out.strip(), 'stored', out)
        self.assertEqual(web("echo (new pts_test_profile('private/tn3mac-1.0.0'))->get_title();", self.t1).strip(), 'TN3 mac title')
        self.assertEqual(tn.srv_sh("ls -a %s/view/test-profiles/private/tn3mac-1.0.0/" % self.repo()).split().count('.DS_Store'), 1)
        # the view is replaced (the item goes): the old one goes completely, dot files and all
        tn.php_boot("phoromatic_private_repo::delete('%s', 'test', 'tn3mac', '1.0.0'); echo 'x';" % self.t1)
        web("echo pts_test_profile::is_test_profile('private/shared-1.0.0');", self.t1)
        self.assertEqual(tn.srv_sh("ls -d %s/view.old-* %s/view.new-* 2>/dev/null | wc -l" % (self.repo(), self.repo())).strip(), '0')
        self.assertEqual(tn.srv_sh("ls %s/view/test-profiles/private/" % self.repo()).split(), ['shared-1.0.0', 'tn1item-1.0.0'])
        self.assertEqual(tn.srv_sh("find %s/view -name '.DS_Store' -o -name '__MACOSX' | wc -l" % self.repo()).strip(), '0')

    def test_remove_tree_takes_dot_files_and_does_not_follow_links(self):
        tn.srv_sh("rm -rf /tmp/tn3rt /tmp/tn3keep && mkdir -p /tmp/tn3rt/a/.b /tmp/tn3keep && echo x > /tmp/tn3rt/a/.b/.c && echo x > /tmp/tn3rt/.d && ln -s /tmp/tn3keep /tmp/tn3rt/link && echo y > /tmp/tn3keep/file")
        self.addCleanup(tn.srv_sh, 'rm -rf /tmp/tn3rt /tmp/tn3keep')
        out = tn.php_boot("phoromatic_private_repo::remove_tree('/tmp/tn3rt'); echo var_export(file_exists('/tmp/tn3rt'), true);")
        self.assertEqual(out.strip(), 'false')
        self.assertEqual(tn.srv_sh("cat /tmp/tn3keep/file").strip(), 'y')

    def test_stale_build_folders_are_swept_and_fresh_ones_left(self):
        r = self.repo()
        tn.srv_sh("mkdir -p %s/view.old-aaaa/.x %s/view.new-bbbb %s/view.new-cccc && touch -d '3 hours ago' %s/view.old-aaaa %s/view.new-bbbb" % (r, r, r, r, r))
        self.addCleanup(tn.srv_sh, "rm -rf %s/view.old-* %s/view.new-*" % (r, r))
        tn.srv_sh("rm -f %s/view/stamp" % r)
        web("echo pts_test_profile::is_test_profile('private/shared-1.0.0');", self.t1)
        self.assertEqual(sorted(tn.srv_sh("ls %s | grep '^view'" % r).split()), ['view', 'view.new-cccc'])

    def test_concurrent_first_uses_build_one_good_view(self):
        import concurrent.futures
        r = self.repo()
        tn.srv_sh("rm -rf %s/view" % r)
        code = "echo (new pts_test_profile('private/shared-1.0.0'))->get_title();"
        with concurrent.futures.ThreadPoolExecutor(6) as ex:
            titles = list(ex.map(lambda _: web(code, self.t1).strip(), range(6)))
        # (a request that reads in the moment between the two renames of a swap finds no view; with no view to replace, as here, there is no such moment)
        self.assertEqual(titles, ['TN1 shared title'] * 6)
        self.assertEqual(tn.srv_sh("ls -d %s/view.old-* %s/view.new-* 2>/dev/null | wc -l" % (r, r)).strip(), '0')


class WebPages(Base):
    """The pages a tenant uses to find, show, choose and schedule tests: each account sees its own private tests and none of the other's."""

    @classmethod
    def setUpClass(cls):
        super().setUpClass()
        cls.a1 = tn.logins('T1')
        cls.a2 = tn.Browser().login('tn2admin')

    PAGES = ('/?tests', '/?tests/private/canary-1.0.0', '/?tests/private/shared-1.0.0', '/?r_add_test_details/&tp=private/canary-1.0.0', '/?r_add_test_details/&tp=private/shared-1.0.0',
             '/?r_add_test_build_suite_details/&tp=private/canary-1.0.0', '/?r_add_test_build_suite_details/&tp=private/shared-1.0.0', '/?build_suite', '/?build_suite/private/canarypsuite-1.0.0',
             '/?r_basic_suite_details/&ts=private/canarypsuite-1.0.0', '/?search&search=canary', '/?search&search=shared', '/?schedules', '/?schedules/%d' % tn.SHARED_SCHEDULE,
             '/?benchmark', '/?sched', '/?private_repository')

    def test_the_account_s_own_pages_show_its_own_private_tests(self):
        # the controls: a clean result for the other account means something
        own1 = self.a1[1].get('/?tests/private/tn1item-1.0.0')[2]
        self.assertIn('TN1 repo test title', own1)
        self.assertIn('TN1 shared title', self.a1[1].get('/?r_add_test_details/&tp=private/shared-1.0.0')[2])
        self.assertIn('TN1 repo suite title', self.a1[1].get('/?r_basic_suite_details/&ts=private/tn1psuite-1.0.0')[2])
        self.assertIn(CANARY + '_shared_title', self.a2.get('/?r_add_test_details/&tp=private/shared-1.0.0')[2])
        self.assertIn(CANARY + '_repo_test_title', self.a2.get('/?tests/private/canary-1.0.0')[2])
        self.assertIn(CANARY + '_repo_suite_title', self.a2.get('/?r_basic_suite_details/&ts=private/canarypsuite-1.0.0')[2])
        listing = self.a1[1].get('/?tests')[2] + self.a1[1].get('/?build_suite')[2]
        self.assertIn('private/tn1item', listing)

    def test_no_page_of_one_account_shows_the_other_s_private_tests_at_any_level(self):
        leaks = []
        for lvl in tn.LEVELS:
            for p in self.PAGES:
                body = self.a1[lvl].get(p)[2]
                if CANARY in body:
                    leaks.append((lvl, p, body[max(0, body.find(CANARY) - 80):body.find(CANARY) + 80]))
        self.assertEqual(leaks, [])
        # and the other way round, for the pages that list
        for p in self.PAGES:
            body = self.a2.get(p)[2]
            self.assertNotIn('TN1 repo', body, p)
            self.assertNotIn('TN1 shared', body, p)
            self.assertNotIn('tn1item', body, p)

    def test_a_schedule_can_take_only_the_account_s_own_private_suite(self):
        b = self.a1[1]
        sched = '/?schedules/%d' % tn.SHARED_SCHEDULE
        count = lambda where='1': tn.sql("SELECT COUNT(*) AS n FROM phoromatic_schedules_tests WHERE AccountID='%s' AND %s" % (self.t1, where))[0]['n']
        before = count()
        body = b.post(sched, {'suite_add': 'private/canarypsuite-1.0.0', 'token_submit': b.token(sched)})[2]
        self.assertNotIn(CANARY, body)
        self.assertEqual(count(), before)
        self.assertEqual(count("TestProfile LIKE '%canary%'"), 0)
        self.addCleanup(tn.sql_exec, "DELETE FROM phoromatic_schedules_tests WHERE AccountID='%s' AND ScheduleID=%d AND TestProfile LIKE 'private/tn1item%%'" % (self.t1, tn.SHARED_SCHEDULE))
        b.post(sched, {'suite_add': 'private/tn1psuite-1.0.0', 'token_submit': b.token(sched)})
        self.assertGreater(count(), before, 'the control: the account\'s own private suite adds its tests')
        self.assertGreater(count("TestProfile LIKE 'private/tn1item%'"), 0)


class ClientEndpoints(Base):
    """v1: the private repository index and fetch of the account named in the request, and the task a client is given. v2 has no private-repository surface at all."""

    @classmethod
    def setUpClass(cls):
        super().setUpClass()
        tn.reset_v1_limits()
        cls.m1 = tn.approved_machine(cls.t1)
        cls.m2 = tn.approved_machine(cls.t2)

    def test_index_and_fetch_serve_the_requesting_account_only(self):
        i1 = tn.v1('private_repo_index', self.t1, self.m1)
        i2 = tn.v1('private_repo_index', self.t2, self.m2)
        self.assertEqual(sorted(i1['index']['tests']), ['shared', 'tn1item'])
        self.assertEqual(sorted(i2['index']['tests']), ['canary', 'shared'])
        for aid, m, ident, ok in ((self.t1, self.m1, 'canary-1.0.0', False), (self.t1, self.m1, 'tn1item-1.0.0', True), (self.t2, self.m2, 'tn1item-1.0.0', False), (self.t2, self.m2, 'canary-1.0.0', True)):
            r = tn.v1('private_repo_fetch', aid, m, kind='test', id=ident)
            self.assertEqual('zip' in r, ok, (aid, ident, r))
            if ok:
                self.assertEqual(tn.sha1(base64.b64decode(r['zip'])), r['sha1'])
        # the same name in both accounts is each account's own file
        z1 = base64.b64decode(tn.v1('private_repo_fetch', self.t1, self.m1, kind='test', id='shared-1.0.0')['zip'])
        z2 = base64.b64decode(tn.v1('private_repo_fetch', self.t2, self.m2, kind='test', id='shared-1.0.0')['zip'])
        self.assertNotEqual(z1, z2)
        self.assertEqual(z1[:2], b'PK')

    def test_a_machine_of_one_account_asking_for_the_other_gets_no_private_repository(self):
        for r in (tn.v1('private_repo_index', self.t2, self.m1), tn.v1('private_repo_fetch', self.t2, self.m1, kind='test', id='canary-1.0.0')):
            self.assertNotIn('index', r)
            self.assertNotIn('zip', r)
            self.assertNotIn(CANARY, json.dumps(r))

    def test_a_ticket_for_a_private_suite_is_issued_with_the_account_s_own_suite_and_never_the_others(self):
        sysid = tn.sql("SELECT SystemID FROM phoromatic_systems WHERE AccountID='%s' AND MachineSelfID='%s'" % (self.t1, self.m1))[0]['SystemID']
        tid = 990000 + int(time.time()) % 9000
        ins = ("INSERT INTO phoromatic_benchmark_tickets (AccountID, TicketID, Title, Description, State, TicketIssueTime, SuiteToRun, ResultIdentifier, EnvironmentVariables, RunTargetSystems, RunTargetGroups, LastModifiedOn) "
               "VALUES ('%s', %d, 'TN3 ticket', 'd', 1, %d, '%s', 'tn3', '', '%s', '', '2026-11-14 00:00:00')")
        self.addCleanup(tn.sql_exec, "DELETE FROM phoromatic_benchmark_tickets WHERE AccountID='%s' AND TicketID IN (%d, %d)" % (self.t1, tid, tid + 1))
        # the other account's private suite: the ticket is not issued
        tn.sql_exec(ins % (self.t1, tid, time.time() - 60, 'private/canarypsuite-1.0.0', sysid))
        r = tn.v1('start', self.t1, self.m1)
        self.assertNotIn(CANARY, json.dumps(r))
        self.assertNotEqual(r.get('task'), 'benchmark', r)
        tn.sql_exec("DELETE FROM phoromatic_benchmark_tickets WHERE AccountID='%s' AND TicketID=%d" % (self.t1, tid))
        # the control: its own private suite is issued, with the account's own test in it
        tn.sql_exec(ins % (self.t1, tid + 1, time.time() - 60, 'private/tn1psuite-1.0.0', sysid))
        r = tn.v1('start', self.t1, self.m1)
        self.assertEqual(r.get('task'), 'benchmark', r)
        self.assertIn('tn1item', json.dumps(r))

    def test_v2_has_no_private_repository_surface(self):
        out = tn.srv_sh("grep -rli 'private' /bench/pts-core/phoromatic/public_html/api/ /bench/pts-core/objects/phoromatic/phoromatic_api_v2*.php | wc -l").strip()
        self.assertEqual(out, '0')


class RollbackToTheShippedRelease(Base):
    def test_the_store_is_untouched_by_the_views_so_the_old_release_still_serves_it(self):
        # 0.9.13 reads accounts/<AID>/private-repo/{index.json,tests,suites}: the view is a new sibling folder and nothing in the store changed
        for aid in (self.t1, self.t2):
            listing = tn.srv_sh("ls -a '%s/accounts/%s/private-repo' | tr '\\n' ' '" % (tn.STORAGE, aid)).split()
            self.assertIn('index.json', listing)
            self.assertIn('tests', listing)
            self.assertIn('view', listing)
            self.assertEqual(tn.srv_sh("find '%s/accounts/%s/private-repo/tests' -name '*.zip' | wc -l" % (tn.STORAGE, aid)).strip(), '2')


if __name__ == '__main__':
    unittest.main()
