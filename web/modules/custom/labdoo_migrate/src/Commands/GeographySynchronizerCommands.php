<?php

namespace Drupal\labdoo_migrate\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;
use Drupal\labdoo_migrate\Services\Config\ConfigurationManagerInterface;
use Drupal\labdoo_migrate\Services\Database\ConnectionManagerInterface;
use Drupal\labdoo_migrate\Services\DestinationContent\DestinationRepositoryInterface;
use Drupal\labdoo_migrate\Services\Mapper\MapperInterface;
use Drupal\labdoo_migrate\Services\SourceContent\SourceRepositoryInterface;
use Drush\Commands\DrushCommands;

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
   * Supported aliases for source types.
   */
  private const SOURCE_TYPE_ALIASES = [
    'dootronic' => 'laptop',
    'dootronics' => 'laptop',
    'laptop' => 'laptop',
    'dootrip' => 'dootrip',
    'dootrips' => 'dootrip',
    'edoovillage' => 'edoovillage',
    'edoovillages' => 'edoovillage',
    'hub' => 'hub',
    'hubs' => 'hub',
  ];

  /**
   * Default set of content types to synchronize.
   */
  private const DEFAULT_SYNC_TYPES = [
    'dootronic',
    'dootrip',
    'edoovillage',
    'hub',
  ];

  /**
   * Batch size to scan destination nodes.
   */
  private const REPAIR_SCAN_BATCH_SIZE = 250;

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
   * Destination geofield per content type.
   */
  private const DESTINATION_GEOFIELD_BY_TYPE = [
    'dootronic' => 'field_locations',
    'hub' => 'field_locations',
    'edoovillage' => 'field_location',
    'dootrip' => 'field_origin_of_the_trip',
  ];

  /**
   * Cache for external table existence checks.
   *
   * @var array<string,bool>
   */
  private array $d7TableExistsCache = [];

  /**
   * GeographySynchronizerCommands constructor.
   */
  public function __construct(
    protected ConnectionManagerInterface $externalConnectionManager,
    protected ConfigurationManagerInterface $configurationManager,
    protected MapperInterface $fieldsMapper,
    protected SourceRepositoryInterface $sourceRepository,
    protected DestinationRepositoryInterface $destinationRepository,
    protected EntityTypeManagerInterface $entityTypeManager
  ) {
    parent::__construct();
  }

  /**
   * Repairs invalid geocoordinates in Drupal 10 using Drupal 7 as source.
   *
   * @command labdoo:migrate-repair-invalid-geo
   * @aliases lmrig
   * @option types Comma-separated list of types (dootronic, dootrip, edoovillage, hub).
   * @option limit Maximum number of invalid nodes to process per type. Use -1 for no limit.
   * @option dry-run Show what would be updated without saving.
   * @option report-path CSV path to report nodes not repairable from Drupal 7.
   * @option backup-path JSONL path to back up nodes before changing them.
   *
   * @usage drush labdoo:migrate-repair-invalid-geo --dry-run
   *   Simulates repair and generates report of non-repairable nodes.
   */
  public function repairInvalidGeography(array $options = [
    'types' => '',
    'limit' => -1,
    'dry-run' => FALSE,
    'report-path' => '',
    'backup-path' => '',
  ]): void {
    $types = empty($options['types'])
      ? self::DEFAULT_SYNC_TYPES
      : array_values(array_filter(array_map('trim', explode(',', (string) $options['types']))));
    if (empty($types)) {
      $this->io()->warning('No valid content types were provided.');
      return;
    }

    $dryRun = filter_var($options['dry-run'], FILTER_VALIDATE_BOOLEAN);
    $limit = (int) ($options['limit'] ?? -1);
    $reportPath = trim((string) ($options['report-path'] ?? ''));
    if ($reportPath === '') {
      $reportPath = '/tmp/labdoo_geo_audit/d7_geo_not_repairable_' . date('Ymd_His') . '.csv';
    }

    $backupPath = trim((string) ($options['backup-path'] ?? ''));
    if ($backupPath === '') {
      $backupPath = '/tmp/labdoo_geo_audit/geo_repair_backup_' . date('Ymd_His') . '.jsonl';
    }

    $reportDirectory = dirname($reportPath);
    if (!is_dir($reportDirectory) && !mkdir($reportDirectory, 0775, TRUE) && !is_dir($reportDirectory)) {
      $this->io()->error(sprintf('Cannot create report directory: %s', $reportDirectory));
      return;
    }

    $reportHandle = fopen($reportPath, 'w');
    if ($reportHandle === FALSE) {
      $this->io()->error(sprintf('Cannot open report file for writing: %s', $reportPath));
      return;
    }

    fputcsv($reportHandle, ['type', 'nid', 'reason', 'd7_field', 'd7_lat', 'd7_lon', 'd7_value']);

    $stats = [
      'invalid_found' => 0,
      'updated' => 0,
      'missing_in_d7' => 0,
      'invalid_in_d7' => 0,
      'ambiguous_in_d7' => 0,
      'errors' => 0,
    ];

    $backupHandle = NULL;
    if (!$dryRun) {
      $backupDirectory = dirname($backupPath);
      if (!is_dir($backupDirectory) && !mkdir($backupDirectory, 0775, TRUE) && !is_dir($backupDirectory)) {
        fclose($reportHandle);
        $this->io()->error(sprintf('Cannot create backup directory: %s', $backupDirectory));
        return;
      }
      $backupHandle = fopen($backupPath, 'w');
      if ($backupHandle === FALSE) {
        fclose($reportHandle);
        $this->io()->error(sprintf('Cannot open backup file for writing: %s', $backupPath));
        return;
      }
    }

    try {
      $externalConnection = $this->externalConnectionManager->setConnection();
    }
    catch (\Exception $e) {
      fclose($reportHandle);
      $this->io()->error('Error connecting to Drupal 7 database: ' . $e->getMessage());
      return;
    }

    try {
      $nodeStorage = $this->entityTypeManager->getStorage('node');

      foreach ($types as $type) {
        if (!in_array($type, self::DEFAULT_SYNC_TYPES, TRUE)) {
          $this->io()->warning(sprintf('Skipping unsupported type "%s".', $type));
          continue;
        }

        $invalidNodes = $this->getInvalidNodesByType($type, $limit);
        $stats['invalid_found'] += count($invalidNodes);

        if (empty($invalidNodes)) {
          $this->io()->writeln(sprintf('[%s] No invalid coordinates found.', $type));
          continue;
        }

        $this->io()->writeln(sprintf('[%s] Found %d invalid nodes.', $type, count($invalidNodes)));
        $this->io()->progressStart(count($invalidNodes));

        foreach ($invalidNodes as $invalidNode) {
          $nid = $invalidNode['nid'];
          $d7Geo = $this->loadD7GeoByType($externalConnection, $type, $nid, $invalidNode['field']);

          if ($d7Geo['status'] !== 'valid') {
            if ($d7Geo['status'] === 'missing') {
              $stats['missing_in_d7']++;
            }
            elseif ($d7Geo['status'] === 'invalid') {
              $stats['invalid_in_d7']++;
            }
            elseif ($d7Geo['status'] === 'ambiguous') {
              $stats['ambiguous_in_d7']++;
            }

            fputcsv($reportHandle, [
              $type,
              $nid,
              $d7Geo['reason'] ?? 'unknown reason',
              $d7Geo['field'] ?? '',
              $d7Geo['lat'] ?? '',
              $d7Geo['lon'] ?? '',
              $d7Geo['value'] ?? '',
            ]);
            $this->io()->progressAdvance();
            continue;
          }

          /** @var \Drupal\node\NodeInterface|null $node */
          $node = $nodeStorage->load($nid);
          if (!$node instanceof NodeInterface) {
            $stats['errors']++;
            fputcsv($reportHandle, [$type, $nid, 'destination node not found in Drupal 10', '', '', '', '']);
            $this->io()->progressAdvance();
            continue;
          }

          $destinationField = $this->resolveDestinationGeoField($type, $node, $d7Geo['field']);
          if ($destinationField === NULL || !$node->hasField($destinationField)) {
            $stats['errors']++;
            fputcsv($reportHandle, [$type, $nid, 'destination geofield not found', $d7Geo['field'], $d7Geo['lat'], $d7Geo['lon'], $d7Geo['value']]);
            $this->io()->progressAdvance();
            continue;
          }

          $lat = (float) $d7Geo['lat'];
          $lon = (float) $d7Geo['lon'];
          $value = $this->buildGeoValue($lat, $lon);
          $geoItem = [
            'value' => $value,
            'lat' => $lat,
            'lon' => $lon,
          ];

          if ($dryRun) {
            $stats['updated']++;
            $this->io()->progressAdvance();
            continue;
          }

          if ($backupHandle !== NULL) {
            $backup = [
              'type' => $type,
              'nid' => (int) $nid,
              'field' => $destinationField,
              'original' => $node->get($destinationField)->getValue(),
              'replacement' => $geoItem,
            ];
            if (fwrite($backupHandle, json_encode($backup, JSON_UNESCAPED_SLASHES) . "\n") === FALSE) {
              $stats['errors']++;
              fputcsv($reportHandle, [$type, $nid, 'could not write node backup', $destinationField, $d7Geo['lat'], $d7Geo['lon'], $d7Geo['value']]);
              $this->io()->progressAdvance();
              continue;
            }
            fflush($backupHandle);
          }

          $node->set($destinationField, $geoItem);

          try {
            $node->save();
            $stats['updated']++;
          }
          catch (\Exception $e) {
            $stats['errors']++;
            fputcsv($reportHandle, [
              $type,
              $nid,
              'error saving destination node: ' . $e->getMessage(),
              $d7Geo['field'],
              $d7Geo['lat'],
              $d7Geo['lon'],
              $d7Geo['value'],
            ]);
          }

          $this->io()->progressAdvance();
        }

        $this->io()->progressFinish();
        $this->io()->newLine();
      }
    }
    finally {
      fclose($reportHandle);
      if (is_resource($backupHandle)) {
        fclose($backupHandle);
      }
      $this->externalConnectionManager->restoreConnection();
    }

    $this->io()->success(sprintf(
      'Geo repair finished%s. Invalid found: %d, Updated: %d, Missing in D7: %d, Invalid in D7: %d, Ambiguous in D7: %d, Errors: %d.',
      $dryRun ? ' (dry-run)' : '',
      $stats['invalid_found'],
      $stats['updated'],
      $stats['missing_in_d7'],
      $stats['invalid_in_d7'],
      $stats['ambiguous_in_d7'],
      $stats['errors']
    ));
    $this->io()->writeln(sprintf('Report file: %s', $reportPath));
    if (!$dryRun) {
      $this->io()->writeln(sprintf('Backup file: %s', $backupPath));
    }
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
   * @option nids Comma-separated list of Drupal 7 nids to synchronize.
   */
  public function syncGeography(
    string $sourceType,
    array $options = [
      'limit' => -1,
      'dry-run' => FALSE,
      'force' => FALSE,
      'nids' => '',
    ]
  ): void {
    $this->syncGeographyType($sourceType, $options);
  }

  /**
   * Synchronizes geographic information for the main content types.
   *
   * @command labdoo-sync-geography-all
   * @aliases labdoo-sync-geo-all
   * @usage labdoo-sync-geography-all
   *   Synchronizes geography for dootronic, dootrip, edoovillage and hub.
   *
   * @option types Comma-separated list of types (dootronic, dootrip, edoovillage, hub).
   * @option limit Limits the execution to the given elements (per type).
   * @option dry-run Whether to run this command in dry-run mode.
   * @option force Force synchronization even if fields are already filled.
   * @option nids Comma-separated list of Drupal 7 nids to synchronize.
   */
  public function syncGeographyAll(array $options = [
    'types' => '',
    'limit' => -1,
    'dry-run' => FALSE,
    'force' => FALSE,
    'nids' => '',
  ]): void {
    $requestedTypes = empty($options['types'])
      ? self::DEFAULT_SYNC_TYPES
      : array_map('trim', explode(',', (string) $options['types']));

    $types = array_values(array_filter($requestedTypes, static fn(string $type): bool => $type !== ''));
    if (empty($types)) {
      $this->logger->warning('No valid content types were provided.');
      return;
    }

    foreach ($types as $type) {
      $this->syncGeographyType($type, $options);
    }
  }

  /**
   * Synchronize geographic information for a source type.
   */
  private function syncGeographyType(string $sourceType, array $options): void {
    $normalizedType = $this->normalizeSourceType($sourceType);
    if ($normalizedType === NULL) {
      $this->logger->warning(sprintf('Unsupported source type "%s".', $sourceType));
      return;
    }

    $options += [
      'limit' => -1,
      'dry-run' => FALSE,
      'force' => FALSE,
      'nids' => '',
    ];

    $this->doSyncGeography($normalizedType, $sourceType, $options);
  }

  /**
   * Performs the synchronization.
   */
  private function doSyncGeography(string $normalizedType, string $inputType, array $options): void {
    try {
      $startTime = microtime(TRUE);
      $this->logger->notice(sprintf('Starting geographic synchronization for type "%s" (source "%s")...', $inputType, $normalizedType));

      // 1. Get the mapping
      $config = $this->configurationManager->getContentConfiguration($normalizedType);
      $mapping = $this->fieldsMapper->buildMapping($config->getFieldsMapping());
      
      // 2. Filter mapping to keep only geographic fields
      $geoMapping = array_filter($mapping, function ($mappingModel) {
        $destField = $mappingModel->getDestinationField()->getFieldName();
        return in_array($destField, self::GEOGRAPHIC_FIELDS, TRUE);
      });

      if (empty($geoMapping)) {
        $this->logger->error(sprintf('No geographic fields defined in mapping for type "%s".', $inputType));
        return;
      }

      $this->logger->notice(sprintf('Fields to sync: %s', implode(', ', array_map(fn($m) => $m->getDestinationField()->getFieldName(), $geoMapping))));

      // 3. Get source entities
      $limit = (int) $options['limit'];
      $sourceEntityIds = $this->sourceRepository->getNodesByType($normalizedType, $geoMapping);
      $selectedNids = $this->extractSelectedNids((string) ($options['nids'] ?? ''));
      if (!empty($selectedNids)) {
        $sourceEntityIds = array_values(array_intersect($sourceEntityIds, $selectedNids));
      }
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
        $this->logger->warning(sprintf('None of the geographic fields exist on the destination entities for type "%s".', $inputType));
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
        $this->sourceRepository->getEntities($normalizedType, $geoMapping, array_keys($destinationEntities)),
        $geoMapping,
        $destinationEntities,
        $options['dry-run']
      );

      $timeElapsedSeconds = microtime(TRUE) - $startTime;
      $this->logger->notice(sprintf(
        "\n\nGEOGRAPHIC SYNCHRONIZATION COMPLETED:\n" .
        "-- Type: %s (source %s).\n" .
        "-- Time elapsed: %s.\n" .
        "-- %d entities updated.",
        $inputType,
        $normalizedType,
        gmdate("H:i:s", $timeElapsedSeconds),
        $updatedCount
      ));
    }
    catch (\Exception $e) {
      $this->logger->error($e->getMessage());
    }
  }

  /**
   * Normalizes source type aliases.
   */
  private function normalizeSourceType(string $sourceType): ?string {
    $normalizedInput = strtolower(trim($sourceType));

    return self::SOURCE_TYPE_ALIASES[$normalizedInput] ?? NULL;
  }

  /**
   * Extracts selected nids from a comma-separated value.
   */
  private function extractSelectedNids(string $nids): array {
    if ($nids === '') {
      return [];
    }

    $parsedNids = array_map(
      static fn(string $nid): int => (int) trim($nid),
      explode(',', $nids)
    );

    return array_values(array_filter($parsedNids, static fn(int $nid): bool => $nid > 0));
  }

  /**
   * Returns destination nodes that currently have invalid coordinates.
   *
   * @return array<int,array{nid:int,reason:string,field:string}>
   *   Invalid nodes info.
   */
  private function getInvalidNodesByType(string $type, int $limit): array {
    $results = [];
    $storage = $this->entityTypeManager->getStorage('node');
    $nids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', $type)
      ->sort('nid', 'ASC')
      ->execute();

    if (empty($nids)) {
      return [];
    }

    foreach (array_chunk(array_values($nids), self::REPAIR_SCAN_BATCH_SIZE) as $nidBatch) {
      /** @var array<int,\Drupal\node\NodeInterface> $nodes */
      $nodes = $storage->loadMultiple($nidBatch);

      foreach ($nidBatch as $nid) {
        if (!isset($nodes[$nid])) {
          continue;
        }

        foreach ($this->inspectDestinationNodeGeo($type, $nodes[$nid]) as $geoInspection) {
          if ($geoInspection['invalid']) {
            $results[] = [
              'nid' => (int) $nid,
              'reason' => $geoInspection['reason'],
              'field' => $geoInspection['field'],
            ];
          }
        }

        if ($limit > 0 && count($results) >= $limit) {
          return $results;
        }
      }
    }

    return $results;
  }

  /**
   * Inspects whether destination node has invalid geo coordinates.
   *
   * @return array{invalid:bool,reason:string,field:string}
   *   Inspection result.
   */
  private function inspectDestinationNodeGeo(string $type, NodeInterface $node): array {
    $fieldNames = match ($type) {
      'dootronic', 'hub' => ['field_locations'],
      'edoovillage' => $node->hasField('field_location') ? ['field_location'] : ['field_locations'],
      'dootrip' => ['field_origin_of_the_trip', 'field_destination_of_the_trip'],
      default => [],
    };
    $inspections = [];
    foreach ($fieldNames as $fieldName) {
      if (!$node->hasField($fieldName)) {
        continue;
      }
      $location = $node->get($fieldName)->first()?->getValue() ?? [];
      $reason = $this->getGeoInvalidReason(
        $location['lat'] ?? NULL,
        $location['lon'] ?? NULL,
        $location['value'] ?? NULL
      );
      $inspections[] = [
        'invalid' => $reason !== NULL,
        'reason' => $reason ?? '',
        'field' => $fieldName,
      ];
    }

    return $inspections;
  }

  /**
   * Loads geo information for one node from Drupal 7.
   *
   * @return array{status:string,reason?:string,field?:string,lat?:float|string|null,lon?:float|string|null,value?:string|null}
   *   Status can be valid|missing|invalid.
   */
  private function loadD7GeoByType(\Drupal\Core\Database\Connection $externalConnection, string $type, int $nid, string $destinationField): array {
    if ($type === 'dootrip') {
      $sourceField = $destinationField;
      $table = 'field_data_' . $sourceField;
      if (!$this->d7TableExists($externalConnection, $table)) {
        return ['status' => 'missing', 'reason' => 'source location field not found', 'field' => $sourceField];
      }
      $row = $externalConnection->select($table, 'f')
        ->fields('f', [$sourceField . '_lid'])
        ->condition('entity_id', $nid)
        ->condition('deleted', 0)
        ->condition('delta', 0)
        ->execute()->fetchAssoc();
      $location = !empty($row[$sourceField . '_lid'])
        ? $externalConnection->select('location', 'l')->fields('l', ['latitude', 'longitude', 'city', 'country'])
          ->condition('lid', $row[$sourceField . '_lid'])->execute()->fetchAssoc()
        : NULL;
      if (!$location) {
        return ['status' => 'missing', 'reason' => 'source location record not found', 'field' => $sourceField];
      }
      $geo = $this->normalizeD7Location($location, $sourceField);
      return $geo;
    }

    if (!in_array($type, ['dootronic', 'hub', 'edoovillage'], TRUE) || !$this->d7TableExists($externalConnection, 'location_instance')) {
      return ['status' => 'missing', 'reason' => 'Drupal 7 location record not found'];
    }

    $query = $externalConnection->select('location_instance', 'i')
      ->fields('l', ['latitude', 'longitude', 'city', 'country'])
      ->fields('i', ['lid'])
      ->condition('i.nid', $nid)
      ->orderBy('i.lid');
    $query->join('location', 'l', 'l.lid = i.lid');
    $locations = $query->execute()->fetchAll(\PDO::FETCH_ASSOC);
    $valid = [];
    foreach ($locations as $location) {
      $candidate = $this->normalizeD7Location($location, $destinationField);
      if ($candidate['status'] === 'valid') {
        $valid[$candidate['lat'] . ',' . $candidate['lon']] = $candidate;
      }
    }
    if (count($valid) === 1) {
      return reset($valid);
    }
    if (count($valid) > 1) {
      return ['status' => 'ambiguous', 'reason' => 'multiple distinct valid source locations', 'field' => $destinationField];
    }
    if (!empty($locations)) {
      $location = reset($locations);
      $result = $this->normalizeD7Location($location, $destinationField);
      $result['status'] = 'invalid';
      return $result;
    }

    return ['status' => 'missing', 'reason' => 'Drupal 7 location record not found', 'field' => $destinationField];
  }

  /**
   * Normalizes a Drupal 7 location module record.
   */
  private function normalizeD7Location(array $location, string $field): array {
    $lat = $location['latitude'] ?? NULL;
    $lon = $location['longitude'] ?? NULL;
    $reason = $this->getGeoInvalidReason($lat, $lon, (string) ($lat ?? '') . ',' . (string) ($lon ?? ''));
    return [
      'status' => $reason === NULL ? 'valid' : 'invalid',
      'reason' => $reason ?? '',
      'field' => $field,
      'lat' => $lat,
      'lon' => $lon,
      'value' => (string) ($lat ?? '') . ',' . (string) ($lon ?? ''),
      'city' => $location['city'] ?? '',
      'country' => $location['country'] ?? '',
    ];
  }

  /**
   * Resolves destination field to update.
   */
  private function resolveDestinationGeoField(string $type, NodeInterface $node, string $d7Field): ?string {
    if ($type === 'dootrip') {
      return match ($d7Field) {
        'field_origin_of_the_trip' => 'field_origin_of_the_trip',
        'field_destination_of_the_trip' => 'field_destination_of_the_trip',
        default => $node->hasField('field_origin_of_the_trip') ? 'field_origin_of_the_trip' : 'field_destination_of_the_trip',
      };
    }

    if ($type === 'edoovillage') {
      if ($d7Field === 'field_location' && $node->hasField('field_location')) {
        return 'field_location';
      }

      return $node->hasField('field_locations') ? 'field_locations' : 'field_location';
    }

    return self::DESTINATION_GEOFIELD_BY_TYPE[$type] ?? NULL;
  }

  /**
   * Checks whether a Drupal 7 table exists.
   */
  private function d7TableExists(\Drupal\Core\Database\Connection $externalConnection, string $table): bool {
    if (!array_key_exists($table, $this->d7TableExistsCache)) {
      $this->d7TableExistsCache[$table] = $externalConnection->schema()->tableExists($table);
    }

    return $this->d7TableExistsCache[$table];
  }

  /**
   * Returns invalid geo reason, or NULL if valid.
   */
  private function getGeoInvalidReason(mixed $lat, mixed $lon, mixed $value): ?string {
    if ($lat === NULL || $lon === NULL || $lat === '' || $lon === '') {
      return 'lat/lon NULL';
    }

    $latFloat = (float) $lat;
    $lonFloat = (float) $lon;

    if ($latFloat === 0.0 && $lonFloat === 0.0) {
      return 'sentinela 0,0';
    }
    if ($latFloat === -90.0 && $lonFloat === -180.0) {
      return 'sentinela -90,-180';
    }
    if ($latFloat < -90.0 || $latFloat > 90.0 || $lonFloat < -180.0 || $lonFloat > 180.0) {
      return 'fuera de rango';
    }

    $valueNormalized = trim((string) ($value ?? ''));
    if ($valueNormalized === '' || $valueNormalized === '0.000000,0.000000') {
      return 'valor vacío/placeholder';
    }

    return NULL;
  }

  /**
   * Builds geofield value string.
   */
  private function buildGeoValue(float $lat, float $lon): string {
    return number_format($lat, 6, '.', '') . ',' . number_format($lon, 6, '.', '');
  }
}
