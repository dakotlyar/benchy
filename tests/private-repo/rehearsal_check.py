"""Seinar-copy rehearsal, one phase per call, driven by rehearsal.sh. Runs against a WORKING copy of the copy of seinar's database
(rehearsal.sh never lets a server touch the copy itself).

  pages <label>    log in as the rehearsal administrator that rehearsal.sh added to the working copy, load the main pages and the first few
                   systems, schedules and results, and fail on any PHP warning, notice or fatal error in a page or in the server log
  counts <name>    save the row counts of every table that existed before the migration (in the state file)
  same <name>      fail if any of those counts changed
  version <n>      fail unless user_version is n; also runs integrity_check
"""
import json
import os
import sys

os.environ.setdefault('RB_STATE', os.environ['RH_STATE'])
from rollback_check import check, failures, pages, server_log_clean, version  # noqa: E402
from test_private_repo_http import sql  # noqa: E402

STATE = os.environ['RH_STATE']


def load():
    return json.load(open(STATE)) if os.path.exists(STATE) else {}


def save(**kw):
    s = load()
    s.update(kw)
    json.dump(s, open(STATE, 'w'))


def table_counts(only=None):
    out = {}
    for t in sql("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name"):
        if only is None or t['name'] in only:
            out[t['name']] = sql('SELECT COUNT(*) AS n FROM "%s"' % t['name'])[0]['n']
    return out


def phase_pages(label):
    systems = [r['SystemID'] for r in sql('SELECT SystemID FROM phoromatic_systems ORDER BY LastCommunication DESC LIMIT 6')]
    scheds = [r['ScheduleID'] for r in sql('SELECT ScheduleID FROM phoromatic_schedules ORDER BY ScheduleID DESC LIMIT 4')]
    results = [r['PPRID'] for r in sql('SELECT PPRID FROM phoromatic_results ORDER BY UploadTime DESC LIMIT 5')]
    paths = ['/', '/?systems', '/?schedules', '/?results', '/?benches', '/?settings', '/?users']
    paths += ['/?systems/%s' % s for s in systems] + ['/?schedules/%s' % s for s in scheds] + ['/?result/%s' % r for r in results]
    if len(results) >= 2:
        paths.append('/?result/%s,%s' % (results[0], results[1]))
    paths += ['/?results/system/%s' % s for s in systems[:2]]
    print('  (%d systems, %d schedules, %d results sampled)' % (len(systems), len(scheds), len(results)))
    pages(paths)
    server_log_clean(label)


def phase_counts(name):
    save(**{'counts_' + name: table_counts()})
    print('  counted %d tables' % len(load()['counts_' + name]))


def phase_same(name):
    before = load()['counts_' + name]
    now = table_counts(set(before))
    diff = {t: (before[t], now.get(t)) for t in before if before[t] != now.get(t)}
    check(not diff, 'no table that existed before the migration changed its row count %s' % diff)


def phase_version(n):
    check(version() == int(n), 'user_version is %s (%s)' % (n, version()))
    ic = sql('PRAGMA integrity_check')
    check([r['integrity_check'] for r in ic] == ['ok'], 'integrity_check says ok')


if __name__ == '__main__':
    globals()['phase_' + sys.argv[1]](*sys.argv[2:])
    print('FAILURES %d' % len(failures))
    sys.exit(1 if failures else 0)
