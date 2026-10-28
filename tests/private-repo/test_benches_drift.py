"""Test benches 2B (Benchy 0.8): the drift guard. Only the class under test holds tests (R12.3); other tracked parts give a notice, one
audit row per episode, and mark results (BenchDeviation). A declared swap holds until the new part is detected, with the "Use current
hardware" escape. Detection is compared on the request's own 'pp' (with the 'h' string as the fallback).

Run through tests/private-repo/run.sh (part of http/all).
"""
import json
import unittest
import uuid

from bench_helpers import (BOARD, CPU_A, CPU_B, GPU_A, GPU_B, act, baseline, bench_audit, drift, make_bench, open_session, pp, result_row, schedule,
                           drop_schedule, sessions, start, swap, system_row, today, upload)
from test_private_repo_http import AID, sql
from test_private_repo_tokens import sql_exec
from test_private_repo_web import Browser, WebBase


def machine_rows(bid, action):
    return [r for r in bench_audit(bid, action) if r['ActorType'] == 'machine']


class DriftBase(WebBase):
    def setUp(self):
        self.msi, self.sid, self.bid, self.s1 = make_bench(self.admin, cls='cpu')
        self.sched = schedule(self.sid)

    def tearDown(self):
        drop_schedule(self.sched)


class TestClassHold(DriftBase):
    def test_cpu_change_on_cpu_bench_holds_once_and_ack_moves_baseline(self):
        r = start(self.msi, cpu=CPU_B)
        self.assertEqual(r.get('task'), 'idle')
        self.assertIn('Hardware changed: CPU %s -> %s' % (CPU_A, CPU_B), r['response'])
        d = drift(self.sid)
        self.assertEqual(d['kind'], 'drift')
        self.assertEqual([(f['key'], f['holds']) for f in d['fields']], [('cpu', True)])
        self.assertIsNone(system_row(self.sid)['BenchTaskSession'])
        for _ in range(3):
            self.assertEqual(start(self.msi, cpu=CPU_B).get('task'), 'idle')
        self.assertEqual(len(machine_rows(self.bid, 'bench_drift_detected')), 1, 'one audit row per episode, not per check-in')
        page = self.admin.get('/?benches/%d' % self.bid)[2]
        self.assertIn('tests are on hold', page)
        self.assertIn(CPU_B, page)
        self.assertIn('CPU changed', self.admin.get('/?systems')[2])
        # "Same hardware, continue" needs the tick
        self.assertIn('Tick the confirmation', act(self.admin, '/?benches/%d/ack/%s' % (self.bid, self.sid), 'ack', expect_kind='drift'))
        act(self.admin, '/?benches/%d/ack/%s' % (self.bid, self.sid), 'ack', expect_kind='drift', confirm_checked='1', note='bios update')
        self.assertIsNone(drift(self.sid))
        b = baseline(self.s1, self.sid)
        self.assertEqual(json.loads(b['BaselineJSON'])['cpu']['model'], CPU_B)
        self.assertEqual(json.loads(b['StartJSON'])['cpu']['model'], CPU_A, 'the start record is not rewritten')
        ack = bench_audit(self.bid, 'bench_drift_ack')[-1]
        self.assertEqual(json.loads(ack['Detail'])['baseline_before']['cpu'], CPU_A)
        self.assertEqual(start(self.msi, cpu=CPU_B).get('task'), 'benchmark')

    def test_putting_the_part_back_auto_clears(self):
        start(self.msi, cpu=CPU_B)
        self.assertEqual(drift(self.sid)['kind'], 'drift')
        r = start(self.msi, cpu=CPU_A)
        self.assertEqual(r.get('task'), 'benchmark')
        self.assertIsNone(drift(self.sid))
        self.assertEqual(len(machine_rows(self.bid, 'bench_drift_cleared')), 1)

    def test_noise_does_not_trip(self):
        variants = [
            pp(cpu=CPU_A + ' @ 5.40GHz (8 Cores / 16 Threads)'),       # clock and core-count suffixes
            pp(cpu=CPU_A.upper()),                                        # case
            pp(board=BOARD + ' (2001 BIOS)'),                             # BIOS update
            pp(mem=int(32768 * 1.03)),                                    # memory within 5%
            pp(gpu=None),                                                 # undetected GPU
            pp(gpu=''),                                                   # empty GPU
            pp(cpu=None, gpu=None, board=None, mem=None),                 # detection failed entirely
        ]
        for v in variants:
            r = start(self.msi, pp_json=v)
            self.assertEqual(r.get('task'), 'benchmark', 'tripped on %s: %s' % (v, r.get('response')))
            self.assertIsNone(drift(self.sid), v)

    def test_h_only_fallback_client(self):
        h = 'Processor: %s @ 5.27GHz (8 Cores / 16 Threads), Motherboard: %s, Memory: 2 x 16GB DDR5-6000MT/s, Graphics: %s' % (CPU_A, BOARD, GPU_A)
        self.assertEqual(start(self.msi, pp_json=None, h=h).get('task'), 'benchmark')
        r = start(self.msi, pp_json=None, h=h.replace(CPU_A, CPU_B))
        self.assertEqual(r.get('task'), 'idle')
        self.assertEqual(drift(self.sid)['kind'], 'drift')


