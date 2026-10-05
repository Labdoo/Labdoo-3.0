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
   * Maximum number of cron runs to retry an item with no usable geocoding.
   */
  private const MAX_GEOCODING_ATTEMPTS = 3;

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

        $state = \Drupal::state();
        $attempt_key = 'labdoo_dootronics.geocoding_attempts.' . $nid;
        try {
          $coordinatesData = $this->reverseLookupCoordinates((float) $locationData['lat'], (float) $locationData['lon']);
        }
        catch (\Throwable $e) {
          // Retain transient provider failures briefly, but cap the retries so
          // a permanently failing provider cannot pin this queue item forever.
          $attempts = (int) $state->get($attempt_key, 0) + 1;
          if ($attempts < self::MAX_GEOCODING_ATTEMPTS) {
            $state->set($attempt_key, $attempts);
            throw new \RuntimeException(sprintf('Geocoding provider request failed for dootronic %d (attempt %d of %d).', $nid, $attempts, self::MAX_GEOCODING_ATTEMPTS), 0, $e);
          }

          $state->delete($attempt_key);
          \Drupal::logger('labdoo_dootronics')->error('Discarding dootronic @nid from geocoding queue after @attempts provider errors (@exception).', [
            '@nid' => $nid,
            '@attempts' => self::MAX_GEOCODING_ATTEMPTS,
            '@exception' => get_class($e),
          ]);
          return;
        }
        
        if (empty($coordinatesData['country_code'])) {
          $state->delete($attempt_key);
          \Drupal::logger('labdoo_dootronics')->error('Discarding dootronic @nid from geocoding queue: Google Maps and Nominatim returned no country for its coordinates.', [
            '@nid' => $nid,
          ]);
          return;
        }

        // Clear any prior failure count after a successful lookup.
        \Drupal::state()->delete('labdoo_dootronics.geocoding_attempts.' . $nid);

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
        $adminLevels = $address->getAdminLevels();
        $result['city'] = $address->getLocality() ?: ($adminLevels->has(2) ? $adminLevels->get(2)->getName() : '') ?: '';
      }
    }
    catch (\Exception $e) {
      $msg = $e->getMessage();
      \Drupal::logger('labdoo_dootronics')->warning('Google Maps reverse geocode failed; trying Nominatim fallback (@exception).', ['@exception' => get_class($e)]);
    }

    // Google can return a successful but unusable response (for example, no
    // country for a coordinate). Use the fallback for that case too. Nominatim
    // requests are limited to one per second by its public service policy.
    if (empty($result['country_code'])) {
      $result = $this->reverseLookupNominatim($lat, $lon);
    }

    return $result;
  }

  /**
   * Reverse geocode coordinates with Nominatim.
   *
   * @throws \Throwable
   *   When the provider request fails. The caller applies the bounded retry.
   */
  private function reverseLookupNominatim(float $lat, float $lon): array {
    $result = ['country_code' => '', 'city' => ''];
    $cache = \Drupal::cache('default');
    $cache_id = 'labdoo_dootronics.nominatim.' . hash('sha256', sprintf('%.6F,%.6F', $lat, $lon));
    if ($cached = $cache->get($cache_id)) {
      return $cached->data;
    }

    // Regular jobs using public Nominatim are limited to four requests per
    // minute. Serialize callers and space requests from completion to start.
    $lock_name = 'labdoo_dootronics.nominatim_rate_limit';
    $lock = \Drupal::lock();
    while (!$lock->acquire($lock_name, 60)) {
      usleep(250000);
    }
    try {
      $last_request = (float) \Drupal::state()->get($lock_name, 0);
      $wait = 16 - (microtime(TRUE) - $last_request);
      if ($wait > 0) {
        usleep((int) ($wait * 1000000));
      }

      $response = \Drupal::httpClient()->get('https://nominatim.openstreetmap.org/reverse', [
        'query' => ['lat' => $lat, 'lon' => $lon, 'format' => 'jsonv2'],
        'headers' => ['User-Agent' => 'Labdoo Geocoding Fallback Bot'],
        'timeout' => 15,
      ]);
      $data = json_decode((string) $response->getBody(), TRUE);
      $address = $data['address'] ?? [];
      if (!empty($address['country_code'])) {
        $result['country_code'] = strtoupper($address['country_code']);
      }
      $result['city'] = $address['city'] ?? $address['town'] ?? $address['village'] ?? $address['hamlet'] ?? $address['municipality'] ?? $address['suburb'] ?? '';

      // Cache both positive and empty results to avoid repeating queries.
      $cache->set($cache_id, $result, \Drupal::time()->getRequestTime() + 2592000);
      return $result;
    }
    finally {
      \Drupal::state()->set($lock_name, microtime(TRUE));
      $lock->release($lock_name);
    }

  }

}
