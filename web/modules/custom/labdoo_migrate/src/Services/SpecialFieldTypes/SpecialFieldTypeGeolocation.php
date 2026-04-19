<?php

namespace Drupal\labdoo_migrate\Services\SpecialFieldTypes;

use Drupal\Core\Entity\EntityInterface;
use Drupal\geofield\WktGeneratorInterface;

/**
 * The special field type for geolocation fields.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class SpecialFieldTypeGeolocation implements SpecialFieldTypeInterface {

  /**
   * The WKTGenerator.
   *
   * @var \Drupal\geofield\WktGeneratorInterface
   */
  protected WktGeneratorInterface $wktGenerator;

  /**
   * SpecialFieldTypeGeolocation constructor.
   *
   * @param \Drupal\geofield\WktGeneratorInterface $wktGenerator
   *   The WKTGenerator.
   */
  public function __construct(WktGeneratorInterface $wktGenerator) {
    $this->wktGenerator = $wktGenerator;
  }

  /**
   * {@inheritDoc}
   */
  public function getValue(
    $value,
    array $metadata,
    EntityInterface $entity,
    string $mainLangCode
  ) {

    if ($value === NULL || $value === '') {
      return NULL;
    }

    if (!is_array($value)) {
      return $this->processSingleValue($value);
    }

    $result = [];
    foreach ($value as $singleValue) {
      $processedValue = $this->processSingleValue($singleValue);
      if ($processedValue !== NULL) {
        $result[] = $processedValue;
      }
    }

    return $result ?: NULL;
  }

  /**
   * Processes a single value.
   *
   * @param mixed $value
   *   The field value.
   *
   * @return string|null
   *   Returns the processed value as an array.
   */
  protected function processSingleValue($value): ?string {
    if (!is_string($value) || trim($value) === '') {
      return NULL;
    }

    $coords = array_map('trim', explode(',', $value));
    if (count($coords) !== 2 || !is_numeric($coords[0]) || !is_numeric($coords[1])) {
      return NULL;
    }

    $latitude = (float) $coords[0];
    $longitude = (float) $coords[1];
    if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
      return NULL;
    }

    $point = [
      $longitude,
      $latitude,
    ];

    return $this->wktGenerator->WktBuildPoint($point);
  }

  /**
   * {@inheritDoc}
   */
  public function filterValue($value) {

    return $value;
  }

}
