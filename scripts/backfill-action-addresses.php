<?php

/**
 * Fill missing action city/country from unambiguous matching V2 coordinates.
 *
 * Default dry-run. LABDOO_ACTION_APPLY=1 requires LABDOO_ACTION_BACKUP pointing
 * to a new private JSONL file outside the document root. LABDOO_ACTION_LIMIT
 * bounds execution. No entity saves, revisions, geocoding or new identifiers.
 */

use Drupal\Core\Cache\Cache;
use Drupal\node\Entity\Node;

$apply = getenv('LABDOO_ACTION_APPLY') === '1';
$limit = max(0, (int) getenv('LABDOO_ACTION_LIMIT'));
$backup = NULL;
if ($apply) {
  $path = getenv('LABDOO_ACTION_BACKUP');
  $parent = $path ? realpath(dirname($path)) : FALSE;
  if (!$parent || !str_starts_with($path, '/') || str_starts_with($parent . '/', realpath(\Drupal::root()) . '/')) {
    throw new RuntimeException('A new private backup outside the document root is required.');
  }
  $backup = fopen($path, 'x');
  if (!$backup || !chmod($path, 0600)) {
    throw new RuntimeException('Cannot create a private backup.');
  }
}
$lock = \Drupal::lock();
if (!$lock->acquire('labdoo.content.action_address_backfill', 3600)) {
  throw new RuntimeException('Another action-address backfill is running.');
}
try {
  ob_start();
  try {
    require __DIR__ . '/audit-action-address-parity.php';
    $audit = json_decode(ob_get_contents(), TRUE, 512, JSON_THROW_ON_ERROR);
  }
  finally {
    ob_end_clean();
  }
  $db = \Drupal::database();
  $report = ['mode' => $apply ? 'apply' : 'dry-run', 'eligible' => 0, 'repaired' => 0, 'skipped_concurrent' => 0];
  $field = 'field_address';
  $table = 'node__' . $field;
  $revision_table = 'node_revision__' . $field;
  $dummy = Node::create(['type' => 'action']);
  $properties = array_keys($dummy->get($field)->getFieldDefinition()->getFieldStorageDefinition()->getColumns());
  foreach (array_chunk($audit['recovery_candidates'], 100, TRUE) as $batch) {
    $transaction = $apply ? $db->startTransaction() : NULL;
    $changed = [];
    foreach ($batch as $nid => $candidate) {
      $report['eligible']++;
      if (!$apply) {
        if ($limit && $report['eligible'] >= $limit) {
          break;
        }
        continue;
      }
      $current_vid = $db->query('SELECT vid FROM {node} WHERE nid=:nid FOR UPDATE', [':nid' => $nid])->fetchField();
      if ((int) $current_vid !== (int) $candidate['vid']) {
        $report['skipped_concurrent']++;
        continue;
      }
      $before = $db->select($table, 'f')->fields('f')->condition('entity_id', $nid)->condition('deleted', 0)->execute()->fetchAll(\PDO::FETCH_ASSOC);
      $after = $before;
      $index = NULL;
      foreach ($before as $i => $row) {
        if ($row['langcode'] === $candidate['langcode'] && (int) $row['delta'] === 0) {
          $index = $i;
          break;
        }
      }
      $row = $index !== NULL ? $before[$index] : ['bundle' => 'action', 'deleted' => 0, 'entity_id' => $nid, 'revision_id' => $candidate['vid'], 'langcode' => $candidate['langcode'], 'delta' => 0];
      $current_city = $row[$field . '_locality'] ?? NULL;
      $current_country = $row[$field . '_country_code'] ?? NULL;
      if ($current_city !== $candidate['before_city'] || $current_country !== $candidate['before_country']) {
        $report['skipped_concurrent']++;
        continue;
      }
      foreach ($properties as $property) {
        $row += [$field . '_' . $property => NULL];
      }
      $row[$field . '_locality'] = $candidate['after']['city'];
      $row[$field . '_country_code'] = $candidate['after']['country'];
      if ($index === NULL) {
        $after[] = $row;
      }
      else {
        $after[$index] = $row;
      }
      $revision_before = $db->select($revision_table, 'f')->fields('f')->condition('entity_id', $nid)->condition('revision_id', $candidate['vid'])->condition('deleted', 0)->execute()->fetchAll(\PDO::FETCH_ASSOC);
      $entry = ['field' => $field, 'nid' => $nid, 'vid' => $candidate['vid'], 'before' => $before, 'revision_before' => $revision_before, 'after' => $after];
      if (fwrite($backup, json_encode($entry, JSON_THROW_ON_ERROR) . PHP_EOL) === FALSE || !fflush($backup) || !fsync($backup)) {
        throw new RuntimeException('Cannot persist backup; transaction aborted.');
      }
      $db->delete($table)->condition('entity_id', $nid)->condition('deleted', 0)->execute();
      $db->delete($revision_table)->condition('entity_id', $nid)->condition('revision_id', $candidate['vid'])->condition('deleted', 0)->execute();
      foreach ($after as $row) {
        $row['revision_id'] = $candidate['vid'];
        $db->insert($table)->fields($row)->execute();
        $db->insert($revision_table)->fields($row)->execute();
      }
      $changed[] = $nid;
      $report['repaired']++;
      if ($limit && $report['eligible'] >= $limit) {
        break;
      }
    }
    unset($transaction);
    if ($changed) {
      \Drupal::entityTypeManager()->getStorage('node')->resetCache($changed);
      Cache::invalidateTags(array_merge(array_map(static fn($id) => 'node:' . $id, $changed), ['node_list:action']));
    }
    if ($limit && $report['eligible'] >= $limit) {
      break;
    }
  }
  if ($apply && $report['repaired'] > 0) {
    Cache::invalidateTags(['node_list']);
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
  if (is_resource($backup)) {
    fclose($backup);
  }
  $lock->release('labdoo.content.action_address_backfill');
}
