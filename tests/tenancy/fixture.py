#!/usr/bin/env python3
"""The two-account fixture.

  fixture.py a   account T1 (seeded id TNAAAA) with one user per tenant level, on a server that has only that account
  fixture.py b   account T2 (TNBBBB) with a CANARY_T2_<table>_<column> value in every column of every tenant table and in every file,
                 T1 data that shares ids with T2 on purpose, a purged-account folder, and the root password

Ids are written to state.json (TN_STATE) for the tests; {T2_PPRID}-style placeholders in routes.json come from it.
"""
import io
import json
import subprocess
import sys
import tarfile
import time

import tn

T1, T2 = 'TNAAAA', 'TNBBBB'
SEED_PHP = r'''
$o = json_decode(file_get_contents('php://stdin'), true);
$d = new SQLite3("%(storage)s/phoromatic.db"); $d->busyTimeout(20000);
$skip = array('phoromatic_accounts', 'phoromatic_users', 'phoromatic_account_settings', 'phoromatic_user_settings');
$t = $d->query("SELECT name FROM sqlite_master WHERE type='table' AND name LIKE 'phoromatic_%%' ORDER BY name");
$tables = array();
while($r = $t->fetchArray(SQLITE3_ASSOC)) { $tables[] = $r['name']; }
function cols($d, $t) { $c = $d->query("PRAGMA table_info($t)"); $out = array(); while($x = $c->fetchArray(SQLITE3_ASSOC)) { $out[] = $x; } return $out; }
function put($d, $table, $aid, $vals, $canary) {
	$cols = cols($d, $table); $names = array(); $binds = array();
	foreach($cols as $c) {
		$n = $c['name'];
		if(array_key_exists($n, $vals)) { $v = $vals[$n]; }
		else if($n == 'AccountID') { $v = $aid; }
		else if(stripos($c['type'], 'INT') !== false) { if($c['pk'] && stripos($c['type'], 'INTEGER') !== false) { continue; } $v = 1; }
		else { $v = $canary ? "CANARY_T2_{$table}_{$n}" : "tn1_{$table}_{$n}"; }
		$names[] = $n; $binds[] = $v;
	}
	$q = $d->prepare("INSERT OR IGNORE INTO $table (" . implode(',', $names) . ") VALUES (" . implode(',', array_fill(0, count($names), '?')) . ")");
	foreach($binds as $i => $v) { $q->bindValue($i + 1, $v); }
	if($q->execute() === false) { fwrite(STDERR, "insert into $table failed: " . $d->lastErrorMsg() . "\n"); exit(1); }
}
foreach($o['rows'] as $row) { put($d, $row['table'], $row['aid'], $row['vals'], $row['canary']); }
foreach($tables as $table) {
	if(in_array($table, $skip) || in_array($table, $o['done'])) { continue; }
	$has = false; foreach(cols($d, $table) as $c) { if($c['name'] == 'AccountID') { $has = true; } }
	if($has) { put($d, $table, $o['t2'], array(), true); }
}
echo "seeded\n";
'''


def put_files(files):
    buf = io.BytesIO()
    with tarfile.open(fileobj=buf, mode='w') as tar:
        for path, data in files.items():
            info = tarfile.TarInfo(path.lstrip('/'))
            info.size = len(data)
            info.mode = 0o644
            info.mtime = int(time.time())
            tar.addfile(info, io.BytesIO(data))
    r = subprocess.run(['docker', 'exec', '-i', tn.SRV, 'tar', '-x', '-C', '/'], input=buf.getvalue(), capture_output=True)
    assert r.returncode == 0, r.stderr


def composite(tag, title):
    return ('<?xml version="1.0"?>\n<PhoronixTestSuite><Generated><Title>%s</Title><LastModified>2026-01-01 00:00:00</LastModified><TestClient>x</TestClient>'
            '<Description>%s_desc</Description></Generated><System><Identifier>sys</Identifier><Hardware>%s_hw</Hardware><Software>%s_sw</Software><Author>a</Author>'
            '<TestDate>2026-01-01</TestDate><TestNotes></TestNotes><Version>10.8.4</Version><AssociatedIdentifiers></AssociatedIdentifiers></System>'
            '<Result><Identifier>pts/sysbench-1.1.0</Identifier><Title>%s_test</Title><AppVersion>1</AppVersion><Arguments>--cpu</Arguments><Description>d</Description>'
            '<Scale>Events</Scale><Proportion>HIB</Proportion><DisplayFormat>BAR_GRAPH</DisplayFormat><Data><Entry><Identifier>sys</Identifier><Value>100</Value>'
            '<RawString>100</RawString></Entry></Data></Result></PhoronixTestSuite>\n' % (title, tag, tag, tag, tag)).encode()


