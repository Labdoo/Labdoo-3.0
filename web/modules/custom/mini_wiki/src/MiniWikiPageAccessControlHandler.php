<?php

declare(strict_types=1);

namespace Drupal\mini_wiki;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Defines the access control handler for the wiki page entity type.
 *
 * phpcs:disable Drupal.Arrays.Array.LongLineDeclaration
 *
 * @see https://www.drupal.org/project/coder/issues/3185082
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
final class MiniWikiPageAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResult {
    if ($account->hasPermission($this->entityType->getAdminPermission())) {
      return AccessResult::allowed()->cachePerPermissions();
    }

    return match($operation) {
      'view' => AccessResult::allowedIfHasPermission($account, 'view mini_wiki_page'),
      'update' => AccessResult::allowedIfHasPermission($account, 'edit mini_wiki_page'),
      'delete' => AccessResult::allowedIfHasPermission($account, 'delete mini_wiki_page'),
      'delete revision' => AccessResult::allowedIfHasPermission($account, 'delete mini_wiki_page revision'),
      'view all revisions', 'view revision' => AccessResult::allowedIfHasPermissions($account, ['view mini_wiki_page revision', 'view mini_wiki_page']),
      'revert' => AccessResult::allowedIfHasPermissions($account, ['revert mini_wiki_page revision', 'edit mini_wiki_page']),
      default => AccessResult::neutral(),
    };
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL): AccessResult {
    return AccessResult::allowedIfHasPermissions($account, ['create mini_wiki_page', 'administer mini_wiki_page'], 'OR');
  }

}
