<?php

namespace Drupal\mini_wiki\Service\Queue\Feeder;

use Drupal\Core\Entity\EntityInterface;

/**
 * Queue feeder that enqueues the mini wiki recompute.
 */
class MiniWikiRecomputeQueueFeeder extends AbstractQueueFeeder {

  /**
   * The queue ID.
   *
   * @var string
   */
  public const QUEUE_ID = 'mini_wiki_recompute';

  /**
   * {@inheritDoc}
   */
  public function getQueueId(): ?string {
    return self::QUEUE_ID;
  }

  /**
   * {@inheritDoc}
   */
  public function feedQueue(EntityInterface $entity): void {
    $this->enqueueItem([
      'id' => (int) $entity->id(),
      'uid' => (int) $entity->getOwnerId(),
    ]);
  }

}
