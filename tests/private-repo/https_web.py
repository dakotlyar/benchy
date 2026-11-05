"""Web-side checks of the built-in HTTPS (certificate upload form, Secure cookie, guarded POSTs). Run inside a client container (or the
server container, for the localhost cases) by tests/private-repo/https.sh, which prints the PASS/FAIL lines it collects:

    https_web.py <scenario>      environment: H_BASE (e.g. https://host:39443), H_CAFILE (optional), H_USER, H_PASS, H_FIX (fixture dir),
                                 H_FP (expected SHA-256 for scenarios that check one), H_HOST (the Host the server knows)
Scenarios: plain_off, local_off, bootstrap, web, upload_pem, upload_pfx, refusals.
"""
import http.cookiejar
import io
import os
import re
import ssl
import sys
import urllib.error
import urllib.parse
import urllib.request
import uuid

BASE = os.environ['H_BASE'].rstrip('/')
FIX = os.environ.get('H_FIX', '/share/fx')
USER = os.environ.get('H_USER', 'qcuser1')
PASS = os.environ.get('H_PASS', 'qcpass123')
PASSES = FAILS = 0


def check(desc, ok, detail=''):
    global PASSES, FAILS
    if ok:
        PASSES += 1
        print('  PASS  ' + desc)
    else:
        FAILS += 1
        print('  FAIL  ' + desc)
        if detail:
            print('        | ' + str(detail)[:600].replace('\n', ' '))


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k):
        return None


def ssl_context():
    if not BASE.startswith('https'):
        return None
    ca = os.environ.get('H_CAFILE')
    ctx = ssl.create_default_context(cafile=ca) if ca else ssl.create_default_context()
    return ctx


class Browser:
    def __init__(self):
        self.jar = http.cookiejar.CookieJar()
        handlers = [urllib.request.HTTPCookieProcessor(self.jar), NoRedirect]
        ctx = ssl_context()
        if ctx is not None:
            handlers.append(urllib.request.HTTPSHandler(context=ctx))
        self.opener = urllib.request.build_opener(*handlers)
        self.last_headers = None

    def request(self, path, data=None, headers=None, files=None):
        headers = dict(headers or {})
        body = None
        if files is not None:
            boundary = uuid.uuid4().hex
            parts = []
            for k, v in (data or {}).items():
                parts.append(('--%s\r\nContent-Disposition: form-data; name="%s"\r\n\r\n%s\r\n' % (boundary, k, v)).encode())
            for k, (name, content) in files.items():
                parts.append(('--%s\r\nContent-Disposition: form-data; name="%s"; filename="%s"\r\nContent-Type: application/octet-stream\r\n\r\n' % (boundary, k, name)).encode() + content + b'\r\n')
            parts.append(('--%s--\r\n' % boundary).encode())
            body = b''.join(parts)
            headers['Content-Type'] = 'multipart/form-data; boundary=' + boundary
        elif data is not None:
            body = urllib.parse.urlencode(data).encode()
        req = urllib.request.Request(BASE + path, data=body, headers=headers)
        try:
            r = self.opener.open(req, timeout=60)
        except urllib.error.HTTPError as e:
            r = e
        self.last_headers = r.headers
        return r.status if hasattr(r, 'status') else r.code, r.read().decode('utf-8', 'replace')

    def get(self, path, **kw):
        return self.request(path, **kw)

    def post(self, path, data, **kw):
        return self.request(path, data, **kw)

    def login(self, user=None, password=None):
        self.get('/')
        status, body = self.post('/?login', {'username': user or USER, 'password': password or PASS})
        assert 'Log-In Successful' in body, 'login failed: ' + body[:200]
        return self

    def token(self, path='/?settings'):
        m = re.search(r'name="token_submit" value="([0-9a-f]+)"', self.get(path)[1])
        assert m, 'no CSRF token on ' + path
        return m.group(1)


def rd(name):
    with open(os.path.join(FIX, name), 'rb') as f:
        return f.read()


def stage(b, files, token=None, names=None, extra=None, headers=None, page='/?settings'):
    data = {'https_action': 'stage'}
    if token is None:
        token = b.token(page)
    if token:
        data['token_submit'] = token
    if names:
        data['names'] = names
    data.update(extra or {})
    return b.request(page, data, headers=headers, files=files)[1]


def staged_ok(body):
    return 'Check this certificate' in body


def confirm(b, page='/?settings'):
    return b.post(page, {'https_action': 'confirm', 'token_submit': b.token(page)})[1]


def make_user(admin, username, level):
    admin.post('/?users', {'username': username, 'password': 'qcpass123', 'confirm_password': 'qcpass123', 'email': username + '@example.com',
                           'admin_level': str(level), 'token_submit': admin.token('/?users')})


