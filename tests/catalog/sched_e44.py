#!/usr/bin/env python3
"""E4.4 C4: schedules and the signed catalog (server design 6; refusal tests of 14.2 and 14.3). Driven by sched.sh against a scratch server whose key table holds throw-away
test keys (TN_SRV, TN_PORT), with the bundles of make_sched_bundles.php in /bundles: pub1 (public 1), priv1 (private 1), pub2 (public 2, benchy/pin 1.2.0 withdrawn).

  pins     the version a job carries, the engine that can't run a pin, a withdrawn and a too-new version, the picker, labels, "Pin to", the version menu, the audit
  admin    the administrator-test rules 0 and A to D, the hold per deferred_kind and what releases it, the fix texts, hold_detail, the task text while waiting
  level    the level-1 rules (S3): adding, version, targets, times, enabled, triggers and group edits, with forged posts; level 2 may remove
  page     (C5) ?server_catalog and the root section: what they show, the web upload and its checks, the notices, the audit rows

Nothing here touches the machine's own Benchy: the server is a throw-away container.
"""
import json
import os
import re
import subprocess
import sys
import time

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'tenancy'))
import serve_e44 as sv  # noqa: E402
import tn  # noqa: E402

fails = 0
A = 'SCHD44'
SEQ = [6000]


def check(what, ok, detail=''):
    global fails
    print('  %s  %s%s' % ('PASS' if ok else 'FAIL', what, '' if ok else ' :: ' + str(detail)[:500]))
    if not ok:
        fails += 1


def sql(q):
    return tn.sql(q)


def execsql(q):
    return tn.sql_exec(q)


# ---- the fleet

def cat_entry(ref, version, title, admin=None):
    e = json.loads(json.dumps(sv.example('hello_request')['catalogue'][0]))
    e['test'] = {'ref': ref, 'version': version}
    e['title'] = title
    e['options'] = []
    if admin is not None:
        e['admin'] = admin
    return e


def hello(system, engine=None, caps=None, reason=None, tests=None):
    token, sid, mid = system['token'], system['sid'], system['mid']
    b = sv.example('hello_request')
    b['machine_id'] = mid
    b['engine']['version'] = engine or system['engine']
    b['system_info']['os']['name'] = 'Windows'
    b['engine']['capabilities'] = list(caps if caps is not None else system['caps'])
    if reason or system.get('reason'):
        b['engine']['not_set_up_reason'] = reason or system['reason']
    b['catalogue'] = tests if tests is not None else system['tests']
    status, body, _ = sv.jcall('/api/v2/agent/hello', b, token)
    assert status == 200, (status, body)
    return body


def adm(accepted=('1.1.0',), version='1.1.0'):
    return cat_entry('benchy/adm', version, 'Admin Bench', {'elevated_run': True, 'machine_install': False, 'accepted_versions': list(accepted), 'installed': None})


def inst(installed, accepted=('1.0.0',)):
    return cat_entry('benchy/inst', '1.0.0', 'Installer Bench', {'elevated_run': False, 'machine_install': True, 'accepted_versions': list(accepted), 'installed': installed})


def pin_entry(version='1.1.0'):
    return cat_entry('benchy/pin', version, 'Pin Bench')


def make_system(name, seed, engine, caps, tests, reason=None):
    token, sid, mid = sv.enroll(A, name, seed)
    s = {'token': token, 'sid': sid, 'mid': mid, 'engine': engine, 'caps': caps, 'tests': tests, 'reason': reason, 'name': name}
    hello(s)
    return s


def poll(s, **extra):
    status, body, _ = sv.jcall('/api/v2/agent/poll', sv.poll_body(s['mid'], **extra), s['token'])
    assert status == 200, (status, body)
    return body


def end(s, job, status='deferred', kind=None, reason=None):
    b = {'status': status, 'reason': reason, 'run': None}
    if kind is not None:
        b['deferred_kind'] = kind
    return sv.jcall('/api/v2/jobs/%d/end' % job['id'], b, s['token'])


def new_schedule(title, systems, tests, groups='', state=1):
    """A daily schedule due now with native rows [(ref, version or None)]; returns its id"""
    SEQ[0] += 1
    sid = SEQ[0]
    execsql("INSERT INTO phoromatic_schedules (AccountID, ScheduleID, Title, Description, State, ActiveOn, RunAt, RunTargetSystems, RunTargetGroups, RunPriority, LastModifiedBy) VALUES ('%s', %d, '%s', 'qc', %d, '0,1,2,3,4,5,6', '00.00', '%s', '%s', 100, 'qc')"
            % (A, sid, title, state, ','.join(s['sid'] for s in systems), groups))
    for i, (ref, version) in enumerate(tests):
        execsql("INSERT INTO phoromatic_schedules_native_tests (AccountID, ScheduleID, Position, Ref, OptionsJSON, Description, AddedOn, AddedBy, Version) VALUES ('%s', %d, %d, '%s', '{}', 'd', 'now', 'qc', %s)"
                % (A, sid, i, ref, 'NULL' if version is None else "'%s'" % version))
    return sid


def drop_schedule(sched):
    for t in ('phoromatic_schedules', 'phoromatic_schedules_native_tests', 'phoromatic_schedules_triggers'):
        execsql("DELETE FROM %s WHERE AccountID='%s' AND ScheduleID=%d" % (t, A, sched))
    execsql("DELETE FROM phoromatic_v2_jobs WHERE AccountID='%s' AND ScheduleID=%d" % (A, sched))


def jobs_of(sched, s=None):
    return sql("SELECT * FROM phoromatic_v2_jobs WHERE AccountID='%s' AND ScheduleID=%d%s ORDER BY JobID" % (A, sched, '' if s is None else " AND SystemID='%s'" % s['sid']))


def audit(action, sched=None):
    q = "SELECT * FROM phoromatic_admin_audit WHERE AccountID='%s' AND Action='%s'" % (A, action)
    if sched is not None:
        q += " AND SubjectID='%d'" % sched
    return sql(q + ' ORDER BY rowid')


def post_form(br, path, fields, token=True):
    data = dict(fields)
    if token:
        data['token_submit'] = br.token(path)
    return br.post(path, data)[2]


def flat(html):
    return re.sub(r'\s+', ' ', html)


# ---- the scenarios

def setup():
    r = tn.srv_sh('sh /bench/benchy server-account-create --user schedadmin --email s@example.com --account-id %s' % A, stdin=tn.PW + '\n')
    check('account made', 'Account created' in r, r)
    tn.grant_catalog(A)
    sv.admin('allow-http', 'on')
    for b in ('pub1', 'priv1'):
        out = tn.cli('server-catalog-import', '/bundles/%s.zip' % b)
        check('bundle %s imported' % b, 'Imported the' in out, out)


