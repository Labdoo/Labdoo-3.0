<?php

namespace Drupal\labdoo_global_action\Service\ActionGenerator;

use Drupal\Core\Entity\EntityInterface;
use Drupal\geocoder\GeocoderInterface;
use Drupal\labdoo_common\Service\Repository\CommonRepository;

/**
 * Abstract class for action generators.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
abstract class AbstractActionGenerator {

  /**
   * The geocoder.
   *
   * @var \Drupal\geocoder\GeocoderInterface
   */
  protected GeocoderInterface $geocoder;

  /**
   * The common repository.
   *
   * @var \Drupal\labdoo_common\Service\Repository\CommonRepository
   */
  protected CommonRepository $commonRepository;

  /**
   * Static cache of geocoded coordinates to prevent redundant API calls.
   *
   * @var array
   */
  protected static array $geoCache = [];

  /**
   * AbstractActionGenerator constructor.
   *
   * @param \Drupal\geocoder\GeocoderInterface $geocoder
   *   The geocoder.
   */
  public function __construct(
    GeocoderInterface $geocoder,
    CommonRepository $commonRepository
  ) {
    $this->geocoder = $geocoder;
    $this->commonRepository = $commonRepository;
  }

  /**
   * Checks the preconditions of this action.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   *
   * @return bool
   *   TRUE if the preconditions match, otherwise FALSE.
   */
  protected function preConditions(EntityInterface $entity): bool {
    return TRUE;
  }

  /**
   * Reverse-geocodes a pair of coordinates.
   *
   * @param string $lat
   *   The latitude.
   * @param string $lon
   *   The longitude.
   *
   * @return array|null
   *   An array with two keys: city and country, or NULL in case of error.
   */
  protected function reverseGeocode(string $lat, string $lon): ?array {
    $geocoderConfig = \Drupal::configFactory()->get('geocoder.settings');
    if ($geocoderConfig->get('geocoder_presave_disabled')) {
      return NULL;
    }

    try {
      $addressCollection = $this->geocoder->reverse(
        $lat,
        $lon,
        ['plugin' => 'googlemaps']
      );
    }
    catch (\Throwable $exception) {
      return NULL;
    }

    if ($addressCollection === NULL ||  !$addressCollection->get(0)) {
      return NULL;
    }

    return [
      'city' => $addressCollection->get(0)->getLocality(),
      'country' => $addressCollection->get(0)->getCountry(),
    ];
  }

  /**
   * Resolves city and country code locally from the entity and/or location data.
   */
  protected function resolveLocalGeoData(EntityInterface $entity, array $location, &$city, &$countryCode): void {
    $city = '';
    $countryCode = '';

    // 1. Try to get from entity fields.
    if ($entity->hasField('field_city') && !$entity->get('field_city')->isEmpty()) {
      $city = $entity->get('field_city')->value;
    }
    if ($entity->hasField('field_country') && !$entity->get('field_country')->isEmpty()) {
      $countryCode = $entity->get('field_country')->value;
    }

    // 2. Fallback to location properties.
    if (empty($city)) {
      $city = $location['city'] ?? $location['locality'] ?? '';
    }
    if (empty($countryCode)) {
      $countryCode = $location['country_code'] ?? $location['country'] ?? '';
    }

    // 3. Fallback to reverse geocoding with cache if city is still empty and we have coordinates.
    if (empty($city)) {
      $lat = $location['lat'] ?? $location['latitude'] ?? NULL;
      $lon = $location['lon'] ?? $location['lng'] ?? $location['longitude'] ?? NULL;
      if ($lat !== NULL && $lon !== NULL && (abs((float)$lat) >= 0.1 || abs((float)$lon) >= 0.1)) {
        $geoData = $this->reverseGeocodeWithFallback((string) $lat, (string) $lon);
        if ($geoData !== NULL) {
          $city = $geoData['city'];
          if (empty($countryCode)) {
            $countryCode = $geoData['country_code'];
          }
        }
      }
    }

    $city = trim($city);
    $countryCode = trim($countryCode);

    // Normalize country code to 2-character ISO if it's currently a country name.
    if (strlen($countryCode) > 2) {
      $countryList = \Drupal::service('country_manager')->getList();
      foreach ($countryList as $code => $name) {
        if (strcasecmp($countryCode, (string) $name) === 0) {
          $countryCode = $code;
          break;
        }
      }
    }

    $countryCode = strtoupper($countryCode);
  }

  /**
   * Reverse geocodes coordinates with caching and Nominatim fallback.
   *
   * @param string $lat
   *   Latitude.
   * @param string $lon
   *   Longitude.
   *
   * @return array|null
   *   Array with keys 'city' and 'country_code', or NULL.
   */
  protected function reverseGeocodeWithFallback(string $lat, string $lon): ?array {
    $cacheKey = round((float) $lat, 4) . ',' . round((float) $lon, 4);
    if (isset(self::$geoCache[$cacheKey])) {
      return self::$geoCache[$cacheKey];
    }

    $result = NULL;

    // 1. Try Google Maps geocoder service.
    try {
      $addressCollection = $this->geocoder->reverse(
        $lat,
        $lon,
        ['plugin' => 'googlemaps']
      );
      if ($addressCollection && !$addressCollection->isEmpty()) {
        /** @var \Geocoder\Location $address */
        $address = $addressCollection->first();
        $adminLevels = $address->getAdminLevels();
        $city = $address->getLocality() ?: ($adminLevels->has(2) ? $adminLevels->get(2)->getName() : '') ?: '';
        $countryCode = '';
        if ($country = $address->getCountry()) {
          $countryCode = $country->getCode();
        }
        if (!empty($city) || !empty($countryCode)) {
          $result = [
            'city' => $city,
            'country_code' => $countryCode,
          ];
        }
      }
    }
    catch (\Throwable $e) {
      // Ignore and fallback to Nominatim.
    }

    // 2. Try Nominatim fallback if Google Maps failed or returned empty.
    if ($result === NULL) {
      try {
        $client = \Drupal::httpClient();
        $response = $client->get("https://nominatim.openstreetmap.org/reverse?lat={$lat}&lon={$lon}&format=json", [
          'headers' => [
            'User-Agent' => 'Labdoo Geocoding Fallback Bot',
          ],
          'timeout' => 5,
        ]);
        if ($response->getStatusCode() === 200) {
          $data = json_decode((string) $response->getBody(), TRUE);
          if (!empty($data['address'])) {
            $addr = $data['address'];
            $city = $addr['city'] ?? $addr['town'] ?? $addr['village'] ?? $addr['hamlet'] ?? $addr['municipality'] ?? $addr['suburb'] ?? $addr['county'] ?? $addr['state'] ?? '';
            $countryCode = !empty($addr['country_code']) ? strtoupper($addr['country_code']) : '';
            if (!empty($city) || !empty($countryCode)) {
              $result = [
                'city' => $city,
                'country_code' => $countryCode,
              ];
            }
          }
        }
      }
      catch (\Throwable $e) {
        // Ignore fallback errors.
      }
    }

    if ($result !== NULL) {
      self::$geoCache[$cacheKey] = $result;
    }

    return $result;
  }

  /**
   * Returns country name from a country code.
   */
  protected function getCountryName(string $countryCode): string {
    if (empty($countryCode)) {
      return 'unknown country';
    }
    $countryList = \Drupal::service('country_manager')->getList();
    return isset($countryList[strtoupper($countryCode)]) ? (string)$countryList[strtoupper($countryCode)] : $countryCode;
  }

  /**
   * Sets the city and country from a given geolocation.
   *
   * @param array $location
   *   The geolocation.
   * @param $city
   *   The city variable, passed by reference.
   * @param $country
   *   The country variable, passed by reference.
   *
   * @return void
   */
  protected function setGeoData(array $location, &$city, &$country): void {
    $city = $location['city'] ?? $location['locality'] ?? 'unknown city';
    $countryCode = $location['country_code'] ?? $location['country'] ?? '';
    $country = $this->getCountryName($countryCode);
  }

  /**
   * Checks if the action must be streamed.
   *
   * @param string $newCity
   *   The new city.
   * @param string $newTitle
   *   The new title.
   *
   * @return bool
   *   TRUE if must be streamed, otherwise FALSE.
   */
  protected function mustBeStreamed(string $newCity, string $newTitle): bool {
    $maxEntityId = $this->commonRepository->getMaxEntity('action');
    $globalAction = $this->commonRepository->loadEntity($maxEntityId);
    if ($globalAction === NULL) {
      return TRUE;
    }

    if (empty($globalAction->get('field_address')->getValue())) {
      return TRUE;
    }

    $oldCity = $globalAction->get('field_address')->getValue()[0]['locality'];
    $oldTitle = $globalAction->label();

    return $newCity !== $oldCity || $newTitle !== $oldTitle;
  }

  /**
   * Sets the global attributes.
   *
   * @param \Drupal\Core\Entity\EntityInterface $globalAction
   *   The global action.
   * @param string $actionType
   *   The action type.
   * @param string $title
   *   The title.
   * @param string $body
   *   The body.
   * @param int|null $edooVillageId
   *   The Edoovillage ID.
   * @param int|null $hubId
   *   The hub ID.
   * @param int $ownerId
   *   The owner ID.
   * @param $location
   *   The location.
   * @param string $city
   *   The city.
   * @param string $countryCode
   *   The country code.
   * @param int $created
   *   The creation date.
   * @param int $changed
   *   The update date.
   *
   * @return void
   */
  protected function setGlobalAttributes(
    EntityInterface $globalAction,
    string $actionType,
    string $title,
    string $body,
    ?int $edooVillageId,
    ?int $hubId,
    int $ownerId,
    $location,
    string $city,
    string $countryCode,
    int $created,
    int $changed
  ): void {
    $globalAction->set('field_action_type', $actionType);
    $globalAction->set('title', $title);
    $globalAction->set(
      'body',
      [
        'value' => $body,
        'format' => 'full_html',
      ]
    );
    $globalAction->set('field_edoovillage_action', $edooVillageId);
    $globalAction->set('field_hub_action', $hubId);
    $globalAction->set('field_labdooer_action', $ownerId);
    $globalAction->set('field_locations', $location);
    $globalAction->set('created', $created);
    $globalAction->set('changed', $changed);
    if ($this->mustBeStreamed($city, $title)) {
      $globalAction->set('field_stream_it', TRUE);
    }
    $globalAction->set('field_address', [
      'locality' => $city,
      'country_code' => $countryCode,
    ]);
  }

  /**
   * Creates the html body of a global action.
   *
   * @param int $nodeId
   *   Node id.
   * @param string $title
   *   Action title.
   * @param string $picture
   *   Picture filename.
   * @param int $width
   *   Picture width.
   *
   * @return string
   *   The html body.
   */
  protected function buildActionBody(
    int $nodeId,
    string $title,
    string $picture,
    int $width
  ): string {
    return sprintf(
      '<a href="/node/%d">%s...</a>&nbsp;<img src="/themes/custom/labdoo/img/%s" width="%d"></a>',
      $nodeId,
      $title,
      $picture,
      $width
    );
  }

}
