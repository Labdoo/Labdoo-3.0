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

    if ($this->deleteRevisions) {
      if ($this->dryRun) {
        $this->deleteAllRevisionsExceptDefault($destinationNode);
      }
      // If NOT dryRun, the mass deletion already happened in the command class.
      // But we might want to ensure it's clean for THIS node if we are running nids only.
      // However, startSync already handles it for the whole bundle or nids.
    }

    if ($this->incremental && !$this->deleteRevisions) {
      $destinationRevisionIds = $this->entityTypeManager
        ->getStorage('node')
        ->revisionIds($destinationNode);

      if (count($revisions) === count($destinationRevisionIds)) {
        if ($this->output()->isVerbose()) {
          $this->logger->info(sprintf('Skipping node %d: Source and destination have the same number of revisions (%d).', $nid, count($revisions)));
        }
        return;
      }

      if (count($destinationRevisionIds) > count($revisions)) {
        $this->cleanDuplicateRevisions($destinationNode, $revisions);
        // Refresh destination revision IDs after cleanup.
        $destinationRevisionIds = $this->entityTypeManager
          ->getStorage('node')
          ->revisionIds($destinationNode);

        if (count($revisions) === count($destinationRevisionIds)) {
          return;
        }
      }
    }

    // Load mapping for this content type
    $config = $this->configurationManager->getContentConfiguration($sourceContentType);
    $mapping = $this->mapper->buildMapping($config->getFieldsMapping());

    // Pre-load existing revisions in destination to avoid duplicates.
    $existingD7Vids = $this->getExistingD7Revisions($destinationNode);

    // Get the current revision ID in D7 to set it as default in D10.
    $currentD7Vid = $this->revisionSourceRepository->getCurrentRevisionId($nid);

    foreach ($revisions as $revision) {
      if (isset($existingD7Vids[$revision->vid])) {
        if ($this->output()->isVerbose()) {
          $this->logger->info(sprintf('Revision %d for node %d already exists in destination. Skipping.', $revision->vid, $nid));
        }
        continue;
      }
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
        'is_default' => ($revision->vid == $currentD7Vid),
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

    if (isset($metadata['is_default']) && $metadata['is_default']) {
      $node->isDefaultRevision(TRUE);
    }
    else {
      // If we are importing revisions in order, and this is not the one
      // supposed to be default, we should mark it as non-default if possible.
      // However, Drupal usually makes the latest saved revision the default one.
      $node->isDefaultRevision(FALSE);
    }

    // Set title and status
    $node->set('title', $metadata['title']);
    $node->set('status', $metadata['status']);
    $node->set('created', $metadata['created']);
    $node->set('changed', $metadata['changed']);

    if ($node instanceof \Drupal\Core\Entity\EntityChangedInterface) {
      $node->setChangedTime($metadata['changed']);
    }

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

  /**
   * Deletes all revisions except the default one.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node entity.
   */
  protected function deleteAllRevisionsExceptDefault(NodeInterface $node): void {
    $storage = $this->entityTypeManager->getStorage('node');
    $revisionIds = $storage->revisionIds($node);
    $defaultVid = $node->getRevisionId();

    foreach ($revisionIds as $vid) {
      if ($vid == $defaultVid) {
        continue;
      }
      if ($this->dryRun) {
        $this->logger->info(sprintf('Dry-run: Would delete revision %d for node %d', $vid, $node->id()));
      }
      else {
        $storage->deleteRevision($vid);
      }
    }
  }

  /**
   * Cleans duplicate revisions in destination.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node entity.
   * @param array $sourceRevisions
   *   The source revisions.
   */
  protected function cleanDuplicateRevisions(NodeInterface $node, array $sourceRevisions): void {
    $storage = $this->entityTypeManager->getStorage('node');
    $revisionIds = $storage->revisionIds($node);
    $vidsFound = [];
    $currentVid = $node->getRevisionId();

    foreach ($revisionIds as $revisionId) {
      /** @var \Drupal\node\NodeInterface $revision */
      $revision = $storage->loadRevision($revisionId);
      $logMessage = $revision->getRevisionLogMessage() ?? '';

      // Extract D7 vid from log message.
      if (preg_match('/Imported from Drupal 7 revision (\d+)/', $logMessage, $matches)) {
        $d7Vid = $matches[1];

        if (isset($vidsFound[$d7Vid])) {
          // It's a duplicate.
          if ($revisionId == $currentVid) {
            $this->logger->warning(sprintf('Revision %d for node %d is a duplicate but it is the current revision. Skipping deletion.', $revisionId, $node->id()));
            continue;
          }

          if ($this->dryRun) {
            $this->logger->info(sprintf('Dry-run: Would delete duplicate revision %d for node %d (D7 vid: %s)', $revisionId, $node->id(), $d7Vid));
          }
          else {
            $storage->deleteRevision($revisionId);
            $this->logger->notice(sprintf('Deleted duplicate revision %d for node %d (D7 vid: %s)', $revisionId, $node->id(), $d7Vid));
          }
        }
        else {
          $vidsFound[$d7Vid] = $revisionId;
        }
      }
    }
  }

  /**
   * Retrieves existing D7 vids for a node.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node entity.
   *
   * @return array
   *   An array keyed by D7 vid with the D10 vid as value.
   */
  protected function getExistingD7Revisions(NodeInterface $node): array {
    $storage = $this->entityTypeManager->getStorage('node');
    $revisionIds = $storage->revisionIds($node);
    $existing = [];

    foreach ($revisionIds as $vid) {
      /** @var \Drupal\node\NodeInterface $revision */
      $revision = $storage->loadRevision($vid);
      if (!$revision) {
        continue;
      }
      $logMessage = $revision->getRevisionLogMessage() ?? '';
      if (preg_match('/Imported from Drupal 7 revision (\d+)/', $logMessage, $matches)) {
        $existing[$matches[1]] = $vid;
      }
    }

    return $existing;
  }

}