def suite_xml(tag):
    return ('<?xml version="1.0"?>\n<PhoronixTestSuite><SuiteInformation><Title>%s_suite</Title><Version>1.0.0</Version><TestType>System</TestType><Description>%s_desc</Description>'
            '<Maintainer>%s</Maintainer></SuiteInformation><Execute><Test>pts/sysbench-1.1.0</Test></Execute></PhoronixTestSuite>\n' % (tag, tag, tag)).encode()


def phase_a():
    tn.register('tn1admin', T1)
    # Root password, set the way an operator does
    subprocess.run(['docker', 'exec', '-i', tn.SRV, 'sh', '/bench/benchy', 'phoromatic.set-root-admin-password'], input=tn.ROOT_PW + '\n', capture_output=True, text=True)
    admin = tn.Browser().login('tn1admin')
    users = {'1': 'tn1admin'}
    for lvl in (2, 3, 10):
        users[str(lvl)] = 'tn1lvl%d' % lvl
        tn.make_user(admin, users[str(lvl)], lvl)
    # A download cache for the caches page to write into
    tn.srv_sh('mkdir -p /var/cache/benchy/download-cache && echo \'{"phoronix-test-suite":{"download-cache":{}}}\' > /var/cache/benchy/download-cache/pts-download-cache.json')
    tn.save_state({'users': {'T1': users}, 'T1': {'aid': T1}})


