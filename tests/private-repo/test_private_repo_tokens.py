"""Phase 1 QC for the private-repo admin work: repository tokens on upload/delete/rebuild.

Run through tests/private-repo/run.sh (it provides the QC_* environment, including QC_REPO_TOKEN created with the server-side
private-repo-token-create command). The token commands are run on the server container the way an administrator would.
"""
import base64
import hashlib
import json
import os
import re
import subprocess
import unittest
import urllib.request
import uuid

from test_private_repo_http import (BASE, AID, REPO_DIR, REPO_TOKEN, SRV, call, new_machine, raw_post, sql, srv_sh, test_zip, uniq, upload)

BADTOKEN = 'bpr_' + 'A' * 43
SYSTEM_IP = '127.0.0.%d'


def benchy_on_server(*args):
    return subprocess.run(['docker', 'exec', SRV, 'sh', '/bench/benchy'] + list(args), capture_output=True, text=True).stdout


def create_token(label):
    out = benchy_on_server('private-repo-token-create', label)
    m = re.search(r'bpr_[A-Za-z0-9_-]{43}', out)
    assert m, out
    return m.group(0), int(re.search(r'Token #(\d+)', out).group(1))


def sql_exec(stmt):
    php = '$d=new SQLite3("/var/lib/benchy/phoromatic/phoromatic.db"); echo @$d->exec($argv[1]) ? "ok" : "refused";'
    return subprocess.run(['docker', 'exec', SRV, 'php', '-r', php, stmt], capture_output=True, text=True).stdout


def audit(**where):
    cond = ' AND '.join("%s='%s'" % (k, v) for k, v in where.items())
    return sql('SELECT * FROM phoromatic_private_repo_audit WHERE %s ORDER BY AuditID' % cond)


def as_ip(ip, msi, resource, **fields):
    """Run the real phoromatic.php in a CLI process with a chosen REMOTE_ADDR (the lockout is per source IP)."""
    f = {'r': resource, 'aid': AID, 'msi': msi, 'pts_core': '10860'}
    f.update(fields)
    driver = ('$raw=stream_get_contents(STDIN); $_POST=$_REQUEST=json_decode($raw,true); $_SERVER["REMOTE_ADDR"]=$argv[1];'
              'chdir("/bench/pts-core/phoromatic/public_html"); include "phoromatic.php";')
    out = subprocess.run(['docker', 'exec', '-i', SRV, 'php', '-d', 'display_errors=0', '-r', driver, '--', ip], input=json.dumps(f),
                         capture_output=True, text=True).stdout
    return json.loads(out[out.index('{"phoromatic"'):])['phoromatic']


