<?php

namespace Drupal\labdoo_hub\Service\Repository;

/**
 * Interface for the Hub repository.
 */
interface HubRepositoryInterface {

  /**
   * Retrieves aggregated stats for Hubs.
   *
   * @param int|null $userId
   *   Filter by user ID if provided.
   *
   * @return array
   *   An array with stats: needed, delivered, in_transit, remaining.
   */
  public function getStats(?int $userId = NULL): array;

  /**
   * Loads a hub.
   *
   * @param int $id
   *   The entity ID.
   *
   * @return \Drupal\Core\Entity\EntityInterface|null
   *   The entity or null.
   */
  public function load(int $id): ?\Drupal\Core\Entity\EntityInterface;

  /**
   * Saves a hub.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   */
  public function saveEntity(\Drupal\Core\Entity\EntityInterface $entity): void;

  /**
   * Invalidates the cache for a hub.
   *
   * @param int $hubId
   *   The hub ID.
   *
   * @return void
   */
  public function invalidateCache(int $hubId): void;

}
