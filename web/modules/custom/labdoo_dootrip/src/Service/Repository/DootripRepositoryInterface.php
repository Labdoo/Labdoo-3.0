<?php

namespace Drupal\labdoo_dootrip\Service\Repository;

use Drupal\Core\Entity\EntityInterface;

/**
 * Interface for dootrip repositories.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
interface DootripRepositoryInterface {

  /**
   * Disables the entity storage cache, useful for bulk operations.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function disableEntityStorageCache(): void;

  /**
   * Enables the entity storage cache.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function enableEntityStorageCache(): void;

  /**
   * Loads a dootrip by ID.
   *
   * @param int $dootripId
   *   The dootrip ID.
   *
   * @return EntityInterface|null
   *   The entity or NULL in case of error.
   */
  public function load(int $dootripId): ?EntityInterface;

  /**
   * Retrieves all dootrip nids.
   *
   * @return array
   *   An array with the dootrip nids.
   */
  public function getAllNids(): array;

  /**
   * Retrieves dootrip nids by the given conditions.
   *
   * @param array $joins
   *   An array with joins like:
   *   [
   *     [
   *       'type' => 'INNER',
   *       'table' => 'node__field_full',
   *       'alias' => 'nff',
   *       'condition' => 'nff.entity_id = n.nid',
   *     ],
   *   ]
 * @param array $conditions
   *   An array with conditions like:
   *   [
   *     [
   *       'field' => 'field_name',
   *       'value' => 'field_value',
   *       'operator' => 'condition_operator',
   *     ],
   *   ]
   *
   * @return array
   *   The matching nids.
   */
  public function getNidsByConditions(array $joins, array $conditions): array;

    /**
   * Loads dootrips by properties.
   *
   * @param array $properties
   *   The properties array.
   *
   * @return array
   *   The loaded dootrips.
   */
  public function loadByProperties(array $properties): array;

  /**
   * Saves a dootrip.
   *
   * @param \Drupal\Core\Entity\EntityInterface $dootrip
   *   The dootrip to be saved.
   *
   * @return bool
   *   The result of the operation.
   */
  public function saveEntity(EntityInterface $dootrip): bool;

  /**
   * Retrieve the edoovillages related to the given dootrip.
   *
   * @param int $dootripId
   *   The dootrip ID.
   *
   * @return array
   *   An array with the edoovillages or empty if no results found.
   */
  public function getRelatedEdooVillages(int $dootripId): array;

}
