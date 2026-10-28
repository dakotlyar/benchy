"""Drives the bench pages through the web UI the way Dave would (used by e2e.sh, section 13).

usage: bench_via_ui.py promote <SystemID> <label>     prints 'OK bench <BenchID>'
       bench_via_ui.py swap <BenchID> <label>         prints 'OK swapped'
       bench_via_ui.py view <pprid>[,<pprid>...]      prints the result page
Needs QC_PORT, QC_USER, QC_PASS (set by run.sh). The bench class is 'other' (not detectable), so a swap takes effect at the next task.
"""
import http.cookiejar
import os
import re
import sys
import urllib.parse
import urllib.request

base = 'http://127.0.0.1:%s/' % os.environ['QC_PORT']
jar = http.cookiejar.CookieJar()
op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))


def get(q):
    return op.open(base + '?' + q, timeout=60).read().decode('utf-8', 'replace')


def post(q, fields):
    return op.open(base + '?' + q, data=urllib.parse.urlencode(fields).encode(), timeout=60).read().decode('utf-8', 'replace')


get('')
post('login', {'username': os.environ['QC_USER'], 'password': os.environ['QC_PASS']})
cmd, arg = sys.argv[1], sys.argv[2]
if cmd == 'promote':
    page = get('benches/promote/' + arg)
    token = re.search(r'name="token_submit" value="([^"]+)"', page).group(1)
    post('benches/promote/' + arg, {'token_submit': token, 'bench_action': 'promote', 'title': 'e2e bench', 'source': 'new', 'class': 'other', 'label': sys.argv[3]})
    m = re.search(r'\?benches/(\d+)', get('systems/' + arg))
    print('OK bench %s' % m.group(1) if m else 'FAIL promote')
elif cmd == 'swap':
    page = get('benches/%s/swap' % arg)
    token = re.search(r'name="token_submit" value="([^"]+)"', page).group(1)
    out = post('benches/' + arg, {'token_submit': token, 'bench_action': 'swap', 'source': 'new', 'class': 'other', 'label': sys.argv[3]})
    print('OK swapped' if 'started with' in out else 'FAIL swap: ' + re.sub(r'\s+', ' ', re.sub(r'<[^>]+>', ' ', out))[:300])
elif cmd == 'view':
    print(get('result/' + arg))
