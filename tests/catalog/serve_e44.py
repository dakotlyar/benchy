#!/usr/bin/env python3
"""E4.4 C3: the server serves the signed catalog (server design 4.2, 4.3, 5; refusal tests of 14.3). Driven by serve.sh against a scratch server whose key table holds throw-away
test keys (TN_SRV, TN_PORT) with the bundles of make_rollback_bundles.php in /bundles: a (public, sequence 1), b (private, sequence 1), c (public, sequence 2).

  no-catalog  a fresh server: hello has no catalog, both routes and a pack are 404, the capabilities a hello reports are kept and replaced, the new fields validate
  serve       after the import: hello's tiers, both routes' bytes and headers, the pack route, the method and tier rules, and the whole auth matrix of 4.3 (N23)
  rehello     an import after a system's hello: the next poll says hello again before any job, and only to a system that has catalog_v1

Nothing here touches the machine's own Benchy: the server is a throw-away container.
"""
import hashlib
import http.client
import json
import os
import subprocess
import sys
import time
import urllib.error
import urllib.request

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'tenancy'))
import tn  # noqa: E402

EXAMPLES = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '..', 'pts-core', 'static', 'protocol-v2', 'examples')
fails = 0
A, B = 'SRV44A', 'SRV44B'


def check(what, ok, detail=''):
    global fails
    print('  %s  %s%s' % ('PASS' if ok else 'FAIL', what, '' if ok else ' :: ' + str(detail)[:400]))
    if not ok:
        fails += 1


def example(name):
    with open(os.path.join(EXAMPLES, name + '.json')) as f:
        return json.load(f)


def admin(*args):
    out = tn.srv_sh('php /bench/tests/private-repo/agent_admin.php ' + ' '.join("'%s'" % a for a in args))
    return json.loads(out[out.index('{'):])


def raw(method, path, token=None, body=None, headers=None):
    """(status, headers dict with lower-case names, bytes)"""
    conn = http.client.HTTPConnection('127.0.0.1', int(tn.PORT), timeout=60)
    h = dict(headers or {})
    if token:
        h['Authorization'] = 'Bearer ' + token
    data = None
    if body is not None:
        data = json.dumps(body).encode()
        h['Content-Type'] = 'application/json'
    conn.request(method, path, body=data, headers=h)
    r = conn.getresponse()
    out = r.read()
    status, hdrs = r.status, {k.lower(): v for k, v in r.getheaders()}
    conn.close()
    return status, hdrs, out


def jcall(path, body, token=None):
    status, hdrs, out = raw('POST', path, token, body)
    try:
        return status, json.loads(out), hdrs
    except ValueError:
        return status, out, hdrs


def code_of(out):
    try:
        return json.loads(out)['error']['code']
    except (ValueError, KeyError, TypeError):
        return None


def validate(definition, body):
    import base64
    r = admin('validate', 'v2.schema.json#/$defs/' + definition, base64.b64encode(json.dumps(body).encode()).decode())
    return r['errors']


def enroll(aid, hostname, seed):
    r = admin('code', aid, 'serve test', '-', 'native')
    b = example('enroll_request')
    b['code'] = r['code']
    b['machine']['machine_id'] = (seed * 64)[:64]
    b['machine']['hostname'] = hostname
    status, body, _ = jcall('/api/v2/enroll', b)
    assert status == 201, (status, body)
    return body['token'], body['system']['id'], (seed * 64)[:64]


def hello_body(machine_id, caps=('cancel', 'json_args', 'run_lock', 'agent'), extra_engine=None, admin_obj=None):
    b = example('hello_request')
    b['machine_id'] = machine_id
    b['engine']['capabilities'] = list(caps)
    b['engine'].update(extra_engine or {})
    if admin_obj is not None:
        b['catalogue'][0]['admin'] = admin_obj
    return b


def poll_body(machine_id, **extra):
    b = {'state': 'idle', 'busy_reason': None, 'current_job': None, 'machine_id': machine_id}
    b.update(extra)
    return b


def sysrow(sid, aid=A):
    return tn.sql("SELECT AgentCapabilities, SystemProperties, LastCommunication FROM phoromatic_systems WHERE AccountID='%s' AND SystemID='%s'" % (aid, sid))[0]


def tier_files():
    rows = tn.sql('SELECT Tier, SignedSHA256, Generation, Sequence FROM phoromatic_catalog_tiers ORDER BY Tier')
    return {r['Tier']: r for r in rows}


