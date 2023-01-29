<?php

namespace Drupal\labdoo_dootronics\Exception;

/**
 * Thrown when a lock cannot be acquired.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class LockException extends \Exception {

  /**
   * LockException constructor.
   *
   * @param string $lockId
   *   The lock ID.
   */
  public function __construct(string $lockId) {
    parent::__construct(sprintf('The lock %s could not be acquired', $lockId));
  }

}
