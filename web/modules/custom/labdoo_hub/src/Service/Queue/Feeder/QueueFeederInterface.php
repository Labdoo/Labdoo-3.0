<?php

namespace Drupal\labdoo_hub\Service\Queue\Feeder;

use Drupal\node\NodeInterface;

/**
 * Interface for the Queue feeder.
 */
interface QueueFeederInterface {

  /**
   * Feeds the queue.
   *
   * @param \Drupal\node\NodeInterface $entity
   *   The entity.
   */
  public function feedQueue(NodeInterface $entity): void;

  /**
   * Returns the queue ID.
   *
   * @return string|null
   *   The queue ID.
   */
  public function getQueueId(): ?string;

}
