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
    $addressCollection = $this->geocoder->reverse(
      $lat,
      $lon,
      ['plugin' => 'googlemaps']
    );
    if ($addressCollection === NULL ||  !$addressCollection->get(0)) {
      return NULL;
    }

    return [
      'city' => $addressCollection->get(0)->getLocality(),
      'country' => $addressCollection->get(0)->getCountry(),
    ];
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
    $lon = $location['lon'] ?? $location['lng'];
    $geoData = NULL;

    if ($location['lat'] !== NULL && $lon !== NULL) {
      $geoData = $this->reverseGeocode($location['lat'], $lon);
    }

    if ($geoData === NULL || $geoData['country'] === NULL) {
      $city = 'unknown city';
      $country = 'unknown country';
    }
    else {
      $city = $geoData['city'];
      $country = $geoData['country']->getName();
    }
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
    int $created,
    int $changed
  ): void {
    $globalAction->set('field_action_type', $actionType);
    $globalAction->set('title', $title);
    $globalAction->set(
      'body',
      [
        'value' => $body,
        'format' => 'basic_html',
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
  }

}
