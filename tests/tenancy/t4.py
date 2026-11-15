"""Helpers of the T4 tests: the console actions as a browser does them, audit and account lookups, and the base class that makes a server administrator."""
import json
import re
import unittest
import uuid

import fixture
import tn

EVIL = {'Origin': 'http://evil.example'}
SUSPENDED = 'This Phoromatic account is suspended.'
TRIGGER = ("CREATE TRIGGER phoromatic_server_audit_no_delete BEFORE DELETE ON phoromatic_server_audit BEGIN SELECT RAISE(ABORT, 'audit rows are append-only'); END")
KEEP = ('phoromatic_accounts', 'phoromatic_users', 'phoromatic_admin_audit', 'phoromatic_private_repo_audit')


def acct(aid):
    rows = tn.sql("SELECT * FROM phoromatic_accounts WHERE AccountID='%s'" % aid)
    return rows[0] if rows else None


def accounts_state():
    return tn.sql('SELECT AccountID, GroupName, State, PurgedOn, DeletedOn, StateChangedBy FROM phoromatic_accounts ORDER BY AccountID')


def audit_total():
    return tn.sql('SELECT COUNT(*) AS n FROM phoromatic_server_audit')[0]['n']


def audit(action, aid=None):
    where = "Action='%s'" % action + (" AND TenantID='%s'" % aid if aid else '')
    return tn.sql('SELECT * FROM phoromatic_server_audit WHERE %s ORDER BY AuditID' % where)


def forget_audit(where):
    """The audit table refuses DELETE; a test that planted or caused rows takes the guard off for one statement and puts it back."""
    tn.sql_exec('DROP TRIGGER phoromatic_server_audit_no_delete; DELETE FROM phoromatic_server_audit WHERE %s; %s' % (where, TRIGGER))


def any_token(b):
    for p in ('/?settings', '/?users', '/?systems', '/?schedules', '/?private_repository', '/?results'):
        m = re.search(r'name="token_submit" value="([0-9a-f]+)"', b.get(p)[2])
        if m:
            return m.group(1)
    return '0' * 40


def act(b, action, token=None, headers=None, **fields):
    """A console change as a browser does it: the POST, then the page it sends the person to. (status, location, page, token used)"""
    tok = token if token is not None else any_token(b)
    s, h, body = b.post('/?r_server_action', dict(fields, action=action, token_submit=tok), headers=headers)
    loc = h.get('Location')
    return s, loc, (b.get(loc)[2] if loc else body)


def console_token(b):
    return b.token('/?server_tenants/new')


def sa_act(b, action, **fields):
    return act(b, action, token=console_token(b), **fields)


def seed_tenant_data(aid):
    """One row of canary data in every table of the tenant, and files in its folder (the repository folder included)."""
    payload = json.dumps({'rows': [], 'done': [], 't2': aid})
    out = tn.srv_php(fixture.SEED_PHP % {'storage': tn.STORAGE}, stdin=payload)
    assert 'seeded' in out, out
    fixture.put_files({'%s/accounts/%s/results/T4CANARY/composite.xml' % (tn.STORAGE, aid): b'canary', '%s/accounts/%s/private-repo/tests/t4canary/1.0.0.zip' % (tn.STORAGE, aid): b'zip',
                       '%s/accounts/%s/systems/T4SYS/log.txt' % (tn.STORAGE, aid): b'log'})
    tn.Browser().get('/')   # (the server mends what the generic seed leaves out, such as a bench session's component row, on its next request: let it, so a later snapshot is of the settled data)


def tenant_tables():
    return [r['name'] for r in tn.sql("SELECT name FROM sqlite_master WHERE type='table' AND name LIKE 'phoromatic_%' ORDER BY name")
            if any(c['name'] == 'AccountID' for c in tn.sql('PRAGMA table_info(%s)' % r['name']))]


def data_snapshot(aid):
    """Everything of the tenant except its account row (which changes with its state)."""
    s = tn.snapshot(aid)
    s['rows'].pop('phoromatic_accounts', None)
    s['listing'] = tn.srv_sh("cd '%s/accounts/%s' 2>/dev/null && find . | sort" % (tn.STORAGE, aid)).split()
    return s


class Console(unittest.TestCase):
    """Two accounts, T1 and T2, and server administrators made by the test (nobody is one otherwise)."""
    @classmethod
    def setUpClass(cls):
        cls.st = tn.state()
        cls.t1, cls.t2 = cls.st['T1']['aid'], cls.st['T2']['aid']
        tn.reset_v1_limits()
        tn.cli('server-admin-grant', 'tn1lvl2')   # a server administrator at account level 2: the role is not the level
        cls.sa = tn.Browser().login('tn1lvl2')
        cls.root = tn.Browser().login_root()

    @classmethod
    def tearDownClass(cls):
        tn.cli('server-admin-revoke', 'tn1lvl2')

    def same(self, a, b, msg=''):
        """Two data snapshots are equal; says what differs."""
        self.maxDiff = None
        self.assertEqual(a['listing'], b['listing'], msg + ' (files and folders)')
        self.assertEqual(a['rows'], b['rows'], msg + ' (rows)')
        self.assertEqual(a['files'], b['files'], msg + ' (file contents)')

    def new_tenant(self, label='T4', browser=None, name=None):
        """A tenant made through the console; returns (aid, name, first user)."""
        b = browser or self.sa
        tag = uuid.uuid4().hex[:6]
        name = name or '%s %s' % (label, tag)
        user = 't4u' + tag
        s, loc, page = act(b, 'tenant_create', token=console_token(b), tenant_name=name, register_username=user, register_password=tn.PW, register_password_confirm=tn.PW,
                           register_email=user + '@example.com', seed_accountid='')
        self.assertEqual(s, 302, page[:300])
        m = re.search(r'server_tenants/([A-Za-z0-9]{6})$', loc or '')
        self.assertTrue(m, (loc, re.sub(r'\s+', ' ', page)[:300]))
        aid = m.group(1)
        return aid, name, user
