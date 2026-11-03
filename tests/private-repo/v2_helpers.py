"""Shared helpers for the native agent API tests (test_native_api*.py): the administrator's side through agent_admin.php, v2 calls the way
an agent makes them, and bodies built from the examples pinned in pts-core/static/protocol-v2/.

Not a test module itself. Run through tests/private-repo/run.sh.
"""
import copy
import json
import os
import urllib.error
import urllib.request
import uuid

from test_private_repo_http import AID, BASE, CORE, srv_sh, sql
from test_private_repo_tokens import sql_exec

EXAMPLES = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '..', 'pts-core', 'static', 'protocol-v2', 'examples')
FIXTURES = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'fixtures', 'native')


def example(name):
    return copy.deepcopy(json.load(open(os.path.join(EXAMPLES, name + '.json'))))


def admin(*args):
    out = srv_sh('php /bench/tests/private-repo/agent_admin.php ' + ' '.join("'%s'" % a for a in args))
    return json.loads(out[out.index('{'):])


def allow_http(on):
    r = admin('allow-http', 'on' if on else 'off')
    assert not r.get('error'), r


def machine_id(seed='a'):
    return (seed * 64)[:64]


def new_code(label='qc rig', system=None, policy='native', account=None):
    r = admin('code', account or AID, label, system or '-', policy or '-')
    assert 'code' in r, r
    return r


def call(path, body=None, token=None, raw=None, headers=None, method='POST', content_type='application/json'):
    """(status, decoded json or text, response headers). `raw` is sent as the body as it is."""
    data = raw if raw is not None else (json.dumps(body).encode() if body is not None else b'')
    req = urllib.request.Request(BASE + path, data=data if method == 'POST' else None, method=method)
    if content_type:
        req.add_header('Content-Type', content_type)
    if token:
        req.add_header('Authorization', 'Bearer ' + token)
    for k, v in (headers or {}).items():
        req.add_header(k, v)
    try:
        with urllib.request.urlopen(req, timeout=60) as r:
            status, text, hdrs = r.status, r.read().decode('utf-8', 'replace'), dict(r.headers)
    except urllib.error.HTTPError as e:
        status, text, hdrs = e.code, e.read().decode('utf-8', 'replace'), dict(e.headers)
    try:
        return status, json.loads(text), hdrs
    except ValueError:
        return status, text, hdrs


def enrol_body(code, machine_seed='a', hostname='qc-native'):
    b = example('enrol_request')
    b['code'] = code
    b['machine']['machine_id'] = machine_id(machine_seed)
    b['machine']['hostname'] = hostname
    return b


def enrol(system=None, label='qc rig', policy='native', machine_seed='a', hostname='qc-native'):
    """A fresh code and the enrolment it buys: (token, reply body)"""
    code = new_code(label, system, policy)['code']
    status, body, _ = call('/api/v2/enrol', enrol_body(code, machine_seed, hostname))
    assert status == 201, (status, body)
    return body['token'], body


def hello_body(machine_seed='a', catalogue=None):
    b = example('hello_request')
    b['machine_id'] = machine_id(machine_seed)
    if catalogue is not None:
        b['catalogue'] = catalogue
    return b


def poll_body(machine_seed='a', state='idle', current=None, busy_reason=None):
    return {'state': state, 'busy_reason': busy_reason, 'current_job': current, 'machine_id': machine_id(machine_seed)}


def system_of(token_reply):
    return token_reply['system']['id']


def native_schedule(sid, tests=(('benchy/stream', {}),), title=None):
    """A daily schedule due now, aimed at the system, with native test rows (and no PTS rows)"""
    from bench_helpers import SCHED_SEQ
    SCHED_SEQ[0] += 1
    sched = SCHED_SEQ[0] + int(uuid.uuid4().int % 1000) * 1000
    title = title or 'qc native %d' % sched
    assert sql_exec("INSERT INTO phoromatic_schedules (AccountID, ScheduleID, Title, Description, State, ActiveOn, RunAt, RunTargetSystems, RunTargetGroups, RunPriority, LastModifiedBy) VALUES ('%s', %d, '%s', 'qc native', 1, '0,1,2,3,4,5,6', '00.00', '%s', '', 100, 'qc')" % (AID, sched, title, sid)) == 'ok'
    for i, (ref, options) in enumerate(tests):
        assert sql_exec("INSERT INTO phoromatic_schedules_native_tests (AccountID, ScheduleID, Position, Ref, OptionsJSON, AddedOn, AddedBy) VALUES ('%s', %d, %d, '%s', '%s', 'now', 'qc')" % (AID, sched, i, ref, json.dumps(options, sort_keys=True))) == 'ok'
    return sched


