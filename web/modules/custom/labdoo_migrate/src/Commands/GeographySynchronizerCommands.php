<?php

namespace Drupal\labdoo_migrate\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\labdoo_migrate\Services\Config\ConfigurationManagerInterface;
use Drupal\labdoo_migrate\Services\DestinationContent\DestinationRepositoryInterface;
use Drupal\labdoo_migrate\Services\Mapper\MapperInterface;
use Drupal\labdoo_migrate\Services\SourceContent\SourceRepositoryInterface;
use Drush\Commands\DrushCommands;
use Symfony\Component\Console\Helper\ProgressBar;

/**
 * Geography synchronization commands.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class GeographySynchronizerCommands extends DrushCommands {

  /**
   * The fields to synchronize.
   */
  private const GEOGRAPHIC_FIELDS = [
    'field_country',
    'field_city',
    'field_postal_code',
    'field_address',
    'field_location',
    'field_locations',
    'field_destination_of_the_trip',
    'field_origin_of_the_trip',
  ];

  /**
   * GeographySynchronizerCommands constructor.
   */
  public function __construct(
    protected ConfigurationManagerInterface $configurationManager,
    protected MapperInterface $fieldsMapper,
    protected SourceRepositoryInterface $sourceRepository,
    protected DestinationRepositoryInterface $destinationRepository,
    protected EntityTypeManagerInterface $entityTypeManager
  ) {
    parent::__construct();
  }

  /**
   * Synchronizes geographic information from Drupal 7.
   *
   * @param string $sourceType
   *   The source type (e.g., edoovillage, hub, laptop, user).
   * @param array $options
   *   Command options.
   *
   * @command labdoo-sync-geography
   * @aliases labdoo-sync-geo
   * @usage labdoo-sync-geography edoovillage
   *   Synchronizes geography for edoovillages.
   *
   * @option limit Limits the execution to the given elements.
   * @option dry-run Whether to run this command in dry-run mode.
   */
  public function syncGeography(
    string $sourceType,
    array $options = [
      'limit' => -1,
      'dry-run' => FALSE,
    ]
  ): void {
    try {
      $startTime = microtime(TRUE);
      $this->logger->notice(sprintf('Starting geographic synchronization for type "%s"...', $sourceType));

      // 1. Get the mapping
      $config = $this->configurationManager->getSourceTypeConfiguration($sourceType);
      $mapping = $this->fieldsMapper->buildMapping($config['fields_mapping']);
      
      // 2. Filter mapping to keep only geographic fields
      $geoMapping = array_filter($mapping, function ($mappingModel) {
        $destField = $mappingModel->getDestinationField()->getFieldName();
        return in_array($destField, self::GEOGRAPHIC_FIELDS);
      });

      if (empty($geoMapping)) {
        $this->logger->error(sprintf('No geographic fields defined in mapping for type "%s".', $sourceType));
        return;
      }

      $this->logger->notice(sprintf('Fields to sync: %s', implode(', ', array_map(fn($m) => $m->getDestinationField()->getFieldName(), $geoMapping))));

      // 3. Get source entities
      $limit = (int) $options['limit'];
      $sourceEntityIds = $this->sourceRepository->getNodesByType($sourceType, $geoMapping);
      if ($limit > 0) {
        $sourceEntityIds = array_slice($sourceEntityIds, 0, $limit);
      }
      
      $total = count($sourceEntityIds);
      if ($total === 0) {
        $this->logger->notice('No source entities found.');
        return;
      }

      // 4. Get destination entities (already migrated nodes)
      $destinationContentTypes = $config['destination_types'];
      $destinationEntities = $this->destinationRepository->getEntities($destinationContentTypes, $sourceEntityIds);

      $this->logger->notice(sprintf('Found %d entities to synchronize.', count($destinationEntities)));

      // 5. Perform the update
      $this->destinationRepository->setOverrideMode(TRUE); // Allow updating existing fields
      $updatedCount = $this->destinationRepository->updateEntities(
        $this->sourceRepository->getEntities($sourceType, $geoMapping, array_keys($destinationEntities)),
        $geoMapping,
        $destinationEntities,
        $options['dry-run']
      );

      $timeElapsedSeconds = microtime(TRUE) - $startTime;
      $this->logger->notice(sprintf(
        "\n\nGEOGRAPHIC SYNCHRONIZATION COMPLETED:\n" .
        "-- Type: %s.\n" .
        "-- Time elapsed: %s.\n" .
        "-- %d entities updated.",
        $sourceType,
        gmdate("H:i:s", $timeElapsedSeconds),
        $updatedCount
      ));
    }
    catch (\Exception $e) {
      $this->logger->error($e->getMessage());
    }
  }
}
