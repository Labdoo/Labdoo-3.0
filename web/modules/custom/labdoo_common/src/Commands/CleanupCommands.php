<?php

namespace Drupal\labdoo_common\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;
use Drush\Commands\DrushCommands;

/**
 * Cleanup Drush commands.
 */
class CleanupCommands extends DrushCommands {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * CleanupCommands constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(EntityTypeManagerInterface $entityTypeManager) {
    parent::__construct();
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * Detects and removes duplicate revisions for nodes.
   *
   * @command labdoo:cleanup-duplicate-revisions
   * @aliases l-cdr
   * @param string $node_type
   *   The node type to process.
   * @option dry-run
   *   Whether to run the command without deleting revisions.
   * @usage drush labdoo:cleanup-duplicate-revisions page
   *   Removes duplicate revisions for 'page' nodes.
   * @usage drush labdoo:cleanup-duplicate-revisions page --dry-run
   *   Lists duplicate revisions for 'page' nodes without deleting them.
   */
  public function cleanupDuplicateRevisions(string $node_type, $options = ['dry-run' => FALSE]): void {
    $dry_run = $options['dry-run'];
    $storage = $this->entityTypeManager->getStorage('node');
    
    $query = $storage->getQuery()
      ->condition('type', $node_type)
      ->accessCheck(FALSE);
    $nids = $query->execute();

    if (empty($nids)) {
      $this->io()->note(dt('No nodes found for type @type.', ['@type' => $node_type]));
      return;
    }

    $this->io()->title(dt('Processing duplicate revisions for type @type', ['@type' => $node_type]));
    $total_deleted = 0;

    foreach ($nids as $nid) {
      $vids = $storage->revisionIds($storage->load($nid));
      if (count($vids) <= 1) {
        continue;
      }

      $this->io()->text(dt('Checking node @nid (@count revisions)...', ['@nid' => $nid, '@count' => count($vids)]));
      
      $revisions_to_delete = [];
      $previous_revision_data = null;

      foreach ($vids as $vid) {
        /** @var \Drupal\node\NodeInterface $revision */
        $revision = $storage->loadRevision($vid);
        if (!$revision) {
          continue;
        }

        $current_revision_data = $this->getRevisionData($revision);

        if ($previous_revision_data !== null && $current_revision_data === $previous_revision_data) {
          // It's a duplicate of the previous revision.
          // Check if it's the default revision. We should not delete the current/default revision.
          if (!$revision->isDefaultRevision()) {
            $revisions_to_delete[] = $vid;
          } else {
             $this->io()->warning(dt('Found duplicate revision @vid for node @nid, but it is the default revision. Skipping.', ['@vid' => $vid, '@nid' => $nid]));
          }
        } else {
          $previous_revision_data = $current_revision_data;
        }
      }

      if (!empty($revisions_to_delete)) {
        foreach ($revisions_to_delete as $vid_to_delete) {
          if ($dry_run) {
            $this->io()->text(dt('  [DRY-RUN] Would delete duplicate revision @vid', ['@vid' => $vid_to_delete]));
          } else {
            $storage->deleteRevision($vid_to_delete);
            $this->io()->text(dt('  Deleted duplicate revision @vid', ['@vid' => $vid_to_delete]));
          }
          $total_deleted++;
        }
      }
    }

    if ($dry_run) {
      $this->io()->success(dt('Dry run completed. Found @count duplicate revisions.', ['@count' => $total_deleted]));
    } else {
      $this->io()->success(dt('Cleanup completed. Deleted @count duplicate revisions.', ['@count' => $total_deleted]));
    }
  }

  /**
   * Extracts relevant data from a revision to compare with others.
   */
  protected function getRevisionData(NodeInterface $revision): array {
    $data = [];
    // We exclude metadata fields like vid, revision_timestamp, revision_uid, revision_log.
    // We want to compare the actual content fields.
    foreach ($revision->getFieldDefinitions() as $field_name => $definition) {
      if ($definition->getFieldStorageDefinition()->isBaseField()) {
        // Skip base fields that are expected to change or are irrelevant for content equality.
        if (in_array($field_name, [
          'vid',
          'revision_timestamp',
          'revision_uid',
          'revision_log',
          'changed',
        ])) {
          continue;
        }
      }
      $data[$field_name] = $revision->get($field_name)->getValue();
    }
    return $data;
  }

}
