#!/usr/bin/env python3
"""Engine self-update, U1, the server side: import, storage, the two routes, hello.engine.available, the admin page and the Systems page.
Driven by serve.sh against a scratch server whose engine key table holds throw-away keys (tests/engine/make_bundles.php; the bundles are in /bundles).
Nothing here touches the machine's own Benchy: the server is a throw-away container.
"""
import hashlib
import http.client
import json
import os
import re
import sys

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'catalog'))
sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'tenancy'))
import tn  # noqa: E402
import serve_e44 as s  # noqa: E402  (its helpers: enroll, hello_body, jcall, raw, validate, check)

A = s.A
check = s.check


def imp(name):
    return tn.cli('server-engine-import', '/bundles/%s.zip' % name)


def engine_files():
    out = tn.srv_sh('cd %s/engine 2>/dev/null && find signed files work -type f 2>/dev/null | sort; test -f state.json && echo state.json' % tn.STORAGE)
    return out.split()


def state():
    out = tn.srv_sh('cat %s/engine/state.json' % tn.STORAGE)
    return json.loads(out) if out.strip() else None


def refused(out, reason):
    return 'Refused (%s)' % reason in out and 'Nothing was changed' in out


def sha_of_archive(version, plat):
    return hashlib.sha256(('engine archive %s %s' % (version, plat)).encode()).hexdigest()


