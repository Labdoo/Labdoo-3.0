"""Test cache isolation and the real Apache header guard without a Drupal database."""

import copy
import fnmatch
import importlib.util
from pathlib import Path
import socket
import subprocess
import tempfile
import time
import unittest
import urllib.error
import urllib.request

ROOT = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location('cache_policy', ROOT / 'scripts/cloudfront_cache_policy.py')
policy = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(policy)
POLICY_ID = '00000000-0000-0000-0000-000000000001'


def original():
    return {
        'Aliases': {'Quantity': 1, 'Items': ['v3.labdoo.org']},
        'Origins': {'Quantity': 1, 'Items': [{'Id': 'origin', 'CustomHeaders': {
            'Quantity': 1, 'Items': [{'HeaderName': 'X-Origin-Verify', 'HeaderValue': 'fixture-secret'}]}}]},
        'WebACLId': 'unchanged-waf-fixture', 'Enabled': True,
        'DefaultCacheBehavior': {'TargetOriginId': 'origin', 'CachePolicyId': 'disabled',
                                 'OriginRequestPolicyId': 'all-viewer',
                                 'ViewerProtocolPolicy': 'redirect-to-https',
                                 'FunctionAssociations': {'Quantity': 0}},
        'CacheBehaviors': {'Quantity': 2, 'Items': [
            {'PathPattern': '/.well-known/acme-challenge/*', 'CachePolicyId': 'disabled'},
            {'PathPattern': '/sites/default/files/*', 'CachePolicyId': 'disabled'},
        ]},
    }