def phase_b():
    st = tn.state()
    # T1 was the only account when it was made, so its level-1 user is a server administrator (the first-account rule); the second account
    # makes this a shared server, where nobody is one unless root says so
    assert 'tn1admin' in tn.cli('server-admin-list'), 'the first account did not get the server administrator role'
    tn.cli('server-admin-revoke', 'tn1admin')
    tn.register('tn2admin', T2)
    st['T2'] = ids2 = {'aid': T2, 'pprid': 'TN2PPRA', 'upload': 770001, 'system': 'T2SYS', 'sched': 770002, 'ticket': 770003, 'bench': 770004,
                       'session': 770005, 'component': 770006, 'suite': 'canarysuite-1.0.0', 'trigger': 'canarytrigger', 'repo_item': 'private/canary-1.0.0'}
    st['T1'].update({'pprid': 'TN1PPRA', 'upload': 660001, 'system': 'T1SYS', 'sched': tn.SHARED_SCHEDULE, 'ticket': 660003, 'suite': 'tn1suite-1.0.0'})
    st['users']['T2'] = {'1': 'tn2admin'}
    st['T2']['trigger_b64'] = tn.b64('canarytrigger')
    t1 = st['T1']
    now = '2026-11-01 10:00:00'
    rows = []

    def row(table, aid, canary, **vals):
        rows.append({'table': table, 'aid': aid, 'canary': canary, 'vals': vals})
    # T2: the key rows with known ids (everything else in the generic pass)
    row('phoromatic_results', T2, True, UploadID=ids2['upload'], PPRID=ids2['pprid'], ScheduleID=ids2['sched'], SystemID=ids2['system'], UploadTime=now, InProgress=0, BenchmarkTicketID=ids2['ticket'])
    for i in range(20):
        row('phoromatic_results', T2, True, UploadID=780001 + i, PPRID='TN2PPR%02d' % i, ScheduleID=tn.SHARED_SCHEDULE, SystemID=ids2['system'], UploadTime=now, InProgress=0)
    row('phoromatic_systems', T2, True, SystemID=ids2['system'], State=1, MachineSelfID='CANARY_T2_msi', LastCommunication=now, CreatedOn=now)
    row('phoromatic_schedules', T2, True, ScheduleID=ids2['sched'], State=1, ActiveOn='1,2,3,4,5,6,7', RunAt='00.00', RunTargetSystems='1', RunTargetGroups='')
    row('phoromatic_benchmark_tickets', T2, True, TicketID=ids2['ticket'], State=1, EnvironmentVariables='PTS_CONCURRENT_TEST_RUNS=2', SuiteToRun=ids2['suite'], TicketIssueTime=1793000000, LastModifiedOn=now,
        RunTargetSystems=ids2['system'], RunTargetGroups='')
    row('phoromatic_schedules_triggers', T2, True, ScheduleID=ids2['sched'], Trigger=ids2['trigger'], TriggeredOn=now)
    row('phoromatic_benches', T2, True, BenchID=ids2['bench'], CreatedOn=now, RetiredOn=None, RetiredBy=None)
    row('phoromatic_components', T2, True, ComponentID=ids2['component'], CreatedOn=now, RetiredOn=None, RetiredBy=None)
    row('phoromatic_bench_sessions', T2, True, SessionID=ids2['session'], BenchID=ids2['bench'], ComponentID=ids2['component'], StartedOn=now, EndedOn=None, EndedBy=None)
    # the session's component row, as the server writes it: without it the server's own repair of a session with no component rows would add one when a command boots the database
    row('phoromatic_bench_session_components', T2, True, SessionID=ids2['session'], ComponentID=ids2['component'], Position=0, OpenFlag=1)
    # T1: ordinary data that shares ids with T2's
    row('phoromatic_results', T1, False, UploadID=t1['upload'], PPRID=t1['pprid'], ScheduleID=tn.SHARED_SCHEDULE, SystemID=t1['system'], UploadTime=now, InProgress=0, Title='TN1 result')
    row('phoromatic_systems', T1, False, SystemID=t1['system'], State=1, MachineSelfID='tn1-msi', LastCommunication=now, CreatedOn=now, Title='TN1 system')
    row('phoromatic_schedules', T1, False, ScheduleID=tn.SHARED_SCHEDULE, State=1, ActiveOn='1,2,3,4,5,6,7', RunAt='00.00', RunTargetSystems='1', RunTargetGroups='', Title='TN1 Same Id Schedule')
    row('phoromatic_benchmark_tickets', T1, False, TicketID=t1['ticket'], State=1, EnvironmentVariables='PTS_CONCURRENT_TEST_RUNS=2', SuiteToRun=t1['suite'], TicketIssueTime=1793000000, LastModifiedOn=now,
        RunTargetSystems=t1['system'], RunTargetGroups='', Title='TN1 ticket')
    done = ['phoromatic_results', 'phoromatic_systems', 'phoromatic_schedules', 'phoromatic_benchmark_tickets', 'phoromatic_schedules_triggers', 'phoromatic_benches',
            'phoromatic_components', 'phoromatic_bench_sessions', 'phoromatic_bench_session_components']
    # Rows with a Title the tests look for in rendered pages
    for r in rows:
        if r['aid'] == T2 and r['table'] in ('phoromatic_results', 'phoromatic_systems', 'phoromatic_schedules', 'phoromatic_benchmark_tickets', 'phoromatic_benches'):
            r['vals'].setdefault('Title', 'CANARY_T2_%s_Title' % r['table'])
    payload = json.dumps({'rows': rows, 'done': done, 't2': T2})
    r = subprocess.run(['docker', 'exec', '-i', tn.SRV, 'php', '-r', SEED_PHP % {'storage': tn.STORAGE}], input=payload, capture_output=True, text=True)
    assert 'seeded' in r.stdout, r.stdout + r.stderr
    acc = tn.STORAGE + '/accounts/'
    files = {}
    for aid, tag, ids in ((T2, 'CANARY_T2_FILE', ids2), (T1, 'TN1_FILE', t1)):
        base = acc + aid + '/'
        files[base + 'results/%d/composite.xml' % ids['upload']] = composite(tag, tag + '_title')
        files[base + 'results/%d/system-logs.zip' % ids['upload']] = (tag + '_zip').encode()
        files[base + 'suites/%s/suite-definition.xml' % ids['suite']] = suite_xml(tag)
        files[base + 'stress-logs/%d/%s.log' % (ids['ticket'], ids['system'])] = ('##### %s stress log\n[%s] line\n' % (tag, tag)).encode()
        files[base + 'systems/%s/sensors.json' % ids['system']] = ('{"uptime": 5, "note": "%s"}' % tag).encode()
        files[base + 'systems/%s/test-installations.json' % ids['system']] = ('{"pts/x-1.0.0": {"title": "%s"}}' % tag).encode()
        files[base + 'systems/%s/phoronix-test-suite.log' % ids['system']] = (tag + ' log\n').encode()
        files[base + 'client-update-script.sh'] = ('#!/bin/sh\n# %s\n' % tag).encode()
    for i in range(20):
        files[acc + T2 + '/results/%d/composite.xml' % (780001 + i)] = composite('CANARY_T2_FILE', 'CANARY_T2_FILE_t%d' % i)
    files[acc + '.purged-%s-20260101/results/1/composite.xml' % T2] = composite('CANARY_T2_FILE', 'CANARY_T2_FILE_purged')
    put_files(files)
    tn.srv_sh('mkdir -p %s/accounts/%s/private-repo && echo CANARY_T2_FILE > %s/accounts/%s/private-repo/note.txt' % (tn.STORAGE, T2, tn.STORAGE, T2))
    tn.save_state(st)


if __name__ == '__main__':
    {'a': phase_a, 'b': phase_b}[sys.argv[1]]()
