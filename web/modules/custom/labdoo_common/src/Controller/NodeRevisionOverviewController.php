<?php declare(strict_types = 1);

namespace Drupal\labdoo_common\Controller;

use Drupal\node\Controller\NodeController;
use Drupal\node\NodeInterface;
use Drupal\node\NodeStorageInterface;

/**
 * Custom node revision overview controller.
 */
class NodeRevisionOverviewController extends NodeController {

  /**
   * {@inheritdoc}
   */
  protected function getRevisionIds(NodeInterface $node, NodeStorageInterface $node_storage): array {
    $revision_key = $node->getEntityType()->getKey('revision');
    $revision_created_key = $node->getEntityType()->getRevisionMetadataKey('revision_created') ?? 'revision_timestamp';

    $result = $node_storage->getQuery()
      ->accessCheck(TRUE)
      ->allRevisions()
      ->condition($node->getEntityType()->getKey('id'), $node->id())
      ->sort($revision_created_key, 'DESC')
      ->sort($revision_key, 'DESC')
      ->pager(50)
      ->execute();

    return array_keys($result);
  }

}
