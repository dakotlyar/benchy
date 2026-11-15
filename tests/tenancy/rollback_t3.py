#!/usr/bin/env python3
"""T3 rollback phases (driven by rollback_t3.sh; the server container is the one TN_SRV/TN_PORT name). State between phases is in TN_STATE.

old-first   the release before T3: account RBAAAA, a repository token, an approved machine, a private test uploaded through the client endpoint; its test page shows it
new-first   this checkout on that data: the page shows it, the shared copy is gone, the view exists, a second upload works, the store is what the old release wrote and grew
old-again   the old release again: the store is intact and the same index, its test page works (it rebuilds its shared copy), a third upload through it works
new-again   this checkout again: all three tests listed from the account's store; a planted shared copy is not served; the old release's leftover is ignored, not trusted
"""
import base64
import hashlib
import json
import re
import sys

import fixture
import tn

AID = 'RBAAAA'
fails = 0


def check(what, ok, detail=''):
    global fails
    print('  %s  %s%s' % ('PASS' if ok else 'FAIL', what, '' if ok else ' :: ' + str(detail)[:300]))
    if not ok:
        fails += 1


def paths():
    return tn.srv_php("define('PTS_MODE','CLIENT');define('PTS_AUTO_LOAD_OBJECTS',true);define('PHOROMATIC_SERVER',true);$_SERVER['argv']=array('x');include('/bench/pts-core/pts-core.php');pts_core::init();"
                      "echo PTS_TEST_PROFILE_PATH, ' ', PTS_OPENBENCHMARKING_SCRATCH_PATH;").split()


def upload(msi, token, name, title):
    z = fixture.repo_zip('test', title, 'RB_' + name)
    return tn.v1('private_repo_upload', AID, msi, kind='test', name=name, zip=base64.b64encode(z).decode(), zip_sha1=hashlib.sha1(z).hexdigest(), commit_description='rb', overwrite='0', rt=token)


def page(b, name):
    return b.get('/?tests/private/%s-1.0.0' % name)[2]


def index_names():
    return sorted(json.loads(tn.srv_sh("cat %s/accounts/%s/private-repo/index.json" % (tn.STORAGE, AID)))['tests'])


def old_first():
    tn.register('rbadmin', AID)
    out = tn.cli('private-repo-token-create', 'rb', '--tenant', AID)
    token = re.search(r'bpr_[A-Za-z0-9_-]{43}', out).group(0)
    msi = tn.approved_machine(AID)
    tn.save_state({'token': token, 'msi': msi})
    r = upload(msi, token, 'rbone', 'RB one title')
    check('the old release stores the upload', 'upload' in r, r)
    b = tn.Browser().login('rbadmin')
    check('its test page shows the test', 'RB one title' in page(b, 'rbone'))
    prof, scratch = paths()
    check('the old release keeps its copy in the shared cache', tn.srv_sh("test -f %sprivate/rbone-1.0.0/test-definition.xml && echo yes" % prof).strip() == 'yes')


def new_first():
    st = tn.state()
    b = tn.Browser().login('rbadmin')
    check('the test page shows the test (from the account\'s own view)', 'RB one title' in page(b, 'rbone'))
    prof, scratch = paths()
    check('the shared copy is gone', tn.srv_sh("ls -d %sprivate %sprivate.index %sprivate 2>/dev/null | wc -l" % (prof, scratch, scratch)).strip() == '0')
    check('the account has its view', tn.srv_sh("test -f %s/accounts/%s/private-repo/view/test-profiles/private/rbone-1.0.0/test-definition.xml && echo yes" % (tn.STORAGE, AID)).strip() == 'yes')
    r = upload(st['msi'], st['token'], 'rbtwo', 'RB two title')
    check('an upload works', 'upload' in r, r)
    check('...and its test page shows it', 'RB two title' in page(b, 'rbtwo'))
    check('the store lists both', index_names() == ['rbone', 'rbtwo'], index_names())
    f = tn.v1('private_repo_fetch', AID, st['msi'], kind='test', id='rbone-1.0.0')
    check('the client endpoint serves the old release\'s upload', 'zip' in f and hashlib.sha1(base64.b64decode(f['zip'])).hexdigest() == f['sha1'], f)


def old_again():
    st = tn.state()
    check('the store is intact', index_names() == ['rbone', 'rbtwo'], index_names())
    b = tn.Browser().login('rbadmin')
    body = page(b, 'rbone') + page(b, 'rbtwo')
    check('the old release\'s test pages work again (it builds its shared copy on a miss)', 'RB one title' in body and 'RB two title' in body, re.sub(r'\s+', ' ', body)[:200])
    r = upload(st['msi'], st['token'], 'rbthree', 'RB three title')
    check('an upload through the old release works', 'upload' in r, r)
    check('the store lists all three', index_names() == ['rbone', 'rbthree', 'rbtwo'], index_names())


def new_again():
    prof, scratch = paths()
    b = tn.Browser().login('rbadmin')
    body = ''.join(page(b, n) for n in ('rbone', 'rbtwo', 'rbthree'))
    check('all three tests are shown', all(t in body for t in ('RB one title', 'RB two title', 'RB three title')), re.sub(r'\s+', ' ', body)[:200])
    # a shared copy (the old release made one again): the account's own store decides, and a planted one that is not in the store is not served
    tn.srv_sh("mkdir -p %sprivate/zzplanted-1.0.0 && cp %sprivate/rbone-1.0.0/test-definition.xml %sprivate/zzplanted-1.0.0/ 2>/dev/null; true" % (prof, prof, prof))
    check('the planted shared test is not served', 'RB one title' not in page(b, 'zzplanted'))
    tn.srv_sh("rm -rf %sprivate" % prof)


{'old-first': old_first, 'new-first': new_first, 'old-again': old_again, 'new-again': new_again}[sys.argv[1]]()
print('FAILURES %d' % fails)
