"""Check the command boundary without connecting to Drupal or MySQL."""
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch, Mock

spec = importlib.util.spec_from_file_location('regression_lab', Path(__file__).with_name('lab.py'))
lab = importlib.util.module_from_spec(spec)
spec.loader.exec_module(lab)


class LabCommandsTest(unittest.TestCase):
    def test_other_checkout_manifest_is_rejected_before_bootstrap(self):
        with tempfile.TemporaryDirectory() as temporary:
            state = Path(temporary)
            (state / 'connection.json').write_text(json.dumps({'root': '/another/checkout'}))
            with patch.object(lab, 'STATE', state), patch.object(lab, 'run') as run:
                with self.assertRaisesRegex(RuntimeError, 'this checkout'):
                    lab.drush('status')
                run.assert_not_called()

    def test_commands_guard_the_current_root_and_test_uri(self):
        with tempfile.TemporaryDirectory() as temporary:
            state = Path(temporary)
            (state / 'connection.json').write_text(json.dumps({'root': str(lab.ROOT)}))
            with patch.object(lab, 'STATE', state), patch.object(lab, 'run') as run:
                lab.drush('status')
                self.assertEqual(run.call_count, 2)
                guard, command = [call.args[0] for call in run.call_args_list]
                self.assertIn('--root=' + str(lab.ROOT / 'web'), command)
                self.assertIn('--uri=http://127.0.0.1:18411', command)
                self.assertIn('assert-isolated.php', guard[-1])
                self.assertEqual(command[-1], 'status')
                self.assertEqual(lab.ENV['LABDOO_E2E_SITE'], '1')

    def test_browser_wrapper_removes_only_the_integration_variant(self):
        with tempfile.TemporaryDirectory() as temporary:
            tools = Path(temporary)
            lab.write_drush_wrapper(tools)
            code = compile((tools / 'drush.py').read_text(), str(tools / 'drush.py'), 'exec')
            drush = Mock()
            with patch('sys.argv', ['drush.py', 'integration', 'php:script', 'check.php']), patch('runpy.run_path', return_value={'drush': drush}):
                exec(code, {'__name__': '__main__'})
            drush.assert_called_once_with('php:script', 'check.php')
            with patch('sys.argv', ['drush.py', 'baseline', 'status']), patch('runpy.run_path') as load:
                with self.assertRaisesRegex(RuntimeError, 'Expected the integration variant'):
                    exec(code, {'__name__': '__main__'})
                load.assert_not_called()


if __name__ == '__main__':
    unittest.main()
