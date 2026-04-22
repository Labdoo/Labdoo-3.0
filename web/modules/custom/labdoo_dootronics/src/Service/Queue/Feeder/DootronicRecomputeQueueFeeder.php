<?php

namespace Drupal\labdoo_dootronics\Service\Queue\Feeder;

use Drupal\Core\Entity\EntityInterface;
use Drupal\queue_manager\Model\QueueDataModel;

/**
 * Service that feeds a queue with a dootronic to recompute heavy fields.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class DootronicRecomputeQueueFeeder extends AbstractQueueFeeder implements QueueFeederInterface {

  /**
   * Queue ID.
   */
  public const QUEUE_ID = 'labdoo_dootronics_recompute';

  /**
   * {@inheritDoc}
   */
  public function getQueueId(): ?string {
    return self::QUEUE_ID;
  }

  /**
   * {@inheritDoc}
   */
  public function feedQueue(EntityInterface $dootronic): void {
    $this->enqueueItem([(int) $dootronic->id()]);
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
