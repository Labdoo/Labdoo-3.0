<?php

namespace Drupal\labdoo_migrate\Commands;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\labdoo_migrate\Services\Config\ConfigurationManagerInterface;
use Drupal\labdoo_migrate\Services\Database\ConnectionManagerInterface;
use Drupal\labdoo_migrate\Services\Media\FileManagerInterface;
use Drupal\labdoo_migrate\Services\Mapper\MapperInterface;
use Drupal\labdoo_migrate\Services\SourceContent\SourceRepositoryInterface;
use Drupal\labdoo_migrate\Services\Tracking\MigrationTrackerInterface;
use Drush\Commands\DrushCommands;

/**
 * Integrity fix commands.
 */
class IntegrityFixCommands extends DrushCommands {

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
   * The file manager.
   *
   * @var \Drupal\labdoo_migrate\Services\Media\FileManagerInterface
   */
  private FileManagerInterface $fileManager;

  /**
   * IntegrityFixCommands constructor.
   */
  public function __construct(
    ConfigurationManagerInterface $configurationManager,
    MapperInterface $mapper,
    SourceRepositoryInterface $sourceRepository,
    EntityTypeManagerInterface $entityTypeManager,
    MigrationTrackerInterface $migrationTracker,
    ConnectionManagerInterface $externalConnectionManager,
    LanguageManagerInterface $languageManager,
    FileManagerInterface $fileManager
  ) {
    parent::__construct();
    $this->configurationManager = $configurationManager;
    $this->mapper = $mapper;
    $this->sourceRepository = $sourceRepository;
    $this->entityTypeManager = $entityTypeManager;
    $this->migrationTracker = $migrationTracker;
    $this->externalConnectionManager = $externalConnectionManager;
    $this->languageManager = $languageManager;
    $this->fileManager = $fileManager;
  }

