"""Schema 59 and 60 migration checks, one phase per call, driven by migration.sh against the server container it points TN_SRV / TN_PORT at.

  fixture-one, fixture-two   (on the 0.9.10 code, schema 58) build the databases
  one       a one-account database: the step runs on the first request; roles, names, backup (0600, schema 58 inside), audit; unchanged flows; twice; a version put back
  two       a two-account database: no role, names, the server forms stay out of reach of both level-1 users
  half-prepare, half   a step that stopped halfway (some columns and the role table exist, user_version is still 58)
  race      eight simultaneous first requests: one backup, one set of audit rows
  nobackup  the backup cannot be made: the step does not run, the log says why once, the pages and commands work or say the upgrade is waiting; fixed, the next request after the retry time runs it
  backup    leftovers in the backups folder are never taken for a backup
  perm-backups, perm-readonly   as the package's non-root user: a backups folder it cannot write, a storage folder that is read-only
"""
import json
import socket
import subprocess
import sys
import threading
import time
import urllib.request

import tn

failures = []
STORAGE = tn.STORAGE
COLUMNS = ('State', 'StateChangedOn', 'StateChangedBy', 'StateReason', 'DeletedOn', 'PurgedOn', 'CreatedBy')
FORM = 'name="client_phone_home"'
FINAL = 60   # tenancy T1 is step 59, T2 (the account settings and server settings that only move toward closed) step 60; a 58 database takes both in one request


def check(ok, what):
    print('  %s  %s' % ('PASS' if ok else 'FAIL', what))
    if not ok:
        failures.append(what)


def version():
    return tn.sql('PRAGMA user_version')[0]['user_version']


def first_request():
    return tn.Browser().get('/')[0]


def backups():
    out = tn.srv_sh("cd %s/backups 2>/dev/null && stat -c '%%a %%n' phoromatic-pre-59-*.db 2>/dev/null" % STORAGE).split('\n')
    return [l for l in out if l.strip()]


def backups60():
    out = tn.srv_sh("cd %s/backups 2>/dev/null && stat -c '%%a %%n' phoromatic-pre-60-*.db 2>/dev/null" % STORAGE).split('\n')
    return [l for l in out if l.strip()]


def audit(action=None):
    return tn.sql("SELECT * FROM phoromatic_server_audit %s ORDER BY AuditID" % ("WHERE Action='%s'" % action if action else ''))


def roles():
    return {r['UserName']: r['GrantedBy'] for r in tn.sql('SELECT u.UserName, r.GrantedBy FROM phoromatic_server_roles r JOIN phoromatic_users u ON u.UserID = r.UserID')}


def log_errors():
    out = subprocess.run(['docker', 'logs', tn.SRV], capture_output=True, text=True)
    # one warning the code already had is left out: mkdir() racing itself when several workers make the same folder at start-up
    return [l for l in (out.stdout + out.stderr).splitlines() if any(k in l for k in ('PHP Fatal', 'PHP Warning', 'PHP Notice', 'Uncaught', 'PHP Deprecated'))
            and 'mkdir(): File exists' not in l]


def accounts():
    return {r['AccountID']: r for r in tn.sql('SELECT AccountID, GroupName, State FROM phoromatic_accounts')}


def backup_facts(name):
    # the backup opens as a database at schema 58, with the accounts and users as they were and none of the new tables
    php = ('$d=new SQLite3("%s/backups/%s", SQLITE3_OPEN_READONLY); echo json_encode(array($d->querySingle("PRAGMA user_version"), (int)$d->querySingle("SELECT COUNT(*) FROM phoromatic_users"), '
           '(int)$d->querySingle("SELECT COUNT(*) FROM sqlite_master WHERE name=\'phoromatic_server_roles\'"), $d->querySingle("SELECT COUNT(*) FROM pragma_table_info(\'phoromatic_accounts\') WHERE name=\'State\'")));' % (STORAGE, name))
    return json.loads(tn.srv_php(php))


def users():
    return {r['UserName']: r['AdminLevel'] for r in tn.sql('SELECT UserName, AdminLevel FROM phoromatic_users')}


