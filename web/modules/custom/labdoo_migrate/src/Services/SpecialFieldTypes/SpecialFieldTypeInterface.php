<?php

namespace Drupal\labdoo_migrate\Services\SpecialFieldTypes;

use Drupal\Core\Entity\EntityInterface;

/**
 * The interface for special type fields.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
interface SpecialFieldTypeInterface {

  /**
   * Retrieves the value of the special type field.
   *
   * @param mixed $value
   *   The value.
   * @param array $metadata
   *   The metadata array.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   * @param string $mainLangCode
   *   The main language code.
   *
   * @return mixed
   *   Returns the value of the special type field.
   *
   * @throws \Exception
   */
  public function getValue(
    $value,
    array $metadata,
    EntityInterface $entity,
    string $mainLangCode
  );

  /**
   * Filters the value.
   *
   * @param mixed $value
   *   The value.
   *
   * @return mixed
   *   Returns the filtered value.
   */
  public function filterValue($value);

}
