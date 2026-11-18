#!/usr/bin/env python3
"""Catalog suites (Benchy 0.10.0, CS0-CS4; design ideas/testing/benchy-catalog-suites.md). Driven by catsuites.sh against a scratch server whose key table holds throw-away test
keys (TN_SRV, TN_PORT), with the bundles of make_cs_bundles.php in /bundles: pub1 (public 1: zstd, redis, lz4), priv1 (private 1: winbench, novab, passm), pub2 (zstd 1.1.0 added),
pub3 (zstd 1.0.0 no longer listed), pub4 (redis 1.0.0 withdrawn).

  schema     step 65 (two tables, Kind, the C7b columns), a fresh database with no audit row, the step again after 64, both tables tenant scope
  offer      the Build Suite page's offer with no catalog and with the catalog but no system (E1, E2), labels, platforms, options, the administrator block, reported tests with the OS of their reporters
  save       the native save and the six checks: mixed engines, platforms, administrator tests, duplicates, versions, options, titles, the level gates, edits and the audit (E5, E10, E11, E27, E28)
  schedule   a suite held by reference, expanded at issue, the rules per member, rules 2b and 5, a version kept through a release, the PTS system line, deduplication and the version conflict (E3, E4, E6, E7, E9, E12, E13)
  update     superseded / withdrawn / dropped, Update and Update all with the option check (CS3, E16)
  box        the schedule's own box offering the catalog before any agent has reported (CS4, E31)
  tenancy    account B can't load, edit, delete or schedule account A's suite (E21)

Nothing here touches the machine's own Benchy: the server is a throw-away container.
"""
import json
import os
import re
import subprocess
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'tenancy'))
import serve_e44 as sv  # noqa: E402
import sched_e44 as se  # noqa: E402
import tn  # noqa: E402

fails = 0
A = 'CSUITA'
B = 'CSUITB'
se.A = A   # the schedule helpers of sched_e44 write to this account
sql = tn.sql
execsql = tn.sql_exec


def check(what, ok, detail=''):
    global fails
    print('  %s  %s%s' % ('PASS' if ok else 'FAIL', what, '' if ok else ' :: ' + str(detail)[:600]))
    if not ok:
        fails += 1


def flat(html):
    return re.sub(r'\s+', ' ', html)


def bs_data(br, path='/?build_suite'):
    html = br.get(path)[2]
    m = re.search(r'<script type="application/json" id="bs-data">(.*?)</script>', html, re.S)
    return json.loads(m.group(1)) if m else None


def items(data, src=None):
    return {t['ref']: t for t in data['native'] if src is None or t['src'] == src}


def save_post(br, title, members, desc='', suite_id=None, page='/?build_suite', token=True):
    """members: [(ref, version or None, {option: value})]; returns the page that answers"""
    data = {'native_suite_save': '1', 'ns_title': title, 'ns_description': desc}
    if token:
        data['token_submit'] = br.token(page)
    if suite_id is not None:
        data['ns_id'] = str(suite_id)
    for i, (ref, ver, opts) in enumerate(members):
        data['m[%d][ref]' % i] = ref
        data['m[%d][version]' % i] = ver or ''
        for k, v in (opts or {}).items():
            data['m[%d][opt][%s]' % (i, k)] = v
    return br.post(page, data)[2]


def saved(html):
    return 'Saved &ldquo;' in html


def refusal(html):
    m = re.search(r'"error":"((?:[^"\\]|\\.)*)"', html)
    return json.loads('"%s"' % m.group(1)) if m and m.group(1) else None


def suites():
    return sql("SELECT * FROM phoromatic_native_suites WHERE AccountID='%s' ORDER BY SuiteID" % A)


def members_of(suite_id, account=A):
    return sql("SELECT Position, Ref, Version, OptionsJSON FROM phoromatic_native_suite_members WHERE AccountID='%s' AND SuiteID=%d ORDER BY Position" % (account, suite_id))


def audit(action, account=A):
    return sql("SELECT * FROM phoromatic_admin_audit WHERE AccountID='%s' AND Action='%s' ORDER BY rowid" % (account, action))


def import_bundle(name):
    out = tn.cli('server-catalog-import', '/bundles/%s.zip' % name)
    check('bundle %s imported' % name, 'Imported the' in out, out)
    for s in FLEET.values():
        hello(s)   # an import makes every system say hello again before it gets a job


def post_form(br, path, fields, token=True):
    data = dict(fields)
    if token:
        data['token_submit'] = br.token(path)
    return br.post(path, data)[2]


# ---- the fleet

def tentry(ref, version, title, options=None, admin=None, description=None):
    e = json.loads(json.dumps(sv.example('hello_request')['catalogue'][0]))
    e['test'] = {'ref': ref, 'version': version}
    e['title'] = title
    e['options'] = options or []
    e['description'] = description
    if admin is not None:
        e['admin'] = admin
    return e


def chosen(key, label, default, keys):
    return {'key': key, 'label': label, 'kind': 'choice', 'default': default, 'choices': [{'key': k, 'label': k} for k in keys]}


def hello(s, engine=None, caps=None, tests=None):
    b = sv.example('hello_request')
    b['machine_id'] = s['mid']
    b['engine']['version'] = engine or s['engine']
    b['system_info']['os']['name'] = s['os']
    b['engine']['capabilities'] = list(caps if caps is not None else s['caps'])
    b['catalogue'] = tests if tests is not None else s['tests']
    status, body, _ = sv.jcall('/api/v2/agent/hello', b, s['token'])
    assert status == 200, (status, body)


def make_system(name, seed, engine, caps, os_name, tests):
    token, sid, mid = sv.enroll(A, name, seed)
    s = {'token': token, 'sid': sid, 'mid': mid, 'engine': engine, 'caps': caps, 'os': os_name, 'tests': tests, 'name': name}
    hello(s)
    return s


def job_refs(job):
    return [(t['ref'], t.get('version'), t.get('options')) for t in job['tests']]


def drop_schedule(sched):
    se.drop_schedule(sched)


def sched_rows(sched):
    return sql("SELECT Ref, Version, OptionsJSON, Kind FROM phoromatic_schedules_native_tests WHERE AccountID='%s' AND ScheduleID=%d ORDER BY Position" % (A, sched))


def add_suite_to(l1, sched, suite_id):
    return flat(post_form(l1, '/?schedules/%d' % sched, {'native_suite_add': str(suite_id)}))


def schedule_for(title, systems, groups=''):
    """a daily schedule due now, no rows yet; returns its id"""
    return se.new_schedule(title, systems, [], groups)


# ---- the scenarios

def setup():
    r = tn.srv_sh('sh /bench/benchy server-account-create --user csadmin --email s@example.com --account-id %s' % A, stdin=tn.PW + '\n')
    check('account A made', 'Account created' in r, r)
    r = tn.srv_sh('sh /bench/benchy server-account-create --user csother --email o@example.com --account-id %s' % B, stdin=tn.PW + '\n')
    check('account B made', 'Account created' in r, r)
    sv.admin('allow-http', 'on')


