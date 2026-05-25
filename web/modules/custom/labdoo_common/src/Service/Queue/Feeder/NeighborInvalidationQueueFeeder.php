<?php

namespace Drupal\labdoo_common\Service\Queue\Feeder;

use Drupal\queue_manager\Model\QueueDataModel;
use Drupal\queue_manager\Service\QueueHelper;

/**
 * Service that feeds a queue for neighbor cache invalidation.
 */
class NeighborInvalidationQueueFeeder implements NeighborInvalidationQueueFeederInterface {

  /**
   * Queue ID.
   */
  public const QUEUE_ID = 'labdoo_common_neighbor_invalidation';

  /**
   * The queue helper.
   *
   * @var \Drupal\queue_manager\Service\QueueHelper
   */
  protected QueueHelper $queueHelper;

  /**
   * Constructor.
   *
   * @param \Drupal\queue_manager\Service\QueueHelper $queueHelper
   *   The queue helper.
   */
  public function __construct(QueueHelper $queueHelper) {
    $this->queueHelper = $queueHelper;
  }

  /**
   * {@inheritdoc}
   */
  public function feedQueue(string $entityType, ?int $entityId = NULL, ?string $label = NULL): void {
    $item = [
      'entity_type' => $entityType,
      'entity_id' => $entityId,
      'label' => $label,
    ];

    $queueData = new QueueDataModel();
    $queueData->setQueueId(self::QUEUE_ID);
    $queueData->setTimestamp(new \DateTime());
    $queueData->setData($item);

    $this->queueHelper->enqueueData(
      self::QUEUE_ID,
      $queueData->__serialize()
    );
  }

}
