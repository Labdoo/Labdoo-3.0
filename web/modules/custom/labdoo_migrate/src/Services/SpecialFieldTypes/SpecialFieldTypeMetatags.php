<?php

namespace Drupal\labdoo_migrate\Services\SpecialFieldTypes;

use Drupal\Core\Entity\EntityInterface;

/**
 * The special field type for metatags fields.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class SpecialFieldTypeMetatags implements SpecialFieldTypeInterface {

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
      return serialize($this->processSingleValue($value));
    }

    $result = [];
    foreach ($value as $singleValue) {
      $result += $this->processSingleValue($singleValue);
    }

    return serialize($result);
  }

  /**
   * Processes a single value.
   *
   * @param string $source
   *   The source value.
   *
   * @return array|null
   *   Returns the processed value as an array.
   */
  protected function processSingleValue(string $source): ?array {

    $source = unserialize($source, ['array']);
    if (!$source) {
      return NULL;
    }

    $result = [];
    foreach ($source as $key => $value) {
      if (strpos(strtolower($key), 'og:') !== FALSE) {
        $key = str_ireplace('og:', 'og_', $key);
      }
      $result[$key] = is_array($value) ? reset($value) : $value;
    }

    return $result;
  }

  /**
   * {@inheritDoc}
   */
  public function filterValue($value) {

    return $value;
  }

}
