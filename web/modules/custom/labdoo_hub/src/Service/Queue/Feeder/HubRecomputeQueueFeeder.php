<?php

namespace Drupal\labdoo_hub\Service\Queue\Feeder;

use Drupal\Core\Entity\EntityInterface;

/**
 * Queue feeder that enqueues the hub recompute.
 */
class HubRecomputeQueueFeeder extends AbstractQueueFeeder {

  /**
   * The queue ID.
   *
   * @var string
   */
  public const QUEUE_ID = 'labdoo_hub_recompute';

  /**
   * {@inheritDoc}
   */
  public function getQueueId(): ?string {
    return self::QUEUE_ID;
  }

  /**
   * {@inheritDoc}
   */
  public function feedQueue(EntityInterface $hub): void {
    $this->enqueueItem([
      'id' => (int) $hub->id(),
      'uid' => (int) $hub->getOwnerId(),
    ]);
  }

}
