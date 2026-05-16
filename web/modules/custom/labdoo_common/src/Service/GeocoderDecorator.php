<?php

namespace Drupal\labdoo_common\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Site\Settings;
use Drupal\geocoder\Geocoder;
use Drupal\geocoder\ProviderPluginManager;
use Geocoder\Model\AddressCollection;
use Geocoder\Query\GeocodeQuery;

/**
 * Decorator for the Geocoder service to allow local disabling.
 */
class GeocoderDecorator extends Geocoder {

  /**
   * {@inheritdoc}
   */
  public function geocode(GeocodeQuery|string $address, array $providers) {
    if (
      Settings::get('labdoo_geocoding_disabled', FALSE)
      || \Drupal::state()->get('labdoo_geocoding_disabled', FALSE)
      || Settings::get('labdoo_migrate_is_running', FALSE)
      || \Drupal::state()->get('labdoo_migrate_is_running', FALSE)
    ) {
      return NULL;
    }
    return parent::geocode($address, $providers);
  }

  /**
   * {@inheritdoc}
   */
  public function reverse($latitude, $longitude, array $providers): ?AddressCollection {
    if (
      Settings::get('labdoo_geocoding_disabled', FALSE)
      || \Drupal::state()->get('labdoo_geocoding_disabled', FALSE)
      || Settings::get('labdoo_migrate_is_running', FALSE)
      || \Drupal::state()->get('labdoo_migrate_is_running', FALSE)
    ) {
      return NULL;
    }
    return parent::reverse($latitude, $longitude, $providers);
  }

}
