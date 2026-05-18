<?php

namespace Drupal\labdoo_migrate\Commands;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\labdoo_migrate\Services\Config\ConfigurationManagerInterface;
use Drupal\labdoo_migrate\Services\Database\ConnectionManagerInterface;
use Drupal\labdoo_migrate\Services\Mapper\MapperInterface;
use Drupal\labdoo_migrate\Services\SourceContent\SourceRepositoryInterface;
use Drupal\labdoo_migrate\Services\Tracking\MigrationTrackerInterface;
use Drush\Commands\DrushCommands;

/**
 * Integrity check commands.
 */
class IntegrityCheckCommands extends DrushCommands {

  /**
   * The configuration manager.
   *
   * @var \Drupal\labdoo_migrate\Services\Config\ConfigurationManagerInterface
   */
  private ConfigurationManagerInterface $configurationManager;

  /**
   * The fields mapper.
   *
   * @var \Drupal\labdoo_migrate\Services\Mapper\MapperInterface
   */
  private MapperInterface $mapper;

  /**
   * The source repository.
   *
   * @var \Drupal\labdoo_migrate\Services\SourceContent\SourceRepositoryInterface
   */
  private SourceRepositoryInterface $sourceRepository;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  private EntityTypeManagerInterface $entityTypeManager;

  /**
   * The migration tracker.
   *
   * @var \Drupal\labdoo_migrate\Services\Tracking\MigrationTrackerInterface
   */
  private MigrationTrackerInterface $migrationTracker;

  /**
   * The external connection manager.
   *
   * @var \Drupal\labdoo_migrate\Services\Database\ConnectionManagerInterface
   */
  private ConnectionManagerInterface $externalConnectionManager;

  /**
   * The language manager.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  private LanguageManagerInterface $languageManager;

  /**
   * IntegrityCheckCommands constructor.
   */
  public function __construct(
    ConfigurationManagerInterface $configurationManager,
    MapperInterface $mapper,
    SourceRepositoryInterface $sourceRepository,
    EntityTypeManagerInterface $entityTypeManager,
    MigrationTrackerInterface $migrationTracker,
    ConnectionManagerInterface $externalConnectionManager,
    LanguageManagerInterface $languageManager
  ) {
    parent::__construct();
    $this->configurationManager = $configurationManager;
    $this->mapper = $mapper;
    $this->sourceRepository = $sourceRepository;
    $this->entityTypeManager = $entityTypeManager;
    $this->migrationTracker = $migrationTracker;
    $this->externalConnectionManager = $externalConnectionManager;
    $this->languageManager = $languageManager;
  }

