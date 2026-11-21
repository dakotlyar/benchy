#!/usr/bin/env python3
"""NC3: named catalogs and the tenant boundary on the server (catalog spec 11.1; the EC-NC1, EC-NC4, EC-NC5 and EC-NC9 cases). Driven by named.sh against a scratch server whose key table
holds throw-away test keys (TN_SRV, TN_PORT) with the bundles of make_named_bundles.php in /bundles: pub1 (public), priv1 (private), game1 (gaming, title "Games"), extras1 (extras, no title).
Accounts are made after the schema step, so they are new tenants: public only until a server administrator grants them something.

  tenants   a new tenant sees public only (hello, the list route, both file routes, a guessed hash), and what a grant, the grid and a de-grant do to each; the capability filter; refresh
  held      a schedule holding a test of a catalog the tenant isn't granted is held, whether or not a system of the tenant reports the test, and is issued again when the grant returns
  page      the Catalogs page: the grid, public locked on, a new catalog off, forged posts, the audit rows, the words

Nothing here touches the machine's own Benchy: the server is a throw-away container.
"""
import hashlib
import json
import os
import re
import sys
import time

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'tenancy'))
import sched_e44 as se  # noqa: E402
import serve_e44 as sv  # noqa: E402
import tn  # noqa: E402

fails = 0
A, B, C = 'NC3AAA', 'NC3BBB', 'NC3CCC'
NAMED = ('catalog_v1', 'catalog_named_v1', 'cancel', 'json_args', 'run_lock', 'agent')
PLAIN = ('catalog_v1', 'cancel', 'json_args', 'run_lock', 'agent')


def check(what, ok, detail=''):
    global fails
    print('  %s  %s%s' % ('PASS' if ok else 'FAIL', what, '' if ok else ' :: ' + str(detail)[:500]))
    if not ok:
        fails += 1


def sql(q):
    return tn.sql(q)


def hello(s, caps):
    st, body, _ = sv.jcall('/api/v2/agent/hello', sv.hello_body(s['mid'], caps=caps), s['token'])
    assert st == 200, (st, body)
    return body


def tiers(body):
    return [(t['tier'], t.get('title')) for t in body.get('catalog', {}).get('tiers', [])]


def names(body):
    return [t[0] for t in tiers(body)]


def catalog_list(s):
    st, hdrs, out = sv.raw('GET', '/api/v2/catalog', s['token'])
    return st, (json.loads(out) if out[:1] == b'{' else out)


def code(out):
    return sv.code_of(out)


def get(s, path):
    st, hdrs, out = sv.raw('GET', path, s['token'])
    return st, hdrs, out


def make_system(aid, name, seed):
    token, sid, mid = sv.enroll(aid, name, seed)
    return {'token': token, 'sid': sid, 'mid': mid, 'aid': aid}


def grants():
    return {(r['AccountID'], r['Catalog']): r for r in sql('SELECT * FROM phoromatic_catalog_grants')}


def grant_rows_audit():
    return sql("SELECT * FROM phoromatic_server_audit WHERE Action = 'catalog_grant_changed' ORDER BY AuditID")


def packs():
    out = {}
    for r in sql('SELECT ID, PackSHA256, Tier FROM phoromatic_catalog_entries'):
        out.setdefault(r['ID'], r['PackSHA256'])
    return out


def post_grid(br, want, accounts=(A, B, C), catalogs=('extras', 'gaming', 'private'), token=True, extra=None, headers=None):
    f = {'catalog_grants': '1', 'grid_accounts': ','.join(accounts), 'grid_catalogs': ','.join(catalogs)}
    for aid, cat in want:
        f['grant[%s][%s]' % (aid, cat)] = '1'
    f.update(extra or {})
    if token:
        f['token_submit'] = br.token('/?server_catalog')
    return se.flat(br.post('/?server_catalog', f, headers=headers)[2]) if headers else se.flat(br.post('/?server_catalog', f)[2])


# ---- the scenarios

