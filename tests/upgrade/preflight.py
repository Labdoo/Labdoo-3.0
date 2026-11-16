"""Run missing Honeypot D10 updates on the isolated current-checkout clone."""

import hashlib
import io
import re
import subprocess
import sys
import urllib.request
import zipfile
from pathlib import Path

from lab import ENV, LAB, ROOT, drush


ARCHIVE_URL = 'https://ftp.drupal.org/files/projects/honeypot-2.1.4.zip'
ARCHIVE_SHA256 = '6c9c8a216beb5f5c7751cd8fcc91b93e02cfbde182cf2ea92d42d072d735af75'
UPDATE_FUNCTIONS = ('honeypot_update_8103', 'honeypot_update_8104')


def extract_update(source, function_name):
    """Extract one pinned upstream update function without other module code."""
    match = re.search(rf'(?m)^function {re.escape(function_name)}\s*\(', source)
    if not match:
        raise RuntimeError(f'Missing expected upstream function {function_name}')

    opening_brace = source.find('{', match.end())
    if opening_brace < 0:
        raise RuntimeError(f'Malformed upstream function {function_name}')

    depth = 0
    for position in range(opening_brace, len(source)):
        if source[position] == '{':
            depth += 1
        elif source[position] == '}':
            depth -= 1
            if depth == 0:
                return source[match.start():position + 1]

    raise RuntimeError(f'Unterminated upstream function {function_name}')


def main():
    core_version = (ROOT / 'web/core/lib/Drupal.php').read_text()
    if "const VERSION = '10." not in core_version:
        raise RuntimeError('Run the intermediate release on Drupal 10 before the major upgrade')

    # This guard must succeed before touching the isolated clone database.
    drush('php:script', str(ROOT / 'tests/e2e/support/assert-isolated.php'))

    archive = urllib.request.urlopen(ARCHIVE_URL, timeout=60).read()
    if hashlib.sha256(archive).hexdigest() != ARCHIVE_SHA256:
        raise RuntimeError('Unexpected intermediate release checksum')

    with zipfile.ZipFile(io.BytesIO(archive)) as package:
        source = package.read('honeypot/honeypot.install').decode()
    updates = '\n\n'.join(extract_update(source, name) for name in UPDATE_FUNCTIONS)

    state = LAB / 'state'
    state.mkdir(parents=True, exist_ok=True)
    script = state / 'honeypot-intermediate-updates.php'
    script.write_text(
        "<?php\n"
        f"require_once {str(ROOT / 'web/core/includes/update.inc')!r};\n"
        f"require_once {str(ROOT / 'web/modules/contrib/honeypot/honeypot.install')!r};\n"
        f'{updates}\n'
        "$registry = \\Drupal::service('update.update_hook_registry');\n"
        "$version = $registry->getInstalledVersion('honeypot');\n"
        "if ($version < 8102 || $version > 8104) {\n"
        "  throw new RuntimeException('Unexpected Honeypot schema version: ' . $version);\n"
        "}\n"
        "$dependencies = [];\n"
        "foreach ([8103, 8104] as $update) {\n"
        "  if ($version >= $update) {\n"
        "    continue;\n"
        "  }\n"
        "  $function = 'honeypot_update_' . $update;\n"
        "  if (!function_exists($function)) {\n"
        "    throw new RuntimeException('Missing intermediate update function: ' . $function);\n"
        "  }\n"
        "  $context = ['sandbox' => [], 'results' => [], 'finished' => 1];\n"
        "  update_do_one('honeypot', $update, $dependencies, $context);\n"
        "  if (!empty($context['results']['#abort'])) {\n"
        "    throw new RuntimeException('Intermediate update failed: ' . $function);\n"
        "  }\n"
        "  $version = $registry->getInstalledVersion('honeypot');\n"
        "  if ($version !== $update) {\n"
        "    throw new RuntimeException('Update did not record schema ' . $update);\n"
        "  }\n"
        "  echo 'Completed ' . $function . PHP_EOL;\n"
        "}\n"
        "if ($version !== 8104) {\n"
        "  throw new RuntimeException('Expected Honeypot schema 8104; found ' . $version);\n"
        "}\n"
        "echo 'Verified Honeypot schema 8104.' . PHP_EOL;\n"
    )

    drush('php:script', str(script))
    drush('cr')
    drush('updatedb', '-y')

    schema = subprocess.run(
        [
            str(ROOT / 'vendor/bin/drush'),
            '--root=' + str(ROOT / 'web'),
            '--uri=http://127.0.0.1:18411',
            'php:eval',
            "echo \\Drupal::keyValue('system.schema')->get('honeypot');",
        ],
        cwd=ROOT,
        env=ENV,
        capture_output=True,
        text=True,
        check=True,
    ).stdout.strip()
    if schema != '8104':
        raise RuntimeError(f'Expected Honeypot schema 8104 after updates; found {schema!r}')
    drush('php:script', str(ROOT / 'tests/e2e/support/assert-isolated.php'))


if __name__ == '__main__':
    main()
