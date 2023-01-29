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
   *
   * @return array
   *   Returns an array of source entities.
   *
   * @throws \Exception
   */
  public function getEntities(
    string $contentType,
    array $mapping,
    ?array $entityIds = NULL
  ): array;

}