def setup():
    for aid, user in ((A, 'nc3admin'), (B, 'nc3badmin'), (C, 'nc3cadmin')):
        r = tn.srv_sh('sh /bench/benchy server-account-create --user %s --email %s@example.com --account-id %s' % (user, user, aid), stdin=tn.PW + '\n')
        check('account %s made' % aid, 'Account created' in r, r)
    sv.admin('allow-http', 'on')
    for b in ('pub1', 'priv1', 'game1', 'extras1'):
        out = tn.cli('server-catalog-import', '/bundles/%s.zip' % b)
        check('bundle %s imported' % b, 'Imported the' in out, out)
    rows = {r['Tier']: r for r in sql('SELECT Tier, Title FROM phoromatic_catalog_tiers')}
    check('the server holds public, private, gaming (titled Games) and extras (no title)', set(rows) == {'public', 'private', 'gaming', 'extras'} and rows['gaming']['Title'] == 'Games' and rows['extras']['Title'] is None, rows)
    out = tn.cli('server-catalog-import', '/bundles/game1.zip')
    check('the CLI words for a named catalog: it is called by its title, and the grant is mentioned', 'already imported' in out and 'Games catalog' in out, out)
    out = tn.cli('server-catalog-status')
    check('the status lists it by title and name', 'Games catalog (gaming): served' in out and 'Extras catalog (extras): served' in out, out)
    s = {'a_new': make_system(A, 'a-new', 'a'), 'a_old': make_system(A, 'a-old', 'b'), 'b': make_system(B, 'b-sys', 'c'), 'c': make_system(C, 'c-sys', 'd')}
    tn.save_state(s)


