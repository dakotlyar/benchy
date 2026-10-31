"""Test benches C-e (Benchy 0.9.1): multi-component sessions. A session has 1-4 components (a platform swap: CPU + board); labels join
with " + ", the drift guard holds on any of their classes, a swap awaits every new detectable class, a part is in one open session
only, ending a session clears the open flag. Migration case 56 is checked here too.

Run through tests/private-repo/run.sh (part of http/all and benches).
"""
import json
import unittest
import uuid

from bench_helpers import (BOARD, CPU_A, CPU_B, GPU_B, act, baseline, bench_audit, drift, drop_schedule, make_bench, open_session, result_row, schedule,
                           sessions, start, today, upload)
from test_benches_cli import ba, web_token
from test_private_repo_http import AID, sql, srv_sh
from test_private_repo_tokens import sql_exec
from test_private_repo_web import WebBase

BOARD_B = 'MSI MEG X870E Ace'


def join_rows(session_id):
    return sql("SELECT j.*, c.Label, c.Class FROM phoromatic_bench_session_components j JOIN phoromatic_components c ON c.ComponentID = j.ComponentID WHERE j.SessionID=%d ORDER BY j.Position" % session_id)


def new_component(browser, cls, label=None):
    label = label or 'qc %s %s' % (cls, uuid.uuid4().hex[:6])
    act(browser, '/?benches/components', 'component_create', label=label, **{'class': cls})
    return sql("SELECT ComponentID FROM phoromatic_components WHERE Label='%s'" % label)[0]['ComponentID'], label


def multi_swap(browser, bid, primary_id, extra_ids=(), tok='auto', **extra):
    """The swap form as a browser posts it: the primary from the inventory plus extra_component_id[] picks"""
    from bench_helpers import token
    data = [('bench_action', 'swap'), ('source', 'inventory'), ('component_id', str(primary_id)), ('config', 'qc multi')]
    data += [('extra_component_id[]', str(i)) for i in extra_ids]
    data += list(extra.items())
    data.append(('token_submit', token(browser) if tok == 'auto' else tok))
    return browser.post('/?benches/%d' % bid, data)[2]


def prepare_database():
    srv_sh("cd /bench/pts-core/phoromatic/public_html && php -r 'define(\"PHOROMATIC_SERVER\", true); define(\"PTS_MODE\", \"WEB_CLIENT\"); define(\"PTS_AUTO_LOAD_OBJECTS\", true); include(\"../../pts-core.php\"); pts_core::init(); phoromatic_server::prepare_database();'")


class TestMigration56(unittest.TestCase):
    def test_backfill_flags_and_repeatable(self):
        names = {r['name'] for r in sql("SELECT name FROM sqlite_master")}
        self.assertTrue({'phoromatic_bench_session_components', 'phoromatic_bench_session_components_open'} <= names)
        self.assertIn('AwaitingClasses', {c['name'] for c in sql('PRAGMA table_info(phoromatic_bench_baselines)')})
        # simulate a 0.9.0 database: no join rows, user_version 56
        self.assertEqual(sql_exec('DELETE FROM phoromatic_bench_session_components'), 'ok')
        self.assertEqual(sql_exec('PRAGMA user_version = 56'), 'ok')
        prepare_database()
        self.assertEqual(sql('PRAGMA user_version')[0]['user_version'], 57)
        n_sessions = sql('SELECT COUNT(*) AS n FROM phoromatic_bench_sessions')[0]['n']
        n_open = sql('SELECT COUNT(*) AS n FROM phoromatic_bench_sessions WHERE EndedOn IS NULL')[0]['n']
        rows = sql('SELECT * FROM phoromatic_bench_session_components')
        self.assertEqual((len(rows), sum(1 for r in rows if r['OpenFlag'] == 1), {r['Position'] for r in rows}), (n_sessions, n_open, {0}))
        before = sql('SELECT * FROM phoromatic_bench_session_components ORDER BY SessionID, ComponentID')
        self.assertEqual(sql_exec('PRAGMA user_version = 56'), 'ok')
        prepare_database()
        self.assertEqual(sql('PRAGMA user_version')[0]['user_version'], 57)
        self.assertEqual(sql('SELECT * FROM phoromatic_bench_session_components ORDER BY SessionID, ComponentID'), before, 'running it again changes nothing')


