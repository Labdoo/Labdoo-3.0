<?php

namespace Drupal\labdoo_migrate\Commands;

use Drupal\Core\Database\Connection;
use Drupal\labdoo_migrate\Services\Database\ConnectionManagerInterface;
use Drush\Commands\DrushCommands;

/**
 * Cleanup commands for Labdoo migration.
 */
class CleanupCommands extends DrushCommands {

  /**
   * The Drupal 10 database connection.
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
   * CleanupCommands constructor.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\labdoo_migrate\Services\Database\ConnectionManagerInterface $externalConnectionManager
   *   The external database manager.
   */
  public function __construct(Connection $database, ConnectionManagerInterface $externalConnectionManager) {
    parent::__construct();
    $this->database = $database;
    $this->externalConnectionManager = $externalConnectionManager;
  }

  /**
   * Cleans orphan records in labdoo_migrate_tracking table.
   *
   * @command labdoo:migrate-clean-orphans
   * @aliases lmco
   * @usage drush labdoo:migrate-clean-orphans
   *   Verifies all tracking records against Drupal 7 and removes orphans.
   */
  public function cleanOrphans() {
    try {
      $extConn = $this->externalConnectionManager->setConnection();
    }
    catch (\Exception $e) {
      $this->io()->error('Error connecting to Drupal 7 database: ' . $e->getMessage());
      return;
    }

    $this->io()->title('Starting cleanup of orphan tracking records');

    // 1. Clean Node orphans
    $this->io()->section('Checking for node orphans');
    $this->doCleanOrphans($extConn, 'node', 'node', 'nid');

    // 2. Clean User orphans
    $this->io()->section('Checking for user orphans');
    $this->doCleanOrphans($extConn, 'user', 'users', 'uid');

    $this->externalConnectionManager->restoreConnection();
    $this->io()->success('Cleanup finished.');
  }

  /**
   * Removes orphan tracking records for a specific entity type.
   */
  protected function doCleanOrphans(Connection $extConn, string $entityType, string $sourceTable, string $sourceIdField) {
    $tracking_ids = $this->database->select('labdoo_migrate_tracking', 't')
      ->fields('t', ['source_id'])
      ->condition('entity_type', $entityType)
      ->execute()
      ->fetchCol();

    if (empty($tracking_ids)) {
      $this->io()->note("No tracking records found for $entityType.");
      return;
    }

    $this->io()->text("Found " . count($tracking_ids) . " total tracking records for $entityType. Verifying against D7...");

    $chunked = array_chunk($tracking_ids, 500);
    $orphans = [];

    foreach ($chunked as $chunk) {
      $existing = $extConn->select($sourceTable, 's')
        ->fields('s', [$sourceIdField])
        ->condition($sourceIdField, $chunk, 'IN')
        ->execute()
        ->fetchCol();

      $chunk_orphans = array_diff($chunk, $existing);
      if (!empty($chunk_orphans)) {
        $orphans = array_merge($orphans, $chunk_orphans);
      }
    }

    if (count($orphans) > 0) {
      $this->io()->warning("Found " . count($orphans) . " orphans for $entityType. Deleting...");
      $num = $this->database->delete('labdoo_migrate_tracking')
        ->condition('entity_type', $entityType)
        ->condition('source_id', $orphans, 'IN')
        ->execute();
      $this->io()->success("Deleted $num orphan records for $entityType.");
    }
    else {
      $this->io()->note("No orphans found for $entityType.");
    }
  }

}
