<?php

namespace Drupal\labdoo_migrate\Services\Incremental;

use Drupal\Core\Database\Connection;
use Drupal\node\NodeInterface;

/** Explicit internal fields and revision-preserving parent counter repair. */
final class DerivedFields {

  public function __construct(private Connection $database) {}

  /** Only fields assigned by the existing Compute services, never author input. */
  public static function computedFields(string $bundle): array {
    return match ($bundle) {
      'hub' => ['field_dootronics_needed', 'field_dootronics_delivered', 'field_dootronics_in_transit', 'field_dootronics_remaining', 'field_dootronics_completed'],
      'edoovillage' => ['field_dootronics_delivered', 'field_dootronics_in_transit', 'field_dootronics_remaining'],
      'dootrip' => ['field_co2_savings_dootrip', 'field_distance_dootrip', 'field_dootrip_weight', 'field_number_of_doojects_assigne', 'field_dootronics_delivered', 'field_dootronics_in_transit', 'field_full', 'field_locations', 'field_edoovillages_assigned'],
      'dootronic' => ['field_tagged', 'field_battery_watt_hours'],
      default => [],
    };
  }

  private function guard(NodeInterface $node): array {
    if ($this->database !== \Drupal::database() || !in_array($node->bundle(), ['hub', 'edoovillage'], TRUE)) {
      throw new \RuntimeException('Directed parent recompute requires the local hub/edoovillage node.');
    }
    $row = $this->database->select('node', 'n')->fields('n')->condition('nid', (int) $node->id())->forUpdate()->execute()->fetchAssoc();
    if (!$row || $row['type'] !== $node->bundle() || (int) $row['vid'] !== (int) $node->getRevisionId()) {
      throw new \RuntimeException('Parent identity/current revision changed; reload before recomputing.');
    }
    return $row;
  }

  private function rows(string $table, int $nid, int $vid, bool $revision): array {
    foreach (['entity_id', 'revision_id', 'bundle', 'deleted', 'langcode', 'delta'] as $column) {
      if (!$this->database->schema()->fieldExists($table, $column)) { throw new \RuntimeException('Unexpected derived field table schema: ' . $table); }
    }
    $query = $this->database->select($table, 'f')->fields('f')->condition('entity_id', $nid)->condition('deleted', 0)->orderBy('langcode')->orderBy('delta')->forUpdate();
    if ($revision) { $query->condition('revision_id', $vid); }
    return $query->execute()->fetchAll(\PDO::FETCH_ASSOC);
  }

  private function where($query, array $before): void {
    foreach ($before as $column => $value) {
      if ($value === NULL) { $query->isNull($column); }
      else { $query->condition($column, $value); }
    }
  }

  private function invalidateAndTrack(NodeInterface $node): array {
    $id = (int) $node->id();
    $bundle = $node->bundle();
    $tags = ['node:' . $id, 'node:' . $bundle, $bundle === 'hub' ? 'hub_chart' : 'edoovillages_chart', $bundle . ':' . $id . ':' . $node->getOwnerId()];
    // Persistent cache invalidation happens only after the outer transaction
    // commits, so a concurrent reader cannot cache the pre-commit counters.
    $this->database->transactionManager()->addPostTransactionCallback(static function (bool $success) use ($tags): void {
      if ($success) { \Drupal::service('cache_tags.invalidator')->invalidateTags($tags); }
    });
    if (\Drupal::hasService('search_api.entity_datasource.tracking_manager')) {
      $fresh = ReadOnlyEntityLoader::storage('node')->load($id);
      $fresh->search_api_skip_tracking = FALSE;
      \Drupal::service('search_api.entity_datasource.tracking_manager')->entityUpdate($fresh);
    }
    return $tags;
  }

