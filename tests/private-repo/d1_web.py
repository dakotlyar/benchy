"""Web-side checks of the Server settings page's discovery section (server discovery D1). Run inside a client container by d1.sh against the
HTTPS server; reuses the browser and the check helpers of https_web.py.

    d1_web.py page      environment as for https_web.py (H_BASE, H_HOST, H_USER, H_PASS, H_CAFILE)
"""
import html
import os
import re
import sys
import uuid

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import https_web as hw  # noqa: E402

check = hw.check
ORIGIN = {'Origin': 'https://%s:39443' % os.environ.get('H_HOST', 'd1srv')}


def state_line(body):
    m = re.search(r'<p id="discovery_state"><strong>(.*?)</strong></p>', body, re.S)
    return html.unescape(m.group(1)) if m else None


def post(b, fields, token=None, headers=None, page='/?server_settings'):
    data = {'discovery_form': '1'}
    data.update(fields)
    if token is None:
        token = b.token(page)
    if token:
        data['token_submit'] = token
    return b.post(page, data, headers=headers if headers is not None else ORIGIN)[1]


def wait_state(b, wanted, seconds=25):
    import time
    end = time.time() + seconds
    last = None
    while time.time() < end:
        last = state_line(b.get('/?server_settings')[1])
        if last and wanted in last:
            return last
        time.sleep(2)
    return last


def scenario_page():
    admin = hw.Browser().login()
    page = admin.get('/?server_settings')[1]
    check('the page has the section, the on/off choice and the network name box', 'Finding this server on the network' in page and 'name="discovery_announce" value="on"' in page and 'name="discovery_name"' in page)
    check('the live state line says it is announced as the host name on the HTTPS port', wait_state(admin, 'Announced as d1host on port 39443.') is not None and 'Announced as d1host on port 39443.' in (wait_state(admin, 'Announced as d1host') or ''), state_line(page))
    check('the DNS record is there, filled in from the first dotted name', 'local-data: &quot;_benchy._tcp.lab.test. 3600 IN SRV 0 0 39443 d1srv.lab.test.&quot;' in page, re.findall(r'id="discovery_srv">(.*?)<', page))
    check('the section sits under the HTTPS one', page.index('<h2>HTTPS</h2>') < page.index('Finding this server on the network'))

    body = post(admin, {'discovery_announce': 'off', 'discovery_name': 'd1host'})
    check('turning it off saves, and says what follows', '<strong>Saved.</strong>' in body and 'within a few seconds' in body, body[-300:])
    check('...and the page now says it is off', 'announcing is turned off' in (state_line(body) or ''), state_line(body))
    check('...and the radio shows it', re.search(r'value="off" checked="checked"', body) is not None)

    body = post(admin, {'discovery_announce': 'on', 'discovery_name': 'd1host'}, token='')
    check('a missing CSRF token is refused and nothing changes', 'expired or was not submitted from this site' in body and 'announcing is turned off' in (state_line(body) or ''), body[-200:])
    body = post(admin, {'discovery_announce': 'on', 'discovery_name': 'd1host'}, headers={'Origin': 'https://evil.example'})
    check('a foreign Origin is refused and nothing changes', 'expired or was not submitted from this site' in body and 'announcing is turned off' in (state_line(body) or ''))
    body = post(admin, {'discovery_name': 'd1host'})
    check('a POST with no on/off choice is refused', 'Choose on or off' in body)

    body = post(admin, {'discovery_announce': 'on', 'discovery_name': 'Café <b>lab</b>'})
    check('a name with an accent and markup is saved', '<strong>Saved.</strong>' in body, body[-300:])
    check('...and drawn as text: the markup is escaped in the page', 'Café <b>lab</b>' not in body and 'Café &lt;b&gt;lab&lt;/b&gt;' in body)
    announced = wait_state(admin, 'Announced as')
    check('...and announced under it', announced is not None and 'Announced as Café <b>lab</b> on port 39443.' in announced, announced)
    body = post(admin, {'discovery_announce': 'on', 'discovery_name': 'y' * 64})
    check('a name over 63 bytes is refused with the reason, and the old name stays', 'limit is 63 bytes' in body and 'Café &lt;b&gt;lab&lt;/b&gt;' in body, body[-300:])
    body = post(admin, {'discovery_announce': 'on', 'discovery_name': 'bad\x07name'})
    check('a name with a control character is refused', 'control characters' in body)
    body = post(admin, {'discovery_announce': 'on', 'discovery_name': ''})
    check('an empty name goes back to the host name', '<strong>Saved.</strong>' in body and 'value="d1host"' in body, body[-300:])

    # who may change it
    suffix = uuid.uuid4().hex[:5]
    name = 'd1q2' + suffix
    hw.make_user(admin, name, 2)
    low = hw.Browser().login(name, 'qcpass123')
    page = low.get('/?server_settings')[1]
    check('a level-2 user is refused the Server settings page', 'Server settings are for server administrators' in page and 'discovery_announce' not in page)
    try:
        tok = low.token('/?settings')
    except AssertionError:
        tok = '0' * 40
    low.post('/?server_settings', {'discovery_form': '1', 'discovery_announce': 'off', 'discovery_name': 'hijack', 'token_submit': tok}, headers=ORIGIN)
    low.post('/?settings', {'discovery_form': '1', 'discovery_announce': 'off', 'discovery_name': 'hijack', 'token_submit': tok}, headers=ORIGIN)
    anon = hw.Browser()
    anon.get('/')
    anon.post('/?server_settings', {'discovery_form': '1', 'discovery_announce': 'off', 'discovery_name': 'hijack', 'token_submit': '0' * 40}, headers=ORIGIN)
    page = admin.get('/?server_settings')[1]
    check('forged posts from a level-2 user and from nobody change nothing', 'value="d1host"' in page and 'name="discovery_announce" value="on" checked="checked"' in page and 'hijack' not in page, state_line(page))


if __name__ == '__main__':
    globals()['scenario_' + sys.argv[1]]()
    print('RESULT %s pass=%d fail=%d' % (sys.argv[1], hw.PASSES, hw.FAILS))
    sys.exit(1 if hw.FAILS else 0)
