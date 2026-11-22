<?php

/**
 * @file
 * Checks report rendering with the dedicated, restricted TLS Redis fixture.
 */

declare(strict_types=1);

use Drupal\Core\Site\Settings;
use Drupal\labdoo_common\Controller\RedisReportController;
use Predis\Client;
use Predis\Response\ServerException;

require __DIR__ . '/assert-isolated.php';

$expected = 'labdoo_e2e_' . substr(hash('sha256', dirname(Drupal::root())), 0, 12);
$prefix = Settings::get('cache_prefix');
$prefix = is_array($prefix) ? ($prefix['default'] ?? NULL) : $prefix;
if (!Settings::get('labdoo_e2e_tls_redis', FALSE) || $prefix !== $expected) {
  throw new RuntimeException('The dedicated TLS Redis fixture is required.');
}
$client = Drupal::service('redis.factory')->getClient();
if (!$client instanceof Client) {
  throw new RuntimeException('The dedicated Predis fixture is required.');
}
$parameters = $client->getConnection()->getParameters();
if (!in_array($parameters->host, ['localhost', '127.0.0.1'], TRUE) || $parameters->scheme !== 'tls') {
  throw new RuntimeException('Refusing a nonlocal or unencrypted Redis fixture.');
}
$controller = RedisReportController::create(Drupal::getContainer());
$build = $controller->overview();
$html = (string) Drupal::service('renderer')->renderRoot($build);
if (!str_contains($html, 'Connected') || !str_contains($html, 'maxmemory policy')) {
  throw new RuntimeException('The report did not render its actual connection and memory metrics.');
}
try {
  $error = FALSE;
  $response = $client->executeRaw(['CONFIG', 'GET', 'maxmemory*'], $error);
  $denied = $error && is_string($response) && str_starts_with($response, 'NOPERM');
}
catch (ServerException $exception) {
  $denied = str_starts_with($exception->getMessage(), 'NOPERM');
}
if (!$denied) {
  throw new RuntimeException('The cache account unexpectedly has configuration privileges.');
}
echo 'REDIS_REPORT_JSON:' . json_encode([
  'rendered' => TRUE,
  'configuration_denied' => TRUE,
], JSON_THROW_ON_ERROR);
