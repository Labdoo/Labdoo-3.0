<?php

namespace Drupal\labdoo_hub\Service\Compute;

use Drupal\Core\Entity\EntityInterface;

/**
 * Interface for Hub compute service.
 */
interface HubComputeInterface {

  /**
   * Enqueues a recompute task for the hub.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The hub entity.
   */
  public function enqueueRecompute(EntityInterface $entity): void;

  /**
   * Enqueues the geocoding of the hub.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   */
  public function enqueueGeocoding(EntityInterface $entity): void;

}
