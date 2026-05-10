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
   * @option type Filter by node type.
   * @option default-uid UID to assign if the original user is not found in Drupal 10.
   * @option dry-run Only show what would be done, without making changes.
   * @usage drush labdoo:fix-node-uids --type=laptop --limit=100
   */
  public function fixNodeUids(array $options = ['limit' => -1, 'type' => NULL, 'default-uid' => NULL, 'dry-run' => FALSE]) {
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
      $this->io()->success('No nodes with uid=0 found.');
      return;
    }

    $this->io()->title(sprintf('Found %d nodes with uid=0', count($nodes)));

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

    $progressBar = $this->io()->createProgressBar(count($nodes));

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

    foreach ($nodes as $node) {
      // Get original uid from Drupal 7.
      $originalUid = $extConn->select('node', 'n')
        ->fields('n', ['uid'])
        ->condition('nid', $node->nid)
        ->execute()
        ->fetchField();

      if ($originalUid === FALSE) {
        $nodeNotFoundInD7++;
      }
      elseif ($originalUid == 0) {
        $alreadyAnonymous++;
      }
      else {
        // Check if the user exists in Drupal 10.
        $userExists = $this->database->select('users', 'u')
          ->fields('u', ['uid'])
          ->condition('uid', $originalUid)
          ->execute()
          ->fetchField();

        if ($userExists) {
          if (!$options['dry-run']) {
            // Update the node.
            $this->database->update('node_field_data')
              ->fields(['uid' => $originalUid])
              ->condition('nid', $node->nid)
              ->execute();

            $this->database->update('node_revision')
              ->fields(['revision_uid' => $originalUid])
              ->condition('nid', $node->nid)
              ->execute();
          }
          $fixed++;
        }
        elseif ($defaultUid) {
          if (!$options['dry-run']) {
            $this->database->update('node_field_data')
              ->fields(['uid' => $defaultUid])
              ->condition('nid', $node->nid)
              ->execute();

            $this->database->update('node_revision')
              ->fields(['revision_uid' => $defaultUid])
              ->condition('nid', $node->nid)
              ->execute();
          }
          $defaultAssigned++;
        }
        else {
          $userNotFound++;
        }
      }
      $progressBar->advance();
    }

    $progressBar->finish();
    $this->io()->newLine();

    if ($options['dry-run']) {
      $this->io()->note('DRY RUN: No changes were made.');
    }

    $this->io()->table(
      ['Status', 'Count'],
      [
        ['Fixed (Original user found)', $fixed],
        ['Assigned to default UID', $defaultAssigned],
        ['Already anonymous in D7', $alreadyAnonymous],
        ['User not found in D10 (and no default)', $userNotFound],
        ['Node not found in D7', $nodeNotFoundInD7],
      ]
    );

    if ($fixed + $defaultAssigned > 0) {
      $this->io()->success(sprintf('Processed %d nodes.', $fixed + $defaultAssigned));
      $this->io()->note('Remember to rebuild the search index if necessary.');
    }
    else {
      $this->io()->warning('No nodes were updated.');
    }

    if ($userNotFound > 0) {
      $this->io()->info(sprintf('%d nodes could not be fixed because their original author does not exist in D10. Use --default-uid to assign them to a specific user.', $userNotFound));
    }

    $this->externalConnectionManager->restoreConnection();
  }

}