class DistributionPolicyTest(unittest.TestCase):
    def test_authentication_host_and_version_are_separate_keys(self):
        p = policy.cache_policy('LabdooStaticAssetsTest')
        params = p['ParametersInCacheKeyAndForwardedToOrigin']
        self.assertEqual(set(params['HeadersConfig']['Headers']['Items']), {'Authorization', 'Host'})
        self.assertEqual(params['QueryStringsConfig']['QueryStringBehavior'], 'all')
        self.assertEqual(params['CookiesConfig']['CookieBehavior'], 'none')
        self.assertEqual((p['MinTTL'], p['DefaultTTL'], p['MaxTTL']), (0, 300, 300))

    def test_dynamic_routes_uploads_and_source_files_are_excluded(self):
        for path in ['/en', '/es/user/1', '/api/private.json', '/core/install.php',
                     '/themes/custom/example/template.twig', '/sites/default/files/upload.pdf',
                     '/sites/default/files/styles/private/image.jpg',
                     '/.well-known/acme-challenge/token.css']:
            self.assertFalse(any(fnmatch.fnmatchcase(path, p) for p in policy.STATIC_PATTERNS), path)

    def test_expected_static_paths_match(self):
        for path in ['/themes/custom/labdoo_next/images/logo.webp', '/core/misc/drupal.js',
                     '/modules/custom/example/css/form.css', '/sites/default/files/css/css_hash.css']:
            self.assertTrue(any(fnmatch.fnmatchcase(path, p) for p in policy.STATIC_PATTERNS), path)

    def test_original_config_secrets_dynamic_behaviors_and_waf_are_preserved(self):
        before = original()
        snapshot = copy.deepcopy(before)
        after = policy.distribution_config(before, POLICY_ID)
        self.assertEqual(before, snapshot)
        for key in ['DefaultCacheBehavior', 'WebACLId', 'Enabled', 'Aliases']:
            self.assertEqual(after[key], before[key])
        self.assertEqual(after['Origins']['Items'][:-1], before['Origins']['Items'])
        static_origin = after['Origins']['Items'][-1]
        self.assertEqual(static_origin['CustomHeaders']['Items'][:-1],
                         before['Origins']['Items'][0]['CustomHeaders']['Items'])
        self.assertEqual(static_origin['CustomHeaders']['Items'][-1],
                         {'HeaderName': 'X-Labdoo-Static-Cache', 'HeaderValue': '1'})
        self.assertEqual(after['CacheBehaviors']['Items'][len(policy.STATIC_PATTERNS):],
                         before['CacheBehaviors']['Items'])

    def test_static_origin_receives_no_allviewer_policy_or_cached_post(self):
        after = policy.distribution_config(original(), POLICY_ID)
        for b in after['CacheBehaviors']['Items'][:len(policy.STATIC_PATTERNS)]:
            self.assertNotIn('OriginRequestPolicyId', b)
            self.assertEqual(b['AllowedMethods']['CachedMethods']['Items'], ['GET', 'HEAD'])
            self.assertNotIn('POST', b['AllowedMethods']['Items'])

    def test_error_response_mappings_survive_with_zero_error_ttl(self):
        before = original()
        before['CustomErrorResponses'] = {'Quantity': 1, 'Items': [
            {'ErrorCode': 404, 'ResponseCode': '404', 'ResponsePagePath': '/error', 'ErrorCachingMinTTL': 10}]}
        after = policy.distribution_config(before, POLICY_ID)
        error = next(e for e in after['CustomErrorResponses']['Items'] if e['ErrorCode'] == 404)
        self.assertEqual(error['ResponsePagePath'], '/error')
        self.assertEqual(error['ErrorCachingMinTTL'], 0)

    def test_refuse_other_distribution_or_preexisting_task_behaviors(self):
        before = original()
        before['Aliases']['Items'] = ['another.example']
        with self.assertRaises(ValueError):
            policy.distribution_config(before, POLICY_ID)
        before = original()
        before['CacheBehaviors']['Items'][0]['PathPattern'] = policy.STATIC_PATTERNS[0]
        with self.assertRaises(ValueError):
            policy.distribution_config(before, POLICY_ID)

    def test_refuse_unreviewed_edge_code(self):
        before = original()
        before['DefaultCacheBehavior']['FunctionAssociations'] = {'Quantity': 1}
        with self.assertRaises(ValueError):
            policy.distribution_config(before, POLICY_ID)

    def test_new_error_entries_preserve_native_status_and_body(self):
        after = policy.distribution_config(original(), POLICY_ID)
        for error in after['CustomErrorResponses']['Items']:
            self.assertEqual(error['ResponsePagePath'], '')
            self.assertEqual(error['ResponseCode'], '')
            self.assertEqual(error['ErrorCachingMinTTL'], 0)

    def test_readback_accepts_unordered_methods_and_origins(self):
        expected = policy.distribution_config(original(), POLICY_ID)
        actual = copy.deepcopy(expected)
        actual['Origins']['Items'].reverse()
        actual['CacheBehaviors']['Items'][0]['AllowedMethods']['Items'].reverse()
        self.assertTrue(policy.equivalent_config(actual, expected))

    def test_readback_rejects_changed_secrets_or_behavior_precedence(self):
        expected = policy.distribution_config(original(), POLICY_ID)
        actual = copy.deepcopy(expected)
        actual['Origins']['Items'][0]['CustomHeaders']['Items'][0]['HeaderValue'] = 'changed-fixture'
        self.assertFalse(policy.equivalent_config(actual, expected))
        actual = copy.deepcopy(expected)
        actual['CacheBehaviors']['Items'].reverse()
        self.assertFalse(policy.equivalent_config(actual, expected))


class ApacheOriginGuardTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.temp = tempfile.TemporaryDirectory(prefix='labdoo-cache-guard-')
        cls.base = Path(cls.temp.name)
        cls.web = cls.base / 'web'
        for folder in ['themes/test', 'sites/default/files/css', 'sites/default/files/uploads', 'private']:
            (cls.web / folder).mkdir(parents=True, exist_ok=True)
        for name in ['public.css', 'private.css', 'html.css', 'data.js', 'unknown.js']:
            (cls.web / 'themes/test' / name).write_text('synthetic fixture\n')
        for name in ['public.js', 'public.svg', 'public.webp', 'public.woff2']:
            (cls.web / 'themes/test' / name).write_text('synthetic MIME fixture\n')
        nested = cls.web / 'themes/test/nested'
        nested.mkdir()
        (nested / 'html.css').write_text('synthetic nested HTML\n')
        (nested / '.htaccess').write_text(
            '<Files "html.css">\nForceType text/html\n'
            'Header always set Cache-Control "public, max-age=86400"\n</Files>\n')
        (cls.web / 'private/error.html').write_text('synthetic internal error handler\n')
        (cls.web / 'sites/default/files/uploads/plain.css').write_text('not in cache scope\n')
        with socket.socket() as sock:
            sock.bind(('127.0.0.1', 0))
            cls.port = sock.getsockname()[1]
        modules = '\n'.join(f'LoadModule {name}_module /usr/lib/apache2/modules/mod_{name}.so'
                            for name in ['mpm_event', 'authz_core', 'mime', 'headers'])
        config = f'''ServerRoot "/etc/apache2"
ServerName localhost
Listen 127.0.0.1:{cls.port}
PidFile "{cls.base}/httpd.pid"
DefaultRuntimeDir "{cls.base}"
ErrorLog "{cls.base}/error.log"
{modules}
TypesConfig /etc/mime.types
DocumentRoot "{cls.web}"
<Directory />
AllowOverride None
Require all denied
</Directory>
<Directory "{cls.web}">
AllowOverride FileInfo
Require all granted
</Directory>
ErrorDocument 404 /private/error.html
Header set Cache-Control "public, max-age=86400"
<Files "private.css">
Header always set Cache-Control "private, no-store, max-age=0"
</Files>
<Files "html.css">
ForceType text/html
</Files>
<Files "data.js">
ForceType application/json
</Files>
<Files "unknown.js">
ForceType text/plain
</Files>
Include "{ROOT}/scripts/runtime/cloudfront-static-cache.conf"
'''
        path = cls.base / 'httpd.conf'
        path.write_text(config)
        subprocess.run(['/usr/sbin/apache2', '-t', '-f', str(path)], check=True,
                       stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        cls.process = subprocess.Popen(['/usr/sbin/apache2', '-f', str(path), '-DFOREGROUND'],
                                       stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        for _ in range(100):
            try:
                cls.get('/themes/test/public.css')
                return
            except urllib.error.URLError:
                if cls.process.poll() is not None:
                    raise RuntimeError('Isolated Apache failed; inspect private temporary error log')
                time.sleep(0.05)
        raise RuntimeError('Isolated Apache did not start')

    @classmethod
    def tearDownClass(cls):
        cls.process.terminate()
        cls.process.wait(timeout=10)
        cls.temp.cleanup()

    @classmethod
    def get(cls, path, marked=True):
        try:
            request = urllib.request.Request(f'http://127.0.0.1:{cls.port}' + path,
                                             headers={'X-Labdoo-Static-Cache': '1'} if marked else {})
            response = urllib.request.urlopen(request, timeout=5)
        except urllib.error.HTTPError as error:
            response = error
        with response:
            return response.status, ','.join(response.headers.get_all('Cache-Control', []))

    def test_public_css_keeps_its_public_header(self):
        status, cache = self.get('/themes/test/public.css')
        self.assertEqual(status, 200)
        self.assertEqual(cache, 'public, max-age=86400')

    def test_reviewed_script_image_and_font_mimes_remain_cacheable(self):
        for name in ['public.js', 'public.svg', 'public.webp', 'public.woff2']:
            status, cache = self.get('/themes/test/' + name)
            self.assertEqual(status, 200)
            self.assertEqual(cache, 'public, max-age=86400', name)

    def test_existing_private_response_stays_private(self):
        status, cache = self.get('/themes/test/private.css')
        self.assertEqual(status, 200)
        self.assertIn('no-store', cache)

    def test_errors_cannot_use_the_prior_public_max_age(self):
        status, cache = self.get('/themes/test/missing.css')
        self.assertEqual(status, 404)
        self.assertEqual(cache, 'private, no-store, max-age=0')

    def test_non_static_responses_cannot_be_cached_as_assets(self):
        for name in ['html.css', 'data.js', 'unknown.js']:
            status, cache = self.get('/themes/test/' + name)
            self.assertEqual(status, 200)
            self.assertEqual(cache, 'private, no-store, max-age=0')

    def test_unreviewed_upload_paths_are_not_changed(self):
        status, cache = self.get('/sites/default/files/uploads/plain.css', marked=False)
        self.assertEqual(status, 200)
        self.assertEqual(cache, 'public, max-age=86400')

    def test_dynamic_html_without_static_origin_marker_is_unchanged(self):
        status, cache = self.get('/themes/test/html.css', marked=False)
        self.assertEqual(status, 200)
        self.assertEqual(cache, 'public, max-age=86400')

    def test_later_directory_headers_cannot_override_the_guard(self):
        status, cache = self.get('/themes/test/nested/html.css')
        self.assertEqual(status, 200)
        self.assertEqual(cache, 'private, no-store, max-age=0')


if __name__ == '__main__':
    unittest.main()
