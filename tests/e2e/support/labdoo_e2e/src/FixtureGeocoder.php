<?php

namespace Drupal\labdoo_e2e;

use Geocoder\Model\AddressBuilder;
use Geocoder\Model\AddressCollection;
use Geocoder\Query\GeocodeQuery;

final class FixtureGeocoder extends \Drupal\geocoder\Geocoder {
  public function __construct() {}

  public function geocode(GeocodeQuery|string $address, array $providers) {
    return $this->result(40.4168, -3.7038);
  }

  public function reverse($latitude, $longitude, array $providers): ?AddressCollection {
    return $this->result((float) $latitude, (float) $longitude);
  }

  private function result(float $latitude, float $longitude): AddressCollection {
    $builder = new AddressBuilder('labdoo-e2e');
    $builder->setCoordinates($latitude, $longitude)
      ->setCountry('Spain')->setCountryCode('ES')
      ->setLocality('Madrid')->setPostalCode('28001')->setStreetName('E2E Street');
    return new AddressCollection([$builder->build()]);
  }
}
