<?php

/**
 * Isolated integration test for field recovery and rollback without new IDs.
 *
 * LABDOO_TEST_REPAIR=geo|s3|actions drush php:script this-file.php.
 */

require __DIR__ . '/assert-isolated.php';

use Drupal\Core\Database\Database;
use Drupal\Core\Site\Settings;

$testKind = getenv('LABDOO_TEST_REPAIR') ?: 'geo';
$testSettings = ['geo' => ['GEO', 'backfill-valid-v2-geolocations.php'], 's3' => ['S3', 'backfill-s3-school-references.php'], 'actions' => ['ACTION', 'backfill-action-addresses.php']];
if (!isset($testSettings[$testKind])) {
  throw new RuntimeException('Unknown isolated repair test.');
}
$testState = Settings::get('labdoo_e2e_state');
Database::removeConnection('external');
Database::addConnectionInfo('external', 'default', json_decode(file_get_contents($testState . '/v2-readonly-connection.json'), TRUE));
$testDb = \Drupal::database();
$testBefore = $testDb->query('SELECT COUNT(*) AS count, MAX(nid) AS max FROM {node}')->fetchAssoc();
$testRevisionBefore = $testDb->query('SELECT COUNT(*) AS count, MAX(vid) AS max FROM {node_revision}')->fetchAssoc();
$testOriginal = [];
if ($testKind === 's3') {
  // Temporarily simulate the dangling reference in an existing local laptop.
  foreach (['node__field_edoovillage_destination', 'node_revision__field_edoovillage_destination'] as $table) {
    $query = $testDb->select($table, 'f')->fields('f')->condition('entity_id', 73875)->condition('deleted', 0);
    if (str_starts_with($table, 'node_revision')) {
      $query->condition('revision_id', $testDb->query('SELECT vid FROM {node} WHERE nid=73875')->fetchField());
    }
    $testOriginal[$table] = $query->execute()->fetchAll(\PDO::FETCH_ASSOC);
    foreach ($testOriginal[$table] as $row) {
      $testDb->update($table)->fields(['field_edoovillage_destination_target_id' => 73054])->condition('entity_id', 73875)->condition('revision_id', $row['revision_id'])->condition('langcode', $row['langcode'])->condition('delta', $row['delta'])->execute();
    }
  }
}
$testRun = static function ($script): array {
  ob_start();
  try {
    require $script;
    return json_decode(ob_get_contents(), TRUE, 512, JSON_THROW_ON_ERROR);
  }
  finally {
    ob_end_clean();
  }
};
[$testPrefix, $testScript] = $testSettings[$testKind];
$testBackup = $testState . '/content-field-' . $testKind . '-' . bin2hex(random_bytes(8)) . '.jsonl';
putenv('LABDOO_' . $testPrefix . '_APPLY=1');
putenv('LABDOO_' . $testPrefix . '_LIMIT=3');
putenv('LABDOO_' . $testPrefix . '_BACKUP=' . $testBackup);
$testResult = NULL;
$testRollback = NULL;
try {
  $testResult = $testRun(dirname(__DIR__, 3) . '/scripts/' . $testScript);
  $testCount = is_array($testResult['repaired']) ? count($testResult['repaired']) : $testResult['repaired'];
  if ($testCount < 1) {
    throw new RuntimeException('No source-backed repair was exercised.');
  }
  $testEntries = array_map(static fn($line) => json_decode($line, TRUE), file($testBackup, FILE_IGNORE_NEW_LINES));
  foreach ($testEntries as $entry) {
    foreach (['node__', 'node_revision__'] as $prefix) {
      $query = $testDb->select($prefix . $entry['field'], 'f')->fields('f')->condition('entity_id', $entry['nid'])->condition('deleted', 0);
      if ($prefix === 'node_revision__') {
        $query->condition('revision_id', $entry['vid']);
      }
      if ($query->execute()->fetchAll(\PDO::FETCH_ASSOC) != $entry['after']) {
        throw new RuntimeException('Repaired field or current revision differs from its recovery plan.');
      }
    }
  }
}
finally {
  if (is_file($testBackup) && filesize($testBackup) > 0) {
    putenv('LABDOO_RESTORE_BACKUP=' . $testBackup);
    putenv('LABDOO_RESTORE_APPLY=1');
    $testRollback = $testRun(dirname(__DIR__, 3) . '/scripts/restore-content-field-backup.php');
  }
  foreach ($testOriginal as $table => $rows) {
    foreach ($rows as $row) {
      $testDb->update($table)->fields(['field_edoovillage_destination_target_id' => $row['field_edoovillage_destination_target_id']])->condition('entity_id', 73875)->condition('revision_id', $row['revision_id'])->condition('langcode', $row['langcode'])->condition('delta', $row['delta'])->execute();
    }
  }
}
if (($testRollback['restored'] ?? 0) !== $testCount || $testRollback['skipped_concurrent'] !== 0) {
  throw new RuntimeException('The tested field repair could not be rolled back exactly.');
}
if ($testDb->query('SELECT COUNT(*) AS count, MAX(nid) AS max FROM {node}')->fetchAssoc() !== $testBefore || $testDb->query('SELECT COUNT(*) AS count, MAX(vid) AS max FROM {node_revision}')->fetchAssoc() !== $testRevisionBefore) {
  throw new RuntimeException('Node or revision identifier invariants changed.');
}
echo 'PROGRAM_JSON:' . json_encode(['passed' => TRUE, 'kind' => $testKind, 'recovery' => $testResult, 'rollback' => $testRollback, 'identifiers_unchanged' => TRUE]);
