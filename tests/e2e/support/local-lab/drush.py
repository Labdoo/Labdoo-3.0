import os
import subprocess
import sys
from pathlib import Path

LAB = next(parent for parent in Path(__file__).resolve().parents if parent.name == '.e2e-lab')
kind = sys.argv[1]
if kind not in ['baseline', 'integration']: raise RuntimeError('Unknown variant')
root = LAB / kind
uri = 'http://127.0.0.1:' + ('18380' if kind == 'baseline' else '18381')
command = ['php', '-d', 'xdebug.mode=off', str(root / 'vendor/bin/drush'), '--root=' + str(root / 'web'), '--uri=' + uri]
environment = dict(os.environ, AWS_EC2_METADATA_DISABLED='true', AWS_CONFIG_FILE='/dev/null', AWS_SHARED_CREDENTIALS_FILE='/dev/null')
environment.update(HTTP_PROXY='http://127.0.0.1:9', HTTPS_PROXY='http://127.0.0.1:9', http_proxy='http://127.0.0.1:9', https_proxy='http://127.0.0.1:9', NO_PROXY='127.0.0.1,localhost', no_proxy='127.0.0.1,localhost')
guard = LAB / 'integration/tests/e2e/support/assert-isolated.php'
subprocess.run(command + ['php:script', str(guard)], cwd=root, env=environment, check=True)
raise SystemExit(subprocess.run(command + sys.argv[2:], cwd=root, env=environment).returncode)