class TestAckAcceptsOnlyTheClass(DriftBase):
    """M3: "Same hardware" and "Use current hardware" accept the part under test only; a constant part that changed stays a notice"""

    def test_same_hardware_keeps_a_gpu_change_as_notice(self):
        start(self.msi, cpu=CPU_B, gpu=GPU_B)
        self.assertEqual(drift(self.sid)['kind'], 'drift')
        act(self.admin, '/?benches/%d/ack/%s' % (self.bid, self.sid), 'ack', expect_kind='drift', confirm_checked='1')
        base = json.loads(baseline(self.s1, self.sid)['BaselineJSON'])
        self.assertEqual((base['cpu']['model'], base['gpu'][0]['model']), (CPU_B, GPU_A))
        d = drift(self.sid)
        self.assertEqual((d['kind'], [f['key'] for f in d['fields']]), ('notice', ['gpu']))
        self.assertEqual(json.loads(bench_audit(self.bid, 'bench_drift_ack')[-1]['Detail'])['notice_after'][0]['key'], 'gpu')
        self.assertEqual(start(self.msi, cpu=CPU_B, gpu=GPU_B).get('task'), 'benchmark')
        uid = upload(self.msi, sched=self.sched, ts=today())
        self.assertEqual(json.loads(result_row(uid)['BenchDeviation'])[0]['key'], 'gpu')

    def test_use_current_hardware_keeps_a_gpu_change_as_notice(self):
        swap(self.admin, self.bid, cls='cpu', label='qc same %s' % uuid.uuid4().hex[:4])
        s2 = open_session(self.bid)['SessionID']
        start(self.msi, cpu=CPU_A, gpu=GPU_B)
        self.assertEqual(drift(self.sid)['kind'], 'awaiting')
        act(self.admin, '/?benches/%d/ack/%s' % (self.bid, self.sid), 'ack', expect_kind='awaiting', confirm_checked='1')
        base = json.loads(baseline(s2, self.sid)['BaselineJSON'])
        self.assertEqual(base['gpu'][0]['model'], GPU_A)
        self.assertEqual(drift(self.sid)['kind'], 'notice')
        self.assertEqual(len(machine_rows(self.bid, 'bench_constant_changed')), 1)

    def test_a_part_undetected_at_baseline_is_filled_in_later(self):
        msi, sid, bid, s1 = make_bench(self.admin, cls='cpu', gpu=None)
        self.assertEqual(json.loads(baseline(s1, sid)['BaselineJSON'])['gpu'], [])
        self.assertEqual(start(msi, gpu=GPU_A).get('task'), 'idle')  # no schedule here: idle, but the guard ran
        self.assertIsNone(drift(sid), 'filling an unknown part is not a change')
        self.assertEqual(json.loads(baseline(s1, sid)['BaselineJSON'])['gpu'][0]['model'], GPU_A)
        start(msi, gpu=GPU_B)
        self.assertEqual(drift(sid)['kind'], 'notice', 'a later GPU change is noticed')

    def test_awaiting_holds_even_when_nothing_is_detected(self):
        swap(self.admin, self.bid, cls='cpu', label='qc nodetect %s' % uuid.uuid4().hex[:4])
        sql_exec("UPDATE phoromatic_systems SET BenchDrift=NULL WHERE SystemID='%s'" % self.sid)
        r = start(self.msi, pp_json=None)
        self.assertEqual(r.get('task'), 'idle', 'an awaiting swap let tests run when detection failed')
        self.assertIn('waiting for the swap', r['response'])


