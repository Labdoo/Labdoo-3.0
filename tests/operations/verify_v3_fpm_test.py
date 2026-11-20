"""Exercise deployment verification without opening a production connection."""
import importlib.util
import json
from pathlib import Path
import unittest

spec = importlib.util.spec_from_file_location('verify_v3_fpm', Path(__file__).resolve().parents[2] / 'scripts/verify-v3-fpm.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
NEXT = '<body class="path-frontpage labdoo-next"><img src="/themes/custom/labdoo_next/images/labdoo-wordmark-240.webp"></body>'


class VerificationTest(unittest.TestCase):
    def test_default_next_and_responsive_logo(self):
        self.assertTrue(module.verify_response('/en', {}, NEXT, b'')['next_body'])

    def test_original_logo_still_supported(self):
        self.assertTrue(module.verify_response('/en', {}, NEXT.replace('-240.webp', '.webp'), b'')['next_body'])

    def test_wrong_default_theme_rejected(self):
        with self.assertRaises(AssertionError):
            module.verify_response('/en', {}, '<body class="other">', b'')

    def test_explicit_original_theme_contract(self):
        self.assertFalse(module.verify_response('/en', {}, '<body class="other">', b'', 'labdoo')['next_body'])
        with self.assertRaises(AssertionError):
            module.verify_response('/en', {}, NEXT, b'', 'labdoo')

    def test_server_errors_are_not_successes(self):
        with self.assertRaises(AssertionError):
            module.verify_response('/en', {'status': '500'}, NEXT, b'')

    def test_stderr_is_rejected(self):
        with self.assertRaises(AssertionError):
            module.verify_response('/en', {}, NEXT, b'PHP warning')

    def test_missing_page_and_logo_are_rejected(self):
        for text in ['OK', '<body class="labdoo-next">', NEXT.replace('labdoo-wordmark-240.webp', '')]:
            with self.assertRaises(AssertionError):
                module.verify_response('/en', {}, text, b'')

    def test_fresh_quote_requires_language_exclusion_and_private_no_store(self):
        uri = '/en/labdoo/education-quote/en?exclude=mandela'
        headers = {'cache-control': 'private, no-store'}
        good = {'language': 'en', 'quote': {'id': 'mann'}}
        self.assertTrue(module.verify_response(uri, headers, json.dumps(good), b'')['fresh_quote'])
        for data, cache in [({'language': 'es', 'quote': {'id': 'mann'}}, headers), ({'language': 'en', 'quote': {'id': 'mandela'}}, headers), (good, {'cache-control': 'public'})]:
            with self.assertRaises(AssertionError):
                module.verify_response(uri, cache, json.dumps(data), b'')

    def test_clustered_maps_validate_counts(self):
        uri = '/en/api/map-points/dootronic?_map=clusters&zoom=0'
        good = {'points': [{'count': 7}, {'count': 3}], 'visible': 10, 'total': 10}
        self.assertEqual(module.verify_response(uri, {}, json.dumps(good), b'')['map_points'], 10)
        for data in [[], {'points': [{'count': 7}], 'visible': 10, 'total': 10}, {'points': [{'count': -1}], 'visible': -1, 'total': 0}]:
            with self.assertRaises(AssertionError):
                module.verify_response(uri, {}, json.dumps(data), b'')

    def test_fastcgi_length_boundaries(self):
        self.assertEqual(module.length(127), b'\x7f')
        self.assertEqual(module.length(128), b'\x80\x00\x00\x80')
        self.assertEqual(len(module.record(4, b'abc')), 11)


if __name__ == '__main__':
    unittest.main()