def main():
    r = tn.srv_sh('sh /bench/benchy server-account-create --user serveadmin --email s@example.com --account-id %s' % A, stdin=tn.PW + '\n')
    check('account made', 'Account created' in r, r)
    s.admin('allow-http', 'on')
    token, sid, mid = s.enroll(A, 'eng-a', 'a')
    check('keys: the server prints its engine key lines', re.match(r'^generation 1 [0-9a-f]{32}\ngeneration 2 [0-9a-f]{32}\n?$', tn.cli('server-engine-keys')) is not None, tn.cli('server-engine-keys'))

    # ---- nothing imported
    status, body, _ = s.jcall('/api/v2/agent/hello', s.hello_body(mid), token)
    check('no engine imported: hello has no engine key', status == 200 and 'engine' not in body, body)
    status, hdrs, out = s.raw('GET', '/api/v2/engine/index.signed', token)
    check('  and the index route is a 404 not_found', status == 404 and s.code_of(out) == 'not_found', (status, out[:100]))

    # ---- refusals before anything is stored: nothing changes
    for name, reason in (('rogue', 'unknown_key'), ('flipped', 'bad_signature'), ('bad-archive', 'bundle_pack_hash'), ('missing', 'bundle_pack_missing'), ('extra', 'bundle_pack_unlisted'),
                         ('badname', 'pack_path'), ('casefold', 'bad_index'), ('bad-version', 'bad_index'), ('min-too-high', 'bad_index')):
        out = imp(name)
        check('refused first: %s is %s, and nothing was stored' % (name, reason), refused(out, reason) and engine_files() == [], out[:300])
    check('a file that is not a zip is refused', 'Refused' in tn.srv_sh('cd /tmp && printf nonsense > /tmp/x.zip && sh /bench/benchy server-engine-import /tmp/x.zip 2>&1') and engine_files() == [])
    check('the refusals are in the audit', len(tn.sql("SELECT 1 FROM phoromatic_server_audit WHERE Action = 'engine_import_refused'")) >= 9)

    # ---- the first release
    out = imp('a')
    check('a imports: engine 0.11.0 for windows-x64 and linux-x64', 'Imported engine 0.11.0' in out and 'windows-x64, linux-x64' in out, out)
    st = state()
    wa, la = sha_of_archive('0.11.0', 'windows-x64'), sha_of_archive('0.11.0', 'linux-x64')
    check('the state names the release and both archives', st and st['version'] == '0.11.0' and st['sequence'] == 1 and set(st['files']) == {wa, la}, st)
    check('two archives, one signed file, state.json, nothing in work/', sorted(engine_files()) == sorted(['files/' + wa, 'files/' + la, 'signed/' + st['signed_sha256'] + '.signed', 'state.json']), engine_files())
    check('permissions: the engine folder is the server user\'s alone', tn.srv_sh('stat -c %%a %s/engine %s/engine/state.json' % (tn.STORAGE, tn.STORAGE)).split() == ['700', '600'])
    out = imp('a')
    check('importing it again changes nothing', 'already imported' in out and 'nothing changed' in out and state() == st, out)
    check('the same pair with other bytes is refused: same_pair_differs', refused(imp('a-other'), 'same_pair_differs') and state() == st)
    check('the audit has the import', len(tn.sql("SELECT 1 FROM phoromatic_server_audit WHERE Action = 'engine_imported'")) == 2)

    # ---- hello and the routes
    status, body, _ = s.jcall('/api/v2/agent/hello', s.hello_body(mid), token)
    check('hello announces the release', status == 200 and body.get('engine') == {'available': {'version': '0.11.0', 'sequence': 1}}, body)
    check('  and the reply is valid for hello_response', s.validate('hello_response', body) == [])
    status, hdrs, idx = s.raw('GET', '/api/v2/engine/index.signed', token)
    check('GET index.signed: 200, exactly the stored file, no-store, octet-stream, nosniff', status == 200 and hashlib.sha256(idx).hexdigest() == st['signed_sha256'] and hdrs.get('cache-control') == 'no-store'
          and hdrs.get('content-type') == 'application/octet-stream' and hdrs.get('x-content-type-options') == 'nosniff' and hdrs.get('content-length') == str(len(idx)), (status, hdrs))
    for plat, sha in (('windows-x64', wa), ('linux-x64', la)):
        status, hdrs, out = s.raw('GET', '/api/v2/engine/files/' + sha, token)
        check('GET files/%s...: 200, the bytes hash to the name' % sha[:8], status == 200 and out == ('engine archive 0.11.0 %s' % plat).encode() and hdrs.get('cache-control') == 'private, max-age=86400', (status, out[:50]))
    for what, h in (('an unknown hash', 'c' * 64), ('too short', 'a' * 63), ('too long', 'a' * 65), ('upper case', wa.upper()), ('a dotted path', '..%2f' + 'a' * 60), ('a trailing newline', wa + '%0a'), ('a catalog pack hash route', 'index.signed')):
        status, hdrs, out = s.raw('GET', '/api/v2/engine/files/' + h, token)
        check('files route, %s: 404' % what, status == 404, (status, out[:80]))
    for method in ('POST', 'PUT', 'DELETE'):
        for path in ('/api/v2/engine/index.signed', '/api/v2/engine/files/' + wa):
            status, hdrs, out = s.raw(method, path, token, body={})
            check('%s %s: 400 with Allow: GET' % (method, path[12:30]), status == 400 and hdrs.get('allow') == 'GET', (status, hdrs.get('allow')))
    check('another path under /engine/ is a 404', s.raw('GET', '/api/v2/engine/other', token)[0] == 404)

    # ---- the auth matrix
    tn.sql_exec('DELETE FROM phoromatic_v2_failures')
    for what, tok in (('no token', None), ('an unknown bsa_ token', 'bsa_' + 'A' * 43), ('a bench token (bbt_)', 'bbt_' + 'A' * 43), ('a private-repo token (bpr_)', 'bpr_' + 'A' * 43)):
        for path in ('/api/v2/engine/index.signed', '/api/v2/engine/files/' + wa):
            status, hdrs, out = s.raw('GET', path, tok)
            check('%s: 401 unauthenticated on %s' % (what, path[12:30]), status == 401 and s.code_of(out) == 'unauthenticated', (status, out[:100]))
    tn.sql_exec('DELETE FROM phoromatic_v2_failures')
    br = tn.Browser().login('serveadmin')
    check('a signed-in browser session without a bearer token: 401', br.get('/api/v2/engine/index.signed')[0] == 401)
    tn.sql_exec("UPDATE phoromatic_systems SET State = 0 WHERE AccountID = '%s' AND SystemID = '%s'" % (A, sid))
    status, body, _ = s.jcall('/api/v2/agent/hello', s.hello_body(mid), token)
    check('an unapproved system: hello has no engine', status == 200 and 'engine' not in body, body)
    for path in ('/api/v2/engine/index.signed', '/api/v2/engine/files/' + wa):
        status, hdrs, out = s.raw('GET', path, token)
        check('an unapproved system: 403 system_not_approved on %s' % path[12:30], status == 403 and s.code_of(out) == 'system_not_approved', (status, out[:100]))
    tn.sql_exec("UPDATE phoromatic_systems SET State = 1 WHERE AccountID = '%s' AND SystemID = '%s'" % (A, sid))
    revoked_token, revoked_sid, _ = s.enroll(A, 'eng-rev', 'c')
    s.admin('revoke-token', A, revoked_sid)
    status, hdrs, out = s.raw('GET', '/api/v2/engine/index.signed', revoked_token)
    check('a revoked token: 401 token_revoked', status == 401 and s.code_of(out) == 'token_revoked', (status, out[:100]))
    tn.sql_exec('DELETE FROM phoromatic_v2_failures')

    # ---- the Systems page: the Engine column
    s.jcall('/api/v2/agent/hello', s.hello_body(mid, extra_engine={'version': '0.10.1'}), token)
    page = br.get('/?systems')[2]
    check('the Systems page shows the engine and what is available', 'Engine:</strong> 0.10.1 (0.11.0 available)' in page, re.sub(r'\s+', ' ', page)[:200])

    # ---- the admin page (root): the section, an upload, a refusal
    tn.cli('server-admin-grant', 'serveadmin')
    root = tn.Browser().login('serveadmin')
    page = root.get('/?server_catalog')[2]
    check('the Engine updates section shows the offered release', 'Engine updates' in page and '<td>0.11.0</td><td>Offered</td>' in page and 'server-engine-import' in page, re.sub(r'\s+', ' ', page)[-300:])
    tok = root.token('/?server_catalog')
    b = open(os.path.join(os.environ.get('BUNDLES', '/bundles'), 'b.zip'), 'rb').read() if os.path.exists(os.path.join(os.environ.get('BUNDLES', '/bundles'), 'b.zip')) else None
    if b is not None:
        st_, _, page = root.multipart('/?server_catalog', {'engine_import': '1', 'token_submit': tok}, {'engine_bundle': ('b.zip', b)}, headers={'Origin': 'http://127.0.0.1:%s' % tn.PORT})
        check('uploading b on the page imports 0.11.1', 'Imported engine 0.11.1' in page and '<td>0.11.1</td><td>Offered</td>' in page and 'Withdrawn: 0.10.0' in page, re.sub(r'\s+', ' ', page)[-400:])
        tok = root.token('/?server_catalog')
        st_, _, page = root.multipart('/?server_catalog', {'engine_import': '1', 'token_submit': tok}, {'engine_bundle': ('rogue.zip', open(os.path.join(os.environ.get('BUNDLES', '/bundles'), 'rogue.zip'), 'rb').read())}, headers={'Origin': 'http://127.0.0.1:%s' % tn.PORT})
        check('uploading a bundle signed by an unknown key is refused on the page', 'Refused (unknown_key)' in page and '<td>0.11.1</td><td>Offered</td>' in page, re.sub(r'\s+', ' ', page)[-300:])
        st2 = state()
        check('the second release replaced the first: old archives collected, state moved', st2['version'] == '0.11.1' and st2['sequence'] == 2 and st2['revoked'] == ['0.10.0'] and st2['floor'] == '0.9.0'
              and sorted(engine_files()) == sorted(['files/' + sha_of_archive('0.11.1', 'windows-x64'), 'files/' + sha_of_archive('0.11.1', 'linux-x64'), 'signed/' + st2['signed_sha256'] + '.signed', 'state.json']), (st2, engine_files()))
        check('  and the old archive is no longer served', s.raw('GET', '/api/v2/engine/files/' + wa, token)[0] == 404)
        check('a lower sequence is refused: rollback', refused(imp('a'), 'rollback') and state() == st2)
        status, body, _ = s.jcall('/api/v2/agent/hello', s.hello_body(mid, extra_engine={'version': '0.10.0'}), token)
        page = br.get('/?systems')[2]
        check('the Systems page marks a withdrawn engine', '0.10.0 (0.11.1 available) <span style="color: #b00020;">withdrawn</span>' in page, re.sub(r'\s+', ' ', page)[:100])

    # ---- keys: the standby takes over, the old key is retired, the table's tail is refused
    out = imp('gen2')
    check('the standby key (generation 2) imports and retires generation 1', 'Imported engine 0.12.0 (key generation 2' in out and state()['retired_below'] == 2, out)
    check('a release signed by the retired key is refused: retired_key', refused(imp('gen1-late'), 'retired_key'))

    # ---- nothing is served from storage that does not verify
    st = state()
    sig = '%s/engine/signed/%s.signed' % (tn.STORAGE, st['signed_sha256'])
    wnew = sha_of_archive('0.12.0', 'windows-x64')
    check('before damage: served', s.raw('GET', '/api/v2/engine/index.signed', token)[0] == 200 and s.raw('GET', '/api/v2/engine/files/' + wnew, token)[0] == 200)
    tn.srv_sh("printf 'x' >> %s" % sig)
    status, body, _ = s.jcall('/api/v2/agent/hello', s.hello_body(mid), token)
    check('a signed file that no longer matches its hash: 404, and not in hello', s.raw('GET', '/api/v2/engine/index.signed', token)[0] == 404 and 'engine' not in body and s.raw('GET', '/api/v2/engine/files/' + wnew, token)[0] == 404, body)
    tn.srv_sh("head -c -1 %s > %s.fix && mv %s.fix %s" % (sig, sig, sig, sig))
    check('and is served again when the bytes are right', s.raw('GET', '/api/v2/engine/index.signed', token)[0] == 200)
    f = '%s/engine/files/%s' % (tn.STORAGE, wnew)
    tn.srv_sh("printf 'x' >> %s" % f)
    check('an archive of the wrong size: nothing is offered', s.raw('GET', '/api/v2/engine/files/' + wnew, token)[0] == 404 and s.raw('GET', '/api/v2/engine/index.signed', token)[0] == 404)
    tn.srv_sh("head -c -1 %s > %s.fix && mv %s.fix %s" % (f, f, f, f))
    tn.srv_sh("chmod 600 %s" % f)
    check('and is served again when the bytes are right', s.raw('GET', '/api/v2/engine/files/' + wnew, token)[0] == 200)
    tn.srv_sh("mv %s %s.away" % (f, f))
    out = imp('gen2')
    check('importing the same bundle again puts a missing archive back', 'missing files were put back' in out and s.raw('GET', '/api/v2/engine/files/' + wnew, token)[0] == 200, out)
    tn.srv_sh("rm -f %s.away; printf '{' > %s/engine/state.json" % (f, tn.STORAGE))
    check('a damaged state.json: nothing is offered and an import says so', s.raw('GET', '/api/v2/engine/index.signed', token)[0] == 404 and 'Refused (state_corrupt)' in imp('gen2'))
    print('FAILURES %d' % s.fails)


if __name__ == '__main__':
    main()
