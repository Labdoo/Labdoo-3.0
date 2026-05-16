<?php

namespace Drupal\queue_manager\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueInterface;

/**
 * Queue Helper.
 *
 * @package Drupal\queue_manager\Services
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class QueueHelper {

  /**
   * The Queue Worker Factory.
   *
   * @var \Drupal\Core\Queue\QueueFactory
   */
  protected QueueFactory $queueFactory;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  private Connection $database;

  /**
   * QueueHelper constructor.
   *
   * @param \Drupal\Core\Queue\QueueFactory $queueFactory
   *   The Queue Factory.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   */
  public function __construct(
    QueueFactory $queueFactory,
    Connection $database
  ) {
    $this->queueFactory = $queueFactory;
    $this->database = $database;
  }

  /**
   * Enqueues data to be processed by the Queue Manager.
   *
   * @param string $queueId
   *   Queue ID.
   * @param array $data
   *   Data coming from the queue.
   *
   * @return \Drupal\Core\Queue\QueueInterface|null
   *   Returns the queue object if the item is enqueued, otherwise FALSE.
   */
  public function enqueueData(string $queueId, array $data): ?QueueInterface {
    // Basic deduplication: Check if an item with the same data already exists.
    // This is useful for recompute queues where we only care about the latest ID.
    $serializedData = serialize($data);
    $exists = $this->database->select('queue', 'q')
      ->fields('q', ['item_id'])
      ->condition('name', $queueId)
      ->condition('data', $serializedData)
      ->range(0, 1)
      ->execute()
      ->fetchField();

    if ($exists) {
      return $this->queueFactory->get($queueId);
    }

    $queue = $this->queueFactory->get($queueId);
    $queue->createQueue();
    $queue->createItem($data);

    return $queue;
  }

  /**
   * Searches items in the queue.
   *
   * Note: This method is not the best possible approach since it is coupled
   * to a specific implementation (database), while a queue may rely on Redis
   * or other implementations.
   *
   * @param string $queueId
   *   The queue ID.
   * @param array $criteria
   *   An array of criteria to filter the queue items.
   *
   * @return array
   *   The matching items.
   *
   * @throws \Exception
   */
  public function searchItems(string $queueId, array $criteria): array {
    $query = $this->database
      ->select('queue', 'q');
    foreach ($criteria as $criteriaItem) {
      $query->condition('data', $criteriaItem, 'LIKE');
    }

    return $query->condition('name', $queueId)
      ->fields('q', ['item_id'])
      ->execute()
      ->fetchAll();
  }

  /**
   * Deletes an item from the queue.
   *
   * Note: This method is not the best possible approach since it is coupled
   * to a specific implementation (database), while a queue may rely on Redis
   * or other implementations.
   *
   * @param string $queueId
   *   Queue ID.
   * @param string $itemId
   *   The item ID.
   *
   * @throws \Exception
   */
  public function deleteItem(string $queueId, string $itemId): void {
    $this->database
      ->delete('queue')
      ->condition('name', $queueId)
      ->condition('item_id', $itemId)
      ->execute();
  }

  /**
   * Requeue data to be processed by the Queue Manager.
   *
   * @param string $queueId
   *   Queue ID.
   * @param mixed $data
   *   Data coming from the queue.
   * @param int $leaseTime
   *   The lease time.
   */
  public function requeueData(string $queueId, $data, int $leaseTime = 3600): void {
    $queue = $this->enqueueData($queueId, $data);
    // Stop infinite loops.
    $queue->claimItem($leaseTime);
  }

}
