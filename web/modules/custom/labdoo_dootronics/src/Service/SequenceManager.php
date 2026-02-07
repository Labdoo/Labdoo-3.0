<?php

namespace Drupal\labdoo_dootronics\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\labdoo_dootronics\Exception\LockException;

/**
 * Service that manages the Dootronics sequence.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class SequenceManager implements SequenceManagerInterface {

  /**
   * Lock key.
   */
  private const LOCK_KEY = 'labdoo.dootronic.id';

  /**
   * The lock API.
   *
   * @var \Drupal\Core\Lock\LockBackendInterface
   */
  private LockBackendInterface $lock;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  private Connection $database;

  /**
   * SequenceManager constructor.
   *
   * @param \Drupal\Core\Lock\LockBackendInterface $lock
   *   The lock API.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   */
  public function __construct(
    LockBackendInterface $lock,
    Connection $database
  ) {
    $this->lock = $lock;
    $this->database = $database;
  }

  /**
   * {@inheritDoc}
   */
  public function get(): int {
    if (!$this->lock->acquire(self::LOCK_KEY, 360)) {
      throw new LockException(self::LOCK_KEY);
    }

    // Find the first available node ID
    return $this->findFirstAvailableNodeId();
  }

  /**
   * {@inheritDoc}
   */
  public function commit(): void {
    $this->lock->release(self::LOCK_KEY);
  }

  /**
   * Finds the first available ID for dootronics based on the title field.
   *
   * This method looks for gaps in the sequence of dootronic titles.
   * Titles are expected to be numeric strings (e.g., "000000001").
   * If a gap is found, it returns the first available ID in the gap.
   * If no gap is found, it returns the next sequential ID.
   *
   * @return int
   *   The first available ID.
   */
  private function findFirstAvailableNodeId(): int {
    // Query to get all dootronic titles that are numeric
    $query = $this->database->select('node_field_data', 'n')
      ->fields('n', ['title'])
      ->condition('n.type', 'dootronic')
      ->orderBy('n.title', 'ASC');

    $result = $query->execute()->fetchCol();

    // Filter out non-numeric titles and convert to integers
    $ids = [];
    foreach ($result as $title) {
      if (is_numeric($title)) {
        $ids[] = (int) $title;
      }
    }
    $ids = array_unique($ids);
    sort($ids);

    if (empty($ids)) {
      // If no numeric dootronics exist, start with 1
      return $this->findFirstAvailableNodeIdInAllNodes(1);
    }

    // Find the first gap in the sequence
    $previousId = 0;
    foreach ($ids as $id) {
      if ($id > $previousId + 1) {
        // Found a gap, check if the ID is available in all nodes as nid
        $potentialId = $previousId + 1;
        return $this->findFirstAvailableNodeIdInAllNodes($potentialId);
      }
      $previousId = $id;
    }

    // No gaps found, return the next sequential ID
    return $this->findFirstAvailableNodeIdInAllNodes($previousId + 1);
  }

  /**
   * Finds the first available node ID starting from a given ID.
   *
   * This method checks if a node ID is already used by any node,
   * not just dootronic nodes.
   *
   * @param int $startId
   *   The ID to start checking from.
   *
   * @return int
   *   The first available node ID.
   */
  private function findFirstAvailableNodeIdInAllNodes(int $startId): int {
    $id = $startId;

    while (true) {
      // Check if the ID is already used by any node
      $query = $this->database->select('node_field_data', 'n')
        ->fields('n', ['nid'])
        ->condition('n.nid', $id)
        ->range(0, 1);

      $result = $query->execute()->fetchField();

      if ($result === false) {
        // ID is not used, return it
        return $id;
      }

      // ID is used, try the next one
      $id++;
    }
  }

}
