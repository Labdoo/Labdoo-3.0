<?php

namespace Drupal\labdoo_edoovillage\Service\Queue\Feeder;

use Drupal\Core\Entity\EntityInterface;

/**
 * Queue feeder that enqueues the edoovillage recompute.
 */
class EdooVillageRecomputeQueueFeeder extends AbstractQueueFeeder {

  /**
   * The queue ID.
   *
   * @var string
   */
  public const QUEUE_ID = 'labdoo_edoovillage_recompute';

  /**
   * {@inheritDoc}
   */
  public function getQueueId(): ?string {
    return self::QUEUE_ID;
  }

  /**
   * {@inheritDoc}
   */
  public function feedQueue(EntityInterface $edoovillage): void {
    $this->enqueueItem([
      'id' => (int) $edoovillage->id(),
      'uid' => (int) $edoovillage->getOwnerId(),
    ]);
  }

}
