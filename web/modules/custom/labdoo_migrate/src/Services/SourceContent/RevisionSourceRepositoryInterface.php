<?php

namespace Drupal\labdoo_migrate\Services\SourceContent;

/**
 * Interface for the revision source repository.
 */
interface RevisionSourceRepositoryInterface {

  /**
   * Retrieves the revisions of a node from the source database.
   *
   * @param int $nid
   *   The node ID.
   *
   * @return array
   *   An array of revision objects.
   */
  public function getRevisionsByNid(int $nid): array;

  /**
   * Retrieves the current revision ID of a node from the source database.
   *
   * @param int $nid
   *   The node ID.
   *
   * @return int|null
   *   The current revision ID or NULL if not found.
   */
  public function getCurrentRevisionId(int $nid): ?int;

  /**
   * Retrieves revision counts for all nodes of a specific type.
   *
   * @param string $contentType
   *   The content type.
   *
   * @return array
   *   An array keyed by nid with the count of revisions as value.
   */
  public function getRevisionCountsByType(string $contentType): array;

  /**
   * Retrieves the field data for a specific revision.
   *
   * @param int $nid
   *   The node ID.
   * @param int $vid
   *   The revision ID.
   * @param array $mapping
   *   The mapping array.
   * @param string $contentType
   *   The content type.
   *
   * @return array
   *   The field data.
   */
  public function getRevisionFieldData(int $nid, int $vid, array $mapping, string $contentType): array;

}
