"""Rollback check for the newest schema steps (60, tenancy T2, on top of 59, T1), driven by rollback.sh. One phase per call, against whichever server
container rollback.sh points the QC_* environment at. The old release (OLD, default v0.9.11, schema 59; v0.9.10 at 58 works too) runs on a fresh volume first.

  old-pages   the old server on a fresh data volume: pages load, user_version is the old release's (57 or 58)
  use-new     this checkout on that volume: migrates to 60 with a backup of the old database, closes what the old release had open (sharing, public
              results, auto-approve, open registration, adding users to an account, profile creation); enroll an agent, run a native job, upload a result, check in a v1 system
  old-after   the old server on the 59 database: starts, serves the pages, the v1 client checks in, the native result is viewable, the single-account
              administrator still has the server settings, and the new tables and columns are left alone
  new-again   this checkout again: the 59 database needs no step; forcing the version back to the old one repeats the steps and changes nothing

Not part of the shared-server phases of run.sh; run it with `tests/private-repo/run.sh rollback`.
"""
import json
import os
import subprocess
import sys

from bench_helpers import new_system, start
from test_native_enroll import audit  # noqa: F401  (imported to fail early when the helpers are missing)
from test_private_repo_http import AID, SRV, sql, srv_sh
from test_private_repo_tokens import sql_exec
from test_private_repo_web import Browser, USER, PASS
from v2_helpers import allow_http, call, enroll, event, fixture_result, get_job, hello_body, hello_event, native_schedule, new_run_id, upload_result

STATE = os.environ['RB_STATE']
NEW_VERSION = 60
NEW_TABLES = ('phoromatic_enrol_codes', 'phoromatic_system_tokens', 'phoromatic_v2_jobs', 'phoromatic_schedules_native_tests', 'phoromatic_system_catalogue', 'phoromatic_v2_failures')
BAD = ('Fatal error', 'Warning</b>', 'Notice</b>', 'Deprecated</b>', 'Uncaught', 'Array to string', 'Parse error')
failures = []


def check(ok, what):
    print('  %s  %s' % ('PASS' if ok else 'FAIL', what))
    if not ok:
        failures.append(what)


def version():
    return sql('PRAGMA user_version')[0]['user_version']


def load_state():
    return json.load(open(STATE)) if os.path.exists(STATE) else {}


def save_state(**kw):
    s = load_state()
    s.update(kw)
    json.dump(s, open(STATE, 'w'))


def pages(paths):
    b = Browser().login(USER, PASS)
    for p in paths:
        try:
            status, _, body = b.get(p)
        except OSError as e:  # a timeout or a dropped connection is a failure of that page, not of the check
            check(False, 'page %s loads (%s)' % (p, e))
            continue
        check(status == 200 and not [n for n in BAD if n in body], 'page %s loads (%d) with no PHP warning' % (p, status))
    return b


def server_log_clean(label):
    out = subprocess.run(['docker', 'logs', SRV], capture_output=True, text=True)
    bad = [l for l in (out.stdout + out.stderr).splitlines() if any(k in l for k in ('PHP Warning', 'PHP Notice', 'PHP Fatal', 'PHP Deprecated', 'Uncaught'))]
    check(not bad, '%s: the server log has no PHP warning, notice or fatal error %s' % (label, bad[:3]))


def server_rows():
    return [sql('SELECT COUNT(*) AS n FROM phoromatic_server_roles')[0]['n'], sql('SELECT COUNT(*) AS n FROM phoromatic_server_audit')[0]['n']]


def counts():
    return {t: sql('SELECT COUNT(*) AS n FROM %s' % t)[0]['n'] for t in NEW_TABLES if sql("SELECT name FROM sqlite_master WHERE name='%s'" % t)}


def schema():
    return sorted([r['type'], r['name'], r['sql'] or ''] for r in sql("SELECT type, name, sql FROM sqlite_master WHERE name NOT LIKE 'sqlite_%'"))


def general_pages(extra=()):
    return pages(['/', '/?systems', '/?schedules', '/?results', '/?benches', '/?settings', '/?users'] + list(extra))


OPEN_PHP = ("define('PTS_MODE','CLIENT');define('PTS_AUTO_LOAD_OBJECTS',true);define('PHOROMATIC_SERVER',true);$_SERVER['argv']=array('x');include('/bench/pts-core/pts-core.php');pts_core::init();"
            "phoromatic_server::init_settings_store();")


def srv_php(code):
    # (no shell in between: OPEN_PHP has $_SERVER in it, which a shell would turn into text)
    r = subprocess.run(['docker', 'exec', SRV, 'php', '-r', OPEN_PHP + code], capture_output=True, text=True)
    return r.stdout


def settings_json():
    out = srv_php("echo json_encode(array_map(function($k) { return phoromatic_server::read_setting($k); }, array('force_result_sharing', 'add_new_users_to_account', 'allow_test_profile_creation', 'account_creation_alt', 'tenant_auto_approve')));")
    try:
        return json.loads(out)
    except ValueError:
        print('  settings_json got: %r' % out[:400])
        raise


