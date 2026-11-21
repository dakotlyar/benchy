#!/usr/bin/env python3
"""NC3 migration phases (driven by rollback_nc3.sh; the server container is the one TN_SRV/TN_PORT name). State between phases is in TN_STATE.

old-first   the release before NC3 (schema 67): two accounts, an enrolled and approved agent of the first, the public and private catalogs; what the catalog tables hold is recorded
new-first   this checkout: schema 68; every catalog row and every column kept and the tiers table has no CHECK left (EC-NC3); `private` granted to both accounts and nothing else (EC-NC4);
            the agent still sees public and private; an account made now sees public only; the audit row and the backup; running the step again changes nothing
old-again   the release before NC3 on the migrated database: it serves public and private to that agent and leaves the tables and the schema number alone
new-again   this checkout: a named catalog imports into the rebuilt table, the first account does not see it until it is granted, the new account never does
"""
import hashlib
import json
import os
import re
import sys
import time

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'catalog'))
import serve_e44 as sv  # noqa: E402
import tn  # noqa: E402

fails = 0
A, B, C = 'MIGNCA', 'MIGNCB', 'MIGNCC'
OLD_COLUMNS = 'Tier, Generation, Sequence, SignedSHA256, KeyID, Issued, MinGeneration, TestCount, SuiteCount, RevokedCount, BundleSHA256, ImportedOn, ImportedBy, ImportedVia, ImportedAccount'
NAMED = ('catalog_v1', 'catalog_named_v1', 'cancel', 'json_args', 'run_lock', 'agent')


def check(what, ok, detail=''):
    global fails
    print('  %s  %s%s' % ('PASS' if ok else 'FAIL', what, '' if ok else ' :: ' + str(detail)[:500]))
    if not ok:
        fails += 1


def version():
    return tn.sql('PRAGMA user_version')[0]['user_version']


def snapshot():
    rows = tn.sql('SELECT %s FROM phoromatic_catalog_tiers ORDER BY Tier' % OLD_COLUMNS)
    other = {t: hashlib.md5(json.dumps(tn.sql('SELECT * FROM phoromatic_catalog_%s ORDER BY rowid' % t), sort_keys=True).encode()).hexdigest() for t in ('state', 'ledger', 'revoked', 'entries')}
    files = tn.srv_php('foreach(glob("%s/catalog/{signed,packs}/*", GLOB_BRACE) as $f) echo basename($f), " ", md5_file($f), "\\n";' % tn.STORAGE)
    return {'tiers': rows, 'other': other, 'files': sorted(f for f in files.split('\n') if f)}


def hello(s, caps=NAMED):
    st, body, _ = sv.jcall('/api/v2/agent/hello', sv.hello_body(s['mid'], caps=caps), s['token'])
    assert st == 200, (st, body)
    return [t['tier'] for t in body.get('catalog', {}).get('tiers', [])]


def pack_status(token, ident):
    # (a pack route has no file extension, so the plain PHP server of this check routes it; the .signed index routes need the launcher's router and are checked by tests/catalog/named.sh)
    packs = {r['ID']: r['PackSHA256'] for r in tn.sql('SELECT ID, PackSHA256 FROM phoromatic_catalog_entries')}
    return sv.raw('GET', '/api/v2/catalog/packs/' + packs[ident], token)[0]


def old_first():
    for aid, user in ((A, 'migadmin'), (B, 'migother')):
        r = tn.srv_sh('sh /bench/benchy server-account-create --user %s --email %s@example.com --account-id %s' % (user, user, aid), stdin=tn.PW + '\n')
        check('account %s made' % aid, 'Account created' in r, r)
    sv.admin('allow-http', 'on')
    check('the release before NC3 is at schema 67', version() == 67, version())
    for b in ('pub1', 'priv1'):
        out = tn.cli('server-catalog-import', '/bundles/%s.zip' % b)
        check('bundle %s imported' % b, 'Imported the' in out, out)
    token, sid, mid = sv.enroll(A, 'mig-a', 'a')
    s = {'token': token, 'sid': sid, 'mid': mid}
    check('an agent of the first account sees public and private', sorted(hello(s, ('catalog_v1', 'cancel', 'json_args', 'run_lock', 'agent'))) == ['private', 'public'])
    check('the old table has its CHECK (that is what the step takes away)', 'CHECK (Tier IN' in tn.sql("SELECT sql FROM sqlite_master WHERE name = 'phoromatic_catalog_tiers'")[0]['sql'])
    snap = snapshot()
    check('two tiers recorded', len(snap['tiers']) == 2 and len(snap['files']) == 6, snap['files'])
    tn.save_state({'agent': s, 'snap': snap})


