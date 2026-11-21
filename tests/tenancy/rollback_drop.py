#!/usr/bin/env python3
"""Legacy hash drop rollback phases (driven by rollback_drop.sh; the server container is the one TN_SRV/TN_PORT name). State between phases is in TN_STATE.

held        this checkout with the drop held back (schema 66, both hashes written, as 0.9.14/0.9.15 do): an account, a user with a trusted modern hash, a dormant user
            (the legacy hash only), a user whose password an older release changed (a stale modern hash), root with the legacy hash only, and an approved agent
new         this checkout as it is: the drop step runs once. No legacy value is left in the database or the settings; the trusted hash is the same one, the dormant ones are
            wrapped; everyone signs in with the password they had, the stale one with the newer password only; root too; the backup the step took holds the legacy hashes and the
            server settings page says so
old         OLD (default v0.9.15) on that data: every sign-in of a user and of root is refused with the usual page (no error), the agent still works; set-root-admin-password and the
            console's Set password give access again
new-again   this checkout again: the passwords OLD set work, the one nobody changed still does, and the sweep on the first open has cleared every legacy value OLD wrote
"""
import hashlib
import json
import re
import subprocess
import sys

import tn
from t4 import act, console_token

fails = 0
AID = 'RDROPA'
ROOT2 = 'rootpass-by-old-2'
STALE_NEW = 'pw-by-older-release-5'
PW_OLD_SET = {'rdmodern': 'set-on-old-modern-6', 'rddormant': 'set-on-old-dormant-7', 'rdstale': 'set-on-old-stale-8'}
USERS = ('rdadmin', 'rdmodern', 'rddormant', 'rdstale', 'rdidle')
REFUSED = 'did not match our records'


def check(what, ok, detail=''):
    global fails
    print('  %s  %s%s' % ('PASS' if ok else 'FAIL', what, '' if ok else ' :: ' + str(detail)[:300]))
    if not ok:
        fails += 1


def sha(t):
    return hashlib.sha256(t.encode()).hexdigest()


def row(name):
    return tn.sql("SELECT u.*, a.Salt FROM phoromatic_users u JOIN phoromatic_accounts a ON a.AccountID = u.AccountID WHERE u.UserName='%s'" % name)[0]


def version():
    return int(tn.sql('PRAGMA user_version')[0]['user_version'])


def drop_step():
    return int(tn.php_boot('echo phoromatic_passwords::DROP_STEP;').strip())


def setting(k):
    return tn.php_boot("phoromatic_server::init_settings_store(); echo (string)phoromatic_server::read_setting('%s');" % k).strip()


def attempt(name, password=tn.PW):
    b = tn.Browser()
    b.get('/')
    return b.post('/?login', {'username': name, 'password': password})


def logs_in(name, password=tn.PW):
    return 'Log-In Successful' in attempt(name, password)[2]


def refused_page(name, password=tn.PW):
    s, _, body = attempt(name, password)
    return s == 200 and REFUSED in body


def root_logs_in(password):
    b = tn.Browser()
    b.get('/')
    b.post('/?login', {'username': 'rootadmin', 'password': password})
    return 'Phoromatic Server Administration' in b.get('/?admin')[2]


def root_refused_page(password):
    s, _, body = attempt('rootadmin', password)
    return s == 200 and REFUSED in body


def set_root(password):
    subprocess.run(['docker', 'exec', '-i', tn.SRV, 'sh', '/bench/benchy', 'phoromatic.set-root-admin-password'], input=password + '\n', capture_output=True, text=True)


def everywhere(needle):
    """Where a value is: any column of any table (the audit included), the settings file, the server's own logs."""
    code = ('$d = new SQLite3("%s/phoromatic.db"); $n = $argv[1]; $t = $d->query("SELECT name FROM sqlite_master WHERE type = \'table\'");'
            'while($r = $t->fetchArray(SQLITE3_ASSOC)){ $c = $d->query("PRAGMA table_info(" . $r["name"] . ")"); while($x = $c->fetchArray(SQLITE3_ASSOC)){'
            '$q = $d->querySingle("SELECT COUNT(*) FROM \\"" . $r["name"] . "\\" WHERE CAST(\\"" . $x["name"] . "\\" AS TEXT) LIKE \'%%" . SQLite3::escapeString($n) . "%%\'"); if($q) echo $r["name"] . "." . $x["name"] . "\\n"; } }') % tn.STORAGE
    found = subprocess.run(['docker', 'exec', tn.SRV, 'php', '-r', code, needle], capture_output=True, text=True).stdout.split()
    settings = tn.php_boot("phoromatic_server::init_settings_store(); $s = phoromatic_settings_store::recover(phoromatic_server::phoromatic_path() . 'phoromatic-settings.pt2so'); echo json_encode($s->get_objects());")
    if needle in settings:
        found.append('settings')
    if needle in tn.srv_sh('cat /var/lib/benchy/phoromatic/*.log 2>/dev/null'):
        found.append('log')
    return found


