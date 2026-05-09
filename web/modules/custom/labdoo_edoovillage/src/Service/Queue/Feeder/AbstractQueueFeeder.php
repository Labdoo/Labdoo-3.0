<?php

namespace Drupal\labdoo_edoovillage\Service\Queue\Feeder;

use Drupal\Core\Queue\QueueFactory;

/**
 * Abstract class for the Queue feeder.
 */
abstract class AbstractQueueFeeder implements QueueFeederInterface {

  /**
   * The queue factory.
   *
   * @var \Drupal\Core\Queue\QueueFactory
   */
  protected QueueFactory $queueFactory;

  /**
   * AbstractQueueFeeder constructor.
   *
   * @param \Drupal\Core\Queue\QueueFactory $queueFactory
   *   The queue factory.
   */
  public function __construct(QueueFactory $queueFactory) {
    $this->queueFactory = $queueFactory;
  }

  /**
   * Enqueues an item.
   *
   * @param array|int $data
   *   The data.
   */
  protected function enqueueItem($data): void {
    $queue = $this->queueFactory->get($this->getQueueId());
    $queue->createItem(['data' => serialize($data)]);
  }

}
