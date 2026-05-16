<?php

namespace Drupal\labdoo_hub\Service\Compute;

use Drupal\Core\Entity\EntityInterface;
use Drupal\labdoo_hub\Service\Queue\Feeder\QueueFeederInterface;

/**
 * Service to compute Hub data.
 */
class HubCompute implements HubComputeInterface {

  /**
   * The recompute feeder.
   *
   * @var \Drupal\labdoo_hub\Service\Queue\Feeder\QueueFeederInterface
   */
  protected QueueFeederInterface $recomputeFeeder;

  /**
   * HubCompute constructor.
   *
   * @param \Drupal\labdoo_hub\Service\Queue\Feeder\QueueFeederInterface $recomputeFeeder
   *   The recompute feeder.
   */
  public function __construct(QueueFeederInterface $recomputeFeeder) {
    $this->recomputeFeeder = $recomputeFeeder;
  }

  /**
   * {@inheritdoc}
   */
  public function enqueueRecompute(EntityInterface $entity): void {
    if ($entity->bundle() !== 'hub') {
      return;
    }

    $this->recomputeFeeder->feedQueue($entity);
  }

  /**
   * {@inheritdoc}
   */
  public function enqueueGeocoding(EntityInterface $entity): void {
    if ($entity->bundle() !== 'hub') {
      return;
    }

    if (!empty($entity->skip_geocoding_enqueue)) {
      return;
    }

    /** @var \Drupal\Core\Queue\QueueFactory $queueFactory */
    $queueFactory = \Drupal::service('queue');
    $queue = $queueFactory->get('labdoo_hub_geocoding');
    $item = [
      'nid' => $entity->id(),
    ];
    $queue->createItem($item);
  }

}