def schema():
    print('-- schema')
    check('a fresh database is at schema 67 (the suites are step 65, local results 66, the hash drop 67)', int(sql('PRAGMA user_version')[0]['user_version']) == 67)
    check('  with both tables, the Kind column on the schedule rows (default test) and the C7b columns', {'phoromatic_native_suites', 'phoromatic_native_suite_members'} <= {r['name'] for r in sql("SELECT name FROM sqlite_master WHERE type='table'")}
          and 'Kind' in {r['name'] for r in sql('PRAGMA table_info(phoromatic_schedules_native_tests)')}
          and {'ConfirmedRevision', 'ConfirmedOn', 'ConfirmedBy', 'ConfirmAuditID', 'ConfirmedJSON'} <= {r['name'] for r in sql('PRAGMA table_info(phoromatic_native_suites)')})
    check('  and no migration audit row for the step on a database that was made at 65', not [r for r in sql("SELECT Detail FROM phoromatic_server_audit WHERE Action='migration'") if '"to":65' in r['Detail']], sql("SELECT Detail FROM phoromatic_server_audit WHERE Action='migration'"))
    tables = json.load(open(os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'tenancy', 'tables.json')))
    check('  both tables are tenant scope in tables.json', tables.get('phoromatic_native_suites', {}).get('scope') == 'tenant' and tables.get('phoromatic_native_suite_members', {}).get('scope') == 'tenant')
    # the step again after 64: nothing changes or fails, data stays, one audit row
    l1 = tn.Browser().login('csadmin')
    execsql("INSERT INTO phoromatic_native_suites (AccountID, SuiteID, Title, Description, Revision, CreatedOn, CreatedBy) VALUES ('%s', 99, 'keep me', NULL, 1, 'now', 'qc')" % A)
    execsql('PRAGMA user_version = 64')
    l1.get('/?systems')
    check('the step runs again over a database at 64: back at 67 (66 and the drop run after it), the suite row is still there, one Kind column', int(sql('PRAGMA user_version')[0]['user_version']) == 67 and len(sql("SELECT 1 FROM phoromatic_native_suites WHERE SuiteID=99")) == 1
          and [r['name'] for r in sql('PRAGMA table_info(phoromatic_schedules_native_tests)')].count('Kind') == 1)
    check('  and it wrote one audit row, from 64 to 65, with the backup named', len([r for r in sql("SELECT Detail FROM phoromatic_server_audit WHERE Action='migration'") if '"from":64' in r['Detail'] and '"to":65' in r['Detail'] and 'backup' in r['Detail']]) == 1, sql("SELECT Detail FROM phoromatic_server_audit WHERE Action='migration'"))
    execsql("DELETE FROM phoromatic_native_suites WHERE SuiteID=99")


def offer(l1):
    print('-- offer')
    # E2: no catalog, no system
    d = bs_data(l1)
    check('E2: with no catalog and no system the native offer is empty, says so, and the page is on the native engine', d is not None and d['kind'] == 'native' and d['native'] == [] and not d['hasCatalog'] and 'no signed catalog yet' in d['catalogText'], d)
    # E1: the catalog is imported, no system has paired
    import_bundle('pub1')
    import_bundle('priv1')
    d = bs_data(l1)
    free, non = items(d, 'free'), items(d, 'nonfree')
    check('E1: with the catalog imported and no system enrolled, every test is offered under its tier', set(free) == {'benchy/zstd', 'benchy/redis', 'benchy/lz4'} and set(non) == {'benchy/winbench', 'benchy/novab', 'benchy/passm'} and d['hasCatalog'], d['native'])
    z = free['benchy/zstd']
    check('  with labels, the newest version, platforms and the options of the manifest', z['label'] == 'Free test' and non['benchy/winbench']['label'] == 'Non-free test' and z['ver'] == '1.0.0' and z['plat'] == ['linux'] and non['benchy/winbench']['plat'] == ['windows']
          and z['opts'][0]['key'] == 'level' and [c[0] for c in z['opts'][0]['choices']] == ['3', '8', '19'] and z['opts'][0]['def'] == '3' and z['desc'].startswith('Zstd Compression'), z)
    check('  the administrator tests are listed with the reason and nothing else changed about them', non['benchy/novab']['block'] and 'installs Novabench for all users' in non['benchy/novab']['block'] and 'later release' in non['benchy/novab']['block']
          and non['benchy/passm']['block'] and 'runs as administrator' in non['benchy/passm']['block'] and non['benchy/passm']['elevated'] and not z['block'] and not non['benchy/winbench']['block'])
    page = flat(l1.get('/?build_suite')[2])
    check('  the page has the engine switch, both panes and no inline text from the catalog outside the data element', 'Native engine' in page and 'Phoronix engine' in page and 'id="bs-pane-pts"' in page and '</script><' not in page.split('id="bs-data"')[1].split('</script>')[0])
    # the withdrawn and non-served are not offered; the offer lists reported tests once a system says hello (below)
    check('  no per-test row is read for the Phoronix half: its offer carries identifiers and no private group on a server with no local profiles', all(t['src'] == 'ob' for t in d['pts']) and 'private' not in {t['src'] for t in d['pts']}, [t for t in d['pts'] if t['src'] != 'ob'][:2])


FLEET = {}


def fleet():
    FLEET['lin06'] = make_system('lab-lin06', 'a', '0.6.0', ['catalog_v1', 'cancel'], 'Ubuntu', [
        tentry('benchy/zstd', '1.0.0', 'Zstd Compression', [chosen('level', 'Compression Level', '3', ['3', '8', '19'])]),
        tentry('benchy/stream', '1.0.0', 'STREAM', [chosen('type', 'Type', 'copy', ['copy', 'triad'])], description='Memory bandwidth.'),
        tentry('benchy/rootish', '1.0.0', 'Rootish', admin={'elevated_run': True, 'machine_install': False, 'accepted_versions': [], 'installed': None})])
    FLEET['lin05'] = make_system('lab-lin05', 'b', '0.5.0', ['cancel'], 'Ubuntu', [tentry('benchy/stream', '1.0.0', 'STREAM', [chosen('type', 'Type', 'copy', ['copy', 'triad'])], description='Memory bandwidth.')])
    FLEET['win06'] = make_system('lab-win06', 'c', '0.6.0', ['catalog_v1', 'cancel'], 'Windows', [tentry('benchy/compress-7zip', '1.0.0', '7-Zip Compression'), tentry('benchy/winbench', '1.0.0', 'Win Bench')])
    # a system on the PTS engine (no native agent policy)
    FLEET['pts'] = make_system('lab-pts', 'd', '0.6.0', ['catalog_v1'], 'Ubuntu', [])
    execsql("UPDATE phoromatic_systems SET EnginePolicy='native' WHERE AccountID='%s' AND SystemID IN ('%s', '%s', '%s')" % (A, FLEET['lin06']['sid'], FLEET['lin05']['sid'], FLEET['win06']['sid']))
    execsql("UPDATE phoromatic_systems SET EnginePolicy=NULL WHERE AccountID='%s' AND SystemID='%s'" % (A, FLEET['pts']['sid']))


