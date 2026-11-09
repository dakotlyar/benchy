#!/usr/bin/env python3
"""Path lint: a value that came from a request must reach phoromatic_server::phoromatic_account_*_path() or find_suite_file() only through
phoromatic_tenant_path::segment() (or uri_segment() / suite_name()), which decode-then-validate. Any other value in those calls (a database
field, a function parameter, a constant) is either one of the plainly safe forms below or on the reviewed allow-list in allow_paths.json,
keyed by the call with a reason.

  lint_paths.py [--root DIR] [--print-new]

Request-derived means: $_GET, $_POST, $_REQUEST, $_COOKIE, $_FILES, $PATH (the raw request URI), the variables phoromatic.php fills from the
client's request (read from its $environment_variables table, so a new one is picked up), phoromatic_get_posted_var(), and any variable assigned
from those in the same file. A request-derived value inside such a call that is not wrapped is a failure that no allow-list entry can excuse,
except for find_suite_file(), whose own body must then call phoromatic_tenant_path::suite_name().
"""
import argparse
import hashlib
import json
import os
import re
import sys

import phpscan

HERE = os.path.dirname(os.path.abspath(__file__))
CALL = re.compile(r'(?<!function )\b(?:phoromatic_server|self)::(phoromatic_account_(?:path|result_path|suite_path|system_path|stress_log_path)|find_suite_file)\s*\(')
WRAP = re.compile(r'phoromatic_tenant_path::(?:segment|uri_segment|suite_name)\s*\(')
TAINT_BASE = [r'\$_GET\b', r'\$_POST\b', r'\$_REQUEST\b', r'\$_COOKIE\b', r'\$_FILES\b', r'\$PATH\b', r'\bphoromatic_get_posted_var\s*\(']
SAFE_TOKEN = re.compile(r"""^(?:\$_SESSION\['AccountID'\]|ACCOUNT_ID|SYSTEM_ID|AID|SID|'[^']*'|"[^"$]*"|\d+|null|SEG)$""")


def v1_variables(root):
    src = open(os.path.join(root, 'pts-core/phoromatic/public_html/phoromatic.php')).read()
    m = re.search(r'\$environment_variables = array\((.*?)\n\t\);', src, re.S)
    return sorted(set(re.findall(r"=>\s*'([A-Z_]+)'", m.group(1)))) if m else []


SANITIZERS = re.compile(r'\b(?:sha1|sha1_file|md5|md5_file|hash|crc32|intval)\s*\(|\(int\)\s*')


def strip_wrappers(text):
    """The segment() family and the plain sanitizers (a hash is hex, an integer cast is digits) replaced by SEG."""
    while True:
        m = WRAP.search(text)
        cast = SANITIZERS.search(text)
        if not m and not cast:
            return text
        if m and (not cast or m.start() <= cast.start()):
            end = phpscan.balanced(text, m.end() - 1)
            text = text[:m.start()] + 'SEG' + text[end:]
        elif cast.group(0).startswith('(int)'):
            end = phpscan.operand_end(text, cast.end())
            text = text[:cast.start()] + 'SEG' + text[end:]
        else:
            end = phpscan.balanced(text, cast.end() - 1)
            text = text[:cast.start()] + 'SEG' + text[end:]


def strip_probes(text):
    # isset()/empty() only test a value; they do not use it
    return re.sub(r'\b(?:isset|empty)\s*\([^()]*(?:\([^()]*\)[^()]*)*\)', 'PROBE', text)


def split_args(text):
    args, depth, cur, i = [], 0, [], 0
    while i < len(text):
        c = text[i]
        if c in '\'"':
            _, j = phpscan.literal_at(text, i)
            cur.append(text[i:j])
            i = j
            continue
        if c in '([':
            depth += 1
        elif c in ')]':
            depth -= 1
        if c == ',' and depth == 0:
            args.append(''.join(cur).strip())
            cur = []
        else:
            cur.append(c)
        i += 1
    if ''.join(cur).strip():
        args.append(''.join(cur).strip())
    return args


def split_concat(text):
    """The operands of a '. a . b' tail (literals kept whole)."""
    out, i = [], 0
    while i < len(text):
        if text[i] in ' \t\r\n.)':
            i += 1
            continue
        if text[i] in '\'"':
            _, j = phpscan.literal_at(text, i)
        else:
            j = max(phpscan.operand_end(text, i), i + 1)
        out.append(text[i:j].strip())
        i = j
    return out


def tail_after(src, end):
    """The rest of the expression after the call: concatenated pieces up to the end of the statement or condition."""
    depth, i = 0, end
    while i < len(src):
        c = src[i]
        if c in '\'"':
            _, i = phpscan.literal_at(src, i)
            continue
        if c == '(':
            depth += 1
        elif c == ')':
            depth -= 1
            if depth < 0:
                break
        elif c in ';{' and depth == 0:
            break
        i += 1
    return src[end:i]