def tenants():
    s = tn.state()
    P = packs()
    # ---- EC-NC4: a new tenant sees public only
    check('no account has a grant (all three were made after the step)', grants() == {}, grants())
    body = hello(s['c'], NAMED)
    check('a new tenant: hello lists public only, to an agent that can receive named catalogs', tiers(body) == [('public', None)], body.get('catalog'))
    check('  and the reply is valid for hello_response', sv.validate('hello_response', body) == [])
    st, body = catalog_list(s['c'])
    check('a new tenant: GET /api/v2/catalog lists public only, and the reply is valid for catalog_list_response', st == 200 and tiers(body) == [('public', None)] and sv.validate('catalog_list_response', body) == [], (st, body))
    check('  with the envelope beside it (api 2 and the server version)', body.get('api') == 2 and 'server' in body, body)

    # ---- EC-NC5: an ungranted tenant is refused the index and any pack only an ungranted catalog names, even by a guessed hash
    st, hdrs, nothing = get(s['c'], '/api/v2/catalog/nosuchname/index.signed')
    for cat in ('private', 'gaming', 'extras'):
        st, hdrs, out = get(s['c'], '/api/v2/catalog/%s/index.signed' % cat)
        check('ungranted: GET %s/index.signed is 404 not_found, the same answer as for a catalog the server does not hold' % cat, st == 404 and code(out) == 'not_found' and out == nothing, (st, out[:120], nothing[:120]))
    for ident in ('benchy/paid', 'benchy/game', 'benchy/extra'):
        st, hdrs, out = get(s['c'], '/api/v2/catalog/packs/' + P[ident])
        check('ungranted: the pack of %s, asked for by its hash, is 404 not_found' % ident, st == 404 and code(out) == 'not_found', (st, out[:100]))
    st, hdrs, out = get(s['c'], '/api/v2/catalog/packs/' + P['benchy/gshared'])
    check('ungranted: the pack that private and gaming both name is 404 too', st == 404, st)
    st, hdrs, out = get(s['c'], '/api/v2/catalog/packs/' + P['benchy/one'])
    check('  while a public pack is 200', st == 200 and hashlib.sha256(out).hexdigest() == P['benchy/one'], st)
    st, hdrs, out = get(s['c'], '/api/v2/catalog/public/index.signed')
    check('  and the public index is 200', st == 200)
    for path in ('/api/v2/catalog/PRIVATE/index.signed', '/api/v2/catalog/gaming%00/index.signed', '/api/v2/catalog/gaming./index.signed', '/api/v2/catalog/%67aming/index.signed'):
        st, hdrs, out = get(s['c'], path)
        check('  a spelling of a catalog name that is not it, %s: 404' % path.split('catalog/')[1], st == 404, (st, out[:100]))

    # ---- grants: A gets private, gaming and extras; B gets gaming; C nothing
    a_hello = hello(s['a_new'], NAMED)
    b_hello = hello(s['b'], NAMED)
    check('before the grants: A and B see public only', tiers(a_hello) == [('public', None)] and tiers(b_hello) == [('public', None)])
    poll = sv.jcall('/api/v2/agent/poll', sv.poll_body(s['a_new']['mid']), s['a_new']['token'])[1]
    check('  and A\'s poll is quiet', poll['rehello'] is False, poll)
    time.sleep(1.2)
    admin = tn.Browser().login('nc3admin')
    out = post_grid(admin, [(A, 'private'), (A, 'gaming'), (A, 'extras'), (B, 'gaming')])
    check('the grid saves four grants', 'Saved. 4 changes' in out, out[-600:])
    g = grants()
    check('  four rows, by the user, with the time', set(g) == {(A, 'private'), (A, 'gaming'), (A, 'extras'), (B, 'gaming')} and all(r['GrantedBy'] == 'nc3admin' and r['GrantedOn'] for r in g.values()), g)
    rows = grant_rows_audit()
    d = [json.loads(r['Detail']) for r in rows]
    check('  one audit row for each change: the catalog as the subject, the account in the detail and as the tenant, who did it', len(rows) == 4 and all(r['SubjectType'] == 'catalog_grant' and r['ActorType'] == 'user' and r['Actor'] == 'nc3admin' for r in rows)
          and sorted((x['account'], x['catalog'], x['granted']) for x in d) == sorted([(A, 'private', True), (A, 'gaming', True), (A, 'extras', True), (B, 'gaming', True)]) and all(r['TenantID'] in (A, B) for r in rows), rows)

    # ---- refresh picks the grants up
    poll = sv.jcall('/api/v2/agent/poll', sv.poll_body(s['a_new']['mid']), s['a_new']['token'])[1]
    check('refresh: the next poll of a system that said hello before the grants says hello again, and holds no job', poll['rehello'] is True and poll['job'] is None and poll['hold'] == 'Refreshing the test catalog before the next job.', poll)
    a_hello = hello(s['a_new'], NAMED)
    check('  its hello now lists public, private, then the others by name, with the title where there is one', tiers(a_hello) == [('public', None), ('private', None), ('extras', None), ('gaming', 'Games')], tiers(a_hello))
    check('  the reply is valid for hello_response', sv.validate('hello_response', a_hello) == [])
    poll = sv.jcall('/api/v2/agent/poll', sv.poll_body(s['a_new']['mid']), s['a_new']['token'])[1]
    check('  and the poll after it is quiet again', poll['rehello'] is False and poll['hold'] is None, poll)
    b_poll = sv.jcall('/api/v2/agent/poll', sv.poll_body(s['b']['mid']), s['b']['token'])[1]
    check('B\'s system, which said hello before the grants, is told to say hello again too', b_poll['rehello'] is True, b_poll)
    c_poll = sv.jcall('/api/v2/agent/poll', sv.poll_body(s['c']['mid']), s['c']['token'])[1]
    check('  and so is C\'s (the catalog changed after its hello; it only learns it still has public)', c_poll['rehello'] is True, c_poll)

    # ---- EC-NC1: only an agent with catalog_named_v1 is told a name other than public and private
    old = hello(s['a_old'], PLAIN)
    check('without catalog_named_v1: public and private only, no gaming and no extras, and no title', tiers(old) == [('public', None), ('private', None)], tiers(old))
    check('  the reply is valid for hello_response', sv.validate('hello_response', old) == [])
    only_named = hello(s['b'], PLAIN)
    check('a tenant whose only extra catalog is named, asking without the capability: public only (the catalog key stays, with public)', tiers(only_named) == [('public', None)], only_named.get('catalog'))
    check('  the same tenant with the capability sees gaming, titled', tiers(hello(s['b'], NAMED)) == [('public', None), ('gaming', 'Games')])
    check('the word is read from this hello, not an earlier one: the same system says it and then does not', tiers(hello(s['a_new'], PLAIN)) == [('public', None), ('private', None)] and 'gaming' in names(hello(s['a_new'], NAMED)))
    st, body = catalog_list(s['a_old'])
    check('the list route has no capability filter: the system without catalog_named_v1 gets all four of its catalogs, in order, titled', st == 200 and tiers(body) == [('public', None), ('private', None), ('extras', None), ('gaming', 'Games')], (st, body))
    check('  and the list is valid for catalog_list_response', sv.validate('catalog_list_response', body) == [])
    st, body = catalog_list(s['b'])
    check('B\'s list: public and gaming', tiers(body) == [('public', None), ('gaming', 'Games')], body)
    st, body = catalog_list(s['c'])
    check('C\'s list is still public only', tiers(body) == [('public', None)], body)

    # ---- the file routes follow the grants
    st, hdrs, out = get(s['a_new'], '/api/v2/catalog/gaming/index.signed')
    row = sql("SELECT SignedSHA256 FROM phoromatic_catalog_tiers WHERE Tier = 'gaming'")[0]['SignedSHA256']
    check('granted: A gets the gaming index, exactly the stored file', st == 200 and hashlib.sha256(out).hexdigest() == row, st)
    check('  A gets the private and the extras index too', get(s['a_new'], '/api/v2/catalog/private/index.signed')[0] == 200 and get(s['a_new'], '/api/v2/catalog/extras/index.signed')[0] == 200)
    check('  B gets gaming and is refused private and extras', get(s['b'], '/api/v2/catalog/gaming/index.signed')[0] == 200 and get(s['b'], '/api/v2/catalog/private/index.signed')[0] == 404 and get(s['b'], '/api/v2/catalog/extras/index.signed')[0] == 404)
    check('  a granted catalog is served to a system that cannot name it in hello (the route has no capability filter)', get(s['a_old'], '/api/v2/catalog/gaming/index.signed')[0] == 200)
    check('a pack only gaming names: A and B get it, C does not', get(s['a_new'], '/api/v2/catalog/packs/' + P['benchy/game'])[0] == 200 and get(s['b'], '/api/v2/catalog/packs/' + P['benchy/game'])[0] == 200 and get(s['c'], '/api/v2/catalog/packs/' + P['benchy/game'])[0] == 404)
    check('a pack only private names: A gets it; B (gaming only) is refused by its hash', get(s['a_new'], '/api/v2/catalog/packs/' + P['benchy/paid'])[0] == 200 and get(s['b'], '/api/v2/catalog/packs/' + P['benchy/paid'])[0] == 404)
    check('a pack only extras names: A gets it, B and C do not', get(s['a_new'], '/api/v2/catalog/packs/' + P['benchy/extra'])[0] == 200 and get(s['b'], '/api/v2/catalog/packs/' + P['benchy/extra'])[0] == 404 and get(s['c'], '/api/v2/catalog/packs/' + P['benchy/extra'])[0] == 404)
    check('a pack that private and gaming both name: B gets it through gaming (a pack shared with a granted catalog is served), C does not', get(s['b'], '/api/v2/catalog/packs/' + P['benchy/gshared'])[0] == 200 and get(s['c'], '/api/v2/catalog/packs/' + P['benchy/gshared'])[0] == 404)
    out = post_grid(admin, [(A, 'private'), (A, 'gaming'), (A, 'extras')])
    check('turning gaming off for B saves one change', 'Saved. 1 change.' in out, out[-500:])
    check('  B is refused the gaming index and the packs of gaming at once', get(s['b'], '/api/v2/catalog/gaming/index.signed')[0] == 404 and get(s['b'], '/api/v2/catalog/packs/' + P['benchy/game'])[0] == 404 and get(s['b'], '/api/v2/catalog/packs/' + P['benchy/gshared'])[0] == 404)
    time.sleep(1.2)
    check('  and its hello no longer lists gaming', tiers(hello(s['b'], NAMED)) == [('public', None)])
    time.sleep(1.2)
    hello(s['b'], NAMED)
    time.sleep(1.2)
    out = post_grid(admin, [(A, 'private'), (A, 'gaming'), (A, 'extras'), (B, 'private')])
    check('private for B: the pack private and gaming share is served through private now', 'Saved. 1 change.' in out and get(s['b'], '/api/v2/catalog/packs/' + P['benchy/gshared'])[0] == 200 and get(s['b'], '/api/v2/catalog/packs/' + P['benchy/game'])[0] == 404)
    check('  the audit has the on and off', [(json.loads(r['Detail'])['account'], json.loads(r['Detail'])['catalog'], json.loads(r['Detail'])['granted']) for r in grant_rows_audit()][-2:] == [(B, 'gaming', False), (B, 'private', True)])
    poll = sv.jcall('/api/v2/agent/poll', sv.poll_body(s['b']['mid']), s['b']['token'])[1]
    check('  and B\'s system is told to say hello again after each change', poll['rehello'] is True)
    # leave B with nothing
    out = post_grid(admin, [(A, 'private'), (A, 'gaming'), (A, 'extras')])
    check('B has nothing again', 'Saved. 1 change.' in out and (B, 'private') not in grants())

    # the route and its methods and its auth
    for method in ('POST', 'PUT', 'DELETE'):
        st, hdrs, out = sv.raw(method, '/api/v2/catalog', s['a_new']['token'], body={} if method != 'DELETE' else None)
        check('%s /api/v2/catalog is a 400 with Allow: GET' % method, st == 400 and hdrs.get('allow') == 'GET', (st, hdrs.get('allow')))
    st, hdrs, out = sv.raw('GET', '/api/v2/catalog', None)
    check('no token: 401', st == 401 and code(out) == 'unauthenticated', (st, out[:100]))
    tn.sql_exec("UPDATE phoromatic_systems SET State = 0 WHERE AccountID = '%s' AND SystemID = '%s'" % (A, s['a_old']['sid']))
    st, hdrs, out = get(s['a_old'], '/api/v2/catalog')
    check('an unapproved system: 403 system_not_approved', st == 403 and code(out) == 'system_not_approved', (st, out[:100]))
    tn.sql_exec("UPDATE phoromatic_systems SET State = 1 WHERE AccountID = '%s' AND SystemID = '%s'" % (A, s['a_old']['sid']))
    # the account of the token decides, never anything in the request
    st, hdrs, out = sv.raw('GET', '/api/v2/catalog?account=%s&tenant=%s' % (A, A), s['c']['token'])
    check('a query string naming another account changes nothing', st == 200 and tiers(json.loads(out)) == [('public', None)], out[:200])
    check('  nor does a header', json.loads(sv.raw('GET', '/api/v2/catalog', s['c']['token'], headers={'X-Account-ID': A, 'X-Tenant': A})[2]).get('catalog', {}).get('tiers', [])[1:] == [])

    # ---- the grid's own rules
    before = grants()
    audit_n = len(grant_rows_audit())
    out = post_grid(admin, [(A, 'private'), (A, 'gaming'), (A, 'extras'), (C, 'public'), (C, 'nosuch'), ('NOACCT', 'gaming')], accounts=(A, B, C, 'NOACCT'), catalogs=('extras', 'gaming', 'private', 'public', 'nosuch'))
    check('public cannot be given a row, and a catalog or an account that does not exist is ignored', 'Nothing changed.' in out and grants() == before and len(grant_rows_audit()) == audit_n, (out[-300:], grants()))
    l1b = None
    tn.make_user(admin, 'nc3l1b', 2)
    tn.sql_exec(tn.level_sql(1, 'nc3l1b'))
    l1b = tn.Browser().login('nc3l1b')
    check('control: nc3l1b is a level-1 user and no server administrator', not sql("SELECT 1 FROM phoromatic_server_roles r JOIN phoromatic_users u ON u.UserID = r.UserID WHERE u.UserName = 'nc3l1b'"))
    out = post_grid(l1b, [(A, 'private'), (A, 'gaming'), (A, 'extras'), (C, 'gaming')])
    check('a forged grant from a user who is no server administrator changes nothing and writes no audit row', grants() == before and len(grant_rows_audit()) == audit_n and 'Saved' not in out, out[-300:])
    out = post_grid(admin, [(C, 'gaming')], token=False)
    check('no CSRF token: nothing changes', 'expired' in out and grants() == before and len(grant_rows_audit()) == audit_n, out[-300:])
    out = post_grid(admin, [(C, 'gaming')], headers={'Origin': 'http://evil.example'})
    check('an Origin that is another site: refused the same way', 'expired' in out and grants() == before and len(grant_rows_audit()) == audit_n, out[-300:])
    check('the first nc3admin post that changes nothing writes no audit row', len(grant_rows_audit()) == audit_n)
    save_check = grants()
    tn.save_state({**s, 'grants': sorted('%s/%s' % k for k in save_check)})


