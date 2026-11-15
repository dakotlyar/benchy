#!/usr/bin/env python3
"""T5 rollback phases (driven by rollback_t5.sh; the server container is the one TN_SRV/TN_PORT name). State between phases is in TN_STATE.

new-first   this checkout, an empty server: root makes two accounts in the console, the first account's administrator makes users (one of them is added to the second account by
            root, one is moved to level 2 on the Users page, one is turned off on the server, one is turned off in the account)
old         the release before T5 on the same data (it also turns a user off, changes a level and makes a user: new-again finds all three): each user logs in to their own account at the level last set; the user who belongs to both sees only the first; the second account's
            administrator's Users page does not list them; the turned-off users are refused; a level-2 user can use the Users page
new-again   this checkout again: the user who belongs to both is still a member of both and switches; the memberships and levels are what they were; turning the user back on works
"""
import re
import sys

import tn

fails = 0


def check(what, ok, detail=''):
    global fails
    print('  %s  %s%s' % ('PASS' if ok else 'FAIL', what, '' if ok else ' :: ' + str(detail)[:300]))
    if not ok:
        fails += 1


def act(b, action, **fields):
    tok = b.token('/?server_tenants/new')
    s, h, body = b.post('/?r_server_action', dict(fields, action=action, token_submit=tok))
    return s, h.get('Location') or '', body


def make(b, name, user):
    s, loc, body = act(b, 'tenant_create', tenant_name=name, register_username=user, register_password=tn.PW, register_password_confirm=tn.PW, register_email=user + '@example.com', seed_accountid='')
    m = re.search(r'server_tenants/(\w{6})$', loc)
    check('the console makes account %s' % user, bool(m), (s, loc, body[:100]))
    return m.group(1) if m else ''


def uid(name):
    return tn.sql("SELECT UserID FROM phoromatic_users WHERE UserName='%s'" % name)[0]['UserID']


def levels():
    return {(r['UserName'], r['AccountID']): r['AdminLevel'] for r in tn.sql('SELECT u.UserName, m.AccountID, m.AdminLevel FROM phoromatic_memberships m JOIN phoromatic_users u ON u.UserID = m.UserID')}


def new_first():
    import subprocess
    subprocess.run(['docker', 'exec', '-i', tn.SRV, 'sh', '/bench/benchy', 'phoromatic.set-root-admin-password'], input=tn.ROOT_PW + '\n', capture_output=True, text=True)
    root = tn.Browser().login_root()
    a, b = make(root, 'Rbt Five A', 'rb5a'), make(root, 'Rbt Five B', 'rb5b')
    admin = tn.Browser().login('rb5a')
    for name, lvl in (('rb5dual', 3), ('rb5viewer', 10), ('rb5off', 3), ('rb5acctoff', 3), ('rb5olddis', 3), ('rb5oldlvl', 3)):
        tn.make_user(admin, name, lvl)
    s, loc, body = act(root, 'member_add', account_id=b, user_name='rb5dual', level='10')
    check('root adds the user to the second account', levels().get(('rb5dual', b)) == 10, (s, loc))
    admin.post('/?users', {'update_user_levels': uid('rb5viewer'), 'admin_level_' + uid('rb5viewer'): '2', 'token_submit': admin.token('/?users')})
    act(root, 'user_disable', user_id=uid('rb5off'))
    admin.post('/?users', {'update_user_levels': uid('rb5acctoff'), 'admin_level_' + uid('rb5acctoff'): '-1', 'token_submit': admin.token('/?users')})
    check('the viewer is at level 2 in the first account and the legacy column agrees', levels()[('rb5viewer', a)] == 2 and tn.sql("SELECT AdminLevel AS l FROM phoromatic_users WHERE UserName='rb5viewer'")[0]['l'] == 2)
    check('the user turned off on the server is negative in the legacy column', tn.sql("SELECT AdminLevel AS l FROM phoromatic_users WHERE UserName='rb5off'")[0]['l'] < 0)
    tn.save_state({'a': a, 'b': b, 'levels': {'%s|%s' % k: v for k, v in levels().items()}})


