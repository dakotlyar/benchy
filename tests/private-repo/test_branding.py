"""The 0.10.0 branding: no Phoronix Test Suite logo, string or mark in the pages, graphs and exports Benchy shows, and the new head links,
icons, notice line and user agent are there. Text and artwork only: nothing here may change a protocol field, endpoint or XML element, so
the v1/v2 protocol tests run unchanged next to this one.

Run through tests/private-repo/run.sh (http, or QC_HTTP_PATTERN=test_branding.py run.sh http). The one place "Phoronix Test Suite" is
allowed to appear is the GPL notice line (it names the project Benchy is based on), so every check removes that line first.
"""
import base64
import hashlib
import os
import re
import subprocess
import unittest
import uuid

from bench_helpers import COMPOSITE, new_system, req, result_row
from test_private_repo_http import AID, BASE, SRV
from test_private_repo_web import Browser, USER, PASS

TREE = os.environ['QC_TREE']
IMAGE = os.environ['QC_IMAGE']
OLD_MARK = 'm74 22v9'                      # the first segment of the "ptsl|" path
NEW_MARK = 'M1 11H27A1 1 0 0 1 28 12'      # the first rect of the benchy mark, drawn as a path
NOTICE = re.compile(r'Benchy is free software under the GNU General Public License, version 3, and comes with no warranty\. It is based on the Phoronix Test Suite, copyright (?:&copy;|©) 2008-\d{4} Phoronix Media\. Modified by Dave Kotlyar, 2026\. Source code and license: github\.com/dakotlyar/benchy')
LOCKUP = 'aria-label="Benchy Phoromatic"'


def read(path):
    with open(path, errors='replace') as f:
        return f.read()


def without_notice(text):
    return NOTICE.sub('', text)


def fixture_composite(client='Phoronix Test Suite v10.8.4'):
    """A result made by an old client (EC-2): the TestClient names the upstream program; two systems so the system table is drawn too"""
    ident = 'brand-' + uuid.uuid4().hex[:6]
    xml = COMPOSITE % {'identifier': ident, 'title': 'brand check ' + ident, 'value': '12.5'}
    return xml.replace('<TestClient>qc</TestClient>', '<TestClient>%s</TestClient>' % client)


def docker_run(script, timeout=240):
    """A throw-away container, unprivileged, no network, the committed tree read-only; a memory cap and output cap like the other checks"""
    r = subprocess.run(['docker', 'run', '--rm', '--network', 'none', '-m', '1g', '-u', '1000', '-e', 'HOME=/home/bob', '-v', TREE + ':/bench:ro', IMAGE, 'sh', '-c', script],
                       capture_output=True, text=True, timeout=timeout, stdin=subprocess.DEVNULL)
    return (r.stdout + r.stderr)[:200000]


