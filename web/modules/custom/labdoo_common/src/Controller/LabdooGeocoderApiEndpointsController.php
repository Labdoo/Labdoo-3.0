<?php

namespace Drupal\labdoo_common\Controller;

use Drupal\geocoder\Controller\GeocoderApiEnpoints;
use Geocoder\Model\AddressCollection;
use Symfony\Component\HttpFoundation\Request;

/**
 * Custom override for Geocoder API endpoints.
 */
class LabdooGeocoderApiEndpointsController extends GeocoderApiEnpoints {

  /**
   * {@inheritdoc}
   */
  public function geocode(Request $request) {
    $address = $request->query->get('address');
    $format = $request->query->get('format');

    try {
      $geocoders = $this->getRequestedGeocoders($request);
      $address_format = $request->query->get('address_format');

      if (isset($address)) {
        $dumper = $this->getDumper($format);
        $geo_collection = $this->geocoder->geocode($address, $geocoders);
        if ($geo_collection instanceof AddressCollection) {
          $this->getAddressCollectionResponse($geo_collection, $dumper, $address_format);
        }
      }
    }
    catch (\Exception $e) {
      $this->getLogger('geocoder')->error($e->getMessage());
    }
    return $this->response;
  }

  /**
   * {@inheritdoc}
   */
  public function reverseGeocode(Request $request) {

    $latlng = $request->query->get('latlng');
    $format = $request->query->get('format');

    try {
      $geocoders = $this->getRequestedGeocoders($request);
      if (isset($latlng)) {
        $latlng = explode(',', $request->query->get('latlng'));
        $dumper = $this->getDumper($format);
        $geo_collection = $this->geocoder->reverse($latlng[0], $latlng[1], $geocoders);
        if ($geo_collection instanceof AddressCollection) {
          $this->getAddressCollectionResponse($geo_collection, $dumper);
        }
      }
    }
    catch (\Exception $e) {
      $this->getLogger('geocoder')->error($e->getMessage());
    }
    return $this->response;
  }

  /**
   * Gets geocoder providers requested in query, with a safe fallback.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return array
   *   The loaded geocoder providers.
   */
  protected function getRequestedGeocoders(Request $request): array {
    $geocoders_ids = trim((string) $request->query->get('geocoder', ''));
    $storage = $this->entityTypeManager->getStorage('geocoder_provider');

    if ($geocoders_ids === '') {
      return $storage->loadMultiple();
    }

    $requested_ids = array_values(array_filter(array_map('trim', explode(',', $geocoders_ids))));
    return $storage->loadMultiple($requested_ids);
  }

}
