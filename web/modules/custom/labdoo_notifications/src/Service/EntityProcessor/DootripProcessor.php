<?php

namespace Drupal\labdoo_notifications\Service\EntityProcessor;

use Drupal\Core\Entity\EntityInterface;
use Drupal\labdoo_notifications\Service\NotificationManager;

/**
 * Processor for dootrip entities.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class DootripProcessor implements EntityProcessorInterface {

  /**
   * The notification manager service.
   *
   * @var \Drupal\labdoo_notifications\Service\NotificationManager
   */
  protected NotificationManager $notificationManager;

  /**
   * DootripProcessor constructor.
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
    if ($entity->getEntityTypeId() !== 'node' || $entity->bundle() !== 'dootrip') {
      return;
    }

    // Send dootrip event notification based on the operation.
    $this->notificationManager->sendDootripEventEmail($entity, $operation);
  }

}
