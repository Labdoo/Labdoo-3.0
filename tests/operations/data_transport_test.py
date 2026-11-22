"""Exercise real Predis TLS, authentication and ACLs on a disposable Redis."""
import json
import os
from pathlib import Path
import secrets
import re
import shutil
import socket
import subprocess
import tempfile
import time
import unittest

ROOT = Path(__file__).resolve().parents[2]


class DataTransportTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        for command in ['openssl', 'redis-server', 'php']:
            if not shutil.which(command):
                raise RuntimeError('Required native TLS fixture dependency missing: ' + command)
        parent = ROOT / 'tests/performance/.local'
        parent.mkdir(parents=True, exist_ok=True)
        cls.directory = tempfile.TemporaryDirectory(prefix='data-transport-', dir=parent)
        cls.path = Path(cls.directory.name)
        cls.path.chmod(0o700)
        cls.addClassCleanup(cls.directory.cleanup)
        cls.log = (cls.path / 'fixture-private.log').open('wb')
        cls.addClassCleanup(cls.log.close)

        def run(*args):
            subprocess.run(args, cwd=cls.path, stdout=cls.log, stderr=cls.log, check=True)

        for name in ['ca', 'wrong-ca']:
            run('openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-days', '2',
                '-keyout', name + '.key', '-out', name + '.pem', '-subj', '/CN=Labdoo isolated ' + name)
        run('openssl', 'req', '-newkey', 'rsa:2048', '-nodes', '-keyout', 'server.key',
            '-out', 'server.csr', '-subj', '/CN=localhost')
        (cls.path / 'server.ext').write_text('subjectAltName=DNS:localhost\nextendedKeyUsage=serverAuth\n')
        run('openssl', 'x509', '-req', '-in', 'server.csr', '-CA', 'ca.pem', '-CAkey', 'ca.key',
            '-CAcreateserial', '-days', '2', '-out', 'server.pem', '-extfile', 'server.ext')
        with socket.socket() as sock:
            sock.bind(('127.0.0.1', 0))
            cls.port = sock.getsockname()[1]
        prefix = getattr(cls, 'key_prefix', 'labdoo')
        if not re.fullmatch(r'[A-Za-z0-9_]+', prefix):
            raise RuntimeError('Invalid disposable cache namespace')
        password = secrets.token_hex(32)
        acl = cls.path / 'users.acl'
        acl.write_text('user default off\nuser labdoo-data on >' + password + ' ~' + prefix + ':* +@all -@admin -@dangerous +info\n')
        acl.chmod(0o600)
        config = cls.path / 'redis.conf'
        config.write_text('\n'.join([
            'bind 127.0.0.1', 'protected-mode yes', 'port 0', 'tls-port ' + str(cls.port),
            'tls-cert-file ' + str(cls.path / 'server.pem'),
            'tls-key-file ' + str(cls.path / 'server.key'),
            'tls-ca-cert-file ' + str(cls.path / 'ca.pem'), 'tls-auth-clients no',
            'aclfile ' + str(acl), 'save ""', 'appendonly no', 'dir ' + str(cls.path),
        ]) + '\n')
        config.chmod(0o600)
        cls.server = subprocess.Popen(['redis-server', str(config)], stdout=cls.log, stderr=cls.log)
        cls.addClassCleanup(cls.stop_server)
        for _ in range(100):
            if cls.server.poll() is not None:
                raise RuntimeError('Disposable TLS Redis failed; inspect private fixture log')
            try:
                with socket.create_connection(('127.0.0.1', cls.port), timeout=0.1):
                    break
            except OSError:
                time.sleep(0.05)
        else:
            raise RuntimeError('Disposable TLS Redis did not become ready')
        cls.input = cls.path / 'connection-private.json'
        cls.input.write_text(json.dumps({'redis': {
            'host': 'localhost', 'port': cls.port, 'ca_file': str(cls.path / 'ca.pem'),
            'username': 'labdoo-data', 'password': password, 'authentication_required': True,
        }, 'wrong_ca': str(cls.path / 'wrong-ca.pem')}))
        cls.input.chmod(0o600)

    @classmethod
    def stop_server(cls):
        if cls.server.poll() is None:
            cls.server.terminate()
            cls.server.wait(timeout=5)

    def probe(self, operation):
        result = subprocess.run(['php', '-d', 'xdebug.mode=off', str(ROOT / 'tests/operations/data_transport_probe.php'),
                                 str(self.input), operation], capture_output=True, text=True)
        self.assertEqual(result.returncode, 0, result.stderr)
        return json.loads(result.stdout)

    def test_valid_profile_preserves_the_guarded_database(self):
        self.assertTrue(self.probe('profile_valid')['local_profile_isolation_enforced'])

    def test_profile_for_another_checkout_is_rejected(self):
        self.assertTrue(self.probe('profile_root')['local_profile_isolation_enforced'])

    def test_profile_for_a_remote_cache_is_rejected(self):
        self.assertTrue(self.probe('profile_host')['local_profile_isolation_enforced'])

    def test_profile_with_external_ca_path_is_rejected(self):
        self.assertTrue(self.probe('profile_ca')['local_profile_isolation_enforced'])

    def test_profile_cannot_select_another_cache_namespace(self):
        self.assertTrue(self.probe('profile_prefix')['local_profile_isolation_enforced'])

    def test_profile_cannot_override_database_provenance(self):
        self.assertTrue(self.probe('profile_database')['local_profile_isolation_enforced'])

    def test_profile_cannot_disable_authentication(self):
        self.assertTrue(self.probe('profile_auth')['local_profile_isolation_enforced'])

    def test_authenticated_tls_round_trip(self):
        self.assertTrue(self.probe('round_trip')['authenticated_tls_round_trip'])

    def test_invalid_ca_is_rejected(self):
        self.assertTrue(self.probe('bad_ca')['expected_rejection'])

    def test_wrong_server_identity_is_rejected(self):
        self.assertTrue(self.probe('wrong_hostname')['expected_rejection'])

    def test_wrong_password_is_rejected(self):
        self.assertTrue(self.probe('wrong_password')['expected_rejection'])

    def test_anonymous_tls_client_is_rejected_by_server(self):
        self.assertTrue(self.probe('no_auth')['expected_rejection'])

    def test_other_namespaces_and_administrative_commands_are_denied(self):
        self.assertEqual(self.probe('acl_boundaries')['acl_denials'], 4)

    def test_database_credentials_options_and_unrelated_connections_survive(self):
        self.assertTrue(self.probe('database_settings')['credentials_and_options_preserved'])

    def test_missing_ca_fails_before_connection(self):
        self.assertTrue(self.probe('missing_ca')['expected_rejection'])

    def test_missing_required_credential_fails_before_connection(self):
        self.assertTrue(self.probe('missing_password')['expected_rejection'])

    def test_plaintext_override_is_rejected(self):
        self.assertTrue(self.probe('plaintext_override')['expected_rejection'])

    def test_unreviewed_endpoint_is_rejected(self):
        self.assertTrue(self.probe('wrong_endpoint')['expected_rejection'])

    def test_unreviewed_replication_hosts_are_rejected(self):
        self.assertTrue(self.probe('replication')['expected_rejection'])


if __name__ == '__main__':
    unittest.main(verbosity=2)