def pins(fleet, l1):
    new, old = fleet['new'], fleet['old']
    # a pin goes to the job; an unpinned row has no version
    s1 = new_schedule('pins one', [new], [('benchy/pin', '1.1.0'), ('benchy/adm', None)][:1])
    b = poll(new)
    check('a pinned schedule is issued to a system with catalog_v1, and the job test carries version 1.1.0', b['job'] is not None and b['job']['tests'][0].get('version') == '1.1.0', b)
    check('  and the job is valid for poll_response', sv.validate('poll_response', b) == [])
    end(new, b['job'])
    drop_schedule(s1)
    s2 = new_schedule('pins none', [new], [('benchy/pin', None)])
    b = poll(new)
    check('an unpinned row has no version key in the job', b['job'] is not None and 'version' not in b['job']['tests'][0], b)
    end(new, b['job'])
    drop_schedule(s2)
    # rule 1: a system whose engine has no catalog_v1
    s3 = new_schedule('pins mixed', [new, old], [('benchy/pin', '1.1.0')])
    b_old = poll(old)
    check('rule 1: the engine without catalog_v1 gets no job, and the hold says why', b_old['job'] is None and b_old['hold'] == 'Schedule "pins mixed" pins test versions, and this system\'s engine (0.5.0) can\'t run a chosen version. Update it to engine 0.6.0 or later.', b_old)
    check('  no job row was made for it, and the other system still gets its job', not jobs_of(s3, old) and poll(new)['job'] is not None)
    for j in jobs_of(s3, new):
        end(new, {'id': int(j['JobID'])})
    page = tn.Browser().login('schedadmin').get('/?systems/%s' % old['sid'])[2]
    check('  the old system\'s page lists it as a line it won\'t run', 'pins test versions' in page and 'Waiting on this system' in page and '0.5.0' in page)
    hello(old, engine='0.6.0', caps=['catalog_v1', 'cancel'])
    b_old = poll(old)
    check('  the trigger was not used: once the engine has catalog_v1 the schedule is issued', b_old['job'] is not None and b_old['job']['tests'][0]['version'] == '1.1.0', b_old)
    end(old, b_old['job'])
    hello(old, engine='0.5.0', caps=['cancel'])
    drop_schedule(s3)
    # an unpinned schedule still goes to the old engine
    s4 = new_schedule('pins none old', [old], [('benchy/pin', None)])
    b = poll(old)
    check('an unpinned schedule is issued to the 0.5 engine as before', b['job'] is not None, b)
    end(old, b['job'])
    drop_schedule(s4)
    # rule 3: a pinned version that needs a newer engine
    s5 = new_schedule('pins new engine', [new], [('benchy/pin', '1.3.0')])
    b = poll(new)
    check('rule 3: a version that needs engine 0.9.0 holds on 0.7.0, saying what is needed', b['job'] is None and '1.3.0 needs engine 0.9.0 or later, and this system runs 0.7.0' in (b['hold'] or ''), b)
    drop_schedule(s5)
    # a pin that is not a valid version is never sent (a hand-edited row)
    s6 = new_schedule('pins junk', [new], [('benchy/pin', 'latest')])
    b = poll(new)
    check('a row with a pin that is not x.y.z is issued without a version, never with it', b['job'] is None or 'version' not in b['job']['tests'][0], b)
    if b['job']:
        end(new, b['job'])
    drop_schedule(s6)

    # the picker and the form
    cat = [pin_entry('1.1.0')]
    a = make_system('pick-a', '1', '0.7.0', ['catalog_v1', 'cancel'], cat)
    b2 = make_system('pick-b', '2', '0.7.0', ['catalog_v1', 'cancel'], [pin_entry('1.0.0')])
    sched = new_schedule('picker', [a], [])
    path = '/?schedules/%d' % sched
    page = flat(l1.get(path)[2])
    check('picker: every target reports 1.1.0, so 1.1.0 is the default and the page says so', 'Pinned to 1.1.0, the version every system has now.' in page and '1.1.0 (what every system has now)' in page, page[-1500:])
    check('  the menu offers the catalog\'s versions, not the withdrawn or unknown ones', '<option value="1.2.0">' in page and '<option value="1.0.0">' in page and '9.9.9' not in page)
    out = flat(post_form(l1, path, {'native_test_add': 'benchy/pin'}))
    rows = sql("SELECT Version FROM phoromatic_schedules_native_tests WHERE AccountID='%s' AND ScheduleID=%d" % (A, sched))
    check('adding with the default pins the version every system has', 'pinned to 1.1.0' in out and [r['Version'] for r in rows] == ['1.1.0'], (out[:300], rows))
    a_add = audit('native_test_add', sched)
    check('  the audit row has version 1.1.0', a_add and json.loads(a_add[-1]['Detail']).get('version') == '1.1.0', a_add)
    drop_rows = lambda: execsql("DELETE FROM phoromatic_schedules_native_tests WHERE AccountID='%s' AND ScheduleID=%d" % (A, sched))
    for bad in ('9.9.9', '1.1', '1.1.0 ', 'latest', '01.1.0', '1.2.0'):
        out = post_form(l1, path, {'native_test_add': 'benchy/pin', 'opt[x]': '', 'native_test_version': bad})
    rows = sql("SELECT COUNT(*) AS n FROM phoromatic_schedules_native_tests WHERE AccountID='%s' AND ScheduleID=%d" % (A, sched))
    check('a version the form was never offered (unknown, malformed, withdrawn later) adds nothing', rows[0]['n'] == 1, rows)
    out = flat(post_form(l1, path, {'native_test_add': 'benchy/pin', 'native_test_version': 'none'}))
    check('a posted "none" is an unpinned row (the key is the same options, so it is the same row: refused)', 'Nothing was added' in out, out[:300])
    drop_rows()
    page = flat(l1.get(path)[2])
    check('the picker with an empty schedule still has the test to add', 'benchy/pin' in page)
    post_form(l1, path, {'native_test_add': 'benchy/pin', 'native_test_version': 'none'})
    rows = sql("SELECT Version FROM phoromatic_schedules_native_tests WHERE AccountID='%s' AND ScheduleID=%d" % (A, sched))
    page = flat(l1.get(path)[2])
    check('"none" adds a row with no pin; the row says Not pinned and has a "Pin to 1.1.0" button', rows[0]['Version'] is None and 'Not pinned' in page and 'value="Pin to 1.1.0"' in page, (rows, page[-900:]))
    row_opts = sql("SELECT OptionsJSON FROM phoromatic_schedules_native_tests WHERE AccountID='%s' AND ScheduleID=%d" % (A, sched))[0]['OptionsJSON']
    out = flat(post_form(l1, path, {'native_test_version_set': '1', 'native_test_ref': 'benchy/pin', 'native_test_options': row_opts, 'native_test_version': '1.1.0'}))
    rows = sql("SELECT Version FROM phoromatic_schedules_native_tests WHERE AccountID='%s' AND ScheduleID=%d" % (A, sched))
    check('"Pin to" pins it, and it is audited', rows[0]['Version'] == '1.1.0' and len(audit('native_test_version_set', sched)) == 1, (out[:300], rows))
    out = flat(post_form(l1, path, {'native_test_version_set': '1', 'native_test_ref': 'benchy/pin', 'native_test_options': row_opts, 'native_test_version': '1.0.0'}))
    rows = sql("SELECT Version FROM phoromatic_schedules_native_tests WHERE AccountID='%s' AND ScheduleID=%d" % (A, sched))
    ev = json.loads(audit('native_test_version_set', sched)[-1]['Detail'])
    check('the version menu changes it (1.1.0 to 1.0.0), audited with from and to', rows[0]['Version'] == '1.0.0' and ev['from'] == '1.1.0' and ev['to'] == '1.0.0', (rows, ev))
    for bad in ('9.9.9', 'x', '', 'default'):
        post_form(l1, path, {'native_test_version_set': '1', 'native_test_ref': 'benchy/pin', 'native_test_options': row_opts, 'native_test_version': bad})
    rows = sql("SELECT Version FROM phoromatic_schedules_native_tests WHERE AccountID='%s' AND ScheduleID=%d" % (A, sched))
    check('a forged version changes nothing', rows[0]['Version'] == '1.0.0', rows)
    post_form(l1, path, {'native_test_version_set': '1', 'native_test_ref': 'benchy/pin', 'native_test_options': row_opts, 'native_test_version': 'none'})
    check('"Not pinned" in the menu unpins it', sql("SELECT Version FROM phoromatic_schedules_native_tests WHERE AccountID='%s' AND ScheduleID=%d" % (A, sched))[0]['Version'] is None)
    n0 = len(audit('native_test_version_set', sched))
    post_form(l1, path, {'native_test_version_set': '1', 'native_test_ref': 'benchy/pin', 'native_test_options': row_opts, 'native_test_version': '1.1.0'}, token=False)
    check('without the CSRF token nothing changes', len(audit('native_test_version_set', sched)) == n0 and sql("SELECT Version FROM phoromatic_schedules_native_tests WHERE AccountID='%s' AND ScheduleID=%d" % (A, sched))[0]['Version'] is None)
    drop_schedule(sched)

    # differing versions: no default, the page says who has what
    sched = new_schedule('picker differ', [a, b2], [])
    path = '/?schedules/%d' % sched
    page = flat(l1.get(path)[2])
    check('targets that report different versions: no default and the lines name each system', 'Each system runs its own newest version: pick-a 1.1.0, pick-b 1.0.0. Pick a version to make them match.' in page or 'pick-b 1.0.0, pick-a 1.1.0' in page, page[-1200:])
    post_form(l1, path, {'native_test_add': 'benchy/pin'})
    check('  adding with the default pins nothing', sql("SELECT Version FROM phoromatic_schedules_native_tests WHERE AccountID='%s' AND ScheduleID=%d" % (A, sched))[0]['Version'] is None)
    drop_rows2 = lambda: execsql("DELETE FROM phoromatic_schedules_native_tests WHERE AccountID='%s' AND ScheduleID=%d" % (A, sched))
    drop_rows2()
    post_form(l1, path, {'native_test_add': 'benchy/pin', 'native_test_version': '1.2.0'})
    check('  and picking a version from the catalog pins it', [r['Version'] for r in sql("SELECT Version FROM phoromatic_schedules_native_tests WHERE AccountID='%s' AND ScheduleID=%d" % (A, sched))] == ['1.2.0'])
    drop_schedule(sched)
    # a target without catalog_v1: nothing to choose
    sched = new_schedule('picker old', [a, old], [])
    path = '/?schedules/%d' % sched
    page = flat(l1.get(path)[2])
    check('a target without catalog_v1: not pinned, the page says which engine can\'t', 'Not pinned: ' in page and 'runs engine 0.5.0' in page and 'Version:' not in page, page[-1000:])
    post_form(l1, path, {'native_test_add': 'benchy/pin', 'native_test_version': '1.1.0'})
    check('  and a posted version is refused (nothing is added)', sql("SELECT COUNT(*) AS n FROM phoromatic_schedules_native_tests WHERE AccountID='%s' AND ScheduleID=%d" % (A, sched))[0]['n'] == 0)
    drop_schedule(sched)

    # a usage error is a failure: no bundle path, or two, exits 2 and imports nothing
    for extra in ((), ('/bundles/a.zip', '/bundles/b.zip')):
        r = subprocess.run(['docker', 'exec', tn.SRV, 'sh', '/bench/benchy', 'server-catalog-import'] + list(extra), capture_output=True, text=True)
        check('server-catalog-import with %d arguments prints Usage and exits 2' % len(extra), r.returncode == 2 and 'Usage: server-catalog-import BUNDLE.zip' in r.stdout, (r.returncode, r.stdout))
    # a withdrawn pin (import pub2: 1.2.0 is withdrawn), and the rest of rule 2
    out = tn.cli('server-catalog-import', '/bundles/pub2.zip')
    check('the second public release (withdrawing benchy/pin 1.2.0) is imported', 'sequence 2' in out, out)
    time.sleep(1.2)
    hello(new)
    s7 = new_schedule('pins withdrawn', [new], [('benchy/pin', '1.2.0')])
    b = poll(new)
    check('rule 2: a pinned version that was withdrawn is held, with the reason and the way out', b['job'] is None and b['hold'] == 'benchy/pin 1.2.0 was withdrawn: security fix. Pick another version.', b)
    page = flat(l1.get('/?schedules/%d' % s7)[2])
    check('  and the schedule page says it in red', 'benchy/pin 1.2.0 was withdrawn: security fix. Pick another version.' in page)
    execsql("UPDATE phoromatic_schedules_native_tests SET Version='1.1.0' WHERE AccountID='%s' AND ScheduleID=%d" % (A, s7))
    b = poll(new)
    check('  another version is issued straight away (nothing was used up)', b['job'] is not None and b['job']['tests'][0]['version'] == '1.1.0', b)
    end(new, b['job'])
    drop_schedule(s7)
    pickc = flat(l1.get('/?schedules/%d' % new_schedule('picker withdrawn', [new], []))[2])
    check('the picker leaves a withdrawn version out of its menu', '<option value="1.2.0">' not in pickc and '<option value="1.3.0">' in pickc, pickc[-800:])