def phase_fixture_one():
    aid = 'MGAAAA'
    tn.register('mg1admin', aid, web=True)
    admin = tn.Browser().login('mg1admin')
    tn.make_user(admin, 'mg1lvl2', 2)
    tn.make_user(admin, 'mg1lvl10', 10)
    tn.make_user(admin, 'mg1second', 3)
    tn.sql_exec("UPDATE phoromatic_users SET AdminLevel=1 WHERE UserName='mg1second'")
    msi = tn.approved_machine(aid)
    # what step 60 closes: the account's sharing, public and auto-approve flags, and the server settings that open results, accounts or the profile store
    tn.sql_exec("UPDATE phoromatic_account_settings SET LetOtherGroupsViewResults=1, LetPublicViewResults=1, AutoApproveNewSystems=1")
    tn.php_boot("phoromatic_server::init_settings_store(); phoromatic_server::save_setting('force_result_sharing', 1); phoromatic_server::save_setting('add_new_users_to_account', '%s'); "
                "phoromatic_server::save_setting('allow_test_profile_creation', 1); phoromatic_server::save_setting('account_creation_alt', '');" % aid)
    tn.save_state({'aid': aid, 'msi': msi})
    check(version() == 58 and users()['mg1second'] == 1, 'the one-account fixture is at schema 58 with two level-1 users %s' % users())
    check(not tn.sql("SELECT name FROM sqlite_master WHERE name='phoromatic_server_roles'"), 'and has no role table')


def phase_fixture_two():
    tn.register('mg2a', 'MGBBBB', web=True)
    tn.register('mg2b', 'MGCCCC', web=True)
    tn.make_user(tn.Browser().login('mg2a'), 'mg2alvl2', 2)
    check(version() == 58 and len(tn.sql('SELECT AccountID FROM phoromatic_accounts')) == 2, 'the two-account fixture is at schema 58 with two accounts')
    tn.save_state(dict(tn.state(), a='MGBBBB', b='MGCCCC'))


def flows_unchanged(aid, msi, level1, others):
    for u in [level1] + others:
        try:
            tn.Browser().login(u)
            ok = True
        except AssertionError:
            ok = False
        check(ok, 'user %s can still log in' % u)
    check('error' not in tn.v1('ping', aid, msi), 'the approved v1 client of the account still checks in')


def restart_server():
    # a restarted container gets a new port; the web helpers follow it
    subprocess.run(['docker', 'restart', tn.SRV], capture_output=True)
    port = subprocess.run(['docker', 'port', tn.SRV, '8080/tcp'], capture_output=True, text=True).stdout.splitlines()[0].split(':')[-1]
    tn.BASE = 'http://127.0.0.1:%s' % port
    for _ in range(30):
        try:
            socket.create_connection(('127.0.0.1', int(port)), timeout=2).close()
            return
        except OSError:
            time.sleep(1)


def server_settings():
    out = tn.php_boot("phoromatic_server::init_settings_store(); echo json_encode(array_map(function($k) { return phoromatic_server::read_setting($k); }, array('force_result_sharing', 'add_new_users_to_account', "
                      "'allow_test_profile_creation', 'account_creation_alt', 'tenant_auto_approve')));")
    return json.loads(out)


