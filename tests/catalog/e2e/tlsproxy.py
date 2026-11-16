#!/usr/bin/env python3
"""A TLS front for the end-to-end test of the signed catalog (E4.4 C3): the engine only talks https, and the scratch server speaks plain HTTP.
tlsproxy.py <cert> <key> <listen port> <upstream host:port>. Forwards each request (method, path, Authorization, Content-Type, body) and sends the reply back
with its status, headers and bytes. Throw-away test tool: one connection per request, no streaming, never used outside the test container."""
import http.client
import http.server
import ssl
import sys

cert, key, port, upstream = sys.argv[1], sys.argv[2], int(sys.argv[3]), sys.argv[4]
host, _, uport = upstream.partition(':')


class Handler(http.server.BaseHTTPRequestHandler):
    protocol_version = 'HTTP/1.1'

    def forward(self):
        n = int(self.headers.get('Content-Length') or 0)
        body = self.rfile.read(n) if n else None
        h = {k: v for k, v in self.headers.items() if k.lower() in ('authorization', 'content-type', 'user-agent')}
        c = http.client.HTTPConnection(host, int(uport), timeout=120)
        c.request(self.command, self.path, body=body, headers=h)
        r = c.getresponse()
        data = r.read()
        self.send_response(r.status)
        for k, v in r.getheaders():
            if k.lower() in ('content-type', 'cache-control', 'x-content-type-options', 'allow'):
                self.send_header(k, v)
        self.send_header('Content-Length', str(len(data)))
        self.end_headers()
        self.wfile.write(data)
        c.close()

    do_GET = do_POST = do_PUT = do_DELETE = forward

    def log_message(self, fmt, *args):
        sys.stderr.write('%s %s\n' % (self.command, self.path) if args and len(args) > 1 else '')


ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
ctx.load_cert_chain(cert, key)
srv = http.server.ThreadingHTTPServer(('127.0.0.1', port), Handler)
srv.socket = ctx.wrap_socket(srv.socket, server_side=True)
srv.serve_forever()
