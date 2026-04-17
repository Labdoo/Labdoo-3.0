<?php

namespace Drupal\labdoo_migrate\Services\DestinationContent;

/**
 * The destination repository interface.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
interface DestinationRepositoryInterface {

  /**
   * Sets the override mode.
   *
   * If TRUE, a nodes are overwritten if it exists.
   * If FALSE, the execution fails gracefully if a node exist.
   *
   * @param bool $overrideMode
   *
   * @return void
   */
  public function setOverrideMode(bool $overrideMode): void;

  /**
   * Retrieves the entities.
   *
   * @param array $contentTypes
   *   The content types array.
   * @param array $nids
   *   Optional list of node IDs to filter the results.
   *
   * @return array
   *   Returns an array of entities.
   *
   * @throws \Exception
   */
  public function getEntities(array $contentTypes, array $nids = []): array;

  /**
   * Retrieves the processed entities summary.
   *
   * @return array
   *   Returns the processed entities summary.
   */
  public function getProcessedEntitiesSummary(): array;

  /**
   * Creates a set of entities.
   *
   * @param array $sourceEntities
   *   The source entities array.
   * @param array $mapping
   *   The mapping.
   * @param bool $dryRun
   *   Whether to run this process in dry-run mode.
   * @param string $contentType
   *   The destination content type.
   *
   * @return int
   *   Returns the created entities count.
   *
   * @throws \Exception
   */
  public function createEntities(
    array $sourceEntities,
    array $mapping,
    string $contentType,
    bool $dryRun = FALSE
  ): int;

  /**
   * Updates a set of entities.
   *
   * @param array $sourceEntities
   *   The source entities array.
   * @param array $mapping
   *   The mapping.
   * @param array $destinationEntities
   *   The destination entities array.
   * @param bool $dryRun
   *   Whether to run this process in dry-run mode.
   *
   * @return int
   *   Returns the updated entities count.
   *
   * @throws \Exception
   */
  public function updateEntities(
    array $sourceEntities,
    array $mapping,
    array $destinationEntities,
    bool $dryRun = FALSE
  ): int;

  /**
   * Sets the total source entities count.
   *
   * @param int $total
   *   The total source entities count.
   *
   * @return void
   */
  public function setTotalCount(int $total): void;

}
