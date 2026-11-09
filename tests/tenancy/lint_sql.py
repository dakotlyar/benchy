#!/usr/bin/env python3
"""SQL scope lint: every FROM / JOIN / UPDATE / DELETE / INSERT on a phoromatic_* table of a tenant must be bound to AccountID in its own
clause (its own SELECT, UPDATE or DELETE), or be on the reviewed allow-list in allow_sql.json, keyed by clause with a reason.

  lint_sql.py [--root DIR] [--print-new]

Exit 1 on: a clause that is not bound and not allow-listed; an allow-list entry that matches nothing (the code changed: review it again);
a table created in the code but not classified in tables.json; a tenant table without an AccountID column; a table named in SQL that is not
classified. --print-new prints skeleton entries for the unbound clauses (reason left as TODO, which still fails).
"""
import argparse
import hashlib
import json
import os
import re
import sys

import phpscan

HERE = os.path.dirname(os.path.abspath(__file__))
STATEMENT = re.compile(r'^\s*(SELECT|UPDATE|DELETE\s+FROM|INSERT\s+(?:OR\s+\w+\s+)?INTO|REPLACE\s+INTO)\b', re.I)
NOT_ALIAS = r'WHERE|ON|JOIN|LEFT|RIGHT|INNER|OUTER|CROSS|ORDER|GROUP|LIMIT|SET|USING|UNION|VALUES|AND|OR|HAVING|NATURAL|SELECT'
REF = re.compile(r'\b(FROM|JOIN|UPDATE|INTO)\s+(phoromatic_\w+)(?:\s+(?:AS\s+)?(?!(?:%s)\b)(\w+))?' % NOT_ALIAS, re.I)


def norm(sql):
    return re.sub(r'\s+', ' ', sql).strip()


def split_scopes(sql):
    """[(text, offset)] one per SELECT / statement scope; nested SELECT groups are cut out of their parent and become scopes of their own."""
    scopes = []
    stack = [[]]
    kinds = ['stmt']
    i = 0
    while i < len(sql):
        c = sql[i]
        if c == '(':
            if re.match(r'\(\s*SELECT\b', sql[i:], re.I):
                stack.append([])
                kinds.append('select')
            else:
                stack[-1].append(c)
                kinds.append('plain')
        elif c == ')' and len(kinds) > 1:
            k = kinds.pop()
            if k == 'select':
                scopes.append(''.join(stack.pop()))
                stack[-1].append('(?SUB)')
            else:
                stack[-1].append(c)
        else:
            stack[-1].append(c)
        i += 1
    while len(stack) > 1:
        scopes.append(''.join(stack.pop()))
    scopes.append(''.join(stack[0]))
    return scopes


def blank_or_groups(scope):
    """The scope with every parenthesised group that contains an OR replaced by (?GRP) (a predicate inside such a group does not bind),
    and every other group flattened into its parent."""
    pat = re.compile(r'\(([^()]*)\)')
    while True:
        m = pat.search(scope)
        if not m:
            return scope
        inner = m.group(1)
        scope = scope[:m.start()] + ('?GRP' if re.search(r'\bOR\b', inner, re.I) else ' ' + inner + ' ') + scope[m.end():]


def refs_in(scope):
    out = []
    for m in REF.finditer(scope):
        kw, table, alias = m.group(1).upper(), m.group(2), m.group(3)
        out.append((kw, table, alias))
    # "FROM a, b" lists
    for m in re.finditer(r',\s*(phoromatic_\w+)(?:\s+(?:AS\s+)?(?!(?:%s)\b)(\w+))?' % NOT_ALIAS, scope, re.I):
        out.append(('FROM', m.group(1), m.group(2)))
    return out


def insert_columns(scope):
    m = re.search(r'\bINTO\s+phoromatic_\w+\s*\(([^)]*)\)', scope, re.I)
    return [c.strip() for c in m.group(1).split(',')] if m else None


def bound(scope, table, alias, nrefs, keyword):
    names = [re.escape(table)] + ([re.escape(alias)] if alias else [])
    qualified = re.compile(r'\b(?:%s)\.AccountID\s*(?:=|==|\bIN\b)' % '|'.join(names), re.I)
    unqualified = re.compile(r'(?<![\w.])AccountID\s*(?:=|==|\bIN\b)', re.I)
    if keyword == 'INTO':
        cols = insert_columns(scope)
        return cols is not None and any(c.lower() == 'accountid' for c in cols)
    if qualified.search(scope):
        return True
    if nrefs == 1 and unqualified.search(scope):
        return True
    # a join condition  x.AccountID = y.AccountID  binds x when y is bound (checked by the caller through the other table's own result)
    return False


