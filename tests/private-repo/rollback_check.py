"""Rollback check for the 0.9.4 schema step (57 -> 58), driven by rollback.sh. One phase per call, against whichever server container
rollback.sh points the QC_* environment at.

  old-pages   the 0.9.3 server on a fresh data volume: pages load, user_version is 57
  use-new     the 0.9.4 server on that volume: migrates to 58; enrol an agent, run a native job, upload a result, check in a v1 system
  old-after   the 0.9.3 server on the 58 database: starts, serves the pages, the v1 client checks in, the native result is viewable,
              and the new tables and column are left alone
  new-again   the 0.9.4 server again: the 58 database needs no step; forcing the version back to 57 repeats the step and changes nothing

Not part of the shared-server phases of run.sh; run it with `tests/private-repo/run.sh rollback`.
"""
import json
import os
import subprocess
import sys

from bench_helpers import new_system, start
from test_native_enrol import audit  # noqa: F401  (imported to fail early when the helpers are missing)
from test_private_repo_http import AID, SRV, sql, srv_sh
from test_private_repo_tokens import sql_exec
from test_private_repo_web import Browser, USER, PASS
from v2_helpers import allow_http, call, enrol, event, fixture_result, get_job, hello_body, hello_event, native_schedule, new_run_id, upload_result

STATE = os.environ['RB_STATE']
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


def counts():
    return {t: sql('SELECT COUNT(*) AS n FROM %s' % t)[0]['n'] for t in NEW_TABLES if sql("SELECT name FROM sqlite_master WHERE name='%s'" % t)}


def schema():
    return sorted([r['type'], r['name'], r['sql'] or ''] for r in sql("SELECT type, name, sql FROM sqlite_master WHERE name NOT LIKE 'sqlite_%'"))


def general_pages(extra=()):
    return pages(['/', '/?systems', '/?schedules', '/?results', '/?benches', '/?settings', '/?users'] + list(extra))


def phase_old_pages():
    check(version() == 57, 'the 0.9.3 database is at user_version 57 (%s)' % version())
    general_pages()
    server_log_clean('0.9.3 fresh')


def phase_use_new():
    general_pages()
    check(version() == 58, 'the 0.9.4 server migrated the 0.9.3 database to 58 (%s)' % version())
    allow_http(True)
    token, reply = enrol()
    sid = reply['system']['id']
    status, body, _ = call('/api/v2/agent/hello', hello_body(), token=token)
    check(status == 200, 'an agent enrols and says hello')
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
    check(start(msi) is not None, 'a v1 client checks in on the 0.9.4 server')
    pages(['/?result/%s' % pprid, '/?systems/%s' % sid, '/?systems/%s/engine' % sid])
    server_log_clean('0.9.4')
    save_state(pprid=pprid, sid=sid, vsid=vsid, sched=sched, counts=counts(), schema=schema(), upload=body['upload_id'])


def phase_old_after():
    st = load_state()
    check(version() == 58, '0.9.3 starts on the 58 database and leaves user_version alone (%s)' % version())
    general_pages(['/?result/%s' % st['pprid'], '/?systems/%s' % st['sid'], '/?results/system/%s' % st['sid']])
    b = Browser().login(USER, PASS)
    page = b.get('/?result/%s' % st['pprid'])[2]
    check('STREAM' in page.upper(), 'the native result is viewable on 0.9.3 (it shows the STREAM test)')
    msi, vsid = new_system()
    check(start(msi) is not None, 'a v1 client checks in on 0.9.3 against the 58 database')
    check(counts() == st['counts'], '0.9.3 left the new tables as they were %s' % counts())
    now = schema()
    diff = [x for x in now if x not in st['schema']] + [x for x in st['schema'] if x not in now]
    check(not diff, '0.9.3 left the schema as it was %s' % [d for d in diff][:4])
    check('EnginePolicy' in {c['name'] for c in sql('PRAGMA table_info(phoromatic_systems)')}, 'the EnginePolicy column is still there')
    server_log_clean('0.9.3 on 58')


def phase_new_again():
    st = load_state()
    before = schema()
    general_pages(['/?result/%s' % st['pprid'], '/?systems/%s' % st['sid']])
    check(version() == 58 and schema() == before, 'upgrading again at 58 changes nothing')
    # the repeatable step: a database whose version was put back to 57 runs it again
    check(sql_exec('PRAGMA user_version = 57') == 'ok', 'version put back to 57')
    c0 = counts()
    srv_sh("cd /bench/pts-core/phoromatic/public_html && php -r 'define(\"PHOROMATIC_SERVER\", true); define(\"PTS_MODE\", \"WEB_CLIENT\"); define(\"PTS_AUTO_LOAD_OBJECTS\", true); include(\"../../pts-core.php\"); pts_core::init(); phoromatic_server::prepare_database();'")
    check(version() == 58, 'the step ran again and set 58 (%s)' % version())
    check(schema() == before, 'the schema is the same after the second run of the step')
    check(counts() == c0 and counts()['phoromatic_v2_jobs'] >= 1, 'the data is the same after the second run of the step %s' % counts())
    pages(['/?result/%s' % st['pprid'], '/?systems/%s' % st['sid']])
    server_log_clean('0.9.4 again')


if __name__ == '__main__':
    globals()['phase_' + sys.argv[1].replace('-', '_')]()
    print('FAILURES %d' % len(failures))
    sys.exit(1 if failures else 0)
