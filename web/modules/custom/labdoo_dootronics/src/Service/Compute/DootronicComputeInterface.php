<?php

namespace Drupal\labdoo_dootronics\Service\Compute;

use Drupal\Core\Entity\EntityInterface;

/**
 * Interface for Compute services.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
interface DootronicComputeInterface {

  /**
   * Computes and updates the EdooVillage data for a given entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity object.
   */
  public function computeEdooVillageData(EntityInterface $entity): void;

  /**
   * Computes and updates the hub data for a given entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity object.
   */
  public function computeHubData(EntityInterface $entity): void;

  /**
   * Sets the Dootronic's title.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   *
   * @return void
   */
  public function setDootronicTitle(EntityInterface $entity): void;

  /**
   * Computes the watt per hours.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   *
   * @return void
   */
  function computeWattHours(EntityInterface $entity): void;

  /**
   * Computes the related dootrips for a dootronic.
   *
   * @param \Drupal\Core\Entity\EntityInterface $dootronic
   *   The dootronic object for which the related dootrips need to be computed.
   * @param array $originalDootripIds
   *   Optional list of original dootrip IDs.
   */
  public function computeRelatedDootrips(EntityInterface &$dootronic, array $originalDootripIds = []): void;

  /**
   * Enqueues the recompute of heavy fields.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   */
  public function enqueueRecompute(EntityInterface $entity): void;

}
