#!/usr/bin/env python3
"""Upload a zip through the Phoromatic web page the way a browser would: log in, stage, read the review page, confirm.

    upload_via_ui.py FILE.zip [--name NAME] [--overwrite] [--desc TEXT]

Environment: QC_PORT, QC_USER, QC_PASS (set by run.sh). Prints "RESULT: <message shown by the page>" and exits 0 only if the page
reported that the package was added.
"""
import http.cookiejar
import os
import re
import sys
import urllib.error
import urllib.parse
import urllib.request
import uuid

BASE = 'http://127.0.0.1:%s' % os.environ['QC_PORT']


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k):
        return None


opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()), NoRedirect)


def request(path, data=None, headers=None):
    req = urllib.request.Request(BASE + path, data=data, headers=headers or {})
    try:
        r = opener.open(req, timeout=120)
    except urllib.error.HTTPError as e:
        r = e
    return r.headers, r.read().decode('utf-8', 'replace')


def token(path):
    return re.search(r'name="token_submit" value="([0-9a-f]+)"', request(path)[1]).group(1)


def flash():
    m = re.search(r'<p class="pr-flash pr-(?:ok|error)"[^>]*>(.*?)</p>', request('/?private_repository')[1], re.S)
    return re.sub(r'<[^>]+>', '', m.group(1)) if m else ''


def main(argv):
    path = argv[1]
    name = argv[argv.index('--name') + 1] if '--name' in argv else ''
    desc = argv[argv.index('--desc') + 1] if '--desc' in argv else 'uploaded by the e2e test'
    overwrite = '--overwrite' in argv
    request('/')
    request('/?login', urllib.parse.urlencode({'username': os.environ['QC_USER'], 'password': os.environ['QC_PASS']}).encode())

    boundary = '----e2e' + uuid.uuid4().hex
    fields = {'action': 'stage', 'name': name, 'commit_description': desc, 'token_submit': token('/?private_repository/upload')}
    body = b''.join(('--%s\r\nContent-Disposition: form-data; name="%s"\r\n\r\n%s\r\n' % (boundary, k, v)).encode() for k, v in fields.items())
    body += ('--%s\r\nContent-Disposition: form-data; name="zip"; filename="%s"\r\nContent-Type: application/zip\r\n\r\n' % (boundary, os.path.basename(path))).encode()
    body += open(path, 'rb').read() + ('\r\n--%s--\r\n' % boundary).encode()
    headers, _ = request('/?r_private_repository_action', body, {'Content-Type': 'multipart/form-data; boundary=' + boundary})
    m = re.search(r'id=([0-9a-f]{32})', headers.get('Location', ''))
    if not m:
        print('RESULT: not staged: ' + flash())
        return 1
    stage_id = m.group(1)
    review = request('/?private_repository/review&id=' + stage_id)[1]
    sha = re.search(r'name="sha256" value="([0-9a-f]{64})"', review).group(1)
    confirm = {'action': 'confirm', 'stage_id': stage_id, 'sha256': sha, 'reviewed': '1', 'token_submit': token('/?private_repository/review&id=' + stage_id)}
    if overwrite:
        confirm['overwrite'] = '1'
    request('/?r_private_repository_action', urllib.parse.urlencode(confirm).encode())
    result = flash()
    print('RESULT: ' + result)
    return 0 if result.startswith('Added ') else 1


if __name__ == '__main__':
    sys.exit(main(sys.argv))