def held():
    check('this checkout is held before the drop step', version() == drop_step() - 1, (version(), drop_step()))
    set_root(tn.ROOT_PW)
    r = subprocess.run(['docker', 'exec', '-i', tn.SRV, 'sh', '/bench/benchy', 'server-account-create', '--user', 'rdadmin', '--email', 'rd@example.com', '--account-id', AID], input=tn.PW + '\n', capture_output=True, text=True)
    check('the account is made', 'Account created' in r.stdout, r.stdout + r.stderr)
    admin = tn.Browser().login('rdadmin')
    for name in ('rdmodern', 'rddormant', 'rdstale', 'rdidle'):
        tn.make_user(admin, name, 3)
    check('every user has both hashes', all(row(n)['Password'] != '!' and row(n)['PasswordHash'] and row(n)['PasswordLegacyFp'] for n in USERS), [row(n) for n in USERS][:2])
    # the states the step meets
    tn.sql_exec("UPDATE phoromatic_users SET PasswordHash = NULL, PasswordLegacyFp = NULL WHERE UserName IN ('rddormant', 'rdidle')")   # made by 0.9.13, nobody signed in since 0.9.14
    stale = row('rdstale')
    tn.sql_exec("UPDATE phoromatic_users SET Password = '%s' WHERE UserName = 'rdstale'" % sha(stale['Salt'] + STALE_NEW))   # a password an older release changed
    tn.php_boot("phoromatic_server::init_settings_store(); foreach(array('root_admin_pw_hash', 'root_admin_pw_fp') as $k) phoromatic_settings_store::remove_in_file(phoromatic_server::phoromatic_path() . 'phoromatic-settings.pt2so', $k);")   # root dormant
    legacy = {n: row(n)['Password'] for n in USERS}
    msi = tn.approved_machine(AID)
    tn.save_state({'legacy': legacy, 'modern_hash': row('rdmodern')['PasswordHash'], 'admin_hash': row('rdadmin')['PasswordHash'], 'root_legacy': sha('PTS' + tn.ROOT_PW), 'msi': msi})
    check('root has only the legacy hash', setting('root_admin_pw') == sha('PTS' + tn.ROOT_PW) and setting('root_admin_pw_hash') == '' and setting('root_admin_pw_fp') == '')
    check('the agent is up', '_raw' not in tn.v1('ping', AID, msi))


def new():
    st = tn.state()
    tn.Browser().get('/')   # the first request runs the step
    check('the drop step ran: schema %d, and the named catalogs step (68) after it' % drop_step(), version() == drop_step() + 1, version())
    check('no user has a legacy value or a fingerprint', tn.sql("SELECT COUNT(*) AS n FROM phoromatic_users WHERE Password != '!' OR PasswordLegacyFp IS NOT NULL")[0]['n'] == 0)
    check('the one with a trusted modern hash kept it (rdadmin and rdmodern), unwrapped',
          row('rdmodern')['PasswordHash'] == st['modern_hash'] and row('rdadmin')['PasswordHash'] == st['admin_hash'] and row('rdmodern')['PasswordScheme'] is None and row('rdadmin')['PasswordScheme'] is None)
    check('the dormant ones and the stale one are wrapped (rddormant, rdidle, rdstale)', all(row(n)['PasswordScheme'] == 'legacy-wrapped' and (row(n)['PasswordHash'] or '').startswith('$') for n in ('rddormant', 'rdidle', 'rdstale')))
    check('root is wrapped, its legacy value is "!" (set, not unset) and its fingerprint is gone', setting('root_admin_pw') == '!' and setting('root_admin_pw_scheme') == 'legacy-wrapped' and setting('root_admin_pw_hash').startswith('$') and setting('root_admin_pw_fp') == '')
    leftovers = {v: everywhere(v) for v in list(st['legacy'].values()) + [st['root_legacy']]}
    check('no legacy value is anywhere in the database, the audit, the settings or a log', all(not w for w in leftovers.values()), leftovers)
    mig = [json.loads(r['Detail']) for r in tn.sql("SELECT Detail FROM phoromatic_server_audit WHERE Action='migration'")]
    mig = [m for m in mig if m.get('to') == drop_step()]
    check('one migration row with counts only', len(mig) == 1 and mig[0].get('kept_modern') == 2 and mig[0].get('wrapped') == 3 and re.match(r'phoromatic-pre-\d+-', mig[0].get('backup', '')), mig)
    check('everyone signs in with the password they had (the stale one with the newer password), and ends on a plain modern hash',
          all(logs_in(n) for n in ('rdadmin', 'rdmodern', 'rddormant', 'rdidle')) and logs_in('rdstale', STALE_NEW) and all(row(n)['PasswordScheme'] is None and row(n)['Password'] == '!' for n in USERS))
    check('root signs in, and ends on a plain modern hash', root_logs_in(tn.ROOT_PW) and setting('root_admin_pw_scheme') == '' and setting('root_admin_pw') == '!')
    backup = tn.srv_sh('ls %s/backups/phoromatic-pre-*.db' % tn.STORAGE).split()
    holds = False
    if backup:
        out = subprocess.run(['docker', 'exec', tn.SRV, 'php', '-r', '$d = new SQLite3($argv[1], SQLITE3_OPEN_READONLY); echo $d->querySingle("SELECT COUNT(*) FROM phoromatic_users WHERE Password != \'!\'");', backup[-1]], capture_output=True, text=True).stdout.strip()
        holds = out.isdigit() and int(out) >= 5
    check('the backup the step took is the restore point: it still holds the legacy hashes', holds, backup)
    tn.cli('server-admin-grant', 'rdadmin')
    page = tn.Browser().login('rdadmin').get('/?server_settings')[2]
    check('the server settings page says old copies hold the old hashes', re.search(r'\d+ older database cop(y|ies) in <code>storage/backups/</code> still holds? the old password hashes', page) is not None)
    tn.cli('server-admin-revoke', 'rdadmin')