class TestConstantPartNotice(DriftBase):
    def test_gpu_change_on_cpu_bench_is_a_notice_and_marks_results(self):
        r = start(self.msi, gpu=GPU_B)
        self.assertEqual(r.get('task'), 'benchmark', 'a constant part must not hold tests')
        d = drift(self.sid)
        self.assertEqual(d['kind'], 'notice')
        self.assertEqual([(f['key'], f['holds']) for f in d['fields']], [('gpu', False)])
        start(self.msi, gpu=GPU_B)
        self.assertEqual(len(machine_rows(self.bid, 'bench_constant_changed')), 1)
        uid = upload(self.msi, sched=self.sched, ts=today())
        dev = json.loads(result_row(uid)['BenchDeviation'])
        self.assertEqual(dev, [{'key': 'gpu', 'baseline': GPU_A, 'detected': GPU_B}])
        self.assertIn('Constant part changed', self.admin.get('/?benches/%d' % self.bid)[2])
        # "Update baseline" moves only the GPU forward and edits the constant parts text in the same form
        act(self.admin, '/?benches/%d/ack/%s' % (self.bid, self.sid), 'ack', expect_kind='notice', config='RX 9070 XT now')
        self.assertIsNone(drift(self.sid))
        base = json.loads(baseline(self.s1, self.sid)['BaselineJSON'])
        self.assertEqual((base['gpu'][0]['model'], base['cpu']['model']), (GPU_B, CPU_A))
        self.assertEqual(open_session(self.bid)['Config'], 'RX 9070 XT now')
        sql_exec("UPDATE phoromatic_schedules SET State=0 WHERE ScheduleID=%d" % self.sched)
        self.assertIsNone(result_row(upload(self.msi))['BenchDeviation'])
        # a CPU change still holds
        self.assertEqual(start(self.msi, cpu=CPU_B, gpu=GPU_B).get('task'), 'idle')
        self.assertEqual(drift(self.sid)['kind'], 'drift')

    def test_notice_restored_and_cleared_by_new_session(self):
        start(self.msi, gpu=GPU_B)
        start(self.msi, gpu=GPU_A)
        self.assertIsNone(drift(self.sid))
        self.assertEqual(len(machine_rows(self.bid, 'bench_constant_restored')), 1)
        start(self.msi, gpu=GPU_B)
        self.assertEqual(drift(self.sid)['kind'], 'notice')
        sql_exec("UPDATE phoromatic_systems SET BenchTaskSession=NULL WHERE SystemID='%s'" % self.sid)  # the run finished
        self.assertIn('started with', swap(self.admin, self.bid, cls='psu'))
        self.assertIsNone(drift(self.sid), 'starting a new session clears the notice')

    def test_hold_text_never_leaks_other_data(self):
        r = start(self.msi, cpu=CPU_B)
        text = r['response']
        other_titles = [x['Title'] for x in sql("SELECT Title FROM phoromatic_benches WHERE BenchID <> %d" % self.bid)]
        for t in other_titles:
            if t not in (sql("SELECT Title FROM phoromatic_benches WHERE BenchID=%d" % self.bid)[0]['Title'],):
                self.assertNotIn(t, text)
        self.assertNotIn(AID, text)


