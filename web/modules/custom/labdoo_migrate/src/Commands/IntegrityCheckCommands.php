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
   *   The number of random nodes to check.
   * @option destination-type
   *   The destination content type (bundle) in Drupal 10.
   * @option nids
   *   Specific source IDs to check.
   *
   * @command labdoo:migrate-check-integrity
   * @aliases lm-ci
   * @usage drush lm-ci story --limit=10
   */
  public function checkIntegrity(string $contentType, array $options = ['limit' => 5, 'destination-type' => NULL, 'nids' => NULL]): void {
    $limit = (int) $options['limit'];
    $destinationType = $options['destination-type'] ?? $contentType;
    $nids = $options['nids'] ? explode(',', $options['nids']) : [];

    $this->io()->title(sprintf('Checking integrity for %d random migrated nodes of type %s (D7) -> %s (D10)', $limit, $contentType, $destinationType));

    try {
      $config = $this->configurationManager->getContentConfiguration($contentType);
      $entityType = $config->getEntityType();
      $mapping = $this->mapper->buildMapping($config->getFieldsMapping());

      if (!empty($nids)) {
        $selectedIds = $nids;
      }
      else {
        $allIds = $this->migrationTracker->getMigratedSourceIds($entityType, $destinationType);

        if (empty($allIds)) {
          $this->io()->warning('No migrated nodes found for this content type.');
          return;
        }

        shuffle($allIds);
        $selectedIds = array_slice($allIds, 0, $limit);
      }

      $results = [];
      foreach ($selectedIds as $sid) {
        $this->io()->text("Checking Source ID: $sid");

        $destId = $this->migrationTracker->getDestinationIdBySourceId($entityType, $sid);
        if (!$destId) {
          $results[] = [$sid, 'N/A', 'ERROR', 'Not found in D10'];
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
        $sourceDataRaw = $sourceRepo->getEntity($contentType, $mapping, $sid);
        $this->externalConnectionManager->restoreConnection();

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
          continue;
        }

        $entityErrors = $this->compareData($sourceData, $destEntity, $mapping);
        if (empty($entityErrors)) {
          $results[] = [$sid, $destId, 'OK', 'All fields match'];
        }
        else {
          foreach ($entityErrors as $error) {
            $results[] = [$sid, $destId, 'ERROR', $error];
          }
        }
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
  protected function compareData(array $sourceData, EntityInterface $destNode, array $mapping): array {
    $errors = [];
    /** @var \Drupal\labdoo_migrate\Model\MappingModel $map */
    foreach ($mapping as $map) {
      $sourceKey = $map->getSourceIdentifier();
      $destField = $map->getDestinationField()->getFieldName();

      // Some fields might be internal metadata or ignored.
      if (in_array($destField, ['nid', 'vid', 'type', 'uuid', 'langcode', 'pass', 'preferred_langcode'])) {
        continue;
      }

      $sourceValue = $sourceData[$sourceKey] ?? NULL;

      // Special handling for different field types might be needed.
      // For now, let's do a basic comparison of values.
      if (!$destNode->hasField($destField)) {
        continue;
      }

      $destValue = $this->getDestinationValue($destNode, $destField);

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
      if (isset($val['latlon'])) {
        $processedValues[] = $val['latlon'];
      }
      elseif (isset($val['target_id'])) {
        $processedValues[] = $val['target_id'];
      }
      elseif (isset($val['value'])) {
        $processedValues[] = $val['value'];
      }
      else {
        $processedValues[] = $val;
      }
    }

    if (count($processedValues) === 1) {
      return $processedValues[0];
    }

    return $processedValues;
  }

  /**
   * Compare two values for equality.
   */
  protected function isEqual($val1, $val2): bool {
    if (is_null($val1) && is_null($val2)) {
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
      return FALSE;
    }

    // Handle numeric strings with different precision/trailing zeros.
    if (is_string($val1) && is_string($val2) && strpos($val1, ',') !== FALSE && strpos($val2, ',') !== FALSE) {
      $parts1 = explode(',', $val1);
      $parts2 = explode(',', $val2);
      if (count($parts1) === 2 && count($parts2) === 2) {
        if (is_numeric($parts1[0]) && is_numeric($parts1[1]) && is_numeric($parts2[0]) && is_numeric($parts2[1])) {
          return abs((float)$parts1[0] - (float)$parts2[0]) < 0.000001 && abs((float)$parts1[1] - (float)$parts2[1]) < 0.000001;
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
    return (string) $val1 === (string) $val2;
  }

}
