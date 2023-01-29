<?php

namespace Drupal\labdoo_notifications\Service\EntityProcessor;

use Drupal\Core\Entity\EntityInterface;
use Drupal\labdoo_notifications\Service\NotificationManager;
use Drupal\user\UserInterface;

/**
 * Processor for user entities.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class UserProcessor implements EntityProcessorInterface {

  /**
   * The notification manager service.
   *
   * @var \Drupal\labdoo_notifications\Service\NotificationManager
   */
  protected NotificationManager $notificationManager;

  /**
   * UserProcessor constructor.
   *
   * @param \Drupal\labdoo_notifications\Service\NotificationManager $notificationManager
   *   The notification manager service.
   */
  public function __construct(NotificationManager $notificationManager) {
    $this->notificationManager = $notificationManager;
  }

  /**
   * {@inheritdoc}
   */
  public function process(EntityInterface $entity, string $operation, $comment = null): void {
    if ($entity->getEntityTypeId() !== 'user') {
      return;
    }

    // For user entities, we need the edit array and category from the hook.
    // Since these are not available in this context, we'll just pass empty values.
    // The actual implementation will be in the hook_user_insert() function.
    if ($operation === 'insert' && $entity instanceof UserInterface) {
      $this->notificationManager->sendUserCreatedEmail([], $entity, 'insert');
    }
  }

}