def old():
    st = tn.state()
    check('OLD refuses every user with the usual page (no error)', all(refused_page(n) for n in ('rdmodern', 'rddormant', 'rdstale')))
    check('and root', root_refused_page(tn.ROOT_PW))
    check('the agent still works', '_raw' not in tn.v1('ping', AID, st['msi']))
    set_root(ROOT2)
    check('set-root-admin-password gives root access again', root_logs_in(ROOT2) and not root_logs_in(tn.ROOT_PW))
    root = tn.Browser()
    root.get('/')
    root.post('/?login', {'username': 'rootadmin', 'password': ROOT2})
    for name, pw in PW_OLD_SET.items():
        s, loc, page = act(root, 'user_password_reset', token=console_token(root), user_id=row(name)['UserID'], new_password=pw, new_password_again=pw)
        check('Set password for %s' % name, 'The password was changed.' in page, page[:200])
    check('they sign in with the new passwords', all(logs_in(n, pw) for n, pw in PW_OLD_SET.items()))
    r = row('rdmodern')
    check('OLD wrote both hashes again', r['Password'] == sha(r['Salt'] + PW_OLD_SET['rdmodern']) and r['PasswordHash'], r)
    tn.save_state(dict(st, old_rows={n: dict(row(n)) for n in USERS}, old_root_legacy=sha('PTS' + ROOT2)))


def new_again():
    st = tn.state()
    tn.Browser().get('/')   # the first open sweeps
    check('the sweep cleared every legacy value OLD wrote', tn.sql("SELECT COUNT(*) AS n FROM phoromatic_users WHERE Password != '!' OR PasswordLegacyFp IS NOT NULL")[0]['n'] == 0)
    legacy = [st['old_rows'][n]['Password'] for n in PW_OLD_SET] + [st['old_root_legacy']]
    check('and they are nowhere', all(not everywhere(v) for v in legacy), {v: everywhere(v) for v in legacy})
    check('the modern hashes OLD made were trusted and kept', all(row(n)['PasswordHash'] == st['old_rows'][n]['PasswordHash'] and row(n)['PasswordScheme'] is None for n in PW_OLD_SET))
    check('root: the modern hash was kept too', setting('root_admin_pw') == '!' and setting('root_admin_pw_hash').startswith('$') and setting('root_admin_pw_fp') == '' and setting('root_admin_pw_scheme') == '')
    check('the passwords OLD set work, and the ones before them are refused', all(logs_in(n, pw) for n, pw in PW_OLD_SET.items()) and refused_page('rdmodern'))
    check('root signs in with the password OLD set', root_logs_in(ROOT2), (setting('root_admin_pw'), setting('root_admin_pw_scheme'), setting('root_admin_pw_hash')[:12], re.sub(r'\s+', ' ', attempt('rootadmin', ROOT2)[2])[-300:]))
    check('a user nobody changed (rdidle) signs in with the password they always had, and so does rdadmin', logs_in('rdidle') and logs_in('rdadmin'))
    audit = tn.sql("SELECT Detail FROM phoromatic_server_audit WHERE Action='password_legacy_dropped'")
    check('the sweep is in the server audit, with counts and no hash', len(audit) >= 1 and not re.search(r'[0-9a-f]{64}', json.dumps(audit)), audit)
    check('the agent still works', '_raw' not in tn.v1('ping', AID, st['msi']))


{'held': held, 'new': new, 'old': old, 'new-again': new_again}[sys.argv[1]]()
print('FAILURES %d' % fails)
