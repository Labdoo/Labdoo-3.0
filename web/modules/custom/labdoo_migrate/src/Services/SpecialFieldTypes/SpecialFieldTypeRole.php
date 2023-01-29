<?php

namespace Drupal\labdoo_migrate\Services\SpecialFieldTypes;

use Drupal\Core\Entity\EntityInterface;

/**
 * The special field type for role fields.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class SpecialFieldTypeRole implements SpecialFieldTypeInterface {

  /**
   * {@inheritDoc}
   */
  public function getValue(
    $value,
    array $metadata,
    EntityInterface $entity,
    string $mainLangCode
  ) {

    $rolesMapping = [
      2 => 'authenticated',
      3 => 'administrator',
      4 => 'wiki_writer',
      5 => 'edoovillage_manager',
      6 => 'hub_manager',
      7 => 'newsletter_manager',
      8 => 'superhub_manager',
      9 => 'team_manager',
    ];
    $roles = [];

    if (!is_array($value)) {
      $value = [$value];
    }
    foreach ($value as $role) {
      if (isset($rolesMapping[$role])) {
        $roles[] = $rolesMapping[$role];
      }
    }

    return $roles;
  }

  /**
   * {@inheritDoc}
   */
  public function filterValue($value) {

    return $value;
  }

}
