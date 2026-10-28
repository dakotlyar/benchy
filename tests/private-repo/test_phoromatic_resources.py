"""phoromatic.php only includes the communication resources it lists (phoromatic_server::client_resources()).

Before 0.7.4 the r= field was pasted into a path ('../communication-resources/' . r . '.php') and any existing *.php
file it reached was included, for any approved system. Run through tests/private-repo/run.sh (http).
"""
import json
import subprocess
import unittest
import urllib.parse
import urllib.request

from test_private_repo_http import AID, CORE, SRV, BASE, new_machine

RESOURCE_DIR = '/bench/pts-core/phoromatic/communication-resources'
CANARY_DIR = '/tmp/qc-lfi'
CANARY = CANARY_DIR + '/canary'          # canary.php, outside the resource directory
HIT = CANARY_DIR + '/hit'                # written if canary.php is ever included


def srv(cmd):
    return subprocess.run(['docker', 'exec', SRV, 'sh', '-c', cmd], capture_output=True, text=True).stdout


def post(msi, r):
    fields = [('aid', AID), ('msi', msi), ('pts_core', CORE)]
    fields += [('r[]', x) for x in r] if isinstance(r, list) else [('r', r)]
    data = urllib.parse.urlencode(fields).encode()
    with urllib.request.urlopen(urllib.request.Request(BASE + '/phoromatic.php', data=data), timeout=60) as resp:
        return resp.read().decode('utf-8', 'replace')


class ResourceWhitelist(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.msi = new_machine(approved=True)
        cls.files = sorted(f[:-4] for f in srv('ls %s' % RESOURCE_DIR).split() if f.endswith('.php'))
        srv("mkdir -p %s && rm -f %s && printf '<?php file_put_contents(\"%s\", \"included\"); echo \"CANARY-INCLUDED\";' > %s.php && chmod -R a+rwX %s"
            % (CANARY_DIR, HIT, HIT, CANARY, CANARY_DIR))

    def assert_refused(self, r, body):
        self.assertNotIn('CANARY-INCLUDED', body, r)
        self.assertTrue(body.startswith('{'), 'no PHP notice or other output before the reply for %r: %s' % (r, body[:200]))
        reply = json.loads(body)['phoromatic']
        self.assertTrue(reply.get('error', '').startswith('Unknown Resource: '), '%r: %s' % (r, body[:300]))
        self.assertEqual(reply.get('policy'), {'no_phone_home': True}, 'the refusal still carries the phone-home policy')
        self.assertEqual(srv('cat %s 2>/dev/null' % HIT), '', 'canary.php was included for %r' % (r,))

    def test_every_resource_on_disk_is_served(self):
        self.assertEqual(len(self.files), 17, self.files)
        for r in self.files:
            with self.subTest(r):
                body = post(self.msi, r)
                self.assertNotIn('Unknown Resource', body)

    def test_an_unknown_name_gets_the_same_reply_as_before(self):
        body = post(self.msi, 'no_such_resource')
        self.assert_refused('no_such_resource', body)
        self.assertEqual(json.loads(body)['phoromatic']['error'], 'Unknown Resource: no_such_resource')

    def test_traversal_absolute_nul_case_and_array_variants_are_refused(self):
        depth = '../' * 12
        variants = [
            depth + CANARY.lstrip('/'),                    # was included before 0.7.4
            '../../../../../../../..' + CANARY,
            CANARY,                                        # absolute
            '../communication-resources/ping',
            './ping', 'ping/', 'ping.php', ' ping', 'ping ', 'ping\n',
            'ping\x00', 'ping\x00.txt', depth + CANARY.lstrip('/') + '\x00',
            'PING', 'Ping', 'pInG',
            '..\\..\\ping', '../public_html/phoromatic', '../../pts-core',
            '%2e%2e/%2e%2e/ping',
        ]
        for r in variants:
            with self.subTest(repr(r)):
                self.assert_refused(r, post(self.msi, r))
        for r in (['ping'], [depth + CANARY.lstrip('/')]):
            with self.subTest(repr(r)):
                self.assert_refused(r, post(self.msi, r))


if __name__ == '__main__':
    unittest.main()
