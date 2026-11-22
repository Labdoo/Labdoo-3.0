<?php

namespace Drupal\labdoo_hub\Service\Queue\Feeder;

use Drupal\node\NodeInterface;
use Drupal\queue_manager\Model\QueueDataModel;

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
  public function feedQueue(NodeInterface $hub): void {
    $this->enqueueItem([
      'id' => (int) $hub->id(),
      'uid' => (int) $hub->getOwnerId(),
    ]);
  }

  /**
   * Enqueues an item.
   *
   * @param array $item
   *   The item.
   */
  protected function enqueueItem(array $item): void {
    $queueData = new QueueDataModel();
    $queueData->setQueueId($this->getQueueId());
    $queueData->setTimestamp(new \DateTime());
    $queueData->setData($item);

    $this->queueHelper->enqueueData(
      $this->getQueueId(),
      $queueData->__serialize()
    );
  }

}
