<?php

namespace Drupal\labdoo_migrate\Commands;

use Drupal\labdoo_migrate\Model\ContentConfigurationModel;
use Drupal\labdoo_migrate\Services\Config\ConfigurationManagerInterface;
use Drupal\labdoo_migrate\Services\DestinationContent\DestinationRepositoryInterface;
use Drupal\labdoo_migrate\Services\Mapper\MapperInterface;
use Drupal\labdoo_migrate\Services\Tracking\MigrationTrackerInterface;
use Drupal\queue_manager\Model\QueueDataModel;
use Drupal\queue_manager\Service\QueueHelper;
use Drush\Commands\DrushCommands;

/**
 * Content synchronization commands.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class SynchronizerCommands extends DrushCommands {

  /**
   * Batch size for source entity loading/processing.
   */
  private const SYNC_BATCH_SIZE = 200;

  /**
   * The configuration manager.
   *
   * @var \Drupal\labdoo_migrate\Services\Config\ConfigurationManagerInterface
   */
  private ConfigurationManagerInterface $configurationManager;

  /**
   * The destination repository.
   *
   * @var \Drupal\labdoo_migrate\Services\DestinationContent\DestinationRepositoryInterface
   */
  private DestinationRepositoryInterface $destinationRepository;

  /**
   * The source repository.
   *
   * @var \Drupal\labdoo_migrate\Services\SourceContent\SourceRepositoryInterface
   */
  private SourceRepositoryInterface $sourceRepository;

  /**
   * The mapper.
   *
   * @var \Drupal\labdoo_migrate\Services\Mapper\MapperInterface
   */
  private MapperInterface $mapper;

  /**
   * The migration tracker.
   *
   * @var \Drupal\labdoo_migrate\Services\Tracking\MigrationTrackerInterface
   */
  private MigrationTrackerInterface $migrationTracker;

  /**
   * The queue helper.
   *
   * @var \Drupal\queue_manager\Service\QueueHelper
   */
  private QueueHelper $queueHelper;

  /**
   * The start time.
   *
   * @var mixed
   */
  private $startTime;

  /**
   * The configuration data.
   *
   * @var \Drupal\labdoo_migrate\Model\ContentConfigurationModel
   */
  private ContentConfigurationModel $configData;

  /**
   * The mapping.
   *
   * @var array
   */
  private array $mapping;

  /**
   * The content type.
   *
   * @var string
   */
  private string $contentType;

  /**
   * The nids.
   *
   * @var mixed
   */
  private $nids;

  /**
   * The limit.
   *
   * @var mixed
   */
  private $limit;

  /**
   * The running mode.
   *
   * @var bool
   */
  private bool $create;

  /**
   * The dry-run mode.
   *
   * @var mixed
   */
  private $dryRun;

  /**
   * The override mode.
   *
   * @var mixed
   */
  private $overrideMode;

  /**
   * Incremental mode.
   *
   * @var bool
   */
  private bool $incremental = FALSE;

  /**
   * Optional UNIX timestamp filter for source nodes.
   *
   * @var int|null
   */
  private ?int $fromTimestamp = NULL;

  /**
   * SynchronizerCommands constructor.
   *
   * @param \Drupal\labdoo_migrate\Services\Config\ConfigurationManagerInterface $configurationManager
   *   The migration configuration manager.
   * @param \Drupal\labdoo_migrate\Services\Mapper\MapperInterface $mapper
   *   The content mapper.
   * @param \Drupal\labdoo_migrate\Services\Tracking\MigrationTrackerInterface $migrationTracker
   *   The migration tracker.
   * @param \Drupal\queue_manager\Service\QueueHelper $queueHelper
   *   The queue helper.
   */
  public function __construct(
    ConfigurationManagerInterface $configurationManager,
    MapperInterface $mapper,
    MigrationTrackerInterface $migrationTracker,
    QueueHelper $queueHelper
  ) {

    parent::__construct();
    $this->configurationManager = $configurationManager;
    $this->mapper = $mapper;
    $this->migrationTracker = $migrationTracker;
    $this->queueHelper = $queueHelper;
  }

  /**
   * Synchronizes content taking a Drupal 7 instance as a source.
   *
   * @param string $contentType
   *   The content type to synchronize.
   * @param array $options
   *   Command options.
   *
   * @command labdoo-synchronize-content content-type [nids=123,456,789] [limit=9] [mode=create|update] [override] [dry-run] [from-date="YYYY-MM-DD HH:MM:SS"] [incremental]
   * @aliases labdoo-sync
   * @usage labdoo-synchronize-content edoovillage
   *   Synchronizes the contents of the type "edoovillage".
   *
   * @option nids List of Drupal 9 IDs to synchronize.
   * @option limit Limits the execution to the given elements.
   * @option mode Defines if the entities must be created or updated (valid values: not defined, "create", "update").
   * @option override Whether to override the nodes or fail gracefully. Specify this parameter to activate the override mode.
   * @option dry-run Whether to run this command in dry-run mode. Specify this parameter to activate the dry-run mode.
   * @option from-date Date/time lower bound to filter source nodes by created/updated (format: "YYYY-MM-DD HH:MM:SS").
   * @option incremental Migrates only those entities that are in Drupal 7 but not in Drupal 10.
   * @option queue Whether to queue the items instead of processing them directly.
   */
  public function startSync(
    string $contentType,
    array $options = [
      'nids' => NULL,
      'limit' => -1,
      'mode' => 'create',
      'override' => FALSE,
      'dry-run' => FALSE,
      'from-date' => NULL,
      'incremental' => FALSE,
      'queue' => FALSE,
    ]
  ): void {
    try {
      $this->setEnvironment($contentType, $options);

      if ($this->create) {
        $this->processCreate($contentType, $options);
      }
      else {
        $this->processUpdate($contentType, $options);
      }

      $this->tearDown(
        $this->destinationRepository->getProcessedEntitiesSummary()['main'] ?? 0,
        $this->destinationRepository->getProcessedEntitiesSummary()
      );
    }
    catch (\Exception $e) {
      $this->logger->error($e->getMessage());
    }
  }

  /**
   * Processes the creation mode.
   *
   * @param string $contentType
   *   The content type.
   * @param array $options
   *   The command options.
   *
   * @throws \Exception
   */
  protected function processCreate(string $contentType, array $options): void {
    $sourceEntitiesIds = $this->getSourceEntitiesIds($contentType);

    if ($this->incremental) {
      $sourceEntitiesIds = $this->applyIncrementalFilter($sourceEntitiesIds);
    }

    if ($this->limit > -1) {
      $sourceEntitiesIds = array_slice($sourceEntitiesIds, 0, $this->limit);
    }

    $total = count($sourceEntitiesIds);
    $this->logger->notice(sprintf('%d source entities found.', $total));
    $this->logger->notice('Creating the destination entities...');

    $destinationTypes = $this->configData->getDestinationTypes();
    $destinationContentType = reset($destinationTypes);
    $this->destinationRepository->setOverrideMode($this->overrideMode);
    $this->destinationRepository->setTotalCount($total);
    $this->destinationRepository->setIndexingMode(FALSE);

    foreach (array_chunk($sourceEntitiesIds, self::SYNC_BATCH_SIZE) as $sourceIdsChunk) {
      if ($options['queue']) {
        $this->enqueueItems($contentType, $sourceIdsChunk, 'create', $destinationContentType);
        continue;
      }

      $this->processCreateChunk($contentType, $sourceIdsChunk, $destinationContentType);
    }
  }

  /**
   * Processes the update mode.
   *
   * @param string $contentType
   *   The content type.
   * @param array $options
   *   The command options.
   *
   * @throws \Exception
   */
  protected function processUpdate(string $contentType, array $options): void {
    $destinationEntities = $this->getDestinationEntities();
    $sourceEntitiesIds = array_keys($destinationEntities);

    if ($this->limit > -1) {
      $sourceEntitiesIds = array_slice($sourceEntitiesIds, 0, $this->limit);
    }

    $total = count($sourceEntitiesIds);
    $this->logger->notice(sprintf('%d source entities found.', $total));
    $this->logger->notice('Updating the destination entities...');
    $this->destinationRepository->setTotalCount($total);
    $this->destinationRepository->setIndexingMode(FALSE);

    $destinationTypes = $this->configData->getDestinationTypes();
    $destinationContentType = reset($destinationTypes);

    foreach (array_chunk($sourceEntitiesIds, self::SYNC_BATCH_SIZE) as $sourceIdsChunk) {
      if ($options['queue']) {
        $this->enqueueItems($contentType, $sourceIdsChunk, 'update', $destinationContentType, $destinationEntities);
        continue;
      }

      $this->processUpdateChunk($contentType, $sourceIdsChunk, $destinationContentType, $destinationEntities);
    }
  }

  /**
   * Gets the source entities IDs.
   *
   * @param string $contentType
   *   The content type.
   *
   * @return array
   *   The source entities IDs.
   *
   * @throws \Exception
   */
  protected function getSourceEntitiesIds(string $contentType): array {
    $sourceEntitiesIds = $this->nids;
    if (empty($sourceEntitiesIds)) {
      $sourceEntitiesIds = $this->sourceRepository->getNodesByType(
        $contentType,
        $this->mapping,
        $this->fromTimestamp
      );
    }

    return $sourceEntitiesIds;
  }

  /**
   * Applies the incremental filter to source IDs.
   *
   * @param array $sourceEntitiesIds
   *   The source entities IDs.
   *
   * @return array
   *   The filtered IDs.
   */
  protected function applyIncrementalFilter(array $sourceEntitiesIds): array {
    $destinationTypes = $this->configData->getDestinationTypes();
    $destinationContentType = reset($destinationTypes);
    $migratedSourceIds = $this->migrationTracker->getMigratedSourceIds(
      $this->configData->getEntityType(),
      $destinationContentType
    );

    return array_diff($sourceEntitiesIds, $migratedSourceIds);
  }

  /**
   * Enqueues items for processing.
   *
   * @param string $contentType
   *   The source content type.
   * @param array $sourceIdsChunk
   *   The chunk of source IDs.
   * @param string $mode
   *   The mode (create|update).
   * @param string $destinationContentType
   *   The destination content type.
   * @param array $destinationEntities
   *   Optional destination entities mapping for updates.
   */
  protected function enqueueItems(
    string $contentType,
    array $sourceIdsChunk,
    string $mode,
    string $destinationContentType,
    array $destinationEntities = []
  ): void {
    $queueId = sprintf('labdoo_migrate_migration_%s', $contentType);
    foreach ($sourceIdsChunk as $sourceId) {
      $queueDataModel = new QueueDataModel();
      $queueDataModel->setQueueId($queueId);
      $data = [
        'content_type' => $contentType,
        'entity_id' => $sourceId,
        'mapping' => $this->mapping,
        'dry_run' => $this->dryRun,
        'mode' => $mode,
        'override' => $this->overrideMode,
        'destination_content_type' => $destinationContentType,
      ];

      if ($mode === 'update' && isset($destinationEntities[$sourceId])) {
        $data['destination_entity_id'] = $destinationEntities[$sourceId];
      }

      $queueDataModel->setData($data);
      $queueDataModel->setTimestamp(new \DateTime());
      $this->queueHelper->enqueueData($queueId, $queueDataModel->__serialize());
    }
  }

  /**
   * Processes a chunk of entities for creation.
   *
   * @param string $contentType
   *   The source content type.
   * @param array $sourceIdsChunk
   *   The chunk of source IDs.
   * @param string $destinationContentType
   *   The destination content type.
   *
   * @throws \Exception
   */
  protected function processCreateChunk(
    string $contentType,
    array $sourceIdsChunk,
    string $destinationContentType
  ): void {
    $sourceEntities = $this->sourceRepository->getEntities(
      $contentType,
      $this->mapping,
      $sourceIdsChunk,
      $this->fromTimestamp
    );
    $sourceEntities = array_filter($sourceEntities);

    if (empty($sourceEntities)) {
      return;
    }

    $this->destinationRepository->createEntities(
      $sourceEntities,
      $this->mapping,
      $destinationContentType,
      $this->dryRun
    );
  }

  /**
   * Processes a chunk of entities for update.
   *
   * @param string $contentType
   *   The source content type.
   * @param array $sourceIdsChunk
   *   The chunk of source IDs.
   * @param string $destinationContentType
   *   The destination content type.
   * @param array $destinationEntities
   *   The destination entities mapping.
   *
   * @throws \Exception
   */
  protected function processUpdateChunk(
    string $contentType,
    array $sourceIdsChunk,
    string $destinationContentType,
    array $destinationEntities
  ): void {
    $sourceEntities = $this->sourceRepository->getEntities(
      $contentType,
      $this->mapping,
      $sourceIdsChunk,
      $this->fromTimestamp
    );
    $sourceEntities = array_filter($sourceEntities);

    if (empty($sourceEntities)) {
      return;
    }

    $destinationEntitiesChunk = array_intersect_key(
      $destinationEntities,
      array_flip(array_keys($sourceEntities))
    );

    $this->destinationRepository->updateEntities(
      $sourceEntities,
      $this->mapping,
      $destinationEntitiesChunk,
      $this->dryRun
    );
  }

  /**
   * Sets the environment.
   *
   * @param string $contentType
   *   The content type to be synchronized.
   * @param array $options
   *   Command options.
   *
   * @throws \Exception
   */
  protected function setEnvironment(
    string $contentType,
    array $options
  ): void {
    $this->logger->notice('Setting the environment...');
    $this->startTime = microtime(TRUE);
    $this->configData = $this->configurationManager->getContentConfiguration($contentType);
    $this->mapping = $this->mapper->buildMapping($this->configData->getFieldsMapping());
    $this->contentType = $contentType;
    if ($options['nids'] !== NULL) {
      $this->nids = explode(',', $options['nids']);
    }
    $this->limit = $options['limit'];
    $this->dryRun = $options['dry-run'];
    $this->overrideMode = $options['override'];
    $this->incremental = $options['incremental'];

    // Parse from-date if provided.
    if (!empty($options['from-date'])) {
      $ts = strtotime($options['from-date']);
      if ($ts === FALSE) {
        $this->logger->error(sprintf('Invalid value for option "from-date": %s. Expected format: YYYY-MM-DD HH:MM:SS', $options['from-date']));
        die;
      }
      $this->fromTimestamp = (int) $ts;
    }

    $sourceRepository = sprintf(
      'labdoo_migrate.source_content.repository.%s',
      $this->configData->getEntityType()
    );
    $this->sourceRepository = \Drupal::service($sourceRepository);
    $destinationRepository = sprintf(
      'labdoo_migrate.destination_content.repository.%s',
      $this->configData->getEntityType()
    );
    $this->destinationRepository = \Drupal::service($destinationRepository);

    if (
      !empty($options['mode'])
      && $options['mode'] !== 'create'
      && $options['mode'] !== 'update'
    ) {
      $errorMessage = sprintf(
        'Invalid value for option "mode". Valid options are "create" and "update"',
      );
      $this->logger->error($errorMessage);
      die;
    }
    $this->create = $options['mode'] === 'create';

    $headerMessage = sprintf(
      "=====================\n"
      . "Entity type: %s\n"
      . "Option mode: %s\n"
      . "Option nids: %s\n"
      . "Option limit: %s\n"
      . "Option override: %s\n"
      . "Option dry-run: %s\n"
      . "Option from-date: %s\n"
      . "Option incremental: %s\n"
      . "=====================",
      $this->contentType,
      $this->create ? 'create' : 'update',
      empty($this->nids) ? 'all' : implode(',', $this->nids),
      (string) $this->limit,
      $this->overrideMode ? 'true' : 'false',
      $this->dryRun ? 'true' : 'false',
      $options['from-date'] ?? 'none',
      $this->incremental ? 'true' : 'false'
    );
    $this->logger->notice($headerMessage);
  }

  /**
   * Retrieves the destination entities.
   *
   * @return array
   *   Returns an array of destination entities.
   *
   * @throws \Exception
   */
  protected function getDestinationEntities(): array {
    $this->logger->notice('Retrieving the destination entities...');

    $destinationEntities = $this->destinationRepository
      ->getEntities($this->configData->getDestinationTypes(), $this->nids);

    if ($this->limit > -1) {
      $destinationEntities = array_slice(
        $destinationEntities,
        0,
        $this->limit,
        TRUE
      );
    }

    $message = sprintf(
      '%d entities to be processed.',
      count($destinationEntities)
    );
    $this->logger->notice($message);

    return $destinationEntities;
  }


  /**
   * Finishes the process.
   *
   * @param int $updated
   *   The number of updated entities.
   * @param array $summary
   *   The process summary.
   */
  protected function tearDown(int $updated, array $summary): void {
    $this->destinationRepository->setIndexingMode(TRUE);

    $mainEntitiesCount = $summary['main'] ?? 0;
    $translationsCount = $summary['translations'] ?? 0;
    $failingIdsArray = $summary['failing_ids'] ?? [];
    $failingIds = implode("\n", $failingIdsArray);
    $failingCount = count($failingIdsArray);
    $total = $mainEntitiesCount + $translationsCount;

    $timeElapsedSeconds = microtime(TRUE) - $this->startTime;
    $infoMessage = sprintf(
      "\n\nPROCESS FINISHED:\n"
      . "-- Content type: %s.\n"
      . "-- Time elapsed: %s.\n"
      . "-- %d/%d main entities processed.\n"
      . "-- %d translations processed.\n"
      . "-- %d total entities created (main and translations combined).\n"
      . "-- Failing IDs: %s",
      $this->contentType,
      gmdate("H:i:s", $timeElapsedSeconds),
      $updated,
      $mainEntitiesCount,
      $translationsCount,
      $total,
      $failingIds
    );
    $this->logger->notice($infoMessage);

    $footerMessage = sprintf(
      "=====================\n"
      . "Entity type: %s\n"
      . "Option mode: %s\n"
      . "Option nids: %s\n"
      . "Option limit: %s\n"
      . "Option override: %s\n"
      . "Option dry-run: %s\n"
      . "Option from-date: %s\n"
      . "Option incremental: %s\n"
      . "Entities processed: %d\n"
      . "Entities failed: %d\n"
      . "=====================",
      $this->contentType,
      $this->create ? 'create' : 'update',
      empty($this->nids) ? 'all' : implode(',', $this->nids),
      (string) $this->limit,
      $this->overrideMode ? 'true' : 'false',
      $this->dryRun ? 'true' : 'false',
      $this->fromTimestamp !== NULL ? date('Y-m-d H:i:s', $this->fromTimestamp) : 'none',
      $this->incremental ? 'true' : 'false',
      $mainEntitiesCount,
      $failingCount
    );
    $this->logger->notice($footerMessage);
  }

}