  /**
   * Checks the integrity of migrated nodes.
   *
   * @param string $contentType
   *   The content type to check.
   * @param array $options
   *   The command options.
   *
   * @option limit
   *   The number of nodes to check (0 for all).
   * @option destination-type
   *   The destination content type (bundle) in Drupal 10.
   * @option nids
   *   Specific source IDs to check.
   * @option fields
   *   Specific fields to check (comma separated).
   * @option inconsistent-only
   *   Check only nodes that were previously marked as inconsistent.
   *
   * @command labdoo:migrate-check-integrity
   * @aliases lm-ci
   * @usage drush lm-ci story
   * @usage drush lm-ci story --limit=10
   * @usage drush lm-ci story --inconsistent-only
   */
  public function checkIntegrity(string $contentType, array $options = ['limit' => 0, 'destination-type' => NULL, 'nids' => NULL, 'fields' => NULL, 'inconsistent-only' => FALSE]): void {
    $limit = isset($options['limit']) ? (int) $options['limit'] : 0;
    $destinationType = $options['destination-type'] ?? $contentType;
    $inconsistentOnly = $options['inconsistent-only'] ?? FALSE;

    $bundleMapping = [
      'story' => 'labdoo_story',
      'team_task' => 'task_team',
      'laptop' => 'dootronic',
    ];

    if ($options['destination-type'] === NULL) {
      $destinationType = $bundleMapping[$contentType] ?? $contentType;
    }
    $nids = $options['nids'] ? explode(',', $options['nids']) : [];
    $config = $this->configurationManager->getContentConfiguration($contentType);
    $targetFields = $options['fields'] ? explode(',', $options['fields']) : $config->getIntegrityFields();

    $sourceContentType = $contentType;

    if ($limit > 0) {
      $this->io()->title(sprintf('Checking integrity for %d random migrated nodes of type %s (D7) -> %s (D10)', $limit, $sourceContentType, $destinationType));
    }
    else {
      $this->io()->title(sprintf('Checking integrity for ALL migrated nodes of type %s (D7) -> %s (D10)', $sourceContentType, $destinationType));
    }

    try {
      $entityType = $config->getEntityType();
      $mapping = $this->mapper->buildMapping($config->getFieldsMapping());

      if (!empty($nids)) {
        $selectedIds = $nids;
      }
      else {
        if ($inconsistentOnly) {
          $allIds = $this->migrationTracker->getSourceIdsByIntegrityStatus($entityType, $destinationType, [2]);
        }
        else {
          $allIds = $this->migrationTracker->getMigratedSourceIds($entityType, $destinationType);
        }

        if (empty($allIds)) {
          $this->io()->warning($inconsistentOnly ? 'No inconsistent nodes found for this content type.' : 'No migrated nodes found for this content type.');
          return;
        }

        if ($limit > 0) {
          shuffle($allIds);
          $selectedIds = array_slice($allIds, 0, $limit);
        }
        else {
          $selectedIds = $allIds;
          sort($selectedIds);
        }
      }

      $results = [];
      $deferredMessages = [];
      $progressBar = $this->io()->createProgressBar(count($selectedIds));
      $progressBar->start();

      foreach ($selectedIds as $sid) {
        $destId = $this->migrationTracker->getDestinationIdBySourceId($entityType, $sid);
        if (!$destId) {
          $results[] = [$sid, 'N/A', 'ERROR', 'Not found in D10'];
          $deferredMessages[] = "Source ID $sid not found in D10.";
          $progressBar->advance();
          continue;
        }

    // Get source data.
    $sourceRepositoryService = sprintf(
      'labdoo_migrate.source_content.repository.%s',
      $entityType
    );
    /** @var \Drupal\labdoo_migrate\Services\SourceContent\SourceRepositoryInterface $sourceRepo */
    $sourceRepo = \Drupal::getContainer()->get($sourceRepositoryService);

    $this->externalConnectionManager->setConnection();
    $sourceDataRaw = $sourceRepo->getEntity($sourceContentType, $mapping, $sid);
    $this->externalConnectionManager->restoreConnection();

    if (empty($sourceDataRaw)) {
      $results[] = [$sid, $destId, 'ERROR', 'Source entity does not exist in D7'];
      $this->migrationTracker->updateIntegrityStatus($entityType, $sid, 2);
      $progressBar->advance();
      continue;
    }

    $defaultLang = $this->languageManager->getDefaultLanguage()->getId();
    if ($entityType === 'user' || $entityType === 'comment') {
      $sourceData = $sourceDataRaw[$defaultLang] ?? $sourceDataRaw;
    }
    else {
      $mainLang = $sourceDataRaw['metadata']['main_langcode'] ?? $defaultLang;
      $sourceData = $sourceDataRaw[$mainLang] ?? [];
    }

    // Get destination entity.
        $destEntity = $this->entityTypeManager->getStorage($entityType)->load($destId);
        if (!$destEntity) {
          $results[] = [$sid, $destId, 'ERROR', 'D10 entity could not be loaded'];
          $progressBar->advance();
          continue;
        }

        $entityErrors = $this->compareData($sourceData, $destEntity, $mapping, $targetFields);
        if (empty($entityErrors)) {
          $results[] = [$sid, $destId, 'OK', 'All fields match'];
          $this->migrationTracker->updateIntegrityStatus($entityType, $sid, 1);
        }
        else {
          $this->migrationTracker->updateIntegrityStatus($entityType, $sid, 2);
          foreach ($entityErrors as $error) {
            $results[] = [$sid, $destId, 'ERROR', $error];
          }
        }
        $progressBar->advance();
      }

      $progressBar->finish();
      $this->io()->newLine(2);

      foreach ($deferredMessages as $message) {
        $this->io()->warning($message);
      }

      $this->io()->table(['Source ID', 'Dest ID', 'Status', 'Details'], $results);
    }
    catch (\Exception $e) {
      $this->io()->error($e->getMessage());
    }
  }

  /**
   * Compares source data with destination node.
   */
  protected function compareData(array $sourceData, EntityInterface $destNode, array $mapping, array $targetFields = []): array {
    $errors = [];
    /** @var \Drupal\labdoo_migrate\Model\MappingModel $map */
    foreach ($mapping as $map) {
      $sourceKey = $map->getSourceIdentifier();
      $destField = $map->getDestinationField()->getFieldName();

      // Some fields might be internal metadata or ignored.
      if (in_array($destField, ['nid', 'vid', 'type', 'uuid', 'langcode', 'pass', 'preferred_langcode'])) {
        continue;
      }

      if (!empty($targetFields) && !in_array($destField, $targetFields)) {
        continue;
      }

      $sourceValue = $sourceData[$sourceKey] ?? NULL;

      // Special handling for different field types might be needed.
      // For now, let's do a basic comparison of values.
      if (!$destNode->hasField($destField)) {
        continue;
      }

      $destValue = $this->getDestinationValue($destNode, $destField);

      // If source value is null but destination has value, it's a mismatch
      // unless it's an ignored field or expected default.
      if (is_null($sourceValue) && !empty($destValue)) {
         // We keep it as is, but we want to make sure it's caught by isEqual.
      }

      // Special handling for file/image fields: compare URIs.
      if ($destField === 'field_picture' && is_numeric($destValue) && is_string($sourceValue) && strpos($sourceValue, '://') !== FALSE) {
        $file = $this->entityTypeManager->getStorage('file')->load($destValue);
        if ($file instanceof \Drupal\file\FileInterface) {
          $destValue = $file->getFileUri();
        }
      }

      if (!$this->isEqual($sourceValue, $destValue)) {
        $errors[] = sprintf(
          "Field %s mismatch. Source: %s, Dest: %s",
          $destField,
          is_scalar($sourceValue) ? $sourceValue : json_encode($sourceValue),
          is_scalar($destValue) ? $destValue : json_encode($destValue)
        );
      }
    }

    return $errors;
  }

