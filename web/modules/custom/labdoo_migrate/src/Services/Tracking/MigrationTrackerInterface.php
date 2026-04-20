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
   */
  public function track(string $entityType, string $bundle, int $sourceId, int $destinationId): void;

  /**
   * Builds dashboard metrics by content type.
   *
   * @return array
   *   An array of rows with totals and latest migration date.
   */
  public function getDashboardRows(): array;

}
