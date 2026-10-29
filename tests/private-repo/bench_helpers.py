"""Shared helpers for the test bench suites (test_benches_*.py): phodevi-shaped 'pp' properties, bench systems registered through
real check-ins, start/upload requests as a Phoromatic client sends them, and the bench page actions as a browser posts them.

Not a test module itself (no test_ prefix). Run the suites through tests/private-repo/run.sh.
"""
import base64
import hashlib
import json
import re
import time
import uuid

from test_private_repo_http import AID, CORE, parse, raw_post, sql, srv_sh
from test_private_repo_tokens import sql_exec
from test_systems_adoption import mac

CPU_A = 'AMD Ryzen 7 9800X3D 8-Core'
CPU_B = 'AMD Ryzen 9 9950X 16-Core'
GPU_A = 'NVIDIA GeForce RTX 4090'
GPU_B = 'AMD Radeon RX 9070 XT'
BOARD = 'ASUS ROG X870E Hero'

COMPOSITE = """<?xml version="1.0"?>
<PhoronixTestSuite>
  <Generated><Title>%(title)s</Title><LastModified>2026-01-01 00:00:00</LastModified><TestClient>qc</TestClient><Description>qc</Description></Generated>
  <System><Identifier>%(identifier)s</Identifier><Hardware>Processor: QC</Hardware><Software>OS: QC</Software><User>qc</User><TimeStamp>2026-01-01 00:00:00</TimeStamp><TestClientVersion>1</TestClientVersion><Notes></Notes></System>
  <Result><Identifier>pts/qc-1.0.0</Identifier><Title>QC Test</Title><AppVersion>1</AppVersion><Arguments></Arguments><Description></Description><Scale>Seconds</Scale><Proportion>LIB</Proportion><DisplayFormat>BAR_GRAPH</DisplayFormat>
    <Data><Entry><Identifier>%(identifier)s</Identifier><Value>%(value)s</Value><RawString>%(value)s</RawString></Entry></Data></Result>
</PhoronixTestSuite>
"""


def pp(cpu=CPU_A, gpu=GPU_A, board=BOARD, mem=32768, device_id=None):
    """phodevi::read_all_properties()-shaped JSON with the given CPU, GPU, board and memory (MB); None leaves a part undetected"""
    d = {'cpu': {'model': cpu, 'identifier': (cpu + ' @ 5.27GHz (8 Cores / 16 Threads)') if cpu else None},
         'gpu': {'model': gpu, 'identifier': gpu}, 'motherboard': {'identifier': (board + ' (1813 BIOS)') if board else None},
         'memory': {'capacity': mem, 'identifier': '2 x 16GB DDR5-6000MT/s'}, 'system': {'hostname': 'qcbench'}}
    if device_id:
        d['gpu']['device-id'] = device_id
    return json.dumps(d)


def req(msi, resource, **fields):
    f = {'r': resource, 'aid': AID, 'msi': msi, 'pts_core': CORE, 'n': 'qcbench'}
    f.update({k: v for k, v in fields.items() if v is not None})
    return parse(raw_post('/phoromatic.php', f))


def system_row(sid):
    r = sql("SELECT * FROM phoromatic_systems WHERE AccountID='%s' AND SystemID='%s'" % (AID, sid))
    return r[0] if r else None


def new_system(title=None, state=1, **ppkw):
    """An approved system registered through a real check-in carrying pp, returned as (msi, SystemID)"""
    msi = str(uuid.uuid4())
    parts = [('Processor', ppkw.get('cpu', CPU_A)), ('Graphics', ppkw.get('gpu', GPU_A))]
    h = ', '.join('%s: %s' % (k, v) for k, v in parts if v)
    req(msi, 'tick', nm=mac(), lip='10.0.0.%d' % (uuid.uuid4().int % 250 + 2), pp=pp(**ppkw), h=h or None)
    sid = sql("SELECT SystemID FROM phoromatic_systems WHERE MachineSelfID='%s'" % msi)[0]['SystemID']
    title = title or 'qcb' + uuid.uuid4().hex[:6]
    assert sql_exec("UPDATE phoromatic_systems SET Title='%s', State=%d WHERE SystemID='%s'" % (title, state, sid)) == 'ok'
    return msi, sid