def drop_native_schedule(sched):
    for table in ('phoromatic_schedules', 'phoromatic_schedules_native_tests'):
        sql_exec("DELETE FROM %s WHERE AccountID='%s' AND ScheduleID=%d" % (table, AID, sched))


def check(def_name, body, file='v2.schema.json'):
    """The problems the pinned schema finds in a body (the server's own validator, run in the container); [] when it is valid"""
    import base64
    out = srv_sh("php /bench/tests/private-repo/agent_admin.php validate '%s#/$defs/%s' '%s'" % (file, def_name, base64.b64encode(json.dumps(body).encode()).decode()))
    return json.loads(out[out.index('{'):])['errors']


def call_local(path, body, token='-'):
    """The same as call(), but made from inside the server container (source address 127.0.0.1): (status, json, retry_after or None)"""
    out = srv_sh("php /bench/tests/private-repo/post_local.php '%s' '%s' '%s'" % (path, token, json.dumps(body)))
    head, _, rest = out.partition('\n\n')
    lines = head.split('\n')
    status = int(lines[0].split()[1])
    retry = None
    for l in lines[1:]:
        if l.lower().startswith('retry-after:'):
            retry = int(l.split(':')[1])
    try:
        return status, json.loads(rest), retry
    except ValueError:
        return status, rest, retry


def multipart(parts):
    """A multipart/form-data body: parts is a list of (name, filename or None, content type, bytes). (body, content type)"""
    boundary = 'qcboundary' + uuid.uuid4().hex
    out = b''
    for name, filename, ctype, data in parts:
        out += ('--%s\r\nContent-Disposition: form-data; name="%s"%s\r\nContent-Type: %s\r\n\r\n' % (boundary, name, '; filename="%s"' % filename if filename else '', ctype)).encode() + data + b'\r\n'
    out += ('--%s--\r\n' % boundary).encode()
    return out, 'multipart/form-data; boundary=' + boundary


def upload_result(token, job_id, result, events_gz=None, as_file=True):
    """POST /api/v2/jobs/<id>/result the way the agent does: (status, json, headers)"""
    parts = [('result', 'result.json' if as_file else None, 'application/json', json.dumps(result).encode() if not isinstance(result, bytes) else result)]
    if events_gz is not None:
        parts.append(('events', 'events.jsonl.gz', 'application/gzip', events_gz))
    body, ctype = multipart(parts)
    return call('/api/v2/jobs/%d/result' % job_id, raw=body, token=token, content_type=ctype)


def fixture_result(name, run, identifier=None, mutate=None):
    d = json.load(open(os.path.join(FIXTURES, name + '.json')))
    d['runs'][0]['run'] = run
    if identifier:
        d['runs'][0]['identifier'] = identifier
    if mutate:
        mutate(d)
    return d


def new_run_id():
    import time
    return time.strftime('%Y%m%dT%H%M%SZ', time.gmtime()) + '-' + uuid.uuid4().hex[:6]


def event(type_, seq, run=None, **fields):
    e = {'v': 1, 'type': type_, 'seq': seq, 'ts': '2026-11-02T21:55:46.067Z'}
    if run:
        e['run'] = run
    e.update(fields)
    return e


def hello_event(run, seq=1):
    return event('hello', seq, run, engine={'name': 'benchy-native', 'version': '0.3.0'}, protocol=1, capabilities=['cancel'], os='linux', command='benchmark', pid=4242)


def job_row(job_id):
    r = sql('SELECT * FROM phoromatic_v2_jobs WHERE JobID=%d' % job_id)
    return r[0] if r else None


def get_job(token, current=None, seed='a'):
    """One poll: the job it hands out (or None) and the whole reply"""
    status, body, _ = call('/api/v2/agent/poll', poll_body(seed, current=current), token=token)
    assert status == 200, (status, body)
    return body['job'], body
