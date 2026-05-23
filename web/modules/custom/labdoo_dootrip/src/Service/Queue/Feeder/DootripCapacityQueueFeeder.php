<?php

namespace Drupal\labdoo_dootrip\Service\Queue\Feeder;

use Drupal\Core\Entity\EntityInterface;
use Drupal\queue_manager\Model\QueueDataModel;

/**
 * Service that feeds a queue with a dootrip to compute its capacity.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class DootripCapacityQueueFeeder extends AbstractQueueFeeder implements QueueFeederInterface {

  /**
   * Queue ID.
   */
  public const QUEUE_ID = 'labdoo_dootrip_calculate_capacity';

  /**
   * {@inheritDoc}
   */
  public function getQueueId(): ?string {
    return self::QUEUE_ID;
  }

  /**
   * {@inheritDoc}
   */
  public function feedQueue(EntityInterface $dootrip): void {
    $item = [
      'id' => (int) $dootrip->id(),
      'uid' => (int) $dootrip->getOwnerId(),
    ];

    if (isset($dootrip->original)) {
      $originalDootronicIds = [];
      foreach ($dootrip->original->get('field_laptops') as $fieldItem) {
        if ($fieldItem->target_id) {
          $originalDootronicIds[] = (int) $fieldItem->target_id;
        }
      }
      $item['original_dootronic_ids'] = $originalDootronicIds;
    }

    $this->enqueueItem($item);
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
