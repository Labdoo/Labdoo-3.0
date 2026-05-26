<?php

namespace Drupal\labdoo_migrate\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\labdoo_migrate\Services\Config\ConfigurationManagerInterface;
use Drupal\labdoo_migrate\Services\Database\ConnectionManagerInterface;
use Drupal\labdoo_migrate\Services\Mapper\MapperInterface;
use Drupal\labdoo_migrate\Services\SourceContent\RevisionSourceRepositoryInterface;
use Drupal\labdoo_migrate\Services\SourceContent\SourceRepositoryInterface;
use Drupal\labdoo_migrate\Traits\NodeRevisionSyncTrait;
use Drupal\labdoo_migrate\Traits\TextFormatMapperTrait;
use Drush\Commands\DrushCommands;
use Symfony\Component\Console\Helper\ProgressBar;

/**
 * Commands for synchronizing node revisions across all content types.
 */
class NodeRevisionSynchronizerCommands extends DrushCommands {

  use TextFormatMapperTrait;
  use NodeRevisionSyncTrait;

  /**
   * The nids to process.
   *
   * @var array|null
   */
  protected ?array $nids = NULL;

  /**
   * The limit.
   *
   * @var int
   */
  protected int $limit = -1;

  /**
   * The dry-run mode.
   *
   * @var bool
   */
  protected bool $dryRun = FALSE;

  /**
   * The incremental mode.
   *
   * @var bool
   */
  protected bool $incremental = FALSE;

  /**
   * The delete revisions mode.
   *
   * @var bool
   */
  protected bool $deleteRevisions = FALSE;

  /**
   * The progress bar.
   *
   * @var \Symfony\Component\Console\Helper\ProgressBar
   */
  protected $progressBar;

  /**
   * Constructor.
   */
  public function __construct(
    protected ConnectionManagerInterface $externalConnectionManager,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected RevisionSourceRepositoryInterface $revisionSourceRepository,
    protected ConfigurationManagerInterface $configurationManager,
    protected MapperInterface $mapper,
    protected SourceRepositoryInterface $sourceRepository,
    protected LanguageManagerInterface $languageManager
  ) {
    parent::__construct();
  }

