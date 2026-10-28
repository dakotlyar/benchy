"""Settings page checks for the no-phone-home tests (nophone.sh): the account setting UploadResultsToOpenBenchmarking is hidden
while the server's client_phone_home policy is forbidden, and saving the page then leaves its stored value alone.

usage: nophone_web.py show   prints 'SHOWN' or 'HIDDEN'
       nophone_web.py save   re-submits the Account Settings form exactly as displayed, prints 'SAVED'
Needs QC_PORT, QC_USER, QC_PASS (set by run.sh).
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
    return op.open(base + '?' + q, data=urllib.parse.urlencode(fields, doseq=True).encode(), timeout=60).read().decode('utf-8', 'replace')


get('')
post('login', {'username': os.environ['QC_USER'], 'password': os.environ['QC_PASS']})
page = get('settings')
if '<h2>Account Settings</h2>' not in page:
    print('FAIL not logged in or no Account Settings section')
    sys.exit(1)
account = page[page.index('<h2>Account Settings</h2>'):]
account = account[:account.index('</form>')]

if sys.argv[1] == 'show':
    print('SHOWN' if 'name="UploadResultsToOpenBenchmarking"' in account else 'HIDDEN')
elif sys.argv[1] == 'save':
    fields = {name: 'yes' for name in re.findall(r'<input type="checkbox" name="(\w+)" checked="checked"', account)}
    fields['account_settings_update'] = '1'
    post('settings', fields)
    print('SAVED')
