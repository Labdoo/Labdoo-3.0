<?php

namespace Drupal\labdoo_privileges\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeAccessControlHandler;

/**
 * Defines the access control handler for nodes.
 *
 * @ingroup node_access
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class LabdooNodeAccessControlHandler extends NodeAccessControlHandler {

  /**
   * An associative array that defines bundles and their respective fields.
   * Each key represents a bundle with an array of associated fields.
   */
  private const BUNDLES = [
    'dootrip' => [
      'field_edoo_additional_editors',
    ],
    'dootronic' => [
      'field_edoo_additional_editors',
      'field_manager',
    ],
    'edoovillage' => [
      'field_edoo_additional_editors',
      'field_project_manager_s_',
    ],
    'hub' => [
      'field_hub_additional_editors',
      'field_hub_manager_s_',
    ],
  ];

  /**
   * {@inheritDoc}
   */
  public function access(
    EntityInterface $entity,
    $operation,
    ?AccountInterface $account = NULL,
    $return_as_object = FALSE
  ) {
    $result = parent::access($entity, $operation, $account, TRUE);
    if (!$result->isNeutral()) {
      return $result;
    }

    if ($account === NULL) {
      return AccessResult::neutral();
    }

    return $this->checkAccess($entity, $operation, $account);
  }

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $node, $operation, AccountInterface $account) {
    if (!$this->isValidBundle($node)) {
      return parent::checkAccess($node, $operation, $account);
    }

    $hasAccess = $this->userHasAccess($node, $account);

    switch ($operation) {
      case 'view':
        return AccessResult::allowed();

      case 'update':
      case 'clone':
        // Allow editing of own nodes for owners or additional editors.
        // Allow cloning too.
        if ($hasAccess) {
          return AccessResult::allowed();
        }
        // Allow editing of any nodes for users with 'edit any content' permission.
        if ($account->hasPermission('edit any content')) {
          return AccessResult::allowed();
        }
        break;

      case 'delete':
        // Allow deletion of own nodes for owners or additional editors.
        if ($hasAccess && !$this->isContributor($node, $account)) {
          return AccessResult::allowed();
        }
        // Allow deletion of any nodes for users with 'delete any content' permission.
        if ($account->hasPermission('delete any content')) {
          return AccessResult::allowed();
        }
        break;
    }

    return parent::checkAccess($node, $operation, $account);
  }

  /**
   * Validates whether the entity belongs to a valid bundle.
   *
   * @param \Drupal\Core\Entity\EntityInterface $node
   *   The entity to be validated.
   *
   * @return bool
   *   TRUE if the entity belongs to one of the valid bundles, FALSE otherwise.
   */
  protected function isValidBundle(EntityInterface $node): bool {
    return array_search($node->bundle(), array_keys(self::BUNDLES));
  }

  /**
   * Determines if the user has access to the given node.
   *
   * @param EntityInterface $node
   *   The content entity node being checked.
   * @param AccountInterface $account
   *   The user account for which access is being checked.
   *
   * @return bool
   *   TRUE if the user is authenticated and satisfies one of the following
   *   conditions: is an administrator, is the owner of the node, or is marked
   *   as a contributor to the node; otherwise FALSE.
   */
  protected function userHasAccess(
    EntityInterface $node,
    AccountInterface $account
  ): bool {
    return $account->isAuthenticated()
      &&
      (
        $this->isAdmin($account)
        || $this->isOwner($node, $account)
        || $this->isContributor($node, $account)
      );
  }

  /**
   * Determines if the given account is the owner of the entity.
   *
   * @param EntityInterface $node
   *   The entity to check ownership for.
   * @param AccountInterface $account
   *   The user account to validate ownership against.
   *
   * @return bool
   *   TRUE if the account is the owner of the entity, FALSE otherwise.
   */
  protected function isOwner(
    EntityInterface $node,
    AccountInterface $account
  ): bool {
    return $node->getOwnerId() === $account->id();
  }

  /**
   * Determines if the given account is a contributor to the specified entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $node
   *   The entity to check for contributor access.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user account to verify as a contributor.
   *
   * @return bool
   *   TRUE if the account is a contributor, FALSE otherwise.
   */
  protected function isContributor(
    EntityInterface $node,
    AccountInterface $account
  ): bool {
    $additionalEditors = $this->getAdditionalEditors($node);

    return in_array($account->id(), $additionalEditors);
  }

  /**
   * Determines if the given account has the administrator role.
   *
   * @param AccountInterface $account
   *   The user account to check.
   *
   * @return bool
   *   TRUE if the account has the "administrator" role, FALSE otherwise.
   */
  protected function isAdmin(AccountInterface $account): bool {
    $roles = $account->getRoles();

    return in_array('administrator', $roles);
  }

  /**
   * Retrieves the additional editors associated with the given entity.
   *
   * @param EntityInterface $node
   *   The entity for which to retrieve the additional editors.
   *
   * @return array
   *   A list of editor IDs associated with the entity.
   */
  protected function getAdditionalEditors(EntityInterface $node): array {
    $editorIds = [];
    $fields = self::BUNDLES[$node->bundle()];

    foreach ($fields as $field) {
      if (
        !$node->hasField($field)
        || !$node->get($field)
      ) {
        continue;
      }

      foreach ($node->get($field)->referencedEntities() as $userEntity) {
        $editorIds[] = $userEntity->id();
      }
    }

    return $editorIds;
  }

}
