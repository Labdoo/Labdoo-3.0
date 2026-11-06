import json
import os
import subprocess
import sys
from pathlib import Path
from urllib.request import urlopen

LAB = next(parent for parent in Path(__file__).resolve().parents if parent.name == '.e2e-lab')
kind = sys.argv[1]
if kind not in ['baseline', 'integration']: raise RuntimeError('Unknown variant')
root = LAB / kind
port = '18380' if kind == 'baseline' else '18381'
uri = 'http://127.0.0.1:' + port
try:
    with urlopen(uri + '/en/user/login', timeout=5) as response:
        if response.headers.get('X-Labdoo-E2E') == kind:
            print(kind + ' server already ready at ' + uri)
            raise SystemExit(0)
except (OSError, TimeoutError):
    pass
subprocess.run([sys.executable, str(LAB / 'tools/drush.py'), kind, 'php:script', str(LAB / 'integration/tests/e2e/support/assert-isolated.php')], check=True)
environment = dict(os.environ, LABDOO_E2E_WEBROOT=str(root / 'web'), PHP_CLI_SERVER_WORKERS='2', AWS_EC2_METADATA_DISABLED='true', AWS_CONFIG_FILE='/dev/null', AWS_SHARED_CREDENTIALS_FILE='/dev/null')
environment.update(HTTP_PROXY='http://127.0.0.1:9', HTTPS_PROXY='http://127.0.0.1:9', http_proxy='http://127.0.0.1:9', https_proxy='http://127.0.0.1:9', NO_PROXY='127.0.0.1,localhost', no_proxy='127.0.0.1,localhost')
log = (LAB / 'state' / (kind + '-server.log')).open('ab')
(LAB / 'state' / (kind + '-server.json')).write_text(json.dumps({'pid': os.getpid(), 'uri': uri}))
print('Starting supervised ' + kind + ' server at ' + uri, flush=True)
os.dup2(log.fileno(), 1)
os.dup2(log.fileno(), 2)
os.chdir(root / 'web')
os.execvpe('php', ['php', '-d', 'xdebug.mode=off', '-d', 'sendmail_path=/bin/true', '-S', '127.0.0.1:' + port, '-t', str(root / 'web'), str(LAB / 'tools/router.php')], environment)
