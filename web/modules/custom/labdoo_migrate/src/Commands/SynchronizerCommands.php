<?php

namespace Drupal\labdoo_migrate\Commands;

use Drupal\labdoo_migrate\Model\ContentConfigurationModel;
use Drupal\labdoo_migrate\Services\Config\ConfigurationManagerInterface;
use Drupal\labdoo_migrate\Services\DestinationContent\DestinationRepositoryInterface;
use Drupal\labdoo_migrate\Services\Mapper\MapperInterface;
use Drupal\labdoo_migrate\Services\SourceContent\SourceRepositoryInterface;
use Drupal\labdoo_migrate\Services\Tracking\MigrationTrackerInterface;
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
   */
  public function __construct(
    ConfigurationManagerInterface $configurationManager,
    MapperInterface $mapper,
    MigrationTrackerInterface $migrationTracker
  ) {

    parent::__construct();
    $this->configurationManager = $configurationManager;
    $this->mapper = $mapper;
    $this->migrationTracker = $migrationTracker;
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
    ]
  ): void {
    try {
      $this->setEnvironment($contentType, $options);

      if ($this->create) {
        $sourceEntitiesIds = $this->nids;
        if (empty($sourceEntitiesIds)) {
          $sourceEntitiesIds = $this->sourceRepository->getNodesByType(
            $contentType,
            $this->mapping,
            $this->fromTimestamp
          );
        }

        if ($this->incremental) {
          $destinationTypes = $this->configData->getDestinationTypes();
          $destinationContentType = reset($destinationTypes);
          $migratedSourceIds = $this->migrationTracker->getMigratedSourceIds(
            $this->configData->getEntityType(),
            $destinationContentType
          );
          $sourceEntitiesIds = array_diff($sourceEntitiesIds, $migratedSourceIds);
        }

        if ($this->limit > -1) {
          $sourceEntitiesIds = array_slice(
            $sourceEntitiesIds,
            0,
            $this->limit
          );
        }

        $total = count($sourceEntitiesIds);
        $this->logger->notice(sprintf('%d source entities found.', $total));
        $this->logger->notice('Creating the destination entities...');

        $destinationTypes = $this->configData->getDestinationTypes();
        $destinationContentType = reset($destinationTypes);
        $this->destinationRepository->setOverrideMode($this->overrideMode);
        $this->destinationRepository->setTotalCount($total);

        $updatedEntities = 0;
        foreach (array_chunk($sourceEntitiesIds, self::SYNC_BATCH_SIZE) as $sourceIdsChunk) {
          $sourceEntities = $this->sourceRepository->getEntities(
            $contentType,
            $this->mapping,
            $sourceIdsChunk,
            $this->fromTimestamp
          );
          $sourceEntities = array_filter($sourceEntities);

          if (empty($sourceEntities)) {
            continue;
          }

          $updatedEntities += $this->destinationRepository->createEntities(
            $sourceEntities,
            $this->mapping,
            $destinationContentType,
            $this->dryRun
          );
        }
      }
      else {
        $destinationEntities = $this->getDestinationEntities();
        $sourceEntitiesIds = array_keys($destinationEntities);

        if ($this->limit > -1) {
          $sourceEntitiesIds = array_slice(
            $sourceEntitiesIds,
            0,
            $this->limit
          );
        }

        $total = count($sourceEntitiesIds);
        $this->logger->notice(sprintf('%d source entities found.', $total));
        $this->logger->notice('Updating the destination entities...');
        $this->destinationRepository->setTotalCount($total);

        $updatedEntities = 0;
        foreach (array_chunk($sourceEntitiesIds, self::SYNC_BATCH_SIZE) as $sourceIdsChunk) {
          $sourceEntities = $this->sourceRepository->getEntities(
            $contentType,
            $this->mapping,
            $sourceIdsChunk,
            $this->fromTimestamp
          );
          $sourceEntities = array_filter($sourceEntities);

          if (empty($sourceEntities)) {
            continue;
          }

          $destinationEntitiesChunk = array_intersect_key(
            $destinationEntities,
            array_flip(array_keys($sourceEntities))
          );

          $updatedEntities += $this->destinationRepository->updateEntities(
            $sourceEntities,
            $this->mapping,
            $destinationEntitiesChunk,
            $this->dryRun
          );
        }
      }

      $this->tearDown(
        $updatedEntities,
        $this->destinationRepository->getProcessedEntitiesSummary()
      );
    }
    catch (\Exception $e) {
      $this->logger->error($e->getMessage());
    }
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
