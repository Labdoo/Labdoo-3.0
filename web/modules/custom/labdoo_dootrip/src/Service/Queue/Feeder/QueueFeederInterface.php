<?php

namespace Drupal\labdoo_dootrip\Service\Queue\Feeder;

use Drupal\node\NodeInterface;

/**
 * Interface for queue feeders.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
interface QueueFeederInterface {

  /**
   * Retrieves the queue ID.
   *
   * @return string
   *   The queue ID.
   */
  public function getQueueId(): string;

  /**
   * Feeds the queue.
   *
   * @param \Drupal\node\NodeInterface $dootrip
   *   The dootrip.
   */
  public function feedQueue(NodeInterface $dootrip): void;

}