def step60_checks(aid):
    """Schema 60: sharing, public results and auto-approve are 0 in every account, the server settings that opened things are closed, the audit row has the
    before-values, a backup of the 59 database was made, and the step has run once."""
    left = tn.sql("SELECT COUNT(*) AS n FROM phoromatic_account_settings WHERE COALESCE(LetOtherGroupsViewResults, 0) <> 0 OR COALESCE(LetPublicViewResults, 0) <> 0 OR COALESCE(AutoApproveNewSystems, 0) <> 0")[0]['n']
    check(left == 0, 'sharing, public results and auto-approve are off in every account')
    force, add, create, alt, policy = server_settings()
    check(force in (False, None, 0, '') and add in (False, None, '') and create in (0, False, None, '0') and alt == 'Account creation is closed on this server. Ask its administrator for an account.' and policy == 'forbidden',
          'force_result_sharing and add_new_users_to_account are gone, profile creation is 0, registration says closed, the auto-approve policy is forbidden %s' % ([force, add, create, alt, policy],))
    rows = [r for r in audit('migration') if json.loads(r['Detail']).get('to') == 60]
    d = json.loads(rows[0]['Detail']) if rows else {}
    check(len(rows) == 1 and d.get('from') == 59 and d.get('backup', '').startswith('phoromatic-pre-60-'), 'one migration row for step 60, naming its backup %s' % d)
    check(d.get('accounts_with_sharing_with_other_accounts', '').startswith('1: ' + aid) and d.get('accounts_with_public_results', '').startswith('1: ' + aid) and d.get('accounts_with_auto_approve', '').startswith('1: ' + aid),
          'which lists the accounts that had each flag on %s' % {k: v for k, v in d.items() if k.startswith('accounts_')})
    sb = d.get('settings_before', {})
    check(sb.get('force_result_sharing') == 1 and sb.get('add_new_users_to_account') == aid and sb.get('allow_test_profile_creation') == 1 and sb.get('account_creation_alt') == '' and sb.get('tenant_auto_approve') == '(not set)',
          'and the server settings as they were %s' % sb)
    check(sorted(d.get('settings_closed', [])) == ['account_creation_alt', 'add_new_users_to_account', 'allow_test_profile_creation', 'force_result_sharing', 'tenant_auto_approve'], 'and which of them it closed %s' % d.get('settings_closed'))
    b = backups60()
    check(len(b) == 1 and b[0].startswith('600 '), 'a backup of the 59 database was made, at 0600 %s' % b)
    if b:
        php = ('$d=new SQLite3("%s/backups/%s", SQLITE3_OPEN_READONLY); echo json_encode(array($d->querySingle("PRAGMA user_version"), (int)$d->querySingle("SELECT COUNT(*) FROM phoromatic_account_settings WHERE LetOtherGroupsViewResults = 1")));' % (STORAGE, b[0].split()[1]))
        facts = json.loads(tn.srv_php(php))
        check(facts == [59, 1], 'and it is the database as it was before step 60: schema 59, the sharing flag still on %s' % facts)


def phase_one():
    st = tn.state()
    aid, msi = st['aid'], st['msi']
    check(version() == 58 and not backups(), 'before the first request: schema 58 and no backup')
    check(first_request() == 200, 'the first request is served')
    check(version() == FINAL, 'the first request runs both steps: user_version is %d (%s)' % (FINAL, version()))
    check(roles() == {'mg1admin': 'migration', 'mg1second': 'migration'}, 'the account\'s level-1 users, and only they, have the role, granted by migration %s' % roles())
    check(all(r['State'] == 'active' for r in accounts().values()) and accounts()[aid]['GroupName'] == 'Default', 'the account is active and its empty name is Default %s' % accounts())
    a = audit()
    check([r['Action'] for r in a].count('migration') == 2 and [r['Action'] for r in a].count('server_role_granted') == 2 and len(a) == 4, 'a migration row for each step and one grant row per role in the server audit %s' % [r['Action'] for r in a])
    check(all(r['ActorType'] == 'migration' and r['Actor'] == 'migration' for r in a), 'written by the migration')
    mig = json.loads(audit('migration')[0]['Detail'])
    check(mig['from'] == 58 and mig['to'] == 59 and mig['backup'] and sorted(mig['server_admins_granted']) == ['mg1admin', 'mg1second'] and list(mig['accounts_named'].values()) == ['Default'], 'the migration row says what was done %s' % mig)
    b = backups()
    check(len(b) == 1 and b[0].startswith('600 '), 'one backup, at 0600 %s' % b)
    check(tn.srv_sh("stat -c %%a %s/backups %s/migrate.lock" % (STORAGE, STORAGE)).split() == ['700', '600'], 'the folder is 0700 and the lock file 0600')
    if b:
        facts = backup_facts(b[0].split()[1])
        check(facts[0] == 58 and facts[1] == len(users()) and facts[2] == 0 and int(facts[3]) == 0, 'the backup is the database as it was: schema 58, the same users, none of the new tables or columns %s' % facts)
        check(mig['backup'] == b[0].split()[1], 'the audit row names the backup')
    step60_checks(aid)
    flows_unchanged(aid, msi, 'mg1admin', ['mg1lvl2', 'mg1lvl10', 'mg1second'])
    page = lambda u: tn.Browser().login(u).get('/?settings')[2]
    check(FORM in page('mg1admin') and FORM in page('mg1second'), 'both level-1 users still have the server settings (the role)')
    check(FORM not in page('mg1lvl2') and FORM not in page('mg1lvl10'), 'level 2 and 10 do not')
    # run twice: a restart and another request change nothing
    snapshot = (audit(), roles(), backups(), accounts())
    restart_server()
    for i in range(3):
        urllib.request.urlopen(tn.BASE + '/', timeout=30).read()
    check((audit(), roles(), backups(), accounts()) == snapshot and version() == FINAL, 'running it again (restart, three more requests) changes nothing')
    # the step repeated by hand: version put back to 58
    tn.sql_exec('PRAGMA user_version = 58')
    urllib.request.urlopen(tn.BASE + '/', timeout=30).read()
    check(version() == FINAL and roles() == snapshot[1] and accounts() == snapshot[3], 'the steps repeated after a version put back add no role and no second set of columns')
    now = backups()
    check(len(now) == 2 and set(snapshot[2]) < set(now), 'but it takes a fresh backup, and keeps the first %s' % now)
    newest = [b for b in now if b not in snapshot[2]][0].split()[1]
    check(backup_facts(newest)[0] == 58, 'the fresh one is the database as it was just before')
    check(len(audit('server_role_granted')) == 2, 'and grants nothing twice')
    check(not log_errors(), 'the server log has no PHP warning, notice or fatal error %s' % log_errors()[:3])


