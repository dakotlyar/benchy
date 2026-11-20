#!/usr/bin/env python3
"""Runtime component updates, R1, the server side over HTTP: the two routes (auth matrix, 404 before an import, exact bytes after one, an unlisted hash) and the page upload (no
form token, a non-admin). Driven by serve.sh right after serve_u1.py, against the same scratch server; the account and its admin login already exist. Bundles c1 and c1-rogue are
in /bundles (tests/engine/make_bundles.php).
"""
import hashlib
import os
import re
import sys

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'catalog'))
sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'tenancy'))
import tn  # noqa: E402
import serve_e44 as s  # noqa: E402

A = s.A
check = s.check
PHP, CLIENT = b'php zip 8.5.13', b'client zip 0.15.0'
SHA_PHP, SHA_CLIENT = hashlib.sha256(PHP).hexdigest(), hashlib.sha256(CLIENT).hexdigest()
IDX, FILE = '/api/v2/components/index.signed', '/api/v2/components/files/'


def main():
    token, sid, mid = s.enroll(A, 'comp-a', 'd')
    # ---- 404 before any import
    status, hdrs, out = s.raw('GET', IDX, token)
    check('no components imported: the index is a 404 not_found', status == 404 and s.code_of(out) == 'not_found', (status, out[:100]))
    check('  and so is a file', s.raw('GET', FILE + SHA_PHP, token)[0] == 404)

    # ---- the auth matrix
    tn.sql_exec('DELETE FROM phoromatic_v2_failures')
    for what, tok in (('no token', None), ('an unknown bsa_ token', 'bsa_' + 'A' * 43), ('a bench token (bbt_)', 'bbt_' + 'A' * 43)):
        for path in (IDX, FILE + SHA_PHP):
            status, hdrs, out = s.raw('GET', path, tok)
            check('%s: 401 unauthenticated on %s' % (what, path[12:34]), status == 401 and s.code_of(out) == 'unauthenticated', (status, out[:100]))
    tn.sql_exec('DELETE FROM phoromatic_v2_failures')
    tn.sql_exec("UPDATE phoromatic_systems SET State = 0 WHERE AccountID = '%s' AND SystemID = '%s'" % (A, sid))
    for path in (IDX, FILE + SHA_PHP):
        status, hdrs, out = s.raw('GET', path, token)
        check('an unapproved system: 403 system_not_approved on %s' % path[12:34], status == 403 and s.code_of(out) == 'system_not_approved', (status, out[:100]))
    tn.sql_exec("UPDATE phoromatic_systems SET State = 1 WHERE AccountID = '%s' AND SystemID = '%s'" % (A, sid))

    # ---- import, then exact bytes
    out = tn.cli('server-component-import', '/bundles/c1.zip')
    check('c1 imports: client 0.15.0 and php 8.5.13', 'Imported the component index' in out and 'php 8.5.13' in out and 'client 0.15.0' in out, out)
    status, hdrs, idx = s.raw('GET', IDX, token)
    stored = tn.srv_sh('cat %s/components/state.json' % tn.STORAGE)
    sha_idx = re.search(r'"signed_sha256":"([0-9a-f]{64})"', stored)
    check('GET index.signed: 200, exactly the stored file, no-store, octet-stream, nosniff', status == 200 and sha_idx and hashlib.sha256(idx).hexdigest() == sha_idx.group(1)
          and hdrs.get('cache-control') == 'no-store' and hdrs.get('content-type') == 'application/octet-stream' and hdrs.get('x-content-type-options') == 'nosniff', (status, hdrs))
    for name, sha, body in (('php', SHA_PHP, PHP), ('client', SHA_CLIENT, CLIENT)):
        status, hdrs, out = s.raw('GET', FILE + sha, token)
        check('GET files/%s: 200 with exactly the bytes' % name, status == 200 and out == body and hdrs.get('cache-control') == 'private, max-age=86400', (status, out[:60]))
    for what, h in (('an unlisted hash', 'c' * 64), ('too short', 'a' * 63), ('upper case', SHA_PHP.upper()), ('an engine file hash route', '../engine/files/' + SHA_PHP)):
        check('files route, %s: 404' % what, s.raw('GET', FILE + h, token)[0] == 404)
    for method in ('POST', 'PUT', 'DELETE'):
        status, hdrs, out = s.raw(method, IDX, token, body={})
        check('%s index.signed: 400 with Allow: GET' % method, status == 400 and hdrs.get('allow') == 'GET', (status, hdrs.get('allow')))
    check('another path under /components/ is a 404', s.raw('GET', '/api/v2/components/other', token)[0] == 404)
    check('the engine routes do not serve a component file', s.raw('GET', '/api/v2/engine/files/' + SHA_PHP, token)[0] == 404)

    # ---- the page
    bundles = os.environ.get('BUNDLES', '/bundles')
    rogue = open(os.path.join(bundles, 'c1-rogue.zip'), 'rb').read()
    tn.cli('server-admin-grant', 'serveadmin')
    root = tn.Browser().login('serveadmin')
    page = root.get('/?server_catalog')[2]
    check('the page lists the components with their versions', 'Runtime component updates' in page and '<td>php</td><td>8.5</td><td>8.5.13</td><td>Offered</td>' in page and 'server-component-import' in page, re.sub(r'\s+', ' ', page)[-300:])
    hdr = {'Origin': 'http://127.0.0.1:%s' % tn.PORT}
    st_, _, page = root.multipart('/?server_catalog', {'components_import': '1', 'token_submit': 'wrong'}, {'components_bundle': ('c1-rogue.zip', rogue)}, headers=hdr)
    check('an upload without the form token is refused', 'form expired' in page and 'Refused' not in page, re.sub(r'\s+', ' ', page)[-200:])
    tok = root.token('/?server_catalog')
    st_, _, page = root.multipart('/?server_catalog', {'components_import': '1', 'token_submit': tok}, {'components_bundle': ('c1-rogue.zip', rogue)}, headers=hdr)
    check('a bundle signed by an unknown key is refused on the page', 'Refused (unknown_key)' in page, re.sub(r'\s+', ' ', page)[-200:])
    before = tn.srv_sh('cat %s/components/state.json' % tn.STORAGE)
    tn.cli('server-admin-revoke', 'serveadmin')
    plain = tn.Browser().login('serveadmin')
    tok = plain.token('/?server_catalog')
    st_, _, page = plain.multipart('/?server_catalog', {'components_import': '1', 'token_submit': tok}, {'components_bundle': ('c1.zip', open(os.path.join(bundles, 'c1.zip'), 'rb').read())}, headers=hdr)
    check('a non-admin upload does nothing: no section, no import, no state change', 'Runtime component updates' not in page and tn.srv_sh('cat %s/components/state.json' % tn.STORAGE) == before)
    print('FAILURES %d' % s.fails)


if __name__ == '__main__':
    main()