def file_taint(src, names):
    base = TAINT_BASE + [r'\$%s\b' % n for n in names]
    tainted, checked = set(), set()
    assign = re.compile(r'\$(\w+)\s*=(?!=)\s*([^;]*);')
    simple = re.compile(r'^\(?\s*(?:trim|basename|strtolower|strtoupper|substr|str_replace|preg_replace)?\(?\s*\$(\w+)(?:\[[^\]]*\])*\s*(?:,[^()]*)?\)?\s*$')
    for _ in range(4):
        for m in assign.finditer(src):
            var, rhs = m.group(1), m.group(2)
            rest = strip_probes(strip_wrappers(rhs))
            if WRAP.search(rhs) and not any(re.search(p, rest) for p in base):
                checked.add(var)  # assigned from segment() & co. (the unchecked branch of a ternary is false or empty, never the raw value)
                continue
            if re.search(r'\bquerySingle\s*\(|\bfetchArray\s*\(', rest):
                continue  # a value read from the database is not the request's (what was stored is checked where it is stored or used)
            if any(re.search(p, rest) for p in base):
                tainted.add(var)
            else:
                sm = simple.match(rest.strip())
                if sm and sm.group(1) in tainted:
                    tainted.add(var)
    for m in re.finditer(r'\(\s*\$(\w+)\s*=\s*phoromatic_tenant_path::', src):
        checked.add(m.group(1))
    return base, tainted - checked, checked


def sink_validates(root, name):
    src = open(os.path.join(root, 'pts-core/objects/phoromatic/phoromatic_server.php')).read()
    m = re.search(r'function %s\s*\(' % name, src)
    if not m:
        return False
    body = src[m.start():m.start() + 1500]
    return 'phoromatic_tenant_path::suite_name(' in body


def analyse(root, path, src, names):
    findings = []
    base, tainted, checked = file_taint(src, names)
    for m in CALL.finditer(src):
        func = m.group(1)
        open_i = m.end() - 1
        close_i = phpscan.balanced(src, open_i)
        args_text = src[open_i + 1:close_i - 1]
        tail = tail_after(src, close_i)
        whole = args_text + ' ## ' + tail
        stripped = strip_probes(strip_wrappers(whole))
        taint_hit = any(re.search(p, stripped) for p in base) or any(re.search(r'\$%s\b' % t, stripped) for t in tainted)
        status = None
        if taint_hit:
            status = 'sink' if func == 'find_suite_file' and sink_validates(root, func) else 'tainted'
        else:
            unsafe = []
            for a in split_args(strip_wrappers(args_text)):
                a = a.strip()
                if SAFE_TOKEN.match(a) or (re.match(r'^\$(\w+)$', a) and re.match(r'^\$(\w+)$', a).group(1) in checked):
                    continue
                unsafe.append(a)
            for piece in split_concat(strip_probes(strip_wrappers(tail))):
                if piece and not SAFE_TOKEN.match(piece) and not (re.match(r'^\$(\w+)$', piece) and re.match(r'^\$(\w+)$', piece).group(1) in checked):
                    unsafe.append(piece)
            status = 'review' if unsafe else 'safe'
        if status in ('safe', 'sink'):
            continue
        text = re.sub(r'\s+', ' ', phpscan_text(src, m.start(), close_i, tail))
        key = '%s::%s::%s' % (path, func, hashlib.sha1(text.encode()).hexdigest()[:10])
        findings.append({'key': key, 'file': path, 'line': phpscan.line_of(src, m.start()), 'status': status, 'text': text[:200]})
    return findings


def phpscan_text(src, start, close_i, tail):
    return src[start:close_i] + tail


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--root', default=phpscan.ROOT_DEFAULT)
    ap.add_argument('--print-new', action='store_true')
    ap.add_argument('--allow', default=os.path.join(HERE, 'allow_paths.json'))
    a = ap.parse_args()
    allow = json.load(open(a.allow))
    names = v1_variables(a.root)
    problems, seen = [], set()
    if not names:
        problems.append("could not read the client variables out of phoromatic.php's $environment_variables table")
    sink_ok = sink_validates(a.root, 'find_suite_file')
    if not sink_ok:
        problems.append('find_suite_file() no longer calls phoromatic_tenant_path::suite_name() on its argument')
    for path in phpscan.php_files(a.root):
        src = phpscan.strip_comments(open(os.path.join(a.root, path), errors='replace').read())
        for f in analyse(a.root, path, src, names):
            if f['status'] == 'tainted':
                problems.append('%s:%d a request-derived value reaches a file path without phoromatic_tenant_path::segment():\n      %s' % (f['file'], f['line'], f['text'][:170]))
                continue
            seen.add(f['key'])
            e = allow.get(f['key'])
            if e is None or not e.get('reason') or e['reason'].strip().upper().startswith('TODO'):
                problems.append('%s:%d path call with a value that is not a recognized safe form and not allow-listed (key %s)\n      %s' % (f['file'], f['line'], f['key'], f['text'][:170]))
                if a.print_new:
                    print(json.dumps({f['key']: {'snippet': f['text'][:160], 'reason': 'TODO'}}, indent=1)[2:-2] + ',')
    for k in allow:
        if k not in seen:
            problems.append('allow-list entry %s matches no call any more: review it and remove or re-key it' % k)
    print('path lint: %d calls are allow-listed with a reason, %d problem(s)' % (len([k for k in allow if k in seen]), len(problems)))
    for p in problems[:80]:
        print('  ' + p)
    if len(problems) > 80:
        print('  ... %d more' % (len(problems) - 80))
    return 1 if problems else 0


if __name__ == '__main__':
    sys.exit(main())
