<?php

namespace Drupal\labdoo_migrate\Services\DestinationContent;

/**
 * The destination repository interface.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 *
 * @template T of \Drupal\Core\Entity\ContentEntityInterface
 */
interface DestinationRepositoryInterface {

  /**
   * Sets the override mode.
   *
   * If TRUE, a nodes are overwritten if it exists.
   * If FALSE, the execution fails gracefully if a node exist.
   *
   * @param bool $overrideMode
   *   Whether the synchronizer requested replacement of existing destinations.
   *
   * @return void
   *   The resulting value.
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
   * @return array<int, T>
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
   * @param string $contentType
   *   The destination content type.
   * @param bool $dryRun
   *   Whether to run this process in dry-run mode.
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
    bool $dryRun = FALSE,
  ): int;

  /**
   * Updates a set of entities.
   *
   * @param array $sourceEntities
   *   The source entities array.
   * @param array $mapping
   *   The mapping.
   * @param array<int, T> $destinationEntities
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
    bool $dryRun = FALSE,
  ): int;

  /**
   * Sets the total source entities count.
   *
   * @param int $total
   *   The total source entities count.
   *
   * @return void
   *   The resulting value.
   */
  public function setTotalCount(int $total): void;

  /**
   * Sets the indexing mode.
   *
   * @param bool $indexingEnabled
   *   Whether to enable or disable indexing.
   *
   * @return void
   *   The resulting value.
   */
  public function setIndexingMode(bool $indexingEnabled): void;

  /**
   * Sets the batch size.
   *
   * @param int $batchSize
   *   The batch size.
   *
   * @return void
   *   The resulting value.
   */
  public function setBatchSize(int $batchSize): void;

}
