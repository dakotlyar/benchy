"""Helpers for the tenancy tests: the scratch server, two accounts, a browser, SQL and file access through the server container.

Everything here talks to the throw-away server tenancy.sh starts (TN_SRV, TN_PORT); nothing touches this machine's Benchy.
"""
import base64
import hashlib
import http.cookiejar
import json
import os
import re
import subprocess
import urllib.error
import urllib.parse
import urllib.request
import uuid

SRV = os.environ.get('TN_SRV', '')
PORT = os.environ.get('TN_PORT', '')
BASE = 'http://127.0.0.1:%s' % PORT
HERE = os.path.dirname(os.path.abspath(__file__))
STATE = os.environ.get('TN_STATE', os.path.join(HERE, 'state.json'))
PW = 'tnpass12345'
ROOT_PW = 'tnrootpass1'
STORAGE = '/var/lib/benchy/phoromatic'
CORE = '10860'
LEVELS = (1, 2, 3, 10)
# Ids both accounts share on purpose, to catch queries that forget AccountID
SHARED_SCHEDULE = 5001


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k):
        return None


class Browser:
    def __init__(self, base=None):
        self.base = base or BASE
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar), NoRedirect)

    def request(self, path, data=None, headers=None, raw=None, timeout=60):
        """(status, headers, body); redirects are returned, not followed."""
        body = raw if raw is not None else (urllib.parse.urlencode(data).encode() if data is not None else None)
        req = urllib.request.Request(self.base + path, data=body, headers=headers or {})
        try:
            r = self.opener.open(req, timeout=timeout)
        except urllib.error.HTTPError as e:
            r = e
        return r.status if hasattr(r, 'status') else r.code, r.headers, r.read().decode('utf-8', 'replace')

    def get(self, path, **kw):
        return self.request(path, None, **kw)

    def post(self, path, data, **kw):
        return self.request(path, data, **kw)

    def login(self, user, password=PW):
        self.get('/')
        status, _, body = self.post('/?login', {'username': user, 'password': password})
        assert 'Log-In Successful' in body, 'log-in as %s failed: %s' % (user, re.sub(r'\s+', ' ', body)[:200])
        return self

    def login_root(self):
        self.get('/')
        self.post('/?login', {'username': 'rootadmin', 'password': ROOT_PW})
        assert 'Phoromatic Server Administration' in self.get('/?admin')[2], 'root log-in failed'
        return self

    def token(self, path='/?settings'):
        m = re.search(r'name="token_submit" value="([0-9a-f]+)"', self.get(path)[2])
        assert m, 'no CSRF token on ' + path
        return m.group(1)

    def multipart(self, path, fields, files, headers=None):
        boundary = '----tn' + uuid.uuid4().hex
        parts = []
        for k, v in fields.items():
            parts.append('--%s\r\nContent-Disposition: form-data; name="%s"\r\n\r\n%s\r\n' % (boundary, k, v))
        raw = ''.join(parts).encode()
        for k, (fname, content) in files.items():
            raw += ('--%s\r\nContent-Disposition: form-data; name="%s"; filename="%s"\r\nContent-Type: application/octet-stream\r\n\r\n' % (boundary, k, fname)).encode() + content + b'\r\n'
        raw += ('--%s--\r\n' % boundary).encode()
        h = {'Content-Type': 'multipart/form-data; boundary=' + boundary}
        h.update(headers or {})
        return self.request(path, raw=raw, headers=h)


def srv_sh(cmd, stdin=None):
    return subprocess.run(['docker', 'exec', '-i', SRV, 'sh', '-c', cmd], input=stdin, capture_output=True, text=True).stdout


def srv_php(code, stdin=None):
    return subprocess.run(['docker', 'exec', '-i', SRV, 'php', '-r', code], input=stdin, capture_output=True, text=True).stdout


def sql(query):
    php = ('$d=new SQLite3("%s/phoromatic.db");$d->busyTimeout(20000);$r=$d->query($argv[1]); if($r===false||$r===true){exit;}'
           'while($x=$r->fetchArray(SQLITE3_ASSOC)){echo json_encode($x),"\\n";}') % STORAGE
    out = subprocess.run(['docker', 'exec', SRV, 'php', '-r', php, query], capture_output=True, text=True).stdout
    return [json.loads(l) for l in out.splitlines() if l.strip()]


def sql_exec(query):
    php = '$d=new SQLite3("%s/phoromatic.db");$d->busyTimeout(20000);if($d->exec($argv[1])===false){fwrite(STDERR,$d->lastErrorMsg());exit(1);}' % STORAGE
    r = subprocess.run(['docker', 'exec', SRV, 'php', '-r', php, query], capture_output=True, text=True)
    assert r.returncode == 0, r.stderr
    return r.stdout


def state():
    return json.load(open(STATE))


def save_state(d):
    json.dump(d, open(STATE, 'w'), indent=1)


def register(username, aid, email=None, check=True):
    b = Browser()
    b.get('/')
    s, _, body = b.post('/?register', {'register_username': username, 'register_password': PW, 'register_password_confirm': PW,
                                       'register_email': email or username + '@example.com', 'seed_accountid': aid})
    assert not check or 'Account Created' in body, 'register %s: %s' % (username, re.sub(r'\s+', ' ', body)[:200])
    return 'Account Created' in body


