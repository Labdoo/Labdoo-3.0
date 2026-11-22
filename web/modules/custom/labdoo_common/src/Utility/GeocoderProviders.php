<?php

declare(strict_types=1);

namespace Drupal\labdoo_common\Utility;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\geocoder\GeocoderProviderInterface;

/**
 * Resolves configured provider IDs for the object-based geocoder API.
 */
final class GeocoderProviders {

  /**
   * Loads configured providers, rejecting incompatible storage results.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entities
   *   The entity type manager.
   * @param string[] $ids
   *   The requested configuration IDs.
   *
   * @return \Drupal\geocoder\GeocoderProviderInterface[]
   *   Configured providers, keyed by ID; absent configurations are omitted.
   */
  public static function load(EntityTypeManagerInterface $entities, array $ids): array {
    $providers = $entities->getStorage('geocoder_provider')->loadMultiple($ids);
    foreach ($providers as $provider) {
      if (!$provider instanceof GeocoderProviderInterface) {
        throw new \UnexpectedValueException('Geocoder provider storage returned an incompatible entity.');
      }
    }
    return $providers;
  }

}
