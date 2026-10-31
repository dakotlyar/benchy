"""PHP 8.5 notices (Benchy 0.9.1, L1): the systems, result and other pages print no Deprecated / Warning / Notice for systems whose
optional columns are NULL (LocalIP, Software, ...). Run through tests/private-repo/run.sh (part of http/all and benches).
"""
import re

from bench_helpers import make_bench, new_system, schedule, start, today, upload
from test_private_repo_http import sql
from test_private_repo_web import WebBase

PHP_NOTICE = re.compile(r'(<b>)?(Fatal error|Warning|Notice|Deprecated)(</b>)?: [^\n]*')


class TestNoPhpNotices(WebBase):
    def test_pages_with_null_columns_print_no_php_notice(self):
        msi, sid, bid, s1 = make_bench(self.admin, cls='cpu')
        msi2, sid2 = new_system(state=0)
        sched = schedule(sid)
        start(msi)
        uid = upload(msi, sched=sched, ts=today())
        pprid = sql("SELECT PPRID FROM phoromatic_results WHERE UploadID=%d" % int(uid))[0]['PPRID']
        pages = ['/?main', '/?systems', '/?systems/%s' % sid, '/?systems/%s' % sid2, '/?results', '/?result/%s' % pprid, '/?schedules', '/?benchmark', '/?settings',
                 '/?users', '/?benches', '/?benches/%d' % bid, '/?benches/components', '/?benches/compare', '/?systems/groups']
        found = []
        for page in pages:
            status, _, body = self.admin.get(page)
            for m in PHP_NOTICE.finditer(body):
                found.append('%s -> %s' % (page, re.sub(r'<[^>]+>', '', m.group(0))[:200]))
        self.assertEqual(sorted(set(found)), [])


if __name__ == '__main__':
    import unittest
    unittest.main()
