<?php

namespace Drupal\queue_manager\Exception;

/**
 * Class EmptyQueueItemException.
 *
 * Thrown when a queue item is empty.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class EmptyQueueItemException extends \Exception {

  /**
   * EmptyQueueItemException constructor.
   *
   * @param string $queueName
   *   The queue name.
   */
  public function __construct(string $queueName) {
    $errorMessage = sprintf(
      'Empty queue item: %s',
      $queueName
    );
    parent::__construct($errorMessage);
  }

}
