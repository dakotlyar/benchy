#!/usr/bin/env python3
"""T6 rollback phases (driven by rollback_t6.sh; the server container is the one TN_SRV/TN_PORT name). State between phases is in TN_STATE.

old-first   the release before T6 (OLD): the root password, an account made with server-account-create and users made on its Users page; everyone signs in
new-first   this checkout on that data: every sign-in gives a user a modern hash (and the root admin) and leaves the legacy hash exactly as it was; a user made here and one whose
            password this checkout changed have both hashes
old         OLD again: everyone signs in, including the users whose passwords this checkout set; it changes one user's password (the password page) and the root password
            (set-root-admin-password), which write only the legacy hash, and makes a user
new-again   this checkout again: the passwords OLD set work and the passwords before them are refused (for a user and for root), each with a fresh modern hash; the user OLD made
            signs in; nobody else's password changed
"""
import hashlib
import subprocess
import sys

import tn

fails = 0
AID = 'RBSIXA'
ROOT2 = 'rootpass-by-old-2'
NEWPW = 'pw-set-by-old-3'
HERE_PW = 'pw-set-here-4'


def check(what, ok, detail=''):
    global fails
    print('  %s  %s%s' % ('PASS' if ok else 'FAIL', what, '' if ok else ' :: ' + str(detail)[:300]))
    if not ok:
        fails += 1


def sha(t):
    return hashlib.sha256(t.encode()).hexdigest()


def row(name):
    return tn.sql("SELECT u.*, a.Salt FROM phoromatic_users u JOIN phoromatic_accounts a ON a.AccountID = u.AccountID WHERE u.UserName='%s'" % name)[0]


def columns():
    return [r['name'] for r in tn.sql('PRAGMA table_info(phoromatic_users)')]


def logs_in(name, password=tn.PW):
    b = tn.Browser()
    b.get('/')
    return 'Log-In Successful' in b.post('/?login', {'username': name, 'password': password})[2]


def root_logs_in(password):
    b = tn.Browser()
    b.get('/')
    b.post('/?login', {'username': 'rootadmin', 'password': password})
    return 'Phoromatic Server Administration' in b.get('/?admin')[2]


def change_password(name, old, new):
    b = tn.Browser().login(name, old)
    s, _, body = b.post('/?password', {'old_password': old, 'register_password': new, 'register_password_confirm': new, 'token_submit': b.token('/?settings')})
    return 'Password Updated' in body


def set_root(password):
    subprocess.run(['docker', 'exec', '-i', tn.SRV, 'sh', '/bench/benchy', 'phoromatic.set-root-admin-password'], input=password + '\n', capture_output=True, text=True)


def old_first():
    set_root(tn.ROOT_PW)
    r = subprocess.run(['docker', 'exec', '-i', tn.SRV, 'sh', '/bench/benchy', 'server-account-create', '--user', 'rb6admin', '--email', 'rb6@example.com', '--account-id', AID], input=tn.PW + '\n', capture_output=True, text=True)
    check('the old release makes the account', 'Account created' in r.stdout, r.stdout + r.stderr)
    admin = tn.Browser().login('rb6admin')
    for name in ('rb6keep', 'rb6chg', 'rb6pw'):
        tn.make_user(admin, name, 3)
    check('the old release has no password columns', 'PasswordHash' not in columns() and tn.sql('PRAGMA user_version')[0]['user_version'] == 60, (columns(), tn.sql('PRAGMA user_version')))
    check('every user signs in, and so does root', all(logs_in(n) for n in ('rb6admin', 'rb6keep', 'rb6chg', 'rb6pw')) and root_logs_in(tn.ROOT_PW))
    tn.save_state({'legacy': {n: row(n)['Password'] for n in ('rb6admin', 'rb6keep', 'rb6chg', 'rb6pw')}, 'root_legacy': sha('PTS' + tn.ROOT_PW)})


