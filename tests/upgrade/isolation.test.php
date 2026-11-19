<?php

declare(strict_types=1);

// Exercise real Drupal site discovery and E2E settings without a database.
$root = dirname(__DIR__, 2);
$class_loader = require $root . '/web/autoload.php';
require_once $root . '/web/core/includes/bootstrap.inc';

function verify(bool $condition, string $message): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
}

foreach (['http://127.0.0.1:18411', 'http://localhost:18411'] as $url) {
  $request = \Symfony\Component\HttpFoundation\Request::create($url . '/', 'GET', [], [], [], ['SCRIPT_NAME' => '/index.php']);
  putenv('LABDOO_E2E_SITE');
  verify(\Drupal\Core\DrupalKernel::findSitePath($request, TRUE, $root . '/web') === 'sites/default', 'Ordinary processes must retain default settings.');
  putenv('LABDOO_E2E_SITE=1');
  verify(\Drupal\Core\DrupalKernel::findSitePath($request, TRUE, $root . '/web') === 'sites/labdoo_e2e', 'The local test origin must select its isolated site.');
}
foreach (['http://127.0.0.1:8080', 'https://example.org'] as $url) {
  $request = \Symfony\Component\HttpFoundation\Request::create($url . '/', 'GET', [], [], [], ['SCRIPT_NAME' => '/index.php']);
  verify(\Drupal\Core\DrupalKernel::findSitePath($request, TRUE, $root . '/web') === 'sites/default', 'Other origins must not select E2E settings.');
}

$temporary = sys_get_temp_dir() . '/labdoo-isolation-' . bin2hex(random_bytes(8));
$state = $temporary . '/tests/upgrade/.local/state';
mkdir($state, 0700, TRUE);
$manifest = [
  'root' => $temporary,
  'salt' => 'test-only-salt',
  'connection' => ['database' => 'labdoo_e2e_test', 'host' => 'localhost', 'username' => 'labdoo_e2e_' . substr(hash('sha256', $temporary), 0, 12), 'password' => 'test-only'],
];
$load = static function () use ($temporary, $class_loader, $root): array {
  $app_root = $temporary . '/web';
  $settings = $databases = $config = [];
  require $root . '/web/sites/labdoo_e2e/settings.php';
  return [$settings, $databases, $config];
};
try {
  file_put_contents($state . '/connection.json', json_encode($manifest, JSON_THROW_ON_ERROR));
  [$settings, $databases, $config] = $load();
  verify($databases['default']['default'] === $manifest['connection'], 'Use only the explicit test connection.');
  verify($settings['file_public_path'] === 'sites/labdoo_e2e/files', 'Public test files must be separate.');
  verify($config['system.mail']['interface']['default'] === 'test_mail_collector', 'Tests must capture email.');
  foreach (['root', 'database', 'host', 'username', 'runner'] as $invalid) {
    $candidate = $manifest;
    putenv('LABDOO_E2E_SITE=1');
    if ($invalid === 'root') {
      $candidate['root'] = '/another/checkout';
    }
    elseif ($invalid === 'runner') {
      putenv('LABDOO_E2E_SITE');
    }
    else {
      $candidate['connection'][$invalid] = 'production';
    }
    file_put_contents($state . '/connection.json', json_encode($candidate, JSON_THROW_ON_ERROR));
    $rejected = FALSE;
    try {
      $load();
    }
    catch (RuntimeException $exception) {
      $rejected = TRUE;
    }
    verify($rejected, 'Unsafe settings must fail closed: ' . $invalid);
  }
}
finally {
  putenv('LABDOO_E2E_SITE');
  unlink($state . '/connection.json');
  rmdir($state);
  rmdir(dirname($state));
  rmdir($temporary . '/tests/upgrade');
  rmdir($temporary . '/tests');
  rmdir($temporary);
}
echo "PASS: Drupal site routing, isolated files/mail, and invalid-connection rejection.\n";
