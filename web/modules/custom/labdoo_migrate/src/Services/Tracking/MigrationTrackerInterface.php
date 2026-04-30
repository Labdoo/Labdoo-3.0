<?php

namespace Drupal\labdoo_migrate\Services\Tracking;

/**
 * Tracks migrated entities and provides dashboard metrics.
 */
interface MigrationTrackerInterface {

  /**
   * Stores or updates a migrated entity record.
   *
   * @param string $entityType
   *   The destination entity type.
   * @param string $bundle
   *   The destination bundle/content type.
   * @param int $sourceId
   *   The source Drupal 7 entity ID.
   * @param int $destinationId
   *   The destination Drupal 10 entity ID.
   * @param int $durationMs
   *   The migration duration in milliseconds.
   */
  public function track(string $entityType, string $bundle, int $sourceId, int $destinationId, int $durationMs = 0): void;

  /**
   * Builds dashboard metrics by content type.
   *
   * @return array
   *   An array of rows with totals and latest migration date.
   */
  public function getDashboardRows(): array;

  /**
   * Retrieves the source IDs that have been already migrated for a bundle.
   *
   * @param string $entityType
   *   The destination entity type.
   * @param string $bundle
   *   The destination bundle/content type.
   *
   * @return array
   *   An array of source entity IDs.
   */
  public function getMigratedSourceIds(string $entityType, string $bundle): array;

  /**
   * Retrieves the source ID for a given destination ID.
   *
   * @param string $entityType
   *   The destination entity type.
   * @param int $destinationId
   *   The destination entity ID.
   *
   * @return int|null
   *   The source entity ID or NULL if not found.
   */
  public function getSourceIdByDestinationId(string $entityType, int $destinationId): ?int;

}
