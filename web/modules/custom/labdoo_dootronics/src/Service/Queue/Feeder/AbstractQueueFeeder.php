<?php

namespace Drupal\labdoo_dootronics\Service\Queue\Feeder;

use Drupal\queue_manager\Service\QueueHelper;

/**
 * Abstract class for queue feeders.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class AbstractQueueFeeder {

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
  public function __construct(
    QueueHelper $queueHelper
  ) {
    $this->queueHelper = $queueHelper;
  }

}
