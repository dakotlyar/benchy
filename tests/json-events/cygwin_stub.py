#!/usr/bin/env python3
# A stub HTTP server for the Cygwin download check (json.sh): /good is a 1.2 MB file starting with MZ, /trunc promises 1.5 MB and closes
# after 1.1 MB, /notmz is 1.2 MB of text. Usage: cygwin_stub.py <port> [cert.pem key.pem] (with a cert the server speaks TLS)
import http.server
import sys


class H(http.server.BaseHTTPRequestHandler):
	def log_message(self, *a):
		pass

	def do_GET(self):
		if self.path == '/good':
			body = b'MZ' + b'\0' * 1200000
			self.send_response(200); self.send_header('Content-Length', str(len(body))); self.end_headers(); self.wfile.write(body)
		elif self.path == '/trunc':
			self.send_response(200); self.send_header('Content-Length', '1500000'); self.end_headers()
			self.wfile.write(b'MZ' + b'\0' * 1100000); self.wfile.flush(); self.close_connection = True
		elif self.path == '/notmz':
			body = b'<html>' + b'x' * 1200000
			self.send_response(200); self.send_header('Content-Length', str(len(body))); self.end_headers(); self.wfile.write(body)
		else:
			self.send_error(404)


srv = http.server.ThreadingHTTPServer(('127.0.0.1', int(sys.argv[1])), H)
if len(sys.argv) > 3:
	import ssl
	ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
	ctx.load_cert_chain(sys.argv[2], sys.argv[3])
	srv.socket = ctx.wrap_socket(srv.socket, server_side=True)
srv.serve_forever()
