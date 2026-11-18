"""HTTPS-level checks of join codes (server discovery D2, Benchy 0.10.0). Run INSIDE the server container by d2.sh (the harness image has python3),
against the built-in HTTPS in internal mode, so everything goes through Caddy, the front controller and the real command line:

    d2_web.py api       the v2 enroll endpoint with a join code: the wire rules, the check characters worked out the way a client does, the account
                        a code decides, the command line, the lockout of one address and the cap across all of them
    d2_web.py page      the Systems page box: the code shown once, the CSRF token and Origin rules, who may make one

Environment: H_BASE (https://d2srv:39443; the name resolves to this container), H_CAFILE (the server's root CA), H_USER and H_PASS (the first
account's administrator), D2_ACCOUNT_B (the second account's id), D2_DB (the database file). The container's own address is the source address of
every request, so the checks reset the failure counters between scenarios.
"""
import hashlib
import json
import os
import re
import sqlite3
import ssl
import subprocess
import sys
import time
import urllib.error
import urllib.request

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import https_web as hw  # noqa: E402

check = hw.check
BASE = os.environ['H_BASE'].rstrip('/')
CA = os.environ['H_CAFILE']
DB = os.environ.get('D2_DB', '/var/lib/benchy/phoromatic/phoromatic.db')
ACCOUNT_B = os.environ.get('D2_ACCOUNT_B', '')
ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ'
ORIGIN = {'Origin': BASE}
CTX = ssl.create_default_context(cafile=CA)


def sql(query, args=()):
    c = sqlite3.connect(DB, timeout=30)
    c.row_factory = sqlite3.Row
    try:
        rows = [dict(r) for r in c.execute(query, args).fetchall()]
        c.commit()
        return rows
    finally:
        c.close()


def reset_counters():
    sql('DELETE FROM phoromatic_v2_failures')


def cli(*args):
    r = subprocess.run(['sh', '/bench/benchy'] + list(args), stdin=subprocess.DEVNULL, capture_output=True, text=True, env=dict(os.environ, HOME='/root'), timeout=300)
    return r.returncode, r.stdout + r.stderr


def call(path, body, method='POST'):
    data = json.dumps(body).encode() if body is not None else None
    req = urllib.request.Request(BASE + path, data=data, method=method, headers={'Content-Type': 'application/json'})
    try:
        r = urllib.request.urlopen(req, timeout=60, context=CTX)
        status, text, headers = r.status, r.read().decode('utf-8', 'replace'), r.headers
    except urllib.error.HTTPError as e:
        status, text, headers = e.code, e.read().decode('utf-8', 'replace'), e.headers
    try:
        return status, json.loads(text), headers
    except ValueError:
        return status, text, headers


def enroll_body(field, value, seed='a'):
    b = json.load(open('/bench/pts-core/static/protocol-v2/examples/enroll_request.json'))
    del b['code']
    b[field] = value
    b['machine']['machine_id'] = (seed * 64)[:64]
    return b


def err(reply):
    return reply['error']['code'] if isinstance(reply, dict) and 'error' in reply else None


# ---- what a client does, written again here and not taken from the server's code
def check_chars(r, anchor):
    h = hashlib.sha256(b'benchy-join-v1\0' + r.encode() + b'\0' + anchor.encode()).digest()
    n = (h[0] << 12) | (h[1] << 4) | (h[2] >> 4)
    return ''.join(ALPHABET[(n >> (5 * i)) & 31] for i in (3, 2, 1, 0))


def client_anchor():
    """The anchor as a client finds it: the CA from server.php?benchy_ca fetched with verification off, exactly one certificate, its SHA-256"""
    ctx = ssl.create_default_context()
    ctx.check_hostname = False
    ctx.verify_mode = ssl.CERT_NONE
    pem = urllib.request.urlopen(BASE + '/server.php?benchy_ca', context=ctx, timeout=30).read().decode()
    certs = re.findall(r'-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----', pem, re.S)
    assert len(certs) == 1, len(certs)
    return hashlib.sha256(ssl.PEM_cert_to_DER_cert(certs[0])).hexdigest()


def verifies(code, anchor):
    return len(code) == 10 and check_chars(code[:6], anchor) == code[6:]


def note_code(code):
    # every secret this run makes goes in a file, so d2.sh can look for it in the server's logs afterwards
    with open('/tmp/d2-codes', 'a') as f:
        f.write(code + '\n')
    return code