def flags_on():
    return sql("SELECT COUNT(*) AS n FROM phoromatic_account_settings WHERE COALESCE(LetOtherGroupsViewResults, 0) <> 0 OR COALESCE(LetPublicViewResults, 0) <> 0 OR COALESCE(AutoApproveNewSystems, 0) <> 0")[0]['n']


def phase_old_pages():
    check(version() in (57, 58, 59), 'the old release\'s database is at user_version 57, 58 or 59 (%s)' % version())
    save_state(v_old=version())
    general_pages()
    # everything T2 closes, open: the old release is the one that opened it
    check(sql_exec("UPDATE phoromatic_account_settings SET LetOtherGroupsViewResults = 1, LetPublicViewResults = 1, AutoApproveNewSystems = 1") == 'ok', 'sharing, public results and auto-approve switched on in the old release\'s database')
    srv_php("phoromatic_server::save_setting('force_result_sharing', 1); phoromatic_server::save_setting('add_new_users_to_account', '%s');"
            " phoromatic_server::save_setting('allow_test_profile_creation', 1);" % AID)
    if os.environ.get('RB_ALT') == 'unset':
        # registration open the way a server nobody ever ran server-registration on has it (and every fresh 0.9.9 database): the text is not set at all
        srv_php("pts_storage_object::remove_in_file(phoromatic_server::phoromatic_path() . 'phoromatic-settings.pt2so', 'account_creation_alt');")
        open_now = settings_json()[3] is False
    else:
        srv_php("phoromatic_server::save_setting('account_creation_alt', '');")
        open_now = settings_json()[3] == ''
    check(flags_on() == 1 and settings_json()[0] == 1 and open_now, 'and the server settings open (sharing forced, new users added to the account, profile creation, registration open: text %s)' % ('not set' if os.environ.get('RB_ALT') == 'unset' else 'empty'))
    check('register_username' in Browser().get('/')[2], 'and the old release\'s welcome page offers registration (that is what "open" means to it)')
    server_log_clean('old release, fresh')


def phase_use_new():
    general_pages()
    check(version() == NEW_VERSION, 'this checkout migrated the old database to %d (%s)' % (NEW_VERSION, version()))
    if version() != NEW_VERSION:
        lg = subprocess.run(['docker', 'logs', SRV], capture_output=True, text=True)
        print('  server log: %r' % [l for l in (lg.stdout + lg.stderr).splitlines() if 'Accepted' not in l and 'Closing' not in l and '[200]' not in l][-12:])
    backup = srv_sh("stat -c '%a %n' /var/lib/benchy/phoromatic/backups/phoromatic-pre-*.db").split()
    check(len(backup) == 2 and backup[0] == '600', 'one backup of the old database was made for the whole upgrade, at 0600 %s' % backup)
    check(sql("SELECT COUNT(*) AS n FROM phoromatic_server_roles")[0]['n'] == 1, 'the account\'s one level-1 user has the server role (from the migration, or from creating the first account)')
    steps = len([v for v in (59, 60) if v > load_state()['v_old']])
    check(sql("SELECT COUNT(*) AS n FROM phoromatic_server_audit WHERE Action='migration'")[0]['n'] == steps, 'and each migration step is in the server audit once (%d)' % steps)
    check(flags_on() == 0, 'sharing, public results and auto-approve are off in every account')
    force, add, create, alt, policy = settings_json()
    check(not force and not add and not create and isinstance(alt, str) and alt != '' and policy == 'forbidden', 'the server settings that opened things are closed: no forced sharing, no add-to-account, no profile creation, registration closed, auto-approve forbidden %s' % ([force, add, create, alt, policy],))
    check('register_username' not in Browser().get('/')[2], 'and the welcome page has no registration form')
    allow_http(True)
    token, reply = enroll()
    sid = reply['system']['id']
    status, body, _ = call('/api/v2/agent/hello', hello_body(), token=token)
    check(status == 200, 'an agent enrolls and says hello')
    sched = native_schedule(sid)
    job, _ = get_job(token)
    check(job is not None, 'a native schedule is issued as a job')
    run = new_run_id()
    evs = [hello_event(run), event('test_start', 2, run, test={'ref': 'benchy/stream', 'version': '1.0.0'}, options={}, summary='', index=1, total=1),
           event('run_end', 3, run, status='ok', result=None, completed=1, failed=0, cancelled=0)]
    check(call('/api/v2/jobs/%d/events' % job['id'], {'events': evs}, token=token)[0] == 200, 'events are taken')
    status, body, _ = upload_result(token, job['id'], fixture_result('stream-ok', run))
    check(status == 200 and body.get('upload_id'), 'a native result uploads (%s)' % status)
    check(call('/api/v2/jobs/%d/end' % job['id'], {'status': 'ok', 'reason': None, 'run': run}, token=token)[0] == 200, 'the job ends ok')
    allow_http(False)
    pprid = sql("SELECT PPRID FROM phoromatic_results WHERE UploadID=%d" % body['upload_id'])[0]['PPRID']
    msi, vsid = new_system()
    check(start(msi) is not None, 'a v1 client checks in on this checkout')
    pages(['/?result/%s' % pprid, '/?systems/%s' % sid, '/?systems/%s/engine' % sid])
    server_log_clean('this checkout')
    save_state(server_rows=server_rows(), pprid=pprid, sid=sid, vsid=vsid, sched=sched, counts=counts(), schema=schema(), upload=body['upload_id'])


