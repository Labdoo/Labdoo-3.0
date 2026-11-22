<?php

namespace Drupal\labdoo_hub\Service\Compute;

use Drupal\node\NodeInterface;

use Drupal\Core\Entity\EntityInterface;

/**
 * Interface for Hub compute service.
 */
interface HubComputeInterface {

  /**
   * Enqueues a recompute task for the hub.
   *
   * @param \Drupal\node\NodeInterface $entity
   *   The hub entity.
   */
  public function enqueueRecompute(NodeInterface $entity): void;

  /**
   * Enqueues the geocoding of the hub.
   *
   * @param \Drupal\node\NodeInterface $entity
   *   The entity.
   */
  public function enqueueGeocoding(EntityInterface $entity): void;

}