def code_from_cli(out):
    m = re.search(r'^\s+([0-9A-HJKMNP-TV-Z]{3}-[0-9A-HJKMNP-TV-Z]{3}-[0-9A-HJKMNP-TV-Z]{4})\s*$', out, re.M)
    return note_code(m.group(1).replace('-', '')) if m else None


def account_a():
    return sql('SELECT AccountID FROM phoromatic_users WHERE UserName = ?', (hw.USER,))[0]['AccountID']


def with_tenant(extra):
    return list(extra) if '--tenant' in extra else list(extra) + ['--tenant', account_a()]


def join_via_cli(label, *extra):
    rc, out = cli('enroll-code-create', label, '--join', *with_tenant(extra))
    return rc, out, code_from_cli(out)


def ordinary_via_cli(label, *extra):
    rc, out = cli('enroll-code-create', label, *with_tenant(extra))
    m = re.search(r'^\s+(ben_[A-Za-z0-9_-]{43})\s*$', out, re.M)
    return rc, out, note_code(m.group(1)) if m else None


def scenario_api():
    anchor = client_anchor()
    reset_counters()
    st, info, _ = call('/api/v2/server', None, 'GET')
    check('A1 /api/v2/server says join_codes is true while the built-in HTTPS is on', st == 200 and info['join_codes'] is True and info['tls']['ca_sha256'] == anchor, info)

    rc, out, code = join_via_cli('d2 rig 1')
    check('A2 enroll-code-create --join prints a join code in groups, once, with its expiry and no pairing string', rc == 0 and code is not None and 'works once' in out and 'benchy-enroll' not in out, out)
    check('A3 the code verifies against the anchor a client finds for this server (the CA of ?benchy_ca), and against nothing else', verifies(code, anchor) and not verifies(code, 'b' * 64) and not verifies(code, 'name:d2srv'), code)
    row = sql("SELECT * FROM phoromatic_enrol_codes WHERE CodeHash = ?", (hashlib.sha256(code.encode()).hexdigest(),))
    check('A4 the database holds Kind join and the hash of the code, and no row holds the code itself', len(row) == 1 and row[0]['Kind'] == 'join' and code not in json.dumps(sql('SELECT * FROM phoromatic_enrol_codes')) and code not in json.dumps(sql('SELECT Detail FROM phoromatic_admin_audit')))
    typo = code[:3] + ALPHABET[(ALPHABET.index(code[3]) + 1) % 32] + code[4:]
    check('A5 a one-character typo fails the client check before anything is sent', not verifies(typo, anchor))

    before = sql("SELECT COUNT(*) AS n FROM phoromatic_v2_failures")[0]['n']
    base = enroll_body('join_code', code)
    both = dict(base, code='ben_' + 'A' * 43)
    neither = {k: v for k, v in base.items() if k != 'join_code'}
    statuses = [call('/api/v2/enroll', body)[0] for body in (both, neither, enroll_body('join_code', code.lower()), enroll_body('join_code', code[:3] + '-' + code[3:]), enroll_body('join_code', 'ben_' + 'A' * 43), enroll_body('code', code), enroll_body('join_code', code[:9]), enroll_body('join_code', code + 'A'), enroll_body('join_code', 7), enroll_body('join_code', None))]
    check('A6 both fields, neither, lower case, hyphens, a ben_ code or a join code in the wrong field, too short, too long, a number, null: all 400, never 401', statuses == [400] * 10, statuses)
    check('A7 and none of those counts as a failed attempt (the wire shape is checked before any code is looked at)', sql("SELECT COUNT(*) AS n FROM phoromatic_v2_failures")[0]['n'] == before)

    st, reply, _ = call('/api/v2/enroll', enroll_body('join_code', code))
    check('A8 the join code enrolls over HTTPS: 201, a bsa_ token, a system, the account, the native policy', st == 201 and re.match(r'^bsa_[A-Za-z0-9_-]{43}$', reply.get('token', '')) and reply['policy'] == 'native' and reply['system']['id'], reply)
    sid = reply['system']['id'] if st == 201 else None
    srow = sql('SELECT AccountID, State, EnginePolicy FROM phoromatic_systems WHERE SystemID = ?', (sid,))
    check('A9 the system is approved and native, in the account the code was made in', len(srow) == 1 and srow[0]['State'] == 1 and srow[0]['EnginePolicy'] == 'native' and srow[0]['AccountID'] == reply['account']['id'], srow)
    st, reply, _ = call('/api/v2/enroll', enroll_body('join_code', code, 'b'))
    check('A10 the same code again is 401 code_used', st == 401 and err(reply) == 'code_used', reply)
    rc, out, code2 = join_via_cli('d2 rig alias')
    st, reply, _ = call('/api/v2/enrol', enroll_body('join_code', code2, 'c'))
    check('A11 the older spelling of the path takes a join code the same way', st == 201, reply)
    rc, out, code3 = join_via_cli('d2 rig expired')
    sql("UPDATE phoromatic_enrol_codes SET ExpiresOn = '2020-01-01 00:00:00' WHERE CodeHash = ?", (hashlib.sha256(code3.encode()).hexdigest(),))
    st, reply, _ = call('/api/v2/enroll', enroll_body('join_code', code3, 'd'))
    check('A12 an expired join code is 401 code_expired', st == 401 and err(reply) == 'code_expired', reply)
    st, reply, _ = call('/api/v2/enroll', enroll_body('join_code', '2222222222', 'e'))
    check('A13 a code nobody made is 401 code_invalid', st == 401 and err(reply) == 'code_invalid', reply)

    # the code decides the account
    rc, out, codeb = join_via_cli('d2 rig b', '--tenant', ACCOUNT_B)
    check('A14 --tenant makes a code for the other account', rc == 0 and codeb and verifies(codeb, anchor), out)
    st, reply, _ = call('/api/v2/enroll', enroll_body('join_code', codeb, 'f'))
    srow = sql('SELECT AccountID FROM phoromatic_systems WHERE SystemID = ?', (reply['system']['id'],)) if st == 201 else []
    check('A15 the machine lands in that account, and the request named no account at all', st == 201 and reply['account']['id'] == ACCOUNT_B and srow and srow[0]['AccountID'] == ACCOUNT_B, reply)
    rc, out = cli('enroll-code-create', 'no tenant', '--join')
    check('A16 with two accounts and no --tenant the command refuses and makes nothing', rc != 0 and 'active accounts, so say which one with --tenant' in out and not sql("SELECT 1 FROM phoromatic_enrol_codes WHERE Label = 'no tenant'"), out)

    # the command line
    rc, out, ordinary = ordinary_via_cli('d2 ordinary')
    check('A17 without --join the command makes an ordinary code and its pairing string', rc == 0 and ordinary and 'benchy-enroll:https://' in out and '&ca=' + anchor in out, out)
    rc, out = cli('enroll-code-create')
    check('A18 with no label and no --join it prints the usage and exits with 2', rc == 2 and 'Usage' in out, out)
    rc, out, _ = join_via_cli('x', '--system', 'NOSUCH')
    check('A19 a system that does not exist is refused', rc != 0 and 'System not found' in out, out)
    rc, out = cli('enroll-code-create', 'bad<b>', '--join', *with_tenant([]))
    check('A20 a label with markup is refused', rc != 0 and 'markup' in out, out)
    rows = sql("SELECT Action, Detail, ActorType FROM phoromatic_admin_audit WHERE Action = 'agent_code_create' AND Detail LIKE '%\"kind\":\"join\"%'")
    check('A21 every join code made is one audit row with kind join, made by a local user, and no code in any of them', len(rows) >= 4 and all(r['ActorType'] == 'user' for r in rows) and all(re.search(r'[0-9A-HJKMNP-TV-Z]{10}', r['Detail']) is None for r in rows), rows[:2])

    # the lockout of one address
    reset_counters()
    rc, out, good = join_via_cli('d2 lockout')
    wrong = [call('/api/v2/enroll', enroll_body('join_code', '3333333333', '1'))[0] for _ in range(10)]
    st, reply, hdrs = call('/api/v2/enroll', enroll_body('join_code', good, '1'))
    check('A22 ten wrong join codes from one address are ten 401s, and then even the right code is 429 with a Retry-After', wrong == [401] * 10 and st == 429 and err(reply) == 'rate_limited' and int(hdrs.get('Retry-After', 0)) > 0, (wrong, st, reply))
    rc, out, ordinary2 = ordinary_via_cli('d2 lockout ordinary')
    st, reply, _ = call('/api/v2/enroll', enroll_body('code', ordinary2, '2'))
    check('A23 the same address is locked out for ordinary codes as well (one budget)', st == 429, reply)
    check('A24 the lockout is audited at its first failure and when it trips, not for every try', 1 <= sql("SELECT COUNT(*) AS n FROM phoromatic_admin_audit WHERE Action = 'agent_enroll_failed'")[0]['n'] <= 6)

    # the cap across all addresses
    reset_counters()
    now = int(time.time())
    for i in range(97):
        sql("INSERT INTO phoromatic_v2_failures (Kind, SourceIP, At) VALUES ('join', ?, ?)", ('198.51.100.%d/%d' % (i % 250 + 1, i), now - 20))
    rc, out, capped = join_via_cli('d2 capped')
    rc, out, ordinary3 = ordinary_via_cli('d2 capped ordinary')
    for _ in range(2):
        call('/api/v2/enroll', enroll_body('join_code', '4444444444', '3'))
    st, reply, _ = call('/api/v2/enroll', enroll_body('join_code', capped, '3'))
    check('A25 at 99 failures server-wide a right join code still works', st == 201, reply)
    rc, out, capped = join_via_cli('d2 capped 2')
    call('/api/v2/enroll', enroll_body('join_code', '4444444444', '4'))
    st, reply, hdrs = call('/api/v2/enroll', enroll_body('join_code', capped, '5'))
    check('A26 the hundredth wrong one switches join codes off: the right code is 429 rate_limited with a Retry-After of at most 600 s, and the message says a pairing string still works',
          st == 429 and err(reply) == 'rate_limited' and 0 < int(hdrs.get('Retry-After', 0)) <= 600 and 'pairing string' in reply['error']['message'], (st, reply, dict(hdrs)))
    st, reply, _ = call('/api/v2/enroll', enroll_body('code', ordinary3, '6'))
    check('A27 an ordinary code enrolls during the pause', st == 201, reply)
    check('A28 the pause is one audit row, join_codes_paused, naming the numbers and no code', len(sql("SELECT * FROM phoromatic_admin_audit WHERE Action = 'join_codes_paused'")) == 1)
    sql("UPDATE phoromatic_v2_failures SET At = At - 601 WHERE Kind IN ('joinpause', 'join')")
    st, reply, _ = call('/api/v2/enroll', enroll_body('join_code', capped, '7'))
    check('A29 ten minutes later the same join code enrolls', st == 201, reply)
    reset_counters()


