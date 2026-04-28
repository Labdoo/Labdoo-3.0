<?php

namespace Drupal\labdoo_edoovillage\Service\Compute;

use Drupal\Core\Entity\EntityInterface;

/**
 * Interface for EdooVillage compute service.
 */
interface EdooVillageComputeInterface {

  /**
   * Sets the EdooVillage title based on ID, country, city and project summary.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The EdooVillage entity.
   */
  public function setEdooVillageTitle(EntityInterface $entity): void;

}
