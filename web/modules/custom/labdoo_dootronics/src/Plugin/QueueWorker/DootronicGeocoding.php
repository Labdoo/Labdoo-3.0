<?php

namespace Drupal\labdoo_dootronics\Plugin\QueueWorker;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\geocoder\GeocoderInterface;
use Drupal\labdoo_dootronics\Service\Compute\DootronicComputeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Updates Dootronic location from coordinates.
 *
 * @QueueWorker(
 *   id = "labdoo_dootronic_geocoding",
 *   title = @Translation("Dootronic Geocoding"),
 *   cron = {"time" = 60}
 * )
 */
class DootronicGeocoding extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The Dootronic compute service.
   *
   * @var \Drupal\labdoo_dootronics\Service\Compute\DootronicComputeInterface
   */
  protected DootronicComputeInterface $dootronicCompute;

  /**
   * The geocoder service.
   *
   * @var \Drupal\geocoder\GeocoderInterface
   */
  protected GeocoderInterface $geocoder;

  /**
   * Constructs a new DootronicGeocoding object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\labdoo_dootronics\Service\Compute\DootronicComputeInterface $dootronic_compute
   *   The Dootronic compute service.
   * @param \Drupal\geocoder\GeocoderInterface $geocoder
   *   The geocoder service.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, DootronicComputeInterface $dootronic_compute, GeocoderInterface $geocoder) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entity_type_manager;
    $this->dootronicCompute = $dootronic_compute;
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
      $container->get('labdoo_dootronics.compute'),
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
      && $node->bundle() === 'dootronic'
      && $node->hasField('field_locations')
      && !$node->get('field_locations')->isEmpty()
    ) {
      $locationData = $node->get('field_locations')->first()->getValue();
      if (isset($locationData['lat']) && isset($locationData['lon'])) {
        // Validation check before reverse lookup (extra safety).
        if (abs((float)$locationData['lat']) < 0.1 && abs((float)$locationData['lon']) < 0.1) {
          return;
        }

        $coordinatesData = $this->reverseLookupCoordinates((float)$locationData['lat'], (float)$locationData['lon']);
        
        if (empty($coordinatesData['country_code'])) {
          // If we couldn't get a country code, throw exception to keep in queue for retry.
          throw new \Exception(sprintf('Failed to reverse geocode dootronic %d. No results or API error.', $nid));
        }

        $countryCode = $coordinatesData['country_code'];
        $node->set('field_country', $countryCode);

        if (!empty($coordinatesData['city']) && $node->hasField('field_city')) {
          $node->set('field_city', $coordinatesData['city']);
        }

        // Save the node.
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
      \Drupal::logger('labdoo_dootronics')->error('Failed to reverse geocode dootronic coordinates: @message', ['@message' => $e->getMessage()]);
      // Re-throw to ensure the queue item is not deleted.
      throw $e;
    }

    return $result;
  }

}