def make_user(admin, username, level):
    tok = admin.token('/?users')
    admin.post('/?users', {'username': username, 'password': PW, 'confirm_password': PW, 'email': username + '@example.com', 'admin_level': str(level), 'token_submit': tok})
    row = sql("SELECT UserID, AdminLevel FROM phoromatic_users WHERE UserName='%s'" % username)
    assert row, 'user %s not created' % username
    return row[0]['UserID']


def logins(aid_key='T1'):
    """One logged-in browser per tenant level of account T1 (1, 2, 3, 10)."""
    st = state()
    out = {}
    for lvl in LEVELS:
        out[lvl] = Browser().login(st['users'][aid_key][str(lvl)])
    return out


def tree_manifest(path):
    """sha1 of the file list and content under a folder of the server (sorted), '' when it does not exist."""
    return srv_sh("cd '%s' 2>/dev/null && find . -type f -o -type d | sort | while read f; do if [ -f \"$f\" ]; then echo \"$f $(sha1sum < \"$f\")\"; else echo \"$f\"; fi; done | sha1sum" % path).strip()


def table_hash(aid):
    """sha1 of every row of account `aid` in every table that has an AccountID column."""
    php = r'''
    $d=new SQLite3("%s/phoromatic.db");$out=[];
    $t=$d->query("SELECT name FROM sqlite_master WHERE type='table' AND name LIKE 'phoromatic_%%' ORDER BY name");
    while($r=$t->fetchArray(SQLITE3_ASSOC)){
      $has=false;$c=$d->query("PRAGMA table_info(".$r['name'].")");
      while($x=$c->fetchArray(SQLITE3_ASSOC)){ if($x['name']=='AccountID') $has=true; }
      if(!$has) continue;
      $q=$d->prepare("SELECT * FROM ".$r['name']." WHERE AccountID = :a");$q->bindValue(':a',$argv[1]);$res=$q->execute();$rows=[];
      while($row=$res->fetchArray(SQLITE3_ASSOC)) $rows[]=json_encode($row);
      sort($rows);$out[$r['name']]=sha1(implode("\n",$rows)).':'.count($rows);
    }
    echo json_encode($out);
    ''' % STORAGE
    out = subprocess.run(['docker', 'exec', SRV, 'php', '-r', php, aid], capture_output=True, text=True).stdout
    return json.loads(out)


def snapshot(aid):
    return {'rows': table_hash(aid), 'files': tree_manifest('%s/accounts/%s' % (STORAGE, aid))}


def raw_post(path, fields, timeout=60):
    data = urllib.parse.urlencode(fields).encode()
    try:
        with urllib.request.urlopen(urllib.request.Request(BASE + path, data=data), timeout=timeout) as r:
            return r.read().decode('utf-8', 'replace')
    except urllib.error.HTTPError as e:
        return e.read().decode('utf-8', 'replace')


def parse_v1(body):
    i = body.find('{"phoromatic"')
    if i < 0:
        return {'_raw': body}
    return json.loads(body[i:])['phoromatic']


def approved_machine(aid):
    """A v1 client of account `aid`, registered the way a client does and approved the way an administrator does."""
    msi = str(uuid.uuid4())
    parse_v1(raw_post('/phoromatic.php', {'r': 'ping', 'aid': aid, 'msi': msi, 'pts_core': CORE}))
    sql_exec("UPDATE phoromatic_systems SET State=1 WHERE MachineSelfID='%s' AND AccountID='%s'" % (msi, aid))
    return msi


def v1(resource, aid, msi, **fields):
    f = {'r': resource, 'aid': aid, 'msi': msi, 'pts_core': CORE}
    f.update(fields)
    return parse_v1(raw_post('/phoromatic.php', f))


def sha1(b):
    return hashlib.sha1(b).hexdigest()


def b64(s):
    return base64.b64encode(s.encode()).decode()


def cli(*args):
    return subprocess.run(['docker', 'exec', SRV, 'sh', '/bench/benchy'] + list(args), capture_output=True, text=True).stdout


def phone_home_policy():
    m = re.search(r'Client phone-home policy: (\w+)', cli('phoromatic-client-phone-home'))
    return m.group(1) if m else None


def v2_allow_http():
    m = re.search(r'plain HTTP: (\w+)', cli('phoromatic-v2-allow-http'))
    return m.group(1) if m else None


def cache_files():
    return srv_sh('ls /var/cache/benchy/download-cache/').split()


def tls_mode_from_root():
    """The HTTPS mode as the root login sees it on ?admin_config."""
    root = Browser().login_root()
    m = re.search(r'Mode: <strong>([^<]*)</strong>', root.get('/?admin_config')[2])
    return m.group(1) if m else None


def php_boot(code):
    """PHP code run in the server container with Benchy's classes loaded (as the server's own commands do)."""
    boot = ("define('PTS_MODE','CLIENT');define('PTS_AUTO_LOAD_OBJECTS',true);define('PHOROMATIC_SERVER',true);$_SERVER['argv']=array('x');"
            "include('/bench/pts-core/pts-core.php');pts_core::init();")
    return srv_php(boot + code)


def pct(s):
    """Every character percent-encoded: the form of ../ that a filter on the raw request URI does not see."""
    return ''.join('%%%02x' % ord(c) for c in s)