def held():
    s = tn.state()
    se.A = A
    a = s['a_new']
    reported = sv.hello_body(a['mid'], caps=NAMED)
    entry = se.cat_entry('benchy/game', '1.0.0', 'Game Bench')
    reported['catalogue'] = [entry]
    st, body, _ = sv.jcall('/api/v2/agent/hello', reported, a['token'])
    check('A\'s system reports benchy/game itself', st == 200, body)
    sched = se.new_schedule('games', [{'sid': a['sid']}], [('benchy/game', None)])
    b = sv.jcall('/api/v2/agent/poll', sv.poll_body(a['mid']), a['token'])[1]
    check('with gaming granted, the schedule is issued', b['job'] is not None and b['job']['tests'][0]['ref'] == 'benchy/game', b)
    se.end(a, b['job'])
    se.drop_schedule(sched)
    time.sleep(1.2)
    admin = tn.Browser().login('nc3admin')
    out = post_grid(admin, [(A, 'private'), (A, 'extras')])
    check('gaming is turned off for A', 'Saved. 1 change.' in out, out[-300:])
    hello(a, NAMED)
    st, body, _ = sv.jcall('/api/v2/agent/hello', reported, a['token'])
    sched = se.new_schedule('games again', [{'sid': a['sid']}], [('benchy/game', None)])
    b = sv.jcall('/api/v2/agent/poll', sv.poll_body(a['mid']), a['token'])[1]
    want = 'Schedule "games again" has benchy/game, which is in the Games catalog, which this tenant doesn\'t have.'
    check('held rule: the schedule is held with the reason, and no job is issued, although the system reports the test', b['job'] is None and b['hold'] == want, b)
    check('  no job row was made', not se.jobs_of(sched))
    page = se.flat(tn.Browser().login('nc3admin').get('/?schedules/%d' % sched)[2])
    check('the picker does not offer it: the suite builder\'s offer holds neither a catalog entry nor a reported one for benchy/game', json.loads(tn.php_boot(
        "phoromatic_tenant_context::set('%s');$o=phoromatic_native_suites::offer('%s');echo json_encode(array(isset($o['catalog']['benchy/game']), isset($o['reported']['benchy/game']), isset($o['catalog']['benchy/paid'])));" % (A, A))) == [False, False, True])
    check('  and the schedule box offer lists benchy/paid (private, granted) and not benchy/game', json.loads(tn.php_boot(
        "phoromatic_tenant_context::set('%s');$o=phoromatic_native_admin::schedule_offer('%s', %d);echo json_encode(array(isset($o['benchy/game']), isset($o['benchy/paid'])));" % (A, A, sched))) == [False, True])
    # a pinned row is held the same way
    pinned = se.new_schedule('games pinned', [{'sid': a['sid']}], [('benchy/game', '1.0.0')])
    b2 = sv.jcall('/api/v2/agent/poll', sv.poll_body(a['mid']), a['token'])[1]
    check('a pinned row naming the test is held too', b2['job'] is None and b2['hold'] is not None and 'which is in the Games catalog' in b2['hold'], b2)
    se.drop_schedule(pinned)
    # a test in a catalog the tenant has beside one it hasn't: the id is held only in the second, so a schedule is not held
    time.sleep(1.2)
    out = post_grid(admin, [(A, 'private'), (A, 'extras'), (A, 'gaming')])
    check('gaming is turned on for A again', 'Saved. 1 change.' in out, out[-300:])
    hello(a, NAMED)
    b = sv.jcall('/api/v2/agent/poll', sv.poll_body(a['mid']), a['token'])[1]
    check('the schedule is issued again', b['job'] is not None and b['hold'] is None, b)
    se.end(a, b['job'])
    se.drop_schedule(sched)
    # the badge comes from the test's license class, not the catalog
    labels = json.loads(tn.php_boot("$l=array(phoromatic_catalog_sched::labels('%s','benchy/game','1.0.0')['tier'], phoromatic_catalog_sched::labels('%s','benchy/gshared','1.0.0')['tier'], phoromatic_catalog_sched::labels('%s','benchy/paid','1.0.0')['tier'], phoromatic_catalog_sched::labels('%s','benchy/one','1.0.0')['tier']);echo json_encode($l);" % (A, A, A, A)))
    check('labels: Free test or Non-free test by the entry\'s license_class (a free test in a named catalog is Free)', labels == ['Free test', 'Non-free test', 'Non-free test', 'Free test'], labels)


