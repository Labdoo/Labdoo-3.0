<?php

namespace Drupal\labdoo_hub\Service\Queue\Feeder;

use Drupal\queue_manager\Service\QueueHelper;

/**
 * Abstract class for the Queue feeder.
 */
abstract class AbstractQueueFeeder implements QueueFeederInterface {

  /**
   * The queue helper.
   *
   * @var \Drupal\queue_manager\Service\QueueHelper
   */
  protected QueueHelper $queueHelper;

  /**
   * AbstractQueueFeeder constructor.
   *
   * @param \Drupal\queue_manager\Service\QueueHelper $queueHelper
   *   The queue helper.
   */
  public function __construct(QueueHelper $queueHelper) {
    $this->queueHelper = $queueHelper;
  }

}
