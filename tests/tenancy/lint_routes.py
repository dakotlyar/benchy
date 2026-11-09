#!/usr/bin/env python3
"""Route inventory: every page, client resource, public endpoint and agent API route must be in routes.json with who may call it. A file or
route the matrix does not know fails the run (so a new route cannot ship without somebody deciding who reaches it and whether the canary
crawl covers it), and so does a matrix entry for a route that is gone.

  lint_routes.py [--root DIR]

routes.json:  pages / resources / public_html / v2, each name -> {who, note, crawl}. For pages, `crawl` lists the paths the canary crawl
requests as every tenant level with the other tenant's ids filled in ({T2_PPRID} and so on, see fixture.py); a page that is not crawled
says why in `crawl_reason`.
"""
import argparse
import json
import os
import re
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT_DEFAULT = os.path.normpath(os.path.join(HERE, '..', '..'))
WHO = {
    'pages': {'tenant', 'root', 'public'},
    'resources': {'approved v1 client', 'approved v1 client with a repository or bench token'},
    'public_html': {'front door', 'v1 client entry', 'public by design', 'agent API', 'public static file', 'event trigger', 'rss'},
    'v2': {'unauthenticated enrollment', 'agent token'},
}


def listing(root):
    base = os.path.join(root, 'pts-core/phoromatic')
    found = {'pages': set(), 'resources': set(), 'public_html': set(), 'v2': set()}
    for f in os.listdir(os.path.join(base, 'pages')):
        m = re.match(r'phoromatic_(.+)\.php\Z', f)
        if m:
            found['pages'].add(m.group(1))
    for f in os.listdir(os.path.join(base, 'communication-resources')):
        if f.endswith('.php'):
            found['resources'].add(f[:-4])
    for dirpath, dirnames, filenames in os.walk(os.path.join(base, 'public_html')):
        for f in filenames:
            found['public_html'].add(os.path.relpath(os.path.join(dirpath, f), os.path.join(base, 'public_html')))
    src = open(os.path.join(root, 'pts-core/objects/phoromatic/phoromatic_api_v2.php'), errors='replace').read()
    for m in re.finditer(r"'(/api/v2/[A-Za-z0-9/_-]*[A-Za-z0-9_-])'", src):
        found['v2'].add(m.group(1))
    m = re.search(r"preg_match\('#\^/api/v2/jobs/\(\[0-9\]\{1,18\}\)/\(([a-z|]+)\)", src)
    for r in (m.group(1).split('|') if m else []):
        found['v2'].add('/api/v2/jobs/{id}/' + r)
    return found


def client_resource_list(root):
    src = open(os.path.join(root, 'pts-core/objects/phoromatic/phoromatic_server.php'), errors='replace').read()
    m = re.search(r'function client_resources\(\)(.*?)\n\t}', src, re.S)
    return set(re.findall(r"'([a-z_0-9]+)'", m.group(1))) if m else set()


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--root', default=ROOT_DEFAULT)
    ap.add_argument('--routes', default=os.path.join(HERE, 'routes.json'))
    a = ap.parse_args()
    routes = json.load(open(a.routes))
    found = listing(a.root)
    problems = []
    for kind, names in found.items():
        have = set(routes.get(kind, {}))
        for n in sorted(names - have):
            problems.append('%s %s exists in the code but not in routes.json: say who may call it (and add it to the crawl matrix)' % (kind, n))
        for n in sorted(have - names):
            problems.append('routes.json lists %s %s, which is not in the code any more' % (kind, n))
        for n in sorted(have & names):
            e = routes[kind][n]
            if e.get('who') not in WHO[kind]:
                problems.append('%s %s: "who" must be one of %s' % (kind, n, sorted(WHO[kind])))
            if not e.get('note'):
                problems.append('%s %s: needs a note' % (kind, n))
            if kind == 'pages' and e.get('who') in ('tenant', 'root') and not e.get('crawl') and not e.get('crawl_reason'):
                problems.append('page %s is not in the canary crawl matrix (give it crawl paths, or a crawl_reason)' % n)
    listed = client_resource_list(a.root)
    if listed != found['resources']:
        problems.append('phoromatic_server::client_resources() and communication-resources/ disagree: %s' % sorted(listed ^ found['resources']))
    if not found['v2']:
        problems.append('could not read the agent API routes out of phoromatic_api_v2.php')
    print('route inventory: %d pages, %d client resources, %d public files, %d agent API routes, %d problem(s)' % (
        len(found['pages']), len(found['resources']), len(found['public_html']), len(found['v2']), len(problems)))
    for p in problems:
        print('  ' + p)
    return 1 if problems else 0


if __name__ == '__main__':
    sys.exit(main())
