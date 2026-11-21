"""Verify that restore checks reject data, permission and path drift."""
import hashlib
import importlib.util
import os
from pathlib import Path
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('recovery', ROOT / 'scripts/verify_v3_recovery_files.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class RecoveryFilesTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='labdoo-recovery-files-')
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        target = self.root / 'mnt/data/example.txt'
        target.parent.mkdir(parents=True)
        target.write_bytes(b'isolated content')
        target.chmod(0o640)
        self.target = target
        info = target.stat()
        self.manifest = {'files': [{'path': '/mnt/data/example.txt', 'size': info.st_size,
                                   'uid': info.st_uid, 'gid': info.st_gid, 'mode': 0o640,
                                   'sha256': hashlib.sha256(target.read_bytes()).hexdigest(),
                                   'www_data_readable': True}]}

    def test_identical_content_owner_permissions_and_readability_pass(self):
        result = module.compare_manifest(self.manifest, self.root, lambda path: True)
        self.assertEqual((result['checked'], result['passed']), (1, 1))
        self.assertTrue(result['web_readability_checked'])

    def test_same_size_content_corruption_is_detected(self):
        self.target.write_bytes(b'corrupted bytes!')
        result = module.compare_manifest(self.manifest, self.root)
        self.assertIn('sha256', result['files'][0]['differences'])

    def test_permission_changes_are_detected(self):
        self.target.chmod(0o644)
        result = module.compare_manifest(self.manifest, self.root)
        self.assertIn('mode', result['files'][0]['differences'])

    def test_owner_changes_in_the_expected_manifest_are_detected(self):
        self.manifest['files'][0]['uid'] = os.getuid() + 1
        result = module.compare_manifest(self.manifest, self.root)
        self.assertIn('uid', result['files'][0]['differences'])

    def test_missing_files_are_not_silently_skipped(self):
        self.target.unlink()
        result = module.compare_manifest(self.manifest, self.root)
        self.assertEqual(result['files'][0]['differences'], ['missing'])

    def test_loss_of_web_user_access_is_detected(self):
        result = module.compare_manifest(self.manifest, self.root, lambda path: False)
        self.assertIn('www_data_readable', result['files'][0]['differences'])

    def test_traversal_or_unexpected_scope_is_rejected(self):
        for path in ['/mnt/data/../../etc/passwd', 'relative.txt', '/home/example']:
            self.manifest['files'][0]['path'] = path
            with self.subTest(path=path), self.assertRaises(ValueError):
                module.compare_manifest(self.manifest, self.root)

    def test_symlinks_and_live_root_are_rejected(self):
        self.target.unlink()
        self.target.symlink_to('/etc/passwd')
        with self.assertRaises(ValueError):
            module.compare_manifest(self.manifest, self.root)
        with self.assertRaises(ValueError):
            module.compare_manifest(self.manifest, '/')


if __name__ == '__main__':
    unittest.main(verbosity=2)
