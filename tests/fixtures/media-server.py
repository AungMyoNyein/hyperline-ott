"""Serve only our generated test pattern over loopback TLS, with controllable CORS."""
import http.server
import ssl
from pathlib import Path

class Handler(http.server.SimpleHTTPRequestHandler):
    extensions_map = {
        **http.server.SimpleHTTPRequestHandler.extensions_map,
        '.mpd': 'application/dash+xml',
        '.m4s': 'video/iso.segment',
        '.m3u8': 'application/vnd.apple.mpegurl',
        '.ts': 'video/mp2t',
    }

    def __init__(self, *args, **kwargs):
        super().__init__(*args, directory='/tmp/hyperline-test-media', **kwargs)

    def end_headers(self):
        if not Path('/tmp/hyperline-test-media/block-cors').exists():
            self.send_header('Access-Control-Allow-Origin', 'http://127.0.0.1:18082')
        super().end_headers()

server = http.server.HTTPServer(('127.0.0.1', 18084), Handler)
context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
context.load_cert_chain('/tmp/hyperline-test-media/cert.pem', '/tmp/hyperline-test-media/key.pem')
server.socket = context.wrap_socket(server.socket, server_side=True)
server.serve_forever()
