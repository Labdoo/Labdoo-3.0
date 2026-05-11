<?php

namespace Drupal\labdoo_migrate\Commands;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\labdoo_migrate\Services\Database\ConnectionManagerInterface;
use Drush\Commands\DrushCommands;

/**
 * Commands to fix orphan uids.
 */
class FixUidCommands extends DrushCommands {

  /**
   * The Drupal database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The external database manager (Drupal 7).
   *
   * @var \Drupal\labdoo_migrate\Services\Database\ConnectionManagerInterface
   */
  protected $externalConnectionManager;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * FixUidCommands constructor.
   */
  public function __construct(
    Connection $database,
    ConnectionManagerInterface $externalConnectionManager,
    EntityTypeManagerInterface $entityTypeManager
  ) {
    parent::__construct();
    $this->database = $database;
    $this->externalConnectionManager = $externalConnectionManager;
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * Fixes nodes with uid=0 by checking their original author in Drupal 7.
   *
   * @param array $options
   *   The command options.
   *
   * @command labdoo:fix-node-uids
   * @aliases lfnuid
   * @option limit Maximum number of nodes to process.
   * @option batch-size Number of nodes to process per batch.
   * @option type Filter by node type.
   * @option default-uid UID to assign if the original user is not found in Drupal 10.
   * @option dry-run Only show what would be done, without making changes.
   * @usage drush labdoo:fix-node-uids --type=laptop --limit=100
   */
  public function fixNodeUids(array $options = ['limit' => -1, 'batch-size' => 1000, 'type' => NULL, 'default-uid' => NULL, 'dry-run' => FALSE]) {
    // Start with nodes that have uid=0 in node_field_data.
    // Process nodes that have uid=0 in node_field_data.
    $this->io()->title('Stage 1: Fixing nodes with uid=0 in node_field_data');
    $this->doFixNodeUids($options);

    // Process nodes that have uid=0 in node_field_revision but are already fixed in node_field_data.
    $this->io()->title('Stage 2: Fixing internal inconsistencies in node_field_revision');
    $this->fixRevisionInconsistencies($options);
  }

  /**
   * Internal logic to fix node UIDs.
   */
  protected function doFixNodeUids(array $options) {
    $query = $this->database->select('node_field_data', 'n')
      ->fields('n', ['nid', 'type'])
      ->condition('n.uid', 0);

    if ($options['type']) {
      $query->condition('n.type', $options['type']);
    }

    if ($options['limit'] != -1) {
      $query->range(0, $options['limit']);
    }

    $nodes = $query->execute()->fetchAll();

    if (empty($nodes)) {
      $this->io()->success('No nodes with uid=0 in node_field_data found.');
      return;
    }

    $total = count($nodes);
    $this->io()->note(sprintf('Found %d nodes with uid=0 in node_field_data', $total));

    try {
      $extConn = $this->externalConnectionManager->setConnection();
    }
    catch (\Exception $e) {
      $this->io()->error('Error connecting to Drupal 7 database: ' . $e->getMessage());
      return;
    }

    $fixed = 0;
    $alreadyAnonymous = 0;
    $userNotFound = 0;
    $nodeNotFoundInD7 = 0;
    $defaultAssigned = 0;

    $defaultUid = $options['default-uid'];
    if ($defaultUid) {
      $userExists = $this->database->select('users', 'u')
        ->fields('u', ['uid'])
        ->condition('uid', $defaultUid)
        ->execute()
        ->fetchField();
      if (!$userExists) {
        $this->io()->error(sprintf('Default user UID %d does not exist in Drupal 10.', $defaultUid));
        return;
      }
    }

    $batchSize = (int) $options['batch-size'];
    $chunks = array_chunk($nodes, $batchSize);
    $progressBar = $this->io()->createProgressBar($total);

    // Cache existing users in Drupal 10 to avoid repeated queries.
    $existingUsers = $this->database->select('users', 'u')
      ->fields('u', ['uid'])
      ->execute()
      ->fetchCol();
    $existingUsersMap = array_combine($existingUsers, $existingUsers);

    foreach ($chunks as $chunk) {
      $nids = array_map(fn($n) => $n->nid, $chunk);

      // Get original uids from Drupal 7 for this batch.
      $originalUids = $extConn->select('node', 'n')
        ->fields('n', ['nid', 'uid'])
        ->condition('nid', $nids, 'IN')
        ->condition('uid', 0, '<>')
        ->execute()
        ->fetchAllKeyed();

      $updates = [];

      foreach ($chunk as $node) {
        $nid = $node->nid;
        if (!isset($originalUids[$nid])) {
          $nodeNotFoundInD7++;
          $progressBar->advance();
          continue;
        }

        $originalUid = $originalUids[$nid];

        // Check if the user exists in Drupal 10 using the cache.
        if (isset($existingUsersMap[$originalUid])) {
          $updates[$originalUid][] = $nid;
          $fixed++;
        }
        elseif ($defaultUid) {
          $updates[$defaultUid][] = $nid;
          $defaultAssigned++;
        }
        else {
          $userNotFound++;
        }
        $progressBar->advance();
      }

      // Apply updates for this batch.
      if (!$options['dry-run'] && !empty($updates)) {
        $transaction = $this->database->startTransaction();
        try {
          foreach ($updates as $uid => $updateNids) {
            $this->database->update('node_field_data')
              ->fields(['uid' => $uid])
              ->condition('nid', $updateNids, 'IN')
              ->execute();

            $this->database->update('node_revision')
              ->fields(['revision_uid' => $uid])
              ->condition('nid', $updateNids, 'IN')
              ->execute();

            $this->database->update('node_field_revision')
              ->fields(['uid' => $uid])
              ->condition('nid', $updateNids, 'IN')
              ->execute();
          }
        }
        catch (\Exception $e) {
          $transaction->rollBack();
          $this->io()->error('Error updating nodes: ' . $e->getMessage());
          break;
        }
      }
    }

    $progressBar->finish();
    $this->io()->newLine();

    $this->io()->table(
      ['Status', 'Count'],
      [
        ['Fixed (Original user found)', $fixed],
        ['Assigned to default UID', $defaultAssigned],
        ['Not found or already anonymous in D7', $nodeNotFoundInD7],
        ['User not found in D10 (and no default)', $userNotFound],
      ]
    );

    $this->externalConnectionManager->restoreConnection();
  }

  /**
   * Fixes inconsistencies where node_field_data has UID but revisions don't.
   */
  protected function fixRevisionInconsistencies(array $options) {
    $query = $this->database->select('node_field_data', 'nfd');
    $query->join('node_field_revision', 'nfr', 'nfd.nid = nfr.nid AND nfd.vid = nfr.vid');
    $query->fields('nfd', ['nid', 'vid', 'uid'])
      ->condition('nfr.uid', 0)
      ->condition('nfd.uid', 0, '<>');

    if ($options['limit'] != -1) {
      $query->range(0, $options['limit']);
    }

    $results = $query->execute()->fetchAll();

    if (empty($results)) {
      $this->io()->success('No inconsistencies found between node_field_data and node_field_revision.');
      return;
    }

    $total = count($results);
    $this->io()->note(sprintf('Found %d nodes with inconsistent UIDs in revisions', $total));

    if ($options['dry-run']) {
      $this->io()->note('DRY RUN: No revision changes were made.');
      return;
    }

    $progressBar = $this->io()->createProgressBar($total);
    $batchSize = (int) $options['batch-size'];
    $chunks = array_chunk($results, $batchSize);

    foreach ($chunks as $chunk) {
      $transaction = $this->database->startTransaction();
      try {
        foreach ($chunk as $row) {
          $this->database->update('node_revision')
            ->fields(['revision_uid' => $row->uid])
            ->condition('nid', $row->nid)
            ->condition('vid', $row->vid)
            ->execute();

          $this->database->update('node_field_revision')
            ->fields(['uid' => $row->uid])
            ->condition('nid', $row->nid)
            ->condition('vid', $row->vid)
            ->execute();
          
          $progressBar->advance();
        }
      }
      catch (\Exception $e) {
        $transaction->rollBack();
        $this->io()->error('Error updating revisions: ' . $e->getMessage());
        break;
      }
    }

    $progressBar->finish();
    $this->io()->newLine();
    $this->io()->success(sprintf('Fixed %d revision inconsistencies.', $total));
  }

}