def phase_two():
    st = tn.state()
    a, b = st['a'], st['b']
    check(first_request() == 200 and version() == FINAL, 'the steps run on a database with two accounts')
    check(roles() == {}, 'no role is granted on a shared server %s' % roles())
    acc = accounts()
    check(acc[a]['GroupName'] == 'Default' and acc[b]['GroupName'] == 'Account ' + b and all(x['State'] == 'active' for x in acc.values()), 'the older account is Default, the other Account <AID>, both active %s' % acc)
    check([r['Action'] for r in audit()] == ['migration', 'migration'], 'a migration row per step and no grant row %s' % [r['Action'] for r in audit()])
    check(json.loads(audit()[0]['Detail'])['server_admins_granted'] == [], 'which says nobody was granted the role')
    bk = backups()
    check(len(bk) == 1 and bk[0].startswith('600 '), 'one backup, at 0600')
    for u in ('mg2a', 'mg2b'):
        browser = tn.Browser().login(u)
        page = browser.get('/?settings')[2]
        check(FORM not in page and 'HTTPS Certificate' not in page and 'name="v2_settings"' not in page, 'level 1 of a shared server (%s) has no server form' % u)
        check('name="dc_upload"' not in browser.get('/?caches')[2], '%s has no download cache upload' % u)
        tok = browser.token('/?settings')
        before = (tn.phone_home_policy(), tn.cli('server-https-status'))
        browser.post('/?settings', {'client_phone_home': 'client_choice', 'https_action': 'bootstrap', 'token_submit': tok})
        check((tn.phone_home_policy(), tn.cli('server-https-status')) == before, '%s cannot change a server setting by posting it' % u)
    check(not log_errors(), 'the server log has no PHP warning, notice or fatal error %s' % log_errors()[:3])


def phase_half_prepare():
    tn.sql_exec("ALTER TABLE phoromatic_accounts ADD COLUMN State TEXT DEFAULT 'active'; ALTER TABLE phoromatic_accounts ADD COLUMN StateReason TEXT; "
                "CREATE TABLE IF NOT EXISTS phoromatic_server_roles (UserID TEXT PRIMARY KEY, Role TEXT NOT NULL, Source TEXT NOT NULL DEFAULT 'local', SourceRef TEXT, GrantedOn TEXT NOT NULL, GrantedBy TEXT NOT NULL); "
                "INSERT INTO phoromatic_server_roles (UserID, Role, GrantedOn, GrantedBy) SELECT UserID, 'admin', '2026-11-01 10:00:00', 'by-hand' FROM phoromatic_users WHERE UserName='mg1admin'; "
                "UPDATE phoromatic_accounts SET GroupName='Mine'")
    check(version() == 58, 'the stopped step left schema 58 with some of its columns and its role table')


