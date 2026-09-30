"""Creates a Phoromatic test schedule through the web UI, the way Dave would, containing a private test.

usage: schedule_via_ui.py private/hello-bench
Prints 'OK listed' if the add-test list offered the private test, 'OK schedule <id>' once it was added and a
run-now was requested. Needs QC_PORT, QC_USER, QC_PASS (set by run.sh).
"""
import http.cookiejar
import os
import re
import sys
import urllib.parse
import urllib.request

base = 'http://127.0.0.1:%s/' % os.environ['QC_PORT']
test = sys.argv[1]
jar = http.cookiejar.CookieJar()
op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))


def get(q):
    return op.open(base + '?' + q, timeout=60).read().decode('utf-8', 'replace')


def post(q, fields):
    return op.open(base + '?' + q, data=urllib.parse.urlencode(fields, doseq=True).encode(), timeout=60).read().decode('utf-8', 'replace')


get('')
post('login', {'username': os.environ['QC_USER'], 'password': os.environ['QC_PASS']})
page = get('sched')
systems = re.findall(r'name="run_on_systems\[\]" value="(\w+)"', page)
title = 'qc-private-' + os.urandom(3).hex()
post('sched', {'schedule_title': title, 'schedule_description': 'qc', 'run_on_systems[]': systems,
               'schedule_hour': '3', 'schedule_minute': '0', 'days_active[]': ['0', '1', '2', '3', '4', '5', '6'], 'run_priority': '100'})
m = re.search(r'\?schedules/(\d+)"[^>]*>\s*' + re.escape(title), get('schedules')) or re.search(r'schedules/(\d+)[^<]*</a>[^<]*' + re.escape(title), get('schedules'))
if not m:
    # fall back to scanning for the id next to the title
    m = re.search(r'schedules/(\d+)[^>]*>[^<]*' + re.escape(title), get('schedules'))
if not m:
    print('FAIL could not find the new schedule in the schedules list')
    sys.exit(1)
sid = m.group(1)
page = get('schedules/' + sid)
token = re.search(r'name="token_submit" value="([^"]+)"', page).group(1)
if re.search(r'<option value="%s' % re.escape(test), page):
    print('OK listed')
else:
    print('FAIL %s missing from the add-test list' % test)
post('schedules/' + sid, {'token_submit': token, 'add_to_schedule_select_test': test})
if test not in get('schedules/' + sid):
    print('FAIL test not added to schedule')
    sys.exit(1)
post('schedules/' + sid, {'token_submit': token, 'do_manual_test_run': '1'})
print('OK schedule', sid)
