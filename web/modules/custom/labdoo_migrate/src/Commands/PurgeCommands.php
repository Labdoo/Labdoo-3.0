<?php

namespace Drupal\labdoo_migrate\Commands;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drush\Commands\DrushCommands;
use Symfony\Component\Console\Helper\ProgressBar;
use Drush\Exceptions\UserAbortException;

/**
 * Commands for high-performance bundle purging via SQL.
 */
class PurgeCommands extends DrushCommands {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The cache tags invalidator.
   *
   * @var \Drupal\Core\Cache\CacheTagsInvalidatorInterface
   */
  protected $cacheTagsInvalidator;

  /**
   * PurgeCommands constructor.
   */
  public function __construct(Connection $database, EntityTypeManagerInterface $entityTypeManager, CacheTagsInvalidatorInterface $cacheTagsInvalidator) {
    parent::__construct();
    $this->database = $database;
    $this->entityTypeManager = $entityTypeManager;
    $this->cacheTagsInvalidator = $cacheTagsInvalidator;
  }

  /**
   * Purge all users using optimized SQL.
   *
   * @param array $options
   *   The command options.
   *
   * @command labdoo_migrate:purge-users
   * @option chunk The number of entities to process per batch.
   * @option dry-run Show what would be done without actually doing it.
   * @aliases lm-pu,purge-users
   */
  public function purgeUsers(array $options = ['chunk' => 1000, 'dry-run' => FALSE]) {
    $chunk_size = (int) $options['chunk'];
    $dry_run = $options['dry-run'];

    // Get total count (excluding uid 0 and 1).
    $count = $this->database->select('users', 'u')
      ->condition('uid', [0, 1], 'NOT IN')
      ->countQuery()
      ->execute()
      ->fetchField();

    if ($count == 0) {
      $this->logger()->notice('No users found to purge (uid 0 and 1 are protected).');
      return;
    }

    // Identify tables.
    $tables = $this->identifyUserTables();

    if ($dry_run) {
      $this->output()->writeln("<info>[DRY-RUN]</info> Summary of purge for <comment>users</comment>:");
      $this->output()->writeln(" - Total entities to delete: <comment>$count</comment>");
      $this->output()->writeln(" - Tables affected:");
      foreach ($tables as $table) {
        $this->output()->writeln("   * $table");
      }
      return;
    }

    $this->output()->writeln("<options=bold;fg=white;bg=red> WARNING: This command performs direct SQL deletions. </>");
    $this->output()->writeln("<fg=yellow>It is intended ONLY for discarded migrated data. It bypasses Entity API (hooks, search indexes, etc.).</>");
    $this->output()->writeln("<fg=cyan>Recommendation: Create a database backup/snapshot before proceeding.</>");

    if (!$this->io()->confirm(sprintf('Are you sure you want to purge %d users?', $count), FALSE)) {
      throw new UserAbortException();
    }

    $total_deleted = 0;
    $start_time_total = microtime(TRUE);

    $transaction = $this->database->startTransaction();
    try {
      // Get UIDs to delete for alias cleanup.
      $uids_to_delete = $this->database->select('users', 'u')
        ->fields('u', ['uid'])
        ->condition('uid', [0, 1], 'NOT IN')
        ->execute()
        ->fetchCol();

      foreach ($tables as $table) {
        $column = $this->database->schema()->fieldExists($table, 'entity_id') ? 'entity_id' : 'uid';
        $query = $this->database->delete($table);
        if ($table === 'users' || $table === 'users_field_data' || $table === 'users_data') {
          $query->condition('uid', [0, 1], 'NOT IN');
        }
        else {
          $query->condition($column, [0, 1], 'NOT IN');
        }
        $query->execute();
      }

      // Handle path aliases in bulk.
      if (!empty($uids_to_delete) && $this->database->schema()->tableExists('path_alias')) {
        $paths = array_map(function($uid) {
          return '/user/' . $uid;
        }, $uids_to_delete);
        
        // Chunk path alias deletion if there are many.
        foreach (array_chunk($paths, 1000) as $path_chunk) {
          $this->database->delete('path_alias')
            ->condition('path', $path_chunk, 'IN')
            ->execute();
        }
      }

      $total_deleted = $count;
    }
    catch (\Exception $e) {
      if (isset($transaction)) {
        $transaction->rollBack();
      }
      $this->logger()->error(sprintf('Error during bulk user purge: %s', $e->getMessage()));
      return;
    }

    $total_elapsed = microtime(TRUE) - $start_time_total;
    $this->output()->writeln(sprintf("<info>Purge completed in %s. %d users deleted.</info>", $this->formatDuration($total_elapsed), $total_deleted));

    $this->output()->writeln("<info>Invalidating cache tags for user_list...</info>");
    $this->cacheTagsInvalidator->invalidateTags(['user_list']);

    if ($this->io()->confirm('Would you like to run "drush cr" now?', TRUE)) {
      \Drush\Drush::drush(\Drush\Drush::aliasManager()->getSelf(), 'cache-rebuild')->run();
    }
  }

