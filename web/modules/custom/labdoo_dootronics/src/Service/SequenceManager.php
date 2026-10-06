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

    // Issued labels are permanent identifiers. Never reuse historical gaps.
    return $this->findNextTitleId();
  }

  /**
   * {@inheritDoc}
   */
  public function commit(): void {
    $this->lock->release(self::LOCK_KEY);
  }

  /**
   * Finds the next ID after the highest numeric dootronic label.
   *
   * Gaps can represent labels printed on physical devices or deleted nodes.
   * Reusing one would make the newest label lower than earlier issued labels.
   *
   * @return int
   *   The first available ID for the dootronic title.
   */
  private function findNextTitleId(): int {
    $query = $this->database->select('node_field_data', 'n')
      ->condition('n.type', 'dootronic')
      ->condition('n.title', '^[0-9]+$', 'REGEXP');
    $query->addExpression('MAX(CAST(n.title AS UNSIGNED))', 'max_label');

    return ((int) $query->execute()->fetchField()) + 1;
  }


}