class Tokens(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.msi = new_machine()
        cls.system_id = sql("SELECT SystemID FROM phoromatic_systems WHERE MachineSelfID='%s'" % cls.msi)[0]['SystemID']

    def test_no_token_gets_the_clear_error_on_all_three_endpoints(self):
        name = uniq('notok')
        z = test_zip()
        out = call('private_repo_upload', self.msi, kind='test', name=name, zip=base64.b64encode(z).decode(), zip_sha1=hashlib.sha1(z).hexdigest(),
                   commit_description='x', overwrite='0', rt='')
        self.assertIn('repository token is required', out['error'])
        self.assertIn('private-repo-login', out['error'])
        self.assertEqual(srv_sh('ls %stests/%s 2>/dev/null' % (REPO_DIR, name)).strip(), '')
        self.assertIn('error', call('private_repo_delete', self.msi, kind='test', name=name, version='1.0.0', rt=''))
        self.assertIn('repository token is required', call('private_repo_rebuild', self.msi, rt='')['error'])
        # three refusals, but "missing" is recorded once per machine and hour
        rows = [r for r in audit(Action='cli_auth_failed', Actor=self.system_id) if '"reason":"missing"' in r['Detail']]
        self.assertEqual(len(rows), 1)
        self.assertEqual((rows[0]['ActorType'], rows[0]['AccountID']), ('machine', AID))

    def test_missing_tokens_never_lock_anyone_out(self):
        msi = new_machine()
        sid = sql("SELECT SystemID FROM phoromatic_systems WHERE MachineSelfID='%s'" % msi)[0]['SystemID']
        for _ in range(12):
            self.assertIn('required', as_ip(SYSTEM_IP % 78, msi, 'private_repo_rebuild')['error'])
        self.assertEqual(len(audit(Action='cli_auth_failed', Actor=sid)), 1, 'repeats must not grow the audit table')
        self.assertIn('rebuild', as_ip(SYSTEM_IP % 78, msi, 'private_repo_rebuild', rt=REPO_TOKEN))

    def test_wrong_malformed_and_foreign_tokens_are_rejected_and_audited(self):
        for label, tok in (('wrong', BADTOKEN), ('malformed', 'hello'), ('prefix only', 'bpr_')):
            with self.subTest(label):
                before = len(audit(Action='cli_auth_failed', Actor=self.system_id))
                out = call('private_repo_rebuild', self.msi, rt=tok)
                self.assertIn('not accepted', out['error'])
                rows = audit(Action='cli_auth_failed', Actor=self.system_id)
                self.assertEqual(len(rows), before + 1)
                self.assertIn('"reason":"invalid"', rows[-1]['Detail'])
                self.assertNotIn(tok, json.dumps(rows[-1]))
        # an array-valued rt is "no token", not a crash
        data = ('r=private_repo_rebuild&aid=%s&msi=%s&pts_core=10860&rt[]=x' % (AID, self.msi)).encode()
        body = urllib.request.urlopen(urllib.request.Request(BASE + '/phoromatic.php', data=data), timeout=30).read().decode()
        self.assertIn('required', json.loads(body[body.index('{'):])['phoromatic']['error'])

    def test_token_of_another_account_is_rejected(self):
        srv_sh('curl -s -c /tmp/cj_tok localhost:8080/ >/dev/null; curl -s -b /tmp/cj_tok -X POST "localhost:8080/?register" '
               '-d register_username=tokiso -d register_password=qcpass123 -d register_password_confirm=qcpass123 -d register_email=t@example.com')
        other = [r['AccountID'] for r in sql('SELECT AccountID FROM phoromatic_accounts ORDER BY CreatedOn DESC') if r['AccountID'] != AID][0]
        msi2 = str(uuid.uuid4())
        call('private_repo_index', msi2, aid=other)
        sql("UPDATE phoromatic_systems SET State=1 WHERE MachineSelfID='%s'" % msi2)
        out = call('private_repo_rebuild', msi2, aid=other)   # account 1's token, account 2's approved machine
        self.assertIn('not accepted', out['error'])

    def test_valid_token_from_an_unapproved_machine_is_not_enough(self):
        msi = new_machine(approved=False)
        name = uniq('unappr')
        z = test_zip()
        out = call('private_repo_upload', msi, kind='test', name=name, zip=base64.b64encode(z).decode(), zip_sha1=hashlib.sha1(z).hexdigest(),
                   commit_description='x', overwrite='0')
        self.assertNotIn('upload', out)
        self.assertIn('Approval', json.dumps(out))
        self.assertEqual(srv_sh('ls %stests/%s 2>/dev/null' % (REPO_DIR, name)).strip(), '')
        self.assertNotIn('rebuild', call('private_repo_rebuild', msi))

    def test_token_use_is_labelled_in_the_audit_and_tracked(self):
        tok, tid = create_token('qc label one')
        name = uniq('lab')
        z = test_zip()
        out = call('private_repo_upload', self.msi, rt=tok, kind='test', name=name, zip=base64.b64encode(z).decode(),
                   zip_sha1=hashlib.sha1(z).hexdigest(), commit_description='x', overwrite='0')
        self.assertIn('upload', out, out)
        rows = audit(Name=name)
        self.assertEqual([r['Action'] for r in rows], ['upload_attempt', 'upload_committed'])
        self.assertEqual({r['ActorDetail'] for r in rows}, {'qc label one'})
        self.assertIn('delete', call('private_repo_delete', self.msi, rt=tok, kind='test', name=name, version='1.0.0'))
        self.assertEqual(audit(Name=name, Action='delete')[0]['ActorDetail'], 'qc label one')
        used = sql('SELECT LastUsedOn, LastUsedIP FROM phoromatic_private_repo_tokens WHERE TokenID=%d' % tid)[0]
        self.assertTrue(used['LastUsedOn'] and used['LastUsedIP'])

    def test_revocation_is_immediate_and_audited(self):
        tok, tid = create_token('qc revoke me')
        self.assertIn('rebuild', call('private_repo_rebuild', self.msi, rt=tok))
        out = benchy_on_server('private-repo-token-list')
        self.assertRegex(out, r'#%d  active  "qc revoke me"' % tid)
        self.assertIn('revoked', benchy_on_server('private-repo-token-revoke', str(tid)))
        self.assertIn('not accepted', call('private_repo_rebuild', self.msi, rt=tok)['error'])
        self.assertRegex(benchy_on_server('private-repo-token-list'), r'#%d  REVOKED' % tid)
        self.assertIn('No active token', benchy_on_server('private-repo-token-revoke', str(tid)))
        rows = audit(Action='token_revoked')
        self.assertEqual(json.loads(rows[-1]['Detail'])['token_id'], tid)
        self.assertEqual((rows[-1]['ActorType'], rows[-1]['Actor']), ('user', 'local:root'))

    def test_creation_is_audited_and_the_token_is_hashed_at_rest(self):
        tok, tid = create_token('qc hash check')
        rows = [r for r in audit(Action='token_created') if json.loads(r['Detail'])['token_id'] == tid]
        self.assertEqual(len(rows), 1)
        self.assertEqual(json.loads(rows[0]['Detail'])['label'], 'qc hash check')
        row = sql('SELECT * FROM phoromatic_private_repo_tokens WHERE TokenID=%d' % tid)[0]
        self.assertEqual(row['TokenHash'], hashlib.sha256(tok.encode()).hexdigest())
        self.assertEqual(row['AccountID'], AID)
        self.assertRegex(tok, r'^bpr_[A-Za-z0-9_-]{43}$')
        # the raw value is nowhere on the server: not in the database (or its WAL), the logs or the audit rows
        for t in (tok, REPO_TOKEN):
            self.assertEqual(srv_sh("grep -rlaF '%s' /var/lib/benchy /var/log /var/cache/benchy /root/.benchy 2>/dev/null" % t).strip(), '', 'raw token found on disk')
        self.assertNotIn(tok, json.dumps(sql('SELECT * FROM phoromatic_private_repo_audit')))
        self.assertNotIn(tok, benchy_on_server('private-repo-token-list'))

    def test_creation_and_revocation_fail_closed_when_the_audit_cannot_be_written(self):
        before = sql('SELECT COUNT(*) AS n FROM phoromatic_private_repo_tokens')[0]['n']
        self.assertEqual(sql_exec("CREATE TRIGGER qc_tok_block BEFORE INSERT ON phoromatic_private_repo_audit WHEN NEW.Action IN ('token_created','token_revoked') BEGIN SELECT RAISE(ABORT,'qc'); END"), 'ok')
        self.addCleanup(sql_exec, 'DROP TRIGGER IF EXISTS qc_tok_block')
        out = benchy_on_server('private-repo-token-create', 'qc blocked')
        self.assertIn('ERROR', out)
        self.assertNotRegex(out, r'bpr_')
        self.assertEqual(sql('SELECT COUNT(*) AS n FROM phoromatic_private_repo_tokens')[0]['n'], before, 'no token row may survive')
        sql_exec('DROP TRIGGER qc_tok_block')
        tok, tid = create_token('qc keep me')
        sql_exec("CREATE TRIGGER qc_tok_block BEFORE INSERT ON phoromatic_private_repo_audit WHEN NEW.Action='token_revoked' BEGIN SELECT RAISE(ABORT,'qc'); END")
        self.assertIn('ERROR', benchy_on_server('private-repo-token-revoke', str(tid)))
        self.assertIn('rebuild', call('private_repo_rebuild', self.msi, rt=tok), 'a token whose revocation could not be audited stays valid')

    def test_label_is_required(self):
        out = benchy_on_server('private-repo-token-create')
        self.assertIn('Usage', out)

    def test_ten_wrong_tokens_lock_the_address_for_everyone_behind_it(self):
        ip = SYSTEM_IP % 77
        msi = new_machine()
        for i in range(10):
            self.assertIn('not accepted', as_ip(ip, msi, 'private_repo_rebuild', rt=BADTOKEN)['error'])
        self.assertEqual(len(audit(Action='cli_auth_failed', SourceIP=ip)), 10)
        # now even the right token is refused from that address, with the same message, and without piling up more rows
        out = as_ip(ip, msi, 'private_repo_rebuild', rt=REPO_TOKEN)
        self.assertRegex(out['error'], r'Too many failed .* try again in about \d+ minute')
        self.assertEqual(len(audit(Action='cli_auth_failed', SourceIP=ip)), 10)
        # another address is unaffected
        self.assertIn('rebuild', as_ip(SYSTEM_IP % 76, msi, 'private_repo_rebuild', rt=REPO_TOKEN))

    def test_lockout_expires_and_reports_the_wait(self):
        msi = new_machine()

        php = ('$d=new SQLite3("/var/lib/benchy/phoromatic/phoromatic.db");'
               'for($i=0;$i<10;$i++){$d->exec(sprintf("INSERT INTO phoromatic_private_repo_audit (AccountID, EventTime, ActorType, Actor, SourceIP, Action, Detail) '
               'VALUES (\'%s\', \'%s\', \'machine\', \'qc\', \'%s\', \'cli_auth_failed\', \'{\\"reason\\":\\"invalid\\"}\')", $argv[1], date("Y-m-d H:i:s", time()-(int)$argv[2]-$i), $argv[3]));}')

        def failures(ip, minutes_ago):
            subprocess.run(['docker', 'exec', SRV, 'php', '-r', php, AID, str(int(minutes_ago * 60)), ip], capture_output=True)

        # ten failures that are six minutes old: outside the five-minute window, so no lockout
        failures(SYSTEM_IP % 75, 6)
        self.assertIn('rebuild', as_ip(SYSTEM_IP % 75, msi, 'private_repo_rebuild', rt=REPO_TOKEN))
        # ten failures four and a half minutes old: still locked, and the message says it ends in about a minute
        failures(SYSTEM_IP % 74, 4.5)
        out = as_ip(SYSTEM_IP % 74, msi, 'private_repo_rebuild', rt=REPO_TOKEN)
        self.assertIn('try again in about 1 minute', out['error'])


class ServerCommandSafety(unittest.TestCase):
    """The token commands share the database and audit log with the service, so they refuse to run as anyone else."""

    def run_as(self, uid, *args):
        r = subprocess.run(['docker', 'exec', '-u', str(uid), '-e', 'HOME=/home/bob', SRV, 'sh', '/bench/benchy'] + list(args), capture_output=True, text=True)
        return r.stdout + r.stderr

    def test_other_users_are_refused_and_create_nothing(self):
        before = sql('SELECT COUNT(*) AS n FROM phoromatic_private_repo_tokens')[0]['n']
        for args in (('private-repo-token-list',), ('private-repo-token-create', 'qc other user'), ('private-repo-token-revoke', '1')):
            out = self.run_as(1000, *args)
            self.assertIn('No Phoromatic database was found', out, args)   # that user has another storage location
            self.assertIn('sudo -u', out)
        self.assertEqual(sql('SELECT COUNT(*) AS n FROM phoromatic_private_repo_tokens')[0]['n'], before)
        self.assertEqual(srv_sh('ls /home/bob/.benchy/phoromatic/phoromatic.db 2>&1 | grep -c "No such"').strip(), '1', 'a stray database was created')

    def test_database_owned_by_someone_else_is_refused(self):
        db = '/var/lib/benchy/phoromatic/phoromatic.db'
        srv_sh('chown 1000 %s' % db)
        self.addCleanup(srv_sh, 'chown root %s' % db)
        out = benchy_on_server('private-repo-token-create', 'qc wrong owner')
        self.assertIn('belongs to bob', out)
        self.assertIn('sudo -u bob env HOME=/home/bob benchy', out)
        self.assertNotRegex(out, r'bpr_')

    def test_audit_log_owned_by_someone_else_is_refused_with_the_fix(self):
        log = '/var/log/phoromatic-private-repo-audit.log'
        srv_sh('touch %s && chown 1000 %s' % (log, log))
        self.addCleanup(srv_sh, 'chown root %s' % log)
        out = benchy_on_server('private-repo-token-list')
        self.assertIn('sudo chown root ' + log, out)

    def test_busy_database_fails_closed(self):
        before = sql('SELECT COUNT(*) AS n FROM phoromatic_private_repo_tokens')[0]['n']
        # a second connection holds the write lock until its stdin is closed
        holder = subprocess.Popen(['docker', 'exec', '-i', SRV, 'php', '-r', '$d=new SQLite3("/var/lib/benchy/phoromatic/phoromatic.db"); $d->exec("BEGIN IMMEDIATE"); fgets(STDIN);'],
                                  stdin=subprocess.PIPE, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        import time
        try:
            time.sleep(2)
            out = benchy_on_server('private-repo-token-create', 'qc busy')
            self.assertIn('busy', out)
            self.assertNotRegex(out, r'bpr_')
            self.assertIn('busy', benchy_on_server('private-repo-token-revoke', '999999'))
        finally:
            holder.stdin.close()
            holder.wait()
        self.assertEqual(sql('SELECT COUNT(*) AS n FROM phoromatic_private_repo_tokens')[0]['n'], before)

    def test_mirror_log_says_attempt_then_committed_or_rolled_back(self):
        log = '/var/log/phoromatic-private-repo-audit.log'
        create_token('qc mirror ok')
        text = srv_sh('cat %s' % log)
        self.assertIn('token_created (attempt)', text)
        self.assertRegex(text, r'token_created committed: token \d+ \(qc mirror ok\)')
        self.assertEqual(sql_exec("CREATE TRIGGER qc_mirror_block BEFORE INSERT ON phoromatic_private_repo_audit WHEN NEW.Action='token_created' BEGIN SELECT RAISE(ABORT,'qc'); END"), 'ok')
        self.addCleanup(sql_exec, 'DROP TRIGGER IF EXISTS qc_mirror_block')
        self.assertIn('ERROR', benchy_on_server('private-repo-token-create', 'qc mirror fail'))
        self.assertIn('token_created ROLLED BACK', srv_sh('cat %s' % log))
        self.assertNotIn('committed: token', srv_sh('tail -n 1 %s' % log))


if __name__ == '__main__':
    unittest.main()
