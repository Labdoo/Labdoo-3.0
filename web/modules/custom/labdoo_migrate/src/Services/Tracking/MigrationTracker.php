<?php

namespace Drupal\labdoo_migrate\Services\Tracking;

use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\labdoo_migrate\Services\Database\ConnectionManagerInterface;

/**
 * Tracks migrated entities and builds migration dashboard metrics.
 */
class MigrationTracker implements MigrationTrackerInterface {

  /**
   * Internal tracking table name.
   */
  private const TRACKING_TABLE = 'labdoo_migrate_tracking';

  /**
   * The Drupal 10 database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  private Connection $database;

  /**
   * The external database manager (Drupal 7).
   *
   * @var \Drupal\labdoo_migrate\Services\Database\ConnectionManagerInterface
   */
  private ConnectionManagerInterface $externalConnectionManager;

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  private ModuleHandlerInterface $moduleHandler;

  /**
   * The date formatter.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  private DateFormatterInterface $dateFormatter;

  /**
   * MigrationTracker constructor.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The Drupal 10 database connection.
   * @param \Drupal\labdoo_migrate\Services\Database\ConnectionManagerInterface $externalConnectionManager
   *   The external database manager.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $dateFormatter
   *   The date formatter service.
   */
  public function __construct(
    Connection $database,
    ConnectionManagerInterface $externalConnectionManager,
    ModuleHandlerInterface $moduleHandler,
    DateFormatterInterface $dateFormatter
  ) {
    $this->database = $database;
    $this->externalConnectionManager = $externalConnectionManager;
    $this->moduleHandler = $moduleHandler;
    $this->dateFormatter = $dateFormatter;
  }

  /**
   * {@inheritDoc}
   */
  public function track(string $entityType, string $bundle, int $sourceId, int $destinationId, int $durationMs = 0): void {
    $this->database->merge(self::TRACKING_TABLE)
      ->keys([
        'entity_type' => $entityType,
        'bundle' => $bundle,
        'source_id' => $sourceId,
      ])
      ->fields([
        'destination_id' => $destinationId,
        'last_migrated' => time(),
        'duration_ms' => max(0, $durationMs),
      ])
      ->execute();
  }

  /**
   * {@inheritDoc}
   */
  public function getDashboardRows(): array {
    $rows = [];
    $mappings = $this->getConfiguredContentTypes();

    $externalConnection = $this->externalConnectionManager->setConnection();
    try {
      foreach ($mappings as $mapping) {
        $rows[] = $this->buildRow($externalConnection, $mapping);
      }
    }
    finally {
      $this->externalConnectionManager->restoreConnection();
    }

    return $rows;
  }

  /**
   * Builds one dashboard row.
   *
   * @param \Drupal\Core\Database\Connection $externalConnection
   *   The external Drupal 7 connection.
   * @param array $mapping
   *   The mapping data.
   *
   * @return array
   *   Row data.
   */
  protected function buildRow(Connection $externalConnection, array $mapping): array {
    $sourceType = $mapping['source_type'];
    $destinationType = $mapping['destination_type'];
    $entityType = $mapping['entity_type'];
    $isUserType = $entityType === 'user';

    $d7Count = $isUserType
      ? $this->countDrupal7Users($externalConnection)
      : $this->countDrupal7Nodes($externalConnection, $sourceType);

    $d10Count = $isUserType
      ? $this->countDrupal10Users()
      : $this->countDrupal10Nodes($destinationType);

    $trackingData = $this->getTrackingData($entityType, $destinationType);

    return [
      'entity_type' => $destinationType,
      'd7_count' => $d7Count,
      'd10_count' => $d10Count,
      'migrated_count' => $trackingData['migrated_count'],
      'avg_duration_ms' => $trackingData['avg_duration_ms'],
      'last_migration' => $trackingData['last_migrated'] > 0
        ? $this->dateFormatter->format($trackingData['last_migrated'], 'custom', 'Y-m-d H:i:s')
        : 'N/A',
    ];
  }

  /**
   * Returns available content types and their mappings from config files.
   *
   * @return array
   *   Mapping data keyed by source type.
   */
  protected function getConfiguredContentTypes(): array {
    $modulePath = $this->moduleHandler->getModule('labdoo_migrate')->getPath();
    $files = glob(sprintf('%s/config/fields_mapping/*.json', $modulePath));
    if (!$files) {
      return [];
    }

    $mappings = [];
    foreach ($files as $filePath) {
      $name = pathinfo($filePath, PATHINFO_FILENAME);
      if ($name === 'global') {
        continue;
      }

      $content = file_get_contents($filePath);
      if (!$content) {
        continue;
      }

      $data = json_decode($content, TRUE);
      if (!$data || !isset($data['source_type'])) {
        continue;
      }

      $sourceType = $data['source_type'];
      $mappings[$sourceType] = [
        'source_type' => $sourceType,
        'destination_type' => $data['destination_types'][0] ?? $sourceType,
        'entity_type' => $data['entity_type'] ?? 'node',
      ];
    }

    ksort($mappings);
    return $mappings;
  }

  /**
   * Counts Drupal 7 nodes by type.
   */
  protected function countDrupal7Nodes(Connection $externalConnection, string $contentType): int {
    return (int) $externalConnection->select('node', 'n')
      ->condition('n.type', $contentType)
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Counts Drupal 10 nodes by type (base language rows only).
   */
  protected function countDrupal10Nodes(string $contentType): int {
    return (int) $this->database->select('node_field_data', 'n')
      ->condition('n.type', $contentType)
      ->condition('n.default_langcode', 1)
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Counts Drupal 7 users.
   */
  protected function countDrupal7Users(Connection $externalConnection): int {
    return (int) $externalConnection->select('users', 'u')
      ->condition('u.uid', 0, '>')
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Counts Drupal 10 users.
   */
  protected function countDrupal10Users(): int {
    return (int) $this->database->select('users_field_data', 'u')
      ->condition('u.uid', 0, '>')
      ->condition('u.default_langcode', 1)
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Returns tracking totals and latest migration timestamp for a bundle.
   */
  protected function getTrackingData(string $entityType, string $bundle): array {
    $query = $this->database->select(self::TRACKING_TABLE, 't');
    $query->condition('t.entity_type', $entityType);
    $query->condition('t.bundle', $bundle);
    $query->addExpression('COUNT(*)', 'migrated_count');
    $query->addExpression('MAX(t.last_migrated)', 'last_migrated');
    $query->addExpression('AVG(t.duration_ms)', 'avg_duration_ms');

    $result = $query->execute()->fetchAssoc() ?: [];

    return [
      'migrated_count' => (int) ($result['migrated_count'] ?? 0),
      'last_migrated' => (int) ($result['last_migrated'] ?? 0),
      'avg_duration_ms' => (float) ($result['avg_duration_ms'] ?? 0),
    ];
  }

}