  /**
   * Gets a simplified value from a destination node field for comparison.
   */
  protected function getDestinationValue(EntityInterface $entity, string $fieldName) {
    $field = $entity->get($fieldName);
    if ($field->isEmpty()) {
      return NULL;
    }

    $values = $field->getValue();
    $processedValues = [];
    foreach ($values as $val) {
      if (isset($val['lat']) && (isset($val['lng']) || isset($val['lon']))) {
        $lng = $val['lng'] ?? $val['lon'];
        $processedValues[] = $val['lat'] . ',' . $lng;
      }
      elseif (isset($val['latlon'])) {
        $processedValues[] = $val['latlon'];
      }
      elseif (isset($val['value']) && is_string($val['value']) && strpos($val['value'], 'POINT (') === 0) {
        // Handle Geofield WKT format for comparison.
        // Convert "POINT (lng lat)" to "lat,lng" for comparison with source.
        $wkt = $val['value'];
        if (preg_match('/POINT\s*\(\s*([0-9.-]+)\s+([0-9.-]+)\s*\)/', $wkt, $matches)) {
          $processedValues[] = $matches[2] . ',' . $matches[1];
        }
        else {
          $processedValues[] = $val['value'];
        }
      }
      elseif (isset($val['target_id'])) {
        $processedValues[] = $val['target_id'];
      }
      elseif (isset($val['value'])) {
        $processedValues[] = $val['value'];
      }
      elseif (isset($val['country_code'])) {
        $processedValues[] = $val['country_code'];
      }
      else {
        $processedValues[] = $val;
      }
    }

    $cardinality = $entity->getFieldDefinition($fieldName)->getFieldStorageDefinition()->getCardinality();
    if ($cardinality == 1) {
      return !empty($processedValues) ? $processedValues[0] : NULL;
    }

    return $processedValues;
  }

  /**
   * Compare two values for equality.
   */
  protected function isEqual($val1, $val2): bool {
    if (is_null($val1) && (is_null($val2) || $val2 === '' || (is_array($val2) && empty($val2)))) {
      return TRUE;
    }

    // Normalize source array to single value if needed.
    if (is_array($val1) && !is_array($val2) && count($val1) <= 1) {
      $val1 = !empty($val1) ? reset($val1) : NULL;
    }

    // Special case for Geolocation field: Dest -90,-180 means empty/null.
    if (($val2 === '-90,-180' || $val2 === '-90,-180.000000') && (is_null($val1) || $val1 === '0.000000,0.000000' || $val1 === '0,0')) {
      return TRUE;
    }

    if (is_array($val1) && is_array($val2)) {
      if (count($val1) !== count($val2)) {
        return FALSE;
      }
      foreach ($val1 as $k => $v) {
        if (!array_key_exists($k, $val2) || !$this->isEqual($v, $val2[$k])) {
          return FALSE;
        }
      }
      return TRUE;
    }

    if (is_array($val1) || is_array($val2)) {
      if (is_array($val1) && count($val1) === 1 && $this->isEqual(reset($val1), $val2)) {
        return TRUE;
      }
      if (is_array($val2) && count($val2) === 1 && $this->isEqual($val1, reset($val2))) {
        return TRUE;
      }
      return FALSE;
    }

    // Handle numeric strings with different precision/trailing zeros (coordinates).
    if (is_string($val1) && is_string($val2) && strpos($val1, ',') !== FALSE && strpos($val2, ',') !== FALSE) {
      $parts1 = explode(',', $val1);
      $parts2 = explode(',', $val2);
      if (count($parts1) === 2 && count($parts2) === 2) {
        if (is_numeric($parts1[0]) && is_numeric($parts1[1]) && is_numeric($parts2[0]) && is_numeric($parts2[1])) {
          // Relax threshold to 0.002 to absorb small geocoding differences.
          return abs((float)$parts1[0] - (float)$parts2[0]) < 0.002 && abs((float)$parts1[1] - (float)$parts2[1]) < 0.002;
        }
      }
    }

    if (is_numeric($val1) && is_numeric($val2)) {
      return (float)$val1 == (float)$val2;
    }

    // Special case for ISO 8601 dates (Drupal 10) vs Y-m-d H:i:s (Drupal 7).
    if (is_string($val1) && is_string($val2)) {
      if (strpos($val2, 'T') !== FALSE) {
        $normalized_val2 = str_replace('T', ' ', $val2);
        if ($val1 === $normalized_val2) {
          return TRUE;
        }
      }
    }

    // Drupal 7 often has strings, Drupal 10 might have integers or strings.
    // Case insensitive for strings (like country codes or usernames).
    if (is_string($val1) && is_string($val2)) {
      if ($val1 === $val2) {
        return TRUE;
      }
      if (strcasecmp($val1, $val2) === 0) {
        return TRUE;
      }
    }

    return (string) $val1 === (string) $val2;
  }

}
