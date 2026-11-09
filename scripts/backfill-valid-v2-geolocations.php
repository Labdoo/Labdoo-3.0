<?php

/**
 * Recover missing/invalid current coordinates from current V2 coordinates.
 *
 * Default is read-only. Apply with LABDOO_GEO_APPLY=1 and
 * LABDOO_GEO_BACKUP=/absolute/private/new-file.jsonl. Optional LABDOO_GEO_LIMIT.
 * No entity saves, revisions, actions, geocoding requests or identifiers.
 */

use Drupal\Core\Cache\Cache;
use Drupal\node\Entity\Node;

$apply = getenv('LABDOO_GEO_APPLY') === '1';
$limit = max(0, (int) getenv('LABDOO_GEO_LIMIT'));
$backup = NULL;
if ($apply) {
  $path = getenv('LABDOO_GEO_BACKUP');
  $parent = $path ? realpath(dirname($path)) : FALSE;
  if (!$parent || !str_starts_with($path, '/') || str_starts_with($parent . '/', realpath(\Drupal::root()) . '/')) {
    throw new RuntimeException('Apply requires a new backup file outside the public document root.');
  }
  $backup = fopen($path, 'x');
  if (!$backup || !chmod($path, 0600)) {
    throw new RuntimeException('Cannot create a private backup.');
  }
}
$lock = \Drupal::lock();
if (!$lock->acquire('labdoo.content.geo_backfill', 3600)) {
  throw new RuntimeException('Another geolocation backfill is running.');
}
try {
  ob_start();
  try {
    require __DIR__ . '/audit-geolocation-parity.php';
    $audit = json_decode(ob_get_contents(), TRUE, 512, JSON_THROW_ON_ERROR);
  }
  finally {
    ob_end_clean();
  }
  $db = \Drupal::database();
  $report = ['mode' => $apply ? 'apply' : 'dry-run', 'eligible' => 0, 'repaired' => 0, 'skipped_concurrent_or_ambiguous' => 0, 'fields' => []];
  foreach ($audit as $name => $data) {
    [$bundle, $field] = explode(':', $name);
    $candidates = $data['recovery_candidates'];
    if (!$candidates) {
      continue;
    }
    $dummy = Node::create(['type' => $bundle]);
    $stored_properties = [];
    foreach (array_keys($dummy->get($field)->getFieldDefinition()->getFieldStorageDefinition()->getColumns()) as $property) {
      $stored_properties[$property] = TRUE;
    }
    foreach (array_chunk($candidates, 100, TRUE) as $batch) {
      $ids = array_keys($batch);
      $metadata = $db->select('node_field_data', 'n')->fields('n', ['nid', 'vid', 'langcode'])->condition('nid', $ids, 'IN')->condition('default_langcode', 1)->execute()->fetchAllAssoc('nid');
      $table = 'node__' . $field;
      $revision_table = 'node_revision__' . $field;
      $transaction = $apply ? $db->startTransaction() : NULL;
      $changed = [];
      foreach ($batch as $nid => $points) {
        $points = array_values(array_unique(array_map('json_encode', $points)));
        if (count($points) !== 1 || !isset($metadata[$nid])) {
          $report['skipped_concurrent_or_ambiguous']++;
          continue;
        }
        [$lat, $lon] = json_decode($points[0], TRUE);
        $meta = $metadata[$nid];
        $before = $db->select($table, 'f')->fields('f')->condition('entity_id', $nid)->condition('deleted', 0)->execute()->fetchAll(\PDO::FETCH_ASSOC);
        $valid_now = FALSE;
        foreach ($before as $row) {
          $valid_now = $valid_now || labdooAuditValidLocation($row[$field . '_lat'], $row[$field . '_lon']);
        }
        if ($valid_now) {
          $report['skipped_concurrent_or_ambiguous']++;
          continue;
        }
        $dummy->set($field, ['value' => sprintf('POINT (%.12F %.12F)', $lon, $lat)]);
        $item = $dummy->get($field)->first()->getValue();
        $after = ['bundle' => $bundle, 'deleted' => 0, 'entity_id' => $nid, 'revision_id' => $meta->vid, 'langcode' => $meta->langcode, 'delta' => 0];
        foreach ($item as $property => $value) {
          if (isset($stored_properties[$property])) {
            $after[$field . '_' . $property] = $value;
          }
        }
        $report['eligible']++;
        $report['fields'][$name] = ($report['fields'][$name] ?? 0) + 1;
        if ($apply) {
          // Lock node metadata against concurrent saves for this transaction.
          $current_vid = $db->query('SELECT vid FROM {node} WHERE nid=:nid FOR UPDATE', [':nid' => $nid])->fetchField();
          $current_rows = $db->select($table, 'f')->fields('f')->condition('entity_id', $nid)->condition('deleted', 0)->execute()->fetchAll(\PDO::FETCH_ASSOC);
          if ((int) $current_vid !== (int) $meta->vid || $current_rows !== $before) {
            $report['skipped_concurrent_or_ambiguous']++;
            continue;
          }
          $revision_before = $db->select($revision_table, 'f')->fields('f')->condition('entity_id', $nid)->condition('revision_id', $meta->vid)->condition('deleted', 0)->execute()->fetchAll(\PDO::FETCH_ASSOC);
          $entry = ['field' => $field, 'nid' => $nid, 'vid' => $meta->vid, 'before' => $before, 'revision_before' => $revision_before, 'after' => [$after]];
          if (fwrite($backup, json_encode($entry, JSON_THROW_ON_ERROR) . PHP_EOL) === FALSE || !fflush($backup) || !fsync($backup)) {
            throw new RuntimeException('Cannot persist backup; transaction aborted.');
          }
          $db->delete($table)->condition('entity_id', $nid)->condition('deleted', 0)->execute();
          $db->insert($table)->fields($after)->execute();
          $db->delete($revision_table)->condition('entity_id', $nid)->condition('revision_id', $meta->vid)->condition('deleted', 0)->execute();
          $db->insert($revision_table)->fields($after)->execute();
          $changed[] = $nid;
          $report['repaired']++;
        }
        if ($limit && $report['eligible'] >= $limit) {
          break;
        }
      }
      unset($transaction);
      if ($changed) {
        \Drupal::entityTypeManager()->getStorage('node')->resetCache($changed);
        Cache::invalidateTags(array_merge(array_map(static fn($id) => 'node:' . $id, $changed), ['node_list:' . $bundle]));
      }
      if ($limit && $report['eligible'] >= $limit) {
        break 2;
      }
    }
  }
  if ($apply && $report['repaired'] > 0) {
    Cache::invalidateTags(['node_list']);
  }
  echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
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
  $lock->release('labdoo.content.geo_backfill');
}