  /**
   * Fixes the integrity of migrated nodes.
   *
   * @param string $contentType
   *   The content type to check and fix.
   * @param array $options
   *   The command options.
   *
   * @option limit
   *   The number of nodes to check and fix (0 for all).
   * @option destination-type
   *   The destination content type (bundle) in Drupal 10.
   * @option nids
   *   Specific source IDs to check and fix.
   * @option fields
   *   Specific fields to fix (comma separated).
   * @option inconsistent-only
   *   Fix only nodes that were previously marked as inconsistent.
   *
   * @command labdoo:migrate-fix-integrity
   * @aliases lm-fi
   * @usage drush lm-fi story
   * @usage drush lm-fi story --limit=10
   * @usage drush lm-fi story --inconsistent-only
   */
  public function fixIntegrity(string $contentType, array $options = ['limit' => 0, 'destination-type' => NULL, 'nids' => NULL, 'fields' => NULL, 'inconsistent-only' => FALSE]): void {
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
      $this->io()->title(sprintf('Fixing integrity for %d random migrated nodes of type %s (D7) -> %s (D10)', $limit, $sourceContentType, $destinationType));
    }
    else {
      $this->io()->title(sprintf('Fixing integrity for ALL migrated nodes of type %s (D7) -> %s (D10)', $sourceContentType, $destinationType));
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

      $totalFixedNodes = 0;
      $totalFixedFields = 0;
      $deferredMessages = [];

      $progressBar = $this->io()->createProgressBar(count($selectedIds));
      $progressBar->start();

      foreach ($selectedIds as $sid) {
        $destId = $this->migrationTracker->getDestinationIdBySourceId($entityType, $sid);
        if (!$destId) {
          $deferredMessages[] = "Source ID $sid not found in D10.";
          $progressBar->advance();
          continue;
        }

        // Get source data.
        $sourceRepositoryService = sprintf('labdoo_migrate.source_content.repository.%s', $entityType);
        /** @var \Drupal\labdoo_migrate\Services\SourceContent\SourceRepositoryInterface $sourceRepo */
        $sourceRepo = \Drupal::getContainer()->get($sourceRepositoryService);

        $this->externalConnectionManager->setConnection();
        $sourceDataRaw = $sourceRepo->getEntity($sourceContentType, $mapping, $sid);
        $this->externalConnectionManager->restoreConnection();

        if (empty($sourceDataRaw)) {
          $deferredMessages[] = "Source data for $sid could not be retrieved from D7.";
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

        // Even if sourceData is empty (all fields empty in D7), we continue
        // to allow comparison and potential clearing of fields in D10.
        if (!is_array($sourceData)) {
          $deferredMessages[] = "Source data for $sid is invalid. Skipping.";
          $progressBar->advance();
          continue;
        }

        /** @var \Drupal\Core\Entity\ContentEntityInterface $destEntity */
        $destEntity = $this->entityTypeManager->getStorage($entityType)->load($destId);
        if (!$destEntity) {
          $deferredMessages[] = "D10 entity $destId could not be loaded.";
          $progressBar->advance();
          continue;
        }

        $nodeChanged = FALSE;
        $fieldsFixed = [];

        /** @var \Drupal\labdoo_migrate\Model\MappingModel $map */
        foreach ($mapping as $map) {
          $sourceKey = $map->getSourceIdentifier();
          $destField = $map->getDestinationField()->getFieldName();

          if (in_array($destField, ['nid', 'vid', 'type', 'uuid', 'langcode', 'pass', 'preferred_langcode'])) {
            continue;
          }

          if (!empty($targetFields) && !in_array($destField, $targetFields)) {
            continue;
          }

          if (!$destEntity->hasField($destField)) {
            continue;
          }

          $sourceValue = $sourceData[$sourceKey] ?? NULL;
          $destValue = $this->getDestinationValue($destEntity, $destField);

          // Skip fix if source value is NULL and it's a critical field
          // to avoid SQL integrity violations, unless we really want to clear it.
          if ($sourceValue === NULL && in_array($destField, ['title', 'created', 'changed', 'uid'])) {
            continue;
          }

          if (!$this->isEqual($sourceValue, $destValue)) {
            $this->applyFix($destEntity, $destField, $sourceValue, $map);
            $fieldsFixed[] = $destField;
            $nodeChanged = TRUE;
          }
        }

        if ($nodeChanged) {
          $destEntity->save();
          $this->migrationTracker->updateIntegrityStatus($entityType, $sid, 1);
          // We don't want to break the progress bar with success messages for every node.
          // But we can overwrite the progress bar message or just log it if we use a quieter approach.
          // For now, let's just advance the progress bar.
          $totalFixedNodes++;
          $totalFixedFields += count($fieldsFixed);
        }
        $progressBar->advance();
      }

      $progressBar->finish();
      $this->io()->newLine(2);

      foreach (array_unique($deferredMessages) as $message) {
        $this->io()->warning($message);
      }

      $this->io()->note(sprintf('Total: %d nodes updated, %d fields corrected.', $totalFixedNodes, $totalFixedFields));
    }
    catch (\Exception $e) {
      $this->io()->error($e->getMessage());
    }
  }

