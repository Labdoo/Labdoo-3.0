<?php

namespace Drupal\labdoo_migrate\Services\SpecialFieldTypes;

/**
 * The special field type factory.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class SpecialFieldTypeFactory {

  /**
   * Retrieves the special type instance.
   *
   * @param string $fieldType
   *   The field type.
   *
   * @return \Drupal\labdoo_migrate\Services\SpecialFieldTypes\SpecialFieldTypeInterface
   *   Returns the special type instance.
   *
   * @throws \Exception
   */
  public static function get(string $fieldType): SpecialFieldTypeInterface {

    $serviceId = sprintf('labdoo_migrate.special_field_types.%s', $fieldType);
    /** @var \Drupal\labdoo_migrate\Services\SpecialFieldTypes\SpecialFieldTypeInterface $serviceInstance */
    $serviceInstance = \Drupal::getContainer()->get($serviceId);
    if (!$serviceInstance) {
      $errorMessage = sprintf('Could not instantiate service %s', $serviceId);
      throw new \Exception($errorMessage);
    }

    return $serviceInstance;
  }

  /**
   * {@inheritDoc}
   */
  public function filterValue($value) {

    return $value;
  }

}