def start(msi, pp_json='default', h=None, **ppkw):
    """A 'start' request (the client asking for work) with this request's own hardware properties"""
    if pp_json == 'default':
        pp_json = pp(**ppkw)
    return req(msi, 'start', pp=pp_json, h=h)


def composite(identifier='2026-10-27', title='qc bench result', value='1.5'):
    return COMPOSITE % {'identifier': identifier, 'title': title, 'value': value}


def upload(msi, sched=None, ts=None, bid=None, identifier=None, progressive=None, value=None):
    """A result upload the way the client posts it (sched/bid/ts empty for a manual phoromatic.upload-result)"""
    # a distinct value per upload: two identical composites from one system in the same second get the same PPRID (unique), and
    # result_upload.php then drops the second row while still answering with an upload id (pre-existing behaviour)
    xml = composite(identifier or (ts or 'adhoc'), value=value or '%d.%d' % (uuid.uuid4().int % 1000, uuid.uuid4().int % 1000))
    fields = {'sched': sched, 'bid': bid, 'ts': ts, 'composite_xml': base64.b64encode(xml.encode()).decode(), 'composite_xml_hash': hashlib.sha1(xml.encode()).hexdigest()}
    if progressive is not None:
        fields['progressive_upload'] = str(progressive)
    r = req(msi, 'result_upload', **fields)
    return r.get('upload_id')


def result_row(upload_id):
    r = sql("SELECT * FROM phoromatic_results WHERE AccountID='%s' AND UploadID=%d" % (AID, int(upload_id)))
    return r[0] if r else None


SCHED_SEQ = [880000]


def schedule(sid, title=None):
    """A daily schedule (all days, due since midnight) with one test, aimed at the system; returns its ScheduleID"""
    SCHED_SEQ[0] += 1
    sched = SCHED_SEQ[0] + int(uuid.uuid4().int % 1000) * 1000
    title = title or 'qc bench sched %d' % sched
    assert sql_exec("INSERT INTO phoromatic_schedules (AccountID, ScheduleID, Title, Description, State, ActiveOn, RunAt, RunTargetSystems, RunTargetGroups, RunPriority, LastModifiedBy) VALUES ('%s', %d, '%s', 'qc', 1, '0,1,2,3,4,5,6', '00.00', '%s', '', 100, 'qc')" % (AID, sched, title, sid)) == 'ok'
    assert sql_exec("INSERT INTO phoromatic_schedules_tests (AccountID, ScheduleID, TestProfile, TestArguments, TestDescription) VALUES ('%s', %d, 'pts/qc-bench-1.0.0', '', 'qc')" % (AID, sched)) == 'ok'
    return sched


def drop_schedule(sched):
    sql_exec("UPDATE phoromatic_schedules SET State=0 WHERE AccountID='%s' AND ScheduleID=%d" % (AID, sched))


def expect_task(msi, sid, want, why='', sched=None):
    """start(msi) and assert its task is `want`. On a mismatch the failure message carries the state that decides it (the system's bench
    row and drift, the schedule's results with their sessions, the active schedules and tickets aimed at the system, the clocks), so an
    intermittent miss leaves its evidence in the log."""
    reply = start(msi)
    got = reply.get('task')
    if got != want:
        parts = ['got %r, expected %r%s' % (got, want, (' (%s)' % why) if why else ''), 'reply: %s' % json.dumps(reply)[:400],
                 'system: %s' % json.dumps(sql("SELECT SystemID, State, BenchID, BenchDrift, BenchTaskSession, CurrentTask, LastCommunication FROM phoromatic_systems WHERE SystemID='%s'" % sid)),
                 'open session: %s' % json.dumps(sql("SELECT s.SessionID, s.BenchID, s.ComponentID FROM phoromatic_bench_sessions s JOIN phoromatic_systems y ON y.BenchID=s.BenchID WHERE y.SystemID='%s' AND s.EndedOn IS NULL" % sid)),
                 'results of the system: %s' % json.dumps(sql("SELECT UploadID, ScheduleID, Trigger, UploadTime, InProgress, BenchSessionID, BenchSessionSource FROM phoromatic_results WHERE SystemID='%s' ORDER BY UploadID" % sid)),
                 'active schedules aimed at it: %s' % json.dumps(sql("SELECT ScheduleID, Title, ActiveOn, RunAt, RunTargetSystems FROM phoromatic_schedules WHERE State=1 AND (',' || RunTargetSystems || ',') LIKE '%%,%s,%%'" % sid)),
                 'active tickets aimed at it: %s' % json.dumps(sql("SELECT TicketID, TicketIssueTime FROM phoromatic_benchmark_tickets WHERE State=1 AND (',' || RunTargetSystems || ',') LIKE '%%,%s,%%'" % sid)),
                 'clocks: web server %s, test process %s' % (srv_sh("php -r 'echo date(\"Y-m-d H:i:s T\");'").strip(), time.strftime('%Y-%m-%d %H:%M:%S %Z'))]
        raise AssertionError('\n    '.join(parts))


