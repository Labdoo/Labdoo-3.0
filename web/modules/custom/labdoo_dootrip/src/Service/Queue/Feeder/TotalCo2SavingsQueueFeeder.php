<?php

namespace Drupal\labdoo_dootrip\Service\Queue\Feeder;

use Drupal\Core\Entity\EntityInterface;

/**
 * Service that feeds a queue with a dootrip to compute the total CO2 savings.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class TotalCo2SavingsQueueFeeder extends AbstractQueueFeeder implements QueueFeederInterface {

  /**
   * Queue ID.
   */
  public const QUEUE_ID = 'labdoo_dootrip_calculate_total_co2_savings';

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
    $this->enqueueItem([$dootrip->id()]);
  }

  /**
   * Recomputes the total CO2 savings.
   */
  public function recompute(): void {
    $this->enqueueItem([]);
  }

}