  /**
   * Purge all entities of a specific bundle using optimized SQL.
   *
   * @param string $entity_type
   *   The entity type (only 'node' is supported for now).
   * @param string $bundle
   *   The bundle to purge.
   * @param array $options
   *   The command options.
   *
   * @command labdoo_migrate:purge-bundle
   * @param-usage node action
   * @option chunk The number of entities to process per batch.
   * @option dry-run Show what would be done without actually doing it.
   * @aliases lm-pb,purge-bundle
   */
  public function purgeBundle(string $entity_type, string $bundle, array $options = ['chunk' => 1000, 'dry-run' => FALSE]) {
    if ($entity_type !== 'node') {
      throw new \InvalidArgumentException('Currently only "node" entity type is supported.');
    }

    // Verify bundle exists.
    $bundles = \Drupal::service('entity_type.bundle.info')->getBundleInfo($entity_type);
    if (!isset($bundles[$bundle])) {
      throw new \InvalidArgumentException(sprintf('Bundle "%s" does not exist for entity type "%s".', $bundle, $entity_type));
    }

    $chunk_size = (int) $options['chunk'];
    $dry_run = $options['dry-run'];

    // Get total count.
    $count = $this->database->select('node_field_data', 'nfd')
      ->condition('type', $bundle)
      ->countQuery()
      ->execute()
      ->fetchField();

    if ($count == 0) {
      $this->logger()->notice(sprintf('No nodes found for bundle "%s".', $bundle));
      return;
    }

    // Identify tables.
    $tables = $this->identifyTables($entity_type);

    if ($dry_run) {
      $this->output()->writeln("<info>[DRY-RUN]</info> Summary of purge for <comment>$entity_type:$bundle</comment>:");
      $this->output()->writeln(" - Total entities to delete: <comment>$count</comment>");
      $this->output()->writeln(" - Tables affected:");
      foreach ($tables as $table) {
        $this->output()->writeln("   * $table");
      }
      return;
    }

    $this->output()->writeln("<options=bold;fg=white;bg=red> WARNING: This command performs direct SQL deletions. </>");
    $this->output()->writeln("<fg=yellow>It is intended ONLY for discarded migrated data. It bypasses Entity API (hooks, search indexes, etc.).</>");
    $this->output()->writeln("<fg=cyan>Recommendation: Create a database backup/snapshot before proceeding.</>");

    if (!$this->io()->confirm(sprintf('Are you sure you want to purge %d nodes of type "%s"?', $count, $bundle), FALSE)) {
      throw new UserAbortException();
    }

    $total_deleted = 0;
    $start_time_total = microtime(TRUE);

    $transaction = $this->database->startTransaction();
    try {
      // Fetch all NIDs for this bundle to handle path_alias cleanup.
      $nids_to_delete = $this->database->select('node_field_data', 'nfd')
        ->fields('nfd', ['nid'])
        ->condition('type', $bundle)
        ->execute()
        ->fetchCol();

      if (empty($nids_to_delete)) {
        $this->logger()->notice(sprintf('No nodes found for bundle "%s".', $bundle));
        return;
      }

      // Identify VIDs for revisions.
      $vids_to_delete = $this->database->select('node_revision', 'nr')
        ->fields('nr', ['vid'])
        ->condition('nid', $nids_to_delete, 'IN')
        ->execute()
        ->fetchCol();

      foreach ($tables as $table) {
        if (strpos($table, 'revision') !== FALSE) {
          if (!empty($vids_to_delete)) {
            $column = $this->database->schema()->fieldExists($table, 'revision_id') ? 'revision_id' : 'vid';
            $this->database->delete($table)
              ->condition($column, $vids_to_delete, 'IN')
              ->execute();
          }
        }
        else {
          $column = $this->database->schema()->fieldExists($table, 'entity_id') ? 'entity_id' : 'nid';
          
          // For base tables, we can filter by bundle directly if the column exists.
          if ($table === 'node_field_data' || $table === 'node_field_revision') {
            $this->database->delete($table)
              ->condition('type', $bundle)
              ->execute();
          }
          elseif ($table === 'node' || $table === 'node_access') {
             $this->database->delete($table)
               ->condition('nid', $nids_to_delete, 'IN')
               ->execute();
          }
          else {
            // Field tables.
            $this->database->delete($table)
              ->condition($column, $nids_to_delete, 'IN')
              ->execute();
          }
        }
      }

      // Handle path aliases in bulk.
      if ($this->database->schema()->tableExists('path_alias')) {
        $paths = array_map(function($nid) {
          return '/node/' . $nid;
        }, $nids_to_delete);
        
        foreach (array_chunk($paths, 1000) as $path_chunk) {
          $this->database->delete('path_alias')
            ->condition('path', $path_chunk, 'IN')
            ->execute();
        }
      }

      $total_deleted = count($nids_to_delete);
    }
    catch (\Exception $e) {
      if (isset($transaction)) {
        $transaction->rollBack();
      }
      $this->logger()->error(sprintf('Error during bulk bundle purge (%s): %s', $bundle, $e->getMessage()));
      return;
    }

    $total_elapsed = microtime(TRUE) - $start_time_total;
    $this->output()->writeln(sprintf("<info>Purge completed in %s. %d nodes deleted.</info>", $this->formatDuration($total_elapsed), $total_deleted));

    $this->output()->writeln("<info>Invalidating cache tags for node_list and node_list:$bundle...</info>");
    $this->cacheTagsInvalidator->invalidateTags(['node_list', 'node_list:' . $bundle]);

    if ($this->io()->confirm('Would you like to run "drush cr" now?', TRUE)) {
      \Drush\Drush::drush(\Drush\Drush::aliasManager()->getSelf(), 'cache-rebuild')->run();
    }
  }

