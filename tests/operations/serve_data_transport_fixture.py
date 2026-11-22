"""Serve an authenticated TLS Redis fixture for the guarded local E2E site."""
import hashlib
import importlib.util
import json
from pathlib import Path
import shutil
import signal
import subprocess
import threading

ROOT = Path(__file__).resolve().parents[2]
STATE = ROOT / 'tests/upgrade/.local/state'
profile_path = STATE / 'data-protection-private.json'
if not (STATE / 'connection.json').is_file() or profile_path.exists():
    raise RuntimeError('Prepare this checkout and remove only your previous fixture profile first')
connection = json.loads((STATE / 'connection.json').read_text())
if connection['root'] != str(ROOT) or not connection['connection']['database'].startswith('labdoo_e2e_'):
    raise RuntimeError('Refusing another checkout or a non-isolated database')
spec = importlib.util.spec_from_file_location('fixture', ROOT / 'tests/operations/data_transport_test.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
fixture = module.DataTransportTest
fixture.key_prefix = 'labdoo_e2e_' + hashlib.sha256(str(ROOT).encode()).hexdigest()[:12]
fixture.setUpClass()
finished = threading.Event()
for sig in [signal.SIGINT, signal.SIGTERM]:
    signal.signal(sig, lambda *_: finished.set())
ca = STATE / (fixture.path.name + '-ca.pem')
try:
    shutil.copyfile(fixture.path / 'ca.pem', ca)
    ca.chmod(0o600)
    config = json.loads(fixture.input.read_text())['redis']
    config['ca_file'] = str(ca)
    profile = {'root': str(ROOT), 'configuration': {'redis': config, 'cache_prefix': fixture.key_prefix}}
    text = json.dumps(profile, indent=2) + '\n'
    profile_path.write_text(text)
    profile_path.chmod(0o600)
    subprocess.run(['python3', '-B', str(ROOT / 'tests/upgrade/lab.py'), 'drush', 'cr'], check=True)
    check = r"""echo json_encode(['fixture'=>\Drupal\Core\Site\Settings::get('labdoo_e2e_tls_redis', false),'backend'=>get_class(\Drupal::cache()),'tls'=>stream_get_meta_data(\Drupal::service('redis.factory')->getClient()->getConnection()->getResource())['crypto']['protocol']??null]);"""
    receipt = subprocess.run(['python3', '-B', str(ROOT / 'tests/upgrade/lab.py'), 'drush', 'php:eval', check],
                             capture_output=True, text=True, check=True)
    verified = json.loads(receipt.stdout)
    if not verified['fixture'] or verified['backend'] != 'Drupal\\redis\\Cache\\Predis' or not verified['tls']:
        raise RuntimeError('The local Drupal site did not adopt the authenticated TLS fixture')
    print(json.dumps({'local_tls_redis_fixture_ready': True, 'database_provenance_unchanged': True,
                      'namespace': fixture.key_prefix}), flush=True)
    while not finished.wait(1):
        if fixture.server.poll() is not None:
            raise RuntimeError('TLS Redis fixture exited unexpectedly')
finally:
    if profile_path.is_file() and profile_path.read_text() == text:
        profile_path.unlink()
        subprocess.run(['python3', '-B', str(ROOT / 'tests/upgrade/lab.py'), 'drush', 'cr'], check=True)
    ca.unlink(missing_ok=True)
    fixture.doClassCleanups()
