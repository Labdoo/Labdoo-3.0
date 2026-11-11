#!/usr/bin/env python3
"""Read-only loopback proxy for the isolated Drupal site, with Apache-like gzip.

The PHP development server does not implement the .htaccess compression or static
expiry used by AWS. Keep this transport identical for before/after Lighthouse runs.
"""
import argparse
import gzip
import http.client
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import urlsplit

parser = argparse.ArgumentParser()
parser.add_argument('--port', type=int, default=18385)
parser.add_argument('--upstream-port', type=int, default=18381)
args = parser.parse_args()
probe = http.client.HTTPConnection('127.0.0.1', args.upstream_port, timeout=30)
probe.request('GET', '/en/user/login')
response = probe.getresponse()
if response.getheader('X-Labdoo-E2E') != 'integration':
    raise SystemExit('Refusing to proxy a site outside the isolated integration copy')
response.read()
probe.close()
HOP = {'connection', 'transfer-encoding', 'content-length', 'content-encoding', 'server', 'date'}

class Handler(BaseHTTPRequestHandler):
    protocol_version = 'HTTP/1.1'

    def do_HEAD(self):
        self.send_site(head=True)

    def do_GET(self):
        self.send_site()

    def send_site(self, head=False):
        upstream = http.client.HTTPConnection('127.0.0.1', args.upstream_port, timeout=120)
        headers = {key: value for key, value in self.headers.items() if key.lower() not in HOP | {'accept-encoding'}}
        headers['Accept-Encoding'] = 'identity'
        try:
            upstream.request('GET', self.path, headers=headers)
            result = upstream.getresponse()
            data = result.read()
            content_type = result.getheader('Content-Type', '').split(';')[0]
            compress = 'gzip' in self.headers.get('Accept-Encoding', '') and len(data) > 512 and (
                content_type.startswith('text/') or content_type in {'application/javascript', 'application/json', 'image/svg+xml'})
            if compress:
                data = gzip.compress(data, compresslevel=6)
            self.send_response(result.status)
            is_static = urlsplit(self.path).path.lower().endswith(('.css', '.js', '.png', '.jpg', '.jpeg', '.gif', '.svg', '.webp', '.ico', '.woff', '.woff2'))
            for key, value in result.getheaders():
                if key.lower() in HOP or (is_static and key.lower() in {'cache-control', 'expires'}):
                    continue
                self.send_header(key, value)
            if is_static and result.status == 200:
                self.send_header('Cache-Control', 'public, max-age=31536000')
            if compress:
                self.send_header('Content-Encoding', 'gzip')
                self.send_header('Vary', 'Accept-Encoding')
            self.send_header('Content-Length', str(len(data)))
            self.end_headers()
            if not head:
                self.wfile.write(data)
        except (BrokenPipeError, ConnectionResetError):
            pass
        finally:
            upstream.close()

    def log_message(self, *_args):
        pass

print(f'Lighthouse proxy: http://127.0.0.1:{args.port} → isolated site on {args.upstream_port}', flush=True)
ThreadingHTTPServer(('127.0.0.1', args.port), Handler).serve_forever()