def phase_half():
    check(first_request() == 200 and version() == FINAL, 'the step finishes what was left')
    cols = [r['name'] for r in tn.sql('PRAGMA table_info(phoromatic_accounts)')]
    check(all(cols.count(c) == 1 for c in COLUMNS), 'each of the seven columns exists once %s' % cols)
    check(roles() == {'mg1admin': 'by-hand', 'mg1second': 'migration'}, 'the role that was there is kept and the other level-1 user gets one %s' % roles())
    check([r['SubjectLabel'] for r in audit('server_role_granted')] == ['mg1second'] and len(audit('migration')) == 2, 'only the new grant is audited, and each migration step once')
    check(list(a['GroupName'] for a in accounts().values()) == ['Mine'] and all(a['State'] == 'active' for a in accounts().values()), 'a name that was set stays, the state is active')
    check(len(backups()) == 1, 'one backup')
    check(not log_errors(), 'no PHP warning, notice or fatal error in the server log %s' % log_errors()[:3])


def phase_race():
    check(version() == 58 and not backups(), 'before the requests: schema 58, no backup')
    barrier = threading.Barrier(8)
    results = []

    def go():
        barrier.wait()
        try:
            results.append(urllib.request.urlopen(tn.BASE + '/', timeout=120).status)
        except Exception as e:
            results.append(str(e))
    threads = [threading.Thread(target=go) for _ in range(8)]
    [t.start() for t in threads]
    [t.join() for t in threads]
    check(results == [200] * 8, 'all eight requests are served %s' % results)
    check(version() == FINAL, 'schema %d' % FINAL)
    check(len(backups()) == 1, 'one backup, not eight %s' % backups())
    check(len(audit('migration')) == 2 and len(audit('server_role_granted')) == 2 and len(audit()) == 4, 'one set of audit rows %s' % [r['Action'] for r in audit()])
    check(len(backups60()) == 1, 'and one backup of the 59 database, not eight %s' % backups60())
    check(len(roles()) == 2, 'one role per level-1 user')
    check(not log_errors(), 'no PHP warning, notice or fatal error in the server log %s' % log_errors()[:3])


def log_text():
    logs = subprocess.run(['docker', 'logs', tn.SRV], capture_output=True, text=True)
    return logs.stdout + logs.stderr


def retry_marker():
    # the marker is in the storage folder (the temporary folder only when the storage cannot be written)
    return tn.srv_sh('ls %s/migrate.retry /tmp/benchy-migrate-retry-* 2>/dev/null' % STORAGE).split('\n')[0].strip()


