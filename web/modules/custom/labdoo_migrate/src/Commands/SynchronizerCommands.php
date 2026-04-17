<?php

namespace Drupal\labdoo_migrate\Commands;

use Drupal\labdoo_migrate\Model\ContentConfigurationModel;
use Drupal\labdoo_migrate\Services\Config\ConfigurationManagerInterface;
use Drupal\labdoo_migrate\Services\DestinationContent\DestinationRepositoryInterface;
use Drupal\labdoo_migrate\Services\Mapper\MapperInterface;
use Drupal\labdoo_migrate\Services\SourceContent\SourceRepositoryInterface;
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
   */
  public function __construct(
    ConfigurationManagerInterface $configurationManager,
    MapperInterface $mapper
  ) {

    parent::__construct();
    $this->configurationManager = $configurationManager;
    $this->mapper = $mapper;
  }

  /**
   * Synchronizes content taking a Drupal 7 instance as a source.
   *
   * @param string $contentType
   *   The content type to synchronize.
   * @param array $options
   *   Command options.
   *
   * @command labdoo-synchronize-content content-type [nids=123,456,789] [limit=9] [mode=create|update] [override] [dry-run] [from-date="YYYY-MM-DD HH:MM:SS"]
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
        foreach ($sourceEntitiesIds as $entityId) {
          $sourceEntity = $this->sourceRepository->getEntity(
            $contentType,
            $this->mapping,
            $entityId,
            $this->fromTimestamp
          );

          if (empty($sourceEntity)) {
            continue;
          }

          $updatedEntities += $this->destinationRepository->createEntities(
            [$entityId => $sourceEntity],
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
        foreach ($sourceEntitiesIds as $entityId) {
          $sourceEntity = $this->sourceRepository->getEntity(
            $contentType,
            $this->mapping,
            $entityId,
            $this->fromTimestamp
          );

          if (empty($sourceEntity)) {
            continue;
          }

          $updatedEntities += $this->destinationRepository->updateEntities(
            [$entityId => $sourceEntity],
            $this->mapping,
            [$entityId => $destinationEntities[$entityId]],
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
    $failingIds = implode("\n", $summary['failing_ids']);
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
  }

}