  /**
   * Synchronizes node revisions for a specific content type.
   *
   * @param string $type
   *   The Drupal 7 content type.
   * @param array $options
   *   Command options.
   *
   * @command labdoo-synchronize-revisions
   * @aliases labdoo-sync-revisions
   * @usage labdoo-synchronize-revisions page
   *   Synchronizes the revisions of the type "page" (basic page).
   * @usage labdoo-synchronize-revisions story --body-field=field_story_text
   *   Synchronizes the revisions of the type "story" using a custom body field.
   *
   * @option nids List of Drupal 7 IDs to synchronize.
   * @option limit Limits the execution to the given elements.
   * @option dry-run Whether to run this command in dry-run mode.
   * @option body-field The field name in D7 that contains the body content (default: "body").
   * @option destination-type The content type in Drupal 10 (defaults to the same as "type").
   * @option incremental Whether to run this command in incremental mode (only if source and destination revision counts differ).
   * @option delete-revisions Whether to delete all existing revisions before synchronization.
   */
  public function startSync(
    string $type,
    array $options = [
      'nids' => NULL,
      'limit' => -1,
      'dry-run' => FALSE,
      'incremental' => FALSE,
      'body-field' => 'body',
      'destination-type' => NULL,
      'delete-revisions' => FALSE,
    ]
  ): void {
    try {
      $this->setEnvironment($options);
      $bodyField = $options['body-field'];

      if ($this->deleteRevisions && !$this->dryRun) {
        $destinationType = $options['destination-type'] ?: $type;
        $this->logger->notice(sprintf('Deleting all existing revisions for type "%s" (destination: "%s")...', $type, $destinationType));

        $database = \Drupal::database();
        // Get all nids for this type.
        $nids = $this->entityTypeManager->getStorage('node')->getQuery()
          ->condition('type', $destinationType)
          ->accessCheck(FALSE)
          ->execute();

        if (!empty($nids)) {
          // Normalize NIDs to strings to ensure array_intersect works correctly.
          $nids = array_map('strval', array_values($nids));

          if ($this->nids !== NULL) {
            // Normalize input NIDs as well.
            $input_nids = array_map('strval', $this->nids);
            // If we have specific nids, only delete revisions for those.
            $nids_to_purge = array_intersect($nids, $input_nids);
          }
          else {
            $nids_to_purge = $nids;
          }

          if (empty($nids_to_purge)) {
            $this->logger->notice('No nodes found to purge revisions.');
          }
          else {
            // Identify revision tables.
            $tables = [
              'node_revision',
              'node_field_revision',
            ];
            // Find all field revision tables.
            $field_revision_tables = $database->query("SHOW TABLES LIKE 'node_revision__%'")->fetchCol();
            $tables = array_merge($tables, $field_revision_tables);

            // Pre-calculate the VIDs to keep (current default revisions).
            $keep_vids = $database->select('node_field_data', 'nfd')
              ->fields('nfd', ['vid'])
              ->condition('type', $destinationType);
            if ($this->nids !== NULL) {
              $keep_vids->condition('nid', $nids_to_purge, 'IN');
            }
            $keep_vids_list = array_map('intval', $keep_vids->execute()->fetchCol());

            foreach ($tables as $table) {
              if ($database->schema()->tableExists($table)) {
                $column = $database->schema()->fieldExists($table, 'revision_id') ? 'revision_id' : 'vid';
                $nid_col = $database->schema()->fieldExists($table, 'entity_id') ? 'entity_id' : 'nid';

                // We want to delete all revisions EXCEPT the ones currently marked as default in node_field_data.
                $query = $database->delete($table);

                if ($this->nids !== NULL) {
                  $query->condition($nid_col, $nids_to_purge, 'IN');
                }
                else {
                  // If we don't have specific NIDs, we MUST filter by NIDs of this type.
                  $query->condition($nid_col, $nids, 'IN');
                }

                if (!empty($keep_vids_list)) {
                  // IMPORTANT: Chunk the deletion if the list is too large to avoid SQL limits or performance hits.
                  // But here we use it in a NOT IN, so it's better to stay within limits.
                  $query->condition($column, $keep_vids_list, 'NOT IN');
                }

                $num_deleted = $query->execute();
                if ($num_deleted > 0) {
                  $this->logger->info(sprintf('Deleted %d rows from %s', $num_deleted, $table));
                }
              }
            }
          }
        }
      }

      $this->logger->notice(sprintf('Retrieving source entities IDs for type "%s"...', $type));

      if ($this->incremental && $this->nids === NULL) {
        $this->logger->notice('Incremental mode: pre-calculating nodes with different revision counts...');
        $sourceCounts = $this->revisionSourceRepository->getRevisionCountsByType($type);

        $destinationType = $options['destination-type'] ?: $type;
        $destinationCountsQuery = $this->entityTypeManager->getStorage('node')->getAggregateQuery();
        $destinationCountsQuery->accessCheck(FALSE);
        $destinationCountsQuery->condition('type', $destinationType);
        $destinationCountsQuery->groupBy('nid');
        $destinationCountsQuery->aggregate('vid', 'COUNT');
        $destResults = $destinationCountsQuery->execute();

        $destinationCounts = [];
        foreach ($destResults as $result) {
          $destinationCounts[$result['nid']] = (int) $result['vid_count'];
        }

        $filteredNids = [];
        foreach ($sourceCounts as $nid => $count) {
          if (!isset($destinationCounts[$nid]) || $destinationCounts[$nid] !== (int) $count) {
            $filteredNids[] = $nid;
          }
        }

        if (empty($filteredNids)) {
          $this->logger->success('All nodes have the same number of revisions. Nothing to synchronize.');
          return;
        }

        $this->logger->notice(sprintf('Found %d nodes that need revision synchronization.', count($filteredNids)));
        $nodes = array_map(function($nid) { return (object)['nid' => $nid]; }, $filteredNids);

        if ($this->limit > -1) {
          $nodes = array_slice($nodes, 0, $this->limit);
        }
      }
      else {
        $query = $this->externalConnectionManager
          ->setConnection()
          ->select('node', 'n')
          ->fields('n', ['nid'])
          ->condition('type', $type)
          ->condition(
            $this->externalConnectionManager->setConnection()->condition('OR')
              ->condition('tnid', 0)
              ->where('nid = tnid')
          );

        if ($this->nids !== NULL) {
          $query->condition('nid', $this->nids, 'IN');
        }
        if ($this->limit > -1) {
          $query->range(0, $this->limit);
        }
        $nodes = $query->execute()->fetchAll();
        $this->externalConnectionManager->restoreConnection();
      }

      $total = count($nodes);

      $this->initProgressBar($total, sprintf('Processing revisions for %s', $type));

      foreach ($nodes as $node) {
        $this->syncNodeRevisions($node->nid, $type, $bodyField);
        $this->advanceProgressBar();
      }

      $this->logger->success(sprintf('Finished synchronizing revisions for type "%s".', $type));
    }
    catch (\Exception $e) {
      $this->logger->error($e->getMessage());
    }
    finally {
      \Drupal::state()->delete('labdoo_migrate_is_running');
    }
  }

  /**
   * Sets the environment.
   */
  protected function setEnvironment(array $options): void {
    \Drupal::state()->set('labdoo_migrate_is_running', TRUE);
    if ($options['nids'] !== NULL) {
      $this->nids = explode(',', $options['nids']);
    }
    $this->limit = (int) $options['limit'];
    $this->dryRun = (bool) $options['dry-run'];
    $this->incremental = (bool) $options['incremental'];
    $this->deleteRevisions = (bool) $options['delete-revisions'];
  }

  /**
   * Initializes the progress bar.
   */
  protected function initProgressBar(int $count, string $message): void {
    $this->output()->writeln($message);
    $this->progressBar = new ProgressBar($this->output(), $count);
    $this->progressBar->start();
  }

  /**
   * Advances the progress bar.
   */
  protected function advanceProgressBar(): void {
    $this->progressBar->advance();
  }

}
