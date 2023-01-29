<?php

namespace Drupal\labdoo_dootrip\Service\Queue\Feeder;

use Drupal\queue_manager\Model\QueueDataModel;
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

  /**
   * Enqueues an item.
   *
   * @param array $item
   *   The item.
   */
  protected function enqueueItem(array $item): void {
    try {
      $this->deleteExistingItems();
    }
    catch (\Exception $e) {
      // We can safely ignore this exception.
      // The worst problem is that we are processing the queue one more time.
    }

    $queueData = new QueueDataModel();
    $queueData->setQueueId($this->getQueueId());
    $queueData->setTimestamp(new \DateTime());
    $queueData->setData($item);

    $this->queueHelper->enqueueData(
      $this->getQueueId(),
      $queueData->__serialize()
    );
  }

  /**
   * Deletes the existing items in the queue because it is enough with the
   * latest item.
   *
   * @throws \Exception
   */
  protected function deleteExistingItems(): void {
    $matchingItems = $this->queueHelper->searchItems(
      $this->getQueueId(),
      []
    );
    if ($matchingItems) {
      foreach ($matchingItems as $item) {
        $this->queueHelper->deleteItem($this->getQueueId(), $item->item_id);
      }
    }
  }

}