class Pages(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.anon = Browser()
        cls.admin = Browser().login(USER, PASS)

    def head_checks(self, body):
        self.assertRegex(body, r'<link rel="icon" href="favicon\.ico\?v=\d+"', 'EC-4: the favicon link carries ?v=')
        self.assertRegex(body, r'<link rel="apple-touch-icon" href="apple-touch-icon\.png', 'the touch icon is linked')
        self.assertNotIn('favicon.svg', body, 'no SVG favicon (the ICO has the hand-snapped sizes)')
        self.assertRegex(body, r'<meta name="keywords" content="Benchy,')
        self.assertRegex(body, r'<meta name="Description" content="Benchy Phoromatic')

    def test_login_page(self):
        status, _, body = self.anon.get('/')
        self.assertEqual(status, 200)
        self.head_checks(body)
        self.assertIn(LOCKUP, body, 'the inline banner lockup')
        self.assertIn('var(--ph-brand', body, 'the banner fills come from the --ph-* tokens')
        self.assertNotIn('phoromatic_logo.svg', body)
        self.assertRegex(body, NOTICE, 'the GPL notice line is in the footer')
        self.assertNotIn('All rights reserved', body)
        self.assertNotIn('Phoronix Test Suite', without_notice(body))
        self.assertNotIn(OLD_MARK, body, 'the footer no longer carries the ptsl mark')

    def test_logged_in_page(self):
        status, _, body = self.admin.get('/?main')
        self.assertEqual(status, 200)
        self.assertIn(LOCKUP, body)
        self.assertRegex(body, NOTICE)
        self.assertNotIn('Phoronix Test Suite', without_notice(body))
        self.assertNotIn(OLD_MARK, body)

    def test_icons_are_served(self):
        for path, magic in (('/favicon.ico?v=10860', b'\x00\x00\x01\x00'), ('/apple-touch-icon.png', b'\x89PNG')):
            with self.subTest(path):
                r = subprocess.run(['curl', '-s', '-o', '-', '-w', '%{http_code}', BASE + path], capture_output=True)
                self.assertTrue(r.stdout.endswith(b'200'), path)
                self.assertTrue(r.stdout.startswith(magic), path)
        r = subprocess.run(['curl', '-s', '-o', '/dev/null', '-w', '%{http_code}', BASE + '/images/phoromatic_logo.svg'], capture_output=True, text=True)
        self.assertNotEqual(r.stdout, '200', 'the PTS banner file is gone')

    def test_css_tokens_and_the_graph_invert_stay(self):
        css = self.anon.get('/phoromatic.css')[2]
        self.assertEqual(len(re.findall(r'--ph-brand:', css)), 3, 'one brand token in each of the three token blocks')
        self.assertIn('--ph-brand: #0b7480', css)
        self.assertIn('--ph-brand: #138896', css)
        self.assertNotRegex(css, r'img\.img_logo_pg\s*\{\s*(/\*.*?\*/\s*)?filter', 'the banner is inline SVG now, so no invert on it')
        self.assertIn('invert(.895) hue-rotate(180deg)', css, 'graphs keep the dark-mode invert')

    def test_result_page_graphs_and_the_old_client_name(self):
        """EC-1, EC-2, EC-3: a result whose TestClient says 'Phoronix Test Suite v10.8.4', rendered by the server"""
        msi, sid = new_system()
        xml = fixture_composite()
        fields = {'composite_xml': base64.b64encode(xml.encode()).decode(), 'composite_xml_hash': hashlib.sha1(xml.encode()).hexdigest()}
        uid = req(msi, 'result_upload', sched='', bid='', ts='', **fields).get('upload_id')
        self.assertTrue(uid)
        pprid = result_row(uid)['PPRID']
        status, _, page = self.admin.get('/?result/' + pprid)
        self.assertEqual(status, 200)
        svgs = re.findall(r'<svg.*?</svg>', page, flags=re.S)
        self.assertGreaterEqual(len(svgs), 1, 'the page has graphs')
        graphs = '\n'.join(svgs)
        self.assertNotIn('Phoronix Test Suite', graphs)
        self.assertNotIn('Phoronix', graphs)
        self.assertNotIn(OLD_MARK, graphs)
        self.assertIn(NEW_MARK, graphs, 'the benchy mark path is in the graph')
        self.assertNotRegex(graphs, r'<text[^>]*>\s*benchy\s*</text>', 'EC-3: the wordmark is outlined paths, not text')
        self.assertNotIn('phoronix-test-suite.com', graphs)
        self.assertNotIn('Phoronix Test Suite', without_notice(page))
        self.assertIn(LOCKUP, page)


class Exports(unittest.TestCase):
    """The CLI exports, in a container of their own with a throw-away HOME (nothing touches a real ~/.benchy)"""

    SETUP = ('mkdir -p /home/bob/.benchy/test-results/brandcheck && cat > /home/bob/.benchy/test-results/brandcheck/composite.xml <<"EOX"\n%s\nEOX\ncd /tmp && cp -r /bench /tmp/w && cd /tmp/w\n')

    def composite(self):
        xml = fixture_composite()
        # a second system, so the systems table and a comparison graph are drawn
        return xml.replace('</System>\n  <Result>', '</System>\n  <System><Identifier>second</Identifier><Hardware>Processor: QC2</Hardware><Software>OS: QC</Software><User>qc</User><TimeStamp>2026-01-01 00:00:00</TimeStamp><TestClientVersion>1</TestClientVersion><Notes></Notes></System>\n  <Result>', 1).replace(
            '</Entry></Data></Result>', '</Entry><Entry><Identifier>second</Identifier><Value>14.5</Value><RawString>14.5</RawString></Entry></Data></Result>', 1)

    def test_html_and_pdf_export_and_an_old_graph_config(self):
        script = (self.SETUP % self.composite() +
                  'sh /tmp/w/benchy result-file-to-html brandcheck >/dev/null 2>&1; cp /home/bob/brandcheck.html /tmp/first.html\n'
                  # graph-config.json written by an older release pinned the old default watermark: it must not bring the text back
                  'sed -i \'s/"watermark":""/"watermark":"Phoronix Test Suite"/\' /home/bob/.benchy/graph-config.json\n'
                  'grep -c "Phoronix Test Suite" /home/bob/.benchy/graph-config.json | sed "s/^/PINNED=/"\n'
                  'sh /tmp/w/benchy result-file-to-html brandcheck >/dev/null 2>&1\n'
                  'sh /tmp/w/benchy result-file-to-pdf brandcheck >/dev/null 2>&1\n'
                  'echo "===HTML"; cat /home/bob/brandcheck.html; echo; echo "===PDF"; grep -a -o "\\(Producer\\|Author\\|Subject\\) ([^)]*)" /home/bob/brandcheck.pdf\n')
        out = docker_run(script)
        self.assertIn('===HTML', out, out[:2000])
        pinned = re.search(r'PINNED=(\d+)', out)
        self.assertTrue(pinned and int(pinned.group(1)) >= 1, 'the fixture really pinned the old watermark text in graph-config.json: ' + out[:500])
        html = out.split('===HTML', 1)[1].split('===PDF', 1)[0]
        pdf = out.split('===PDF', 1)[1]
        self.assertIn(NEW_MARK, html, 'the export has graphs with the benchy mark')
        self.assertNotIn(OLD_MARK, html)
        self.assertNotIn('phoronix-test-suite.com', html)
        self.assertRegex(html, NOTICE, 'the export footer carries the notice line')
        self.assertNotIn('Phoronix Test Suite', without_notice(html), 'HTML export (and the old pinned watermark) shows no Phoronix Test Suite text')
        self.assertNotRegex(html, r'<text[^>]*>\s*benchy\s*</text>')
        self.assertRegex(pdf, r'Author \(Benchy\)')
        self.assertRegex(pdf, r'Producer \(Benchy\)')
        self.assertNotIn('Phoronix', pdf)


class Source(unittest.TestCase):
    """What is in the committed tree"""

    def php_files(self):
        for root, _, files in os.walk(os.path.join(TREE, 'pts-core')):
            if '/test-profiles' in root or '/openbenchmarking.org' in root:
                continue
            for f in files:
                if f.endswith(('.php', '.html', '.css', '.js', '.svg')):
                    yield os.path.join(root, f)

    def test_the_old_mark_is_gone_everywhere(self):
        hits = [p for p in self.php_files() if OLD_MARK in read(p)]
        self.assertEqual(hits, [])

    def test_every_graph_class_draws_the_one_helper(self):
        callers = [p for p in self.php_files() if 'draw_brand_mark(' in read(p) and 'pts_Graph' in p]
        names = sorted(os.path.basename(p) for p in callers)
        for want in ('pts_graph_core.php', 'pts_Table.php', 'pts_graph_radar_chart.php', 'pts_graph_run_vs_run.php', 'pts_graph_mini_overview.php', 'pts_ResultFileCompactSystemsTable.php', 'pts_SideViewTable.php'):
            self.assertIn(want, names)

    def test_no_pts_logo_or_phoronix_links_in_graph_code(self):
        for p in self.php_files():
            if 'pts_Graph' not in p:
                continue
            body = re.sub(r'^<\?php\s*/\*.*?\*/', '', read(p), count=1, flags=re.S)   # skip the license header: it names the upstream project and stays
            self.assertNotIn('phoronix-test-suite.com', body, p)
            self.assertNotIn('openbenchmarking.org/static/images/pts', body, p)

    def test_user_agent_and_no_upstream_version_calls(self):
        out = docker_run("cd /tmp && php -r '$_SERVER[\"argv\"]=array(\"x\"); define(\"PTS_MODE\",\"CLIENT\"); define(\"PTS_AUTO_LOAD_OBJECTS\", true); include(\"/bench/pts-core/pts-core.php\"); pts_core::init(); echo \"UA=\" . pts_network::user_agent() . PHP_EOL;' 2>&1 | tail -3")
        self.assertRegex(out, r'UA=Benchy/\d+\.\d+\.\d+', out)
        for rel in ('pts-core/modules/update_checker.php', 'pts-core/phoromatic/pages/phoromatic_main.php', 'pts-core/commands/network_setup.php', 'pts-core/commands/network_info.php', 'pts-core/objects/pts_network.php'):
            text = read(os.path.join(TREE, rel))
            self.assertNotIn('phoronix-test-suite.com/PTS', text, rel)
            self.assertNotIn('LATEST_CORE', text.replace('class ', ''), rel)
            self.assertNotIn('update-check.php', text, rel)


if __name__ == '__main__':
    unittest.main()
