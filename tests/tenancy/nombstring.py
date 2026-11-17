"""Checks of nombstring.sh: the server console and the activity page on a PHP without mbstring. Talks to the scratch server of nombstring.sh only."""
import os
import re
import subprocess
import sys

import tn

SRV = os.environ['NM_SRV']
b = tn.Browser('http://127.0.0.1:%s' % os.environ['NM_PORT'])
bad = []


def check(what, ok, detail=''):
    print('  %s  %s%s' % ('PASS' if ok else 'FAIL', what, '' if ok else ' -- ' + re.sub(r'\s+', ' ', str(detail))[:300]))
    if not ok:
        bad.append(what)


def create(name, user):
    token = b.token('/?server_tenants/new')
    s, h, body = b.post('/?r_server_action', {'action': 'tenant_create', 'token_submit': token, 'tenant_name': name, 'register_username': user, 'register_password': tn.PW,
                                               'register_password_confirm': tn.PW, 'register_email': user + '@example.com', 'seed_accountid': ''})
    loc = h.get('Location')
    return s, loc, (b.get(loc)[2] if loc else body)


def php(code):
    return subprocess.run(['docker', 'exec', SRV, 'php', '-r', code], capture_output=True, text=True).stdout


b.login('nmadmin', 'nmpass12345')
s, loc, page = create('Ünï Lab', 'nmuser1')
check('an account with a non-ASCII name is made in the console (no 500)', s == 302 and re.search(r'server_tenants/[A-Za-z0-9]{6}$', loc or ''), (s, loc, page[:200]))
s, loc, page = create('Plain Name', 'nmuser2')
check('a second account is made', s == 302 and re.search(r'server_tenants/[A-Za-z0-9]{6}$', loc or ''), (s, loc))
s, loc, page = create('plain NAME', 'nmuser3')
check('a name taken in another case is refused with a message', s == 302 and (loc or '').endswith('server_tenants/new') and 'already called' in page, (s, loc, page[:200]))
check('and no account was made for it', php('$d=new SQLite3("/var/lib/benchy/phoromatic/phoromatic.db"); echo $d->querySingle("SELECT COUNT(*) FROM phoromatic_users WHERE UserName=\'nmuser3\'");') == '0')
s, loc, page = create('é' * 65, 'nmuser4')
check('a name of 65 characters (130 bytes) is refused as too long, not 64 bytes', s == 302 and (loc or '').endswith('server_tenants/new') and 'at most 64' in page, (s, loc, page[:200]))
s, loc, page = create('é' * 64, 'nmuser5')
check('a name of exactly 64 two-byte characters is accepted', s == 302 and re.search(r'server_tenants/[A-Za-z0-9]{6}$', loc or ''), (s, loc, page[:200]))

counts = php('$d=new SQLite3("/var/lib/benchy/phoromatic/phoromatic.db"); echo $d->querySingle("SELECT COUNT(*) FROM phoromatic_accounts"), " ", $d->querySingle("SELECT COUNT(*) FROM phoromatic_users"), " ", $d->querySingle("SELECT COUNT(*) FROM phoromatic_accounts WHERE AccountID NOT IN (SELECT AccountID FROM phoromatic_users)");')
check('the first account and the three made: four accounts, four users, no account without a user (a failed request leaves nothing half made)', counts == '4 4 0', counts)

# The activity page with events whose benchmark ticket or schedule is gone
php('$d=new SQLite3("/var/lib/benchy/phoromatic/phoromatic.db"); foreach(array("benchmark","schedule","tests_for_schedule") as $e) { $d->exec("INSERT INTO phoromatic_activity_stream (AccountID, ActivityTime, ActivityCreator, ActivityCreatorType, ActivityEvent, ActivityEventID, ActivityEventType) VALUES (\'NMAAAA\', datetime(\'now\'), \'nmadmin\', \'USER\', \'$e\', \'999\', \'removed\')"); }')
s, _, page = b.get('/?account_activity')
check('the activity page shows events of a deleted benchmark and schedule', s == 200 and 'Recent Account Activity' in page and page.count(': 999') == 3, (s, page[-300:]))
log = subprocess.run(['docker', 'exec', SRV, 'sh', '-c', 'cat /tmp/php-err.log 2>/dev/null'], capture_output=True, text=True).stdout
found = [l for l in log.splitlines() if 'Fatal' in l or 'mb_' in l or 'array offset' in l]
check('the PHP log has no fatal error, mb_ call or array-offset warning', not found, found[:3])
print('NOMBSTRING: %s' % ('FAIL' if bad else 'PASS'))
sys.exit(1 if bad else 0)
