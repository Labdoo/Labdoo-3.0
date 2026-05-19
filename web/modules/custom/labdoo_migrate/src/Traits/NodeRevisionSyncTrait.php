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

    if ($this->incremental) {
      $destinationRevisionIds = $this->entityTypeManager
        ->getStorage('node')
        ->revisionIds($destinationNode);

      if (count($revisions) === count($destinationRevisionIds)) {
        if ($this->logger->isVerbose()) {
          $this->logger->info(sprintf('Skipping node %d: Source and destination have the same number of revisions (%d).', $nid, count($revisions)));
        }
        return;
      }
    }

    // Load mapping for this content type
    $config = $this->configurationManager->getContentConfiguration($sourceContentType);
    $mapping = $this->mapper->buildMapping($config->getFieldsMapping());

    foreach ($revisions as $revision) {
      // Get revision metadata
      $revisionMetadata = [
        'nid' => $revision->nid,
        'vid' => $revision->vid,
        'title' => $revision->title,
        'uid' => $revision->uid,
        'status' => $revision->status,
        'created' => $revision->timestamp,
        'changed' => $revision->timestamp,
        'log' => $revision->log,
      ];

      // Get field data for this specific revision using mapping
      $fieldData = $this->revisionSourceRepository->getRevisionFieldData(
        $nid,
        $revision->vid,
        $mapping,
        $sourceContentType
      );

      $this->updateRevisionInDestination($destinationNode, $revisionMetadata, $fieldData);
    }
  }

  /**
   * Updates/Creates a revision in destination.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node entity.
   * @param array $metadata
   *   The revision metadata.
   * @param array $fieldData
   *   The revision field data.
   */
  protected function updateRevisionInDestination(NodeInterface $node, array $metadata, array $fieldData): void {
    if ($this->dryRun) {
      $this->logger->info(sprintf('Dry-run: Creating revision for node %d (D7 vid: %d)', $metadata['nid'], $metadata['vid']));
      return;
    }

    $node->setNewRevision(TRUE);
    $node->setRevisionLogMessage($metadata['log'] ?: 'Imported from Drupal 7 revision ' . $metadata['vid']);
    $node->setRevisionCreationTime($metadata['created']);
    $node->setRevisionUserId($metadata['uid']);

    // Set title and status
    $node->set('title', $metadata['title']);
    $node->set('status', $metadata['status']);
    $node->set('changed', $metadata['changed']);

    // Set field values from mapping
    foreach ($fieldData as $fieldName => $value) {
      if ($node->hasField($fieldName)) {
        // Special handling for body or formatted text fields if needed
        $fieldDefinition = $node->getFieldDefinition($fieldName);
        $fieldType = $fieldDefinition->getType();

        if ($fieldType === 'text_with_summary' || $fieldType === 'text_long') {
          // Attempt to get format if available, otherwise use default
          $format = 'basic_html';
          if (isset($fieldData[$fieldName . '_format'])) {
            $format = $this->mapFormat($fieldData[$fieldName . '_format']);
          }
          $node->set($fieldName, [
            'value' => $value,
            'format' => $format,
          ]);
        }
        else {
          // Check for multi-value fields (many fields in D7 are migrated as single value but might be arrays)
          // For now, simple set.
          $node->set($fieldName, $value);
        }
      }
    }

    // Force values to be saved in revisions
    if (method_exists($node, 'setSyncing')) {
      $node->setSyncing(FALSE); // Try FALSE to see if it makes a difference for revisions
    }

    $node->save();
  }

}
