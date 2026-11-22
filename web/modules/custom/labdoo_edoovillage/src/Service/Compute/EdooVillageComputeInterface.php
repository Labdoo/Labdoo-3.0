<?php

namespace Drupal\labdoo_edoovillage\Service\Compute;

use Drupal\node\NodeInterface;

/**
 * Interface for EdooVillage compute service.
 */
interface EdooVillageComputeInterface {

  /**
   * Sets the EdooVillage title based on ID, country, city and project summary.
   *
   * @param \Drupal\node\NodeInterface $entity
   *   The EdooVillage entity.
   */
  public function setEdooVillageTitle(NodeInterface $entity): void;

  /**
   * Enqueues a recompute task for the edoovillage.
   *
   * @param \Drupal\node\NodeInterface $entity
   *   The edoovillage entity.
   */
  public function enqueueRecompute(NodeInterface $entity): void;

}
