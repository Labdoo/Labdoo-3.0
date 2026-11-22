<?php

namespace Drupal\labdoo_e2e;

use Drupal\geocoder\Geocoder;
use Geocoder\Model\AddressBuilder;
use Geocoder\Model\AddressCollection;
use Geocoder\Query\GeocodeQuery;

/**
 * Returns deterministic geocoding results without external requests.
 */
final class FixtureGeocoder extends Geocoder {

  public function __construct() {}

  /**
   * {@inheritdoc}
   */
  public function geocode(GeocodeQuery|string $address, array $providers): AddressCollection {
    return $this->result(40.4168, -3.7038);
  }

  /**
   * {@inheritdoc}
   */
  public function reverse($latitude, $longitude, array $providers): AddressCollection {
    return $this->result((float) $latitude, (float) $longitude);
  }

  /**
   * Builds a deterministic address collection without external provider calls.
   */
  private function result(float $latitude, float $longitude): AddressCollection {
    $builder = new AddressBuilder('labdoo-e2e');
    $builder->setCoordinates($latitude, $longitude)
      ->setCountry('Spain')->setCountryCode('ES')
      ->setLocality('Madrid')->setPostalCode('28001')->setStreetName('E2E Street');
    return new AddressCollection([$builder->build()]);
  }

}
