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
   * @usage drush labdoo:fix-node-uids --type=laptop --limit=100
   */
  public function fixNodeUids(array $options = ['limit' => -1, 'type' => NULL]) {
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
    $progressBar = $this->io()->createProgressBar(count($nodes));

    foreach ($nodes as $node) {
      // Get original uid from Drupal 7.
      $originalUid = $extConn->select('node', 'n')
        ->fields('n', ['uid'])
        ->condition('nid', $node->nid)
        ->execute()
        ->fetchField();

      if ($originalUid && $originalUid != 0) {
        // Check if the user exists in Drupal 10.
        $userExists = $this->database->select('users', 'u')
          ->fields('u', ['uid'])
          ->condition('uid', $originalUid)
          ->execute()
          ->fetchField();

        if ($userExists) {
          // Update the node.
          $this->database->update('node_field_data')
            ->fields(['uid' => $originalUid])
            ->condition('nid', $node->nid)
            ->execute();
          
          $this->database->update('node_revision')
            ->fields(['revision_uid' => $originalUid])
            ->condition('nid', $node->nid)
            ->execute();

          $fixed++;
        }
      }
      $progressBar->advance();
    }

    $progressBar->finish();
    $this->io()->newLine();
    $this->io()->success(sprintf('Fixed %d nodes.', $fixed));
    
    if ($fixed > 0) {
      $this->io()->note('Remember to rebuild the search index if necessary.');
    }

    $this->externalConnectionManager->restoreConnection();
  }

}