def labels(fleet, l1):
    unatt, desk, bare = fleet['unattended'], fleet['desk'], fleet['bare']
    sched = new_schedule('labels', [unatt, desk, bare], [('benchy/pin', '1.1.0'), ('benchy/adm', '1.1.0'), ('benchy/inst', '1.0.0')])
    page = flat(l1.get('/?schedules/%d' % sched)[2])
    check('labels: the public test is a Free test, the others Non-free', 'Free test' in page and 'Non-free test' in page, page[-2500:])
    check('  an installer says what it installs, an administrator test says so', 'Installs Acme Suite for all users' in page and 'Runs as administrator' in page)
    check('  each target\'s status is on the page', 'lab-unattended: runs without asking' in page and 'lab-desk: someone at the desk approves each run' in page and 'lab-bare: not set up yet, see its page' in page, page[-2500:])
    check('  an installer that is installed and accepted is ready', 'lab-unattended: ready' in page or 'lab-desk: ready' in page, page[-2500:])
    page = flat(l1.get('/?schedules/%d' % new_schedule('labels none', [unatt], []))[2])
    drop_schedule(sched)
    fake = new_schedule('labels unknown', [unatt], [('benchy/stream', None)])
    page = flat(l1.get('/?schedules/%d' % fake)[2])
    check('a test the server\'s catalog doesn\'t hold says so and has no label', 'Not in this server\'s catalog.' in page, page[-800:])
    drop_schedule(fake)