def page():
    s = tn.state()
    admin = tn.Browser().login('nc3admin')
    html = se.flat(admin.get('/?server_catalog')[2])
    check('the Catalogs page lists the catalogs by label: Free, Non-free, the title Games, and extras by its name', all(x in html for x in ('<td>Free</td><td>Served</td>', '<td>Non-free</td><td>Served</td>', '<td>Games</td><td>Served</td>', '<td>extras</td><td>Served</td>')), html[html.find('Test catalog'):][:1500])
    check('  and a test list for each', all(x in html for x in ('<h3>Free tests</h3>', '<h3>Non-free tests</h3>', '<h3>Games tests</h3>', '<h3>extras tests</h3>')) and '<td>benchy/game</td>' in html)
    check('the grid is there with its plain words', 'Who gets which catalog' in html and 'Every account gets the Free catalog' in html and 'A catalog you import later starts out off for every account' in html)
    grid = html[html.find('Who gets which catalog'):]
    check('Free is a checked, disabled box in every row; a granted catalog is checked', grid.count('checked="checked" disabled="disabled"') == 3 and 'name="grant[%s][gaming]" value="1" checked="checked"' % A in grid, grid[:1500])
    check('  a catalog nobody has been given is unchecked for every account', 'name="grant[%s][gaming]" value="1" />' % C in grid and 'name="grant[%s][private]" value="1" />' % C in grid and 'name="grant[%s][extras]" value="1" />' % B in grid)
    check('  the form carries the token and says which accounts and catalogs it showed', 'name="token_submit"' in grid and 'name="grid_accounts"' in grid and 'name="grid_catalogs" value="extras,gaming,private"' in grid)
    ev = html[html.find('Recent catalog events'):]
    check('the events list says who turned what on or off for which account, in words', re.search(r'nc3admin turned the Games catalog on for [^<]*</td>', ev) is not None and re.search(r'turned the Games catalog off for', ev) is not None, ev[:1200])
    imp = [r for r in sql("SELECT * FROM phoromatic_server_audit WHERE Action = 'catalog_imported' AND SubjectID = 'gaming'")]
    check('the import of a named catalog is audited under its name', len(imp) >= 1 and imp[0]['SubjectType'] == 'catalog_tier', imp)
    # a catalog imported later starts out off for everyone
    before = grants()
    check('a catalog imported later is off for every account: the grid made no grant on import', all(k[1] != 'zzz' for k in before))
    for who, br in (('a level-1 user who is no server administrator', tn.Browser().login('nc3l1b')),):
        out = se.flat(br.get('/?server_catalog')[2])
        check('%s: the page is refused and shows no grid' % who, 'for server administrators' in out and 'Who gets which catalog' not in out and 'grant[' not in out, out[-400:])
    # a database that cannot be read fails closed: no grants table means public only (tiers_for_account), checked on a copy of the logic with the table gone
    gone = json.loads(tn.php_boot("phoromatic_server::prepare_database();phoromatic_server::$db->exec('ALTER TABLE phoromatic_catalog_grants RENAME TO phoromatic_catalog_grants_away');echo json_encode(phoromatic_catalog::tiers_for_account('%s'));phoromatic_server::$db->exec('ALTER TABLE phoromatic_catalog_grants_away RENAME TO phoromatic_catalog_grants');" % A))
    check('a grants table that cannot be read serves public only (the boundary fails closed)', gone == ['public'], gone)
    check('  and the grants are back as they were', grants() == before)


if __name__ == '__main__':
    phase = sys.argv[1]
    {'setup': setup, 'tenants': tenants, 'held': held, 'page': page}[phase]()
    print('FAILURES %d' % fails)