def storage_file(rel):
    return tn.STORAGE + '/catalog/' + rel


def no_catalog():
    r = tn.srv_sh('sh /bench/benchy server-account-create --user serveadmin --email s@example.com --account-id %s' % A, stdin=tn.PW + '\n')
    check('account A made', 'Account created' in r, r)
    r = tn.srv_sh('sh /bench/benchy server-account-create --user serveadmin2 --email s2@example.com --account-id %s' % B, stdin=tn.PW + '\n')
    check('account B made', 'Account created' in r, r)
    check('plain HTTP allowed for the test agent', not admin('allow-http', 'on').get('error'))
    token, sid, mid = enroll(A, 'srv-a', 'a')
    status, body, _ = jcall('/api/v2/agent/hello', hello_body(mid, caps=('catalog_v1', 'elevation_unattended', 'Bad Word', 'catalog_v1', 'x' * 41, 'elevation_desktop')), token)
    check('hello on a server with no catalog is 200', status == 200, body)
    check('and has no catalog key', isinstance(body, dict) and 'catalog' not in body, body)
    check('the reply is valid for hello_response', validate('hello_response', body) == [])
    caps = json.loads(sysrow(sid)['AgentCapabilities'])
    check('the capability words are kept: lower-case words only, once each', caps == ['catalog_v1', 'elevation_unattended', 'elevation_desktop'], caps)
    status, body, _ = jcall('/api/v2/agent/hello', hello_body(mid, caps=('cancel', 'agent'), extra_engine={'not_set_up_reason': 'no_desktop_client'}), token)
    check('the next hello replaces them (a downgraded agent loses catalog_v1)', json.loads(sysrow(sid)['AgentCapabilities']) == ['cancel', 'agent'], sysrow(sid))
    props = json.loads(sysrow(sid)['SystemProperties'])['benchy_agent']
    check('not_set_up_reason is kept with the hello', props.get('not_set_up_reason') == 'no_desktop_client', props)
    status, body, _ = jcall('/api/v2/agent/hello', hello_body(mid, extra_engine={'not_set_up_reason': 'Bad Reason!'}), token)
    props = json.loads(sysrow(sid)['SystemProperties'])['benchy_agent']
    check('a reason that is not a plain word is dropped, the hello still works', status == 200 and props.get('not_set_up_reason') is None, (status, props))
    full = hello_body(mid, caps=('catalog_v1', 'elevation_desktop'), extra_engine={'not_set_up_reason': 'binary_not_protected'},
                      admin_obj={'elevated_run': True, 'machine_install': False, 'accepted_versions': ['1.0.0', '1.1.0'], 'installed': None})
    check('a hello with every 0.7.0 field is valid', validate('hello_request', full) == [])
    status, body, _ = jcall('/api/v2/agent/hello', full, token)
    check('and is accepted; the admin object stays in the stored catalogue entry', status == 200 and '"accepted_versions":["1.0.0","1.1.0"]' in tn.sql("SELECT TestJSON FROM phoromatic_system_catalogue WHERE SystemID='%s' LIMIT 1" % sid)[0]['TestJSON'], (status, body))
    bad = hello_body(mid, admin_obj={'accepted_versions': 'x'})
    status, body, _ = jcall('/api/v2/agent/hello', bad, token)
    check('an admin object of the wrong shape is a 400', status == 400 and code_of(json.dumps(body).encode()) == 'bad_request', (status, body))
    status, body, _ = jcall('/api/v2/agent/poll', poll_body(mid, ready_for_approval={'schedule_id': 7}), token)
    check('a poll with ready_for_approval is accepted', status == 200 and validate('poll_request', poll_body(mid, ready_for_approval={'schedule_id': 7})) == [], (status, body))
    status, body, _ = jcall('/api/v2/agent/poll', poll_body(mid, ready_for_approval={'schedule_id': 'x'}), token)
    check('and one with a bad schedule_id is a 400', status == 400, (status, body))
    for path in ('/api/v2/catalog/public/index.signed', '/api/v2/catalog/private/index.signed', '/api/v2/catalog/packs/' + 'a' * 64):
        status, hdrs, out = raw('GET', path, token)
        check('no catalog: GET %s is a 404 not_found' % path.split('/api/v2/')[1][:40], status == 404 and code_of(out) == 'not_found', (status, out[:100]))
    return token, sid, mid


