<?php

namespace Drupal\labdoo_common\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Drupal\Core\Routing\RoutingEvents;
use Symfony\Component\Routing\RouteCollection;

/**
 * Overrides contrib geocoder API routes with custom controller.
 */
class RouteSubscriber extends RouteSubscriberBase {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    $events[RoutingEvents::ALTER] = ['onAlterRoutes', -200];
    return $events;
  }

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection): void {
    $node_revision_history = $collection->get('entity.node.version_history');
    if ($node_revision_history) {
      $node_revision_history->setDefault('_controller', '\\Drupal\\labdoo_common\\Controller\\NodeRevisionOverviewController::revisionOverview');
    }

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
