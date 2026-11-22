<?php

namespace Drupal\labdoo_dootronics\Service\Compute;

use Drupal\node\NodeInterface;

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
   * @param \Drupal\node\NodeInterface $entity
   *   The entity object.
   */
  public function computeEdooVillageData(NodeInterface $entity): void;

  /**
   * Computes and updates the hub data for a given entity.
   *
   * @param \Drupal\node\NodeInterface $entity
   *   The entity object.
   */
  public function computeHubData(NodeInterface $entity): void;

  /**
   * Sets the Dootronic's title.
   *
   * @param \Drupal\node\NodeInterface $entity
   *   The entity.
   *
   * @return void
   *   The resulting value.
   */
  public function setDootronicTitle(NodeInterface $entity): void;

  /**
   * Computes the watt per hours.
   *
   * @param \Drupal\node\NodeInterface $entity
   *   The entity.
   *
   * @return void
   *   The resulting value.
   */
  public function computeWattHours(NodeInterface $entity): void;

  /**
   * Computes the related dootrips for a dootronic.
   *
   * @param \Drupal\node\NodeInterface $dootronic
   *   The dootronic object for which the related dootrips need to be computed.
   * @param array $originalDootripIds
   *   Optional list of original dootrip IDs.
   */
  public function computeRelatedDootrips(NodeInterface &$dootronic, array $originalDootripIds = []): void;

  /**
   * Enqueues the recompute of heavy fields.
   *
   * @param \Drupal\node\NodeInterface $entity
   *   The entity.
   */
  public function enqueueRecompute(NodeInterface $entity): void;

}
