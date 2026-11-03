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
        self.assertEqual((len(rows), sum(1 for r in rows if r['OpenFlag'] == 1)), (n_sessions, n_open))
        self.assertTrue({r['Position'] for r in rows} <= {0})
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


    def test_form_can_carry_a_part_over_and_the_pickers_say_so(self):
        # AP-5 M1: the pickers list this bench's own open-session parts, tagged, so S5 {CPU-A, Board-X} -> S6 {CPU-A, Board-Y} can be posted
        first = sql("SELECT ComponentID, Label FROM phoromatic_bench_session_components j JOIN phoromatic_components c USING (ComponentID) WHERE j.SessionID=%d" % self.s1)[0]
        multi_swap(self.admin, self.bid, first['ComponentID'], [self.board_b])  # S2: the CPU stays, board X joins
        s2 = int(open_session(self.bid)['SessionID'])
        page = self.admin.get('/?benches/%d/swap' % self.bid)[2]
        self.assertIn('under test now, carried over', page)
        self.assertRegex(page, r'<option value="%d">[^<]*carried over' % self.board_b)
        board_y, _ = new_component(self.admin, 'motherboard', 'qc board Y %s' % uuid.uuid4().hex[:5])
        multi_swap(self.admin, self.bid, first['ComponentID'], [board_y])
        s3 = int(open_session(self.bid)['SessionID'])
        self.assertNotEqual(s3, s2)
        self.assertEqual([r['ComponentID'] for r in join_rows(s3)], [first['ComponentID'], board_y])
        self.assertEqual(json.loads(baseline(s3, self.sid)['AwaitingClasses']), ['motherboard'], 'only the new board is awaited')
        # another bench does not get this bench's parts offered
        msi2, sid2, bid2, _ = make_bench(self.admin, cls='other')
        self.assertNotRegex(self.admin.get('/?benches/%d/swap' % bid2)[2], r'<option value="%d">' % first['ComponentID'])

    def test_reconcile_on_start_after_a_0_9_write(self):
        # AP-5 M2: a 0.9.0 server run after the migration ends sessions without clearing OpenFlag and starts sessions without join rows
        s2 = self.platform_swap()
        self.assertEqual([r['OpenFlag'] for r in join_rows(s2)], [1, 1])
        extra, _ = new_component(self.admin, 'psu')
        # 0.9 ends S2 (flags stay) and starts a session with `extra` (no join row)
        self.assertEqual(sql_exec("UPDATE phoromatic_bench_sessions SET EndedOn=datetime('now'), EndedBy='qc09', EndReason='end' WHERE SessionID=%d" % s2), 'ok')
        self.assertEqual(sql_exec("INSERT INTO phoromatic_bench_sessions (AccountID, BenchID, ComponentID, StartedOn, StartedBy) VALUES ('%s', %d, %d, datetime('now'), 'qc09')" % (AID, self.bid, extra)), 'ok')
        s3 = int(open_session(self.bid)['SessionID'])
        self.assertEqual(len(join_rows(s3)), 0)
        prepare_database()
        self.assertEqual([r['OpenFlag'] for r in join_rows(s2)], [None, None], 'the ended session no longer holds its parts')
        self.assertEqual([(r['ComponentID'], r['Position'], r['OpenFlag']) for r in join_rows(s3)], [(extra, 0, 1)])
        before = sql('SELECT * FROM phoromatic_bench_session_components ORDER BY SessionID, ComponentID')
        prepare_database()
        self.assertEqual(sql('SELECT * FROM phoromatic_bench_session_components ORDER BY SessionID, ComponentID'), before, 'idempotent')
        # the 0.9-started session's part cannot be double-booked now, and the freed parts can be used again
        msi2, sid2, bid2, _ = make_bench(self.admin, cls='other')
        self.assertIn('already under test', multi_swap(self.admin, bid2, new_component(self.admin, 'other')[0], [extra]))
        multi_swap(self.admin, bid2, self.cpu_b, [self.board_b])
        self.assertEqual(len(join_rows(int(open_session(bid2)['SessionID']))), 2)

    def test_a_reswap_during_a_pending_hold_keeps_the_pending_classes_awaited(self):
        # AP-5 L1: S2 awaits CPU + board; S3 = the same parts + a GPU must still await all three
        self.platform_swap()
        gpu_x, _ = new_component(self.admin, 'gpu')
        multi_swap(self.admin, self.bid, self.cpu_b, [self.board_b, gpu_x])
        s3 = int(open_session(self.bid)['SessionID'])
        self.assertEqual(sorted(json.loads(baseline(s3, self.sid)['AwaitingClasses'])), ['cpu', 'gpu', 'motherboard'])
        self.assertEqual(start(self.msi, cpu=CPU_B, board=BOARD_B).get('task'), 'idle', 'the GPU has not changed')
        r = start(self.msi, cpu=CPU_B, board=BOARD_B, gpu=GPU_B)
        self.assertEqual(r.get('task'), 'benchmark', r.get('response'))
        base = json.loads(baseline(s3, self.sid)['BaselineJSON'])
        self.assertEqual((base['cpu']['model'], base['gpu'][0]['model']), (CPU_B, GPU_B), 'the new CPU is in the baseline')
        self.assertIsNone(drift(self.sid))
        self.assertEqual(start(self.msi, cpu=CPU_B, board=BOARD_B, gpu=GPU_B).get('task'), 'benchmark', 'no spurious hold afterwards')

    def test_use_current_hardware_accepts_only_the_awaited_classes(self):
        # AP-5 L4: S2 = {CPU-A kept, board B new} awaits the board only; a CPU change is not covered by "Use current hardware"
        first = sql("SELECT ComponentID FROM phoromatic_bench_session_components WHERE SessionID=%d" % self.s1)[0]['ComponentID']
        multi_swap(self.admin, self.bid, first, [self.board_b])
        s2 = int(open_session(self.bid)['SessionID'])
        r = start(self.msi, cpu=CPU_B, board=BOARD)
        self.assertEqual(r.get('task'), 'idle')
        self.assertEqual([f['key'] for f in drift(self.sid)['fields']], ['motherboard'])
        act(self.admin, '/?benches/%d/ack/%s' % (self.bid, self.sid), 'ack', expect_kind='awaiting', confirm_checked='1')
        base = json.loads(baseline(s2, self.sid)['BaselineJSON'])
        self.assertEqual(base['cpu']['model'], CPU_A, 'the CPU change was not absorbed')
        self.assertEqual(start(self.msi, cpu=CPU_B, board=BOARD).get('task'), 'idle')
        self.assertEqual(drift(self.sid)['kind'], 'drift')

    def test_banner_names_every_waiting_class(self):
        self.platform_swap()
        page = self.admin.get('/?benches/%d' % self.bid)[2]
        self.assertIn('Waiting for the swap', page)
        self.assertIn('CPU and Motherboard', page)
        start(self.msi, cpu=CPU_B, board=BOARD)
        page = self.admin.get('/?benches/%d' % self.bid)[2]
        self.assertIn('swap the Motherboard;', page)

    def test_cli_also_must_be_a_list_of_names(self):
        tok = web_token(self.admin, self.bid)
        before = len(sessions(self.bid))
        for bad in ('x', {'a': 1}, [1], [''], [['nested']], [None]):
            out = ba(self.msi, 'swap', bt=tok, component=str(self.cpu_b), also=bad)
            self.assertIn('Usage', out.get('error', ''), (bad, out))
        out = ba(self.msi, 'swap', bt=tok, component=str(self.cpu_b), also=['a', 'b', 'c', 'd'])
        self.assertIn('at most 4', out.get('error', ''))
        self.assertEqual(len(sessions(self.bid)), before)


    def zero_nine_ends(self, session_id):
        """what a 0.9.0 server does to end a session: the session row only (it knows nothing of the component rows)"""
        self.assertEqual(sql_exec("UPDATE phoromatic_bench_sessions SET EndedOn=datetime('now'), EndedBy='qc09', EndReason='end' WHERE SessionID=%d" % session_id), 'ok')

    def zero_nine_starts(self, bench_id, component_id):
        """...and to start one: a session row with the primary only"""
        self.assertEqual(sql_exec("INSERT INTO phoromatic_bench_sessions (AccountID, BenchID, ComponentID, StartedOn, StartedBy) VALUES ('%s', %d, %d, datetime('now'), 'qc09')" % (AID, bench_id, component_id)), 'ok')
        return int(open_session(bench_id)['SessionID'])

    def test_reconcile_a_part_freed_and_taken_again_by_0_9_0_is_mended_at_once(self):
        # AP-5 F1(a): 0.9.0 ends S(A) and starts a new session with A; the flag must be cleared before the new row is written
        multi_swap(self.admin, self.bid, self.cpu_b)
        s2 = int(open_session(self.bid)['SessionID'])
        self.zero_nine_ends(s2)
        s3 = self.zero_nine_starts(self.bid, self.cpu_b)
        prepare_database()  # one start
        self.assertEqual([r['OpenFlag'] for r in join_rows(s2)], [None])
        self.assertEqual([(r['ComponentID'], r['Position'], r['OpenFlag']) for r in join_rows(s3)], [(self.cpu_b, 0, 1)], 'the new session has its row, flagged open, at the first start')

    def test_reconcile_a_primary_that_is_an_extra_part_of_a_live_session_converges(self):
        # AP-5 F1(b): 0.9.0 checks only sessions.ComponentID, so it lets another bench take an extra part of a live 0.9.1 session as its primary
        self.platform_swap()
        live = int(open_session(self.bid)['SessionID'])
        msi2, sid2, bid2, s_other = make_bench(self.admin, cls='other')
        self.zero_nine_ends(s_other)
        y = self.zero_nine_starts(bid2, self.board_b)
        uid = upload(msi2)  # an ad-hoc result, filed under Y
        self.assertEqual(int(result_row(uid)['BenchSessionID']), y)
        prepare_database()
        self.assertEqual([(r['ComponentID'], r['Position'], r['OpenFlag']) for r in join_rows(y)], [(self.board_b, 0, None)], 'Y is recorded, without the flag the live row holds')
        self.assertEqual([r['OpenFlag'] for r in join_rows(live)], [1, 1], 'the live session keeps its parts')
        audit = bench_audit(bid2, 'bench_part_double_booked')
        self.assertEqual(len(audit), 1, 'the double booking is visible in the audit log')
        detail = json.loads(audit[0]['Detail'])
        self.assertEqual((detail['session'], detail['also_in_open_session']), ('S%d' % y, 'S%d' % live))
        # converged: another start changes nothing and writes nothing
        before = (sql('SELECT * FROM phoromatic_bench_session_components ORDER BY SessionID, ComponentID'), len(sql('SELECT AuditID FROM phoromatic_admin_audit')))
        prepare_database()
        prepare_database()
        self.assertEqual((sql('SELECT * FROM phoromatic_bench_session_components ORDER BY SessionID, ComponentID'), len(sql('SELECT AuditID FROM phoromatic_admin_audit'))), before)
        # Y's result is in the class filter
        body = self.admin.get('/?benches/compare&bench=%d&class=motherboard&schedule=0' % bid2)[2]
        self.assertIn(self.board_b_label, body)


    def double_booked(self):
        """a live 0.9.1 session holding board_b as an extra part, and a session a 0.9.0 server started on another bench with board_b as
        its primary: (live session, other bench id, the 0.9.0 session)"""
        self.platform_swap()
        live = int(open_session(self.bid)['SessionID'])
        msi2, sid2, bid2, s_other = make_bench(self.admin, cls='other')
        self.zero_nine_ends(s_other)
        return live, bid2, self.zero_nine_starts(bid2, self.board_b)

    def test_double_booking_audit_names_both_sessions_and_the_one_to_end(self):
        live, bid2, y = self.double_booked()
        prepare_database()
        note = json.loads(bench_audit(bid2, 'bench_part_double_booked')[0]['Detail'])['note']
        self.assertIn('S%d' % y, note)
        self.assertIn('S%d' % live, note)
        self.assertIn('the 0.9.0 server started', note)
        self.assertNotIn('End one of them', note)

    def test_double_booking_audit_row_has_the_requests_ip(self):
        # the repair runs inside whichever web request opens the database first; the audit row says where that request came from
        live, bid2, y = self.double_booked()
        self.admin.get('/?benches/%d' % self.bid)
        audit = bench_audit(bid2, 'bench_part_double_booked')
        self.assertEqual(len(audit), 1)
        self.assertEqual((audit[0]['ActorType'], audit[0]['Actor']), ('system', 'server'))
        self.assertRegex(audit[0]['SourceIP'] or '', r'^\d+\.\d+\.\d+\.\d+$')

    def test_double_booking_is_audited_only_when_the_row_was_really_written(self):
        # a second request that saw the same orphan finds its INSERT OR IGNORE changed nothing (another request got there first). A
        # trigger that swallows the insert stands in for that request: no row, so no audit; once the insert works it is audited once.
        live, bid2, y = self.double_booked()
        self.assertEqual(sql_exec("CREATE TRIGGER qc_swallow BEFORE INSERT ON phoromatic_bench_session_components WHEN NEW.SessionID = %d BEGIN SELECT RAISE(IGNORE); END" % y), 'ok')
        try:
            prepare_database()
            self.assertEqual(join_rows(y), [])
            self.assertEqual(bench_audit(bid2, 'bench_part_double_booked'), [], 'nothing was written, nothing is audited')
        finally:
            self.assertEqual(sql_exec('DROP TRIGGER qc_swallow'), 'ok')
        prepare_database()
        prepare_database()
        self.assertEqual(len(join_rows(y)), 1)
        self.assertEqual(len(bench_audit(bid2, 'bench_part_double_booked')), 1)

    def test_ending_the_live_session_gives_its_part_to_the_0_9_0_session_that_still_has_it(self):
        # the orphan row of the 0.9.0 session has no flag while the live session holds the part. When the live one ends the part must not
        # look free: the other open session takes the flag.
        live, bid2, y = self.double_booked()
        prepare_database()
        self.assertEqual([r['OpenFlag'] for r in join_rows(y)], [None])
        act(self.admin, '/?benches/%d' % self.bid, 'end')
        self.assertIsNone(open_session(self.bid))
        self.assertEqual([r['OpenFlag'] for r in join_rows(live)], [None, None])
        self.assertEqual([(r['ComponentID'], r['OpenFlag']) for r in join_rows(y)], [(self.board_b, 1)], 'the part is still held, by Y')
        # and it can not be taken by a third session meanwhile
        cpu_c, _ = new_component(self.admin, 'cpu')
        multi_swap(self.admin, self.bid, cpu_c, [self.board_b])
        self.assertIsNone(open_session(self.bid), 'a part under test in Y can not start a new session')
        # ending Y frees it for good
        act(self.admin, '/?benches/%d' % bid2, 'end')
        self.assertEqual([r['OpenFlag'] for r in join_rows(y)], [None])

    def part_flag(self, session_id):
        return [r['OpenFlag'] for r in join_rows(session_id) if r['ComponentID'] == self.board_b][0]

    def third_open_session_with_unflagged_part(self):
        """a second open session Z, newer than the 0.9.0 session Y, with board_b as an extra part and no flag on it. Only rows written by hand
        can look like this (0.9.1 refuses a part that is under test and 0.9.0 has one primary per session); it is what the repair has to
        survive without ever flagging two rows of one part."""
        msi3, sid3, bid3, s_third = make_bench(self.admin, cls='other')
        self.zero_nine_ends(s_third)
        cpu_z, _ = new_component(self.admin, 'cpu')
        z = self.zero_nine_starts(bid3, cpu_z)
        prepare_database()
        self.assertEqual(sql_exec("INSERT INTO phoromatic_bench_session_components (AccountID, SessionID, ComponentID, Position, OpenFlag) VALUES ('%s', %d, %d, 1, NULL)" % (AID, z, self.board_b)), 'ok')
        return z, bid3

    def test_a_second_rollback_hands_the_part_to_the_session_that_still_has_it(self):
        # AP-5 (0.9.3 gate): Y is a 0.9.0 session with the part as its primary, while the live 0.9.1 session L holds it. A second 0.9.0
        # rollback ends L (its flags stay set); on the next upgrade the stale flags are cleared, and the part must go to Y, not look free.
        live, bid2, y = self.double_booked()
        prepare_database()
        self.assertEqual([r['OpenFlag'] for r in join_rows(y)], [None])
        self.zero_nine_ends(live)
        prepare_database()
        self.assertEqual([r['OpenFlag'] for r in join_rows(live)], [None, None], 'the ended session lets go of its parts')
        self.assertEqual([(r['ComponentID'], r['OpenFlag']) for r in join_rows(y)], [(self.board_b, 1)], 'the part is still under test in Y')
        # not free: a third session can not take it
        cpu_c, _ = new_component(self.admin, 'cpu')
        multi_swap(self.admin, self.bid, cpu_c, [self.board_b])
        self.assertIsNone(open_session(self.bid), 'a part under test in Y can not start a new session')
        # and another start changes nothing
        before = sql('SELECT * FROM phoromatic_bench_session_components ORDER BY SessionID, ComponentID')
        prepare_database()
        self.assertEqual(sql('SELECT * FROM phoromatic_bench_session_components ORDER BY SessionID, ComponentID'), before)

    def test_a_part_with_two_open_unflagged_rows_goes_to_the_oldest_and_only_one(self):
        # Y and Z both have the part, without a flag, while L holds it; L ends by 0.9.0 and is mended: the oldest open row (Y) gets the flag
        live, bid2, y = self.double_booked()
        z, bid3 = self.third_open_session_with_unflagged_part()
        self.zero_nine_ends(live)
        prepare_database()
        self.assertEqual((self.part_flag(y), self.part_flag(z)), (1, None), 'the oldest open row holds the part, and only one row does')

    def test_ending_a_session_does_not_flag_a_row_when_another_open_row_already_holds_the_part(self):
        # Y and Z (open sessions) both have the part unflagged while the live session L holds it. Ending Y must leave the part with L:
        # flagging Z as well would break the one-open-row-per-part index and fail the end.
        live, bid2, y = self.double_booked()
        z, bid3 = self.third_open_session_with_unflagged_part()
        act(self.admin, '/?benches/%d' % bid2, 'end')
        self.assertIsNone(open_session(bid2), 'the end went through')
        self.assertEqual([r['OpenFlag'] for r in join_rows(live)], [1, 1], 'L keeps its part')
        self.assertEqual(self.part_flag(z), None)

    def test_a_repair_that_fails_half_way_changes_nothing(self):
        # AP-5 F8: the stale flags are cleared and handed on in one transaction. With the hand-over made to fail, the cleared flags must
        # come back (nothing half done); once it can work, the next start mends it.
        live, bid2, y = self.double_booked()
        prepare_database()
        self.zero_nine_ends(live)
        before = sql('SELECT * FROM phoromatic_bench_session_components ORDER BY SessionID, ComponentID')
        self.assertEqual(sql_exec("CREATE TRIGGER qc_no_flag BEFORE UPDATE OF OpenFlag ON phoromatic_bench_session_components WHEN NEW.OpenFlag = 1 BEGIN SELECT RAISE(ABORT, 'qc'); END"), 'ok')
        try:
            prepare_database()
            self.assertEqual(sql('SELECT * FROM phoromatic_bench_session_components ORDER BY SessionID, ComponentID'), before, 'rolled back as a whole')
        finally:
            self.assertEqual(sql_exec('DROP TRIGGER qc_no_flag'), 'ok')
        prepare_database()
        self.assertEqual([(r['ComponentID'], r['OpenFlag']) for r in join_rows(y)], [(self.board_b, 1)])
        self.assertEqual([r['OpenFlag'] for r in join_rows(live)], [None, None])


if __name__ == '__main__':
    unittest.main()
