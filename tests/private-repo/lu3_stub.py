"""A stand-in Phoromatic server for lu3.sh (LU-S3). Logs every POST (the Phoromatic protocol), one JSON line each (path, form fields; big values cut to their
length), to the file in argv[1], and answers the way the file argv[2] says (re-read per request): ok (ping, then an upload id),
approval (every request: Waiting For Approval), noid (ping ok, the upload answered with a response but no upload id), zero (upload id 0), notice (a PHP notice printed before the JSON of the upload reply). Never used for deployment."""
import json
import sys
import threading
import urllib.parse
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

LOG, MODE = sys.argv[1:3]
LOCK = threading.Lock()


class Handler(BaseHTTPRequestHandler):
    protocol_version = 'HTTP/1.0'

    def log_message(self, *args):
        pass

    def do_POST(self):
        length = int(self.headers.get('Content-Length') or 0)
        body = self.rfile.read(length).decode('utf-8', 'replace') if length else ''
        form = {k: v[0] for k, v in urllib.parse.parse_qs(body, keep_blank_values=True).items()}
        with LOCK, open(LOG, 'a') as f:
            f.write(json.dumps({'path': self.path, 'r': form.get('r'), 'fields': {k: (len(v) if len(v) > 60 else v) for k, v in form.items()}}, sort_keys=True) + '\n')
        try:
            mode = open(MODE).read().strip()
        except OSError:
            mode = 'ok'
        r = form.get('r')
        if mode == 'approval':
            reply = {'phoromatic': {'response': 'Waiting For Approval From Administrator.'}}
        elif r == 'ping':
            reply = {'phoromatic': {'ping': 'ping'}}
        elif r == 'result_upload':
            reply = {'phoromatic': {'response': 'Information Added.'}} if mode == 'noid' else {'phoromatic': {'upload_id': 7, 'response': 'Result Upload: 7'}}
        else:
            reply = {'phoromatic': {'response': 'ok'}}
        if mode == 'zero' and r == 'result_upload':
            reply = {'phoromatic': {'upload_id': 0, 'response': 'Result Upload: 0'}}
        data = json.dumps(reply).encode()
        if mode == 'notice' and r == 'result_upload':
            data = b'<br />\n<b>Notice</b>: something in the server in <b>x.php</b> on line <b>1</b><br />\n' + data
        self.send_response(200)
        self.send_header('Content-Type', 'application/json')
        self.send_header('Content-Length', str(len(data)))
        self.end_headers()
        self.wfile.write(data)

    def do_GET(self):
        # The client reads a configured server's profile cache (openbenchmarking-cache.php) at the start of any command: not a
        # request of the upload, not logged
        data = b'{}'
        self.send_response(200)
        self.send_header('Content-Length', str(len(data)))
        self.end_headers()
        self.wfile.write(data)


ThreadingHTTPServer(('0.0.0.0', 8080), Handler).serve_forever()