class TestMultiComponent(WebBase):
    def setUp(self):
        self.msi, self.sid, self.bid, self.s1 = make_bench(self.admin, cls='cpu')
        self.sched = schedule(self.sid)
        self.cpu_b, self.cpu_b_label = new_component(self.admin, 'cpu', 'qc 9950X %s' % uuid.uuid4().hex[:5])
        self.board_b, self.board_b_label = new_component(self.admin, 'motherboard', 'qc Ace %s' % uuid.uuid4().hex[:5])

    def tearDown(self):
        drop_schedule(self.sched)

    def platform_swap(self):
        body = multi_swap(self.admin, self.bid, self.cpu_b, [self.board_b])
        s = open_session(self.bid)
        self.assertIsNotNone(s, body[:500])
        return int(s['SessionID'])

    def test_one_component_session_has_one_row(self):
        rows = join_rows(self.s1)
        self.assertEqual([(r['Position'], r['OpenFlag']) for r in rows], [(0, 1)])
        r = start(self.msi)
        self.assertNotIn('components', r['bench'], 'a one-component reply is the 0.9.0 reply')

    def test_two_component_session_rows_labels_task_env_and_audit(self):
        s2 = self.platform_swap()
        rows = join_rows(s2)
        self.assertEqual([(r['ComponentID'], r['Position'], r['OpenFlag']) for r in rows], [(self.cpu_b, 0, 1), (self.board_b, 1, 1)])
        self.assertEqual([r['OpenFlag'] for r in join_rows(self.s1)], [None], 'the swapped-out session is closed')
        self.assertEqual(open_session(self.bid)['ComponentID'], self.cpu_b, 'the session row keeps the primary')
        joined = '%s + %s' % (self.cpu_b_label, self.board_b_label)
        page = self.admin.get('/?benches/%d/session/%d' % (self.bid, s2))[2]
        self.assertIn(joined, page)
        self.assertIn('cpu + motherboard', page)
        self.assertIn(joined, self.admin.get('/?benches/%d' % self.bid)[2])
        detail = json.loads(bench_audit(self.bid, 'session_start')[-1]['Detail'])
        self.assertEqual([c['label'] for c in detail['components']], [self.cpu_b_label, self.board_b_label])
        self.assertEqual(detail['component'], self.cpu_b_label)
        # the swap has happened on the bench: both parts detected, the task carries the list and the joined env label
        r = start(self.msi, cpu=CPU_B, board=BOARD_B)
        self.assertEqual(r.get('task'), 'benchmark', r.get('response'))
        self.assertEqual(r['bench']['component'], {'label': self.cpu_b_label, 'class': 'cpu'})
        self.assertEqual(r['bench']['components'], [{'label': self.cpu_b_label, 'class': 'cpu'}, {'label': self.board_b_label, 'class': 'motherboard'}])
        env = r['pre_set_sys_env_vars']
        self.assertIn('BENCHY_BENCH_COMPONENT=%s;' % joined, env)
        # a result is filed under the session; compare keys by the component set and the class filter matches any component of it
        uid = upload(self.msi, sched=self.sched, ts=today())
        self.assertEqual(int(result_row(uid)['BenchSessionID']), s2)
        for cls, shown in (('cpu', True), ('motherboard', True), ('gpu', False)):
            body = self.admin.get('/?benches/compare&bench=%d&class=%s&schedule=0' % (self.bid, cls))[2]
            body += self.admin.get('/?benches/compare&bench=%d&class=%s&schedule=%d' % (self.bid, cls, self.sched))[2]
            self.assertEqual(joined in body, shown, cls)

    def test_awaiting_waits_for_every_new_detectable_class(self):
        s2 = self.platform_swap()
        d = drift(self.sid)
        self.assertEqual((d['kind'], [f['key'] for f in d['fields']]), ('awaiting', ['cpu', 'motherboard']))
        self.assertEqual(json.loads(baseline(s2, self.sid)['AwaitingClasses']), ['cpu', 'motherboard'])
        r = start(self.msi, cpu=CPU_B, board=BOARD)  # the CPU is swapped, the board is not yet
        self.assertEqual(r.get('task'), 'idle')
        self.assertIn('waiting for the swap', r['response'])
        self.assertIn('Motherboard', r['response'])
        self.assertNotIn('a CPU other than', r['response'], 'only the class still waiting is named')
        self.assertEqual([f['key'] for f in drift(self.sid)['fields']], ['motherboard'])
        r = start(self.msi, cpu=CPU_B, board=BOARD_B)
        self.assertEqual(r.get('task'), 'benchmark', r.get('response'))
        b = baseline(s2, self.sid)
        self.assertIsNone(b['AwaitingFromJSON'])
        self.assertIsNone(b['AwaitingClasses'])
        self.assertEqual(json.loads(b['BaselineJSON'])['cpu']['model'], CPU_B)
        self.assertIsNone(drift(self.sid))

    def test_a_component_carried_over_is_not_awaited(self):
        first = sql("SELECT ComponentID FROM phoromatic_bench_session_components WHERE SessionID=%d" % self.s1)[0]['ComponentID']
        multi_swap(self.admin, self.bid, first, [self.board_b])  # the CPU stays, a board is added
        s2 = int(open_session(self.bid)['SessionID'])
        self.assertEqual(json.loads(baseline(s2, self.sid)['AwaitingClasses']), ['motherboard'])
        self.assertEqual(start(self.msi, cpu=CPU_A, board=BOARD).get('task'), 'idle')
        self.assertEqual(start(self.msi, cpu=CPU_A, board=BOARD_B).get('task'), 'benchmark')

    def test_a_change_in_either_class_holds_a_third_gives_a_notice(self):
        self.platform_swap()
        self.assertEqual(start(self.msi, cpu=CPU_B, board=BOARD_B).get('task'), 'benchmark')
        r = start(self.msi, cpu=CPU_B, board=BOARD_B, gpu=GPU_B)
        self.assertEqual(r.get('task'), 'benchmark', 'a constant part gives a notice, not a hold')
        d = drift(self.sid)
        self.assertEqual((d['kind'], [(f['key'], f['holds']) for f in d['fields']]), ('notice', [('gpu', False)]))
        r = start(self.msi, cpu=CPU_B, board=BOARD, gpu=GPU_B)
        self.assertEqual(r.get('task'), 'idle')
        self.assertIn('Hardware changed: Motherboard', r['response'])
        d = drift(self.sid)
        self.assertEqual(d['kind'], 'drift')
        self.assertIn(('motherboard', True), [(f['key'], f['holds']) for f in d['fields']])
        r = start(self.msi, cpu=CPU_A, board=BOARD_B, gpu=GPU_B)
        self.assertEqual(r.get('task'), 'idle', 'the CPU is the other class under test')
        # "Same hardware, continue" takes both parts under test
        act(self.admin, '/?benches/%d/ack/%s' % (self.bid, self.sid), 'ack', expect_kind='drift', confirm_checked='1')
        base = json.loads(baseline(int(open_session(self.bid)['SessionID']), self.sid)['BaselineJSON'])
        self.assertEqual(base['cpu']['model'], CPU_A)

    def test_a_part_is_in_one_open_session_only(self):
        self.platform_swap()
        msi2, sid2, bid2, s_other = make_bench(self.admin, cls='other')
        # as an extra part
        body = multi_swap(self.admin, bid2, new_component(self.admin, 'psu')[0], [self.board_b])
        self.assertIn('already under test', body)
        self.assertEqual(len(sessions(bid2)), 1, 'nothing changed')
        self.assertEqual(join_rows(s_other)[0]['OpenFlag'], 1, 'and the open session is untouched (the swap rolled back)')
        # the index itself
        self.assertNotEqual(sql_exec("INSERT INTO phoromatic_bench_session_components (AccountID, SessionID, ComponentID, Position, OpenFlag) VALUES ('%s', %d, %d, 3, 1)" % (AID, s_other, self.board_b)), 'ok')

    def test_refusals_change_nothing(self):
        spare = [new_component(self.admin, 'other')[0] for _ in range(4)]
        before = len(sessions(self.bid))
        self.assertIn('at most 4', multi_swap(self.admin, self.bid, spare[0], spare[1:] + [self.board_b]))
        self.assertIn('picked twice', multi_swap(self.admin, self.bid, spare[0], [spare[1], spare[1]]))
        self.assertIn('picked twice', multi_swap(self.admin, self.bid, spare[0], [spare[0]]))
        self.assertIn('not in the inventory', multi_swap(self.admin, self.bid, spare[0], ['999999']))
        self.assertEqual(len(sessions(self.bid)), before)
        self.assertEqual(open_session(self.bid)['SessionID'], self.s1)
        self.assertEqual(join_rows(self.s1)[0]['OpenFlag'], 1)
        # four is fine
        multi_swap(self.admin, self.bid, spare[0], spare[1:])
        self.assertEqual(len(join_rows(int(open_session(self.bid)['SessionID']))), 4)

    def test_same_set_refused_dropping_a_part_allowed(self):
        s2 = self.platform_swap()
        self.assertIn('component set already under test', multi_swap(self.admin, self.bid, self.board_b, [self.cpu_b]))
        self.assertEqual(int(open_session(self.bid)['SessionID']), s2)
        multi_swap(self.admin, self.bid, self.cpu_b)
        s3 = int(open_session(self.bid)['SessionID'])
        self.assertNotEqual(s3, s2)
        self.assertEqual([r['ComponentID'] for r in join_rows(s3)], [self.cpu_b])
        # the board is free again
        self.assertEqual([r['OpenFlag'] for r in join_rows(s2)], [None, None])

    def test_ending_clears_the_open_flag_and_frees_the_parts(self):
        s2 = self.platform_swap()
        start(self.msi, cpu=CPU_B, board=BOARD_B)
        sql_exec("UPDATE phoromatic_systems SET BenchTaskSession=NULL WHERE SystemID='%s'" % self.sid)
        act(self.admin, '/?benches/%d' % self.bid, 'end')
        self.assertIsNone(open_session(self.bid))
        self.assertEqual([r['OpenFlag'] for r in join_rows(s2)], [None, None])
        detail = json.loads(bench_audit(self.bid, 'session_end')[-1]['Detail'])
        self.assertEqual(len(detail['components']), 2)
        inventory = self.admin.get('/?benches/components')[2]
        self.assertIn(self.board_b_label, inventory)
        multi_swap(self.admin, self.bid, self.board_b)  # free to be used again
        self.assertEqual(open_session(self.bid)['ComponentID'], self.board_b)

    def test_pages_render_without_php_errors(self):
        import re
        s2 = self.platform_swap()
        start(self.msi, cpu=CPU_B, board=BOARD)
        uid = upload(self.msi, sched=self.sched, ts=today())
        pprid = sql("SELECT PPRID FROM phoromatic_results WHERE UploadID=%d" % int(uid))[0]['PPRID']
        err = re.compile(r'(<b>)?(Fatal error|Warning|Notice)(</b>)?: |Uncaught|Stack trace|(<b>)?Deprecated(</b>)?: [^\n]*(phoromatic_bench|phoromatic_benches|phoromatic_result_access)\.php')
        for page in ('/?benches', '/?benches/%d' % self.bid, '/?benches/%d/swap' % self.bid, '/?benches/%d/end' % self.bid, '/?benches/%d/ack/%s' % (self.bid, self.sid),
                     '/?benches/%d/session/%d' % (self.bid, s2), '/?benches/components', '/?benches/compare', '/?systems', '/?systems/%s' % self.sid, '/?result/%s' % pprid, '/?main'):
            status, _, body = self.admin.get(page)
            self.assertEqual(status, 200, page)
            m = err.search(body)
            self.assertIsNone(m, '%s: %s' % (page, body[max(0, m.start() - 200):m.start() + 300] if m else ''))

    def test_cli_resource_takes_further_parts_and_status_lists_them(self):
        tok = web_token(self.admin, self.bid)
        out = ba(self.msi, 'swap', bt=tok, component=str(self.cpu_b), also=[self.board_b_label])
        self.assertIn('started with', out['bench']['done'], out)
        s2 = int(open_session(self.bid)['SessionID'])
        self.assertEqual([r['ComponentID'] for r in join_rows(s2)], [self.cpu_b, self.board_b])
        self.assertEqual(bench_audit(self.bid, 'session_start')[-1]['ActorType'], 'bench_token')
        status = ba(self.msi, 'status')['bench']['session']
        self.assertEqual([c['label'] for c in status['components']], [self.cpu_b_label, self.board_b_label])
        self.assertIn('No live component', ba(self.msi, 'swap', bt=tok, component=str(self.cpu_b), also=['qc nope'])['error'])


if __name__ == '__main__':
    unittest.main()