def new_first():
    st = tn.state()
    check('the schema is at 66 (the catalog, join-code, native-suite and local-result steps after the password one) with the two columns, once', tn.sql('PRAGMA user_version')[0]['user_version'] == 66 and columns().count('PasswordHash') == 1 and columns().count('PasswordLegacyFp') == 1)
    check('nobody has a modern hash before they sign in', all(row(n)['PasswordHash'] is None for n in st['legacy']))
    check('every user signs in', all(logs_in(n) for n in st['legacy']))
    for n, legacy in st['legacy'].items():
        r = row(n)
        check('%s now has a modern hash and the legacy hash is as the old release wrote it' % n, r['Password'] == legacy and (r['PasswordHash'] or '').startswith('$') and r['PasswordLegacyFp'] == sha('legacy-fingerprint:' + legacy), r)
    check('root signs in with the password the old release set', root_logs_in(tn.ROOT_PW))
    settings = lambda k: tn.php_boot("phoromatic_server::init_settings_store(); echo (string)phoromatic_server::read_setting('%s');" % k).strip()
    check('root has a modern hash too, and the legacy one is what it was', settings('root_admin_pw') == st['root_legacy'] and settings('root_admin_pw_hash').startswith('$') and settings('root_admin_pw_fp') == sha('legacy-fingerprint:' + st['root_legacy']))
    check('this checkout changes a password on the password page', change_password('rb6chg', tn.PW, HERE_PW))
    r = row('rb6chg')
    check('both hashes follow it', r['Password'] == sha(r['Salt'] + HERE_PW) and r['PasswordLegacyFp'] == sha('legacy-fingerprint:' + r['Password']), r)
    tn.make_user(tn.Browser().login('rb6admin'), 'rb6new', 3)
    check('a user made here has both hashes', row('rb6new')['PasswordHash'] is not None and row('rb6new')['Password'] == sha(row('rb6new')['Salt'] + tn.PW))
    tn.save_state(dict(st, after_new={n: dict(row(n)) for n in ('rb6admin', 'rb6keep', 'rb6chg', 'rb6pw', 'rb6new')}))


def old():
    st = tn.state()
    check('every user signs in on the old release, with the password this checkout set for rb6chg and rb6new', all(logs_in(n) for n in ('rb6admin', 'rb6keep', 'rb6pw', 'rb6new')) and logs_in('rb6chg', HERE_PW) and not logs_in('rb6chg'))
    check('root signs in', root_logs_in(tn.ROOT_PW))
    check('the old release changes a password on its password page', change_password('rb6pw', tn.PW, NEWPW))
    set_root(ROOT2)
    check('and the root password', root_logs_in(ROOT2) and not root_logs_in(tn.ROOT_PW))
    tn.make_user(tn.Browser().login('rb6admin'), 'rb6oldmade', 3)
    r = row('rb6pw')
    check('it wrote the legacy hash only: the modern hash is the one from before', r['Password'] == sha(r['Salt'] + NEWPW) and r['PasswordHash'] == st['after_new']['rb6pw']['PasswordHash'], r)


def new_again():
    st = tn.state()
    check('the password the old release set works, and the one before it is refused', logs_in('rb6pw', NEWPW) and not logs_in('rb6pw'))
    r = row('rb6pw')
    check('and the modern hash was made again, beside the new legacy hash', r['PasswordHash'] != st['after_new']['rb6pw']['PasswordHash'] and r['PasswordLegacyFp'] == sha('legacy-fingerprint:' + r['Password']), r)
    check('the new password keeps working (now on the modern hash)', logs_in('rb6pw', NEWPW))
    check('the root password the old release set works, and the one before it is refused', root_logs_in(ROOT2) and not root_logs_in(tn.ROOT_PW))
    check('the user the old release made signs in and gets a modern hash', logs_in('rb6oldmade') and row('rb6oldmade')['PasswordHash'] is not None)
    check('nobody else\'s password changed', all(logs_in(n) for n in ('rb6admin', 'rb6keep', 'rb6new')) and logs_in('rb6chg', HERE_PW) and not logs_in('rb6chg'))
    check('the users who did not change keep the hashes they had', all(row(n)['PasswordHash'] == st['after_new'][n]['PasswordHash'] for n in ('rb6admin', 'rb6keep', 'rb6new')))


{'old-first': old_first, 'new-first': new_first, 'old': old, 'new-again': new_again}[sys.argv[1]]()
print('FAILURES %d' % fails)
