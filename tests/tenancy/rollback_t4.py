#!/usr/bin/env python3
"""T4 rollback phases (driven by rollback_t4.sh; the server container is the one TN_SRV/TN_PORT name). State between phases is in TN_STATE.

new-first   this checkout, an empty server: root makes three accounts in the console (one name with markup in it), an approved machine in the first; the second is
            suspended, the third suspended and deleted
old         the release before T4 on the same data: the active account's user logs in and its machine checks in; the suspended account is refused; the deleted one is
            an unknown account; the name is shown escaped; root's own page lists the accounts; a new account can still be made on the old release's page
new-again   this checkout again: the console lists the three in their states, the second is resumed and the third restored and resumed, and both work; the data is the same
"""
import re
import subprocess
import sys

import tn

fails = 0
SUSPENDED = 'This Phoromatic account is suspended.'
XSS = '<b>Rb</b> One'


def check(what, ok, detail=''):
    global fails
    print('  %s  %s%s' % ('PASS' if ok else 'FAIL', what, '' if ok else ' :: ' + str(detail)[:300]))
    if not ok:
        fails += 1


def root_console():
    return tn.Browser().login_root()


def act(b, action, **fields):
    tok = b.token('/?server_tenants/new')
    s, h, body = b.post('/?r_server_action', dict(fields, action=action, token_submit=tok))
    return s, h.get('Location') or '', body


def make(b, name, user):
    s, loc, body = act(b, 'tenant_create', tenant_name=name, register_username=user, register_password=tn.PW, register_password_confirm=tn.PW, register_email=user + '@example.com', seed_accountid='')
    m = re.search(r'server_tenants/(\w{6})$', loc)
    check('the console makes account %s' % user, bool(m), (s, loc, body[:100]))
    return m.group(1) if m else ''


def new_first():
    subprocess.run(['docker', 'exec', '-i', tn.SRV, 'sh', '/bench/benchy', 'phoromatic.set-root-admin-password'], input=tn.ROOT_PW + '\n', capture_output=True, text=True)
    b = root_console()
    a, s, d = make(b, XSS, 'rbtone'), make(b, 'Rb Two', 'rbttwo'), make(b, 'Rb Three', 'rbtthree')
    msi = tn.approved_machine(a)
    act(b, 'tenant_suspend', account_id=s)
    act(b, 'tenant_suspend', account_id=d)
    act(b, 'tenant_delete', account_id=d, confirm_name='Rb Three')
    rows = {r['AccountID']: r['State'] for r in tn.sql('SELECT AccountID, State FROM phoromatic_accounts')}
    check('the states are active, suspended, deleted', rows == {a: 'active', s: 'suspended', d: 'deleted'}, rows)
    tn.save_state({'a': a, 's': s, 'd': d, 'msi': msi})


def old():
    st = tn.state()
    check('the release before reads the same accounts', len(tn.sql('SELECT 1 FROM phoromatic_accounts')) == 3)
    u = tn.Browser().login('rbtone')
    main = u.get('/?main')[2]
    check('the active account\'s user logs in and its page shows the name escaped', '&lt;b&gt;Rb&lt;/b&gt; One' in main and '<b>Rb</b> One' not in main, re.sub(r'\s+', ' ', main)[:200])
    check('its machine checks in', 'error' not in tn.v1('ping', st['a'], st['msi']))
    check('a page of the account works', 'Settings' in u.get('/?settings')[2])
    two = tn.Browser()
    two.get('/')
    check('the suspended account\'s user is refused', 'Log-In Successful' not in two.post('/?login', {'username': 'rbttwo', 'password': tn.PW})[2])
    check('its machines are told it is suspended', tn.v1('ping', st['s'], 'rb-msi').get('error') == SUSPENDED, tn.v1('ping', st['s'], 'rb-msi'))
    check('the deleted account is an unknown account to its machines', tn.v1('ping', st['d'], 'rb-msi').get('error') == 'Invalid User')
    root = tn.Browser().login_root()
    check('root\'s page lists the accounts', all(i in root.get('/?admin')[2] for i in (st['a'], st['s'], st['d'])))
    check('the console pages are not there (a main page instead)', 'Create an account' not in tn.Browser().login('rbtone').get('/?server_tenants/new')[2])


def new_again():
    st = tn.state()
    b = root_console()
    page = b.get('/?server_tenants/show/all')[2]
    check('the console lists the three', all(i in page for i in (st['a'], st['s'], st['d'])) and '&lt;b&gt;Rb&lt;/b&gt; One' in page)
    act(b, 'tenant_resume', account_id=st['s'])
    act(b, 'tenant_restore', account_id=st['d'])
    act(b, 'tenant_resume', account_id=st['d'])
    rows = {r['AccountID']: r['State'] for r in tn.sql('SELECT AccountID, State FROM phoromatic_accounts')}
    check('all three are active', set(rows.values()) == {'active'}, rows)
    check('the suspended account\'s user logs in again', bool(tn.Browser().login('rbttwo')))
    check('the restored account\'s user logs in', bool(tn.Browser().login('rbtthree')))
    check('the first account\'s machine still checks in', 'error' not in tn.v1('ping', st['a'], st['msi']))


{'new-first': new_first, 'old': old, 'new-again': new_again}[sys.argv[1]]()
print('FAILURES %d' % fails)