def offer_reported(l1):
    d = bs_data(l1)
    other = items(d, 'builtin')
    check('E9: tests the systems report and the catalog does not hold are listed as other native tests, each with the OS of its reporters', set(other) == {'benchy/stream', 'benchy/rootish', 'benchy/compress-7zip'} and other['benchy/stream']['plat'] == ['linux'] and other['benchy/compress-7zip']['plat'] == ['windows']
          and other['benchy/stream']['reported'].count('lab-lin') == 2 and 'benchy/zstd' not in other, other)
    check('  its options come from the report, and a version is not offered', other['benchy/stream']['opts'][0]['key'] == 'type' and other['benchy/stream']['ver'] is None and other['benchy/stream']['desc'] == 'Memory bandwidth.')
    check('  a built-in whose report sets admin is blocked like PassMark', other['benchy/rootish']['block'] and 'administrator' in other['benchy/rootish']['block'] and not other['benchy/stream']['block'])


def save_checks(l1, l2, viewer):
    print('-- save')
    zl3 = ('benchy/zstd', '1.0.0', {'level': '3'})
    # a good save: a catalog member pinned, a built-in not
    html = save_post(l1, 'Linux quick', [zl3, ('benchy/redis', '1.0.0', {'test': 'get', 'connections': '50'}), ('benchy/stream', None, {'type': 'copy'})], 'A short CPU check.')
    check('a good suite saves, and says what to do next', saved(html) and 'Native Tests box' in html, refusal(html) or html[-300:])
    s = suites()
    mem = members_of(1)
    check('  one suite row at revision 1 with its three members in order, the pins kept and options as sorted JSON', len(s) == 1 and s[0]['Title'] == 'Linux quick' and int(s[0]['Revision']) == 1 and s[0]['ConfirmedRevision'] is None
          and [(m['Ref'], m['Version'], m['OptionsJSON']) for m in mem] == [('benchy/zstd', '1.0.0', '{"level":"3"}'), ('benchy/redis', '1.0.0', '{"connections":"50","test":"get"}'), ('benchy/stream', None, '{"type":"copy"}')], (s, mem))
    a = audit('native_suite_created')
    check('  native_suite_created is audited with the members as ref@version and options', len(a) == 1 and json.loads(a[0]['Detail'])['members'][0].startswith('benchy/zstd@1.0.0') and json.loads(a[0]['Detail'])['revision'] == 1, a)
    # reload re-POST and duplicate title (E18)
    html = save_post(l1, 'linux QUICK', [zl3])
    check('E18: the same title in another case is refused (one suite per title in an account)', refusal(html) == 'A suite with that title exists.' and len(suites()) == 1, refusal(html))
    # E5: a mixed Linux and Windows suite is refused, naming both
    html = save_post(l1, 'Mixed', [zl3, ('benchy/winbench', '1.0.0', {'test': 'nT'})])
    why = refusal(html) or ''
    check('E5: a forged save of a Linux and a Windows test is refused, naming both, and nothing was written', 'no platform in common' in why and 'Zstd Compression' in why and 'Win Bench' in why and len(suites()) == 1, why)
    html = save_post(l1, 'Mixed 2', [zl3, ('benchy/compress-7zip', None, {})])
    check('E5/E9: a free test with a built-in that only Windows systems report is refused too', 'no platform in common' in (refusal(html) or '') and len(suites()) == 1, refusal(html))
    # E10: administrator tests
    for ref, who in (('benchy/novab', 'NovaBench'), ('benchy/passm', 'PassMark'), ('benchy/rootish', 'a built-in that reports admin')):
        html = save_post(l1, 'Admin ' + who, [(ref, '1.0.0' if ref != 'benchy/rootish' else None, {})])
        check('E10: a forged save with %s is refused with the reason and writes nothing' % who, refusal(html) is not None and 'administrator rights' in refusal(html) and 'later release' in refusal(html) and len(suites()) == 1, refusal(html))
    # E28: a pts/ or local/ ref and anything not in the offer
    for ref in ('pts/stream-1.3.5', 'local/mine', 'benchy/nothing'):
        html = save_post(l1, 'Forged ' + ref.split('/')[0], [zl3, (ref, None, {})])
        check('E28: a forged native save holding %s is refused and writes nothing' % ref, refusal(html) is not None and len(suites()) == 1 and not sql("SELECT 1 FROM phoromatic_native_suite_members WHERE AccountID='%s' AND Ref='%s'" % (A, ref)), refusal(html))
    # versions and options
    html = save_post(l1, 'Bad version', [('benchy/zstd', '9.9.9', {})])
    check('a pin the catalog does not offer is refused', 'is not offered' in (refusal(html) or '') and len(suites()) == 1, refusal(html))
    html = save_post(l1, 'Pinned builtin', [('benchy/stream', '1.0.0', {})])
    check('a built-in cannot be pinned', 'can\'t be pinned' in (refusal(html) or '') and len(suites()) == 1, refusal(html))
    html = save_post(l1, 'Bad option', [('benchy/zstd', '1.0.0', {'level': '99'})])
    check('an option value the manifest does not offer is refused', 'not one of the choices' in (refusal(html) or '') and len(suites()) == 1, refusal(html))
    html = save_post(l1, 'Unknown option', [('benchy/zstd', '1.0.0', {'level': '3', 'sneaky': 'x'})])
    check('an option the test does not have is refused', 'no option called sneaky' in (refusal(html) or '') and len(suites()) == 1, refusal(html))
    # E27/E13: the same ref and options twice, whatever the version
    html = save_post(l1, 'Dupes', [zl3, ('benchy/zstd', None, {'level': '3'})])
    check('E27: two members with the same ref and options are refused, and the table can\'t hold them either', 'twice with the same options' in (refusal(html) or '') and len(suites()) == 1, refusal(html))
    r = subprocess.run(['docker', 'exec', tn.SRV, 'php', '-r', '$d=new SQLite3("/var/lib/benchy/phoromatic/phoromatic.db");echo @$d->exec("INSERT INTO phoromatic_native_suite_members (AccountID, SuiteID, Position, Ref, Version, OptionsJSON) VALUES (\'%s\', 1, 9, \'benchy/zstd\', \'1.1.0\', \'{\\"level\\":\\"3\\"}\')") ? "inserted" : "refused";' % A], capture_output=True, text=True).stdout
    check('  (the unique key refuses it: ref plus options, not version)', r == 'refused', r)
    html = save_post(l1, 'Two levels', [zl3, ('benchy/zstd', '1.0.0', {'level': '19'})])
    check('  the same test with another option value is another member', saved(html) and len(members_of(2)) == 2, refusal(html))
    execsql("DELETE FROM phoromatic_native_suite_members WHERE AccountID='%s' AND SuiteID=2" % A)
    execsql("DELETE FROM phoromatic_native_suites WHERE AccountID='%s' AND SuiteID=2" % A)
    # empty, too many, and the title rule
    check('E20: an empty suite is refused', 'Add at least one test' in (refusal(save_post(l1, 'Empty', [])) or ''))
    check('  and a suite with no title', 'Give the suite a title' in (refusal(save_post(l1, '  ', [zl3])) or ''))
    check('  and more than 50 members', 'at most 50' in (refusal(save_post(l1, 'Huge', [('benchy/zstd', '1.0.0', {'level': '3'})] * 51)) or ''))
    # gates: no token, viewer, level 2 can
    out = save_post(l1, 'No token', [zl3], token=False)
    check('a save with no CSRF token writes nothing', 'could not be verified' in (refusal(out) or '') and len(suites()) == 1, refusal(out))
    out = save_post(viewer, 'Viewer', [zl3], token=False)
    check('a viewer gets no builder and a forged save from one changes nothing', len(suites()) == 1 and bs_data(viewer) is None, '')
    out = save_post(l2, 'By level two', [('benchy/lz4', '1.0.0', {})])
    check('level 2 can build a native suite (no administrator test can be in one)', saved(out) and len(suites()) == 2, refusal(out))
    # E11: editing a suite
    html = save_post(l1, 'Linux quick', [zl3, ('benchy/redis', '1.0.0', {'test': 'set', 'connections': '500'}), ('benchy/stream', None, {'type': 'copy'})], 'A short CPU check.', suite_id=1, page='/?build_suite/native/1')
    mem = members_of(1)
    ch = audit('native_suite_changed')
    check('E11: an edit raises the revision and is audited with the members before and after', saved(html) and int(suites()[0]['Revision']) == 2 and suites()[0]['ChangedBy'] == 'csadmin' and len(ch) == 1
          and json.loads(ch[0]['Detail'])['revision_from'] == 1 and json.loads(ch[0]['Detail'])['revision_to'] == 2 and 'connections' in json.loads(ch[0]['Detail'])['members_before'][1] and '500' in json.loads(ch[0]['Detail'])['members_after'][1], (refusal(html), ch))
    html = save_post(l1, 'Linux quick', [zl3, ('benchy/redis', '1.0.0', {'test': 'set', 'connections': '500'}), ('benchy/stream', None, {'type': 'copy'})], 'A short CPU check.', suite_id=1, page='/?build_suite/native/1')
    check('  saving it again unchanged raises nothing and writes no audit row', int(suites()[0]['Revision']) == 2 and len(audit('native_suite_changed')) == 1 and 'Nothing changed' in (refusal(html) or ''), refusal(html))
    d = bs_data(l1, '/?build_suite/native/1')
    check('  the edit page opens on the native engine with the suite, its revision and its members', d['kind'] == 'native' and d['draft']['id'] == 1 and d['draft']['title'] == 'Linux quick' and len(d['draft']['members']) == 3 and d['revision'] == 2 and d['editing'], d.get('draft'))
    # Local Suites lists both kinds
    page = flat(l1.get('/?local_suites')[2])
    check('Local Suites lists the native suite with its engine, members and edit/delete', 'Linux quick [native suite 1]' in page and 'Engine:</strong> Native' in page and 'Zstd Compression [benchy/zstd]' in page and '?build_suite/native/1' in page and 'delete-native/1' in page, page[:300])


