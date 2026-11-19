"""Exercise audit target checks/report interpretation without a live browser."""
from hashlib import sha256
from io import BytesIO
import json
from pathlib import Path
import runpy
import tempfile
import unittest
from unittest.mock import patch

RUNNER = Path(__file__).with_name('lighthouse.py').resolve()
ROOT = RUNNER.parents[2]


class Response(BytesIO):
    status = 200

    def __init__(self, identity):
        super().__init__(b'<html><body>Test</body></html>')
        self.headers = {'X-Labdoo-E2E': 'integration', 'X-Labdoo-E2E-Root': identity}


class LighthouseRunnerTest(unittest.TestCase):
    def execute(self, output, identity, command):
        args = [str(RUNNER), '--base-url', 'http://127.0.0.1:18411', '--output', str(output), '--routes', 'home', '--profiles', 'mobile']
        with patch('sys.argv', args), patch('urllib.request.urlopen', return_value=Response(identity)), patch('subprocess.run', side_effect=command):
            runpy.run_path(str(RUNNER), run_name='__main__')

    def test_rejects_another_checkout_before_launching_the_browser(self):
        with tempfile.TemporaryDirectory() as output:
            with self.assertRaisesRegex(RuntimeError, 'not running this checkout'):
                self.execute(output, 'another-checkout', lambda *args, **kwargs: self.fail('Browser must not start'))

    def test_rejects_remote_targets(self):
        with tempfile.TemporaryDirectory() as output:
            args = [str(RUNNER), '--base-url', 'https://v3.labdoo.org', '--output', output, '--routes', 'home', '--profiles', 'mobile']
            with patch('sys.argv', args), self.assertRaisesRegex(SystemExit, 'restricted to a local HTTP loopback site'):
                runpy.run_path(str(RUNNER), run_name='__main__')

    def test_records_real_report_failures_and_warnings_in_the_summary(self):
        def command(args, **kwargs):
            prefix = next(arg.split('=', 1)[1] for arg in args if arg.startswith('--output-path='))
            report = {
                'categories': {'performance': {'score': 0.72}, 'accessibility': {'score': 0.85}},
                'audits': {name: {'numericValue': 123} for name in ['first-contentful-paint', 'largest-contentful-paint', 'total-blocking-time', 'cumulative-layout-shift', 'speed-index']},
                'runWarnings': ['Controlled warning'], 'lighthouseVersion': 'test-version',
            }
            report['audits']['contrast'] = {'score': 0, 'scoreDisplayMode': 'binary'}
            Path(prefix + '.report.json').write_text(json.dumps(report))
        with tempfile.TemporaryDirectory() as output:
            self.execute(output, sha256(str(ROOT).encode()).hexdigest(), command)
            summary = json.loads((Path(output) / 'summary.json').read_text())
            self.assertEqual(len(summary), 1)
            self.assertEqual(summary[0]['scores']['performance'], 72)
            self.assertEqual(summary[0]['failed_audits'], ['contrast'])
            self.assertEqual(summary[0]['warnings'], ['Controlled warning'])


if __name__ == '__main__':
    unittest.main()
