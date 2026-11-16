#!/usr/bin/env python3
# A stub HTTP server for the Cygwin download check (json.sh): /good is a 200 KB file starting with MZ, /trunc promises 300 KB and closes
# after 100 KB, /notmz is 200 KB of text. Usage: cygwin_stub.py <port>
import http.server
import sys


class H(http.server.BaseHTTPRequestHandler):
	def log_message(self, *a):
		pass

	def do_GET(self):
		if self.path == '/good':
			body = b'MZ' + b'\0' * 200000
			self.send_response(200); self.send_header('Content-Length', str(len(body))); self.end_headers(); self.wfile.write(body)
		elif self.path == '/trunc':
			self.send_response(200); self.send_header('Content-Length', '300000'); self.end_headers()
			self.wfile.write(b'MZ' + b'\0' * 100000); self.wfile.flush(); self.close_connection = True
		elif self.path == '/notmz':
			body = b'<html>' + b'x' * 200000
			self.send_response(200); self.send_header('Content-Length', str(len(body))); self.end_headers(); self.wfile.write(body)
		else:
			self.send_error(404)


http.server.ThreadingHTTPServer(('127.0.0.1', int(sys.argv[1])), H).serve_forever()