def serve():
    token, sid, mid = no_catalog_state['a']
    tiers = tier_files()
    check('the import made both tiers', set(tiers) == {'public', 'private'}, tiers)
    # hello
    status, body, _ = jcall('/api/v2/agent/hello', hello_body(mid), token)
    want = [{'tier': 'private', 'generation': 1, 'sequence': 1}, {'tier': 'public', 'generation': 1, 'sequence': 1}]
    check('hello of an approved system lists the tiers with their pairs', status == 200 and sorted(body.get('catalog', {}).get('tiers', []), key=lambda t: t['tier']) == want, body)
    check('and the reply is valid for hello_response', validate('hello_response', body) == [])
    # the index route
    for tier in ('public', 'private'):
        status, hdrs, out = raw('GET', '/api/v2/catalog/%s/index.signed' % tier, token)
        check('GET %s index.signed: 200 and exactly the stored file' % tier, status == 200 and hashlib.sha256(out).hexdigest() == tiers[tier]['SignedSHA256'], (status, len(out)))
        check('  headers: octet-stream, Content-Length, no-store, nosniff', hdrs.get('content-type') == 'application/octet-stream' and hdrs.get('content-length') == str(len(out))
              and hdrs.get('cache-control') == 'no-store' and hdrs.get('x-content-type-options') == 'nosniff', hdrs)
    pub = raw('GET', '/api/v2/catalog/public/index.signed', token)[2]
    priv = raw('GET', '/api/v2/catalog/private/index.signed', token)[2]
    check('the public route never returns the private tier\'s file', pub != priv and b'"tier": "public"' in pub.replace(b'"tier":"public"', b'"tier": "public"'), pub[:200])
    for path in ('/api/v2/catalog/other/index.signed', '/api/v2/catalog/PUBLIC/index.signed', '/api/v2/catalog/packs/index.signed', '/api/v2/catalog/%2e%2e/index.signed'):
        status, hdrs, out = raw('GET', path, token)
        check('a tier that is not public or private: %s is a 404' % path.split('catalog/')[1], status == 404 and code_of(out) == 'not_found', (status, out[:100]))
    # packs
    packs = tn.sql('SELECT Tier, PackSHA256, PackSize FROM phoromatic_catalog_entries ORDER BY Tier, ID')
    check('the entries name three packs', len(packs) == 3, packs)
    for p in packs:
        status, hdrs, out = raw('GET', '/api/v2/catalog/packs/' + p['PackSHA256'], token)
        check('pack of a %s entry: 200, the bytes hash to the name' % p['Tier'], status == 200 and hashlib.sha256(out).hexdigest() == p['PackSHA256'] and len(out) == p['PackSize'], (status, len(out)))
    check('  headers: Cache-Control private, max-age=86400, nosniff, octet-stream, Content-Length', hdrs.get('cache-control') == 'private, max-age=86400' and hdrs.get('x-content-type-options') == 'nosniff'
          and hdrs.get('content-type') == 'application/octet-stream' and hdrs.get('content-length') == str(len(out)), hdrs)
    for what, h in (('an unknown hash', 'c' * 64), ('too short', 'a' * 63), ('too long', 'a' * 65), ('upper case', packs[0]['PackSHA256'].upper()), ('a dotted path', '..%2f' + 'a' * 60), ('a trailing newline', packs[0]['PackSHA256'] + '%0a')):
        status, hdrs, out = raw('GET', '/api/v2/catalog/packs/' + h, token)
        check('pack route, %s: 404 not_found' % what, status == 404 and code_of(out) == 'not_found', (status, out[:100]))
    # methods
    for method in ('POST', 'PUT', 'DELETE', 'HEAD'):
        for path in ('/api/v2/catalog/public/index.signed', '/api/v2/catalog/packs/' + packs[0]['PackSHA256']):
            status, hdrs, out = raw(method, path, token, body={} if method in ('POST', 'PUT') else None)
            check('%s %s is a 400 with Allow: GET' % (method, path.split('/api/v2/')[1][:28]), status == 400 and hdrs.get('allow') == 'GET' and (method == 'HEAD' or code_of(out) == 'bad_request'), (status, hdrs.get('allow'), out[:80]))
    status, hdrs, out = raw('GET', '/api/v2/agent/hello', token)
    check('the agent routes still take POST only (GET hello is a 400, Allow: POST)', status == 400 and hdrs.get('allow') == 'POST', (status, hdrs))
    # a fetch is not a check-in
    before = sysrow(sid)['LastCommunication']
    time.sleep(1.2)
    raw('GET', '/api/v2/catalog/public/index.signed', token)
    check('fetching the catalog does not update LastCommunication', sysrow(sid)['LastCommunication'] == before, (before, sysrow(sid)['LastCommunication']))
    # a second tenant gets the same server-wide catalog
    tokb, sidb, midb = enroll(B, 'srv-b', 'b')
    status, body, _ = jcall('/api/v2/agent/hello', hello_body(midb), tokb)
    check('a system of another account is served the same catalog', status == 200 and len(body.get('catalog', {}).get('tiers', [])) == 2, body)
    check('  and gets the same bytes', raw('GET', '/api/v2/catalog/public/index.signed', tokb)[2] == pub)

    # ---- the auth matrix (N23)
    tn.sql_exec('DELETE FROM phoromatic_v2_failures')
    for what, tok in (('no token', None), ('an unknown bsa_ token', 'bsa_' + 'A' * 43), ('a bench token (bbt_)', 'bbt_' + 'A' * 43), ('a private-repo token (bpr_)', 'bpr_' + 'A' * 43)):
        for path in ('/api/v2/catalog/public/index.signed', '/api/v2/catalog/packs/' + packs[0]['PackSHA256']):
            status, hdrs, out = raw('GET', path, tok)
            check('%s: 401 unauthenticated on %s' % (what, path.split('/api/v2/')[1][:20]), status == 401 and code_of(out) == 'unauthenticated', (status, out[:100]))
    br = tn.Browser().login('serveadmin')
    status, _, body = br.get('/api/v2/catalog/public/index.signed')
    check('a signed-in browser session without a bearer token: 401', status == 401, (status, body[:100]))
    tn.sql_exec('DELETE FROM phoromatic_v2_failures')
    # unapproved system
    tn.sql_exec("UPDATE phoromatic_systems SET State = 0 WHERE AccountID = '%s' AND SystemID = '%s'" % (A, sid))
    status, body, _ = jcall('/api/v2/agent/hello', hello_body(mid), token)
    check('an unapproved system: hello is answered, with no catalog', status == 200 and 'catalog' not in body and body['system']['approved'] is False, body)
    for path in ('/api/v2/catalog/public/index.signed', '/api/v2/catalog/packs/' + packs[0]['PackSHA256']):
        status, hdrs, out = raw('GET', path, token)
        check('an unapproved system: 403 system_not_approved on %s' % path.split('/api/v2/')[1][:20], status == 403 and code_of(out) == 'system_not_approved', (status, out[:100]))
    tn.sql_exec("UPDATE phoromatic_systems SET State = 1 WHERE AccountID = '%s' AND SystemID = '%s'" % (A, sid))
    check('approved again: served again', raw('GET', '/api/v2/catalog/public/index.signed', token)[0] == 200)
    # suspended tenant (B), then deleted
    out = tn.cli('server-tenant-suspend', B)
    check('account B is suspended', 'uspended' in out, out)
    status, body, _ = jcall('/api/v2/agent/hello', hello_body(midb), tokb)
    check('suspended tenant: hello is a 403 tenant_suspended, no catalog', status == 403 and body['error']['code'] == 'tenant_suspended' and 'catalog' not in body, body)
    for path in ('/api/v2/catalog/public/index.signed', '/api/v2/catalog/private/index.signed', '/api/v2/catalog/packs/' + packs[0]['PackSHA256']):
        status, hdrs, out = raw('GET', path, tokb)
        check('suspended tenant: 403 tenant_suspended on %s' % path.split('/api/v2/')[1][:20], status == 403 and code_of(out) == 'tenant_suspended', (status, out[:100]))
    check('the other account is not affected', raw('GET', '/api/v2/catalog/public/index.signed', token)[0] == 200)
    # revoked token
    revoked_token, revoked_sid, _ = enroll(A, 'srv-rev', 'c')
    admin('revoke-token', A, revoked_sid)
    status, hdrs, out = raw('GET', '/api/v2/catalog/public/index.signed', revoked_token)
    check('a revoked token: 401 token_revoked', status == 401 and code_of(out) == 'token_revoked', (status, out[:100]))

    # ---- a tier whose stored file is gone is neither advertised nor served; the other tier is
    f = storage_file('signed/%s.signed' % tiers['public']['SignedSHA256'])
    tn.srv_sh('mv %s %s.away' % (f, f))
    status, body, _ = jcall('/api/v2/agent/hello', hello_body(mid), token)
    check('a tier whose file is missing: not in hello, the other tier is', [t['tier'] for t in body.get('catalog', {}).get('tiers', [])] == ['private'], body)
    check('  its route is a 404 and the other tier is 200', raw('GET', '/api/v2/catalog/public/index.signed', token)[0] == 404 and raw('GET', '/api/v2/catalog/private/index.signed', token)[0] == 200)
    tn.srv_sh('mv %s.away %s' % (f, f))
    tn.srv_sh("printf 'x' >> %s" % f)
    check('a file that no longer matches its row is not served either', raw('GET', '/api/v2/catalog/public/index.signed', token)[0] == 404)
    tn.srv_sh("head -c -1 %s > %s.fix && mv %s.fix %s" % (f, f, f, f))
    check('and is served again when the bytes are right', raw('GET', '/api/v2/catalog/public/index.signed', token)[0] == 200)
    p = [x for x in packs if x['Tier'] == 'public'][0]
    pf = storage_file('packs/%s.zip' % p['PackSHA256'])
    tn.srv_sh("printf 'x' >> %s" % pf)
    check('a pack of the wrong size is a 404', raw('GET', '/api/v2/catalog/packs/' + p['PackSHA256'], token)[0] == 404)
    tn.srv_sh("head -c -1 %s > %s.fix && mv %s.fix %s" % (pf, pf, pf, pf))
    check('and is served when the size is right again', raw('GET', '/api/v2/catalog/packs/' + p['PackSHA256'], token)[0] == 200)
    # a pack the index of a served tier doesn't name is not served, even though the file is on disk
    stray = 'd' * 64
    tn.srv_sh("cp %s %s" % (pf, storage_file('packs/%s.zip' % stray)))
    check('a pack file that no entry names is a 404', raw('GET', '/api/v2/catalog/packs/' + stray, token)[0] == 404)
    tn.srv_sh('rm -f %s' % storage_file('packs/%s.zip' % stray))


