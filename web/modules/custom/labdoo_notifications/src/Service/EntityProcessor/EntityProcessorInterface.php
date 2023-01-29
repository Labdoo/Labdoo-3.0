<?php

namespace Drupal\labdoo_notifications\Service\EntityProcessor;

use Drupal\Core\Entity\EntityInterface;

/**
 * Interface for entity processors.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
interface EntityProcessorInterface {

  /**
   * Processes an entity for notifications.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to process.
   * @param string $operation
   *   The operation being performed (insert, update, etc.).
   * @param mixed $comment
   *   Optional comment if the notification is for a comment.
   */
  public function process(
    EntityInterface $entity,
    string $operation,
    $comment = null
  ): void;
}
