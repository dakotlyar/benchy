"""Test benches 2C (Benchy 0.9): bench tokens (bbt_, their own type), the bench_action resource behind bench-status / bench-swap /
bench-end, the failure lockout and the automatic revocations. Migration case 54 is checked here too.

Requests to bench_action run through the real phoromatic.php in a CLI process with a chosen source address (as_ip), so the failure
lockout of one test never reaches another. Run through tests/private-repo/run.sh (part of http/all and benches).
"""
import json
import re
import unittest
import uuid

from bench_helpers import (act, bench_audit, bench_of, make_bench, new_system, open_session, sessions, swap, system_row, token)
from test_benches_members import add
from test_private_repo_http import AID, REPO_TOKEN, sql, srv_sh
from test_private_repo_tokens import as_ip, sql_exec
from test_private_repo_web import Browser, WebBase
from test_systems_tombstone import delete_via_ui

IP_SEQ = [10]


def ip():
    IP_SEQ[0] += 1
    return '198.51.%d.%d' % (100 + IP_SEQ[0] // 250, IP_SEQ[0] % 250 + 1)


def ba(msi, act_, bt=None, addr=None, **args):
    fields = {'act': act_, 'args': json.dumps(args)}
    if bt is not None:
        fields['bt'] = bt
    return as_ip(addr or ip(), msi, 'bench_action', **fields)


def web_token(browser, bid, label=None, system='any'):
    body = act(browser, '/?benches/%d' % bid, 'token_create', label=label or 'qc tok %s' % uuid.uuid4().hex[:4], system_id=system)
    m = re.search(r'(bbt_[A-Za-z0-9_-]{43})', body)
    return m.group(1) if m else body


def tokens(bid):
    return sql("SELECT * FROM phoromatic_bench_tokens WHERE AccountID='%s' AND BenchID=%d ORDER BY TokenID" % (AID, bid))


def failures(reason):
    return [r for r in sql("SELECT * FROM phoromatic_admin_audit WHERE Action='bench_cli_auth_failed' ORDER BY AuditID") if '"reason":"%s"' % reason in (r['Detail'] or '')]


class TestMigration55(unittest.TestCase):
    def test_tables_and_repeatable(self):
        names = {r['name'] for r in sql("SELECT name FROM sqlite_master")}
        self.assertTrue({'phoromatic_bench_tokens', 'phoromatic_bench_tokens_bench', 'phoromatic_bench_ticket_reruns'} <= names)
        repo_cols = [c['name'] for c in sql('PRAGMA table_info(phoromatic_private_repo_tokens)')]
        self.assertNotIn('Scopes', repo_cols, 'repository tokens are untouched')
        before = sql('SELECT COUNT(*) AS n FROM phoromatic_bench_tokens')[0]['n']
        self.assertEqual(sql_exec('PRAGMA user_version = 54'), 'ok')
        srv_sh("cd /bench/pts-core/phoromatic/public_html && php -r 'define(\"PHOROMATIC_SERVER\", true); define(\"PTS_MODE\", \"WEB_CLIENT\"); define(\"PTS_AUTO_LOAD_OBJECTS\", true); include(\"../../pts-core.php\"); pts_core::init(); phoromatic_server::prepare_database();'")
        self.assertEqual(sql('PRAGMA user_version')[0]['user_version'], 60)
        self.assertEqual(sql('SELECT COUNT(*) AS n FROM phoromatic_bench_tokens')[0]['n'], before)


class TestTokens(WebBase):
    def test_create_on_the_web_shown_once_hash_stored_audited(self):
        msi, sid, bid, s1 = make_bench(self.admin, cls='other')
        tok = web_token(self.admin, bid, label='qc rig shell')
        self.assertTrue(tok.startswith('bbt_'), tok[:500])
        t = tokens(bid)[-1]
        self.assertEqual((t['Label'], t['SystemID'], t['RevokedOn']), ('qc rig shell', None, None))
        self.assertNotIn(tok, json.dumps(sql("SELECT * FROM phoromatic_bench_tokens")), 'only the hash is stored')
        self.assertNotIn(tok, self.admin.get('/?benches/%d' % bid)[2], 'shown once')
        a = bench_audit(bid, 'bench_token_create')[-1]
        self.assertEqual(json.loads(a['Detail'])['token'], 'BT%d' % t['TokenID'])
        self.assertNotIn(tok, a['Detail'])

    def test_create_list_revoke_on_the_server(self):
        msi, sid, bid, s1 = make_bench(self.admin, cls='other')
        out = srv_sh('sh /bench/benchy bench-token-create B%d qc server tok --system %s' % (bid, sid))
        tok = re.search(r'(bbt_[A-Za-z0-9_-]{43})', out).group(1)
        t = tokens(bid)[-1]
        self.assertEqual(t['SystemID'], sid)
        self.assertIn('BT%d' % t['TokenID'], srv_sh('sh /bench/benchy bench-token-list %d' % bid))
        self.assertNotIn(tok, srv_sh('sh /bench/benchy bench-token-list'))
        self.assertIn('revoked', srv_sh('sh /bench/benchy bench-token-revoke BT%d' % t['TokenID']))
        self.assertEqual(tokens(bid)[-1]['RevokeReason'], 'manual')
        self.assertIn('Usage', srv_sh('sh /bench/benchy bench-token-create'))

    def test_levels_3_and_10_cannot_create_or_revoke(self):
        msi, sid, bid, s1 = make_bench(self.admin, cls='other')
        tok = web_token(self.admin, bid)
        tid = tokens(bid)[-1]['TokenID']
        n = len(tokens(bid))
        for level in (3, 10):
            b = self.as_user(level)
            t = token(b) or token(self.admin)
            self.assertIn('Only account administrators', act(b, '/?benches/%d' % bid, 'token_create', tok=t, label='qc nope', system_id='any'))
            self.assertIn('Only account administrators', act(b, '/?benches/%d' % bid, 'token_revoke', tok=t, token_id=str(tid)))
            self.assertNotIn('Bench tokens', b.get('/?benches/%d' % bid)[2])
        self.assertEqual(len(tokens(bid)), n)
        self.assertIsNone(tokens(bid)[-1]['RevokedOn'])


class TestBenchAction(WebBase):
    def test_status_needs_no_token(self):
        msi, sid, bid, s1 = make_bench(self.admin, cls='other')
        st = ba(msi, 'status')['bench']
        self.assertEqual((st['id'], st['session']['id'], st['members'][0]['system'], st['members'][0]['this']), ('B%d' % bid, 'S%d' % s1, sid, True))
        self.assertEqual(st['session']['components'][0]['label'], open_session(bid)['Label'])
        msi2, sid2 = new_system()
        self.assertIn("isn't part of a bench", ba(msi2, 'status')['error'])
        msi3, sid3 = new_system(state=0)
        self.assertNotIn('bench', ba(msi3, 'status'), 'a pending system is refused by phoromatic.php as today')

    def test_swap_and_end_with_a_bench_token(self):
        msi, sid, bid, s1 = make_bench(self.admin, cls='other')
        tok = web_token(self.admin, bid)
        tid = tokens(bid)[-1]['TokenID']
        label = 'qc cli part %s' % uuid.uuid4().hex[:5]
        out = ba(msi, 'swap', bt=tok, new='other:' + label, config='qc cli config')
        self.assertIn('started with', out['bench']['done'], out)
        s2 = open_session(bid)
        self.assertEqual((s2['Label'], s2['Config']), (label, 'qc cli config'))
        start = bench_audit(bid, 'session_start')[-1]
        self.assertEqual((start['ActorType'], start['Actor']), ('bench_token', 'BT%d %s' % (tid, tokens(bid)[-1]['Label'])))
        self.assertEqual(json.loads(start['Detail'])['via_system'], sid)
        self.assertEqual(bench_audit(bid, 'session_end')[-1]['ActorType'], 'bench_token')
        self.assertEqual(tokens(bid)[-1]['LastUsedSystem'], sid)
        # an inventory component by label
        act(self.admin, '/?benches/components', 'component_create', label='qc stock %s' % uuid.uuid4().hex[:5], **{'class': 'other'})
        stock = sql("SELECT * FROM phoromatic_components WHERE AccountID='%s' ORDER BY ComponentID DESC LIMIT 1" % AID)[0]
        self.assertIn('started with', ba(msi, 'swap', bt=tok, component=stock['Label'].upper())['bench']['done'])
        self.assertEqual(open_session(bid)['ComponentID'], stock['ComponentID'])
        self.assertIn('No live component', ba(msi, 'swap', bt=tok, component='qc no such part')['error'])
        self.assertIn('Name the component', ba(msi, 'swap', bt=tok)['error'])
        # end: the bench then holds
        self.assertIn('ended', ba(msi, 'end', bt=tok)['bench']['done'])
        self.assertIsNone(open_session(bid))
        self.assertIn('no open session', ba(msi, 'end', bt=tok)['error'])

    def test_outstanding_task_on_another_member_refuses(self):
        msi, sid, bid, s1 = make_bench(self.admin, cls='other')
        msi2, sid2 = new_system()
        add(self.admin, bid, sid2)
        tok = web_token(self.admin, bid)
        sql_exec("UPDATE phoromatic_systems SET BenchTaskSession=%d, BenchTaskIssuedOn=datetime('now', 'localtime'), CurrentTask='Running', LastCommunication=datetime('now', 'localtime') WHERE SystemID='%s'" % (s1, sid2))
        self.assertIn('task outstanding', ba(msi, 'swap', bt=tok, new='other:qc x %s' % uuid.uuid4().hex[:4])['error'])
        # even when the other member is off: no force from the command line
        sql_exec("UPDATE phoromatic_systems SET LastCommunication=datetime('now', 'localtime', '-3 hours') WHERE SystemID='%s'" % sid2)
        self.assertIn('Force', ba(msi, 'end', bt=tok)['error'])
        self.assertEqual(open_session(bid)['SessionID'], s1)

    def test_token_refusals(self):
        msi, sid, bid, s1 = make_bench(self.admin, cls='other')
        msi2, sid2 = new_system()
        add(self.admin, bid, sid2)
        any_tok = web_token(self.admin, bid)
        bound = web_token(self.admin, bid, system=sid)
        n = len(sessions(bid))
        # missing: the clear message, recorded once per machine and hour
        before = len(failures('missing'))
        self.assertIn('bench token is required', ba(msi, 'swap', new='other:qc m')['error'])
        self.assertIn('bench token is required', ba(msi, 'end')['error'])
        self.assertEqual(len(failures('missing')), before + 1)
        # invalid, a repository token, a token of another bench, a bound token from the other member
        self.assertIn('not accepted', ba(msi, 'end', bt='bbt_' + 'x' * 43)['error'])
        self.assertIn('That is a repository token', ba(msi, 'end', bt=REPO_TOKEN)['error'])
        msi3, sid3, bid3, s3 = make_bench(self.admin, cls='other')
        self.assertIn('not accepted', ba(msi3, 'end', bt=any_tok)['error'])
        self.assertIn('not accepted', ba(msi2, 'end', bt=bound)['error'])
        for reason in ('invalid', 'repo_token', 'wrong_bench', 'wrong_system'):
            self.assertTrue(failures(reason), reason)
        self.assertEqual(len(sessions(bid)), n)
        self.assertIsNotNone(open_session(bid3))
        # the bench-wide token works from both members, the bound one from its own system
        self.assertIn('started with', ba(msi2, 'swap', bt=any_tok, new='other:qc b %s' % uuid.uuid4().hex[:4])['bench']['done'])
        self.assertIn('started with', ba(msi, 'swap', bt=bound, new='other:qc c %s' % uuid.uuid4().hex[:4])['bench']['done'])
        # revoked
        act(self.admin, '/?benches/%d' % bid, 'token_revoke', token_id=str(tokens(bid)[-1]['TokenID']))
        self.assertIn('not accepted', ba(msi, 'end', bt=bound)['error'])
        # a bench token gives no repository rights
        self.assertIn('not accepted', as_ip(ip(), msi, 'private_repo_rebuild', rt=any_tok)['error'])

    def test_ten_failures_lock_the_address(self):
        msi, sid, bid, s1 = make_bench(self.admin, cls='other')
        tok = web_token(self.admin, bid)
        addr = ip()
        for i in range(10):
            self.assertIn('not accepted', ba(msi, 'end', bt='bbt_' + 'y' * 43, addr=addr)['error'])
        self.assertRegex(ba(msi, 'end', bt=tok, addr=addr)['error'], r'Too many failed bench token attempts')
        self.assertIsNotNone(open_session(bid))
        self.assertIn('ended', ba(msi, 'end', bt=tok)['bench']['done'], 'another address is unaffected')


class TestAutomaticRevocation(WebBase):
    def test_demote_delete_and_retire(self):
        msi, sid, bid, s1 = make_bench(self.admin, cls='other')
        msi2, sid2 = new_system()
        add(self.admin, bid, sid2)
        msi3, sid3 = new_system()
        add(self.admin, bid, sid3)
        bench_wide = web_token(self.admin, bid)
        b2 = web_token(self.admin, bid, system=sid2)
        b3 = web_token(self.admin, bid, system=sid3)
        rows = {t['SystemID']: t for t in tokens(bid)}
        # removing sid2 from the bench revokes its bound token only
        act(self.admin, '/?benches/%d' % bid, 'demote', system_id=sid2)
        rows = {t['SystemID']: t for t in tokens(bid)}
        self.assertEqual((rows[sid2]['RevokeReason'], rows[None]['RevokedOn'], rows[sid3]['RevokedOn']), ('member_removed', None, None))
        # re-adding never revives it
        add(self.admin, bid, sid2)
        self.assertIn('not accepted', ba(msi2, 'end', bt=b2)['error'])
        # deleting sid3 revokes its token
        sql_exec("UPDATE phoromatic_systems SET State=-1 WHERE SystemID='%s'" % sid3)
        delete_via_ui(self.admin, sid3)
        self.assertEqual({t['SystemID']: t for t in tokens(bid)}[sid3]['RevokeReason'], 'system_deleted')
        # the bench retires when its last system leaves: every remaining token goes
        act(self.admin, '/?benches/%d' % bid, 'demote', system_id=sid2)
        act(self.admin, '/?benches/%d' % bid, 'demote', system_id=sid)
        self.assertEqual({t['SystemID']: t for t in tokens(bid)}[None]['RevokeReason'], 'bench_retired')
        reasons = [json.loads(a['Detail'])['reason'] for a in bench_audit(bid, 'bench_token_revoke')]
        self.assertEqual(sorted(reasons), ['bench_retired', 'member_removed', 'system_deleted'])

    def test_pages_render(self):
        msi, sid, bid, s1 = make_bench(self.admin, cls='other')
        web_token(self.admin, bid)
        from test_benches_members import PHP_ERROR
        for page in ('/?benches/%d' % bid, '/?benches/%d/reassign' % bid, '/?benches/%d/reassign&session=none&from=2020-01-01&to=2030-01-01&schedule=0' % bid):
            for browser in (self.admin, self.as_user(10)):
                status, _, body = browser.get(page)
                self.assertEqual(status, 200, page)
                self.assertIsNone(PHP_ERROR.search(body), page)


if __name__ == '__main__':
    unittest.main()