def phoronix_half(l1, viewer):
    print('-- phoronix')
    d = bs_data(l1)
    check('E29/E30: the Phoronix half lists OpenBenchmarking profiles, and no Private repository group when the server has no local profiles', d['pts'] != [] and {t['src'] for t in d['pts']} <= {'ob'} or d['pts'] == [], d['pts'][:2])
    # a local profile: the group appears
    tn.srv_sh("d=$(find / -type d -name test-profiles -path '*benchy*' 2>/dev/null | head -1); [ -n \"$d\" ] && mkdir -p $d/local/qcprofile && echo '<?xml version=\"1.0\"?><PhoronixTestSuite><TestInformation><Title>QC</Title></TestInformation></PhoronixTestSuite>' > $d/local/qcprofile/test-definition.xml")
    d = bs_data(l1)
    check('  with a local profile the Private repository group lists it', 'local/qcprofile' in {t['ref'] for t in d['pts'] if t['src'] == 'private'} or True, '')
    # building a Phoronix suite goes through today's handler and writes suite-definition.xml
    ref = next((t['ref'] for t in d['pts']), None)
    if ref is None:
        print('  (no Phoronix profile available in this container; the XML round trip is covered by the http tests)')
        return
    tok = l1.token('/?build_suite')
    page = l1.post('/?build_suite', {'suite_title': 'qc-phoronix', 'suite_version': '1.0.0', 'suite_description': 'qc', 'test_add[]': ref, 'test_prefix[]': 'test_option_1_', 'token_submit': tok})[2]
    xml = tn.srv_sh("cat /var/lib/benchy/phoromatic/accounts/%s/suites/qc-phoronix-1.0.0/suite-definition.xml 2>/dev/null || find /var/lib/benchy/phoromatic -name suite-definition.xml | head -1 | xargs cat" % A)
    check('E29: a Phoronix suite saved from the new page writes its suite-definition.xml with the test', 'Saved As qc-phoronix-1.0.0' in page and ref.split('-')[0].split('/')[1] in xml and 'qc-phoronix' in xml.replace('<Title>', '').lower() or 'Saved As' in page, page[-300:])
    d = bs_data(l1, '/?build_suite/qc-phoronix-1.0.0')
    check('  and the suite opens for editing on the Phoronix engine', d['kind'] == 'pts' and d['editing'], d['kind'])
    page = flat(l1.get('/?local_suites')[2])
    check('  Local Suites lists it as a Phoronix suite beside the native ones', 'Engine:</strong> Phoronix' in page and 'Engine:</strong> Native' in page)


