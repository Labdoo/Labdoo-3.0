<?php

namespace Drupal\labdoo_common\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/**
 * Overrides contrib geocoder API routes with custom controller.
 */
class RouteSubscriber extends RouteSubscriberBase {

  /**
   * {@inheritdoc}
   */
  public function alterRoutes(RouteCollection $collection) {
    $geocode_route = $collection->get('geocoder.api.geocode');
    if ($geocode_route) {
      $geocode_route->addDefaults([
        '_controller' => '\\Drupal\\labdoo_common\\Controller\\LabdooGeocoderApiEndpointsController::geocode',
      ]);
    }

    $reverse_route = $collection->get('geocoder.api.reverse_geocode');
    if ($reverse_route) {
      $reverse_route->addDefaults([
        '_controller' => '\\Drupal\\labdoo_common\\Controller\\LabdooGeocoderApiEndpointsController::reverseGeocode',
      ]);
    }
  }

}
