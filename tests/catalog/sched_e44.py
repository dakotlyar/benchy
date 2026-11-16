#!/usr/bin/env python3
"""E4.4 C4: schedules and the signed catalog (server design 6; refusal tests of 14.2 and 14.3). Driven by sched.sh against a scratch server whose key table holds throw-away
test keys (TN_SRV, TN_PORT), with the bundles of make_sched_bundles.php in /bundles: pub1 (public 1), priv1 (private 1), pub2 (public 2, benchy/pin 1.2.0 withdrawn).

  pins     the version a job carries, the engine that can't run a pin, a withdrawn and a too-new version, the picker, labels, "Pin to", the version menu, the audit
  admin    the administrator-test rules 0 and A to D, the hold per deferred_kind and what releases it, the fix texts, hold_detail, the task text while waiting
  level    the level-1 rules (S3): adding, version, targets, times, enabled, triggers and group edits, with forged posts; level 2 may remove

Nothing here touches the machine's own Benchy: the server is a throw-away container.
"""
import json
import os
import re
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
    check('Linux, any reason: the one-time sudo command', 'sudo benchy-engine agent setup-elevated --system-unit' in (b['hold'] or ''), b)
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
    for name, fn in (('pins', lambda: pins(fleet, l1)), ('labels', lambda: labels(fleet, l1)), ('admin', lambda: admin_rules(fleet, l1)), ('level', lambda: level_rules(fleet, l1, l2, viewer))):
        if len(sys.argv) < 2 or sys.argv[1] in ('all', name):
            print('-- ' + name)
            fn()
    print('FAILURES %d' % fails)


if __name__ == '__main__':
    main()