def new_first():
    st = tn.state()
    s = st['agent']
    check('the first request on this checkout runs the step: schema 68', tn.Browser().get('/')[0] == 200 and version() == 68, version())
    snap = snapshot()
    check('every catalog row and every old column is kept, and the pairs with them (EC-NC3)', snap['tiers'] == st['snap']['tiers'] and len(snap['tiers']) == 2, (snap['tiers'], st['snap']['tiers']))
    check('  the other catalog tables and every stored file are untouched', snap['other'] == st['snap']['other'] and snap['files'] == st['snap']['files'])
    cols = tn.sql('PRAGMA table_info(phoromatic_catalog_tiers)')
    check('  the tiers table has no CHECK on the name any more and has a Title column, null for the rows that were there', 'CHECK (Tier IN' not in tn.sql("SELECT sql FROM sqlite_master WHERE name = 'phoromatic_catalog_tiers'")[0]['sql']
          and 'Title' in [c['name'] for c in cols] and all(r['Title'] is None for r in tn.sql('SELECT Title FROM phoromatic_catalog_tiers')), cols)
    check('  and still takes only cli, web or package as the way it was imported', 'CHECK (ImportedVia IN' in tn.sql("SELECT sql FROM sqlite_master WHERE name = 'phoromatic_catalog_tiers'")[0]['sql'])
    g = tn.sql('SELECT * FROM phoromatic_catalog_grants ORDER BY AccountID')
    check('private is granted to both existing accounts, by the migration, and nothing else is (EC-NC4)', [(r['AccountID'], r['Catalog'], r['GrantedBy']) for r in g] == [(A, 'private', 'migration'), (B, 'private', 'migration')], (g, tn.sql('SELECT AccountID, State, PurgedOn FROM phoromatic_accounts')))
    check('  public has no row (it is always granted)', not [r for r in g if r['Catalog'] == 'public'])
    a = tn.sql("SELECT * FROM phoromatic_server_audit WHERE Action = 'migration' ORDER BY AuditID DESC LIMIT 1")
    d = json.loads(a[0]['Detail']) if a else {}
    check('one migration audit row names the step, the catalogs kept and the grants made, and the backup', d.get('to') == 68 and d.get('from') == 67 and d.get('catalogs_kept') == 2 and d.get('private_granted') == 2 and str(d.get('backup')).startswith('phoromatic-pre-68-'), a)
    b = tn.srv_sh("cd %s/backups && stat -c '%%a %%n' phoromatic-pre-68-*.db" % tn.STORAGE).split('\n')
    check('  the backup is there, mode 0600', len([x for x in b if x.strip()]) == 1 and b[0].startswith('600 '), b)
    check('an agent of the first account still sees public and private', sorted(hello(s)) == ['private', 'public'])
    check('  and gets a private pack as before', pack_status(s['token'], 'benchy/paid') == 200)
    st_c, body_c, _ = (None, None, None)
    r = tn.srv_sh('sh /bench/benchy server-account-create --user migthird --email migthird@example.com --account-id %s' % C, stdin=tn.PW + '\n')
    check('an account made now is a new tenant', 'Account created' in r and not tn.sql("SELECT 1 FROM phoromatic_catalog_grants WHERE AccountID = '%s'" % C))
    token, sid, mid = sv.enroll(C, 'mig-c', 'c')
    c = {'token': token, 'sid': sid, 'mid': mid}
    check('  it sees public only, and the private pack, asked for by its hash, is 404 (the first account gets it)', hello(c) == ['public'] and pack_status(token, 'benchy/paid') == 404 and pack_status(s['token'], 'benchy/paid') == 200)
    before = (tn.sql('SELECT * FROM phoromatic_catalog_grants ORDER BY AccountID, Catalog'), snapshot())
    # the step again: a version put back changes nothing (no second set of grants, no lost rows)
    tn.sql_exec('PRAGMA user_version = 67')
    time.sleep(1.1)
    check('the step run again after a version put back: schema 68', tn.Browser().get('/')[0] == 200 and version() == 68, version())
    after = (tn.sql('SELECT * FROM phoromatic_catalog_grants ORDER BY AccountID, Catalog'), snapshot())
    check('  it grants nothing new and keeps every row (an account made after the first run is seeded too: its private grant is the one new row)',
          [r for r in after[0] if r['AccountID'] != C] == [r for r in before[0] if r['AccountID'] != C] and after[1] == before[1], (before[0], after[0]))
    # C was made after the step and seeded by this second run: take the row away again, as it is the new tenant of the rest of the checks
    tn.sql_exec("DELETE FROM phoromatic_catalog_grants WHERE AccountID = '%s'" % C)
    check('the second run also left the other accounts\' rows as they were (GrantedOn unchanged)', [(r['AccountID'], r['GrantedOn']) for r in after[0] if r['AccountID'] != C] == [(r['AccountID'], r['GrantedOn']) for r in before[0] if r['AccountID'] != C])
    tn.save_state({**st, 'c': c, 'snap2': snapshot(), 'version': version()})


