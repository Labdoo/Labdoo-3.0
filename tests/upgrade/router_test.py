"""Check static transport and private-path boundaries on a disposable PHP server."""
import gzip
import http.client
import os
from pathlib import Path
import socket
import subprocess
import tempfile
import time
import unittest

ROUTER = Path(__file__).resolve().parents[1] / 'e2e/support/local-lab/router.php'


class RouterTransportTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.temporary = tempfile.TemporaryDirectory()
        root = Path(cls.temporary.name)
        (root / 'index.php').write_text('<?php')
        for name in ['themes/test/icon.webp', 'themes/test/font.woff2', 'themes/test/main.js', 'sites/default/files/upload.png', 'sites/default/settings.php']:
            path = root / name
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_bytes(b'const example = "transport fixture";' if name.endswith('.js') else b'private-or-binary-fixture')
        with socket.socket() as probe:
            probe.bind(('127.0.0.1', 0))
            cls.port = probe.getsockname()[1]
        cls.server = subprocess.Popen(['php', '-S', f'127.0.0.1:{cls.port}', '-t', str(root), str(ROUTER)],
                                      env={**os.environ, 'LABDOO_E2E_WEBROOT': str(root), 'XDEBUG_MODE': 'off'},
                                      stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        for attempt in range(100):
            try:
                with socket.create_connection(('127.0.0.1', cls.port), timeout=0.2):
                    break
            except OSError:
                time.sleep(0.05)
        else:
            raise RuntimeError('Disposable PHP server did not start')

    @classmethod
    def tearDownClass(cls):
        cls.server.terminate()
        cls.server.wait(timeout=5)
        cls.temporary.cleanup()

    def get(self, path, headers=None):
        connection = http.client.HTTPConnection('127.0.0.1', self.port, timeout=5)
        try:
            connection.request('GET', path, headers=headers or {})
            response = connection.getresponse()
            return response.status, dict(response.getheaders()), response.read()
        finally:
            connection.close()

    def test_code_image_has_correct_mime_and_conditional_revalidation(self):
        status, headers, body = self.get('/themes/test/icon.webp')
        self.assertEqual(status, 200)
        self.assertEqual(headers['Content-Type'], 'image/webp')
        self.assertNotIn('Cache-Control', headers)
        self.assertEqual(body, b'private-or-binary-fixture')
        status, _, body = self.get('/themes/test/icon.webp', {'If-None-Match': headers['ETag']})
        self.assertEqual((status, body), (304, b''))

    def test_binary_fonts_are_typed_without_gzip(self):
        status, headers, body = self.get('/themes/test/font.woff2', {'Accept-Encoding': 'gzip'})
        self.assertEqual(status, 200)
        self.assertEqual(headers['Content-Type'], 'font/woff2')
        self.assertNotIn('Content-Encoding', headers)
        self.assertEqual(body, b'private-or-binary-fixture')

    def test_javascript_keeps_gzip_and_correct_body(self):
        status, headers, body = self.get('/themes/test/main.js', {'Accept-Encoding': 'gzip'})
        self.assertEqual(status, 200)
        self.assertEqual(headers['Content-Encoding'], 'gzip')
        self.assertEqual(gzip.decompress(body), b'const example = "transport fixture";')

    def test_uploaded_image_does_not_gain_the_code_cache_policy(self):
        status, headers, _ = self.get('/sites/default/files/upload.png')
        self.assertEqual(status, 200)
        self.assertNotIn('Cache-Control', headers)

    def test_settings_remain_unavailable(self):
        status, headers, body = self.get('/sites/default/settings.php')
        self.assertEqual(status, 404)
        self.assertNotIn('Cache-Control', headers)
        self.assertNotIn(b'private-or-binary-fixture', body)


if __name__ == '__main__':
    unittest.main()