  /**
   * Applies the fix to a destination field.
   */
  protected function applyFix(EntityInterface $entity, string $fieldName, $sourceValue, $map = NULL): void {
    $fieldDefinition = $entity->getFieldDefinition($fieldName);
    $fieldType = $fieldDefinition->getType();
    $cardinality = $fieldDefinition->getFieldStorageDefinition()->getCardinality();

    // Specific case for Geofield with WKT.
    if ($fieldType === 'geofield' && is_string($sourceValue) && strpos($sourceValue, ',') !== FALSE) {
      list($lat, $lng) = explode(',', $sourceValue);
      $sourceValue = sprintf('POINT (%f %f)', (float)trim($lng), (float)trim($lat));
    }

    if ($sourceValue === NULL) {
      $entity->set($fieldName, NULL);
      return;
    }

    // Handle special types from mapping.
    if ($map instanceof \Drupal\labdoo_migrate\Model\MappingModel) {
      $specialType = $map->getDestinationField()->getSpecialType();
      if ($specialType && $specialType->getType() === 'managed_file') {
        if (!empty($sourceValue)) {
          try {
            if (is_array($sourceValue)) {
              $fids = [];
              foreach ($sourceValue as $uri) {
                if (strpos($uri, '://') !== FALSE) {
                  $fileContents = $this->fileManager->getFileContents($uri, FALSE, TRUE);
                  $fileName = $this->fileManager->buildFileName($uri);
                  $fileEntity = $this->fileManager->createFile($uri, $fileName, $fileContents);
                  if ($fileEntity) {
                    $fids[] = ['target_id' => $fileEntity->id()];
                  }
                }
              }
              $entity->set($fieldName, $fids);
            }
            elseif (is_string($sourceValue) && strpos($sourceValue, '://') !== FALSE) {
              $fileContents = $this->fileManager->getFileContents($sourceValue, FALSE, TRUE);
              $fileName = $this->fileManager->buildFileName($sourceValue);
              $fileEntity = $this->fileManager->createFile($sourceValue, $fileName, $fileContents);
              if ($fileEntity) {
                $entity->set($fieldName, ['target_id' => $fileEntity->id()]);
              }
            }
          }
          catch (\Exception $e) {
            // Log error but don't stop the whole process.
          }
        }
        else {
          $entity->set($fieldName, NULL);
        }
        return;
      }
    }

    // Normalize source value.
    if ($cardinality == 1 && is_array($sourceValue)) {
      $sourceValue = !empty($sourceValue) ? reset($sourceValue) : NULL;
    }
    elseif ($cardinality != 1 && !is_array($sourceValue)) {
      $sourceValue = $sourceValue !== NULL ? [$sourceValue] : [];
    }

    switch ($fieldType) {
      case 'address':
        // Address fields usually expect an array with specific keys.
        // If sourceValue is just a country code string.
        if (is_string($sourceValue) && strlen($sourceValue) == 2) {
          $entity->set($fieldName, ['country_code' => strtoupper($sourceValue)]);
        }
        else {
          $entity->set($fieldName, $sourceValue);
        }
        break;

      case 'geofield':
      case 'geolocation':
        // Handle geofield (WKT) or geolocation (lat/lng array).
        if ($fieldType === 'geofield') {
          // Geofield expects WKT string like "POINT (longitude latitude)".
          // Already handled normalization above.
          $entity->set($fieldName, $sourceValue);
        }
        else {
          // Geolocation expects 'lat' and 'lng'.
          if (is_string($sourceValue) && strpos($sourceValue, ',') !== FALSE) {
            list($lat, $lng) = explode(',', $sourceValue);
            $entity->set($fieldName, ['lat' => trim($lat), 'lng' => trim($lng)]);
          }
          else {
            $entity->set($fieldName, $sourceValue);
          }
        }
        break;

      case 'entity_reference':
      case 'file':
      case 'image':
        // If it's a target_id (FID for files/images).
        $entity->set($fieldName, $sourceValue);
        break;

      case 'datetime':
      case 'timestamp':
      case 'created':
      case 'changed':
        if ($fieldName === 'changed' && is_numeric($sourceValue)) {
          $entity->set($fieldName, (int) $sourceValue);
          // For node entities, we need to ensure the changed time is NOT overwritten by saving.
          if ($entity instanceof \Drupal\node\NodeInterface) {
            $entity->setChangedTime((int) $sourceValue);
          }
        }
        else {
          $entity->set($fieldName, $sourceValue);
        }
        break;

      default:
        $entity->set($fieldName, $sourceValue);
        break;
    }
  }

  /**
   * Gets a simplified value from a destination node field for comparison.
   * (Copied from IntegrityCheckCommands)
   */
  protected function getDestinationValue(EntityInterface $entity, string $fieldName) {
    $field = $entity->get($fieldName);
    if ($field->isEmpty()) {
      return NULL;
    }

    $values = $field->getValue();
    $processedValues = [];
    foreach ($values as $val) {
      if (isset($val['latlon'])) {
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
   * (Copied and improved from IntegrityCheckCommands)
   */
  protected function isEqual($val1, $val2): bool {
    if (is_null($val1) && (is_null($val2) || $val2 === '' || (is_array($val2) && empty($val2)))) {
      return TRUE;
    }

    // Normalize source array to single value if needed.
    // In many D7 fields, they are technically arrays but mapped to single values.
    if (is_array($val1) && !is_array($val2) && count($val1) <= 1) {
       $val1 = !empty($val1) ? reset($val1) : NULL;
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
      // If one is array and other is not, and the array has the scalar as its only element.
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
