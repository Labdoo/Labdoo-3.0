<?php

namespace Drupal\labdoo_dootrip\Service\Compute;

use Drupal\node\NodeInterface;

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
   * @param \Drupal\node\NodeInterface $dootrip
   *   The dootrip.
   */
  public function computeCo2Savings(NodeInterface $dootrip): void;

  /**
   * Computes the dootrip distance.
   *
   * @param \Drupal\node\NodeInterface $dootrip
   *   The dootrip.
   */
  public function computeDistance(NodeInterface $dootrip): void;

  /**
   * Computes the dootrip weight.
   *
   * @param \Drupal\node\NodeInterface $dootrip
   *   The dootrip.
   */
  public function computeWeight(NodeInterface $dootrip): void;

  /**
   * Computes the assigned dootronics.
   *
   * @param \Drupal\node\NodeInterface $dootrip
   *   The dootrip.
   */
  public function computeDootronicsAssigned(NodeInterface $dootrip): void;

  /**
   * Computes the Dootrip capacity.
   *
   * @param \Drupal\node\NodeInterface $dootrip
   *   The dootrip.
   */
  public function computeDootripCapacity(NodeInterface &$dootrip): void;

  /**
   * Computes the Dootrip locations.
   *
   * @param \Drupal\node\NodeInterface $dootrip
   *   The dootrip.
   */
  public function computeDootripLocations(NodeInterface &$dootrip): void;

  /**
   * Computes the edoovillages assigned to a dootrip.
   *
   * @param \Drupal\node\NodeInterface $dootrip
   *   The dootrip.
   */
  public function computeEdoovillagesAssigned(NodeInterface &$dootrip): void;

  /**
   * Computes the related dootronics for a dootrip.
   *
   * @param \Drupal\node\NodeInterface $dootrip
   *   The dootrip object for which the related dootronics need to be computed.
   * @param array $originalDootronicIds
   *   Optional list of original dootronic IDs.
   */
  public function computeRelatedDootronics(NodeInterface &$dootrip, array $originalDootronicIds = []): void;

  /**
   * Enqueues the recompute of the dootrip capacity.
   *
   * @param \Drupal\node\NodeInterface $entity
   *   The entity.
   */
  public function enqueueCapacityRecompute(NodeInterface $entity): void;

  /**
   * Enqueues the geocoding of the dootrip.
   *
   * @param \Drupal\node\NodeInterface $entity
   *   The entity.
   */
  public function enqueueGeocoding(NodeInterface $entity): void;

  /**
   * Enqueues the recompute of the total CO2 savings.
   */
  public function enqueueTotalCo2SavingsRecompute(): void;

  /**
   * Sets the title for the dootrip.
   *
   * @param \Drupal\node\NodeInterface $entity
   *   The dootrip entity.
   */
  public function setDootripTitle(NodeInterface $entity): void;

}