def deferred_server_checks(aid, msi, lock_usable=True):
    """What a server whose schema 59 step has not run must still do, and refuse to do, without a PHP error."""
    n_accounts = len(tn.sql('SELECT AccountID FROM phoromatic_accounts'))
    out = subprocess.run(['docker', 'exec', '-i', tn.SRV, 'sh', '/bench/benchy', 'server-account-create', '--user', 'mgnew1', '--email', 'n@example.com', '--account-id', 'MGNEW1'], input=tn.PW + '\n', capture_output=True, text=True).stdout
    check('waiting to finish a database upgrade' in out and 'Account created' not in out, 'account creation says the upgrade is waiting and makes nothing')
    check(len(tn.sql('SELECT AccountID FROM phoromatic_accounts')) == n_accounts and not tn.sql("SELECT 1 FROM phoromatic_users WHERE UserName='mgnew1'"), 'no account, no user')
    check('waiting to finish' in tn.cli('server-admin-grant', 'mg1admin'), 'server-admin-grant says the upgrade is waiting')
    check('waiting to finish' in tn.cli('server-admin-list') and 'waiting to finish' in tn.cli('server-admin-revoke', 'mg1admin'), 'and so do server-admin-list and -revoke')
    reply = tn.parse_v1(tn.raw_post('/phoromatic.php', {'r': 'ping', 'msi': msi, 'pts_core': tn.CORE}))
    check(reply.get('account_id') == aid, 'v1 discovery by machine id works at the old schema %s' % reply)
    tn.sql_exec("INSERT INTO phoromatic_system_association_claims (AccountID, IPAddress, NetworkMAC, CreationTime) VALUES ('%s', 'mg-ip', 'aa:bb:cc:00:11:33', '2026-11-01 10:00:00')" % aid)
    reply = tn.parse_v1(tn.raw_post('/phoromatic.php', {'r': 'ping', 'msi': 'mg-unknown-msi', 'nm': 'aa:bb:cc:00:11:33', 'pts_core': tn.CORE}))
    check(reply.get('account_id') == aid, 'and by IP/MAC claim %s' % reply)
    check('error' not in tn.v1('ping', aid, msi), 'the approved v1 client checks in')
    feed = tn.Browser().get(tn.feed_path('mg1admin'))
    if lock_usable:
        check(feed[0] == 200 and '<rss' in feed[2], 'the results feed is served')
    else:
        # the feed key is made under the lock the schema steps use; with a lock the server cannot open there is no key, so no feed (and no link) rather than a guessable one
        check('<rss' not in feed[2], 'the results feed is not served while no key can be made')
    b = tn.Browser().login('mg1admin')
    check('Logout' in b.get('/?main')[2] and b.get('/?settings')[0] == 200 and FORM not in b.get('/?settings')[2], 'log-in and pages work; nobody is a server administrator yet')
    tn.cli('phoromatic-client-phone-home', 'client_choice')
    check('Client phone-home policy: client_choice' in tn.cli('phoromatic-client-phone-home'), 'a server-wide setting can still be changed from the command line (no server audit row to write yet)')
    tn.cli('phoromatic-client-phone-home', 'forbidden')
    out = tn.php_boot("phoromatic_server::prepare_database(); echo phoromatic_tenant::active_clause(), ' ', json_encode(phoromatic_tenant::state('%s'));" % aid)
    check(out.strip() == '1 = 1 "active"', 'the tenant helpers treat every account as active %s' % out.strip())
    check(first_request() == 200 and not tn.sql("SELECT name FROM sqlite_master WHERE name='phoromatic_server_audit'") and version() == 58, 'schema 60 waits behind 59: nothing of either step is applied')


def phase_nobackup():
    st = tn.state()
    tn.srv_sh('touch %s/backups' % STORAGE)     # a file where the folder should be: the copy cannot be made
    check(first_request() == 200, 'the page is served')
    check(version() == 58, 'the step did not run: still schema 58 (%s)' % version())
    text = log_text()
    check('database migration' in text.lower() and 'backup' in text, 'the server log says the backup could not be made')
    check(not tn.sql("SELECT name FROM sqlite_master WHERE name='phoromatic_server_audit'"), 'and nothing of the step was applied')
    check(bool(retry_marker()), 'a retry time is recorded')
    for i in range(5):
        first_request()
    check(log_text().lower().count('schema 59 was not applied') == 1, 'five more requests do not try again or log again')
    deferred_server_checks(st['aid'], st['msi'])
    check(log_text().lower().count('schema 59 was not applied') == 1, 'still one line in the log after all of that')
    check(retry_marker() == STORAGE + '/migrate.retry', 'the marker is in the storage folder %s' % retry_marker())
    tries = lambda: log_text().lower().count('schema 59 was not applied')
    tn.srv_sh('touch -d "2099-01-01" %s/migrate.retry' % STORAGE)
    first_request()
    check(tries() == 2, 'a marker dated in the future is ignored: the step is tried again')
    tn.srv_sh('rm -f %s/migrate.retry && ln -s /tmp/qc-link-target %s/migrate.retry' % (STORAGE, STORAGE))
    first_request()
    check(tries() == 3 and not tn.srv_sh('ls /tmp/qc-link-target 2>/dev/null').strip(), 'a marker that is a link is ignored, and the link is not followed')
    check(tn.srv_sh('test -L %s/migrate.retry && echo link' % STORAGE).strip() == '', 'it was replaced by a plain file')
    tn.srv_sh('rm -f %s/backups' % STORAGE)
    first_request()
    check(version() == 58, 'with the fault gone but the retry time not reached, nothing is tried yet')
    tn.srv_sh('touch -d "10 minutes ago" ' + retry_marker())
    first_request()
    check(version() == FINAL and len(backups()) == 1 and backups()[0].startswith('600 '), 'once the retry time has passed the next request runs the steps and makes the backup (0600)')
    check(not retry_marker(), 'and the retry time is cleared')
    check(not log_errors(), 'no PHP warning, notice or fatal error in the server log %s' % log_errors()[:3])


