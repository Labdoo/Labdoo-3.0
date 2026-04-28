<?php

namespace Drupal\labdoo_edoovillage\Service\Repository;

use Drupal\Core\Entity\EntityInterface;

/**
 * Interface for the EdooVillage repository.
 */
interface EdooVillageRepositoryInterface {

  /**
   * Generates a new EdooVillage ID and releases the lock.
   *
   * @return int
   *   The generated ID.
   */
  public function generateId(): int;

  /**
   * Commits the current sequence (releases the lock).
   */
  public function commit(): void;

}