  /** Same formulas as DootronicCompute, without entity save or new revisions. */
  public function recompute(NodeInterface $node): array {
    $transaction = $this->database->startTransaction();
    try {
      $identity = $this->guard($node);
      $nid = (int) $node->id();
      $vid = (int) $identity['vid'];
      $bundle = $node->bundle();
      $defaultLanguage = $node->getUntranslated()->language()->getId();
      $dependencies = [];
      // Validate repository dependencies before calling its legacy catch-and-zero
      // methods: missing schema must never silently turn into zero counters.
      foreach (['node__field_dootronic_status' => ['entity_id', 'bundle', 'field_dootronic_status_value'], 'node__field_hub' => ['entity_id', 'field_hub_target_id'], 'node__field_number_of_laptops_needed' => ['entity_id', 'field_number_of_laptops_needed_value'], 'node__field_edoovillage_destination' => ['entity_id', 'field_edoovillage_destination_target_id']] as $table => $columns) {
        foreach ($columns as $column) {
          if (!$this->database->schema()->fieldExists($table, $column)) { throw new \RuntimeException('Missing counter repository dependency: ' . $table); }
        }
      }
      $repository = \Drupal::service('labdoo_common.repository.common');
      if ($bundle === 'hub') {
        $needed = (int) $repository->getDootronicsNeededByHub($nid);
        $delivered = (int) $repository->getDootronicsCountByStatus('S4', NULL, $nid);
        $inTransit = (int) $repository->getDootronicsCountByStatus('T1', NULL, $nid);
      }
      else {
        $query = $this->database->select('node__field_number_of_laptops_needed', 'f')->fields('f')->condition('entity_id', $nid)->condition('deleted', 0)->condition('delta', 0)->condition('langcode', $defaultLanguage)->forUpdate();
        $dependencies = $query->execute()->fetchAll(\PDO::FETCH_ASSOC);
        if (count($dependencies) > 1) { throw new \RuntimeException('Ambiguous authoritative edoovillage demand.'); }
        $needed = (int) ($dependencies[0]['field_number_of_laptops_needed_value'] ?? 0);
        $delivered = (int) $repository->getDootronicsCountByStatus('S4', $nid);
        $inTransit = (int) $repository->getDootronicsCountByStatus('T1', $nid);
      }
      $remaining = max(0, $needed - $delivered - $inTransit);
      $values = ['field_dootronics_delivered' => $delivered, 'field_dootronics_in_transit' => $inTransit, 'field_dootronics_remaining' => $remaining];
      if ($bundle === 'hub') {
        $completed = $needed === 0 ? 0 : max(0, ($needed - $remaining) * 100 / $needed);
        $values += ['field_dootronics_needed' => $needed, 'field_dootronics_completed' => $completed];
      }
      $record = ['nid' => $nid, 'vid' => $vid, 'bundle' => $bundle, 'changed' => FALSE, 'tables' => [], 'dependency_before' => $dependencies, 'values' => $values];
      foreach ($values as $field => $value) {
        if (!in_array($field, self::computedFields($bundle), TRUE) || !$node->hasField($field)) {
          throw new \RuntimeException('Missing or non-allowlisted derived field: ' . $field);
        }
        $definition = $node->getFieldDefinition($field);
        $storage = $definition->getFieldStorageDefinition();
        if ($storage->getCardinality() !== 1 || !in_array($storage->getType(), ['integer', 'decimal', 'float'], TRUE)) {
          throw new \RuntimeException('Unsupported derived numeric field storage: ' . $field);
        }
        $desired = match ($storage->getType()) {
          'integer' => (string) (int) $value,
          'decimal' => number_format((float) $value, (int) $storage->getSetting('scale'), '.', ''),
          default => str_replace(',', '.', sprintf('%.17g', (float) $value)),
        };
        $valueColumn = $field . '_value';
        foreach (['node__' . $field => FALSE, 'node_revision__' . $field => TRUE] as $table => $isRevision) {
          if (!$this->database->schema()->fieldExists($table, $valueColumn)) { throw new \RuntimeException('Missing derived value column.'); }
          $before = $this->rows($table, $nid, $vid, $isRevision);
          $byLanguage = [];
          foreach ($before as $row) {
            if ($row['bundle'] !== $bundle || (int) $row['revision_id'] !== $vid || (int) $row['delta'] !== 0 || isset($byLanguage[$row['langcode']])) {
              throw new \RuntimeException('Derived field rows do not match the current parent identity.');
            }
            $byLanguage[$row['langcode']] = TRUE;
            if ((string) $row[$valueColumn] !== $desired) {
              $update = $this->database->update($table)->fields([$valueColumn => $desired]);
              $this->where($update, $row);
              if ($update->execute() !== 1) { throw new \RuntimeException('Derived field changed concurrently.'); }
              $record['changed'] = TRUE;
            }
          }
          $languages = $definition->isTranslatable() ? array_keys($node->getTranslationLanguages()) : [$defaultLanguage];
          foreach ($languages as $lang) {
            if (!isset($byLanguage[$lang])) {
              $row = ['bundle' => $bundle, 'deleted' => 0, 'entity_id' => $nid, 'revision_id' => $vid, 'langcode' => $lang, 'delta' => 0, $valueColumn => $desired];
              $this->database->insert($table)->fields($row)->execute();
              $record['changed'] = TRUE;
            }
          }
          $after = $this->rows($table, $nid, $vid, $isRevision);
          if ($before !== $after) { $record['tables'][$table] = ['before' => $before, 'after' => $after]; }
        }
      }
      if ($record['changed']) { $record['cache_tags'] = $this->invalidateAndTrack($node); }
      unset($transaction);
      return $record;
    }
    catch (\Throwable $e) { $transaction->rollBack(); throw $e; }
  }
}