def today():
    return srv_sh("php -r 'echo date(\"Y-m-d\");'").strip()


# ---------------------------------------------------------------- web actions

def token(browser, path='/?systems'):
    m = re.search(r'name="token_submit" value="([0-9a-f]+)"', browser.get(path)[2])
    return m.group(1) if m else None


def act(browser, path, action, tok='auto', headers=None, **fields):
    """POST a bench page action; tok='auto' scrapes the browser's own token, None sends none"""
    data = dict(fields, bench_action=action)
    if tok == 'auto':
        tok = token(browser)
    if tok:
        data['token_submit'] = tok
    return browser.post(path, data, headers=headers)[2]


def promote(browser, sid, title=None, source='detected', cls='cpu', label=None, **extra):
    fields = {'title': title or 'Bench %s' % uuid.uuid4().hex[:5], 'source': source, 'class': cls, 'label': label if label is not None else CPU_A, 'config': 'qc constant parts'}
    fields.update(extra)
    return act(browser, '/?benches/promote/%s' % sid, 'promote', **fields)


def bench_of(sid):
    r = system_row(sid)
    return int(r['BenchID']) if r and r['BenchID'] is not None else None


def open_session(bench_id):
    r = sql("SELECT s.*, c.Label, c.Class FROM phoromatic_bench_sessions s JOIN phoromatic_components c ON c.ComponentID=s.ComponentID WHERE s.AccountID='%s' AND s.BenchID=%d AND s.EndedOn IS NULL" % (AID, bench_id))
    return r[0] if r else None


def sessions(bench_id):
    return sql("SELECT * FROM phoromatic_bench_sessions WHERE AccountID='%s' AND BenchID=%d ORDER BY SessionID" % (AID, bench_id))


def swap(browser, bench_id, label=None, cls='other', source='new', tok='auto', **extra):
    fields = {'source': source, 'class': cls, 'label': label or 'qc part %s' % uuid.uuid4().hex[:6]}
    fields.update(extra)
    return act(browser, '/?benches/%d' % bench_id, 'swap', tok=tok, **fields)


def bench_audit(bench_id, action=None):
    return sql("SELECT * FROM phoromatic_admin_audit WHERE SubjectType='bench' AND SubjectID='%d'%s ORDER BY AuditID" % (bench_id, " AND Action='%s'" % action if action else ''))


def baseline(session_id, sid):
    r = sql("SELECT * FROM phoromatic_bench_baselines WHERE SessionID=%d AND SystemID='%s'" % (session_id, sid))
    return r[0] if r else None


def drift(sid):
    d = system_row(sid)['BenchDrift']
    return json.loads(d) if d else None


def make_bench(browser, cls='cpu', label=None, **ppkw):
    """A system promoted to a bench with a first session; returns (msi, SystemID, BenchID, SessionID)"""
    msi, sid = new_system(**ppkw)
    if label is None:
        label = '%s %s' % (CPU_A if cls == 'cpu' else 'qc part', uuid.uuid4().hex[:6])
    body = promote(browser, sid, cls=cls, label=label, source='new' if cls not in ('cpu', 'gpu', 'motherboard', 'memory') else 'detected')
    bid = bench_of(sid)
    assert bid, body[:2000]
    return msi, sid, bid, int(open_session(bid)['SessionID'])
