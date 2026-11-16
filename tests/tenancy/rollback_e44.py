#!/usr/bin/env python3
"""E4.4 C2 rollback phases (driven by rollback_e44.sh; the server container is the one TN_SRV/TN_PORT name). State between phases is in TN_STATE.

new-first   this checkout (a key table of throw-away test keys): an account and users, both catalog tiers imported; what the catalog tables and files hold is recorded
old         the release before the catalog (OLD): it starts on the schema-63 database and leaves user_version alone, signs people in, makes a user, and leaves the catalog tables
            and files exactly as they were (it does not know them)
new-again   this checkout again: the schema is still 63, the catalog is byte for byte what it was, both tiers are served, importing the first bundle again is "unchanged",
            the next release imports, and the user the old release made signs in
"""
import hashlib
import json
import subprocess
import sys

import tn

fails = 0
AID = 'RBE44A'
TABLES = ('state', 'tiers', 'ledger', 'revoked', 'entries')


def check(what, ok, detail=''):
    global fails
    print('  %s  %s%s' % ('PASS' if ok else 'FAIL', what, '' if ok else ' :: ' + str(detail)[:300]))
    if not ok:
        fails += 1


def logs_in(name, password=tn.PW):
    b = tn.Browser()
    b.get('/')
    return 'Log-In Successful' in b.post('/?login', {'username': name, 'password': password})[2]


def catalog_snapshot():
    rows = {t: hashlib.md5(json.dumps(tn.sql('SELECT * FROM phoromatic_catalog_%s ORDER BY rowid' % t), sort_keys=True).encode()).hexdigest() for t in TABLES}
    files = tn.srv_php('foreach(glob("%s/catalog/{signed,packs}/*", GLOB_BRACE) as $f) echo basename($f), " ", md5_file($f), " ", substr(sprintf("%%o", fileperms($f)), -4), "\\n";' % tn.STORAGE)
    return {'rows': rows, 'files': sorted(files.split('\n'))}


def version():
    return tn.sql('PRAGMA user_version')[0]['user_version']


def new_first():
    r = subprocess.run(['docker', 'exec', '-i', tn.SRV, 'sh', '/bench/benchy', 'server-account-create', '--user', 'rbeadmin', '--email', 'rbe@example.com', '--account-id', AID], input=tn.PW + '\n', capture_output=True, text=True)
    check('this checkout makes the account', 'Account created' in r.stdout, r.stdout + r.stderr)
    admin = tn.Browser().login('rbeadmin')
    tn.make_user(admin, 'rbeuser', 3)
    check('the schema is 63 and the catalog tables are empty', version() == 63 and tn.sql('SELECT COUNT(*) AS n FROM phoromatic_catalog_tiers')[0]['n'] == 0)
    for b in ('a', 'b'):
        out = tn.cli('server-catalog-import', '/bundles/%s.zip' % b)
        check('bundle %s is imported: %s' % (b, out.strip().splitlines()[-1] if out.strip() else ''), 'Imported the' in out, out)
    out = tn.cli('server-catalog-status')
    check('both tiers are served', 'Free catalog (public): served' in out and 'Non-free catalog (private): served' in out, out)
    snap = catalog_snapshot()
    check('the catalog holds two tiers, three entries, two signed files and three packs', len(snap['files']) == 6 and tn.sql('SELECT COUNT(*) AS n FROM phoromatic_catalog_entries')[0]['n'] == 3, snap)
    check('everyone signs in', logs_in('rbeadmin') and logs_in('rbeuser'))
    tn.save_state({'snap': snap, 'audit': tn.sql("SELECT COUNT(*) AS n FROM phoromatic_server_audit WHERE Action = 'catalog_imported'")[0]['n']})


def old():
    st = tn.state()
    check('the old release starts on the schema-63 database and leaves user_version alone', version() == 63, version())
    check('users sign in on it', logs_in('rbeadmin') and logs_in('rbeuser'))
    tn.make_user(tn.Browser().login('rbeadmin'), 'rbeoldmade', 3)
    check('and it makes a user', logs_in('rbeoldmade'))
    check('the catalog tables and files are exactly as they were', catalog_snapshot() == st['snap'], catalog_snapshot())


def new_again():
    st = tn.state()
    check('the schema is still 63 and the catalog is byte for byte what it was', version() == 63 and catalog_snapshot() == st['snap'])
    out = tn.cli('server-catalog-status')
    check('both tiers are served again', 'Free catalog (public): served' in out and 'Non-free catalog (private): served' in out, out)
    out = tn.cli('server-catalog-import', '/bundles/a.zip')
    check('importing the first bundle again says unchanged', 'already imported' in out and catalog_snapshot() == st['snap'], out)
    n = tn.sql("SELECT COUNT(*) AS n FROM phoromatic_server_audit WHERE Action = 'catalog_imported'")[0]['n']
    check('and writes one more audit row', n == st['audit'] + 1, n)
    out = tn.cli('server-catalog-import', '/bundles/c.zip')
    check('the next release imports', 'sequence 2 (was generation 1, sequence 1). 1 test added' in out, out)
    check('the user the old release made signs in, and so does everyone else', logs_in('rbeoldmade') and logs_in('rbeadmin') and logs_in('rbeuser'))


{'new-first': new_first, 'old': old, 'new-again': new_again}[sys.argv[1]]()
print('FAILURES %d' % fails)
