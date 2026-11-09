<?php

/**
 * @file
 * Read-only migration inventory. Does not synchronize content or enqueue jobs.
 *
 * Run: drush php:script scripts/migration_readiness.php
 * This is a candidate inventory, not proof of field-by-field equality.
 */

use Drupal\Core\Database\Database;

$started = microtime(TRUE);
$destination = \Drupal::database();
$activeKey = $destination->getKey();
$report = [
  'captured_at' => gmdate('c'),
  'read_only' => TRUE,
  'batch_size' => 500,
  'limitations' => [
    'changed timestamps do not detect all field-only edits, translations, revisions or mapping changes',
    'a tracking timestamp is not a source content fingerprint or a synchronization watermark',
    'untracked existing entities must be reconciled before using create --incremental',
    'destination changes since migration are possible conflicts, not confirmed conflicts',
    'no records are created, updated, deleted or queued by this script',
  ],
  'bundles' => [],
];
$source = \Drupal::service('labdoo_migrate.database.external_connection_manager')->setConnection();
Database::setActiveConnection($activeKey);
try {
  $module = \Drupal::service('extension.list.module')->getPath('labdoo_migrate');
  foreach (glob($module . '/config/fields_mapping/*.json') as $file) {
    $mapping = json_decode(file_get_contents($file), TRUE, 512, JSON_THROW_ON_ERROR);
    if (($mapping['entity_type'] ?? 'node') !== 'node' || empty($mapping['source_type'])) {
      continue;
    }
    $type = $mapping['source_type'];
    if (!is_string($type)) {
      continue;
    }
    $bundle = $mapping['destination_types'][0] ?? $type;
    $row = [
      'source_type' => $type,
      'destination_bundle' => $bundle,
      'source_default_nodes' => 0,
      'destination_nodes' => (int) $destination->query('SELECT COUNT(*) FROM {node} WHERE type = :type', [':type' => $bundle])->fetchField(),
      'tracked_existing' => 0,
      'changed_since_tracking' => 0,
      'possible_destination_edits' => 0,
      'publication_mismatches' => 0,
      'untracked_existing' => 0,
      'untracked_ambiguous' => 0,
      'no_mapped_destination' => 0,
      'invalid_tracking_targets' => 0,
      'tracked_source_absent' => 0,
    ];
    $last = 0;
    do {
      $batch = $source->queryRange('SELECT nid, changed, status FROM {node} WHERE type = :type AND nid > :last AND (tnid = 0 OR tnid = nid) ORDER BY nid', 0, 500, [
        ':type' => $type,
        ':last' => $last,
      ])->fetchAllAssoc('nid');
      if (!$batch) {
        break;
      }
      $ids = array_keys($batch);
      $tracked = $destination->query('SELECT t.source_id, t.last_migrated, t.destination_id, n.type, d.changed, d.status FROM {labdoo_migrate_tracking} t LEFT JOIN {node} n ON n.nid = t.destination_id LEFT JOIN {node_field_data} d ON d.nid = n.nid AND d.default_langcode = 1 WHERE t.entity_type = :entity AND t.bundle = :bundle AND t.source_id IN (:ids[])', [
        ':entity' => 'node',
        ':bundle' => $bundle,
        ':ids[]' => $ids,
      ])->fetchAllAssoc('source_id');
      $untracked = [];
      if ($destination->schema()->tableExists('node__field_d7_nid')) {
        $references = $destination->query('SELECT f.field_d7_nid_value AS source_id, f.entity_id FROM {node__field_d7_nid} f INNER JOIN {node} n ON n.nid = f.entity_id WHERE n.type = :bundle AND f.deleted = 0 AND f.field_d7_nid_value IN (:ids[])', [
          ':bundle' => $bundle,
          ':ids[]' => $ids,
        ]);
        foreach ($references as $reference) {
          $untracked[$reference->source_id][$reference->entity_id] = TRUE;
        }
      }
      foreach ($batch as $id => $node) {
        $row['source_default_nodes']++;
        $track = $tracked[$id] ?? NULL;
        if ($track && $track->type === $bundle) {
          $row['tracked_existing']++;
          $row['changed_since_tracking'] += (int) ((int) $node->changed > (int) $track->last_migrated);
          $row['possible_destination_edits'] += (int) ((int) $track->changed > (int) $track->last_migrated);
          $row['publication_mismatches'] += (int) ((int) $node->status !== (int) $track->status);
        }
        else {
          $row['invalid_tracking_targets'] += (int) ($track !== NULL);
          $count = count($untracked[$id] ?? []);
          if ($count === 1) {
            $row['untracked_existing']++;
          }
          elseif ($count > 1) {
            $row['untracked_ambiguous']++;
          }
          else {
            $row['no_mapped_destination']++;
          }
        }
      }
      $last = (int) max($ids);
    } while (count($batch) === 500);

    $last = 0;
    do {
      $ids = $destination->queryRange('SELECT source_id FROM {labdoo_migrate_tracking} WHERE entity_type = :entity AND bundle = :bundle AND source_id > :last ORDER BY source_id', 0, 500, [
        ':entity' => 'node',
        ':bundle' => $bundle,
        ':last' => $last,
      ])->fetchCol();
      if (!$ids) {
        break;
      }
      $present = $source->query('SELECT nid FROM {node} WHERE nid IN (:ids[])', [':ids[]' => $ids])->fetchCol();
      $row['tracked_source_absent'] += count(array_diff($ids, $present));
      $last = (int) max($ids);
    } while (count($ids) === 500);
    $report['bundles'][] = $row;
  }
}
finally {
  Database::setActiveConnection($activeKey);
}
$report['seconds'] = round(microtime(TRUE) - $started, 3);
$report['peak_memory_bytes'] = memory_get_peak_usage(TRUE);
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
