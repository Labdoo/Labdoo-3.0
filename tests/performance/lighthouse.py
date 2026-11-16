#!/usr/bin/env python3
"""Run real Lighthouse navigation audits against the isolated loopback site."""
import argparse
import base64
import json
import os
from html.parser import HTMLParser
import urllib.request
from pathlib import Path
import subprocess
from urllib.parse import urlsplit, urljoin

ROUTES = {
    'home': '/en?theme=labdoo_next',
    'dashboard': '/en/content/dootronics-dashboard?theme=labdoo_next',
    'school': '/en/node/749?theme=labdoo_next',
    'wiki': '/en/content/labdoo-social-network-how-it-works?theme=labdoo_next',
}
parser = argparse.ArgumentParser()
parser.add_argument('--base-url', default='http://127.0.0.1:18381')
parser.add_argument('--target-mode', choices=['isolated', 'readonly'], default='isolated')
parser.add_argument('--output', required=True, type=Path)
parser.add_argument('--path', help='Audit one exact application path, including its query string')
parser.add_argument('--lighthouse', default='lighthouse', help='Lighthouse executable, including a pinned alternate version')
parser.add_argument('--routes', nargs='+', choices=ROUTES, default=list(ROUTES))
parser.add_argument('--profiles', nargs='+', choices=['mobile', 'desktop'], default=['mobile', 'desktop'])
parser.add_argument('--runs', type=int, default=1)
args = parser.parse_args()
if args.path:
    if not args.path.startswith('/') or args.path.startswith('//'):
        raise SystemExit('The audit path must be relative to the isolated application origin')
    ROUTES = {'requested': args.path}
    args.routes = ['requested']
target = urlsplit(args.base_url)
is_loopback = target.hostname in {'127.0.0.1', 'localhost', '::1'}
if args.target_mode == 'isolated' and not is_loopback:
    raise SystemExit('Isolated Lighthouse audits must use a loopback site')
if args.target_mode == 'readonly' and (target.scheme != 'https' or is_loopback):
    raise SystemExit('Remote Lighthouse audits must use a configured HTTPS host')
auth_user = os.environ.get('LABDOO_HTTP_AUTH_USER', '')
auth_password = os.environ.get('LABDOO_HTTP_AUTH_PASSWORD', '')
if bool(auth_user) != bool(auth_password):
    raise SystemExit('Set both LABDOO_HTTP_AUTH_USER and LABDOO_HTTP_AUTH_PASSWORD')
if args.target_mode == 'readonly' and auth_user:
    raise SystemExit('Remote Lighthouse audits with HTTP Basic Auth are disabled to keep credentials away from third-party assets')
extra_headers = {}
if auth_user:
    token = base64.b64encode(f'{auth_user}:{auth_password}'.encode()).decode()
    extra_headers['Authorization'] = f'Basic {token}'
args.output.mkdir(parents=True, exist_ok=True)
env = dict(os.environ, CHROME_PATH='/usr/bin/google-chrome', XDG_CONFIG_HOME='/tmp/lighthouse-labdoo-config', XDG_CACHE_HOME='/tmp/lighthouse-labdoo-cache')
# Compile local aggregates before measuring, as on the production release. Do not
# warm browser caches: every Lighthouse navigation still uses a fresh profile.
class Assets(HTMLParser):
    def __init__(self):
        super().__init__()
        self.urls = set()

    def handle_starttag(self, tag, attributes):
        attributes = dict(attributes)
        if tag == 'link' and attributes.get('rel') == 'stylesheet':
            self.urls.add(attributes['href'])
        if tag == 'script' and attributes.get('src'):
            self.urls.add(attributes['src'])

for route in args.routes:
    page_url = args.base_url + ROUTES[route]
    request = urllib.request.Request(page_url, headers={'Authorization': extra_headers['Authorization']} if extra_headers else {})
    with urllib.request.urlopen(request, timeout=60) as response:
        if response.status != 200 or (args.target_mode == 'isolated' and response.headers.get('X-Labdoo-E2E') != 'integration'):
            raise RuntimeError('Audit target returned an unexpected response')
        assets = Assets()
        assets.feed(response.read().decode())
    for asset in assets.urls:
        url = urljoin(page_url, asset)
        if urlsplit(url).netloc == urlsplit(args.base_url).netloc:
            request = urllib.request.Request(url, headers={'Authorization': extra_headers['Authorization']} if extra_headers else {})
            with urllib.request.urlopen(request, timeout=60) as response:
                response.read()

summary = []
for route, suffix in ((name, ROUTES[name]) for name in args.routes):
    for profile in args.profiles:
        for run in range(1, args.runs + 1):
            output = args.output / f'{route}-{profile}-{run}'
            command = [args.lighthouse, args.base_url + suffix, '--chrome-flags=--headless --no-sandbox --disable-dev-shm-usage', '--only-categories=performance,accessibility,best-practices,seo', '--output=json', '--output=html', '--output-path=' + str(output), '--quiet']
            if profile == 'desktop': command.append('--preset=desktop')
            if extra_headers: command.extend(['--extra-headers', json.dumps(extra_headers)])
            subprocess.run(command, env=env, check=True)
            report = json.loads(Path(str(output) + '.report.json').read_text())
            if report.get('runtimeError'): raise RuntimeError(report['runtimeError']['message'])
            result = {
                'route': route, 'profile': profile, 'run': run,
                'scores': {name: round(category['score'] * 100) for name, category in report['categories'].items()},
                'metrics': {key: report['audits'][key].get('numericValue') for key in ['first-contentful-paint', 'largest-contentful-paint', 'total-blocking-time', 'cumulative-layout-shift', 'speed-index']},
                'failed_audits': [key for key, audit in report['audits'].items() if audit.get('score') is not None and audit['score'] < 1 and audit.get('scoreDisplayMode') not in ['notApplicable', 'manual', 'informative']],
                'warnings': report.get('runWarnings', []),
                'lighthouse_version': report['lighthouseVersion'],
            }
            summary.append(result)
            (args.output / 'summary.json').write_text(json.dumps(summary, indent=2) + '\n')
            print(json.dumps(result), flush=True)
