<?php

namespace Drupal\labdoo_hub\Service\Repository;

/**
 * Interface for the Hub repository.
 */
interface HubRepositoryInterface {

  /**
   * Retrieves aggregated stats for Hubs.
   *
   * @param int|null $userId
   *   Filter by user ID if provided.
   *
   * @return array
   *   An array with stats: needed, delivered, in_transit, remaining.
   */
  public function getStats(?int $userId = NULL): array;

}
