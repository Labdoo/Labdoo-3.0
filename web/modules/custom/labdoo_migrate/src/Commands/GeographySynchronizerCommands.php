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
   * @option force Force synchronization even if fields are already filled.
   */
  public function syncGeography(
    string $sourceType,
    array $options = [
      'limit' => -1,
      'dry-run' => FALSE,
      'force' => FALSE,
    ]
  ): void {
    try {
      $startTime = microtime(TRUE);
      $this->logger->notice(sprintf('Starting geographic synchronization for type "%s"...', $sourceType));

      // 1. Get the mapping
      $config = $this->configurationManager->getContentConfiguration($sourceType);
      $mapping = $this->fieldsMapper->buildMapping($config->getFieldsMapping());
      
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
      $destinationContentTypes = $config->getDestinationTypes();
      $destinationEntities = $this->destinationRepository->getEntities($destinationContentTypes, $sourceEntityIds);

      $this->logger->notice(sprintf('Found %d entities to synchronize.', count($destinationEntities)));

      if (empty($destinationEntities)) {
        return;
      }

      // 5. Filter geoMapping to keep only fields that exist in the destination entities
      // We check the first entity as they should all be of the same bundle(s)
      $firstEntity = reset($destinationEntities);
      $geoMappingNames = [];
      $geoMapping = array_filter($geoMapping, function ($mappingModel) use ($firstEntity, &$geoMappingNames) {
        $fieldName = $mappingModel->getDestinationField()->getFieldName();
        $exists = $firstEntity->hasField($fieldName);
        if ($exists) {
          $geoMappingNames[] = $fieldName;
        }
        return $exists;
      });

      if (empty($geoMapping)) {
        $this->logger->warning(sprintf('None of the geographic fields exist on the destination entities for type "%s".', $sourceType));
        return;
      }

      $this->logger->notice(sprintf('Actual fields to sync: %s', implode(', ', $geoMappingNames)));

      // 5b. Filter destination entities if they already have all geographic fields filled
      if (!$options['force']) {
        $destinationEntities = array_filter($destinationEntities, function ($entity) use ($geoMappingNames) {
          foreach ($geoMappingNames as $fieldName) {
            if ($entity->get($fieldName)->isEmpty()) {
              return TRUE; // At least one field is empty, keep for sync
            }
          }
          return FALSE; // All fields are filled, skip
        });

        $this->logger->notice(sprintf('Filtered entities to synchronize: %d (those with empty fields).', count($destinationEntities)));

        if (empty($destinationEntities)) {
          $this->logger->notice('All entities already have their geographic information filled.');
          return;
        }
      }

      // 6. Perform the update
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