  /**
   * Identify all tables related to users.
   */
  protected function identifyUserTables(): array {
    $tables = [
      'users',
      'users_field_data',
      'users_data',
    ];

    // Find all field tables.
    $field_tables = $this->database->query("SHOW TABLES LIKE 'user__%'")->fetchCol();
    $tables = array_merge($tables, $field_tables);

    // Filter out tables that don't exist and remove duplicates.
    $tables = array_filter($tables, function($table) {
      return $this->database->schema()->tableExists($table);
    });

    return array_unique($tables);
  }

  /**
   * Identify all tables related to the entity type.
   */
  protected function identifyTables(string $entity_type): array {
    $tables = [
      'node',
      'node_field_data',
      'node_revision',
      'node_field_revision',
      'node_access',
    ];

    // Find all field tables.
    $field_tables = $this->database->query("SHOW TABLES LIKE 'node__%'")->fetchCol();
    $tables = array_merge($tables, $field_tables);
    
    $revision_field_tables = $this->database->query("SHOW TABLES LIKE 'node_revision__%'")->fetchCol();
    $tables = array_merge($tables, $revision_field_tables);

    // Filter out tables that don't exist and remove duplicates.
    $tables = array_filter($tables, function($table) {
      return $this->database->schema()->tableExists($table);
    });

    return array_unique($tables);
  }

  /**
   * Formats duration in seconds to human readable string.
   */
  protected function formatDuration(float $seconds): string {
    if ($seconds < 60) {
      return round($seconds, 2) . 's';
    }
    return gmdate("H:i:s", (int)$seconds);
  }

}