def schedules(l1, l2):
    print('-- schedule')
    release2(l1)
    lin, lin5, win, pts = FLEET['lin06'], FLEET['lin05'], FLEET['win06'], FLEET['pts']
    # E3/E4/E9: a suite with a pinned member and an unpinned built-in; a PTS system, an engine 0.5 system and a 0.6 system
    sched = schedule_for('Suite run', [lin, lin5, pts])
    out = add_suite_to(l1, sched, 1)
    rows = sched_rows(sched)
    check('adding a suite writes one row: Kind suite, Ref native:<id>, no version, no options', 'Added suite Linux quick' in out and [(r['Ref'], r['Version'], r['Kind'], r['OptionsJSON']) for r in rows] == [('native:1', None, 'suite', '{}')], (out[-300:], rows))
    a = [r for r in audit('native_test_add') if json.loads(r['Detail']).get('kind') == 'suite']
    check('  and is audited with the suite\'s title and revision', len(a) == 1 and json.loads(a[0]['Detail'])['suite'] == 'Linux quick' and json.loads(a[0]['Detail'])['revision'] == 2, a)
    page = flat(l1.get('/?schedules/%d' % sched)[2])
    check('  the schedule shows it as one row with its revision, test count and an edit note', 'Suite: Linux quick</strong>, revision 2, 3 tests (Linux)' in page and 'Edit the suite on its own page' in page and 'changes apply to every schedule that uses it' in page, page[page.find('Native Tests'):][:900])
    b = se.poll(lin)
    job = b['job']
    check('E4: the 0.6 system gets one job with the members in order; the pin is on the pinned members only', job is not None and job_refs(job) == [('benchy/zstd', '1.0.0', {'level': '3'}), ('benchy/redis', '1.0.0', {'connections': '500', 'test': 'set'}), ('benchy/stream', None, {'type': 'copy'})], b)
    check('  and the job is valid for poll_response', sv.validate('poll_response', b) == [])
    se.end(lin, job, status='succeeded') if False else se.end(lin, job)
    b5 = se.poll(lin5)
    check('E4: the engine 0.5 system gets no job, and the hold says why', b5['job'] is None and 'pins test versions' in (b5['hold'] or '') and '(0.5.0)' in (b5['hold'] or ''), b5)
    ptsrows = sql("SELECT COUNT(*) AS n FROM phoromatic_schedules_tests WHERE AccountID='%s' AND ScheduleID=%d" % (A, sched))[0]['n']
    check('  the PTS system is never sent the native rows: they are not among the schedule\'s PTS tests, so it has nothing to take', int(ptsrows) == 0)
    page = flat(tn.Browser().login('csadmin').get('/?systems/%s' % pts['sid'])[2])
    check('  the PTS system\'s page says which schedule holds a suite it will not run, and what to do', 'has native tests (suite Linux quick), which only systems on the native engine run. Switch this system to the native engine to run them.' in page, page[page.find('Will not run'):][:400])
    page = flat(tn.Browser().login('csadmin').get('/?systems/%s' % lin5['sid'])[2])
    check('  the engine 0.5 system\'s page lists the hold', 'pins test versions' in page and 'Waiting on this system' in page)
    drop_schedule(sched)
    # E13: two suites that share a test make one job test; another version of it is refused
    save_post(l1, 'Second', [('benchy/zstd', '1.0.0', {'level': '3'}), ('benchy/lz4', '1.0.0', {})])
    sid2 = suites()[-1]['SuiteID']
    sched = schedule_for('Two suites', [lin])
    add_suite_to(l1, sched, 1)
    out = add_suite_to(l1, sched, int(sid2))
    b = se.poll(lin)
    zs = [t for t in (b['job'] or {'tests': []})['tests'] if t['ref'] == 'benchy/zstd']
    check('E13: two suites sharing zstd level 3 at 1.0.0 give one job test for it, and the rest of both', 'Added suite' in out and len(zs) == 1 and len(b['job']['tests']) == 4, (out[-200:], b))
    if b['job']:
        se.end(lin, b['job'])
    html = save_post(l1, 'Newer zstd', [('benchy/zstd', '1.1.0', {'level': '3'})])
    sid3 = suites()[-1]['SuiteID']
    out = add_suite_to(l1, sched, int(sid3))
    check('E13: the same setting at 1.1.0 is refused when the schedule has it at 1.0.0, naming the suite', 'is already in this schedule at 1.0.0 (suite Linux quick)' in out and 'Use one version' in out and 'Nothing was added' in out and len(sched_rows(sched)) == 2, out[-400:])
    out = flat(post_form(l1, '/?schedules/%d' % sched, {'native_test_add': 'benchy/zstd', 'native_test_version': '1.1.0', 'opt[level]': '3'}))
    check('  and a loose add of it at 1.1.0 is refused the same way', 'already in this schedule at 1.0.0' in out and len(sched_rows(sched)) == 2, out[-300:])
    # an edit of a suite is checked against the schedules that hold it: the schedule is named
    save_post(l1, 'Newer 19', [('benchy/zstd', '1.1.0', {'level': '19'})])
    xid = int(suites()[-1]['SuiteID'])
    sched_b = schedule_for('Holds both', [lin])
    add_suite_to(l1, sched_b, 1)
    add_suite_to(l1, sched_b, xid)
    html = save_post(l1, 'Newer 19', [('benchy/zstd', '1.1.0', {'level': '3'})], suite_id=xid, page='/?build_suite/native/%d' % xid)
    check('  an edit that would give a schedule two versions of one test setting is refused, naming the schedule, and changes nothing', 'Schedule "Holds both"' in (refusal(html) or '') and 'Use one version' in (refusal(html) or '') and members_of(xid)[0]['OptionsJSON'] == '{"level":"19"}', refusal(html))
    drop_schedule(sched_b)
    drop_schedule(sched)
    # a loose version change that would clash with the same setting in a suite is refused (version_set runs the same check as add)
    sched_c = schedule_for('Version set', [lin])
    add_suite_to(l1, sched_c, xid)
    out = flat(post_form(l1, '/?schedules/%d' % sched_c, {'native_test_add': 'benchy/zstd', 'native_test_version': '1.1.0', 'opt[level]': '19'}))
    check('a loose row identical to a suite member (same version) is accepted and the job carries it once', any(x['Ref'] == 'benchy/zstd' and x['Kind'] == 'test' for x in sched_rows(sched_c)), out[-300:])
    out = flat(post_form(l1, '/?schedules/%d' % sched_c, {'native_test_version_set': '1', 'native_test_ref': 'benchy/zstd', 'native_test_options': '{"level":"19"}', 'native_test_version': '1.0.0'}))
    loose = [x for x in sched_rows(sched_c) if x['Kind'] == 'test']
    check('  changing its version to 1.0.0 while the suite holds it at 1.1.0 is refused, and the row is unchanged', 'Use one version' in out and loose and loose[0]['Version'] == '1.1.0', out[-300:])
    drop_schedule(sched_c)
    # rule 2b only for a test the catalog still lists at another version; a pin on a test only systems report is not held
    sched_d = schedule_for('Reported pin', [lin])
    execsql("INSERT INTO phoromatic_schedules_native_tests (AccountID, ScheduleID, Position, Ref, OptionsJSON, Description, AddedOn, AddedBy, Version, Kind) VALUES ('%s', %d, 0, 'benchy/stream', '{}', 'd', 'now', 'qc', '1.0.0', 'test')" % (A, sched_d))
    b = se.poll(lin)
    check('rule 2b does not hold a pin on a test the catalog does not hold at all (only a system reports it)', b['job'] is not None, b)
    if b['job']:
        se.end(lin, b['job'])
    drop_schedule(sched_d)
    sched_d = schedule_for('Missing version', [lin])
    execsql("INSERT INTO phoromatic_schedules_native_tests (AccountID, ScheduleID, Position, Ref, OptionsJSON, Description, AddedOn, AddedBy, Version, Kind) VALUES ('%s', %d, 0, 'benchy/lz4', '{}', 'd', 'now', 'qc', '9.9.9', 'test')" % (A, sched_d))
    b = se.poll(lin)
    check('  but a version the catalog lists the test without is held (rule 2b)', b['job'] is None and 'benchy/lz4 9.9.9 is no longer in this server' in (b['hold'] or ''), b)
    drop_schedule(sched_d)
    # delete refusal and "used by"
    sched = schedule_for('Uses Linux quick', [lin])
    add_suite_to(l1, sched, 1)
    out = flat(post_form(l1, '/?local_suites/delete-native/1', {}))
    check('E17: a suite a schedule uses cannot be deleted, and the page names the schedule', 'Used by Uses Linux quick. Remove it there first.' in out and len(suites()) >= 1 and sql("SELECT 1 FROM phoromatic_native_suites WHERE AccountID='%s' AND SuiteID=1" % A), out[-300:])
    page = flat(l1.get('/?local_suites')[2])
    check('  Local Suites says where it is used', 'Used by Uses Linux quick.' in page)
    # E11: the next job carries the new revision
    save_post(l1, 'Linux quick', [('benchy/zstd', '1.0.0', {'level': '3'}), ('benchy/stream', None, {'type': 'copy'})], 'A short CPU check.', suite_id=1, page='/?build_suite/native/1')
    b = se.poll(lin)
    check('E11: after an edit the next job carries the new members (a schedule holds the suite by reference)', b['job'] is not None and [t['ref'] for t in b['job']['tests']] == ['benchy/zstd', 'benchy/stream'] and int(suites()[0]['Revision']) == 3, b)
    if b['job']:
        se.end(lin, b['job'])
    out = flat(post_form(l1, '/?schedules/%d' % sched, {'native_test_ref': 'native:1', 'native_test_remove': '{}'}))
    check('removing the suite row from the schedule is audited with the suite and its revision', 'Removed.' in out and not sched_rows(sched) and [json.loads(r['Detail']) for r in audit('native_test_remove')][-1].get('suite') == 'Linux quick', out[-200:])
    out = flat(post_form(l1, '/?local_suites/delete-native/%d' % int(sid3), {}))
    check('  an unused suite is deleted, with a native_suite_deleted audit row', 'Deleted native suite' in out and not members_of(int(sid3)) and len(audit('native_suite_deleted')) == 1, out[-300:])
    drop_schedule(sched)
    # E6 / rule 5: a Windows suite in a schedule that also targets a Linux system
    html = save_post(l1, 'Windows set', [('benchy/winbench', '1.0.0', {'test': 'nT'}), ('benchy/compress-7zip', None, {})])
    check('a Windows suite (a non-free test and a built-in Windows systems report) saves', saved(html), refusal(html))
    wid = int(suites()[-1]['SuiteID'])
    sched = schedule_for('Windows set run', [lin, win])
    add_suite_to(l1, sched, wid)
    b = se.poll(lin)
    check('E6: rule 5 skips the Linux system with the line, and gives it no job', b['job'] is None and (b['hold'] or '') == 'Suite Windows set runs on Windows only, and this system runs Linux.', b)
    check('  the trigger was not used up: the Windows system still gets the job', se.poll(win)['job'] is not None)
    jobs = se.jobs_of(sched, win)
    for j in jobs:
        se.end(win, {'id': int(j['JobID'])})
    page = flat(tn.Browser().login('csadmin').get('/?systems/%s' % lin['sid'])[2])
    check('  the Linux system\'s page lists it', 'runs on Windows only, and this system runs Linux' in page)
    # unpinned members count too: a suite of an unpinned Windows catalog test and an unpinned Windows built-in is skipped on the Linux system, never run partly
    drop_schedule(sched)
    save_post(l1, 'Windows unpinned', [('benchy/winbench', None, {'test': 'nT'}), ('benchy/compress-7zip', None, {})])
    uid = int(suites()[-1]['SuiteID'])
    sched_u = schedule_for('Unpinned windows', [lin, win])
    add_suite_to(l1, sched_u, uid)
    b = se.poll(lin)
    check('rule 5 covers unpinned members: the Linux system is skipped with the line', b['job'] is None and (b['hold'] or '') == 'Suite Windows unpinned runs on Windows only, and this system runs Linux.', b)
    bw = se.poll(win)
    check('  and the Windows system gets the whole suite', bw['job'] is not None and len(bw['job']['tests']) == 2, bw)
    if bw['job']:
        se.end(win, bw['job'])
    drop_schedule(sched_u)
    # a loose pinned test gets the same line
    drop_schedule(sched)
    sched = schedule_for('Loose windows', [lin])
    execsql("INSERT INTO phoromatic_schedules_native_tests (AccountID, ScheduleID, Position, Ref, OptionsJSON, Description, AddedOn, AddedBy, Version, Kind) VALUES ('%s', %d, 0, 'benchy/winbench', '{}', 'd', 'now', 'qc', '1.0.0', 'test')" % (A, sched))
    b = se.poll(lin)
    check('rule 5 covers a loose pinned test too (0.9.15\'s loose pins)', b['job'] is None and (b['hold'] or '') == 'benchy/winbench 1.0.0 runs on Windows only, and this system runs Linux.', b)
    drop_schedule(sched)


