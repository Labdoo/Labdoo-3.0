<?php

namespace Drupal\labdoo_edoovillage\Service;

/**
 * Interface for the EdooVillage sequence manager.
 */
interface SequenceManagerInterface {

  /**
   * Acquires the lock and returns the next available EdooVillage ID.
   *
   * @return int
   *   The next available EdooVillage ID.
   */
  public function get(): int;

  /**
   * Releases the lock.
   */
  public function commit(): void;

}
