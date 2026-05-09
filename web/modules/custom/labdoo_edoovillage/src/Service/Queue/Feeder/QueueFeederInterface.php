<?php

namespace Drupal\labdoo_edoovillage\Service\Queue\Feeder;

use Drupal\Core\Entity\EntityInterface;

/**
 * Interface for the Queue feeder.
 */
interface QueueFeederInterface {

  /**
   * Feeds the queue.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   */
  public function feedQueue(EntityInterface $entity): void;

  /**
   * Returns the queue ID.
   *
   * @return string|null
   *   The queue ID.
   */
  public function getQueueId(): ?string;

}
