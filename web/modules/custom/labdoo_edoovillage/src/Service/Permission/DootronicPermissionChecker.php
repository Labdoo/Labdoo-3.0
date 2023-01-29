<?php

namespace Drupal\labdoo_edoovillage\Service\Permission;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Service for checking permissions related to Dootronics.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class DootronicPermissionChecker {

  /**
   * The roles that have permission to change dootronic status.
   *
   * @var array
   */
  protected const ALLOWED_ROLES = [
    'administrator',
    'edoovillage_manager',
    'hub_manager',
  ];

  /**
   * Checks if a user has permission to change the dootronic status.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to check permissions for.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user account to check.
   *
   * @return bool
   *   TRUE if the user has permission, FALSE otherwise.
   */
  public function canChangeDootronicStatus(EntityInterface $entity, AccountInterface $account): bool {
    // Check if user has one of the allowed roles
    foreach (self::ALLOWED_ROLES as $role) {
      if ($account->hasRole($role)) {
        return TRUE;
      }
    }

    // Check if user is in the additional editors field
    if ($entity->hasField('field_edoo_additional_editors') && !$entity->get('field_edoo_additional_editors')->isEmpty()) {
      foreach ($entity->get('field_edoo_additional_editors')->referencedEntities() as $userEntity) {
        if ($userEntity->id() == $account->id()) {
          return TRUE;
        }
      }
    }

    return FALSE;
  }

}