<?php

namespace Drupal\labdoo_gallery\Service\Compute;

use Drupal\Core\Entity\EntityInterface;

/**
 * Interface for Gallery compute service.
 */
interface GalleryComputeInterface {

  /**
   * Enqueues the creation or update of a gallery for an entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   */
  public function enqueueGalleryCreateOrUpdate(EntityInterface $entity): void;

  /**
   * Creates or updates a gallery entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to attach the gallery to.
   */
  public function createOrUpdateGallery(EntityInterface $entity): void;

}
