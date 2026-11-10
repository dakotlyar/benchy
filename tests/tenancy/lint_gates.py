#!/usr/bin/env python3
"""Gate lint (T1): the places that must look at a tenant's state or set the tenant context still do, and the changes made so that nothing depends on a
token in an address, an existence test on a folder, or a logout by link stay made.

  lint_gates.py [--root DIR]

Checks, each a plain search of the PHP source:
  1. every entry point that learns an account (phoromatic.php, rss.php, event.php, the v2 API, the web bootstrap) calls phoromatic_tenant_context::set
  2. the three that take an account from a client or a feed look at phoromatic_tenant::state() / is_active() before doing anything
  3. every query of the event server over phoromatic_systems is limited with phoromatic_tenant::active_clause()
  4. no page builds a link with the CSRF token in it (append_token_to_url, token_submit= in a string)
  5. an account exists because it has a row: no is_phoromatic_account_path(), no is_dir() on accounts/ in the entry points
  6. phoromatic_account_path() asks the tenant context
  7. logging out needs a POST
  8. the web bootstrap refreshes the session from the database before the viewer flag is defined
"""
import argparse
import os
import re
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT_DEFAULT = os.path.normpath(os.path.join(HERE, '..', '..'))
PH = 'pts-core/phoromatic/'
OBJ = 'pts-core/objects/phoromatic/'


def read(root, rel):
    try:
        return open(os.path.join(root, rel)).read()
    except OSError:
        return None


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--root', default=ROOT_DEFAULT)
    a = ap.parse_args()
    problems = []
    checks = 0

    def need(rel, pattern, why, flags=0):
        nonlocal checks
        checks += 1
        text = read(a.root, rel)
        if text is None:
            problems.append('%s is missing (%s)' % (rel, why))
        elif not re.search(pattern, text, flags):
            problems.append('%s: %s' % (rel, why))

    def forbid(rel, pattern, why, flags=0):
        nonlocal checks
        checks += 1
        text = read(a.root, rel)
        if text is not None and (m := re.search(pattern, text, flags)):
            problems.append('%s:%d %s' % (rel, text[:m.start()].count('\n') + 1, why))

    for rel in (PH + 'public_html/phoromatic.php', PH + 'public_html/rss.php', PH + 'public_html/event.php', PH + 'public_html/index.php', OBJ + 'phoromatic_api_v2.php'):
        need(rel, r'phoromatic_tenant_context::set\(', 'does not set phoromatic_tenant_context')
    need(PH + 'public_html/phoromatic.php', r'phoromatic_tenant::state\(', 'does not check the account state')
    need(PH + 'public_html/phoromatic.php', r"phoromatic_tenant::SUSPENDED[\s\S]{0,200}This Phoromatic account is suspended\.", 'does not answer a suspended account with the suspended error')
    for rel in (PH + 'public_html/rss.php', PH + 'public_html/event.php'):
        need(rel, r'phoromatic_tenant::is_active\(', 'does not check that the account is active')
    event_server = read(a.root, OBJ + 'pts_phoromatic_event_server.php') or ''
    checks += 1
    loops = event_server.count('FROM phoromatic_systems')
    if loops < 3 or event_server.count('phoromatic_tenant::active_clause()') < loops:
        problems.append('%spts_phoromatic_event_server.php: %d queries over phoromatic_systems but %d use phoromatic_tenant::active_clause()' % (OBJ, loops, event_server.count('phoromatic_tenant::active_clause()')))
    for dirpath, dirnames, filenames in os.walk(os.path.join(a.root, PH)):
        for f in filenames:
            if f.endswith('.php'):
                rel = os.path.relpath(os.path.join(dirpath, f), a.root)
                forbid(rel, r'append_token_to_url|[?&]token_submit=', 'a link with the CSRF token in the address')
    forbid(PH + 'phoromatic_functions.php', r'function append_token_to_url', 'append_token_to_url() is back')
    for rel in (PH + 'public_html/phoromatic.php', PH + 'public_html/index.php', OBJ + 'phoromatic_server.php'):
        forbid(rel, r'is_phoromatic_account_path', 'an account is told by its folder, not its row')
    forbid(PH + 'public_html/phoromatic.php', r'is_dir\([^)]*accounts/', 'an account is told by its folder, not its row')
    need(OBJ + 'phoromatic_server.php', r'function phoromatic_account_path\(\$account_id(, \$folder = null)?\)\s*\{(\s*//[^\n]*)*\s*phoromatic_tenant_context::require_account\(\$account_id(, \$folder)?\);', 'phoromatic_account_path() does not ask the tenant context')
    need(PH + 'public_html/index.php', r"\$logging_out\s*=[^;]*REQUEST_METHOD'\]\s*===\s*'POST'[^;]*verify_submission_token", 'logging out does not need a POST with the token')
    need(PH + 'phoromatic_functions.php', r'phoromatic_auth::refresh_session\(\);\s*\}\s*define\(\'PHOROMATIC_USER_IS_VIEWER\'', 'the session is not refreshed before the viewer flag is defined')
    print('gate lint: %d checks, %d problem(s)' % (checks, len(problems)))
    for p in problems:
        print('  ' + p)
    return 1 if problems else 0


if __name__ == '__main__':
    sys.exit(main())
