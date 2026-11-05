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


def enroll_body(code, machine_seed='a', hostname='qc-native'):
    b = example('enroll_request')
    b['code'] = code
    b['machine']['machine_id'] = machine_id(machine_seed)
    b['machine']['hostname'] = hostname
    return b


def enroll(system=None, label='qc rig', policy='native', machine_seed='a', hostname='qc-native'):
    """A fresh code and the enrollment it buys: (token, reply body)"""
    code = new_code(label, system, policy)['code']
    status, body, _ = call('/api/v2/enroll', enroll_body(code, machine_seed, hostname))
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


def server_log_problems():
    """Lines the v2 code logged about a PHP warning, notice or uncaught error (the server prints them to its stderr, which docker keeps)"""
    import subprocess
    from test_private_repo_http import SRV
    out = subprocess.run(['docker', 'logs', SRV], capture_output=True, text=True)
    return [l for l in (out.stdout + out.stderr).splitlines() if 'api v2:' in l][:10]


def make_other_account():
    """A second account row (nothing else of it exists), removed again with everything that hangs on it. Other suites pick "the other
    account" as the newest account that is not the harness's, so a leftover row would be mistaken for it."""
    other = 'QCOTH' + uuid.uuid4().hex[:6]
    assert sql_exec("INSERT INTO phoromatic_accounts (AccountID, ValidateID, CreatedOn, Salt) VALUES ('%s', 'x', '2000-01-01 00:00:00', 'x')" % other) == 'ok'
    return other


def remove_account(account):
    for table in ('phoromatic_accounts', 'phoromatic_systems', 'phoromatic_system_tokens', 'phoromatic_enrol_codes', 'phoromatic_v2_jobs', 'phoromatic_system_catalogue'):
        sql_exec("DELETE FROM %s WHERE AccountID='%s'" % (table, account))


def check_inline(schema, body):
    import base64
    out = srv_sh("php /bench/tests/private-repo/agent_admin.php validate-inline '%s' '%s'" % (base64.b64encode(json.dumps(schema).encode()).decode(), base64.b64encode(json.dumps(body).encode()).decode()))
    return json.loads(out[out.index('{'):])['errors']


class Sidecar:
    """A second PHP server in the harness container (port 8082, low memory limit, its own error log) for the tests that make the v2 code crash
    on purpose. The shared server prints every logged error to the container log, which the other modules treat as a failure
    (server_log_problems), so a deliberate crash goes here. Same code tree, same database. Use as a context manager."""
    PORT = 8082

    def __enter__(self):
        import subprocess
        import time
        from test_private_repo_http import SRV
        self.log = '/tmp/qc-sidecar-%s.log' % uuid.uuid4().hex[:6]
        srv_sh('rm -f %s' % self.log)
        subprocess.run(['docker', 'exec', '-d', '-w', '/bench/pts-core/phoromatic/public_html', SRV, 'php', '-d', 'memory_limit=64M', '-d', 'post_max_size=16M', '-d', 'upload_max_filesize=16M',
                        '-d', 'error_log=' + self.log, '-S', '0.0.0.0:%d' % self.PORT, '-t', '/bench/pts-core/phoromatic/public_html'], check=True)
        out = subprocess.run(['docker', 'inspect', '-f', '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}', SRV], capture_output=True, text=True).stdout.strip()
        self.base = 'http://%s:%d' % (out, self.PORT)
        for _ in range(30):
            try:
                urllib.request.urlopen(self.base + '/', timeout=2)
                break
            except urllib.error.HTTPError:
                break
            except OSError:
                time.sleep(0.5)
        return self

    def __exit__(self, *exc):
        srv_sh("pkill -f '[%s]%s -t /bench'; rm -f %s" % (str(self.PORT)[0], str(self.PORT)[1:], self.log))

    def logged(self):
        return srv_sh('cat %s 2>/dev/null' % self.log)

    def call(self, path, body=None, token=None, raw=None, content_type='application/json'):
        """The same as call(), against the sidecar"""
        data = raw if raw is not None else json.dumps(body).encode()
        req = urllib.request.Request(self.base + path, data=data, method='POST')
        req.add_header('Content-Type', content_type)
        if token:
            req.add_header('Authorization', 'Bearer ' + token)
        try:
            with urllib.request.urlopen(req, timeout=120) as r:
                status, text = r.status, r.read().decode('utf-8', 'replace')
        except urllib.error.HTTPError as e:
            status, text = e.code, e.read().decode('utf-8', 'replace')
        try:
            return status, json.loads(text)
        except ValueError:
            return status, text