def old():
    st = tn.state()
    a, b = st['a'], st['b']
    d = tn.Browser().login('rb5dual')
    check('the user who belongs to both logs in to their own account', 'Rbt Five A' in d.get('/?main')[2])
    check('and there is no way across: no switcher, no second account\'s data', 'r_tenant_switch' not in d.get('/?main')[2] and 'Rbt Five B' not in d.get('/?main')[2])
    bb = tn.Browser().login('rb5b')
    check('the second account\'s administrator does not see them on the Users page', 'rb5dual' not in bb.get('/?users')[2])
    v = tn.Browser().login('rb5viewer')
    check('the level that was set on the Users page is the one the old release reads (2: the Users page opens)', "aren't an account administrator" not in v.get('/?users')[2])
    for name in ('rb5off', 'rb5acctoff'):
        x = tn.Browser()
        x.get('/')
        check('%s is refused' % name, 'Log-In Successful' not in x.post('/?login', {'username': name, 'password': tn.PW})[2])
    # what the old release writes now: it turns a user off (root's page flips the sign), changes a level on the Users page and makes a user
    root = tn.Browser().login_root()
    s_, h_, body = root.post('/?admin', {'disable_user': 'rb5olddis', 'token_submit': root.token('/?admin')})
    check('the old release turns a user off', tn.sql("SELECT AdminLevel AS l FROM phoromatic_users WHERE UserName='rb5olddis'")[0]['l'] < 0, body[:200])
    admin = tn.Browser().login('rb5a')
    admin.post('/?users', {'update_user_levels': uid('rb5oldlvl'), 'admin_level_' + uid('rb5oldlvl'): '10', 'token_submit': admin.token('/?users')})
    check('and changes a level', tn.sql("SELECT AdminLevel AS l FROM phoromatic_users WHERE UserName='rb5oldlvl'")[0]['l'] == 10)
    tn.make_user(admin, 'rb5oldnew', 3)
    check('and makes a user', bool(tn.sql("SELECT 1 FROM phoromatic_users WHERE UserName='rb5oldnew'")))
    check('the first account\'s machine page works for its admin', 'Settings' in tn.Browser().login('rb5a').get('/?settings')[2])


def new_again():
    st = tn.state()
    a, b = st['a'], st['b']
    now = {'%s|%s' % k: v for k, v in levels().items()}
    want = dict(st['levels'])
    want.update({'rb5olddis|%s' % a: -3, 'rb5oldlvl|%s' % a: 10, 'rb5oldnew|%s' % a: 3})
    check('every membership and level is what it was, with the three things the old release wrote carried forward', now == want, (now, want))
    x_ = tn.Browser()
    x_.get('/')
    check('the user the old release turned off is off', 'Log-In Successful' not in x_.post('/?login', {'username': 'rb5olddis', 'password': tn.PW})[2])
    check('the user it made signs in', bool(tn.Browser().login('rb5oldnew')))
    check('the level it changed is the one the server uses (a viewer cannot open the Users page)', "aren't an account administrator" in tn.Browser().login('rb5oldlvl').get('/?users')[2])
    d = tn.Browser().login('rb5dual')
    check('the user who belongs to both is in the first account with a switcher', 'r_tenant_switch' in d.get('/?main')[2])
    tok = d.token('/?settings')
    d.post('/?r_tenant_switch', {'account_id': b, 'token_submit': tok})
    check('and switches to the second', 'Rbt Five B' in d.get('/?main')[2])
    check('the second account\'s Users page lists them as a viewer', 'rb5dual' in tn.Browser().login('rb5b').get('/?users')[2])
    root = tn.Browser().login_root()
    x = tn.Browser()
    x.get('/')
    check('the user turned off is still off', 'Log-In Successful' not in x.post('/?login', {'username': 'rb5off', 'password': tn.PW})[2])
    act(root, 'user_enable', user_id=uid('rb5off'))
    check('and logs in once turned on', bool(tn.Browser().login('rb5off')))
    check('the user turned off in the account is still off there', 'Log-In Successful' not in (lambda y: (y.get('/'), y.post('/?login', {'username': 'rb5acctoff', 'password': tn.PW}))[1][2])(tn.Browser()))


{'new-first': new_first, 'old': old, 'new-again': new_again}[sys.argv[1]]()
print('FAILURES %d' % fails)
