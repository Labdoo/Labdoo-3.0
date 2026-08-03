<?php

namespace Drupal\labdoo_notifications\Service\EntityProcessor;

use Drupal\Core\Entity\EntityInterface;
use Drupal\labdoo_notifications\Service\NotificationManager;

/**
 * Processor for team-related entities.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class TeamProcessor implements EntityProcessorInterface {

  /**
   * The notification manager service.
   *
   * @var \Drupal\labdoo_notifications\Service\NotificationManager
   */
  protected NotificationManager $notificationManager;

  /**
   * TeamProcessor constructor.
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
    // Check if this is a team-related entity.
    if ($entity->getEntityTypeId() !== 'node') {
      return;
    }

    $bundle = $entity->bundle();
    if (!in_array($bundle, ['event', 'team_task', 'team_post'])) {
      return;
    }

    // Send team event notification.
    $this->notificationManager->sendTeamEventEmail($entity, $comment);
  }

}
