<?php

namespace Drupal\labdoo_common\Service\Queue\Feeder;

/**
 * Interface for Neighbor Invalidation Queue Feeder.
 */
interface NeighborInvalidationQueueFeederInterface {

  /**
   * Enqueues a neighbor invalidation task.
   *
   * @param string $entityType
   *   The entity type (dootronic, edoovillage, hub).
   * @param int|null $entityId
   *   The entity ID.
   * @param string|null $label
   *   The entity label (title), useful for dootronics if deleted.
   */
  public function feedQueue(string $entityType, ?int $entityId = NULL, ?string $label = NULL): void;

  /**
   * Enqueues a node cache invalidation task.
   *
   * @param int $nid
   *   The node ID.
   */
  public function invalidateNode(int $nid): void;

}
