<?php

namespace Drupal\labdoo_edoovillage\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\labdoo_common\Service\Repository\CommonRepository;
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
   * The common repository.
   *
   * @var \Drupal\labdoo_common\Service\Repository\CommonRepository
   */
  private CommonRepository $commonRepository;

  /**
   * SequenceManager constructor.
   *
   * @param \Drupal\Core\Lock\LockBackendInterface $lock
   *   The lock API.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\labdoo_common\Service\Repository\CommonRepository $commonRepository
   *   The common repository.
   */
  public function __construct(
    LockBackendInterface $lock,
    Connection $database,
    CommonRepository $commonRepository
  ) {
    $this->lock = $lock;
    $this->database = $database;
    $this->commonRepository = $commonRepository;
  }

  /**
   * {@inheritDoc}
   */
  public function get(): int {
    if (!$this->lock->acquire(self::LOCK_KEY, 360)) {
      throw new LockException(self::LOCK_KEY);
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
      ->orderBy('n.title', 'ASC');

    $result = $query->execute();

    $totalNumEdoovillages = $this->commonRepository->getBundleCount('edoovillage');

    $edoovillageIds = [];
    while (($title = $result->fetchField()) !== FALSE) {
      $edoovillageWords = explode(' ', $title);
      // Skip edoovillages that don't follow the "Edoovillage #ID" pattern.
      if (!isset($edoovillageWords[1]) || $edoovillageWords[0] !== "Edoovillage") {
        continue;
      }
      $edoovillageNumber = explode('#', $edoovillageWords[1]);
      if (isset($edoovillageNumber[1])) {
        $edoovillageIds[] = (int) $edoovillageNumber[1];
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

    // If no IDs were found in any of the edoovillage titles, it means this is
    // the first edoovillage to be saved using the ID notation.
    if (!$potentialId) {
      return $totalNumEdoovillages + 1;
    }

    // If the potential ID is larger than the total number of edoovillages,
    // this means that we deleted an edoovillage which did not have an ID.
    // Thus we should assign an ID just one number smaller than the smallest ID
    // we currently have, expanding the ID set from the left rather than from the right.
    if ($potentialId > $totalNumEdoovillages + 1) {
      return $smallestId - 1;
    }

    return $potentialId;
  }

}
