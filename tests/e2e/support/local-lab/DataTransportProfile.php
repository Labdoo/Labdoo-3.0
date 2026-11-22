<?php

declare(strict_types=1);

namespace Labdoo\E2e;

use Composer\Autoload\ClassLoader;
use Labdoo\Operations\DataProtectionSettings;

/**
 * Enables disposable loopback Redis TLS without relaxing the database guard.
 */
final class DataTransportProfile {

  /**
   * Accepts only this checkout's authenticated local cache fixture.
   */
  public static function apply(string $root, string $state, array $profile, array &$databases, array &$settings, ClassLoader $loader): void {
    $configuration = $profile['configuration'] ?? [];
    $redis = $configuration['redis'] ?? [];
    $ca = realpath($redis['ca_file'] ?? '');
    $expectedPrefix = 'labdoo_e2e_' . substr(hash('sha256', $root), 0, 12);
    if (($profile['root'] ?? NULL) !== $root
      || $state !== $root . '/tests/upgrade/.local/state'
      || array_diff(array_keys($configuration), ['redis', 'cache_prefix'])
      || !in_array($redis['host'] ?? NULL, ['localhost', '127.0.0.1'], TRUE)
      || ($redis['authentication_required'] ?? NULL) !== TRUE
      || ($configuration['cache_prefix'] ?? NULL) !== $expectedPrefix
      || $ca === FALSE || !str_starts_with($ca, $state . DIRECTORY_SEPARATOR)) {
      throw new \RuntimeException('Refusing an unscoped or non-local data transport fixture.');
    }
    require_once $root . '/scripts/runtime/DataProtectionSettings.php';
    DataProtectionSettings::apply($databases, $settings, $loader, $configuration);
    $settings['container_yamls'][] = $root . '/web/modules/contrib/redis/redis.services.yml';
    $settings['cache']['default'] = 'cache.backend.redis';
    // Keep the bootstrap container on its guarded local database. Application
    // bins exercise the authenticated TLS cache after its services are loaded.
    foreach (['container', 'bootstrap', 'discovery', 'config'] as $bin) {
      $settings['cache']['bins'][$bin] = 'cache.backend.database';
    }
    $settings['labdoo_e2e_tls_redis'] = TRUE;
  }

}