def admin_rules(fleet, l1):
    for s in fleet.values():
        hello(s)   # the import of the second release made every catalog_v1 system say hello again
    unatt, desk, bare, e06, old = fleet['unattended'], fleet['desk'], fleet['bare'], fleet['e06'], fleet['old']
    # three targets: issued to the first two, held on the third with the fix; the trigger is not used
    sched = new_schedule('admin fleet', [unatt, desk, bare, e06], [('benchy/adm', None)])
    j1, j2, hb, h6 = poll(unatt), poll(desk), poll(bare), poll(e06)
    check('administrator test: an unattended system and an at-the-desk system get the job', j1['job'] is not None and j2['job'] is not None, (j1, j2))
    for s, j in ((unatt, j1), (desk, j2)):
        end(s, j['job'])
    check('  a system that is not set up is held with the setup fix, no job row', hb['job'] is None and hb['hold'].startswith('Schedule "admin fleet" is waiting: this system can\'t run tests that need administrator rights yet. Start the server agent from the Benchy tray') and not jobs_of(sched, bare), hb)
    check('  an engine 0.6 system gets "update the engine" and nothing else (rule 0 first)', h6['job'] is None and 'Update the engine on this system to 0.7.0 or later. It can then run tests that need administrator rights.' in h6['hold'] and 'set up' not in h6['hold'], h6)
    hello(bare, caps=['catalog_v1', 'elevation_unattended'], tests=[adm()])
    b = poll(bare)
    check('  the trigger was not used: once the system\'s hello reports a capability the schedule is issued', b['job'] is not None, b)
    end(bare, b['job'])
    hello(bare)
    drop_schedule(sched)

    # the fix text for each reason, and for Linux
    for reason, want in (('elevated_without_policy', 'User Account Control is off'), ('binary_not_protected', 'protected copy'), ('home_not_protected', 'folder isn\'t protected'),
                         ('policy_unreadable', 'policy file on this system can\'t be read safely'), ('no_desktop_client', 'Start the server agent from the Benchy tray'), (None, 'Start the server agent from the Benchy tray')):
        hello(bare, reason=reason) if reason else hello(bare, reason=None, tests=bare['tests'])
        if reason is None:
            bare['reason'] = None
        sched = new_schedule('admin fix', [bare], [('benchy/adm', None)])
        b = poll(bare)
        check('fix for not_set_up_reason %s says: %s' % (reason, want), b['job'] is None and want in (b['hold'] or ''), b)
        drop_schedule(sched)
    bare['reason'] = 'no_desktop_client'
    props = json.loads(sql("SELECT SystemProperties FROM phoromatic_systems WHERE AccountID='%s' AND SystemID='%s'" % (A, bare['sid']))[0]['SystemProperties'])
    props['benchy_system_info']['os']['name'] = 'Ubuntu'
    execsql("UPDATE phoromatic_systems SET SystemProperties='%s' WHERE AccountID='%s' AND SystemID='%s'" % (json.dumps(props).replace("'", "''"), A, bare['sid']))
    sched = new_schedule('admin linux', [bare], [('benchy/adm', None)])
    b = poll(bare)
    check('Linux, any reason: says administrator tests are Windows only, and names no setup command that does not exist', 'Windows machines only' in (b['hold'] or '') and 'system-unit' not in (b['hold'] or ''), b)
    drop_schedule(sched)
    hello(bare)

    # rule B: not accepted, and the version the job would send
    hello(unatt, tests=[adm(accepted=('1.0.0',))])
    sched = new_schedule('admin accept', [unatt], [('benchy/adm', None)])
    b = poll(unatt)
    check('rule B: 1.1.0 is not among the accepted versions: held with hold_detail', b['job'] is None and 'someone at this machine has to accept Admin Bench 1.1.0 once' in b['hold'] and b.get('hold_detail', {}).get('kind') == 'acceptance_needed'
          and b['hold_detail']['tests'] == [{'ref': 'benchy/adm', 'version': '1.1.0'}] and b['hold_detail']['fix'], b)
    check('  and the reply is valid for poll_response', sv.validate('poll_response', b) == [])
    check('  no job row, trigger not used', not jobs_of(sched, unatt))
    hello(unatt, tests=[adm(accepted=('1.0.0', '1.1.0'))])
    b = poll(unatt)
    check('  after a hello that lists it, the schedule is issued', b['job'] is not None and 'hold_detail' not in b, b)
    end(unatt, b['job'])
    drop_schedule(sched)
    # 0.16.1: the same hold_detail while nothing is due, and the visible holds on the server's pages
    hello(unatt, tests=[adm(accepted=('1.0.0',))])
    sched = new_schedule('admin not due', [unatt], [('benchy/adm', None)])
    execsql("UPDATE phoromatic_schedules SET ActiveOn='' WHERE AccountID='%s' AND ScheduleID=%d" % (A, sched))
    b = poll(unatt)
    check('0.16.1: a schedule that is not due still gives hold_detail (acceptance_needed, the test) and no job', b['job'] is None and b.get('hold_detail', {}).get('kind') == 'acceptance_needed'
          and b['hold_detail']['tests'] == [{'ref': 'benchy/adm', 'version': '1.1.0'}] and 'schedules' not in b['hold_detail'], b)
    check('  and the reply is valid for poll_response', sv.validate('poll_response', b) == [])
    page = flat(l1.get('/?schedules/%d' % sched)[2])
    check('  the schedule page says it is held on 1 of 1 machines', 'Held on 1 of 1 machine: needs acceptance at the machine (benchy/adm 1.1.0)' in page, page[-1500:])
    check('  the systems list has the badge and the dashboard the line', 'Needs acceptance' in flat(l1.get('/?systems')[2]) and '1 system is holding schedules until a test is accepted' in flat(l1.get('/')[2]))
    hello(unatt, tests=[adm(accepted=('1.1.0',))])
    b = poll(unatt)
    check('  after the acceptance the hold_detail is gone, and so are the badge and the line', 'hold_detail' not in b and 'Needs acceptance' not in flat(l1.get('/?systems')[2]) and 'holding schedules until a test' not in flat(l1.get('/')[2]), b)
    drop_schedule(sched)
    hello(unatt, tests=[adm(accepted=('1.1.0',))])
    sched = new_schedule('admin pinned other', [unatt], [('benchy/adm', '1.0.0')])
    b = poll(unatt)
    check('rule B uses the job\'s version: pinned to 1.0.0 on a system that has accepted only 1.1.0 is held', b['job'] is None and b.get('hold_detail', {}).get('tests') == [{'ref': 'benchy/adm', 'version': '1.0.0'}], b)
    drop_schedule(sched)

    # machine install: rule A on `installed`, and unknown for another version
    hello(bare, caps=['catalog_v1'], tests=[inst(False)])
    sched = new_schedule('inst missing', [bare], [('benchy/inst', None)])
    b = poll(bare)
    check('rule A: an installer not installed on a not-set-up system is held', b['job'] is None and 'can\'t run tests that need administrator rights yet' in (b['hold'] or ''), b)
    drop_schedule(sched)
    hello(bare, caps=['catalog_v1'], tests=[inst(True)])
    sched = new_schedule('inst there', [bare], [('benchy/inst', None)])
    b = poll(bare)
    check('  installed and accepted: it runs (no administrator rights are needed)', b['job'] is not None, b)
    if b['job']:
        end(bare, b['job'])
    drop_schedule(sched)
    hello(bare, caps=['catalog_v1'], tests=[inst(False, accepted=('1.0.0',))])
    execsql("UPDATE phoromatic_schedules_native_tests SET Version=Version")  # (no-op: keeps the statement shape)
    sched = new_schedule('inst other version', [bare], [('benchy/inst', '1.0.0')])
    # reported 1.0.0 and installed false: the same version, so rule A holds; with a different pinned version `installed` is unknown
    b = poll(bare)
    check('  (control) the pin equals the reported version: installed is false, held', b['job'] is None, b)
    drop_schedule(sched)
    tests = [inst(False)]
    tests[0]['test']['version'] = '0.9.0'
    hello(bare, caps=['catalog_v1'], tests=tests)
    sched = new_schedule('inst other', [bare], [('benchy/inst', '1.0.0')])
    execsql("UPDATE phoromatic_system_catalogue SET TestJSON = REPLACE(TestJSON, '\"accepted_versions\":[\"1.0.0\"]', '\"accepted_versions\":[\"1.0.0\"]') WHERE AccountID='%s'" % A)
    b = poll(bare)
    check('  the pin differs from the reported version: `installed` is unknown, rule A does not hold for it, the agent decides', b['job'] is not None, b)
    if b['job']:
        end(bare, b['job'])
    drop_schedule(sched)
    hello(bare)

    # mixed schedule: held whole, and what runs where
    sched = new_schedule('admin mixed', [unatt, bare], [('benchy/pin', None), ('benchy/adm', None)])
    b = poll(bare)
    check('a schedule that mixes an ordinary test with an administrator test is held whole on a system that can\'t run it', b['job'] is None and not jobs_of(sched, bare), b)
    b = poll(unatt)
    check('  and goes whole to a system that can', b['job'] is not None and [t['ref'] for t in b['job']['tests']] == ['benchy/pin', 'benchy/adm'], b)
    end(unatt, b['job'])
    drop_schedule(sched)

    # rule C: every deferred_kind
    for kind in ('approval_declined', 'uac_declined'):
        sched = new_schedule('defer ' + kind, [desk], [('benchy/adm', None)])
        other = new_schedule('defer other ' + kind, [desk], [('benchy/pin', None)])
        execsql("UPDATE phoromatic_schedules SET RunPriority = 200 WHERE AccountID='%s' AND ScheduleID=%d" % (A, sched))
        b = poll(desk)
        check('%s: the administrator schedule is issued' % kind, b['job'] is not None and b['job']['schedule']['id'] == sched, b)
        status, body, _ = end(desk, b['job'], kind=kind)
        check('  the end with its kind is accepted', status == 200, body)
        row = jobs_of(sched, desk)[0]
        check('  the job is stored deferred with DeferredKind %s, never failed' % kind, row['EndStatus'] == 'deferred' and row['DeferredKind'] == kind, row)
        b = poll(desk)
        check('  the next poll holds that schedule on that system, and another schedule still runs', b['job'] is not None and b['job']['schedule']['id'] == other, b)
        end(desk, b['job'])
        execsql("UPDATE phoromatic_v2_jobs SET EndStatus='cancelled', EndReason='agent_stopped' WHERE AccountID='%s' AND ScheduleID=%d" % (A, other))
        drop_schedule(other)
        b = poll(desk)
        check('  with nothing else to run: held, with the hold text, and no new job row', b['job'] is None and 'nobody approved its last run at this machine' in (b['hold'] or '') and len(jobs_of(sched, desk)) == 1, b)
        b = poll(desk, ready_for_approval={'schedule_id': sched + 1})
        check('  a ready click for another schedule doesn\'t release it', b['job'] is None, b)
        b = poll(unatt) if False else None
        b = poll(desk, ready_for_approval={'schedule_id': sched})
        check('  a ready click for this schedule issues it at once', b['job'] is not None and b['job']['schedule']['id'] == sched, b)
        end(desk, b['job'], kind=kind)
        check('  held again after the next decline', poll(desk)['job'] is None)
        execsql("UPDATE phoromatic_v2_jobs SET EndedOn = datetime('now', 'localtime', '-7 hours') WHERE AccountID='%s' AND ScheduleID=%d" % (A, sched))
        b = poll(desk)
        check('  after 6 hours it is offered again', b['job'] is not None, b)
        end(desk, b['job'])
        check('  (a trigger that was never used: every job of it ended deferred)', all(r['EndStatus'] == 'deferred' for r in jobs_of(sched, desk)))
        drop_schedule(sched)
    # not_set_up: released by a hello that reports a capability
    sched = new_schedule('defer setup', [unatt], [('benchy/adm', None)])
    b = poll(unatt)
    end(unatt, b['job'], kind='not_set_up')
    check('not_set_up: held after the deferral, even though the system looks set up (the state changed between hello and poll)', poll(unatt)['job'] is None)
    time.sleep(1.2)
    hello(unatt, tests=[adm()])
    b = poll(unatt)
    check('  a later hello that reports elevation_unattended releases it', b['job'] is not None, b)
    end(unatt, b['job'])
    drop_schedule(sched)
    # acceptance_needed
    sched = new_schedule('defer accept', [unatt], [('benchy/adm', None)])
    b = poll(unatt)
    end(unatt, b['job'], kind='acceptance_needed')
    b = poll(unatt)
    check('acceptance_needed: held with the acceptance detail', b['job'] is None and b.get('hold_detail', {}).get('kind') == 'acceptance_needed', b)
    time.sleep(1.2)
    hello(unatt, tests=[adm(accepted=('1.1.0',))])
    check('  a hello that holds the version releases it', poll(unatt)['job'] is not None)
    execsql("UPDATE phoromatic_v2_jobs SET State='ended', EndStatus='cancelled' WHERE AccountID='%s' AND ScheduleID=%d AND State<>'ended'" % (A, sched))
    drop_schedule(sched)
    # install_needs_setup holds the same way, and the old meaning and an unknown word hold nothing
    for kind in ('install_needs_setup', None, 'a_future_word'):
        sched = new_schedule('defer ' + str(kind), [desk], [('benchy/adm', None)])
        b = poll(desk)
        end(desk, b['job'], kind=kind)
        b2 = poll(desk)
        if kind == 'install_needs_setup':
            check('install_needs_setup: held', b2['job'] is None, b2)
        else:
            check('a deferral with %s holds nothing (the old meaning)' % ('no kind' if kind is None else 'a word this server doesn\'t know'), b2['job'] is not None, b2)
            end(desk, b2['job'])
        drop_schedule(sched)
    # the kind is a word, or the end is refused
    sched = new_schedule('defer bad', [desk], [('benchy/adm', None)])
    b = poll(desk)
    status, body, _ = end(desk, b['job'], kind='Bad Kind!')
    check('a deferred_kind that is not a plain word is a 400, and the job is not ended', status == 400 and jobs_of(sched, desk)[0]['State'] != 'ended', (status, body))
    status, body, _ = end(desk, b['job'], status='failed', kind='not_set_up')
    check('a kind sent with another status is ignored', status == 200 and jobs_of(sched, desk)[0]['DeferredKind'] is None, jobs_of(sched, desk))
    drop_schedule(sched)

    # the task text while the agent waits for a person
    sched = new_schedule('awaiting', [desk], [('benchy/adm', None)])
    b = poll(desk)
    ev = {'v': 1, 'type': 'log', 'seq': 1, 'ts': '2026-11-16T10:00:00.000Z', 'run': '20261116T100000Z-b0ece1', 'level': 'notice', 'text': 'waiting', 'source': 'engine', 'detail': {'kind': 'awaiting_approval'}}
    status, body, _ = sv.jcall('/api/v2/jobs/%d/events' % b['job']['id'], {'events': [ev]}, desk['token'])
    task = sql("SELECT CurrentTask FROM phoromatic_systems WHERE AccountID='%s' AND SystemID='%s'" % (A, desk['sid']))[0]['CurrentTask']
    check('the awaiting_approval notice sets the system\'s task text', status == 200 and task == 'Waiting for administrator approval at the machine', (status, body, task))
    end(desk, b['job'])
    drop_schedule(sched)
    page = flat(l1.get('/?systems/%s' % unatt['sid'])[2])
    check('the system page says what runs as administrator', 'Administrator tests' in page and 'Run with no prompt' in page, page[-1500:])
    page = flat(l1.get('/?systems/%s' % bare['sid'])[2])
    check('  and what is missing when not set up', 'Not set up yet.' in page, page[-1500:])
    page = flat(l1.get('/?systems/%s' % e06['sid'])[2])
    check('  and to update an engine older than 0.7.0', 'Update the engine on this system to 0.7.0 or later' in page, page[-1500:])