def phase_old_after():
    st = load_state()
    check(version() == NEW_VERSION, 'the old release starts on the %d database and leaves user_version alone (%s)' % (NEW_VERSION, version()))
    general_pages(['/?result/%s' % st['pprid'], '/?systems/%s' % st['sid'], '/?results/system/%s' % st['sid']])
    b = Browser().login(USER, PASS)
    page = b.get('/?result/%s' % st['pprid'])[2]
    check('STREAM' in page.upper(), 'the native result is viewable on the old release (it shows the STREAM test)')
    msi, vsid = new_system()
    check(start(msi) is not None, 'a v1 client checks in on the old release against the new database')
    check(counts() == st['counts'], 'the old release left the new tables as they were %s' % counts())
    now = schema()
    diff = [x for x in now if x not in st['schema']] + [x for x in st['schema'] if x not in now]
    check(not diff, 'the old release left the schema as it was %s' % [d for d in diff][:4])
    check('EnginePolicy' in {c['name'] for c in sql('PRAGMA table_info(phoromatic_systems)')}, 'the EnginePolicy column is still there')
    # what the new release closed stays closed under the old one: it honours the same settings, so nothing is shared, public or auto-approved,
    # and registration (the welcome page of the old release) is closed
    check(flags_on() == 0, 'the old release left sharing, public results and auto-approve off')
    force, add, create, alt, policy = settings_json()
    check(not force and not add and not create and isinstance(alt, str) and alt != '', 'and the server settings are still closed on it %s' % ([force, add, create, alt],))
    check('register_username' not in Browser().get('/')[2], 'and the old release\'s welcome page has no registration form (the setting says closed)')
    if st['v_old'] >= 58:
        # 0.9.10 and later treat a level-1 user of a one-account server as the server administrator; the account here is that
        check('name="client_phone_home"' in b.get('/?settings')[2], 'the single account\'s administrator still has the server settings on the old release')
    check(server_rows() == st['server_rows'] and server_rows()[0] == 1, 'the old release left the role and the server audit as they were %s' % server_rows())
    server_log_clean('old release on the new database')


def phase_new_again():
    st = load_state()
    before = schema()
    general_pages(['/?result/%s' % st['pprid'], '/?systems/%s' % st['sid']])
    check(version() == NEW_VERSION and schema() == before, 'upgrading again at %d changes nothing' % NEW_VERSION)
    # the repeatable steps: a database whose version was put back to the old one runs them again
    check(sql_exec('PRAGMA user_version = %d' % st['v_old']) == 'ok', 'version put back to %d' % st['v_old'])
    c0 = counts()
    roles0 = sql('SELECT UserID, GrantedBy FROM phoromatic_server_roles ORDER BY UserID')
    backups0 = srv_sh('ls /var/lib/benchy/phoromatic/backups').split()
    srv_sh("cd /bench/pts-core/phoromatic/public_html && php -r 'define(\"PHOROMATIC_SERVER\", true); define(\"PTS_MODE\", \"WEB_CLIENT\"); define(\"PTS_AUTO_LOAD_OBJECTS\", true); include(\"../../pts-core.php\"); pts_core::init(); phoromatic_server::prepare_database();'")
    check(version() == NEW_VERSION, 'the steps ran again and set %d (%s)' % (NEW_VERSION, version()))
    now = srv_sh('ls /var/lib/benchy/phoromatic/backups').split()
    check(sql('SELECT UserID, GrantedBy FROM phoromatic_server_roles ORDER BY UserID') == roles0, 'no second role')
    check(len(now) == len(backups0) + 1 and set(backups0) < set(now), 'one fresh backup for the steps that ran again, beside the first')
    check(schema() == before, 'the schema is the same after the second run of the step')
    check(counts() == c0 and counts()['phoromatic_v2_jobs'] >= 1, 'the data is the same after the second run of the step %s' % counts())
    pages(['/?result/%s' % st['pprid'], '/?systems/%s' % st['sid']])
    server_log_clean('this checkout again')


if __name__ == '__main__':
    globals()['phase_' + sys.argv[1].replace('-', '_')]()
    print('FAILURES %d' % len(failures))
    sys.exit(1 if failures else 0)
