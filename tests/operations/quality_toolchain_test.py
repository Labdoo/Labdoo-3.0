"""Check real framework reflection without executing standalone framework doubles."""
import json
from pathlib import Path
import re
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]


class QualityToolchainTest(unittest.TestCase):
    def test_framework_types_remain_visible_in_application_analysis(self):
        result = subprocess.run([
            'php', '-d', 'xdebug.mode=off',
            'tools/quality/vendor/bin/phpstan', 'analyse',
            '-c', 'tools/quality/phpstan.neon', '--no-progress',
            '--memory-limit=1G', '--error-format=json',
            'web/modules/custom', 'web/themes/custom', 'tests/phpunit',
            'tools/quality/tests/framework-types.php',
        ], cwd=ROOT, text=True, capture_output=True, check=False)
        self.assertIn(result.returncode, (0, 1), result.stderr)
        report = json.loads(result.stdout)
        self.assertEqual(report['errors'], [])
        fixture = str(ROOT / 'tools/quality/tests/framework-types.php')
        self.assertNotIn(fixture, report['files'])
        shared_tests = str(ROOT / 'tests/phpunit') + '/'
        shared_errors = {path: detail for path, detail in report['files'].items()
                         if path.startswith(shared_tests)}
        self.assertEqual(shared_errors, {})

    def test_standalone_geography_cases_still_run(self):
        self.run_standalone('labdoo_migrate/tests/geo_repair_validation_unit.php')

    def test_standalone_city_country_cases_still_run(self):
        self.run_standalone('labdoo_global_action/tests/city_country_unit.php')

    def test_nullable_relations_still_reject_unsafe_calls(self):
        source = r'''<?php
use Drupal\node\NodeInterface;
use Drupal\webform\WebformSubmissionInterface;
function unsafe_relation_calls(NodeInterface $node, WebformSubmissionInterface $submission): void {
    $node->getOwner()->getEmail();
    $submission->getWebform()->id();
}
'''
        with tempfile.TemporaryDirectory(prefix='labdoo-nullability-') as directory:
            fixture = Path(directory) / 'unsafe.php'
            fixture.write_text(source)
            result = subprocess.run([
                'php', '-d', 'xdebug.mode=off',
                'tools/quality/vendor/bin/phpstan', 'analyse',
                '-c', 'tools/quality/phpstan.neon', '--no-progress',
                '--memory-limit=1G', '--level=8', '--error-format=json',
                str(fixture),
            ], cwd=ROOT, text=True, capture_output=True, check=False)
            self.assertEqual(result.returncode, 1, result.stderr)
            report = json.loads(result.stdout)
            self.assertEqual(report['errors'], [])
            messages = report['files'][str(fixture)]['messages']
            self.assertEqual(len(messages), 2, messages)
            self.assertTrue(all(item['identifier'] == 'method.nonObject' for item in messages))
            combined = '\n'.join(item['message'] for item in messages)
            self.assertIn('UserInterface|null', combined)
            self.assertIn('WebformInterface|null', combined)

    def test_dependency_readers_and_fluent_queries_reject_unsafe_calls(self):
        source = r"""<?php
function unsafe_readers(
    \Drupal\Component\Gettext\PoStreamReader $reader,
    \Drupal\language\Config\LanguageConfigFactoryOverrideInterface $overrides,
    \Drupal\Core\Database\Query\SelectInterface $query
): void {
    $reader->readItem()->getSource();
    $overrides->getLanguage()->getId();
    $query->forUpdate()->missingQueryMethod();
}
"""
        with tempfile.TemporaryDirectory(prefix='labdoo-dependency-readers-') as directory:
            fixture = Path(directory) / 'unsafe.php'
            fixture.write_text(source)
            result = subprocess.run([
                'php', '-d', 'xdebug.mode=off',
                'tools/quality/vendor/bin/phpstan', 'analyse',
                '-c', 'tools/quality/phpstan.neon', '--no-progress',
                '--memory-limit=1G', '--level=8', '--error-format=json',
                str(fixture),
            ], cwd=ROOT, text=True, capture_output=True, check=False)
            self.assertEqual(result.returncode, 1, result.stderr)
            report = json.loads(result.stdout)
            self.assertEqual(report['errors'], [])
            messages = report['files'][str(fixture)]['messages']
            self.assertCountEqual([item['identifier'] for item in messages],
                                  ['method.nonObject', 'method.nonObject', 'method.notFound'])
            self.assertIn('missingQueryMethod', '\n'.join(item['message'] for item in messages))

    def test_inherited_factories_reject_incompatible_constructors(self):
        factories = []
        for filename in sorted((ROOT / 'web/modules/custom').rglob('*.php')):
            if 'vendor' in filename.parts:
                continue
            source = filename.read_text()
            if '@phpstan-consistent-constructor' not in source:
                continue
            namespace = re.search(r'^namespace ([^;]+);', source, re.MULTILINE)
            name = re.search(r'^(?:abstract |final )?class (\w+)', source, re.MULTILINE)
            self.assertIsNotNone(namespace, filename)
            self.assertIsNotNone(name, filename)
            factories.append(namespace[1] + '\\' + name[1])
        self.assertGreaterEqual(len(factories), 64)
        lines = ['<?php', r'namespace Labdoo\Quality\Fixtures;']
        for index, factory in enumerate(factories):
            lines.append(
                f'class IncompatibleFactory{index} extends \\{factory} '
                r'{ public function __construct(public \stdClass $incompatibleRequired) {} }'
            )
        with tempfile.TemporaryDirectory(prefix='labdoo-factories-') as directory:
            fixture = Path(directory) / 'incompatible-factories.php'
            fixture.write_text('\n'.join(lines) + '\n')
            result = subprocess.run([
                'php', '-d', 'xdebug.mode=off',
                'tools/quality/vendor/bin/phpstan', 'analyse',
                '-c', 'tools/quality/phpstan.neon', '--no-progress',
                '--memory-limit=1G', '--error-format=json', str(fixture),
            ], cwd=ROOT, text=True, capture_output=True, check=False)
            self.assertEqual(result.returncode, 1, result.stderr)
            report = json.loads(result.stdout)
            self.assertEqual(report['errors'], [])
            messages = report['files'][str(fixture)]['messages']
            allowed = {'method.childParameterType', 'parameter.missing', 'parameter.notOptional'}
            self.assertTrue(all(item['identifier'] in allowed for item in messages), messages)
            self.assertEqual({item['line'] for item in messages}, set(range(3, len(factories) + 3)))

    def run_standalone(self, relative):
        result = subprocess.run([
            'php', '-d', 'xdebug.mode=off',
            str(ROOT / 'web/modules/custom' / relative),
        ], cwd=ROOT, text=True, capture_output=True, check=False)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(result.stdout.count('PASS:'), 5)


if __name__ == '__main__':
    unittest.main()
