<?php

namespace Drupal\labdoo_migrate\Services\SourceContent;

/**
 * The source content repository interface.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
interface SourceRepositoryInterface {

  /**
   * Retrieves the source entities.
   *
   * @param string $contentType
   *   The content type.
   * @param array $mapping
   *   The mapping array.
   * @param array|null $entityIds
   *   The entity IDs array.
   * @param int|null $fromTimestamp
   *   Optional UNIX timestamp to filter nodes by created/updated date (>=).
   *
   * @return array
   *   Returns an array of source entities.
   *
   * @throws \Exception
   */
  public function getEntities(
    string $contentType,
    array $mapping,
    ?array $entityIds = NULL,
    ?int $fromTimestamp = NULL
  ): array;

  /**
   * Retrieves a single source entity.
   *
   * @param string $contentType
   *   The content type.
   * @param array $mapping
   *   The mapping array.
   * @param int $entityId
   *   The entity ID.
   * @param int|null $fromTimestamp
   *   Optional UNIX timestamp to filter nodes by created/updated date (>=).
   *
   * @return array
   *   Returns an array of source entity data.
   *
   * @throws \Exception
   */
  public function getEntity(
    string $contentType,
    array $mapping,
    int $entityId,
    ?int $fromTimestamp = NULL
  ): array;

  /**
   * Retrieves nodes by type.
   *
   * @param string|null $contentType
   *   Optional content type.
   * @param array|null $mapping
   *   Optional mapping array.
   * @param int|null $fromTimestamp
   *   Optional UNIX timestamp to filter nodes by created/updated date (>=).
   *
   * @return array
   *   Returns an array of node IDs.
   *
   * @throws \Exception
   */
  public function getNodesByType(
    ?string $contentType = NULL,
    ?array $mapping = NULL,
    ?int $fromTimestamp = NULL
  ): array;

}