def level_rules(fleet, l1, l2, viewer):
    for s in fleet.values():
        hello(s)   # the admin scenarios left some systems reporting less
    unatt, desk = fleet['unattended'], fleet['desk']
    sched = new_schedule('level plain', [unatt], [])
    path = '/?schedules/%d' % sched
    out = flat(post_form(l2, path, {'native_test_add': 'benchy/adm', 'native_test_version': 'default'}))
    check('level 2 can\'t add a test that needs administrator rights, and the page says why', 'An account administrator has to change this' in out and not sql("SELECT 1 FROM phoromatic_schedules_native_tests WHERE AccountID='%s' AND ScheduleID=%d" % (A, sched)), out[:400])
    out = flat(post_form(l2, path, {'native_test_add': 'benchy/inst'}))
    check('  nor a machine-install test', 'An account administrator has to change this' in out and not sql("SELECT 1 FROM phoromatic_schedules_native_tests WHERE AccountID='%s' AND ScheduleID=%d" % (A, sched)), out[:400])
    page = flat(l2.get(path)[2])
    check('  the Add button is disabled for them', re.search(r'value="Add" disabled="disabled"', page) is not None and 'An account administrator has to change this' in page)
    out = flat(post_form(l2, path, {'native_test_add': 'benchy/pin'}))
    check('level 2 can still add an ordinary test', 'Added' in out, re.findall(r'(?:Added|Nothing|None of|An account)[^<]{0,200}', out)[:3])
    execsql("DELETE FROM phoromatic_schedules_native_tests WHERE AccountID='%s' AND ScheduleID=%d" % (A, sched))
    out = flat(post_form(l1, path, {'native_test_add': 'benchy/adm'}))
    row = sql("SELECT Version FROM phoromatic_schedules_native_tests WHERE AccountID='%s' AND ScheduleID=%d" % (A, sched))
    check('level 1 can add it, and the audit has admin and version', 'Added' in out and len(row) == 1, (out[:300], row))
    a = audit('native_test_add', sched)
    d = json.loads(a[-1]['Detail']) if a else {}
    check('  native_test_add holds version and admin (elevated_run)', d.get('admin', {}).get('elevated_run') is True and 'version' in d, d)
    c = audit('admin_schedule_changed', sched)
    check('  and one admin_schedule_changed row names the field and the test', len(c) == 1 and json.loads(c[0]['Detail'])['field'] == 'tests' and json.loads(c[0]['Detail'])['tests'] == ['benchy/adm'], c)
    # level 2 and the admin schedule
    row_opts = sql("SELECT OptionsJSON FROM phoromatic_schedules_native_tests WHERE AccountID='%s' AND ScheduleID=%d" % (A, sched))[0]['OptionsJSON']
    post_form(l2, path, {'native_test_version_set': '1', 'native_test_ref': 'benchy/adm', 'native_test_options': row_opts, 'native_test_version': '1.0.0'})
    check('level 2 can\'t change the version of an administrator test', sql("SELECT Version FROM phoromatic_schedules_native_tests WHERE AccountID='%s' AND ScheduleID=%d" % (A, sched))[0]['Version'] != '1.0.0')
    post_form(l1, path, {'native_test_version_set': '1', 'native_test_ref': 'benchy/adm', 'native_test_options': row_opts, 'native_test_version': '1.0.0'})
    check('  level 1 can, and it is an admin_schedule_changed row (field version)', sql("SELECT Version FROM phoromatic_schedules_native_tests WHERE AccountID='%s' AND ScheduleID=%d" % (A, sched))[0]['Version'] == '1.0.0'
          and any(json.loads(r['Detail'])['field'] == 'version' for r in audit('admin_schedule_changed', sched)))
    # enabled
    post_form(l2, '/?schedules/%d/deactivate' % sched, {})
    check('level 2 can\'t switch an administrator schedule off', sql("SELECT State FROM phoromatic_schedules WHERE AccountID='%s' AND ScheduleID=%d" % (A, sched))[0]['State'] == 1)
    post_form(l1, '/?schedules/%d/deactivate' % sched, {})
    check('  level 1 can, audited as field enabled', sql("SELECT State FROM phoromatic_schedules WHERE AccountID='%s' AND ScheduleID=%d" % (A, sched))[0]['State'] == 0 and any(json.loads(r['Detail'])['field'] == 'enabled' for r in audit('admin_schedule_changed', sched)))
    post_form(l2, '/?schedules/%d/activate' % sched, {})
    check('  and level 2 can\'t switch it back on', sql("SELECT State FROM phoromatic_schedules WHERE AccountID='%s' AND ScheduleID=%d" % (A, sched))[0]['State'] == 0)
    post_form(l1, '/?schedules/%d/activate' % sched, {})
    # triggers
    execsql("INSERT INTO phoromatic_schedules_triggers (AccountID, ScheduleID, Trigger, TriggeredOn) VALUES ('%s', %d, 'trig1', '2026-11-16 00:00:00')" % (A, sched))
    post_form(l2, '/?schedules/%d/delete-trigger/%s' % (sched, __import__('base64').b64encode(b'trig1').decode()), {})
    check('level 2 can\'t delete a trigger of an administrator schedule', len(sql("SELECT 1 FROM phoromatic_schedules_triggers WHERE AccountID='%s' AND ScheduleID=%d" % (A, sched))) == 1)
    post_form(l1, '/?schedules/%d/delete-trigger/%s' % (sched, __import__('base64').b64encode(b'trig1').decode()), {})
    check('  level 1 can', not sql("SELECT 1 FROM phoromatic_schedules_triggers WHERE AccountID='%s' AND ScheduleID=%d" % (A, sched)))
    # targets, times (the edit form), from a level-2 and a level-1 browser
    form = {'schedule_title': 'level plain', 'schedule_description': 'qc', 'run_on_systems[]': [unatt['sid']], 'schedule_hour': '00', 'schedule_minute': '00', 'days_active[]': ['0', '1', '2', '3', '4', '5', '6'], 'run_priority': '100'}

    def edit(br, **over):
        f = dict(form)
        f.update(over)
        import urllib.parse
        data = urllib.parse.urlencode(f, doseq=True) + '&token_submit=' + br.token('/?sched/%d' % sched)
        return br.request('/?sched/%d' % sched, raw=data.encode(), headers={'Content-Type': 'application/x-www-form-urlencoded'})[2]
    before = sql("SELECT RunTargetSystems, RunAt, ActiveOn, State FROM phoromatic_schedules WHERE AccountID='%s' AND ScheduleID=%d" % (A, sched))[0]
    out = flat(edit(l2, **{'run_on_systems[]': [unatt['sid'], desk['sid']]}))
    check('level 2 can\'t add a system to an administrator schedule\'s targets', 'An account administrator has to change this' in out and sql("SELECT RunTargetSystems FROM phoromatic_schedules WHERE AccountID='%s' AND ScheduleID=%d" % (A, sched))[0] == {'RunTargetSystems': before['RunTargetSystems']}, out[:400])
    out = flat(edit(l2, schedule_hour='05'))
    check('  nor change its time', sql("SELECT RunAt FROM phoromatic_schedules WHERE AccountID='%s' AND ScheduleID=%d" % (A, sched))[0]['RunAt'] == before['RunAt'], out[:300])
    out = flat(edit(l2, **{'days_active[]': ['1']}))
    check('  nor its days', sql("SELECT ActiveOn FROM phoromatic_schedules WHERE AccountID='%s' AND ScheduleID=%d" % (A, sched))[0]['ActiveOn'] == before['ActiveOn'])
    n0 = len(audit('admin_schedule_changed', sched))
    edit(l2, schedule_title='level plain renamed')
    check('  but a title edit that changes none of those is allowed', sql("SELECT Title FROM phoromatic_schedules WHERE AccountID='%s' AND ScheduleID=%d" % (A, sched))[0]['Title'] == 'level plain renamed' and len(audit('admin_schedule_changed', sched)) == n0)
    edit(l1, **{'run_on_systems[]': [unatt['sid'], desk['sid']]})
    after = sql("SELECT RunTargetSystems FROM phoromatic_schedules WHERE AccountID='%s' AND ScheduleID=%d" % (A, sched))[0]['RunTargetSystems']
    check('level 1 can, and each changed field is audited with its old and new value', set(after.split(',')) == {unatt['sid'], desk['sid']} and any(json.loads(r['Detail'])['field'] == 'systems' for r in audit('admin_schedule_changed', sched)))
    # the buttons are disabled for level 2 on an administrator schedule, and the POSTs are refused (forged posts included)
    page = flat(l2.get(path)[2])
    check('level 2 sees Activate/Deactivate and Run now disabled (Skip stays), with the explanation', all(re.search(r'value="%s"[^>]*disabled="disabled"' % v, page) for v in ('Deactivate Schedule', 'Run Test Schedule Now')) and not re.search(r'value="Skip Current Test Ticket"[^>]*disabled', page) and 'An account administrator has to change this' in page, page[-1800:])
    page = flat(l1.get(path)[2])
    check('  level 1 sees them enabled', not re.search(r'value="(Run Test Schedule Now|Skip Current Test Ticket)"[^>]*disabled', page))
    execsql("INSERT INTO phoromatic_schedules_triggers (AccountID, ScheduleID, Trigger, TriggeredOn) VALUES ('%s', %d, 'trig2', '2026-11-16 00:00:00')" % (A, sched))
    check('level 2 sees Remove Trigger disabled', re.search(r'value="Remove Trigger"[^>]*disabled="disabled"', flat(l2.get(path)[2])) is not None)
    execsql("DELETE FROM phoromatic_schedules_triggers WHERE AccountID='%s' AND ScheduleID=%d" % (A, sched))
    form_page = l2.get('/?sched/%d' % sched)[2]
    check('level 2 sees the edit form\'s systems, days and time disabled, the current values carried in hidden fields, and the explanation',
          form_page.count('name="run_on_systems[]"') >= 2 and re.search(r'<input type="checkbox" disabled="disabled" name="run_on_systems\[\]"', form_page) and re.search(r'<select disabled="disabled" name="schedule_hour"', form_page)
          and 'type="hidden" name="schedule_hour"' in form_page and 'type="hidden" name="run_on_systems[]"' in form_page and 'An account administrator has to change this' in form_page, form_page[:200])
    check('  level 1 sees them enabled', 'disabled="disabled" name="run_on_systems[]"' not in tn.Browser().login('schedadmin').get('/?sched/%d' % sched)[2])
    n0 = len(sql("SELECT 1 FROM phoromatic_schedules_triggers WHERE AccountID='%s' AND ScheduleID=%d" % (A, sched)))
    out = flat(post_form(l2, path, {'do_manual_test_run': '1'}))
    check('a forged Run now from level 2 adds no trigger and says why', len(sql("SELECT 1 FROM phoromatic_schedules_triggers WHERE AccountID='%s' AND ScheduleID=%d" % (A, sched))) == n0 and 'An account administrator has to change this' in out)
    n1 = len(audit('admin_schedule_changed', sched))
    post_form(l2, path, {'skip_current_ticket': '1'})
    check('level 2 may Skip (it only narrows what runs), and it is audited as skip_ticket', sql("SELECT 1 FROM phoromatic_schedules_trigger_skips WHERE AccountID='%s' AND ScheduleID=%d" % (A, sched))
          and [json.loads(r['Detail'])['field'] for r in audit('admin_schedule_changed', sched)][n1:] == ['skip_ticket'])
    execsql("DELETE FROM phoromatic_schedules_trigger_skips WHERE AccountID='%s' AND ScheduleID=%d" % (A, sched))
    n1 = len(audit('admin_schedule_changed', sched))
    post_form(l1, path, {'do_manual_test_run': '1'})
    check('level 1 can Run now, audited as run_now', len(sql("SELECT 1 FROM phoromatic_schedules_triggers WHERE AccountID='%s' AND ScheduleID=%d" % (A, sched))) == n0 + 1
          and [json.loads(r['Detail'])['field'] for r in audit('admin_schedule_changed', sched)][n1:] == ['run_now'])
    post_form(l2, path, {'do_manual_test_run': '1'}, token=False)
    plainp = new_schedule('level plain2', [unatt], [('benchy/pin', None)])
    post_form(l2, '/?schedules/%d' % plainp, {'do_manual_test_run': '1'})
    check('level 2 can still Run now on an ordinary schedule', len(sql("SELECT 1 FROM phoromatic_schedules_triggers WHERE AccountID='%s' AND ScheduleID=%d" % (A, plainp))) == 1)
    drop_schedule(plainp)
    # forged posts: a viewer, and no token
    post_form(viewer, path, {'native_test_remove': row_opts, 'native_test_ref': 'benchy/adm'}, token=False)
    check('a viewer\'s forged remove changes nothing', len(sql("SELECT 1 FROM phoromatic_schedules_native_tests WHERE AccountID='%s' AND ScheduleID=%d" % (A, sched))) == 1)
    # removal is allowed at level 2, audited
    n0 = len(audit('admin_schedule_changed', sched))
    post_form(l2, path, {'native_test_remove': row_opts, 'native_test_ref': 'benchy/adm'})
    check('level 2 may remove an administrator test, and it is audited the same way', not sql("SELECT 1 FROM phoromatic_schedules_native_tests WHERE AccountID='%s' AND ScheduleID=%d" % (A, sched)) and len(audit('admin_schedule_changed', sched)) == n0 + 1)
    drop_schedule(sched)

    # group edits: the indirect way to aim a schedule at another machine
    execsql("INSERT INTO phoromatic_groups (AccountID, GroupName) VALUES ('%s', 'labs')" % A)
    gsched = new_schedule('group admin', [], [('benchy/adm', None)], groups='labs')
    plain = new_schedule('group plain', [], [('benchy/pin', None)], groups='plainlabs')
    execsql("INSERT INTO phoromatic_groups (AccountID, GroupName) VALUES ('%s', 'plainlabs')" % A)
    sid = desk['sid']

    def regroup(br, groups):
        data = {'system_group_update': '1', 'groups_%s[]' % sid: groups, 'token_submit': br.token('/?systems')}
        import urllib.parse
        return br.request('/?systems', raw=urllib.parse.urlencode(data, doseq=True).encode(), headers={'Content-Type': 'application/x-www-form-urlencoded'})[2]

    def groups_of():
        return sql("SELECT Groups FROM phoromatic_systems WHERE AccountID='%s' AND SystemID='%s'" % (A, sid))[0]['Groups']
    execsql("UPDATE phoromatic_systems SET Groups = NULL WHERE AccountID='%s'" % A)
    regroup(l2, ['labs'])
    check('level 2 can\'t put a system in a group that an enabled administrator schedule runs on', not groups_of(), groups_of())
    regroup(l2, ['plainlabs'])
    check('  but can use a group only an ordinary schedule runs on', groups_of() == '#plainlabs#', groups_of())
    regroup(l1, ['labs'])
    check('level 1 can, and the audit names the schedule and the system', groups_of() == '#labs#' and any(json.loads(r['Detail'])['field'] == 'groups' for r in audit('admin_schedule_changed', gsched)), audit('admin_schedule_changed', gsched))
    regroup(l2, [''])
    check('level 2 can take a system out of such a group (that only narrows)', not groups_of(), groups_of())
    execsql("UPDATE phoromatic_schedules SET State = 0 WHERE AccountID='%s' AND ScheduleID=%d" % (A, gsched))
    regroup(l2, ['labs'])
    check('a switched-off administrator schedule isn\'t aimed at anything: level 2 may use its group', groups_of() == '#labs#', groups_of())
    drop_schedule(gsched)
    drop_schedule(plain)


