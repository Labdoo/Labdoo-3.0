<?php

namespace Drupal\labdoo_edoovillage\Plugin\QueueWorker;

use Drupal\Core\Queue\SuspendQueueException;
use Drupal\Core\Site\Settings;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\geocoder\GeocoderInterface;
use Drupal\labdoo_edoovillage\Service\Compute\EdooVillageComputeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Updates Edoovillage location from coordinates.
 *
 * @QueueWorker(
 *   id = "labdoo_edoovillage_geocoding",
 *   title = @Translation("Edoovillage Geocoding"),
 *   cron = {"time" = 60}
 * )
 */
class EdooVillageGeocoding extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The EdooVillage compute service.
   *
   * @var \Drupal\labdoo_edoovillage\Service\Compute\EdooVillageComputeInterface
   */
  protected EdooVillageComputeInterface $edoovillageCompute;

  /**
   * The geocoder service.
   *
   * @var \Drupal\geocoder\GeocoderInterface
   */
  protected GeocoderInterface $geocoder;

  /**
   * Constructs a new EdooVillageGeocoding object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\labdoo_edoovillage\Service\Compute\EdooVillageComputeInterface $edoovillage_compute
   *   The EdooVillage compute service.
   * @param \Drupal\geocoder\GeocoderInterface $geocoder
   *   The geocoder service.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, EdooVillageComputeInterface $edoovillage_compute, GeocoderInterface $geocoder) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entity_type_manager;
    $this->edoovillageCompute = $edoovillage_compute;
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
      $container->get('labdoo_edoovillage.compute'),
      $container->get('geocoder')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data) {
    $nid = is_array($data) ? filter_var($data['nid'] ?? NULL, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : FALSE;
    if (!$nid) {
      return;
    }
    /** @var \Drupal\node\NodeInterface $node */
    $node = $this->entityTypeManager->getStorage('node')->load($nid);

    // Stale items must not prevent the remaining queue from being processed.
    if (!$node || $node->bundle() !== 'edoovillage') {
      return;
    }
    $countryMissing = $node->get('field_country')->isEmpty();
    $cityMissing = $node->hasField('field_city') && $node->get('field_city')->isEmpty();
    if (!$countryMissing && !$cityMissing) {
      return;
    }
    if (
      Settings::get('labdoo_geocoding_disabled', FALSE)
      || \Drupal::state()->get('labdoo_geocoding_disabled', FALSE)
      || Settings::get('labdoo_migrate_is_running', FALSE)
      || \Drupal::state()->get('labdoo_migrate_is_running', FALSE)
    ) {
      throw new SuspendQueueException('Geocoding is disabled; retain pending work.');
    }
    $modified = FALSE;

    if (
      $node
      && $node->bundle() === 'edoovillage'
      && $node->hasField('field_location')
      && !$node->get('field_location')->isEmpty()
    ) {
      $locationData = $node->get('field_location')->first()->getValue();
      if (isset($locationData['lat']) && isset($locationData['lon'])) {
        $lat = (float) $locationData['lat'];
        $lon = (float) $locationData['lon'];
        if (!is_finite($lat) || !is_finite($lon) || abs($lat) > 90 || abs($lon) > 180 || ($lat == 0 && $lon == 0) || ($lat == -90 && $lon == -180)) {
          return;
        }
        $coordinatesData = $this->reverseLookupCoordinates($locationData['lat'], $locationData['lon']);
        if (empty($coordinatesData['country_code']) && empty($coordinatesData['city'])) {
          throw new SuspendQueueException('Geocoder returned no location; retain pending work.');
        }
        if ($countryMissing && !empty($coordinatesData['country_code'])) {
          $countryCode = $coordinatesData['country_code'];
          // Persist the country code back to the entity.
          $node->set('field_country', $countryCode);
          $modified = TRUE;
        }
        if ($cityMissing && !empty($coordinatesData['city'])) {
          $nodeCity = $coordinatesData['city'];
          // Persist the city back to the entity if the field exists.
          if ($node->hasField('field_city')) {
            $node->set('field_city', $nodeCity);
            $modified = TRUE;
          }
        }
        if (!$modified) {
          throw new SuspendQueueException('Geocoder did not resolve the missing fields; retain pending work.');
        }
      }
    }

    if (!$modified) {
      return;
    }

    // Re-generate title with new country/city data.
    // We pass a copy to avoid recursion or multiple saves if setEdooVillageTitle were to trigger anything,
    // although here it's safe because it only modifies the entity in memory.
    $this->edoovillageCompute->setEdooVillageTitle($node);

    // Save the node to persist updated title and fields (country/city).
    // Use a flag to avoid re-queueing if possible, but __labdoo_edoovillage_enqueue_geocoding
    // already checks if country/city are empty.
    // Do not enqueue the same geocoding task again from the update hook.
    $node->skip_geocoding_enqueue = TRUE;
    $node->save();
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
        $adminLevels = $address->getAdminLevels();
        $result['city'] = $address->getLocality() ?: ($adminLevels->has(2) ? $adminLevels->get(2)->getName() : '') ?: '';
      }
    }
    catch (\Exception $e) {
      $msg = $e->getMessage();
      \Drupal::logger('labdoo_edoovillage')->warning('Google Maps reverse geocode failed: @message. Trying fallback Nominatim API...', ['@message' => $msg]);

      // Fallback to Nominatim OpenStreetMap API.
      try {
        $client = \Drupal::httpClient();
        $response = $client->get("https://nominatim.openstreetmap.org/reverse?lat={$lat}&lon={$lon}&format=json", [
          'connect_timeout' => 3,
          'timeout' => 5,
          'headers' => [
            'User-Agent' => 'Labdoo Geocoding Fallback Bot',
          ],
        ]);
        if ($response->getStatusCode() === 200) {
          $data = json_decode((string) $response->getBody(), TRUE);
          if (!empty($data['address'])) {
            $addr = $data['address'];
            if (!empty($addr['country_code'])) {
              $result['country_code'] = strtoupper($addr['country_code']);
            }
            $result['city'] = $addr['city'] ?? $addr['town'] ?? $addr['village'] ?? $addr['hamlet'] ?? $addr['municipality'] ?? $addr['suburb'] ?? '';
          }
        }
      }
      catch (\Exception $fallbackException) {
        \Drupal::logger('labdoo_edoovillage')->error('Fallback Nominatim reverse geocode failed: @message', ['@message' => $fallbackException->getMessage()]);

        // Circuit Breaker: If API is blocked (403) or rate limited (429), suspend queue.
        if (
          strpos($msg, 'Access Not Configured') !== FALSE ||
          strpos($msg, 'API keys with referer restrictions') !== FALSE ||
          strpos($msg, '403') !== FALSE ||
          strpos($msg, '429') !== FALSE ||
          strpos($msg, 'QuotaExceeded') !== FALSE
        ) {
          throw new SuspendQueueException('Google Maps API Error: ' . $msg);
        }

        // Re-throw to ensure the queue item is not deleted for other transient errors.
        throw $e;
      }
    }

    return $result;
  }

}