def phase_backup():
    # a leftover that looks like a backup must never be taken for one: a copy cut short, and an old complete-looking file from an earlier run
    tn.srv_sh('mkdir -p %s/backups && cd %s/backups && printf "truncated" > phoromatic-pre-59-20200101-000000.db.part && printf "SQLite format 3" > phoromatic-pre-59-20200101-000000.db && printf "SQLite format 3" > phoromatic-pre-59-20200102-000000.db' % (STORAGE, STORAGE))
    check(first_request() == 200 and version() == FINAL, 'the step runs with leftovers in the backups folder')
    names = tn.srv_sh('ls %s/backups' % STORAGE).split()
    check('phoromatic-pre-59-20200101-000000.db.part' not in names, 'the cut-short copy is removed')
    fresh = [n for n in names if n.startswith('phoromatic-pre-59-') and not n.startswith('phoromatic-pre-59-2020')]
    check(len(fresh) == 1 and fresh[0].endswith('.db'), 'a fresh backup was made beside the old file %s' % names)
    if fresh:
        check(backup_facts(fresh[0])[0] == 58 and backup_facts(fresh[0])[1] == len(users()), 'and it is the database as it was')
        check(json.loads(audit('migration')[0]['Detail'])['backup'] == fresh[0], 'the audit row names the fresh one, not the old file')
        check(tn.srv_sh("stat -c %%a %s/backups/%s" % (STORAGE, fresh[0])).strip() == '600', 'at 0600')
        check('phoromatic-pre-59-20200101-000000.db' not in names and 'phoromatic-pre-59-20200102-000000.db' in names, 'only the newest earlier copy is kept beside it (disk use stays bounded) %s' % names)
    check(not log_errors(), 'no PHP warning, notice or fatal error in the server log %s' % log_errors()[:3])


def phase_perm_backups():
    st = tn.state()
    global STORAGE
    STORAGE = tn.STORAGE = '/data/phoromatic'
    subprocess.run(['docker', 'exec', '-u', '0', tn.SRV, 'sh', '-c', 'mkdir -p %s/backups && chown 0:0 %s/backups && chmod 755 %s/backups' % (STORAGE, STORAGE, STORAGE)])
    check(tn.srv_sh('id -u').strip() != '0', 'the server runs as a user that is not root')
    # a marker of another user (root) is not honoured: the step is tried although a fresh-looking marker is there
    subprocess.run(['docker', 'exec', '-u', '0', tn.SRV, 'sh', '-c', 'touch %s/migrate.retry' % STORAGE])
    check(first_request() == 200 and version() == 58, 'the backups folder cannot be written: the step does not run')
    check('schema 59 was not applied' in log_text().lower(), 'a retry marker owned by another user did not hold it back')
    check(tn.srv_sh('stat -c %%u %s/migrate.retry' % STORAGE).strip() == tn.srv_sh('id -u').strip(), 'and was replaced by the server\'s own')
    check('backup' in log_text() and 'schema 59 was not applied' in log_text().lower(), 'the log says so')
    deferred_server_checks(st['aid'], st['msi'])
    subprocess.run(['docker', 'exec', '-u', '0', tn.SRV, 'sh', '-c', 'chown 33:33 %s/backups; rm -f %s/migrate.retry /tmp/benchy-migrate-retry-*' % (STORAGE, STORAGE)])
    first_request()
    check(version() == FINAL and len(tn.srv_sh("ls %s/backups/*.db" % STORAGE).split()) == 2, 'with the folder fixed the steps run and make a backup each (59, then 60)')
    check(not log_errors(), 'no PHP warning, notice or fatal error in the server log %s' % log_errors()[:3])


