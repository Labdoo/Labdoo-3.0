<?php

/**
 * Restore one of the scoped field backfills without saving entities.
 *
 * LABDOO_RESTORE_BACKUP=/private/backup.jsonl; default dry-run.
 * LABDOO_RESTORE_APPLY=1 restores only if current rows still match the repair.
 */

use Drupal\Core\Cache\Cache;

$path = getenv('LABDOO_RESTORE_BACKUP');
if (!$path || !is_file($path)) {
  throw new RuntimeException('A readable JSONL field backup is required.');
}
$apply = getenv('LABDOO_RESTORE_APPLY') === '1';
$db = \Drupal::database();
$report = ['mode' => $apply ? 'apply' : 'dry-run', 'eligible' => 0, 'restored' => 0, 'skipped_concurrent' => 0];
$stream = fopen($path, 'r');
try {
  while (($line = fgets($stream)) !== FALSE) {
    $entry = json_decode($line, TRUE, 512, JSON_THROW_ON_ERROR);
    $field = $entry['field'];
    if (!in_array($field, ['field_locations', 'field_location', 'field_origin_of_the_trip', 'field_destination_of_the_trip', 'field_edoovillage_destination', 'field_edoovillages_assigned', 'field_address'], TRUE)) {
      throw new RuntimeException('Unsupported field in backup.');
    }
    $nid = (int) $entry['nid'];
    $vid = (int) $entry['vid'];
    $table = 'node__' . $field;
    $revision_table = 'node_revision__' . $field;
    $transaction = $apply ? $db->startTransaction() : NULL;
    $sql = 'SELECT vid FROM {node} WHERE nid=:nid' . ($apply ? ' FOR UPDATE' : '');
    $current_vid = $db->query($sql, [':nid' => $nid])->fetchField();
    $rows = $db->select($table, 'f')->fields('f')->condition('entity_id', $nid)->condition('deleted', 0)->execute()->fetchAll(\PDO::FETCH_ASSOC);
    $revision_rows = $db->select($revision_table, 'f')->fields('f')->condition('entity_id', $nid)->condition('revision_id', $vid)->condition('deleted', 0)->execute()->fetchAll(\PDO::FETCH_ASSOC);
    if ((int) $current_vid !== $vid || $rows != $entry['after'] || $revision_rows != $entry['after']) {
      $report['skipped_concurrent']++;
      unset($transaction);
      continue;
    }
    $report['eligible']++;
    if ($apply) {
      $db->delete($table)->condition('entity_id', $nid)->condition('deleted', 0)->execute();
      $db->delete($revision_table)->condition('entity_id', $nid)->condition('revision_id', $vid)->condition('deleted', 0)->execute();
      foreach ($entry['before'] as $row) {
        $db->insert($table)->fields($row)->execute();
      }
      foreach ($entry['revision_before'] as $row) {
        $db->insert($revision_table)->fields($row)->execute();
      }
      $report['restored']++;
    }
    unset($transaction);
    if ($apply) {
      \Drupal::entityTypeManager()->getStorage('node')->resetCache([$nid]);
      Cache::invalidateTags(['node:' . $nid, 'node_list']);
    }
  }
  echo json_encode($report, JSON_PRETTY_PRINT) . PHP_EOL;
}
catch (\Throwable $exception) {
  if (isset($transaction)) {
    $transaction->rollBack();
  }
  throw $exception;
}
finally {
  fclose($stream);
}
