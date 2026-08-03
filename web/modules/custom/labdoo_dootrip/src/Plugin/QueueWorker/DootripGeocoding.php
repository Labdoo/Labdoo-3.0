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
      $originCity = '';
      $originCountry = '';
      $destinationCity = '';
      $destinationCountry = '';

      // Process Origin
      if ($node->hasField('field_origin_of_the_trip') && !$node->get('field_origin_of_the_trip')->isEmpty()) {
        $originData = $node->get('field_origin_of_the_trip')->first()->getValue();
        if (isset($originData['lat']) && isset($originData['lon'])) {
          if (abs((float)$originData['lat']) > 0.1 || abs((float)$originData['lon']) > 0.1) {
            $coordinatesData = $this->reverseLookupCoordinates((float)$originData['lat'], (float)$originData['lon']);
            if (!empty($coordinatesData['country_code'])) {
              $originCountryCode = $coordinatesData['country_code'];
              $countryManager = \Drupal::service('country_manager');
              $countries = $countryManager->getList();
              $originCountry = isset($countries[$originCountryCode]) ? (string) $countries[$originCountryCode] : $originCountryCode;
              $originCity = $coordinatesData['city'];

              \Drupal::logger('labdoo_dootrip')->info('Geocoded origin for dootrip @nid: @country, @city', [
                '@nid' => $nid,
                '@country' => $originCountry,
                '@city' => $originCity,
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
              $destinationCountryCode = $coordinatesData['country_code'];
              $countryManager = \Drupal::service('country_manager');
              $countries = $countryManager->getList();
              $destinationCountry = isset($countries[$destinationCountryCode]) ? (string) $countries[$destinationCountryCode] : $destinationCountryCode;
              $destinationCity = $coordinatesData['city'];

              \Drupal::logger('labdoo_dootrip')->info('Geocoded destination for dootrip @nid: @country, @city', [
                '@nid' => $nid,
                '@country' => $destinationCountry,
                '@city' => $destinationCity,
              ]);
            }
          }
        }
      }

      $title = $node->getTitle();
      $extractedId = NULL;
      if ($title && preg_match('/Dootrip\s+#(\d+)/i', $title, $matches)) {
        $extractedId = (int) $matches[1];
      }
      if ($extractedId === NULL) {
        $extractedId = (int) $node->id();
      }

      $prefix = 'Dootrip #' . sprintf('%09d', $extractedId);
      $suffix = '';
      if (!empty($originCity) || !empty($originCountry)) {
        $origin_text = '';
        if (!empty($originCity) && !empty($originCountry)) {
          $origin_text = "$originCity ($originCountry)";
        } elseif (!empty($originCountry)) {
          $origin_text = $originCountry;
        } else {
          $origin_text = $originCity;
        }
        $suffix .= " - from $origin_text";
      }
      if (!empty($destinationCity) || !empty($destinationCountry)) {
        $destination_text = '';
        if (!empty($destinationCity) && !empty($destinationCountry)) {
          $destination_text = "$destinationCity ($destinationCountry)";
        } elseif (!empty($destinationCountry)) {
          $destination_text = $destinationCountry;
        } else {
          $destination_text = $destinationCity;
        }
        $suffix .= " to $destination_text";
      }

      $newTitle = $prefix . $suffix;
      if ($title !== $newTitle) {
        $node->setTitle($newTitle);
        $modified = TRUE;
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
        $adminLevels = $address->getAdminLevels();
        $result['city'] = $address->getLocality() ?: ($adminLevels->has(2) ? $adminLevels->get(2)->getName() : '') ?: '';
      }
    }
    catch (\Exception $e) {
      $msg = $e->getMessage();
      \Drupal::logger('labdoo_dootrip')->warning('Google Maps reverse geocode failed: @message. Trying fallback Nominatim API...', ['@message' => $msg]);

      // Fallback to Nominatim OpenStreetMap API
      try {
        $client = \Drupal::httpClient();
        $response = $client->get("https://nominatim.openstreetmap.org/reverse?lat={$lat}&lon={$lon}&format=json", [
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
        \Drupal::logger('labdoo_dootrip')->error('Fallback Nominatim reverse geocode failed: @message', ['@message' => $fallbackException->getMessage()]);

        // Circuit Breaker: If API is blocked (403) or rate limited (429), suspend queue.
        if (
          strpos($msg, 'Access Not Configured') !== FALSE ||
          strpos($msg, 'API keys with referer restrictions') !== FALSE ||
          strpos($msg, '403') !== FALSE ||
          strpos($msg, '429') !== FALSE ||
          strpos($msg, 'QuotaExceeded') !== FALSE
        ) {
          throw new \Drupal\Core\Queue\SuspendQueueException('Google Maps API Error: ' . $msg);
        }

        // Re-throw to ensure the queue item is not deleted for other transient errors.
        throw $e;
      }
    }

    return $result;
  }

}