def phase_perm_readonly():
    """A lock file the server's user cannot open (the step is deferred and the pages work), and a storage folder it cannot write (the retry marker goes to the
    temporary folder). A whole read-only storage folder is not served at all by any release (the database cannot even be read), so that part is a unit check."""
    st = tn.state()
    global STORAGE
    STORAGE = tn.STORAGE = '/data/phoromatic'
    subprocess.run(['docker', 'exec', '-u', '0', tn.SRV, 'sh', '-c', 'touch %s/migrate.lock; chown 0:0 %s/migrate.lock; chmod 600 %s/migrate.lock' % (STORAGE, STORAGE, STORAGE)])
    status = first_request()
    check(status == 200, 'a lock file the server cannot open: the page is still served (%s)' % status)
    check(version() == 58, 'the step did not run')
    check(log_text().lower().count('schema 59 was not applied') == 1 and 'lock file' in log_text(), 'one log line, naming the lock file')
    for i in range(4):
        first_request()
    check(log_text().lower().count('schema 59 was not applied') == 1, 'four more requests add none')
    deferred_server_checks(st['aid'], st['msi'], lock_usable=False)
    bad = [l for l in log_errors() if 'Fatal' in l or 'Uncaught' in l]
    check(not bad, 'and there is no PHP fatal error %s' % bad[:2])
    subprocess.run(['docker', 'exec', '-u', '0', tn.SRV, 'sh', '-c', 'rm -f %s/migrate.lock %s/migrate.retry' % (STORAGE, STORAGE)])
    first_request()
    check(version() == FINAL, 'with the lock file usable again the next request runs the steps')
    # the marker when the storage folder cannot be written
    subprocess.run(['docker', 'exec', '-u', '0', tn.SRV, 'sh', '-c', 'rm -f %s/migrate.retry /tmp/benchy-migrate-retry-*; chmod 555 %s' % (STORAGE, STORAGE)])
    out = tn.srv_php('define("PTS_MODE","CLIENT");define("PTS_AUTO_LOAD_OBJECTS",true);include("/bench/pts-core/pts-core.php");pts_core::init();'
                     '$m = new ReflectionMethod("phoromatic_server", "migrate_deferred"); $m->setAccessible(true); $m->invoke(null, "test");')
    marker = retry_marker()
    subprocess.run(['docker', 'exec', '-u', '0', tn.SRV, 'sh', '-c', 'chmod 755 %s' % STORAGE])
    check(marker.startswith('/tmp/benchy-migrate-retry-'), 'storage not writable: the marker goes to the temporary folder %s' % marker)
    # a marker there that is not the server\'s own, or is a link, or is dated ahead is not honoured
    subprocess.run(['docker', 'exec', '-u', '0', tn.SRV, 'sh', '-c', 'rm -f %s /tmp/benchy-migrate-retry-*; touch %s' % (marker, marker)])
    code = ('define("PTS_MODE","CLIENT");define("PTS_AUTO_LOAD_OBJECTS",true);include("/bench/pts-core/pts-core.php");pts_core::init();'
            '$m = new ReflectionMethod("phoromatic_server", "migrate_retry_active"); $m->setAccessible(true); echo $m->invoke(null) ? "honoured" : "ignored";')
    check(tn.srv_php(code).strip() == 'ignored', 'a marker in /tmp owned by another user is not honoured')
    subprocess.run(['docker', 'exec', '-u', '0', tn.SRV, 'sh', '-c', 'rm -f %s; touch -d 2099-01-01 %s; chown 33:33 %s' % (marker, marker, marker)])
    check(tn.srv_php(code).strip() == 'ignored', 'a marker dated in the future is not honoured')
    subprocess.run(['docker', 'exec', '-u', '0', tn.SRV, 'sh', '-c', 'rm -f %s; ln -s /etc/passwd %s' % (marker, marker)])
    check(tn.srv_php(code).strip() == 'ignored', 'a marker that is a link is not honoured')
    subprocess.run(['docker', 'exec', '-u', '0', tn.SRV, 'sh', '-c', 'rm -f %s; touch %s; chown 33:33 %s' % (marker, marker, marker)])
    check(tn.srv_php(code).strip() == 'honoured', 'a fresh marker of the server\'s own user is honoured')


if __name__ == '__main__':
    globals()['phase_' + sys.argv[1].replace('-', '_')]()
    print('FAILURES %d' % len(failures))
    sys.exit(1 if failures else 0)