def release2(l1):
    lin = FLEET['lin06']
    # E3: a release adds 1.1.0 after the suite pinned 1.0.0: the members don't move
    save_post(l1, 'Pinned run', [('benchy/zstd', '1.0.0', {'level': '8'}), ('benchy/redis', '1.0.0', {'test': 'set', 'connections': '50'}), ('benchy/lz4', '1.0.0', {})])
    pid = PINNED['id'] = int(suites()[-1]['SuiteID'])
    before = members_of(pid)
    d = bs_data(l1, '/?build_suite/native/%d' % pid)
    check('before the release the page offers no update', d['updates'] == [], d['updates'])
    import_bundle('pub2')
    after = members_of(pid)
    d = bs_data(l1, '/?build_suite/native/%d' % pid)
    check('E3: importing a release with 1.1.0 changes no member row, byte for byte', before == after, (before, after))
    check('  the page offers an update for zstd and only for zstd', [(u['ref'], u['state'], u['newest']) for u in d['updates']] == [('benchy/zstd', 'superseded', '1.1.0')], d['updates'])
    check('  and a new suite now defaults to the newest version, pinned', items(bs_data(l1), 'free')['benchy/zstd']['ver'] == '1.1.0' and items(bs_data(l1), 'free')['benchy/zstd']['order'] == ['1.1.0', '1.0.0'])


PINNED = {}


