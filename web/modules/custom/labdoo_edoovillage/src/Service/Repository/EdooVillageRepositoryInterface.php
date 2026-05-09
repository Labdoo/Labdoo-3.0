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

  /**
   * Retrieves aggregated stats for EdooVillages.
   *
   * @param int|null $userId
   *   Filter by user ID if provided.
   *
   * @return array
   *   An array with stats: needed, delivered, in_transit, remaining.
   */
  public function getStats(?int $userId = NULL): array;

  /**
   * Loads an edoovillage.
   *
   * @param int $id
   *   The entity ID.
   *
   * @return \Drupal\Core\Entity\EntityInterface|null
   *   The entity or null.
   */
  public function load(int $id): ?EntityInterface;

  /**
   * Saves an edoovillage.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   */
  public function saveEntity(EntityInterface $entity): void;

}
