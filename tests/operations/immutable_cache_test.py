"""Exercise immutable-cache headers with real isolated Apache and Basic Auth."""
import base64
import gzip
from pathlib import Path
import subprocess
import sys
import unittest
import urllib.error
import urllib.request

sys.path.insert(0, str(Path(__file__).parent))
import cloudfront_cache_test as fixture
ROOT = fixture.ROOT

HASH = 'a' * 64
PREFIX = '/themes/custom/labdoo_next/static/' + HASH


class ImmutableCacheTest(unittest.TestCase):
    @classmethod
    def get(cls, path, marked=True):
        result = fixture.ApacheOriginGuardTest.get.__func__(cls, path, marked)
        if result[0] == 500:
            print((cls.base / 'error.log').read_text()[-1800:])
        return result

    @classmethod
    def setUpClass(cls):
        fixture.ApacheOriginGuardTest.setUpClass.__func__(cls)
        cls.process.terminate()
        cls.process.wait(timeout=10)
        for name in ['public.js', 'public.css', 'public.webp', 'private.css', 'html.css', 'guarded.js']:
            dest = cls.web / PREFIX.lstrip('/') / name
            dest.parent.mkdir(parents=True, exist_ok=True)
            dest.write_text('owned immutable fixture\n')
        cls.passwd = cls.base / 'passwd'
        cls.passwd.write_bytes(subprocess.check_output(['htpasswd', '-bn', 'fixture', 'test-only-password']))
        (cls.web / PREFIX.lstrip('/') / '.htaccess').write_text(
            '<Files "guarded.js">\nAuthType Basic\nAuthName fixture\n'
            f'AuthUserFile "{cls.passwd}"\nRequire valid-user\n</Files>\n')
        path = cls.base / 'httpd.conf'
        config = path.read_text().replace('Header set Cache-Control "public, max-age=86400"\n', '')
        config = config.replace('AllowOverride FileInfo', 'AllowOverride FileInfo AuthConfig')
        config += '\n' + '\n'.join(f'LoadModule {name}_module /usr/lib/apache2/modules/mod_{name}.so'
                                   for name in ['auth_basic', 'authn_core', 'authn_file', 'authz_user', 'rewrite'])
        aggregate = cls.web / 'sites/default/files/js'
        aggregate.mkdir(parents=True, exist_ok=True)
        cls.aggregate_name = 'js_' + 'b' * 43 + '.js'
        (aggregate / cls.aggregate_name).write_bytes(b'owned aggregate')
        (aggregate / (cls.aggregate_name + '.gz')).write_bytes(gzip.compress(b'owned aggregate'))
        (aggregate / '.htaccess').write_text(
            'RewriteEngine On\nRewriteCond %{HTTP:Accept-Encoding} gzip\n'
            f'RewriteRule ^({cls.aggregate_name})$ $1.gz [L]\n'
            '<FilesMatch "\\.js\\.gz$">\nForceType application/javascript\n'
            'Header set Content-Encoding gzip\n</FilesMatch>\n')
        config += f'\nInclude "{ROOT}/scripts/runtime/immutable-static-cache.conf"\n'
        path.write_text(config)
        subprocess.run(['/usr/sbin/apache2', '-t', '-f', str(path)], check=True, capture_output=True)
        cls.process = subprocess.Popen(['/usr/sbin/apache2', '-f', str(path), '-DFOREGROUND'],
                                       stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        import time
        for _ in range(100):
            try:
                cls.get(PREFIX + '/public.css')
                break
            except urllib.error.URLError:
                time.sleep(0.05)
        else:
            raise RuntimeError('Isolated immutable-cache Apache failed')

    @classmethod
    def tearDownClass(cls):
        fixture.ApacheOriginGuardTest.tearDownClass.__func__(cls)

    def test_public_versioned_code_gets_long_browser_and_short_edge_cache(self):
        for name in ['public.js', 'public.css', 'public.webp']:
            status, cache = self.get(PREFIX + '/' + name)
            self.assertEqual(status, 200)
            self.assertIn('max-age=31536000', cache)
            self.assertIn('s-maxage=300', cache)
            self.assertIn('immutable', cache)

    def test_private_and_html_mime_are_never_made_public(self):
        for name in ['private.css', 'html.css', 'missing.js']:
            _, cache = self.get(PREFIX + '/' + name)
            self.assertNotIn('31536000', cache)
            self.assertNotIn('immutable', cache)

    def test_unversioned_code_does_not_get_immutable_headers(self):
        _, cache = self.get('/themes/test/public.js')
        self.assertNotIn('immutable', cache)

    def test_authentication_remains_required_before_cacheable_success(self):
        status, cache = self.get(PREFIX + '/guarded.js')
        self.assertEqual(status, 401)
        self.assertNotIn('31536000', cache)
        request = urllib.request.Request(f'http://127.0.0.1:{self.port}' + PREFIX + '/guarded.js', headers={
            'Authorization': 'Basic ' + base64.b64encode(b'fixture:test-only-password').decode(),
            'X-Labdoo-Static-Cache': '1',
        })
        with urllib.request.urlopen(request) as response:
            self.assertEqual(response.status, 200)
            self.assertIn('immutable', response.headers['Cache-Control'])

    def test_compressed_aggregate_keeps_immutable_headers_and_body(self):
        request = urllib.request.Request(f'http://127.0.0.1:{self.port}/sites/default/files/js/' + self.aggregate_name,
                                         headers={'Accept-Encoding': 'gzip', 'X-Labdoo-Static-Cache': '1'})
        with urllib.request.urlopen(request) as response:
            self.assertEqual(response.status, 200)
            self.assertIn('immutable', response.headers['Cache-Control'])
            self.assertEqual(gzip.decompress(response.read()), b'owned aggregate')


if __name__ == '__main__':
    unittest.main()