def review_fixes(fleet, l1, l2):
    """AP-5's C4 findings: case-variant groups (M1), the trigger URL (M2), engines that can't be described (S1), the unpin menu (S2), another tenant's schedule id"""
    for s in fleet.values():
        hello(s)
    unatt, desk, old, e06 = fleet['unattended'], fleet['desk'], fleet['old'], fleet['e06']
    # M1: "LABS" is "labs" to the scheduler, so it is to the rule too
    execsql("INSERT OR IGNORE INTO phoromatic_groups (AccountID, GroupName) VALUES ('%s', 'labs')" % A)
    gsched = new_schedule('group admin case', [], [('benchy/adm', None)], groups='labs')
    sid = desk['sid']
    execsql("UPDATE phoromatic_systems SET Groups = NULL WHERE AccountID='%s'" % A)

    def groups_of():
        return sql("SELECT Groups FROM phoromatic_systems WHERE AccountID='%s' AND SystemID='%s'" % (A, sid))[0]['Groups']

    def posted(br, data):
        import urllib.parse
        data = dict(data, token_submit=br.token('/?systems'))
        return br.request('/?systems', raw=urllib.parse.urlencode(data, doseq=True).encode(), headers={'Content-Type': 'application/x-www-form-urlencoded'})[2]
    posted(l2, {'system_group_update': '1', 'groups_%s[]' % sid: ['LABS']})
    check('M1: level 2 can\'t put a system in "LABS" when an administrator schedule runs on "labs" (update form)', not groups_of(), groups_of())
    posted(l2, {'new_group': 'LABS', 'systems_for_group[]': [sid]})
    check('  nor through a new group of that name', not groups_of(), groups_of())
    posted(l2, {'system_group_update': '1', 'groups_%s[]' % sid: ['LaBs']})
    check('  nor a third spelling', not groups_of(), groups_of())
    posted(l1, {'system_group_update': '1', 'groups_%s[]' % sid: ['LABS']})
    check('  level 1 can, audited', groups_of() == '#LABS#' and any(json.loads(r['Detail'])['field'] == 'groups' for r in audit('admin_schedule_changed', gsched)), groups_of())
    drop_schedule(gsched)
    execsql("UPDATE phoromatic_systems SET Groups = NULL WHERE AccountID='%s'" % A)

    # M2: the unauthenticated trigger URL
    adm_s = new_schedule('event admin', [unatt], [('benchy/adm', None)])
    plain_s = new_schedule('event plain', [unatt], [('benchy/pin', None)])
    execsql("UPDATE phoromatic_schedules SET PublicKey='KEYADMIN1234' WHERE AccountID='%s' AND ScheduleID=%d" % (A, adm_s))
    execsql("UPDATE phoromatic_schedules SET PublicKey='KEYPLAIN1234' WHERE AccountID='%s' AND ScheduleID=%d" % (A, plain_s))
    anon = tn.Browser()
    out = anon.get('/event.php?type=trigger&user=schedl2&public_key=KEYADMIN1234&trigger=x1')[2]
    check('M2: a level-2 user\'s trigger URL for an administrator schedule is refused and records nothing', 'account administrator' in out and not sql("SELECT 1 FROM phoromatic_schedules_triggers WHERE AccountID='%s' AND ScheduleID=%d" % (A, adm_s)), out[:200])
    out = anon.get('/event.php?type=trigger&user=schedadmin&public_key=KEYADMIN1234&trigger=x2')[2]
    check('  a level-1 user\'s is accepted and audited as run_now', 'added' in out and len(sql("SELECT 1 FROM phoromatic_schedules_triggers WHERE AccountID='%s' AND ScheduleID=%d" % (A, adm_s))) == 1
          and [json.loads(r['Detail'])['field'] for r in audit('admin_schedule_changed', adm_s)] == ['run_now'], out[:200])
    out = anon.get('/event.php?type=trigger&user=schedl2&public_key=KEYPLAIN1234&trigger=x3')[2]
    check('  a level-2 user can still trigger an ordinary schedule, with no admin audit row', 'added' in out and not audit('admin_schedule_changed', plain_s), out[:200])
    drop_schedule(adm_s)
    drop_schedule(plain_s)

    # S1: a test that needs administrator rights, when nothing describes the version the job will send
    s1 = new_schedule('undescribed unpinned', [old], [('benchy/adm', None)])
    b = poll(old)
    check('S1: an engine older than 0.7.0 that never reported the test is held with "update the engine", not given a job that fails', b['job'] is None and 'Update the engine on this system to 0.7.0 or later' in (b['hold'] or ''), b)
    drop_schedule(s1)
    s2 = new_schedule('undescribed pinned', [e06], [('benchy/adm', '9.0.0')])
    b = poll(e06)
    check('  and so is a pin to a version the server doesn\'t hold while the system reports another (since catalog suites, rule 2b says so first: the catalog dropped that version)', b['job'] is None and 'benchy/adm 9.0.0 is no longer in this server\'s catalog. Pick another version.' == (b['hold'] or ''), b)
    drop_schedule(s2)
    s3 = new_schedule('undescribed ordinary', [old], [('benchy/pin', None)])
    b = poll(old)
    check('  (control) an ordinary test is unaffected', b['job'] is not None, b)
    if b['job']:
        end(old, b['job'])
    drop_schedule(s3)

    # S2: "Not pinned" is on the menu even when a target can't run a chosen version
    s4 = new_schedule('unpin old', [old], [('benchy/pin', '1.1.0')])
    page = flat(l1.get('/?schedules/%d' % s4)[2])
    check('S2: a pinned row on a schedule with a catalog_v1-less target has a menu with "Not pinned"', 'value="Change version"' in page and '<option value="none">Not pinned</option>' in page, page[-1200:])
    row_opts = sql("SELECT OptionsJSON FROM phoromatic_schedules_native_tests WHERE AccountID='%s' AND ScheduleID=%d" % (A, s4))[0]['OptionsJSON']
    post_form(l1, '/?schedules/%d' % s4, {'native_test_version_set': '1', 'native_test_ref': 'benchy/pin', 'native_test_options': row_opts, 'native_test_version': 'none'})
    check('  and choosing it unpins', sql("SELECT Version FROM phoromatic_schedules_native_tests WHERE AccountID='%s' AND ScheduleID=%d" % (A, s4))[0]['Version'] is None)
    drop_schedule(s4)

    # another tenant's schedule id in ready_for_approval releases nothing
    r = tn.srv_sh('sh /bench/benchy server-account-create --user schedother --email o@example.com --account-id SCHE44', stdin=tn.PW + '\n')
    check('a second account made', 'Account created' in r, r)
    foreign = 7777
    execsql("INSERT INTO phoromatic_schedules (AccountID, ScheduleID, Title, Description, State, ActiveOn, RunAt, RunTargetSystems, RunTargetGroups, RunPriority, LastModifiedBy) VALUES ('SCHE44', %d, 'theirs', 'x', 1, '0', '00.00', '', '', 100, 'qc')" % foreign)
    mine = new_schedule('mine held', [desk], [('benchy/adm', None)])
    b = poll(desk)
    end(desk, b['job'], kind='approval_declined')
    b = poll(desk, ready_for_approval={'schedule_id': foreign})
    check('ready_for_approval with another tenant\'s schedule id releases nothing and starts nothing there', b['job'] is None and not sql("SELECT 1 FROM phoromatic_v2_jobs WHERE ScheduleID=%d" % foreign), b)
    status, body, _ = sv.jcall('/api/v2/agent/poll', sv.poll_body(desk['mid'], ready_for_approval={'schedule_id': mine}), desk['token'])
    check('  (control) this tenant\'s own id does', body['job'] is not None, body)
    if body['job']:
        end(desk, body['job'])
    drop_schedule(mine)


