<?php

namespace Drupal\labdoo_migrate\Services\SpecialFieldTypes;

use Drupal\Core\Entity\EntityInterface;
use Drupal\user\UserInterface;

/**
 * The special field type for password fields.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class SpecialFieldTypePassword implements SpecialFieldTypeInterface {

  /**
   * {@inheritDoc}
   */
  public function getValue(
    $value,
    array $metadata,
    EntityInterface $entity,
    string $mainLangCode
  ) {

    if ($entity instanceof UserInterface && $value) {
      $entity->setExistingPassword($value);
      $entity->pass->skip_rehash = TRUE;
    }

    // We return NULL because the password has already been set via
    // setExistingPassword() and we don't want setFieldValue() to
    // try to set it again using the standard field API, which would
    // trigger the re-hashing.
    return NULL;
  }

  /**
   * {@inheritDoc}
   */
  public function filterValue($value) {

    return $value;
  }

}
