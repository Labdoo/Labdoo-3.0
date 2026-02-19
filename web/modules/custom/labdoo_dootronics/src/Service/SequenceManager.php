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

    // Find the first available numeric title (dootronic label), independent of NIDs.
    return $this->findFirstAvailableTitleId();
  }

  /**
   * {@inheritDoc}
   */
  public function commit(): void {
    $this->lock->release(self::LOCK_KEY);
  }

  /**
   * Finds the first available ID for dootronic titles (labels).
   *
   * Searches for gaps in the numeric title sequence of dootronics
   * (for example, "000000001"). If it finds a gap, it returns the first
   * available ID in that gap. If there are no gaps, it returns the next
   * sequential ID. This logic is completely independent of NIDs
   * (internal node identifiers), as required.
   *
   * @return int
   *   The first available ID for the dootronic title.
   */
  private function findFirstAvailableTitleId(): int {
    // Get all dootronic titles.
    $query = $this->database->select('node_field_data', 'n')
      ->fields('n', ['title'])
      ->condition('n.type', 'dootronic')
      ->orderBy('n.title', 'ASC');

    $result = $query->execute()->fetchCol();

    // Filter non-numeric titles and convert to integers.
    $ids = [];
    foreach ($result as $title) {
      if (is_numeric($title)) {
        $ids[] = (int) $title;
      }
    }

    if (empty($ids)) {
      // If no numeric dootronics exist, start at 1.
      return 1;
    }

    $ids = array_unique($ids);
    sort($ids);

    // Find the first gap.
    $previousId = 0;
    foreach ($ids as $id) {
      if ($id > $previousId + 1) {
        return $previousId + 1;
      }
      $previousId = $id;
    }

    // No gaps: return the next sequential ID.
    return $previousId + 1;
  }


}
