<?php

namespace Drupal\labdoo_migrate\Traits;

use Drupal\node\NodeInterface;

/**
 * Trait for synchronizing node revisions.
 */
trait NodeRevisionSyncTrait {

  /**
   * Synchronizes revisions for a single node.
   *
   * @param int $nid
   *   The node ID.
   * @param string $sourceContentType
   *   The source content type in D7.
   * @param string $bodyFieldName
   *   The body field name in D7 (usually 'body').
   *
   * @throws \Exception
   */
  protected function syncNodeRevisions(int $nid, string $sourceContentType, string $bodyFieldName = 'body'): void {
    $revisions = $this->revisionSourceRepository->getRevisionsByNid($nid);
    if (empty($revisions)) {
      return;
    }

    $destinationNode = $this->entityTypeManager
      ->getStorage('node')
      ->load($nid);

    if (!$destinationNode) {
      $this->logger->warning(sprintf('Node %d not found in destination. Skipping revisions.', $nid));
      return;
    }

    foreach ($revisions as $revision) {
      // Get revision data including fields
      $revisionData = $this->getRevisionData($revision, $sourceContentType, $bodyFieldName);

      if (empty($revisionData)) {
        continue;
      }

      $this->updateRevisionInDestination($destinationNode, $revisionData);
    }
  }

  /**
   * Gets revision data.
   *
   * @param object $revision
   *   The revision object from D7.
   * @param string $sourceContentType
   *   The source content type in D7.
   * @param string $bodyFieldName
   *   The body field name in D7.
   *
   * @return array
   *   The revision data.
   */
  protected function getRevisionData(object $revision, string $sourceContentType, string $bodyFieldName): array {
    $nodeId = $revision->nid;
    $vid = $revision->vid;

    // Get the body field for this specific revision
    $tableName = 'field_revision_' . $bodyFieldName;
    $valueCol = $bodyFieldName . '_value';
    $formatCol = $bodyFieldName . '_format';

    $body = NULL;
    try {
      $body = $this->externalConnectionManager
        ->setConnection()
        ->select($tableName, 'frb')
        ->fields('frb', [$valueCol, $formatCol])
        ->condition('entity_id', $nodeId)
        ->condition('revision_id', $vid)
        ->condition('entity_type', 'node')
        ->condition('bundle', $sourceContentType)
        ->execute()
        ->fetch();
    }
    catch (\Exception $e) {
      // If table or field doesn't exist, we just skip it (body will be empty)
    }

    $this->externalConnectionManager->restoreConnection();

    return [
      'nid' => $nodeId,
      'vid' => $vid,
      'title' => $revision->title,
      'uid' => $revision->uid,
      'status' => $revision->status,
      'created' => $revision->timestamp,
      'changed' => $revision->timestamp,
      'log' => $revision->log,
      'body' => $body ? $body->$valueCol : '',
      'body_format' => $body ? $body->$formatCol : 'basic_html',
    ];
  }

  /**
   * Updates/Creates a revision in destination.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node entity.
   * @param array $revisionData
   *   The revision data.
   */
  protected function updateRevisionInDestination(NodeInterface $node, array $revisionData): void {
    if ($this->dryRun) {
      $this->logger->info(sprintf('Dry-run: Creating revision for node %d (D7 vid: %d)', $revisionData['nid'], $revisionData['vid']));
      return;
    }

    $node->setNewRevision(TRUE);
    $node->setRevisionLogMessage($revisionData['log'] ?: 'Imported from Drupal 7 revision ' . $revisionData['vid']);
    $node->setRevisionCreationTime($revisionData['created']);
    $node->setRevisionUserId($revisionData['uid']);

    // Set values
    if ($node->hasField('body')) {
      $format = $this->mapFormat($revisionData['body_format']);
      $node->set('body', [
        'value' => $revisionData['body'],
        'format' => $format,
      ]);
    }
    $node->set('title', $revisionData['title']);
    $node->set('status', $revisionData['status']);
    $node->set('changed', $revisionData['changed']);

    if (method_exists($node, 'setSyncing')) {
      $node->setSyncing(TRUE);
    }

    $node->save();
  }

}
