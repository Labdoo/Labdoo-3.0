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
