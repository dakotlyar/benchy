"""Small PHP source scanner shared by the tenancy lints: comment-free source, string-literal concatenation chains, balanced text.

It is not a PHP parser. It knows enough to find SQL and path expressions the way this code base writes them (single quoted strings joined
with dots, variables and calls in between), and it keeps positions so a finding can say where it is.
"""
import os
import re

ROOT_DEFAULT = os.path.normpath(os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '..'))


# Not part of the server: the exported standalone result viewer ships with a database of its own (a different SQLite file), and is
# not served by the Phoromatic server.
EXCLUDED = ('pts-core/phoromatic/export-public-viewer',)


def php_files(root, subdirs=('pts-core',)):
    for sub in subdirs:
        for dirpath, dirnames, filenames in os.walk(os.path.join(root, sub)):
            dirnames[:] = [d for d in dirnames if d not in ('.git',) and os.path.relpath(os.path.join(dirpath, d), root) not in EXCLUDED]
            for f in sorted(filenames):
                if f.endswith('.php'):
                    yield os.path.relpath(os.path.join(dirpath, f), root)


def strip_comments(src):
    """Same length as the input: comments become spaces (newlines kept), string contents are left alone."""
    out = []
    i, n = 0, len(src)
    while i < n:
        c = src[i]
        if c in '\'"':
            j = i + 1
            while j < n and src[j] != c:
                j += 2 if src[j] == '\\' else 1
            out.append(src[i:j + 1])
            i = j + 1
        elif src.startswith('//', i) or (c == '#' and not src.startswith('#[', i)):
            j = src.find('\n', i)
            j = n if j < 0 else j
            out.append(' ' * (j - i))
            i = j
        elif src.startswith('/*', i):
            j = src.find('*/', i + 2)
            j = n if j < 0 else j + 2
            out.append(re.sub(r'[^\n]', ' ', src[i:j]))
            i = j
        else:
            out.append(c)
            i += 1
    return ''.join(out)


def line_of(src, pos):
    return src.count('\n', 0, pos) + 1


def literal_at(src, i):
    """(text, end) of the string literal starting at src[i] (a quote), with escapes resolved and PHP variables inside double quotes as ?."""
    q = src[i]
    j = i + 1
    buf = []
    while j < len(src) and src[j] != q:
        if src[j] == '\\' and j + 1 < len(src):
            nxt = src[j + 1]
            if q == "'":
                buf.append(nxt if nxt in "'\\" else '\\' + nxt)
            else:
                buf.append({'n': '\n', 't': '\t', '0': '\0', '"': '"', '\\': '\\', '$': '$'}.get(nxt, '\\' + nxt))
            j += 2
        else:
            buf.append(src[j])
            j += 1
    text = ''.join(buf)
    if q == '"':
        text = re.sub(r'\{\$[^}]*\}|\$\w+(?:\[[^\]]*\]|->\w+)*', '?', text)
    return text, j + 1


def balanced(src, i, open_c='(', close_c=')'):
    """Index just past the group that opens at src[i]."""
    depth = 0
    j = i
    while j < len(src):
        c = src[j]
        if c in '\'"':
            _, j = literal_at(src, j)
            continue
        if c == open_c:
            depth += 1
        elif c == close_c:
            depth -= 1
            if depth == 0:
                return j + 1
        j += 1
    return len(src)


def operand_end(src, i):
    """End of one concatenation operand starting at i (a variable, call, cast, parenthesised expression or number)."""
    j = i
    while j < len(src):
        c = src[j]
        if c in '\'"':
            _, j = literal_at(src, j)
            continue
        if c in '([':
            j = balanced(src, j, c, ')' if c == '(' else ']')
            continue
        if c in '.;,)]}{' and not (c == '.' and j + 1 < len(src) and src[j + 1].isdigit() and src[j - 1].isdigit()):
            return j
        if src.startswith('?', j) or src.startswith(' ? ', j):
            return j
        j += 1
    return j


def chains(src):
    """Yield (start, end, text, parts): each maximal 'literal . operand . literal ...' concatenation that starts with a string literal.

    text has every non-literal operand replaced by ?; parts is the list of (kind, text) pieces."""
    i, n = 0, len(src)
    while i < n:
        c = src[i]
        if c not in '\'"':
            i += 1
            continue
        start = i
        # a literal that is the continuation of an operand chain that began with a variable is not a chain start
        k = start - 1
        while k >= 0 and src[k] in ' \t\r\n':
            k -= 1
        text, j = literal_at(src, i)
        parts = [('lit', text)]
        buf = [text]
        while True:
            m = re.compile(r'\s*\.(?!=)\s*').match(src, j)
            if not m:
                break
            k = m.end()
            if k >= n:
                break
            if src[k] in '\'"':
                t, j2 = literal_at(src, k)
                parts.append(('lit', t))
                buf.append(t)
                j = j2
            else:
                e = operand_end(src, k)
                if e == k:
                    break
                parts.append(('expr', src[k:e].strip()))
                buf.append('?')
                j = e
        yield start, j, ''.join(buf), parts
        i = j
