<?php

/**
 * @file
 * Exercise data transport only against disposable loopback fixtures.
 */

declare(strict_types=1);

use Labdoo\E2e\DataTransportProfile;
use Labdoo\Operations\DataProtectionSettings;
use Labdoo\Operations\VerifiedPredisClient;

// This fixture runner accepts local disposable endpoints only.
$root = dirname(__DIR__, 2);
$loader = require $root . '/vendor/autoload.php';
$loader->addPsr4('Drupal\\redis\\', $root . '/web/modules/contrib/redis/src');
require_once $root . '/scripts/runtime/VerifiedPredisClient.php';
require_once $root . '/scripts/runtime/DataProtectionSettings.php';
$input = json_decode(file_get_contents($argv[1]), TRUE, 512, JSON_THROW_ON_ERROR);
$configuration = $input['redis'];
if (!in_array($configuration['host'], ['localhost', '127.0.0.1'], TRUE)) {
  throw new RuntimeException('Transport fixtures must remain on loopback.');
}
$operation = $argv[2];
set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
  throw new ErrorException($message, 0, $severity, $file, $line);
});
try {
  if ($operation === 'database_settings') {
    $databases = ['default' => ['default' => [
      'driver' => 'mysql', 'host' => 'localhost', 'username' => 'fixture',
      'password' => 'fixture-secret', 'database' => 'labdoo_e2e_fixture',
      'pdo' => [PDO::ATTR_TIMEOUT => 7], 'init_commands' => ['fixture' => 'SET SQL_BIG_SELECTS=1'],
    ]], 'other' => ['default' => ['driver' => 'mysql', 'host' => 'other.invalid']]];
    $settings = ['cache_prefix' => 'previous'];
    $before = $databases;
    DataProtectionSettings::apply($databases, $settings, $loader, [
      'database' => ['expected_host' => 'localhost', 'ca_file' => $configuration['ca_file']],
      'cache_prefix' => 'labdoo',
    ]);
    if ($databases['default']['default']['pdo'][PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] !== TRUE
      || $databases['default']['default']['pdo'][PDO::MYSQL_ATTR_SSL_CA] !== $configuration['ca_file']
      || $databases['default']['default']['pdo'][PDO::ATTR_TIMEOUT] !== 7
      || $databases['default']['default']['password'] !== $before['default']['default']['password']
      || $databases['default']['default']['init_commands'] !== $before['default']['default']['init_commands']
      || $databases['other'] !== $before['other']
      || $settings['cache_prefix'] !== ['default' => 'labdoo']) {
      throw new LogicException('Database options or unrelated connections changed.');
    }
    echo json_encode(['database_tls_verification_required' => TRUE, 'credentials_and_options_preserved' => TRUE]);
    exit(0);
  }
  if (str_starts_with($operation, 'profile_')) {
    require_once $root . '/tests/e2e/support/local-lab/DataTransportProfile.php';
    $state = $root . '/tests/upgrade/.local/state';
    if (!is_dir($state)) {
      mkdir($state, 0700, TRUE);
    }
    $ca = $state . '/data-transport-guard-' . bin2hex(random_bytes(8)) . '.pem';
    copy($configuration['ca_file'], $ca);
    chmod($ca, 0600);
    $databases = ['default' => ['default' => ['database' => 'labdoo_e2e_fixture', 'host' => 'localhost']]];
    $before = $databases;
    $settings = [];
    $profile = [
      'root' => $root,
      'configuration' => [
        'redis' => array_replace($configuration, ['ca_file' => $ca]),
        'cache_prefix' => 'labdoo_e2e_' . substr(hash('sha256', $root), 0, 12),
      ],
    ];
    switch ($operation) {
      case 'profile_root':
        $profile['root'] = '/another/checkout';
        break;

      case 'profile_host':
        $profile['configuration']['redis']['host'] = 'production.invalid';
        break;

      case 'profile_ca':
        $profile['configuration']['redis']['ca_file'] = $configuration['ca_file'];
        break;

      case 'profile_prefix':
        $profile['configuration']['cache_prefix'] = 'unrelated_application';
        break;

      case 'profile_database':
        $profile['configuration']['database'] = ['expected_host' => 'production.invalid', 'ca_file' => $ca];
        break;

      case 'profile_auth':
        $profile['configuration']['redis']['authentication_required'] = FALSE;
        break;
    }
    $rejected = FALSE;
    try {
      DataTransportProfile::apply($root, $state, $profile, $databases, $settings, $loader);
    }
    catch (RuntimeException $exception) {
      if (!str_starts_with($exception->getMessage(), 'Refusing an unscoped or non-local')) {
        throw $exception;
      }
      $rejected = TRUE;
    }
    finally {
      unlink($ca);
    }
    if ($databases !== $before || $rejected !== ($operation !== 'profile_valid')) {
      throw new LogicException('The local transport profile did not preserve its isolation boundaries.');
    }
    if (!$rejected && ($settings['labdoo_e2e_tls_redis'] ?? NULL) !== TRUE) {
      throw new LogicException('The local TLS profile was not enabled.');
    }
    echo json_encode(['local_profile_isolation_enforced' => TRUE]);
    exit(0);
  }
  $expectCertificateFailure = in_array($operation, ['bad_ca', 'wrong_hostname'], TRUE);
  $expectAuthFailure = in_array($operation, ['wrong_password', 'no_auth'], TRUE);
  if ($operation === 'bad_ca') {
    $configuration['ca_file'] = $input['wrong_ca'];
  }
  elseif ($operation === 'wrong_hostname') {
    $configuration['host'] = '127.0.0.1';
  }
  elseif ($operation === 'wrong_password') {
    $configuration['password'] = str_repeat('Z', 64);
  }
  elseif ($operation === 'no_auth') {
    unset($configuration['username'], $configuration['password']);
    $configuration['authentication_required'] = FALSE;
  }
  $invalidOperations = ['missing_ca', 'missing_password', 'plaintext_override', 'wrong_endpoint', 'replication'];
  $invalid = in_array($operation, $invalidOperations, TRUE);
  if ($operation === 'missing_ca') {
    $configuration['ca_file'] .= '.missing';
  }
  elseif ($operation === 'missing_password') {
    unset($configuration['password']);
  }
  elseif ($operation === 'plaintext_override') {
    $configuration['scheme'] = 'tcp';
  }
  try {
    $factory = new VerifiedPredisClient($configuration);
    $client = $factory->getClient(
      $operation === 'wrong_endpoint' ? '127.0.0.1' : NULL,
      NULL,
      0,
      NULL,
      $operation === 'replication' ? [['host' => 'localhost']] : [],
    );
    if ($invalid) {
      throw new LogicException('An invalid transport configuration was accepted.');
    }
    if ((string) $client->ping() !== 'PONG') {
      throw new LogicException('Authenticated TLS PING failed.');
    }
    if ($expectCertificateFailure || $expectAuthFailure) {
      throw new LogicException('An invalid peer or credential was accepted.');
    }
    $key = 'labdoo:transport-probe';
    $client->set($key, 'verified');
    if ($client->get($key) !== 'verified') {
      throw new LogicException('TLS data round trip failed.');
    }
    $client->del([$key]);
    if ($operation === 'acl_boundaries') {
      $rejected = 0;
      foreach ([['SET', 'unrelated:must-deny', 'x'], ['CONFIG', 'GET', '*'], ['ACL', 'LIST'], ['FLUSHALL']] as $command) {
        $error = FALSE;
        $response = $client->executeRaw($command, $error);
        if (!$error || !is_string($response) || !str_contains($response, 'NOPERM')) {
          throw new LogicException('Redis did not reject the prohibited command with NOPERM.');
        }
        $rejected++;
      }
      if ($rejected !== 4) {
        throw new LogicException('Redis ACL boundaries were not enforced.');
      }
    }
    echo json_encode(['authenticated_tls_round_trip' => TRUE, 'acl_denials' => $rejected ?? NULL]);
  }
  catch (Throwable $exception) {
    $message = $exception->getMessage();
    if (($invalid && $exception instanceof InvalidArgumentException)
      || ($expectCertificateFailure && preg_match('/certificate|SSL operation|peer_name|CN=|TLS/i', $message))
      || ($expectAuthFailure && preg_match('/NOAUTH|WRONGPASS|AUTH failed|invalid username-password/i', $message))) {
      echo json_encode(['expected_rejection' => TRUE, 'operation' => $operation]);
    }
    else {
      throw $exception;
    }
  }
}
catch (Throwable $exception) {
  $path = $argv[1] . '.failure.json';
  $failure = ['operation' => $operation, 'class' => get_class($exception), 'message' => $exception->getMessage()];
  file_put_contents($path, json_encode($failure));
  chmod($path, 0600);
  fwrite(STDERR, 'Transport fixture failed; inspect its private failure receipt.' . PHP_EOL);
  exit(1);
}