def scenario_plain_off():
    b = Browser().login()
    page = b.get('/?settings')[1]
    check('plain HTTP: the settings page offers "Turn on HTTPS with the built-in CA"', 'Turn on HTTPS with the built-in CA' in page)
    check('plain HTTP: the upload form is disabled with the HTTPS message', "Upload needs HTTPS so the private key isn't sent in plain text" in page.replace('&#039;', "'") and 'name="cert_file"' not in page)
    body = stage(b, {'cert_file': ('c.pem', rd('leaf-chain.crt')), 'key_file': ('k.pem', rd('leaf.key'))})
    check('plain HTTP: the upload POST is refused even with a valid token', 'only accepted over HTTPS' in body and not staged_ok(body), body[-400:])


def scenario_local_off():
    b = Browser().login()
    page = b.get('/?settings')[1]
    check('from localhost over plain HTTP the upload form is enabled', 'name="cert_file"' in page)
    body = stage(b, {'cert_file': ('c.pem', rd('leaf-chain.crt')), 'key_file': ('k.pem', rd('leaf.key'))})
    fp = os.environ.get('H_FP', '').upper()
    shown = body.replace('<code>', '').replace('</code>', '')
    check('from localhost over plain HTTP the upload is accepted and the confirm step shows subject, issuer, expiry and the fingerprint', staged_ok(body) and 'Issuer' in body and 'Expires' in body and fp and ':'.join(fp[i:i + 2] for i in range(0, 64, 2)) in shown, body[-500:])
    cancel = b.post('/?settings', {'https_action': 'cancel', 'token_submit': b.token()})[1]
    check('cancelling the staged certificate changes nothing', 'Cancelled' in cancel)


def scenario_bootstrap():
    b = Browser().login()
    body = b.post('/?settings', {'https_action': 'bootstrap', 'token_submit': b.token()})[1]
    check('the bootstrap button saves the built-in CA mode and asks for a restart', 'Restart the Benchy server service' in body and 'Saved: HTTPS with the built-in CA' in body, body[-500:])
    body = b.post('/?settings', {'https_action': 'bootstrap'})[1]
    check('the bootstrap button without a token changes nothing', 'expired or was not submitted from this site' in body)


def scenario_web():
    host = os.environ['H_HOST']
    b = Browser()
    b.get('/')
    b.post('/?login', {'username': USER, 'password': PASS})
    sessions = [c for c in b.jar if c.name == 'PHPSESSID']
    check('web login over HTTPS sets a Secure session cookie', bool(sessions) and all(c.secure for c in sessions), [(c.name, c.secure) for c in b.jar])
    tok = b.token('/?settings')
    origin = {'Origin': 'https://%s:%s' % (host, BASE.rsplit(':', 1)[1])}
    body = b.post('/?settings', {'client_phone_home': 'client_choice', 'token_submit': tok}, headers=origin)[1]
    check('a guarded POST over HTTPS (Origin + token) succeeds', 'Saved. Clients pick up' in body, body[-300:])
    body = b.post('/?settings', {'client_phone_home': 'forbidden', 'token_submit': tok}, headers={'Origin': 'https://evil.example'})[1]
    check('the same POST with a foreign Origin is refused', 'expired or was not submitted from this site' in body)
    b.post('/?settings', {'client_phone_home': 'forbidden', 'token_submit': tok}, headers=origin)
    page = b.get('/?settings')[1]
    check('the settings page over HTTPS offers the upload form', 'name="cert_file"' in page and 'name="pfx_file"' in page)


def scenario_pairing_host():
    # The pairing string carries the host the admin browsed with; a host the certificate does not cover gets a warning with the command to add it
    host = os.environ['H_HOST']
    port = BASE.rsplit(':', 1)[1]
    b = Browser().login()

    def code_box(use_host):
        hdr = {'Host': '%s:%s' % (use_host, port), 'Origin': 'https://%s:%s' % (use_host, port)}
        m = re.search(r'name="token_submit" value="([0-9a-f]+)"', b.get('/?systems', headers={'Host': hdr['Host']})[1])
        body = b.post('/?systems', {'native_enroll': '1', 'native_label': 'qc pairing host', 'token_submit': m.group(1)}, headers=hdr)[1]
        return re.search(r'<textarea id="pairing_string"[^>]*>([^<]*)</textarea>', body).group(1), body

    pairing, body = code_box(host)
    check('pairing: a covered host goes into the string as it is', pairing.startswith('benchy-enroll:https://%s:%s?code=ben_' % (host, port)), pairing[:80])
    check('pairing: a covered host gets no certificate warning', 'does not cover' not in body and 'certificate covers' not in body)
    pairing, body = code_box('other-name.example')
    check('pairing: the browsed host is used even when the certificate does not cover it', pairing.startswith('benchy-enroll:https://other-name.example:%s?code=ben_' % port), pairing[:80])
    check('pairing: an uncovered host is warned about, with the command that adds it', 'is not one of the names this server' in body and 'server-https-set internal --names %s,' % host in body and body.count('other-name.example') >= 3, body[body.find('<h2>Enrollment') : body.find('<h2>Enrollment') + 200])
    m = re.search(r'<code>(benchy server-https-set internal --names [^<]*)</code>', body)
    check('pairing: the command keeps every configured name and ends with the new one', bool(m) and m.group(1).endswith(',other-name.example') and host in m.group(1), m and m.group(1))
    pairing, body = code_box('127.0.0.1')
    check('pairing: a covered IP address is fine too', 'is not one of the names this server' not in body)