def scenario_page():
    anchor = client_anchor()
    reset_counters()
    admin = hw.Browser().login()
    tok = lambda b, path='/?systems': b.token(path)
    page = admin.get('/?systems')[1]
    check('B1 the Systems page offers "Make a join code" next to the pairing string button', 'name="native_join"' in page and 'value="Make a join code"' in page and 'value="Enroll a new native system"' in page)

    def post(b, fields, token=True, headers=ORIGIN, path='/?systems'):
        data = dict(fields)
        if token:
            data['token_submit'] = tok(b, path)
        return b.post(path, data, headers=headers)[1]

    n0 = sql('SELECT COUNT(*) AS n FROM phoromatic_enrol_codes')[0]['n']
    body = post(admin, {'native_enroll': '1', 'native_join': 'Make a join code', 'native_label': 'page rig'})
    hdr = admin.last_headers
    m = re.search(r'<code id="join_code">([0-9A-HJKMNP-TV-Z]{3}-[0-9A-HJKMNP-TV-Z]{3}-[0-9A-HJKMNP-TV-Z]{4})</code>', body)
    code = note_code(m.group(1).replace('-', '')) if m else None
    check('B2 the button shows a join code in groups of three, three and four, in a box that says an hour and once', code is not None and 'within an hour' in body and 'works once' in body, body[-600:])
    check('B3 the page is not cached, and the code verifies against the anchor a client finds', 'no-store' in (hdr.get('Cache-Control') or '') and code is not None and verifies(code, anchor), dict(hdr))
    check('B4 it is the join button that made it: Kind join in the table, one row more, no pairing string on the page', sql('SELECT COUNT(*) AS n FROM phoromatic_enrol_codes')[0]['n'] == n0 + 1 and sql("SELECT Kind FROM phoromatic_enrol_codes WHERE Label = 'page rig'")[0]['Kind'] == 'join' and 'benchy-enroll:' not in body)
    again = admin.get('/?systems')[1]
    check('B5 shown once: reloading shows neither the code nor its groups, but lists it as an open join code', code not in again and (code[:3] + '-' + code[3:6]) not in again and 'page rig' in again and 'Join code' in again)
    st, reply, _ = call('/api/v2/enroll', enroll_body('join_code', code, '8'))
    check('B6 the code from the page enrolls', st == 201, reply)
    sid = reply['system']['id'] if st == 201 else '-'

    n = sql('SELECT COUNT(*) AS n FROM phoromatic_enrol_codes')[0]['n']
    out = post(admin, {'native_enroll': '1', 'native_join': 'x', 'native_label': 'no token'}, token=False)
    check('B7 without the CSRF token nothing is made, and the page says it expired', 'expired' in out and sql('SELECT COUNT(*) AS n FROM phoromatic_enrol_codes')[0]['n'] == n and 'id="join_code"' not in out)
    out = post(admin, {'native_enroll': '1', 'native_join': 'x', 'native_label': 'foreign'}, headers={'Origin': 'https://evil.example'})
    check('B8 from another origin nothing is made', 'id="join_code"' not in out and sql('SELECT COUNT(*) AS n FROM phoromatic_enrol_codes')[0]['n'] == n)
    out = post(admin, {'native_enroll': '1', 'native_join': 'x', 'native_label': '<b>x</b>'})
    check('B9 a label with markup is refused and nothing is made', 'id="join_code"' not in out and 'markup' in out and sql('SELECT COUNT(*) AS n FROM phoromatic_enrol_codes')[0]['n'] == n)
    out = post(admin, {'native_enroll': '1', 'native_join': 'x', 'native_label': 'on a system'}, path='/?systems/' + sid)
    check('B10 a join code cannot be made from one system\'s page (it makes new systems), and nothing is made', 'id="join_code"' not in out and 'makes a new system' in out and sql('SELECT COUNT(*) AS n FROM phoromatic_enrol_codes')[0]['n'] == n, out[-300:])
    out = post(admin, {'native_enroll': '1', 'native_join': 'x', 'native_label': ''})
    check('B11 no label is fine: it is called "join code"', 'id="join_code"' in out and sql("SELECT COUNT(*) AS n FROM phoromatic_enrol_codes WHERE Label = 'join code'")[0]['n'] == 1)

    # who may
    hw.make_user(admin, 'qcpower2', 3)
    hw.make_user(admin, 'qcview2', 10)
    for name, level in (('qcpower2', 'a power user'), ('qcview2', 'a viewer')):
        b = hw.Browser().login(name, 'qcpass123')
        shown = b.get('/?systems')[1]
        n = sql('SELECT COUNT(*) AS n FROM phoromatic_enrol_codes')[0]['n']
        out = post(b, {'native_enroll': '1', 'native_join': 'x', 'native_label': 'sneaky ' + name})
        check('B12 %s has no join code button, and a posted one makes nothing' % level, 'native_join' not in shown and 'id="join_code"' not in out and sql('SELECT COUNT(*) AS n FROM phoromatic_enrol_codes')[0]['n'] == n and not sql("SELECT 1 FROM phoromatic_enrol_codes WHERE Label LIKE 'sneaky%'"))

    sql("INSERT INTO phoromatic_v2_failures (Kind, SourceIP, At) VALUES ('joinpause', '*', ?)", (int(time.time()),))
    out = admin.get('/?systems')[1]
    check('B13 while join codes are paused the Systems page says so, and that a pairing string still works', 'switched off' in out and 'pairing string' in out)
    reset_counters()
    bad = [w for w in ('Fatal error', 'Warning</b>', 'Notice</b>', 'Deprecated</b>', 'Uncaught', 'Array to string') if w in out or w in body or w in again]
    check('B14 no PHP warning or error on any of those pages', not bad, bad)


if __name__ == '__main__':
    {'api': scenario_api, 'page': scenario_page}[sys.argv[1]]()
    print('RESULT d2_web %s pass=%d fail=%d' % (sys.argv[1], hw.PASSES, hw.FAILS))
    sys.exit(1 if hw.FAILS else 0)