def rehello():
    token, sid, mid = no_catalog_state['a']
    tokn, sidn, midn = enroll(A, 'srv-old', 'd')
    jcall('/api/v2/agent/hello', hello_body(mid), token)
    jcall('/api/v2/agent/hello', hello_body(midn, caps=('cancel', 'agent')), tokn)
    status, body, _ = jcall('/api/v2/agent/poll', poll_body(mid), token)
    check('after hello with the catalog current: the poll does not ask for a hello', status == 200 and body['rehello'] is False and body['hold'] is None, body)
    time.sleep(1.2)
    out = tn.cli('server-catalog-import', '/bundles/c.zip')
    check('the next release is imported', 'sequence 2' in out, out)
    status, body, _ = jcall('/api/v2/agent/poll', poll_body(mid), token)
    check('the next poll says hello again, with the refresh hold and no job', status == 200 and body['rehello'] is True and body['job'] is None and body['hold'] == 'Refreshing the test catalog before the next job.', body)
    check('  and the reply is valid for poll_response', validate('poll_response', body) == [])
    status, body, _ = jcall('/api/v2/agent/poll', poll_body(midn), tokn)
    check('an agent without catalog_v1 is not asked', status == 200 and body['rehello'] is False and body['hold'] != 'Refreshing the test catalog before the next job.', body)
    time.sleep(1.2)
    status, body, _ = jcall('/api/v2/agent/hello', hello_body(mid), token)
    check('hello now announces public sequence 2', {t['tier']: t['sequence'] for t in body['catalog']['tiers']} == {'public': 2, 'private': 1}, body)
    status, body, _ = jcall('/api/v2/agent/poll', poll_body(mid), token)
    check('and the poll after it is quiet again', body['rehello'] is False and body['hold'] is None, body)


no_catalog_state = {}

if __name__ == '__main__':
    phase = sys.argv[1]
    st = {}
    if phase == 'no-catalog':
        t = no_catalog()
        tn.save_state({'a': list(t)})
    else:
        st = tn.state()
        no_catalog_state['a'] = tuple(st['a'])
        {'serve': serve, 'rehello': rehello}[phase]()
    print('FAILURES %d' % fails)
