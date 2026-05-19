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

}
