<?php

namespace Drupal\labdoo_dootrip\Service\Compute;

use Drupal\Core\Entity\EntityInterface;

/**
 * Interface for Compute services.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
interface DootripComputeInterface {

  /**
   * Retrieves the total CO2 savings due to dootrips.
   *
   * @return float
   *   The CO2 savings of all dootrips.
   */
  public function computeTotalCo2Savings(): float;

  /**
   * Computes the CO2 savings of this dootrip.
   *
   * @param \Drupal\Core\Entity\EntityInterface $dootrip
   *   The dootrip.
   */
  public function computeCo2Savings(EntityInterface $dootrip): void;

  /**
   * Computes the dootrip distance.
   *
   * @param \Drupal\Core\Entity\EntityInterface $dootrip
   *   The dootrip.
   */
  public function computeDistance(EntityInterface $dootrip): void;

  /**
   * Computes the dootrip weight.
   *
   * @param \Drupal\Core\Entity\EntityInterface $dootrip
   *   The dootrip.
   */
  public function computeWeight(EntityInterface $dootrip): void;

  /**
   * Computes the assigned dootronics.
   *
   * @param \Drupal\Core\Entity\EntityInterface $dootrip
   *   The dootrip.
   */
  public function computeDootronicsAssigned(EntityInterface $dootrip): void;

  /**
   * Computes the Dootrip capacity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $dootrip
   *   The dootrip.
   */
  public function computeDootripCapacity(EntityInterface &$dootrip): void;

  /**
   * Computes the Dootrip locations.
   *
   * @param \Drupal\Core\Entity\EntityInterface $dootrip
   *   The dootrip.
   */
  public function computeDootripLocations(EntityInterface &$dootrip): void;

  /**
   * Computes the edoovillages assigned to a dootrip.
   *
   * @param \Drupal\Core\Entity\EntityInterface $dootrip
   *   The dootrip.
   */
  public function computeEdoovillagesAssigned(EntityInterface &$dootrip): void;

  /**
   * Computes the related dootronics for a dootrip.
   *
   * @param \Drupal\Core\Entity\EntityInterface $dootrip
   *   The dootrip object for which the related dootronics need to be computed.
   */
  public function computeRelatedDootronics(EntityInterface &$dootrip): void;

  /**
   * Enqueues the recompute of the dootrip capacity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   */
  public function enqueueCapacityRecompute(EntityInterface $entity): void;

  /**
   * Enqueues the geocoding of the dootrip.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   */
  public function enqueueGeocoding(EntityInterface $entity): void;

  /**
   * Enqueues the recompute of the total CO2 savings.
   */
  public function enqueueTotalCo2SavingsRecompute(): void;

}