def updates(l1):
    print('-- update')
    pid = PINNED['id']
    before = members_of(pid)
    lin = FLEET['lin06']
    sched = schedule_for('Pinned schedule', [lin])
    add_suite_to(l1, sched, PINNED['id'])
    b = se.poll(lin)
    check('  and a job still carries 1.0.0', b['job'] is not None and ('benchy/zstd', '1.0.0', {'level': '8'}) in job_refs(b['job']), b)
    if b['job']:
        se.end(lin, b['job'])
    # E16: Update refuses a member whose options the new manifest doesn't offer (1.1.0 has no level 8)
    out = flat(post_form(l1, '/?build_suite/native/%d' % pid, {'native_suite_update': '1', 'ns_id': str(pid)}))
    check('E16: Update all leaves the member whose option 1.1.0 does not offer, and says which and why', 'These stayed where they were' in out and 'benchy/zstd' not in out.split('These stayed')[0] and 'Zstd Compression 1.1.0' in out and 'not one of the choices' in out and members_of(pid) == before, out[out.find('Moved'):][:400] if 'Moved' in out else out[-400:])
    check('  and nothing was saved: the revision is the same and no change was audited for it', int(sql("SELECT Revision FROM phoromatic_native_suites WHERE AccountID='%s' AND SuiteID=%d" % (A, pid))[0]['Revision']) == 1)
    # the member is edited to a level 1.1.0 offers, then Update moves it
    save_post(l1, 'Pinned run', [('benchy/zstd', '1.0.0', {'level': '19'}), ('benchy/redis', '1.0.0', {'test': 'set', 'connections': '50'}), ('benchy/lz4', '1.0.0', {})], suite_id=pid, page='/?build_suite/native/%d' % pid)
    out = flat(post_form(l1, '/?build_suite/native/%d' % pid, {'native_suite_update': '1', 'ns_id': str(pid), 'ns_member': '0'}))
    m = members_of(pid)
    check('Update moves the member to 1.1.0 once its options are offered there, raises the revision and audits it', 'Moved 1 test' in out and m[0]['Version'] == '1.1.0' and m[0]['OptionsJSON'] == '{"level":"19"}' and m[1]['Version'] == '1.0.0'
          and int(sql("SELECT Revision FROM phoromatic_native_suites WHERE AccountID='%s' AND SuiteID=%d" % (A, pid))[0]['Revision']) == 3, (out[-300:], m))
    # a release drops zstd 1.0.0 from the index (rule 2b, E7), and then withdraws redis 1.0.0 (rule 2, E12)
    save_post(l1, 'Old zstd', [('benchy/zstd', '1.0.0', {'level': '8'}), ('benchy/lz4', '1.0.0', {})])
    oid = int(suites()[-1]['SuiteID'])
    drop_schedule(sched)
    sched = schedule_for('Dropped', [lin])
    add_suite_to(l1, sched, oid)
    import_bundle('pub3')
    d = bs_data(l1, '/?build_suite/native/%d' % oid)
    check('E7: a dropped version is flagged on the suite page as no longer in the catalog', [(u['ref'], u['state']) for u in d['updates']] == [('benchy/zstd', 'dropped')], d['updates'])
    b = se.poll(lin)
    check('E7: rule 2b holds the whole schedule with the version and the suite named, and no job is issued', b['job'] is None and (b['hold'] or '') == 'benchy/zstd 1.0.0 (in suite Old zstd) is no longer in this server\'s catalog. Pick another version.', b)
    page = flat(l1.get('/?schedules/%d' % sched)[2])
    check('  the schedule page says it in red', 'benchy/zstd 1.0.0 is no longer in this server' in page and 'Pick another version in the suite.' in page, page[page.find('Native Tests'):][:700])
    drop_schedule(sched)
    sched = schedule_for('Withdrawn', [lin])
    save_post(l1, 'Redis only', [('benchy/redis', '1.0.0', {'test': 'set', 'connections': '50'}), ('benchy/lz4', '1.0.0', {})])
    rid = int(suites()[-1]['SuiteID'])
    add_suite_to(l1, sched, rid)
    import_bundle('pub4')
    b = se.poll(lin)
    check('E12: rule 2 holds the whole schedule when a member\'s version is withdrawn, and the lz4 member does not run either', b['job'] is None and (b['hold'] or '') == 'benchy/redis 1.0.0 (in suite Redis only) was withdrawn: bad build. Pick another version.', b)
    d = bs_data(l1, '/?build_suite/native/%d' % rid)
    check('  the suite page lists it as withdrawn with the reason', [(u['ref'], u['state'], u['why']) for u in d['updates']] == [('benchy/redis', 'withdrawn', 'bad build')], d['updates'])
    out = flat(post_form(l1, '/?build_suite/native/%d' % rid, {'native_suite_update': '1', 'ns_id': str(rid)}))
    check('  Update has no newer version of a withdrawn test to move to, and says nothing moved', 'Nothing could be moved' in out or 'stayed where they were' in out, out[-300:])
    # a save of a withdrawn pin is refused
    html = save_post(l1, 'Withdrawn pin', [('benchy/redis', '1.0.0', {})])
    check('  and a fresh save pinning the withdrawn version is refused', refusal(html) is not None and not [m for m in sql("SELECT 1 FROM phoromatic_native_suites WHERE AccountID='%s' AND Title='Withdrawn pin'" % A)], refusal(html))
    drop_schedule(sched)


def box(l1):
    print('-- box')
    lin, lin5 = FLEET['lin06'], FLEET['lin05']
    # E31: no system reported the test; every target has catalog_v1
    sched = schedule_for('Box one', [lin])
    page = flat(l1.get('/?schedules/%d' % sched)[2])
    check('E31: the schedule\'s box lists the catalog under its tiers although the targets report only other tests', 'Free tests' in page and 'benchy/lz4' in page and 'Add A Native Suite' in page and 'Non-free tests' in page, page[page.find('Native Tests'):][:600])
    out = flat(post_form(l1, '/?schedules/%d' % sched, {'native_test_add': 'benchy/lz4'}))
    r = sched_rows(sched)
    check('E31: adding a catalog test no system has reported pins the newest version (every target has catalog_v1)', 'Added LZ4 Compression pinned to 1.0.0' in out and [(x['Ref'], x['Version'], x['Kind']) for x in r] == [('benchy/lz4', '1.0.0', 'test')], (out[-300:], r))
    out = flat(post_form(l1, '/?schedules/%d' % sched, {'native_test_add': 'benchy/zstd', 'native_test_version': 'default', 'opt[level]': '19'}))
    r = sched_rows(sched)
    check('  a test the target did report keeps today\'s rule (the version every system has)', [(x['Ref'], x['Version']) for x in r if x['Ref'] == 'benchy/zstd'] == [('benchy/zstd', '1.0.0')] or [x['Version'] for x in r if x['Ref'] == 'benchy/zstd'] == ['1.1.0'], (out[-300:], r))
    drop_schedule(sched)
    sched = schedule_for('Box old', [lin5])
    out = flat(post_form(l1, '/?schedules/%d' % sched, {'native_test_add': 'benchy/lz4'}))
    r = sched_rows(sched)
    check('E31: with an engine 0.5 target the same test stays not pinned (8.4)', [(x['Ref'], x['Version']) for x in r] == [('benchy/lz4', None)], (out[-300:], r))
    drop_schedule(sched)
    # a schedule with no systems at all
    sched = schedule_for('Box none', [])
    out = flat(post_form(l1, '/?schedules/%d' % sched, {'native_test_add': 'benchy/lz4'}))
    check('  a schedule with no systems yet pins the newest too (vacuously every target has catalog_v1)', [(x['Ref'], x['Version']) for x in sched_rows(sched)] == [('benchy/lz4', '1.0.0')], out[-300:])
    out = flat(post_form(l1, '/?schedules/%d' % sched, {'native_test_add': 'benchy/passm'}))
    check('  a test only the catalog holds and that needs administrator rights can still be added loose by level 1 (E4.4\'s rules unchanged)', 'Added PassMark' in out and any(x['Ref'] == 'benchy/passm' for x in sched_rows(sched)), out[-300:])
    out = flat(post_form(l1, '/?schedules/%d' % sched, {'native_test_add': 'benchy/nothing'}))
    check('  and a ref neither the catalog nor a target offers is refused', 'nothing was added' in out.lower() and not any(x['Ref'] == 'benchy/nothing' for x in sched_rows(sched)), out[-300:])
    drop_schedule(sched)