def fingerprint_of(body):
    m = re.search(r'SHA-256:</td><td><code>([0-9A-F:]+)</code>', body)
    return m.group(1).replace(':', '').lower() if m else None


def scenario_upload_pem():
    b = Browser().login()
    body = stage(b, {'cert_file': ('c.pem', rd('leaf-chain.crt')), 'key_file': ('k.pem', rd('leaf.key'))})
    check('PEM chain + key: the confirm step shows subject, issuer, names, expiry and fingerprint', staged_ok(body) and all(w in body for w in ('Subject:', 'Issuer:', 'Names:', 'Expires:')), body[-500:])
    fp = fingerprint_of(body)
    done = confirm(b)
    check('confirming stores the certificate and applies it', 'Certificate saved' in done, done[-400:])
    print('FP ' + (fp or ''))


def scenario_upload_pfx():
    b = Browser().login()
    body = stage(b, {'pfx_file': ('c.pfx', rd('leaf2.pfx'))}, extra={'pfx_password': 'pfxpass'})
    check('PFX + password: the confirm step is shown', staged_ok(body), body[-500:])
    fp = fingerprint_of(body)
    done = confirm(b)
    check('confirming the PFX stores the certificate and applies it', 'Certificate saved' in done, done[-400:])
    print('FP ' + (fp or ''))


def scenario_refusals():
    admin = Browser().login()
    suffix = uuid.uuid4().hex[:5]
    users = {}
    for level in (2, 3, 10):
        users[level] = 'httpq%d%s' % (level, suffix)
        make_user(admin, users[level], level)
    files = {'cert_file': ('c.pem', rd('leaf-chain.crt')), 'key_file': ('k.pem', rd('leaf.key'))}
    for level, name in users.items():
        b = Browser().login(name, 'qcpass123')
        tok = None
        try:
            tok = b.token('/?settings')
        except AssertionError:
            pass
        body = stage(b, files, token=tok or '0' * 40)
        check('level %d cannot stage a certificate' % level, not staged_ok(body))
        check('level %d does not see the form' % level, 'name="cert_file"' not in b.get('/?settings')[1])
    anon = Browser()
    anon.get('/')
    body = anon.request('/?settings', {'https_action': 'stage', 'token_submit': '0' * 40}, files=files)[1]
    check('an anonymous request cannot stage a certificate', not staged_ok(body))
    body = stage(admin, files, token='')
    check('a missing CSRF token is refused', not staged_ok(body) and 'expired or was not submitted from this site' in body, body[-300:])
    body = stage(admin, files, headers={'Origin': 'https://evil.example'})
    check('a foreign Origin is refused', not staged_ok(body) and 'expired or was not submitted from this site' in body)
    body = stage(admin, {'cert_file': ('c.pem', rd('leaf-chain.crt')), 'key_file': ('k.pem', rd('other.key'))})
    check('a key that does not belong to the certificate is refused', not staged_ok(body) and 'does not belong' in body, body[-300:])
    body = stage(admin, {'cert_file': ('c.pem', rd('expired.crt')), 'key_file': ('k.pem', rd('expired.key'))})
    check('an expired certificate is refused', not staged_ok(body) and 'expired' in body, body[-300:])
    body = stage(admin, {'cert_file': ('c.pem', rd('future.crt')), 'key_file': ('k.pem', rd('future.key'))})
    check('a not-yet-valid certificate is refused', not staged_ok(body) and 'not valid until' in body, body[-300:])
    body = stage(admin, {'pfx_file': ('c.pfx', rd('leaf2.pfx'))}, extra={'pfx_password': 'wrong'})
    check('a PFX with the wrong password is refused', not staged_ok(body) and 'PFX could not be read' in body, body[-300:])
    body = stage(admin, {'cert_file': ('c.pem', b'x' * (65 * 1024)), 'key_file': ('k.pem', rd('leaf.key'))})
    check('a 65 KiB file is refused', not staged_ok(body) and 'larger than 64 KiB' in body, body[-300:])
    body = stage(admin, {'cert_file': ('c.pem', rd('leaf-chain.crt'))})
    check('a certificate without its key is refused', not staged_ok(body) and 'Choose a certificate file and a key file' in body, body[-300:])


if __name__ == '__main__':
    name = sys.argv[1]
    globals()['scenario_' + name]()
    print('RESULT %s pass=%d fail=%d' % (name, PASSES, FAILS))
    sys.exit(1 if FAILS else 0)