def old_again():
    st = tn.state()
    s = st['agent']
    check('the release before NC3 starts on the migrated database and leaves the schema number alone', version() == 68, version())
    check('it serves that agent public and private, and a private pack', sorted(hello(s, ('catalog_v1', 'cancel', 'json_args', 'run_lock', 'agent'))) == ['private', 'public'] and pack_status(s['token'], 'benchy/paid') == 200)
    check('the catalog tables and files are as they were (it does not know the new column or table)', snapshot() == st['snap2'])
    check('it signs people in', tn.Browser().login('migadmin') is not None)


def new_again():
    st = tn.state()
    s, c = st['agent'], st['c']
    check('this checkout again: schema 68 and the catalog as it was', version() == 68 and snapshot() == st['snap2'], version())
    out = tn.cli('server-catalog-import', '/bundles/game1.zip')
    check('a named catalog imports into the rebuilt table', 'Imported the Games catalog' in out and 'turn it on for that account' in out, out)
    row = tn.sql("SELECT Tier, Title FROM phoromatic_catalog_tiers WHERE Tier = 'gaming'")
    check('  with its title', row == [{'Tier': 'gaming', 'Title': 'Games'}], row)
    check('the first account does not see it until it is granted (private was kept by the migration, gaming is a new catalog)', hello(s) == ['public', 'private'])
    check('  and its pack is a 404 for it and for the new account', pack_status(s['token'], 'benchy/game') == 404 and pack_status(c['token'], 'benchy/game') == 404)
    tn.sql_exec("INSERT INTO phoromatic_catalog_grants (AccountID, Catalog, GrantedBy, GrantedOn) VALUES ('%s', 'gaming', 'qc', '2026-01-01 00:00:00')" % A)
    check('granted, the first account sees it, with its title; the new account still does not', hello(s) == ['public', 'private', 'gaming'] and hello(c) == ['public'])
    check('  and the pack is served to the first account now, and still not to the new one', pack_status(s['token'], 'benchy/game') == 200 and pack_status(c['token'], 'benchy/game') == 404)


def old_last():
    st = tn.state()
    s, c = st['agent'], st['c']
    plain = ('catalog_v1', 'cancel', 'json_args', 'run_lock', 'agent')
    check('the release before NC3 starts on the database that holds a named catalog and leaves schema 68 alone', version() == 68, version())
    # What actually happens (documented here, and in the release notes): 0.14.0 serves every catalog the server holds to every tenant. It ignores the grants.
    check('it announces the named catalog to the first account (which has it granted)', sorted(hello(s, plain)) == ['gaming', 'private', 'public'])
    check('  and to the account that was made after the step and has nothing granted: it sees private and gaming too, so remove named catalogs before rolling back', sorted(hello(c, plain)) == ['gaming', 'private', 'public'], hello(c, plain))
    check('  and serves it the gaming pack', pack_status(c['token'], 'benchy/game') == 200)


if __name__ == '__main__':
    {'old-first': old_first, 'new-first': new_first, 'old-again': old_again, 'new-again': new_again, 'old-last': old_last}[sys.argv[1]]()
    print('FAILURES %d' % fails)