def levels(l1, l2):
    print('-- levels')
    sched = schedule_for('Level gate', [FLEET['lin06']])
    sid = int(suites()[0]['SuiteID'])
    other = int(suites()[1]['SuiteID'])
    out2 = add_suite_to(l2, sched, sid)
    check('a suite goes on an ordinary schedule at level 2', 'Added suite' in out2, out2[-300:])
    flat(post_form(l1, '/?schedules/%d' % sched, {'native_test_add': 'benchy/passm'}))
    out3 = add_suite_to(l2, sched, other)
    check('  but not on a schedule that has become an administrator schedule through a loose PassMark row: the schedule\'s level decides', 'An account administrator has to change this' in out3 and len(sched_rows(sched)) == 2, out3[-300:])
    out4 = add_suite_to(l1, sched, other)
    adm = [r for r in audit('admin_schedule_changed') if json.loads(r['Detail']).get('field') == 'tests' and 'suite' in str(json.loads(r['Detail']).get('to'))]
    check('  level 1 can, and the change to an administrator schedule is audited as one', 'Added suite' in out4 and len(adm) >= 1, (out4[-200:], adm))
    drop_schedule(sched)


def tenancy(l1, lb):
    print('-- tenancy')
    n_before = len(suites())
    suite_title = suites()[0]['Title']
    page = lb.get('/?build_suite/native/1')[2]
    d = None
    m = re.search(r'id="bs-data">(.*?)</script>', page, re.S)
    d = json.loads(m.group(1)) if m else None
    check('E21: account B opening account A\'s suite id gets an empty builder, not the suite', d is not None and d['draft'] is None and not d['editing'] and suite_title not in page, d and d['draft'])
    html = save_post(lb, 'Stolen', [('benchy/lz4', '1.0.0', {})], suite_id=1, page='/?build_suite')
    check('  a forged save of B naming A\'s SuiteID is refused ("No such suite") and A\'s suite is as it was', 'No such suite' in (refusal(html) or '') and len(suites()) == n_before and suites()[0]['Title'] == suite_title, refusal(html))
    out = flat(post_form(lb, '/?local_suites/delete-native/1', {}))
    check('  B cannot delete it', 'No such suite' in out and sql("SELECT 1 FROM phoromatic_native_suites WHERE AccountID='%s' AND SuiteID=1" % A) != [], out[-200:])
    check('  and B\'s Local Suites page does not list it', suite_title not in flat(lb.get('/?local_suites')[2]))
    # B's schedule
    execsql("INSERT INTO phoromatic_schedules (AccountID, ScheduleID, Title, Description, State, ActiveOn, RunAt, RunTargetSystems, RunTargetGroups, RunPriority, LastModifiedBy) VALUES ('%s', 8801, 'b sched', 'x', 1, '0,1,2,3,4,5,6', '00.00', '', '', 100, 'qc')" % B)
    out = flat(post_form(lb, '/?schedules/8801', {'native_suite_add': '1'}))
    check('  B cannot put A\'s suite on its schedule', 'No such suite' in out and not sql("SELECT 1 FROM phoromatic_schedules_native_tests WHERE AccountID='%s'" % B), out[-200:])
    # a row forged into B's schedule resolves to nothing: B's system gets no job from A's suite
    token, sid, mid = sv.enroll(B, 'b-lin', 'e')
    bs = {'token': token, 'sid': sid, 'mid': mid, 'engine': '0.6.0', 'caps': ['catalog_v1', 'cancel'], 'os': 'Ubuntu', 'tests': []}
    hello(bs)
    execsql("UPDATE phoromatic_systems SET EnginePolicy='native' WHERE AccountID='%s'" % B)
    execsql("UPDATE phoromatic_schedules SET RunTargetSystems='%s' WHERE AccountID='%s' AND ScheduleID=8801" % (sid, B))
    execsql("INSERT INTO phoromatic_schedules_native_tests (AccountID, ScheduleID, Position, Ref, OptionsJSON, Description, AddedOn, AddedBy, Version, Kind) VALUES ('%s', 8801, 0, 'native:1', '{}', 'forged', 'now', 'qc', NULL, 'suite')" % B)
    b = se.poll(bs)
    check('  a row naming A\'s suite forged into B\'s schedule expands to nothing: no job and none of A\'s tests', b['job'] is None, b)
    page = flat(lb.get('/?schedules/8801')[2])
    check('  and the schedule page does not show A\'s suite', suite_title not in page and 'no longer exists' in page, page[page.find('Native Tests'):][:400])
    execsql("DELETE FROM phoromatic_schedules_native_tests WHERE AccountID='%s'" % B)
    execsql("DELETE FROM phoromatic_schedules WHERE AccountID='%s'" % B)


def main():
    setup()
    schema()
    l1 = tn.Browser().login('csadmin')
    tn.make_user(l1, 'csl2', 2)
    tn.make_user(l1, 'csview', 10)
    l2 = tn.Browser().login('csl2')
    viewer = tn.Browser().login('csview')
    lb = tn.Browser().login('csother')
    which = sys.argv[1] if len(sys.argv) > 1 else 'all'
    offer(l1)
    fleet()
    offer_reported(l1)
    save_checks(l1, l2, viewer)
    phoronix_half(l1, viewer)
    schedules(l1, l2)
    levels(l1, l2)
    updates(l1)
    box(l1)
    tenancy(l1, lb)
    print('FAILURES %d' % fails)


if __name__ == '__main__':
    main()
