<?php

namespace Drupal\labdoo_edoovillage\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\labdoo_dootronics\Exception\LockException;

/**
 * Service that manages the EdooVillage sequence.
 */
class SequenceManager implements SequenceManagerInterface {

  /**
   * Lock key.
   */
  private const LOCK_KEY = 'labdoo.edoovillage.id';

  /**
   * Lock timeout in seconds.
   */
  private const LOCK_TIMEOUT = 15;

  /**
   * Max time to wait for an existing lock to be released.
   */
  private const LOCK_WAIT_TIMEOUT = 5;

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
    if (!$this->lock->acquire(self::LOCK_KEY, self::LOCK_TIMEOUT)) {
      // Another process may be creating/cloning an edoovillage at the same time.
      // Wait briefly and retry once before failing.
      $this->lock->wait(self::LOCK_KEY, self::LOCK_WAIT_TIMEOUT);

      if (!$this->lock->acquire(self::LOCK_KEY, self::LOCK_TIMEOUT)) {
        throw new LockException(self::LOCK_KEY);
      }
    }

    return $this->findFirstAvailableTitleId();
  }

  /**
   * {@inheritDoc}
   */
  public function commit(): void {
    $this->lock->release(self::LOCK_KEY);
  }

  /**
   * Finds the first available ID for edoovillage titles.
   *
   * Searches for gaps in the numeric title sequence of edoovillages
   * following the pattern "Edoovillage #ID".
   *
   * @return int
   *   The first available ID for the edoovillage title.
   */
  private function findFirstAvailableTitleId(): int {
    // Query all existing edoovillage titles.
    $query = $this->database->select('node_field_data', 'n')
      ->fields('n', ['title'])
      ->condition('n.type', 'edoovillage')
      ->condition('n.title', 'Edoovillage #%', 'LIKE')
      ->orderBy('n.title', 'ASC');

    $result = $query->execute();

    $edoovillageIds = [];
    while (($title = $result->fetchField()) !== FALSE) {
      // Extract numeric IDs from titles that contain "#<number>".
      if (preg_match('/#(\d+)/', (string) $title, $matches)) {
        $edoovillageIds[] = (int) $matches[1];
      }
    }

    sort($edoovillageIds);
    $edoovillageIds = array_unique($edoovillageIds);

    // The following algorithm searches for any possible holes in the Labdoo ID
    // space and if none, allocates the next smallest ID.
    $potentialId = 0;
    $smallestId = 999999999999;

    foreach ($edoovillageIds as $thisId) {
      if ($smallestId > $thisId) {
        $smallestId = $thisId;
      }
      if (!$potentialId) {
        $potentialId = $thisId + 1;
        continue;
      }
      if ($potentialId < $thisId) {
        break;
      }
      $potentialId++;
    }

    // If no IDs were found in any of the edoovillage titles, start from 1.
    if (!$potentialId) {
      return 1;
    }

    return $potentialId;
  }

}