def bundle_bytes(name):
    return subprocess.run(['docker', 'exec', tn.SRV, 'cat', '/bundles/%s.zip' % name], capture_output=True).stdout


def catalog_audit():
    return sql("SELECT * FROM phoromatic_server_audit WHERE Action IN ('catalog_imported','catalog_import_refused') ORDER BY AuditID")


def upload(br, path, name, data, token=True, fields=None, headers=None, page=None):
    f = {'catalog_import': '1'}
    if token:
        f['token_submit'] = br.token(page or path)
    f.update(fields or {})
    return flat(br.multipart(path, f, {'catalog_bundle': (name, data)} if data is not None else {}, headers)[2])


def page_checks(l1, l2, viewer):
    """C5: ?server_catalog and the root section (server design 8.1, 3.5, 9, 14.3)"""
    subprocess.run(['docker', 'exec', '-i', tn.SRV, 'sh', '/bench/benchy', 'phoromatic.set-root-admin-password'], input=tn.ROOT_PW + '\n', capture_output=True, text=True)
    root = tn.Browser().login_root()
    tn.make_user(l1, 'schedl1b', 2)
    execsql(tn.level_sql(1, 'schedl1b'))
    l1b = tn.Browser().login('schedl1b')
    # the account's first user is a server administrator; the second level-1 user is not
    check('control: schedl1b is a level-1 user and not a server administrator', not sql("SELECT 1 FROM phoromatic_server_roles r JOIN phoromatic_users u ON u.UserID = r.UserID WHERE u.UserName='schedl1b'"))
    page = flat(l1.get('/?server_catalog')[2])
    check('the page shows the heading, both tiers served, the release and key text', 'Test catalog' in page and 'Free</td><td>Served' in page and 'Non-free</td><td>Served' in page and 'key generation 1</td>' in page and 'older key' not in page and 'current key' not in page and 'Retired keys: none' in page, page[-1800:])
    check('  the test lists show id, version, platforms and the labels', '<td>benchy/pin</td><td>1.1.0</td>' in page and '<td>benchy/inst</td>' in page and 'Installs Acme Suite for all users' in page and 'Runs as administrator' in page, page[-2500:])
    check('  the events list shows the command-line imports (the withdrawn list is checked after the web import)', 'Withdrawn</h3>' in page and 'imported the non-free catalog' in page and '(cli) imported' in page, page[-1200:])
    check('  the upload form is multipart with a token, and the CLI command is shown', 'enctype="multipart/form-data"' in page and 'name="token_submit"' in page and 'server-catalog-import /path/to/bundle.zip' in page)
    for who, br in (('a level-1 user who is no server administrator', l1b), ('level 2', l2), ('a viewer', viewer)):
        out = flat(br.get('/?server_catalog')[2])
        check('%s: the page is refused and shows nothing of the catalog' % who, 'for server administrators' in out and 'benchy/pin' not in out and 'name="catalog_bundle"' not in out, out[-600:])
    before = catalog_audit()
    tiers_before = sql('SELECT Tier, Sequence, SignedSHA256 FROM phoromatic_catalog_tiers ORDER BY Tier')
    out = upload(l1b, '/?server_catalog', 'pub3.zip', bundle_bytes('pub3'), page='/?settings')
    check('a forged upload from a user who is no server administrator changes nothing', sql('SELECT Tier, Sequence, SignedSHA256 FROM phoromatic_catalog_tiers ORDER BY Tier') == tiers_before and len(catalog_audit()) == len(before) and 'Imported' not in out, out[-500:])
    out = upload(l2, '/?server_catalog', 'pub3.zip', bundle_bytes('pub3'), page='/?settings')
    check('  and nor from level 2', sql('SELECT Tier, Sequence FROM phoromatic_catalog_tiers ORDER BY Tier') == [{'Tier': t['Tier'], 'Sequence': t['Sequence']} for t in tiers_before] and len(catalog_audit()) == len(before))
    out = upload(l1, '/?server_catalog', 'pub3.zip', bundle_bytes('pub3'), token=False)
    check('no CSRF token: nothing is imported and nothing reaches the verifier (no audit row)', 'expired' in out and len(catalog_audit()) == len(before) and sql('SELECT Sequence FROM phoromatic_catalog_tiers WHERE Tier=\'public\'')[0]['Sequence'] < 3, out[-400:])
    out = upload(l1, '/?server_catalog', 'pub3.zip', bundle_bytes('pub3'), headers={'Origin': 'http://evil.example'})
    check('an Origin that is another site: refused the same way', 'expired' in out and len(catalog_audit()) == len(before), out[-400:])
    out = upload(l1, '/?server_catalog', 'x.zip', None)
    check('no file chosen: says so, nothing audited', 'Choose a catalog bundle' in out and len(catalog_audit()) == len(before), out[-400:])
    st, hd, raw = l1.multipart('/?server_catalog', {'catalog_import': '1', 'token_submit': l1.token('/?server_catalog')}, {'catalog_bundle': ('big.zip', b'\0' * (25 * 1048576))})
    out = flat(raw)
    check('a file over the 24 MiB web limit: the page says to use the command, nothing is audited', 'larger than this page accepts' in out and 'server-catalog-import' in out and len(catalog_audit()) == len(before), (st, out[:300], out[out.find('id="test-catalog"'):][:1200]))

    # a real import by a server administrator
    out = upload(l1, '/?server_catalog', 'pub3.zip', bundle_bytes('pub3'))
    check('a server administrator imports pub3: the outcome is the CLI\'s sentence', 'Imported the free catalog: generation 1, sequence 3 (was generation 1, sequence' in out, out[-600:])
    rows = catalog_audit()
    last = rows[-1] if len(rows) > len(before) else {}
    d = json.loads(last.get('Detail') or '{}')
    check('  one catalog_imported row: via web, the user as actor, the tier as the subject, no tenant', len(rows) == len(before) + 1 and last['Action'] == 'catalog_imported' and d.get('via') == 'web' and last['ActorType'] == 'user' and last['Actor'] == 'schedadmin' and last['SubjectID'] == 'public' and last['SubjectType'] == 'catalog_tier' and last['TenantID'] is None and d.get('sequence') == 3 and 'account ' + A in (last['ActorDetail'] or ''), last)
    check('  the tier row records the web import and the account', sql("SELECT ImportedVia, ImportedBy, ImportedAccount FROM phoromatic_catalog_tiers WHERE Tier='public'") == [{'ImportedVia': 'web', 'ImportedBy': 'schedadmin', 'ImportedAccount': A}], sql("SELECT ImportedVia, ImportedBy, ImportedAccount FROM phoromatic_catalog_tiers WHERE Tier='public'"))
    page = flat(l1.get('/?server_catalog')[2])
    check('  the page now shows sequence 3, the withdrawn version with its reason (escaped) and the new event', 'generation 1, 3</td>' in page and 'benchy/pin 1.2.0 (free): security &lt;b&gt;fix&lt;/b&gt; isn&#039;t safe' in page and '<b>fix</b>' not in page and 'schedadmin (web) imported the free catalog' in page and re.search(r'was 1 [12]\)', page), page[-1500:])
    # a refusal through the web page
    before = catalog_audit()
    out = upload(l1, '/?server_catalog', 'pub1.zip', bundle_bytes('pub1'))
    rows = catalog_audit()
    d = json.loads(rows[-1]['Detail']) if len(rows) > len(before) else {}
    check('re-importing the older release is refused (rollback) with a word and "Nothing was changed"; one catalog_import_refused row, via web', 'Refused (rollback)' in out and 'Nothing was changed' in out and len(rows) == len(before) + 1 and rows[-1]['Action'] == 'catalog_import_refused' and d.get('via') == 'web' and d.get('reason') == 'rollback', (out[-400:], rows[-1:]))
    out = upload(l1, '/?server_catalog', 'junk.zip', b'not a zip at all')
    rows2 = catalog_audit()
    check('a file that is no catalog is refused with a reason and audited, and the stored catalog is the same', 'Refused (' in out and len(rows2) == len(rows) + 1 and sql("SELECT Sequence FROM phoromatic_catalog_tiers WHERE Tier='public'")[0]['Sequence'] == 3, (out[-300:], rows2[-1:]))
    page = flat(l1.get('/?server_catalog')[2])
    check('  the refusals are in the recent events', 'and was refused (rollback)' in page and 'schedadmin (web)' in page)

    # notices
    n_clean = 'color: #b00020' in page
    check('control: no notice on a healthy catalog', not n_clean, page[:600])
    execsql("UPDATE phoromatic_catalog_tiers SET Issued='2020-01-01T00:00:00Z' WHERE Tier='private'")
    page = flat(l1.get('/?server_catalog')[2])
    check('an issue date over a year old: the non-free catalog notice', 'The non-free catalog is more than a year old. Check for a newer release.' in page, page[:800])
    st = flat(l1.get('/?server_settings')[2])
    check('?server_settings points at the catalog page instead of repeating the notice, and always links to it', 'href="?server_catalog"' in st and 'The test catalog needs attention.' in st and 'more than a year old' not in st, st[-1500:])
    execsql("UPDATE phoromatic_catalog_tiers SET Issued='%s' WHERE Tier='private'" % sql("SELECT Issued FROM phoromatic_catalog_tiers WHERE Tier='public'")[0]['Issued'])
    check('  (and it goes with the date)', 'more than a year old' not in flat(l1.get('/?server_catalog')[2]))
    # same_pair_differs stays until a later import of that tier brings a new pair
    n = len(catalog_audit())
    out = upload(l1, '/?server_catalog', 'pub3b.zip', bundle_bytes('pub3b'))
    rows = catalog_audit()
    check('the same release number with other contents is refused (same_pair_differs), and the refusal row names the tier and the account', 'Refused (same_pair_differs)' in out and len(rows) == n + 1 and rows[-1]['SubjectID'] == 'public' and 'account ' + A in (rows[-1]['ActorDetail'] or ''), (out[-300:], rows[-1:]))
    page = flat(l1.get('/?server_catalog')[2])
    check('  the red notice names the broken signing process and the tier, and the event names the tier', 'Two different catalogs with the same release number were offered for the free tier. That means the signing process or a key is broken. Tell the catalog&#039;s publisher.' in page and 'tried to import the free catalog and was refused (same_pair_differs)' in page, page[:900])
    check('  the stored catalog is the first one (sequence 3, no extra test)', sql("SELECT TestCount FROM phoromatic_catalog_tiers WHERE Tier='public'")[0]['TestCount'] == 4, sql("SELECT TestCount FROM phoromatic_catalog_tiers WHERE Tier='public'"))
    upload(l1, '/?server_catalog', 'priv1.zip', bundle_bytes('priv1'))
    check('  another tier\'s import does not clear it', 'Two different catalogs' in flat(l1.get('/?server_catalog')[2]))
    out = upload(l1, '/?server_catalog', 'pub3.zip', bundle_bytes('pub3'))
    check('  importing the same release again (unchanged) does not clear it either', 'already imported' in out and 'Two different catalogs' in flat(l1.get('/?server_catalog')[2]), out[-400:])
    out = upload(l1, '/?server_catalog', 'pub4.zip', bundle_bytes('pub4'))
    check('  a later import of that tier with a new release clears it', 'Imported the free catalog' in out and 'Two different catalogs' not in flat(l1.get('/?server_catalog')[2]), out[-400:])

    # the root login
    ra = flat(root.get('/?admin_config')[2])
    check('root: ?admin_config has the same section, the form posts to admin_config', 'id="test-catalog"' in ra and 'benchy/pin' in ra and 'action="?admin_config"' in ra and 'name="catalog_bundle"' in ra, ra[-800:])
    before = catalog_audit()
    out = upload(root, '/?admin_config', 'pub4.zip', bundle_bytes('pub4'))
    rows = catalog_audit()
    check('  a root upload goes through the same path: the audit row says root, via web, and the tier row has no account', 'already imported' in out and len(rows) == len(before) + 1 and rows[-1]['ActorType'] == 'root' and json.loads(rows[-1]['Detail'])['via'] == 'web' and rows[-1]['TenantID'] is None, (out[-300:], rows[-1:]))
    out = upload(root, '/?admin_config', 'pub1.zip', bundle_bytes('pub1'))
    check('  and a root refusal is the same refusal', 'Refused (rollback)' in out and catalog_audit()[-1]['ActorType'] == 'root')
    ev = flat(root.get('/?admin_config')[2])
    check('  the root page lists those events as "the root login"', 'the root login (web) imported' in ev, ev[-600:])
    print('  (page checks done)')


