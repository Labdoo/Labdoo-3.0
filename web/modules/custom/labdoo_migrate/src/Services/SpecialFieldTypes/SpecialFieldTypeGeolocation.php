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

    if (!is_array($value)) {
      return $this->processSingleValue($value);
    }

    $result = [];
    foreach ($value as $singleValue) {
      $result[] = $this->processSingleValue($singleValue);
    }

    return $result;
  }

  /**
   * Processes a single value.
   *
   * @param string $value
   *   The field value.
   *
   * @return string
   *   Returns the processed value as an array.
   */
  protected function processSingleValue(string $value): string {
    $coords = explode(',', $value);
    $point = [
      $coords[1],
      $coords[0],
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