def analyse(path, src, tables):
    findings = []
    for start, end, text, parts in phpscan.chains(src):
        if not STATEMENT.match(text) or 'phoromatic_' not in text:
            continue
        sql = norm(text)
        for scope in split_scopes(sql):
            refs = refs_in(scope)
            if not refs:
                continue
            nrefs = len(refs)
            flat = blank_or_groups(scope)
            has_or = re.search(r'\bOR\b', flat, re.I) is not None
            statuses = []
            for kw, table, alias in refs:
                kind = tables.get(table)
                if kind is None:
                    statuses.append((table, 'unclassified', scope))
                elif kind == 'server':
                    continue
                elif not bound(scope if kw == 'INTO' else flat, table, alias, nrefs, kw):
                    statuses.append((table, 'unbound', scope))
                elif has_or and kw != 'INTO':
                    statuses.append((table, 'or', scope))
            # a join condition can bind a second table to a first one that is bound
            if nrefs > 1:
                names = {}
                for kw, table, alias in refs:
                    names[(alias or table).lower()] = table
                joined = []
                for table, status, sc in statuses:
                    if status != 'unbound':
                        joined.append((table, status, sc))
                        continue
                    ok = False
                    for m in re.finditer(r'\b(\w+)\.AccountID\s*=\s*(\w+)\.AccountID', sc, re.I):
                        a, b = m.group(1).lower(), m.group(2).lower()
                        if names.get(a) == table and not any(t == names.get(b) and s == 'unbound' for t, s, _ in statuses if t != table):
                            ok = True
                        if names.get(b) == table and not any(t == names.get(a) and s == 'unbound' for t, s, _ in statuses if t != table):
                            ok = True
                        # correlation with a table of an outer scope
                        if (names.get(a) == table and b not in names) or (names.get(b) == table and a not in names):
                            ok = True
                    if not ok:
                        joined.append((table, status, sc))
                statuses = joined
            for table, status, sc in statuses:
                key = '%s::%s::%s' % (path, table, hashlib.sha1(sc.encode()).hexdigest()[:10])
                findings.append({'key': key, 'file': path, 'line': phpscan.line_of(src, start), 'table': table, 'status': status, 'clause': sc[:200]})
    return findings


def created_tables(root):
    found = {}
    for path in phpscan.php_files(root):
        src = open(os.path.join(root, path), errors='replace').read()
        for m in re.finditer(r'CREATE TABLE (?:IF NOT EXISTS )?(phoromatic_\w+) \((.*?)\)\s*[\'"]', src, re.S):
            found[m.group(1)] = 'AccountID' in m.group(2)
    return found


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--root', default=phpscan.ROOT_DEFAULT)
    ap.add_argument('--print-new', action='store_true')
    ap.add_argument('--allow', default=os.path.join(HERE, 'allow_sql.json'))
    ap.add_argument('--tables', default=os.path.join(HERE, 'tables.json'))
    a = ap.parse_args()
    tables = json.load(open(a.tables))
    tmap = {t: v['scope'] for t, v in tables.items()}
    allow = json.load(open(a.allow))
    problems = []
    created = created_tables(a.root)
    for t, has_acct in sorted(created.items()):
        if t not in tmap:
            problems.append('table %s is created in the code but not classified in tables.json (tenant or server)' % t)
        elif tmap[t] == 'tenant' and not has_acct:
            problems.append('table %s is classified tenant but has no AccountID column' % t)
    for t in tmap:
        if t not in created:
            problems.append('tables.json lists %s, which no CREATE TABLE in the code creates' % t)
    findings = []
    for path in phpscan.php_files(a.root):
        src = phpscan.strip_comments(open(os.path.join(a.root, path), errors='replace').read())
        findings += analyse(path, src, tmap)
    seen = set()
    for f in findings:
        if f['status'] == 'unclassified':
            problems.append('%s:%d names table %s, which tables.json does not classify' % (f['file'], f['line'], f['table']))
            continue
        seen.add(f['key'])
        entry = allow.get(f['key'])
        if entry is None or not entry.get('reason') or entry['reason'].strip().upper().startswith('TODO'):
            problems.append('%s:%d %s on %s is %s and not allow-listed (key %s)\n      %s' % (f['file'], f['line'], 'clause', f['table'], 'not bound to AccountID' if f['status'] == 'unbound' else 'bound only next to an OR', f['key'], f['clause'][:160]))
            if a.print_new:
                print(json.dumps({f['key']: {'snippet': f['clause'][:160], 'reason': 'TODO'}}, indent=1)[2:-2] + ',')
    for k in allow:
        if k not in seen:
            problems.append('allow-list entry %s matches no clause any more (the SQL changed or went away): review it and remove or re-key it' % k)
    print('sql lint: %d clauses on tenant tables are allow-listed with a reason, %d problem(s)' % (len([k for k in allow if k in seen]), len(problems)))
    for p in problems:
        print('  ' + p)
    return 1 if problems else 0


if __name__ == '__main__':
    sys.exit(main())
