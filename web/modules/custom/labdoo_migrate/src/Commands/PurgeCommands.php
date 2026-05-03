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

    // Get all UIDs at once or in a single large fetch since we are deleting one by one.
    // For very large datasets, we could still batch the fetch, but delete individually.
    $all_uids = $this->database->select('users', 'u')
      ->fields('u', ['uid'])
      ->condition('uid', [0, 1], 'NOT IN')
      ->execute()
      ->fetchCol();

    $progress = new ProgressBar($this->output(), count($all_uids));
    $progress->start();

    foreach ($all_uids as $uid) {
      $iteration_start_time = microtime(TRUE);

      $max_retries = 3;
      $retry_count = 0;
      $success = FALSE;

      while ($retry_count < $max_retries && !$success) {
        $transaction = $this->database->startTransaction();
        try {
          foreach ($tables as $table) {
            $column = $this->database->schema()->fieldExists($table, 'entity_id') ? 'entity_id' : 'uid';
            $this->database->delete($table)
              ->condition($column, $uid)
              ->execute();
          }

          // Handle path aliases.
          if ($this->database->schema()->tableExists('path_alias')) {
            $this->database->delete('path_alias')
              ->condition('path', '/user/' . $uid)
              ->execute();
          }

          $success = TRUE;
          $total_deleted++;
          $progress->advance();

          $iteration_time = microtime(TRUE) - $iteration_start_time;
          $total_elapsed = microtime(TRUE) - $start_time_total;

          $progress->setMessage(sprintf(
            ' Last: %d ms | Total: %s',
            round($iteration_time * 1000),
            $this->formatDuration($total_elapsed)
          ));

        } catch (\Exception $e) {
          if (isset($transaction)) {
            $transaction->rollBack();
          }
          if (strpos($e->getMessage(), '1205 Lock wait timeout exceeded') !== FALSE) {
            $retry_count++;
            sleep(1);
          } else {
            $this->logger()->error(sprintf('Error deleting user %d: %s', $uid, $e->getMessage()));
            break 2;
          }
        }
      }

      if (!$success) {
        $this->logger()->error(sprintf('Failed to delete user %d after max retries.', $uid));
        break;
      }
    }

    $progress->finish();
    $this->output()->writeln('');

    $this->output()->writeln("<info>Purge completed. $total_deleted users deleted.</info>");

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

    // Fetch all NIDs for this bundle.
    $all_nids = $this->database->select('node_field_data', 'nfd')
      ->fields('nfd', ['nid'])
      ->condition('type', $bundle)
      ->execute()
      ->fetchCol();

    $progress = new ProgressBar($this->output(), count($all_nids));
    $progress->start();

    foreach ($all_nids as $nid) {
      $iteration_start_time = microtime(TRUE);

      // Get vids for this nid to handle revisions.
      $vids = $this->database->select('node_revision', 'nr')
        ->fields('nr', ['vid'])
        ->condition('nid', $nid)
        ->execute()
        ->fetchCol();

      $max_retries = 3;
      $retry_count = 0;
      $success = FALSE;

      while ($retry_count < $max_retries && !$success) {
        $transaction = $this->database->startTransaction();
        try {
          foreach ($tables as $table) {
            if (strpos($table, 'revision') !== FALSE) {
              if (!empty($vids)) {
                $column = $this->database->schema()->fieldExists($table, 'revision_id') ? 'revision_id' : 'vid';
                $this->database->delete($table)
                  ->condition($column, $vids, 'IN')
                  ->execute();
              }
            } else {
              $column = $this->database->schema()->fieldExists($table, 'entity_id') ? 'entity_id' : 'nid';
              $this->database->delete($table)
                ->condition($column, $nid)
                ->execute();
            }
          }

          // Handle path aliases.
          if ($this->database->schema()->tableExists('path_alias')) {
            $this->database->delete('path_alias')
              ->condition('path', '/node/' . $nid)
              ->execute();
          }

          $success = TRUE;
          $total_deleted++;
          $progress->advance();

          $iteration_time = microtime(TRUE) - $iteration_start_time;
          $total_elapsed = microtime(TRUE) - $start_time_total;

          $progress->setMessage(sprintf(
            ' Last: %d ms | Total: %s',
            round($iteration_time * 1000),
            $this->formatDuration($total_elapsed)
          ));

        } catch (\Exception $e) {
          if (isset($transaction)) {
            $transaction->rollBack();
          }
          if (strpos($e->getMessage(), '1205 Lock wait timeout exceeded') !== FALSE) {
            $retry_count++;
            sleep(1);
          } else {
            $this->logger()->error(sprintf('Error deleting node %d: %s', $nid, $e->getMessage()));
            break 2;
          }
        }
      }

      if (!$success) {
        $this->logger()->error(sprintf('Failed to delete node %d after max retries.', $nid));
        break;
      }
    }

    $progress->finish();
    $this->output()->writeln('');

    $this->output()->writeln("<info>Purge completed. $total_deleted nodes deleted.</info>");

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
