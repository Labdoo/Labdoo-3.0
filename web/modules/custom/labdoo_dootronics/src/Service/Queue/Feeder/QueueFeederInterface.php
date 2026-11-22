<?php

namespace Drupal\labdoo_dootronics\Service\Queue\Feeder;

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
   * @return null|string
   *   The queue ID.
   */
  public function getQueueId(): ?string;

  /**
   * Feeds the queue.
   *
   * @param \Drupal\node\NodeInterface $dootronic
   *   The dootronic.
   */
  public function feedQueue(NodeInterface $dootronic): void;

}
