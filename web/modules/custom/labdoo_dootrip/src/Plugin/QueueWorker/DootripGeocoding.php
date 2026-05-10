<?php

namespace Drupal\labdoo_dootrip\Plugin\QueueWorker;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\geocoder\GeocoderInterface;
use Drupal\labdoo_dootrip\Service\Compute\DootripComputeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Updates Dootrip location from coordinates.
 *
 * @QueueWorker(
 *   id = "labdoo_dootrip_geocoding",
 *   title = @Translation("Dootrip Geocoding"),
 *   cron = {"time" = 60}
 * )
 */
class DootripGeocoding extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The Dootrip compute service.
   *
   * @var \Drupal\labdoo_dootrip\Service\Compute\DootripComputeInterface
   */
  protected DootripComputeInterface $dootripCompute;

  /**
   * The geocoder service.
   *
   * @var \Drupal\geocoder\GeocoderInterface
   */
  protected GeocoderInterface $geocoder;

  /**
   * Constructs a new DootripGeocoding object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\labdoo_dootrip\Service\Compute\DootripComputeInterface $dootrip_compute
   *   The Dootrip compute service.
   * @param \Drupal\geocoder\GeocoderInterface $geocoder
   *   The geocoder service.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, DootripComputeInterface $dootrip_compute, GeocoderInterface $geocoder) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entity_type_manager;
    $this->dootripCompute = $dootrip_compute;
    $this->geocoder = $geocoder;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('labdoo_dootrip.compute'),
      $container->get('geocoder')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data) {
    $nid = $data['nid'];
    /** @var \Drupal\node\NodeInterface $node */
    $node = $this->entityTypeManager->getStorage('node')->load($nid);

    if (
      $node
      && $node->bundle() === 'dootrip'
    ) {
      $modified = FALSE;

      // Process Origin
      if ($node->hasField('field_origin_of_the_trip') && !$node->get('field_origin_of_the_trip')->isEmpty()) {
        $originData = $node->get('field_origin_of_the_trip')->first()->getValue();
        if (isset($originData['lat']) && isset($originData['lon'])) {
          if (abs((float)$originData['lat']) > 0.1 || abs((float)$originData['lon']) > 0.1) {
            $coordinatesData = $this->reverseLookupCoordinates((float)$originData['lat'], (float)$originData['lon']);
            // We can log or extend this if fields are added later.
            if (!empty($coordinatesData['country_code'])) {
              \Drupal::logger('labdoo_dootrip')->info('Geocoded origin for dootrip @nid: @country, @city', [
                '@nid' => $nid,
                '@country' => $coordinatesData['country_code'],
                '@city' => $coordinatesData['city'],
              ]);
            }
          }
        }
      }

      // Process Destination
      if ($node->hasField('field_destination_of_the_trip') && !$node->get('field_destination_of_the_trip')->isEmpty()) {
        $destinationData = $node->get('field_destination_of_the_trip')->first()->getValue();
        if (isset($destinationData['lat']) && isset($destinationData['lon'])) {
          if (abs((float)$destinationData['lat']) > 0.1 || abs((float)$destinationData['lon']) > 0.1) {
            $coordinatesData = $this->reverseLookupCoordinates((float)$destinationData['lat'], (float)$destinationData['lon']);
            if (!empty($coordinatesData['country_code'])) {
              \Drupal::logger('labdoo_dootrip')->info('Geocoded destination for dootrip @nid: @country, @city', [
                '@nid' => $nid,
                '@country' => $coordinatesData['country_code'],
                '@city' => $coordinatesData['city'],
              ]);
            }
          }
        }
      }

      if ($modified) {
        $node->save();
      }
    }
  }

  /**
   * Reverse geocode coordinates to get country and city.
   *
   * @param float $lat
   *   Latitude.
   * @param float $lon
   *   Longitude.
   *
   * @return array
   *   Array with 'country_code' and 'city'.
   */
  private function reverseLookupCoordinates(float $lat, float $lon): array {
    $result = ['country_code' => '', 'city' => ''];

    try {
      // Use the geocoder service with the 'googlemaps' provider.
      $addressCollection = $this->geocoder->reverse((string) $lat, (string) $lon, ['googlemaps']);

      if ($addressCollection && !$addressCollection->isEmpty()) {
        /** @var \Geocoder\Location $address */
        $address = $addressCollection->first();

        // Extract country code.
        if ($country = $address->getCountry()) {
          $result['country_code'] = $country->getCode();
        }

        // Extract city (locality or admin area).
        $result['city'] = $address->getLocality() ?: $address->getAdminLevels()->get(2)->getName() ?: '';
      }
    }
    catch (\Exception $e) {
      \Drupal::logger('labdoo_dootrip')->error('Failed to reverse geocode dootrip coordinates: @message', ['@message' => $e->getMessage()]);
      // Re-throw to ensure the queue item is not deleted.
      throw $e;
    }

    return $result;
  }

}