def main():
    setup()
    l1 = tn.Browser().login('schedadmin')
    tn.make_user(l1, 'schedl2', 2)
    tn.make_user(l1, 'schedview', 10)
    l2 = tn.Browser().login('schedl2')
    viewer = tn.Browser().login('schedview')
    fleet = {
        'new': make_system('lab-new', 'a', '0.7.0', ['catalog_v1', 'cancel'], [pin_entry()]),
        'old': make_system('lab-old', 'b', '0.5.0', ['cancel'], [pin_entry()]),
        'unattended': make_system('lab-unattended', 'c', '0.7.0', ['catalog_v1', 'elevation_unattended'], [adm(), inst(True), pin_entry()]),
        'desk': make_system('lab-desk', 'd', '0.7.0', ['catalog_v1', 'elevation_desktop'], [adm(), inst(True), pin_entry()]),
        'bare': make_system('lab-bare', 'e', '0.7.0', ['catalog_v1'], [adm(), pin_entry()], reason='no_desktop_client'),
        'e06': make_system('lab-e06', 'f', '0.6.0', ['catalog_v1'], [adm(), pin_entry()]),
    }
    # the systems page and the picker read these as native systems
    execsql("UPDATE phoromatic_systems SET EnginePolicy='native' WHERE AccountID='%s'" % A)
    for name, fn in (('pins', lambda: pins(fleet, l1)), ('labels', lambda: labels(fleet, l1)), ('admin', lambda: admin_rules(fleet, l1)), ('level', lambda: level_rules(fleet, l1, l2, viewer)), ('review', lambda: review_fixes(fleet, l1, l2)), ('page', lambda: page_checks(l1, l2, viewer))):
        if len(sys.argv) < 2 or sys.argv[1] in ('all', name):
            print('-- ' + name)
            fn()
    print('FAILURES %d' % fails)


if __name__ == '__main__':
    main()
