<?php

namespace Drupal\labdoo_common\Service\Repository;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;

/**
 * Provides methods to retrieve user data.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class UserRepository {

  /**
   * UserRepository constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected AccountProxyInterface $accountProxy
  ) {}

  /**
   * Get the current user entity.
   *
   * @return \Drupal\Core\Entity\EntityInterface|null
   *   The current user entity, or null if not found.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function getCurrentUser(): ?EntityInterface {
    return $this->entityTypeManager
      ->getStorage('user')
      ->load($this->accountProxy->id());
  }

}
