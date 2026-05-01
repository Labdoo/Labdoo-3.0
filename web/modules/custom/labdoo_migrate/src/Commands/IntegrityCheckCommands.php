<?php

namespace Drupal\labdoo_migrate\Commands;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
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
   * IntegrityCheckCommands constructor.
   */
  public function __construct(
    ConfigurationManagerInterface $configurationManager,
    MapperInterface $mapper,
    SourceRepositoryInterface $sourceRepository,
    EntityTypeManagerInterface $entityTypeManager,
    MigrationTrackerInterface $migrationTracker,
    ConnectionManagerInterface $externalConnectionManager
  ) {
    parent::__construct();
    $this->configurationManager = $configurationManager;
    $this->mapper = $mapper;
    $this->sourceRepository = $sourceRepository;
    $this->entityTypeManager = $entityTypeManager;
    $this->migrationTracker = $migrationTracker;
    $this->externalConnectionManager = $externalConnectionManager;
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
   *
   * @command labdoo:migrate-check-integrity
   * @aliases lm-ci
   * @usage drush lm-ci story --limit=10
   */
  public function checkIntegrity(string $contentType, array $options = ['limit' => 5]): void {
    $limit = (int) $options['limit'];
    $this->io()->title(sprintf('Checking integrity for %d random nodes of type %s', $limit, $contentType));

    try {
      $config = $this->configurationManager->getContentConfiguration($contentType);
      $mapping = $this->mapper->buildMapping($config->getFieldsMapping());

      $this->externalConnectionManager->setConnection();
      $allNodeIds = $this->sourceRepository->getNodesByType($contentType, $mapping);
      $this->externalConnectionManager->restoreConnection();

      if (empty($allNodeIds)) {
        $this->io()->warning('No nodes found in source for this content type.');
        return;
      }

      shuffle($allNodeIds);
      $selectedIds = array_slice($allNodeIds, 0, $limit);

      $results = [];
      foreach ($selectedIds as $nid) {
        $this->io()->text("Checking Source Node ID: $nid");

        $destId = $this->migrationTracker->getDestinationIdBySourceId('node', $nid);
        if (!$destId) {
          $results[] = [$nid, 'N/A', 'ERROR', 'Not found in D10'];
          continue;
        }

        // Get source data.
        $this->externalConnectionManager->setConnection();
        $sourceDataRaw = $this->sourceRepository->getEntity($contentType, $mapping, $nid);
        $this->externalConnectionManager->restoreConnection();

        $mainLang = $sourceDataRaw['metadata']['main_langcode'] ?? 'en';
        $sourceData = $sourceDataRaw[$mainLang] ?? [];

        // Get destination node.
        /** @var \Drupal\node\NodeInterface $destNode */
        $destNode = $this->entityTypeManager->getStorage('node')->load($destId);
        if (!$destNode) {
          $results[] = [$nid, $destId, 'ERROR', 'D10 node could not be loaded'];
          continue;
        }

        $nodeErrors = $this->compareData($sourceData, $destNode, $mapping);
        if (empty($nodeErrors)) {
          $results[] = [$nid, $destId, 'OK', 'All fields match'];
        }
        else {
          foreach ($nodeErrors as $error) {
            $results[] = [$nid, $destId, 'ERROR', $error];
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
      if (in_array($destField, ['nid', 'vid', 'type', 'uuid', 'langcode'])) {
        continue;
      }

      $sourceValue = $sourceData[$sourceKey] ?? NULL;

      // Special handling for different field types might be needed.
      // For now, let's do a basic comparison of values.
      if (!$destNode->hasField($destField)) {
        $errors[] = "Field $destField does not exist in D10";
        continue;
      }

      $destValue = $this->getDestinationValue($destNode, $destField);

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
    if (count($values) === 1) {
      $val = $values[0];
      // common keys: value, target_id, uri, etc.
      if (isset($val['value'])) {
        return $val['value'];
      }
      if (isset($val['target_id'])) {
        return $val['target_id'];
      }
      return $val;
    }

    return $values;
  }

  /**
   * Compare two values for equality.
   */
  protected function isEqual($val1, $val2): bool {
    if (is_null($val1) && is_null($val2)) {
      return TRUE;
    }
    if (is_array($val1) && is_array($val2)) {
      return $val1 == $val2;
    }
    // Drupal 7 often has strings, Drupal 10 might have integers or strings.
    return (string) $val1 === (string) $val2;
  }

}
