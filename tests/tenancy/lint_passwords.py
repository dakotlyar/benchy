#!/usr/bin/env python3
"""Password writer lint (the legacy hash drop, schema 67): the legacy hash functions are called only inside phoromatic_passwords, so every place that sets a password
takes its column values from phoromatic_passwords::columns_for() (or store_root()) and none of them can write a legacy hash after the drop by calling legacy_user() or
legacy_root() itself; and nothing outside it writes the Password column of phoromatic_users with anything but a value from columns_for().

  lint_passwords.py [--root DIR]

Checks, each a plain search of the PHP source with comments removed:
  1. legacy_user( and legacy_root( appear nowhere but in objects/phoromatic/phoromatic_passwords.php
  2. every UPDATE or INSERT of phoromatic_users that sets Password (not PasswordHash and the like) binds it to a columns_for() value: the file calls columns_for(
  3. the sha256 of 'PTS' . a password (root's legacy value) is built nowhere but there
Exit 1 with one line per problem.
"""
import argparse
import os
import re
import sys

import phpscan

HERE = os.path.dirname(os.path.abspath(__file__))
HOME = 'pts-core/objects/phoromatic/phoromatic_passwords.php'


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--root', default=os.path.normpath(os.path.join(HERE, '..', '..')))
    a = ap.parse_args()
    problems = []
    seen_home = False
    for rel in phpscan.php_files(a.root):
        src = phpscan.strip_comments(open(os.path.join(a.root, rel), errors='replace').read())
        if rel == HOME:
            seen_home = True
            continue
        for m in re.finditer(r'\blegacy_(user|root)\s*\(', src):
            problems.append('%s:%d calls legacy_%s(): only phoromatic_passwords may (use columns_for() or store_root())' % (rel, phpscan.line_of(src, m.start()), m.group(1)))
        for m in re.finditer(r"hash\(\s*'sha256'\s*,\s*'PTS'\s*\.", src):
            problems.append("%s:%d builds root's legacy hash itself" % (rel, phpscan.line_of(src, m.start())))
        for m in re.finditer(r"(UPDATE\s+phoromatic_users\s+SET[^;]*?\bPassword\s*=|INSERT\s+(?:OR\s+\w+\s+)?INTO\s+phoromatic_users\s*\([^)]*\bPassword\b)", src, re.I | re.S):
            if 'columns_for(' not in src:
                problems.append('%s:%d writes phoromatic_users.Password without columns_for()' % (rel, phpscan.line_of(src, m.start())))
    if not seen_home:
        problems.append(HOME + ' not found')
    for p in problems:
        print(p)
    print('lint_passwords: %s' % ('FAIL (%d)' % len(problems) if problems else 'ok'))
    return 1 if problems else 0


if __name__ == '__main__':
    sys.exit(main())
