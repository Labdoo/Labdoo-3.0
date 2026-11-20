"""Exercise the actual Apache origin guard without Drupal or external services."""
import base64
import http.client
from pathlib import Path
import socket
import subprocess
import tempfile
import time
import unittest

ROOT = Path(__file__).resolve().parents[2]
TEMPLATE = ROOT / 'scripts/templates/v3-origin-guard.conf.template'
TOKEN = 'isolated_test_token_0123456789'


class OriginGuardTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.temp = tempfile.TemporaryDirectory(prefix='labdoo-origin-guard-')
        cls.base = Path(cls.temp.name)
        for directory in ['www', 'acme', 'www/override']:
            (cls.base / directory).mkdir(parents=True, exist_ok=True)
        (cls.base / 'www/index.html').write_text('private page')
        (cls.base / 'acme/challenge').write_text('isolated challenge')
        (cls.base / 'www/override/index.html').write_text('override page')
        (cls.base / 'www/override/.htaccess').write_text('Require all granted\n')
        secret = cls.base / 'htpasswd'
        subprocess.run(['htpasswd', '-b', '-c', str(secret), 'test', 'dummy'],
                       check=True, capture_output=True)
        with socket.socket() as server:
            server.bind(('127.0.0.1', 0))
            cls.port = server.getsockname()[1]
        guard = cls.base / 'guard.conf'
        guard.write_text(TEMPLATE.read_text().replace('__LABDOO_ORIGIN_TOKEN__', TOKEN))
        modules = ['mpm_event', 'authn_core', 'authn_file', 'authz_core',
                   'authz_user', 'auth_basic', 'alias', 'rewrite', 'dir']
        loads = '\n'.join(f'LoadModule {name}_module /usr/lib/apache2/modules/mod_{name}.so'
                          for name in modules)
        config = cls.base / 'httpd.conf'
        config.write_text(f'''ServerRoot "{cls.base}"
PidFile "{cls.base}/httpd.pid"
Listen 127.0.0.1:{cls.port}
ServerName isolated.labdoo.invalid
ErrorLog "{cls.base}/error.log"
LogLevel warn
{loads}
DirectoryIndex index.html
<Directory />
AllowOverride None
Require all denied
</Directory>
<VirtualHost 127.0.0.1:{cls.port}>
ServerName isolated.labdoo.invalid
Include "{guard}"
DocumentRoot "{cls.base}/www"
<Directory "{cls.base}/www">
AllowOverride All
AuthType Basic
AuthName "Isolated fixture"
AuthUserFile "{secret}"
Require valid-user
</Directory>
Alias /.well-known/acme-challenge/ "{cls.base}/acme/"
<Directory "{cls.base}/acme">
AllowOverride None
Require all granted
</Directory>
</VirtualHost>
''')
        cls.output = (cls.base / 'process.log').open('w+')
        subprocess.run(['apache2', '-t', '-f', str(config)], check=True,
                       stdout=cls.output, stderr=cls.output)
        cls.process = subprocess.Popen(['apache2', '-X', '-f', str(config)],
                                       stdout=cls.output, stderr=cls.output)
        for _ in range(100):
            if cls.process.poll() is not None:
                cls.output.seek(0)
                raise RuntimeError(cls.output.read())
            try:
                with socket.create_connection(('127.0.0.1', cls.port), timeout=.1):
                    return
            except OSError:
                time.sleep(.05)
        raise RuntimeError('Isolated Apache did not start')

    @classmethod
    def tearDownClass(cls):
        cls.process.terminate()
        cls.process.wait(timeout=10)
        cls.output.close()
        cls.temp.cleanup()

    def request(self, path='/', token=None, auth=False, local=False, headers=None):
        values = dict(headers or {})
        if token is not None:
            values['X-Origin-Verify'] = token
        if auth:
            values['Authorization'] = 'Basic ' + base64.b64encode(b'test:dummy').decode()
        connection = http.client.HTTPConnection(
            '127.0.0.1', self.port, timeout=5,
            source_address=('127.0.0.1' if local else '127.0.0.2', 0))
        try:
            connection.request('GET', path, headers=values)
            response = connection.getresponse()
            return response.status, response.read().decode(), dict(response.getheaders())
        finally:
            connection.close()

    def test_missing_or_wrong_token_is_forbidden_even_with_basic_auth(self):
        for token in [None, '', 'wrong', TOKEN + 'suffix', 'prefix' + TOKEN]:
            with self.subTest(token=token):
                self.assertEqual(self.request(token=token, auth=True)[0], 403)

    def test_matching_token_preserves_basic_auth(self):
        status, _, headers = self.request(token=TOKEN)
        self.assertEqual(status, 401)
        self.assertIn('WWW-Authenticate', headers)
        status, body, _ = self.request(token=TOKEN, auth=True)
        self.assertEqual((status, body), (200, 'private page'))

    def test_acme_requires_distribution_token_but_no_basic_auth(self):
        self.assertEqual(self.request('/.well-known/acme-challenge/challenge')[0], 403)
        status, body, _ = self.request('/.well-known/acme-challenge/challenge', token=TOKEN)
        self.assertEqual((status, body), (200, 'isolated challenge'))

    def test_viewer_cannot_spoof_cloudfront_or_local_source(self):
        for headers in [{'Via': '1.1 pretend.cloudfront.net (CloudFront)'},
                        {'X-Forwarded-For': '127.0.0.1'},
                        {'X-Origin-Verify': TOKEN.upper()},
                        {'X-Origin-Verify': TOKEN + ',' + TOKEN}]:
            with self.subTest(headers=headers):
                self.assertEqual(self.request(auth=True, headers=headers)[0], 403)

    def test_directory_authorization_override_cannot_bypass_guard(self):
        self.assertEqual(self.request('/override/')[0], 403)
        self.assertEqual(self.request('/override/', token=TOKEN)[0], 200)

    def test_loopback_maintenance_preserves_authentication(self):
        self.assertEqual(self.request(local=True)[0], 401)
        self.assertEqual(self.request(local=True, auth=True)[0], 200)

    def test_secret_is_not_reflected_in_response(self):
        for token in [None, TOKEN]:
            _, body, headers = self.request(token=token)
            self.assertNotIn(TOKEN, body + str(headers))


class InstallerPlanTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        import importlib.util
        spec = importlib.util.spec_from_file_location('installer', ROOT / 'scripts/install-v3-origin-guard.py')
        cls.installer = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(cls.installer)

    def test_existing_token_must_match_reviewed_distribution(self):
        text = 'RewriteCond %{HTTP:X-Origin-Verify} !^' + TOKEN + '$\n'
        value = self.installer.render(text, TEMPLATE.read_text(), self.installer.digest(TOKEN.encode()))
        self.assertIn(TOKEN.encode(), value)
        with self.assertRaises(ValueError):
            self.installer.render(text, TEMPLATE.read_text(), '0' * 64)

    def test_regex_metacharacters_are_rejected(self):
        token = TOKEN + '.*'
        text = 'RewriteCond %{HTTP:X-Origin-Verify} !^' + token + '$\n'
        with self.assertRaises(ValueError):
            self.installer.render(text, TEMPLATE.read_text(), self.installer.digest(token.encode()))

    def test_include_precedes_existing_rules_and_is_not_duplicated(self):
        text = '<VirtualHost *:443>\nRewriteEngine On\n</VirtualHost>\n'
        result = self.installer.add_include(text).decode()
        self.assertLess(result.index(self.installer.INCLUDE), result.index('RewriteEngine'))
        with self.assertRaises(ValueError):
            self.installer.add_include(result)


if __name__ == '__main__':
    unittest.main(verbosity=2)
