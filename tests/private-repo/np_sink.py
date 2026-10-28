"""Stand-in for openbenchmarking.org in the no-phone-home tests (nophone.sh). Never used for deployment.

Clients resolve openbenchmarking.org to this container (docker --add-host) on a network with no route out, so every request
meant for openbenchmarking.org lands here and is logged, one JSON line each, to the file given as argv[1]: port, method, path,
query and form fields. It answers just enough for the client to believe it is online (GET /PTS -> "PTS") and to hand out a
fixed GSID; everything else gets an empty 200, which the client treats as "no answer" (cached indexes are kept).
usage: np_sink.py LOG CERT KEY
"""
import json
import ssl
import sys
import threading
import urllib.parse
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

LOG, CERT, KEY = sys.argv[1:4]
LOCK = threading.Lock()
GSID = {'gsid': 'NPSINK123', 'gsid_e': 'NPSINKGSIE12', 'gsid_p': 'NPSINKGSP1'}


class Handler(BaseHTTPRequestHandler):
    protocol_version = 'HTTP/1.0'

    def log_message(self, *args):
        pass

    def handle_any(self):
        length = int(self.headers.get('Content-Length') or 0)
        body = self.rfile.read(length).decode('utf-8', 'replace') if length else ''
        url = urllib.parse.urlsplit(self.path)
        form = {k: v[0] for k, v in urllib.parse.parse_qs(body, keep_blank_values=True).items()}
        entry = {'port': self.server.server_address[1], 'method': self.command, 'host': self.headers.get('Host', ''),
                 'path': url.path, 'query': url.query, 'form': form}
        with LOCK, open(LOG, 'a') as f:
            f.write(json.dumps(entry, sort_keys=True) + '\n')

        if url.path == '/PTS':
            reply = 'PTS'
        elif form.get('r') in ('request_gsid', 'retrieve_gsid'):
            reply = json.dumps({'openbenchmarking': {'gsid': GSID}})
        else:
            reply = ''
        data = reply.encode()
        self.send_response(200)
        self.send_header('Content-Type', 'text/plain')
        self.send_header('Content-Length', str(len(data)))
        self.end_headers()
        self.wfile.write(data)

    do_GET = do_POST = do_PUT = do_HEAD = handle_any


def serve(port, tls):
    httpd = ThreadingHTTPServer(('0.0.0.0', port), Handler)
    if tls:
        ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
        ctx.load_cert_chain(CERT, KEY)
        httpd.socket = ctx.wrap_socket(httpd.socket, server_side=True)
    httpd.serve_forever()


threading.Thread(target=serve, args=(443, True), daemon=True).start()
serve(80, False)
