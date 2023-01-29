<?php

namespace Drupal\labdoo_dootronics\Service;

/**
 * Interface for sequence managers.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
interface SequenceManagerInterface {

  /**
   * Gets the current sequence number.
   *
   * @return int
   *   Returns the current sequence number.
   *
   * @throws \Drupal\labdoo_dootronics\Exception\LockException
   */
  public function get(): int;

  /**
   * Commits the sequence number.
   */
  public function commit(): void;

}
