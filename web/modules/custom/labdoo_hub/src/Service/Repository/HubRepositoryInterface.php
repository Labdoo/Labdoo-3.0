<?php

namespace Drupal\labdoo_hub\Service\Repository;

use Drupal\node\NodeInterface;

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
   * @return \Drupal\node\NodeInterface|null
   *   The entity or null.
   */
  public function load(int $id): ?NodeInterface;

  /**
   * Saves a hub.
   *
   * @param \Drupal\node\NodeInterface $entity
   *   The entity.
   */
  public function saveEntity(NodeInterface $entity): void;

  /**
   * Invalidates the cache for a hub.
   *
   * @param int $hubId
   *   The hub ID.
   *
   * @return void
   *   The resulting value.
   */
  public function invalidateCache(int $hubId): void;

}