class TestAwaitingSwap(DriftBase):
    def test_declare_first_holds_until_the_new_part_is_detected(self):
        swap(self.admin, self.bid, cls='cpu', label='qc 9950X %s' % uuid.uuid4().hex[:4])
        s2 = open_session(self.bid)['SessionID']
        self.assertEqual(drift(self.sid)['kind'], 'awaiting')
        self.assertEqual(sql("SELECT COUNT(*) AS n FROM phoromatic_systems WHERE SystemID='%s'" % self.sid)[0]['n'], 1)
        r = start(self.msi, cpu=CPU_A)
        self.assertEqual(r.get('task'), 'idle')
        self.assertIn('waiting for the swap', r['response'])
        self.assertIn('Waiting for the swap', self.admin.get('/?benches/%d' % self.bid)[2])
        # power down, swap, boot: tests start by themselves; the start record is the new hardware
        r = start(self.msi, cpu=CPU_B)
        self.assertEqual(r.get('task'), 'benchmark', r.get('response'))
        self.assertEqual(r['bench']['session'], s2)
        b = baseline(s2, self.sid)
        self.assertEqual(json.loads(b['StartJSON'])['cpu']['model'], CPU_B)
        self.assertIsNone(b['AwaitingFromJSON'])
        self.assertIsNone(drift(self.sid))

    def test_swap_first_then_declare_runs_immediately(self):
        # the part was changed and the bench checked in (its stored properties show the new CPU) before the swap is declared;
        # the declaration still waits for a CPU other than the previous baseline, which this check-in already reports
        swap(self.admin, self.bid, cls='cpu', label='qc 9950X %s' % uuid.uuid4().hex[:4])
        self.assertEqual(start(self.msi, cpu=CPU_B).get('task'), 'benchmark')

    def test_constant_part_changed_during_awaiting_becomes_a_notice(self):
        swap(self.admin, self.bid, cls='cpu', label='qc 9950X %s' % uuid.uuid4().hex[:4])
        r = start(self.msi, cpu=CPU_B, gpu=GPU_B)
        self.assertEqual(r.get('task'), 'benchmark')
        d = drift(self.sid)
        self.assertEqual(d['kind'], 'notice')
        self.assertEqual([f['key'] for f in d['fields']], ['gpu'])

    def test_use_current_hardware_escape(self):
        swap(self.admin, self.bid, cls='cpu', label='qc same model %s' % uuid.uuid4().hex[:4])
        s2 = open_session(self.bid)['SessionID']
        start(self.msi, cpu=CPU_A)
        act(self.admin, '/?benches/%d/ack/%s' % (self.bid, self.sid), 'ack', expect_kind='awaiting', confirm_checked='1')
        self.assertIsNone(drift(self.sid))
        self.assertEqual(json.loads(baseline(s2, self.sid)['BaselineJSON'])['cpu']['model'], CPU_A)
        self.assertEqual(start(self.msi, cpu=CPU_A).get('task'), 'benchmark')

    def test_stale_ack_form_changes_nothing(self):
        start(self.msi, gpu=GPU_B)
        self.assertIn('state changed', act(self.admin, '/?benches/%d/ack/%s' % (self.bid, self.sid), 'ack', expect_kind='drift', confirm_checked='1'))
        self.assertEqual(drift(self.sid)['kind'], 'notice')

    def test_viewer_cannot_acknowledge(self):
        start(self.msi, cpu=CPU_B)
        viewer = self.as_user(10)
        self.assertIn('Only account administrators', act(viewer, '/?benches/%d/ack/%s' % (self.bid, self.sid), 'ack', tok=None, expect_kind='drift', confirm_checked='1'))
        self.assertEqual(drift(self.sid)['kind'], 'drift')
        self.assertIn('tests are on hold', viewer.get('/?benches/%d' % self.bid)[2], 'viewers see the hold')


if __name__ == '__main__':
    unittest.main()
